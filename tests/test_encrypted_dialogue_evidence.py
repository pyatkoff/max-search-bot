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


class EvidenceTests(unittest.TestCase):
    def setUp(self):
        self.identity = pyrage.x25519.Identity.generate()
        self.recipient = str(self.identity.to_public())

    def build(self, report=None):
        return evidence.build_evidence([("live", report or fixture())], SHA, self.recipient)

    def test_minimization_retains_debuggable_message_order(self):
        data = self.build()
        report = json.loads(data)
        messages = report["sessions"][0]["messages"]
        self.assertIn("Египет на 7 ночей, 2 взрослых", messages[0]["text"])
        self.assertEqual(messages[1]["text"], "SYNTHETIC_PRIVATE_MESSAGE")
        for secret in (b"987654", b"private-login", b"hidden-user", b"999", b"example.org", b"@contact"):
            self.assertNotIn(secret, data)

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
