"""Fixed-account, two-call, GET-method-only Direct feeds preflight.

Stream over the established strict-pinned SSH path; stdout is private input to
the encrypted report runner. No installed application imports or database use.
The project/env/credential checks below are copied unchanged from the accepted
2026-10-22 collector (3faf4f780de7b1dde85a9884c35ba3b45b84ec8b).
"""
from __future__ import annotations

from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import signal
import ssl
import subprocess
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, build_opener, HTTPSHandler, HTTPRedirectHandler, ProxyHandler

ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
REPOSITORY = 'pyatkoff/yandex-direct-autopilot'
LOGIN = 'anytour2024tp'
BASE = 'https://api.direct.yandex.com/json/v501/'
TASK_ID = 'direct-feed-preflight-20261026'
MAX_RESPONSE = 2 * 1024 * 1024
TOTAL_SECONDS = 180
ENV_NAMES = {'YANDEX_OAUTH_TOKEN', 'YANDEX_DIRECT_CLIENT_LOGIN', 'READ_ONLY', 'YANDEX_DIRECT_SANDBOX'}
CLIENT_PAYLOAD = {'method': 'get', 'params': {'FieldNames': ['Login', 'ClientId']}}
FEED_PAYLOAD = {'method': 'get', 'params': {
    'FieldNames': ['Id', 'Name', 'BusinessType', 'SourceType', 'Status',
                   'NumberOfItems', 'FilterSchema', 'CampaignIds'],
    'FileFeedFieldNames': ['Filename'],
    'Page': {'Limit': 50, 'Offset': 0}}}
FIXED_CALLS = (('clients', CLIENT_PAYLOAD), ('feeds', FEED_PAYLOAD))


class SafeError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


def fail(code):
    raise SafeError(code)


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



def encoded_payload(payload):
    try:
        return json.dumps(payload, ensure_ascii=False, sort_keys=True,
                          separators=(',', ':'), allow_nan=False).encode('utf-8')
    except (TypeError, ValueError):
        fail('REQUEST_PAYLOAD_INVALID')


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        fail('API_REDIRECT_REFUSED')


class API:
    """Exactly the two fixed read calls, in order; no automatic retry."""

    def __init__(self, token, deadline, report):
        self._token = token
        self.deadline = deadline
        self.calls = 0
        self.report = report
        self.opener = build_opener(
            ProxyHandler({}), HTTPSHandler(context=ssl.create_default_context()),
            NoRedirect())

    def post(self, service, payload):
        if self.calls >= len(FIXED_CALLS):
            fail('FIXED_REQUEST_BUDGET_EXHAUSTED')
        if not isinstance(payload, dict) or payload.get('method') != 'get':
            fail('READ_ONLY_FIXED_ENDPOINT_AND_PAYLOAD_REQUIRED')
        allowed_service, allowed_payload = FIXED_CALLS[self.calls]
        body = encoded_payload(payload)
        if service != allowed_service or body != encoded_payload(allowed_payload):
            fail('READ_ONLY_FIXED_ENDPOINT_AND_PAYLOAD_REQUIRED')
        remaining = self.deadline - time.monotonic()
        if remaining <= 0:
            fail('TOTAL_TIME_BUDGET_REACHED')
        request = Request(BASE + allowed_service, data=body, headers={
            'Authorization': 'Bearer ' + self._token,
            'Client-Login': LOGIN,
            'Accept-Language': 'ru',
            'Content-Type': 'application/json; charset=utf-8'}, method='POST')
        record = {'service': allowed_service, 'api_method': 'get',
                  'http': None, 'response_bytes': None}
        self.report['requests'].append(record)
        self.calls += 1
        try:
            with self.opener.open(request, timeout=max(1, min(30, remaining))) as response:
                status = response.status
                raw = response.read(MAX_RESPONSE + 1)
        except HTTPError as exc:
            status = exc.code
            try:
                raw = exc.read(MAX_RESPONSE + 1)
            finally:
                exc.close()
        except SafeError:
            raise
        except (URLError, OSError, TimeoutError):
            fail('API_READ_TRANSPORT_FAILED')
        record['http'] = status
        record['response_bytes'] = len(raw)
        if len(raw) > MAX_RESPONSE:
            fail('API_RESPONSE_OVERSIZED')
        try:
            decoded = raw.decode('utf-8-sig')
            if self._token in decoded:
                fail('TOKEN_IN_API_RESPONSE_REFUSED')
            result = json.loads(decoded)
        except SafeError:
            raise
        except (UnicodeError, ValueError):
            fail('API_RESPONSE_NOT_JSON')
        if not isinstance(result, dict):
            fail('API_RESPONSE_SHAPE_INVALID')
        if status != 200 or 'error' in result or 'errors' in result:
            error = result.get('error')
            if isinstance(error, dict):
                code = error.get('error_code')
                if type(code) is int or (isinstance(code, str) and re.fullmatch(r'[0-9]{1,10}', code)):
                    record['api_error_code'] = code
            fail('API_READ_REJECTED')
        if not isinstance(result.get('result'), dict):
            fail('API_RESULT_MISSING')
        return result


def collect(api, report):
    client_body = api.post('clients', CLIENT_PAYLOAD)
    report['responses']['clients'] = client_body
    clients = client_body['result'].get('Clients')
    if (not isinstance(clients, list) or len(clients) != 1
            or not isinstance(clients[0], dict)
            or clients[0].get('Login') != LOGIN
            or type(clients[0].get('ClientId')) is not int
            or clients[0]['ClientId'] <= 0):
        fail('EXACT_CLIENT_NOT_RETURNED')
    report['client_login_verified'] = True
    feed_body = api.post('feeds', FEED_PAYLOAD)
    report['responses']['feeds'] = feed_body
    feeds = feed_body['result'].get('Feeds')
    if not isinstance(feeds, list) or len(feeds) > 50:
        fail('FEED_CATALOG_SHAPE_OR_BOUND')
    seen = set()
    for feed in feeds:
        if not isinstance(feed, dict):
            fail('FEED_ROW_SHAPE_INVALID')
        fid = feed.get('Id')
        if type(fid) is not int or fid <= 0 or fid in seen:
            fail('FEED_ID_INVALID_OR_DUPLICATE')
        seen.add(fid)
        if not isinstance(feed.get('Name'), str):
            fail('FEED_NAME_INVALID')
        if feed.get('BusinessType') not in {
                'RETAIL', 'HOTELS', 'REALTY', 'AUTOMOBILES', 'FLIGHTS', 'OTHER'}:
            fail('FEED_BUSINESS_TYPE_INVALID')
        if feed.get('SourceType') not in {'FILE', 'URL'}:
            fail('FEED_SOURCE_TYPE_INVALID')
        if feed.get('Status') not in {'NEW', 'UPDATING', 'DONE', 'ERROR'}:
            fail('FEED_STATUS_INVALID')
        number = feed.get('NumberOfItems')
        if number is not None and (type(number) is not int or number < 0):
            fail('FEED_ITEM_COUNT_INVALID')
        campaigns = feed.get('CampaignIds')
        if campaigns is not None:
            if not isinstance(campaigns, dict) or set(campaigns) != {'Items'}:
                fail('FEED_CAMPAIGN_IDS_INVALID')
            items = campaigns['Items']
            if not isinstance(items, list) or any(type(value) is not int or value <= 0 for value in items):
                fail('FEED_CAMPAIGN_IDS_INVALID')
        file_feed = feed.get('FileFeed')
        if file_feed is not None and (not isinstance(file_feed, dict)
                or not isinstance(file_feed.get('Filename'), str)):
            fail('FEED_FILENAME_INVALID')
        if feed.get('FilterSchema') is not None and not isinstance(feed['FilterSchema'], str):
            fail('FEED_FILTER_SCHEMA_INVALID')
    # Keep the selected raw API response in the encrypted owner report. A
    # LimitedBy marker makes completeness unknown; do not silently paginate.
    report['responses']['feeds'] = feed_body
    report['feed_count'] = len(feeds)
    if feed_body['result'].get('LimitedBy') is not None:
        fail('FEED_CATALOG_TRUNCATED')
    report['feed_catalog_complete'] = True


def main():
    report = {'schema': 1, 'task_id': TASK_ID, 'client_login': LOGIN,
              'started_at_utc': stamp(), 'status': 'READ_FAILED',
              'server_writes': False, 'advertising_writes': False,
              'database_opened': False, 'token_exported': False,
              'client_login_verified': False, 'feed_catalog_complete': False,
              'requests': [], 'responses': {}, 'errors': []}
    token = None

    def timed_out(_signum, _frame):
        fail('TOTAL_TIME_BUDGET_REACHED')

    signal.signal(signal.SIGALRM, timed_out)
    signal.alarm(TOTAL_SECONDS)
    try:
        token, installed_sha = credentials()
        report['installed_sha'] = installed_sha
        report['local_config_verified'] = True
        api = API(token, time.monotonic() + TOTAL_SECONDS, report)
        collect(api, report)
        report['status'] = 'COLLECTED'
    except SafeError as exc:
        report['errors'].append({'code': exc.code})
    except Exception:
        report['errors'].append({'code': 'LOCAL_READER_FAILED'})
    finally:
        signal.alarm(0)
        report['finished_at_utc'] = stamp()
    encoded = json.dumps(report, ensure_ascii=False, allow_nan=False)
    if token and token in encoded:
        # Never emit a value derived from the secret, even into ciphertext.
        report['responses'] = {}
        report['errors'] = [{'code': 'TOKEN_EXPORT_GUARD_STOP'}]
        report['status'] = 'READ_FAILED'
        encoded = json.dumps(report, ensure_ascii=False, allow_nan=False)
    print(encoded)


if __name__ == '__main__':
    main()

