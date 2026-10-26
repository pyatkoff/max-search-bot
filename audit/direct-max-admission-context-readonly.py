"""Fixed-account, two-call Direct campaign context reader.

Run only through the existing strict-pinned encrypted diagnostic runner. Stdout
contains the private selected API responses. No campaign is selected or changed.
The project/env/credential checks are unchanged from direct-feed-readonly.py.
The campaigns payload is exactly read_request.payload in the prepared
max-landing-next-read-contract.json; no runtime input changes its scope.
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
TASK_ID = 'direct-max-admission-context-20261026'
MAX_RESPONSE = 2 * 1024 * 1024
TOTAL_SECONDS = 90
ENV_NAMES = {'YANDEX_OAUTH_TOKEN', 'YANDEX_DIRECT_CLIENT_LOGIN', 'READ_ONLY', 'YANDEX_DIRECT_SANDBOX'}
CLIENT_PAYLOAD = {'method': 'get', 'params': {'FieldNames': ['Login', 'ClientId']}}
CAMPAIGN_PAYLOAD = {'method': 'get', 'params': {'SelectionCriteria': {'Types': ['UNIFIED_CAMPAIGN'], 'States': ['OFF', 'SUSPENDED', 'ON']}, 'FieldNames': ['Id', 'Name', 'Type', 'Status', 'State', 'StatusClarification'], 'UnifiedCampaignFieldNames': ['BiddingStrategy', 'PriorityGoals', 'CounterIds', 'AttributionModel', 'PackageBiddingStrategy', 'TrackingParams'], 'UnifiedCampaignSearchStrategyPlacementTypesFieldNames': ['SearchResults', 'ProductGallery', 'DynamicPlaces', 'Maps', 'SearchOrganizationList'], 'UnifiedCampaignPackageBiddingStrategyPlatformsFieldNames': ['SearchResult', 'ProductGallery', 'Maps', 'SearchOrganizationList', 'Network', 'DynamicPlaces'], 'Page': {'Limit': 1000, 'Offset': 0}}}
FIXED_CALLS = (('clients', CLIENT_PAYLOAD), ('campaigns', CAMPAIGN_PAYLOAD))


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
    """Exactly clients.get then campaigns.get, in order; no automatic retry."""

    def __init__(self, token, deadline, report):
        self._token = token
        self.deadline = deadline
        self.calls = 0
        self.response_bytes = 0
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
        if allowed_service == 'campaigns' and not self.report.get('client_login_verified'):
            fail('EXACT_CLIENT_NOT_VERIFIED')
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
        self.response_bytes += len(raw)
        if len(raw) > MAX_RESPONSE or self.response_bytes > MAX_RESPONSE:
            fail('API_RESPONSE_OVERSIZED')
        try:
            decoded = raw.decode('utf-8-sig')
            if self._token in decoded:
                fail('TOKEN_IN_API_RESPONSE_REFUSED')
            result = json.loads(decoded, parse_constant=lambda _value: fail('API_RESPONSE_NOT_JSON'))
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


def explicit_items_count(value):
    """Count only an explicit Items array; null/missing/{} remains unknown."""
    if isinstance(value, dict) and isinstance(value.get('Items'), list):
        return len(value['Items'])
    return None


def summarize_campaigns(body):
    result = body['result']
    campaigns = result.get('Campaigns')
    if not isinstance(campaigns, list):
        fail('CAMPAIGN_CATALOG_SHAPE_INVALID')
    summary = {
        'total': len(campaigns),
        'states': {'OFF': 0, 'SUSPENDED': 0, 'ON': 0},
        'statuses': {}, 'types': {},
        'has_strategy': {'present': 0, 'unknown_or_empty': 0},
        'strategy_fields': {
            key: {'nonempty_objects': 0, 'unknown_or_empty': 0}
            for key in ('BiddingStrategy', 'PackageBiddingStrategy')},
        'counters': {'explicit_items_total': 0, 'campaigns_with_explicit_items': 0,
                     'campaigns_with_nonempty_items': 0, 'campaigns_unknown': 0},
        'goals': {'explicit_items_total': 0, 'campaigns_with_explicit_items': 0,
                  'campaigns_with_nonempty_items': 0, 'campaigns_unknown': 0},
        'unified_campaign_context': {'objects': 0, 'unknown_or_empty': 0}}
    seen = set()
    for campaign in campaigns:
        if not isinstance(campaign, dict):
            fail('CAMPAIGN_ROW_SHAPE_INVALID')
        cid = campaign.get('Id')
        if type(cid) is not int or cid <= 0 or cid in seen:
            fail('CAMPAIGN_ID_INVALID_OR_DUPLICATE')
        seen.add(cid)
        if campaign.get('Type') != 'UNIFIED_CAMPAIGN':
            fail('CAMPAIGN_TYPE_OUTSIDE_FIXED_SELECTION')
        state = campaign.get('State')
        if state not in {'OFF', 'SUSPENDED', 'ON'}:
            fail('CAMPAIGN_STATE_OUTSIDE_FIXED_SELECTION')
        if not isinstance(campaign.get('Name'), str):
            fail('CAMPAIGN_NAME_INVALID')
        if not isinstance(campaign.get('Status'), str):
            fail('CAMPAIGN_STATUS_INVALID')
        context = campaign.get('UnifiedCampaign')
        if context is not None and not isinstance(context, dict):
            fail('UNIFIED_CAMPAIGN_CONTEXT_INVALID')
        if context:
            summary['unified_campaign_context']['objects'] += 1
        else:
            summary['unified_campaign_context']['unknown_or_empty'] += 1
        context = context or {}
        summary['states'][state] += 1
        for field, target in (('Status', 'statuses'), ('Type', 'types')):
            key = campaign[field]
            summary[target][key] = summary[target].get(key, 0) + 1
        has_strategy = False
        for key in ('BiddingStrategy', 'PackageBiddingStrategy'):
            value = context.get(key)
            if isinstance(value, dict) and value:
                summary['strategy_fields'][key]['nonempty_objects'] += 1
                has_strategy = True
            else:
                summary['strategy_fields'][key]['unknown_or_empty'] += 1
        summary['has_strategy']['present' if has_strategy else 'unknown_or_empty'] += 1
        for field, target in (('CounterIds', 'counters'), ('PriorityGoals', 'goals')):
            count = explicit_items_count(context.get(field))
            bucket = summary[target]
            if count is None:
                bucket['campaigns_unknown'] += 1
            else:
                bucket['explicit_items_total'] += count
                bucket['campaigns_with_explicit_items'] += 1
                bucket['campaigns_with_nonempty_items'] += int(count > 0)
    # A response at the requested bound is not evidence of complete inventory.
    truncated = result.get('LimitedBy') is not None or len(campaigns) >= 1000
    return summary, truncated


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
    campaign_body = api.post('campaigns', CAMPAIGN_PAYLOAD)
    report['responses']['campaigns'] = campaign_body
    summary, truncated = summarize_campaigns(campaign_body)
    report['summary'] = summary
    report['campaign_catalog_complete'] = not truncated
    report['campaign_selection_performed'] = False
    report['status'] = 'TRUNCATED_NOT_SELECTED' if truncated else 'COLLECTED'


def main():
    report = {'schema': 1, 'task_id': TASK_ID, 'client_login': LOGIN,
              'started_at_utc': stamp(), 'status': 'READ_FAILED',
              'server_writes': False, 'advertising_writes': False,
              'campaign_writes': 0, 'goal_writes': 0, 'feed_add_attempts': 0,
              'database_opened': False, 'token_exported': False,
              'client_login_verified': False, 'campaign_catalog_complete': False,
              'campaign_selection_performed': False,
              'requests': [], 'responses': {}, 'errors': []}
    token = None

    def timed_out(_signum, _frame):
        fail('TOTAL_TIME_BUDGET_REACHED')

    signal.signal(signal.SIGALRM, timed_out)
    signal.alarm(TOTAL_SECONDS)
    deadline = time.monotonic() + TOTAL_SECONDS
    try:
        token, installed_sha = credentials()
        report['installed_sha'] = installed_sha
        report['local_config_verified'] = True
        api = API(token, deadline, report)
        collect(api, report)
    except SafeError as exc:
        report['status'] = 'READ_FAILED'
        report['errors'].append({'code': exc.code})
    except Exception:
        report['status'] = 'READ_FAILED'
        report['errors'].append({'code': 'LOCAL_READER_FAILED'})
    finally:
        signal.alarm(0)
        report['finished_at_utc'] = stamp()
    encoded = json.dumps(report, ensure_ascii=False, allow_nan=False)
    if token and token in encoded:
        # Never emit a value derived from the secret, even into ciphertext.
        report['responses'] = {}
        report.pop('summary', None)
        report['errors'] = [{'code': 'TOKEN_EXPORT_GUARD_STOP'}]
        report['status'] = 'READ_FAILED'
        encoded = json.dumps(report, ensure_ascii=False, allow_nan=False)
    print(encoded)


if __name__ == '__main__':
    main()
