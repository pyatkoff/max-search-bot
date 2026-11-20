"""Closed app report schema. Pure validation only; no I/O or decryption.

validate_app(report) returns the original report or raises ValidationError with a
fixed diagnostic code. The accepted aggregate validator below is copied verbatim
from hotel-entry-forecast-20261120/runner.py; it sees only the original report
keys after the new bridge has been removed. Import this module in its own
namespace: BASE and WINDOW intentionally belong to the accepted app aggregate.
"""
import base64
from collections import Counter, defaultdict
import datetime as dt
import json
import math
import re

BASE='b4b65daaa472c15426a244b87ede3e6c0394626b'
WINDOW={'from_utc':'2026-11-11 21:00:00','until_utc_exclusive':'2026-11-18 21:00:00','followup_until_utc_exclusive':'2026-11-19 21:00:00'}
ACCEPTED_VALIDATOR_SOURCE_SHA256='6f02839d12253f367a8f03f06c6cdf2bd86801bc659de9ef93a4ab08ed654a16'

class Stop(Exception): pass

def need(condition, code):
    if not condition: raise Stop(code)

ValidationError = Stop

# BEGIN BYTE-EQUIVALENT ACCEPTED AGGREGATE VALIDATOR
def no_private_fields(value):
    forbidden={'conversation_id','customer_key','customer_id','external_user_id','external_chat_id','entry_id','snapshot_json','profile_json','post_text','text','dialogues','raw','token','yclid','client_id','profile_id','journal_id','request_anchor','current_request_anchor'}
    if isinstance(value,dict):
        need(not (set(value)&forbidden),'PRIVATE_FIELD_IN_OUTPUT')
        for v in value.values(): no_private_fields(v)
    elif isinstance(value,list):
        for v in value: no_private_fields(v)

STATES=('pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched')
PRICE_ACTIONS=('missing','unchanged','rebased','reduced','voided','unverified','review_required')
AGE_BANDS=('first_hour','first_three_hours','first_day','later_update','unknown')
MONETARY_VERSIONS=('early_deal_commission_7pct_v1','early_deal_commission_7pct_v2','early_deal_commission_7pct_v3')
LIMITS={'max_entry_rows':10000,'max_profile_rows':10000,'max_profile_packet_bytes':32768,
    'max_current_access_scopes':10000,'max_current_access_profile_bytes':131072,'max_current_access_scalar_bytes':32,
    'max_journal_rows':1000,'max_journal_packet_bytes':4096,'max_ledger_rows':10000,
    'max_selected_bytes':4194304,'max_output_bytes':4194304,'max_execution_seconds':20,'sql_timeout_ms':3000}
COVERAGE_COUNTS=('entry_rows_read','global_entry_rows_scanned','global_window_entries',
    'unavailable_publication_entries','invalid_binding_entries','owner_scope_excluded_entries','admitted_entries',
    'current_access_scopes_checked','current_access_allowed_scopes','current_access_blocked_scopes',
    'current_access_unavailable_scopes','current_access_blocked_entries','current_access_unavailable_entries',
    'admitted_scopes','scopes_without_profile_in_followup','profile_rows_read','profile_packets_read',
    'invalid_profile_packets','unavailable_profile_packets','profiles_unknown_clock','profiles_outside_entry_24h',
    'profiles_not_exact_entry','profiles_missing_price_context','exact_linked_profiles','exact_linked_entries',
    'selected_request_anchors','ambiguous_request_anchors','duplicate_profiles_same_anchor','ledger_rows_read','ledger_invalid_rows')
ORIGINS=('unknown','customer_budget','delivered_offer','native_hotel_inquiry','channel_post_range','unavailable')
SEMANTICS={
    'entry_basis':'registry_accepted_entries_event_join_not_rechecked',
    'current_access':'latest_exact_subject_profile_access_status_allowed_at_read_time',
    'source_clock_basis':'saved_database_recording_evidence_not_native_action_time',
    'profile_candidate_basis':'same_subject_and_scope_time_bounds_not_entry_attribution',
    'profile_origin_counts_basis':'declared_bounded_metadata_not_validated_money',
    'selection':'earliest_valid_exact_price_linked_frozen_profile_per_request_anchor_within_entry_24h',
    'request_identity':'observed_anchor_not_verified_person_or_trip',
    'exact_link':'frozen_channel_post_range_reference_and_matching_entry_publication_version_context',
    'other_price_origins_attributed_to_post':False,
    'ledger_scope':'all_valid_exact_linked_profiles_within_entry_24h_excluding_ambiguous_anchors',
    'ledger_lifecycle_basis':'recorded_observation_timestamps_and_current_states_as_of_read',
    'ledger_audit_sums_are_per_record_diagnostics':True,
    'forecast_value_and_ledger_delta_additive':False,
    'price_verified':False,'calibration_status':'expert_estimate_not_calibrated',
    'entries_are_clicks':False,'dialogues_are_unique_people':False,
    'max2_visit_linkage_established':False,'entry_capture_completeness':'not_scanned_without_exact_frozen_reference',
    'customer_texts_read':False,'full_profile_json_read':False,'recomputation_performed':False}

def count(value, bound=100000):
    need(type(value) is int and 0<=value<=bound,'COUNT_INVALID')
    return value

def money(value):
    need(type(value) in (int,float) and math.isfinite(value) and 0<=value<=10**12
         and abs(value*100-round(value*100))<0.001,'MONEY_INVALID')
    return value

def fields(value, names, code):
    need(isinstance(value,dict) and set(value)==set(names),code)

def same_money(a,b):
    need(abs(a-b)<0.001,'MONEY_RECONCILIATION_FAILED')

def validate_ledger(ledger):
    fields(ledger,('rows','value_rub_sum','by_state','api_accepted_rows','api_accepted_value_rub_sum',
        'processed_rows','processed_value_rub_sum','price_audit'),'LEDGER_FIELDS_INVALID')
    rows=count(ledger['rows']);total=money(ledger['value_rub_sum'])
    fields(ledger['by_state'],STATES,'LEDGER_STATES_INVALID')
    for state,v in ledger['by_state'].items():
        fields(v,('rows','value_rub_sum'),'LEDGER_STATE_FIELDS_INVALID')
        count(v['rows'],rows);money(v['value_rub_sum'])
    need(sum(v['rows'] for v in ledger['by_state'].values())==rows,'LEDGER_COUNT_RECONCILIATION_FAILED')
    same_money(sum(v['value_rub_sum'] for v in ledger['by_state'].values()),total)
    for prefix in ('api_accepted','processed'):
        count(ledger[prefix+'_rows'],rows);money(ledger[prefix+'_value_rub_sum'])
        need(ledger[prefix+'_value_rub_sum']<=total+0.001,'LEDGER_SUBSET_INVALID')
    fields(ledger['price_audit'],('counts','original_rub_sum','applied_rub_sum','prior_reserved_rub_sum'),
        'PRICE_AUDIT_FIELDS_INVALID')
    audit=ledger['price_audit'];fields(audit['counts'],PRICE_ACTIONS,'PRICE_AUDIT_ACTIONS_INVALID')
    for v in audit['counts'].values():count(v,rows)
    need(sum(audit['counts'].values())==rows,'PRICE_AUDIT_COUNT_RECONCILIATION_FAILED')
    for key in ('original_rub_sum','applied_rub_sum','prior_reserved_rub_sum'):money(audit[key])

def validate_forecast(row):
    fields(row,('monetary_version','semantic_version','model_sha256','anchors','estimated_count','probability_sum',
        'probability_mean','frozen_value_rub_sum','base_rub_sum','deal_point_min_rub','deal_point_max_rub',
        'age_seconds_min','age_seconds_max','age_bands','first_frozen_at','last_frozen_at'),'FORECAST_FIELDS_INVALID')
    need(row['monetary_version'] in MONETARY_VERSIONS and row['semantic_version']=='semantic_purchase_heuristic_v1',
        'MODEL_VERSION_INVALID')
    need(isinstance(row['model_sha256'],str) and re.fullmatch('[a-f0-9]{64}',row['model_sha256']) is not None,
        'MODEL_DIGEST_INVALID')
    anchors=count(row['anchors']);need(anchors>0,'EMPTY_FORECAST_GROUP')
    estimated=count(row['estimated_count'],anchors)
    count(row['base_rub_sum'],anchors*100000)
    if estimated:
        for k,bound in (('probability_sum',estimated),('probability_mean',1)):
            v=row[k];need(type(v) in (int,float) and math.isfinite(v) and 0<v<=bound,'PROBABILITY_INVALID')
        need(abs(row['probability_sum']/estimated-row['probability_mean'])<=0.000001,'PROBABILITY_RECONCILIATION_FAILED')
        count(row['frozen_value_rub_sum'],estimated*100000)
    else:
        need(all(row[k] is None for k in ('probability_sum','probability_mean','frozen_value_rub_sum')),
             'UNKNOWN_PROBABILITY_NOT_NULL')
    for k in ('deal_point_min_rub','deal_point_max_rub'):
        need(row[k] is None or type(row[k]) is int and 10000<=row[k]<=10000000,'DEAL_POINT_INVALID')
    need((row['deal_point_min_rub'] is None)==(row['deal_point_max_rub'] is None),'DEAL_POINT_PAIR_INVALID')
    if row['deal_point_min_rub'] is not None:
        need(row['deal_point_min_rub']<=row['deal_point_max_rub'],'DEAL_POINT_ORDER_INVALID')
    for k in ('age_seconds_min','age_seconds_max'):count(row[k],86400)
    need(row['age_seconds_min']<=row['age_seconds_max'],'AGE_ORDER_INVALID')
    fields(row['age_bands'],AGE_BANDS,'AGE_BANDS_INVALID')
    for v in row['age_bands'].values():count(v,anchors)
    need(sum(row['age_bands'].values())==anchors,'AGE_COUNT_RECONCILIATION_FAILED')
    for k in ('first_frozen_at','last_frozen_at'):
        need(isinstance(row[k],str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z',row[k]) is not None,
             'FROZEN_CLOCK_INVALID')
        moment=dt.datetime.fromisoformat(row[k].replace('Z','+00:00'))
        lower=dt.datetime.strptime(WINDOW['from_utc'],'%Y-%m-%d %H:%M:%S').replace(tzinfo=dt.timezone.utc)
        upper=dt.datetime.strptime(WINDOW['followup_until_utc_exclusive'],'%Y-%m-%d %H:%M:%S').replace(tzinfo=dt.timezone.utc)
        need(lower<=moment<upper,'FROZEN_CLOCK_SCOPE_INVALID')
    need(row['first_frozen_at']<=row['last_frozen_at'],'FROZEN_CLOCK_ORDER_INVALID')

def validate_report(report):
    need(isinstance(report,dict),'REPORT_SHAPE_INVALID')
    if report.get('ok') is False:
        need(set(report)=={'ok','error'} and isinstance(report['error'],str)
             and re.fullmatch('[a-z_]{1,80}',report['error']) is not None,'UNSAFE_ERROR_OUTPUT')
        return report
    fields(report,('ok','installed_sha','db_writes','provider_calls','message_sends','aggregate'),'REPORT_FIELDS_INVALID')
    need(report['ok'] is True and report['installed_sha']==BASE,'RUNTIME_PROVENANCE_MISMATCH')
    for k in ('db_writes','provider_calls','message_sends'):
        need(type(report[k]) is int and report[k]==0,'SIDE_EFFECT_FLAGS_INVALID')
    a=report['aggregate']
    fields(a,('schema','window','checked_at_utc','coverage','posts','ledger','semantics','limits'),'AGGREGATE_FIELDS_INVALID')
    need(a['schema']=='post_entry_forecast_aggregate_v1','AGGREGATE_SCHEMA_INVALID')
    need(a['window']=={**WINDOW,'business_timezone':'Europe/Moscow','days':7,'followup_hours':24},'WINDOW_MISMATCH')
    need(a['limits']==LIMITS,'LIMITS_MISMATCH')
    need(a['semantics']==SEMANTICS,'SEMANTICS_MISMATCH')
    need(isinstance(a['checked_at_utc'],str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z',a['checked_at_utc']) is not None,
        'READ_CLOCK_INVALID')
    c=a['coverage'];fields(c,(*COVERAGE_COUNTS,'complete_within_bound','profile_origin_counts'),'COVERAGE_FIELDS_INVALID')
    need(c['complete_within_bound'] is True,'INCOMPLETE_COHORT')
    for k in COVERAGE_COUNTS:count(c[k],10000)
    fields(c['profile_origin_counts'],ORIGINS,'PROFILE_ORIGIN_FIELDS_INVALID')
    for v in c['profile_origin_counts'].values():count(v,10000)
    need(sum(c['profile_origin_counts'].values())==c['profile_rows_read'],'PROFILE_ORIGIN_RECONCILIATION_FAILED')
    need(c['current_access_scopes_checked']==sum(c['current_access_'+k+'_scopes'] for k in ('allowed','blocked','unavailable'))
         and c['current_access_allowed_scopes']==c['admitted_scopes']
         and c['current_access_blocked_scopes']<=c['current_access_blocked_entries']
         and c['current_access_unavailable_scopes']<=c['current_access_unavailable_entries'],
         'CURRENT_ACCESS_RECONCILIATION_FAILED')
    need(c['entry_rows_read']<=c['global_window_entries']<=c['global_entry_rows_scanned']
         and c['admitted_entries']<=c['entry_rows_read']
         and c['exact_linked_entries']<=c['admitted_entries']
         and c['exact_linked_profiles']<=c['profile_packets_read']<=c['profile_rows_read']
         and c['selected_request_anchors']<=c['exact_linked_profiles']
         and c['scopes_without_profile_in_followup']<=c['admitted_scopes'],'COVERAGE_RELATIONS_INVALID')
    need(isinstance(a['posts'],list) and len(a['posts'])<=1000,'POST_GROUP_BOUND')
    seen=set()
    for p in a['posts']:
        fields(p,('publication_id','version_id','platform','channel_id','entries','admitted_entries','exact_linked_entries',
            'exact_linked_profiles','selected_anchors','forecasts','ledger'),'POST_FIELDS_INVALID')
        need(all(type(p[k]) is int and 0<p[k]<10**19 for k in ('publication_id','version_id')),'PUBLICATION_ID_INVALID')
        need(p['platform'] in ('max','telegram') and isinstance(p['channel_id'],str)
             and re.fullmatch('-?[0-9]{1,64}',p['channel_id']) is not None,'PUBLIC_CHANNEL_INVALID')
        key=(p['platform'],p['channel_id'],p['publication_id'],p['version_id'])
        need(key not in seen,'DUPLICATE_POST_GROUP');seen.add(key)
        for k in ('entries','admitted_entries','exact_linked_entries','exact_linked_profiles','selected_anchors'):count(p[k],10000)
        need(0<p['entries'] and p['exact_linked_entries']<=p['admitted_entries']<=p['entries']
             and p['selected_anchors']<=p['exact_linked_profiles'],'POST_COVERAGE_INVALID')
        need(isinstance(p['forecasts'],list) and len(p['forecasts'])<=1000,'FORECAST_GROUP_BOUND')
        forecast_keys=set()
        for f in p['forecasts']:
            validate_forecast(f)
            fk=(f['monetary_version'],f['semantic_version'],f['model_sha256'])
            need(fk not in forecast_keys,'DUPLICATE_FORECAST_GROUP');forecast_keys.add(fk)
        need(sum(f['anchors'] for f in p['forecasts'])==p['selected_anchors'],'ANCHOR_RECONCILIATION_FAILED')
        validate_ledger(p['ledger'])
    validate_ledger(a['ledger'])
    for key,coverage_key in (('admitted_entries','admitted_entries'),('exact_linked_entries','exact_linked_entries'),
        ('exact_linked_profiles','exact_linked_profiles'),('selected_anchors','selected_request_anchors')):
        need(sum(p[key] for p in a['posts'])==c[coverage_key],'POST_TOTAL_RECONCILIATION_FAILED')
    need(sum(p['ledger']['rows'] for p in a['posts'])==a['ledger']['rows'],'POST_LEDGER_RECONCILIATION_FAILED')
    same_money(sum(p['ledger']['value_rub_sum'] for p in a['posts']),a['ledger']['value_rub_sum'])
    need(sum(p['entries'] for p in a['posts'])+c['unavailable_publication_entries']+c['invalid_binding_entries']==c['entry_rows_read'],
        'ENTRY_TOTAL_RECONCILIATION_FAILED')
    need(c['admitted_entries']+c['owner_scope_excluded_entries']+c['unavailable_publication_entries']+c['invalid_binding_entries']
         +c['current_access_blocked_entries']+c['current_access_unavailable_entries']==c['entry_rows_read'],
        'ADMISSION_TOTAL_RECONCILIATION_FAILED')
    need(a['ledger']['rows']+c['ledger_invalid_rows']<=c['ledger_rows_read'],'LEDGER_READ_RECONCILIATION_FAILED')
    for state in STATES:
        need(sum(p['ledger']['by_state'][state]['rows'] for p in a['posts'])==a['ledger']['by_state'][state]['rows'],
            'LEDGER_STATE_RECONCILIATION_FAILED')
        same_money(sum(p['ledger']['by_state'][state]['value_rub_sum'] for p in a['posts']),
            a['ledger']['by_state'][state]['value_rub_sum'])
    for prefix in ('api_accepted','processed'):
        need(sum(p['ledger'][prefix+'_rows'] for p in a['posts'])==a['ledger'][prefix+'_rows'],
            'LEDGER_SUBSET_RECONCILIATION_FAILED')
        same_money(sum(p['ledger'][prefix+'_value_rub_sum'] for p in a['posts']),
            a['ledger'][prefix+'_value_rub_sum'])
    for action in PRICE_ACTIONS:
        need(sum(p['ledger']['price_audit']['counts'][action] for p in a['posts'])==a['ledger']['price_audit']['counts'][action],
            'PRICE_AUDIT_COUNT_RECONCILIATION_FAILED')
    for key in ('original_rub_sum','applied_rub_sum','prior_reserved_rub_sum'):
        same_money(sum(p['ledger']['price_audit'][key] for p in a['posts']),a['ledger']['price_audit'][key])
    no_private_fields(report)
    return report

# END BYTE-EQUIVALENT ACCEPTED AGGREGATE VALIDATOR

APP_BRIDGE_SEMANTICS = {
    'entry_selection': 'same_accepted_registry_window_and_current_latest_profile_access_fence',
    'profile_selection': 'all_nonambiguous_exact_price_linked_frozen_profiles_within_entry_24h',
    'earliest_flag': 'earliest_valid_exact_price_linked_profile_per_subject_scoped_anchor',
    'identity_source': 'actual_saved_outbox_max_tracker_binding_not_new_lookup',
    'identity_agreement_is_visit_or_causal_attribution': False,
    'native_actor_only_is_exact_client_agreement': False,
    'source_clock': 'saved_tracker_clock_interpreted_by_installed_policy_not_native_action',
    'ledger_lifecycle': 'current_saved_rows_as_of_read_not_entry_24h_state',
    'profile_values_and_ledger_rows_additive': False,
    'repeated_tracker_ids_are_distinct_visits': False,
    'raw_customer_identity_exported': False,
    'raw_customer_text_exported': False,
    'yclid_projection': 'not_read_from_app_outbox',
    'decryption': 'local_private_key_memory_only_after_outer_authentication',
}
APP_BRIDGE_LIMITS = {
    'max_entries': 10000, 'max_profiles': 10000, 'max_ledger_rows': 10000,
    'max_tuple_bytes': 342, 'ciphertext_bytes': 384,
    'max_selected_bytes': 4194304, 'max_output_bytes': 4194304,
}
APP_COVERAGE_COUNTS = (
    'current_allowed_max_entries', 'entries_invalid_native_actor',
    'nonambiguous_max_profiles', 'selected_earliest_max_profiles',
    'ambiguous_max_profiles_excluded', 'max_ledger_rows',
    'identity_source_mismatch_rows', 'ledger_identity_present',
    'ledger_identity_missing', 'ledger_identity_invalid', 'ledger_invalid_native_actor',
    'tracker_record_present', 'tracker_record_missing_or_invalid',
    'tracker_clock_valid', 'tracker_clock_unknown', 'tracker_clock_after_signal',
)
APP_ENTRY_FIELDS = (
    'entry_ref', 'platform', 'channel_id', 'publication_id', 'version_id',
    'entered_at_utc', 'basis', 'identity_state', 'identity_capsule_b64',
)
APP_PROFILE_FIELDS = (
    'value_profile_ref', 'entry_ref', 'anchor_ref', 'publication_id', 'version_id',
    'channel_id', 'selected_earliest', 'monetary_version', 'monetary_rules_sha256',
    'semantic_version', 'model_sha256', 'deal_digest', 'frozen_at', 'input_cutoff_at',
    'profile_prediction_at', 'age_seconds', 'early_window', 'forecast_status',
    'estimated_probability', 'saved_value_rub', 'saved_base_rub',
    'commission_estimate_rub', 'price',
)
APP_LEDGER_FIELDS = (
    'outbox_ref', 'value_profile_ref', 'entry_ref', 'anchor_ref', 'publication_id',
    'version_id', 'channel_id', 'selected_earliest_profile', 'counter_id',
    'identity_source_key', 'tracker_record_id', 'tracker_observed_unix',
    'tracker_clock_state', 'event_unix', 'target_kind', 'state', 'value_rub',
    'api_accepted_at_unix', 'processed_at_unix', 'basis', 'identity_state',
    'identity_capsule_b64',
)
APP_MONETARY_RULES = {
    'early_deal_commission_7pct_v1': 'f4f205793ce51a18f7268292df3e0362b152869d60c71a5bc9c62017ca136593',
    'early_deal_commission_7pct_v2': '8a35e248d8237b9042d6aa94eca975c00aae8ae2dc6b3ef42c61b50ee4273669',
    'early_deal_commission_7pct_v3': '1e57fdf4a06c20e5136c968418a151d46aba8516e7f249ebd7c2f705325933da',
}
APP_ERROR_CODES = frozenset((
    'runtime_changed', 'runtime_dirty', 'source_changed', 'mysql_required',
    'outer_transaction', 'unsupported_server_version', 'followup_not_mature',
    'goal_scope_changed', 'wall_budget', 'selected_byte_limit', 'output_byte_limit',
    'query_scope', 'row_limit_incomplete', 'entry_limit_incomplete',
    'profile_limit_incomplete', 'journal_limit_incomplete', 'ledger_limit_incomplete',
    'current_access_limit_incomplete', 'current_access_binding', 'entry_duplicate',
    'entry_clock', 'entry_binding', 'entry_reconciliation', 'profile_binding',
    'ledger_duplicate', 'capped_primary_plan', 'explain_shape', 'explain_rows_unknown',
    'explain_dependent', 'unbounded_base_access', 'explain_full_scan',
    'explain_cost_or_missing_base', 'report_public_key_invalid',
    'identity_capsule_size', 'identity_capsule_encrypt', 'identity_scope_mismatch',
    'bridge_reconciliation', 'bounded_read_stopped',
))


def _app_literal(value, expected, code):
    """Unlike Python dict equality, do not accept 0/1 for false/true or floats for ints."""
    need(type(value) is type(expected), code)
    if isinstance(expected, dict):
        fields(value, expected, code)
        for key, item in expected.items():
            _app_literal(value[key], item, code)
    else:
        need(value == expected, code)


def _app_hash(value):
    need(isinstance(value, str) and re.fullmatch('[a-f0-9]{64}', value) is not None,
         'APP_DIGEST_INVALID')
    return value


def _app_iso(value):
    need(isinstance(value, str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z', value) is not None,
         'APP_CLOCK_INVALID')
    return int(dt.datetime.fromisoformat(value.replace('Z', '+00:00')).timestamp())


def _app_epoch(value, now):
    need(type(value) is int and 946684800 <= value <= now, 'APP_EPOCH_INVALID')
    return value


def _app_capsule(value, required):
    if not required:
        need(value is None, 'APP_CAPSULE_MUST_BE_NULL')
        return
    need(isinstance(value, str) and len(value) == 512, 'APP_CAPSULE_INVALID')
    raw = base64.b64decode(value, validate=True)
    need(len(raw) == 384 and base64.b64encode(raw).decode('ascii') == value, 'APP_CAPSULE_INVALID')


def _app_post_key(row):
    need(all(type(row[k]) is int and 0 < row[k] < 10**19 for k in ('publication_id', 'version_id')),
         'APP_PUBLICATION_ID_INVALID')
    need(isinstance(row['channel_id'], str) and re.fullmatch('-?[0-9]{1,64}', row['channel_id']) is not None,
         'APP_PUBLIC_CHANNEL_INVALID')
    return ('max', row['channel_id'], row['publication_id'], row['version_id'])


def _app_cents(value):
    money(value)
    return round(value * 100)


def _app_forecast_group(rows, expected):
    """Reconcile exported frozen values, never generate a replacement forecast."""
    need(len(rows) == expected['anchors'], 'APP_FORECAST_ANCHORS_MISMATCH')
    estimated = [p for p in rows if p['forecast_status'] == 'estimated']
    need(len(estimated) == expected['estimated_count'], 'APP_FORECAST_ESTIMATED_MISMATCH')
    need(sum(p['saved_base_rub'] for p in rows) == expected['base_rub_sum'], 'APP_FORECAST_BASE_MISMATCH')
    if estimated:
        probability = sum(p['estimated_probability'] for p in estimated)
        need(abs(probability - expected['probability_sum']) <= 0.000001,
             'APP_FORECAST_PROBABILITY_MISMATCH')
        # The PHP aggregate rounds its sum and then its mean to six decimal places.
        need(abs(expected['probability_sum'] / len(estimated) - expected['probability_mean']) <= 0.000001,
             'APP_FORECAST_PROBABILITY_MISMATCH')
        need(sum(p['saved_value_rub'] for p in estimated) == expected['frozen_value_rub_sum'],
             'APP_FORECAST_VALUE_MISMATCH')
    else:
        need(all(expected[k] is None for k in ('probability_sum', 'probability_mean', 'frozen_value_rub_sum')),
             'APP_FORECAST_UNKNOWN_MISMATCH')
    for suffix, fn in (('min', min), ('max', max)):
        need(fn(p['price']['point'] for p in rows) == expected['deal_point_' + suffix + '_rub'],
             'APP_FORECAST_PRICE_MISMATCH')
        need(fn(p['age_seconds'] for p in rows) == expected['age_seconds_' + suffix], 'APP_FORECAST_AGE_MISMATCH')
    bands = dict.fromkeys(AGE_BANDS, 0)
    for p in rows:
        bands[p['early_window']] += 1
    need(bands == expected['age_bands'], 'APP_FORECAST_BANDS_MISMATCH')
    need(min(p['frozen_at'] for p in rows) == expected['first_frozen_at']
         and max(p['frozen_at'] for p in rows) == expected['last_frozen_at'], 'APP_FORECAST_CLOCK_MISMATCH')


def _app_validate_success(report):
    fields(report, ('ok', 'installed_sha', 'db_writes', 'provider_calls', 'message_sends', 'aggregate', 'bridge'),
           'APP_REPORT_FIELDS_INVALID')
    validate_report({k: v for k, v in report.items() if k != 'bridge'})
    aggregate = report['aggregate']
    _app_literal(aggregate['window'], {**WINDOW, 'business_timezone': 'Europe/Moscow', 'days': 7, 'followup_hours': 24},
                 'APP_WINDOW_MISMATCH')
    _app_literal(aggregate['limits'], LIMITS, 'APP_AGGREGATE_LIMITS_MISMATCH')
    _app_literal(aggregate['semantics'], SEMANTICS, 'APP_AGGREGATE_SEMANTICS_MISMATCH')
    lower = _app_iso('2026-11-11T21:00:00Z')
    upper = _app_iso('2026-11-18T21:00:00Z')
    followup = _app_iso('2026-11-19T21:00:00Z')
    now = _app_iso(aggregate['checked_at_utc'])
    need(now >= followup, 'APP_FOLLOWUP_NOT_MATURE')
    bridge = report['bridge']
    fields(bridge, ('schema', 'inner_scheme', 'rsa_bits', 'identity_domain', 'coverage',
                    'entries', 'profiles', 'ledger_rows', 'semantics', 'limits'), 'APP_BRIDGE_FIELDS_INVALID')
    for key, expected in {'schema': 'max2_hotel_app_identity_bridge_v1', 'inner_scheme': 'RSA-OAEP-SHA1',
                          'rsa_bits': 3072, 'identity_domain': 'max2_hotel_identity_v1'}.items():
        _app_literal(bridge[key], expected, 'APP_BRIDGE_CONTRACT_INVALID')
    _app_literal(bridge['semantics'], APP_BRIDGE_SEMANTICS, 'APP_BRIDGE_SEMANTICS_MISMATCH')
    _app_literal(bridge['limits'], APP_BRIDGE_LIMITS, 'APP_BRIDGE_LIMITS_MISMATCH')
    coverage = bridge['coverage']
    fields(coverage, (*APP_COVERAGE_COUNTS, 'max_ledger_value_rub_sum'), 'APP_COVERAGE_FIELDS_INVALID')
    for key in APP_COVERAGE_COUNTS:
        count(coverage[key], 10000)
    money(coverage['max_ledger_value_rub_sum'])
    for key, limit in (('entries', 'max_entries'), ('profiles', 'max_profiles'), ('ledger_rows', 'max_ledger_rows')):
        need(isinstance(bridge[key], list) and len(bridge[key]) <= APP_BRIDGE_LIMITS[limit], 'APP_ROW_BOUND')
    posts = {_app_post_key(p): p for p in aggregate['posts'] if p['platform'] == 'max'}
    observed = Counter()
    entries = {}
    entry_counts = Counter()
    entry_times = {}
    for row in bridge['entries']:
        fields(row, APP_ENTRY_FIELDS, 'APP_ENTRY_FIELDS_INVALID')
        _app_literal(row['platform'], 'max', 'APP_ENTRY_PLATFORM_INVALID')
        ref = _app_hash(row['entry_ref'])
        need(ref not in entries, 'APP_DUPLICATE_ENTRY')
        key = _app_post_key(row)
        need(key in posts, 'APP_ENTRY_POST_MISSING')
        when = _app_iso(row['entered_at_utc'])
        need(lower <= when < upper, 'APP_ENTRY_WINDOW_INVALID')
        _app_literal(row['basis'], 'native_actor_only', 'APP_ENTRY_IDENTITY_INVALID')
        need(row['identity_state'] in ('not_requested', 'invalid_native_actor'), 'APP_ENTRY_IDENTITY_INVALID')
        _app_capsule(row['identity_capsule_b64'], row['identity_state'] == 'not_requested')
        if row['identity_state'] == 'invalid_native_actor':
            observed['entries_invalid_native_actor'] += 1
        entries[ref] = row
        entry_times[ref] = when
        entry_counts[key] += 1
    observed['current_allowed_max_entries'] = len(entries)

    profiles = {}
    profile_counts = Counter()
    linked_entries = defaultdict(set)
    anchors = defaultdict(list)
    selected_groups = defaultdict(list)
    for row in bridge['profiles']:
        fields(row, APP_PROFILE_FIELDS, 'APP_PROFILE_FIELDS_INVALID')
        for key in ('value_profile_ref', 'entry_ref', 'anchor_ref', 'monetary_rules_sha256', 'model_sha256', 'deal_digest'):
            _app_hash(row[key])
        ref = row['value_profile_ref']
        need(ref not in profiles, 'APP_DUPLICATE_PROFILE')
        need(row['entry_ref'] in entries, 'APP_PROFILE_ENTRY_MISSING')
        key = _app_post_key(row)
        need(key == _app_post_key(entries[row['entry_ref']]), 'APP_PROFILE_POST_MISMATCH')
        need(type(row['selected_earliest']) is bool, 'APP_PROFILE_SELECTION_INVALID')
        need(row['monetary_version'] in MONETARY_VERSIONS
             and row['monetary_rules_sha256'] == APP_MONETARY_RULES[row['monetary_version']]
             and row['semantic_version'] == 'semantic_purchase_heuristic_v1', 'APP_PROFILE_MODEL_INVALID')
        frozen = _app_iso(row['frozen_at'])
        cutoff = _app_iso(row['input_cutoff_at'])
        prediction = _app_iso(row['profile_prediction_at'])
        age = count(row['age_seconds'], 86400)
        entered = entry_times[row['entry_ref']]
        need(entered <= frozen - age <= cutoff <= prediction <= frozen < entered + 86400
             and frozen < followup, 'APP_PROFILE_WINDOW_INVALID')
        band = 'first_hour' if age <= 3600 else ('first_three_hours' if age <= 10800 else 'first_day')
        need(row['early_window'] == band, 'APP_PROFILE_AGE_BAND_INVALID')
        price = row['price']
        fields(price, ('low', 'high', 'point', 'basis', 'source_kind', 'unit', 'price_verified',
                       'content_sha256', 'exact_frozen_entry_price_link'), 'APP_PRICE_FIELDS_INVALID')
        _app_hash(price['content_sha256'])
        for field in ('low', 'high', 'point'):
            value = price[field]
            need((value is None and field != 'point') or type(value) is int and 10000 <= value <= 10000000,
                 'APP_PRICE_AMOUNT_INVALID')
        need(price['low'] is not None or price['high'] is not None, 'APP_PRICE_RANGE_INVALID')
        need((price['low'] is None or price['low'] <= price['point'])
             and (price['high'] is None or price['point'] <= price['high']), 'APP_PRICE_RANGE_INVALID')
        need(price['basis'] in ('advertised_floor_proxy', 'advertised_range_proxy'), 'APP_PRICE_BASIS_INVALID')
        for field, expected in {'source_kind': 'channel_post_range', 'unit': 'party_total',
                                'price_verified': False, 'exact_frozen_entry_price_link': True}.items():
            _app_literal(price[field], expected, 'APP_PRICE_CONTRACT_INVALID')
        commission = (price['point'] * 7 + 50) // 100
        cap = 25000 if row['monetary_version'] == 'early_deal_commission_7pct_v1' else 100000
        need(type(row['commission_estimate_rub']) is int and row['commission_estimate_rub'] == commission
             and type(row['saved_base_rub']) is int and row['saved_base_rub'] == min(cap, commission),
             'APP_SAVED_MONETARY_CONTRACT_INVALID')
        need(row['forecast_status'] in ('estimated', 'unavailable'), 'APP_FORECAST_STATUS_INVALID')
        if row['forecast_status'] == 'estimated':
            probability = row['estimated_probability']
            need(type(probability) in (int, float) and math.isfinite(probability) and 0 < probability <= 1,
                 'APP_PROBABILITY_INVALID')
            count(row['saved_value_rub'], cap)
            need(row['saved_value_rub'] == math.floor(probability * row['saved_base_rub'] + 0.5),
                 'APP_SAVED_VALUE_CONTRACT_INVALID')
        else:
            need(row['estimated_probability'] is None and row['saved_value_rub'] is None, 'APP_UNKNOWN_VALUE_NOT_NULL')
        profiles[ref] = row
        profile_counts[key] += 1
        linked_entries[key].add(row['entry_ref'])
        anchors[row['anchor_ref']].append(row)
        if row['selected_earliest']:
            group = (key, row['monetary_version'], row['semantic_version'], row['model_sha256'])
            selected_groups[group].append(row)
    for rows in anchors.values():
        selected = [p for p in rows if p['selected_earliest']]
        need(len({p['entry_ref'] for p in rows}) == 1 and len(selected) == 1, 'APP_ANCHOR_AMBIGUOUS_OR_DUPLICATED')
        need(selected[0]['frozen_at'] == min(p['frozen_at'] for p in rows), 'APP_EARLIEST_PROFILE_MISMATCH')
    observed['nonambiguous_max_profiles'] = len(profiles)
    observed['selected_earliest_max_profiles'] = len(anchors)
    exact_total = sum(p['exact_linked_profiles'] for p in posts.values())
    observed['ambiguous_max_profiles_excluded'] = exact_total - len(profiles)
    need(0 <= observed['ambiguous_max_profiles_excluded']
         <= aggregate['coverage']['exact_linked_profiles'] - aggregate['coverage']['selected_request_anchors']
            - aggregate['coverage']['duplicate_profiles_same_anchor'], 'APP_AMBIGUOUS_PROFILE_MISMATCH')
    need(len(profiles) - len(anchors) <= aggregate['coverage']['duplicate_profiles_same_anchor'],
         'APP_DUPLICATE_PROFILE_COUNT_MISMATCH')
    expected_groups = {}
    for key, post in posts.items():
        need(entry_counts[key] == post['admitted_entries'], 'APP_ENTRY_COUNT_MISMATCH')
        missing_profiles = post['exact_linked_profiles'] - profile_counts[key]
        missing_entries = post['exact_linked_entries'] - len(linked_entries[key])
        need(0 <= missing_entries <= missing_profiles, 'APP_LINKED_COVERAGE_MISMATCH')
        for group in post['forecasts']:
            group_key = (key, group['monetary_version'], group['semantic_version'], group['model_sha256'])
            expected_groups[group_key] = group
    need(set(selected_groups) == set(expected_groups), 'APP_FORECAST_GROUPS_MISMATCH')
    for key, rows in selected_groups.items():
        _app_forecast_group(rows, expected_groups[key])

    ledger_by_post = defaultdict(list)
    outboxes = set()
    ledger_cents = 0
    for row in bridge['ledger_rows']:
        fields(row, APP_LEDGER_FIELDS, 'APP_LEDGER_FIELDS_INVALID')
        for key in ('outbox_ref', 'value_profile_ref', 'entry_ref', 'anchor_ref'):
            _app_hash(row[key])
        need(row['outbox_ref'] not in outboxes, 'APP_DUPLICATE_OUTBOX')
        outboxes.add(row['outbox_ref'])
        need(row['value_profile_ref'] in profiles, 'APP_LEDGER_PROFILE_MISSING')
        profile = profiles[row['value_profile_ref']]
        key = _app_post_key(row)
        need(key == _app_post_key(profile) and row['entry_ref'] == profile['entry_ref']
             and row['anchor_ref'] == profile['anchor_ref'], 'APP_LEDGER_BINDING_MISMATCH')
        _app_literal(row['selected_earliest_profile'], profile['selected_earliest'], 'APP_LEDGER_SELECTION_MISMATCH')
        _app_literal(row['counter_id'], '98615635', 'APP_COUNTER_INVALID')
        need(row['identity_source_key'] in ('max_tracker', 'unavailable'), 'APP_IDENTITY_SOURCE_INVALID')
        event = _app_epoch(row['event_unix'], now)
        need(event >= entry_times[row['entry_ref']], 'APP_LEDGER_EVENT_WINDOW_INVALID')
        need(row['target_kind'] in ('profile_prediction', 'legacy_stage') and row['state'] in STATES,
             'APP_LEDGER_ENUM_INVALID')
        cents = _app_cents(row['value_rub'])
        need(0 < cents <= 10000000, 'APP_LEDGER_VALUE_INVALID')
        for field in ('api_accepted_at_unix', 'processed_at_unix', 'tracker_observed_unix'):
            if row[field] is not None:
                _app_epoch(row[field], now)
        tracker = row['tracker_record_id']
        need(tracker is None or isinstance(tracker, str) and re.fullmatch('[1-9][0-9]{0,19}', tracker) is not None,
             'APP_TRACKER_RECORD_INVALID')
        clock = row['tracker_observed_unix']
        expected_clock = 'unknown' if clock is None else ('after_signal' if clock > event else 'at_or_before_signal')
        need(row['tracker_clock_state'] == expected_clock, 'APP_TRACKER_CLOCK_STATE_INVALID')
        identity = row['identity_state']
        if row['identity_source_key'] == 'unavailable':
            need(identity == 'identity_source_mismatch' and row['basis'] == 'unavailable'
                 and tracker is None and clock is None, 'APP_UNAVAILABLE_IDENTITY_INVALID')
            _app_capsule(row['identity_capsule_b64'], False)
            observed['identity_source_mismatch_rows'] += 1
        else:
            need(identity in ('present', 'missing', 'invalid', 'invalid_native_actor'), 'APP_IDENTITY_STATE_INVALID')
            invalid_actor = entries[row['entry_ref']]['identity_state'] == 'invalid_native_actor'
            need((identity == 'invalid_native_actor') == invalid_actor, 'APP_NATIVE_ACTOR_STATE_MISMATCH')
            if invalid_actor:
                need(row['basis'] in ('native_actor_only', 'native_actor_and_client'), 'APP_IDENTITY_BASIS_INVALID')
                _app_capsule(row['identity_capsule_b64'], False)
                observed['ledger_invalid_native_actor'] += 1
            else:
                expected_basis = 'native_actor_and_client' if identity == 'present' else 'native_actor_only'
                need(row['basis'] == expected_basis, 'APP_IDENTITY_BASIS_INVALID')
                _app_capsule(row['identity_capsule_b64'], True)
                observed['ledger_identity_' + identity] += 1
        observed['tracker_record_present' if tracker is not None else 'tracker_record_missing_or_invalid'] += 1
        observed['tracker_clock_valid' if clock is not None else 'tracker_clock_unknown'] += 1
        if expected_clock == 'after_signal':
            observed['tracker_clock_after_signal'] += 1
        ledger_cents += cents
        ledger_by_post[key].append(row)
    observed['max_ledger_rows'] = len(outboxes)
    need(ledger_cents == _app_cents(coverage['max_ledger_value_rub_sum']), 'APP_LEDGER_TOTAL_MISMATCH')
    for key in APP_COVERAGE_COUNTS:
        need(coverage[key] == observed[key], 'APP_COVERAGE_RECONCILIATION_FAILED')
    for key, post in posts.items():
        rows, expected = ledger_by_post[key], post['ledger']
        need(len(rows) == expected['rows']
             and sum(_app_cents(r['value_rub']) for r in rows) == _app_cents(expected['value_rub_sum']),
             'APP_POST_LEDGER_TOTAL_MISMATCH')
        for state in STATES:
            subset = [r for r in rows if r['state'] == state]
            need(len(subset) == expected['by_state'][state]['rows']
                 and sum(_app_cents(r['value_rub']) for r in subset) == _app_cents(expected['by_state'][state]['value_rub_sum']),
                 'APP_POST_LEDGER_STATE_MISMATCH')
        for prefix, field in (('api_accepted', 'api_accepted_at_unix'), ('processed', 'processed_at_unix')):
            subset = [r for r in rows if r[field] is not None]
            need(len(subset) == expected[prefix + '_rows']
                 and sum(_app_cents(r['value_rub']) for r in subset) == _app_cents(expected[prefix + '_value_rub_sum']),
                 'APP_POST_LEDGER_LIFECYCLE_MISMATCH')


def validate_app(report):
    """Reject unknown keys/strings, bad crypto shapes, drift, and unreconciled rows."""
    try:
        need(isinstance(report, dict) and type(report.get('ok')) is bool, 'APP_REPORT_SHAPE_INVALID')
        if report['ok'] is False:
            fields(report, ('ok', 'error', 'db_writes', 'provider_calls', 'message_sends'), 'APP_ERROR_FIELDS_INVALID')
            need(isinstance(report['error'], str) and report['error'] in APP_ERROR_CODES, 'APP_ERROR_CODE_INVALID')
            for field in ('db_writes', 'provider_calls', 'message_sends'):
                _app_literal(report[field], 0, 'APP_SIDE_EFFECT_FLAGS_INVALID')
        else:
            _app_validate_success(report)
        need(len(json.dumps(report, ensure_ascii=False, allow_nan=False, separators=(',', ':')).encode('utf-8')) <= 4194304,
             'APP_OUTPUT_BYTE_LIMIT')
        return report
    except Stop:
        raise
    except (KeyError, TypeError, ValueError, OverflowError, RecursionError):
        raise Stop('APP_REPORT_INVALID') from None
