#!/usr/bin/env python3
"""Exercise the actual continuation controller with a fake child and clock."""
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

PATH = Path(__file__).with_name("continue-saved-monitor-prices-20261114.py")
SPEC = importlib.util.spec_from_file_location("saved_continuation", PATH)
runner = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runner)
SHA = "80ebb725c0a954bae7d2ae78ba6e7c48056248a6"


def result(first=1, updated=30, scanned=50):
    updates = []
    for tv in range(first, first + updated):
        record = hashlib.sha256(f"saved-observation-{tv}".encode()).hexdigest()
        operation = f"commercial-monitor-price-h{tv}-{record}"
        posts = [{"platform": platform, "state": "sent", "publication_id": tv * 2 + i,
                  "version_id": tv * 2 + i + 10000, "message_id": f"existing-{tv}-{platform}",
                  "post_url": f"https://example.invalid/{platform}/{tv}",
                  "existing_message_reused": True, "body_photo_button_verified": True}
                 for i, platform in enumerate(("max", "telegram"))]
        updates.append({"tourvisor_hotel_id": tv, "record_id": record, "operation": operation, "posts": posts,
                        "feed_operation": "commercial-channel-hotel-feeds-20261114-update" + str(int(hashlib.sha256(operation.encode()).hexdigest()[:14], 16))})
    return {"ok": True, "mode": "existing_monitor_post_feed_hook", "installed_sha": SHA,
            "budget_seconds": 480, "finish_reserve_seconds": 0, "updated_pairs": updated,
            "unchanged_pairs": 0, "eligible_pairs": updated, "held_pairs": 0,
            "scanned_pairs": scanned, "remaining_unscanned_pairs": 604 - scanned, "roster_pairs": 604,
            "updates": updates, "deferred": [{"tourvisor_hotel_id": 900000 + i, "reason": "no_fresh_observation"}
                                               for i in range(scanned - updated)],
            "error_counts": [], "new_posts": 0, "supplier_calls": 0, "site_writes": 0,
            "bounded_pass_stopped": scanned < 604, "duration_seconds": 287}


class FakeChild:
    def __init__(self, steps):
        self.steps, self.calls, self.now = steps, [], 0.0

    def clock(self):
        return self.now

    def __call__(self, number, stream):
        self.calls.append(number)
        step = self.steps[len(self.calls) - 1]
        self.now += step.get("elapsed", 1)
        value = step.get("result")
        raw = step.get("raw", runner.HOOK_MARKER + json.dumps(value, ensure_ascii=False).encode() + b"\n")
        stream.write(raw)
        if step.get("raise"):
            raise OSError("synthetic secret transport detail")
        return step.get("returncode", 0)


class ContinuationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def run_case(self, steps, name="capture"):
        child = FakeChild(steps)
        directory = self.root / name
        summary = runner.run_continuation(directory, SHA, child, child.clock)
        self.assertEqual(json.loads((directory / "continuation.json").read_text()), summary)
        return summary, child, directory

    def test_two_successful_original_passes(self):
        summary, child, directory = self.run_case([{"result": result(), "elapsed": 287},
                                                  {"result": result(31, 2, 604), "elapsed": 20}])
        self.assertTrue(summary["ok"] and summary["scan_complete"])
        self.assertFalse(summary["partial"])
        self.assertEqual((summary["passes_accepted"], summary["updated_pairs"], summary["unique_updated_hotels"]), (2, 32, 32))
        self.assertEqual(child.calls, [1, 2])
        self.assertEqual(json.loads((directory / "pass-01.json").read_text()), result())
        self.assertEqual(json.loads((directory / "pass-02.json").read_text()), result(31, 2, 604))

    def test_full_scan_without_updates_stops_once(self):
        summary, child, _ = self.run_case([{"result": result(updated=0, scanned=604)}])
        self.assertTrue(summary["ok"] and summary["scan_complete"])
        self.assertEqual(summary["updated_pairs"], 0)
        self.assertEqual(child.calls, [1])

    def test_transport_unknown_and_nonzero_never_repeat(self):
        for suffix, extra in [("exit", {"returncode": 2}), ("unknown", {"raise": True})]:
            with self.subTest(suffix=suffix):
                summary, child, directory = self.run_case([{"result": result(), **extra}], suffix)
                self.assertFalse(summary["ok"])
                self.assertEqual(summary["error"], "ssh_failed_or_unknown")
                self.assertEqual(child.calls, [1])
                self.assertTrue((directory / "pass-01.json").exists())
                self.assertNotIn("synthetic secret", json.dumps(summary))

    def test_absent_or_ambiguous_marker_stops_unknown(self):
        for i, raw in enumerate((b"no result\n", runner.HOOK_MARKER + b"{}\n" + runner.HOOK_MARKER + b"{}\n")):
            summary, child, directory = self.run_case([{"raw": raw}], f"marker-{i}")
            self.assertFalse(summary["ok"])
            self.assertEqual(summary["error"], "hook_result_missing_or_ambiguous")
            self.assertEqual((directory / "pass-01.stdout").read_bytes(), raw)
            self.assertEqual(child.calls, [1])

    def test_invalid_counts_preserve_full_result_before_rejection(self):
        for i, change in enumerate(({"remaining_unscanned_pairs": 0}, {"updated_pairs": True}, {"eligible_pairs": 29})):
            bad = result(); bad.update(change)
            summary, child, directory = self.run_case([{"result": bad}], f"count-{i}")
            self.assertFalse(summary["ok"])
            self.assertEqual(summary["error"], "hook_invalid_counts")
            self.assertEqual(json.loads((directory / "pass-01.json").read_text()), bad)
            self.assertEqual(child.calls, [1])

    def test_duplicate_record_cannot_start_third_pass(self):
        summary, child, directory = self.run_case([{"result": result()}, {"result": result()}])
        self.assertFalse(summary["ok"])
        self.assertEqual(summary["error"], "hook_duplicate_updated_record_or_operation")
        self.assertEqual((summary["passes_accepted"], summary["updated_pairs"]), (1, 30))
        self.assertEqual(child.calls, [1, 2])
        self.assertTrue((directory / "pass-01.json").exists() and (directory / "pass-02.json").exists())

    def test_admission_reserves_full_default_pass_and_finish_margin(self):
        stopped, child, _ = self.run_case([{"result": result(), "elapsed": 1021}], "late")
        self.assertTrue(stopped["ok"] and stopped["partial"])
        self.assertFalse(stopped["scan_complete"])
        self.assertEqual(stopped["stop_reason"], "aggregate_admission_deadline")
        self.assertEqual(child.calls, [1])
        allowed, child, _ = self.run_case([{"result": result(), "elapsed": 1020},
                                          {"result": result(31, 1, 604)}], "boundary")
        self.assertTrue(allowed["scan_complete"])
        self.assertEqual(child.calls, [1, 2])

    def test_fewer_than_thirty_with_remaining_is_partial(self):
        summary, child, _ = self.run_case([{"result": result(updated=12, scanned=100)}])
        self.assertTrue(summary["ok"] and summary["partial"])
        self.assertFalse(summary["scan_complete"])
        self.assertEqual(summary["stop_reason"], "partial_pass_budget_stopped")
        self.assertEqual(summary["last_accepted_remaining_unscanned_pairs"], 504)
        self.assertEqual(child.calls, [1])

    def test_failure_after_success_keeps_receipts_and_truthful_totals(self):
        bad = result(31); bad["ok"] = False; bad["error"] = "hook_post_outcome_unresolved_stop"
        summary, child, directory = self.run_case([{"result": result()}, {"result": bad, "returncode": 2}])
        self.assertFalse(summary["ok"])
        self.assertEqual((summary["passes_accepted"], summary["updated_pairs"]), (1, 30))
        self.assertIsNone(summary["supplier_calls"])
        self.assertEqual(json.loads((directory / "pass-01.json").read_text()), result())
        self.assertEqual(json.loads((directory / "pass-02.json").read_text()), bad)
        with self.assertRaisesRegex(runner.Stop, "continuation_output_already_used"):
            runner.run_continuation(directory, SHA, child, child.clock)
        self.assertEqual(child.calls, [1, 2])

    def test_holds_errors_effects_and_unconfirmed_posts_stop(self):
        changes = [("held_pairs", 1), ("error_counts", {"monitor_price_expired": 1}),
                   ("supplier_calls", 1), ("new_posts", 1), ("site_writes", 1), ("child_failure", {"error": "unknown"})]
        for i, (field, value) in enumerate(changes):
            bad = result(); bad[field] = value
            summary, child, _ = self.run_case([{"result": bad}], f"guard-{i}")
            self.assertFalse(summary["ok"])
            self.assertEqual(child.calls, [1])
        bad = result(); bad["updates"][0]["posts"][0]["state"] = "unknown"
        summary, child, _ = self.run_case([{"result": bad}], "unknown-post")
        self.assertFalse(summary["ok"])
        self.assertEqual(summary["error"], "hook_unconfirmed_post")

    def test_ssh_uses_original_argv_stdin_without_collector_context_or_kill(self):
        args = SimpleNamespace(hook=self.root / "hook.php", key=self.root / "key", known_hosts=self.root / "known_hosts")
        args.hook.write_bytes(b"<?php\ndeclare(strict_types=1);\n// validated fixture\n")
        args.key.write_text("synthetic"); args.known_hosts.write_text("synthetic")
        env = {"EXPECTED_PRODUCTION_SHA": SHA, "DEPLOY_HOST": "example.invalid", "DEPLOY_USER": "fixture",
               **{key: "must-not-inherit" for key in runner.COLLECTOR_ENV}}
        expected, child = runner.make_ssh_child(args, env)
        with patch.object(runner.subprocess, "run", return_value=SimpleNamespace(returncode=0)) as call:
            self.assertEqual(child(1, io.BytesIO()), 0)
        self.assertEqual(expected, SHA)
        self.assertEqual(call.call_count, 1)
        argv, kwargs = call.call_args.args[0], call.call_args.kwargs
        self.assertEqual(argv[0], "ssh")
        self.assertTrue(argv[-1].endswith("; php"))
        self.assertIn(SHA, argv[-1]); self.assertIn("status --porcelain --untracked-files=no", argv[-1])
        self.assertNotIn("--nightly", argv[-1]); self.assertNotIn("release-monitor", argv[-1])
        self.assertFalse(any(key in kwargs["env"] for key in runner.COLLECTOR_ENV))
        self.assertEqual(kwargs["input"], args.hook.read_bytes())
        self.assertEqual(kwargs["stderr"], runner.subprocess.DEVNULL)
        self.assertNotIn("timeout", kwargs); self.assertNotIn("shell", kwargs)


if __name__ == "__main__":
    unittest.main(verbosity=2)
