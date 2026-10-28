#!/usr/bin/env python3
"""Pure bounded state transition for the existing private PHP study supplier."""
import copy
import json
import re
import sys
import time
import purchase_conversion_study as study

VERSION = 'purchase_study_runtime_v1'
MAX_STATE_BYTES = 1048576
RESERVE_BYTES = 16384
ARM_CASE_LIMIT = 128
TRAIN_CASE_BYTES_LIMIT = 524288
EXCLUSION_LEDGER_BYTES_LIMIT = 131072
CONFIRMATION_RESERVE_BYTES = 512
CAPACITY_POLICY = 'first_seen_128_each_train_half_ledger_creation_floor_v1'
REQUEST_KEYS = {'operation', 'state_json', 'now', 'reader_sha256', 'profile_fence',
                'profile_cursor', 'profiles', 'updates'}


def encoded(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':'), allow_nan=False)


def seal(state):
    return {**{k: v for k, v in state.items() if k != 'sha256'},
            'sha256': study.digest({k: v for k, v in state.items() if k != 'sha256'})}


def identifier(value):
    study.require(isinstance(value, str) and bool(re.fullmatch(r'(?:0|[1-9][0-9]{0,19})', value)), 'runtime_cursor')
    return int(value)


def protocol_info():
    return {'version': VERSION, 'protocol_sha256': study.PROTOCOL_SHA, 'target': study.TARGET,
            'horizon_days': 30, 'extractor_sha256': study.PROTOCOL['extractor_sha256'],
            'maximum_state_bytes': MAX_STATE_BYTES, 'maximum_cases': study.MAX_CASES,
            'runtime_capacity_policy': CAPACITY_POLICY,
            'maximum_train_cases': ARM_CASE_LIMIT, 'maximum_control_cases': ARM_CASE_LIMIT,
            'maximum_training_case_bytes': TRAIN_CASE_BYTES_LIMIT,
            'maximum_exclusion_ledger_bytes': EXCLUSION_LEDGER_BYTES_LIMIT,
            'forecast_confirmation_policy': 'next_saved_state_read_request_clock_v1',
            'pending_confirmation_reserve_bytes': CONFIRMATION_RESERVE_BYTES}


def document(state):
    cases = []
    confirmed_groups = {p['person_group'] for p in state['predictions']
                        if p['sha256'] in state['prediction_confirmations']}
    for original in state['cases']:
        c = {k: v for k, v in original.items()}
        if c['packet']['person_group'] in state['exclusions']:
            c['assignment'] = 'excluded'
        elif c['assignment'] == 'control' and c['packet']['person_group'] not in confirmed_groups and 'coverage' in c:
            # Keep the cohort denominator, but a CPU-computed forecast without
            # a later private-state read cannot contribute a resolved target.
            c['coverage'] = {**c['coverage'], 'status': 'partial'}
        cases.append(c)
    return {'manifest': state['manifest'], 'cases': cases}


def target_evidence(case):
    """Only evidence capable of changing the preregistered target/risk set."""
    start = study.moment(case['packet']['frozen_at'])
    end = start+study.timedelta(days=30)
    return {r['receipt_id']: r['sha256'] for r in study.receipts(case)
            if start < study.moment(r['recorded_at']) <= end
            or (study.moment(r['recorded_at']) <= start and r['payload']['outcome'] == 'won')}


def complete_target_coverage(case):
    coverage = case.get('coverage', {})
    start = study.moment(case['packet']['frozen_at'])
    return (coverage.get('status') == 'complete' and study.moment(coverage['from']) <= start
            and study.moment(coverage['through']) >= start+study.timedelta(days=30))


def revalidate_training(state, case):
    group = case['packet']['person_group']
    frozen = state['training_truth'].get(group)
    if frozen is None: return
    # Reread coverage clocks and temporary query lag are not target revisions.
    changed = target_evidence(case) != frozen['target_receipts']
    if not changed and complete_target_coverage(case):
        changed = study.label(case, study.admission(case, state['manifest']))['value'] != frozen['value']
    if changed:
        state['model_invalidated'] = True
        state['evaluation'] = None


def summary(state, capacity=0, deferred_updates=0):
    report = study.readiness(document(state))
    evaluation = state['evaluation']
    case_groups = {c['packet']['person_group'] for c in state['cases']}
    pending = sum(c['packet']['person_group'] in state['training_truth'] and not complete_target_coverage(c)
                  for c in state['cases'])
    comparison = (evaluation is not None and not state['model_invalidated'] and not pending
                  and report['comparison_support_ready'])
    return {'version': VERSION, 'target': study.TARGET, 'protocol_sha256': study.PROTOCOL_SHA,
            'cases': len(state['cases']), 'excluded_people': len(state['exclusions'])+state['exclusion_compaction']['people'],
            'train_people': report['counts']['train']['people'], 'train_resolved': report['counts']['train']['resolved'],
            'control_people': report['counts']['control']['people'], 'control_resolved': report['counts']['control']['resolved'],
            'unknown_people': (sum(c['people']-c['resolved'] for c in report['counts'].values())
                              +len(set(state['exclusions'])-case_groups)+state['exclusion_compaction']['people']),
            'model_fitted': int(state['model'] is not None), 'model_invalidated': int(state['model_invalidated']),
            'predictions': len(state['predictions']), 'comparison_available': int(comparison),
            'paired_resolved_people': evaluation['paired_resolved_people'] if evaluation else 0,
            'conditional_score_advantage': int(bool(comparison and evaluation['numerical_advantage_in_conditional_subset'])),
            'paired_brier_delta': evaluation['paired_delta_conditions_minus_baseline']['brier'] if comparison else None,
            'capacity_paused': int(bool(capacity)), 'coverage_is_admitted_journal_only': 1,
            'deferred_updates': deferred_updates, 'training_revalidation_pending': pending,
            'verified_payment_probability': 0, 'all_lead_calibration_ready': 0, 'conversion_uploads': 0}


def load(raw, reader_sha256):
    study.require(isinstance(raw, str) and len(raw.encode()) <= MAX_STATE_BYTES, 'runtime_state_bytes')
    state = json.loads(raw, object_pairs_hook=study.unique_object)
    study.require(isinstance(state, dict) and set(state) == {'kind', 'version', 'protocol_sha256', 'manifest',
                   'profile_cursor', 'profile_fence', 'last_now', 'monitor_cursor', 'cases', 'exclusions',
                   'exclusion_compaction', 'model', 'model_invalidated', 'training_truth', 'predictions',
                   'prediction_confirmations', 'evaluation', 'sha256'}, 'runtime_state_shape')
    study.require(state['kind'] == 'purchase_study_state' and state['version'] == VERSION
                  and state['protocol_sha256'] == study.PROTOCOL_SHA
                  and study.digest({k: v for k, v in state.items() if k != 'sha256'}) == state['sha256'], 'runtime_state_hash')
    study.validate_manifest(state['manifest'])
    study.require(state['manifest']['reader_sha256'] == reader_sha256, 'runtime_reader_changed')
    identifier(state['profile_cursor']); identifier(state['profile_fence']); study.moment(state['last_now'])
    study.require(type(state['monitor_cursor']) is int and state['monitor_cursor'] >= 0
                  and type(state['model_invalidated']) is bool and isinstance(state['exclusions'], dict)
                  and all(study.hash_value(g) and isinstance(v, dict)
                          and set(v) == {'reason', 'packet_sha256', 'profile_id', 'first_frozen_at', 'creation_bound'}
                          and isinstance(v['reason'], str) and study.hash_value(v['packet_sha256'])
                          and isinstance(v['profile_id'], str)
                          for g, v in state['exclusions'].items()), 'runtime_state_metadata')
    study.require(all(study.moment(state['manifest']['registered_at']) <= study.moment(r['first_frozen_at'])
                      <= study.moment(state['last_now']) for r in state['exclusions'].values()), 'runtime_exclusion_clock')
    for r in state['exclusions'].values():
        bound = r['creation_bound']
        study.require(bound is None or (isinstance(bound, dict) and set(bound) == {'created_at', 'source_sha256'}
                      and study.hash_value(bound['source_sha256'])
                      and study.moment(bound['created_at']) <= study.moment(r['first_frozen_at'])), 'runtime_exclusion_bound')
    compaction = state['exclusion_compaction']
    study.require(isinstance(compaction, dict) and set(compaction) == {'created_after', 'people', 'chain_sha256'}
                  and type(compaction['people']) is int and compaction['people'] >= 0
                  and study.hash_value(compaction['chain_sha256'])
                  and study.moment(state['manifest']['registered_at']) <= study.moment(compaction['created_after'])
                  <= study.moment(state['last_now'])
                  and len(encoded(state['exclusions']).encode()) <= EXCLUSION_LEDGER_BYTES_LIMIT,
                  'runtime_exclusion_compaction')
    initial_chain = study.digest([CAPACITY_POLICY, state['manifest']['registered_at']])
    study.require((compaction['people'] == 0 and compaction['created_after'] == state['manifest']['registered_at']
                   and compaction['chain_sha256'] == initial_chain)
                  or (compaction['people'] > 0 and compaction['chain_sha256'] != initial_chain),
                  'runtime_exclusion_compaction')
    study.require(isinstance(state['cases'], list) and len(state['cases']) <= study.MAX_CASES
                  and isinstance(state['predictions'], list) and len(state['predictions']) <= study.MAX_CASES, 'runtime_state_limit')
    study.require(isinstance(state['prediction_confirmations'], dict), 'runtime_confirmation_shape')
    predictions = {}
    for p in state['predictions']:
        study.unseal(p, 'paired_prediction')
        study.require(p['sha256'] not in predictions and isinstance(state['model'], dict)
                      and p.get('model_sha256') == state['model'].get('sha256')
                      and study.moment(p['predicted_at']) <= study.moment(state['last_now']), 'runtime_prediction_binding')
        predictions[p['sha256']] = p
    study.require(set(state['prediction_confirmations']) <= set(predictions), 'runtime_confirmation_prediction')
    for sha, confirmation in state['prediction_confirmations'].items():
        study.require(isinstance(confirmation, dict)
                      and set(confirmation) == {'version', 'prediction_sha256', 'confirmed_at', 'loaded_state_sha256', 'sha256'}
                      and type(confirmation['version']) is int and confirmation['version'] == 1
                      and confirmation['prediction_sha256'] == sha
                      and study.hash_value(confirmation['loaded_state_sha256'])
                      and study.digest({k: v for k, v in confirmation.items() if k != 'sha256'}) == confirmation['sha256']
                      and study.moment(predictions[sha]['predicted_at']) <= study.moment(confirmation['confirmed_at'])
                      <= study.moment(state['last_now']), 'runtime_confirmation_binding')
    study.assemble(document(state))
    study.require(all(sum(c['packet']['assignment'] == arm for c in state['cases']) <= ARM_CASE_LIMIT
                      for arm in ('train', 'control')) and within_training_budget(state), 'runtime_arm_capacity')
    study.require(isinstance(state['training_truth'], dict), 'runtime_training_truth_shape')
    expected = set()
    if state['model'] is not None:
        study.validate_model(state['model']); expected = set(state['model']['training_groups'])
    study.require(set(state['training_truth']) == expected, 'runtime_training_truth_groups')
    by_group = {c['packet']['person_group']: c for c in state['cases']}
    for group, truth in state['training_truth'].items():
        study.require(group in by_group and isinstance(truth, dict)
                      and set(truth) == {'packet_sha256', 'value', 'target_receipts'}
                      and truth['packet_sha256'] == by_group[group]['packet']['sha256']
                      and type(truth['value']) is int and truth['value'] in (0, 1)
                      and isinstance(truth['target_receipts'], dict)
                      and all(isinstance(k, str) and study.hash_value(v) for k, v in truth['target_receipts'].items()),
                      'runtime_training_truth_shape')
    if state['model'] is not None:
        study.require(sum(t['value'] for t in state['training_truth'].values()) == state['model']['train_positives'],
                      'runtime_training_truth_labels')
    if not state['model_invalidated']:
        for group in expected:
            revalidate_training(state, by_group[group])
        study.require(not state['model_invalidated'], 'runtime_training_truth_changed')
    return state


def creation_bound(case, manifest, profile_id):
    proof = case.get('admission')
    if not isinstance(proof, dict) or proof.get('profile_id') != profile_id: return None
    # Exposure can prohibit enrollment without invalidating otherwise bound
    # native creation evidence. Never invent a bound for an unknown/bad clock.
    probe = {**case, 'admission': {**proof, 'prior_conversations': 0, 'earlier_profiles': 0, 'exposure': 'unseen'}}
    try:
        if study.admission(probe, manifest): return None
    except study.NotReady:
        return None
    return {'created_at': proof['conversation_created_at'], 'source_sha256': proof['source_reference']['sha256']}


def excluded(state, group, reason, packet=None, profile_id=None, case=None):
    if group not in state['exclusions']:
        if packet is None:
            case = next(c for c in state['cases'] if c['packet']['person_group'] == group)
            packet = case['packet']; profile_id = case['admission']['profile_id']
        state['exclusions'][group] = {'reason': reason, 'packet_sha256': packet['sha256'],
                                      'profile_id': profile_id, 'first_frozen_at': packet['frozen_at'],
                                      'creation_bound': creation_bound(case or {}, state['manifest'], profile_id)}
    if state['model'] is not None and group in state['model']['training_groups']:
        state['model_invalidated'] = True
        state['evaluation'] = None
    if any(p['person_group'] == group for p in state['predictions']):
        state['evaluation'] = None
    if len(encoded(state['exclusions']).encode()) > EXCLUSION_LEDGER_BYTES_LIMIT:
        case_groups = {c['packet']['person_group'] for c in state['cases']}
        compacted = {g: r for g, r in state['exclusions'].items() if g not in case_groups and r['creation_bound'] is not None}
        study.require(bool(compacted), 'runtime_unbound_exclusion_capacity')
        old = state['exclusion_compaction']
        floor = max([old['created_after']]+[r['first_frozen_at'] for r in compacted.values()])
        state['exclusion_compaction'] = {'created_after': floor, 'people': old['people']+len(compacted),
            'chain_sha256': study.digest({'previous': old['chain_sha256'], 'created_after': floor,
                                         'exclusions': compacted})}
        state['exclusions'] = {g: r for g, r in state['exclusions'].items() if g not in compacted}
        study.require(len(encoded(state['exclusions']).encode()) <= EXCLUSION_LEDGER_BYTES_LIMIT,
                      'runtime_case_exclusion_capacity')


def within_budget(state, reserve=RESERVE_BYTES):
    pending = sum(p['sha256'] not in state['prediction_confirmations'] for p in state['predictions'])
    return len(encoded(seal(state)).encode()) <= MAX_STATE_BYTES-reserve-pending*CONFIRMATION_RESERVE_BYTES


def within_training_budget(state):
    # Includes outcome envelopes, not just the small initial feature packet.
    # The other half remains available for model, future controls and metadata.
    cases = [c for c in state['cases'] if c['packet']['assignment'] == 'train']
    return len(encoded(cases).encode()) <= TRAIN_CASE_BYTES_LIMIT


def capacity_exclusion(state, group, reason):
    # Known contrary evidence cannot be discarded while retaining a valid model
    # or an old complete comparison. Keep its source in the canonical journal;
    # persist the small disposition even when the full new receipt cannot fit.
    candidate = copy.deepcopy(state)
    excluded(candidate, group, reason)
    candidate['evaluation'] = None
    study.require(within_budget(candidate, 0), 'runtime_capacity_disposition')
    return candidate


def enforce_confirmation_chronology(state, case):
    group = case['packet']['person_group']
    forecast = next((p for p in state['predictions'] if p['person_group'] == group), None)
    confirmation = state['prediction_confirmations'].get(forecast['sha256']) if forecast else None
    if confirmation and any(r['payload']['outcome'] in ('won', 'lost')
                            and study.moment(r['recorded_at']) <= study.moment(confirmation['confirmed_at'])
                            for r in case['outcomes']):
        excluded(state, group, 'outcome_recorded_before_forecast_confirmation')


def initialize(request):
    now = request['now']
    manifest = {'study_id': study.digest([now, request['reader_sha256'], study.PROTOCOL_SHA]),
                'registered_at': now, 'protocol_sha256': study.PROTOCOL_SHA,
                'reader_kind': 'reviewed_runtime_reader', 'reader_sha256': request['reader_sha256']}
    state = {'kind': 'purchase_study_state', 'version': VERSION, 'protocol_sha256': study.PROTOCOL_SHA,
             'manifest': manifest, 'profile_cursor': request['profile_fence'], 'profile_fence': request['profile_fence'],
             'last_now': now, 'monitor_cursor': 0, 'cases': [], 'exclusions': {}, 'model': None,
             'exclusion_compaction': {'created_after': now, 'people': 0,
                                     'chain_sha256': study.digest([CAPACITY_POLICY, now])},
             'model_invalidated': False, 'training_truth': {}, 'predictions': [],
             'prediction_confirmations': {}, 'evaluation': None}
    return state


def advance(request):
    study.require(isinstance(request, dict) and set(request) == REQUEST_KEYS, 'runtime_request_shape')
    study.require(request['operation'] in ('init', 'advance') and study.hash_value(request['reader_sha256']), 'runtime_operation')
    now, actual = study.moment(request['now']), study.moment(study.utc_now())
    study.require(0 <= (actual-now).total_seconds() <= 60, 'runtime_actual_clock')
    fence, cursor = identifier(request['profile_fence']), identifier(request['profile_cursor'])
    study.require(cursor <= fence and isinstance(request['profiles'], list) and len(request['profiles']) <= 32
                  and isinstance(request['updates'], list) and len(request['updates']) <= 4, 'runtime_batch_limit')
    if request['operation'] == 'init' or request['state_json'] is None:
        study.require(request['state_json'] is None and not request['profiles'] and not request['updates'], 'runtime_init_scope')
        state = initialize(request)
        return {'state_json': encoded(seal(state)), 'summary': summary(state)}
    state = load(request['state_json'], request['reader_sha256'])
    study.require(study.moment(state['last_now']) <= now, 'runtime_clock_regressed')
    old = identifier(state['profile_cursor'])
    study.require(old <= cursor <= fence and identifier(state['profile_fence']) <= fence, 'runtime_cursor_regressed')
    state = copy.deepcopy(state)
    loaded_state_sha = state['sha256']; capacity = False
    # This incoming state was read by PHP before the current invocation. Only
    # its already-saved forecasts can acquire a persistence confirmation here.
    for forecast in list(state['predictions']):
        sha = forecast['sha256']
        if sha in state['prediction_confirmations']: continue
        candidate = copy.deepcopy(state)
        candidate['prediction_confirmations'][sha] = study.seal({'version': 1, 'prediction_sha256': sha,
            'confirmed_at': request['now'], 'loaded_state_sha256': loaded_state_sha})
        if within_budget(candidate, 0): state = candidate
        else:
            state = capacity_exclusion(state, forecast['person_group'], 'prediction_confirmation_capacity')
            capacity = True
    for c in state['cases']: enforce_confirmation_chronology(state, c)
    seen = {c['packet']['person_group']: c for c in state['cases']}
    old_groups = set(seen)
    stopped_at = None; previous = old; fresh = []; validated_profiles = []; outside_floor = set(); dispositioned = set()
    for entry in request['profiles']:
        study.require(isinstance(entry, dict) and set(entry) == {'db_profile_id', 'observed_at', 'wrapper', 'admission'}, 'runtime_profile_shape')
        index = identifier(entry['db_profile_id'])
        study.require(previous < index <= cursor, 'runtime_profile_order'); previous = index
        wrapper = entry['wrapper']; study.require(isinstance(wrapper, dict) and 'purchase_study' in wrapper, 'runtime_packet_missing')
        p = study.validate_packet(wrapper['purchase_study'])
        study.require(entry['observed_at'] == p['frozen_at'] and study.moment(p['frozen_at']) <= now
                      and wrapper.get('subject') == p['scope'] and wrapper.get('access_status') == p['source_access'], 'runtime_profile_binding')
        validated_profiles.append((entry, index, p))
    provided_groups = {p['person_group'] for entry, index, p in validated_profiles}
    for entry, index, p in validated_profiles:
        # Entry-only packets are not an enrollment opportunity; the rule is
        # fixed before labels and never filters on the number of conditions.
        if (p['baseline'][study.BASE_NAMES.index('activity')] != 1
                and p['extractor_sha256'] == study.PROTOCOL['extractor_sha256']):
            continue
        group = p['person_group']
        if group in seen:
            continue
        if group in state['exclusions']:
            dispositioned.add(group); continue
        floor = study.moment(state['exclusion_compaction']['created_after'])
        proof = entry['admission']
        created = None
        if isinstance(proof, dict):
            try: created = study.moment(proof.get('conversation_created_at'))
            except study.NotReady: pass
        if study.moment(p['frozen_at']) <= floor or (created is not None and created <= floor):
            # The registered creation-time fence, not a forgotten person's label
            # or richer later packet, determines capacity-cohort eligibility.
            outside_floor.add(group); continue
        c = {'packet': p, 'assignment': p['assignment'], 'assigned_at': p['assigned_at'],
             'admission': entry['admission'], 'outcomes': []}
        issues = study.admission(c, state['manifest'])
        if isinstance(entry['admission'], dict) and entry['admission'].get('profile_id') != entry['db_profile_id']:
            issues.append('native_profile_id_not_bound')
        reason = issues[0] if issues else None
        if not reason and p['assignment'] == 'control' and state['model'] is None:
            reason = 'control_person_seen_before_fit'
        elif not reason and state['model'] is not None and study.moment(p['frozen_at']) < study.moment(state['model']['fitted_at']):
            reason = 'profile_precedes_fitted_model'
        if not reason and sum(c['packet']['assignment'] == p['assignment'] for c in state['cases']) >= ARM_CASE_LIMIT:
            reason = p['assignment']+'_person_quota'
        candidate = copy.deepcopy(state)
        if reason:
            # Permanent negative admission decisions need only a compact hash
            # receipt, not a full frozen vector occupying the research case cap.
            excluded(candidate, group, reason, p, entry['db_profile_id'], c)
        else:
            if len(state['cases']) >= study.MAX_CASES:
                capacity = True; stopped_at = index-1; break
            candidate['cases'].append(c)
            if not within_training_budget(candidate):
                candidate = copy.deepcopy(state)
                reason = 'train_case_byte_quota'
                excluded(candidate, group, reason, p, entry['db_profile_id'], c)
        if not within_budget(candidate):
            capacity = True; stopped_at = index-1; break
        state = candidate
        if reason: dispositioned.add(group)
        else: seen[group] = c; fresh.append(group)
    state['profile_cursor'] = str(stopped_at) if stopped_at is not None else request['profile_cursor']
    state['profile_fence'] = request['profile_fence']
    # Cursor backpressure never deletes source observations: the caller retains
    # the exact unprocessed metadata row for a future bounded pass.
    monitored_old = 0; deferred_updates = 0; updated_groups = set()
    for update in request['updates']:
        study.require(isinstance(update, dict) and set(update) == {'person_group', 'outcomes', 'coverage', 'access_status'}, 'runtime_update_shape')
        group = update['person_group']
        study.require(group not in updated_groups, 'runtime_duplicate_person_update'); updated_groups.add(group)
        study.require(update['access_status'] in ('allowed', 'denied', 'unknown'), 'runtime_update_access')
        if group in old_groups: monitored_old += 1
        if group in state['exclusions']: continue
        # A later profile in this same batch can compact a non-case ledger
        # entry. Its supplied update remains non-enrolled for this pass even
        # though the compacted per-person record is no longer in the state.
        if group in outside_floor or group in dispositioned: continue
        if group not in seen and capacity and group in provided_groups:
            deferred_updates += 1; continue
        study.require(group in seen, 'runtime_unknown_person_update')
        if update['access_status'] != 'allowed':
            excluded(state, group, 'current_access_'+update['access_status']); continue
        if group in state['exclusions']: continue
        c = next(c for c in state['cases'] if c['packet']['person_group'] == group)
        incoming = update['outcomes']
        study.require(isinstance(incoming, list) and len(incoming) <= 17, 'runtime_outcome_batch')
        by_id = {r['receipt_id']: r for r in c['outcomes']}
        for receipt in incoming:
            study.require(isinstance(receipt, dict) and isinstance(receipt.get('receipt_id'), str), 'runtime_receipt_shape')
            if receipt['receipt_id'] in by_id:
                study.require(study.digest(receipt) == study.digest(by_id[receipt['receipt_id']]), 'runtime_receipt_mutated')
            else: by_id[receipt['receipt_id']] = receipt
        if len(by_id) > 16:
            excluded(state, group, 'outcome_receipt_limit'); continue
        candidate = copy.deepcopy(state); cc = next(x for x in candidate['cases'] if x['packet']['person_group'] == group)
        cc['outcomes'] = list(by_id.values()); study.receipts(cc)
        coverage = update['coverage']
        study.require(isinstance(coverage, dict) and set(coverage) == {'from', 'through', 'available_at', 'status', 'source_reference'}
                      and coverage['status'] in ('complete', 'partial'), 'runtime_coverage_shape')
        study.require(study.moment(coverage['through']) <= study.moment(coverage['available_at']) <= actual, 'runtime_coverage_clock')
        if 'coverage' in cc:
            study.require(study.moment(cc['coverage']['through']) <= study.moment(coverage['through'])
                          and study.moment(cc['coverage']['available_at']) <= study.moment(coverage['available_at']), 'runtime_coverage_regressed')
        cc['coverage'] = coverage
        revalidate_training(candidate, cc)
        enforce_confirmation_chronology(candidate, cc)
        if not within_training_budget(candidate) or not within_budget(candidate):
            state = capacity_exclusion(state, group, 'outcome_update_byte_capacity')
            capacity = True; continue
        state = candidate
    state['monitor_cursor'] = (state['monitor_cursor']+monitored_old) % max(1, len(state['cases']))
    data = document(state); ready = study.readiness(data)
    if state['model'] is None and ready['fit_contract_ready']:
        candidate = copy.deepcopy(state); candidate['model'] = study.fit(data)
        model = candidate['model']
        model['excluded_training_groups'] = sorted((set(model['excluded_training_groups']) | set(state['exclusions']))-set(model['training_groups']))
        candidate['model'] = study.seal({k: v for k, v in model.items() if k != 'sha256'})
        for c in candidate['cases']:
            group = c['packet']['person_group']
            if group in model['training_groups']:
                candidate['training_truth'][group] = {'packet_sha256': c['packet']['sha256'],
                    'value': study.label(c, study.admission(c, candidate['manifest']))['value'],
                    'target_receipts': target_evidence(c)}
        if within_budget(candidate, 4096): state = candidate
        else: capacity = True
    if state['model'] is not None and not state['model_invalidated']:
        predicted = {p['person_group'] for p in state['predictions']}
        for group in fresh:
            c = next(x for x in state['cases'] if x['packet']['person_group'] == group)
            if group in state['exclusions'] or c['packet']['assignment'] != 'control' or group in predicted: continue
            if any(r['payload']['outcome'] in ('won', 'lost') for r in c['outcomes']):
                excluded(state, group, 'outcome_before_first_forecast'); continue
            candidate = copy.deepcopy(state)
            candidate['predictions'].append(study.predict(state['model'], c['packet'], c['admission'], state['manifest']))
            if within_budget(candidate, 4096): state = candidate
            else:
                excluded(state, group, 'prediction_capacity'); capacity = True
        data = document(state); ready = study.readiness(data)
        if ready['comparison_support_ready'] and all(complete_target_coverage(c) for c in state['cases']
                                                    if c['packet']['person_group'] in state['training_truth']):
            candidate = copy.deepcopy(state)
            candidate['evaluation'] = study.evaluate(state['model'], data, state['predictions'])
            if within_budget(candidate, 1024): state = candidate
            else: capacity = True
    state['last_now'] = study.utc_now()
    result = encoded(seal(state)); study.require(len(result.encode()) <= MAX_STATE_BYTES, 'runtime_state_capacity')
    return {'state_json': result, 'summary': summary(state, capacity, deferred_updates)}


def main():
    began = time.monotonic()
    try:
        raw = sys.stdin.buffer.read(study.MAX_BYTES+1); study.require(len(raw) <= study.MAX_BYTES, 'runtime_input_bytes')
        request = json.loads(raw.decode('utf-8'), object_pairs_hook=study.unique_object)
        result = protocol_info() if request == {'operation': 'protocol'} else advance(request)
        text = encoded(result)
        study.require(time.monotonic()-began <= 5 and len(text.encode()) <= study.MAX_BYTES, 'runtime_budget')
    except (study.NotReady, ValueError, TypeError, KeyError, AttributeError, IndexError, OverflowError, UnicodeDecodeError, RecursionError):
        print('{"ok":false,"error":"runtime_study_invalid_or_not_ready"}', file=sys.stderr); return 2
    print(text); return 0


if __name__ == '__main__':
    raise SystemExit(main())
