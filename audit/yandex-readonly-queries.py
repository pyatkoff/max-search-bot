"""Fixed-scope ephemeral server reader. No app import, DB or server file writes."""
import csv
import hashlib
import io
import json
import os
import re
import signal
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path('/var/www/anytoour/data/www/d.anytoour.ru')
CID = 714260445
LOGIN = 'anytour2024tp'
EXPECTED = '602c6db7bbe4eebfbbe75744567bb58d67ba0dd0'
result = {'campaign_id': CID, 'client_login': LOGIN, 'started_at_utc': datetime.now(timezone.utc).isoformat(),
          'server_writes': False, 'database_opened': False, 'advertising_writes': False,
          'token_exported': False, 'errors': [], 'requests': [], 'query_rows': []}

class Stop(Exception):
    def __init__(self, code, http=None, api_code=None):
        self.info = {'code': code, 'http': http, 'api_code': api_code}

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def ident(value):
    if type(value) is int and value > 0:
        return value
    if isinstance(value, str) and re.fullmatch(r'[1-9][0-9]{0,19}', value):
        return int(value)
    raise Stop('INVALID_API_ID')

def items(value):
    if isinstance(value, dict): value=value.get('Items')
    if value is None: return []
    if not isinstance(value, list): raise Stop('INVALID_ITEMS')
    return value

def values(obj, field):
    out=[]
    if isinstance(obj, dict):
        for k,v in obj.items():
            if k==field: out.append(v)
            else: out.extend(values(v,field))
    elif isinstance(obj,list):
        for v in obj: out.extend(values(v,field))
    return out

def run():
    import subprocess
    from dotenv import dotenv_values
    signal.signal(signal.SIGALRM, lambda *args: (_ for _ in ()).throw(Stop('TIME_BUDGET')))
    signal.alarm(210)
    if Path.cwd()!=ROOT or ROOT.resolve()!=ROOT: raise Stop('EXACT_DIRECTORY_REQUIRED')
    envfile=ROOT/'.env'
    if envfile.is_symlink() or not envfile.is_file() or envfile.stat().st_size>65536:
        raise Stop('LOCAL_ENV_NOT_CONFIRMED')
    env={'PATH':'/usr/local/bin:/usr/bin:/bin','HOME':os.environ.get('HOME','/'),
         'GIT_OPTIONAL_LOCKS':'0','GIT_TERMINAL_PROMPT':'0','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null'}
    def git(args):
        p=subprocess.run(['git','--no-optional-locks','-c','core.fsmonitor=false',
            '-c','core.hooksPath=/dev/null','-C',str(ROOT)]+args,capture_output=True,text=True,timeout=10,env=env)
        if p.returncode: raise Stop('PROJECT_GIT_CHECK_FAILED')
        return p.stdout.strip()
    if git(['rev-parse','HEAD'])!=EXPECTED or git(['rev-parse','--show-toplevel'])!=str(ROOT):
        raise Stop('PROJECT_REVISION_CHANGED')
    if git(['status','--porcelain=v1','--untracked-files=no']): raise Stop('TRACKED_DRIFT')
    if git(['config','--get','remote.origin.url']) not in (
        'git@github.com:pyatkoff/yandex-direct-autopilot.git','https://github.com/pyatkoff/yandex-direct-autopilot.git',
        'https://github.com/pyatkoff/yandex-direct-autopilot'):
        raise Stop('PROJECT_IDENTITY_MISMATCH')
    cfg=dotenv_values(envfile,interpolate=False)
    def conf(k, default=''):
        return os.environ.get(k,cfg.get(k,default))
    if str(conf('READ_ONLY')).lower()!='true': raise Stop('READ_ONLY_NOT_TRUE')
    if str(conf('YANDEX_DIRECT_SANDBOX','false')).lower() not in ('false','0'): raise Stop('SANDBOX_ENABLED')
    if conf('YANDEX_DIRECT_CLIENT_LOGIN')!=LOGIN: raise Stop('ACCOUNT_MISMATCH')
    token=conf('YANDEX_OAUTH_TOKEN')
    if not isinstance(token,str) or not token.strip(): raise Stop('LOCAL_TOKEN_MISSING')
    result['local_config_verified']=True
    result['installed_sha']=EXPECTED
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    def req(path, payload=None, query=None, report=False):
        direct=path in ('campaigns','strategies','keywords','adgroups','ads','reports')
        if not direct and not re.fullmatch(r'/management/v1/counter/[1-9][0-9]*(/goals)?', path):
            raise Stop('ENDPOINT_NOT_ALLOWED')
        if direct and path!='reports' and (not isinstance(payload,dict) or payload.get('method')!='get'):
            raise Stop('READ_METHOD_REQUIRED')
        if path=='reports' and payload.get('params',{}).get('ReportType')!='SEARCH_QUERY_PERFORMANCE_REPORT':
            raise Stop('QUERY_REPORT_REQUIRED')
        url=('https://api.direct.yandex.com/json/'+('v5/' if path=='strategies' else 'v501/')+path if direct else 'https://api-metrika.yandex.net'+path)
        if query: url+='?'+urllib.parse.urlencode(query)
        headers={'Authorization':('Bearer ' if direct else 'OAuth ')+token,'Accept-Language':'ru'}
        if direct: headers.update({'Client-Login':LOGIN,'Content-Type':'application/json; charset=utf-8'})
        if report: headers.update({'processingMode':'auto','returnMoneyInMicros':'false','skipReportHeader':'true',
                                  'skipColumnHeader':'false','skipReportSummary':'true'})
        data=json.dumps(payload).encode() if payload is not None else None
        for attempt in range(3):
            if len(result['requests'])>=30: raise Stop('REQUEST_BUDGET')
            q=urllib.request.Request(url,data=data,headers=headers,method='POST' if direct else 'GET')
            try:
                with opener.open(q,timeout=20) as r:
                    status=r.status; rh=r.headers; raw=r.read(4000001)
            except urllib.error.HTTPError as e:
                status=e.code; rh=e.headers; raw=e.read(4000001)
            except Exception:
                raise Stop('HTTPS_READ_FAILED') from None
            result['requests'].append({'endpoint':path,'http':status,'request_id':rh.get('RequestId')})
            if len(raw)>4000000: raise Stop('RESPONSE_TOO_LARGE')
            if status in (201,202,429,500,502,503,504):
                wait=rh.get('retryIn',rh.get('Retry-After','3'))
                wait=int(wait) if re.fullmatch('[0-9]{1,4}',wait) else 3
                if attempt<2 and wait<=10: time.sleep(max(1,wait)); continue
                raise Stop('REPORT_PENDING_OR_RETRY_LATER',status)
            if report and status==200: return raw.decode('utf-8-sig')
            try: body=json.loads(raw)
            except Exception: raise Stop('NON_JSON_RESPONSE',status) from None
            err=body.get('error') if isinstance(body,dict) else None
            if status!=200 or err or (isinstance(body,dict) and body.get('errors')):
                code=err.get('error_code') if isinstance(err,dict) else None
                if type(code) not in (int,str) or not re.fullmatch('[0-9]{1,5}',str(code)): code=None
                raise Stop('API_READ_REJECTED',status,code)
            if not isinstance(body,dict): raise Stop('INVALID_JSON_RESPONSE',status)
            return body
        raise Stop('RETRY_LIMIT')
    fields=['CounterIds','BiddingStrategy','PriorityGoals','AttributionModel','PackageBiddingStrategy']
    c=req('campaigns',{'method':'get','params':{'SelectionCriteria':{'Ids':[CID]},
        'FieldNames':['Id','Name','Type','State','TimeZone','Currency','StartDate','NegativeKeywords'],
        'TextCampaignFieldNames':fields,'UnifiedCampaignFieldNames':fields}})['result']
    cs=c.get('Campaigns',[])
    if c.get('LimitedBy') is not None or len(cs)!=1 or ident(cs[0]['Id'])!=CID: raise Stop('EXACT_CAMPAIGN_NOT_RETURNED')
    campaign=cs[0]; result['campaign']=campaign
    block=campaign.get('UnifiedCampaign',campaign.get('TextCampaign'))
    if not isinstance(block,dict): raise Stop('CAMPAIGN_TYPE_NOT_SUPPORTED')
    strategy=(block.get('BiddingStrategy') or {}).get('Search') or {}
    package=block.get('PackageBiddingStrategy')
    if package:
        sid=ident(package['StrategyId'])
        sf={k:['GoalId'] for k in ('StrategyMaximumConversionRateFieldNames','StrategyAverageCpaFieldNames',
             'StrategyPayForConversionFieldNames','StrategyPayForConversionMultipleGoalsFieldNames',
             'StrategyAverageCrrFieldNames','StrategyPayForConversionCrrFieldNames')}
        s=req('strategies',{'method':'get','params':{'SelectionCriteria':{'Ids':[sid]},
            'FieldNames':['Id','Type','CounterIds','PriorityGoals','AttributionModel'],**sf}})['result']
        ss=s.get('Strategies',[])
        if s.get('LimitedBy') is not None or len(ss)!=1 or ident(ss[0]['Id'])!=sid: raise Stop('EXACT_STRATEGY_NOT_RETURNED')
        result['portfolio_strategy']=ss[0]; block=strategy=ss[0]
    model=block.get('AttributionModel')
    goals=sorted({ident(x) for x in values(strategy,'GoalId')})
    if not goals: goals=sorted({ident(x) for x in values(block.get('PriorityGoals'),'GoalId')})
    counters=[ident(x) for x in items(block.get('CounterIds'))]
    if not 1<=len(counters)<=5 or not 1<=len(goals)<=10 or model not in ('AUTO','LC','FCCD','LSCCD'):
        raise Stop('OWN_GOAL_OR_ATTRIBUTION_UNRESOLVED')
    catalog={}; inaccessible=[]
    for counter in counters:
        try:
            data=req('/management/v1/counter/'+str(counter)+'/goals')
            catalog[str(counter)]=[{k:g.get(k) for k in ('id','name','type','conditions')} for g in data.get('goals',[]) if ident(g['id']) in goals]
        except Stop as e:
            inaccessible.append(counter)
            result['errors'].append({'stage':'counter_goals','counter_id':counter,**e.info})
    result['goal_catalog']=catalog
    result['goal_binding']={'accessible_matches':{str(g):[int(c) for c,gs in catalog.items() if any(ident(v['id'])==g for v in gs)] for g in goals},
        'unread_counter_ids':inaccessible,'all_configured_counters_checked':not inaccessible,
        'direct_goal_from_exact_campaign_strategy':True}
    # Reports use only the exact goal(s) returned by Direct for this campaign.
    # Unread Metrika counters remain explicit; no cross-counter attribution claim.
    last=(datetime.now(ZoneInfo('Europe/Moscow')).date()-timedelta(days=1))
    first=last-timedelta(days=29)
    result['context']={'counter_ids':counters,'goal_ids':goals,'attribution':model,
        'campaign_timezone':campaign.get('TimeZone'),'date1':first.isoformat(),'date2':last.isoformat(),
        'date_basis':'Direct Reports Date; explicit requested dates, not membership timestamps',
        'include_vat':False,'current_settings_not_historical':True,'counts_provisional':True}
    columns=['Date','CampaignId','AdGroupId','Query','CriterionType','MatchedKeyword','MatchType','Impressions','Clicks','Cost','Conversions']
    expected=columns[:-1]+['Conversions_'+str(g)+'_'+model for g in goals]
    seen=set(); complete=False
    for page in range(10):
        params={'SelectionCriteria':{'DateFrom':first.isoformat(),'DateTo':last.isoformat(),
            'Filter':[{'Field':'CampaignId','Operator':'IN','Values':[str(CID)]}]},
            'FieldNames':columns,'Goals':list(map(str,goals)),'AttributionModels':[model],
            'Page':{'Limit':1000,'Offset':page*1000},
            'OrderBy':[{'Field':k,'SortOrder':'ASCENDING'} for k in columns[:7]],
            'ReportType':'SEARCH_QUERY_PERFORMANCE_REPORT','DateRangeType':'CUSTOM_DATE','Format':'TSV','IncludeVAT':'NO'}
        params['ReportName']='AnyTour-private-query-'+hashlib.sha256(json.dumps(params,sort_keys=True).encode()).hexdigest()[:20]
        reader=csv.DictReader(io.StringIO(req('reports',{'params':params},report=True)),delimiter='\t')
        if reader.fieldnames!=expected: raise Stop('REPORT_COLUMNS_MISMATCH')
        batch=list(reader)
        if len(batch)>1000: raise Stop('PAGE_LIMIT_VIOLATED')
        for row in batch:
            if set(row)!=set(expected) or ident(row['CampaignId'])!=CID: raise Stop('QUERY_SCOPE_MISMATCH')
            key=tuple(row[k] for k in columns[:7])
            if key in seen: raise Stop('DUPLICATE_OR_DRIFTING_PAGE')
            seen.add(key)
        result['query_rows'].extend(batch)
        if len(batch)<1000: complete=True; break
    result['query_collection_complete']=complete
    if not complete: result['errors'].append({'code':'QUERY_PAGE_BUDGET_REACHED'})
    for service,field,names,extra in [
        ('keywords','Keywords',['Id','CampaignId','AdGroupId','Keyword','State'],{}),
        ('adgroups','AdGroups',['Id','CampaignId','Name','RegionIds','NegativeKeywords'],{}),
        ('ads','Ads',['Id','CampaignId','AdGroupId','Type','State','Status'],{'TextAdFieldNames':['Title','Title2','Text','Href'],'ResponsiveAdFieldNames':['Titles','Texts','Href']})]:
        try:
            objects=[]; offset=0
            for _ in range(5):
                p=req(service,{'method':'get','params':{'SelectionCriteria':{'CampaignIds':[CID]},
                    'FieldNames':names,'Page':{'Limit':1000,'Offset':offset},**extra}})['result']
                rows=p[field]
                if not all(ident(r['CampaignId'])==CID for r in rows): raise Stop('OBJECT_SCOPE_MISMATCH')
                objects.extend(rows)
                if p.get('LimitedBy') is None: break
                nxt=ident(p['LimitedBy'])
                if nxt<=offset: raise Stop('INVALID_OBJECT_CURSOR')
                offset=nxt
            else: raise Stop('OBJECT_PAGE_BUDGET')
            result[service]=objects
        except Stop as e:
            result['errors'].append({'stage':service,**e.info})
    result['status']='COLLECTED' if not result['errors'] else 'PARTIAL'

if __name__=='__main__':
    try: run()
    except Stop as e: result['errors'].append(e.info); result['status']='PARTIAL' if result['query_rows'] else 'READ_FAILED'
    except Exception: result['errors'].append({'code':'LOCAL_READER_FAILED'}); result['status']='READ_FAILED'
    finally:
        signal.alarm(0)
        result['finished_at_utc']=datetime.now(timezone.utc).isoformat()
        print(json.dumps(result,ensure_ascii=False,allow_nan=False))
