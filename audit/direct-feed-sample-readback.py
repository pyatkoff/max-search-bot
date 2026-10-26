"""Read-only processing receipt for the one previously accepted sample feed.

The feed ID is derived only from its fixed private operation journal. This
reader never creates, appends, locks, resets or replays an operation. Stdout is
private input to the established encrypted SSH report runner.
"""
from __future__ import annotations

from datetime import datetime, timezone
import hashlib
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
TASK_ID = 'direct-feed-sample-readback-20261026'
UPLOAD_TASK_ID = 'direct-feed-sample-upload-20261026'
CSV_SHA256 = '9f77989c1002c081e5619b82055a913861f6ee2c9220d2a8263c525198742e80'
REQUEST_SHA256 = '47be86231de8a9d086754e7839ac09b22300a5387121115be61cab6b4845a7e0'
JOURNAL_NAME = UPLOAD_TASK_ID + '-' + CSV_SHA256[:16] + '.jsonl'
EXPECTED_NAME = 'AnyTour MAX Travel specimen 20261026 9f77989c'
EXPECTED_FILENAME = 'AnyTour_MAX_Travel_feed_specimen_2026-10-26.csv'
ENV_NAMES = {'YANDEX_OAUTH_TOKEN', 'YANDEX_DIRECT_CLIENT_LOGIN', 'READ_ONLY', 'YANDEX_DIRECT_SANDBOX'}
MAX_JOURNAL = 4_000_000
MAX_RESPONSE = 1_000_000
MAX_TOTAL_RESPONSE = 2_000_000
MAX_CALLS = 7
TOTAL_SECONDS = 220
FIELDS = ['Id', 'Name', 'BusinessType', 'SourceType', 'Status',
          'NumberOfItems', 'FilterSchema', 'CampaignIds', 'TitleAndTextSources']


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







def parse_journal(raw):
    if not raw or len(raw) > MAX_JOURNAL or not raw.endswith(b'\n'):
        fail('OPERATION_JOURNAL_INCOMPLETE_OR_OVERSIZED')
    try:
        rows = [json.loads(line, parse_constant=lambda _value: fail('JOURNAL_NONFINITE_JSON'))
                for line in raw.decode('utf-8').splitlines()]
    except SafeError:
        raise
    except (UnicodeError, ValueError):
        fail('OPERATION_JOURNAL_INVALID_JSON')
    if not rows or len(rows) > 32:
        fail('OPERATION_JOURNAL_RECORD_BOUND')
    accepted_rows, ids, events = [], set(), []
    for row in rows:
        if (not isinstance(row, dict) or row.get('task_id') != UPLOAD_TASK_ID
                or row.get('client_login') != LOGIN
                or row.get('csv_sha256') != CSV_SHA256
                or row.get('request_sha256') != REQUEST_SHA256):
            fail('OPERATION_JOURNAL_SCOPE_MISMATCH')
        event = row.get('event')
        if event not in {'intent', 'attempt', 'accepted', 'poll', 'terminal', 'stopped'}:
            fail('OPERATION_JOURNAL_EVENT_INVALID')
        events.append(event)
        if 'accepted_feed_id' in row and row['accepted_feed_id'] is not None:
            fid = row['accepted_feed_id']
            if type(fid) is not int or fid <= 0:
                fail('OPERATION_JOURNAL_FEED_ID_INVALID')
            ids.add(fid)
        if event == 'accepted':
            accepted_rows.append(row)
    if (events[:3] != ['intent', 'attempt', 'accepted']
            or events.count('intent') != 1 or events.count('attempt') != 1
            or len(accepted_rows) != 1 or len(ids) != 1):
        fail('EXACT_SINGLE_ACCEPTED_OPERATION_NOT_PROVEN')
    intent, attempt, accepted = rows[0], rows[1], accepted_rows[0]
    if (intent.get('feed_name') != EXPECTED_NAME
            or intent.get('filename') != EXPECTED_FILENAME
            or attempt.get('api_method') != 'feeds.add' or attempt.get('attempt') != 1):
        fail('OPERATION_JOURNAL_FIXED_REQUEST_NOT_PROVEN')
    fid = next(iter(ids))
    if accepted.get('accepted_feed_id') != fid:
        fail('OPERATION_JOURNAL_ACCEPTED_ID_MISMATCH')
    response = accepted.get('response')
    results = response.get('result', {}).get('AddResults') if isinstance(response, dict) else None
    if (not isinstance(results, list) or len(results) != 1
            or not isinstance(results[0], dict)
            or type(results[0].get('Id')) is not int or results[0]['Id'] != fid
            or results[0].get('Errors')):
        fail('OPERATION_JOURNAL_API_ACCEPTANCE_NOT_PROVEN')
    return fid, {'records': len(rows), 'bytes': len(raw),
                 'sha256': hashlib.sha256(raw).hexdigest(),
                 'accepted_event_count': 1, 'unique_accepted_id_count': 1}


def read_accepted_id():
    """Descriptor-relative read, no symlink following and no filesystem writes."""
    if (DATA_ROOT.is_symlink() or DATA_ROOT.resolve() != DATA_ROOT
            or not hasattr(os, 'O_NOFOLLOW') or not hasattr(os, 'O_DIRECTORY')):
        fail('PRIVATE_JOURNAL_ROOT_NOT_PROVEN')
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    current, journal = os.open(str(DATA_ROOT), flags), None
    try:
        if os.fstat(current).st_uid != os.geteuid():
            fail('PRIVATE_JOURNAL_OWNER_MISMATCH')
        for name in ('.codex-private', 'direct-feed-validation'):
            child = os.open(name, flags, dir_fd=current)
            info = os.fstat(child)
            if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
                os.close(child)
                fail('PRIVATE_JOURNAL_DIRECTORY_NOT_PRIVATE')
            os.close(current)
            current = child
        journal = os.open(JOURNAL_NAME, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=current)
        before = os.fstat(journal)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != os.geteuid()
                or stat.S_IMODE(before.st_mode) != 0o600 or before.st_nlink != 1
                or before.st_size <= 0 or before.st_size > MAX_JOURNAL):
            fail('PRIVATE_JOURNAL_FILE_NOT_PRIVATE_OR_BOUND')
        chunks, size = [], 0
        while True:
            part = os.read(journal, min(65536, MAX_JOURNAL + 1 - size))
            if not part:
                break
            size += len(part)
            if size > MAX_JOURNAL:
                fail('PRIVATE_JOURNAL_SIZE_BOUND')
            chunks.append(part)
        after = os.fstat(journal)
        current_path = os.stat(JOURNAL_NAME, dir_fd=current, follow_symlinks=False)
        identity = lambda value: (value.st_dev, value.st_ino, value.st_size,
                                   value.st_mtime_ns, value.st_ctime_ns,
                                   value.st_uid, stat.S_IMODE(value.st_mode), value.st_nlink)
        if (identity(before) != identity(after) or identity(after) != identity(current_path)
                or size != before.st_size):
            fail('PRIVATE_JOURNAL_CHANGED_DURING_READ')
        return parse_journal(b''.join(chunks))
    except FileNotFoundError:
        fail('PRIVATE_OPERATION_JOURNAL_NOT_FOUND')
    except OSError:
        fail('PRIVATE_OPERATION_JOURNAL_READ_REFUSED')
    finally:
        if journal is not None:
            os.close(journal)
        os.close(current)


def campaign_links(feed):
    if 'CampaignIds' not in feed:
        return {'state': 'NOT_RETURNED_UNKNOWN', 'count': None}
    value = feed['CampaignIds']
    if value is None:
        return {'state': 'NULL_UNKNOWN', 'count': None}
    if not isinstance(value, dict) or set(value) - {'Items'}:
        fail('CAMPAIGN_LINKS_INVALID_SHAPE')
    if 'Items' not in value:
        return {'state': 'WRAPPER_WITHOUT_ITEMS_UNKNOWN', 'count': None}
    items = value['Items']
    if items is None:
        return {'state': 'NULL_ITEMS_UNKNOWN', 'count': None}
    if not isinstance(items, list) or any(type(x) is not int or x <= 0 for x in items):
        fail('CAMPAIGN_LINKS_INVALID_ITEMS')
    return {'state': 'EXPLICIT_ITEMS', 'count': len(items)}


def observe(body, accepted_id):
    feeds = body['result'].get('Feeds')
    if (not isinstance(feeds, list) or len(feeds) != 1 or not isinstance(feeds[0], dict)
            or type(feeds[0].get('Id')) is not int or feeds[0]['Id'] != accepted_id):
        fail('EXACT_ACCEPTED_FEED_NOT_RETURNED')
    feed = feeds[0]
    if (feed.get('Name') != EXPECTED_NAME or feed.get('BusinessType') != 'OTHER'
            or feed.get('SourceType') != 'FILE'):
        fail('ACCEPTED_FEED_IDENTITY_MISMATCH')
    file_feed = feed.get('FileFeed')
    if file_feed is None:
        filename = {'state': 'NULL_OR_NOT_RETURNED_UNKNOWN', 'verified': False}
    elif not isinstance(file_feed, dict):
        fail('ACCEPTED_FEED_FILENAME_SHAPE_INVALID')
    elif 'Filename' not in file_feed or file_feed['Filename'] is None:
        filename = {'state': 'WRAPPER_WITHOUT_FILENAME_UNKNOWN', 'verified': False}
    elif file_feed['Filename'] != EXPECTED_FILENAME:
        fail('ACCEPTED_FEED_FILENAME_MISMATCH')
    else:
        filename = {'state': 'EXACT_FILENAME', 'verified': True}
    state = feed.get('Status')
    if state not in {'NEW', 'UPDATING', 'DONE', 'ERROR'}:
        fail('ACCEPTED_FEED_STATUS_UNKNOWN')
    if feed.get('FilterSchema') is not None and not isinstance(feed['FilterSchema'], str):
        fail('ACCEPTED_FEED_FILTER_SCHEMA_INVALID')
    number = feed.get('NumberOfItems')
    if number is not None and (type(number) is not int or number < 0):
        fail('ACCEPTED_FEED_ITEM_COUNT_INVALID')
    links = campaign_links(feed)
    if links['count'] is not None and links['count'] > 0:
        fail('UNEXPECTED_CAMPAIGN_ASSOCIATION')
    return {'status': state, 'number_of_items': number,
            'campaign_links': links, 'filename': filename,
            'filter_schema': feed.get('FilterSchema'),
            'title_and_text_sources': feed.get('TitleAndTextSources')}


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        fail('API_REDIRECT_REFUSED')


class API:
    def __init__(self, token, accepted_id, report, deadline):
        self.token, self.accepted_id = token, accepted_id
        self.report, self.deadline = report, deadline
        self.calls, self.response_bytes = 0, 0
        self.opener = build_opener(ProxyHandler({}),
            HTTPSHandler(context=ssl.create_default_context()), NoRedirect())

    def request(self, kind):
        if self.calls >= MAX_CALLS:
            fail('FIXED_REQUEST_BUDGET_EXHAUSTED')
        if kind == 'clients' and self.calls == 0:
            service, payload = 'clients', {'method': 'get', 'params': {'FieldNames': ['Login', 'ClientId']}}
        elif (kind == 'poll' and self.calls >= 1 and self.report['client_login_verified']
                and type(self.accepted_id) is int and self.accepted_id > 0):
            service, payload = 'feeds', {'method': 'get', 'params': {
                'SelectionCriteria': {'Ids': [self.accepted_id]},
                'FieldNames': FIELDS, 'FileFeedFieldNames': ['Filename']}}
        else:
            fail('FIXED_READ_ONLY_ORDER_REQUIRED')
        remaining = self.deadline - time.monotonic()
        if remaining <= 0:
            fail('TOTAL_TIME_BUDGET_REACHED')
        raw_request = json.dumps(payload, ensure_ascii=False, allow_nan=False).encode('utf-8')
        request = Request(BASE + service, data=raw_request, headers={
            'Authorization': 'Bearer ' + self.token, 'Client-Login': LOGIN,
            'Accept-Language': 'ru', 'Content-Type': 'application/json; charset=utf-8'}, method='POST')
        label = kind + '_' + str(self.calls + 1)
        record = {'kind': kind, 'service': service, 'api_method': 'get',
                  'http': None, 'response_bytes': None}
        self.report['requests'].append(record)
        self.calls += 1
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
            fail('API_READ_TRANSPORT_FAILED_NO_RETRY')
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
            fail('API_READ_REJECTED_NO_RETRY')
        if not isinstance(body.get('result'), dict):
            fail('API_RESULT_MISSING')
        return body


def verify_client(body):
    clients = body['result'].get('Clients')
    if (not isinstance(clients, list) or len(clients) != 1 or not isinstance(clients[0], dict)
            or clients[0].get('Login') != LOGIN or type(clients[0].get('ClientId')) is not int
            or clients[0]['ClientId'] <= 0):
        fail('EXACT_CLIENT_NOT_RETURNED')


def main():
    report = {'schema': 1, 'task_id': TASK_ID, 'client_login': LOGIN,
              'started_at_utc': stamp(), 'status': 'READ_FAILED',
              'server_writes': False, 'advertising_writes': False,
              'campaign_writes': 0, 'goal_writes': 0, 'database_opened': False,
              'token_exported': False, 'feed_add_attempts': 0,
              'private_journal_read_only': True, 'client_login_verified': False,
              'requests': [], 'responses': {}, 'errors': []}
    token = None
    deadline = time.monotonic() + TOTAL_SECONDS

    def timed_out(_signum, _frame):
        fail('TOTAL_TIME_BUDGET_REACHED')

    signal.signal(signal.SIGALRM, timed_out)
    signal.alarm(TOTAL_SECONDS)
    try:
        token, installed = credentials()
        report['installed_sha'] = installed
        accepted_id, evidence = read_accepted_id()
        report['accepted_feed_id'], report['journal_evidence'] = accepted_id, evidence
        api = API(token, accepted_id, report, deadline)
        verify_client(api.request('clients'))
        report['client_login_verified'] = True
        report['status'] = 'PROCESSING_PENDING_KNOWN_ID'
        for attempt in range(6):
            if attempt:
                if time.monotonic() + 30 >= deadline:
                    fail('POLL_TIME_BUDGET_REACHED')
                time.sleep(30)
            observation = observe(api.request('poll'), accepted_id)
            report['last_observation'] = observation
            if observation['status'] == 'ERROR':
                report['status'] = 'FEED_PROCESSING_ERROR_KNOWN_ID'
                break
            if observation['status'] == 'DONE':
                if observation['number_of_items'] != 4:
                    report['status'] = 'PROCESSED_ITEM_COUNT_MISMATCH_KNOWN_ID'
                elif observation['campaign_links']['state'] == 'EXPLICIT_ITEMS':
                    report['status'] = 'PROCESSED_4_ITEMS_UNLINKED'
                else:
                    report['status'] = 'PROCESSED_4_ITEMS_ASSOCIATION_UNKNOWN'
                break
    except SafeError as exc:
        report['errors'].append({'code': exc.code})
        report['status'] = 'READ_FAILED'
    except Exception:
        report['errors'].append({'code': 'LOCAL_READBACK_FAILED'})
        report['status'] = 'READ_FAILED'
    finally:
        signal.alarm(0)
        report['finished_at_utc'] = stamp()
    encoded = json.dumps(report, ensure_ascii=False, allow_nan=False)
    if token and token in encoded:
        report['responses'] = {}
        report['errors'] = [{'code': 'TOKEN_EXPORT_GUARD_STOP'}]
        report['status'] = 'READ_FAILED'
        encoded = json.dumps(report, ensure_ascii=False, allow_nan=False)
    print(encoded)


if __name__ == '__main__':
    main()
