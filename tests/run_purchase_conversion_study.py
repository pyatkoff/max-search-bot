#!/usr/bin/env python3
"""Synthetic research contracts, never claims about buyer accuracy or real data."""
import copy
import json
from pathlib import Path
import subprocess
import shutil
import sys
import unittest
from datetime import timedelta
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'tools'))
sys.path.insert(0, str(ROOT/'tests'))
import purchase_conversion_study as study
import profile_conversion_journal as journal
from run_conversion_profiles import journal_row, journal_document, membership_journal_receipt, J_EXTRACT

REGISTERED = '2026-10-01T00:00:00Z'
TRAIN = '2026-10-02T12:00:00Z'
FIT = '2026-12-01T00:00:00Z'
CONTROL = '2026-12-02T12:00:00Z'
PREDICT = '2026-12-02T12:00:02Z'
EVALUATE = '2027-01-10T00:00:00Z'


def later(at, days=0, seconds=0):
    return (study.moment(at)+timedelta(days=days, seconds=seconds)).strftime('%Y-%m-%dT%H:%M:%SZ')


def manifest():
    return {'study_id': 'synthetic-study', 'registered_at': REGISTERED,
            'protocol_sha256': study.PROTOCOL_SHA, 'reader_kind': 'synthetic_contract', 'reader_sha256': 'a'*64}


def packet(person, at, condition=0, baseline=0):
    subject = {'project_key': 'anytour', 'platform': 'max', 'customer_key': person, 'conversation_id': 'lead:'+person}
    b = [0.]*len(study.BASE_NAMES); b[study.BASE_NAMES.index('activity')] = 1.
    b[study.BASE_NAMES.index('subscription_known')] = 1.
    b[study.BASE_NAMES.index('subscription')] = float(baseline)
    c = [0.]*len(study.CONDITION_NAMES); c[study.CONDITION_NAMES.index('budget_known')] = 1.
    c[study.CONDITION_NAMES.index('budget_max')] = float(condition)
    return study.seal({'kind': 'frozen_profile', 'version': study.VERSION, 'target': study.TARGET,
                       'protocol_sha256': study.PROTOCOL_SHA, 'scope': subject, 'person_group': study.group_key(subject),
                       'frozen_at': at, 'source_fence_at': at, 'profile_fingerprint': study.digest([person, at]),
                       'extractor_sha256': study.PROTOCOL['extractor_sha256'], 'references': [], 'baseline_names': list(study.BASE_NAMES),
                       'condition_names': list(study.CONDITION_NAMES), 'baseline': b, 'conditions': c,
                       'inquiry_admission': 'not_established', 'source_access': 'allowed', 'legacy_trip_status': 'unknown',
                       'assignment': study.assignment_for_scope(subject), 'assigned_at': at,
                       'purchase_probability': None, 'export_allowed': False})


def proof(p):
    return {'scope': p['scope'], 'packet_sha256': p['sha256'], 'profile_id': 'profile:'+p['scope']['customer_key'],
            'reader_sha256': 'a'*64, 'run_id': 'synthetic-reader', 'observed_at': later(p['frozen_at'], seconds=1),
            'conversation_created_at': p['frozen_at'], 'person_first_seen_at': p['frozen_at'],
            'prior_conversations': 0, 'earlier_profiles': 0, 'exposure': 'unseen', 'exposure_ledger_sha256': 'c'*64,
            'source_reference': {'kind': 'native_inquiry_metadata', 'id': p['scope']['conversation_id'],
                                 'sha256': 'd'*64, 'information_fence': '1000'}}


def receipt(p, outcome, index=1, at=None, actor='manager'):
    at = at or later(p['frozen_at'], days=1)
    payload = {'version': 1, 'origin': 'existing_sales_pipeline', 'producer': 'sales_pipeline_manager_assertion',
               'evidence_basis': 'manager_operational_assertion', 'scope': p['scope'], 'outcome': outcome,
               'reason_key': None, 'sale_amount': None, 'sale_amount_basis': 'sale_revenue_assertion_not_commission',
               'sale_date': None, 'sale_date_precision': 'unknown', 'actor_role': actor, 'inquiry_binding': None,
               'profile_id': None, 'episode_key': None, 'previous_outcome': None, 'operation_id': 'e'*32,
               '_recorded_clock': {'version': 1, 'basis': 'server_recording_unix', 'unix': int(study.moment(at).timestamp())}}
    r = {'receipt_id': 'receipt:'+p['scope']['customer_key']+':'+str(index), 'scope': p['scope'],
         'event_type': 'sales_pipeline_outcome_observed', 'payload': payload, 'recorded_at': at,
         'available_at': later(at, seconds=1),
         'source_reference': {'kind': 'conversation_event', 'id': 'event:'+p['scope']['customer_key']+':'+str(index),
                              'sha256': 'f'*64, 'journal_id': str(index)}}
    return {**r, 'sha256': study.digest(r)}


def case(person, at, y, condition, role='train', baseline=0):
    # Synthetic groups are selected by the same preregistered hash rule, not
    # assigned to an arm after observing an outcome.
    original = person
    for suffix in range(1000):
        person = original+':'+str(suffix)
        subject = {'project_key': 'anytour', 'customer_key': person}
        if study.assignment_for_scope(subject) == role:
            break
    p = packet(person, at, condition, baseline)
    return {'packet': p, 'assignment': role, 'assigned_at': at, 'admission': proof(p),
            'outcomes': [receipt(p, 'won' if y else 'lost')],
            'coverage': {'from': at, 'through': later(at, days=30), 'available_at': later(at, days=31),
                         'status': 'complete', 'source_reference': {'sha256': '1'*64, 'information_fence': '2000'}}}


def document(cases):
    return {'manifest': manifest(), 'cases': cases}


def experiment(mode='gain'):
    train = [case('train:'+str(i), TRAIN, i % 2, i % 2 if mode != 'none' else 0) for i in range(48)]
    control = []
    for i in range(48):
        y = i % 2
        condition = y if mode == 'gain' else (1-y if mode == 'reversal' else 0)
        control.append(case('new:'+str(i), CONTROL, y, condition, 'control'))
    with patch.object(study, 'utc_now', return_value=FIT):
        model = study.fit(document(train))
    with patch.object(study, 'utc_now', return_value=PREDICT):
        predictions = [study.predict(model, c['packet'], c['admission'], manifest()) for c in control]
    with patch.object(study, 'utc_now', return_value=EVALUATE):
        report = study.evaluate(model, document(train+control), predictions)
    return model, train, control, predictions, report


class StudyTests(unittest.TestCase):
    def test_same_learner_gain_reversal_and_no_unsupported_improvement(self):
        gain = experiment('gain')[-1]
        self.assertLess(gain['paired_delta_conditions_minus_baseline']['brier'], -.03)
        self.assertTrue(gain['numerical_advantage_in_conditional_subset'])
        reverse = experiment('reversal')[-1]
        self.assertGreater(reverse['paired_delta_conditions_minus_baseline']['brier'], 0)
        self.assertFalse(reverse['numerical_advantage_in_conditional_subset'])
        no_gain = experiment('none')[-1]
        self.assertEqual(no_gain['paired_delta_conditions_minus_baseline']['brier'], 0)
        self.assertEqual(no_gain['paired_person_bootstrap']['brier'], {'lower': 0, 'upper': 0})
        for report in (gain, reverse, no_gain):
            self.assertNotIn('real_world_improvement_proven', report)
            self.assertFalse(report['full_buyer_quality_ready'])
            self.assertIsNone(report['purchase_probability']); self.assertFalse(report['export_allowed'])

    def test_paired_bootstrap_is_deterministic_and_actual_predictions_are_bound(self):
        model, train, control, predictions, first = experiment()
        with patch.object(study, 'utc_now', return_value=EVALUATE):
            again = study.evaluate(model, document(train+control), predictions)
            self.assertEqual(first, again)
            bad = copy.deepcopy(predictions); bad[0]['probabilities']['conditions'] += .01
            bad[0] = study.seal({k: v for k, v in bad[0].items() if k != 'sha256'})
            with self.assertRaisesRegex(study.NotReady, 'prediction_value_mismatch'):
                study.evaluate(model, document(control), bad)
            late = copy.deepcopy(predictions); late[0]['predicted_at'] = later(CONTROL, days=2)
            late[0] = study.seal({k: v for k, v in late[0].items() if k != 'sha256'})
            with self.assertRaisesRegex(study.NotReady, 'prediction_after_outcome'):
                study.evaluate(model, document(control), late)
            replaced = document(control); replaced['manifest']['study_id'] = 'different-study'
            with self.assertRaisesRegex(study.NotReady, 'evaluation_manifest_mismatch'):
                study.evaluate(model, replaced, predictions)
        with patch.object(study, 'utc_now', return_value=PREDICT):
            changed = manifest(); changed['registered_at'] = '2026-09-01T00:00:00Z'
            with self.assertRaisesRegex(study.NotReady, 'prediction_manifest_mismatch'):
                study.predict(model, control[0]['packet'], control[0]['admission'], changed)

    def test_unknown_censoring_and_corrections_never_become_negative(self):
        original = case('p', TRAIN, 0, 0)
        with patch.object(study, 'utc_now', return_value=EVALUATE):
            for change, reason in [('empty', 'no_confirmed_outcome'), ('partial', 'outcome_coverage_incomplete'),
                                   ('open', 'open_not_negative'), ('system', 'no_confirmed_outcome'),
                                   ('correction', 'outcome_correction_unresolved'),
                                   ('system_correction', 'latest_outcome_not_manager_confirmed')]:
                c = copy.deepcopy(original)
                if change == 'empty': c['outcomes'] = []
                if change == 'partial': c['coverage']['status'] = 'partial'
                if change == 'open': c['outcomes'] = [receipt(c['packet'], 'open')]
                if change == 'system': c['outcomes'] = [receipt(c['packet'], 'won', actor='system')]
                if change == 'correction': c['outcomes'] = [receipt(c['packet'], 'won'), receipt(c['packet'], 'lost', 2, later(TRAIN, days=2))]
                if change == 'system_correction': c['outcomes'] = [receipt(c['packet'], 'won'), receipt(c['packet'], 'lost', 2, later(TRAIN, days=2), actor='system')]
                out = study.assemble(document([c]))['records'][0]['label']
                self.assertIsNone(out['value']); self.assertEqual(out['reason'], reason)
            with patch.object(study, 'utc_now', return_value=later(TRAIN, days=4)):
                c = copy.deepcopy(original); c.pop('coverage')
                out = study.assemble(document([c]))['records'][0]['label']
                self.assertIsNone(out['value']); self.assertEqual(out['reason'], 'window_not_mature')

    def test_equal_time_system_manager_conflicts_ignore_receipt_id_order(self):
        c = case('p', TRAIN, 1, 1)
        with patch.object(study, 'utc_now', return_value=EVALUATE):
            for manager_id, system_id in [(1, 2), (2, 1)]:
                c['outcomes'] = [receipt(c['packet'], 'won', manager_id),
                                 receipt(c['packet'], 'lost', system_id, actor='system')]
                out = study.assemble(document([c]))['records'][0]['label']
                self.assertIsNone(out['value']); self.assertEqual(out['reason'], 'equal_time_outcome_conflict')

    def test_reader_proof_exposure_and_activation_are_required_not_just_boolean(self):
        c = case('p', TRAIN, 1, 1)
        with patch.object(study, 'utc_now', return_value=EVALUATE):
            no = copy.deepcopy(c); no['admission'] = {'verified': True}
            self.assertIsNone(study.assemble(document([no]))['records'][0]['label']['value'])
            for field, value in [('exposure', 'development'), ('prior_conversations', 1),
                                 ('earlier_profiles', 1), ('reader_sha256', '0'*64),
                                 ('person_first_seen_at', '2026-09-01T00:00:00Z')]:
                no = copy.deepcopy(c); no['admission'][field] = value
                assembled = study.assemble(document([no]))
                self.assertIsNone(assembled['records'][0]['label']['value'])
                self.assertFalse(assembled['full_buyer_quality_ready'])
            good = study.assemble(document([c]))
            self.assertEqual(good['records'][0]['label']['value'], 1)
            self.assertEqual(good['records'][0]['packet']['legacy_trip_status'], 'unknown')

    def test_assignment_is_frozen_from_person_and_protocol_not_conditions(self):
        original = case('p', TRAIN, 1, 1)['packet']
        changed = copy.deepcopy(original); changed['conditions'] = [0.]*len(study.CONDITION_NAMES)
        changed['frozen_at'] = later(TRAIN, seconds=10); changed['assigned_at'] = changed['frozen_at']
        changed = study.seal({k: v for k, v in changed.items() if k != 'sha256'})
        with patch.object(study, 'utc_now', return_value=EVALUATE):
            study.validate_packet(changed)
            self.assertEqual(original['assignment'], changed['assignment'])
            changed['assignment'] = 'control' if changed['assignment'] == 'train' else 'train'
            changed = study.seal({k: v for k, v in changed.items() if k != 'sha256'})
            with self.assertRaisesRegex(study.NotReady, 'packet_assignment_policy'): study.validate_packet(changed)

    def test_first_person_selected_before_labels_and_roles_never_overlap(self):
        first = case('p', TRAIN, 0, 0); first['outcomes'] = []
        second = case('p', later(TRAIN, seconds=10), 1, 1)
        with patch.object(study, 'utc_now', return_value=EVALUATE):
            result = study.assemble(document([second, first]))
            self.assertEqual(result['selected_people'], 1)
            self.assertIsNone(result['records'][0]['label']['value'])
            self.assertEqual(result['records'][0]['packet']['sha256'], first['packet']['sha256'])
            second['assignment'] = 'control'
            with self.assertRaisesRegex(study.NotReady, 'person_split_overlap|assignment_policy_mismatch'):
                study.assemble(document([first, second]))
            with self.assertRaisesRegex(study.NotReady, 'duplicate_packet'):
                study.assemble(document([first, first]))

    def test_entry_only_profile_does_not_prevent_first_customer_text_enrollment(self):
        text = case('p', later(TRAIN, seconds=10), 1, 1)
        entry = case('p', TRAIN, 0, 0)
        entry['packet']['baseline'][study.BASE_NAMES.index('activity')] = 0
        entry['packet'] = study.seal({k: v for k, v in entry['packet'].items() if k != 'sha256'})
        entry.pop('admission'); entry['outcomes'] = []
        with patch.object(study, 'utc_now', return_value=EVALUATE):
            result = study.assemble(document([entry, text]))
        self.assertEqual(result['selected_people'], 1)
        self.assertEqual(result['records'][0]['packet']['sha256'], text['packet']['sha256'])

    def test_future_source_receipts_aliases_and_invalid_artifacts_are_rejected(self):
        model, train, control, predictions, report = experiment()
        with patch.object(study, 'utc_now', return_value=EVALUATE):
            bad = copy.deepcopy(model); bad['baseline_weights'][0] += 1
            with self.assertRaisesRegex(study.NotReady, 'artifact_hash_mismatch'): study.validate_model(bad)
            bad = copy.deepcopy(model); bad['baseline_weights'][0] = float('nan')
            with self.assertRaises(study.NotReady): study.validate_model(bad)
            bad = copy.deepcopy(model); bad['probability_scope'] = 'payment_probability_all_leads'
            bad = study.seal({k: v for k, v in bad.items() if k != 'sha256'})
            with self.assertRaisesRegex(study.NotReady, 'model_probability_scope'): study.validate_model(bad)
            bad = copy.deepcopy(control[0]); bad['outcomes'][0]['payload']['scope']['project_key'] = 'other'
            with self.assertRaises(study.NotReady): study.assemble(document([bad]))
            future = copy.deepcopy(control[0]); future['outcomes'][0] = receipt(future['packet'], 'won', at='2028-01-01T00:00:00Z')
            with self.assertRaisesRegex(study.NotReady, 'receipt_clock'): study.assemble(document([future]))
            duplicate = copy.deepcopy(control[0]); duplicate['outcomes'] *= 2
            with self.assertRaisesRegex(study.NotReady, 'duplicate_receipt'): study.assemble(document([duplicate]))
            alias = copy.deepcopy(control[0]); copied = copy.deepcopy(alias['outcomes'][0])
            copied['receipt_id'] = 'different-envelope-same-source'
            copied['sha256'] = study.digest({k: v for k, v in copied.items() if k != 'sha256'})
            alias['outcomes'].append(copied)
            with self.assertRaisesRegex(study.NotReady, 'duplicate_receipt_source'): study.assemble(document([alias]))
        with patch.object(study, 'utc_now', return_value=EVALUATE):
            late_model = copy.deepcopy(model); late_model['fitted_at'] = later(CONTROL, days=2)
            late_model = study.seal({k: v for k, v in late_model.items() if k != 'sha256'})
            rebound = [study.seal({**{k: v for k, v in p.items() if k != 'sha256'},
                                  'model_sha256': late_model['sha256']}) for p in predictions]
            with self.assertRaisesRegex(study.NotReady, 'training_outcome_after_control'):
                study.evaluate(late_model, document(control), rebound)

    def test_insufficient_support_returns_no_fake_probability(self):
        d = document([case('single', TRAIN, 1, 1)])
        with patch.object(study, 'utc_now', return_value=EVALUATE):
            r = study.readiness(d)
            self.assertFalse(r['fit_contract_ready']); self.assertIsNone(r['purchase_probability'])
            with self.assertRaisesRegex(study.NotReady, 'training_support_insufficient'): study.fit(d)
            all_unknown = copy.deepcopy(d); all_unknown['cases'][0]['outcomes'] = []
            self.assertEqual(study.readiness(all_unknown)['counts']['train']['resolved'], 0)

    def test_actual_journal_wrapper_keeps_legacy_guards_and_new_conditions(self):
        row = journal_row(text='Нужна поездка 3взр+2реб на 6/8/11 ночей с конца ноября')
        before = copy.deepcopy(row)
        wrapped = journal.profile(journal_document(row), J_EXTRACT)['profiles'][0]
        p = wrapped['purchase_study']; study.validate_packet(p)
        self.assertEqual(row, before)
        self.assertEqual(p['conditions'][study.CONDITION_NAMES.index('adults')], .1)
        self.assertEqual(p['conditions'][study.CONDITION_NAMES.index('duration_discrete')], 1)
        self.assertEqual(p['conditions'][study.CONDITION_NAMES.index('late_month')], 1)
        self.assertEqual(p['conditions'][study.CONDITION_NAMES.index('open_end')], 1)
        self.assertEqual(p['inquiry_admission'], 'not_established')
        self.assertEqual(wrapped['profile']['trip_status'], 'unknown')
        self.assertFalse(wrapped['profile']['target_admission']['eligible'])
        self.assertIsNone(wrapped['profile']['purchase_with_us_probability'])
        self.assertFalse(wrapped['profile']['export_allowed'])
        event = journal_row('2'); event['source_kind'] = event['snapshot']['source_kind'] = 'event'
        event['snapshot']['payload'] = {'event_type': 'waiting_manager', 'actor_type': 'system', 'payload_json': '{}'}
        with_event = journal.profile(journal_document(row, event), J_EXTRACT)['profiles'][0]['purchase_study']
        self.assertEqual(with_event['baseline'][study.BASE_NAMES.index('manager_request')], 1)
        self.assertEqual(with_event['baseline'][study.BASE_NAMES.index('manager_request_known')], 1)

    def test_late_extraction_cannot_be_an_earlier_packet(self):
        wrapped = journal.profile(journal_document(journal_row()), J_EXTRACT)['profiles'][0]
        bad = copy.deepcopy(wrapped); bad['profile']['prediction_at'] = '2026-10-26T09:10:00Z'
        with self.assertRaisesRegex(study.NotReady, 'late_profile_prediction'): study.freeze_profile(bad)

    def test_unregistered_extractor_keeps_descriptive_packet_without_parsing_new_conditions(self):
        wrapped = journal.profile(journal_document(journal_row()), J_EXTRACT)['profiles'][0]
        wrapped['profile']['extractor_sha256'] = '9'*64
        wrapped['profile']['fields'] = 'unregistered-future-condition-shape'
        descriptor = study.freeze_profile(wrapped)
        study.validate_packet(descriptor)
        self.assertEqual(descriptor['inquiry_admission'], 'extractor_source_not_preregistered')
        self.assertEqual(descriptor['conditions'], [0]*len(study.CONDITION_NAMES))
        self.assertEqual(study.admission({'packet': descriptor}, manifest()), ['extractor_source_not_preregistered'])

    def test_json_integral_float_roundtrip_preserves_packet_hash(self):
        p = journal.profile(journal_document(journal_row()), J_EXTRACT)['profiles'][0]['purchase_study']
        encoded = json.dumps(p).replace('0.0,', '0,').replace('1.0,', '1,').replace('0.0]', '0]').replace('1.0]', '1]')
        roundtrip = json.loads(encoded)
        self.assertEqual(study.validate_packet(roundtrip)['sha256'], p['sha256'])
        self.assertEqual(study.digest({'numeric': 1.0, 'string': '1'}), study.digest({'numeric': 1, 'string': '1'}))
        self.assertNotEqual(study.digest({'numeric': '1'}), study.digest({'numeric': 1}))

    @unittest.skipUnless(shutil.which('php'), 'PHP available in required CI; local Python-only runtime')
    def test_actual_php_profile_json_roundtrip_preserves_packet_hash(self):
        wrapper = journal.profile(journal_document(journal_row()), J_EXTRACT)['profiles'][0]
        run = subprocess.run(['php', '-r', 'echo json_encode(json_decode(stream_get_contents(STDIN),true),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'],
                             input=json.dumps(wrapper).encode(), capture_output=True)
        self.assertEqual(run.returncode, 0, run.stderr)
        saved = json.loads(run.stdout)['purchase_study']
        self.assertEqual(study.validate_packet(saved)['sha256'], wrapper['purchase_study']['sha256'])

    def test_actual_signal_baseline_includes_membership_reply_offer_booking_and_revocation(self):
        customer = journal_row('1', text='Нужна поездка')
        manager = journal_row('2', text='Могу предложить варианты')
        manager['snapshot']['source_created_at_unix'] += 1
        manager['snapshot']['payload'].update(direction='outbound', sender_type='manager', metadata_json='{}')
        reply = journal_row('3', text='Готовы бронировать')
        reply['snapshot']['source_created_at_unix'] += 2
        request = journal_row('4'); request['source_kind'] = request['snapshot']['source_kind'] = 'event'
        request['snapshot']['payload'] = {'event_type': 'waiting_manager', 'actor_type': 'system', 'payload_json': '{}'}
        offer = journal_row('7'); offer['source_kind'] = offer['snapshot']['source_kind'] = 'event'
        offer['snapshot']['payload'] = {'event_type': 'tour_offer_delivered', 'actor_type': 'system', 'payload_json': '{}'}
        sub = membership_journal_receipt('5', 'channel_subscription_observed', 1793005200, 1793005260, 1793005320)
        ret = membership_journal_receipt('6', 'channel_retention_30m_observed', 1793007000, 1793007060, 1793007120,
                                         subscription_event_id='5', minimum_age_seconds=1800)
        d = journal_document(customer, manager, reply, request, sub, ret, offer)
        d['requested_at'] = '2026-10-26T10:00:00Z'
        result = journal.profile(d, '2026-10-26T10:00:01Z')['profiles'][0]['purchase_study']
        for key in ('activity', 'reply_after_manager', 'manager_request', 'offer', 'booking',
                    'subscription', 'subscription_known', 'retention', 'retention_known'):
            self.assertEqual(result['baseline'][study.BASE_NAMES.index(key)], 1, key)
        d['cases'][0]['access_denied'] = True
        denied = journal.profile(d, '2026-10-26T10:00:01Z')['profiles'][0]['purchase_study']
        self.assertEqual(denied['source_access'], 'denied')
        self.assertEqual(denied['baseline'][study.BASE_NAMES.index('manager_request')], 0)
        self.assertEqual(study.admission({'packet': denied}, manifest()), ['source_access_not_allowed'])

    def test_cli_input_redaction_and_readiness_contract(self):
        for raw in [b'{"operation":"readiness","document":"private-example"}', b'{"operation":"fit","operation":"assemble"}', b'\xff']:
            run = subprocess.run([sys.executable, str(ROOT/'tools/purchase_conversion_study.py')], input=raw, capture_output=True)
            self.assertEqual(run.returncode, 2); self.assertFalse(run.stdout)
            self.assertNotIn(b'private-example', run.stderr)
        run = subprocess.run([sys.executable, str(ROOT/'tools/purchase_conversion_study.py')],
                             input=json.dumps({'operation': 'readiness', 'document': document([])}).encode(), capture_output=True)
        self.assertEqual(run.returncode, 0, run.stderr)
        out = json.loads(run.stdout); self.assertFalse(out['fit_contract_ready']); self.assertFalse(out['export_allowed'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
