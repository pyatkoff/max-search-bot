"""Synthetic-only tests: never load production conversations or owner keys."""

import contextlib
import datetime as dt
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest import mock

import pyrage


SCRIPT = Path(__file__).resolve().parents[1] / "tools/private_conversation_corpus_crypto.py"
SPEC = importlib.util.spec_from_file_location("private_corpus_crypto", SCRIPT)
crypto = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(crypto)


class PrivateConversationCorpusCryptoTests(unittest.TestCase):
    def setUp(self):
        self.now = dt.datetime.now(dt.timezone.utc)
        self.sha = "a" * 40
        self.identity = pyrage.x25519.Identity.generate()
        self.recipient = self.identity.to_public()
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.identity_path = self.base / "owner.agekey"
        self.identity_path.write_text(str(self.identity) + "\n", encoding="ascii")
        self.identity_path.chmod(0o600)
        self.recipient_path = self.base / "recipient.txt"
        self.recipient_path.write_text(str(self.recipient) + "\n", encoding="ascii")
        self.private = self.base / "private"
        self.private.mkdir(mode=0o700)
        self.output = self.private / "corpus.jsonl"

    def records(self, *, generated=None, finished=None, text=None):
        counts = dict.fromkeys(crypto.COUNT_KEYS, 1)
        moment = generated if generated is not None else self.now.isoformat()
        return [
            {"type": "header", "schema": crypto.CORPUS_SCHEMA,
             "repository": crypto.REPOSITORY, "production_sha": self.sha,
             "generated_at": moment, "project_scope": ["anytour"],
             "upper_ids": dict.fromkeys(crypto.COUNT_KEYS, 100),
             "expected_counts": counts,
             "privacy": {"full_message_text": True, "contacts": "best_effort_redacted"},
             "snapshot": {"isolation": "REPEATABLE READ", "read_only": True}},
            {"type": "conversation", "id": "c_" + "1" * 24},
            {"type": "message", "id": "m_" + "2" * 24,
             "text": text if text is not None else "Synthetic question about a trip"},
            {"type": "event", "id": "e_" + "3" * 24},
            {"type": "assignment", "id": "a_" + "4" * 24},
            {"type": "manager", "id": "g_" + "5" * 24},
            {"type": "footer", "schema": crypto.CORPUS_SCHEMA, "complete": True,
             "expected_counts": counts, "exported_counts": counts,
             "statistics": {}, "finished_at": finished if finished is not None else moment},
        ]

    @staticmethod
    def encoded(records):
        return b"".join((json.dumps(row, ensure_ascii=False, separators=(",", ":"))
                          + "\n").encode("utf-8") for row in records)

    def encrypted(self, **options):
        data = self.encoded(self.records(**options))
        ciphertext, manifest = crypto.encrypt_corpus(data, self.recipient, self.sha, now=self.now)
        return data, ciphertext, manifest

    def decrypt(self, ciphertext, *, identity=None, sha=None, digest=None, **options):
        return crypto.decrypt_corpus(
            ciphertext, identity if identity is not None else self.identity,
            sha if sha is not None else self.sha,
            digest if digest is not None else hashlib.sha256(ciphertext).hexdigest(),
            now=self.now, **options)

    def decrypt_cli(self, ciphertext, *, sha=None, identity_path=None, digest=None):
        encrypted_path = self.base / "capture.age"
        encrypted_path.write_bytes(ciphertext)
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            status = crypto.main([
                "decrypt", "--identity", str(identity_path or self.identity_path),
                "--sha", sha or self.sha, "--input", str(encrypted_path),
                "--ciphertext-sha256", digest or hashlib.sha256(ciphertext).hexdigest(),
                "--output", str(self.output),
            ])
        return status, out.getvalue(), err.getvalue()

    def test_complete_roundtrip_preserves_full_long_utf8_text_and_exact_bytes(self):
        text = ("Синтетическая поездка: даты изменились.\nСтрока с emoji 🏖 и бюджетом. " * 9000
                + "END_UNTRUNCATED_0123456789")
        data, ciphertext, manifest = self.encrypted(text=text)
        self.assertGreater(len(text), 600000)
        self.assertEqual(self.decrypt(ciphertext), data)
        self.assertEqual(json.loads(data.splitlines()[2])["text"], text)
        self.assertEqual(manifest["counts"], dict.fromkeys(crypto.COUNT_KEYS, 1))
        self.assertEqual(manifest["plaintext_sha256"], hashlib.sha256(data).hexdigest())
        self.assertEqual(manifest["ciphertext_sha256"], hashlib.sha256(ciphertext).hexdigest())
        self.assertNotIn("END_UNTRUNCATED", json.dumps(manifest))
        self.assertNotIn(text.encode("utf-8"), ciphertext)

    def test_wrong_key_fails_without_output_or_sensitive_logs(self):
        _, ciphertext, _ = self.encrypted(text="PRIVATE_TEST_SENTINEL")
        other = self.base / "wrong.agekey"
        wrong_identity = pyrage.x25519.Identity.generate()
        other.write_text(str(wrong_identity), encoding="ascii")
        other.chmod(0o600)
        status, out, err = self.decrypt_cli(ciphertext, identity_path=other)
        self.assertEqual(status, 1)
        self.assertFalse(self.output.exists())
        self.assertNotIn("PRIVATE_TEST_SENTINEL", out + err)
        self.assertNotIn(str(wrong_identity), out + err)
        self.assertEqual(err, "Private corpus operation failed; no plaintext was published\n")

    def test_tampered_age_payload_fails_even_with_recomputed_ciphertext_digest(self):
        _, ciphertext, _ = self.encrypted()
        tampered = ciphertext[:-1] + bytes([ciphertext[-1] ^ 1])
        status, _, _ = self.decrypt_cli(tampered)
        self.assertEqual(status, 1)
        self.assertFalse(self.output.exists())

    def test_wrong_ciphertext_digest_fails(self):
        _, ciphertext, _ = self.encrypted()
        status, _, _ = self.decrypt_cli(ciphertext, digest="0" * 64)
        self.assertEqual(status, 1)
        self.assertFalse(self.output.exists())

    def test_wrong_exact_production_sha_fails_without_output(self):
        _, ciphertext, _ = self.encrypted()
        status, _, _ = self.decrypt_cli(ciphertext, sha="b" * 40)
        self.assertEqual(status, 1)
        self.assertFalse(self.output.exists())
        for bad in ["main", "a" * 7, "A" * 40, self.sha + "0"]:
            with self.subTest(sha=bad), self.assertRaises(crypto.CorpusError):
                crypto.validate_corpus(self.encoded(self.records()), bad, now=self.now)

    def test_stale_capture_rejected_on_encrypt(self):
        old = (self.now - dt.timedelta(seconds=3601)).isoformat()
        with self.assertRaises(crypto.CorpusError):
            crypto.encrypt_corpus(self.encoded(self.records(generated=old)),
                                  self.recipient, self.sha, now=self.now)

    def test_capture_expires_on_decrypt_without_output(self):
        _, ciphertext, _ = self.encrypted()
        with self.assertRaises(crypto.CorpusError):
            crypto.decrypt_corpus(ciphertext, self.identity, self.sha,
                                  hashlib.sha256(ciphertext).hexdigest(),
                                  now=self.now + dt.timedelta(seconds=3601))
        self.assertFalse(self.output.exists())

    def test_one_hour_boundary_is_allowed(self):
        boundary = (self.now - dt.timedelta(seconds=3600)).isoformat()
        data, ciphertext, _ = self.encrypted(generated=boundary)
        self.assertEqual(self.decrypt(ciphertext), data)

    def test_future_naive_and_out_of_order_timestamps_fail(self):
        invalid = [
            {"generated": (self.now + dt.timedelta(seconds=61)).isoformat()},
            {"generated": self.now.replace(tzinfo=None).isoformat()},
            {"finished": (self.now - dt.timedelta(seconds=1)).isoformat()},
        ]
        for options in invalid:
            with self.subTest(options=options), self.assertRaises(crypto.CorpusError):
                crypto.validate_corpus(self.encoded(self.records(**options)), self.sha, now=self.now)

    def test_missing_footer_false_complete_and_count_mismatches_fail(self):
        cases = []
        cases.append(self.records()[:-1])
        incomplete = self.records()
        incomplete[-1]["complete"] = False
        cases.append(incomplete)
        cases.append(self.records()[:2] + self.records()[3:])
        mismatch = self.records()
        mismatch[-1]["exported_counts"] = dict.fromkeys(crypto.COUNT_KEYS, 2)
        cases.append(mismatch)
        expected_mismatch = self.records()
        expected_mismatch[-1]["expected_counts"] = dict.fromkeys(crypto.COUNT_KEYS, 2)
        cases.append(expected_mismatch)
        for records in cases:
            with self.subTest(records=len(records)), self.assertRaises(crypto.CorpusError):
                crypto.encrypt_corpus(self.encoded(records), self.recipient, self.sha, now=self.now)

    def test_record_after_footer_duplicate_id_unknown_type_and_blank_line_fail(self):
        records = self.records()
        cases = [self.encoded(records + [records[2]]),
                 self.encoded(records[:-1] + [records[2], records[-1]]),
                 self.encoded(records).replace(b'"type":"message"', b'"type":"unknown"'),
                 self.encoded(records) + b"\n",
                 self.encoded(records).rstrip(b"\n")]
        for data in cases:
            with self.subTest(size=len(data)), self.assertRaises(crypto.CorpusError):
                crypto.validate_corpus(data, self.sha, now=self.now)

    def test_non_readonly_snapshot_wrong_scope_wrong_repo_and_bool_counts_fail(self):
        for modify in [
            lambda row: row.update(repository="other/repository"),
            lambda row: row.update(project_scope=["other"]),
            lambda row: row["snapshot"].update(read_only=False),
            lambda row: row["snapshot"].update(isolation="READ COMMITTED"),
            lambda row: row["expected_counts"].update(messages=True),
            lambda row: row["expected_counts"].update(messages=-1),
        ]:
            records = self.records()
            modify(records[0])
            with self.assertRaises(crypto.CorpusError):
                crypto.validate_corpus(self.encoded(records), self.sha, now=self.now)

    def test_json_duplicate_keys_invalid_constant_and_invalid_utf8_fail(self):
        data = self.encoded(self.records())
        cases = [data.replace(b'"type":"header"', b'"type":"header","type":"header"'),
                 data.replace(b'"statistics":{}', b'"statistics":{"value":NaN}'),
                 data.replace(b'"statistics":{}', b'"statistics":{"value":"\xff"}')]
        for value in cases:
            with self.assertRaises(crypto.CorpusError):
                crypto.validate_corpus(value, self.sha, now=self.now)

    def test_recipient_and_plaintext_digest_are_bound_inside_age(self):
        data, ciphertext, _ = self.encrypted()
        header, payload = pyrage.decrypt(ciphertext, [self.identity]).split(b"\n", 1)
        for field, value in [
            ("recipient", str(pyrage.x25519.Identity.generate().to_public())),
            ("plaintext_sha256", "0" * 64),
            ("plaintext_bytes", len(data) - 1),
        ]:
            envelope = json.loads(header)
            envelope[field] = value
            forged = pyrage.encrypt(json.dumps(envelope).encode() + b"\n" + payload,
                                    [self.recipient])
            with self.subTest(field=field), self.assertRaises(crypto.CorpusError):
                self.decrypt(forged)

    def test_size_limits_fail_closed_without_truncating(self):
        data, ciphertext, _ = self.encrypted()
        with self.assertRaises(crypto.CorpusError):
            crypto.encrypt_corpus(data, self.recipient, self.sha, now=self.now,
                                  max_bytes=len(data) - 1)
        with self.assertRaises(crypto.CorpusError):
            self.decrypt(ciphertext, max_bytes=len(data) - 1)
        for limit in [0, -1, True, crypto.MAX_PLAINTEXT_BYTES + 1]:
            with self.subTest(limit=limit), self.assertRaises(crypto.CorpusError):
                crypto.validate_corpus(data, self.sha, now=self.now, max_bytes=limit)
        with mock.patch.object(crypto, "MAX_CIPHERTEXT_BYTES", len(ciphertext) - 1):
            with self.assertRaises(crypto.CorpusError):
                self.decrypt(ciphertext)

    def test_decrypt_cli_creates_only_private_external_exact_output(self):
        data, ciphertext, _ = self.encrypted()
        self.output = self.private / "new" / "nested" / "corpus.jsonl"
        status, out, err = self.decrypt_cli(ciphertext)
        self.assertEqual(status, 0, err)
        self.assertEqual(self.output.read_bytes(), data)
        self.assertEqual(stat.S_IMODE(self.output.stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(self.output.parent.stat().st_mode), 0o700)
        self.assertEqual(out, "Private corpus operation succeeded\n")
        self.assertEqual(err, "")

    def test_no_overwrite_and_no_final_or_parent_symlinks(self):
        _, ciphertext, _ = self.encrypted()
        self.output.write_bytes(b"existing")
        status, _, _ = self.decrypt_cli(ciphertext)
        self.assertEqual(status, 1)
        self.assertEqual(self.output.read_bytes(), b"existing")
        self.output.unlink()
        target = self.private / "untouched"
        target.write_bytes(b"existing target")
        self.output.symlink_to(target)
        status, _, _ = self.decrypt_cli(ciphertext)
        self.assertEqual(status, 1)
        self.assertEqual(target.read_bytes(), b"existing target")
        self.output.unlink()
        linked = self.base / "linked"
        linked.symlink_to(self.private, target_is_directory=True)
        self.output = linked / "no.jsonl"
        status, _, _ = self.decrypt_cli(ciphertext)
        self.assertEqual(status, 1)
        self.assertFalse((self.private / "no.jsonl").exists())

    def test_nonprivate_directory_and_checkout_output_are_rejected(self):
        _, ciphertext, _ = self.encrypted()
        self.private.chmod(0o755)
        status, _, _ = self.decrypt_cli(ciphertext)
        self.assertEqual(status, 1)
        self.assertFalse(self.output.exists())
        self.private.chmod(0o700)
        with self.assertRaises(crypto.CorpusError):
            crypto.private_write_many([(self.output, b"synthetic")], checkout_root=self.base)
        self.assertFalse(self.output.exists())

    def test_write_failure_removes_partial_outputs_and_preserves_existing_files(self):
        encrypted = self.private / "corpus.age"
        manifest = self.private / "manifest.json"
        manifest.write_bytes(b"existing manifest")
        with self.assertRaises(FileExistsError):
            crypto.private_write_many([(encrypted, b"ciphertext"), (manifest, b"new manifest")])
        self.assertFalse(encrypted.exists())
        self.assertEqual(manifest.read_bytes(), b"existing manifest")
        with mock.patch.object(crypto.os, "fsync", side_effect=OSError("PRIVATE_ERROR_SENTINEL")):
            with self.assertRaises(OSError):
                crypto.private_write_many([(self.output, b"PRIVATE_TEST_SENTINEL")])
        self.assertFalse(self.output.exists())

    def test_encrypt_cli_supports_stdin_and_manifest_without_printing_text(self):
        data = self.encoded(self.records(text="PRIVATE_TEST_SENTINEL"))
        ciphertext_path = self.private / "corpus.age"
        manifest_path = self.private / "manifest.json"
        out, err = io.StringIO(), io.StringIO()
        with mock.patch.object(crypto.sys, "stdin", io.TextIOWrapper(io.BytesIO(data))), \
                contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            status = crypto.main([
                "encrypt", "--recipient", str(self.recipient_path), "--sha", self.sha,
                "--input", "-", "--output", str(ciphertext_path),
                "--manifest", str(manifest_path),
            ])
        self.assertEqual(status, 0, err.getvalue())
        manifest = json.loads(manifest_path.read_bytes())
        ciphertext = ciphertext_path.read_bytes()
        self.assertEqual(manifest["ciphertext_sha256"], hashlib.sha256(ciphertext).hexdigest())
        self.assertEqual(self.decrypt(ciphertext), data)
        self.assertNotIn("PRIVATE_TEST_SENTINEL", out.getvalue() + err.getvalue())
        self.assertNotIn("PRIVATE_TEST_SENTINEL", manifest_path.read_text())
        self.assertEqual(stat.S_IMODE(ciphertext_path.stat().st_mode), 0o600)

    def test_incomplete_encrypt_cli_publishes_neither_ciphertext_nor_manifest(self):
        input_path = self.base / "incomplete.jsonl"
        input_path.write_bytes(self.encoded(self.records()[:-1]))
        encrypted = self.private / "corpus.age"
        manifest = self.private / "manifest.json"
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            status = crypto.main([
                "encrypt", "--recipient", str(self.recipient_path), "--sha", self.sha,
                "--input", str(input_path), "--output", str(encrypted),
                "--manifest", str(manifest),
            ])
        self.assertEqual(status, 1)
        self.assertFalse(encrypted.exists())
        self.assertFalse(manifest.exists())

    def test_identity_file_must_be_private_regular_and_not_symlink(self):
        _, ciphertext, _ = self.encrypted()
        self.identity_path.chmod(0o644)
        self.assertEqual(self.decrypt_cli(ciphertext)[0], 1)
        self.assertFalse(self.output.exists())
        self.identity_path.chmod(0o600)
        linked = self.base / "linked-key"
        linked.symlink_to(self.identity_path)
        self.assertEqual(self.decrypt_cli(ciphertext, identity_path=linked)[0], 1)
        self.assertFalse(self.output.exists())


if __name__ == "__main__":
    unittest.main()
