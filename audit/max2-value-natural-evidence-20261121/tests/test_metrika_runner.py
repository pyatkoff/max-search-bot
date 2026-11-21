import base64
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import runpy
import shutil
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('natural_runner', ROOT / 'metrika_runner.py')
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)


class CarrierTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); self.event_path = self.root / 'event.json'
        self.env = {'GITHUB_EVENT_NAME': 'push', 'GITHUB_REPOSITORY': 'pyatkoff/max-search-bot',
            'GITHUB_ACTOR': 'pyatkoff', 'GITHUB_TRIGGERING_ACTOR': 'pyatkoff',
            'GITHUB_REF': 'refs/heads/' + m.BRANCH, 'GITHUB_RUN_ATTEMPT': '1',
            'GITHUB_SHA': 'a' * 40, 'GITHUB_EVENT_PATH': str(self.event_path),
            'EVIDENCE_INPUTS_SHA256': hashlib.sha256((ROOT / 'evidence-inputs.json').read_bytes()).hexdigest(),
            'READER_SHA256': hashlib.sha256((ROOT / 'metrika_reader.py').read_bytes()).hexdigest()}
        self.event = {'created': True, 'forced': False, 'deleted': False, 'before': '0' * 40,
            'after': 'a' * 40, 'ref': self.env['GITHUB_REF'], 'sender': {'login': 'pyatkoff'},
            'repository': {'full_name': 'pyatkoff/max-search-bot'}, 'head_commit': {'message': m.MESSAGE}}
        self.write_event()

    def write_event(self): self.event_path.write_text(json.dumps(self.event))

    @staticmethod
    def git(args, **kwargs):
        assert args[0] == 'git' and args[-1] in ('HEAD', 'HEAD^')
        return subprocess.CompletedProcess(args, 0, ('a' * 40 if args[-1] == 'HEAD' else m.BASE) + '\n', '')

    def test_original_owner_source_and_actual_evidence_admitted(self):
        with mock.patch.dict(os.environ, self.env, clear=True), mock.patch.object(m.subprocess, 'run', side_effect=self.git):
            m.source_gate()
            proof = m.verify_evidence()
        self.assertEqual(proof['install_receipt_sha256'], m.INSTALL_RECEIPT)
        self.assertEqual(len(proof['runtime_files']), 18)

    def test_rerun_owner_repo_branch_rejected_before_subprocess(self):
        for key, value in (('GITHUB_RUN_ATTEMPT', '2'), ('GITHUB_ACTOR', 'other'),
                           ('GITHUB_TRIGGERING_ACTOR', 'other'), ('GITHUB_REPOSITORY', 'other/repo'),
                           ('GITHUB_EVENT_NAME', 'workflow_dispatch'), ('GITHUB_REF', 'refs/heads/main')):
            with self.subTest(key=key), mock.patch.dict(os.environ, {**self.env, key: value}, clear=True), \
                 mock.patch.object(m.subprocess, 'run') as call:
                with self.assertRaises(m.SafeStop): m.source_gate()
                call.assert_not_called()

    def test_created_forced_message_sender_must_match(self):
        baseline = copy.deepcopy(self.event)
        mutations = [lambda e: e.update(created=False), lambda e: e.update(forced=True),
            lambda e: e.update(deleted=True), lambda e: e.update(before='b' * 40),
            lambda e: e['head_commit'].update(message='old read'),
            lambda e: e['sender'].update(login='other'), lambda e: e.update(after='c' * 40)]
        for mutation in mutations:
            self.event = copy.deepcopy(baseline); mutation(self.event); self.write_event()
            with mock.patch.dict(os.environ, self.env, clear=True), mock.patch.object(m.subprocess, 'run') as call:
                with self.assertRaises(m.SafeStop): m.source_gate()
                call.assert_not_called()

    def test_parent_and_checkout_mismatch_rejected(self):
        for index in (0, 1):
            values = ['a' * 40, m.BASE]; values[index] = 'f' * 40
            replies = [subprocess.CompletedProcess([], 0, value + '\n', '') for value in values]
            with mock.patch.dict(os.environ, self.env, clear=True), mock.patch.object(m.subprocess, 'run', side_effect=replies):
                with self.assertRaises(m.SafeStop): m.source_gate()

    def test_wrong_evidence_and_report_body_are_rejected(self):
        evidence = self.root / 'evidence'; evidence.mkdir()
        shutil.copytree(ROOT / 'proof', evidence / 'proof')
        original = json.loads((ROOT / 'evidence-inputs.json').read_bytes())
        for mutation in (lambda p: p.update(goal_ids=[678187097, 687941321]),
                         lambda p: p['install'].update(run_id=1),
                         lambda p: p.update(date1='2026-11-20'),
                         lambda p: p['admission'].update(request_sha256='0' * 64)):
            pin = copy.deepcopy(original); mutation(pin)
            raw = (json.dumps(pin) + '\n').encode(); (evidence / 'evidence-inputs.json').write_bytes(raw)
            env = {**self.env, 'EVIDENCE_INPUTS_SHA256': hashlib.sha256(raw).hexdigest()}
            with mock.patch.dict(os.environ, env, clear=True), mock.patch.object(m, 'HERE', evidence):
                with self.assertRaises(m.SafeStop): m.verify_evidence()
        raw = (ROOT / 'evidence-inputs.json').read_bytes(); (evidence / 'evidence-inputs.json').write_bytes(raw)
        (evidence / 'proof/install-report.json').write_text('{}')
        with mock.patch.dict(os.environ, self.env, clear=True), mock.patch.object(m, 'HERE', evidence):
            with self.assertRaises(m.SafeStop): m.verify_evidence()

    def test_transport_one_ssh_only_and_original_credential_path(self):
        fixture = runpy.run_path(str(ROOT / 'tests/test_metrika.py'), run_name='fixture')
        model = fixture['m']; model.reset()
        report = model.collect(lambda path, plan: fixture['body']())
        report.update(local_config_verified=True, installed_sha='b' * 40,
            install_receipt_sha256=m.INSTALL_RECEIPT, finished_at_utc=report['started_at_utc'],
            requests=[{'endpoint': model.REPORT_PATH, 'query': 'two_new_goal_totals', 'attempt': 1, 'http': 200}])
        encoded_host = base64.b64encode(b'synthetic-host-key').decode()
        pin = 'SHA256:' + base64.b64encode(hashlib.sha256(b'synthetic-host-key').digest()).decode().rstrip('=')
        calls = []
        def run(args, **kwargs):
            if args[0] == 'git': return self.git(args, **kwargs)
            calls.append(args)
            if args[0] == 'ssh-keyscan': return subprocess.CompletedProcess(args, 0, 'fixture.example ssh-ed25519 ' + encoded_host, '')
            self.assertEqual(args[0], 'ssh')
            kwargs['stdout'].write(json.dumps(report).encode())
            return subprocess.CompletedProcess(args, 0)
        env = {**self.env, 'SSH_KEY': '-----BEGIN PRIVATE KEY-----\nsynthetic\n-----END PRIVATE KEY-----',
            'SSH_HOST': 'fixture.example', 'SSH_USER': 'fixture'}
        with mock.patch.dict(os.environ, env, clear=True), mock.patch.object(m, 'PIN', pin), \
             mock.patch.object(m.subprocess, 'run', side_effect=run):
            result = m.execute(self.root)
        self.assertEqual(result['status'], 'OBSERVED')
        ssh = [v for v in calls if v[0] == 'ssh']; self.assertEqual(len(ssh), 1)
        self.assertEqual(ssh[0][-1], 'cd /var/www/anytoour/data/www/d.anytoour.ru && .venv/bin/python -I -B - ' + m.INSTALL_RECEIPT)
        self.assertIn('StrictHostKeyChecking=yes', ssh[0])
        self.assertEqual((self.root / 'key').stat().st_mode & 0o777, 0o600)


if __name__ == '__main__': unittest.main()
