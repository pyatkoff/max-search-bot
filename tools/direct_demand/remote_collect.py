"""Bounded read-only Direct account inventory; intended for SSH stdin, stdlib only.

No installed application imports, local database access, server file writes, or
advertising mutations. Credentials are read only from the verified project's .env.
"""
from __future__ import annotations

import csv
from datetime import date, datetime, timezone
from decimal import Decimal, InvalidOperation
import hashlib
import io
import json
import os
from pathlib import Path
import re
import signal
import ssl
import subprocess
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, build_opener, HTTPSHandler, HTTPRedirectHandler, ProxyHandler


ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
REPOSITORY = 'pyatkoff/yandex-direct-autopilot'
LOGIN = 'anytour2024tp'
FIRST = '2026-09-23'
LAST = '2026-10-22'
BASE = 'https://api.direct.yandex.com/json/v501/'
FIELDS = ('CampaignId', 'AdNetworkType', 'Impressions', 'Clicks', 'Cost')
STATES = ('ARCHIVED', 'CONVERTED', 'ENDED', 'OFF', 'ON', 'SUSPENDED')
CONFIG_PAGE_SIZE = 1000
PAGE_SIZE = 10000
MAX_ROWS = 100000
MAX_RESPONSE = 8 * 1024 * 1024
TOTAL_SECONDS = 590
ENV_NAMES = {'YANDEX_OAUTH_TOKEN', 'YANDEX_DIRECT_CLIENT_LOGIN', 'READ_ONLY', 'YANDEX_DIRECT_SANDBOX'}
NULLS = {'', '--'}


class SafeError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


def fail(code):
    raise SafeError(code)


def sha(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True,
        separators=(',', ':'), allow_nan=False).encode('utf-8')).hexdigest()


def stamp():
    return datetime.now(timezone.utc).isoformat()


def read_small(path, limit=1024 * 1024):
    if path.is_symlink() or not path.is_file() or path.resolve() != path:
        fail('PROJECT_FILE_IDENTITY_MISMATCH')
    with path.open('rb') as stream:
        raw = stream.read(limit + 1)
    if len(raw) > limit:
        fail('PROJECT_FILE_OVERSIZED')
    try:
        return raw.decode('utf-8-sig')
    except UnicodeError:
        fail('PROJECT_FILE_ENCODING')


def git(*args):
    try:
        result = subprocess.run(
            ['git', '--no-optional-locks', '-c', 'core.fsmonitor=false', '-c',
             'core.untrackedCache=false', '-C', str(ROOT), *args],
            env={'PATH': os.defpath, 'LANG': 'C', 'GIT_OPTIONAL_LOCKS': '0',
                 'GIT_CONFIG_GLOBAL': os.devnull, 'GIT_CONFIG_SYSTEM': os.devnull,
                 'GIT_TERMINAL_PROMPT': '0'},
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True,
            check=True, timeout=15)
        if len(result.stdout) > 16384:
            fail('PROJECT_GIT_OUTPUT_OVERSIZED')
        return result.stdout.strip()
    except (subprocess.SubprocessError, OSError):
        fail('PROJECT_GIT_CHECK_FAILED')


def origin_matches(value):
    if value.startswith('git@github.com:'):
        path = value[len('git@github.com:'):]
    else:
        parsed = urlsplit(value)
        if parsed.scheme not in {'https', 'ssh'} or parsed.hostname != 'github.com':
            return False
        path = parsed.path.lstrip('/')
    if path.endswith('.git'):
        path = path[:-4]
    return path == REPOSITORY


def parse_env(text):
    """Parse whitelisted dotenv assignments without interpolation or execution."""
    found = {}
    for line in text.splitlines():
        match = re.fullmatch(r'\s*(?:export\s+)?([A-Za-z_][A-Za-z_0-9]*)\s*=\s*(.*?)\s*', line)
        if not match:
            continue
        key, raw = match.groups()
        key = key.upper()
        if key not in ENV_NAMES:
            continue
        if key in found:
            fail('DUPLICATE_WHITELISTED_ENV_NAME')
        if raw.startswith(('"', "'")):
            quote = raw[0]
            match = re.fullmatch(re.escape(quote) + r'(.*?)' + re.escape(quote) + r'\s*(?:#.*)?', raw)
            if not match or '\\' in match.group(1):
                fail('UNSUPPORTED_WHITELISTED_ENV_VALUE')
            value = match.group(1)
        else:
            value = re.split(r'\s+#', raw, maxsplit=1)[0].strip()
        if '\n' in value or '\r' in value or len(value) > 8192:
            fail('INVALID_WHITELISTED_ENV_VALUE')
        found[key] = value
    if found.get('READ_ONLY', '').lower() != 'true':
        fail('EXPLICIT_READ_ONLY_REQUIRED')
    if found.get('YANDEX_DIRECT_SANDBOX', 'false').lower() != 'false':
        fail('PRODUCTION_API_REQUIRED')
    if found.get('YANDEX_DIRECT_CLIENT_LOGIN') != LOGIN:
        fail('EXACT_CLIENT_LOGIN_REQUIRED')
    token = found.get('YANDEX_OAUTH_TOKEN', '')
    if not token or token != token.strip() or any(ord(c) < 33 or ord(c) > 126 for c in token):
        fail('OAUTH_TOKEN_UNAVAILABLE_OR_MALFORMED')
    return token


def credentials():
    if not ROOT.is_absolute() or not ROOT.is_dir() or ROOT.resolve() != ROOT or ROOT.is_symlink():
        fail('PROJECT_ROOT_IDENTITY_MISMATCH')
    if Path(git('rev-parse', '--show-toplevel')).resolve() != ROOT:
        fail('PROJECT_GIT_ROOT_MISMATCH')
    if not origin_matches(git('config', '--get', 'remote.origin.url')):
        fail('PROJECT_REMOTE_MISMATCH')
    project = read_small(ROOT / 'pyproject.toml')
    section = re.search(r'(?ms)^\[project\]\s*\n(.*?)(?=^\[|\Z)', project)
    if not section or not re.search(r'''(?m)^name\s*=\s*['"]anytour-yandex-autopilot['"]\s*$''', section.group(1)):
        fail('PROJECT_PACKAGE_MISMATCH')
    installed_sha = git('rev-parse', 'HEAD')
    if not re.fullmatch(r'[0-9a-f]{40}', installed_sha):
        fail('PROJECT_SHA_MALFORMED')
    token = parse_env(read_small(ROOT / '.env'))
    return token, installed_sha


def positive_id(value, nullable=False):
    if nullable and value in NULLS:
        return None
    if not isinstance(value, str) or not re.fullmatch(r'[1-9][0-9]{0,18}', value):
        fail('REPORT_ID_MALFORMED')
    parsed = int(value)
    if parsed > 9223372036854775807:
        fail('REPORT_ID_OUT_OF_BOUNDS')
    return parsed


def number(value, integer=False, nullable=True):
    if nullable and value in NULLS:
        return None
    if not isinstance(value, str) or len(value) > 64:
        fail('REPORT_NUMBER_MALFORMED')
    try:
        parsed = Decimal(value)
    except InvalidOperation:
        fail('REPORT_NUMBER_MALFORMED')
    if not parsed.is_finite() or parsed < 0 or parsed > Decimal('1e18'):
        fail('REPORT_NUMBER_OUT_OF_BOUNDS')
    if integer and parsed != parsed.to_integral_value():
        fail('REPORT_COUNT_NOT_INTEGER')
    return parsed


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        fail('API_REDIRECT_REFUSED')


class API:
    def __init__(self, token, deadline):
        self.deadline = deadline
        self.calls = 0
        self.errors = []
        self.headers = {'Authorization': 'Bearer ' + token, 'Client-Login': LOGIN,
                        'Accept-Language': 'ru', 'Content-Type': 'application/json; charset=utf-8'}
        self.opener = build_opener(ProxyHandler({}), HTTPSHandler(context=ssl.create_default_context()), NoRedirect())

    def remaining(self):
        left = self.deadline - time.monotonic()
        if left <= 0:
            fail('TOTAL_TIME_BUDGET_REACHED')
        return left

    def wait(self, seconds):
        if seconds + 1 >= self.remaining():
            fail('TOTAL_TIME_BUDGET_REACHED')
        time.sleep(seconds)

    def post(self, service, payload, report=False):
        if service not in {'campaigns', 'reports'} or (service == 'campaigns' and payload.get('method') != 'get'):
            fail('READ_ONLY_ENDPOINT_GUARD')
        if report != (service == 'reports'):
            fail('READ_ONLY_ENDPOINT_GUARD')
        extra = {'processingMode': 'auto', 'returnMoneyInMicros': 'false',
                 'skipReportHeader': 'true', 'skipColumnHeader': 'false', 'skipReportSummary': 'true'} if report else {}
        request = Request(BASE + service,
                          data=json.dumps(payload, ensure_ascii=False, allow_nan=False).encode('utf-8'),
                          headers={**self.headers, **extra}, method='POST')
        for attempt in range(20):
            timeout = max(1, min(20, self.remaining()))
            self.calls += 1
            status, headers, body = None, {}, b''
            try:
                with self.opener.open(request, timeout=timeout) as response:
                    status = response.status
                    headers = response.headers
                    body = response.read(MAX_RESPONSE + 1)
            except HTTPError as exc:
                status = exc.code
                headers = exc.headers
                body = exc.read(MAX_RESPONSE + 1)
                exc.close()
            except (URLError, OSError, TimeoutError):
                if attempt == 19:
                    fail('API_NETWORK_UNAVAILABLE')
                self.wait(min(10, 1 + attempt))
                continue
            if len(body) > MAX_RESPONSE:
                fail('API_RESPONSE_OVERSIZED')
            if status == 200:
                if report:
                    return body, headers.get('RequestId', '')
                try:
                    result = json.loads(body.decode('utf-8-sig'))
                except (UnicodeError, ValueError):
                    fail('API_JSON_MALFORMED')
                if not isinstance(result, dict) or 'error' in result:
                    fail('API_READ_REJECTED')
                return result, headers.get('RequestId', '')
            rate_limited = False
            if status == 400:
                try:
                    rate_limited = str(json.loads(body.decode('utf-8-sig')).get('error', {}).get('error_code')) == '56'
                except (ValueError, UnicodeError, AttributeError, TypeError):
                    pass
            if rate_limited:
                if attempt == 19:
                    fail('API_RATE_LIMIT_RETRY_EXHAUSTED')
                self.wait(11)
                continue
            if (report and status in {201, 202}) or status in {429, 500, 502, 503, 504}:
                if attempt == 19:
                    fail('API_REPORT_PENDING_OR_RETRY_EXHAUSTED')
                try:
                    delay = min(20, max(1, int(headers.get('retryIn', '5'))))
                except (ValueError, TypeError):
                    delay = 5
                self.wait(delay)
                continue
            try:
                error = json.loads(body.decode('utf-8-sig')).get('error', {})
                raw_code = str(error.get('error_code', ''))
                code = raw_code if re.fullmatch(r'[0-9]{1,10}', raw_code) else 'UNAVAILABLE'
                self.errors.append({'service': service, 'http_status': int(status), 'code': code})
            except (UnicodeError, ValueError, AttributeError, TypeError):
                self.errors.append({'service': service, 'http_status': int(status), 'code': 'NON_JSON_ERROR'})
            fail('API_READ_REJECTED')
        fail('API_RETRY_EXHAUSTED')



def inventory(api):
    rows, seen, pages, offset = [], set(), [], 0
    complete = False
    problem = None
    fields = ['CounterIds', 'BiddingStrategy', 'PriorityGoals', 'AttributionModel', 'PackageBiddingStrategy']
    for _ in range(100):
        try:
            response, request_id = api.post('campaigns', {'method': 'get', 'params': {
                'SelectionCriteria': {'States': list(STATES)},
                'FieldNames': ['Id', 'Name', 'Type', 'State', 'Status', 'TimeZone', 'Currency'],
                'TextCampaignFieldNames': fields, 'UnifiedCampaignFieldNames': fields,
                'Page': {'Limit': CONFIG_PAGE_SIZE, 'Offset': offset}}})
            result = response.get('result')
            page = result.get('Campaigns') if isinstance(result, dict) else None
            if not isinstance(page, list) or len(page) > CONFIG_PAGE_SIZE:
                fail('CAMPAIGN_PAGE_MALFORMED')
            new_ids = []
            for item in page:
                if not isinstance(item, dict) or type(item.get('Id')) is not int or item['Id'] <= 0:
                    fail('CAMPAIGN_ID_MALFORMED')
                if item['Id'] in seen or item['Id'] in new_ids:
                    fail('CAMPAIGN_PAGE_DUPLICATE_OR_DRIFT')
                if item.get('State') not in STATES:
                    fail('CAMPAIGN_STATE_NOT_REQUESTED')
                new_ids.append(item['Id'])
            if len(rows) + len(page) > MAX_ROWS:
                fail('CAMPAIGN_INVENTORY_LIMIT')
            rows.extend(page); seen.update(new_ids)
            pages.append({'offset': offset, 'rows': len(page), 'request_id': request_id})
            limited = result.get('LimitedBy')
            if limited is None:
                complete = True
                break
            if type(limited) is not int or limited != offset + len(page) or not page:
                fail('CAMPAIGN_CURSOR_INCONSISTENT')
            offset = limited
        except SafeError as exc:
            problem = exc.code
            break
    else:
        problem = 'CAMPAIGN_PAGE_BUDGET_REACHED'
    rows.sort(key=lambda item: item['Id'])
    return {'at_utc': stamp(), 'campaigns': rows, 'sha256': sha(rows),
            'pages': pages, 'complete': complete, 'problem': problem,
            'states_requested': list(STATES), 'archived_and_converted_explicitly_included': True,
            'configuration_fields_scope': 'COMMON_ALL_TYPES_TEXT_AND_UNIFIED_STRATEGY_FIELDS_PORTFOLIO_POINTERS'}


def parse_stats(body, max_rows=PAGE_SIZE):
    try:
        text = body.decode('utf-8-sig') if isinstance(body, bytes) else body
        reader = csv.DictReader(io.StringIO(text), delimiter='\t', strict=True)
        if reader.fieldnames != list(FIELDS):
            fail('STATS_COLUMNS_MISMATCH')
        rows, keys = [], set()
        for row in reader:
            if len(rows) >= max_rows or set(row) != set(FIELDS) or any(value is None for value in row.values()):
                fail('STATS_ROW_SHAPE_OR_LIMIT')
            positive_id(row['CampaignId'])
            surface = row['AdNetworkType']
            if not isinstance(surface, str) or not re.fullmatch(r'[A-Z_]{1,64}', surface):
                fail('STATS_SURFACE_MALFORMED')
            number(row['Impressions'], integer=True); number(row['Clicks'], integer=True); number(row['Cost'])
            key = (row['CampaignId'], surface)
            if key in keys:
                fail('STATS_PAGE_DUPLICATE')
            keys.add(key); rows.append(dict(row))
        return rows
    except SafeError:
        raise
    except (ValueError, UnicodeError, csv.Error, TypeError):
        fail('STATS_PARSE_FAILED')


def account_stats(api, result):
    rows, seen, pages, offset = result['raw_stats'], set(), [], 0
    coverage = result['stats_coverage']
    for _ in range(101):
        params = {'SelectionCriteria': {'DateFrom': FIRST, 'DateTo': LAST},
                  'FieldNames': list(FIELDS), 'ReportType': 'CAMPAIGN_PERFORMANCE_REPORT',
                  'DateRangeType': 'CUSTOM_DATE', 'Format': 'TSV', 'IncludeVAT': 'NO',
                  'Page': {'Limit': PAGE_SIZE, 'Offset': offset},
                  'OrderBy': [{'Field': 'CampaignId', 'SortOrder': 'ASCENDING'},
                              {'Field': 'AdNetworkType', 'SortOrder': 'ASCENDING'}]}
        params['ReportName'] = 'AnyTour-account-inventory-' + sha(params)[:24]
        try:
            body, request_id = api.post('reports', {'params': params}, report=True)
            page = parse_stats(body)
            keys = {(row['CampaignId'], row['AdNetworkType']) for row in page}
            if keys & seen:
                fail('STATS_DRIFT_OR_DUPLICATE_PAGE')
            if len(rows) + len(page) > MAX_ROWS:
                fail('STATS_TOTAL_ROW_LIMIT')
            rows.extend(page); seen.update(keys)
            pages.append({'offset': offset, 'rows': len(page), 'request_id': request_id})
            if len(page) < PAGE_SIZE:
                coverage['complete'] = True
                break
            offset += PAGE_SIZE
        except SafeError as exc:
            coverage['problem'] = exc.code
            break
    else:
        coverage['problem'] = 'STATS_PAGE_BUDGET_REACHED'
    coverage['pages'] = pages
    result['campaign_ids_with_search_impressions'] = sorted({int(row['CampaignId']) for row in rows
        if row['AdNetworkType'] == 'SEARCH' and number(row['Impressions']) is not None
        and number(row['Impressions']) > 0})
    result['campaign_ids_with_search_clicks'] = sorted({int(row['CampaignId']) for row in rows
        if row['AdNetworkType'] == 'SEARCH' and number(row['Clicks']) is not None
        and number(row['Clicks']) > 0})


def self_test():
    header = '\t'.join(FIELDS) + '\n'
    valid = header + '123\tSEARCH\t12\t0\t0.00\n456\tAD_NETWORK\t--\t--\t--\n'
    assert len(parse_stats(valid)) == 2 and parse_stats(valid)[1]['Cost'] == '--'
    rejected = 0
    for bad in (valid.replace('12\t0', '-1\t0'), valid.replace('0.00', 'NaN'),
                valid.replace('Cost', 'Wrong'), valid + '123\tSEARCH\t1\t1\t1\n'):
        try:
            parse_stats(bad)
        except SafeError:
            rejected += 1
        else:
            raise AssertionError('bad statistics accepted')
    class Fake:
        def post(self, service, payload, report=False):
            assert service == 'campaigns' and payload['method'] == 'get'
            params = payload['params']
            assert set(params['SelectionCriteria']['States']) == set(STATES)
            offset = params['Page']['Offset']
            if offset == 0:
                return {'result': {'Campaigns': [{'Id': 1, 'State': 'ARCHIVED'}], 'LimitedBy': 1}}, 'fake1'
            assert offset == 1
            return {'result': {'Campaigns': [{'Id': 2, 'State': 'CONVERTED'}]}}, 'fake2'
    inv = inventory(Fake())
    assert inv['complete'] and len(inv['campaigns']) == 2 and len(inv['pages']) == 2
    assert not inv['problem']
    print(json.dumps({'self_test': 'passed', 'invalid_stats_rejected': rejected,
                      'archived_converted_pagination': 'passed', 'network_called': False}))


def main():
    if sys.argv[1:] == ['--self-test']:
        self_test(); return 0
    if sys.argv[1:]:
        print(json.dumps({'status': 'FAILED', 'problems': ['ARGUMENTS_NOT_SUPPORTED']})); return 2
    started = time.monotonic()
    result = {'schema': 1, 'status': 'FAILED', 'started_at_utc': stamp(), 'client_login': LOGIN,
              'scope': 'ALL_ACCESSIBLE_CAMPAIGNS_OF_EXACT_CLIENT_LOGIN', 'date1': FIRST, 'date2': LAST,
              'stats_granularity': 'PERIOD_TOTAL_BY_CAMPAIGN_AND_AD_NETWORK_TYPE',
              'read_only': True, 'database_opened': False, 'server_files_written': False,
              'production_changes': False, 'conversions_requested': False,
              'cost_basis': 'ACCOUNT_CURRENCY_EXCLUDING_VAT_NOT_MICROS',
              'config_before': None, 'config_after': None, 'config_unchanged': None,
              'raw_stats': [], 'stats_coverage': {'complete': False, 'problem': None, 'pages': []},
              'campaign_ids_with_search_impressions': [], 'campaign_ids_with_search_clicks': [],
              'problems': []}
    api = None
    def alarm(signum, frame):
        fail('TOTAL_TIME_BUDGET_REACHED')
    signal.signal(signal.SIGALRM, alarm); signal.alarm(TOTAL_SECONDS)
    try:
        token, installed_sha = credentials()
        result['verified_project_sha'] = installed_sha
        api = API(token, started + TOTAL_SECONDS - 5)
        result['config_before'] = inventory(api)
        account_stats(api, result)
        result['config_after'] = inventory(api)
        result['config_unchanged'] = result['config_before']['sha256'] == result['config_after']['sha256']
        for inv in (result['config_before'], result['config_after']):
            if not inv['complete']:
                result['problems'].append(inv['problem'] or 'INVENTORY_INCOMPLETE')
        if not result['stats_coverage']['complete']:
            result['problems'].append(result['stats_coverage']['problem'] or 'STATS_INCOMPLETE')
        if not result['config_unchanged']:
            result['problems'].append('CAMPAIGN_CONFIGURATION_CHANGED_DURING_COLLECTION')
        ids = {item['Id'] for item in result['config_before']['campaigns'] + result['config_after']['campaigns']}
        result['stats_campaign_ids_outside_inventory'] = sorted({int(row['CampaignId']) for row in result['raw_stats']} - ids)
        if result['stats_campaign_ids_outside_inventory']:
            result['problems'].append('STATS_CAMPAIGNS_OUTSIDE_INVENTORY')
        result['status'] = 'COLLECTED' if not result['problems'] else 'PARTIAL'
    except SafeError as exc:
        result['problems'].append(exc.code)
        result['status'] = 'PARTIAL' if result['raw_stats'] or result['config_before'] else 'FAILED'
    except Exception:
        result['problems'].append('INVENTORY_RUNTIME_OR_SCHEMA_FAILURE')
        result['status'] = 'PARTIAL' if result['raw_stats'] or result['config_before'] else 'FAILED'
    finally:
        signal.alarm(0)
    result['finished_at_utc'] = stamp()
    result['http_attempts'] = api.calls if api else 0
    result['api_errors'] = api.errors if api else []
    result['stats_row_count'] = len(result['raw_stats'])
    result['elapsed_seconds'] = round(time.monotonic() - started, 2)
    result['problems'] = sorted(set(result['problems']))
    print(json.dumps({'stage': 'finished', 'status': result['status'], 'stats_rows': result['stats_row_count'],
                      'http_attempts': result['http_attempts']}, separators=(',', ':')), file=sys.stderr)
    print(json.dumps(result, ensure_ascii=False, separators=(',', ':'), allow_nan=False))
    return 0 if result['status'] == 'COLLECTED' else 2


if __name__ == '__main__':
    raise SystemExit(main())
