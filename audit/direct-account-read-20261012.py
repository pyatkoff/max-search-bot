"""One account, fourteen completed Direct report dates; existing local OAuth only."""
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
from datetime import datetime, timedelta, timezone
from decimal import Decimal
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
LOGIN = 'anytour2024tp'
last = datetime.now(ZoneInfo('Europe/Moscow')).date() - timedelta(days=1)
first = last - timedelta(days=13)
out = {'ok': False, 'account': LOGIN, 'generated_at_utc': datetime.now(timezone.utc).isoformat(),
       'date1': first.isoformat(), 'date2': last.isoformat(), 'completed_dates_only': True,
       'date_basis': 'Direct Reports Date, explicit calendar dates', 'include_vat': False,
       'include_discount': False, 'server_writes': False, 'advertising_writes': False,
       'database_opened': False, 'token_exported': False, 'requests': []}

class ReadError(Exception):
    pass

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def require(condition, code):
    if not condition:
        raise ReadError(code)

def run():
    from dotenv import dotenv_values
    require(ROOT.is_dir() and not ROOT.is_symlink() and ROOT.resolve() == ROOT, 'ROOT_SCOPE')
    require(Path.cwd() == ROOT, 'EXACT_DIRECTORY_REQUIRED')
    origin = subprocess.check_output(['git', '--no-optional-locks', '-C', str(ROOT),
                                     'config', '--get', 'remote.origin.url'], text=True, timeout=5).strip()
    require(origin in ['git@github.com:pyatkoff/yandex-direct-autopilot.git',
                       'https://github.com/pyatkoff/yandex-direct-autopilot.git',
                       'https://github.com/pyatkoff/yandex-direct-autopilot'], 'REPO_IDENTITY')
    env = ROOT / '.env'
    require(not env.is_symlink() and env.is_file() and env.stat().st_size <= 65536, 'ENV_SCOPE')
    cfg = dotenv_values(env, interpolate=False)
    def val(key, default=''):
        return os.environ.get(key, cfg.get(key, default))
    require(str(val('READ_ONLY')).lower() == 'true', 'READ_ONLY_CONFIGURATION')
    require(str(val('YANDEX_DIRECT_SANDBOX', 'false')).lower() in ['false', '0'], 'SANDBOX_ENABLED')
    require(val('YANDEX_DIRECT_CLIENT_LOGIN') == LOGIN, 'ACCOUNT_MISMATCH')
    token = val('YANDEX_OAUTH_TOKEN')
    require(isinstance(token, str) and bool(token.strip()), 'LOCAL_TOKEN_MISSING')
    columns = ['Date', 'CampaignId', 'CampaignName', 'Impressions', 'Clicks', 'Cost']
    params = {'SelectionCriteria': {'DateFrom': out['date1'], 'DateTo': out['date2']},
              'FieldNames': columns, 'OrderBy': [{'Field': 'Date', 'SortOrder': 'ASCENDING'},
                                               {'Field': 'CampaignId', 'SortOrder': 'ASCENDING'}],
              'Page': {'Limit': 10000, 'Offset': 0}, 'ReportType': 'CAMPAIGN_PERFORMANCE_REPORT',
              'DateRangeType': 'CUSTOM_DATE', 'Format': 'TSV', 'IncludeVAT': 'NO', 'IncludeDiscount': 'NO'}
    params['ReportName'] = 'AnyTour-account-read-' + hashlib.sha256(json.dumps(params, sort_keys=True).encode()).hexdigest()[:20]
    headers = {'Authorization': 'Bearer ' + token, 'Client-Login': LOGIN,
               'Content-Type': 'application/json; charset=utf-8', 'Accept-Language': 'en',
               'processingMode': 'auto', 'returnMoneyInMicros': 'false', 'skipReportHeader': 'true',
               'skipColumnHeader': 'false', 'skipReportSummary': 'true'}
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    for attempt in range(4):
        req = urllib.request.Request('https://api.direct.yandex.com/json/v5/reports',
            data=json.dumps({'params': params}).encode(), headers=headers, method='POST')
        try:
            with opener.open(req, timeout=25) as response:
                status, rh, body = response.status, response.headers, response.read(2000001)
        except urllib.error.HTTPError as error:
            status, rh, body = error.code, error.headers, error.read(2000001)
        out['requests'].append({'http': status, 'request_id': rh.get('RequestId')})
        require(len(body) <= 2000000, 'RESPONSE_BUDGET')
        if status == 200:
            break
        if status in (201, 202, 429, 500, 502, 503, 504) and attempt < 3:
            raw = rh.get('retryIn', rh.get('Retry-After', '3'))
            delay = int(raw) if str(raw).isdigit() else 3
            require(delay <= 15, 'REPORT_PENDING')
            time.sleep(max(1, delay))
            continue
        raise ReadError('DIRECT_REPORT_HTTP_' + str(status))
    reader = csv.DictReader(io.StringIO(body.decode('utf-8-sig')), delimiter='\t')
    require(reader.fieldnames == columns, 'COLUMNS_MISMATCH')
    rows = list(reader)
    require(len(rows) < 10000, 'REPORT_PAGE_BUDGET_REACHED')
    seen = set()
    clean = []
    for row in rows:
        require(set(row) == set(columns), 'ROW_SHAPE')
        cid, day = row['CampaignId'], row['Date']
        require(re.fullmatch(r'[1-9][0-9]*', cid) and out['date1'] <= day <= out['date2'], 'ROW_SCOPE')
        require((cid, day) not in seen, 'DUPLICATE_ROW')
        seen.add((cid, day))
        require(re.fullmatch(r'[0-9]+', row['Impressions']) and re.fullmatch(r'[0-9]+', row['Clicks']), 'COUNT_FORMAT')
        cost = Decimal(row['Cost'])
        require(cost.is_finite() and cost >= 0, 'COST_FORMAT')
        clean.append({'date': day, 'campaign_id': int(cid), 'campaign_name': row['CampaignName'],
                      'impressions': int(row['Impressions']), 'clicks': int(row['Clicks']),
                      'cost_no_vat': str(cost)})
    out.update({'ok': True, 'complete': True, 'rows': clean, 'row_count': len(clean),
                'campaign_count': len({row['campaign_id'] for row in clean}),
                'limitations': ['No goals requested; no account-wide conversion or attribution model assumed.',
                                'Campaigns without report rows are absent, not asserted inactive.',
                                'Reports can be revised later by Direct.']})

if __name__ == '__main__':
    signal.signal(signal.SIGALRM, lambda *args: (_ for _ in ()).throw(ReadError('TIME_BUDGET')))
    signal.alarm(140)
    try:
        run()
    except ReadError as error:
        out['error'] = str(error)
    except Exception:
        out['error'] = 'LOCAL_READER_FAILED'
    finally:
        signal.alarm(0)
        print(json.dumps(out, ensure_ascii=False, allow_nan=False))
