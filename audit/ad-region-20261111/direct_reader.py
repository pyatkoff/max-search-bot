"""Bounded Direct URL inventory. Only fixed-origin JSON-v5/v501 POST method=get.
Streamed into existing server venv; stdout is consumed ONLY by encrypted runner.
No app import, database, URL navigation, API mutation or server persistence.
"""
import copy
import hashlib
import json
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
AUDIT_KIND = 'direct_advertising_region_v1'
STARTING_ACCOUNT = 'anytour2024tp'
MAX_REQUESTS, MAX_RESPONSE_BYTES, MAX_REPORT_BYTES = 180, 4_000_000, 3_900_000
MAX_TOTAL_RESPONSE_BYTES = 24_000_000
MAX_ACCOUNTS, MAX_CAMPAIGNS, MAX_ADS, MAX_GROUPS, MAX_URLS = 20, 2_000, 25_000, 25_000, 12_000
PAGE_LIMIT, DEADLINE_SECONDS = 1000, 240
SERVICES = {'clients', 'agencyclients', 'campaigns', 'ads', 'adgroups', 'sitelinks'}
VERSIONS = {name: ('v5' if name in ('clients', 'agencyclients') else 'v501') for name in SERVICES}
AD_BASE_FIELDS = ['Id', 'CampaignId', 'AdGroupId', 'Type', 'Subtype', 'State', 'Status']
AD_DETAILS = {
    ('TEXT_AD', 'NONE'): ('TextAd', ['Href', 'SitelinkSetId', 'TurboPageId']),
    ('DYNAMIC_TEXT_AD', 'NONE'): ('DynamicTextAd', ['SitelinkSetId']),
    ('MOBILE_APP_AD', 'NONE'): ('MobileAppAd', ['TrackingUrl']),
    ('IMAGE_AD', 'TEXT_IMAGE_AD'): ('TextImageAd', ['Href', 'TurboPageId']),
    ('IMAGE_AD', 'TEXT_AD_BUILDER_AD'): ('TextAdBuilderAd', ['Href', 'TurboPageId']),
    ('IMAGE_AD', 'MOBILE_APP_IMAGE_AD'): ('MobileAppImageAd', ['TrackingUrl']),
    ('IMAGE_AD', 'MOBILE_APP_AD_BUILDER_AD'): ('MobileAppAdBuilderAd', ['TrackingUrl']),
    ('CPC_VIDEO_AD', 'NONE'): ('CpcVideoAdBuilderAd', ['Href', 'TurboPageId']),
    ('CPC_VIDEO_AD', 'MOBILE_APP_CPC_VIDEO_AD_BUILDER_AD'):
        ('MobileAppCpcVideoAdBuilderAd', ['TrackingUrl']),
    ('CPM_BANNER_AD', 'NONE'): ('CpmBannerAdBuilderAd', ['Href', 'TurboPageId']),
    ('CPM_VIDEO_AD', 'NONE'): ('CpmVideoAdBuilderAd', ['Href', 'TurboPageId']),
    ('RESPONSIVE_AD', 'NONE'): ('ResponsiveAd', ['Href', 'SitelinkSetId']),
    ('SHOPPING_AD', 'NONE'): ('ShoppingAd', ['SitelinkSetId', 'FeedId']),
    ('LISTING_AD', 'NONE'): ('ListingAd', ['SitelinkSetId', 'FeedId']),
}
DOCS = [
    'https://yandex.com/dev/direct/doc/en/clients/get',
    'https://yandex.com/dev/direct/doc/en/agencyclients/get',
    'https://yandex.com/dev/direct/doc/en/campaigns/get',
    'https://yandex.com/dev/direct/doc/en/campaigns/get-unified-campaign',
    'https://yandex.com/dev/direct/doc/en/ads/get',
    'https://yandex.com/dev/direct/doc/en/adgroups/get',
    'https://yandex.com/dev/direct/doc/en/sitelinks/get',
    'https://yandex.com/dev/direct/doc/en/best-practice/get',
    'https://yandex.com/dev/direct/doc/en/concepts/headers',
    'https://yandex.ru/dev/direct/doc/en/format',
]


def timestamp():
    return datetime.now(timezone.utc).isoformat()


def new_report():
    return {'schema': 1, 'audit_kind': AUDIT_KIND, 'starting_account': STARTING_ACCOUNT,
            'started_at_utc': timestamp(), 'status': 'READ_FAILED',
            'server_writes': False, 'advertising_writes': False, 'database_opened': False,
            'token_exported': False, 'api_writes': False,
            'api_origin': 'https://api.direct.yandex.com', 'api_versions': VERSIONS,
            'requests': [], 'errors': [], 'accounts': [], 'urls': [],
            'complete': False, 'all_projects_proven': False,
            'limits': {'requests': MAX_REQUESTS, 'response_bytes': MAX_RESPONSE_BYTES,
                       'report_bytes': MAX_REPORT_BYTES, 'accounts': MAX_ACCOUNTS,
                       'campaigns': MAX_CAMPAIGNS, 'ads': MAX_ADS, 'groups': MAX_GROUPS,
                       'urls': MAX_URLS, 'seconds': DEADLINE_SECONDS},
            'scope_limits': [
                'Accessible own/configured account and agency clients only; not an inventory of every independent OAuth account.',
                'JSON v501 for campaigns/ads/groups/sitelinks; v5 only for account discovery. Feed-generated destinations may remain unresolved.',
                'No destination is fetched; macro presence is not proof of click substitution or first-entry persistence.',
                'Live paginated configuration is not an atomic provider snapshot; start/end timestamps delimit collection.',
                'Sitelinks are collected only for referenced sets. Group/campaign tracking retained separately; no guessed precedence.',
            ], 'official_schema_sources': DOCS}


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


def valid_login(value):
    return isinstance(value, str) and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.@-]{0,127}', value)


def positive_id(value):
    if type(value) is int and 0 < value <= 9223372036854775807:
        return value
    raise Stop('INVALID_PROVIDER_ID')


def load_local_config(report):
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
    report['installed_integration_sha'] = sha
    cfg = dotenv_values(envfile, interpolate=False)
    def conf(key, default=''):
        return os.environ.get(key, cfg.get(key, default))
    if str(conf('READ_ONLY')).lower() != 'true':
        raise Stop('READ_ONLY_NOT_TRUE')
    if str(conf('YANDEX_DIRECT_SANDBOX', 'false')).lower() not in ('false', '0'):
        raise Stop('SANDBOX_ENABLED')
    token = conf('YANDEX_OAUTH_TOKEN')
    if not isinstance(token, str) or not token or len(token) > 8192 or re.search(r'\s', token):
        raise Stop('LOCAL_TOKEN_MISSING_OR_INVALID')
    login = conf('YANDEX_DIRECT_CLIENT_LOGIN', '')
    if login is None:
        login = ''
    if login and not valid_login(login):
        raise Stop('LOCAL_DIRECT_LOGIN_INVALID')
    report['local_config_verified'] = True
    report['configured_client_login'] = login or None
    return token, login


def make_reader(token, report):
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    started, total_bytes = time.monotonic(), 0
    def get(service, params, login=None):
        nonlocal total_bytes
        if service not in SERVICES or not isinstance(params, dict):
            raise Stop('ENDPOINT_NOT_ALLOWED')
        if login is not None and not valid_login(login):
            raise Stop('CLIENT_LOGIN_INVALID')
        if service == 'agencyclients' and login is not None:
            raise Stop('AGENCY_HEADER_FORBIDDEN')
        body = json.dumps({'method': 'get', 'params': params}, allow_nan=False).encode()
        if len(body) > 200_000:
            raise Stop('REQUEST_SIZE_BOUND')
        headers = {'Authorization': 'Bearer ' + token, 'Accept-Language': 'en',
                   'Content-Type': 'application/json; charset=utf-8'}
        if login is not None:
            headers['Client-Login'] = login
        request = urllib.request.Request('https://api.direct.yandex.com/json/' + VERSIONS[service] + '/' + service,
                                         data=body, headers=headers, method='POST')
        for attempt in range(2):
            if time.monotonic() - started >= DEADLINE_SECONDS:
                raise Stop('TIME_BUDGET')
            if len(report['requests']) >= MAX_REQUESTS:
                raise Stop('REQUEST_BUDGET')
            try:
                with opener.open(request, timeout=15) as response:
                    status, response_headers = response.status, response.headers
                    raw = response.read(MAX_RESPONSE_BYTES + 1)
            except urllib.error.HTTPError as error:
                status, response_headers = error.code, error.headers
                raw = error.read(MAX_RESPONSE_BYTES + 1)
            except Stop:
                raise
            except Exception:
                raise Stop('HTTPS_READ_FAILED') from None
            rid = response_headers.get('RequestId', '')
            row = {'service': service, 'method': 'get', 'http_method': 'POST', 'http': status,
                   'api_version': VERSIONS[service],
                   'client_login': login,
                   'request_id': rid if re.fullmatch(r'[0-9]{1,80}', rid) else None}
            report['requests'].append(row)
            total_bytes += len(raw)
            if total_bytes > MAX_TOTAL_RESPONSE_BYTES:
                raise Stop('TOTAL_RESPONSE_BUDGET')
            if len(raw) > MAX_RESPONSE_BYTES:
                raise Stop('RESPONSE_TOO_LARGE', status)
            # No provider text, headers, credentials or arbitrary exception message is exported.
            if token.encode() in raw:
                raise Stop('SECRET_REFLECTED_BY_RESPONSE')
            if status in (429, 500, 502, 503, 504) and attempt == 0:
                time.sleep(1)
                continue
            if status != 200:
                raise Stop('API_READ_REJECTED', status)
            try:
                result = json.loads(raw)
            except Exception:
                raise Stop('NON_JSON_RESPONSE', status) from None
            if not isinstance(result, dict):
                raise Stop('API_RESPONSE_SHAPE', status)
            if result.get('error'):
                err = result['error'] if isinstance(result['error'], dict) else {}
                raise Stop('API_METHOD_ERROR', status, err.get('error_code'))
            if not isinstance(result.get('result'), dict):
                raise Stop('API_RESULT_MISSING', status)
            return result['result']
        raise Stop('RETRY_LIMIT')
    return get


def page_rows(get, service, params, key, login, cap, coverage):
    offset, seen = 0, set()
    while True:
        data = get(service, {**copy.deepcopy(params),
                             'Page': {'Limit': PAGE_LIMIT, 'Offset': offset}}, login)
        rows = data.get(key)
        if not isinstance(rows, list) or len(rows) > PAGE_LIMIT:
            raise Stop('PAGE_SHAPE_INVALID')
        coverage['pages'] += 1
        for row in rows:
            if not isinstance(row, dict):
                raise Stop('ROW_SHAPE_INVALID')
            identity = row.get('Id', row.get('ClientId'))
            positive_id(identity)
            if identity in seen:
                raise Stop('DUPLICATE_ID_DURING_PAGINATION')
            seen.add(identity)
            if coverage['rows'] >= cap:
                raise Stop('ROW_BUDGET')
            coverage['rows'] += 1
            yield row
        limited = data.get('LimitedBy')
        if limited is None:
            coverage['complete'] = True
            return
        if type(limited) is not int or limited <= offset or limited != offset + len(rows):
            raise Stop('PAGINATION_CURSOR_INVALID')
        offset = limited


def analyze_region(value, kind='url'):
    """Inspect bounded text only; never follow URLs or pretend nested values reach a receiver."""
    if not isinstance(value, str) or len(value) > 8192:
        return {'status': 'invalid_or_oversized', 'evidence': []}
    evidence, queue, seen = [], [(value, 0, kind)], set()
    while queue and len(seen) < 32:
        text, depth, origin = queue.pop(0)
        if text in seen:
            continue
        seen.add(text)
        # Preserve symbolic substitution as symbolic evidence, not a concrete region.
        is_url = bool(re.match(r'\A[a-z][a-z0-9+.-]*://|\A//', text, re.I))
        payload_scan = (not is_url and '://' not in text and origin not in ('url', 'tracking'))
        matches = [] if not payload_scan else list(re.finditer(
            r'(?:^|[_?&])(?:region(?:_id=|_)|r_)(\{[^{}]{1,80}\}|[0-9]{1,20})(?=[_&#]|$)', text, re.I))
        if payload_scan:
            positional = re.fullmatch(r'[0-9]{6,}_([0-9]{1,20})_campaign_[0-9]+', text, re.I)
            if positional:
                matches.append(positional)
        for match in matches:
            v = match.group(1)
            evidence.append({'value': v, 'depth': depth, 'source': origin, 'inspection_only': False,
                             'kind': 'macro' if v.lower() == '{region_id}' else
                                     ('unresolved_placeholder' if v.startswith('{') else
                                      ('literal' if int(v) > 0 else 'invalid_zero'))})
        try:
            query = text.lstrip('?&') if origin == 'tracking' else urllib.parse.urlsplit(text).query
            pairs = urllib.parse.parse_qsl(query, keep_blank_values=True, max_num_fields=100)
        except (ValueError, TypeError):
            pairs = []
        for key, val in pairs:
            if key.lower() == 'region_id' and not any(
                    x['value'] == val and x['depth'] == depth and x['source'] == origin for x in evidence):
                k = ('macro' if val.lower() == '{region_id}' else 'literal'
                     if re.fullmatch(r'[0-9]{1,20}', val) and int(val) > 0 else 'invalid_or_unknown')
                evidence.append({'value': val[:100], 'depth': depth, 'source': origin,
                                 'inspection_only': (is_url or '://' in text) and
                                                    (depth > 0 or origin == 'tracking'), 'kind': k})
            if depth < 2 and key.lower() in (
                    'start', 'startapp', 'utm_term', 'utm_content', 'url', 'href', 'redirect',
                    'redirect_url', 'target', 'to', 'link', 'destination'):
                queue.append((val, depth + 1, key.lower()))
        if depth < 2 and '%' in text:
            decoded = urllib.parse.unquote(text)
            if decoded != text:
                queue.append((decoded, depth + 1, 'percent_decoded_inspection'))
    unique = {(x['kind'], x['value']) for x in evidence}
    valid = {x for x in unique if x[0] in ('macro', 'literal')}
    status = 'missing' if not evidence else 'present_symbolic' if any(x[0] == 'macro' for x in valid) else (
        'present_literal' if valid else 'invalid_or_unknown')
    if valid and any(k not in ('macro', 'literal') for k, _ in unique):
        status = 'mixed_unknown_requires_review'
    if valid and all(x.get('inspection_only') or x['depth'] > 1 or (x['depth'] > 0 and x['source'] in ('percent_decoded_inspection',
                     'url', 'href', 'redirect', 'redirect_url', 'target', 'to', 'link', 'destination'))
                     for x in evidence if x['kind'] in ('macro', 'literal')):
        status = 'nested_or_encoded_requires_review'
    if len({v for k, v in valid}) > 1:
        status = 'multiple_values_requires_review'
    return {'status': status, 'evidence': evidence[:24],
            'contains_region_name_macro': '{region_name}' in value.lower(),
            'bot_campaign_marker_present': '_campaign_' in value,
            'receiver_persistence_proven': False}


class Collector:
    def __init__(self, get, report):
        self.get, self.r = get, report
        self.url_index = {}

    def error(self, stage, error, **scope):
        self.r['errors'].append({'stage': stage, **scope, **error.info})

    def url(self, raw, kind):
        if raw is None or raw == '':
            return None
        if not isinstance(raw, str) or len(raw) > 8192:
            raise Stop('URL_FIELD_INVALID')
        digest = hashlib.sha256((kind + '\0' + raw).encode()).hexdigest()
        if digest not in self.url_index:
            if len(self.r['urls']) >= MAX_URLS:
                raise Stop('URL_BUDGET')
            self.url_index[digest] = len(self.r['urls'])
            self.r['urls'].append({'id': digest, 'kind': kind, 'value': raw,
                                   'region': analyze_region(raw, kind)})
        return digest

    def rows(self, service, params, key, login, cap, coverage, stage):
        try:
            yield from page_rows(self.get, service, params, key, login, cap, coverage)
        except Stop as error:
            if error.info['code'] in ('TIME_BUDGET', 'REQUEST_BUDGET', 'TOTAL_RESPONSE_BUDGET'):
                raise
            self.error(stage, error, client_login=login)
            coverage['complete'] = False

    def collect_account(self, login, identity):
        account = {'client_login': login, 'identity': identity, 'campaigns': [], 'ads': [],
                   'groups': [], 'sitelinks': [], 'unsupported': [],
                   'coverage': {k: {'pages': 0, 'rows': 0, 'complete': False}
                                for k in ('campaigns', 'ads', 'groups', 'sitelinks')}}
        self.r['accounts'].append(account)
        cover = account['coverage']
        campaigns = list(self.rows('campaigns',
            {'SelectionCriteria': {}, 'FieldNames': ['Id', 'Name', 'Type', 'State', 'Status'],
             'UnifiedCampaignFieldNames': ['TrackingParams']}, 'Campaigns', login,
            MAX_CAMPAIGNS, cover['campaigns'], 'campaigns'))
        for c in campaigns:
            row = {k: c.get(k) for k in ('Id', 'Name', 'Type', 'State', 'Status')}
            tracking = c.get('UnifiedCampaign', {})
            if isinstance(tracking, dict):
                row['tracking_url_id'] = self.url(tracking.get('TrackingParams'), 'tracking')
            account['campaigns'].append(row)
        cids = [positive_id(x['Id']) for x in campaigns]
        if not cids:
            for key in ('ads', 'groups', 'sitelinks'):
                cover[key]['complete'] = cover['campaigns']['complete']
            account['static_destination_complete'] = cover['campaigns']['complete']
            return
        # A shared stage counter gives a real whole-account cap across all batches.
        ad_batches_complete, group_batches_complete = [], []
        ads = {}
        for start in range(0, len(cids), 10):
            batch = cids[start:start + 10]
            group_page = {'pages': 0, 'rows': cover['groups']['rows'], 'complete': False}
            for g in self.rows('adgroups', {'SelectionCriteria': {'CampaignIds': batch},
                 'FieldNames': ['Id', 'CampaignId', 'Type', 'Subtype', 'TrackingParams'],
                 'MobileAppAdGroupFieldNames': ['StoreUrl'],
                 'DynamicTextAdGroupFieldNames': ['DomainUrl'],
                 'DynamicTextFeedAdGroupFieldNames': ['FeedId'],
                 'SmartAdGroupFieldNames': ['FeedId']}, 'AdGroups', login, MAX_GROUPS,
                 group_page, 'adgroups'):
                if positive_id(g.get('CampaignId')) not in batch:
                    raise Stop('GROUP_CAMPAIGN_SCOPE_MISMATCH')
                row = {k: g.get(k) for k in ('Id', 'CampaignId', 'Type', 'Subtype')}
                row['tracking_url_id'] = self.url(g.get('TrackingParams'), 'tracking')
                for block, field in [('MobileAppAdGroup', 'StoreUrl'),
                                     ('DynamicTextAdGroup', 'DomainUrl')]:
                    if isinstance(g.get(block), dict):
                        row[block] = {field: self.url(g[block].get(field), 'url')}
                for block in ('DynamicTextFeedAdGroup', 'SmartAdGroup'):
                    if block in g:
                        row[block] = g[block]
                account['groups'].append(row)
            cover['groups']['pages'] += group_page['pages']
            cover['groups']['rows'] = group_page['rows']
            group_batches_complete.append(group_page['complete'])
            ad_page = {'pages': 0, 'rows': cover['ads']['rows'], 'complete': False}
            for ad in self.rows('ads', {'SelectionCriteria': {'CampaignIds': batch},
                                      'FieldNames': AD_BASE_FIELDS}, 'Ads', login,
                                MAX_ADS, ad_page, 'ads_inventory'):
                aid = positive_id(ad['Id'])
                if positive_id(ad.get('CampaignId')) not in batch:
                    raise Stop('AD_CAMPAIGN_SCOPE_MISMATCH')
                positive_id(ad.get('AdGroupId'))
                if aid in ads:
                    raise Stop('AD_ID_DUPLICATED_ACROSS_CAMPAIGNS')
                ads[aid] = {k: ad.get(k) for k in AD_BASE_FIELDS}
            cover['ads']['pages'] += ad_page['pages']
            cover['ads']['rows'] = ad_page['rows']
            ad_batches_complete.append(ad_page['complete'])
        cover['ads']['complete'] = all(ad_batches_complete)
        cover['groups']['complete'] = all(group_batches_complete)
        account['ads'] = list(ads.values())
        sitelink_ids = set()
        for key in sorted({(a.get('Type'), a.get('Subtype', 'NONE')) for a in ads.values()},
                          key=lambda value: (str(value[0]), str(value[1]))):
            selected = [a for a in ads.values() if (a.get('Type'), a.get('Subtype', 'NONE')) == key]
            detail_spec = AD_DETAILS.get(key)
            if detail_spec is None:
                account['unsupported'].append({'type': key[0], 'subtype': key[1],
                    'ads': len(selected), 'reason': 'NO_STATIC_DESTINATION_READER'})
                for ad in selected:
                    ad['detail_complete'] = False
                continue
            block, fields = detail_spec
            for start in range(0, len(selected), 1000):
                chunk = selected[start:start + 1000]
                ids = [a['Id'] for a in chunk]
                detail_page = {'pages': 0, 'rows': 0, 'complete': False}
                found = set()
                for detail in self.rows('ads', {'SelectionCriteria': {'Ids': ids},
                    'FieldNames': AD_BASE_FIELDS, block + 'FieldNames': fields}, 'Ads',
                    login, 1000, detail_page, 'ads_details'):
                    aid = positive_id(detail['Id'])
                    if aid not in ids or any(detail.get(k) != ads[aid].get(k)
                                             for k in ('CampaignId', 'AdGroupId', 'Type', 'Subtype')):
                        raise Stop('AD_CHANGED_OR_SCOPE_MISMATCH')
                    found.add(aid)
                    payload = detail.get(block)
                    if not isinstance(payload, dict):
                        ads[aid]['detail_complete'] = False
                        self.error('ads_details', Stop('AD_DETAIL_BLOCK_MISSING'),
                                   client_login=login, ad_id=aid)
                        continue
                    ads[aid]['detail_complete'] = detail_page['complete']
                    ads[aid]['detail'] = {}
                    for field in fields:
                        value = payload.get(field)
                        if field in ('Href', 'TrackingUrl'):
                            ads[aid]['detail'][field + 'UrlId'] = self.url(value, 'url')
                        elif field == 'SitelinkSetId':
                            if value is not None:
                                sitelink_ids.add(positive_id(value))
                            ads[aid]['detail'][field] = value
                        else:
                            ads[aid]['detail'][field] = value
                    if key[0] in ('DYNAMIC_TEXT_AD', 'SHOPPING_AD', 'LISTING_AD'):
                        ads[aid]['generated_destination_unresolved'] = True
                    elif not any(ads[aid]['detail'].get(f + 'UrlId') for f in ('Href', 'TrackingUrl')):
                        ads[aid]['generated_destination_unresolved'] = True
                for ad in chunk:
                    ad['detail_complete'] = (ad.get('detail') is not None and
                                             detail_page['complete'] and ad['Id'] in found)
                    if ad['Id'] not in found:
                        self.error('ads_details', Stop('AD_DETAIL_NOT_RETURNED'),
                                   client_login=login, ad_id=ad['Id'])
        account['ads'] = list(ads.values())
        sitelink_batches_complete = []
        slids = sorted(sitelink_ids)
        for start in range(0, len(slids), 100):
            page = {'pages': 0, 'rows': cover['sitelinks']['rows'], 'complete': False}
            found = set()
            for s in self.rows('sitelinks', {'SelectionCriteria': {'Ids': slids[start:start + 100]},
                'FieldNames': ['Id'], 'SitelinkFieldNames': ['Href', 'TurboPageId']},
                'SitelinksSets', login, 10_000, page, 'sitelinks'):
                sid = positive_id(s['Id'])
                if sid not in slids[start:start + 100]:
                    raise Stop('SITELINK_SCOPE_MISMATCH')
                found.add(sid)
                items = s.get('Sitelinks')
                if not isinstance(items, list) or len(items) > 8 or any(not isinstance(v, dict) for v in items):
                    raise Stop('SITELINK_SHAPE_INVALID')
                account['sitelinks'].append({'Id': sid, 'items': [
                    {'url_id': self.url(v.get('Href'), 'url'),
                     'TurboPageId': v.get('TurboPageId')} for v in items]})
            cover['sitelinks']['pages'] += page['pages']
            cover['sitelinks']['rows'] = page['rows']
            complete = page['complete'] and found == set(slids[start:start + 100])
            sitelink_batches_complete.append(complete)
            if not complete:
                self.error('sitelinks', Stop('REFERENCED_SITELINKS_INCOMPLETE'), client_login=login)
        cover['sitelinks']['complete'] = all(sitelink_batches_complete)
        account['static_destination_complete'] = (
            all(x['complete'] for x in cover.values()) and
            all(a.get('detail_complete') and not a.get('generated_destination_unresolved')
                for a in account['ads']) and not account['unsupported'] and
            not any(v.get('TurboPageId') for s in account['sitelinks'] for v in s['items']) and
            not any(not v.get('url_id') for s in account['sitelinks'] for v in s['items']) and
            not any(a.get('detail', {}).get('TurboPageId') for a in account['ads']))

    def collect(self, configured_login):
        data = self.get('clients', {'FieldNames': ['ClientId', 'Login', 'Type']}, None)
        clients = data.get('Clients')
        if not isinstance(clients, list) or len(clients) != 1:
            raise Stop('PRINCIPAL_IDENTITY_NOT_UNIQUE')
        principal = clients[0]
        if not isinstance(principal, dict) or not valid_login(principal.get('Login')):
            raise Stop('PRINCIPAL_LOGIN_INVALID')
        positive_id(principal.get('ClientId'))
        self.r['principal'] = {k: principal.get(k) for k in ('ClientId', 'Login', 'Type')}
        accounts = {}
        discovery = {'complete': False, 'basis': 'principal_and_configured_login',
                     'agency_pages': [], 'independent_delegated_accounts_enumerable': False}
        self.r['account_discovery'] = discovery
        if principal.get('Type') == 'AGENCY':
            discovery['basis'] = 'agencyclients_archived_yes_and_no_plus_configured_login'
            done = []
            for archived in ('NO', 'YES'):
                coverage = {'pages': 0, 'rows': 0, 'complete': False}
                for row in self.rows('agencyclients',
                    {'SelectionCriteria': {'Archived': archived},
                     'FieldNames': ['ClientId', 'Login', 'Type', 'Archived']},
                    'Clients', None, MAX_ACCOUNTS, coverage, 'agencyclients'):
                    if not valid_login(row.get('Login')):
                        raise Stop('AGENCY_CLIENT_LOGIN_INVALID')
                    accounts[row['Login']] = row
                discovery['agency_pages'].append({'archived': archived, **coverage})
                done.append(coverage['complete'])
            discovery['complete'] = all(done)
        elif principal.get('Type') in ('CLIENT', 'SUBCLIENT'):
            accounts[principal['Login']] = principal
            discovery['complete'] = True
        else:
            raise Stop('PRINCIPAL_TYPE_UNSUPPORTED')
        if configured_login:
            accounts.setdefault(configured_login, {'Login': configured_login, 'source': 'local_config'})
        if not accounts:
            raise Stop('NO_ADVERTISER_ACCOUNT_DISCOVERED')
        if len(accounts) > MAX_ACCOUNTS:
            self.error('accounts', Stop('ACCOUNT_BUDGET'))
            discovery['complete'] = False
        # Existing configured account first; advertised starting point is only a hint.
        order = sorted(accounts, key=lambda x: (x != configured_login, x != STARTING_ACCOUNT, x))
        for login in order[:MAX_ACCOUNTS]:
            try:
                self.collect_account(login, accounts[login])
            except Stop as error:
                if error.info['code'] in ('TIME_BUDGET', 'REQUEST_BUDGET', 'TOTAL_RESPONSE_BUDGET'):
                    raise
                self.error('account', error, client_login=login)
        self.r['complete'] = (discovery['complete'] and not self.r['errors'] and
            all(a.get('static_destination_complete', False) for a in self.r['accounts']))
        self.r['status'] = 'COLLECTED' if self.r['complete'] else 'PARTIAL'


def fit_report(report):
    """Never silently truncate an encrypted payload: explicit omitted counts and incomplete."""
    report['finished_at_utc'] = timestamp()
    omitted = {}
    while len(json.dumps(report, ensure_ascii=False, allow_nan=False).encode()) > MAX_REPORT_BYTES:
        report['complete'], report['status'], report['output_truncated'] = False, 'PARTIAL', True
        removed = False
        for account in reversed(report['accounts']):
            for field in ('ads', 'groups', 'sitelinks', 'campaigns'):
                if account[field]:
                    count = min(1000, len(account[field]))
                    del account[field][-count:]
                    omitted[field] = omitted.get(field, 0) + count
                    removed = True
                    break
            if removed:
                break
        if not removed and report['urls']:
            count = min(1000, len(report['urls']))
            del report['urls'][-count:]
            omitted['urls'] = omitted.get('urls', 0) + count
            removed = True
        if not removed:
            raise Stop('UNREDUCIBLE_REPORT_SIZE')
        report['omitted_output'] = omitted
    return report


def main():
    report = new_report()
    def timeout(*_):
        raise Stop('TIME_BUDGET')
    signal.signal(signal.SIGALRM, timeout)
    signal.alarm(DEADLINE_SECONDS)
    try:
        token, login = load_local_config(report)
        Collector(make_reader(token, report), report).collect(login)
    except Stop as error:
        report['errors'].append(error.info)
        report['status'] = 'PARTIAL' if report['accounts'] else 'READ_FAILED'
    except Exception:
        report['errors'].append({'code': 'LOCAL_READER_FAILED'})
        report['status'] = 'PARTIAL' if report['accounts'] else 'READ_FAILED'
    finally:
        signal.alarm(0)
    try:
        output = fit_report(report)
    except Exception:
        output = {'schema': 1, 'audit_kind': AUDIT_KIND, 'status': 'READ_FAILED',
                  'error_code': 'REPORT_SERIALIZATION_FAILED', 'server_writes': False,
                  'advertising_writes': False, 'api_writes': False,
                  'database_opened': False, 'token_exported': False, 'complete': False}
    print(json.dumps(output, ensure_ascii=False, allow_nan=False))


if __name__ == '__main__':
    main()
