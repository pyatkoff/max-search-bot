import importlib.util
import copy
import hashlib
import shutil
import subprocess
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("admission_runner", ROOT / "runner.py")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


def synthetic_request():
    # These synthetic bindings are used only to test parsing. Never invoke the
    # canonical guard or present them as a native source/admission receipt.
    return {"version": 1, "repository": runner.REPOSITORY, "pr_number": 123,
            "head_sha": "a" * 40, "base_sha": runner.BASE, "coordinator_sha": "a" * 40,
            "coordinator_core": {
                "tools/release_priority.js": {"git_blob": runner.BASE_CORE["tools/release_priority.js"][0], "bytes": 23624},
                "tools/deployment_release.js": {"git_blob": "b" * 40, "bytes": 100},
                "tools/release_slot_close.js": {"git_blob": "c" * 40, "bytes": 100},
            }}


def fixture_repository(root):
    (root / "tools").mkdir()
    shutil.copyfile(ROOT / "tests/fixtures/release_priority.js", root / "tools/release_priority.js")
    # Updated dependencies are inert byte fixtures. No module is executed.
    (root / "tools/deployment_release.js").write_text("'use strict';\nmodule.exports = {};\n")
    (root / "tools/release_slot_close.js").write_text("'use strict';\n// fixture only\nmodule.exports = {};\n")
    def git(*args):
        result = subprocess.run(["git", "-C", str(root), "-c", "core.hooksPath=/dev/null",
                                 "-c", "commit.gpgsign=false", "-c", "user.name=Fixture",
                                 "-c", "user.email=fixture@example.invalid", *args],
                                check=True, capture_output=True, timeout=15)
        return result.stdout.decode().strip()
    git("init", "-q")
    git("add", "tools")
    git("commit", "-qm", "synthetic transport fixture")
    source = git("rev-parse", "HEAD")
    value = synthetic_request()
    value["head_sha"] = value["coordinator_sha"] = source
    for path in value["coordinator_core"]:
        raw = (root / path).read_bytes()
        value["coordinator_core"][path] = {
            "git_blob": hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest(),
            "bytes": len(raw),
        }
    return value


class PreparationOnly(unittest.TestCase):
    def test_draft_has_no_admission(self):
        with self.assertRaises(runner.Refusal):
            runner.request(ROOT / "admission-request.template.json")

    def test_missing_native_context_has_no_admission(self):
        with self.assertRaises(runner.Refusal):
            runner.native_context({})

    def test_exact_request_and_full_cli_argument_shape(self):
        value = synthetic_request()
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

    def test_new_operation_template_is_fail_closed(self):
        value = json.loads((ROOT / "admission-request.template.json").read_text())
        self.assertIsNone(value["pr_number"])
        self.assertIsNone(value["head_sha"])
        self.assertIsNone(value["coordinator_sha"])
        self.assertIsNone(value["coordinator_core"]["tools/deployment_release.js"]["git_blob"])
        self.assertIsNone(value["coordinator_core"]["tools/release_slot_close.js"]["bytes"])
        self.assertEqual(value["base_sha"], runner.BASE)
        with self.assertRaises(runner.Refusal):
            runner.request(ROOT / "admission-request.template.json")

    def test_previous_release_request_cannot_cross_new_base_gate(self):
        previous = synthetic_request()
        previous["base_sha"] = "b678bc2f211c5f141751137453b199036491222d"
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "previous-operation.json"
            path.write_text(json.dumps(previous))
            with self.assertRaises(runner.Refusal):
                runner.request(path)

    def test_coordinator_must_be_exact_same_checked_pr_head(self):
        value = synthetic_request()
        value["coordinator_sha"] = "d" * 40
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "wrong-source.json"
            path.write_text(json.dumps(value))
            with self.assertRaisesRegex(runner.Refusal, "COORDINATOR_MUST_BE_EXACT_CHECKED_PR_HEAD"):
                runner.request(path)

    def test_unsealed_changed_core_cannot_be_admitted(self):
        value = synthetic_request()
        value["coordinator_core"]["tools/deployment_release.js"] = {"git_blob": None, "bytes": None}
        with self.assertRaisesRegex(runner.Refusal, "SEALED_COORDINATOR_PINS_REQUIRED"):
            runner.coordinator_pins(value)

    def test_original_base_dependencies_are_not_a_repaired_source(self):
        value = synthetic_request()
        blob, size = runner.BASE_CORE["tools/deployment_release.js"]
        value["coordinator_core"]["tools/deployment_release.js"] = {"git_blob": blob, "bytes": size}
        with self.assertRaisesRegex(runner.Refusal, "CHECKED_REPAIR_CORE_REQUIRED"):
            runner.coordinator_pins(value)

    def test_priority_pin_cannot_be_replaced_even_when_other_source_is_checked(self):
        value = synthetic_request()
        value["coordinator_core"]["tools/release_priority.js"]["git_blob"] = "e" * 40
        with self.assertRaisesRegex(runner.Refusal, "PRIORITY_MUST_BE_BYTE_EXACT_BASE"):
            runner.coordinator_pins(value)

    def test_actual_git_coordinator_is_repair_source_and_admission_base_stays_original(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            value = fixture_repository(root)
            self.assertNotEqual(value["coordinator_sha"], runner.BASE)
            verified = runner.verify_core(root, value)
            self.assertEqual(verified["tools/release_priority.js"]["git_blob"], "3c1b228d36785546d71fb9e21c68ee4b03961619")
            self.assertEqual(verified["tools/release_priority.js"]["sha256"], "880addcb078f8f92e0524a5b93de14ab2d1ed24d442180e6e253310038b65d62")
            self.assertEqual(runner.command(value, root)[-1], runner.BASE)
            altered = copy.deepcopy(value)
            altered["coordinator_sha"] = "f" * 40
            with self.assertRaisesRegex(runner.Refusal, "COORDINATOR_SOURCE_CHANGED"):
                runner.verify_core(root, altered)

    def test_priority_working_bytes_must_match_exact_base_blob(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            value = fixture_repository(root)
            path = root / "tools/release_priority.js"
            path.write_bytes(path.read_bytes() + b"// changed fixture\n")
            with self.assertRaisesRegex(runner.Refusal, "CANONICAL_GIT_BLOB_CHANGED"):
                runner.verify_core(root, value)

    def test_previous_five_key_admission_request_is_rejected(self):
        value = synthetic_request()
        del value["coordinator_sha"]
        del value["coordinator_core"]
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "previous-shape.json"
            path.write_text(json.dumps(value))
            with self.assertRaisesRegex(runner.Refusal, "REQUEST_SHAPE"):
                runner.request(path)


if __name__ == "__main__":
    unittest.main()
