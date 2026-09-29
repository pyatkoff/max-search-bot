"""Pure read-only CSV aggregate. No DB, configuration, credential, network or subprocess access.
Only event counts and per-metric unique-user counts leave this process.
"""
import csv, datetime, hashlib, io, json, pathlib, re, sys
from collections import Counter

FIELDS=['DateTime','ChatID','YclidText','RegionID','CampaignID','Event','Details']
EVENTS=('bot_started','search_ready','show_tours','channel_offer_start','channel_click')
SELECTED=('714260445','714320748')
FIRST,LAST='2026-09-23','2026-09-28'

class Bucket:
    def __init__(self):
        self.n=Counter();self.u={e:set() for e in EVENTS}
    def add(self,event,user):
        self.n[event]+=1
        if user:self.u[event].add(user)
    def result(self):
        return {e:{'events':self.n[e],'users':len(self.u[e])} for e in EVENTS}

def collect(raw):
    reader=csv.reader(io.StringIO(raw.decode('utf-8-sig'),newline=''),strict=True)
    if next(reader,None)!=FIELDS:raise ValueError('HEADER_MISMATCH')
    all_rows=Bucket();selected=Bucket();campaigns={};days={};day_campaigns={};rows=0;matched=0
    first_stamp=None;last_stamp=None
    for r in reader:
        if not r:continue
        if len(r)!=7:raise ValueError('ROW_WIDTH_MISMATCH')
        rows+=1
        stamp,user,unused_yclid,unused_region,cid,event,unused_details=r
        parsed=datetime.datetime.strptime(stamp,'%Y-%m-%d %H:%M:%S')
        first_stamp=stamp if first_stamp is None else min(first_stamp,stamp)
        last_stamp=stamp if last_stamp is None else max(last_stamp,stamp)
        day=parsed.date().isoformat()
        if not FIRST<=day<=LAST or event not in EVENTS:continue
        matched+=1;cid=cid.strip()
        if not cid or re.fullmatch('0+',cid):cid='not_recorded'
        elif not re.fullmatch('[1-9][0-9]{0,19}',cid):cid='invalid'
        all_rows.add(event,user)
        campaigns.setdefault(cid,Bucket()).add(event,user)
        days.setdefault(day,Bucket()).add(event,user)
        day_campaigns.setdefault((day,cid),Bucket()).add(event,user)
        if cid in SELECTED:selected.add(event,user)
    for e in EVENTS:
        if all_rows.n[e]!=sum(v.n[e] for v in campaigns.values()):raise ValueError('RECONCILIATION_FAILED')
    return {'ok':True,'period':{'first_day':FIRST,'last_day':LAST,'basis':'literal recorded journal dates; timezone not inferred'},
      'input':{'sha256':hashlib.sha256(raw).hexdigest(),'bytes':len(raw),'rows':rows,'metric_rows':matched,'first_timestamp':first_stamp,'last_timestamp':last_stamp},
      'totals':all_rows.result(),'selected_totals':selected.result(),
      'by_campaign':{c:campaigns.get(c,Bucket()).result() for c in sorted(set(campaigns)|set(SELECTED))},
      'by_day':{d:{'totals':days[d].result(),'campaigns':{c:b.result() for (day,c),b in day_campaigns.items() if day==d}} for d in sorted(days)},
      'limitations':['Recorded activity, not a joined advertising cohort.','All project journal transports; no inferred MAX-only scope.','Test traffic is not independently excluded.','search_ready is recorded readiness; show_tours is recorded handling, not successful site loading.','channel_click is not a subscription.','No advertising clicks or confirmed membership inferred.','Journal rotation and historical timezone changes are not independently verified.']}

def main():
    try:
        if len(sys.argv)!=2:raise ValueError('ONE_INPUT_REQUIRED')
        p=pathlib.Path(sys.argv[1])
        if p.is_symlink() or not p.is_file() or p.stat().st_size>134217728:raise ValueError('INPUT_BOUNDARY_FAILED')
        raw=p.read_bytes()
        if not raw.endswith(b'\n'):raise ValueError('INCOMPLETE_LINE')
        result=collect(raw)
    except Exception as e:
        result={'ok':False,'error_class':type(e).__name__}
    print(json.dumps(result,ensure_ascii=False,separators=(',',':')))
    return 0 if result['ok'] else 1

if __name__=='__main__':sys.exit(main())
