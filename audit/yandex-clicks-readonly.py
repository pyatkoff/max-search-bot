"""Read-only Yandex Direct campaign clicks for the owner funnel report. No writes, no token export."""
import csv, io, json, os, re, time, urllib.request, urllib.error
from pathlib import Path
from dotenv import dotenv_values
from datetime import datetime, timezone

ROOT=Path('/var/www/anytoour/data/www/d.anytoour.ru')
LOGIN='anytour2024tp'
CIDS=(714260445,714320748)
DATE1,DATE2='2026-09-23','2026-09-29'
out={'ok':False,'generated_at_utc':datetime.now(timezone.utc).isoformat(),'date1':DATE1,'date2':DATE2,
     'campaigns':{str(c):{'clicks':0,'cost_no_vat':0.0,'days':{}} for c in CIDS},
     'server_writes':False,'advertising_writes':False,'database_opened':False,'token_exported':False}
try:
    if Path.cwd()!=ROOT: raise RuntimeError('wrong_directory')
    cfg=dotenv_values(ROOT/'.env',interpolate=False)
    if str(cfg.get('READ_ONLY','')).lower()!='true': raise RuntimeError('read_only_required')
    if cfg.get('YANDEX_DIRECT_CLIENT_LOGIN')!=LOGIN: raise RuntimeError('login_mismatch')
    token=cfg.get('YANDEX_OAUTH_TOKEN')
    if not isinstance(token,str) or not token.strip(): raise RuntimeError('token_missing')
    params={'SelectionCriteria':{'DateFrom':DATE1,'DateTo':DATE2,
             'Filter':[{'Field':'CampaignId','Operator':'IN','Values':[str(c) for c in CIDS]}]},
            'FieldNames':['Date','CampaignId','Clicks','Cost'],
            'OrderBy':[{'Field':'Date','SortOrder':'ASCENDING'},{'Field':'CampaignId','SortOrder':'ASCENDING'}],
            'ReportName':'MAX-funnel-clicks-'+DATE1+'-'+DATE2+'-'+str(int(time.time())),
            'ReportType':'CAMPAIGN_PERFORMANCE_REPORT','DateRangeType':'CUSTOM_DATE','Format':'TSV',
            'IncludeVAT':'NO','IncludeDiscount':'NO'}
    headers={'Authorization':'Bearer '+token,'Client-Login':LOGIN,'Accept-Language':'ru',
             'Content-Type':'application/json; charset=utf-8','processingMode':'auto',
             'returnMoneyInMicros':'false','skipReportHeader':'true','skipColumnHeader':'false','skipReportSummary':'true'}
    req=urllib.request.Request('https://api.direct.yandex.com/json/v501/reports',
        data=json.dumps({'params':params}).encode(),headers=headers,method='POST')
    raw=None
    for attempt in range(5):
        try:
            with urllib.request.urlopen(req,timeout=25) as r:
                if r.status==200: raw=r.read(2000000).decode('utf-8-sig'); break
        except urllib.error.HTTPError as e:
            if e.code in (201,202):
                wait=e.headers.get('retryIn','2')
                time.sleep(int(wait) if re.fullmatch(r'[0-9]{1,2}',wait or '') else 2); continue
            raise
    if raw is None: raise RuntimeError('report_not_ready')
    reader=csv.DictReader(io.StringIO(raw),delimiter='\t')
    if reader.fieldnames!=['Date','CampaignId','Clicks','Cost']: raise RuntimeError('columns_changed')
    for row in reader:
        cid=row['CampaignId']
        if cid not in out['campaigns']: raise RuntimeError('campaign_scope')
        clicks=int(row['Clicks']); cost=float(row['Cost'])
        out['campaigns'][cid]['clicks']+=clicks
        out['campaigns'][cid]['cost_no_vat']=round(out['campaigns'][cid]['cost_no_vat']+cost,2)
        out['campaigns'][cid]['days'][row['Date']]={'clicks':clicks,'cost_no_vat':cost}
    out['total_clicks']=sum(v['clicks'] for v in out['campaigns'].values())
    out['total_cost_no_vat']=round(sum(v['cost_no_vat'] for v in out['campaigns'].values()),2)
    out['ok']=True
except Exception as e:
    out['error_class']=type(e).__name__
finally:
    token=None
print(json.dumps(out,ensure_ascii=False,allow_nan=False,indent=2))
