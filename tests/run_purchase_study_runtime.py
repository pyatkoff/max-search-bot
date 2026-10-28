#!/usr/bin/env python3
"""Synthetic server bridge lifecycle; no real data, source calls or filesystem state."""
import copy
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'tools')); sys.path.insert(0, str(ROOT/'tests'))
import purchase_conversion_study as study
import purchase_study_runtime as runtime
from run_purchase_conversion_study import case, receipt, later, REGISTERED, TRAIN, FIT, CONTROL, PREDICT, EVALUATE

READER_SHA = 'a'*64


def request(now, state_json=None, operation='advance', cursor='0', profiles=(), updates=()):
    return {'operation': operation, 'state_json': state_json, 'now': now, 'reader_sha256': READER_SHA,
            'profile_fence': cursor, 'profile_cursor': cursor, 'profiles': list(profiles), 'updates': list(updates)}


def entry(c, index):
    p = c['packet']; proof = copy.deepcopy(c['admission']); proof['profile_id'] = str(index)
    proof['reader_sha256'] = READER_SHA
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


def confirm_forecasts(state_json, now):
    return call(request(now, state_json, cursor=json.loads(state_json)['profile_cursor']))


def fitted_state():
    result = initialized()
    train = [case('runtime-train:'+str(i), TRAIN, i % 2, i % 2) for i in range(40)]
    old_control = [case('runtime-before-fit', TRAIN, 1, 1, 'control')]
    result = add_cases(result['state_json'], train+old_control, later(TRAIN, seconds=2))
    result = apply_updates(result['state_json'], train, FIT)
    return result, train, old_control


class RuntimeTests(unittest.TestCase):
    @unittest.skipUnless(shutil.which('php'), 'PHP available in required CI; local Python-only runtime')
    def test_actual_php_private_store_preserves_fitted_model_and_forecasts_for_evaluation(self):
        # Actual PHP owner methods, not a replacement JSON persistence algorithm.
        # The cohort is synthetic; this verifies transport, never predictive gain.
        prefix = 'require $argv[1]."/services/ModeledConversionPurchaseStudy.php";'
        reader = subprocess.run(['php', '-r', prefix+
            '$method=new ReflectionMethod(ModeledConversionPurchaseStudy::class,"readerSha");echo $method->invoke(null);', str(ROOT)],
            capture_output=True)
        self.assertEqual(reader.returncode, 0, reader.stderr)
        reader_sha = reader.stdout.decode()
        self.assertTrue(study.hash_value(reader_sha))
        with patch(__name__+'.READER_SHA', reader_sha):
            result, train, _ = fitted_state()
            controls = [case('php-persist-control:'+str(i), CONTROL, i % 2, i % 2, 'control') for i in range(40)]
            result = add_cases(result['state_json'], controls, PREDICT, 42)
            before = json.loads(result['state_json'])
            self.assertEqual(len(before['predictions']), 40)
            pending_shas = {p['sha256'] for p in before['predictions']}-set(before['prediction_confirmations'])
            self.assertTrue(pending_shas)
            program = prefix+'''
                $input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
                $owner=new ModeledConversionPurchaseStudy(new PDO('sqlite::memory:'),$argv[2]);
                $type=new ReflectionClass($owner);
                $type->getMethod('directory')->invoke($owner,true);
                $lock=$type->getMethod('lock')->invoke($owner,LOCK_EX);
                if(!is_resource($lock))throw new RuntimeException('fixture_lock');
                try{
                    $receipt=$input['summary']+array_fill_keys($type->getConstant('COUNTERS'),0)
                        +['observed_at_utc'=>gmdate('Y-m-d\\TH:i:s\\Z')];
                    $type->getMethod('write')->invoke($owner,$input['state_json'],$receipt);
                    $saved=$type->getMethod('read')->invoke($owner);
                    echo json_encode(['state_json'=>$saved['state_json'],
                        'model_sha256'=>$saved['state']['model']['sha256'],
                        'predictions'=>count($saved['state']['predictions']),
                        'file_mode'=>fileperms($argv[2].'/state.json')&0777],JSON_THROW_ON_ERROR);
                }finally{flock($lock,LOCK_UN);fclose($lock);}
            '''
            with tempfile.TemporaryDirectory(prefix='purchase-study-php-') as directory:
                saved = subprocess.run(['php', '-r', program, str(ROOT), directory+'/private'],
                    input=json.dumps(result).encode(), capture_output=True)
                self.assertEqual(saved.returncode, 0, saved.stderr)
                stored = json.loads(saved.stdout)
            self.assertEqual(stored['state_json'], result['state_json'])
            self.assertEqual(stored['model_sha256'], before['model']['sha256'])
            self.assertEqual(stored['predictions'], 40)
            self.assertEqual(stored['file_mode'], 0o600)
            fresh_read_at = later(PREDICT, seconds=1)
            reloaded = confirm_forecasts(stored['state_json'], fresh_read_at)
            confirmations = json.loads(reloaded['state_json'])['prediction_confirmations']
            self.assertEqual(len(confirmations), 40)
            for sha in pending_shas:
                self.assertEqual(confirmations[sha]['loaded_state_sha256'], before['sha256'])
                self.assertEqual(confirmations[sha]['confirmed_at'], fresh_read_at)
            for sha, original in before['prediction_confirmations'].items():
                self.assertEqual(confirmations[sha], original)
            evaluated = apply_updates(reloaded['state_json'], controls, EVALUATE)
            final = json.loads(evaluated['state_json'])
            self.assertEqual(final['model'], before['model'])
            self.assertEqual(final['predictions'], before['predictions'])
            self.assertEqual(evaluated['summary']['paired_resolved_people'], 40)
            self.assertEqual(evaluated['summary']['comparison_available'], 1)

    def test_init_is_actual_and_excludes_prior_profile_fence(self):
        result = call(request(REGISTERED, operation='init', cursor='991'))
        state = json.loads(result['state_json'])
        self.assertEqual(state['manifest']['registered_at'], REGISTERED)
        self.assertEqual(state['profile_cursor'], '991'); self.assertFalse(state['cases'])
        self.assertEqual(result['summary']['model_fitted'], 0)
        self.assertEqual(result['summary']['verified_payment_probability'], 0)
        self.assertEqual(set(result), {'state_json', 'summary'})
        self.assertEqual(runtime.protocol_info()['extractor_sha256'], study.PROTOCOL['extractor_sha256'])
        self.assertEqual(runtime.protocol_info()['maximum_train_cases'], 128)
        self.assertEqual(runtime.protocol_info()['maximum_control_cases'], 128)
        self.assertEqual(runtime.protocol_info()['maximum_training_case_bytes'], 524288)
        self.assertEqual(runtime.protocol_info()['maximum_exclusion_ledger_bytes'], 131072)

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
        result = confirm_forecasts(result['state_json'], later(PREDICT, seconds=1))
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

    def test_first_128_training_people_cannot_starve_future_control_cohort(self):
        train = [case('quota-train:'+str(i), TRAIN, i % 2, i % 2) for i in range(129)]
        result = add_cases(initialized()['state_json'], train, later(TRAIN, seconds=2))
        enrolled = json.loads(result['state_json'])
        overflow = train[-1]['packet']['person_group']
        self.assertEqual(result['summary']['cases'], 128)
        self.assertEqual(enrolled['profile_cursor'], '129')
        self.assertEqual(enrolled['exclusions'][overflow]['reason'], 'train_person_quota')
        self.assertEqual(enrolled['exclusions'][overflow]['packet_sha256'], train[-1]['packet']['sha256'])
        # A richer, later packet cannot replace the first excluded opportunity.
        replay = case('quota-train:128', later(TRAIN, seconds=10), 1, 0)
        result = add_cases(result['state_json'], [replay], later(TRAIN, seconds=12), 130)
        self.assertEqual(json.loads(result['state_json'])['exclusions'][overflow], enrolled['exclusions'][overflow])
        self.assertEqual(result['summary']['cases'], 128)
        result = apply_updates(result['state_json'], train[:40], FIT)
        self.assertEqual(result['summary']['model_fitted'], 1)
        controls = [case('quota-control:'+str(i), CONTROL, i % 2, i % 2, 'control') for i in range(40)]
        result = add_cases(result['state_json'], controls, PREDICT, 131)
        self.assertEqual(result['summary']['predictions'], 40)
        self.assertEqual(result['summary']['control_people'], 40)
        self.assertEqual(result['summary']['cases'], 168)
        result = confirm_forecasts(result['state_json'], later(PREDICT, seconds=1))
        result = apply_updates(result['state_json'], controls, EVALUATE)
        self.assertEqual(result['summary']['paired_resolved_people'], 40)
        self.assertEqual(result['summary']['comparison_available'], 1)
        self.assertLessEqual(len(result['state_json'].encode()), runtime.MAX_STATE_BYTES)

    def test_control_arm_has_its_own_first_seen_quota(self):
        result, _, _ = fitted_state()
        controls = [case('quota-control-limit:'+str(i), CONTROL, i % 2, i % 2, 'control') for i in range(129)]
        result = add_cases(result['state_json'], controls, PREDICT, 42)
        state = json.loads(result['state_json'])
        self.assertEqual(result['summary']['control_people'], 128)
        self.assertEqual(result['summary']['predictions'], 128)
        self.assertEqual(state['profile_cursor'], '170')
        self.assertEqual(state['exclusions'][controls[-1]['packet']['person_group']]['reason'], 'control_person_quota')

    def test_training_byte_quota_is_sticky_and_does_not_block_future_control(self):
        result, _, _ = fitted_state()
        state = json.loads(result['state_json'])
        training_bytes = len(runtime.encoded(state['cases']).encode())
        new_train = case('quota-training-bytes', CONTROL, 1, 1)
        with patch.object(runtime, 'TRAIN_CASE_BYTES_LIMIT', training_bytes+100):
            result = add_cases(result['state_json'], [new_train], PREDICT, 42)
            self.assertEqual(result['summary']['cases'], 40)
            group = new_train['packet']['person_group']
            self.assertEqual(json.loads(result['state_json'])['exclusions'][group]['reason'], 'train_case_byte_quota')
            new_control = case('quota-control-byte-headroom', CONTROL, 1, 1, 'control')
            result = add_cases(result['state_json'], [new_control], PREDICT, 43)
        self.assertEqual(result['summary']['predictions'], 1)
        self.assertEqual(json.loads(result['state_json'])['profile_cursor'], '43')

    def test_unstorable_training_correction_persists_small_fail_closed_disposition(self):
        initial, train, _ = fitted_state()
        before = json.loads(initial['state_json'])
        revised = copy.deepcopy(train[0])
        r = receipt(revised['packet'], 'won', 2, later(TRAIN, days=2))
        r['available_at'] = later(FIT, seconds=1)
        revised['outcomes'].append(study.seal({k: v for k, v in r.items() if k != 'sha256'}))
        revised['coverage'].update(through=EVALUATE, available_at=EVALUATE)
        train_bytes = len(runtime.encoded(before['cases']).encode())
        for budget in ('training', 'whole_state'):
            with self.subTest(budget=budget):
                limit = (patch.object(runtime, 'TRAIN_CASE_BYTES_LIMIT', train_bytes+100) if budget == 'training'
                         else patch.object(runtime, 'MAX_STATE_BYTES', len(initial['state_json'].encode())+runtime.RESERVE_BYTES+100))
                with limit:
                    result = apply_updates(initial['state_json'], [revised], EVALUATE)
                state = json.loads(result['state_json'])
                group = revised['packet']['person_group']
                self.assertEqual(result['summary']['model_invalidated'], 1)
                self.assertEqual(result['summary']['capacity_paused'], 1)
                self.assertEqual(result['summary']['comparison_available'], 0)
                self.assertEqual(state['exclusions'][group]['reason'], 'outcome_update_byte_capacity')
                self.assertEqual(state['model'], before['model'])
                self.assertEqual(state['cases'][0]['outcomes'], before['cases'][0]['outcomes'])
                self.assertIsNone(state['evaluation'])

    def test_bounded_exclusion_compaction_keeps_cases_and_allows_new_future_controls(self):
        result, _, _ = fitted_state()
        kept = case('ledger-retained-control', CONTROL, 1, 1, 'control')
        result = add_cases(result['state_json'], [kept], PREDICT, 42)
        denied = update(kept, 'denied')
        result = call(request(PREDICT, result['state_json'], cursor='42', updates=[denied]))
        retained_group = kept['packet']['person_group']
        model = json.loads(result['state_json'])['model']
        original_forecast = json.loads(result['state_json'])['predictions'][0]
        excluded = [case('ledger-old:'+str(i), CONTROL, i % 2, i % 2) for i in range(24)]
        for c in excluded: c['admission']['prior_conversations'] = 1
        with patch.object(runtime, 'EXCLUSION_LEDGER_BYTES_LIMIT', 4000):
            result = add_cases(result['state_json'], excluded, PREDICT, 43)
            first = json.loads(result['state_json'])
            self.assertEqual(first['exclusion_compaction']['created_after'], CONTROL)
            self.assertGreater(first['exclusion_compaction']['people'], 0)
            self.assertLessEqual(len(runtime.encoded(first['exclusions']).encode()), 4000)
            self.assertEqual(first['exclusions'][retained_group]['reason'], 'current_access_denied')
            later_excluded = [case('ledger-later:'+str(i), later(CONTROL, seconds=1), i % 2, i % 2) for i in range(24)]
            for c in later_excluded: c['admission']['prior_conversations'] = 1
            result = add_cases(result['state_json'], later_excluded, PREDICT, 67)
            second = json.loads(result['state_json'])
            self.assertEqual(second['exclusion_compaction']['created_after'], later(CONTROL, seconds=1))
            self.assertGreater(second['exclusion_compaction']['people'], first['exclusion_compaction']['people'])
            self.assertNotEqual(second['exclusion_compaction']['chain_sha256'], first['exclusion_compaction']['chain_sha256'])
            self.assertEqual(second['exclusions'][retained_group], first['exclusions'][retained_group])
            # Even if scope is later restored and an input asserts no earlier
            # packet, the original conversation clock cannot pass the floor.
            replay = case('ledger-old:0', later(CONTROL, seconds=2), 1, 0)
            replay['admission'].update(conversation_created_at=CONTROL, person_first_seen_at=CONTROL)
            result = add_cases(result['state_json'], [replay], later(CONTROL, seconds=3), 91)
            replayed = json.loads(result['state_json'])
            self.assertEqual(replayed['exclusion_compaction'], second['exclusion_compaction'])
            self.assertFalse(any(c['packet']['person_group'] == replay['packet']['person_group'] for c in replayed['cases']))
            controls = [case('ledger-genuinely-new:'+str(i), later(CONTROL, seconds=3), i % 2, i % 2, 'control') for i in range(40)]
            result = add_cases(result['state_json'], controls, later(CONTROL, seconds=4), 92)
            self.assertEqual(result['summary']['control_people'], 40)
            self.assertEqual(result['summary']['predictions'], 41)
            result = confirm_forecasts(result['state_json'], later(CONTROL, seconds=5))
            result = apply_updates(result['state_json'], controls, EVALUATE)
            final = json.loads(result['state_json'])
            self.assertEqual(result['summary']['comparison_available'], 1)
            self.assertEqual(result['summary']['paired_resolved_people'], 40)
            self.assertEqual(final['model'], model)
            self.assertEqual(final['predictions'][0], original_forecast)
            self.assertEqual(final['exclusions'][retained_group]['reason'], 'current_access_denied')

    def test_malformed_capacity_compaction_state_is_rejected(self):
        original = json.loads(initialized()['state_json'])
        for mutation in ('missing', 'hash', 'future', 'negative', 'unproved_floor'):
            with self.subTest(mutation=mutation):
                bad = copy.deepcopy(original)
                if mutation == 'missing': del bad['exclusion_compaction']
                if mutation == 'hash': bad['exclusion_compaction']['chain_sha256'] = 'invalid'
                if mutation == 'future': bad['exclusion_compaction']['created_after'] = TRAIN
                if mutation == 'negative': bad['exclusion_compaction']['people'] = -1
                if mutation == 'unproved_floor': bad['exclusion_compaction']['created_after'] = '2026-09-01T00:00:00Z'
                with self.assertRaisesRegex(study.NotReady, 'runtime_state_shape|runtime_exclusion_compaction'):
                    call(request(TRAIN, runtime.encoded(runtime.seal(bad))))

    def test_same_pass_compacted_rejections_skip_only_their_new_updates(self):
        old = case('same-pass-existing-train', TRAIN, 1, 1)
        old['outcomes'] = []
        old['coverage'].update(through=later(TRAIN, seconds=2), available_at=later(TRAIN, seconds=2), status='partial')
        base = add_cases(initialized()['state_json'], [old], later(TRAIN, seconds=2))
        base = apply_updates(base['state_json'], [old], later(TRAIN, seconds=2))
        now = later(TRAIN, seconds=4)
        old['coverage'].update(through=now, available_at=now)
        train_bytes = len(runtime.encoded(json.loads(base['state_json'])['cases']).encode())
        for reason in ('prefit_control', 'arm_quota', 'training_byte_quota'):
            with self.subTest(reason=reason):
                new = case('same-pass-new:'+reason, later(TRAIN, seconds=3), 0, 0,
                           'control' if reason == 'prefit_control' else 'train')
                new['outcomes'] = []
                new['coverage'].update(through=now, available_at=now, status='partial')
                quota = (patch.object(runtime, 'ARM_CASE_LIMIT', 1) if reason == 'arm_quota'
                         else patch.object(runtime, 'TRAIN_CASE_BYTES_LIMIT', train_bytes+500 if reason == 'training_byte_quota' else runtime.TRAIN_CASE_BYTES_LIMIT))
                with patch.object(runtime, 'EXCLUSION_LEDGER_BYTES_LIMIT', 300), quota:
                    r = request(now, base['state_json'], cursor='2', profiles=[entry(new, 2)],
                                updates=[update(new), update(old)])
                    result = call(r)
                    state = json.loads(result['state_json'])
                    self.assertEqual(state['exclusion_compaction']['people'], 1)
                    self.assertNotIn(new['packet']['person_group'], state['exclusions'])
                    self.assertEqual(state['profile_cursor'], '2')
                    self.assertEqual(state['cases'][0]['coverage']['through'], now)
                    self.assertEqual(result['summary']['train_people'], 1)
                    self.assertEqual(result['summary']['excluded_people'], 1)
                    unrelated = copy.deepcopy(r); unrelated['updates'][0]['person_group'] = '0'*64
                    with self.assertRaisesRegex(study.NotReady, 'runtime_unknown_person_update'):
                        call(unrelated)

    def test_known_noncase_replay_update_stays_dispositioned_after_later_batch_compaction(self):
        first = case('same-pass-previously-excluded', TRAIN, 0, 0, 'control')
        first['outcomes'] = []
        first['coverage'].update(through=later(TRAIN, seconds=2), available_at=later(TRAIN, seconds=2), status='partial')
        initial = add_cases(initialized()['state_json'], [first], later(TRAIN, seconds=2))
        old_ledger_bytes = len(runtime.encoded(json.loads(initial['state_json'])['exclusions']).encode())
        now = later(TRAIN, seconds=4)
        second = case('same-pass-compacting-new', later(TRAIN, seconds=3), 0, 0, 'control')
        second['outcomes'] = []
        second['coverage'].update(through=now, available_at=now, status='partial')
        with patch.object(runtime, 'EXCLUSION_LEDGER_BYTES_LIMIT', old_ledger_bytes+100):
            result = call(request(now, initial['state_json'], cursor='3', profiles=[entry(first, 2), entry(second, 3)],
                                  updates=[update(first), update(second)]))
        state = json.loads(result['state_json'])
        self.assertFalse(state['exclusions'])
        self.assertEqual(state['exclusion_compaction']['people'], 2)
        self.assertEqual(state['profile_cursor'], '3')
        self.assertEqual(result['summary']['cases'], 0)

    def test_unbound_creation_exclusion_stays_sticky_when_safe_ledger_entries_compact(self):
        unknown = case('ledger-unbound-creation', TRAIN, 1, 1)
        unknown['admission']['conversation_created_at'] = later(TRAIN, days=1)
        result = add_cases(initialized()['state_json'], [unknown], later(TRAIN, seconds=2))
        group = unknown['packet']['person_group']
        self.assertIsNone(json.loads(result['state_json'])['exclusions'][group]['creation_bound'])
        safe = [case('ledger-safe-old:'+str(i), TRAIN, i % 2, i % 2) for i in range(12)]
        for c in safe: c['admission']['prior_conversations'] = 1
        with patch.object(runtime, 'EXCLUSION_LEDGER_BYTES_LIMIT', 2000):
            result = add_cases(result['state_json'], safe, later(TRAIN, seconds=2), 2)
            state = json.loads(result['state_json'])
            self.assertGreater(state['exclusion_compaction']['people'], 0)
            self.assertIn(group, state['exclusions'])
            self.assertIsNone(state['exclusions'][group]['creation_bound'])
            replay = case('ledger-unbound-creation', FIT, 1, 0)
            result = add_cases(result['state_json'], [replay], FIT, 14)
            self.assertEqual(result['summary']['cases'], 0)
            self.assertEqual(json.loads(result['state_json'])['exclusions'][group], state['exclusions'][group])
        bad = json.loads(result['state_json'])
        bad['exclusions'][group]['creation_bound'] = {'created_at': FIT, 'source_sha256': 'f'*64}
        with self.assertRaisesRegex(study.NotReady, 'runtime_exclusion_bound'):
            call(request(FIT, runtime.encoded(runtime.seal(bad)), cursor='14'))

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
        result = confirm_forecasts(result['state_json'], later(PREDICT, seconds=1))
        saved = json.loads(result['state_json'])
        changed = copy.deepcopy(controls[-1])
        r = receipt(changed['packet'], 'lost', 2, later(PREDICT, seconds=1))
        r['available_at'] = EVALUATE
        r = study.seal({k: v for k, v in r.items() if k != 'sha256'})
        changed['outcomes'].append(r)
        # First test the actual intake race, before mature comparison exists.
        race = apply_updates(result['state_json'], controls[:-1]+[changed], EVALUATE)
        final = json.loads(race['state_json'])
        group = changed['packet']['person_group']
        self.assertEqual(final['exclusions'][group]['reason'], 'outcome_recorded_before_forecast_confirmation')
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

    def test_cpu_forecast_then_outcome_before_delayed_persistence_is_excluded_on_next_read(self):
        result, _, _ = fitted_state()
        control = case('persistence-race', CONTROL, 1, 1, 'control')
        result = add_cases(result['state_json'], [control], PREDICT, 42)
        saved = json.loads(result['state_json'])
        forecast = saved['predictions'][0]
        self.assertFalse(saved['prediction_confirmations'])
        self.assertEqual(result['summary']['control_people'], 1)
        self.assertEqual(result['summary']['control_resolved'], 0)
        self.assertEqual(result['summary']['comparison_available'], 0)
        # Computed at t, won at t+1, file durable only at t+2, next read t+3.
        # The input models the already-durable previous state, not a backdate.
        won = receipt(control['packet'], 'won', at=later(PREDICT, seconds=1))
        control['outcomes'] = [won]
        next_read = later(PREDICT, seconds=3)
        control['coverage'].update(through=next_read, available_at=next_read, status='partial')
        result = apply_updates(result['state_json'], [control], next_read)
        state = json.loads(result['state_json'])
        confirmation = state['prediction_confirmations'][forecast['sha256']]
        self.assertEqual(confirmation['confirmed_at'], next_read)
        self.assertEqual(confirmation['loaded_state_sha256'], saved['sha256'])
        self.assertEqual(state['predictions'][0], forecast)
        self.assertEqual(state['model'], saved['model'])
        self.assertEqual(state['exclusions'][control['packet']['person_group']]['reason'],
                         'outcome_recorded_before_forecast_confirmation')
        self.assertEqual(result['summary']['control_resolved'], 0)

    def test_saved_forecast_confirmed_before_truly_later_outcome_remains_eligible(self):
        result, _, _ = fitted_state()
        control = case('persistence-later-outcome', CONTROL, 1, 1, 'control')
        result = add_cases(result['state_json'], [control], PREDICT, 42)
        saved = json.loads(result['state_json'])
        confirmed_at = later(PREDICT, seconds=1)
        result = confirm_forecasts(result['state_json'], confirmed_at)
        confirmed = json.loads(result['state_json'])
        self.assertEqual(len(confirmed['prediction_confirmations']), 1)
        control['outcomes'] = [receipt(control['packet'], 'won', at=later(confirmed_at, seconds=1))]
        result = apply_updates(result['state_json'], [control], EVALUATE)
        state = json.loads(result['state_json'])
        self.assertNotIn(control['packet']['person_group'], state['exclusions'])
        self.assertEqual(result['summary']['control_people'], 1)
        self.assertEqual(result['summary']['control_resolved'], 1)
        self.assertEqual(state['predictions'], saved['predictions'])
        self.assertEqual(state['model'], saved['model'])
        self.assertEqual(state['prediction_confirmations'], confirmed['prediction_confirmations'])

    def test_confirmation_artifact_binding_clocks_and_32_pending_byte_reserve(self):
        result, _, _ = fitted_state()
        controls = [case('pending-confirmation:'+str(i), CONTROL, i % 2, i % 2, 'control') for i in range(32)]
        # One supported CLI batch: every prediction is computed in this pass.
        result = call(request(PREDICT, result['state_json'], cursor='73',
                              profiles=[entry(c, 42+i) for i, c in enumerate(controls)]))
        saved = json.loads(result['state_json'])
        self.assertEqual(len(saved['predictions']), 32)
        self.assertFalse(saved['prediction_confirmations'])
        # Even complete/mature future targets cannot be counted before the
        # forecast's later-read evidence exists; only the projection changes.
        view = copy.deepcopy(saved)
        for c, original in zip(view['cases'][40:], controls):
            c['outcomes'] = original['outcomes']; c['coverage'] = original['coverage']
        with patch.object(study, 'utc_now', return_value=EVALUATE):
            report = study.readiness(runtime.document(view))
        self.assertEqual(report['counts']['control']['people'], 32)
        self.assertEqual(report['counts']['control']['resolved'], 0)
        self.assertFalse(report['comparison_support_ready'])
        self.assertEqual(view['cases'][40]['coverage']['status'], 'complete')
        reserved = 32*runtime.CONFIRMATION_RESERVE_BYTES
        with patch.object(runtime, 'MAX_STATE_BYTES', len(result['state_json'].encode())+reserved+4096):
            self.assertTrue(runtime.within_budget(saved, 4096))
            confirmed = confirm_forecasts(result['state_json'], later(PREDICT, seconds=1))
        state = json.loads(confirmed['state_json'])
        self.assertEqual(len(state['prediction_confirmations']), 32)
        self.assertEqual(state['predictions'], saved['predictions'])
        for key, value in state['prediction_confirmations'].items():
            self.assertLess(len(runtime.encoded({key: value}).encode()), runtime.CONFIRMATION_RESERVE_BYTES)
        key = state['predictions'][0]['sha256']
        for mutation in ('unknown_prediction', 'moved_clock', 'moved_state', 'early', 'future', 'missing_binding'):
            with self.subTest(mutation=mutation):
                bad = copy.deepcopy(state); c = bad['prediction_confirmations'][key]
                if mutation == 'unknown_prediction': bad['prediction_confirmations']['0'*64] = bad['prediction_confirmations'].pop(key)
                if mutation == 'moved_clock': c['confirmed_at'] = PREDICT
                if mutation == 'moved_state': c['loaded_state_sha256'] = 'f'*64
                if mutation == 'early': c['confirmed_at'] = CONTROL
                if mutation == 'future': c['confirmed_at'] = EVALUATE
                if mutation == 'missing_binding': del c['loaded_state_sha256']
                if mutation in ('early', 'future'):
                    bad['prediction_confirmations'][key] = study.seal({k: v for k, v in c.items() if k != 'sha256'})
                with self.assertRaisesRegex(study.NotReady, 'runtime_confirmation'):
                    call(request(later(PREDICT, seconds=1), runtime.encoded(runtime.seal(bad)), cursor='73'))

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
