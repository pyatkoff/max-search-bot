import datetime,json,os,pathlib
temp=pathlib.Path(os.environ['RUNNER_TEMP']);base=pathlib.Path('tools/direct_demand/operations')
paths=list((temp/'retained-read9').rglob('price-readback.json'));assert len(paths)==1
d=json.loads(paths[0].read_text().splitlines()[-1]);assert d['operation']=='commercial-price-monitor-20261104-read9' and d['ok'] and len(d['hotels'])==157 and d['installed_sha']=='9678e8c617734dd3bd5054eabf3585e396e3ca12'
review={482,476,81173,32577,478,80964};eligible=[];deferred=[];seen=set()
for h in d['hotels']:
 hid=int(h['local_tv_id']);assert hid not in seen;seen.add(hid);cat=h.get('catalog',{})
 if hid in review:deferred.append({'hotel_id':hid,'reason':'NAME_REVIEW_NO_FORCE'});continue
 if h.get('identity_provenance')!='accepted_native_catalog_read' or h.get('current_name_matches_expected') is not True or int(cat.get('id',0))!=hid or int(cat.get('is_active',0))!=1 or int(cat.get('country_id',0))!=int(h['expected_country_id']) or int(h['expected_country_id']) not in (1,4):
  deferred.append({'hotel_id':hid,'reason':'RETAINED_CURRENT_NATIVE_PROOF_NOT_ADMITTED'});continue
 eligible.append({'hotel_id':hid,'country_id':int(h['expected_country_id'])})
# Two new actually-published native identities are already proven by original stock39/READ15; use only the retained public native projection.
p=json.loads((base/'commercial-stock-20261104-39-native-projection.json').read_text())
def rows(x):
 if isinstance(x,list):
  for item in x:yield from rows(item)
 elif isinstance(x,dict):
  yield x
  for value in x.values():
   if isinstance(value,(list,dict)):yield from rows(value)
for hid in [17480,1100]:
 assert hid not in seen
 matched=[r for r in rows(p) if str(r.get('catalog_id',r.get('hotel_id',r.get('local_tv_id',r.get('native_catalog_id',r.get('id',''))))))==str(hid)]
 assert matched,'RETAINED_NEW_PUBLISHED_NATIVE_PROJECTION_REQUIRED'
 assert any(int(r.get('country_id',r.get('expected_country_id',4)))==4 for r in matched)
 eligible.append({'hotel_id':hid,'country_id':4});seen.add(hid)
assert len(seen)==159 and len({r['hotel_id'] for r in eligible})==len(eligible)
assert not ({r['hotel_id'] for r in eligible}&review)
groups=[]
for country in [1,4]:
 ids=sorted(r['hotel_id'] for r in eligible if r['country_id']==country)
 for offset in range(0,len(ids),20):groups.append({'country_id':country,'hotel_ids':ids[offset:offset+20]})
assert len(groups)<=12 and all(1<=len(g['hotel_ids'])<=20 for g in groups)
assert {hid for g in groups for hid in g['hotel_ids']}=={r['hotel_id'] for r in eligible}
today=datetime.datetime.now(datetime.timezone.utc).date();start=today+datetime.timedelta(days=1);end=start+datetime.timedelta(days=6)
plan={'operation':'commercial-price-monitor-20261104-published-refresh-proposal1','state':'PREPARED_READONLY_SCOPE_AWAITING_EXPLICIT_MANUAL_COLLECTION_APPROVAL_NOT_EXECUTED','goal':'Obtain new source contexts for actual published hotels instead of retrying exhausted cached tours','published_scope_count':159,'admitted_native_hotel_count':len(eligible),'deferred':deferred,'groups':groups,'departure_id':1,'departure':'Moscow','adults':2,'children':0,'currency':'RUB','departure_date_from':start.isoformat(),'departure_date_to':end.isoformat(),'nights_from':5,'nights_to':14,'maximum_new_searches':len(groups),'hard_search_ceiling':12,'max_age_seconds':108000,'manual_collection_approved':False,'collector_dispatches':0,'source_calls':0,'db_writes':0,'post_edits':0,'constraints':['One finite scoped pass only; never automatic retry or interval/budget/cron/observer/transport changes','Exact newly approved execution route must preserve existing quota/lock/resume accounting before any search','No extra broad scheduled/matrix manual branches from workflow_dispatch defaults','Only actually published admitted native identities; no name-review force; target parameters from plan','No known expired/consumed old tour fingerprints reused as fresh input','Search results are not final availability or full basket; only separately justified real flights checks can reach serial existing-post updater','No guarantee that a real offer exists for every admitted hotel in this date window','If UTCdate changes, regenerate date input and revalidate before execution']}
(temp/'published-price-refresh-proposal.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({k:plan[k] for k in ['operation','state','published_scope_count','admitted_native_hotel_count','maximum_new_searches','departure_date_from','departure_date_to','nights_from','nights_to','source_calls','post_edits']}))
print(json.dumps({'new_scope_cases':3,'prefix157_preserved':True,'two_new_published_ids_added':[17480,1100],'name_review_excluded':True,'exact_group_union_verified':True,'old_fixtures_rerun':0,'provider_calls':0,'db_calls':0}))
print('PROPOSAL_B64 '+__import__('base64').b64encode((temp/'published-price-refresh-proposal.json').read_bytes()).decode())
