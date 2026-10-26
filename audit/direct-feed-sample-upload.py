"""Finite manual upload of one owner-requested, fixed CSV sample.

This is an explicit manual fixed-feed action, not a READ_ONLY autopilot method.
The application/config READ_ONLY flag must remain true. Execution admission is
the caller's separate review of this pinned source and exact request. No API
write other than this single feeds.add is reachable. This file itself is only
prepared locally until that review admits a run.
"""
from __future__ import annotations

import base64
import csv
from datetime import datetime, timezone
import hashlib
import hmac
import io
import json
import os
from pathlib import Path
import re
import signal
import ssl
import stat
import subprocess
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, build_opener, HTTPSHandler, HTTPRedirectHandler, ProxyHandler

ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
DATA_ROOT = Path('/var/www/anytoour/data')
REPOSITORY = 'pyatkoff/yandex-direct-autopilot'
LOGIN = 'anytour2024tp'
BASE = 'https://api.direct.yandex.com/json/v501/'
TASK_ID = 'direct-feed-sample-upload-20261026'
ENV_NAMES = {'YANDEX_OAUTH_TOKEN', 'YANDEX_DIRECT_CLIENT_LOGIN', 'READ_ONLY', 'YANDEX_DIRECT_SANDBOX'}
CSV_SHA256 = '9f77989c1002c081e5619b82055a913861f6ee2c9220d2a8263c525198742e80'
CSV_BYTES = 1526
REQUEST_B64 = 'eyJtZXRob2QiOiJhZGQiLCJwYXJhbXMiOnsiRmVlZHMiOlt7IkJ1c2luZXNzVHlwZSI6Ik9USEVSIiwiRmlsZUZlZWQiOnsiRGF0YSI6IlJHVnpkR2x1WVhScGIyNGdTVVFzUkdWemRHbHVZWFJwYjI0Z2JtRnRaU3hHYVc1aGJDQlZVa3dzU1cxaFoyVWdWVkpNTEZScGRHeGxEUXBCVkUxQldGQTFPQ3hUVlU1U1NWTkZJRkpsYldGc0lFSmxZV05vSU1LM0lOQ28wTERSZ05DOExkR04wTHZSakMzUXFOQzEwTG5SaFN4b2RIUndjem92TDIxaGVDNXlkUzlwWkRrM01EUXdORGczT0RGZk1sOWliM1EvYzNSaGNuUmhjSEE5ZTNsamJHbGtmVjl5WldkcGIyNWZlM0psWjJsdmJsOXBaSDFmWTJGdGNHRnBaMjVmZTJOaGJYQmhhV2R1WDJsa2ZWOWpkSGd4WDJWNVNuZGlNMDR3V0RKc2EwbHFiMmxPVkdkcFRFTktkbHB0V214amJEbHdXa05KTmtscFNqa3NhSFIwY0hNNkx5OWtNWGR2TjJ0aFpXeHdOV1ZqYXk1amJHOTFaR1p5YjI1MExtNWxkQzl6ZFc1eWFYTmxMWEpsYzI5eWRITXVZMjl0TFRFMk1URTVOelkxTlRNdlkyMXpMMk5oWTJobEwzWXlMelpoTW1abFkyUmpPR0psTldJdWFuQm5MekU1TWpCNE1UQTRNQzltYVhRdk9EQXZNV05sWlRVME9HWXlNekUyTXprNVpUQXdOVFZrTUdNMlpEQTBaVE14WlRrdWFuQm5MTkNmMEw3UXROQ3gwTDdSZ0NEUmd0R0QwWURRc0NEUXNpQlRWVTVTU1ZORklGSmxiV0ZzSUVKbFlXTm9EUXBCVkUxQldGQTFPU3hUVlU1U1NWTkZJRkpsYldGc0lGSmxjMjl5ZENEQ3R5RFFxTkN3MFlEUXZDM1JqZEM3MFl3dDBLalF0ZEM1MFlVc2FIUjBjSE02THk5dFlYZ3VjblV2YVdRNU56QTBNRFE0TnpneFh6SmZZbTkwUDNOMFlYSjBZWEJ3UFh0NVkyeHBaSDFmY21WbmFXOXVYM3R5WldkcGIyNWZhV1I5WDJOaGJYQmhhV2R1WDN0allXMXdZV2xuYmw5cFpIMWZZM1I0TVY5bGVVcDNZak5PTUZneWJHdEphbTlwVGxScmFVeERTblphYlZwc1kydzVjRnBEU1RaSmFVbzVMR2gwZEhCek9pOHZaREYzYnpkcllXVnNjRFZsWTJzdVkyeHZkV1JtY205dWRDNXVaWFF2YzNWdWNtbHpaUzF5WlhOdmNuUnpMbU52YlMweE5qRXhPVGMyTlRVekwyTnRjeTlqWVdOb1pTOTJNaTgyTkRka1lqUXpObVE0Wm1Sa0xtcHdaeTh4TWpBd2VEWXpNQzltYVhRdk9EQXZPREEzT0dNeE5XUXdNekkwWVdGbFlXRTVZemc1TWpVM05XVmlOV0l6WWpBdWFuQm5MTkNmMEw3UXROQ3gwTDdSZ0NEUmd0R0QwWURRc0NEUXNpQlRWVTVTU1ZORklGSmxiV0ZzSUZKbGMyOXlkQTBLUVZSTlFWaFFOakFzVW1WM1lYbGhJRWx1YmlCU1pYTnZjblFnd3JjZzBLWFJnOUdBMExQUXNOQzAwTEFzYUhSMGNITTZMeTl0WVhndWNuVXZhV1E1TnpBME1EUTROemd4WHpKZlltOTBQM04wWVhKMFlYQndQWHQ1WTJ4cFpIMWZjbVZuYVc5dVgzdHlaV2RwYjI1ZmFXUjlYMk5oYlhCaGFXZHVYM3RqWVcxd1lXbG5ibDlwWkgxZlkzUjRNVjlsZVVwM1lqTk9NRmd5Ykd0SmFtOXBUbXBCYVV4RFNuWmFiVnBzWTJ3NWNGcERTVFpKYVVvNUxHaDBkSEJ6T2k4dmNHbGpjeTVrWlhKMGIzVnlMbkp2TDJodmRHVnNjeTlsWjNsd2RDOXZZbXhoYzNRdGFIVnlaMmhoWkdFdmFIVnlaMmhoWkdFdmNtVjNZWGxoTFdsdWJpMXlaWE52Y25RdlJFcEpYekl3TWpVeE1URTJNVGN5TWpJMlh6QXhNVFpmUkM1cWNHY3MwSi9RdnRDMDBMSFF2dEdBSU5HQzBZUFJnTkN3SU5DeUlGSmxkMkY1WVNCSmJtNGdVbVZ6YjNKMERRcEJWRTFCV0ZBMk1TeFdJRWh2ZEdWc0lGTm9ZWEp0SUVWc0lGTm9aV2xyYUNEQ3R5RFFxTkN3MFlEUXZDM1JqZEM3MFl3dDBLalF0ZEM1MFlVc2FIUjBjSE02THk5dFlYZ3VjblV2YVdRNU56QTBNRFE0TnpneFh6SmZZbTkwUDNOMFlYSjBZWEJ3UFh0NVkyeHBaSDFmY21WbmFXOXVYM3R5WldkcGIyNWZhV1I5WDJOaGJYQmhhV2R1WDN0allXMXdZV2xuYmw5cFpIMWZZM1I0TVY5bGVVcDNZak5PTUZneWJHdEphbTlwVG1wRmFVeERTblphYlZwc1kydzVjRnBEU1RaSmFVbzVMR2gwZEhCek9pOHZkR2hsZG1odmRHVnNjMlZuZVhCMExtTnZiUzkzY0MxamIyNTBaVzUwTDNWd2JHOWhaSE12TWpBeU5TOHdPQzlXTFZOVFNGOHRVM2RwYlcxcGJtY3RVRzl2YkMweE1pMHhMbmRsWW5BczBKL1F2dEMwMExIUXZ0R0FJTkdDMFlQUmdOQ3dJTkN5SUZZZ1NHOTBaV3dnVTJoaGNtMGdSV3dnVTJobGFXdG9EUW89IiwiRmlsZW5hbWUiOiJBbnlUb3VyX01BWF9UcmF2ZWxfZmVlZF9zcGVjaW1lbl8yMDI2LTEwLTI2LmNzdiJ9LCJOYW1lIjoiQW55VG91ciBNQVggVHJhdmVsIHNwZWNpbWVuIDIwMjYxMDI2IDlmNzc5ODljIiwiU291cmNlVHlwZSI6IkZJTEUifV19fQ=='
REQUEST_SHA256 = '47be86231de8a9d086754e7839ac09b22300a5387121115be61cab6b4845a7e0'
REQUEST_CANONICAL = base64.b64decode(REQUEST_B64, validate=True)
JOURNAL_NAME = TASK_ID + '-' + CSV_SHA256[:16] + '.jsonl'
MAX_RESPONSE = 1_000_000
MAX_TOTAL_RESPONSE = 2_000_000
MAX_JOURNAL = 4_000_000
TOTAL_SECONDS = 150
MAX_CALLS = 9
CLIENT_FIELDS = ['Login', 'ClientId']
FEED_FIELDS = ['Id', 'Name', 'BusinessType', 'SourceType', 'Status',
               'NumberOfItems', 'FilterSchema', 'CampaignIds']
POSTFLIGHT_FIELDS = FEED_FIELDS + ['TitleAndTextSources']


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





def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True,
                      separators=(',', ':'), allow_nan=False).encode('utf-8')


def fixed_sample():
    if hashlib.sha256(REQUEST_CANONICAL).hexdigest() != REQUEST_SHA256:
        fail('FIXED_REQUEST_DIGEST_MISMATCH')
    request = json.loads(REQUEST_CANONICAL)
    if canonical(request) != REQUEST_CANONICAL or set(request) != {'method', 'params'}:
        fail('FIXED_REQUEST_SHAPE_INVALID')
    if request['method'] != 'add' or set(request['params']) != {'Feeds'}:
        fail('FIXED_ADD_REQUIRED')
    feeds = request['params']['Feeds']
    if not isinstance(feeds, list) or len(feeds) != 1:
        fail('EXACTLY_ONE_FEED_REQUIRED')
    feed = feeds[0]
    if set(feed) != {'Name', 'BusinessType', 'SourceType', 'FileFeed'}:
        fail('FIXED_FEED_FIELDS_REQUIRED')
    if (feed['Name'] != 'AnyTour MAX Travel specimen 20261026 9f77989c'
            or feed['BusinessType'] != 'OTHER' or feed['SourceType'] != 'FILE'
            or set(feed['FileFeed']) != {'Filename', 'Data'}
            or feed['FileFeed']['Filename'] != 'AnyTour_MAX_Travel_feed_specimen_2026-10-26.csv'):
        fail('FIXED_FEED_IDENTITY_MISMATCH')
    raw = base64.b64decode(feed['FileFeed']['Data'], validate=True)
    if len(raw) != CSV_BYTES or hashlib.sha256(raw).hexdigest() != CSV_SHA256:
        fail('FIXED_CSV_DIGEST_MISMATCH')
    if raw.startswith(b'\xef\xbb\xbf') or raw.count(b'\r\n') != 5:
        fail('FIXED_CSV_ENCODING_OR_RECORDS')
    reader = csv.DictReader(io.StringIO(raw.decode('utf-8'), newline=''), strict=True)
    if reader.fieldnames != ['Destination ID', 'Destination name', 'Final URL', 'Image URL', 'Title']:
        fail('FIXED_CSV_COLUMNS_MISMATCH')
    rows = list(reader)
    if len(rows) != 4:
        fail('FIXED_CSV_ROWS_MISMATCH')
    for row, post in zip(rows, ('58', '59', '60', '61')):
        # The accepted receiver requires this key order, rather than sorted JSON.
        context = json.dumps({'post_id': post, 'offer_id': ''}, separators=(',', ':')).encode()
        token = base64.urlsafe_b64encode(context).decode().rstrip('=')
        expected = ('https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}'
                    '_campaign_{campaign_id}_ctx1_' + token)
        if (row['Destination ID'] != 'ATMAXP' + post or row['Final URL'] != expected
                or not row['Image URL'].startswith('https://')
                or any(value is None or value == '' for value in row.values())):
            fail('FIXED_CSV_CONTEXT_MISMATCH')
    return feed


def campaign_links(feed):
    """Retain unknown wrapper/null states; only explicit Items proves a count."""
    if 'CampaignIds' not in feed:
        return {'state': 'NOT_RETURNED_UNKNOWN', 'count': None}
    value = feed['CampaignIds']
    if value is None:
        return {'state': 'NULL_UNKNOWN', 'count': None}
    if not isinstance(value, dict) or set(value) - {'Items'}:
        return {'state': 'INVALID_SHAPE_UNKNOWN', 'count': None}
    if 'Items' not in value:
        return {'state': 'WRAPPER_WITHOUT_ITEMS_UNKNOWN', 'count': None}
    items = value['Items']
    if not isinstance(items, list) or any(type(x) is not int or x <= 0 for x in items):
        return {'state': 'INVALID_ITEMS_UNKNOWN', 'count': None}
    return {'state': 'EXPLICIT_ITEMS', 'count': len(items)}


def reject_existing_operation():
    """A previous marker stops before any API read or write; no auto reconcile."""
    if DATA_ROOT.is_symlink() or DATA_ROOT.resolve() != DATA_ROOT:
        fail('PRIVATE_JOURNAL_ROOT_NOT_PROVEN')
    directory = DATA_ROOT
    for name in ('.codex-private', 'direct-feed-validation'):
        directory = directory / name
        try:
            info = directory.lstat()
        except FileNotFoundError:
            return
        if (not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid()
                or stat.S_IMODE(info.st_mode) != 0o700):
            fail('PRIVATE_JOURNAL_DIRECTORY_NOT_PRIVATE')
    try:
        (directory / JOURNAL_NAME).lstat()
    except FileNotFoundError:
        return
    fail('EXISTING_OPERATION_NO_ADD_RECONCILIATION_REQUIRED')


class OperationJournal:
    """Exclusive durable marker: its existence permanently forbids this add replay."""

    def __init__(self, report):
        self.report = report
        self.fd = None
        self.directory_fd = None
        self.attempt_fsynced = False

    def open(self):
        if (DATA_ROOT.is_symlink() or DATA_ROOT.resolve() != DATA_ROOT
                or not hasattr(os, 'O_NOFOLLOW') or not hasattr(os, 'O_DIRECTORY')):
            fail('PRIVATE_JOURNAL_ROOT_NOT_PROVEN')
        flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
        current = os.open(str(DATA_ROOT), flags)
        try:
            if os.fstat(current).st_uid != os.geteuid():
                fail('PRIVATE_JOURNAL_OWNER_MISMATCH')
            for name in ('.codex-private', 'direct-feed-validation'):
                try:
                    os.mkdir(name, 0o700, dir_fd=current)
                    self.report['server_writes'] = True
                    os.fsync(current)
                except FileExistsError:
                    pass
                child = os.open(name, flags, dir_fd=current)
                info = os.fstat(child)
                if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
                    os.close(child)
                    fail('PRIVATE_JOURNAL_DIRECTORY_NOT_PRIVATE')
                os.close(current)
                current = child
            self.directory_fd = current
            current = None
            try:
                self.fd = os.open(JOURNAL_NAME,
                    os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                    0o600, dir_fd=self.directory_fd)
            except FileExistsError:
                fail('EXISTING_OPERATION_NO_ADD_RECONCILIATION_REQUIRED')
            self.report['server_writes'] = True
            info = os.fstat(self.fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid()
                    or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1):
                fail('PRIVATE_JOURNAL_FILE_NOT_PRIVATE')
            os.fsync(self.directory_fd)
        finally:
            if current is not None:
                os.close(current)

    def append(self, event, **data):
        if self.fd is None:
            fail('PRIVATE_JOURNAL_NOT_OPEN')
        record = {'event': event, 'task_id': TASK_ID, 'client_login': LOGIN,
                  'at': stamp(), 'csv_sha256': CSV_SHA256,
                  'request_sha256': REQUEST_SHA256, **data}
        raw = canonical(record) + b'\n'
        if os.fstat(self.fd).st_size + len(raw) > MAX_JOURNAL:
            fail('PRIVATE_JOURNAL_SIZE_BOUND')
        offset = 0
        while offset < len(raw):
            written = os.write(self.fd, raw[offset:])
            if written <= 0:
                fail('PRIVATE_JOURNAL_WRITE_FAILED')
            offset += written
        os.fsync(self.fd)
        if event == 'attempt':
            self.attempt_fsynced = True

    def close(self):
        if self.fd is not None:
            os.close(self.fd)
            self.fd = None
        if self.directory_fd is not None:
            os.close(self.directory_fd)
            self.directory_fd = None


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        fail('API_REDIRECT_REFUSED')


class API:
    def __init__(self, token, report, deadline):
        self.token = token
        self.report = report
        self.deadline = deadline
        self.calls = 0
        self.response_bytes = 0
        self.accepted_id = None
        self.journal = None
        self.opener = build_opener(ProxyHandler({}),
            HTTPSHandler(context=ssl.create_default_context()), NoRedirect())

    def request(self, kind):
        if self.calls >= MAX_CALLS:
            fail('FIXED_REQUEST_BUDGET_EXHAUSTED')
        if kind == 'clients' and self.calls == 0:
            service, payload = 'clients', {'method': 'get', 'params': {'FieldNames': CLIENT_FIELDS}}
        elif kind == 'catalog' and self.calls == 1 and self.report['client_login_verified']:
            service, payload = 'feeds', {'method': 'get', 'params': {
                'FieldNames': FEED_FIELDS, 'FileFeedFieldNames': ['Filename'],
                'Page': {'Limit': 50, 'Offset': 0}}}
        elif (kind == 'add' and self.calls == 2 and self.report['preflight_complete']
                and self.journal is not None and self.journal.attempt_fsynced
                and self.report['feed_add_attempts'] == 0):
            service, payload = 'feeds', json.loads(REQUEST_CANONICAL)
            if canonical(payload) != REQUEST_CANONICAL:
                fail('FIXED_ADD_REQUEST_CHANGED')
        elif (kind == 'poll' and self.calls >= 3
                and type(self.accepted_id) is int and self.accepted_id > 0
                and self.report['feed_add_attempts'] == 1):
            service, payload = 'feeds', {'method': 'get', 'params': {
                'SelectionCriteria': {'Ids': [self.accepted_id]},
                'FieldNames': POSTFLIGHT_FIELDS,
                'FileFeedFieldNames': ['Filename']}}
        else:
            fail('FIXED_OPERATION_ORDER_OR_ADMISSION_REQUIRED')
        if service != 'feeds' and payload['method'] != 'get':
            fail('NON_FEED_WRITE_FORBIDDEN')
        remaining = self.deadline - time.monotonic()
        if remaining <= 0:
            fail('TOTAL_TIME_BUDGET_REACHED')
        request = Request(BASE + service, data=canonical(payload), headers={
            'Authorization': 'Bearer ' + self.token, 'Client-Login': LOGIN,
            'Accept-Language': 'ru', 'Content-Type': 'application/json; charset=utf-8'},
            method='POST')
        label = kind + '_' + str(self.calls + 1)
        record = {'kind': kind, 'service': service, 'api_method': payload['method'],
                  'http': None, 'response_bytes': None}
        self.report['requests'].append(record)
        self.calls += 1
        if kind == 'add':
            self.report['feed_add_attempts'] += 1
            self.report['advertising_writes'] = True
        try:
            with self.opener.open(request, timeout=max(1, min(15, remaining))) as response:
                status, raw = response.status, response.read(MAX_RESPONSE + 1)
        except HTTPError as exc:
            status = exc.code
            try:
                raw = exc.read(MAX_RESPONSE + 1)
            finally:
                exc.close()
        except SafeError:
            raise
        except (URLError, OSError, TimeoutError):
            fail('API_TRANSPORT_FAILED_NO_RETRY')
        record['http'], record['response_bytes'] = status, len(raw)
        self.response_bytes += len(raw)
        if len(raw) > MAX_RESPONSE or self.response_bytes > MAX_TOTAL_RESPONSE:
            fail('API_RESPONSE_BYTES_BOUND')
        try:
            text = raw.decode('utf-8-sig')
            if self.token in text:
                fail('TOKEN_IN_API_RESPONSE_REFUSED')
            body = json.loads(text, parse_constant=lambda _value: fail('API_NONFINITE_JSON'))
        except SafeError:
            raise
        except (UnicodeError, ValueError):
            fail('API_RESPONSE_NOT_JSON')
        if not isinstance(body, dict):
            fail('API_RESPONSE_SHAPE_INVALID')
        self.report['responses'][label] = body
        if status != 200 or 'error' in body or 'errors' in body:
            error = body.get('error')
            if isinstance(error, dict):
                code = error.get('error_code')
                if type(code) is int or (isinstance(code, str) and re.fullmatch(r'[0-9]{1,10}', code)):
                    record['api_error_code'] = code
            fail('API_REQUEST_REJECTED_NO_RETRY')
        if not isinstance(body.get('result'), dict):
            fail('API_RESULT_MISSING')
        return body


def verify_client(body):
    clients = body['result'].get('Clients')
    if (not isinstance(clients, list) or len(clients) != 1
            or not isinstance(clients[0], dict) or clients[0].get('Login') != LOGIN
            or type(clients[0].get('ClientId')) is not int or clients[0]['ClientId'] <= 0):
        fail('EXACT_CLIENT_NOT_RETURNED')
    return clients[0]['ClientId']


def verify_catalog(body, sample):
    result = body['result']
    feeds = result.get('Feeds')
    if not isinstance(feeds, list) or len(feeds) >= 50 or result.get('LimitedBy') is not None:
        fail('FEED_CAPACITY_OR_COMPLETENESS_NOT_PROVEN')
    ids = set()
    for feed in feeds:
        if not isinstance(feed, dict) or type(feed.get('Id')) is not int or feed['Id'] <= 0 or feed['Id'] in ids:
            fail('FEED_CATALOG_IDENTITY_INVALID')
        ids.add(feed['Id'])
        if not isinstance(feed.get('Name'), str) or feed.get('SourceType') not in {'FILE', 'URL'}:
            fail('FEED_CATALOG_METADATA_INVALID')
        file_feed = feed.get('FileFeed')
        filename = file_feed.get('Filename') if isinstance(file_feed, dict) else None
        if feed['Name'] == sample['Name'] or filename == sample['FileFeed']['Filename']:
            fail('MATCHING_FEED_ALREADY_EXISTS_NO_ADD')
        if feed['SourceType'] == 'FILE' and not isinstance(filename, str):
            fail('EXISTING_FILE_FEED_FILENAME_UNKNOWN_NO_ADD')
    return len(feeds)


def accepted_add_id(body):
    values = body['result'].get('AddResults')
    if not isinstance(values, list) or len(values) != 1 or not isinstance(values[0], dict):
        fail('ADD_RESULT_UNKNOWN_NO_RETRY')
    result = values[0]
    if result.get('Errors'):
        fail('ADD_ITEM_REJECTED_NO_RETRY')
    if type(result.get('Id')) is not int or result['Id'] <= 0:
        fail('ADD_ID_UNKNOWN_NO_RETRY')
    return result['Id']


def inspect_poll(body, accepted_id, sample):
    feeds = body['result'].get('Feeds')
    if (not isinstance(feeds, list) or len(feeds) != 1 or not isinstance(feeds[0], dict)
            or type(feeds[0].get('Id')) is not int or feeds[0]['Id'] != accepted_id):
        fail('EXACT_CREATED_FEED_NOT_RETURNED')
    feed = feeds[0]
    if (feed.get('Name') != sample['Name'] or feed.get('BusinessType') != 'OTHER'
            or feed.get('SourceType') != 'FILE'):
        fail('CREATED_FEED_IDENTITY_MISMATCH')
    file_feed = feed.get('FileFeed')
    if file_feed is not None and (not isinstance(file_feed, dict)
            or file_feed.get('Filename') != sample['FileFeed']['Filename']):
        fail('CREATED_FEED_FILENAME_MISMATCH')
    if feed.get('FilterSchema') is not None and not isinstance(feed['FilterSchema'], str):
        fail('CREATED_FEED_FILTER_SCHEMA_INVALID')
    state = feed.get('Status')
    if state not in {'NEW', 'UPDATING', 'DONE', 'ERROR'}:
        fail('CREATED_FEED_STATUS_UNKNOWN')
    links = campaign_links(feed)
    if links['count'] is not None and links['count'] > 0:
        fail('UNEXPECTED_CAMPAIGN_ASSOCIATION_STOP')
    return {'status': state, 'number_of_items': feed.get('NumberOfItems'),
            'campaign_links': links, 'filename_readback_verified': file_feed is not None}


def main():
    os.umask(0o077)
    report = {'schema': 1, 'task_id': TASK_ID, 'client_login': LOGIN,
              'started_at_utc': stamp(), 'status': 'STOPPED_NO_ADD',
              'manual_scope': 'FIXED_OWNER_SAMPLE_ONLY_NOT_AUTOPILOT_WRITE_MODE',
              'app_read_only_preserved': False, 'server_writes': False,
              'private_journal_only': True, 'advertising_writes': False,
              'campaign_writes': 0, 'goal_writes': 0, 'database_opened': False,
              'token_exported': False, 'feed_add_attempts': 0,
              'client_login_verified': False, 'preflight_complete': False,
              'csv_sha256': CSV_SHA256, 'csv_bytes': CSV_BYTES,
              'request_sha256': REQUEST_SHA256,
              'requests': [], 'responses': {}, 'errors': []}
    token, journal = None, OperationJournal(report)
    deadline = time.monotonic() + TOTAL_SECONDS

    def timed_out(_signum, _frame):
        fail('TOTAL_TIME_BUDGET_REACHED')

    signal.signal(signal.SIGALRM, timed_out)
    signal.alarm(TOTAL_SECONDS)
    try:
        sample = fixed_sample()
        token, installed = credentials()
        report['installed_sha'], report['app_read_only_preserved'] = installed, True
        reject_existing_operation()
        api = API(token, report, deadline)
        report['client_id'] = verify_client(api.request('clients'))
        report['client_login_verified'] = True
        report['existing_feed_count'] = verify_catalog(api.request('catalog'), sample)
        report['preflight_complete'] = True
        fresh_token, fresh_installed = credentials()
        if fresh_installed != installed or not hmac.compare_digest(fresh_token, token):
            fail('PRE_ADD_PROJECT_OR_CREDENTIAL_BINDING_DRIFT')
        journal.open()
        journal.append('intent', installed_sha=installed, client_id=report['client_id'],
                       feed_name=sample['Name'], filename=sample['FileFeed']['Filename'],
                       manual_scope=report['manual_scope'])
        journal.append('attempt', api_method='feeds.add', attempt=1)
        api.journal = journal
        added = api.request('add')
        accepted_id = accepted_add_id(added)
        report['accepted_feed_id'] = accepted_id
        api.accepted_id = accepted_id
        journal.append('accepted', accepted_feed_id=accepted_id, response=added)
        report['status'] = 'PROCESSING_PENDING_KNOWN_ID'
        for attempt in range(6):
            if attempt:
                if time.monotonic() + 5 >= deadline:
                    fail('POLL_TIME_BUDGET_REACHED')
                time.sleep(5)
            body = api.request('poll')
            observation = inspect_poll(body, accepted_id, sample)
            report['last_observation'] = observation
            journal.append('poll', poll=attempt + 1, accepted_feed_id=accepted_id,
                           observation=observation, response=body)
            if observation['status'] == 'ERROR':
                report['status'] = 'FEED_PROCESSING_ERROR_KNOWN_ID'
                break
            if observation['status'] == 'DONE':
                if type(observation['number_of_items']) is not int or observation['number_of_items'] != 4:
                    report['status'] = 'PROCESSED_ITEM_COUNT_MISMATCH_KNOWN_ID'
                elif observation['campaign_links']['state'] == 'EXPLICIT_ITEMS':
                    report['status'] = 'PROCESSED_4_ITEMS_UNLINKED'
                else:
                    report['status'] = 'PROCESSED_4_ITEMS_ASSOCIATION_UNKNOWN'
                break
        journal.append('terminal', status=report['status'],
                       accepted_feed_id=accepted_id, feed_add_attempts=report['feed_add_attempts'])
    except SafeError as exc:
        report['errors'].append({'code': exc.code})
        if report.get('accepted_feed_id') is not None:
            report['status'] = 'POSTFLIGHT_FAILED_KNOWN_ID_NO_ADD_RETRY'
        elif report['feed_add_attempts']:
            report['status'] = 'UNKNOWN_NO_ADD_RETRY'
        elif exc.code == 'EXISTING_OPERATION_NO_ADD_RECONCILIATION_REQUIRED':
            report['status'] = 'EXISTING_OPERATION_NO_ADD'
        if journal.fd is not None:
            try:
                journal.append('stopped', status=report['status'], code=exc.code,
                               accepted_feed_id=report.get('accepted_feed_id'),
                               feed_add_attempts=report['feed_add_attempts'])
            except Exception:
                report['journal_stop_receipt_failed'] = True
    except Exception:
        report['errors'].append({'code': 'LOCAL_UPLOADER_FAILED'})
        report['status'] = ('POSTFLIGHT_FAILED_KNOWN_ID_NO_ADD_RETRY'
                            if report.get('accepted_feed_id') is not None
                            else 'UNKNOWN_NO_ADD_RETRY' if report['feed_add_attempts']
                            else 'STOPPED_NO_ADD')
        if journal.fd is not None:
            try:
                journal.append('stopped', status=report['status'], code='LOCAL_UPLOADER_FAILED',
                               accepted_feed_id=report.get('accepted_feed_id'),
                               feed_add_attempts=report['feed_add_attempts'])
            except Exception:
                report['journal_stop_receipt_failed'] = True
    finally:
        signal.alarm(0)
        try:
            journal.close()
        except OSError:
            report['journal_close_failed'] = True
        report['finished_at_utc'] = stamp()
    encoded = json.dumps(report, ensure_ascii=False, allow_nan=False)
    if token and token in encoded:
        report['responses'] = {}
        report['errors'] = [{'code': 'TOKEN_EXPORT_GUARD_STOP'}]
        report['status'] = 'TOKEN_EXPORT_GUARD_STOP_NO_RETRY'
        encoded = json.dumps(report, ensure_ascii=False, allow_nan=False)
    print(encoded)


if __name__ == '__main__':
    main()
