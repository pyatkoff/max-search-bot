import copy,datetime,json,os,pathlib
temp=pathlib.Path(os.environ['RUNNER_TEMP']);base=pathlib.Path('tools/direct_demand/operations')
plan=json.loads((base/'commercial-price-monitor-20261105-published-refresh-proposal2-plan.json').read_text())
paths=list((temp/'original-read11').rglob('price-readback.json'));assert len(paths)==1
d=json.loads(paths[0].read_text().splitlines()[-1]);assert d['operation']=='commercial-price-monitor-20261105-read11-new672-input1' and d['ok'] and d['installed_sha']=='25a2d5e31aec7243b45c47e836a544e659e28b1d'
assert len(d['hotels'])==159 and len({int(h['local_tv_id']) for h in d['hotels']})==159
priced=set(plan['priced_ids_excluded']);review=set(plan['name_review_ids_excluded']);assert len(priced)==32 and len(review)==6 and not priced&review
def native_proof(h):
 cat=h.get('catalog',{});kind=h.get('identity_provenance')
 return kind in ('accepted_native_catalog_read','accepted_typed_bridge') and int(cat.get('id',0))==int(h['local_tv_id']) and int(cat.get('is_active',0))==1 and int(cat.get('country_id',0))==int(h['expected_country_id']) and int(h['expected_country_id']) in (1,4) and h.get('current_name_matches_expected') is True and (kind!='accepted_typed_bridge' or isinstance(h.get('anytour_id'),int) and h['anytour_id']>0)
eligible=[];deferred=[]
for h in d['hotels']:
 hid=int(h['local_tv_id'])
 if hid in priced:deferred.append({'hotel_id':hid,'reason':'ALREADY_PRICED_72H_NO_MANUAL_REFRESH_NEEDED'});continue
 if hid in review:deferred.append({'hotel_id':hid,'reason':'NAME_REVIEW_NO_FORCE'});continue
 if not native_proof(h):deferred.append({'hotel_id':hid,'reason':'EXACT_CURRENT_NATIVE_COUNTRY_ACTIVE_NAME_NOT_ADMITTED'});continue
 eligible.append({'hotel_id':hid,'country_id':int(h['expected_country_id']),'identity_provenance':h['identity_provenance']})
assert len(eligible)<=121 and len(eligible)+len(deferred)==159 and not {x['hotel_id'] for x in eligible}&(priced|review)
# New actual-input safeguards: typed publisher/native roles remain distinct, mismatched country and priced/context replay stop.
typed=next(h for h in d['hotels'] if h['identity_provenance']=='accepted_typed_bridge' and native_proof(h))
assert int(typed['local_tv_id'])!=int(typed['anytour_id']) and native_proof(typed)
bad=copy.deepcopy(typed);bad['catalog']['country_id']=4 if int(typed['expected_country_id'])==1 else 1
assert not native_proof(bad)
assert not priced&{x['hotel_id'] for x in eligible} and set(priced)<={int(h['local_tv_id']) for h in d['hotels']}
groups=[]
for country in [1,4]:
 ids=sorted(x['hotel_id'] for x in eligible if x['country_id']==country)
 for i in range(0,len(ids),20):groups.append({'country_id':country,'hotel_ids':ids[i:i+20]})
assert len(groups)<=12 and all(1<=len(g['hotel_ids'])<=20 for g in groups)
assert {hid for g in groups for hid in g['hotel_ids']}=={x['hotel_id'] for x in eligible}
today=datetime.datetime.now(datetime.timezone.utc).date();first=today+datetime.timedelta(days=1);last=first+datetime.timedelta(days=6)
proposal={'operation':plan['operation'],'state':'PURE_EXACT_GROUPS_PREPARED_NO_MANUAL_APPROVAL_NO_EXECUTION','published_scope_count':159,'priced_count_excluded':len(priced),'name_review_count_excluded':len(review),'eligible_hotels':eligible,'deferred':deferred,'groups':groups,'maximum_new_searches':len(groups),'hard_search_ceiling':12,'departure_id':1,'departure':'Moscow','adults':2,'children':0,'currency':'RUB','departure_date_from':first.isoformat(),'departure_date_to':last.isoformat(),'nights_from':5,'nights_to':14,'max_age_seconds':259200,'original_proof_run':41945775526,'original_proof_artifact':13377689882,'original_proof_source':'25a2d5e31aec7243b45c47e836a544e659e28b1d','manual_collection_approved':False,'execution_route_ready':False,'collector_dispatches':0,'source_calls':0,'db_calls':0,'post_edits':0,'new_posts':0,'new_input_cases':3,'old_fixture_reruns':0}
raw=json.dumps(proposal,ensure_ascii=False,indent=2)+'\n';(temp/'published-price-refresh-proposal2.json').write_text(raw)
print(json.dumps({'operation':plan['operation'],'state':proposal['state'],'admitted_unpriced_hotels':len(eligible),'maximum_new_searches':len(groups),'deferred_name_review_or_proof':len(deferred)-32,'priced_excluded':32,'departure_date_from':first.isoformat(),'departure_date_to':last.isoformat(),'new_input_cases':3,'old_fixture_reruns':0,'source_calls':0,'db_calls':0,'post_edits':0}))
print('SAFE_PROPOSAL2_B64 '+__import__('base64').b64encode(raw.encode()).decode())
