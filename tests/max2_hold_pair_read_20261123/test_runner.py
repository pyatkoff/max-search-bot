"""Synthetic transport/schema tests. Never contacts a server or provider."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('hold_pair_runner', ROOT / 'tools/max2_hold_pair_read_20261123/runner.py')
R = importlib.util.module_from_spec(spec)
spec.loader.exec_module(R)
SHA = 'a' * 40


def diagnosis():
    item = {'canonical_result':'null','first_gate':'NO_ELIGIBLE_SIGNAL','category':'no_admitted_estimate',
        'checks':{'access_allowed':True,'evidence_admitted':0}}
    return {'version':'max2_original_two_hold_diagnosis_v1','temporal_result':'invariant_over_saved_interval',
        'original_null_reproduced':True,'first_failed_gate':'NO_ELIGIBLE_SIGNAL','category':'no_admitted_estimate',
        'at_tick_start':copy.deepcopy(item),'at_hold_recorded':copy.deepcopy(item),'canonical_parity_verified':True,
        'source_hashes_verified':True,'input_unchanged':True,'model_calls':0,'provider_requests':0,'writes':0}


def result():
    cases = []
    for case, minute, second in [('A','23','03'),('B','28','05')]:
        cases.append({'case':case,'saved_outcome':'HOLD','saved_reason':'SAVED_VALUE_UNAVAILABLE',
            'tick_started_at':'2026-11-23T02:' + minute + ':00Z','hold_recorded_at':'2026-11-23T02:' + minute + ':02Z',
            'tick_finished_at':'2026-11-23T02:' + minute + ':' + second + 'Z',
            'current_native_scope_admitted':True,'profile_source_admitted':True,'diagnosis':diagnosis()})
    return {'version':'max2_original_hold_pair_read_v1','status':'OBSERVED','fixed_historical_pair':True,
        'activation_at':'2026-11-22T22:39:36Z','selected_profiles':2,'cases':cases,'read_only_snapshot':True,
        'snapshot_rolled_back':True,'current_access_rechecked_after_snapshot':True,'installed_source_hashes_verified':True,
        'queries':8,'selected_bytes':2048,'writes':0,'provider_requests':0,'model_calls':0,'worker_calls':0,
        'conversion_uploads':0,'financial_delivery_permission':False}


def report():
    return {'version':'max2_original_hold_pair_operation_v1','operation':R.OPERATION,'application_sha':SHA,
        'observed_at_utc':'2026-11-23T22:00:00Z','result':result(),'automatic_retry':False,'worker_invoked':False,
        'v1_invoked':False,'provider_requests':0}


class SchemaTests(unittest.TestCase):
    def rejected(self, value):
        with self.assertRaises((R.Stop, ValueError, TypeError, KeyError)):
            R.checked_report(json.dumps(value).encode(), SHA)

    def test_complete_observation(self):
        value = report()
        self.assertEqual(R.checked_report(json.dumps(value).encode(), SHA), value)

    def test_private_extra_fields_at_every_level(self):
        for location in [[], ['result'], ['result','cases',0], ['result','cases',0,'diagnosis'],
            ['result','cases',0,'diagnosis','at_tick_start'], ['result','cases',0,'diagnosis','at_tick_start','checks']]:
            value = report(); node = value
            for key in location:
                node = node[key]
            node['customer_key'] = 'PRIVATE_MUST_NOT_ESCAPE'
            self.rejected(value)

    def test_nonboolean_or_money_cannot_hide_in_checks(self):
        for bad in ['private text', 123, None, {}, []]:
            value = report(); value['result']['cases'][0]['diagnosis']['at_tick_start']['checks']['access_allowed'] = bad
            self.rejected(value)

    def test_count_bounds_and_boolean_as_count(self):
        for bad in [-1, 1001, True, '5', 1.5]:
            value = report(); value['result']['cases'][0]['diagnosis']['at_tick_start']['checks']['evidence_admitted'] = bad
            self.rejected(value)

    def test_required_scope_and_side_effect_proofs(self):
        for name, bad in [('activation_at','2026-11-22T22:39:37Z'),('selected_profiles',3),('snapshot_rolled_back',False),
            ('current_access_rechecked_after_snapshot',False),('installed_source_hashes_verified',False),('queries',9),
            ('queries',7),('selected_bytes',278529),('financial_delivery_permission',True),('writes',1),
            ('worker_calls',1),('conversion_uploads',True),('provider_requests',1)]:
            value = report(); value['result'][name] = bad; self.rejected(value)

    def test_exact_historical_pair(self):
        for mutation in ['third','swapped','wrong_clock','other_reason','personal_label']:
            value = report(); rows = value['result']['cases']
            if mutation == 'third': rows.append(copy.deepcopy(rows[0]))
            if mutation == 'swapped': rows.reverse()
            if mutation == 'wrong_clock': rows[0]['tick_finished_at'] = '2026-11-23T03:23:03Z'
            if mutation == 'other_reason': rows[0]['saved_reason'] = 'SAVED_PROFILE_UNAVAILABLE'
            if mutation == 'personal_label': rows[0]['case'] = 'PRIVATE_NAME'
            self.rejected(value)

    def test_canonical_proof_mandatory(self):
        for field in ['canonical_parity_verified','source_hashes_verified','input_unchanged']:
            value = report(); value['result']['cases'][0]['diagnosis'][field] = False; self.rejected(value)

    def test_gate_category_cannot_disclose_text(self):
        for field in ['first_gate','category','canonical_result']:
            value = report(); value['result']['cases'][0]['diagnosis']['at_tick_start'][field] = 'PRIVATE_TEXT'
            self.rejected(value)

    def test_time_boundary_stays_unresolved(self):
        value = report(); d = value['result']['cases'][0]['diagnosis']
        d['at_hold_recorded'] = {'canonical_result':'candidate','first_gate':'CANDIDATE_AVAILABLE','category':'admitted','checks':{}}
        d.update(temporal_result='original_evaluation_time_unresolved', original_null_reproduced=False,
            first_failed_gate='EVALUATION_TIME_BOUNDARY', category='time_boundary_unresolved')
        self.assertEqual(R.checked_report(json.dumps(value).encode(), SHA), value)
        d['original_null_reproduced'] = True; self.rejected(value)

    def test_unavailable_has_no_partial_cases(self):
        value = report()
        value['result'] = {'version':'max2_original_hold_pair_read_v1','status':'UNAVAILABLE','reason':'CURRENT_ACCESS_DENIED',
            'fixed_historical_pair':True,'writes':0,'provider_requests':0,'model_calls':0,'worker_calls':0,
            'conversion_uploads':0,'financial_delivery_permission':False}
        self.assertEqual(R.checked_report(json.dumps(value).encode(), SHA), value)
        value['result']['cases'] = []; self.rejected(value)

    def test_native_failure_is_fixed_and_anonymous(self):
        value = {'version':'max2_original_hold_pair_operation_v1','operation':R.OPERATION,'status':'UNAVAILABLE',
            'reason':'PROJECT_UNAVAILABLE','automatic_retry':False,'worker_invoked':False,'v1_invoked':False,'provider_requests':0}
        self.assertEqual(R.checked_report(json.dumps(value).encode(), SHA), value)
        value['reason'] = 'PDO failed PRIVATE_DSN'; self.rejected(value)

    def test_source_sha_and_retry(self):
        for field, bad in [('application_sha','b'*40),('automatic_retry',True),('v1_invoked',True),('worker_invoked',True)]:
            value = report(); value[field] = bad; self.rejected(value)

    def test_duplicate_json_and_bound(self):
        with self.assertRaises(R.Stop): R.parse(b'{"x":1,"x":2}')
        with self.assertRaises(R.Stop): R.checked_report(b'x' * (R.MAX_OUTPUT + 1), SHA)

    def test_unknown_host_is_never_trusted(self):
        with self.assertRaises(R.Stop): R.host_lines('example ssh-ed25519 YWJj\n')

    def test_unsafe_context_fails_before_subprocess(self):
        with patch.object(R.subprocess, 'run') as run:
            for args in [{}, {'GITHUB_REPOSITORY':R.REPO,'GITHUB_REF':'refs/heads/main'},
                {'GITHUB_REPOSITORY':R.REPO,'GITHUB_REF':'refs/heads/'+R.BRANCH,'GITHUB_JOB':'diagnose',
                 'GITHUB_EVENT_NAME':'push','GITHUB_RUN_ATTEMPT':'2'}]:
                with self.assertRaises(R.Stop): R.resolve(args)
            run.assert_not_called()

    def test_package_has_no_unfilled_schema(self):
        self.assertEqual(len(R.GATES), 23)
        self.assertEqual(len(R.BOOLEAN_CHECKS) + len(R.COUNT_CHECKS), 82)
        self.assertIn('CURRENT_ACCESS_DENIED', R.READER_FAILURES)
        self.assertIn('PROJECT_UNAVAILABLE', R.PROGRAM_FAILURES)

    def test_bundle_is_fixed_read_only_stdin(self):
        with patch.object(R, 'PACKAGE', ROOT / R.PACKAGE):
            data = R.bundle(SHA)
            self.assertEqual(data.count(b'<?php'), 1)
            self.assertIn(b"Max2HoldPairReadOperation::main('" + SHA.encode(), data)
            self.assertLess(len(data), 262144)
            self.assertNotIn(b'BOOTSTRAP_HASHES = []', data)
            with self.assertRaises(R.Stop): R.bundle("'; write(); '")

    def test_source_change_and_plan_replay_stop_before_transport(self):
        with patch.object(R, 'source_pins', return_value={'changed':'hash'}), patch.object(R, 'resolve') as resolve:
            with self.assertRaises(R.Stop):
                R.execute({'schema':'max2-original-hold-pair-plan-v1','run_id':'1','prepared_at':int(R.time.time()),
                    'resolution':{},'source_pins':{}}, {'GITHUB_RUN_ID':'1'})
            resolve.assert_not_called()

    def test_attempt_is_consumed_before_transport_and_not_repeated(self):
        with tempfile.TemporaryDirectory() as name:
            env = {'RUNNER_TEMP':name,'GITHUB_RUN_ID':'123','GITHUB_SHA':SHA}
            (Path(name) / 'max2-original-hold-pair-plan.json').write_text('{}')
            with patch.dict(os.environ, env, clear=True), patch.object(R.sys, 'argv', ['runner.py','--execute']), \
                patch.object(R, 'execute', side_effect=R.Stop('SYNTHETIC_UNAVAILABLE')) as execute:
                with self.assertRaises(R.Stop): R.main()
                self.assertTrue((Path(name) / 'max2-original-hold-pair-attempted').exists())
                with self.assertRaises(R.Stop): R.main()
                self.assertEqual(execute.call_count, 1)


if __name__ == '__main__':
    unittest.main()
