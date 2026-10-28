#!/usr/bin/env python3
"""Bounded offline inquiry-outcome research. No source reads, payment claims or exports."""
import hashlib
import json
import math
import random
import re
import sys
import time
from datetime import datetime, timedelta, timezone

VERSION = 'inquiry_outcome_study_v1'
TARGET = 'manager_recorded_inquiry_outcome_30d_v1'
PROBABILITY_SCOPE = 'research_estimate_for_resolved_manager_outcome_subset_not_all_leads'
MAX_BYTES = 2097152
MAX_CASES = 256
PROTOCOL = {'version': VERSION, 'target': TARGET, 'horizon_days': 30,
            'extractor_sha256': 'd8f4162dbcd94253af0fa34e9e7ede27edef3cef993be86849da3c1d95fc9bb9',
            'unit': 'earliest_customer_text_person_observation_before_labels',
            'assignment_policy': 'sha256_project_person_half_v1',
            'learner': 'same_l2_logistic_fixed_transforms', 'l2': .05,
            'iterations': 200, 'learning_rate': .25, 'bootstrap_seed': 314159,
            'bootstrap_replicates': 200, 'confidence': .95, 'calibration_bins': 5,
            'top_fraction': .25, 'minimum_train': 40, 'minimum_train_class': 8,
            'minimum_control': 40, 'minimum_control_class': 8}
BASE_NAMES = ('log_customer', 'log_manager', 'log_offers', 'recency', 'recency_known',
              'activity', 'reply_after_manager', 'manager_request', 'manager_request_known',
              'offer', 'offer_known', 'booking', 'subscription', 'subscription_known',
              'retention', 'retention_known')
CONDITION_NAMES = ('budget_known', 'budget_rub', 'budget_per_person', 'budget_max',
                   'adults_known', 'adults', 'children_known', 'children', 'ages_known',
                   'duration_known', 'duration_min', 'duration_max', 'duration_nights',
                   'duration_discrete', 'dates_known', 'month_known', 'month_sin', 'month_cos',
                   'late_month', 'open_end', 'day_known', 'year_known',
                   'hotels_known', 'hotel_options', 'room_not_old', 'room_avoid_animation_pool',
                   'room_main_building', 'room_main_excluded', 'room_bungalow_excluded',
                   'services_known', 'package_inquiry', 'hotel_only_inquiry', 'flight_search')
SCOPE_KEYS = ('project_key', 'platform', 'customer_key', 'conversation_id')


class NotReady(ValueError):
    pass


def require(ok, reason):
    if not ok:
        raise NotReady(reason)


def utc_now():
    return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def moment(value):
    require(isinstance(value, str) and bool(re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ', value)), 'invalid_clock')
    try:
        return datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)
    except ValueError:
        raise NotReady('invalid_clock') from None


def digest(value):
    def canonical_numbers(item):
        # The existing PHP profile writer round-trips JSON without
        # JSON_PRESERVE_ZERO_FRACTION. Integral floats and integers therefore
        # have one canonical numeric representation across both runtimes.
        if type(item) is float:
            require(math.isfinite(item), 'invalid_json_value')
            return int(item) if item.is_integer() else item
        if isinstance(item, dict):
            return {k: canonical_numbers(v) for k, v in item.items()}
        if isinstance(item, (list, tuple)):
            return [canonical_numbers(v) for v in item]
        return item
    try:
        return hashlib.sha256(json.dumps(canonical_numbers(value), ensure_ascii=False, sort_keys=True,
                                         separators=(',', ':'), allow_nan=False).encode()).hexdigest()
    except (ValueError, TypeError):
        raise NotReady('invalid_json_value') from None


def hash_value(value):
    return isinstance(value, str) and bool(re.fullmatch('[a-f0-9]{64}', value))


PROTOCOL_SHA = digest(PROTOCOL)


def seal(value):
    return {**value, 'sha256': digest(value)}


def unseal(value, kind):
    require(isinstance(value, dict) and value.get('kind') == kind and hash_value(value.get('sha256')), 'invalid_artifact')
    payload = {k: v for k, v in value.items() if k != 'sha256'}
    require(digest(payload) == value['sha256'], 'artifact_hash_mismatch')
    require(value.get('version') == VERSION and value.get('protocol_sha256') == PROTOCOL_SHA, 'protocol_mismatch')
    return value


def scope(value):
    require(isinstance(value, dict) and set(value) == set(SCOPE_KEYS), 'invalid_scope')
    require(value['project_key'] == 'anytour' and value['platform'] in ('max', 'telegram'), 'foreign_scope')
    require(all(isinstance(value[k], str) and bool(re.fullmatch(r'[A-Za-z0-9._:-]{1,128}', value[k]))
                for k in SCOPE_KEYS), 'invalid_scope')
    return value


def group_key(subject):
    return digest({'project_key': subject['project_key'], 'customer_key': subject['customer_key']})


def assignment_for_scope(subject):
    value = digest({'protocol_sha256': PROTOCOL_SHA, 'project_key': subject['project_key'],
                    'customer_key': subject['customer_key']})
    return 'train' if int(value[0], 16) < 8 else 'control'


def number(value, lo=0, hi=1):
    require(type(value) in (int, float) and math.isfinite(value) and lo <= value <= hi, 'invalid_number')
    return float(value)


def known(profile, field):
    row = profile.get('fields', {}).get(field, {})
    return row.get('value') if row.get('status') == 'known' and row.get('usable_at_prediction') is True else None


def freeze_profile(wrapper, journal_rows=()):
    """Descriptive packet for each future profile; inquiry admission is separate."""
    profile = wrapper['profile']; subject = scope({k: wrapper['subject'][k] for k in SCOPE_KEYS})
    at = profile['extracted_at']; moment(at)
    require(profile['prediction_at'] == at, 'late_profile_prediction')
    if profile['extractor_sha256'] != PROTOCOL['extractor_sha256']:
        # A later extractor may change condition semantics. Preserve a clearly
        # descriptive receipt without parsing its unregistered condition schema.
        return seal({'kind': 'frozen_profile', 'version': VERSION, 'protocol_sha256': PROTOCOL_SHA,
                     'target': TARGET, 'scope': subject, 'person_group': group_key(subject),
                     'frozen_at': at, 'source_fence_at': profile.get('prediction_features', {}).get('source_fence_at'),
                     'profile_fingerprint': profile['prediction_features']['feature_fingerprint'],
                     'extractor_sha256': profile['extractor_sha256'], 'references': wrapper['source_references'],
                     'baseline_names': list(BASE_NAMES), 'condition_names': list(CONDITION_NAMES),
                     'baseline': [0]*len(BASE_NAMES), 'conditions': [0]*len(CONDITION_NAMES),
                     'inquiry_admission': 'extractor_source_not_preregistered', 'source_access': wrapper.get('access_status', 'unknown'),
                     'assignment': assignment_for_scope(subject), 'assigned_at': at,
                     'legacy_trip_status': profile.get('trip_status', 'unknown'), 'purchase_probability': None, 'export_allowed': False})
    counts = profile['observation']['features']['counts']
    customer = number(counts.get('customer_message', 0), 0, 500)
    manager = number(counts.get('manager_message', 0), 0, 500)
    offers = number(counts.get('offer_sent', 0), 0, 500)
    events = profile.get('observation_input', {}).get('evidence', [])
    before = [e for e in events if e.get('at', '') <= at and e.get('available_at', '') <= at]
    ctimes = [e['at'] for e in before if e.get('kind') == 'customer_message']
    mtimes = [e['at'] for e in before if e.get('kind') == 'manager_message']
    recency = profile['observation']['features'].get('seconds_since_customer_message')
    flags = set()
    for row in journal_rows if wrapper.get('access_status') == 'allowed' else ():
        snap = row.get('snapshot', {}); payload = snap.get('payload', {})
        if (snap.get('source_status') != 'present' or row.get('source_kind') != 'event'
                or snap.get('payload_status') != 'copied' or row.get('source_version_no') != 1
                or any(str(snap.get(k, '')) != subject[k] for k in SCOPE_KEYS)):
            continue
        recorded = snap.get('source_created_at_unix'); received = row.get('observed_at_unix')
        if (type(recorded) is not int or type(received) is not int or not 946684800 <= recorded <= received
                or datetime.fromtimestamp(received, timezone.utc) > moment(at)
                or snap.get('source_timestamp_basis') not in ('database_session_timezone_to_unix', 'canonical_recording_unix_v1')):
            continue
        event_type = payload.get('event_type')
        if event_type in ('waiting_manager', 'tour_offer_delivered'):
            flags.add(event_type)
    early = profile.get('early_signals', {})
    sub = early.get('subscription', {}); retention = early.get('retention_30m', {})
    sub_known = sub.get('status') == 'known' and sub.get('state') in ('subscribed', 'not_subscribed')
    ret_known = retention.get('status') == 'known' and retention.get('state') in ('retained', 'not_retained')
    booking = any(e.get('kind') in ('booking_intent', 'booking_confirmed') for e in before)
    b = [math.log1p(customer)/math.log(501), math.log1p(manager)/math.log(501), math.log1p(offers)/math.log(501),
         min(number(recency, 0, 4102444800), 2592000)/2592000 if recency is not None else 0,
         float(recency is not None), float(customer > 0),
         float(bool(ctimes and mtimes and max(ctimes) > min(mtimes))),
         float('waiting_manager' in flags), float('waiting_manager' in flags),
         float(offers > 0 or 'tour_offer_delivered' in flags), float(offers > 0 or 'tour_offer_delivered' in flags),
         float(booking), float(sub_known and sub.get('state') == 'subscribed'), float(sub_known),
         float(ret_known and retention.get('state') == 'retained'), float(ret_known)]
    budget = known(profile, 'budget'); duration = known(profile, 'duration'); dates = known(profile, 'dates')
    hotels = known(profile, 'hotel_candidates'); services = known(profile, 'service_alternatives')
    party = profile.get('party', {})
    def person(name):
        row = party.get(name, {})
        return row.get('value') if row.get('status') == 'known' and row.get('usable_at_prediction') is True else None
    adults, children, ages = person('adults'), person('children'), person('child_ages')
    month = dates.get('start_month') if isinstance(dates, dict) else None
    month = month if type(month) is int and 1 <= month <= 12 else None
    options = hotels.get('options', []) if isinstance(hotels, dict) else []
    constraints = {c.get('value') for h in options for c in h.get('room_constraints', [])}
    requests = {r.get('category') for r in services.get('requests', [])} if isinstance(services, dict) else set()
    rub = isinstance(budget, dict) and budget.get('currency') == 'RUB'
    amount = budget.get('maximum') if rub else None
    c = [float(budget is not None), float(rub), float(isinstance(budget, dict) and budget.get('unit') == 'per_person'),
         math.log1p(min(number(amount, 0, 10000000000), 100000000))/math.log1p(100000000) if amount is not None else 0,
         float(adults is not None), min(number(adults, 0, 30), 30)/30 if adults is not None else 0,
         float(children is not None), min(number(children, 0, 30), 30)/30 if children is not None else 0,
         float(ages is not None), float(duration is not None),
         number(duration['minimum'], 1, 90)/90 if duration else 0,
         number(duration['maximum'], 1, 90)/90 if duration else 0,
         float(bool(duration and duration.get('unit') == 'nights')), float(bool(duration and duration.get('options'))),
         float(dates is not None), float(month is not None),
         math.sin(2*math.pi*month/12) if month else 0, math.cos(2*math.pi*month/12) if month else 0,
         float(bool(dates and dates.get('start_month_part') == 'late')), float(bool(dates and dates.get('open_end'))),
         float(bool(dates and dates.get('start_day') is not None)), float(bool(dates and dates.get('year') is not None)),
         float(hotels is not None), min(len(options), 20)/20,
         *[float(v in constraints) for v in ('not_old', 'avoid_animation_pool', 'main_building_requested', 'main_building_excluded', 'bungalow_excluded')],
         float(services is not None), float('package_price_inquiry' in requests),
         float(bool(requests & {'hotel_only_price_inquiry', 'hotel_only_availability_inquiry'})), float('flight_date_search_inquiry' in requests)]
    refs = wrapper['source_references']
    require(isinstance(refs, list) and len(refs) <= 50, 'reference_limit')
    payload = {'kind': 'frozen_profile', 'version': VERSION, 'protocol_sha256': PROTOCOL_SHA,
               'target': TARGET, 'scope': subject, 'person_group': group_key(subject),
               'frozen_at': at, 'source_fence_at': profile.get('source_fence_at', profile.get('prediction_features', {}).get('source_fence_at')),
               'profile_fingerprint': profile['prediction_features']['feature_fingerprint'],
               'extractor_sha256': profile['extractor_sha256'], 'references': refs,
               'baseline_names': list(BASE_NAMES), 'condition_names': list(CONDITION_NAMES),
               'baseline': b, 'conditions': c, 'inquiry_admission': 'not_established',
               'source_access': wrapper.get('access_status', 'unknown'),
               'assignment': assignment_for_scope(subject), 'assigned_at': at,
               'legacy_trip_status': profile['trip_status'], 'purchase_probability': None, 'export_allowed': False}
    return seal(payload)


def validate_packet(packet):
    unseal(packet, 'frozen_profile'); subject = scope(packet.get('scope'))
    require(packet.get('person_group') == group_key(subject), 'person_scope_mismatch')
    require(packet.get('target') == TARGET and packet.get('export_allowed') is False and packet.get('purchase_probability') is None, 'invalid_target')
    require(packet.get('source_access') in ('allowed', 'denied', 'unknown'), 'invalid_source_access')
    require(packet.get('assignment') == assignment_for_scope(subject)
            and packet.get('assigned_at') == packet.get('frozen_at'), 'packet_assignment_policy')
    require(packet.get('baseline_names') == list(BASE_NAMES) and packet.get('condition_names') == list(CONDITION_NAMES), 'feature_schema_mismatch')
    for key, names in [('baseline', BASE_NAMES), ('conditions', CONDITION_NAMES)]:
        require(isinstance(packet.get(key), list) and len(packet[key]) == len(names), 'feature_shape')
        for value in packet[key]:
            number(value, -1, 1)
    require(hash_value(packet.get('profile_fingerprint')) and hash_value(packet.get('extractor_sha256')), 'missing_profile_provenance')
    require(moment(packet['frozen_at']) <= moment(utc_now()), 'future_profile')
    if packet.get('source_fence_at') is not None:
        require(moment(packet['source_fence_at']) <= moment(packet['frozen_at']), 'future_source_fence')
    return packet


def admission(case, manifest):
    """Validate reader evidence structure; offline JSON cannot authenticate a reader."""
    packet = case['packet']; proof = case.get('admission')
    if packet.get('source_access') != 'allowed':
        return ['source_access_not_allowed']
    if packet.get('extractor_sha256') != PROTOCOL['extractor_sha256']:
        return ['extractor_source_not_preregistered']
    if not isinstance(proof, dict):
        return ['inquiry_admission_missing']
    required = {'scope', 'packet_sha256', 'profile_id', 'reader_sha256', 'run_id', 'observed_at',
                'conversation_created_at', 'person_first_seen_at', 'prior_conversations', 'earlier_profiles',
                'exposure', 'exposure_ledger_sha256', 'source_reference'}
    if set(proof) != required:
        return ['inquiry_admission_shape']
    ref = proof['source_reference']
    if (proof['scope'] != packet['scope'] or proof['packet_sha256'] != packet['sha256']
            or not isinstance(proof['profile_id'], str) or not proof['profile_id']
            or proof['reader_sha256'] != manifest['reader_sha256'] or not hash_value(proof['exposure_ledger_sha256'])
            or not isinstance(ref, dict) or set(ref) != {'kind', 'id', 'sha256', 'information_fence'}
            or ref['kind'] != 'native_inquiry_metadata' or ref['id'] != packet['scope']['conversation_id']
            or not hash_value(ref['sha256']) or not isinstance(ref['information_fence'], str)
            or not isinstance(proof['run_id'], str) or not proof['run_id']):
        return ['inquiry_provenance_unbound']
    if (type(proof['prior_conversations']) is not int or proof['prior_conversations'] != 0
            or type(proof['earlier_profiles']) is not int or proof['earlier_profiles'] != 0
            or proof['exposure'] != 'unseen'):
        return ['prior_person_or_development_exposure']
    registered, frozen = moment(manifest['registered_at']), moment(packet['frozen_at'])
    created, first, seen = [moment(proof[k]) for k in ('conversation_created_at', 'person_first_seen_at', 'observed_at')]
    if not registered < first <= created <= frozen <= seen <= moment(utc_now()):
        return ['inquiry_clock_or_activation']
    if packet['baseline'][BASE_NAMES.index('activity')] != 1:
        return ['no_customer_text_profile']
    return []


def receipts(case):
    result = []; seen = set(); source_seen = set(); packet = case['packet']
    require(isinstance(case.get('outcomes', []), list) and len(case.get('outcomes', [])) <= 16, 'receipt_limit')
    for r in case.get('outcomes', []):
        require(isinstance(r, dict) and set(r) == {'receipt_id', 'scope', 'event_type', 'payload', 'recorded_at', 'available_at', 'source_reference', 'sha256'}, 'receipt_shape')
        require(digest({k: v for k, v in r.items() if k != 'sha256'}) == r['sha256'], 'receipt_hash_mismatch')
        require(r['receipt_id'] not in seen and isinstance(r['receipt_id'], str), 'duplicate_receipt')
        seen.add(r['receipt_id'])
        require(r['scope'] == packet['scope'] and r['event_type'] == 'sales_pipeline_outcome_observed', 'receipt_scope_or_type')
        payload = r['payload']; require(isinstance(payload, dict), 'receipt_payload')
        require(payload.get('version') == 1 and type(payload.get('version')) is int
                and payload.get('producer') == 'sales_pipeline_manager_assertion'
                and payload.get('evidence_basis') == 'manager_operational_assertion'
                and payload.get('origin') == 'existing_sales_pipeline'
                and payload.get('scope') == packet['scope'] and payload.get('outcome') in ('won', 'lost', 'open')
                and payload.get('actor_role') in ('manager', 'system'), 'receipt_producer')
        recorded, available = moment(r['recorded_at']), moment(r['available_at'])
        require(recorded <= available <= moment(utc_now()), 'receipt_clock')
        clock = payload.get('_recorded_clock', {})
        require(isinstance(clock, dict) and set(clock) == {'version', 'basis', 'unix'}
                and clock['version'] == 1 and type(clock['version']) is int
                and clock['basis'] == 'server_recording_unix' and type(clock['unix']) is int
                and clock['unix'] == int(recorded.timestamp()), 'receipt_recording_clock')
        ref = r['source_reference']
        require(isinstance(ref, dict) and set(ref) == {'kind', 'id', 'sha256', 'journal_id'}
                and ref['kind'] == 'conversation_event' and hash_value(ref['sha256'])
                and isinstance(ref['id'], str) and isinstance(ref['journal_id'], str), 'receipt_source_reference')
        source_key = (ref['kind'], ref['id'])
        require(source_key not in source_seen, 'duplicate_receipt_source'); source_seen.add(source_key)
        result.append(r)
    return sorted(result, key=lambda r: (r['recorded_at'], r['available_at'], r['receipt_id']))


def label(case, issues):
    packet = case['packet']; start = moment(packet['frozen_at']); end = start+timedelta(days=30)
    all_receipts = receipts(case)
    descriptive = {v: sum(r['payload']['outcome'] == v for r in all_receipts) for v in ('won', 'lost', 'open')}
    out = {'value': None, 'reason': 'inquiry_admission_missing', 'available_at': None,
           'window_ends_at': end.strftime('%Y-%m-%dT%H:%M:%SZ'), 'receipt_ids': [], 'conditional_outcomes': descriptive}
    if issues:
        out['reason'] = issues[0]; return out
    if any(moment(r['recorded_at']) <= start and r['payload']['outcome'] == 'won' for r in all_receipts):
        out['reason'] = 'outcome_already_recorded'; return out
    window = [r for r in all_receipts if start < moment(r['recorded_at']) <= end
              and r['payload'].get('actor_role') == 'manager']
    if not window:
        out['reason'] = 'no_confirmed_outcome'; return out
    all_window = [r for r in all_receipts if start < moment(r['recorded_at']) <= end]
    latest_time = all_window[-1]['recorded_at']
    tied = [r for r in all_window if r['recorded_at'] == latest_time]
    if len({r['payload']['outcome'] for r in tied}) > 1:
        out['reason'] = 'equal_time_outcome_conflict'; return out
    if any(r['payload']['actor_role'] != 'manager' for r in tied):
        out['reason'] = 'latest_outcome_not_manager_confirmed'; return out
    # The reviewed NEW-lead contract binds all outcome receipts for this exact
    # canonical conversation/card. A nullable trip is expected and stays unknown.
    states = {r['payload']['outcome'] for r in window}
    latest = window[-1]
    if 'won' in states and latest['payload']['outcome'] != 'won':
        out['reason'] = 'outcome_correction_unresolved'; return out
    if len({r['payload']['outcome'] for r in window if r['recorded_at'] == latest['recorded_at']}) > 1:
        out['reason'] = 'equal_time_outcome_conflict'; return out
    coverage = case.get('coverage', {})
    full = False
    if isinstance(coverage, dict) and set(coverage) == {'from', 'through', 'available_at', 'status', 'source_reference'}:
        ref = coverage['source_reference']
        require(isinstance(ref, dict) and set(ref) == {'sha256', 'information_fence'} and hash_value(ref['sha256']), 'coverage_reference')
        require(moment(coverage['from']) <= moment(coverage['through']) <= moment(coverage['available_at']) <= moment(utc_now()), 'coverage_clock')
        full = coverage['status'] == 'complete' and moment(coverage['from']) <= start and moment(coverage['through']) >= end
    if moment(utc_now()) < end:
        out['reason'] = 'window_not_mature'; return out
    if not full:
        out['reason'] = 'outcome_coverage_incomplete'; return out
    state = latest['payload']['outcome']
    if state == 'open':
        out['reason'] = 'open_not_negative'; return out
    out.update(value=int(state == 'won'), reason='resolved_manager_outcome_conditional_subset',
               available_at=max(latest['available_at'], coverage['available_at']),
               receipt_ids=[r['receipt_id'] for r in window])
    return out


def validate_manifest(m):
    require(isinstance(m, dict) and set(m) == {'study_id', 'registered_at', 'protocol_sha256', 'reader_kind', 'reader_sha256'}, 'manifest_shape')
    require(m['protocol_sha256'] == PROTOCOL_SHA and m['reader_kind'] in ('synthetic_contract', 'reviewed_runtime_reader')
            and hash_value(m['reader_sha256']) and isinstance(m['study_id'], str) and bool(m['study_id']), 'manifest_provenance')
    require(moment(m['registered_at']) <= moment(utc_now()), 'future_registration')
    return m


def assemble(document):
    require(isinstance(document, dict) and set(document) == {'manifest', 'cases'}, 'study_shape')
    m = validate_manifest(document['manifest'])
    require(isinstance(document['cases'], list) and len(document['cases']) <= MAX_CASES, 'case_limit')
    chosen = {}; packets = set(); assignments = {}
    # Select before label lookup, never a later resolved case of the same person.
    for case in document['cases']:
        require(isinstance(case, dict) and set(case) <= {'packet', 'assignment', 'assigned_at', 'admission', 'outcomes', 'coverage'}
                and {'packet', 'assignment', 'assigned_at'} <= set(case), 'case_shape')
        p = validate_packet(case['packet']); require(p['sha256'] not in packets, 'duplicate_packet'); packets.add(p['sha256'])
        require(case['assignment'] in ('train', 'control', 'excluded'), 'invalid_assignment')
        require((case['assignment'] == 'excluded' or case['assignment'] == p['assignment'])
                and case['assigned_at'] == p['assigned_at'], 'assignment_policy_mismatch')
        require(moment(m['registered_at']) <= moment(case['assigned_at']), 'assignment_before_protocol')
        g = p['person_group']
        require(g not in assignments or assignments[g] == case['assignment'], 'person_split_overlap'); assignments[g] = case['assignment']
        def enrollment_order(candidate):
            return (candidate['baseline'][BASE_NAMES.index('activity')] != 1,
                    candidate['frozen_at'], candidate['sha256'])
        if g not in chosen or enrollment_order(p) < enrollment_order(chosen[g]['packet']):
            chosen[g] = case
    records = []; receipt_owners = {}
    for g, case in sorted(chosen.items()):
        for r in receipts(case):
            source_key = (r['source_reference']['kind'], r['source_reference']['id'])
            require(source_key not in receipt_owners or receipt_owners[source_key] == g, 'cross_person_receipt_source')
            receipt_owners[source_key] = g
        issues = admission(case, m)
        if case['assignment'] == 'excluded':
            issues.append('excluded_by_reader')
        records.append({'packet': case['packet'], 'person_group': g, 'assignment': case['assignment'],
                        'admission_issues': issues, 'label': label(case, issues)})
    return seal({'kind': 'assembled_study', 'version': VERSION, 'target': TARGET, 'protocol_sha256': PROTOCOL_SHA,
                 'assembled_at': utc_now(), 'document_sha256': digest(document), 'manifest': m,
                 'records': records, 'raw_cases': len(document['cases']), 'selected_people': len(records),
                 'unknown_labels': sum(r['label']['value'] is None for r in records),
                 'reader_assurance': 'structured_producer_evidence_not_authenticated_by_offline_tool',
                 'full_buyer_quality_ready': False, 'purchase_probability': None, 'export_allowed': False})


def sigmoid(x):
    return 1/(1+math.exp(-max(-35, min(35, x))))


def vector(packet, arm):
    return [1.] + packet['baseline'] + (packet['conditions'] if arm == 'conditions' else [])


def learn(records, arm):
    x = [vector(r['packet'], arm) for r in records]; y = [r['label']['value'] for r in records]
    weights = [0.]*len(x[0]); weights[0] = math.log(sum(y)/(len(y)-sum(y)))
    for _ in range(PROTOCOL['iterations']):
        grad = [0.]*len(weights)
        for row, target in zip(x, y):
            error = sigmoid(sum(a*b for a, b in zip(row, weights)))-target
            for i, v in enumerate(row):
                grad[i] += error*v/len(y)
        weights = [w-PROTOCOL['learning_rate']*(g+(PROTOCOL['l2']*w if i else 0))
                   for i, (w, g) in enumerate(zip(weights, grad))]
    return weights


def fit(document):
    study = assemble(document)
    train = [r for r in study['records'] if r['assignment'] == 'train' and r['label']['value'] is not None]
    y = [r['label']['value'] for r in train]
    require(len(y) >= PROTOCOL['minimum_train'] and min(sum(y), len(y)-sum(y)) >= PROTOCOL['minimum_train_class'], 'training_support_insufficient')
    baseline_weights = learn(train, 'baseline'); condition_weights = learn(train, 'conditions')
    return seal({'kind': 'paired_model', 'version': VERSION, 'target': TARGET, 'protocol_sha256': PROTOCOL_SHA,
                 'fitted_at': utc_now(), 'training_through': max(r['label']['available_at'] for r in train),
                 'training_groups': sorted(r['person_group'] for r in train),
                 'excluded_training_groups': sorted(r['person_group'] for r in study['records'] if r not in train),
                 'training_document_sha256': study['document_sha256'], 'reader_kind': study['manifest']['reader_kind'],
                 'study_manifest': study['manifest'], 'manifest_sha256': digest(study['manifest']),
                 'train_n': len(y), 'train_positives': sum(y),
                 'baseline_weights': baseline_weights, 'condition_weights': condition_weights,
                 'probability_scope': PROBABILITY_SCOPE,
                 'purchase_probability': None, 'export_allowed': False})


def validate_model(model):
    unseal(model, 'paired_model')
    require(set(model) == {'kind', 'version', 'target', 'protocol_sha256', 'fitted_at', 'training_through',
                          'training_groups', 'excluded_training_groups', 'training_document_sha256', 'reader_kind',
                          'study_manifest', 'manifest_sha256',
                          'train_n', 'train_positives', 'baseline_weights', 'condition_weights', 'probability_scope',
                          'purchase_probability', 'export_allowed', 'sha256'}, 'model_shape')
    require(model.get('target') == TARGET and model.get('export_allowed') is False and model.get('purchase_probability') is None, 'model_target')
    require(model.get('probability_scope') == PROBABILITY_SCOPE, 'model_probability_scope')
    m = validate_manifest(model.get('study_manifest'))
    require(digest(m) == model.get('manifest_sha256') and model.get('reader_kind') == m['reader_kind']
            and moment(m['registered_at']) <= moment(model['fitted_at']), 'model_manifest')
    require(moment(model['training_through']) <= moment(model['fitted_at']) <= moment(utc_now()), 'model_clock')
    groups = model.get('training_groups')
    require(isinstance(groups, list) and len(groups) == len(set(groups)) and all(hash_value(g) for g in groups)
            and len(groups) == model.get('train_n') and len(groups) >= PROTOCOL['minimum_train'], 'model_support')
    excluded = model.get('excluded_training_groups')
    require(isinstance(excluded, list) and len(excluded) == len(set(excluded))
            and all(hash_value(g) for g in excluded) and not set(groups) & set(excluded)
            and hash_value(model.get('training_document_sha256'))
            and model.get('reader_kind') in ('synthetic_contract', 'reviewed_runtime_reader'), 'model_training_provenance')
    positives = model.get('train_positives'); require(type(positives) is int and min(positives, len(groups)-positives) >= PROTOCOL['minimum_train_class'], 'model_class_support')
    for key, length in [('baseline_weights', 1+len(BASE_NAMES)), ('condition_weights', 1+len(BASE_NAMES)+len(CONDITION_NAMES))]:
        require(isinstance(model.get(key), list) and len(model[key]) == length, 'model_feature_shape')
        for w in model[key]: number(w, -100, 100)
    return model


def predict(model, packet, admission_proof, manifest):
    validate_model(model); validate_packet(packet)
    require(manifest == model['study_manifest'], 'prediction_manifest_mismatch')
    require(packet['assignment'] == 'control', 'prediction_not_control_assignment')
    case = {'packet': packet, 'admission': admission_proof}
    require(not admission(case, manifest), 'prediction_inquiry_not_admitted')
    excluded = set(model['training_groups']) | set(model['excluded_training_groups'])
    require(packet['person_group'] not in excluded, 'prediction_person_seen')
    require(moment(model['fitted_at']) <= moment(packet['frozen_at']) <= moment(utc_now()), 'model_after_prediction_cutoff')
    probabilities = {arm: sigmoid(sum(a*b for a, b in zip(vector(packet, arm), model[key])))
                     for arm, key in [('baseline', 'baseline_weights'), ('conditions', 'condition_weights')]}
    return seal({'kind': 'paired_prediction', 'version': VERSION, 'target': TARGET, 'protocol_sha256': PROTOCOL_SHA,
                 'packet_sha256': packet['sha256'], 'model_sha256': model['sha256'], 'person_group': packet['person_group'],
                 'prediction_cutoff': packet['frozen_at'], 'predicted_at': utc_now(), 'probabilities': probabilities,
                 'probability_scope': model['probability_scope'], 'purchase_probability': None, 'export_allowed': False})


def metrics(y, p):
    n = len(y); require(n > 0, 'empty_metric')
    clipped = [max(1e-12, min(1-1e-12, v)) for v in p]
    top = max(1, math.ceil(n*PROTOCOL['top_fraction']))
    ranked = sorted(range(n), key=lambda i: (-p[i], i))[:top]
    bins = []
    for b in range(PROTOCOL['calibration_bins']):
        ids = [i for i, v in enumerate(p) if min(4, int(v*5)) == b]
        bins.append({'bin': b, 'n': len(ids), 'predicted_mean': sum(p[i] for i in ids)/len(ids) if ids else None,
                     'observed_rate': sum(y[i] for i in ids)/len(ids) if ids else None})
    return {'n': n, 'positives': sum(y), 'brier': sum((a-b)**2 for a, b in zip(y, p))/n,
            'log_loss': -sum(a*math.log(b)+(1-a)*math.log1p(-b) for a, b in zip(y, clipped))/n,
            'top_k': top, 'precision_at_k': sum(y[i] for i in ranked)/top, 'calibration': bins}


def paired_uncertainty(y, a, b):
    rng = random.Random(PROTOCOL['bootstrap_seed']); changes = {k: [] for k in ('brier', 'log_loss', 'precision_at_k')}
    for _ in range(PROTOCOL['bootstrap_replicates']):
        ids = [rng.randrange(len(y)) for _ in y]
        yy = [y[i] for i in ids]; aa = metrics(yy, [a[i] for i in ids]); bb = metrics(yy, [b[i] for i in ids])
        for key in changes: changes[key].append(bb[key]-aa[key])
    return {key: {'lower': sorted(values)[int(.025*len(values))], 'upper': sorted(values)[min(len(values)-1, int(.975*len(values)))]}
            for key, values in changes.items()}


def evaluate(model, document, predictions):
    validate_model(model); study = assemble(document)
    require(study['manifest'] == model['study_manifest'], 'evaluation_manifest_mismatch')
    require(isinstance(predictions, list) and len(predictions) <= MAX_CASES, 'prediction_limit')
    by_packet = {}
    for p in predictions:
        unseal(p, 'paired_prediction')
        require(p.get('model_sha256') == model['sha256'] and p.get('packet_sha256') not in by_packet, 'prediction_model_or_duplicate')
        require(p.get('target') == TARGET and p.get('export_allowed') is False and p.get('purchase_probability') is None, 'prediction_target')
        require(p.get('probability_scope') == PROBABILITY_SCOPE, 'prediction_probability_scope')
        require(set(p.get('probabilities', {})) == {'baseline', 'conditions'}, 'prediction_arms')
        for v in p['probabilities'].values(): number(v)
        by_packet[p['packet_sha256']] = p
    pairs = []; train_groups = set(model['training_groups']) | set(model['excluded_training_groups'])
    controls = [r for r in study['records'] if r['assignment'] == 'control']
    for r in controls:
        packet = r['packet']; p = by_packet.get(packet['sha256'])
        require(r['person_group'] not in train_groups, 'control_person_seen')
        require(moment(model['training_through']) <= moment(model['fitted_at']) <= moment(packet['frozen_at']), 'training_outcome_after_control')
        if r['label']['value'] is None: continue
        require(p is not None, 'control_prediction_missing')
        require(p['person_group'] == r['person_group'] and p['prediction_cutoff'] == packet['frozen_at'], 'prediction_packet_binding')
        require(moment(model['fitted_at']) <= moment(packet['frozen_at']) <= moment(p['predicted_at']) <= moment(utc_now()), 'prediction_clock')
        # This pure contract checks the computed prediction clock. The runtime
        # separately requires a later private-state read confirming persistence.
        source_case = next(c for c in document['cases'] if c['packet']['sha256'] == packet['sha256'])
        require(all(moment(p['predicted_at']) < moment(x['recorded_at'])
                    for x in receipts(source_case) if x['payload']['outcome'] in ('won', 'lost')), 'prediction_after_outcome')
        for arm, key in [('baseline', 'baseline_weights'), ('conditions', 'condition_weights')]:
            expected = sigmoid(sum(a*b for a, b in zip(vector(packet, arm), model[key])))
            require(abs(expected-p['probabilities'][arm]) < 1e-12, 'prediction_value_mismatch')
        pairs.append((r, p))
    y = [r['label']['value'] for r, p in pairs]
    require(len(y) >= PROTOCOL['minimum_control'] and min(sum(y), len(y)-sum(y)) >= PROTOCOL['minimum_control_class'], 'control_support_insufficient')
    a = [p['probabilities']['baseline'] for r, p in pairs]; b = [p['probabilities']['conditions'] for r, p in pairs]
    am, bm = metrics(y, a), metrics(y, b); uncertainty = paired_uncertainty(y, a, b)
    return seal({'kind': 'paired_evaluation', 'version': VERSION, 'target': TARGET, 'protocol_sha256': PROTOCOL_SHA,
                 'evaluated_at': utc_now(), 'model_sha256': model['sha256'], 'document_sha256': study['document_sha256'],
                 'control_people': len(controls), 'paired_resolved_people': len(y),
                 'unknown_or_censored_people': len(controls)-len(y), 'baseline': am, 'conditions': bm,
                 'paired_delta_conditions_minus_baseline': {k: bm[k]-am[k] for k in ('brier', 'log_loss', 'precision_at_k')},
                 'paired_person_bootstrap': uncertainty, 'evaluation_population': 'same_resolved_conditional_subset',
                 'numerical_advantage_in_conditional_subset': uncertainty['brier']['upper'] < 0 and uncertainty['log_loss']['upper'] < 0,
                 'comparison_conclusion': ('conditional_score_advantage' if uncertainty['brier']['upper'] < 0 and uncertainty['log_loss']['upper'] < 0 else 'conditional_advantage_not_established'),
                 'runtime_provenance_requires_independent_verification': True, 'full_buyer_quality_ready': False,
                 'reader_assurance': study['reader_assurance'], 'purchase_probability': None, 'export_allowed': False})


def readiness(document):
    study = assemble(document); records = study['records']
    counts = {role: {'people': sum(r['assignment'] == role for r in records),
                     'resolved': sum(r['assignment'] == role and r['label']['value'] is not None for r in records),
                     'positive': sum(r['assignment'] == role and r['label']['value'] == 1 for r in records),
                     'negative': sum(r['assignment'] == role and r['label']['value'] == 0 for r in records)}
              for role in ('train', 'control', 'excluded')}
    train = counts['train']; control = counts['control']
    return {'version': VERSION, 'target': TARGET, 'protocol_sha256': PROTOCOL_SHA, 'counts': counts,
            'label_reasons': {reason: sum(r['label']['reason'] == reason for r in records)
                              for reason in sorted({r['label']['reason'] for r in records})},
            'fit_contract_ready': train['resolved'] >= 40 and min(train['positive'], train['negative']) >= 8,
            'comparison_support_ready': control['resolved'] >= 40 and min(control['positive'], control['negative']) >= 8,
            'prospective_prediction_receipts_still_required': True, 'full_buyer_quality_ready': False,
            'assurance': 'source_reader_and_population_truth_require_separate_runtime_verification',
            'purchase_probability': None, 'export_allowed': False}


def unique_object(pairs):
    out = {}
    for k, v in pairs:
        require(k not in out, 'duplicate_json_key'); out[k] = v
    return out


def main():
    began = time.monotonic()
    try:
        raw = sys.stdin.buffer.read(MAX_BYTES+1); require(len(raw) <= MAX_BYTES, 'input_byte_limit')
        data = json.loads(raw.decode('utf-8'), object_pairs_hook=unique_object)
        require(isinstance(data, dict), 'operation_shape')
        op = data.get('operation')
        if op in ('assemble', 'readiness', 'fit') and set(data) == {'operation', 'document'}:
            result = {'assemble': assemble, 'readiness': readiness, 'fit': fit}[op](data['document'])
        elif op == 'predict' and set(data) == {'operation', 'model', 'packet', 'admission', 'manifest'}:
            result = predict(data['model'], data['packet'], data['admission'], data['manifest'])
        elif op == 'evaluate' and set(data) == {'operation', 'model', 'document', 'predictions'}:
            result = evaluate(data['model'], data['document'], data['predictions'])
        else:
            raise NotReady('invalid_operation')
        require(time.monotonic()-began <= 5, 'runtime_limit')
        text = json.dumps(result, sort_keys=True, ensure_ascii=False, separators=(',', ':'), allow_nan=False)
        require(len(text.encode()) <= MAX_BYTES, 'output_byte_limit')
    except (NotReady, ValueError, TypeError, KeyError, AttributeError, IndexError, OverflowError, UnicodeDecodeError, RecursionError):
        print('{"ok":false,"error":"invalid_or_not_ready"}', file=sys.stderr); return 2
    print(text); return 0


if __name__ == '__main__':
    raise SystemExit(main())
