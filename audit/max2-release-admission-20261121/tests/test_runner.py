import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("admission_runner", ROOT / "runner.py")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class PreparationOnly(unittest.TestCase):
    def test_draft_has_no_admission(self):
        with self.assertRaises(runner.Refusal):
            runner.request(ROOT / "admission-request.template.json")

    def test_missing_native_context_has_no_admission(self):
        with self.assertRaises(runner.Refusal):
            runner.native_context({})

    def test_exact_request_and_full_cli_argument_shape(self):
        value = {"version": 1, "repository": runner.REPOSITORY, "pr_number": 123,
                 "head_sha": "a" * 40, "base_sha": runner.BASE}
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "synthetic.json"
            path.write_text(json.dumps(value))
            result, digest = runner.request(path)
            self.assertEqual(result, value)
            self.assertEqual(len(digest), 64)
            command = runner.command(result, Path("/synthetic/base"))
            self.assertEqual(command, ["node", "/synthetic/base/tools/release_priority.js", "--admit", "123", "a" * 40, runner.BASE])
            self.assertNotIn("--publisher", command)
            path.write_text(json.dumps(value)[:-1] + ',"publisher":{"comment_id":1}}')
            with self.assertRaises(runner.Refusal):
                runner.request(path)

    def test_duplicate_request_keys_rejected(self):
        with self.assertRaises(runner.Refusal):
            json.loads('{"version":1,"version":1}', object_pairs_hook=runner.unique_object)

    def test_result_decoder_does_not_promote_refusal(self):
        decision = {"allowed": False, "reason": "earlier_ready_release", "holder_pr": 12,
                    "expires_at": "2026-11-21T18:00:00.000Z", "active_reservations": 1}
        raw = ("release-priority: " + json.dumps(decision) + "\n").encode()
        self.assertEqual(runner.canonical_result(1, raw, b"")["status"], "REFUSED")
        with self.assertRaises(runner.Refusal):
            runner.canonical_result(0, raw, b"")

    def test_unexpected_output_is_not_admission_or_raw_artifact(self):
        with self.assertRaises(runner.Refusal):
            runner.canonical_result(0, b"not a canonical decision\n", b"")
        result = runner.canonical_result(1, b"", b"synthetic sensitive URL or bearer detail\n")
        self.assertEqual(result, {"status": "REFUSED", "reason": "canonical_refusal"})

    def test_actual_five_key_initial_admission_shapes_are_preserved(self):
        # Shape read from the exact BASE decidePriority -> runGuard -> CLI path.
        # No canonical code, API request or admission is invoked by this test.
        for decision in [
            {"allowed": True, "reason": "no_active_reservation", "holder_pr": None,
             "expires_at": None, "active_reservations": 0},
            {"allowed": True, "reason": "priority_owned", "holder_pr": 1643,
             "expires_at": "2026-11-21T18:00:00.000Z", "active_reservations": 2},
        ]:
            raw = ("release-priority: " + json.dumps(decision, separators=(",", ":")) + "\n").encode()
            self.assertEqual(runner.canonical_result(0, raw, b""), {"status": "ADMITTED", "decision": decision})
            with self.assertRaises(runner.Refusal):
                runner.canonical_result(1, raw, b"")

    def test_noncanonical_admission_modes_and_extra_output_are_rejected(self):
        for decision in [
            {"allowed": True, "reason": "manual_checks_only", "holder_pr": None, "expires_at": None},
            {"allowed": True, "reason": "active_publisher", "holder_pr": 1643,
             "expires_at": None, "active_reservations": 1, "publisher_comment": 7063060212},
        ]:
            raw = ("release-priority: " + json.dumps(decision) + "\n").encode()
            with self.assertRaises(runner.Refusal):
                runner.canonical_result(0, raw, b"")
        decision = {"allowed": True, "reason": "no_active_reservation", "holder_pr": None,
                    "expires_at": None, "active_reservations": 0}
        raw = ("release-priority: " + json.dumps(decision) + "\n").encode()
        with self.assertRaises(runner.Refusal):
            runner.canonical_result(0, raw + b"unexpected line\n", b"")
        with self.assertRaises(runner.Refusal):
            runner.canonical_result(0, raw, b"unexpected stderr\n")

    def test_concrete_request_is_the_parent_supplied_pr_tuple(self):
        value, _ = runner.request(ROOT / "admission-request.json")
        self.assertEqual(value["pr_number"], 1643)
        self.assertEqual(value["head_sha"], "de603d8c5b53a5dbf3e972e166da96c42c4e5500")
        self.assertEqual(value["base_sha"], runner.BASE)


if __name__ == "__main__":
    unittest.main()
