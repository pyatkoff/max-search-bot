"""Ephemeral fixed-scope Direct reader; stdout MUST be encrypted by its runner.

Stream this source to the established server Python. Do not install it as a
worker. No application import, database, credential export or advertising write.
"""
import sys
sys.dont_write_bytecode = True

import csv
import hashlib
import io
import json
import os
import re
import signal
import subprocess
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path

ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
CLIENT_LOGIN = 'anytour2024tp'
PROBE_ID = 'anytour-hotel-feed-audit-20261119'
DATE_FROM, DATE_TO = '2026-11-12', '2026-11-18'
TOTAL_SECONDS, MAX_CALLS = 180, 30
MAX_RESPONSE_BYTES, MAX_STDOUT_BYTES = 4 * 1024 * 1024, 12_000_000
MAX_SAVED_BYTES = MAX_STDOUT_BYTES - 512 * 1024
CAMPAIGN_FIELDS = ['Id', 'Name', 'State', 'Status', 'Type', 'DailyBudget']
CAMPAIGN_REPORT_FIELDS = ['CampaignId', 'CampaignName', 'Impressions', 'Clicks', 'Cost', 'AvgCpc']
AD_REPORT_FIELDS = ['CampaignId', 'AdGroupId', 'AdId', 'Impressions', 'Clicks', 'Cost', 'AvgCpc']
AD_FIELDS = ['Id', 'CampaignId', 'AdGroupId', 'Type', 'State', 'Status']
TEXT_FIELDS = ['Title', 'Title2', 'Text', 'Href']
RESPONSIVE_FIELDS = ['Titles', 'Texts', 'Href']
FEED_FIELDS = ['FeedId', 'FeedFilterConditions', 'FeedProcessingStatus']
FEED_INVENTORY_FIELDS = ['Id', 'Name', 'BusinessType', 'SourceType', 'CampaignIds',
                         'NumberOfItems', 'Status', 'UpdatedAt']


def new_result():
    return {'schema_version': 1, 'probe_id': PROBE_ID,
            'started_at_utc': datetime.now(timezone.utc).isoformat(),
            'period': {'from': DATE_FROM, 'to': DATE_TO, 'include_vat': True,
                       'return_money_in_micros': False,
                       'date_basis': 'Direct Reports Date; fixed requested completed dates'},
            'client_login': CLIENT_LOGIN,
            'server_writes': False, 'advertising_writes': False,
            'database_opened': False, 'token_exported': False,
            'hotel_classification': 'NOT_INFERRED_FROM_CAMPAIGN_NAME',
            'requests': [], 'responses': [], 'errors': [],
            'inventory': {'complete': False},
            'feeds': {'complete': False},
            'campaign_performance': {'complete': False},
            'ad_performance': {'complete': False},
            'ad_details': {'complete': False}}


RESULT = new_result()


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


def report_id(value, optional=False):
    if optional and value == '--':
        return None
    return positive_id(value)


def verify_configured_login(value):
    # The original access probe uses the fixed approved Client-Login header.
    # An absent optional local login must not reject that existing path.
    if value not in (None, '', CLIENT_LOGIN):
        raise Stop('ACCOUNT_MISMATCH')


def numeric(value, integer=False, missing=False):
    if missing and value == '--':
        return None
    if not isinstance(value, str) or not re.fullmatch(r'[0-9]+(?:\.[0-9]+)?', value):
        raise Stop('INVALID_REPORT_NUMBER')
    try:
        number = Decimal(value)
    except InvalidOperation:
        raise Stop('INVALID_REPORT_NUMBER') from None
    if not number.is_finite() or number < 0 or (integer and number != number.to_integral_value()):
        raise Stop('INVALID_REPORT_NUMBER')
    return int(number) if integer else number


def parse_report(text, expected):
    """Validate exact headers/row widths, preserving original numeric strings."""
    reader = csv.DictReader(io.StringIO(text), delimiter='\t', strict=True)
    try:
        if reader.fieldnames != expected:
            raise Stop('REPORT_COLUMNS_MISMATCH')
        rows, seen = [], set()
        identity = [key for key in expected if key in ('CampaignId', 'AdGroupId', 'AdId')]
        for row in reader:
            if set(row) != set(expected) or any(value is None for value in row.values()):
                raise Stop('REPORT_ROW_WIDTH_MISMATCH')
            key = tuple(report_id(row[field], optional=field in ('AdGroupId', 'AdId'))
                        for field in identity)
            if key in seen and None not in key:
                raise Stop('DUPLICATE_REPORT_ROW')
            seen.add(key)
            numeric(row['Impressions'], integer=True)
            numeric(row['Clicks'], integer=True)
            numeric(row['Cost'])
            numeric(row['AvgCpc'], missing=True)
            rows.append(row)
        return rows
    except csv.Error:
        raise Stop('INVALID_REPORT_TSV') from None


def select_ads(rows):
    """Select distinct click-bearing ads by summed cost, bounded to 200 IDs."""
    totals = {}
    for row in rows:
        # Campaign Wizard may legitimately return '--' for these identifiers.
        # Its spend remains in the raw report and report totals, not attributed
        # to a guessed individual ad or one of the other returned ad IDs.
        if row['AdId'] == '--' or row['AdGroupId'] == '--':
            continue
        if numeric(row['Clicks'], integer=True) <= 0:
            continue
        identity = positive_id(row['AdId'])
        binding = (positive_id(row['CampaignId']), positive_id(row['AdGroupId']))
        if identity in totals and totals[identity]['binding'] != binding:
            raise Stop('AD_ID_SCOPE_CONFLICT')
        entry = totals.setdefault(identity, {'binding': binding, 'cost': Decimal(0)})
        entry['cost'] += numeric(row['Cost'])
    selected = sorted(totals, key=lambda identity: (-totals[identity]['cost'], identity))[:200]
    return selected, totals


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

    def read(endpoint, payload, label, report=False):
        if endpoint not in ('campaigns', 'ads', 'reports', 'feeds'):
            raise Stop('ENDPOINT_NOT_ALLOWED')
        if report:
            params = payload.get('params', {})
            if endpoint != 'reports' or params.get('ReportType') not in (
                'CAMPAIGN_PERFORMANCE_REPORT', 'AD_PERFORMANCE_REPORT'):
                raise Stop('REPORT_NOT_ALLOWED')
            if params.get('SelectionCriteria') != {'DateFrom': DATE_FROM, 'DateTo': DATE_TO}:
                raise Stop('REPORT_SCOPE_CHANGED')
            if params.get('IncludeVAT') != 'YES' or 'Page' in params:
                raise Stop('REPORT_CONTRACT_CHANGED')
        elif endpoint == 'reports' or payload.get('method') != 'get':
            raise Stop('READ_METHOD_REQUIRED')
        headers = {'Authorization': 'Bearer ' + token, 'Client-Login': CLIENT_LOGIN,
                   'Accept-Language': 'ru', 'Content-Type': 'application/json; charset=utf-8'}
        if report:
            headers.update({'processingMode': 'auto', 'returnMoneyInMicros': 'false',
                            'skipReportHeader': 'true', 'skipColumnHeader': 'false',
                            'skipReportSummary': 'true'})
        data = encode(payload)
        url = 'https://api.direct.yandex.com/json/v501/' + endpoint
        for poll in range(3 if report else 1):
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
            if report and status in (201, 202):
                wait = rh.get('retryIn', '3')
                if (poll >= 2 or not re.fullmatch(r'[0-9]{1,3}', wait)
                        or not 0 <= int(wait) <= 10):
                    raise Stop('REPORT_PENDING', status)
                delay = max(1, int(wait))
                if deadline - time.monotonic() <= delay:
                    raise Stop('TIME_BUDGET', status)
                time.sleep(delay)
                continue
            if report and status == 200:
                return text
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
        raise Stop('REPORT_PENDING')
    return read


def inventory(read):
    offset, seen, pages = 0, set(), 0
    for page in range(5):
        payload = {'method': 'get', 'params': {'SelectionCriteria': {},
                   'FieldNames': CAMPAIGN_FIELDS, 'Page': {'Limit': 1000, 'Offset': offset}}}
        body = read('campaigns', payload, 'campaign_inventory_' + str(page))
        block = body.get('result')
        rows = block.get('Campaigns') if isinstance(block, dict) else None
        if not isinstance(rows, list) or len(rows) > 1000:
            raise Stop('INVALID_CAMPAIGN_PAGE')
        batch = []
        for row in rows:
            if not isinstance(row, dict):
                raise Stop('INVALID_CAMPAIGN_ROW')
            identity = positive_id(row.get('Id'))
            if identity in seen or identity in batch:
                raise Stop('DUPLICATE_OR_DRIFTING_CAMPAIGN_PAGE')
            batch.append(identity)
        seen.update(batch)
        pages += 1
        RESULT['inventory'].update(rows=len(seen), pages=pages, campaign_ids=sorted(seen),
                                   pagination_atomic=False)
        limited = block.get('LimitedBy')
        if limited is None:
            RESULT['inventory']['complete'] = True
            return
        next_offset = positive_id(limited)
        if next_offset <= offset or next_offset > 5000:
            raise Stop('INVALID_CAMPAIGN_CURSOR')
        offset = next_offset
    raise Stop('CAMPAIGN_INVENTORY_CAP_5000')


def report_request(kind, fields):
    return {'params': {'SelectionCriteria': {'DateFrom': DATE_FROM, 'DateTo': DATE_TO},
                       'FieldNames': fields, 'ReportName': PROBE_ID + '-' + kind.lower(),
                       'ReportType': kind, 'DateRangeType': 'CUSTOM_DATE',
                       'Format': 'TSV', 'IncludeVAT': 'YES'}}


def collect_feeds(read):
    payload = {'method': 'get', 'params': {'FieldNames': FEED_INVENTORY_FIELDS,
               'UrlFeedFieldNames': ['Url', 'RemoveUtmTags'], 'FileFeedFieldNames':['Filename'], 'Page': {'Limit': 1000, 'Offset': 0}}}
    body = read('feeds', payload, 'feed_inventory')
    block = body.get('result')
    rows = block.get('Feeds') if isinstance(block, dict) else None
    if not isinstance(rows, list) or len(rows) > 1000:
        raise Stop('INVALID_FEED_PAGE')
    seen = set()
    for row in rows:
        if not isinstance(row, dict):
            raise Stop('INVALID_FEED_ROW')
        identity = positive_id(row.get('Id'))
        if identity in seen:
            raise Stop('DUPLICATE_FEED_ROW')
        seen.add(identity)
    RESULT['feeds'].update(rows=len(rows), feed_ids=sorted(seen),
                           complete=block.get('LimitedBy') is None,
                           pagination='one_bounded_page_only')
    if not RESULT['feeds']['complete']:
        raise Stop('FEED_INVENTORY_PAGE_CAP')


def collect(read):
    for stage, operation in [('inventory', lambda: inventory(read)),
                             ('feeds', lambda: collect_feeds(read)),
                             ('campaign_performance', lambda: collect_campaigns(read)),
                             ('ad_performance', lambda: collect_ads(read))]:
        try:
            operation()
        except Stop as error:
            RESULT['errors'].append({'stage': stage, **error.info})
    RESULT['status'] = 'COLLECTED' if not RESULT['errors'] else 'PARTIAL'


def collect_campaigns(read):
    text = read('reports', report_request('CAMPAIGN_PERFORMANCE_REPORT', CAMPAIGN_REPORT_FIELDS),
                'campaign_performance', report=True)
    rows = parse_report(text, CAMPAIGN_REPORT_FIELDS)
    RESULT['campaign_performance'].update(complete=True, rows=len(rows),
        impressions=sum(numeric(row['Impressions'], integer=True) for row in rows),
        clicks=sum(numeric(row['Clicks'], integer=True) for row in rows),
        cost=str(sum((numeric(row['Cost']) for row in rows), Decimal(0))))


def collect_ads(read):
    text = read('reports', report_request('AD_PERFORMANCE_REPORT', AD_REPORT_FIELDS),
                'ad_performance', report=True)
    rows = parse_report(text, AD_REPORT_FIELDS)
    selected, all_clicked = select_ads(rows)
    unresolved = [row for row in rows if row['AdId'] == '--' or row['AdGroupId'] == '--']
    unresolved_clicks = sum(numeric(row['Clicks'], integer=True) for row in unresolved)
    RESULT['ad_performance'].update(complete=True, rows=len(rows),
        click_bearing_distinct_ads=len(all_clicked),
        ad_identity_coverage_complete=not unresolved,
        unresolved_identity_rows=len(unresolved),
        unresolved_identity_clicks=unresolved_clicks,
        unresolved_identity_impressions=sum(numeric(row['Impressions'], integer=True) for row in unresolved),
        unresolved_identity_cost=str(sum((numeric(row['Cost']) for row in unresolved), Decimal(0))),
        unresolved_identity_reason='Direct may omit AdGroupId/AdId for Campaign Wizard; no ad/feed inferred',
        impressions=sum(numeric(row['Impressions'], integer=True) for row in rows),
        clicks=sum(numeric(row['Clicks'], integer=True) for row in rows),
        cost=str(sum((numeric(row['Cost']) for row in rows), Decimal(0))))
    RESULT['ad_details'].update(selected_ids=selected, selected_count=len(selected),
        selection='up_to_200_distinct_click_bearing_AdId_by_summed_Cost_desc_then_AdId',
        all_resolved_click_bearing_ads_selected=len(selected) == len(all_clicked),
        all_click_bearing_report_rows_represented=len(selected) == len(all_clicked) and unresolved_clicks == 0,
        text_types_supported=['TEXT_AD', 'RESPONSIVE_AD'],
        feed_types_supported=['SHOPPING_AD', 'LISTING_AD'])
    if not selected:
        RESULT['ad_details'].update(complete=True, returned_count=0, missing_ids=[])
        return
    payload = {'method': 'get', 'params': {'SelectionCriteria': {'Ids': selected},
               'FieldNames': AD_FIELDS, 'TextAdFieldNames': TEXT_FIELDS,
               'ResponsiveAdFieldNames': RESPONSIVE_FIELDS,
               'ShoppingAdFieldNames': FEED_FIELDS, 'ListingAdFieldNames': FEED_FIELDS,
               'Page': {'Limit': 200, 'Offset': 0}}}
    body = read('ads', payload, 'selected_ad_details')
    block = body.get('result')
    ads = block.get('Ads') if isinstance(block, dict) else None
    if not isinstance(ads, list) or len(ads) > len(selected):
        raise Stop('INVALID_AD_DETAILS')
    returned, unsupported, missing_content, feed_bindings = set(), [], [], []
    for row in ads:
        if not isinstance(row, dict):
            raise Stop('INVALID_AD_DETAIL_ROW')
        identity = positive_id(row.get('Id'))
        if identity not in selected or identity in returned:
            raise Stop('AD_DETAILS_SCOPE_MISMATCH')
        returned.add(identity)
        binding = (positive_id(row.get('CampaignId')), positive_id(row.get('AdGroupId')))
        if binding != all_clicked[identity]['binding']:
            raise Stop('AD_DETAILS_BINDING_CHANGED')
        if row.get('Type') not in ('TEXT_AD', 'RESPONSIVE_AD'):
            unsupported.append(identity)
        else:
            content = row.get('TextAd' if row['Type'] == 'TEXT_AD' else 'ResponsiveAd')
            if not isinstance(content, dict) or not isinstance(content.get('Href'), str):
                missing_content.append(identity)
        if row.get('Type') in ('SHOPPING_AD', 'LISTING_AD'):
            content = row.get('ShoppingAd' if row['Type'] == 'SHOPPING_AD' else 'ListingAd')
            if isinstance(content, dict) and content.get('FeedId') is not None:
                feed_bindings.append({'ad_id': identity, 'feed_id': positive_id(content['FeedId'])})
    RESULT['ad_details'].update(returned_count=len(returned), missing_ids=sorted(set(selected) - returned),
        unsupported_text_type_ids=unsupported, missing_href_ids=missing_content,
        feed_bindings=feed_bindings,
        text_coverage_complete=not unsupported and not missing_content,
        complete=block.get('LimitedBy') is None and returned == set(selected))
    if not RESULT['ad_details']['complete']:
        raise Stop('AD_DETAILS_PARTIAL')
    if unsupported or missing_content:
        RESULT['errors'].append({'stage': 'ad_details', 'code': 'AD_TEXT_TYPE_OR_HREF_UNAVAILABLE'})


def finish():
    RESULT['finished_at_utc'] = datetime.now(timezone.utc).isoformat()
    output = encode(RESULT)
    if len(output) > MAX_STDOUT_BYTES:
        # The retain() reserve makes this unreachable within the fixed bounds.
        # Preserve already accepted responses rather than dropping them silently.
        raise Stop('STDOUT_BUDGET')
    sys.stdout.buffer.write(output + b'\n')



def collect_metadata(read):
    active=[]
    offset=0
    seen=set()
    for page in range(5):
        payload={'method':'get','params':{'SelectionCriteria':{},'FieldNames':CAMPAIGN_FIELDS,'Page':{'Limit':1000,'Offset':offset}}}
        body=read('campaigns',payload,'campaign_inventory_'+str(page))
        block=body.get('result');rows=block.get('Campaigns') if isinstance(block,dict) else None
        if not isinstance(rows,list) or len(rows)>1000:raise Stop('INVALID_CAMPAIGN_PAGE')
        for row in rows:
            cid=positive_id(row.get('Id'))
            if cid in seen:raise Stop('CAMPAIGN_PAGE_DRIFT')
            seen.add(cid)
            if row.get('State')=='ON':active.append(cid)
        RESULT['inventory'].update(rows=len(seen),active_count=len(active),pages=page+1)
        if block.get('LimitedBy') is None:
            RESULT['inventory']['complete']=True
            break
        next_offset=positive_id(block['LimitedBy'])
        if next_offset<=offset:raise Stop('INVALID_CAMPAIGN_CURSOR')
        offset=next_offset
    else:raise Stop('CAMPAIGN_PAGE_CAP')
    RESULT['active_ad_bindings']={'complete':False,'active_campaign_ids':sorted(active),'pages':[]}
    if len(active)>100:raise Stop('ACTIVE_CAMPAIGN_CAP_100')
    for start in range(0,len(active),10):
        chunk=sorted(active)[start:start+10]
        payload={'method':'get','params':{'SelectionCriteria':{'CampaignIds':chunk,'States':['ON']},
            'FieldNames':AD_FIELDS,'TextAdFieldNames':TEXT_FIELDS,'ResponsiveAdFieldNames':RESPONSIVE_FIELDS,
            'ShoppingAdFieldNames':FEED_FIELDS,'ListingAdFieldNames':FEED_FIELDS,
            'Page':{'Limit':1000,'Offset':0}}}
        body=read('ads',payload,'active_ads_'+str(start//10))
        block=body.get('result');ads=block.get('Ads') if isinstance(block,dict) else None
        if not isinstance(ads,list):raise Stop('INVALID_ACTIVE_AD_ROWS')
        ids=set()
        for ad in ads:
            aid=positive_id(ad.get('Id'));cid=positive_id(ad.get('CampaignId'))
            if cid not in chunk or aid in ids or ad.get('State')!='ON':raise Stop('ACTIVE_AD_SCOPE')
            ids.add(aid)
        RESULT['active_ad_bindings']['pages'].append({'rows':len(ads),'campaign_ids':chunk,'complete':block.get('LimitedBy') is None})
        if block.get('LimitedBy') is not None:raise Stop('ACTIVE_AD_PAGE_CAP')
    RESULT['active_ad_bindings']['complete']=True

def collect_fresh_ad_report(read):
    text=read('reports',report_request('AD_PERFORMANCE_REPORT',AD_REPORT_FIELDS),'ad_performance',report=True)
    rows=parse_report(text,AD_REPORT_FIELDS)
    RESULT['ad_performance'].update(complete=True,rows=len(rows),
        clicks=sum(numeric(x['Clicks'],integer=True) for x in rows),
        impressions=sum(numeric(x['Impressions'],integer=True) for x in rows),
        cost=str(sum((numeric(x['Cost']) for x in rows),Decimal(0))))

def collect_fresh(read):
    RESULT['scope']='Fresh read-only account feeds and active ad bindings; no campaign, feed, route or budget writes'
    RESULT['import_time_semantics']='UpdatedAt is the provider date of feed data change; not asserted as last successful import time'
    for stage,fn in [('feeds',collect_feeds),('inventory_and_active_ads',collect_metadata),('campaign_performance',collect_campaigns),('ad_performance',collect_fresh_ad_report)]:
        try:fn(read)
        except Stop as error:RESULT['errors'].append({'stage':stage,**error.info})
    RESULT['status']='COLLECTED' if not RESULT['errors'] else 'PARTIAL'

if __name__ == '__main__':
    def timeout(*_):
        raise Stop('TIME_BUDGET')
    signal.signal(signal.SIGALRM, timeout)
    deadline = time.monotonic() + TOTAL_SECONDS
    signal.alarm(TOTAL_SECONDS)
    try:
        collect_fresh(make_reader(load_local_config(), deadline))
    except Stop as error:
        RESULT['errors'].append(error.info)
        RESULT['status'] = 'PARTIAL'
    except Exception:
        RESULT['errors'].append({'code': 'LOCAL_READER_FAILED'})
        RESULT['status'] = 'PARTIAL'
    finally:
        signal.alarm(0)
        finish()
