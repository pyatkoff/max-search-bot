"""Synthetic-only privacy, integrity and provenance regressions."""
import datetime as dt
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
import pyrage

ROOT = Path(__file__).resolve().parents[1]
TOOL = ROOT / "tools/encrypted_dialogue_evidence.py"
SPEC = importlib.util.spec_from_file_location("evidence", TOOL)
evidence = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(evidence)
SHA = "a" * 40


def fixture():
    now = dt.datetime.now(dt.timezone.utc).isoformat()
    return {"ok": True, "generated_at": now, "channel": "max", "since_utc": now,
            "window_type": "rolling_hours", "window_hours": 1,
            "sessions": [{"conversation_id": 987654, "channel": "max", "status": "ai",
                          "login": "private-login", "external_user_id": "hidden-user",
                          "is_test": False, "flags": ["repeated_callback_input"],
                          "manager_requested": True,
                          "message_tail": [
                              {"direction": "inbound", "sender_type": "customer", "created_at": now,
                               "text": "Египет на 7 ночей, 2 взрослых. +7 (999) 123-45-67 a@example.org https://example.org/?token=secret @contact"},
                              {"direction": "outbound", "sender_type": "ai", "created_at": now,
                               "text": "SYNTHETIC_PRIVATE_MESSAGE"}]}]}


def production_fixture():
    now = dt.datetime.now(dt.timezone.utc).isoformat()
    return {"ok": True, "production": {"sha": SHA}, "generated_at": now,
            "manager_ai_feedback": {"ok": True, "generated_at": now,
                                    "unknown_private_summary": "HIDDEN_SUMMARY"},
            "manager_ai_feedback_private": [{
                "draft_id": "deadbeef" * 4, "conversation_id": 987654, "message_id": 7654321,
                "proposed_text": "SYNTHETIC_PRIVATE_PROPOSAL +7 (999) 123-45-67 a@example.org https://example.org @contact",
                "current_sent_text": "SYNTHETIC_PRIVATE_SENT token=secret password=hidden",
                "proposed_text_truncated": False, "current_sent_text_truncated": False,
                "initial_text_available": True, "recorded_text_matches_proposal": False,
                "edited": True, "created_at": now, "sent_at": now,
                "manager_id": "HIDDEN_ACTOR", "project_key": "HIDDEN_PROJECT",
                "context_sha256": "HIDDEN_CONTEXT", "sent_text_sha256": "HIDDEN_HASH",
                "proposal_json": "HIDDEN_RAW_PROPOSAL", "extra": "HIDDEN_EXTRA"}]}


class EvidenceTests(unittest.TestCase):
    def setUp(self):
        self.identity = pyrage.x25519.Identity.generate()
        self.recipient = str(self.identity.to_public())

    def build(self, report=None, production=None):
        return evidence.build_evidence([("live", report or fixture())], SHA, self.recipient,
                                       production=production)

    def test_optional_feedback_projection_redacts_and_allowlists_private_fields(self):
        self.assertNotIn("manager_ai_feedback", json.loads(self.build()))
        data = self.build(production=production_fixture())
        report = json.loads(data)
        feedback = report["manager_ai_feedback"]
        self.assertTrue(feedback["ok"])
        self.assertEqual(feedback["limits"], {"samples": 10, "characters_per_text": 2000})
        sample = feedback["samples"][0]
        self.assertEqual(sample["session_ref"], report["sessions"][0]["session_ref"])
        self.assertEqual(set(sample), {"draft_ref", "session_ref", "message_ref", "proposed_text",
                                      "current_sent_text", "initial_text_available", "edited",
                                      "recorded_text_matches_proposal", "proposed_text_truncated",
                                      "current_sent_text_truncated", "created_at", "sent_at", "source"})
        self.assertEqual(sample["source"], "generated")
        self.assertIn("SYNTHETIC_PRIVATE_PROPOSAL", sample["proposed_text"])
        for marker in ("[NUMBER]", "[EMAIL]", "[URL]", "[HANDLE]"):
            self.assertIn(marker, sample["proposed_text"])
        self.assertEqual(sample["current_sent_text"], "SYNTHETIC_PRIVATE_SENT [CREDENTIAL] [CREDENTIAL]")
        for secret in ("deadbeef", "HIDDEN_", "example.org", "@contact", "secret", "password=hidden"):
            self.assertNotIn(secret.encode(), data)

    def test_prepared_source_survives_without_exposing_its_job_identity(self):
        production = production_fixture()
        sample = production["manager_ai_feedback_private"][0]
        sample.update(source="prepared", preparation_job_id="PRIVATE_JOB_ID")
        data = self.build(production=production)
        self.assertEqual(json.loads(data)["manager_ai_feedback"]["samples"][0]["source"], "prepared")
        self.assertNotIn(b"PRIVATE_JOB_ID", data)
        sample["source"] = "untrusted"
        with self.assertRaises(ValueError):
            self.build(production=production)

    def test_feedback_correlations_rotate_and_missing_identifiers_are_not_invented(self):
        production = production_fixture()
        production["manager_ai_feedback_private"] *= 2
        first = json.loads(self.build(production=production))["manager_ai_feedback"]["samples"]
        second = json.loads(self.build(production=production))["manager_ai_feedback"]["samples"]
        for field in ("draft_ref", "session_ref", "message_ref"):
            self.assertEqual(first[0][field], first[1][field])
            self.assertNotEqual(first[0][field], second[0][field])
        for field in ("draft_id", "conversation_id", "message_id"):
            production["manager_ai_feedback_private"][0].pop(field)
        sample = json.loads(self.build(production=production))["manager_ai_feedback"]["samples"][0]
        self.assertTrue(all(field not in sample for field in ("draft_ref", "session_ref", "message_ref")))

    def test_feedback_text_bounds_and_truthful_truncation_after_redaction(self):
        production = production_fixture()
        sample = production["manager_ai_feedback_private"][0]
        sample["proposed_text"] = "ю" * 2000
        sample["proposed_text_truncated"] = True
        sample["current_sent_text"] = "a@b.c " * 333
        result = json.loads(self.build(production=production))["manager_ai_feedback"]["samples"][0]
        self.assertEqual(result["proposed_text"], "ю" * 2000)
        self.assertEqual(len(result["current_sent_text"]), 2000)
        self.assertTrue(result["proposed_text_truncated"])
        self.assertTrue(result["current_sent_text_truncated"])
        self.assertNotIn("a@b.c", result["current_sent_text"])
        sample.update(proposed_text="short", proposed_text_truncated=False,
                      current_sent_text="short", current_sent_text_truncated=False)
        result = json.loads(self.build(production=production))["manager_ai_feedback"]["samples"][0]
        self.assertFalse(result["proposed_text_truncated"])
        self.assertFalse(result["current_sent_text_truncated"])
        production["manager_ai_feedback_private"] *= 10
        self.assertEqual(len(json.loads(self.build(production=production))["manager_ai_feedback"]["samples"]), 10)

    def test_feedback_empty_and_unavailable_are_distinct(self):
        production = production_fixture()
        production["manager_ai_feedback_private"] = []
        for available in (True, False):
            production["manager_ai_feedback"]["ok"] = available
            feedback = json.loads(self.build(production=production))["manager_ai_feedback"]
            self.assertEqual(feedback["ok"], available)
            self.assertEqual(feedback["samples"], [])
        production = production_fixture()
        sample = production["manager_ai_feedback_private"][0]
        sample["initial_text_available"] = False
        result = json.loads(self.build(production=production))["manager_ai_feedback"]["samples"][0]
        self.assertFalse(result["initial_text_available"])
        self.assertIn("SYNTHETIC_PRIVATE_PROPOSAL", result["proposed_text"])

    def test_feedback_snapshot_rejects_wrong_sha_staleness_and_malformed_shapes(self):
        mutations = [
            lambda p: p.update(ok=False),
            lambda p: p.update(production={"sha": "b" * 40}),
            lambda p: p.update(production=[]),
            lambda p: p.update(generated_at="2020-01-01T00:00:00Z"),
            lambda p: p["manager_ai_feedback"].update(generated_at="2020-01-01T00:00:00Z"),
            lambda p: p.pop("manager_ai_feedback"),
            lambda p: p.pop("manager_ai_feedback_private"),
            lambda p: p.update(manager_ai_feedback=[]),
            lambda p: p["manager_ai_feedback"].update(ok=1),
            lambda p: p["manager_ai_feedback"].update(ok=False),
            lambda p: p.update(manager_ai_feedback_private={}),
            lambda p: p.update(manager_ai_feedback_private=[None]),
            lambda p: p.update(manager_ai_feedback_private=p["manager_ai_feedback_private"] * 11),
        ]
        for mutate in mutations:
            production = production_fixture()
            mutate(production)
            with self.subTest(snapshot=production), self.assertRaises(ValueError):
                self.build(production=production)
        invalid_fields = [("proposed_text", "x" * 2001), ("current_sent_text", None),
                          ("initial_text_available", 1), ("recorded_text_matches_proposal", "true"),
                          ("edited", None), ("proposed_text_truncated", 0),
                          ("current_sent_text_truncated", "false"), ("created_at", None),
                          ("sent_at", "invalid"), ("draft_id", "not-an-id"),
                          ("conversation_id", True), ("message_id", -1)]
        for field, value in invalid_fields:
            production = production_fixture()
            production["manager_ai_feedback_private"][0][field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                self.build(production=production)

    def test_feedback_roundtrip_uses_same_age_integrity_contract(self):
        data = self.build(production=production_fixture())
        ciphertext = pyrage.encrypt(data, [self.identity.to_public()])
        self.assertNotIn(b"SYNTHETIC_PRIVATE_PROPOSAL", ciphertext)
        self.assertNotIn(b"SYNTHETIC_PRIVATE_SENT", ciphertext)
        self.assertEqual(pyrage.decrypt(ciphertext, [self.identity]), data)
        with self.assertRaises(Exception):
            pyrage.decrypt(ciphertext, [pyrage.x25519.Identity.generate()])
        damaged = ciphertext[:-1] + bytes([ciphertext[-1] ^ 1])
        with self.assertRaises(Exception):
            pyrage.decrypt(damaged, [self.identity])

    def test_minimization_retains_debuggable_message_order(self):
        source = fixture()
        now = dt.datetime.now(dt.timezone.utc)
        source["generated_at"] = source["since_utc"] = now.replace(microsecond=462999).isoformat()
        for message in source["sessions"][0]["message_tail"]:
            message["created_at"] = now.replace(microsecond=987654).isoformat()
        data = self.build(source)
        report = json.loads(data)
        session = report["sessions"][0]
        messages = session["messages"]
        self.assertIn("Египет на 7 ночей, 2 взрослых", messages[0]["text"])
        self.assertEqual(messages[1]["text"], "SYNTHETIC_PRIVATE_MESSAGE")
        for field in ("conversation_id", "login", "external_user_id"):
            self.assertNotIn(field, session)
        for secret in (b"private-login", b"hidden-user", b"example.org", b"@contact"):
            self.assertNotIn(secret, data)
        # Numeric tokens may also occur in legitimate timestamps or age recipients.
        for message in messages:
            for secret in ("987654", "999"):
                self.assertNotIn(secret, message["text"])
            self.assertEqual(message["created_at"], source["sessions"][0]["message_tail"][0]["created_at"])
        self.assertEqual(report["windows"][0]["generated_at"], source["generated_at"])
        self.assertEqual(report["windows"][0]["since_utc"], source["since_utc"])

    def test_test_sessions_and_unflagged_sessions_are_excluded(self):
        report = fixture()
        report["sessions"] += [dict(report["sessions"][0], is_test=True),
                               dict(report["sessions"][0], flags=[])]
        self.assertEqual(len(json.loads(self.build(report))["sessions"]), 1)

    def test_limits(self):
        report = fixture()
        report["sessions"][0]["message_tail"] *= 30
        report["sessions"][0]["message_tail"][0]["text"] = "ю" * 1000
        report["sessions"] *= 30
        result = json.loads(self.build(report))
        self.assertEqual(len(result["sessions"]), 20)
        self.assertEqual(result["omitted_sessions"], 10)
        self.assertTrue(all(len(s["messages"]) == 24 for s in result["sessions"]))
        self.assertTrue(all(len(m["text"]) <= 280 for s in result["sessions"] for m in s["messages"]))

    def test_aliases_correlate_within_capture_but_rotate_between_captures(self):
        report = fixture()
        report["sessions"] *= 2
        one, two = json.loads(self.build(report)), json.loads(self.build(report))
        self.assertEqual(one["sessions"][0]["session_ref"], one["sessions"][1]["session_ref"])
        self.assertNotEqual(one["sessions"][0]["session_ref"], two["sessions"][0]["session_ref"])

    def test_bad_sha_stale_snapshot_and_wrong_channel_fail_closed(self):
        with self.assertRaises(ValueError):
            evidence.build_evidence([("live", fixture())], "main", self.recipient)
        for key, value in [("generated_at", "2020-01-01T00:00:00Z"), ("channel", "telegram"), ("ok", False)]:
            report = fixture()
            report[key] = value
            with self.assertRaises(ValueError):
                self.build(report)

    def test_standard_age_roundtrip_and_integrity(self):
        data = self.build()
        encrypted = pyrage.encrypt(data, [self.identity.to_public()])
        self.assertNotIn(b"SYNTHETIC_PRIVATE_MESSAGE", encrypted)
        self.assertNotIn("Египет".encode(), encrypted)
        self.assertEqual(pyrage.decrypt(encrypted, [self.identity]), data)
        with self.assertRaises(Exception):
            pyrage.decrypt(encrypted, [pyrage.x25519.Identity.generate()])
        damaged = encrypted[:-1] + bytes([encrypted[-1] ^ 1])
        with self.assertRaises(Exception):
            pyrage.decrypt(damaged, [self.identity])

    def test_no_output_or_private_content_on_cli_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory)
            identity_file, encrypted_file, output = (parent / n for n in ("identity", "input.age", "output.json"))
            evidence.private_write(identity_file, str(self.identity).encode())
            data = self.build()
            encrypted_file.write_bytes(pyrage.encrypt(data, [self.identity.to_public()]))
            command = [sys.executable, str(TOOL), "decrypt", "--identity", str(identity_file),
                       "--input", str(encrypted_file), "--sha", "b" * 40, "--output", str(output)]
            failed = subprocess.run(command, capture_output=True)
            self.assertNotEqual(failed.returncode, 0)
            self.assertFalse(output.exists())
            self.assertNotIn(b"SYNTHETIC_PRIVATE_MESSAGE", failed.stdout + failed.stderr)
            command[command.index("--sha") + 1] = SHA
            succeeded = subprocess.run(command, capture_output=True)
            self.assertEqual(succeeded.returncode, 0)
            self.assertEqual(output.read_bytes(), data)
            self.assertEqual(output.stat().st_mode & 0o777, 0o600)
            self.assertNotIn(b"SYNTHETIC_PRIVATE_MESSAGE", succeeded.stdout + succeeded.stderr)
            with self.assertRaises(FileExistsError):
                evidence.private_write(output, b"overwrite")

    def test_private_files_cannot_be_written_to_checkout_or_symlink(self):
        with self.assertRaises(ValueError):
            evidence.private_write(ROOT / "private-evidence-test.json", b"secret")
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "target"
            target.write_bytes(b"unchanged")
            link = Path(directory) / "link"
            link.symlink_to(target)
            with self.assertRaises(ValueError):
                evidence.private_write(link, b"overwrite")
            self.assertEqual(target.read_bytes(), b"unchanged")

    def test_full_encrypt_cli_uses_all_three_windows_without_plaintext_stdout(self):
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory)
            recipient = parent / "recipient.txt"
            recipient.write_text(self.recipient)
            paths = []
            for name in ("live", "today", "yesterday"):
                report = fixture()
                if name != "live":
                    report.update(window_type="calendar_day", timezone="Europe/Kaliningrad", window_hours=None)
                path = parent / (name + ".json")
                path.write_text(json.dumps(report))
                paths.extend(["--" + name, str(path)])
            output = parent / "result.age"
            command = [sys.executable, str(TOOL), "encrypt", "--recipient", str(recipient),
                       "--sha", SHA, "--output", str(output)] + paths
            result = subprocess.run(command, capture_output=True)
            self.assertEqual(result.returncode, 0)
            self.assertNotIn(b"SYNTHETIC_PRIVATE_MESSAGE", result.stdout + result.stderr + output.read_bytes())
            decoded = json.loads(pyrage.decrypt(output.read_bytes(), [self.identity]))
            self.assertEqual([w["name"] for w in decoded["windows"]], ["live", "today", "yesterday"])
            self.assertEqual(decoded["production_sha"], SHA)

    def test_optional_production_cli_roundtrip_and_failures_never_publish_plaintext(self):
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory)
            recipient = parent / "recipient.txt"
            recipient.write_text(self.recipient)
            paths = []
            for name in ("live", "today", "yesterday"):
                report = fixture()
                if name != "live":
                    report.update(window_type="calendar_day", timezone="Europe/Kaliningrad", window_hours=None)
                path = parent / (name + ".json")
                path.write_text(json.dumps(report))
                paths.extend(["--" + name, str(path)])
            production = parent / "production.json"
            production.write_text(json.dumps(production_fixture()))
            output = parent / "result.age"
            command = [sys.executable, str(TOOL), "encrypt", "--recipient", str(recipient),
                       "--sha", SHA, "--output", str(output), "--production", str(production)] + paths
            result = subprocess.run(command, capture_output=True)
            self.assertEqual(result.returncode, 0)
            private_markers = (b"SYNTHETIC_PRIVATE_MESSAGE", b"SYNTHETIC_PRIVATE_PROPOSAL", b"SYNTHETIC_PRIVATE_SENT")
            for marker in private_markers:
                self.assertNotIn(marker, result.stdout + result.stderr + output.read_bytes())
            decoded = json.loads(pyrage.decrypt(output.read_bytes(), [self.identity]))
            self.assertEqual(len(decoded["manager_ai_feedback"]["samples"]), 1)
            self.assertEqual(output.stat().st_mode & 0o777, 0o600)
            output.unlink()
            wrong_sha, stale = production_fixture(), production_fixture()
            wrong_sha["production"]["sha"] = "b" * 40
            stale["generated_at"] = "2020-01-01T00:00:00Z"
            for invalid in (None, [], wrong_sha, stale, {"raw": "SYNTHETIC_PRIVATE_PROPOSAL"}):
                production.write_text(json.dumps(invalid))
                failed = subprocess.run(command, capture_output=True)
                self.assertNotEqual(failed.returncode, 0)
                self.assertFalse(output.exists())
                self.assertEqual(failed.stdout, b"")
                for marker in private_markers:
                    self.assertNotIn(marker, failed.stderr)
            with production.open("wb") as stream:
                stream.truncate(evidence.MAX_INPUT + 1)
            failed = subprocess.run(command, capture_output=True)
            self.assertNotEqual(failed.returncode, 0)
            self.assertFalse(output.exists())
            self.assertIn(b"no plaintext was published", failed.stderr)

    def test_decrypt_rejects_old_authenticated_capture(self):
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory)
            identity = parent / "identity"
            evidence.private_write(identity, str(self.identity).encode())
            data = json.loads(self.build())
            data["generated_at"] = "2020-01-01T00:00:00Z"
            encrypted = parent / "stale.age"
            encrypted.write_bytes(pyrage.encrypt(json.dumps(data).encode(), [self.identity.to_public()]))
            output = parent / "plain.json"
            result = subprocess.run([sys.executable, str(TOOL), "decrypt", "--identity", str(identity),
                                     "--input", str(encrypted), "--sha", SHA, "--output", str(output)],
                                    capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(output.exists())

    def test_publisher_upload_is_ciphertext_only_after_plaintext_cleanup(self):
        workflow = (ROOT / ".github/workflows/publish-conversation-diagnostics.yml").read_text()
        upload = workflow.split("- name: Upload encrypted dialogue evidence", 1)[1].split("- name:", 1)[0]
        self.assertIn("path: ${{ runner.temp }}/dialogue-evidence.age", upload)
        self.assertIn("retention-days: 1", upload)
        self.assertIn("if-no-files-found: error", upload)
        self.assertNotIn("*.json", upload)
        self.assertLess(workflow.index("rm -f production_snapshot.json"), workflow.index("- name: Upload encrypted"))
        self.assertIn("- name: Remove private capture files\n        if: always()", workflow)
        self.assertIn("test \"$(git rev-parse refs/remotes/origin/main)\" = \"$TARGET_SHA\"", workflow)


if __name__ == "__main__":
    unittest.main()
