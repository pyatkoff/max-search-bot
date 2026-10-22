"""Bounded read-only Direct demand export; intended for SSH stdin, stdlib only.

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
CAMPAIGNS = (714260445, 714320748)
FIRST = '2026-09-23'
LAST = '2026-10-22'
BASE = 'https://api.direct.yandex.com/json/v501/'
FIELDS = ('Date', 'CampaignId', 'AdGroupId', 'AdId', 'Query', 'Impressions', 'Clicks', 'Cost')
PAGE_SIZE = 1000
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


def parse_tsv(body, campaign_ids, first, last, *, max_rows=PAGE_SIZE):
    """Keep API cell strings exactly; validate nulls, scope and numeric bounds."""
    try:
        text = body.decode('utf-8-sig') if isinstance(body, bytes) else body
        reader = csv.DictReader(io.StringIO(text), delimiter='\t', strict=True)
        if reader.fieldnames != list(FIELDS):
            fail('REPORT_COLUMNS_MISMATCH')
        rows = []
        for row in reader:
            if len(rows) >= max_rows or set(row) != set(FIELDS) or any(v is None for v in row.values()):
                fail('REPORT_ROW_SHAPE_OR_LIMIT')
            day = date.fromisoformat(row['Date'])
            if day.isoformat() != row['Date'] or not first <= row['Date'] <= last:
                fail('REPORT_DATE_OUT_OF_SCOPE')
            if positive_id(row['CampaignId']) not in campaign_ids:
                fail('REPORT_CAMPAIGN_OUT_OF_SCOPE')
            positive_id(row['AdGroupId'], nullable=True)
            positive_id(row['AdId'], nullable=True)
            if not isinstance(row['Query'], str) or len(row['Query']) > 4096:
                fail('REPORT_QUERY_MALFORMED')
            number(row['Impressions'], integer=True)
            number(row['Clicks'], integer=True)
            number(row['Cost'])
            rows.append(dict(row))
        return rows
    except SafeError:
        raise
    except (ValueError, UnicodeError, csv.Error, TypeError):
        fail('REPORT_PARSE_FAILED')


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        fail('API_REDIRECT_REFUSED')


class API:
    def __init__(self, token, deadline):
        self.deadline = deadline
        self.calls = 0
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
            if (report and status in {201, 202}) or status in {429, 500, 502, 503, 504}:
                if attempt == 19:
                    fail('API_REPORT_PENDING_OR_RETRY_EXHAUSTED')
                try:
                    delay = min(20, max(1, int(headers.get('retryIn', '5'))))
                except (ValueError, TypeError):
                    delay = 5
                self.wait(delay)
                continue
            fail('API_READ_REJECTED')
        fail('API_RETRY_EXHAUSTED')


def campaign_configuration(api):
    fields = ['CounterIds', 'BiddingStrategy', 'PriorityGoals', 'AttributionModel', 'PackageBiddingStrategy']
    response, request_id = api.post('campaigns', {'method': 'get', 'params': {
        'SelectionCriteria': {'Ids': list(CAMPAIGNS)},
        'FieldNames': ['Id', 'Name', 'Type', 'State', 'Status', 'TimeZone', 'Currency'],
        'TextCampaignFieldNames': fields, 'UnifiedCampaignFieldNames': fields}})
    result = response.get('result')
    rows = result.get('Campaigns') if isinstance(result, dict) else None
    if not isinstance(rows, list) or result.get('LimitedBy') is not None or len(rows) != len(CAMPAIGNS):
        fail('EXACT_CAMPAIGN_CONFIGURATION_UNAVAILABLE')
    ids = []
    for row in rows:
        if not isinstance(row, dict) or type(row.get('Id')) is not int or row['Id'] not in CAMPAIGNS:
            fail('CAMPAIGN_CONFIGURATION_SCOPE_MISMATCH')
        if row.get('Type') not in {'TEXT_CAMPAIGN', 'UNIFIED_CAMPAIGN'}:
            fail('CAMPAIGN_CONFIGURATION_TYPE_UNSUPPORTED')
        ids.append(row['Id'])
    if len(set(ids)) != len(CAMPAIGNS):
        fail('CAMPAIGN_CONFIGURATION_DUPLICATE')
    rows = sorted(rows, key=lambda row: row['Id'])
    return {'at_utc': stamp(), 'campaigns': rows, 'sha256': sha(rows), 'request_id': request_id}


def export_queries(api, output):
    seen = set()
    for campaign_id in CAMPAIGNS:
        scope = {'campaign_id': campaign_id, 'complete': False, 'rows': 0, 'pages': [], 'problem': None}
        output['coverage']['campaigns'].append(scope)
        offset = 0
        while True:
            # Reserve one probe request at the cap to distinguish exact cap from truncation.
            params = {'SelectionCriteria': {'DateFrom': FIRST, 'DateTo': LAST,
                       'Filter': [{'Field': 'CampaignId', 'Operator': 'IN', 'Values': [str(campaign_id)]}]},
                      'FieldNames': list(FIELDS), 'ReportType': 'SEARCH_QUERY_PERFORMANCE_REPORT',
                      'DateRangeType': 'CUSTOM_DATE', 'Format': 'TSV', 'IncludeVAT': 'NO',
                      'Page': {'Limit': PAGE_SIZE, 'Offset': offset},
                      'OrderBy': [{'Field': name, 'SortOrder': 'ASCENDING'}
                                  for name in ('Date', 'CampaignId', 'AdGroupId', 'AdId', 'Query')]}
            params['ReportName'] = 'AnyTour-demand-' + sha(params)[:32]
            try:
                body, request_id = api.post('reports', {'params': params}, report=True)
                rows = parse_tsv(body, {campaign_id}, FIRST, LAST)
                page_keys = [tuple(row[field] for field in FIELDS[:5]) for row in rows]
                if len(set(page_keys)) != len(page_keys) or any(key in seen for key in page_keys):
                    fail('DUPLICATE_OR_DRIFTING_REPORT_PAGE')
                if len(output['raw_rows']) + len(rows) > MAX_ROWS:
                    fail('TOTAL_ROW_LIMIT_REACHED')
                seen.update(page_keys)
                output['raw_rows'].extend(rows)
                scope['rows'] += len(rows)
                scope['pages'].append({'offset': offset, 'rows': len(rows), 'request_id': request_id})
                print(json.dumps({'stage': 'report_page', 'campaign_id': campaign_id,
                                  'page': len(scope['pages']), 'rows': len(rows)}, separators=(',', ':')), file=sys.stderr, flush=True)
                if len(rows) < PAGE_SIZE:
                    scope['complete'] = True
                    break
                offset += PAGE_SIZE
                if offset > MAX_ROWS:
                    fail('TOTAL_ROW_LIMIT_REACHED')
            except SafeError as exc:
                scope['problem'] = exc.code
                output['problems'].append(exc.code)
                break
    output['coverage']['pagination_complete'] = (
        len(output['coverage']['campaigns']) == len(CAMPAIGNS)
        and all(row['complete'] for row in output['coverage']['campaigns']))


def self_test():
    """Meaningful offline parser, null, scope, bounds and credential tests."""
    header = '\t'.join(FIELDS) + '\n'
    row = ['2026-10-01', '714260445', '10', '--', 'отель с аквапарком', '12', '0', '0.00']
    assert parse_tsv(header + '\t'.join(row) + '\n', {714260445}, FIRST, LAST)[0]['AdId'] == '--'
    assert number('--') is None and number('0') == Decimal(0)
    assert number('1.25') == Decimal('1.25')
    rejected = 0
    cases = []
    for index, value in ((0, '2026-09-22'), (1, '999'), (2, '1.5'), (5, 'NaN'),
                         (6, '-1'), (6, '1.2'), (7, '1e9999')):
        broken = row.copy(); broken[index] = value
        cases.append(header + '\t'.join(broken) + '\n')
    cases.extend([header.replace('Cost', 'Unknown') + '\t'.join(row) + '\n',
                  header + '\t'.join(row[:-1]) + '\n'])
    for case in cases:
        try:
            parse_tsv(case, {714260445}, FIRST, LAST)
        except SafeError:
            rejected += 1
        else:
            raise AssertionError('invalid report accepted')
    try:
        parse_tsv(header + '\t'.join(row) + '\n', {714260445}, FIRST, LAST, max_rows=0)
    except SafeError:
        rejected += 1
    else:
        raise AssertionError('row limit bypassed')
    assert origin_matches('git@github.com:pyatkoff/yandex-direct-autopilot.git')
    assert not origin_matches('https://github.com/other/yandex-direct-autopilot.git')
    assert parse_env('READ_ONLY=true\nYANDEX_DIRECT_CLIENT_LOGIN=anytour2024tp\nYANDEX_OAUTH_TOKEN="FAKE_TEST_TOKEN"\nDB_PASSWORD=ignored') == 'FAKE_TEST_TOKEN'
    print(json.dumps({'self_test': 'passed', 'rejected_invalid_cases': rejected, 'network_called': False}))


def main():
    if sys.argv[1:] == ['--self-test']:
        self_test()
        return 0
    if sys.argv[1:]:
        print(json.dumps({'status': 'FAILED', 'problems': ['ARGUMENTS_NOT_SUPPORTED']}))
        return 2
    started = time.monotonic()
    output = {'schema': 1, 'status': 'FAILED', 'started_at_utc': stamp(),
              'client_login': LOGIN, 'campaign_ids': list(CAMPAIGNS), 'date1': FIRST, 'date2': LAST,
              'read_only': True, 'production_changes': False, 'server_files_written': False,
              'database_opened': False, 'conversions_requested': False,
              'cost_basis': 'ACCOUNT_CURRENCY_EXCLUDING_VAT_NOT_MICROS',
              'coverage': {'campaigns': [], 'pagination_complete': False,
                           'all_market_queries': False, 'includes_current_calendar_day': True,
                           'scope': 'DIRECT_EXPOSED_ROWS_FOR_TWO_EXACT_CAMPAIGNS',
                           'pagination_not_atomic_snapshot': True},
              'config_before': None, 'config_after': None, 'config_unchanged': None,
              'raw_rows': [], 'problems': []}
    api = None
    def timeout_handler(signum, frame):
        fail('TOTAL_TIME_BUDGET_REACHED')
    signal.signal(signal.SIGALRM, timeout_handler)
    signal.alarm(TOTAL_SECONDS)
    try:
        token, installed_sha = credentials()
        output['verified_project_sha'] = installed_sha
        api = API(token, started + TOTAL_SECONDS - 5)
        output['config_before'] = campaign_configuration(api)
        output['campaign_timezones'] = {str(row['Id']): row.get('TimeZone')
                                     for row in output['config_before']['campaigns']}
        export_queries(api, output)
        output['config_after'] = campaign_configuration(api)
        output['config_unchanged'] = output['config_before']['sha256'] == output['config_after']['sha256']
        if not output['config_unchanged']:
            output['problems'].append('CAMPAIGN_CONFIGURATION_CHANGED_DURING_COLLECTION')
        output['status'] = 'COLLECTED' if output['coverage']['pagination_complete'] and not output['problems'] else 'PARTIAL'
    except SafeError as exc:
        output['problems'].append(exc.code)
        output['status'] = 'PARTIAL' if output['raw_rows'] else 'FAILED'
    except Exception:
        output['problems'].append('COLLECTION_RUNTIME_OR_SCHEMA_FAILURE')
        output['status'] = 'PARTIAL' if output['raw_rows'] else 'FAILED'
    finally:
        signal.alarm(0)
    output['finished_at_utc'] = stamp()
    output['http_attempts'] = api.calls if api else 0
    output['row_count'] = len(output['raw_rows'])
    output['elapsed_seconds'] = round(time.monotonic() - started, 2)
    output['problems'] = sorted(set(output['problems']))
    print(json.dumps({'stage': 'finished', 'status': output['status'], 'rows': output['row_count'],
                      'http_attempts': output['http_attempts']}, separators=(',', ':')), file=sys.stderr)
    print(json.dumps(output, ensure_ascii=False, separators=(',', ':'), allow_nan=False))
    return 0 if output['status'] == 'COLLECTED' else 2


if __name__ == '__main__':
    raise SystemExit(main())
