"""Prepared one-GET natural observation of only the two new MAX2 value goals.

Existing READ_ONLY standby credential path; no management/catalog, user dimensions,
conversion submission, database, campaign operation or browser execution.
Actual outer installation/acceptance proof and a new scoped carrier are required.
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
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

sys.dont_write_bytecode = True
ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
COUNTER = 98615635
SCOPE = 'max2_new_value_goals_natural_observation_20261121_v1'
DATE = '2026-11-21'
TIMEZONE = '+03:00'
INSTALL_RECEIPT_SHA256 = '45850623438036c8d27c03484f734e31d312250cdc5a88008c265c49fb2a4dd2'
GOALS = (687931224, 687941321)
TARGETS = ('max2ok', 'anytour_modeled_value_v2_max2')
METRICS = tuple('ym:s:goal' + str(g) + suffix for g in GOALS
                for suffix in ('reaches', 'visits', 'convertedRUBRevenue'))
REPORT_PATH = '/stat/v1/data'
PLAN = {'ids': str(COUNTER), 'date1': DATE, 'date2': DATE, 'timezone': TIMEZONE,
        'accuracy': 'full', 'lang': 'en', 'include_undefined': 'true',
        'offset': '1', 'limit': '1', 'metrics': ','.join(METRICS)}
MAX_ATTEMPTS, MAX_RESPONSE_BYTES, MAX_RETURN_BYTES = 1, 2_000_000, 32768
TOTAL_SECONDS, REQUEST_TIMEOUT = 60, 15

class Stop(Exception):
    def __init__(self, code, http=None, provider_codes=None):
        self.info = {'code': code, 'http': http}
        if provider_codes: self.info['provider_error_codes'] = provider_codes
        super().__init__(code)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None

def check_time():
    if time.monotonic() >= DEADLINE: raise Stop('TIME_BUDGET')

def reset():
    global RESULT, DEADLINE
    DEADLINE = time.monotonic() + TOTAL_SECONDS
    RESULT = {'schema_version': 1, 'scope': SCOPE, 'counter_id': COUNTER,
        'date1': DATE, 'date2': DATE, 'timezone': TIMEZONE,
        'started_at_utc': datetime.now(timezone.utc).isoformat(), 'finished_at_utc': None,
        'status': 'NOT_OBSERVED', 'goals': None, 'quality': None, 'requests': [], 'errors': [],
        'local_config_verified': False, 'installed_sha': None, 'install_receipt_sha256': None,
        'server_writes': False, 'database_opened': False, 'advertising_writes': False,
        'token_exported': False, 'conversion_events_sent': False, 'goal_catalog_read': False,
        'interpretation': {'provider_report_only': True, 'offline_PROCESSED_receipt': False,
            'per_reservation_delivery_verified': False, 'direct_attribution_verified': False,
            'post_install_only_cohort': False,
            'date_basis': 'visit_start_day_in_explicit_UTC_plus_03',
            'unknown_is_not_zero': True, 'natural_events_generated_by_reader': False}}
    return RESULT

def validate_request(path, params):
    if path != REPORT_PATH or params != PLAN: raise Stop('EXACT_NEW_GOAL_REPORT_REQUIRED')
    if len(RESULT['requests']) >= MAX_ATTEMPTS: raise Stop('REQUEST_BUDGET')
    return 'two_new_goal_totals'

def finite(value):
    if type(value) not in (int, float) or not math.isfinite(value) or not 0 <= value <= 1e15:
        raise Stop('INVALID_AGGREGATE_NUMBER')
    return value

def count(value):
    value = finite(value)
    if int(value) != value: raise Stop('NONINTEGER_GOAL_COUNT')
    return int(value)

def project(body):
    if not isinstance(body, dict) or body.get('error') or body.get('errors'): raise Stop('REPORT_BODY_INVALID')
    q = body.get('query')
    if (not isinstance(q, dict) or q.get('ids') != [COUNTER]
            or q.get('date1') != DATE or q.get('date2') != DATE or q.get('timezone') != TIMEZONE
            or q.get('metrics') != list(METRICS) or q.get('dimensions', []) != []
            or q.get('filters') not in (None, '') or type(q.get('limit')) is not int or q['limit'] != 1
            or type(q.get('offset')) is not int or q['offset'] != 1):
        raise Stop('PROVIDER_QUERY_SCOPE_MISMATCH')
    totals, rows = body.get('totals'), body.get('data')
    if not isinstance(totals, list) or len(totals) != 6: raise Stop('METRICS_SHAPE')
    totals = [finite(v) for v in totals]
    if not isinstance(rows, list) or len(rows) > 1: raise Stop('REPORT_ROW_BOUND')
    if rows:
        row = rows[0]
        if (not isinstance(row, dict) or row.get('dimensions') != []
                or not isinstance(row.get('metrics'), list) or len(row['metrics']) != 6
                or [finite(v) for v in row['metrics']] != totals): raise Stop('AGGREGATE_TOTALS_MISMATCH')
    elif any(totals): raise Stop('AGGREGATE_TOTALS_MISMATCH')
    quality = {k: body.get(k) for k in ('sampled', 'sample_share', 'data_lag', 'total_rows',
                                      'total_rows_rounded', 'contains_sensitive_data')}
    for key in ('sampled', 'total_rows_rounded', 'contains_sensitive_data'):
        if quality[key] is not None and type(quality[key]) is not bool: raise Stop('QUALITY_METADATA_INVALID')
    if type(quality['total_rows']) is not int or quality['total_rows'] != len(rows):
        raise Stop('QUALITY_METADATA_INVALID')
    if quality['sample_share'] is not None and not 0 <= finite(quality['sample_share']) <= 1:
        raise Stop('QUALITY_METADATA_INVALID')
    if quality['data_lag'] is not None: finite(quality['data_lag'])
    complete = (quality['sampled'] is False and quality['sample_share'] in (None, 1)
                and quality['total_rows_rounded'] is False and quality['contains_sensitive_data'] is False)
    goals = []
    for index, (goal, target) in enumerate(zip(GOALS, TARGETS)):
        reaches, visits, revenue = totals[index*3:index*3+3]
        reaches, visits = count(reaches), count(visits)
        if visits > reaches: raise Stop('INCONSISTENT_GOAL_COUNTS')
        goals.append({'goal_id': goal, 'target': target, 'reported_reaches': reaches,
            'reported_goal_visits': visits, 'reported_value_rub': str(revenue),
            'positive_natural_report_observed': reaches > 0})
    return {'status': 'OBSERVED' if complete else 'PARTIAL',
            'quality': quality | {'complete': complete}, 'goals': goals if complete else None}

def make_reader(token):
    context = ssl.create_default_context()
    context.check_hostname = True; context.verify_mode = ssl.CERT_REQUIRED
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(),
                                         urllib.request.HTTPSHandler(context=context))
    def get(path, params):
        name = validate_request(path, params); check_time()
        url = 'https://api-metrika.yandex.net' + path + '?' + urllib.parse.urlencode(params)
        request = urllib.request.Request(url, method='GET',
                    headers={'Authorization': 'OAuth ' + token, 'Accept-Language': 'en'})
        entry = {'endpoint': path, 'query': name, 'attempt': 1, 'http': None}
        RESULT['requests'].append(entry)
        try:
            with opener.open(request, timeout=REQUEST_TIMEOUT) as response:
                status = response.status
                if response.geturl() != url: raise Stop('RESPONSE_ENDPOINT_MISMATCH')
                raw = response.read(MAX_RESPONSE_BYTES + 1)
        except Stop: raise
        except urllib.error.HTTPError as error:
            status = error.code; raw = error.read(MAX_RESPONSE_BYTES + 1); error.close()
        except Exception: raise Stop('HTTPS_READ_FAILED') from None
        entry['http'] = status
        if len(raw) > MAX_RESPONSE_BYTES: raise Stop('RESPONSE_TOO_LARGE', status)
        if status != 200: raise Stop('API_READ_REJECTED', status, error_codes(raw))
        try: return strict_json(raw)
        except Exception: raise Stop('INVALID_JSON_RESPONSE', status) from None
    return get

def collect(get):
    validate_request(REPORT_PATH, PLAN)
    RESULT.update(project(get(REPORT_PATH, dict(PLAN))))
    return RESULT

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

def validate_projected_report(report):
    """Closed retained output for a future NEW scoped carrier; not provider data or authentication."""
    keys = {'schema_version', 'scope', 'counter_id', 'date1', 'date2', 'timezone',
        'started_at_utc', 'finished_at_utc', 'status', 'goals', 'quality', 'requests', 'errors',
        'local_config_verified', 'installed_sha', 'install_receipt_sha256', 'server_writes',
        'database_opened', 'advertising_writes', 'token_exported', 'conversion_events_sent',
        'goal_catalog_read', 'interpretation'}
    if not isinstance(report, dict) or set(report) != keys: raise Stop('RETAINED_REPORT_SHAPE')
    if (report['schema_version'] != 1 or type(report['schema_version']) is not int
            or report['scope'] != SCOPE or report['counter_id'] != COUNTER
            or report['date1'] != DATE or report['date2'] != DATE or report['timezone'] != TIMEZONE
            or report['install_receipt_sha256'] != INSTALL_RECEIPT_SHA256): raise Stop('RETAINED_REPORT_SCOPE')
    for key in ('server_writes', 'database_opened', 'advertising_writes', 'token_exported',
                'conversion_events_sent', 'goal_catalog_read'):
        if report[key] is not False: raise Stop('RETAINED_WRITE_CLAIM')
    expected_interpretation = {'provider_report_only': True, 'offline_PROCESSED_receipt': False,
        'per_reservation_delivery_verified': False, 'direct_attribution_verified': False,
        'post_install_only_cohort': False, 'date_basis': 'visit_start_day_in_explicit_UTC_plus_03',
        'unknown_is_not_zero': True, 'natural_events_generated_by_reader': False}
    if report['interpretation'] != expected_interpretation: raise Stop('RETAINED_REPORT_INTERPRETATION')
    if report['local_config_verified'] is not True or not isinstance(report['installed_sha'], str) \
            or not re.fullmatch('[a-f0-9]{40}', report['installed_sha']): raise Stop('RETAINED_LOCAL_PROOF_MISSING')
    times = []
    for key in ('started_at_utc', 'finished_at_utc'):
        value = report[key]
        if not isinstance(value, str) or len(value) > 40: raise Stop('RETAINED_REPORT_TIME')
        try: parsed = datetime.fromisoformat(value)
        except Exception: raise Stop('RETAINED_REPORT_TIME') from None
        if parsed.utcoffset() != timedelta(0): raise Stop('RETAINED_REPORT_TIME')
        times.append(parsed)
    if not 0 <= (times[1] - times[0]).total_seconds() <= TOTAL_SECONDS + 5: raise Stop('RETAINED_REPORT_TIME')
    requests = report['requests']
    if not isinstance(requests, list) or len(requests) != 1: raise Stop('RETAINED_REPORT_REQUEST_BOUND')
    request = requests[0]
    if (not isinstance(request, dict) or set(request) != {'endpoint', 'query', 'attempt', 'http'}
            or request['endpoint'] != REPORT_PATH or request['query'] != 'two_new_goal_totals'
            or type(request['attempt']) is not int or request['attempt'] != 1
            or (request['http'] is not None and (type(request['http']) is not int or not 100 <= request['http'] <= 599))):
        raise Stop('RETAINED_REPORT_REQUEST_SCOPE')
    if report['status'] not in ('OBSERVED', 'PARTIAL', 'READ_FAILED'): raise Stop('RETAINED_REPORT_STATUS')
    errors = report['errors']
    if not isinstance(errors, list) or len(errors) > 1: raise Stop('RETAINED_REPORT_ERROR_SHAPE')
    for error in errors:
        if (not isinstance(error, dict) or set(error) not in ({'code', 'http'}, {'code', 'http', 'provider_error_codes'})
                or not isinstance(error['code'], str) or not re.fullmatch('[A-Z_]{1,80}', error['code'])
                or (error['http'] is not None and (type(error['http']) is not int or not 100 <= error['http'] <= 599))):
            raise Stop('RETAINED_REPORT_ERROR_SHAPE')
        codes = error.get('provider_error_codes', [])
        if not isinstance(codes, list) or len(codes) > 22 or any(not isinstance(v, str)
                or not re.fullmatch('[A-Za-z0-9_.:-]{1,80}', v) for v in codes): raise Stop('RETAINED_REPORT_ERROR_SHAPE')
    if report['status'] == 'READ_FAILED':
        if not errors or report['goals'] is not None or report['quality'] is not None: raise Stop('RETAINED_REPORT_STATUS')
        return report
    if errors or request['http'] != 200: raise Stop('RETAINED_REPORT_STATUS')
    quality = report['quality']
    if not isinstance(quality, dict) or set(quality) != {'sampled', 'sample_share', 'data_lag', 'total_rows',
            'total_rows_rounded', 'contains_sensitive_data', 'complete'}: raise Stop('RETAINED_REPORT_QUALITY')
    if type(quality['complete']) is not bool: raise Stop('RETAINED_REPORT_QUALITY')
    for key in ('sampled', 'total_rows_rounded', 'contains_sensitive_data'):
        if quality[key] is not None and type(quality[key]) is not bool: raise Stop('RETAINED_REPORT_QUALITY')
    if type(quality['total_rows']) is not int or quality['total_rows'] not in (0, 1): raise Stop('RETAINED_REPORT_QUALITY')
    if quality['sample_share'] is not None and not 0 <= finite(quality['sample_share']) <= 1: raise Stop('RETAINED_REPORT_QUALITY')
    if quality['data_lag'] is not None: finite(quality['data_lag'])
    complete = (quality['sampled'] is False and quality['sample_share'] in (None, 1)
                and quality['total_rows_rounded'] is False and quality['contains_sensitive_data'] is False)
    if quality['complete'] != complete or (report['status'] == 'OBSERVED') != complete: raise Stop('RETAINED_REPORT_QUALITY')
    if not complete:
        if report['goals'] is not None: raise Stop('RETAINED_REPORT_GOALS')
        return report
    if not isinstance(report['goals'], list) or len(report['goals']) != 2: raise Stop('RETAINED_REPORT_GOALS')
    for row, goal, target in zip(report['goals'], GOALS, TARGETS):
        if (not isinstance(row, dict) or set(row) != {'goal_id', 'target', 'reported_reaches',
                'reported_goal_visits', 'reported_value_rub', 'positive_natural_report_observed'}
                or row['goal_id'] != goal or row['target'] != target): raise Stop('RETAINED_REPORT_GOALS')
        reaches, visits = count(row['reported_reaches']), count(row['reported_goal_visits'])
        if visits > reaches or row['positive_natural_report_observed'] is not (reaches > 0): raise Stop('RETAINED_REPORT_GOALS')
        revenue = row['reported_value_rub']
        if not isinstance(revenue, str) or not re.fullmatch(r'[0-9]{1,16}(?:\.[0-9]{1,16})?(?:e[+-]?[0-9]{1,2})?', revenue):
            raise Stop('RETAINED_REPORT_GOALS')
        finite(float(revenue))
    return report

def main():
    reset()
    def timeout(*_): raise Stop('TIME_BUDGET')
    signal.signal(signal.SIGALRM, timeout); signal.alarm(TOTAL_SECONDS)
    try:
        if len(sys.argv) != 2 or sys.argv[1] != INSTALL_RECEIPT_SHA256:
            raise Stop('ORIGINAL_INSTALL_RECEIPT_PIN_REQUIRED')
        # The new outer carrier must verify the original install+acceptance evidence.
        # This pin is provenance input, not a self-issued permission or delivery proof.
        RESULT['install_receipt_sha256'] = sys.argv[1]
        if datetime.now(timezone(timedelta(hours=3))).date().isoformat() != DATE:
            raise Stop('OBSERVATION_DAY_EXPIRED')
        collect(make_reader(load_local_config()))
    except Stop as error:
        RESULT['status'] = 'READ_FAILED'; RESULT['goals'] = None; RESULT['errors'].append(error.info)
    except Exception:
        RESULT['status'] = 'READ_FAILED'; RESULT['goals'] = None
        RESULT['errors'].append({'code': 'LOCAL_READER_FAILED', 'http': None})
    finally:
        signal.alarm(0)
        RESULT['finished_at_utc'] = datetime.now(timezone.utc).isoformat()
        raw = json.dumps(RESULT, ensure_ascii=False, allow_nan=False, separators=(',', ':'))
        if len(raw.encode()) > MAX_RETURN_BYTES: raise SystemExit('RETURN_SIZE_BUDGET')
        print(raw)

reset()
if __name__ == '__main__': main()
