"""Bounded Direct report using existing server configuration. No writes or token export."""
from pathlib import Path
from datetime import datetime, timezone
import csv, io, json, os, re, shlex, subprocess, time
import urllib.request, urllib.error
ROOT=Path('/var/www/anytoour/data/www/d.anytoour.ru')
IDS=['714260445','714320748']
out={'ok':False,'generated_at_utc':datetime.now(timezone.utc).isoformat(),'date1':'2026-09-23','date2':'2026-09-30','server_writes':False,'advertising_writes':False,'database_opened':False,'token_exported':False}
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k):return None
try:
    if not ROOT.is_dir() or ROOT.is_symlink() or ROOT.resolve()!=ROOT:raise ValueError('ROOT_SCOPE')
    origin=subprocess.check_output(['git','-C',str(ROOT),'config','--get','remote.origin.url'],text=True,timeout=5).strip()
    if origin not in ['git@github.com:pyatkoff/yandex-direct-autopilot.git','https://github.com/pyatkoff/yandex-direct-autopilot.git','https://github.com/pyatkoff/yandex-direct-autopilot']:raise ValueError('REPO_IDENTITY')
    env=ROOT/'.env'
    if env.is_symlink() or not env.is_file() or env.stat().st_size>65536:raise ValueError('ENV_SCOPE')
    cfg={}
    for line in env.read_text().splitlines():
        m=re.match(r'^\s*(?:export\s+)?([A-Z_][A-Z_0-9]*)\s*=\s*(.*)$',line)
        if m:
            parts=shlex.split(m[2],comments=True)
            cfg[m[1]]=' '.join(parts)
    def val(k,d=''):return os.environ.get(k,cfg.get(k,d))
    if val('READ_ONLY').lower()!='true' or val('YANDEX_DIRECT_SANDBOX','false').lower() not in ['false','0']:raise ValueError('READ_ONLY_CONFIGURATION')
    if val('YANDEX_DIRECT_CLIENT_LOGIN')!='anytour2024tp':raise ValueError('ACCOUNT_MISMATCH')
    token=val('YANDEX_OAUTH_TOKEN')
    if not token:raise ValueError('TOKEN_MISSING')
    params={'SelectionCriteria':{'DateFrom':out['date1'],'DateTo':out['date2'],'Filter':[{'Field':'CampaignId','Operator':'IN','Values':IDS}]},'FieldNames':['Date','CampaignId','Clicks','Cost'],'OrderBy':[{'Field':'Date','SortOrder':'ASCENDING'}],'ReportName':'AnyTour-pilot-two-campaigns-20260930-v1','ReportType':'CAMPAIGN_PERFORMANCE_REPORT','DateRangeType':'CUSTOM_DATE','Format':'TSV','IncludeVAT':'NO','IncludeDiscount':'NO'}
    headers={'Authorization':'Bearer '+token,'Client-Login':'anytour2024tp','Content-Type':'application/json; charset=utf-8','Accept-Language':'en','processingMode':'auto','returnMoneyInMicros':'false','skipReportHeader':'true','skipColumnHeader':'false','skipReportSummary':'true'}
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
    out['requests']=[]
    for attempt in range(4):
        q=urllib.request.Request('https://api.direct.yandex.com/json/v5/reports',data=json.dumps({'params':params}).encode(),headers=headers,method='POST')
        try:
            with opener.open(q,timeout=25) as r:status=r.status;rh=r.headers;body=r.read(500001)
        except urllib.error.HTTPError as e:status=e.code;rh=e.headers;body=e.read(500001)
        out['requests'].append({'http':status,'request_id':rh.get('RequestId')})
        if len(body)>500000:raise ValueError('RESPONSE_BUDGET')
        if status==200:break
        if status in (201,202,429) and attempt<3:
            wait=rh.get('retryIn',rh.get('Retry-After','3'))
            delay=int(wait) if str(wait).isdigit() else 3
            if delay>10:raise ValueError('REPORT_PENDING')
            time.sleep(max(1,delay));continue
        raise ValueError('DIRECT_REPORT_HTTP_'+str(status))
    reader=csv.DictReader(io.StringIO(body.decode('utf-8-sig')),delimiter='\t')
    if reader.fieldnames!=params['FieldNames']:raise ValueError('COLUMNS_MISMATCH')
    campaigns={cid:{'clicks':0,'cost_no_vat':0.0,'days':{}} for cid in IDS}
    for row in reader:
        cid=row['CampaignId'];day=row['Date']
        if cid not in campaigns or not out['date1']<=day<=out['date2']:raise ValueError('ROW_SCOPE')
        c=campaigns[cid]
        if day in c['days']:raise ValueError('DUPLICATE_ROW')
        clicks=int(row['Clicks']);cost=float(row['Cost'])
        c['days'][day]={'clicks':clicks,'cost_no_vat':cost};c['clicks']+=clicks;c['cost_no_vat']+=cost
    out['campaigns']=campaigns;out['ok']=True
    out['limitations']=['Date is Direct report date, not a user join timestamp. September 30 is a partial day. No intraday split for September 29 deployment. No advertising or Metrika setting changed.']
except Exception as e:
    out['error']=str(e) if isinstance(e,ValueError) and re.fullmatch('[A-Z_0-9]+',str(e)) else type(e).__name__
print(json.dumps(out,ensure_ascii=False))
raise SystemExit(0 if out['ok'] else 1)
