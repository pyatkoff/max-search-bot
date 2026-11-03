#!/usr/bin/env python3
"""Synthetic fixtures only: no network, production IDs or real bot credentials."""
import copy
import json
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
import modeled_conversion_monitor as monitor

RECIPIENT = "123456"
TOKEN = "123456:abcdefghijklmnopqrstuvwxyz_0123456789"
ROUTE = {"schema_version": 1, "project": "anytour", "counter_id": "98615635", "token": TOKEN}

def health(status="ok", flags=None):
    return {"schema_version": 1, "project": "anytour", "counter_id": "98615635", "status": status, "flags": flags or []}

class Wire:
    def __init__(self):
        self.calls = []
        self.chat = {"id": int(RECIPIENT), "type": "private"}
        self.lose_send_response = False
        self.before_send = None
    def __call__(self, token, method, payload):
        self.calls.append((method, copy.deepcopy(payload)))
        if method == "getChat":
            return {"ok": True, "result": copy.deepcopy(self.chat)}
        if self.before_send:
            self.before_send()
        if self.lose_send_response:
            raise TimeoutError("synthetic lost response")
        return {"ok": True, "result": {"message_id": len(self.calls), "chat": {"id": int(RECIPIENT), "type": "private"}}}
    def posts(self):
        return sum(method == "sendMessage" for method, _ in self.calls)

class MonitorTests(unittest.TestCase):
    def setUp(self):
        self.wire = Wire()
        self.saves = []
    def save(self, state):
        self.saves.append(copy.deepcopy(state))
    def activate(self):
        state, output = monitor.prepare(health(), ROUTE, None, RECIPIENT, self.wire, "101", bootstrap=True, now=100)
        self.assertTrue(output["intent_prepared"])
        self.assertEqual(self.wire.posts(), 0)
        state, output = monitor.send(state, RECIPIENT, self.wire, self.save, "101", True, now=101)
        self.assertEqual(output["notification"], "sent")
        self.assertTrue(state["initialized"])
        return state
    def cycle(self, state, observed, run_id):
        state, output = monitor.prepare(observed, None, state, RECIPIENT, self.wire, run_id, now=200)
        if output["intent_prepared"]:
            state, output = monitor.send(state, RECIPIENT, self.wire, self.save, run_id, True, now=201)
        return state, output
    def test_cipher_roundtrip_and_tamper(self):
        state = self.activate()
        key = b"synthetic-private-key\n"
        cipher = monitor.encode_cache(state, key)
        self.assertNotIn(TOKEN.encode(), cipher)
        self.assertEqual(monitor.decode_cache(cipher, key), state)
        damaged = bytearray(cipher)
        damaged[len(damaged) // 2] ^= 1
        with self.assertRaises(Exception):
            monitor.decode_cache(bytes(damaged), key)
        with self.assertRaises(Exception):
            monitor.decode_cache(cipher, b"different-key\n")
        self.assertEqual(monitor.password(b"synthetic-private-key\r\n"), monitor.password(key))
    def test_bootstrap_intent_before_private_send(self):
        state, output = monitor.prepare(health(), ROUTE, None, RECIPIENT, self.wire, "101", bootstrap=True, now=100)
        self.assertTrue(output["intent_prepared"])
        self.assertEqual(self.wire.posts(), 0)
        self.wire.before_send = lambda: self.assertEqual(self.saves[-1]["pending"]["state"], "sending")
        state, output = monitor.send(state, RECIPIENT, self.wire, self.save, "101", True)
        self.assertTrue(state["initialized"])
        self.assertGreater(state["last_notification"]["message_id"], 0)
        state, output = self.cycle(state, health(), "102")
        self.assertEqual(output["notification"], "quiet")
        self.assertEqual(self.wire.posts(), 1)
    def test_failure_dedup_changed_flags_recovery(self):
        state = self.activate()
        failed = health("degraded", ["delivery_tick_stale"])
        state, output = self.cycle(state, failed, "102")
        self.assertEqual(output["notification"], "sent")
        state, output = self.cycle(state, failed, "103")
        self.assertEqual(output["notification"], "quiet")
        state, output = self.cycle(state, health("degraded", ["delivery_tick_stale", "report_unavailable"]), "104")
        self.assertEqual(output["notification"], "sent")
        state, output = self.cycle(state, health(), "105")
        self.assertEqual(output["notification"], "sent")
        self.assertIsNone(state["incident"])
        state, output = self.cycle(state, health(), "106")
        self.assertEqual(output["notification"], "quiet")
        self.assertEqual(self.wire.posts(), 4)
    def test_off_is_quiet(self):
        state = self.activate()
        state, _ = self.cycle(state, health("degraded", ["delivery_tick_errors"]), "102")
        before = self.wire.posts()
        state, output = self.cycle(state, health("off", ["profile_disabled"]), "103")
        self.assertEqual(output["notification"], "quiet")
        self.assertIsNone(state["incident"])
        state, output = self.cycle(state, health(), "104")
        self.assertEqual(self.wire.posts(), before)
    def test_server_unavailable_uses_cached_route(self):
        state = self.activate()
        state, output = self.cycle(state, health("unavailable", ["monitor_transport_unavailable"]), "102")
        self.assertEqual(output["notification"], "sent")
    def test_prior_intent_not_replayed(self):
        state = self.activate()
        state, output = monitor.prepare(health("degraded", ["ready_queue_stale"]), None, state, RECIPIENT, self.wire, "102")
        self.assertTrue(output["intent_prepared"])
        before = self.wire.posts()
        state, output = self.cycle(state, health(), "103")
        self.assertEqual(output["notification"], "unknown")
        self.assertEqual(self.wire.posts(), before)
    def test_lost_response_without_resend(self):
        state = self.activate()
        self.wire.lose_send_response = True
        failed = health("degraded", ["report_unavailable"])
        state, output = self.cycle(state, failed, "102")
        self.assertEqual(output["notification"], "unknown")
        self.assertEqual(state["pending"]["state"], "unknown")
        before = self.wire.posts()
        state, output = self.cycle(state, failed, "103")
        self.assertEqual(self.wire.posts(), before)
    def test_same_run_and_explicit_send_guard(self):
        state = self.activate()
        state, _ = monitor.prepare(health("degraded", ["delivery_tick_stale"]), None, state, RECIPIENT, self.wire, "102")
        before = self.wire.posts()
        _, output = monitor.send(state, RECIPIENT, self.wire, self.save, "103", True)
        self.assertEqual(output["notification"], "unknown")
        _, output = monitor.send(state, RECIPIENT, self.wire, self.save, "102", False)
        self.assertEqual(output["notification"], "unknown")
        self.assertEqual(self.wire.posts(), before)
    def test_recipient_and_private_chat_checks(self):
        state = self.activate()
        before = self.wire.posts()
        with self.assertRaises(ValueError):
            monitor.prepare(health(), None, state, "123457", self.wire, "102")
        for chat in ({"id": int(RECIPIENT), "type": "group"}, {"id": 123457, "type": "private"}):
            self.wire.chat = chat
            state, output = monitor.prepare(health(), ROUTE, None, RECIPIENT, self.wire, "102")
            self.assertFalse(output["ok"])
            self.assertFalse(state["verified"])
        self.assertEqual(self.wire.posts(), before)
    def test_scope_flags_and_sanitized_output(self):
        state = self.activate()
        bad = health()
        bad["counter_id"] = "1"
        state, output = self.cycle(state, bad, "102")
        self.assertEqual(output["flags"], ["monitor_source_mismatch"])
        encoded = json.dumps(output)
        self.assertNotIn(RECIPIENT, encoded)
        self.assertNotIn(TOKEN, encoded)
        self.assertEqual(monitor.health_view(health("degraded", ["unknown_private_text"])), ("unavailable", ["health_metadata_unavailable"]))
        with self.assertRaises(ValueError):
            monitor.prepare(health(), {**ROUTE, "project": "foreign"}, None, RECIPIENT, self.wire, "103")
    def test_bootstrap_or_token_rotation_cannot_reset_state(self):
        state = self.activate()
        before = self.wire.posts()
        with self.assertRaises(ValueError):
            monitor.prepare(health(), ROUTE, state, RECIPIENT, self.wire, "102", bootstrap=True)
        with self.assertRaises(ValueError):
            monitor.prepare(health(), {**ROUTE, "token": TOKEN + "x"}, state, RECIPIENT, self.wire, "102")
        self.assertEqual(self.wire.posts(), before)
    def test_redirect_never_forwards_request(self):
        with self.assertRaises(ValueError):
            monitor.NoRedirect().redirect_request(None, None, 302, "", {}, "https://example.invalid")

if __name__ == "__main__":
    unittest.main()
