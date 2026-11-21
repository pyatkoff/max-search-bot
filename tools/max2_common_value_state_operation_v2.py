#!/usr/bin/env python3
"""Finite operator carrier for the NEW common-value state CLI.

Authorization is the owner's existing two-goal task and reviewed normal release.
Hashes bind source and requests; they do not grant permission. There is no tick,
provider call, alternate credential, installer, migration writer or V1 command.
"""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time

REPOSITORY = 'pyatkoff/max-search-bot'
REPOSITORY_ID = 1342493152
WORKFLOW = '.github/workflows/max2-common-value-state-v2.yml'
RUNNER = 'tools/max2_common_value_state_operation_v2.py'
CLI = 'tools/max2_common_value_state_v2.php'
MANIFEST = 'resources/max2-common-v2/release-manifest.json'
SIDECAR = 'resources/max2-common-v2/sidecar-admission.json'
SIDECAR_SHA = '0adba48cf79defdf7fc83db3ea3e7126c4e578594b601a6df3b7e2014335e1ba'
CONTRACT = '3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e'
TARGET = 'anytour_modeled_value_v2_max2'
SCOPE = {'project_key': 'anytour', 'counter_id': 98615635, 'goal_id': 687941321, 'target': TARGET}
OPERATIONS = ('status', 'activate', 'off', 'collect', 'send')
APP = Path('/var/www/anytoour/data/www/app.anytoour.ru')
TABLES = sorted(['max2_common_value_accounts_v2', 'max2_common_value_events_v2', 'max2_common_settings_v2',
    'max2_common_ticks_v2', 'max2_common_profile_work_v2', 'max2_common_lookup_intents_v2', 'max2_common_entry_frames_v2'])
MIGRATIONS = ('migrations/112_max2_common_value_v2.sql', 'migrations/113_max2_common_runtime_v2.sql')
HEX40 = re.compile(r'[a-f0-9]{40}\Z')
HEX64 = re.compile(r'[a-f0-9]{64}\Z')
_TRANSPORT_ATTEMPTED = False


class Refusal(RuntimeError):
    pass


def need(ok, reason):
    if not ok:
        raise Refusal(reason)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True, allow_nan=False).encode() + b'\n'


def pairs(items):
    out = {}
    for key, value in items:
        need(key not in out, 'DUPLICATE_JSON_KEY')
        out[key] = value
    return out


def parsed(raw, maximum):
    need(isinstance(raw, bytes) and 0 < len(raw) <= maximum, 'JSON_BOUND')
    try:
        return json.loads(raw, object_pairs_hook=pairs, parse_constant=lambda _: (_ for _ in ()).throw(Refusal('INVALID_JSON_NUMBER')))
    except (UnicodeError, ValueError) as error:
        raise Refusal('JSON_UNAVAILABLE') from error


def stamp(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def read_regular(path, maximum):
    need(path.resolve(strict=True) == path, 'SOURCE_PATH_NOT_PHYSICAL')
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and 0 < before.st_size <= maximum, 'SOURCE_FILE_BOUND')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        raw = stream.read(maximum + 1)
        after = os.fstat(stream.fileno())
    need(len(raw) == before.st_size and stamp(before) == stamp(after) == stamp(path.lstat()), 'SOURCE_FILE_CHANGED')
    return raw, stamp(after)


def run_bounded(argv, *, cwd, data=None, timeout=25, limit=65536, runner=subprocess.run):
    try:
        result = runner(argv, cwd=cwd, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                        timeout=timeout, env={'PATH': os.environ.get('PATH', '/usr/bin:/bin')})
    except (subprocess.TimeoutExpired, OSError) as error:
        raise Refusal('CHILD_RESULT_UNKNOWN') from error
    need(isinstance(result.stdout, bytes) and isinstance(result.stderr, bytes)
         and len(result.stdout) <= limit and len(result.stderr) <= limit, 'CHILD_OUTPUT_BOUND')
    return result


def git_head(root, runner=subprocess.run):
    result = run_bounded(['git', '-C', str(root), 'rev-parse', '--verify', 'HEAD'], cwd=root, limit=128, runner=runner)
    need(result.returncode == 0 and result.stderr == b'', 'INSTALLED_HEAD_UNAVAILABLE')
    head = result.stdout.decode('ascii').strip()
    need(HEX40.fullmatch(head), 'INSTALLED_HEAD_UNAVAILABLE')
    return head


def relative(name):
    return isinstance(name, str) and re.fullmatch(r'[A-Za-z0-9_./-]{1,220}', name) is not None \
        and not name.startswith('/') and all(p not in ('', '.', '..') for p in name.split('/'))


def permitted_source(name):
    if not relative(name):
        return False
    return name in {CLI, RUNNER, WORKFLOW, SIDECAR, 'webhook.php', 'cron_metrika.php',
        'services/ChannelPostEntryService.php', 'tools/deployment_release.js',
        'tests/run_deployment_release_regression.js', 'tests/required_checks_manifest.php',
        'tests/run_max2_common_value_v2_regression.php', 'tests/run_max2_common_value_state_operation_v2_regression.py',
        'docs/MAX2_COMMON_VALUE_V2.md', 'resources/max2-common-v2/canonical-map-v1055.json', *MIGRATIONS} \
        or re.fullmatch(r'services/Max2[A-Za-z0-9]*V2\.php', name) is not None \
        or name.startswith('tests/max2_common_v2/')


def source_package(root):
    raw, identity = read_regular(root / MANIFEST, 131072)
    value = parsed(raw, 131072)
    need(isinstance(value, dict) and value.get('schema') == 'max2-common-v2-app-source-v1'
         and value.get('counter_id') == 98615635 and value.get('goal_ids') == [687931224, 687941321]
         and value.get('target') == TARGET and value.get('producer_contract_sha256') == CONTRACT
         and value.get('initial_mode') == 'off', 'EXACT_NEW_RELEASE_MANIFEST_REQUIRED')
    files = value.get('files')
    need(isinstance(files, dict) and 10 <= len(files) <= 768
         and {CLI, RUNNER, WORKFLOW, SIDECAR, 'tools/deployment_release.js', *MIGRATIONS}.issubset(files)
         and all(permitted_source(k) and isinstance(v, str) and HEX64.fullmatch(v) and v != '0' * 64
                 for k, v in files.items()), 'NEW_RELEASE_FILE_SCOPE')
    snapshot = {MANIFEST: identity}
    total = 0
    for name, expected in sorted(files.items()):
        content, file_identity = read_regular(root / name, 2 * 1024 * 1024)
        total += len(content)
        need(total <= 32 * 1024 * 1024 and sha(content) == expected, 'INSTALLED_SOURCE_PIN_CHANGED')
        snapshot[name] = file_identity
    sidecar, _ = read_regular(root / SIDECAR, 16384)
    need(sha(sidecar) == SIDECAR_SHA and files[SIDECAR] == SIDECAR_SHA, 'ACCEPTED_SIDECAR_RESOURCE_REQUIRED')
    dependency = parsed(sidecar, 16384)
    need(dependency.get('schema') == 'max2-common-sidecar-admission-v1'
         and dependency.get('operation') == 'max2-common-value-sidecar-20261121-v1'
         and dependency.get('counter_id') == 98615635 and dependency.get('common_goal_id') == 687941321
         and dependency.get('common_target') == TARGET
         and dependency.get('scope', {}).get('source_pin_is_not_permission') is True
         and dependency.get('scope', {}).get('signed_business_lookup_observed') is False,
         'ACCEPTED_SIDECAR_SCOPE_CHANGED')
    return {'sha256': sha(raw), 'files': files, 'snapshot': snapshot}


def context(env, event):
    need(env.get('GITHUB_REPOSITORY') == REPOSITORY and env.get('GITHUB_REF') == 'refs/heads/main'
         and env.get('GITHUB_EVENT_NAME') == 'workflow_dispatch' and env.get('GITHUB_RUN_ATTEMPT') == '1'
         and env.get('GITHUB_ACTOR') == 'pyatkoff' and env.get('GITHUB_TRIGGERING_ACTOR') == 'pyatkoff'
         and env.get('GITHUB_WORKFLOW_REF') == REPOSITORY + '/' + WORKFLOW + '@refs/heads/main'
         and env.get('GITHUB_JOB') == 'state' and HEX40.fullmatch(env.get('GITHUB_SHA', ''))
         and re.fullmatch(r'[1-9][0-9]{0,18}', env.get('GITHUB_RUN_ID', '')),
         'ORIGINAL_OWNER_MAIN_MANUAL_REQUIRED')
    need(isinstance(event, dict) and event.get('repository', {}).get('full_name') == REPOSITORY
         and event.get('repository', {}).get('id') == REPOSITORY_ID
         and event.get('sender', {}).get('login') == 'pyatkoff' and event.get('sender', {}).get('id') == 226193297,
         'OWNER_EVENT_REQUIRED')
    inputs = event.get('inputs')
    need(isinstance(inputs, dict) and {'operation', 'expected_application_sha'}.issubset(inputs)
         and set(inputs).issubset({'operation', 'expected_application_sha', 'expected_epoch'}), 'EXACT_MANUAL_INPUTS_REQUIRED')
    op, source, epoch = inputs['operation'], inputs['expected_application_sha'], inputs.get('expected_epoch', '')
    need(op in OPERATIONS and isinstance(source, str) and HEX40.fullmatch(source)
         and isinstance(epoch, str) and (epoch == '' or HEX64.fullmatch(epoch)), 'FINITE_OPERATION_REQUIRED')
    need(op in ('status', 'activate') or bool(epoch), 'EXACT_ACTIVATION_EPOCH_REQUIRED')
    need(op != 'activate' or epoch == '', 'NEW_ACTIVATION_ONLY')
    return {'operation': op, 'source_sha': source, 'expected_epoch': epoch or None}


def request_bytes(op, source, epoch):
    return canonical({'version': 1, 'operation': op, **SCOPE, 'source_sha': source, 'expected_epoch': epoch})


def accepted_resolution(raw, repository_sha, application_sha):
    need(isinstance(raw, bytes) and 0 < len(raw) <= 2048, 'CANONICAL_RELEASE_OUTPUT_BOUND')
    values = {}
    for line in raw.decode('ascii').splitlines():
        fields = line.split('=', 1)
        need(len(fields) == 2 and fields[0] not in values, 'CANONICAL_RELEASE_OUTPUT')
        values[fields[0]] = fields[1]
    need(set(values) == {'repository_sha', 'application_sha', 'kind', 'perform_actions', 'ready'}
         and values['repository_sha'] == repository_sha and values['application_sha'] == application_sha
         and values['kind'] in ('installed', 'application_unchanged')
         and values['perform_actions'] == values['ready'] == 'true', 'ACTUAL_ACCEPTED_RELEASE_REQUIRED')
    return values


def cli_result(raw, operation, application_sha, request_sha, epoch):
    value = parsed(raw, 32768)
    need(isinstance(value, dict) and set(value) == {'version', 'operation', 'status', 'ok', 'reason', 'scope',
         'admission', 'state', 'tables', 'readback', 'mutation_attempted', 'automatic_retry', 'provider_calls',
         'tick_invoked', 'v1_invoked'} and type(value['version']) is int and value['version'] == 1 and value['operation'] == operation
         and value['scope'] == SCOPE and value['admission'] == {'source_sha': application_sha,
            'observed_source_sha': application_sha, 'request_sha256': request_sha, 'expected_epoch': epoch}
         and value['automatic_retry'] is False and type(value['provider_calls']) is int and value['provider_calls'] == 0
         and value['tick_invoked'] is False and value['v1_invoked'] is False
         and type(value['ok']) is bool and type(value['readback']) is bool and type(value['mutation_attempted']) is bool,
         'EXACT_CLI_RECEIPT_REQUIRED')
    need(value['status'] in ('OBSERVED', 'ACTIVATED', 'ALREADY_ACTIVATED', 'MODE_SET', 'HOLD', 'UNKNOWN'), 'CLI_STATUS_REQUIRED')
    need(value['reason'] is None or isinstance(value['reason'], str) and re.fullmatch(r'[A-Z0-9_]{1,100}', value['reason']), 'CLI_REASON_SCOPE')
    state = value['state']
    need(isinstance(state, dict) and set(state).issubset({'status', 'reason', 'mode', 'activation', 'cursor',
         'initial_profile_max_id', 'active_tick_key', 'lease_until_unix'}) and len(canonical(state)) <= 4096,
         'PRIVATE_STATE_SCOPE')
    if value['ok']:
        need(value['readback'] is True and value['tables'] == {'status': 'OBSERVED_TABLES_READY', 'expected_count': 7,
             'observed_count': 7, 'full_schema_validation': False, 'business_rows_read': 0, 'tables': TABLES, 'engine': 'InnoDB'},
             'ACTUAL_OWN_TABLES_REQUIRED')
        need(set(state) == {'status', 'mode', 'activation', 'cursor', 'initial_profile_max_id', 'active_tick_key', 'lease_until_unix'}
             and state.get('status') == 'OBSERVED' and state.get('mode') in ('off', 'collect', 'send')
             and all(isinstance(state[k], str) and re.fullmatch(r'(?:0|[1-9][0-9]{0,19})', state[k]) for k in ('cursor', 'initial_profile_max_id'))
             and (state['active_tick_key'] is None or isinstance(state['active_tick_key'], str) and HEX64.fullmatch(state['active_tick_key']))
             and type(state['lease_until_unix']) is int and 0 <= state['lease_until_unix'] < 1000000000000,
             'ACTUAL_OWN_SETTINGS_REQUIRED')
        activation = state.get('activation')
        if activation is not None:
            need(isinstance(activation, dict) and set(activation) == {'version', 'mode', 'counter_id', 'goal_id', 'target',
                 'producer_contract_sha256', 'activation_sha256', 'activated_at_unix', 'initial_profile_max_id'}
                 and type(activation['version']) is int and activation['version'] == 1 and activation['mode'] == state['mode']
                 and activation['counter_id'] == 98615635 and activation['goal_id'] == 687941321
                 and activation['target'] == TARGET and activation['producer_contract_sha256'] == CONTRACT
                 and isinstance(activation['activation_sha256'], str) and HEX64.fullmatch(activation['activation_sha256'])
                 and type(activation['activated_at_unix']) is int and 0 < activation['activated_at_unix'] < 1000000000000
                 and activation['initial_profile_max_id'] == state['initial_profile_max_id'], 'PRIVATE_ACTIVATION_SCOPE')
        if operation == 'status':
            need(value['status'] == 'OBSERVED' and value['mutation_attempted'] is False, 'READ_ONLY_STATUS_REQUIRED')
        elif operation == 'activate':
            need(value['status'] in ('ACTIVATED', 'ALREADY_ACTIVATED') and state['mode'] == 'collect', 'ACTIVATION_READBACK_REQUIRED')
        else:
            need(value['status'] == 'MODE_SET' and state['mode'] == operation, 'MODE_READBACK_REQUIRED')
        if operation != 'status':
            activation = state.get('activation')
            need(isinstance(activation, dict) and HEX64.fullmatch(activation.get('activation_sha256', ''))
                 and activation.get('counter_id') == 98615635 and activation.get('goal_id') == 687941321
                 and activation.get('target') == TARGET and activation.get('producer_contract_sha256') == CONTRACT
                 and (epoch is None or activation['activation_sha256'] == epoch), 'IMMUTABLE_EPOCH_REQUIRED')
    else:
        need(value['status'] in ('HOLD', 'UNKNOWN'), 'FAILED_CLI_CANNOT_CLAIM_SUCCESS')
    return value


def native(payload, root=APP, runner=subprocess.run):
    """Tests may inject a temporary root and fake subprocess; native CLI admits neither override."""
    result = {'ok': False, 'status': 'HOLD', 'mutation_attempted': False, 'automatic_retry': False,
              'provider_calls': 0, 'tick_invoked': False, 'v1_invoked': False, 'reason': None}
    try:
        need(isinstance(payload, dict) and set(payload) == {'operation', 'application_sha', 'expected_epoch',
             'request_sha256', 'request_b64', 'release_manifest_sha256', 'sidecar_admission_sha256'}, 'NATIVE_INPUT_SCOPE')
        op, source, epoch = payload['operation'], payload['application_sha'], payload['expected_epoch']
        need(op in OPERATIONS and isinstance(source, str) and HEX40.fullmatch(source)
             and (epoch is None or isinstance(epoch, str) and HEX64.fullmatch(epoch)), 'NATIVE_INPUT_IDENTITY')
        need((op in ('status', 'activate') or epoch is not None) and (op != 'activate' or epoch is None), 'NATIVE_EPOCH_SCOPE')
        request = base64.b64decode(payload['request_b64'], validate=True)
        need(base64.b64encode(request).decode() == payload['request_b64'] and request == request_bytes(op, source, epoch)
             and sha(request) == payload['request_sha256'] and payload['sidecar_admission_sha256'] == SIDECAR_SHA,
             'EXACT_NATIVE_REQUEST_REQUIRED')
        need(root.resolve(strict=True) == root and git_head(root, runner) == source, 'INSTALLED_SOURCE_CHANGED')
        before = source_package(root)
        need(before['sha256'] == payload['release_manifest_sha256'], 'INSTALLED_RELEASE_MANIFEST_CHANGED')

        def invoke(operation):
            raw = request_bytes(operation, source, epoch)
            command = ['php', '-d', 'display_errors=0', '-d', 'display_startup_errors=0', '-d', 'log_errors=0',
                '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d',
                'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail',
                CLI, operation, source, sha(raw)]
            response = run_bounded(command, cwd=root, data=raw, timeout=25, limit=32768, runner=runner)
            value = cli_result(response.stdout, operation, source, sha(raw), epoch)
            need(response.returncode == 0 if value['ok'] else response.returncode != 0, 'CLI_EXIT_CONFLICT')
            return value

        observed = invoke('status')
        need(observed['ok'] and source_package(root) == before and git_head(root, runner) == source, 'PREFLIGHT_STATE_OR_SOURCE_UNPROVED')
        result['before'] = observed
        if op == 'status':
            receipt = observed
        else:
            state = observed['state']
            activation = state.get('activation')
            if op == 'activate':
                need(state['mode'] == 'off' and activation is None, 'INITIAL_ACTIVATION_REQUIRED')
            else:
                need(isinstance(activation, dict) and activation.get('activation_sha256') == epoch, 'CURRENT_EPOCH_CHANGED')
                if op == 'send':
                    need(state['mode'] == 'collect', 'COLLECT_BEFORE_SEND_REQUIRED')
            result['mutation_attempted'] = True  # A timeout after entering the child is UNKNOWN, never retried.
            receipt = invoke(op)
        result['receipt'] = receipt
        need(source_package(root) == before and git_head(root, runner) == source, 'POST_OPERATION_SOURCE_CHANGED')
        if not receipt['ok']:
            result.update(status=receipt['status'], reason=receipt['reason'])
            return result
        result.update(ok=True, status='OBSERVED' if op == 'status' else 'APPLIED',
                      source_sha=source, release_manifest_sha256=before['sha256'],
                      sidecar_admission_sha256=SIDECAR_SHA, files_verified=len(before['files']) + 1,
                      source_preserved=True)
        return result
    except BaseException as error:
        if result['mutation_attempted']:
            result['status'] = 'UNKNOWN'
        result['reason'] = str(error) if isinstance(error, Refusal) and re.fullmatch('[A-Z_]{1,100}', str(error)) else 'STATE_OPERATION_UNCONFIRMED'
        return result


def prepare(root, env, event, resolver=subprocess.run):
    selection = context(env, event)
    need(git_head(root) == env['GITHUB_SHA'], 'CHECKOUT_SOURCE_CHANGED')
    package = source_package(root)
    # The canonical resolver is explicitly registered for this exact manual workflow.
    # Do not replace this call with exported internals or spoof another event/path.
    safe_env = {k: v for k, v in env.items() if not k.startswith(('PYTHON', 'NODE_OPTIONS', 'GIT_'))}
    response = resolver(['node', 'tools/deployment_release.js', 'resolve-consumer'], cwd=root,
                        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=180, env=safe_env)
    need(response.returncode == 0 and len(response.stderr) <= 4096, 'CANONICAL_RELEASE_REFUSED')
    resolution = accepted_resolution(response.stdout, env['GITHUB_SHA'], selection['source_sha'])
    raw = request_bytes(selection['operation'], selection['source_sha'], selection['expected_epoch'])
    payload = {'operation': selection['operation'], 'application_sha': selection['source_sha'],
               'expected_epoch': selection['expected_epoch'], 'request_sha256': sha(raw),
               'request_b64': base64.b64encode(raw).decode(), 'release_manifest_sha256': package['sha256'],
               'sidecar_admission_sha256': SIDECAR_SHA}
    need(source_package(root) == package and git_head(root) == env['GITHUB_SHA'], 'CHECKOUT_CHANGED_DURING_ADMISSION')
    return {'schema': 'max2-common-state-operation-plan-v1', 'run_id': env['GITHUB_RUN_ID'], 'run_attempt': 1,
            'prepared_at_unix': int(time.time()), 'repository_sha': env['GITHUB_SHA'], 'resolution': resolution,
            'payload': payload, 'runner_sha256': package['files'][RUNNER]}


def execute(plan, root, env):
    global _TRANSPORT_ATTEMPTED
    key = env.get('STANDBY_DEPLOY_SSH_KEY', '').replace('\r', '')
    host, user = env.get('STANDBY_DEPLOY_HOST', ''), env.get('STANDBY_DEPLOY_USER', '')
    need(bool(key) and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.:-]{0,252}', host)
         and re.fullmatch(r'[a-z_][a-z0-9_-]{0,63}', user), 'EXISTING_CANONICAL_CREDENTIAL_REQUIRED')
    if 'PRIVATE KEY' in key:
        key = key.replace('\\n', '\n')
    else:
        key = base64.b64decode(key, validate=True).decode('ascii')
    raw, _ = read_regular(root / RUNNER, 65536)
    need(sha(raw) == plan['runner_sha256'], 'RUNNER_SOURCE_CHANGED')
    import shlex
    with tempfile.TemporaryDirectory(prefix='max2-common-state-') as temporary:
        keyfile = Path(temporary) / 'key'
        fd = os.open(keyfile, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'w') as stream:
            stream.write(key.rstrip() + '\n')
        probe = subprocess.run(['ssh-keygen', '-y', '-f', str(keyfile)], stdout=subprocess.DEVNULL,
                               stderr=subprocess.DEVNULL, timeout=10)
        need(probe.returncode == 0, 'EXISTING_CANONICAL_CREDENTIAL_UNAVAILABLE')
        command = ['ssh', '-i', str(keyfile), '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=2',
            # Same existing canonical STANDBY transport; no second principal/key fallback.
            '-o', 'StrictHostKeyChecking=no', '-o', 'UserKnownHostsFile=/dev/null', user + '@' + host,
            'python3 -I -B -c ' + shlex.quote(raw.decode('utf-8')) + ' --native']
        try:
            _TRANSPORT_ATTEMPTED = True
            response = subprocess.run(command, input=canonical(plan['payload']), stdout=subprocess.PIPE,
                                      stderr=subprocess.PIPE, timeout=90)
        except (subprocess.TimeoutExpired, OSError):
            return {'ok': False, 'status': 'UNKNOWN', 'reason': 'SSH_RESULT_UNKNOWN', 'automatic_retry': False}
        if len(response.stdout) > 65536 or len(response.stderr) > 65536:
            return {'ok': False, 'status': 'UNKNOWN', 'reason': 'SSH_OUTPUT_BOUND', 'automatic_retry': False}
        try:
            result = parsed(response.stdout, 65536)
        except Exception:
            return {'ok': False, 'status': 'UNKNOWN', 'reason': 'SSH_RESULT_UNKNOWN', 'automatic_retry': False}
        need(isinstance(result, dict), 'NATIVE_RESULT_REQUIRED')
        if response.returncode != 0:
            result['transport_exit_nonzero'] = True
        return result


def main():
    need(sys.argv[1:] in (['--prepare'], ['--execute'], ['--native']), 'FINITE_CARRIER_MODE_REQUIRED')
    if sys.argv[1] == '--native':
        result = native(parsed(sys.stdin.buffer.read(131073), 131072))
        sys.stdout.buffer.write(canonical(result))
        return 0 if result['ok'] else 1
    root = Path(__file__).resolve().parents[1]
    env = dict(os.environ)
    event, _ = read_regular(Path(env['GITHUB_EVENT_PATH']), 262144)
    plan = prepare(root, env, parsed(event, 262144))
    temp = Path(env['RUNNER_TEMP']).resolve(strict=True)
    plan_file = temp / 'max2-common-state-plan.json'
    if sys.argv[1] == '--prepare':
        need(not os.path.lexists(plan_file), 'PLAN_ALREADY_EXISTS')
        fd = os.open(plan_file, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(canonical(plan))
        print(json.dumps({'ok': True, 'stage': 'prepared', 'operation': plan['payload']['operation'],
                          'native_calls': 0, 'source_pin_is_not_permission': True}))
        return 0
    saved, _ = read_regular(plan_file, 16384)
    previous = parsed(saved, 16384)
    need(type(previous.get('prepared_at_unix')) is int and 0 <= time.time() - previous['prepared_at_unix'] <= 300,
         'PREPARED_ADMISSION_EXPIRED')
    need({k: v for k, v in previous.items() if k != 'prepared_at_unix'} ==
         {k: v for k, v in plan.items() if k != 'prepared_at_unix'}, 'PREPARED_ADMISSION_CHANGED')
    result = execute(plan, root, env)
    report = {'schema': 'max2-common-state-operation-result-v1', 'repository_sha': plan['repository_sha'],
              'application_sha': plan['payload']['application_sha'], 'run_id': int(env['GITHUB_RUN_ID']), 'run_attempt': 1,
              'operation': plan['payload']['operation'], 'request_sha256': plan['payload']['request_sha256'],
              'release_resolution': plan['resolution'], 'sidecar_admission_sha256': SIDECAR_SHA,
              'result': result, 'automatic_retry': False}
    report_file = temp / 'max2-common-value-state-result.json'
    fd = os.open(report_file, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(canonical(report))
    print(json.dumps({'ok': result.get('ok'), 'status': result.get('status'), 'reason': result.get('reason'),
                      'operation': report['operation'], 'provider_calls_by_carrier': 0, 'automatic_retry': False}))
    if result.get('ok') is not True:
        return 1
    need(result.get('ok') is True and result.get('transport_exit_nonzero') is not True
         and result.get('status') == ('OBSERVED' if report['operation'] == 'status' else 'APPLIED')
         and result.get('source_sha') == report['application_sha'] and result.get('source_preserved') is True
         and result.get('release_manifest_sha256') == plan['payload']['release_manifest_sha256']
         and result.get('sidecar_admission_sha256') == SIDECAR_SHA and result.get('automatic_retry') is False,
         'ORIGINAL_STATE_OPERATION_NOT_CONFIRMED')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception as error:
        reason = str(error) if isinstance(error, Refusal) and re.fullmatch('[A-Z_]{1,100}', str(error)) else 'STATE_CARRIER_UNCONFIRMED'
        print(json.dumps({'ok': False, 'status': 'UNKNOWN' if _TRANSPORT_ATTEMPTED else 'HOLD', 'reason': reason, 'automatic_retry': False}))
        raise SystemExit(1)
