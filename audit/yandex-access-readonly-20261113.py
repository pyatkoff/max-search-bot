"""Fixed read-only access and Wordstat probes using existing server-local tokens.

Stream to the established server transport; never install this as a worker.
The existing runner must encrypt stdout; tokens never leave the server.
"""
import sys
sys.dont_write_bytecode = True
import json
import math
import os
import re
import signal
import socket
import subprocess
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
COUNTER = 98615635
CLIENT_LOGIN = 'anytour2024tp'
MAX_BYTES, TOTAL_SECONDS = 2 * 1024 * 1024, 90
WORDSTAT_KEYS = ('WORDSTAT_OAUTH_TOKEN', 'YANDEX_WORDSTAT_OAUTH_TOKEN', 'YANDEX_WORDSTAT_TOKEN')
DIRECT_BODY = {'method': 'get', 'params': {'SelectionCriteria': {},
               'FieldNames': ['Id'], 'Page': {'Limit': 1}}}
ENDPOINTS = {
    'metrika_counter': ('GET', 'https://api-metrika.yandex.net/management/v1/counter/98615635', None),
    'direct_campaign_access': ('POST', 'https://api.direct.yandex.com/json/v501/campaigns', DIRECT_BODY),
    'wordstat_regions': ('POST', 'https://api.wordstat.yandex.net/v1/getRegionsTree', {}),
    'wordstat_top': ('POST', 'https://api.wordstat.yandex.net/v1/topRequests', {
        'phrase': '"замена аккумулятора iphone 13"', 'regions': [22], 'devices': ['all']}),
    'wordstat_dynamics': ('POST', 'https://api.wordstat.yandex.net/v1/dynamics', {
        'phrase': 'замена аккумулятора iphone', 'period': 'monthly',
        'fromDate': '2024-11-01', 'toDate': '2026-10-31', 'regions': [22], 'devices': ['all']}),
}
RESULT = {'schema_version': 1, 'probe_id': 'appleservice39-yandex-access-20261113',
          'started_at_utc': datetime.now(timezone.utc).isoformat(),
          'server_writes': False, 'advertising_writes': False, 'database_opened': False,
          'token_exported': False, 'requests': [], 'errors': [],
          'wordstat': {'status': 'not_requested', 'requested': False, 'responses': {},
                       'demand_measurement': 'requires_response_review'}}


class Stop(Exception):
    def __init__(self, code, http=None, api_code=None):
        self.info = {'code': code}
        if http is not None: self.info['http'] = http
        if type(api_code) is int: self.info['api_code'] = api_code


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def load_local_config():
    """Retain the checked 122e556 loader's directory, origin and dotenv guards."""
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
        process = subprocess.run(['git', '--no-optional-locks', '-c', 'core.fsmonitor=false',
            '-c', 'core.hooksPath=/dev/null', '-C', str(ROOT), *args],
            capture_output=True, text=True, timeout=10, env=safe_env)
        if process.returncode or len(process.stdout) > 4096:
            raise Stop('PROJECT_IDENTITY_CHECK_FAILED')
        return process.stdout.strip()
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
    credential_pattern = r'(?:WORDSTAT_[A-Z0-9_]+|YANDEX_(?:WORDSTAT|CLOUD|SEARCH)_[A-Z0-9_]+|YC_[A-Z0-9_]*(?:TOKEN|KEY|SECRET|ID)[A-Z0-9_]*)'
    names = {'YANDEX_OAUTH_TOKEN'} | {key for key in set(cfg) | set(os.environ)
        if len(key) <= 80 and re.fullmatch(credential_pattern, key)}
    if len(names) > 30:
        raise Stop('CREDENTIAL_METADATA_BOUND')
    present = lambda key: isinstance(conf(key), str) and bool(conf(key).strip())
    RESULT['credential_presence'] = {key: present(key) for key in sorted(names)}
    token = conf('YANDEX_OAUTH_TOKEN')
    if not isinstance(token, str) or not token.strip() or re.search(r'\s', token):
        raise Stop('LOCAL_TOKEN_MISSING_OR_INVALID')
    dedicated = [key for key in WORDSTAT_KEYS if present(key)]
    selected = dedicated[0] if len(dedicated) == 1 else 'YANDEX_OAUTH_TOKEN'
    wordstat_token = conf(selected)
    if not isinstance(wordstat_token, str) or re.search(r'\s', wordstat_token):
        raise Stop('WORDSTAT_TOKEN_INVALID')
    RESULT['wordstat']['selected_token_key'] = selected
    RESULT['wordstat']['token_selection'] = 'single_configured_wordstat_token' if len(dedicated) == 1 else 'existing_yandex_oauth_token'
    RESULT['local_config_verified'] = True
    return token, wordstat_token, [conf(key) for key in names if present(key)]


def make_reader(tokens, deadline):
    # Default ProxyHandler and HTTPSHandler retain inherited proxy/CA settings.
    opener = urllib.request.build_opener(NoRedirect())
    token, wordstat_token, sensitive_values = tokens
    last_wordstat_start = None
    def safe_body(body):
        if isinstance(body, str):
            for secret in sensitive_values:
                if secret: body = body.replace(secret, '[REDACTED]')
            return body
        if isinstance(body, list): return [safe_body(value) for value in body]
        if isinstance(body, dict):
            return {safe_body(key): safe_body(value) for key, value in body.items()
                    if key.casefold() not in ('authorization', 'proxy-authorization')}
        return body
    def read(name):
        nonlocal last_wordstat_start
        if name not in ENDPOINTS or name in {r['endpoint'] for r in RESULT['requests']}:
            raise Stop('ENDPOINT_NOT_ALLOWED_OR_ALREADY_ATTEMPTED')
        remaining = deadline - time.monotonic()
        if remaining <= 0 or len(RESULT['requests']) >= 5:
            raise Stop('REQUEST_BUDGET')
        method, url, payload = ENDPOINTS[name]
        wordstat = name.startswith('wordstat_')
        if wordstat and last_wordstat_start is not None:
            pace = max(0, 1 - (time.monotonic() - last_wordstat_start))
            if pace >= remaining: raise Stop('TIME_BUDGET')
            time.sleep(pace)
        remaining = deadline - time.monotonic()
        if remaining <= 0: raise Stop('TIME_BUDGET')
        headers = {'Accept': 'application/json', 'Accept-Language': 'ru',
                   'Authorization': ('OAuth ' if name == 'metrika_counter' else 'Bearer ') + (wordstat_token if wordstat else token)}
        if method == 'POST': headers['Content-Type'] = 'application/json; charset=utf-8'
        if name == 'direct_campaign_access':
            headers.update({'Client-Login': CLIENT_LOGIN, 'Content-Type': 'application/json; charset=utf-8'})
        request = urllib.request.Request(url, data=json.dumps(payload, separators=(',', ':')).encode() if payload is not None else None,
                                         headers=headers, method=method)
        attempt = {'endpoint': name, 'method': method, 'http': None}
        RESULT['requests'].append(attempt)
        if wordstat:
            last_wordstat_start = time.monotonic()
            RESULT['wordstat']['requested'] = True
        try:
            with opener.open(request, timeout=min(15, remaining)) as response:
                status = response.status
                raw = response.read(MAX_BYTES + 1)
        except Stop:
            raise
        except urllib.error.HTTPError as error:
            status = error.code
            try: raw = error.read(MAX_BYTES + 1)
            finally: error.close()
        except Exception:
            raise Stop('HTTPS_READ_FAILED') from None
        attempt['http'] = status
        if len(raw) > MAX_BYTES:
            raise Stop('RESPONSE_TOO_LARGE', status)
        try:
            def invalid_constant(_): raise ValueError('nonfinite')
            body = json.loads(raw, parse_constant=invalid_constant)
        except (ValueError, UnicodeDecodeError):
            raise Stop('API_READ_REJECTED' if status != 200 else 'NON_JSON_RESPONSE', status) from None
        if status != 200:
            raise Stop('API_READ_REJECTED', status)
        if not isinstance(body, dict):
            raise Stop('INVALID_RESPONSE_SHAPE', status)
        if body.get('errors') or body.get('error'):
            error = body.get('error')
            raise Stop('API_RESPONSE_ERROR', status, error.get('error_code') if isinstance(error, dict) else None)
        if wordstat:
            RESULT['wordstat']['responses'][name] = {'http': status, 'request': payload, 'raw_json': safe_body(body)}
        return body
    return read


def collect(read):
    for endpoint in ('metrika_counter', 'direct_campaign_access'):
        try:
            body = read(endpoint)
            if endpoint == 'metrika_counter':
                counter = body.get('counter')
                if not isinstance(counter, dict) or type(counter.get('id')) is not int or counter['id'] != COUNTER:
                    raise Stop('EXACT_COUNTER_NOT_RETURNED')
                status, zone = counter.get('status'), counter.get('time_zone_name')
                if not isinstance(status, str) or not re.fullmatch('[A-Za-z_]{1,32}', status) or not isinstance(zone, str) or not re.fullmatch('[A-Za-z0-9_+/-]{1,64}', zone):
                    raise Stop('INVALID_COUNTER_METADATA')
                RESULT['metrika'] = {'access': 'confirmed', 'id': counter['id'], 'status': status, 'timezone': zone}
            else:
                result = body.get('result')
                campaigns = result.get('Campaigns') if isinstance(result, dict) else None
                if not isinstance(campaigns, list) or len(campaigns) > 1 or any(not isinstance(row, dict) or type(row.get('Id')) is not int or row['Id'] <= 0 for row in campaigns):
                    raise Stop('DIRECT_GET_RESULT_NOT_CONFIRMED')
                RESULT['direct'] = {'access': 'confirmed', 'campaign_rows_returned': len(campaigns), 'limit': 1}
        except Stop as error:
            RESULT['errors'].append({'stage': endpoint, **error.info})
    collect_wordstat(read)
    RESULT['status'] = 'COLLECTED' if not RESULT['errors'] else 'PARTIAL'


def collect_wordstat(read):
    try:
        tree = read('wordstat_regions')
        matches = []
        def visit(value, inherited_id=None):
            if isinstance(value, list):
                for child in value: visit(child)
            elif isinstance(value, dict):
                identity = value.get('regionId', value.get('id', inherited_id))
                label = value.get('name', value.get('label', value.get('regionName')))
                if str(identity) == '22' and isinstance(label, str) and label.casefold() in ('калининград', 'kaliningrad'):
                    matches.append({'id': 22, 'name': label})
                for key, child in value.items():
                    if key == '22' and isinstance(child, str) and child.casefold() in ('калининград', 'kaliningrad'):
                        matches.append({'id': 22, 'name': child})
                    if isinstance(child, (dict, list)): visit(child, key if key.isdigit() else None)
        visit(tree)
        if not matches: raise Stop('KALININGRAD_CITY_NOT_RETURNED')
        RESULT['wordstat']['region'] = matches[0]
        RESULT['wordstat']['region_schema'] = 'returned_id_22_and_exact_city_name'
    except Stop as error:
        RESULT['errors'].append({'stage': 'wordstat_regions', **error.info})
        RESULT['wordstat']['status'] = 'region_not_confirmed'
        return
    for endpoint, field in (('wordstat_top', 'topRequests'), ('wordstat_dynamics', 'dynamics')):
        try:
            body = read(endpoint); rows = body.get(field)
            if not isinstance(rows, list) or len(rows) > 10000:
                raise Stop('WORDSTAT_ARRAY_NOT_RETURNED')
            for row in rows:
                if not isinstance(row, dict) or type(row.get('count')) is not int or row['count'] < 0:
                    raise Stop('WORDSTAT_COUNT_INVALID')
                if endpoint == 'wordstat_top':
                    if not isinstance(row.get('phrase'), str) or not row['phrase']:
                        raise Stop('WORDSTAT_PHRASE_INVALID')
                else:
                    try: datetime.strptime(row.get('date', ''), '%Y-%m-%d')
                    except (ValueError, TypeError): raise Stop('WORDSTAT_DATE_INVALID') from None
                    share = row.get('share')
                    if type(share) not in (int, float) or not math.isfinite(share) or share < 0:
                        raise Stop('WORDSTAT_SHARE_INVALID')
            observation = RESULT['wordstat']['responses'][endpoint]
            observation.update(schema_status='documented_array_confirmed', rows=len(rows))
            if endpoint == 'wordstat_dynamics':
                actual = [row['date'] for row in rows]
                expected = [f'{2024 + (10 + i)//12}-{(10+i)%12+1:02}-01' for i in range(24)]
                observation['requested_24_months_complete'] = actual == expected
                observation['returned_dates'] = actual
            else:
                observation['period_validation'] = 'endpoint_declares_last_30_days_actual_freshness_not_independently_confirmed'
        except Stop as error:
            RESULT['errors'].append({'stage': endpoint, **error.info})
            if error.info.get('http') in (401, 403, 410, 429) or error.info['code'] in ('TIME_BUDGET', 'REQUEST_BUDGET'):
                break
    RESULT['wordstat']['status'] = 'access_denied_or_incomplete' if any(row.get('stage', '').startswith('wordstat_') for row in RESULT['errors']) else 'responses_collected_for_review'



def diagnose_dns():
    rows = []
    for host in ('api-metrika.yandex.net', 'api.direct.yandex.com', 'api.wordstat.yandex.net'):
        row = {'host': host}
        try:
            resolved = socket.getaddrinfo(host, 443, type=socket.SOCK_STREAM)
            row.update(status='resolved', address_count=len(resolved))
        except socket.gaierror as error:
            row.update(status='resolution_failed', error_class='gaierror', errno=error.errno)
        except Exception:
            row.update(status='resolution_failed', error_class='other')
        rows.append(row)
    RESULT['diagnostic_scope'] = 'DNS only; no API requests and no credential transmission'
    RESULT['dns'] = rows
    RESULT['status'] = 'COLLECTED'

if __name__ == '__main__':
    def timeout(*_):
        raise Stop('TIME_BUDGET')
    signal.signal(signal.SIGALRM, timeout)
    deadline = time.monotonic() + TOTAL_SECONDS
    signal.alarm(TOTAL_SECONDS)
    try:
        load_local_config()
        diagnose_dns()
    except Stop as error:
        RESULT['errors'].append(error.info); RESULT['status'] = 'READ_FAILED'
    except Exception:
        RESULT['errors'].append({'code': 'LOCAL_READER_FAILED'}); RESULT['status'] = 'READ_FAILED'
    finally:
        signal.alarm(0)
        RESULT['finished_at_utc'] = datetime.now(timezone.utc).isoformat()
        print(json.dumps(RESULT, ensure_ascii=False, allow_nan=False))
