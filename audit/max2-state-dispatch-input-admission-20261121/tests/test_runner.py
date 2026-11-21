"""Source-only fixtures. No real token, API, canonical CLI or admission is run."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("admission_runner", ROOT / "runner.py")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class PreparationOnly(unittest.TestCase):
    def concrete(self):
        return {"version": 1, "repository": runner.REPOSITORY, "pr_number": 123,
                "head_sha": "a" * 40, "base_sha": runner.BASE}

    def read_request(self, value):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "synthetic.json"
            path.write_text(json.dumps(value))
            return runner.request(path)

    def test_null_template_and_optional_root_sealed_request(self):
        for name in ("admission-request.template.json", "admission-request.json"):
            value = json.loads((ROOT / name).read_text())
            self.assertEqual(value["base_sha"], runner.BASE)
            if name.endswith(".template.json") or value["pr_number"] is None:
                self.assertIsNone(value["pr_number"])
                self.assertIsNone(value["head_sha"])
                with self.assertRaises(runner.Refusal):
                    runner.request(ROOT / name)
            else:
                # Root may seal this excluded request after exact-head CI;
                # sealing does not require changing any fixture or source pin.
                self.assertEqual(runner.request(ROOT / name)[0], value)

    def test_exact_request_and_full_cli_argument_shape(self):
        value = self.concrete()
        result, digest = self.read_request(value)
        self.assertEqual(result, value)
        self.assertEqual(len(digest), 64)
        self.assertEqual(runner.command(result, Path("/synthetic/base")),
                         ["node", "/synthetic/base/tools/release_priority.js", "--admit",
                          "123", "a" * 40, runner.BASE])

    def test_recovery_coordinator_and_publisher_overrides_rejected(self):
        for extra in ({"coordinator_sha": "a" * 40}, {"coordinator_core": {}},
                      {"publisher": {"comment_id": 1}}, {"run_id": 44919222495}):
            with self.assertRaises(runner.Refusal):
                self.read_request(self.concrete() | extra)

    def test_stale_base_wrong_repository_and_nonconcrete_source_rejected(self):
        for change in ({"base_sha": "cabc34b7166994c65816ec8c81e48895ea889683"},
                       {"base_sha": "16631e06f4eca1f95ee7a5216a1c5e07e563d06d"},
                       {"repository": "synthetic/other"}, {"head_sha": runner.BASE},
                       {"pr_number": True}, {"head_sha": None}, {"version": True}):
            with self.assertRaises(runner.Refusal):
                self.read_request(self.concrete() | change)

    def test_duplicate_request_keys_rejected(self):
        with self.assertRaises(runner.Refusal):
            json.loads('{"version":1,"version":1}', object_pairs_hook=runner.unique_object)

    def test_missing_native_context_has_no_admission(self):
        with self.assertRaises(runner.Refusal):
            runner.native_context({})

    def synthetic_native(self, directory):
        path = Path(directory) / "event.json"
        event = {"repository": {"full_name": runner.REPOSITORY},
                 "ref": "refs/heads/" + runner.BRANCH, "after": "b" * 40,
                 "deleted": False, "forced": False, "created": True,
                 "before": "0" * 40, "sender": {"login": "pyatkoff"},
                 "head_commit": {"id": "b" * 40, "message": runner.MESSAGE}}
        path.write_text(json.dumps(event))
        env = {"GITHUB_ACTIONS": "true", "GITHUB_REPOSITORY": runner.REPOSITORY,
               "GITHUB_EVENT_NAME": "push", "GITHUB_ACTOR": "pyatkoff",
               "GITHUB_RUN_ATTEMPT": "1", "GITHUB_REF": "refs/heads/" + runner.BRANCH,
               "GITHUB_WORKFLOW_REF": runner.REPOSITORY + "/" + runner.WORKFLOW +
               "@refs/heads/" + runner.BRANCH, "GITHUB_JOB": "canonical-readonly",
               "GITHUB_SHA": "b" * 40, "GITHUB_RUN_ID": "1001", "GITHUB_EVENT_PATH": str(path)}
        return env, event, path

    def test_rerun_dispatch_foreign_owner_and_old_workflow_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            env, _, _ = self.synthetic_native(directory)
            runner.native_context(env)  # Pure shape test only, never real native proof.
            for change in ({"GITHUB_RUN_ATTEMPT": "2"}, {"GITHUB_EVENT_NAME": "workflow_dispatch"},
                           {"GITHUB_ACTOR": "synthetic-other"},
                           {"GITHUB_REF": "refs/heads/audit/max2-repaired-release-admission-20261121"}):
                with self.assertRaises(runner.Refusal):
                    runner.native_context(env | change)

    def test_nonoriginal_push_and_changed_request_source_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            env, event, path = self.synthetic_native(directory)
            for change in ({"created": False}, {"forced": True}, {"before": "c" * 40},
                           {"after": "d" * 40}, {"sender": {"login": "synthetic-other"}},
                           {"head_commit": {"id": "b" * 40, "message": "different operation"}}):
                path.write_text(json.dumps(event | change))
                with self.assertRaises(runner.Refusal):
                    runner.native_context(env)

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
        self.assertEqual(runner.canonical_result(1, b"", b"synthetic private URL or bearer\n"),
                         {"status": "REFUSED", "reason": "canonical_refusal"})

    def test_actual_five_key_initial_admission_shapes_are_preserved(self):
        for decision in [
            {"allowed": True, "reason": "no_active_reservation", "holder_pr": None,
             "expires_at": None, "active_reservations": 0},
            {"allowed": True, "reason": "priority_owned", "holder_pr": 123,
             "expires_at": "2026-11-21T18:00:00.000Z", "active_reservations": 2},
        ]:
            raw = ("release-priority: " + json.dumps(decision, separators=(",", ":")) + "\n").encode()
            self.assertEqual(runner.canonical_result(0, raw, b""), {"status": "ADMITTED", "decision": decision})
            with self.assertRaises(runner.Refusal):
                runner.canonical_result(1, raw, b"")

    def test_manual_continuation_and_extra_output_are_rejected(self):
        for decision in [
            {"allowed": True, "reason": "manual_checks_only", "holder_pr": None, "expires_at": None},
            {"allowed": True, "reason": "active_publisher", "holder_pr": 123,
             "expires_at": None, "active_reservations": 1, "publisher_comment": 7063060212},
        ]:
            with self.assertRaises(runner.Refusal):
                runner.canonical_result(0, ("release-priority: " + json.dumps(decision) + "\n").encode(), b"")
        decision = {"allowed": True, "reason": "no_active_reservation", "holder_pr": None,
                    "expires_at": None, "active_reservations": 0}
        raw = ("release-priority: " + json.dumps(decision) + "\n").encode()
        for stdout, stderr in ((raw + b"extra\n", b""), (raw, b"extra stderr\n")):
            with self.assertRaises(runner.Refusal):
                runner.canonical_result(0, stdout, stderr)

    def test_canonical_failure_reason_stays_refused(self):
        self.assertEqual(runner.canonical_result(1, b"", b"deployment-release: prior_release_pending\n"),
                         {"status": "REFUSED", "reason": "prior_release_pending"})

    def test_current_canonical_core_pins_and_priority_fixture(self):
        proof = json.loads((ROOT / "canonical-source-pins.json").read_text())
        self.assertEqual(proof["canonical_source_sha"], runner.BASE)
        self.assertEqual(set(proof["files"]), set(runner.CORE))
        for name, pin in proof["files"].items():
            self.assertEqual((pin["git_blob"], pin["bytes"]), runner.CORE[name])
        raw = (ROOT / "tests/fixtures/release_priority.js").read_bytes()
        pin = proof["files"]["tools/release_priority.js"]
        self.assertEqual(len(raw), pin["bytes"])
        self.assertEqual(hashlib.sha256(raw).hexdigest(), pin["sha256"])
        self.assertEqual(hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest(), pin["git_blob"])
        self.assertIn(b"await verifyPreviousRelease(context, env.GITHUB_TOKEN, fetchImpl);", raw)
        self.assertIn(b"fetchImpl: globalThis.fetch", raw)

    def test_core_verification_rejects_candidate_identity_and_byte_drift(self):
        # Actual priority bytes plus explicit synthetic neighbour fixtures test
        # filesystem identity checks; no canonical JavaScript is executed.
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "tools").mkdir()
            raws = {"tools/release_priority.js": (ROOT / "tests/fixtures/release_priority.js").read_bytes(),
                    "tools/deployment_release.js": b"synthetic dependency\n",
                    "tools/release_slot_close.js": b"synthetic closer\n"}
            pins = {}
            for name, raw in raws.items():
                (root / name).write_bytes(raw)
                pins[name] = (hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest(), len(raw))
            def git(_, *args):
                return runner.BASE if args == ("rev-parse", "HEAD") else pins[args[1][5:]][0]
            with patch.object(runner, "CORE", pins), patch.object(runner, "git", side_effect=git):
                self.assertEqual(set(runner.verify_core(root)), set(pins))
                (root / "tools/deployment_release.js").write_bytes(raws["tools/deployment_release.js"] + b"drift")
                with self.assertRaises(runner.Refusal):
                    runner.verify_core(root)
            with patch.object(runner, "git", return_value="a" * 40):
                with self.assertRaisesRegex(runner.Refusal, "BASE_COORDINATOR_CHANGED"):
                    runner.verify_core(root)

    def test_workflow_keeps_real_token_readonly_permissions_and_base_checkout(self):
        workflow = (ROOT / "workflow.yml").read_text()
        self.assertIn("GITHUB_TOKEN: ${{ github.token }}", workflow)
        self.assertIn("ref: ${{ steps.request.outputs.base_sha }}", workflow)
        self.assertIn("ref: ${{ steps.request.outputs.head_sha }}", workflow)
        self.assertEqual(workflow.count("persist-credentials: false"), 3)
        self.assertNotIn(": write", workflow)
        self.assertNotIn("secrets.", workflow)
        self.assertNotIn("workflow_dispatch:", workflow)
        self.assertNotIn("--publisher", workflow)
        self.assertNotIn("coordinator_sha }}", workflow)


if __name__ == "__main__":
    unittest.main()
