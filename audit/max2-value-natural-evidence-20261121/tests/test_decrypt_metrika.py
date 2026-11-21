"""Local synthetic artifacts only; never reads an operational key or calls a service."""
import base64
import contextlib
import copy
import hashlib
import io
import json
import os
import runpy
import stat
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

HERE = Path(__file__).resolve().parents[1]

class DecryptTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.key = rsa.generate_private_key(public_exponent=65537, key_size=3072)
        cls.private = cls.key.private_bytes(serialization.Encoding.PEM,
                                            serialization.PrivateFormat.PKCS8,
                                            serialization.NoEncryption())
        cls.public = cls.key.public_key().public_bytes(
            serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo)

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.m = runpy.run_path(str(HERE / 'decrypt_metrika.py'), run_name='source_fixture')
        fixture = runpy.run_path(str(HERE / 'tests' / 'test_metrika.py'), run_name='source_fixture')
        model = fixture['m']; model.reset()
        self.report = model.collect(lambda path, plan: fixture['body']())
        self.report.update(local_config_verified=True, installed_sha='b' * 40,
            install_receipt_sha256=model.INSTALL_RECEIPT_SHA256,
            finished_at_utc=self.report['started_at_utc'],
            requests=[{'endpoint': model.REPORT_PATH, 'query': 'two_new_goal_totals', 'attempt': 1, 'http': 200}])
        self.private_path = self.directory / 'synthetic-private.pem'
        self.private_path.write_bytes(self.private)
        self.private_path.chmod(0o600)
        self.archive = self.directory / 'synthetic-artifact.zip'
        self.expected = {
            'schema': 1, 'repository': 'pyatkoff/max-search-bot',
            'branch': 'audit/max2-new-value-natural-20261121',
            'workflow_sha': 'a'*40, 'run_id': '1001', 'job_id': '1002',
            'run_attempt': 1, 'artifact_id': '1003',
            'artifact_name': 'max2-new-value-natural-98615635-20261121',
            'archive_sha256': '0'*64,
            'reader_sha256': hashlib.sha256((HERE/'metrika_reader.py').read_bytes()).hexdigest(),
            'runner_sha256': hashlib.sha256((HERE/'metrika_runner.py').read_bytes()).hexdigest(),
            'report_public_key_sha256': hashlib.sha256(self.public).hexdigest(),
        }

    def package(self, envelope_change=None, extra=None, symlink=False, oversized=False):
        key, nonce = AESGCM.generate_key(bit_length=256), os.urandom(12)
        aad = (self.m['AAD_PREFIX'] + self.expected['workflow_sha']).encode()
        plaintext = json.dumps(self.report, ensure_ascii=False, allow_nan=False).encode()
        b64 = lambda value: base64.b64encode(value).decode()
        envelope = {
            'schema': 1, 'workflow_sha': self.expected['workflow_sha'],
            'aad': b64(aad), 'nonce': b64(nonce),
            'wrapped_key': b64(self.key.public_key().encrypt(key, padding.OAEP(
                mgf=padding.MGF1(hashes.SHA256()), algorithm=hashes.SHA256(), label=None))),
            'ciphertext': b64(AESGCM(key).encrypt(nonce, plaintext, aad)),
        }
        if envelope_change:
            envelope_change(envelope)
        body = json.dumps(envelope).encode()
        if oversized:
            body = b'x' * (self.m['MAX_ENVELOPE'] + 1)
        with zipfile.ZipFile(self.archive, 'w', compression=zipfile.ZIP_DEFLATED) as z:
            member = zipfile.ZipInfo(self.m['MEMBER'])
            member.create_system = 3
            member.external_attr = ((stat.S_IFLNK | 0o777) if symlink else (stat.S_IFREG | 0o600)) << 16
            member.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(member, body)
            if extra:
                z.writestr(extra, b'extra')
        self.expected['archive_sha256'] = hashlib.sha256(self.archive.read_bytes()).hexdigest()
        return plaintext

    def decrypt(self):
        return self.m['decrypt_archive'](self.archive, self.private_path, self.expected)

    def test_actual_reader_report_authenticated_roundtrip(self):
        plaintext = self.package()
        report, provenance = self.decrypt()
        self.assertEqual(report, self.report)
        self.assertEqual(provenance['plaintext_sha256'], hashlib.sha256(plaintext).hexdigest())
        self.assertFalse(provenance['network_performed_by_helper'])
        self.assertNotIn('synthetic-private', json.dumps(provenance))
        self.assertNotIn(self.private.decode(), json.dumps(provenance))

    def test_digest_source_and_attempt_reject_before_private_key_read(self):
        self.package()
        original = copy.deepcopy(self.expected)
        for field, value, code in [
            ('archive_sha256', '0'*64, 'ARCHIVE_DIGEST_MISMATCH'),
            ('workflow_sha', 'b'*40, 'ENVELOPE_SOURCE_OR_SCHEMA'),
            ('reader_sha256', '0'*64, 'LOCAL_REVIEWED_SOURCE_CHANGED'),
            ('run_attempt', 2, 'EXPECTED_SCOPE_MISMATCH'),
            ('artifact_name', 'other-artifact', 'EXPECTED_SCOPE_MISMATCH'),
            ('branch', 'main', 'EXPECTED_SCOPE_MISMATCH'),
        ]:
            with self.subTest(field=field):
                self.expected = {**original, field: value}
                with self.assertRaises(self.m['SafeStop']) as error:
                    self.m['decrypt_archive'](self.archive, self.directory/'missing-private', self.expected)
                self.assertEqual(str(error.exception), code)

    def test_tampered_aad_ciphertext_and_key_binding(self):
        for mutation, code in [
            (lambda e: e.update(aad=base64.b64encode(b'wrong scope').decode()), 'ENVELOPE_AAD_MISMATCH'),
            (lambda e: e.update(ciphertext=base64.b64encode(b'x'*100).decode()), 'AUTHENTICATED_DECRYPT_FAILED'),
            (lambda e: e.update(nonce=base64.b64encode(b'x'*11).decode()), 'ENVELOPE_CRYPTO_LENGTH'),
        ]:
            with self.subTest(code=code):
                self.package(envelope_change=mutation)
                with self.assertRaises(self.m['SafeStop']) as error:
                    self.decrypt()
                self.assertEqual(str(error.exception), code)
        self.package()
        self.expected['report_public_key_sha256'] = '0'*64
        with self.assertRaises(self.m['SafeStop']) as error:
            self.decrypt()
        self.assertEqual(str(error.exception), 'PRIVATE_PUBLIC_KEY_BINDING_MISMATCH')

    def test_extra_symlink_and_oversized_archive_members(self):
        for options, code in [
            ({'extra': 'unexpected.json'}, 'ARTIFACT_MEMBER_COUNT'),
            ({'extra': '../outside.json'}, 'ARTIFACT_MEMBER_COUNT'),
            ({'symlink': True}, 'ARTIFACT_MEMBER_INVALID'),
            ({'oversized': True}, 'ARTIFACT_MEMBER_INVALID'),
        ]:
            with self.subTest(options=options):
                self.package(**options)
                with self.assertRaises(self.m['SafeStop']) as error:
                    self.decrypt()
                self.assertEqual(str(error.exception), code)
        self.assertFalse((self.directory.parent/'outside.json').exists())

    def test_plaintext_privacy_scope_and_goal_are_validated(self):
        original = copy.deepcopy(self.report)
        for modify, code in [
            (lambda r: r.update(database_opened=True), 'REPORT_SCOPE_OR_PROOF_INVALID'),
            (lambda r: r.update(date1='2026-11-20'), 'REPORT_SCOPE_OR_PROOF_INVALID'),
            (lambda r: r['goals'][1].update(goal_id=678187097), 'REPORT_SCOPE_OR_PROOF_INVALID'),
            (lambda r: r.update(client_id='PRIVATE_FIXTURE'), 'PLAINTEXT_PRIVATE_FIELD'),
            (lambda r: r['requests'][0].update(attempt=2), 'REPORT_SCOPE_OR_PROOF_INVALID'),
        ]:
            with self.subTest(code=code):
                self.report = copy.deepcopy(original)
                modify(self.report)
                self.package()
                with self.assertRaises(self.m['SafeStop']) as error:
                    self.decrypt()
                self.assertEqual(str(error.exception), code)

    def test_private_key_permissions_and_symlinks_fail_closed(self):
        self.package()
        self.private_path.chmod(0o644)
        with self.assertRaises(self.m['SafeStop']) as error:
            self.decrypt()
        self.assertEqual(str(error.exception), 'PRIVATE_KEY_PERMISSIONS_REQUIRED')
        self.private_path.chmod(0o600)
        alias = self.directory/'private-alias.pem'
        alias.symlink_to(self.private_path)
        with self.assertRaises(self.m['SafeStop']) as error:
            self.m['decrypt_archive'](self.archive, alias, self.expected)
        self.assertEqual(str(error.exception), 'LOCAL_FILE_UNAVAILABLE_OR_SYMLINK')

    def test_cli_saves_exclusively_without_plaintext_stdout(self):
        self.package()
        expected_file = self.directory/'expected.json'
        expected_file.write_text(json.dumps(self.expected))
        output, provenance = self.directory/'report.json', self.directory/'provenance.json'
        argv = ['decrypt_metrika.py', '--archive', str(self.archive),
                '--private-key', str(self.private_path), '--expected', str(expected_file),
                '--output', str(output), '--provenance-output', str(provenance)]
        stdout, stderr = io.StringIO(), io.StringIO()
        with mock.patch('sys.argv', argv), contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            self.assertEqual(self.m['main'](), 0)
        self.assertEqual(stdout.getvalue(), 'AUTHENTICATED_REPORT_SAVED\n')
        self.assertEqual(stderr.getvalue(), '')
        self.assertEqual(json.loads(output.read_bytes()), self.report)
        self.assertEqual(stat.S_IMODE(output.stat().st_mode), 0o600)
        before = output.read_bytes()
        with mock.patch('sys.argv', argv), contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(self.m['main'](), 1)
        self.assertEqual(before, output.read_bytes())

    def test_duplicate_or_nonfinite_json_is_rejected(self):
        for data in (b'{"schema":1,"schema":2}', b'{"value":NaN}', b'{"value":Infinity}'):
            with self.assertRaises(self.m['SafeStop']):
                self.m['strict_json'](data)

if __name__ == '__main__':
    unittest.main()

