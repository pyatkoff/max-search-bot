"""Exactly two read-only management GETs for the four fixed MAX2 goals.

No report data, conversions, database, campaign reads or writes. Existing audited
local-token/SSH transport is retained; only this new catalog scope is admitted.
"""
import json
import math
import os
import re
import signal
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

sys.dont_write_bytecode = True
ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
COUNTER = 98615635
SCOPE = 'max2_new_goal_catalog_v1'
OBSERVATION_DATE = '2026-11-21'
MAX_ATTEMPTS = 2
MAX_RESPONSE_BYTES = 2_000_000
MAX_RETURN_BYTES = 32_768
TOTAL_SECONDS, REQUEST_TIMEOUT = 60, 15
COUNTER_PATH = '/management/v1/counter/' + str(COUNTER)
GOALS_PATH = COUNTER_PATH + '/goals'
EXPECTED_PATHS = (COUNTER_PATH, GOALS_PATH)
GOAL_ORDER = (563541588, 678187097, 687931224, 687941321)
ACTION_TARGETS = {
    678187097: 'anytour_modeled_value_v1',
    687931224: 'max2ok',
    687941321: 'anytour_modeled_value_v2_max2',
}
PROTECTED_BASELINE = {
    563541588: {'name': 'max2', 'type': 'url',
                'conditions': [{'type': 'contain', 'url': '/max2/'}],
                'is_retargeting': False},
    678187097: {'name': 'AnyTour: оценочная ценность обращения v1', 'type': 'action',
                'conditions': [{'type': 'exact', 'url': 'anytour_modeled_value_v1'}],
                'is_retargeting': False},
}


class Stop(Exception):
    def __init__(self, code, http=None, provider_codes=None):
        self.info = {'code': code, 'http': http}
        if provider_codes:
            self.info['provider_error_codes'] = provider_codes
        super().__init__(code)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def bounded_text(value, limit=256):
    if not isinstance(value, str) or len(value) > limit or re.search(r'[\x00-\x1f\x7f]', value):
        raise Stop('INVALID_METADATA')
    return value


def check_time():
    if time.monotonic() >= DEADLINE:
        raise Stop('TIME_BUDGET')


def load_local_config():
    from dotenv import dotenv_values
    if Path.cwd() != ROOT or ROOT.resolve() != ROOT:
        raise Stop('EXACT_DIRECTORY_REQUIRED')
    envfile = ROOT / '.env'
    if envfile.is_symlink() or not envfile.is_file() or envfile.stat().st_size > 65536:
        raise Stop('LOCAL_ENV_NOT_CONFIRMED')
    safe_env = {
        'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': os.environ.get('HOME', '/'),
        'GIT_OPTIONAL_LOCKS': '0', 'GIT_TERMINAL_PROMPT': '0',
        'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
    }

    def git(*args):
        check_time()
        p = subprocess.run(
            ['git', '--no-optional-locks', '-c', 'core.fsmonitor=false',
             '-c', 'core.hooksPath=/dev/null', '-C', str(ROOT), *args],
            capture_output=True, text=True, timeout=10, env=safe_env)
        if p.returncode:
            raise Stop('PROJECT_IDENTITY_CHECK_FAILED')
        return p.stdout.strip()

    if git('rev-parse', '--show-toplevel') != str(ROOT):
        raise Stop('PROJECT_IDENTITY_MISMATCH')
    if git('config', '--get', 'remote.origin.url') not in (
        'git@github.com:pyatkoff/yandex-direct-autopilot.git',
        'https://github.com/pyatkoff/yandex-direct-autopilot.git',
        'https://github.com/pyatkoff/yandex-direct-autopilot',
    ):
        raise Stop('PROJECT_IDENTITY_MISMATCH')
    sha = git('rev-parse', 'HEAD')
    if not re.fullmatch('[0-9a-f]{40}', sha):
        raise Stop('INVALID_INSTALLED_REVISION')
    RESULT['installed_sha'] = sha
    cfg = dotenv_values(envfile, interpolate=False)

    def conf(key, default=''):
        return os.environ.get(key, cfg.get(key, default))

    if str(conf('READ_ONLY')).lower() != 'true':
        raise Stop('READ_ONLY_NOT_TRUE')
    if str(conf('YANDEX_DIRECT_SANDBOX', 'false')).lower() not in ('false', '0'):
        raise Stop('SANDBOX_ENABLED')
    token = conf('YANDEX_OAUTH_TOKEN')
    if not isinstance(token, str) or not token or re.search(r'\s', token):
        raise Stop('LOCAL_TOKEN_MISSING_OR_INVALID')
    RESULT['local_config_verified'] = True
    return token


def error_codes(raw):
    """Only short machine codes survive. Never return provider error messages."""
    try:
        body = json.loads(raw)
        candidates = []
        if isinstance(body, dict):
            candidates += [body.get('code'), body.get('error_type')]
            for item in body.get('errors', [])[:10]:
                if isinstance(item, dict):
                    candidates += [item.get('code'), item.get('error_type')]
        return list(dict.fromkeys(
            str(v) for v in candidates if type(v) is int
            or isinstance(v, str) and re.fullmatch(r'[A-Za-z0-9_.:-]{1,80}', v)))
    except Exception:
        return []


def make_reader(token):
    context = ssl.create_default_context()
    context.check_hostname = True
    context.verify_mode = ssl.CERT_REQUIRED
    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler({}), NoRedirect(),
        urllib.request.HTTPSHandler(context=context))

    def get(path, params=None):
        name = validate_request(path, params)
        check_time()
        if len(RESULT['requests']) >= MAX_ATTEMPTS:
            raise Stop('REQUEST_BUDGET')
        url = 'https://api-metrika.yandex.net' + path
        request = urllib.request.Request(
            url, headers={'Authorization': 'OAuth ' + token, 'Accept-Language': 'en'},
            method='GET')
        entry = {'endpoint': path, 'query': name, 'attempt': 1, 'http': None}
        RESULT['requests'].append(entry)
        try:
            with opener.open(request, timeout=REQUEST_TIMEOUT) as response:
                status = response.status
                if response.geturl() != url:
                    raise Stop('RESPONSE_ENDPOINT_MISMATCH')
                raw = response.read(MAX_RESPONSE_BYTES + 1)
        except Stop:
            raise
        except urllib.error.HTTPError as error:
            status = error.code
            raw = error.read(MAX_RESPONSE_BYTES + 1)
            error.close()
        except Exception:
            raise Stop('HTTPS_READ_FAILED') from None
        entry['http'] = status
        if len(raw) > MAX_RESPONSE_BYTES:
            raise Stop('RESPONSE_TOO_LARGE', status)
        if status != 200:
            raise Stop('API_READ_REJECTED', status, error_codes(raw))
        try:
            body = strict_json(raw)
        except Stop:
            raise
        except Exception:
            raise Stop('NON_JSON_RESPONSE', status) from None
        if not isinstance(body, dict) or body.get('errors') or body.get('error'):
            raise Stop('API_RESPONSE_ERROR', status, error_codes(raw))
        return body
    return get


def reset():
    global RESULT, DEADLINE
    DEADLINE = time.monotonic() + TOTAL_SECONDS
    RESULT = {
        'schema_version': 1, 'scope': SCOPE, 'counter_id': COUNTER,
        'observation_date': OBSERVATION_DATE,
        'started_at_utc': datetime.now(timezone.utc).isoformat(),
        'server_writes': False, 'advertising_writes': False,
        'database_opened': False, 'token_exported': False,
        'campaign_optimization_read': False, 'conversion_events_sent': False,
        'requests': [], 'errors': [], 'goals': [],
        'goal_catalog_verified': False, 'protected_goal_baseline_verified': False,
        'limits': {'get_attempts': MAX_ATTEMPTS, 'retries': 0,
                   'request_timeout_seconds': REQUEST_TIMEOUT,
                   'total_seconds': TOTAL_SECONDS,
                   'response_bytes': MAX_RESPONSE_BYTES,
                   'return_bytes': MAX_RETURN_BYTES},
        'interpretation': {
            'catalog_verification_only': True,
            'goal_creation_performed': False,
            'event_code_or_value_model_verified': False,
            'goal_receipt_or_visit_linkage_verified': False,
            'campaign_settings_verified': False,
            'protected_baseline_source_run': '44889693953',
            'protected_baseline_source_sha': '05d8604c0a092bd034f18da7fadc248b3238c074',
            'baseline_fields': ['name', 'type', 'conditions', 'is_retargeting'],
        },
    }
    return RESULT


def strict_json(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise Stop('DUPLICATE_JSON_KEY')
            result[key] = value
        return result
    def invalid(_):
        raise Stop('INVALID_JSON_NUMBER')
    return json.loads(raw, object_pairs_hook=pairs, parse_constant=invalid)


def validate_request(path, params):
    if params not in (None, {}):
        raise Stop('PARAMETERS_NOT_ALLOWED')
    if path not in EXPECTED_PATHS:
        raise Stop('ENDPOINT_NOT_ALLOWED')
    index = len(RESULT['requests'])
    if index >= MAX_ATTEMPTS:
        raise Stop('REQUEST_BUDGET')
    if path != EXPECTED_PATHS[index]:
        raise Stop('EXACT_REQUEST_ORDER_REQUIRED')
    return 'counter' if index == 0 else 'goal_catalog'


def project_goals(goals):
    if not isinstance(goals, list) or len(goals) > 200:
        raise Stop('GOAL_CATALOG_BOUND')
    seen, targets = set(), {}
    for goal in goals:
        gid = goal.get('id') if isinstance(goal, dict) else None
        if type(gid) is not int or gid <= 0 or gid in seen:
            raise Stop('INVALID_GOAL_CATALOG')
        seen.add(gid)
        if gid in GOAL_ORDER:
            targets[gid] = goal
    if set(targets) != set(GOAL_ORDER):
        raise Stop('EXACT_FOUR_GOALS_NOT_RETURNED')
    output = []
    for gid in GOAL_ORDER:
        goal = targets[gid]
        conditions = goal.get('conditions')
        expected_type = 'url' if gid == 563541588 else 'action'
        expected_conditions = ([{'type': 'contain', 'url': '/max2/'}]
                               if gid == 563541588
                               else [{'type': 'exact', 'url': ACTION_TARGETS[gid]}])
        if goal.get('type') != expected_type or conditions != expected_conditions:
            raise Stop('GOAL_' + str(gid) + '_TYPE_OR_TARGET_MISMATCH')
        retargeting = goal.get('is_retargeting')
        if type(retargeting) not in (bool, int) or retargeting not in (0, 1):
            raise Stop('GOAL_' + str(gid) + '_RETARGETING_UNAVAILABLE')
        row = {'id': gid, 'name': bounded_text(goal.get('name', ''), 512),
               'type': expected_type, 'conditions': expected_conditions,
               'is_retargeting': bool(retargeting)}
        if gid in PROTECTED_BASELINE:
            if any(row[key] != value for key, value in PROTECTED_BASELINE[gid].items()):
                raise Stop('PROTECTED_GOAL_' + str(gid) + '_BASELINE_MISMATCH')
        output.append(row)
    return output


def collect(get):
    counter = get(COUNTER_PATH).get('counter', {})
    if (not isinstance(counter, dict) or type(counter.get('id')) is not int
            or counter['id'] != COUNTER):
        raise Stop('EXACT_COUNTER_NOT_RETURNED')
    safe_counter = {'id': COUNTER}
    for field in ('status', 'time_zone_name'):
        if field in counter:
            safe_counter[field] = bounded_text(counter[field])
    if 'time_zone_offset' in counter:
        offset = counter['time_zone_offset']
        if type(offset) not in (int, float) or not math.isfinite(offset) or abs(offset) > 1440:
            raise Stop('INVALID_COUNTER_TIMEZONE')
        safe_counter['time_zone_offset'] = offset
    RESULT['counter'] = safe_counter
    RESULT['goals'] = project_goals(get(GOALS_PATH).get('goals'))
    RESULT['goal_catalog_verified'] = True
    RESULT['protected_goal_baseline_verified'] = True
    RESULT['status'] = 'COLLECTED'
    return RESULT


def validate_projected_report(report):
    if not isinstance(report, dict):
        raise Stop('REPORT_SCHEMA')
    if (report.get('schema_version') != 1 or report.get('scope') != SCOPE
            or type(report.get('counter_id')) is not int or report['counter_id'] != COUNTER
            or report.get('observation_date') != OBSERVATION_DATE
            or report.get('status') not in ('COLLECTED', 'READ_FAILED')):
        raise Stop('REPORT_SCOPE_MISMATCH')
    flags = ('server_writes', 'advertising_writes', 'database_opened', 'token_exported',
             'campaign_optimization_read', 'conversion_events_sent')
    if any(report.get(k) is not False for k in flags):
        raise Stop('REPORT_SAFETY_FLAGS_INVALID')
    requests = report.get('requests')
    if not isinstance(requests, list) or len(requests) > MAX_ATTEMPTS:
        raise Stop('REPORT_REQUEST_BOUND')
    for index, request in enumerate(requests):
        if (not isinstance(request, dict)
                or set(request) != {'endpoint', 'query', 'attempt', 'http'}
                or request['endpoint'] != EXPECTED_PATHS[index]
                or request['query'] != ('counter' if index == 0 else 'goal_catalog')
                or type(request['attempt']) is not int or request['attempt'] != 1
                or (request['http'] is not None and (type(request['http']) is not int
                                                     or not 100 <= request['http'] <= 599))):
            raise Stop('REPORT_REQUEST_CHANGED')
    if report['status'] == 'COLLECTED':
        if (len(requests) != 2 or any(r['http'] != 200 for r in requests)
                or report.get('errors') != []
                or report.get('counter', {}).get('id') != COUNTER
                or report.get('goal_catalog_verified') is not True
                or report.get('protected_goal_baseline_verified') is not True
                or report.get('goals') != project_goals(report.get('goals'))):
            raise Stop('COLLECTED_PROOF_INCOMPLETE')
    else:
        if (report.get('goal_catalog_verified') is not False
                or report.get('protected_goal_baseline_verified') is not False
                or report.get('goals') != [] or not report.get('errors')):
            raise Stop('FAILED_PROOF_INVALID')


def serialized_result():
    raw = json.dumps(RESULT, ensure_ascii=False, allow_nan=False, separators=(',', ':'))
    if len(raw.encode('utf-8')) > MAX_RETURN_BYTES:
        raise Stop('RETURN_SIZE_BUDGET')
    return raw


def main():
    reset()
    def timeout(*_):
        raise Stop('TIME_BUDGET')
    signal.signal(signal.SIGALRM, timeout)
    signal.alarm(TOTAL_SECONDS)
    try:
        collect(make_reader(load_local_config()))
    except Stop as error:
        RESULT['errors'].append(error.info)
        RESULT['status'] = 'READ_FAILED'
    except Exception:
        RESULT['errors'].append({'code': 'LOCAL_READER_FAILED', 'http': None})
        RESULT['status'] = 'READ_FAILED'
    finally:
        signal.alarm(0)
        RESULT['finished_at_utc'] = datetime.now(timezone.utc).isoformat()
        print(serialized_result())


reset()
if __name__ == '__main__':
    main()
