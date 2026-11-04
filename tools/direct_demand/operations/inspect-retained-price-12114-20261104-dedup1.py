"""Inspect only retained12114 candidates against the old quote1 intent. No server or provider calls."""
import hashlib,json,os,pathlib,re,time
T=pathlib.Path(os.environ['RUNNER_TEMP'])
MAX_AGE=108000
def single(root,pattern):
 p=list(root.rglob(pattern));assert len(p)==1;return p[0]
def fingerprint_values(value):
 out=set()
 if isinstance(value,dict):
  for k,v in value.items():
   if k in ('retained_tour_fingerprint','tour_fingerprint','tour_id_sha256') and isinstance(v,str) and re.fullmatch(r'[a-f0-9]{64}',v):out.add(v)
   elif isinstance(v,(dict,list)):out.update(fingerprint_values(v))
 elif isinstance(value,list):
  for v in value:out.update(fingerprint_values(v))
 return out
def choose(rows,old,identity_ok,now):
 if not identity_ok or len(old)!=1:return []
 eligible=[]
 for c in rows:
  if not isinstance(c,dict) or not re.fullmatch(r'[a-f0-9]{64}',str(c.get('retained_tour_fingerprint',''))):continue
  if c['retained_tour_fingerprint'] in old:continue
  if not 0<=now-int(c.get('created_at_stored_epoch_utc',0))<=MAX_AGE:continue
  if int(c.get('adults',0))!=2 or int(c.get('children_count',-1))!=0 or c.get('child_ages_signature')!='' or c.get('currency')!='RUB':continue
  if int(c.get('meal_id',0)) not in (2,3,4,5,7,9) or not c.get('room_type') or float(c.get('raw_observed_price',0))<=0:continue
  if str(c.get('departure_date',''))<=time.strftime('%Y-%m-%d',time.gmtime(now)):continue
  eligible.append(c)
 return sorted(eligible,key=lambda c:(int(c['created_at_stored_epoch_utc']),int(c['observation_id'])),reverse=True)[:1]
fixture={'observation_id':2,'retained_tour_fingerprint':'b'*64,'created_at_stored_epoch_utc':2000000000-42,'adults':2,'children_count':0,'child_ages_signature':'','currency':'RUB','meal_id':7,'room_type':'Fixture room','raw_observed_price':1,'departure_date':'2040-01-01'}
same=dict(fixture,retained_tour_fingerprint='a'*64)
assert choose([same,fixture],{'a'*64},True,2000000000)==[fixture]
assert choose([fixture],set(),True,2000000000)==[]
assert choose([fixture],{'a'*64},False,2000000000)==[]
print('RETAINED12114_NEW_FINGERPRINT_RULES_OK new_cases=3 old_age_fixtures_rerun=0 server_calls=0',flush=True)
raw=single(T/'read6','channel-preflight.json').read_text()
original=json.loads(raw.splitlines()[-1])
assert original['ok'] and original['operation']=='commercial-price-monitor-20261104-read6'
hotels=[h for h in original['hotels'] if int(h.get('local_tv_id',0))==12114];assert len(hotels)==1
hotel=hotels[0];rows=hotel.get('observations',[]);assert len(rows)<=2
catalog=hotel.get('catalog',{})
identity=(int(catalog.get('id',0))==12114 and int(catalog.get('country_id',0))==1 and int(catalog.get('is_active',0))==1 and hotel.get('current_name_matches_expected') is True)
oldpaths=list((T/'oldquote1intent').rglob('*.json'));assert 1<=len(oldpaths)<=4
oldfps=set()
for p in oldpaths:
 assert p.stat().st_size<=500000
 oldfps.update(fingerprint_values(json.loads(p.read_text())))
now=int(time.time());chosen=choose(rows,oldfps,identity,now)
age_eligible=[r for r in rows if 0<=now-int(r.get('created_at_stored_epoch_utc',0))<=MAX_AGE]
same_count=sum(r.get('retained_tour_fingerprint') in oldfps for r in age_eligible) if len(oldfps)==1 else 0
private={'operation':'commercial-price-monitor-20261104-retained12114-dedup1','original_run':41786947561,'old_quote_intent_run':41609029830,'max_age_seconds':MAX_AGE,'checked_epoch_utc':now,'selected':[{'hotel':{k:v for k,v in hotel.items() if k not in ('observations','coverage')},'retained':r} for r in chosen]}
(T/'private-quote-candidate-input.json').write_text(json.dumps(private,ensure_ascii=False,separators=(',',':')))
summary={'operation':private['operation'],'ok':True,'catalog_id':12114,'retained_rows_inspected':len(rows),'within30h_rows':len(age_eligible),'identity_current_name_and_country_accepted':identity,'old_quote1_fingerprint_uniquely_bound':len(oldfps)==1,'same_old_quote_fingerprint_rows':same_count,'distinct_eligible_selected':len(chosen),'state':'DISTINCT_PRIVATE_CANDIDATE_PREPARED_NO_PROVIDER_CALL' if chosen else ('NO_SELECTION_OLD_FINGERPRINT_NOT_BOUND' if len(oldfps)!=1 else 'NO_DISTINCT_ELIGIBLE_CANDIDATE'),'max_age_seconds':MAX_AGE,'checked_epoch_utc':now,'server_connections':0,'db_reads':0,'db_writes':0,'provider_calls':0,'message_edits':0,'full_quotes':0,'availability_verified':False,'final_total_verified':False}
(T/'retained12114-dedup-summary.json').write_text(json.dumps(summary,sort_keys=True))
print(json.dumps(summary,sort_keys=True),flush=True)
