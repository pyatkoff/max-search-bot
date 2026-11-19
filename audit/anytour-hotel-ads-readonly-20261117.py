"""Ephemeral fixed-scope Direct reader; stdout MUST be encrypted by its runner.

Stream this source to the established server Python. Do not install it as a
worker. No application import, database, credential export or advertising write.
"""
import sys
sys.dont_write_bytecode = True

import hashlib
import json
import os
import re
import signal
import subprocess
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
CLIENT_LOGIN = 'anytour2024tp'
PROBE_ID = 'anytour-tg-campaign-audit-20261119'
TOTAL_SECONDS, MAX_CALLS = 100, 30
MAX_RESPONSE_BYTES, MAX_STDOUT_BYTES = 4_194_304, 12_000_000
MAX_SAVED_BYTES = MAX_STDOUT_BYTES - 512_000
FEED_ID = 4086314
CAMPAIGN_FIELDS = ['Id', 'Name', 'State', 'Status', 'StatusPayment', 'Type', 'DailyBudget']
STRATEGY_FIELDS = ['BiddingStrategy', 'PackageBiddingStrategy', 'AttributionModel', 'CounterIds', 'PriorityGoals']
FEED_FIELDS = ['FeedId', 'FeedProcessingStatus', 'FeedFilterConditions']
RESULT = {
    'schema_version': 1, 'probe_id': PROBE_ID, 'client_login': CLIENT_LOGIN, 'feed_id': FEED_ID,
    'started_at_utc': datetime.now(timezone.utc).isoformat(),
    'server_writes': False, 'advertising_writes': False, 'database_opened': False, 'token_exported': False,
    'requests': [], 'responses': [], 'errors': [], 'coverage': {},
}

def verify_configured_login(value):
    if value not in (None, '', CLIENT_LOGIN):
        raise Stop('ACCOUNT_MISMATCH')

class Stop(Exception):
    def __init__(self, code, http=None, api_code=None):
        self.info = {'code': code}
        if http is not None:
            self.info['http'] = http
        if type(api_code) is int:
            self.info['api_code'] = api_code


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def encode(value):
    return json.dumps(value, ensure_ascii=False, allow_nan=False,
                      separators=(',', ':')).encode('utf-8')


def positive_id(value):
    if type(value) is int and value > 0:
        return value
    if isinstance(value, str) and re.fullmatch(r'[1-9][0-9]{0,19}', value):
        return int(value)
    raise Stop('INVALID_API_ID')


def load_local_config():
    """Same checked directory/origin/dotenv guards as the 13 November reader."""
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
    verify_configured_login(conf('YANDEX_DIRECT_CLIENT_LOGIN'))
    token = conf('YANDEX_OAUTH_TOKEN')
    if not isinstance(token, str) or not token.strip() or re.search(r'\s', token):
        raise Stop('LOCAL_TOKEN_MISSING_OR_INVALID')
    RESULT['local_config_verified'] = True
    return token


def make_reader(token, deadline):
    # Inherited proxy/CA settings, strict default TLS verification and no redirects.
    opener = urllib.request.build_opener(NoRedirect())

    def safe_body(body):
        if isinstance(body, str):
            return body.replace(token, '[REDACTED]')
        if isinstance(body, list):
            return [safe_body(value) for value in body]
        if isinstance(body, dict):
            return {safe_body(key): safe_body(value) for key, value in body.items()
                    if key.casefold() not in ('authorization', 'proxy-authorization')}
        return body

    def retain(value):
        safe = safe_body(value)
        # Include JSON escaping, not just received bytes, in the cumulative bound.
        if len(encode(RESULT)) + len(encode(safe)) > MAX_SAVED_BYTES:
            raise Stop('RETAINED_RESPONSE_BUDGET')
        RESULT['responses'].append(safe)
        return len(RESULT['responses']) - 1

    def read(endpoint, payload, label):
        if endpoint not in ('campaigns', 'ads', 'feeds', 'strategies'):
            raise Stop('ENDPOINT_NOT_ALLOWED')
        if payload.get('method') != 'get':
            raise Stop('READ_METHOD_REQUIRED')
        headers = {'Authorization': 'Bearer ' + token, 'Client-Login': CLIENT_LOGIN,
                   'Accept-Language': 'ru', 'Content-Type': 'application/json; charset=utf-8'}
        data = encode(payload)
        version = 'v5' if endpoint == 'strategies' else 'v501'
        url = 'https://api.direct.yandex.com/json/' + version + '/' + endpoint
        for poll in range(1):
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise Stop('TIME_BUDGET')
            if len(RESULT['requests']) >= MAX_CALLS:
                raise Stop('REQUEST_BUDGET')
            attempt = {'endpoint': endpoint, 'label': label, 'http': None, 'poll': poll,
                       'request_body_sha256': hashlib.sha256(data).hexdigest()}
            RESULT['requests'].append(attempt)
            request = urllib.request.Request(url, data=data, headers=headers, method='POST')
            try:
                with opener.open(request, timeout=min(20, remaining)) as response:
                    status, rh = response.status, response.headers
                    raw = response.read(MAX_RESPONSE_BYTES + 1)
            except Stop:
                raise
            except urllib.error.HTTPError as error:
                status, rh = error.code, error.headers
                try:
                    raw = error.read(MAX_RESPONSE_BYTES + 1)
                finally:
                    error.close()
            except Exception:
                raise Stop('HTTPS_READ_FAILED') from None
            attempt['http'] = status
            attempt['request_id'] = safe_body(str(rh.get('RequestId', ''))[:180])
            attempt['received_bytes'] = len(raw)
            if len(raw) > MAX_RESPONSE_BYTES:
                raise Stop('RESPONSE_TOO_LARGE', status)
            try:
                text = raw.decode('utf-8-sig')
            except UnicodeDecodeError:
                raise Stop('NON_UTF8_RESPONSE', status) from None
            saved = {'endpoint': endpoint, 'label': label, 'http': status,
                     'request': payload, 'raw_text': text}
            attempt['response_index'] = retain(saved)
            try:
                def invalid_constant(_):
                    raise ValueError('nonfinite')
                body = json.loads(text, parse_constant=invalid_constant)
            except (ValueError, UnicodeDecodeError):
                raise Stop('API_READ_REJECTED' if status != 200 else 'NON_JSON_RESPONSE', status) from None
            error = body.get('error') if isinstance(body, dict) else None
            if status != 200 or error or (isinstance(body, dict) and body.get('errors')):
                raise Stop('API_READ_REJECTED', status,
                           error.get('error_code') if isinstance(error, dict) else None)
            if not isinstance(body, dict):
                raise Stop('INVALID_RESPONSE_SHAPE', status)
            return body
        raise Stop('NO_RESPONSE')
    return read


def paged(read, endpoint, label, field, params, limit=1000):
    rows, seen = [], set()
    offset = 0
    for page in range(5):
        payload = {'method': 'get', 'params': {**params, 'Page': {'Limit': limit, 'Offset': offset}}}
        result = read(endpoint, payload, label + '_' + str(page)).get('result')
        batch = result.get(field) if isinstance(result, dict) else None
        if not isinstance(batch, list) or len(batch) > limit:
            raise Stop('INVALID_LIST')
        for row in batch:
            identity = positive_id(row.get('Id') if isinstance(row, dict) else None)
            if identity in seen:
                raise Stop('DUPLICATE_ID')
            seen.add(identity)
            rows.append(row)
        if result.get('LimitedBy') is None:
            RESULT['coverage'][label] = {'complete': True, 'rows': len(rows), 'pages': page + 1}
            return rows
        next_offset = positive_id(result['LimitedBy'])
        if next_offset <= offset:
            raise Stop('PAGE_CURSOR_NOT_ADVANCING')
        offset = next_offset
    raise Stop('PAGE_CAP')

def stage(fn, label):
    try:
        return fn()
    except Stop as error:
        RESULT['errors'].append({'stage': label, **error.info})
        return []

def collect(read):
    feed_params = {'SelectionCriteria': {'Ids': [FEED_ID]},
                   'FieldNames': ['Id', 'Name', 'CampaignIds', 'NumberOfItems', 'Status', 'SourceType', 'UpdatedAt'],
                   'UrlFeedFieldNames': ['Url'], 'FileFeedFieldNames': ['Filename']}
    feeds = stage(lambda: paged(read, 'feeds', 'target_feed', 'Feeds', feed_params), 'target_feed')
    if feeds and {x['Id'] for x in feeds} != {FEED_ID}:
        raise Stop('FEED_SCOPE')
    feed_refs = sorted({positive_id(value) for row in feeds
                        for value in (row.get('CampaignIds') or {}).get('Items', [])})
    RESULT['feed_campaign_ids'] = feed_refs
    campaign_params = {'FieldNames': CAMPAIGN_FIELDS,
                       'UnifiedCampaignFieldNames': STRATEGY_FIELDS + ['WeeklyBudgetRollover'],
                       'TextCampaignFieldNames': STRATEGY_FIELDS + ['WeeklyBudgetRollover']}
    by_id = {}
    criteria = [('inactive_campaigns', {'States': ['SUSPENDED', 'OFF']}),
                ('draft_campaigns', {'Statuses': ['DRAFT']})]
    if feed_refs:
        criteria.append(('feed_campaigns', {'Ids': feed_refs}))
    for label, selection in criteria:
        params = {**campaign_params, 'SelectionCriteria': selection}
        rows = stage(lambda: paged(read, 'campaigns', label, 'Campaigns', params), label)
        for row in rows:
            cid = positive_id(row['Id'])
            if label == 'inactive_campaigns' and row.get('State') not in ('SUSPENDED', 'OFF'):
                raise Stop('INACTIVE_SCOPE')
            if label == 'draft_campaigns' and row.get('Status') != 'DRAFT':
                raise Stop('DRAFT_SCOPE')
            if label == 'feed_campaigns' and cid not in feed_refs:
                raise Stop('FEED_CAMPAIGN_SCOPE')
            if cid in by_id and by_id[cid] != row:
                raise Stop('CAMPAIGN_READ_CHANGED')
            by_id[cid] = row
    ids = sorted(by_id)
    RESULT['checked_campaign_ids'] = ids
    if len(ids) > 100:
        raise Stop('CAMPAIGN_SCOPE_CAP')
    for start in range(0, len(ids), 10):
        chunk = ids[start:start + 10]
        params = {'SelectionCriteria': {'CampaignIds': chunk},
                  'FieldNames': ['Id', 'CampaignId', 'AdGroupId', 'State', 'Status', 'Type'],
                  'ShoppingAdFieldNames': FEED_FIELDS, 'ListingAdFieldNames': FEED_FIELDS,
                  'TextAdFieldNames': ['Title', 'Href'],
                  'ResponsiveAdFieldNames': ['Titles', 'Href']}
        label = 'ads_' + str(start // 10)
        rows = stage(lambda: paged(read, 'ads', label, 'Ads', params), label)
        if any(positive_id(row['CampaignId']) not in chunk for row in rows):
            raise Stop('AD_SCOPE')
    strategy_ids = set()
    for campaign in by_id.values():
        for key in ('UnifiedCampaign', 'TextCampaign'):
            package = (campaign.get(key) or {}).get('PackageBiddingStrategy')
            if isinstance(package, dict) and package.get('StrategyId'):
                strategy_ids.add(positive_id(package['StrategyId']))
            platforms = package.get('Platforms') if isinstance(package, dict) else None
            items = platforms.get('Items', []) if isinstance(platforms, dict) else platforms
            for item in items if isinstance(items, list) else []:
                if isinstance(item, dict) and item.get('StrategyId'):
                    strategy_ids.add(positive_id(item['StrategyId']))
    if strategy_ids:
        params = {'SelectionCriteria': {'Ids': sorted(strategy_ids)}, 'FieldNames': ['Id', 'Name', 'Type', 'StatusArchived']}
        for strategy_type in ('MaximumClicks', 'MaximumConversionRate', 'AverageCpc', 'AverageCpa',
                              'AverageCpaMultipleGoals', 'MaxProfit', 'PayForConversionMaxProfit',
                              'PayForConversion', 'AverageCrr', 'PayForConversionCrr'):
            params['Strategy' + strategy_type + 'FieldNames'] = ['WeeklySpendLimit', 'CustomPeriodBudget', 'BudgetType']
        label = 'package_budgets'
        rows = stage(lambda: paged(read, 'strategies', label, 'Strategies', params), label)
        if {row['Id'] for row in rows} != strategy_ids:
            RESULT['errors'].append({'stage':label, 'code':'PACKAGE_BUDGET_SCOPE_INCOMPLETE'})
    RESULT['status'] = 'PARTIAL' if RESULT['errors'] else 'COLLECTED'

if __name__ == '__main__':
    def timeout(*_):
        raise Stop('TIME_BUDGET')
    signal.signal(signal.SIGALRM, timeout)
    end = time.monotonic() + TOTAL_SECONDS
    signal.alarm(TOTAL_SECONDS)
    try:
        collect(make_reader(load_local_config(), end))
    except Stop as error:
        RESULT['errors'].append(error.info)
        RESULT['status'] = 'PARTIAL'
    except Exception:
        RESULT['errors'].append({'code': 'LOCAL_READER_FAILED'})
        RESULT['status'] = 'PARTIAL'
    finally:
        signal.alarm(0)
        RESULT['finished_at_utc'] = datetime.now(timezone.utc).isoformat()
        print(encode(RESULT).decode())
