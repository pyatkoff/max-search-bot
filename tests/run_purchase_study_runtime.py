#!/usr/bin/env python3
"""Synthetic server bridge lifecycle; no real data, source calls or filesystem state."""
import copy
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'tools')); sys.path.insert(0, str(ROOT/'tests'))
import purchase_conversion_study as study
import purchase_study_runtime as runtime
from run_purchase_conversion_study import case, receipt, later, REGISTERED, TRAIN, FIT, CONTROL, PREDICT, EVALUATE


def request(now, state_json=None, operation='advance', cursor='0', profiles=(), updates=()):
    return {'operation': operation, 'state_json': state_json, 'now': now, 'reader_sha256': 'a'*64,
            'profile_fence': cursor, 'profile_cursor': cursor, 'profiles': list(profiles), 'updates': list(updates)}


def entry(c, index):
    p = c['packet']; proof = copy.deepcopy(c['admission']); proof['profile_id'] = str(index)
    return {'db_profile_id': str(index), 'observed_at': p['frozen_at'],
            'wrapper': {'subject': p['scope'], 'access_status': 'allowed', 'purchase_study': p}, 'admission': proof}


def update(c, access='allowed'):
    return {'person_group': c['packet']['person_group'], 'outcomes': c['outcomes'],
            'coverage': c['coverage'], 'access_status': access}


def call(r):
    with patch.object(study, 'utc_now', return_value=r['now']):
        return runtime.advance(r)


def initialized():
    return call(request(REGISTERED, operation='init'))


def add_cases(state_json, cases, now, first_id=1):
    result = None
    for start in range(0, len(cases), 16):
        batch = cases[start:start+16]
        end = first_id+start+len(batch)-1
        result = call(request(now, state_json, cursor=str(end),
                              profiles=[entry(c, first_id+start+i) for i, c in enumerate(batch)]))
        state_json = result['state_json']
    return result


def apply_updates(state_json, cases, now):
    result = None; cursor = json.loads(state_json)['profile_cursor']
    for start in range(0, len(cases), 4):
        result = call(request(now, state_json, cursor=cursor, updates=[update(c) for c in cases[start:start+4]]))
        state_json = result['state_json']
    return result


def fitted_state():
    result = initialized()
    train = [case('runtime-train:'+str(i), TRAIN, i % 2, i % 2) for i in range(40)]
    old_control = [case('runtime-before-fit', TRAIN, 1, 1, 'control')]
    result = add_cases(result['state_json'], train+old_control, later(TRAIN, seconds=2))
    result = apply_updates(result['state_json'], train, FIT)
    return result, train, old_control


class RuntimeTests(unittest.TestCase):
    def test_init_is_actual_and_excludes_prior_profile_fence(self):
        result = call(request(REGISTERED, operation='init', cursor='991'))
        state = json.loads(result['state_json'])
        self.assertEqual(state['manifest']['registered_at'], REGISTERED)
        self.assertEqual(state['profile_cursor'], '991'); self.assertFalse(state['cases'])
        self.assertEqual(result['summary']['model_fitted'], 0)
        self.assertEqual(result['summary']['verified_payment_probability'], 0)
        self.assertEqual(set(result), {'state_json', 'summary'})
        self.assertEqual(runtime.protocol_info()['extractor_sha256'], study.PROTOCOL['extractor_sha256'])

    def test_fit_once_future_forecast_and_paired_mature_comparison(self):
        result, train, before_fit = fitted_state()
        state = json.loads(result['state_json'])
        self.assertEqual(result['summary']['model_fitted'], 1)
        self.assertEqual(result['summary']['predictions'], 0)
        self.assertIn(before_fit[0]['packet']['person_group'], state['model']['excluded_training_groups'])
        model_hash = state['model']['sha256']
        control = [case('runtime-new:'+str(i), CONTROL, i % 2, i % 2, 'control') for i in range(40)]
        result = add_cases(result['state_json'], control, PREDICT, 42)
        self.assertEqual(result['summary']['predictions'], 40)
        snapshot = json.loads(result['state_json'])
        frozen = snapshot['cases'][0]['packet']['sha256']
        forecast_hashes = [p['sha256'] for p in snapshot['predictions']]
        result = apply_updates(result['state_json'], control, EVALUATE)
        final = json.loads(result['state_json'])
        self.assertEqual(final['model']['sha256'], model_hash)
        self.assertEqual(final['cases'][0]['packet']['sha256'], frozen)
        self.assertEqual([p['sha256'] for p in final['predictions']], forecast_hashes)
        self.assertEqual(result['summary']['comparison_available'], 1)
        self.assertEqual(result['summary']['paired_resolved_people'], 40)
        self.assertEqual(result['summary']['conditional_score_advantage'], 1)
        self.assertEqual(result['summary']['all_lead_calibration_ready'], 0)
        self.assertEqual(result['summary']['coverage_is_admitted_journal_only'], 1)
        public = json.dumps(result['summary'])
        self.assertNotIn('runtime-train', public); self.assertNotIn('customer_key', public)
        self.assertLessEqual(len(result['state_json'].encode()), runtime.MAX_STATE_BYTES)
        partial = copy.deepcopy(train[0])
        partial['coverage'].update(status='partial', through=EVALUATE, available_at=EVALUATE)
        paused = apply_updates(result['state_json'], [partial], EVALUATE)
        self.assertEqual(paused['summary']['model_invalidated'], 0)
        self.assertEqual(paused['summary']['training_revalidation_pending'], 1)
        self.assertEqual(paused['summary']['comparison_available'], 0)
        self.assertEqual(paused['summary']['conditional_score_advantage'], 0)
        self.assertEqual(json.loads(paused['state_json'])['model'], final['model'])
        partial['coverage']['status'] = 'complete'
        resumed = apply_updates(paused['state_json'], [partial], EVALUATE)
        self.assertEqual(resumed['summary']['training_revalidation_pending'], 0)
        self.assertEqual(resumed['summary']['comparison_available'], 1)

    def test_first_packet_and_exclusions_cannot_be_replaced_or_cherry_picked(self):
        first = case('runtime-first', TRAIN, 1, 1)
        first['admission']['prior_conversations'] = 1
        result = add_cases(initialized()['state_json'], [first], later(TRAIN, seconds=2))
        second = case('runtime-first', later(TRAIN, seconds=10), 1, 0)
        result = add_cases(result['state_json'], [second], later(TRAIN, seconds=12), 2)
        state = json.loads(result['state_json'])
        self.assertEqual(len(state['cases']), 0)
        self.assertEqual(state['exclusions'][first['packet']['person_group']]['packet_sha256'], first['packet']['sha256'])
        self.assertEqual(result['summary']['excluded_people'], 1)
        self.assertEqual(result['summary']['train_resolved'], 0)

    def test_receipts_append_idempotently_and_mutation_fails_without_state_change(self):
        c = case('runtime-event', TRAIN, 1, 1)
        result = add_cases(initialized()['state_json'], [c], later(TRAIN, seconds=2))
        result = apply_updates(result['state_json'], [c], FIT)
        again = apply_updates(result['state_json'], [c], FIT)
        self.assertEqual(len(json.loads(again['state_json'])['cases'][0]['outcomes']), 1)
        before = again['state_json']; bad = copy.deepcopy(c)
        r = bad['outcomes'][0]; r['payload']['outcome'] = 'lost'
        r['sha256'] = study.digest({k: v for k, v in r.items() if k != 'sha256'})
        with self.assertRaisesRegex(study.NotReady, 'runtime_receipt_mutated'):
            apply_updates(before, [bad], FIT)
        self.assertEqual(before, again['state_json'])

    def test_capacity_returns_backpressure_cursor_and_preserves_unprocessed_profile(self):
        c = case('runtime-budget', TRAIN, 1, 1)
        initial = initialized()['state_json']
        with patch.object(runtime, 'MAX_STATE_BYTES', 18000):
            result = add_cases(initial, [c], later(TRAIN, seconds=2))
        self.assertEqual(result['summary']['capacity_paused'], 1)
        self.assertEqual(json.loads(result['state_json'])['profile_cursor'], '0')
        self.assertEqual(result['summary']['cases'], 0)
        retried = add_cases(result['state_json'], [c], later(TRAIN, seconds=3))
        self.assertEqual(retried['summary']['cases'], 1)
        self.assertEqual(json.loads(retried['state_json'])['profile_cursor'], '1')

    def test_mixed_new_and_old_updates_only_advance_old_monitor_rotation(self):
        old = [case('runtime-monitor-old:'+str(i), TRAIN, i % 2, i % 2) for i in range(3)]
        result = add_cases(initialized()['state_json'], old, later(TRAIN, seconds=2))
        new = [case('runtime-monitor-new:'+str(i), FIT, i % 2, i % 2) for i in range(2)]
        for c in new:
            c['outcomes'] = []
            c['coverage'].update(through=later(FIT, seconds=2), available_at=later(FIT, seconds=2), status='partial')
        result = call(request(later(FIT, seconds=2), result['state_json'], cursor='5',
                              profiles=[entry(c, 4+i) for i, c in enumerate(new)],
                              updates=[update(old[0]), update(old[1]), update(new[0]), update(new[1])]))
        self.assertEqual(json.loads(result['state_json'])['monitor_cursor'], 2)

    def test_capacity_blocked_new_update_is_deferred_but_old_update_is_retained(self):
        old = case('runtime-cap-old', TRAIN, 1, 1)
        initial = add_cases(initialized()['state_json'], [old], later(TRAIN, seconds=2))
        new = case('runtime-cap-new', FIT, 0, 0); new['outcomes'] = []
        new['coverage'].update(through=later(FIT, seconds=2), available_at=later(FIT, seconds=2), status='partial')
        r = request(later(FIT, seconds=2), initial['state_json'], cursor='2', profiles=[entry(new, 2)],
                    updates=[update(old), update(new)])
        with patch.object(study, 'MAX_CASES', 1):
            result = call(r)
        state = json.loads(result['state_json'])
        self.assertEqual(state['profile_cursor'], '1')
        self.assertEqual(len(state['cases'][0]['outcomes']), 1)
        self.assertEqual(result['summary']['deferred_updates'], 1)
        self.assertEqual(result['summary']['capacity_paused'], 1)
        invalid = copy.deepcopy(r); invalid['updates'][1]['person_group'] = '0'*64
        with patch.object(study, 'MAX_CASES', 1):
            with self.assertRaisesRegex(study.NotReady, 'runtime_unknown_person_update'): call(invalid)

    def test_corruption_clock_reader_and_native_profile_binding_fail_closed(self):
        initial = initialized()['state_json']
        bad = json.loads(initial); bad['profile_cursor'] = '9'
        with self.assertRaisesRegex(study.NotReady, 'runtime_state_hash'):
            call(request(TRAIN, json.dumps(bad), cursor='9'))
        changed = request(TRAIN, initial); changed['reader_sha256'] = 'b'*64
        with self.assertRaisesRegex(study.NotReady, 'runtime_reader_changed'): call(changed)
        old = request('2026-09-01T00:00:00Z', initial)
        with self.assertRaisesRegex(study.NotReady, 'runtime_clock_regressed|future_registration'): call(old)
        c = case('runtime-binding', TRAIN, 1, 1); e = entry(c, 1); e['admission']['profile_id'] = 'wrong'
        result = call(request(later(TRAIN, seconds=2), initial, cursor='1', profiles=[e]))
        self.assertEqual(result['summary']['excluded_people'], 1)

    def test_revoked_training_scope_invalidates_frozen_model_and_stops_forecasts(self):
        result, train, controls = fitted_state()
        result = call(request(FIT, result['state_json'], cursor='41', updates=[update(train[0], 'denied')]))
        self.assertEqual(result['summary']['model_invalidated'], 1)
        new = case('runtime-after-revoke', CONTROL, 1, 1, 'control')
        result = add_cases(result['state_json'], [new], PREDICT, 42)
        self.assertEqual(result['summary']['predictions'], 0)
        self.assertEqual(result['summary']['comparison_available'], 0)

    def test_late_training_target_revision_invalidates_without_rewriting_model_or_truth(self):
        initial, train, _ = fitted_state()
        before = json.loads(initial['state_json'])
        for index, new_outcome in [(0, 'won'), (1, 'open')]:
            with self.subTest(outcome=new_outcome):
                changed = copy.deepcopy(train[index])
                r = receipt(changed['packet'], new_outcome, 2, later(TRAIN, days=2))
                r['available_at'] = later(FIT, seconds=1)
                r = study.seal({k: v for k, v in r.items() if k != 'sha256'})
                changed['outcomes'].append(r)
                changed['coverage'].update(through=EVALUATE, available_at=EVALUATE)
                result = apply_updates(initial['state_json'], [changed], EVALUATE)
                final = json.loads(result['state_json'])
                self.assertEqual(result['summary']['model_invalidated'], 1)
                self.assertEqual(result['summary']['comparison_available'], 0)
                self.assertEqual(final['model'], before['model'])
                self.assertEqual(final['training_truth'], before['training_truth'])
                self.assertIsNone(final['evaluation'])
                # The new immutable receipt remains available for the audit.
                self.assertEqual(len(final['cases'][index]['outcomes']), 2)
                again = call(request(EVALUATE, result['state_json'], cursor='41'))
                self.assertEqual(again['summary']['model_invalidated'], 1)

    def test_transient_training_partial_and_post_horizon_receipt_preserve_model(self):
        result, train, _ = fitted_state()
        original = json.loads(result['state_json'])
        changed = copy.deepcopy(train[0])
        changed['coverage'].update(status='partial', through=EVALUATE, available_at=EVALUATE)
        result = apply_updates(result['state_json'], [changed], EVALUATE)
        self.assertEqual(result['summary']['model_invalidated'], 0)
        self.assertEqual(result['summary']['training_revalidation_pending'], 1)
        self.assertEqual(result['summary']['comparison_available'], 0)
        changed['coverage']['status'] = 'complete'
        changed['outcomes'].append(receipt(changed['packet'], 'open', 2, later(TRAIN, days=31)))
        result = apply_updates(result['state_json'], [changed], EVALUATE)
        final = json.loads(result['state_json'])
        self.assertEqual(result['summary']['model_invalidated'], 0)
        self.assertEqual(result['summary']['training_revalidation_pending'], 0)
        self.assertEqual(final['model'], original['model'])
        self.assertEqual(final['training_truth'], original['training_truth'])

    def test_late_control_recording_excludes_only_that_person_and_clears_stale_comparison(self):
        result, train, _ = fitted_state()
        controls = [case('runtime-late-control:'+str(i), CONTROL, i % 2, i % 2, 'control') for i in range(40)]
        result = add_cases(result['state_json'], controls, PREDICT, 42)
        saved = json.loads(result['state_json'])
        changed = copy.deepcopy(controls[0])
        r = receipt(changed['packet'], 'lost', 2, later(CONTROL, seconds=1))
        r['available_at'] = later(PREDICT, seconds=1)
        r = study.seal({k: v for k, v in r.items() if k != 'sha256'})
        changed['outcomes'].append(r)
        # First test the actual intake race, before mature comparison exists.
        race = apply_updates(result['state_json'], [changed]+controls[1:], EVALUATE)
        final = json.loads(race['state_json'])
        group = changed['packet']['person_group']
        self.assertEqual(final['exclusions'][group]['reason'], 'outcome_recorded_before_saved_forecast')
        self.assertEqual(race['summary']['control_resolved'], 39)
        self.assertEqual(race['summary']['comparison_available'], 0)
        self.assertEqual(final['predictions'], saved['predictions'])
        self.assertEqual(final['model'], saved['model'])
        # The same late event can arrive after a previously computed comparison.
        mature = apply_updates(result['state_json'], controls, EVALUATE)
        self.assertEqual(mature['summary']['comparison_available'], 1)
        revised = apply_updates(mature['state_json'], [changed], EVALUATE)
        self.assertIsNone(json.loads(revised['state_json'])['evaluation'])
        self.assertEqual(revised['summary']['comparison_available'], 0)
        self.assertEqual(revised['summary']['model_invalidated'], 0)
        retry = apply_updates(revised['state_json'], [changed], EVALUATE)
        self.assertEqual(retry['summary']['control_resolved'], 39)
        self.assertEqual(json.loads(retry['state_json'])['predictions'], saved['predictions'])

    def test_training_truth_missing_corrupt_or_material_access_unknown_fails_closed(self):
        result, train, _ = fitted_state()
        original = json.loads(result['state_json'])
        for mutation in ('missing', 'label', 'packet'):
            bad = copy.deepcopy(original); group = train[0]['packet']['person_group']
            if mutation == 'missing': del bad['training_truth'][group]
            if mutation == 'label': bad['training_truth'][group]['value'] = 1
            if mutation == 'packet': bad['training_truth'][group]['packet_sha256'] = '0'*64
            with self.assertRaisesRegex(study.NotReady, 'runtime_training_truth'):
                call(request(FIT, runtime.encoded(runtime.seal(bad)), cursor='41'))
        denied = call(request(FIT, result['state_json'], cursor='41', updates=[update(train[0], 'unknown')]))
        self.assertEqual(denied['summary']['model_invalidated'], 1)
        self.assertEqual(json.loads(denied['state_json'])['model'], original['model'])

    def test_unregistered_extractor_is_descriptive_and_never_admitted(self):
        c = case('runtime-source', TRAIN, 1, 1)
        p = c['packet']; p['extractor_sha256'] = '9'*64
        c['packet'] = study.seal({k: v for k, v in p.items() if k != 'sha256'})
        c['admission']['packet_sha256'] = c['packet']['sha256']
        result = add_cases(initialized()['state_json'], [c], later(TRAIN, seconds=2))
        self.assertEqual(result['summary']['excluded_people'], 1)
        self.assertEqual(result['summary']['model_fitted'], 0)
        self.assertEqual(json.loads(result['state_json'])['exclusions'][c['packet']['person_group']]['packet_sha256'], c['packet']['sha256'])

    def test_old_people_use_compact_exclusions_without_exhausting_admitted_case_slots(self):
        people = [case('runtime-old:'+str(i), TRAIN, 1, 1) for i in range(256)]
        for c in people: c['admission']['prior_conversations'] = 1
        result = add_cases(initialized()['state_json'], people, later(TRAIN, seconds=2))
        self.assertEqual(result['summary']['cases'], 0)
        self.assertEqual(result['summary']['excluded_people'], 256)
        new = case('runtime-actually-new', TRAIN, 1, 1)
        result = add_cases(result['state_json'], [new], later(TRAIN, seconds=3), 257)
        self.assertEqual(result['summary']['cases'], 1)
        self.assertEqual(result['summary']['capacity_paused'], 0)
        self.assertEqual(json.loads(result['state_json'])['profile_cursor'], '257')

    def test_cli_protocol_and_redacted_failure_have_no_file_io_contract(self):
        run = subprocess.run([sys.executable, str(ROOT/'tools/purchase_study_runtime.py')],
                             input=b'{"operation":"protocol"}', capture_output=True)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(json.loads(run.stdout)['protocol_sha256'], study.PROTOCOL_SHA)
        bad = subprocess.run([sys.executable, str(ROOT/'tools/purchase_study_runtime.py')],
                             input=b'{"operation":"advance","private":"do-not-echo"}', capture_output=True)
        self.assertEqual(bad.returncode, 2); self.assertFalse(bad.stdout)
        self.assertNotIn(b'do-not-echo', bad.stderr)


if __name__ == '__main__':
    unittest.main(verbosity=2)
