"""Streaming read-only Direct account query export; SSH stdin, stdlib only.

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
CAMPAIGN_IDS = (116302082, 116302526, 116773442, 116862972, 116862975, 116862986, 116999383, 116999391, 116999395, 117002963, 119230848, 119232872, 119232873, 119607403, 710098637, 710099107, 710100086, 710140850, 710140851, 710149159, 710149160, 710149161, 710149162, 710149163, 710149164, 710156106, 710156162, 710165291, 710165327, 710171114, 710171115, 710183948, 710187715, 710283666, 710320185, 710737601, 710737605, 710737978, 710741085, 710753136, 710759897, 710764850, 710776420, 710776422, 710793156, 710793157, 710874696, 710895331, 713216424, 713231029, 713555035, 713747091, 713757657, 713759213, 713759214, 714260445, 714320748, 715091398, 715091443, 715096911, 715109284, 715137003, 715137014, 715331883)  # Parent fills verified search-active IDs from the account inventory.
FIRST = '2026-09-23'
LAST = '2026-10-22'
BASE = 'https://api.direct.yandex.com/json/v501/'
FIELDS = ('Date', 'CampaignId', 'AdGroupId', 'AdId', 'Query', 'Impressions', 'Clicks', 'Cost')
PAGE_SIZE = 20000
MAX_ROWS = 1000000
MAX_RESPONSE = 16 * 1024 * 1024
TOTAL_SECONDS = 840
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
                detail = str(error.get('error_detail', ''))
                detail = detail.replace(self.headers['Authorization'].removeprefix('Bearer '), '[REDACTED]')[:500]
                self.errors.append({'service': service, 'http_status': status, 'code': error.get('error_code'), 'detail': detail})
            except (UnicodeError, ValueError, AttributeError, TypeError):
                self.errors.append({'service': service, 'http_status': status, 'code': 'NON_JSON_ERROR'})
            fail('API_READ_REJECTED')
        fail('API_RETRY_EXHAUSTED')


BATCH_SIZE = 25


def emit(kind, **data):
    print(json.dumps({'event': kind, **data}, ensure_ascii=False,
                     separators=(',', ':'), allow_nan=False), flush=True)


def campaign_configuration(api):
    """Read only the explicit, inventory-derived scope; include all campaign types."""
    fields = ['CounterIds', 'BiddingStrategy', 'PriorityGoals', 'AttributionModel', 'PackageBiddingStrategy']
    campaigns, requests, read_problems = [], [], []
    for start in range(0, len(CAMPAIGN_IDS), 1000):
        ids = CAMPAIGN_IDS[start:start + 1000]
        try:
            response, request_id = api.post('campaigns', {'method': 'get', 'params': {
                'SelectionCriteria': {'Ids': list(ids)},
                'FieldNames': ['Id', 'Name', 'Type', 'State', 'Status', 'TimeZone', 'Currency'],
                'TextCampaignFieldNames': fields, 'UnifiedCampaignFieldNames': fields}})
        except SafeError as exc:
            if exc.code != 'API_READ_REJECTED':
                raise
            # Stats can retain removed/hidden IDs. Missing configuration must not
            # erase their independently available historical query data.
            read_problems.append({'campaign_ids': list(ids), 'problem': exc.code})
            continue
        result = response.get('result')
        batch = result.get('Campaigns') if isinstance(result, dict) else None
        if not isinstance(batch, list) or result.get('LimitedBy') is not None:
            fail('EXACT_CAMPAIGN_CONFIGURATION_UNAVAILABLE')
        if any(not isinstance(row, dict) or type(row.get('Id')) is not int for row in batch):
            fail('CAMPAIGN_CONFIGURATION_SCHEMA_MISMATCH')
        returned = [row['Id'] for row in batch]
        if len(returned) != len(set(returned)) or not set(returned).issubset(ids):
            fail('CAMPAIGN_CONFIGURATION_SCOPE_MISMATCH')
        campaigns.extend(batch)
        requests.append(request_id)
    campaigns.sort(key=lambda row: row['Id'])
    returned_ids = {row['Id'] for row in campaigns}
    missing_ids = sorted(set(CAMPAIGN_IDS) - returned_ids)
    return {'at_utc': stamp(), 'campaigns': campaigns, 'sha256': sha(campaigns),
            'request_ids': requests, 'requested_ids': list(CAMPAIGN_IDS),
            'missing_ids': missing_ids, 'configuration_complete': not missing_ids,
            'read_problems': read_problems}


def collect_batches(api, state):
    """Keep one page plus its predecessor in memory; runner checks global keys."""
    for index, start in enumerate(range(0, len(CAMPAIGN_IDS), BATCH_SIZE)):
        ids = CAMPAIGN_IDS[start:start + BATCH_SIZE]
        scope = {'batch_index': index, 'campaign_ids': list(ids), 'complete': False,
                 'rows': 0, 'pages': [], 'problem': None}
        state['batches'].append(scope)
        previous_keys = set()
        offset = 0
        try:
            while True:
                params = {'SelectionCriteria': {'DateFrom': FIRST, 'DateTo': LAST,
                          'Filter': [{'Field': 'CampaignId', 'Operator': 'IN',
                                      'Values': [str(cid) for cid in ids]}]},
                          'FieldNames': list(FIELDS), 'ReportType': 'SEARCH_QUERY_PERFORMANCE_REPORT',
                          'DateRangeType': 'CUSTOM_DATE', 'Format': 'TSV', 'IncludeVAT': 'NO',
                          'Page': {'Limit': PAGE_SIZE, 'Offset': offset},
                          'OrderBy': [{'Field': name, 'SortOrder': 'ASCENDING'}
                                      for name in FIELDS[:5]]}
                params['ReportName'] = 'AnyTour-account-demand-' + sha(params)[:32]
                body, request_id = api.post('reports', {'params': params}, report=True)
                rows = parse_tsv(body, set(ids), FIRST, LAST)
                keys = {tuple(row[field] for field in FIELDS[:5]) for row in rows}
                if len(keys) != len(rows) or keys.intersection(previous_keys):
                    fail('DUPLICATE_OR_DRIFTING_REPORT_PAGE')
                # Permit an empty cap probe; reject a nonempty page past the total cap.
                if state['row_count'] + len(rows) > MAX_ROWS:
                    fail('TOTAL_ROW_LIMIT_REACHED')
                receipt = {'offset': offset, 'rows': len(rows), 'request_id': request_id,
                           'rows_sha256': sha(rows)}
                emit('page', batch_index=index, campaign_ids=list(ids), **receipt, data=rows)
                scope['pages'].append(receipt)
                scope['rows'] += len(rows)
                state['row_count'] += len(rows)
                previous_keys = keys
                print(json.dumps({'stage': 'page', 'batch': index, 'offset': offset,
                                  'rows': len(rows)}, separators=(',', ':')), file=sys.stderr, flush=True)
                if len(rows) < PAGE_SIZE:
                    scope['complete'] = True
                    break
                offset += PAGE_SIZE
                if offset > MAX_ROWS:
                    fail('TOTAL_ROW_LIMIT_REACHED')
        except SafeError as exc:
            scope['problem'] = exc.code
            state['problems'].append(exc.code)
            emit('batch_coverage', **scope)
            if exc.code in {'TOTAL_TIME_BUDGET_REACHED', 'TOTAL_ROW_LIMIT_REACHED'}:
                raise
            continue
        emit('batch_coverage', **scope)


def main():
    started = time.monotonic()
    metadata = {'schema': 2, 'client_login': LOGIN, 'campaign_ids': list(CAMPAIGN_IDS),
                'date1': FIRST, 'date2': LAST, 'started_at_utc': stamp(),
                'read_only': True, 'production_changes': False, 'server_files_written': False,
                'database_opened': False, 'conversions_requested': False,
                'configuration_scope': 'CAMPAIGNS_GET_FIELDS_ONLY_PORTFOLIO_POINTERS_NOT_RESOLVED',
                'cost_basis': 'ACCOUNT_CURRENCY_EXCLUDING_VAT_NOT_MICROS',
                'page_size': PAGE_SIZE, 'batch_size': BATCH_SIZE, 'max_rows': MAX_ROWS,
                'scope': 'INVENTORY_DERIVED_CAMPAIGNS_WITH_SEARCH_ACTIVITY',
                'query_network_type': 'SEARCH', 'report_type': 'SEARCH_QUERY_PERFORMANCE_REPORT',
                'pagination_not_atomic_snapshot': True}
    emit('header', **metadata)
    state = {'status': 'FAILED', 'row_count': 0, 'batches': [], 'problems': [],
             'config_before': None, 'config_after': None, 'config_unchanged': None}
    api = None
    def timeout_handler(signum, frame):
        fail('TOTAL_TIME_BUDGET_REACHED')
    signal.signal(signal.SIGALRM, timeout_handler)
    signal.alarm(TOTAL_SECONDS)
    try:
        if (sys.argv[1:] or not CAMPAIGN_IDS or len(CAMPAIGN_IDS) != len(set(CAMPAIGN_IDS))
                or any(type(cid) is not int or cid <= 0 for cid in CAMPAIGN_IDS)):
            fail('EXPLICIT_INVENTORY_CAMPAIGN_SCOPE_REQUIRED')
        token, installed_sha = credentials()
        state['verified_project_sha'] = installed_sha
        api = API(token, started + TOTAL_SECONDS - 5)
        state['config_before'] = campaign_configuration(api)
        emit('configuration', phase='before', snapshot=state['config_before'])
        collect_batches(api, state)
        state['config_after'] = campaign_configuration(api)
        emit('configuration', phase='after', snapshot=state['config_after'])
        state['config_unchanged'] = state['config_before']['sha256'] == state['config_after']['sha256']
        state['config_stability_scope'] = 'RETURNED_CAMPAIGN_OBJECTS_NOT_MISSING_CAMPAIGNS'
        state['config_missing_ids'] = sorted(set(state['config_before']['missing_ids'])
                                          | set(state['config_after']['missing_ids']))
        state['configuration_coverage'] = {
            'status': 'PARTIAL' if state['config_missing_ids'] else 'COMPLETE',
            'complete': not state['config_missing_ids'],
            'requested_ids': list(CAMPAIGN_IDS),
            'missing_ids_before': state['config_before']['missing_ids'],
            'missing_ids_after': state['config_after']['missing_ids'],
            'comparison_scope': state['config_stability_scope']}
        if not state['config_unchanged']:
            state['problems'].append('CAMPAIGN_CONFIGURATION_CHANGED_DURING_COLLECTION')
        expected_batches = (len(CAMPAIGN_IDS) + BATCH_SIZE - 1) // BATCH_SIZE
        complete = len(state['batches']) == expected_batches and all(b['complete'] for b in state['batches'])
        state['status'] = 'COLLECTED' if complete and not state['problems'] else 'PARTIAL'
    except SafeError as exc:
        state['problems'].append(exc.code)
        state['status'] = 'PARTIAL' if state['row_count'] else 'FAILED'
    except Exception:
        state['problems'].append('COLLECTION_RUNTIME_OR_SCHEMA_FAILURE')
        state['status'] = 'PARTIAL' if state['row_count'] else 'FAILED'
    finally:
        signal.alarm(0)
    state['problems'] = sorted(set(state['problems']))
    state['finished_at_utc'] = stamp()
    state['http_attempts'] = api.calls if api else 0
    state['api_errors'] = api.errors if api else []
    state['elapsed_seconds'] = round(time.monotonic() - started, 2)
    emit('summary', **state)
    return 0 if state['status'] == 'COLLECTED' else 2


if __name__ == '__main__':
    raise SystemExit(main())

