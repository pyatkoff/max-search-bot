"""One admitted read of two immutable HOLD records; no retry or business action."""
from __future__ import annotations

import base64
import hashlib
import json
import os
from pathlib import Path
import re
import resource
import subprocess
import sys
import tempfile
import time

REPO = 'pyatkoff/max-search-bot'
BRANCH = 'ops/max2-hold-pair-read-20261123'
OPERATION = 'max2_hold_pair_20261123'
PACKAGE = Path('tools/max2_hold_pair_read_20261123')
HOST_PIN = 'SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0'
APP = '/var/www/anytoour/data/www/app.anytoour.ru'
MAX_OUTPUT = 32768
FILES = ['diagnose.php', 'read-pair.php', 'program.php', 'runner.py']
GATES = {'WRAPPER_SCOPE': 'source_structure', 'CONTEXTUAL_SIGNAL_SCHEMA': 'source_structure', 'EXTRACTOR_SOURCE': 'source_provenance', 'PRE_DEPARTURE_PRODUCER_TUPLE': 'source_provenance', 'LEGACY_PRODUCER_TUPLE': 'source_provenance', 'FROZEN_TIME_FORMAT': 'time_inconsistent', 'CUTOFF_TIME_FORMAT': 'time_inconsistent', 'CUTOFF_AFTER_FREEZE': 'time_inconsistent', 'FREEZE_AFTER_EVALUATION': 'time_inconsistent', 'FREEZE_BEFORE_ACTIVATION': 'time_prospective', 'OBSERVATION_INPUT_SCHEMA': 'source_structure', 'LEGACY_PREDICTION_CAPSULE': 'prediction_capsule', 'SAVED_MONEY_CAPSULE': 'money_capsule', 'PAYMENT_HOLD': 'contrary_outcome', 'ADVERTISING_PREDICTION_CAPSULE': 'prediction_capsule', 'MONEY_NOT_ESTIMATED': 'money_capsule', 'ADVERTISING_STAGE_UNSUPPORTED': 'prediction_capsule', 'ADVERTISING_ANCHOR_UNRESOLVED': 'source_structure', 'ADVERTISING_ANCHOR_ROLE': 'source_structure', 'ADVERTISING_ANCHOR_BEFORE_ACTIVATION': 'time_prospective', 'CONTRARY_EVENT': 'contrary_outcome', 'NO_ELIGIBLE_SIGNAL': 'no_admitted_estimate', 'CANDIDATE_AVAILABLE': 'admitted'}
BOOLEAN_CHECKS = ['access_allowed', 'wrapper_subject_matches', 'project_anytour', 'platform_supported', 'contextual_version_matches', 'events_are_array', 'events_within_bound', 'extractor_matches', 'adapter_current', 'adapter_pre_departure', 'producer_legacy_path', 'legacy_adapter_matches', 'legacy_extractor_matches', 'legacy_model_matches', 'legacy_advertising_mode', 'legacy_money_version_matches', 'legacy_frozen_matches', 'legacy_cutoff_matches', 'frozen_time_valid', 'cutoff_time_valid', 'cutoff_not_after_freeze', 'freeze_not_after_evaluation', 'freeze_since_activation', 'observation_subject_matches', 'evidence_is_array', 'evidence_within_bound', 'contrary_event_present', 'semantic_capsule_available', 'prediction_is_array', 'prediction_status_predicted', 'prediction_subject_matches', 'prediction_model_admitted', 'prediction_digest_checked', 'prediction_digest_valid', 'prediction_stage_supported', 'prediction_anchor_found', 'prediction_anchor_customer', 'prediction_anchor_since_activation', 'prediction_freeze_matches', 'money_capsule_present', 'money_capsule_valid', 'payment_hold_observed', 'money_status_estimated', 'prediction_missing', 'prediction_insufficient_context', 'prediction_known_outcome', 'prediction_access_denied', 'money_object_present', 'money_prediction_present', 'money_size_valid', 'money_version_string', 'money_policy_supported', 'money_rules_match', 'money_subject_matches', 'money_prediction_digest_matches', 'money_commission_rate_matches', 'money_currency_matches', 'money_freeze_matches', 'money_cutoff_matches', 'money_anchor_matches', 'money_payment_hold_boolean', 'money_digest_string', 'money_fields_exact', 'money_fixed_metadata_valid', 'money_deal_shape_valid', 'money_source_refs_valid', 'money_digest_valid', 'money_base_arithmetic_valid', 'money_probability_binding_valid', 'money_amount_arithmetic_valid']
COUNT_CHECKS = ['evidence_rows', 'evidence_scope_rejected', 'evidence_reference_count_rejected', 'evidence_reference_format_rejected', 'evidence_time_rejected', 'evidence_admitted', 'event_rows', 'events_without_admitted_reference', 'events_before_activation', 'events_role_or_product_rejected', 'events_without_value_stage', 'positive_event_candidates']
READER_FAILURES = ['ACCESS_UNAVAILABLE', 'UNSUPPORTED_STORAGE', 'OUTER_TRANSACTION', 'READ_TIME_BOUND', 'READ_BYTE_BOUND', 'READ_QUERY_BOUND', 'READ_ONLY_SNAPSHOT_UNAVAILABLE', 'SETTINGS_SCOPE_UNPROVED', 'PAIR_NOT_EXACT_OR_NOT_CURRENTLY_ADMITTED', 'ORIGINAL_TICK_UNPROVED', 'ORIGINAL_WORK_UNPROVED', 'PROFILE_SCOPE_OR_BOUND', 'PROFILE_JSON_INVALID', 'PROFILE_NOT_CURRENT_ADMITTED_SOURCE', 'SUBJECT_NOT_ADMITTED', 'CURRENT_ACCESS_DENIED', 'CURRENT_ACCESS_UNAVAILABLE', 'INSTALLED_SOURCE_UNPROVED', 'DIAGNOSIS_UNAVAILABLE', 'DIAGNOSIS_PROJECTION_UNSAFE', 'SAVED_STORAGE_UNAVAILABLE', 'SNAPSHOT_ROLLBACK_UNCONFIRMED']
PROGRAM_FAILURES = ['OPERATION_SCOPE', 'INSTALLED_SOURCE_CHANGED', 'BOOTSTRAP_SOURCE_CHANGED', 'PRIVATE_CONFIG_UNAVAILABLE', 'PRIVATE_CONFIG_CHANGED', 'BOOTSTRAP_OUTPUT', 'PROJECT_UNAVAILABLE', 'STORAGE_UNAVAILABLE', 'NATIVE_OUTPUT_BOUND', 'READ_BUDGET_EXCEEDED', 'OPERATION_UNAVAILABLE']


class Stop(Exception):
    pass


def need(ok, reason):
    if not ok:
        raise Stop(reason)


def keys(value, expected):
    return isinstance(value, dict) and set(value) == set(expected)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def regular(path, limit):
    need(path.is_file() and not path.is_symlink() and 0 < path.stat().st_size <= limit, 'SOURCE_UNAVAILABLE')
    raw = path.read_bytes()
    need(0 < len(raw) <= limit, 'SOURCE_UNAVAILABLE')
    return raw


def parse(raw):
    def pairs(items):
        value = {}
        for key, item in items:
            need(key not in value, 'DUPLICATE_JSON_KEY')
            value[key] = item
        return value
    return json.loads(raw, object_pairs_hook=pairs, parse_constant=lambda value: (_ for _ in ()).throw(Stop('NONFINITE_JSON')))


def utc(value):
    if not isinstance(value, str) or re.fullmatch(r'20\d\d-\d\d-\d\dT\d\d:\d\d:\d\dZ', value) is None:
        return False
    try:
        return time.strftime('%Y-%m-%dT%H:%M:%SZ', time.strptime(value, '%Y-%m-%dT%H:%M:%SZ')) == value
    except ValueError:
        return False


def checked_diagnosis(value):
    need(keys(value, ['version','temporal_result','original_null_reproduced','first_failed_gate','category',
        'at_tick_start','at_hold_recorded','canonical_parity_verified','source_hashes_verified','input_unchanged',
        'model_calls','provider_requests','writes']), 'DIAGNOSIS_SHAPE')
    need(value['version'] == 'max2_original_two_hold_diagnosis_v1'
        and type(value['original_null_reproduced']) is bool, 'DIAGNOSIS_SHAPE')
    for name in ['canonical_parity_verified','source_hashes_verified','input_unchanged']:
        need(value[name] is True, 'DIAGNOSIS_PROOF')
    for name in ['model_calls','provider_requests','writes']:
        need(type(value[name]) is int and value[name] == 0, 'DIAGNOSIS_SIDE_EFFECT')
    for name in ['at_tick_start','at_hold_recorded']:
        item = value[name]
        need(keys(item, ['canonical_result','first_gate','category','checks'])
            and item['first_gate'] in GATES and GATES[item['first_gate']] == item['category']
            and item['canonical_result'] == ('candidate' if item['first_gate'] == 'CANDIDATE_AVAILABLE' else 'null')
            and isinstance(item['checks'], dict), 'DIAGNOSIS_GATE')
        for key, check in item['checks'].items():
            need((key in BOOLEAN_CHECKS and type(check) is bool)
                or (key in COUNT_CHECKS and type(check) is int and 0 <= check <= 1000), 'DIAGNOSIS_PRIVATE_FIELD')
    left, right = value['at_tick_start'], value['at_hold_recorded']
    if value['temporal_result'] == 'invariant_over_saved_interval':
        need(left == right and value['first_failed_gate'] == left['first_gate'] and value['category'] == left['category']
            and value['original_null_reproduced'] is (left['canonical_result'] == 'null'), 'DIAGNOSIS_TIME_PROOF')
    else:
        need(value['temporal_result'] == 'original_evaluation_time_unresolved'
            and value['first_failed_gate'] == 'EVALUATION_TIME_BOUNDARY'
            and value['category'] == 'time_boundary_unresolved' and value['original_null_reproduced'] is False
            and any(left[key] != right[key] for key in ['canonical_result','first_gate','category']), 'DIAGNOSIS_TIME_PROOF')


def checked_result(value):
    base = ['version','status','fixed_historical_pair','writes','provider_requests','model_calls','worker_calls',
        'conversion_uploads','financial_delivery_permission']
    need(isinstance(value, dict) and value.get('version') == 'max2_original_hold_pair_read_v1'
        and value.get('fixed_historical_pair') is True and value.get('financial_delivery_permission') is False, 'RESULT_SCOPE')
    for name in ['writes','provider_requests','model_calls','worker_calls','conversion_uploads']:
        need(type(value.get(name)) is int and value[name] == 0, 'RESULT_SIDE_EFFECT')
    if value['status'] == 'UNAVAILABLE':
        need(keys(value, base + ['reason']) and value['reason'] in READER_FAILURES, 'RESULT_FAILURE')
        return
    need(keys(value, base + ['activation_at','selected_profiles','cases','read_only_snapshot','snapshot_rolled_back',
        'queries','selected_bytes','current_access_rechecked_after_snapshot','installed_source_hashes_verified'])
        and value['status'] == 'OBSERVED' and value['activation_at'] == '2026-11-22T22:39:36Z'
        and type(value['selected_profiles']) is int and value['selected_profiles'] == 2
        and value['read_only_snapshot'] is True and value['snapshot_rolled_back'] is True
        and value['current_access_rechecked_after_snapshot'] is True and value['installed_source_hashes_verified'] is True
        and type(value['queries']) is int and value['queries'] == 8
        and type(value['selected_bytes']) is int and 0 < value['selected_bytes'] <= 278528
        and isinstance(value['cases'], list) and len(value['cases']) == 2, 'RESULT_PAIR')
    for index, item in enumerate(value['cases']):
        need(keys(item, ['case','saved_outcome','saved_reason','tick_started_at','hold_recorded_at',
            'tick_finished_at','current_native_scope_admitted','profile_source_admitted','diagnosis'])
            and item['case'] == ['A','B'][index] and item['saved_outcome'] == 'HOLD'
            and item['saved_reason'] == 'SAVED_VALUE_UNAVAILABLE' and item['current_native_scope_admitted'] is True
            and item['profile_source_admitted'] is True
            and item['tick_finished_at'] == ['2026-11-23T02:23:03Z','2026-11-23T02:28:05Z'][index]
            and utc(item['tick_started_at']) and utc(item['hold_recorded_at'])
            and item['tick_started_at'] <= item['hold_recorded_at'] <= item['tick_finished_at'], 'RESULT_CASE')
        checked_diagnosis(item['diagnosis'])


def checked_report(raw, application_sha):
    need(0 < len(raw) <= MAX_OUTPUT, 'NATIVE_OUTPUT_BOUND')
    value = parse(raw)
    need(isinstance(value, dict) and value.get('version') == 'max2_original_hold_pair_operation_v1'
        and value.get('operation') == OPERATION and value.get('automatic_retry') is False
        and value.get('worker_invoked') is False and value.get('v1_invoked') is False
        and type(value.get('provider_requests')) is int and value['provider_requests'] == 0, 'NATIVE_REPORT_SCOPE')
    common = ['version','operation','automatic_retry','worker_invoked','v1_invoked','provider_requests']
    if value.get('status') == 'UNAVAILABLE':
        need(keys(value, common + ['status','reason']) and value['reason'] in PROGRAM_FAILURES, 'NATIVE_FAILURE_SHAPE')
        return value
    need(keys(value, common + ['application_sha','observed_at_utc','result'])
        and value['application_sha'] == application_sha and utc(value['observed_at_utc']), 'NATIVE_PROVENANCE')
    checked_result(value['result'])
    return value


def source_pins():
    return {name: sha(regular(PACKAGE / name, 131072)) for name in FILES}


def resolve(env):
    need(env.get('GITHUB_REPOSITORY') == REPO and env.get('GITHUB_REF') == 'refs/heads/' + BRANCH
        and env.get('GITHUB_JOB') == 'diagnose' and env.get('GITHUB_EVENT_NAME') == 'push'
        and env.get('GITHUB_RUN_ATTEMPT') == '1', 'OPERATION_CONTEXT')
    result = subprocess.run(['node','tools/deployment_release.js','resolve-consumer'],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=55, check=False)
    need(result.returncode == 0 and len(result.stdout) <= 32768, 'CANONICAL_ADMISSION_UNAVAILABLE')
    values = {}
    for line in result.stdout.decode('utf-8').splitlines():
        if '=' not in line:
            continue
        key, value = line.split('=', 1)
        need(key not in values, 'CANONICAL_ADMISSION_DUPLICATE')
        values[key] = value
    need(values.get('ready') == 'true' and values.get('perform_actions') == 'true'
        and values.get('read_operation') == OPERATION and values.get('repository_sha') == env.get('GITHUB_SHA')
        and re.fullmatch('[a-f0-9]{40}', values.get('application_sha', '')) is not None, 'CANONICAL_ADMISSION_SCOPE')
    head = subprocess.run(['git','rev-parse','HEAD'], capture_output=True, check=True, timeout=10).stdout.decode().strip()
    dirty = subprocess.run(['git','status','--porcelain','--untracked-files=no'], capture_output=True, check=True, timeout=10).stdout
    need(head == env['GITHUB_SHA'] and dirty == b'', 'CHECKOUT_CHANGED')
    return {key: values[key] for key in ['repository_sha','application_sha','read_operation']}


def bundle(application_sha):
    need(re.fullmatch('[a-f0-9]{40}', application_sha) is not None, 'APPLICATION_SHA')
    out = '<?php\ndeclare(strict_types=1);\n'
    for name in ['diagnose.php','read-pair.php','program.php']:
        raw = regular(PACKAGE / name, 131072).decode('utf-8')
        prefix = '<?php\ndeclare(strict_types=1);\n'
        need(raw.startswith(prefix) and '?>' not in raw, 'BUNDLE_SOURCE_FORMAT')
        out += raw[len(prefix):] + '\n'
    out += "exit(Max2HoldPairReadOperation::main('" + application_sha + "'));\n"
    need(len(out.encode()) <= 262144, 'BUNDLE_BOUND')
    return out.encode()


def host_lines(raw):
    result = []
    for line in raw.splitlines():
        fields = line.split()
        if len(fields) != 3 or fields[0].startswith('#'):
            continue
        try:
            fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(base64.b64decode(fields[2], validate=True)).digest()).decode().rstrip('=')
        except ValueError:
            continue
        if fingerprint == HOST_PIN:
            result.append(line)
    need(bool(result), 'HOST_CONTINUITY_UNPROVED')
    return '\n'.join(result) + '\n'


def output_limit():
    resource.setrlimit(resource.RLIMIT_FSIZE, (65536, 65536))


def execute(plan, env):
    need(keys(plan, ['schema','run_id','prepared_at','resolution','source_pins'])
        and plan['schema'] == 'max2-original-hold-pair-plan-v1' and plan['run_id'] == env.get('GITHUB_RUN_ID')
        and type(plan['prepared_at']) is int and 0 <= time.time() - plan['prepared_at'] <= 300
        and plan['source_pins'] == source_pins(), 'PLAN_UNPROVED')
    resolution = resolve(env)
    need(resolution == plan['resolution'], 'RELEASE_CHANGED')
    key, host, user = (env.get('STANDBY_DEPLOY_' + name, '') for name in ['SSH_KEY','HOST','USER'])
    need(bool(key) and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.:-]{0,252}', host) is not None
        and re.fullmatch(r'[a-z_][a-z0-9_-]{0,63}', user) is not None, 'EXISTING_CREDENTIAL_REQUIRED')
    key = key.replace('\r', '')
    key = key.replace('\\n', '\n') if 'PRIVATE KEY' in key else base64.b64decode(key, validate=True).decode('ascii')
    raw = bundle(resolution['application_sha'])
    with tempfile.TemporaryDirectory(prefix='max2-hold-read-', dir=env['RUNNER_TEMP']) as name:
        work = Path(name)
        keyfile = work / 'key'; keyfile.write_text(key.rstrip() + '\n'); keyfile.chmod(0o600)
        scan = subprocess.run(['ssh-keyscan','-T','10','-t','ed25519,ecdsa,rsa',host], capture_output=True, text=True, timeout=35)
        known = work / 'known_hosts'; known.write_text(host_lines(scan.stdout))
        # The genuine canonical context is checked again immediately before the only SSH read.
        need(resolve(env) == resolution and plan['source_pins'] == source_pins(), 'ADMISSION_CHANGED')
        disabled = 'curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail,exec,shell_exec,system,passthru,proc_open,popen'
        remote = 'unset GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES; cd ' + APP
        remote += ' && php -d display_errors=0 -d display_startup_errors=0 -d log_errors=0 -d allow_url_fopen=0 -d allow_url_include=0 -d memory_limit=64M -d max_execution_time=10 -d disable_functions=' + disabled
        command = ['ssh','-i',str(keyfile),'-o','IdentitiesOnly=yes','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes',
            '-o','UserKnownHostsFile=' + str(known),'-o','GlobalKnownHostsFile=/dev/null','-o','ConnectTimeout=10',
            '-o','ServerAliveInterval=15','-o','ServerAliveCountMax=2',user + '@' + host,remote]
        with (work / 'stdout').open('wb') as out, (work / 'stderr').open('wb') as err:
            native = subprocess.run(command, input=raw, stdout=out, stderr=err, timeout=45, preexec_fn=output_limit, check=False)
        report = checked_report(regular(work / 'stdout', MAX_OUTPUT), resolution['application_sha'])
        need(native.returncode in [0,2], 'NATIVE_EXIT_UNPROVED')
        observed = report.get('result', {}).get('status') == 'OBSERVED'
        need((native.returncode == 0) is observed, 'NATIVE_EXIT_MISMATCH')
        need(resolve(env) == resolution and plan['source_pins'] == source_pins(), 'POST_READ_SOURCE_CHANGED')
        return report


def main():
    os.umask(0o077)
    need(len(sys.argv) == 2 and sys.argv[1] in ['--prepare','--execute'], 'ARGUMENTS')
    env = dict(os.environ)
    temp = Path(env['RUNNER_TEMP']).resolve(strict=True)
    plan_path = temp / 'max2-original-hold-pair-plan.json'
    result_path = temp / 'max2-original-hold-pair-result.json'
    if sys.argv[1] == '--prepare':
        need(not plan_path.exists(), 'PREPARE_REPLAY_FORBIDDEN')
        plan = {'schema':'max2-original-hold-pair-plan-v1','run_id':env['GITHUB_RUN_ID'],'prepared_at':int(time.time()),
            'resolution':resolve(env),'source_pins':source_pins()}
        plan_path.write_text(json.dumps(plan, separators=(',', ':')))
        print('READ_PREPARED')
        return
    need(not result_path.exists(), 'READ_REPLAY_FORBIDDEN')
    # Local claim exists before the first transport attempt. An uncertain attempt is never retried.
    claim = temp / 'max2-original-hold-pair-attempted'
    with claim.open('x') as stream:
        stream.write(env.get('GITHUB_RUN_ID', ''))
    try:
        report = execute(parse(regular(plan_path, 8192)), env)
        outcome = 'OBSERVED' if report.get('result', {}).get('status') == 'OBSERVED' else 'UNAVAILABLE'
        receipt = {'schema':'max2-original-hold-pair-receipt-v1','run_id':env['GITHUB_RUN_ID'],
            'run_attempt':1,'repository_sha':env['GITHUB_SHA'],'report':report,'automatic_retry':False}
    except Exception:
        outcome = 'UNAVAILABLE'
        receipt = {'schema':'max2-original-hold-pair-receipt-v1','run_id':env.get('GITHUB_RUN_ID',''),
            'run_attempt':1,'repository_sha':env.get('GITHUB_SHA',''),'status':'UNAVAILABLE',
            'reason':'READ_RESULT_UNPROVED','automatic_retry':False}
    raw = json.dumps(receipt, ensure_ascii=True, allow_nan=False, separators=(',', ':')).encode()
    need(len(raw) <= 33792, 'RECEIPT_BOUND')
    result_path.write_bytes(raw)
    print('READ_RESULT=' + outcome)
    print('RECEIPT_SHA256=' + sha(raw))
    if outcome != 'OBSERVED':
        raise Stop('READ_UNAVAILABLE')


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('MAX2_HOLD_READ_SAFE_STOP', file=sys.stderr)
        sys.exit(1)
