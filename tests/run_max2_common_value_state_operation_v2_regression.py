#!/usr/bin/env python3
"""Temporary-filesystem and fake transport/PHP fixtures. No credential or live call."""
import base64
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('finite_max2_state_carrier', ROOT / 'tools/max2_common_value_state_operation_v2.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)
SOURCE = '7' * 40
EPOCH = '8' * 64


def activation(mode='collect', epoch=EPOCH):
    return {'version': 1, 'mode': mode, 'counter_id': 98615635, 'goal_id': 687941321, 'target': M.TARGET,
            'producer_contract_sha256': M.CONTRACT, 'activation_sha256': epoch,
            'activated_at_unix': 1795269900, 'initial_profile_max_id': '12'}


def state(mode='off', active=None):
    return {'status': 'OBSERVED', 'mode': mode, 'activation': active, 'cursor': '0' if active is None else '12',
            'initial_profile_max_id': '0' if active is None else '12', 'active_tick_key': None, 'lease_until_unix': 0}


def tables():
    return {'status': 'OBSERVED_TABLES_READY', 'expected_count': 7, 'observed_count': 7,
            'full_schema_validation': False, 'business_rows_read': 0, 'tables': M.TABLES, 'engine': 'InnoDB'}


def event(operation='status', epoch=''):
    env = {'GITHUB_REPOSITORY': M.REPOSITORY, 'GITHUB_REF': 'refs/heads/main', 'GITHUB_EVENT_NAME': 'workflow_dispatch',
           'GITHUB_RUN_ATTEMPT': '1', 'GITHUB_ACTOR': 'pyatkoff', 'GITHUB_TRIGGERING_ACTOR': 'pyatkoff',
           'GITHUB_WORKFLOW_REF': M.REPOSITORY + '/' + M.WORKFLOW + '@refs/heads/main', 'GITHUB_JOB': 'state',
           'GITHUB_SHA': SOURCE, 'GITHUB_RUN_ID': '42'}
    value = {'repository': {'full_name': M.REPOSITORY, 'id': M.REPOSITORY_ID}, 'sender': {'login': 'pyatkoff', 'id': 226193297},
             'inputs': {'operation': operation, 'expected_application_sha': SOURCE, 'expected_epoch': epoch}}
    return env, value


class NativeFixture:
    def __init__(self, root, operation='status', current=None):
        self.root = root
        self.current = current if current is not None else state()
        self.calls = []
        self.mode_calls = 0
        self.head = SOURCE
        self.fault = None
        paths = {M.CLI: b'<?php // synthetic CLI bytes; never executed\n',
            M.RUNNER: (ROOT / M.RUNNER).read_bytes(), M.WORKFLOW: (ROOT / M.WORKFLOW).read_bytes(),
            M.SIDECAR: (ROOT / M.SIDECAR).read_bytes(), 'tools/deployment_release.js': b'// synthetic resolver; never executed\n',
            M.MIGRATIONS[0]: b'-- synthetic migration112; never executed\n', M.MIGRATIONS[1]: b'-- synthetic migration113; never executed\n',
            'services/Max2CommonValueStateV2.php': b'<?php // synthetic state file; never executed\n',
            'webhook.php': b'<?php // synthetic webhook; never executed\n', 'cron_metrika.php': b'<?php // synthetic cron; never executed\n'}
        for name, raw in paths.items():
            p = root / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(raw)
        self.manifest = {'schema': 'max2-common-v2-app-source-v1', 'counter_id': 98615635, 'goal_ids': [687931224, 687941321],
            'target': M.TARGET, 'producer_contract_sha256': M.CONTRACT, 'initial_mode': 'off',
            'files': {k: M.sha(v) for k, v in paths.items()}}
        self.seal()
        self.select(operation)

    def seal(self):
        (self.root / M.MANIFEST).write_bytes(M.canonical(self.manifest))

    def select(self, op):
        epoch = None if op in ('status', 'activate') else EPOCH
        raw = M.request_bytes(op, SOURCE, epoch)
        self.payload = {'operation': op, 'application_sha': SOURCE, 'expected_epoch': epoch,
            'request_sha256': M.sha(raw), 'request_b64': base64.b64encode(raw).decode(),
            'release_manifest_sha256': M.sha((self.root / M.MANIFEST).read_bytes()), 'sidecar_admission_sha256': M.SIDECAR_SHA}

    def images(self):
        return {p.relative_to(self.root).as_posix(): p.read_bytes() for p in self.root.rglob('*') if p.is_file() and not p.is_symlink()}

    def runner(self, argv, **kwargs):
        if argv[0] == 'git':
            assert argv == ['git', '-C', str(self.root), 'rev-parse', '--verify', 'HEAD']
            return subprocess.CompletedProcess(argv, 0, (self.head + '\n').encode(), b'')
        assert argv[0] == 'php' and argv[-4] == M.CLI and kwargs['cwd'] == self.root
        op, source, digest = argv[-3:]
        request = M.parsed(kwargs['input'], 8192)
        assert source == SOURCE and M.sha(kwargs['input']) == digest and request == json.loads(M.request_bytes(op, SOURCE, request['expected_epoch']))
        assert set(request) == {'version', 'operation', 'project_key', 'counter_id', 'goal_id', 'target', 'source_sha', 'expected_epoch'}
        assert 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail' in argv
        self.calls.append(op)
        output = {'version': 1, 'operation': op, 'status': 'OBSERVED', 'ok': True, 'reason': None, 'scope': M.SCOPE.copy(),
            'admission': {'source_sha': SOURCE, 'observed_source_sha': SOURCE, 'request_sha256': digest, 'expected_epoch': request['expected_epoch']},
            'state': json.loads(json.dumps(self.current)), 'tables': tables(), 'readback': True, 'mutation_attempted': op != 'status',
            'automatic_retry': False, 'provider_calls': 0, 'tick_invoked': False, 'v1_invoked': False}
        if op == 'status':
            if self.fault == 'tables':
                output.update(ok=False, status='HOLD', reason='V2_OWN_TABLES_UNAVAILABLE')
                output['tables']['observed_count'] = 6
            if self.fault == 'old_goal':
                output['scope']['goal_id'] = 678187097
            if self.fault == 'raw_state':
                output['state']['raw_client_id'] = 'synthetic_sensitive_value'
            if self.fault == 'drift_before':
                (self.root / 'webhook.php').write_bytes(b'changed source\n')
            if self.fault == 'bad_json_before':
                return subprocess.CompletedProcess(argv, 0, b'not-json', b'')
        else:
            self.mode_calls += 1
            mode = 'collect' if op == 'activate' else op
            self.current = state(mode, activation(mode))
            output['state'] = self.current
            output['status'] = 'ACTIVATED' if op == 'activate' else 'MODE_SET'
            if self.fault == 'timeout_after':
                raise subprocess.TimeoutExpired(argv, 25)
            if self.fault == 'unknown_commit':
                output.update(ok=False, status='UNKNOWN', reason='V2_STATE_COMMIT_UNCONFIRMED')
            if self.fault == 'known_hold':
                output.update(ok=False, status='HOLD', reason='V2_ACTIVE_TICK_MODE_TRANSITION_HELD')
            if self.fault == 'drift_after':
                (self.root / 'webhook.php').write_bytes(b'changed source\n')
            if self.fault == 'epoch_after':
                output['state']['activation']['activation_sha256'] = '9' * 64
            if self.fault == 'bad_json_after':
                return subprocess.CompletedProcess(argv, 0, b'not-json', b'')
        return subprocess.CompletedProcess(argv, 0 if output['ok'] else 3 if output['status'] == 'UNKNOWN' else 2,
                                           M.canonical(output), b'')

    def run(self):
        return M.native(self.payload, root=self.root, runner=self.runner)


class FiniteCarrierTests(unittest.TestCase):
    def fixture(self, operation='status', current=None):
        temp = tempfile.TemporaryDirectory(prefix='max2-state-test-')
        self.addCleanup(temp.cleanup)
        return NativeFixture(Path(temp.name), operation, current)

    def test_status_is_source_bound_read_only(self):
        f = self.fixture()
        before = f.images()
        r = f.run()
        self.assertTrue(r['ok'])
        self.assertEqual(r['status'], 'OBSERVED')
        self.assertEqual(f.calls, ['status'])
        self.assertFalse(r['mutation_attempted'])
        self.assertEqual(f.images(), before)

    def test_initial_activate_persists_only_collect_epoch(self):
        f = self.fixture('activate')
        before = f.images()
        r = f.run()
        self.assertTrue(r['ok'])
        self.assertEqual(f.calls, ['status', 'activate'])
        self.assertEqual(r['receipt']['state']['mode'], 'collect')
        self.assertEqual(f.images(), before)
        self.assertFalse(r['tick_invoked'])
        self.assertEqual(r['provider_calls'], 0)

    def test_send_uses_exact_existing_epoch_from_collect(self):
        f = self.fixture('send', state('collect', activation()))
        r = f.run()
        self.assertTrue(r['ok'])
        self.assertEqual(f.calls, ['status', 'send'])
        self.assertEqual(r['receipt']['state']['activation']['activation_sha256'], EPOCH)

    def test_off_and_collect_use_only_own_mode_command(self):
        for op in ('off', 'collect'):
            with self.subTest(op=op):
                f = self.fixture(op, state('send', activation('send')))
                self.assertTrue(f.run()['ok'])
                self.assertEqual(f.calls, ['status', op])

    def test_activation_cannot_reset_existing_epoch(self):
        f = self.fixture('activate', state('collect', activation()))
        self.assertFalse(f.run()['ok'])
        self.assertEqual(f.calls, ['status'])

    def test_send_requires_current_collect(self):
        for mode in ('off', 'send'):
            f = self.fixture('send', state(mode, activation(mode)))
            self.assertEqual(f.run()['reason'], 'COLLECT_BEFORE_SEND_REQUIRED')
            self.assertEqual(f.mode_calls, 0)

    def test_wrong_epoch_stops_before_mode_mutation(self):
        f = self.fixture('collect', state('off', activation('off', '9' * 64)))
        self.assertEqual(f.run()['reason'], 'CURRENT_EPOCH_CHANGED')
        self.assertEqual(f.mode_calls, 0)

    def test_actual_own_table_observation_required(self):
        f = self.fixture('activate')
        f.fault = 'tables'
        self.assertFalse(f.run()['ok'])
        self.assertEqual(f.calls, ['status'])

    def test_old_goal_and_raw_identity_output_refused(self):
        for fault in ('old_goal', 'raw_state'):
            f = self.fixture('activate')
            f.fault = fault
            self.assertFalse(f.run()['ok'])
            self.assertEqual(f.mode_calls, 0)

    def test_source_drift_before_mutation_is_hold(self):
        f = self.fixture('activate')
        f.fault = 'drift_before'
        r = f.run()
        self.assertEqual(r['status'], 'HOLD')
        self.assertEqual(f.mode_calls, 0)

    def test_source_drift_after_mutation_is_unknown_no_rollback(self):
        f = self.fixture('activate')
        f.fault = 'drift_after'
        r = f.run()
        self.assertEqual(r['status'], 'UNKNOWN')
        self.assertEqual(f.current['mode'], 'collect')
        self.assertEqual(f.calls, ['status', 'activate'])

    def test_timeout_unknown_commit_and_invalid_response_never_retry(self):
        for fault in ('timeout_after', 'unknown_commit', 'bad_json_after'):
            f = self.fixture('activate')
            f.fault = fault
            r = f.run()
            self.assertEqual(r['status'], 'UNKNOWN')
            self.assertFalse(r['automatic_retry'])
            self.assertEqual(f.mode_calls, 1)

    def test_mode_readback_cannot_change_epoch(self):
        f = self.fixture('send', state('collect', activation()))
        f.fault = 'epoch_after'
        self.assertEqual(f.run()['status'], 'UNKNOWN')
        self.assertEqual(f.mode_calls, 1)

    def test_known_hold_remains_a_hold(self):
        f = self.fixture('collect', state('off', activation('off')))
        f.fault = 'known_hold'
        r = f.run()
        self.assertEqual(r['status'], 'HOLD')
        self.assertFalse(r['ok'])
        self.assertEqual(f.mode_calls, 1)

    def test_head_mismatch_prevents_all_php(self):
        f = self.fixture('activate')
        f.head = '6' * 40
        self.assertFalse(f.run()['ok'])
        self.assertEqual(f.calls, [])

    def test_missing_migration_and_foreign_manifest_path_refused(self):
        for mode in ('missing', 'foreign'):
            f = self.fixture('activate')
            if mode == 'missing':
                del f.manifest['files'][M.MIGRATIONS[0]]
            else:
                f.manifest['files']['config.php'] = 'a' * 64
            f.seal()
            f.select('activate')
            self.assertFalse(f.run()['ok'])
            self.assertEqual(f.calls, [])

    def test_accepted_sidecar_bytes_cannot_be_synthesized(self):
        f = self.fixture('activate')
        raw = (f.root / M.SIDECAR).read_bytes().replace(b'14708309343', b'14708309344')
        (f.root / M.SIDECAR).write_bytes(raw)
        f.manifest['files'][M.SIDECAR] = M.sha(raw)
        f.seal()
        f.select('activate')
        self.assertEqual(f.run()['reason'], 'ACCEPTED_SIDECAR_RESOURCE_REQUIRED')
        self.assertEqual(f.calls, [])

    def test_symlink_source_is_not_a_regular_installed_file(self):
        f = self.fixture('activate')
        original = f.root / 'webhook.php'
        real = f.root / 'synthetic-other.php'
        original.rename(real)
        original.symlink_to(real)
        self.assertFalse(f.run()['ok'])
        self.assertEqual(f.calls, [])

    def test_request_pin_extra_keys_and_operation_escape_refused(self):
        for key, value in [('request_sha256', '0' * 64), ('operation', 'launch-v1'), ('extra', True), ('expected_epoch', EPOCH)]:
            f = self.fixture('activate')
            f.payload[key] = value
            self.assertFalse(f.run()['ok'])
            self.assertEqual(f.calls, [])

    def test_manual_context_exact_owner_main_workflow_attempt(self):
        env, ev = event('activate')
        self.assertEqual(M.context(env, ev)['operation'], 'activate')
        for key, value in [('GITHUB_ACTOR', 'someone'), ('GITHUB_REF', 'refs/heads/other'), ('GITHUB_RUN_ATTEMPT', '2'),
            ('GITHUB_EVENT_NAME', 'push'), ('GITHUB_EVENT_NAME', 'workflow_run'), ('GITHUB_EVENT_NAME', 'schedule'),
            ('GITHUB_WORKFLOW_REF', M.REPOSITORY + '/.github/workflows/modeled-conversion-value-export.yml@refs/heads/main')]:
            with self.assertRaises(M.Refusal):
                M.context({**env, key: value}, ev)

    def test_manual_parameters_reject_foreign_goal_and_missing_epoch(self):
        env, ev = event('send')
        with self.assertRaises(M.Refusal):
            M.context(env, ev)
        ev['inputs']['expected_epoch'] = EPOCH
        self.assertEqual(M.context(env, ev)['expected_epoch'], EPOCH)
        ev['inputs']['goal_id'] = '678187097'
        with self.assertRaises(M.Refusal):
            M.context(env, ev)

    def test_canonical_resolver_mapping_not_just_green_sha(self):
        valid = b'repository_sha=' + SOURCE.encode() + b'\napplication_sha=' + b'6' * 40 + b'\nkind=application_unchanged\nperform_actions=true\nready=true\n'
        self.assertEqual(M.accepted_resolution(valid, SOURCE, '6' * 40)['application_sha'], '6' * 40)
        for raw in (valid.replace(b'ready=true', b'ready=false'), valid + b'ready=true\n', valid.replace(b'application_unchanged', b'pending')):
            with self.assertRaises(M.Refusal):
                M.accepted_resolution(raw, SOURCE, '6' * 40)

    def test_duplicate_json_and_bounds_refuse(self):
        for raw in (b'{"a":1,"a":2}', b'{"a":NaN}', b'{}' * 10000):
            with self.assertRaises(M.Refusal):
                M.parsed(raw, 8192)

    def test_transport_is_single_existing_principal_no_retry(self):
        f = self.fixture()
        plan = {'runner_sha256': f.manifest['files'][M.RUNNER], 'payload': f.payload}
        calls = []
        def fake(argv, **kw):
            calls.append(argv)
            if argv[0] == 'ssh-keygen':
                return subprocess.CompletedProcess(argv, 0)
            self.assertEqual(argv[0], 'ssh')
            self.assertIn('existing_user@synthetic.example.invalid', argv)
            self.assertTrue(argv[-1].endswith(' --native'))
            self.assertEqual(json.loads(kw['input']), f.payload)
            raise subprocess.TimeoutExpired(argv, 90)
        with patch.object(M.subprocess, 'run', fake):
            result = M.execute(plan, f.root, {'STANDBY_DEPLOY_SSH_KEY': '-----BEGIN PRIVATE KEY-----\nsynthetic\n',
                'STANDBY_DEPLOY_HOST': 'synthetic.example.invalid', 'STANDBY_DEPLOY_USER': 'existing_user'})
        self.assertEqual(result['status'], 'UNKNOWN')
        self.assertEqual([c[0] for c in calls], ['ssh-keygen', 'ssh'])
        self.assertFalse(result['automatic_retry'])

    def test_workflow_has_no_automatic_business_trigger(self):
        raw = (ROOT / M.WORKFLOW).read_text()
        self.assertIn('  workflow_dispatch:', raw)
        self.assertNotIn('  workflow_run:', raw)
        self.assertNotIn('  schedule:', raw)
        self.assertNotIn('  push:', raw)
        self.assertIn('group: production-deploy', raw)
        self.assertIn('options: ["status", "activate", "off", "collect", "send"]', raw)
        self.assertNotIn('actions: write', raw)
        self.assertNotIn('contents: write', raw)

    def test_manual_operation_choices_are_exact_json_strings(self):
        # Require the JSON subset of YAML here: YAML 1.1 coerces plain off to false.
        # This reads the real operation block and needs no optional YAML library.
        raw = (ROOT / M.WORKFLOW).read_text()
        expected = ['status', 'activate', 'off', 'collect', 'send']

        def checked_choices(workflow):
            blocks = re.findall(r'^      operation:\n((?:        [^\n]*\n)+)', workflow, re.MULTILINE)
            self.assertEqual(len(blocks), 1)
            options = re.findall(r'^        options: (.+)$', blocks[0], re.MULTILINE)
            self.assertEqual(len(options), 1)
            choices = json.loads(options[0])
            self.assertIsInstance(choices, list)
            self.assertTrue(all(type(choice) is str for choice in choices))
            self.assertEqual(choices, expected)
            return options[0]

        actual = checked_choices(raw)
        for bad in ('[status, activate, off, collect, send]',
                    '["status", "activate", false, "collect", "send"]'):
            with self.subTest(options=bad), self.assertRaises((AssertionError, json.JSONDecodeError)):
                checked_choices(raw.replace(actual, bad, 1))


if __name__ == '__main__':
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(FiniteCarrierTests)
    result = unittest.TextTestRunner(verbosity=1).run(suite)
    if result.wasSuccessful():
        print(json.dumps({'status': 'PASS', 'checks': result.testsRun, 'fixtures': 'temporary_filesystem_fake_php_and_transport',
            'live_database_connections': 0, 'native_calls': 0, 'provider_calls': 0, 'activation': False}))
    raise SystemExit(0 if result.wasSuccessful() else 1)
