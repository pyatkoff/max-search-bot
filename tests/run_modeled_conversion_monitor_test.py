#!/usr/bin/env python3
"""Synthetic fixtures only: no network, production IDs or real bot credentials."""
import copy
import fcntl
import json
import io
import os
import re
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
from contextlib import redirect_stdout
from types import SimpleNamespace
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
import modeled_conversion_monitor as monitor
import modeled_conversion_monitor_runtime as durable_runtime
import modeled_conversion_monitor_local as local_monitor

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


class RuntimeTests(unittest.TestCase):
    NOW = 1793700000

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="synthetic-monitor-runtime-")
        self.directory = Path(self.temporary.name) / "runtime"
        self.directory.mkdir(mode=0o700)
        self.wire = Wire()
        self.state = {
            "schema_version": 1, "project": "anytour", "counter_id": "98615635",
            "recipient": RECIPIENT, "token": TOKEN, "verified": True, "initialized": True, "role": "pipeline",
            "incident": None, "pending": None,
            "last_notification": {"kind": "bootstrap", "intent_id": "a" * 32, "message_id": 7,
                                  "confirmed_at": self.NOW - 100},
        }
        self.seed(self.state)

    def tearDown(self):
        self.temporary.cleanup()

    def seed(self, state):
        path = self.directory / durable_runtime.STATE_FILE
        if path.is_symlink():
            path.unlink()
        path.write_bytes(monitor.canonical_bytes(state))
        path.chmod(0o600)

    def saved(self):
        return json.loads((self.directory / durable_runtime.STATE_FILE).read_bytes())

    def receipt(self):
        return json.loads((self.directory / durable_runtime.RECEIPT_FILE).read_bytes())

    def runtime(self, observed=None, **kwargs):
        return durable_runtime.DurableMonitorRuntime(
            self.directory, lambda: health() if observed is None else observed, self.wire,
            role=kwargs.pop("role", "pipeline"), clock=kwargs.pop("clock", lambda: self.NOW), **kwargs)

    def test_library_import_without_pyrage_or_site_packages(self):
        tools = str(Path(__file__).resolve().parents[1] / "tools")
        script = "import sys;sys.path.insert(0," + repr(tools) + ");import modeled_conversion_monitor_runtime;assert 'pyrage' not in sys.modules;print('stdlib-runtime-import-ok')"
        env = {name: os.environ[name] for name in ("PATH", "LD_LIBRARY_PATH") if name in os.environ}
        completed = subprocess.run([sys.executable, "-I", "-S", "-B", "-c", script],
                                   env=env, capture_output=True, text=True, timeout=5)
        self.assertEqual(completed.returncode, 0, completed.stderr[:500])
        self.assertEqual(completed.stdout.strip(), "stdlib-runtime-import-ok")

    def test_quiet_preserves_state_and_last_notification(self):
        before = (self.directory / durable_runtime.STATE_FILE).read_bytes()
        result = self.runtime().cycle()
        self.assertTrue(result["ok"])
        self.assertTrue(result["receipt_recorded"])
        self.assertEqual(result["notification"], "quiet")
        self.assertEqual(self.wire.posts(), 0)
        self.assertEqual((self.directory / durable_runtime.STATE_FILE).read_bytes(), before)
        self.assertEqual(self.saved()["last_notification"], self.state["last_notification"])
        self.assertFalse(result["external_proof"])
        self.assertFalse(result["scheduled_proof"])

    def test_prepared_and_sending_states_are_durable_before_wire(self):
        runtime = self.runtime(health("degraded", ["delivery_tick_stale"]))
        phases, syncs = [], []
        store, fsync = runtime._store, os.fsync

        def saved(directory_fd, state):
            store(directory_fd, state)
            phases.append(state["pending"]["state"] if state["pending"] else "final")

        def synced(fd):
            syncs.append("directory" if stat.S_ISDIR(os.fstat(fd).st_mode) else "file")
            return fsync(fd)

        def before_send():
            pending = self.saved()["pending"]
            self.assertEqual(pending["state"], "sending")
            self.assertEqual(phases, ["prepared", "sending"])
            self.assertEqual(syncs, ["file", "directory", "file", "directory"])
            self.assertTrue(pending["owner_run"].isdecimal())
            self.assertGreater(len(pending["owner_run"]), 20)

        self.wire.before_send = before_send
        with mock.patch.object(runtime, "_store", saved), mock.patch.object(durable_runtime.os, "fsync", synced):
            result = runtime.cycle()
        self.assertTrue(result["ok"])
        self.assertEqual(result["notification"], "sent")
        self.assertEqual(phases, ["prepared", "sending", "final"])
        self.assertEqual(self.wire.posts(), 1)
        self.assertIsNone(self.saved()["pending"])

    def test_crash_after_durable_intent_cannot_replay_it(self):
        class SyntheticCrash(BaseException):
            pass
        runtime = self.runtime(health("degraded", ["ready_queue_stale"]))
        store = runtime._store

        def crash_after_prepared(directory_fd, state):
            store(directory_fd, state)
            if state["pending"] and state["pending"]["state"] == "prepared":
                raise SyntheticCrash()

        with mock.patch.object(runtime, "_store", crash_after_prepared):
            with self.assertRaises(SyntheticCrash):
                runtime.cycle()
        original = self.saved()["pending"]
        self.assertEqual(original["state"], "prepared")
        self.assertEqual(self.wire.posts(), 0)
        self.assertFalse((self.directory / durable_runtime.RECEIPT_FILE).exists())
        result = self.runtime().cycle()
        self.assertFalse(result["ok"])
        self.assertEqual(result["notification"], "unknown")
        self.assertEqual(self.saved()["pending"]["intent_id"], original["intent_id"])
        self.assertEqual(self.saved()["pending"]["state"], "unknown")
        self.assertEqual(self.wire.posts(), 0)

    def test_directory_sync_failure_prevents_wire(self):
        runtime = self.runtime(health("degraded", ["report_unavailable"]))
        fsync = os.fsync

        def fail_directory_sync(fd):
            if stat.S_ISDIR(os.fstat(fd).st_mode):
                raise OSError("synthetic-directory-sync-failure")
            return fsync(fd)

        with mock.patch.object(durable_runtime.os, "fsync", fail_directory_sync):
            result = runtime.cycle()
        self.assertFalse(result["ok"])
        self.assertEqual(self.wire.posts(), 0)
        self.assertEqual(self.saved()["pending"]["state"], "prepared")
        self.assertEqual(self.runtime().cycle()["notification"], "unknown")
        self.assertEqual(self.wire.posts(), 0)

    def test_wire_failure_is_unknown_and_next_cycle_does_not_resend(self):
        self.wire.lose_send_response = True
        failed = health("degraded", ["report_unavailable"])
        first = self.runtime(failed).cycle()
        original = self.saved()["pending"]
        self.assertEqual(first["notification"], "unknown")
        self.assertFalse(first["ok"])
        self.assertTrue(first["receipt_recorded"])
        self.assertEqual(original["state"], "unknown")
        self.assertEqual(self.wire.posts(), 1)
        second = self.runtime(failed).cycle()
        self.assertFalse(second["ok"])
        self.assertEqual(second["notification"], "unknown")
        self.assertEqual(self.saved()["pending"]["intent_id"], original["intent_id"])
        self.assertEqual(self.saved()["last_notification"], self.state["last_notification"])
        self.assertEqual(self.wire.posts(), 1)

    def test_acknowledged_wire_with_failed_final_state_never_resends(self):
        runtime = self.runtime(health("degraded", ["profile_tick_stale"]))
        store = runtime._store

        def lose_final_state(directory_fd, state):
            if state["pending"] is None and state.get("last_notification", {}).get("kind") == "failure":
                raise OSError("synthetic-final-state-write-failure")
            store(directory_fd, state)

        with mock.patch.object(runtime, "_store", lose_final_state):
            result = runtime.cycle()
        self.assertFalse(result["ok"])
        self.assertEqual(result["notification"], "unknown")
        self.assertEqual(self.saved()["pending"]["state"], "sending")
        self.assertEqual(self.wire.posts(), 1)
        self.assertEqual(self.runtime().cycle()["notification"], "unknown")
        self.assertEqual(self.wire.posts(), 1)

    def test_receipt_failure_cannot_report_green_or_repeat_known_send(self):
        runtime = self.runtime(health("degraded", ["profile_tick_errors"]))
        with mock.patch.object(runtime, "_write_receipt", side_effect=OSError("synthetic receipt failure")):
            result = runtime.cycle()
        self.assertFalse(result["ok"])
        self.assertFalse(result["receipt_recorded"])
        self.assertEqual(result["runtime_status"], "local_receipt_unavailable")
        self.assertEqual(result["notification"], "sent")
        self.assertIsNone(self.saved()["pending"])
        self.assertEqual(self.saved()["last_notification"]["kind"], "failure")
        self.assertEqual(self.wire.posts(), 1)
        retry = self.runtime(health("degraded", ["profile_tick_errors"])).cycle()
        self.assertTrue(retry["ok"])
        self.assertEqual(retry["notification"], "quiet")
        self.assertEqual(self.wire.posts(), 1)

    def test_incident_dedup_and_recovery_use_retained_state(self):
        failed = health("degraded", ["delivery_tick_stale"])
        self.assertEqual(self.runtime(failed).cycle()["notification"], "sent")
        self.assertEqual(self.runtime(failed).cycle()["notification"], "quiet")
        self.assertEqual(self.runtime().cycle()["notification"], "sent")
        self.assertEqual(self.runtime().cycle()["notification"], "quiet")
        self.assertEqual(self.wire.posts(), 2)
        self.assertIsNone(self.saved()["incident"])

    def test_handed_off_pending_variants_are_preserved_and_held(self):
        pending, _ = monitor.prepare(health("degraded", ["ready_queue_stale"]), None,
                                     self.state, RECIPIENT, self.wire, "102", now=self.NOW - 10, role="pipeline")
        for state_name in ("prepared", "sending", "unknown"):
            with self.subTest(state=state_name):
                value = copy.deepcopy(pending)
                value["pending"]["state"] = state_name
                self.seed(value)
                result = self.runtime().cycle()
                after = self.saved()
                self.assertFalse(result["ok"])
                self.assertEqual(result["notification"], "unknown")
                self.assertEqual(after["pending"]["state"], "unknown")
                self.assertEqual(after["pending"]["intent_id"], value["pending"]["intent_id"])
                self.assertEqual(after["pending"]["owner_run"], "102")
                self.assertEqual(after["last_notification"], self.state["last_notification"])
                self.assertEqual(self.wire.posts(), 0)

    def test_missing_or_uninitialized_state_never_bootstraps(self):
        path = self.directory / durable_runtime.STATE_FILE
        path.unlink()
        result = self.runtime().cycle()
        self.assertFalse(result["ok"])
        self.assertFalse(path.exists())
        self.assertFalse((self.directory / durable_runtime.RECEIPT_FILE).exists())
        uninitialized = {**self.state, "initialized": False, "verified": False}
        self.seed(uninitialized)
        self.assertFalse(self.runtime().cycle()["ok"])
        self.assertEqual(self.saved(), uninitialized)
        self.assertEqual(self.wire.calls, [])

    def test_private_modes_and_symlinks_are_rejected_without_forwarding(self):
        state_path = self.directory / durable_runtime.STATE_FILE
        state_path.chmod(0o644)
        self.assertFalse(self.runtime().cycle()["ok"])
        state_path.chmod(0o600)
        self.directory.chmod(0o755)
        self.assertFalse(self.runtime().cycle()["ok"])
        self.directory.chmod(0o700)
        target = Path(self.temporary.name) / "outside"
        target.write_bytes(b"synthetic-private-outside")
        target.chmod(0o600)
        for name in (durable_runtime.STATE_FILE, durable_runtime.LOCK_FILE, durable_runtime.RECEIPT_FILE):
            with self.subTest(name=name):
                path = self.directory / name
                if path.exists() or path.is_symlink():
                    path.unlink()
                path.symlink_to(target)
                self.assertFalse(self.runtime().cycle()["ok"])
                self.assertEqual(target.read_bytes(), b"synthetic-private-outside")
                path.unlink()
                self.seed(self.state)
        alias = Path(self.temporary.name) / "alias"
        alias.symlink_to(self.directory, target_is_directory=True)
        self.assertFalse(durable_runtime.DurableMonitorRuntime(alias, health, self.wire, role="pipeline", clock=lambda: self.NOW).cycle()["ok"])
        self.assertEqual(self.wire.posts(), 0)

    def test_nonblocking_lock_does_not_collect_or_send(self):
        lock_path = self.directory / durable_runtime.LOCK_FILE
        lock_path.write_bytes(b"")
        lock_path.chmod(0o600)
        collected = []
        with lock_path.open("r+b") as handle:
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            runtime = durable_runtime.DurableMonitorRuntime(
                self.directory, lambda: collected.append(True) or health(), self.wire, role="pipeline", clock=lambda: self.NOW)
            result = runtime.cycle()
        self.assertFalse(result["ok"])
        self.assertEqual(result["runtime_status"], "busy")
        self.assertEqual(collected, [])
        self.assertEqual(self.wire.calls, [])

    def test_bounded_inputs_and_collector_errors_never_expose_details(self):
        state_path = self.directory / durable_runtime.STATE_FILE
        state_path.write_bytes(b" " * (durable_runtime.MAX_STATE + 1))
        self.assertFalse(self.runtime().cycle()["ok"])
        self.assertEqual(self.wire.posts(), 0)
        self.seed(self.state)
        runtime = durable_runtime.DurableMonitorRuntime(
            self.directory, lambda: {"private_detail": "sensitive-fixture" * 10000}, self.wire, role="pipeline", clock=lambda: self.NOW)
        result = runtime.cycle()
        self.assertEqual(result["flags"], ["health_metadata_unavailable"])
        self.assertNotIn("sensitive-fixture", json.dumps(result))
        self.assertNotIn("sensitive-fixture", json.dumps(self.receipt()))
        self.assertEqual(self.wire.posts(), 1)

    def test_local_receipts_are_bounded_and_never_replace_external_proof(self):
        outside = Path(self.temporary.name) / "external"
        outside.mkdir(mode=0o700)
        external = outside / "last-check.json"
        external.write_bytes(b"synthetic-external-record")
        external.chmod(0o600)
        for offset in range(5):
            self.assertTrue(self.runtime(clock=lambda offset=offset: self.NOW + offset).cycle()["ok"])
        receipt = self.receipt()
        self.assertEqual(receipt["source"], "local_runtime")
        self.assertEqual(receipt["kind"], durable_runtime.RECEIPT_KIND)
        self.assertFalse(receipt["external_proof"])
        self.assertFalse(receipt["scheduled_proof"])
        self.assertEqual([r["checked_at_unix"] for r in receipt["recent"]], [self.NOW + 2, self.NOW + 3, self.NOW + 4])
        self.assertEqual(len({r["cycle_id"] for r in receipt["recent"]}), 3)
        self.assertTrue(all("run_id" not in r and "event" not in r and "source_sha" not in r for r in receipt["recent"]))
        self.assertEqual(external.read_bytes(), b"synthetic-external-record")
        self.assertEqual(self.wire.posts(), 0)
        result = durable_runtime.DurableMonitorRuntime(outside, health, self.wire, role="pipeline", clock=lambda: self.NOW).cycle()
        self.assertFalse(result["ok"])
        self.assertEqual(result["runtime_status"], "dedicated_runtime_directory_required")
        self.assertEqual(external.read_bytes(), b"synthetic-external-record")

    def test_original_cycle_identity_is_generated_without_github_environment(self):
        runtime = self.runtime(health("degraded", ["delivery_tick_errors"]))
        owners = []
        self.wire.before_send = lambda: owners.append(self.saved()["pending"]["owner_run"])
        with mock.patch.dict(os.environ, {"GITHUB_RUN_ID": "101", "GITHUB_RUN_ATTEMPT": "99"}):
            result = runtime.cycle()
        self.assertTrue(result["ok"])
        cycle_id = self.receipt()["recent"][-1]["cycle_id"]
        self.assertRegex(cycle_id, r"\A[a-f0-9]{32}\Z")
        self.assertTrue(owners[0].isdecimal())
        self.assertGreater(len(owners[0]), 20)
        self.assertNotEqual(owners[0], "101")
        self.assertNotEqual(cycle_id, "101")
        self.assertNotIn("GITHUB_RUN_ID", json.dumps(result))


def typed_fixture_state(role):
    return {"schema_version": 1, "project": "anytour", "counter_id": "98615635",
            "recipient": RECIPIENT, "token": TOKEN, "verified": True, "initialized": True,
            "incident": None, "pending": None, "role": role,
            "last_notification": {"kind": "bootstrap", "intent_id": "a" * 32, "message_id": 7, "confirmed_at": 1793699900}}

class RoleTests(unittest.TestCase):
    def test_role_flag_partitions_and_legacy_calls(self):
        wire = Wire()
        for role, denied in (("pipeline", monitor.EXTERNAL_ROLE_FLAGS),
                             ("external", monitor.FLAGS - monitor.ROLE_FLAGS["external"])):
            state = typed_fixture_state(role)
            for flag in denied:
                with self.subTest(role=role, flag=flag):
                    with self.assertRaises(ValueError):
                        monitor.prepare(health("degraded", [flag]), None, state, RECIPIENT, wire, "102", role=role)
            with self.assertRaises(ValueError):
                monitor.prepare(health(), None, state, RECIPIENT, wire, "102")
        legacy = typed_fixture_state("legacy")
        legacy.pop("role")
        _, result = monitor.prepare(health(), None, legacy, RECIPIENT, wire, "102")
        self.assertEqual(result["notification"], "quiet")
        for role in ("pipeline", "external"):
            with self.assertRaises(ValueError):
                monitor.prepare(health(), ROUTE, None, RECIPIENT, wire, "102", role=role)
        self.assertEqual(wire.calls, [])

    def test_retained_pending_flags_are_guarded_again_before_send(self):
        wire = Wire()
        state, _ = monitor.prepare(health("degraded", ["delivery_tick_stale"]), None,
                                   typed_fixture_state("pipeline"), RECIPIENT, wire, "102", role="pipeline")
        state["role"] = "external"
        with self.assertRaises(ValueError):
            monitor.send(state, RECIPIENT, wire, lambda value: None, "102", True, role="external")
        self.assertEqual(wire.calls, [])
        self.assertEqual(state["pending"]["state"], "prepared")

    def test_external_adapter_uses_only_compatible_retained_role(self):
        legacy, external = typed_fixture_state("legacy"), typed_fixture_state("external")
        legacy.pop("role")
        fallback = health("unavailable", ["monitor_transport_unavailable"])
        self.assertIsNone(monitor.external_adapter_role(fallback, legacy))
        self.assertEqual(monitor.external_adapter_role(fallback, external), "external")
        self.assertEqual(monitor.external_adapter_role({**health(), "monitor_scope": "external"}, external), "external")
        self.assertIsNone(monitor.external_adapter_role({**health(), "monitor_scope": "legacy"}, legacy))
        for observed, state in ((fallback, typed_fixture_state("pipeline")),
                                ({**health(), "monitor_scope": "pipeline"}, external),
                                ({**health(), "monitor_scope": "legacy"}, external),
                                ({**health(), "monitor_scope": "external"}, legacy),
                                ({**health(), "monitor_scope": None}, external),
                                ({**health(), "monitor_scope": "external"}, None)):
            with self.assertRaises(ValueError):
                monitor.external_adapter_role(observed, state)
        self.assertEqual(monitor.external_adapter_role(None, external), "external")

    def test_common_errors_have_distinct_owner_visible_role_labels(self):
        messages = {}
        for role in ("pipeline", "external"):
            wire = Wire()
            state, _ = monitor.prepare(health("unavailable", ["configuration_unavailable"]), None,
                                       typed_fixture_state(role), RECIPIENT, wire, "102", role=role)
            _, result = monitor.send(state, RECIPIENT, wire, lambda value: None, "102", True, role=role)
            self.assertEqual(result["notification"], "sent")
            messages[role] = wire.calls[-1][1]["text"]
        self.assertIn("обработка конверсий", messages["pipeline"])
        self.assertIn("внешний контроль", messages["external"])
        self.assertNotEqual(messages["pipeline"], messages["external"])


class LocalCliTests(unittest.TestCase):
    NOW = RuntimeTests.NOW

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="synthetic-local-monitor-")
        self.directory = Path(self.temporary.name) / "pipeline"
        self.directory.mkdir(mode=0o700)
        self.state = typed_fixture_state("pipeline")
        self.wire = Wire()
        self.seed(self.state)
        self.private_directory = mock.patch.object(local_monitor, "PRIVATE_DIR", self.directory)
        self.private_directory.start()
        self.proof_patch = mock.patch.object(local_monitor, "refresh_visit_proof", return_value="skipped")
        self.proof_patch.start()

    def tearDown(self):
        self.proof_patch.stop()
        self.private_directory.stop()
        self.temporary.cleanup()

    def seed(self, value):
        path = self.directory / durable_runtime.STATE_FILE
        path.write_bytes(monitor.canonical_bytes(value))
        path.chmod(0o600)

    def activate(self, **overrides):
        value = {"schema_version": 1, "project": "anytour", "counter_id": "98615635", "role": "pipeline",
                 "operation": "101", "activated_at": durable_runtime.DurableMonitorRuntime._iso(self.NOW - 10),
                 "source_sha": "a" * 40, "state_imported": True}
        value.update(overrides)
        path = self.directory / local_monitor.ACTIVATION_FILE
        path.write_bytes(monitor.canonical_bytes(value))
        path.chmod(0o600)

    def invoke(self, arguments, now=None):
        output = io.StringIO()
        with redirect_stdout(output), mock.patch.object(local_monitor.time, "time", return_value=self.NOW if now is None else now):
            code = local_monitor.main(arguments)
        return code, json.loads(output.getvalue())

    def test_status_missing_marker_is_false_and_read_only(self):
        before = {p.name: p.read_bytes() for p in self.directory.iterdir()}
        code, result = self.invoke(["--status"])
        self.assertEqual(code, 0)
        self.assertFalse(result["configured"])
        self.assertEqual(result["role"], "pipeline")
        self.assertEqual(before, {p.name: p.read_bytes() for p in self.directory.iterdir()})
        self.assertFalse((self.directory / durable_runtime.LOCK_FILE).exists())
        (self.directory / durable_runtime.STATE_FILE).unlink()
        self.assertEqual(self.invoke(["--status"])[0], 0)
        with mock.patch.object(local_monitor, "PRIVATE_DIR", self.directory / "missing"):
            self.assertEqual(self.invoke(["--status"])[0], 0)
            self.assertFalse((self.directory / "missing").exists())

    def test_status_requires_valid_private_marker_and_matching_role(self):
        self.activate()
        before = {p.name: p.read_bytes() for p in self.directory.iterdir()}
        code, result = self.invoke(["--status"])
        self.assertEqual(code, 0)
        self.assertTrue(result["configured"])
        self.assertEqual(before, {p.name: p.read_bytes() for p in self.directory.iterdir()})
        self.seed(typed_fixture_state("external"))
        self.assertNotEqual(self.invoke(["--status"])[0], 0)
        self.seed(self.state)
        self.activate(role="external")
        self.assertNotEqual(self.invoke(["--status"])[0], 0)
        self.activate()
        (self.directory / local_monitor.ACTIVATION_FILE).chmod(0o644)
        self.assertNotEqual(self.invoke(["--status"])[0], 0)
        self.activate()
        marker = self.directory / local_monitor.ACTIVATION_FILE
        content = marker.read_bytes()
        marker.write_bytes(content + b" " * (2049 - len(content)))
        self.assertNotEqual(self.invoke(["--status"])[0], 0)

    def test_cron_origin_and_real_root_guard_precede_runtime_collection(self):
        self.activate()
        with mock.patch.object(local_monitor, "_source_sha") as source:
            with mock.patch.object(local_monitor, "_root_valid", return_value=True), mock.patch.dict(os.environ, {}, clear=True):
                self.assertNotEqual(self.invoke(["--cron"])[0], 0)
            with mock.patch.object(local_monitor, "_root_valid", return_value=False), mock.patch.dict(os.environ, {"MODELED_MONITOR_TRIGGER": "cron"}):
                self.assertNotEqual(self.invoke(["--cron"])[0], 0)
            source.assert_not_called()
        self.assertFalse((self.directory / durable_runtime.PIPELINE_RECEIPT_FILE).exists())
        self.assertEqual(self.wire.calls, [])

    def test_generic_cycles_never_qualify_as_three_natural_cron_checks(self):
        for offset in range(3):
            checker = durable_runtime.DurableMonitorRuntime(
                self.directory, health, self.wire, role="pipeline", clock=lambda offset=offset: self.NOW + offset)
            self.assertTrue(checker.cycle()["ok"])
        self.assertFalse((self.directory / durable_runtime.PIPELINE_RECEIPT_FILE).exists())
        self.activate()
        with mock.patch.object(local_monitor, "_root_valid", return_value=True), \
                mock.patch.object(local_monitor, "_source_sha", return_value="a" * 40), \
                mock.patch.object(local_monitor, "pipeline_health", side_effect=health), \
                mock.patch.object(local_monitor.monitor, "telegram_wire", self.wire), \
                mock.patch.dict(os.environ, {"MODELED_MONITOR_TRIGGER": "cron"}):
            for index in range(1, 5):
                code, result = self.invoke(["--cron"], now=self.NOW + index * 300)
                self.assertEqual(code, 0)
                self.assertTrue(result["ok"])
                self.assertEqual(result["origin"], "server_cron")
                receipt = json.loads((self.directory / durable_runtime.PIPELINE_RECEIPT_FILE).read_bytes())
                self.assertEqual(len(receipt["checks"]), min(index, 3))
        self.assertEqual(receipt["role"], "pipeline")
        self.assertEqual(receipt["origin"], "server_cron")
        self.assertEqual(receipt["cycle_id"], result["cycle_id"])
        self.assertRegex(receipt["cycle_id"], r"\A[a-f0-9]{32}\Z")
        self.assertEqual(len({item["cycle_id"] for item in receipt["checks"]}), 3)
        self.assertTrue(all(item["ok"] for item in receipt["checks"]))
        self.assertEqual([item["checked_at"] for item in receipt["checks"]],
                         [durable_runtime.DurableMonitorRuntime._iso(self.NOW + index * 300) for index in (2, 3, 4)])
        self.assertEqual(receipt["last_notification_at"], durable_runtime.DurableMonitorRuntime._iso(self.state["last_notification"]["confirmed_at"]))
        self.assertLessEqual(len((self.directory / durable_runtime.PIPELINE_RECEIPT_FILE).read_bytes()), 4096)
        self.assertEqual(self.wire.posts(), 0)

    def test_handled_business_failure_and_unknown_notification_have_honest_proofs(self):
        self.activate()
        failed = health("degraded", ["delivery_tick_errors"])
        with mock.patch.object(local_monitor, "_root_valid", return_value=True), \
                mock.patch.object(local_monitor, "_source_sha", return_value="a" * 40), \
                mock.patch.object(local_monitor, "pipeline_health", return_value=failed), \
                mock.patch.object(local_monitor.monitor, "telegram_wire", self.wire), \
                mock.patch.dict(os.environ, {"MODELED_MONITOR_TRIGGER": "cron"}):
            code, result = self.invoke(["--cron"])
            receipt = json.loads((self.directory / durable_runtime.PIPELINE_RECEIPT_FILE).read_bytes())
            self.assertEqual(code, 0)
            self.assertTrue(receipt["ok"])
            self.assertFalse(receipt["checks"][-1]["ok"])
            self.assertEqual(receipt["status"], "degraded")
            self.assertEqual(self.wire.posts(), 1)
            self.wire.lose_send_response = True
            changed = health("degraded", ["delivery_tick_errors", "report_unavailable"])
            with mock.patch.object(local_monitor, "pipeline_health", return_value=changed):
                code, result = self.invoke(["--cron"], now=self.NOW + 300)
            self.assertNotEqual(code, 0)
            receipt = json.loads((self.directory / durable_runtime.PIPELINE_RECEIPT_FILE).read_bytes())
            self.assertFalse(receipt["ok"])
            self.assertEqual(receipt["notification"], "unknown")
            self.assertFalse(receipt["checks"][-1]["ok"])
            before = self.wire.posts()
            self.assertNotEqual(self.invoke(["--cron"], now=self.NOW + 600)[0], 0)
            self.assertEqual(self.wire.posts(), before)

    def test_php_health_is_fixed_bounded_read_only_and_never_a_transport_flag(self):
        for payload, code in ((health(), 0), (health("degraded", ["profile_tick_stale"]), 0),
                              (health("unavailable", ["monitor_transport_unavailable"]), 0), (health(), 1)):
            def run(command, **kwargs):
                self.assertEqual(command, ["/usr/bin/php", "tools/modeled_conversion_monitor.php", "health"])
                self.assertEqual(kwargs["timeout"], 20)
                self.assertEqual(kwargs["cwd"], str(local_monitor.ROOT))
                kwargs["stdout"].write(json.dumps(payload).encode())
                return SimpleNamespace(returncode=code)
            with mock.patch.object(local_monitor.subprocess, "run", side_effect=run):
                result = local_monitor.pipeline_health()
            self.assertNotIn("monitor_transport_unavailable", result["flags"])
            if code != 0 or "monitor_transport_unavailable" in payload["flags"]:
                self.assertEqual(result["flags"], ["health_metadata_unavailable"])

    def test_git_source_is_fixed_and_timed(self):
        with mock.patch.object(local_monitor.subprocess, "run", return_value=SimpleNamespace(returncode=0, stdout=b"a" * 40 + b"\n")) as run:
            self.assertEqual(local_monitor._source_sha(), "a" * 40)
            self.assertEqual(run.call_args.args[0], ["/usr/bin/git", "rev-parse", "HEAD"])
            self.assertEqual(run.call_args.kwargs["timeout"], 3)
            self.assertEqual(run.call_args.kwargs["cwd"], str(local_monitor.ROOT))


class VisitProofRefreshTests(unittest.TestCase):
    def test_fixed_bounded_read_only_command_and_no_queue_or_route_operations(self):
        payload = {"ok": True, "private_verification_saved": True,
                   "provider_write_requests": False, "source_writes": False}
        def run(command, **kwargs):
            self.assertEqual(command, ["/usr/bin/php", "tools/modeled_conversion_visit_proof.php", "scheduled", "19"])
            self.assertEqual(kwargs["timeout"], 21)
            self.assertEqual(kwargs["cwd"], str(local_monitor.ROOT))
            kwargs["stdout"].write(json.dumps(payload).encode())
            return SimpleNamespace(returncode=0)
        with mock.patch.object(local_monitor.subprocess, "run", side_effect=run) as call:
            self.assertEqual(local_monitor.refresh_visit_proof(20), "saved")
            self.assertEqual(call.call_count, 1)
        with mock.patch.object(local_monitor.subprocess, "run") as call:
            self.assertEqual(local_monitor.refresh_visit_proof(36), "no_time_budget")
            call.assert_not_called()

    def test_failures_and_oversized_or_unsafe_receipts_stay_isolated(self):
        for payload, code in ((b"x"*1025, 0), (b"{}", 0), (
                b'{"ok":true,"private_verification_saved":true,"provider_write_requests":true,"source_writes":false}', 0),
                (b"{}", 1)):
            def run(command, **kwargs):
                kwargs["stdout"].write(payload)
                return SimpleNamespace(returncode=code)
            with mock.patch.object(local_monitor.subprocess, "run", side_effect=run):
                self.assertEqual(local_monitor.refresh_visit_proof(1), "unavailable")
        with mock.patch.object(local_monitor.subprocess, "run", side_effect=subprocess.TimeoutExpired("fixed", 20)):
            self.assertEqual(local_monitor.refresh_visit_proof(1), "unavailable")

    def test_new_flags_remain_in_the_pipeline_role_and_have_owner_notifications(self):
        flags = ["visit_proof_stale", "visit_proof_refresh_failed"]
        self.assertTrue(set(flags).issubset(monitor.ROLE_FLAGS["pipeline"]))
        self.assertTrue(set(flags).isdisjoint(monitor.ROLE_FLAGS["external"]))
        self.assertEqual(monitor.clean_flags(flags), sorted(flags))

if __name__ == "__main__":
    unittest.main()
