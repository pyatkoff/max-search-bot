"""Fixed-counter, fixed-week GET-only Metrika reader; streamed to the existing server."""
import json
import math
import os
import re
import signal
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
COUNTER = 98615635
DATE1, DATE2 = '2026-10-18', '2026-10-24'
MAX_ATTEMPTS, MAX_BYTES = 90, 4_000_000
RESULT = {'counter_id': COUNTER, 'date1': DATE1, 'date2': DATE2,
          'started_at_utc': datetime.now(timezone.utc).isoformat(),
          'server_writes': False, 'advertising_writes': False,
          'database_opened': False, 'token_exported': False,
          'requests': [], 'errors': [], 'goals': [], 'scopes': {}}


class Stop(Exception):
    def __init__(self, code, http=None):
        self.info = {'code': code, 'http': http}


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def finite_count(value):
    if type(value) not in (int, float) or not math.isfinite(value) or value < 0:
        raise Stop('INVALID_COUNT')
    return value


def quality(body):
    return {k: body.get(k) for k in ('sampled', 'sample_share', 'sample_size',
                                   'sample_space', 'data_lag', 'total_rows')}


def load_local_config():
    from dotenv import dotenv_values
    if Path.cwd() != ROOT or ROOT.resolve() != ROOT:
        raise Stop('EXACT_DIRECTORY_REQUIRED')
    envfile = ROOT / '.env'
    if envfile.is_symlink() or not envfile.is_file() or envfile.stat().st_size > 65536:
        raise Stop('LOCAL_ENV_NOT_CONFIRMED')
    safe_env = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': os.environ.get('HOME', '/'),
                'GIT_OPTIONAL_LOCKS': '0', 'GIT_TERMINAL_PROMPT': '0',
                'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'}
    def git(*args):
        p = subprocess.run(['git', '--no-optional-locks', '-c', 'core.fsmonitor=false',
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
        'https://github.com/pyatkoff/yandex-direct-autopilot'):
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
    if not isinstance(token, str) or not token.strip() or re.search(r'\s', token):
        raise Stop('LOCAL_TOKEN_MISSING_OR_INVALID')
    RESULT['local_config_verified'] = True
    return token


def make_reader(token):
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    allowed = {'/management/v1/counter/' + str(COUNTER),
               '/management/v1/counter/' + str(COUNTER) + '/goals', '/stat/v1/data'}
    def get(path, params=None):
        if path not in allowed:
            raise Stop('ENDPOINT_NOT_ALLOWED')
        if path == '/stat/v1/data' and (not params or params.get('ids') != str(COUNTER)
                                      or params.get('date1') != DATE1 or params.get('date2') != DATE2):
            raise Stop('REPORT_SCOPE_MISMATCH')
        url = 'https://api-metrika.yandex.net' + path
        if params:
            url += '?' + urllib.parse.urlencode(params)
        request = urllib.request.Request(url, headers={'Authorization': 'OAuth ' + token,
                                                     'Accept-Language': 'ru'}, method='GET')
        for attempt in range(3):
            if len(RESULT['requests']) >= MAX_ATTEMPTS:
                raise Stop('REQUEST_BUDGET')
            try:
                with opener.open(request, timeout=15) as response:
                    status, headers = response.status, response.headers
                    raw = response.read(MAX_BYTES + 1)
            except urllib.error.HTTPError as error:
                status, headers = error.code, error.headers
                raw = error.read(MAX_BYTES + 1)
            except Exception:
                raise Stop('HTTPS_READ_FAILED') from None
            RESULT['requests'].append({'endpoint': path, 'http': status})
            if len(raw) > MAX_BYTES:
                raise Stop('RESPONSE_TOO_LARGE', status)
            if status in (429, 500, 502, 503, 504) and attempt < 2:
                delay = headers.get('Retry-After', '2')
                delay = int(delay) if re.fullmatch(r'[0-9]{1,2}', delay) else 2
                if delay <= 8:
                    time.sleep(max(1, delay))
                    continue
            if status != 200:
                raise Stop('API_READ_REJECTED', status)
            try:
                body = json.loads(raw)
            except Exception:
                raise Stop('NON_JSON_RESPONSE', status) from None
            if not isinstance(body, dict) or body.get('errors') or body.get('error'):
                raise Stop('API_RESPONSE_ERROR', status)
            return body
        raise Stop('RETRY_LIMIT')
    return get


def collect(get):
    counter = get('/management/v1/counter/' + str(COUNTER)).get('counter', {})
    if str(counter.get('id')) != str(COUNTER):
        raise Stop('EXACT_COUNTER_NOT_RETURNED')
    RESULT['counter'] = {k: counter.get(k) for k in ('id', 'name', 'time_zone_name', 'time_zone_offset', 'status')}
    goals = get('/management/v1/counter/' + str(COUNTER) + '/goals').get('goals')
    if not isinstance(goals, list) or len(goals) > 200:
        raise Stop('GOAL_CATALOG_BOUND')
    seen = set()
    for goal in goals:
        gid = goal.get('id') if isinstance(goal, dict) else None
        if type(gid) is not int or gid <= 0 or gid in seen:
            raise Stop('INVALID_GOAL_CATALOG')
        seen.add(gid)
        RESULT['goals'].append({k: goal.get(k) for k in ('id', 'name', 'type')})
    base = {'ids': str(COUNTER), 'date1': DATE1, 'date2': DATE2, 'accuracy': 'full', 'limit': '1000'}
    scopes = [('all_traffic', None, None)]
    try:
        engines = get('/stat/v1/data', {**base, 'metrics': 'ym:s:visits',
                                      'dimensions': 'ym:s:lastsignAdvEngine', 'include_undefined': 'true'})
        RESULT['engine_discovery'] = {'quality': quality(engines), 'data': engines.get('data', [])}
        if engines.get('total_rows', 0) > 1000:
            raise Stop('ENGINE_DISCOVERY_TRUNCATED')
        matches = []
        for row in engines.get('data', []):
            dims = row.get('dimensions', [])
            dim = dims[0] if len(dims) == 1 else {}
            name, value = dim.get('name', ''), dim.get('id')
            if re.search(r'(яндекс[.\s]*директ|yandex[.\s]*direct)', name, re.I):
                if not isinstance(value, str) or not re.fullmatch(r'[A-Za-z0-9_.-]{1,100}', value):
                    raise Stop('DIRECT_ENGINE_ID_INVALID')
                metrics = row.get('metrics', [])
                if len(metrics) != 1:
                    raise Stop('ENGINE_METRICS_INVALID')
                matches.append((value, finite_count(metrics[0])))
        if len(matches) == 1:
            value, visits = matches[0]
            scopes.append(('yandex_direct_last_significant', "ym:s:lastsignAdvEngine=='" + value + "'", visits))
        else:
            RESULT['errors'].append({'code': 'DIRECT_ENGINE_NOT_UNIQUELY_DISCOVERED', 'matches': len(matches)})
    except Stop as error:
        RESULT['errors'].append({'stage': 'engine_discovery', **error.info})
    goal_ids = [goal['id'] for goal in RESULT['goals']]
    for scope, filters, expected_visits in scopes:
        output = {'filter': filters, 'date1': DATE1, 'date2': DATE2, 'goals': {},
                  'batches': [], 'complete': False, 'expected_visits_from_discovery': expected_visits}
        RESULT['scopes'][scope] = output
        for offset in range(0, max(1, len(goal_ids)), 6):
            batch = goal_ids[offset:offset + 6]
            metrics = ['ym:s:visits', 'ym:s:users']
            for gid in batch:
                metrics += ['ym:s:goal' + str(gid) + suffix for suffix in ('reaches', 'visits', 'users')]
            params = {**base, 'metrics': ','.join(metrics)}
            if filters:
                params['filters'] = filters
            try:
                body = get('/stat/v1/data', params)
                totals = body.get('totals')
                if not isinstance(totals, list) or len(totals) != len(metrics):
                    raise Stop('METRICS_LENGTH_MISMATCH')
                totals = list(map(finite_count, totals))
                output['batches'].append({'goal_ids': batch, 'quality': quality(body), 'visits': totals[0], 'users': totals[1]})
                if 'visits' not in output:
                    output.update({'visits': totals[0], 'users': totals[1]})
                for idx, gid in enumerate(batch):
                    values = totals[2 + idx * 3:5 + idx * 3]
                    output['goals'][str(gid)] = dict(zip(('reaches', 'visits', 'users'), values))
            except Stop as error:
                RESULT['errors'].append({'stage': 'goal_statistics', 'scope': scope, 'goal_ids': batch, **error.info})
        output['complete'] = len(output['goals']) == len(goal_ids)
        if expected_visits is not None and output.get('visits') != expected_visits:
            output['source_visit_count_matches_discovery'] = False
            RESULT['errors'].append({'stage': 'source_validation', 'code': 'SOURCE_VISITS_CHANGED_OR_FILTER_MISMATCH'})
        elif expected_visits is not None:
            output['source_visit_count_matches_discovery'] = True
    RESULT['status'] = 'COLLECTED' if not RESULT['errors'] else 'PARTIAL'


if __name__ == '__main__':
    def timeout(*_):
        raise Stop('TIME_BUDGET')
    signal.signal(signal.SIGALRM, timeout)
    signal.alarm(240)
    try:
        collect(make_reader(load_local_config()))
    except Stop as error:
        RESULT['errors'].append(error.info)
        RESULT['status'] = 'PARTIAL' if RESULT['scopes'] else 'READ_FAILED'
    except Exception:
        RESULT['errors'].append({'code': 'LOCAL_READER_FAILED'})
        RESULT['status'] = 'READ_FAILED'
    finally:
        signal.alarm(0)
        RESULT['finished_at_utc'] = datetime.now(timezone.utc).isoformat()
        print(json.dumps(RESULT, ensure_ascii=False, allow_nan=False))
