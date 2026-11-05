import base64,collections,datetime,decimal,hashlib,json,os,pathlib
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
def one(folder,name):
 p=list((temp/folder).rglob(name));assert len(p)==1,(folder,name);return p[0]
source=json.loads(one('source-scope','approved-source-new-scope.private.json').read_text());assert source['source_run']==41976677950 and source['old_retained_rows']==85
blocked=set(source['old_retained_fingerprints']);searches=set(source['original_search_ids']);allitems=[]
for folder,name,op,run,n in [('context13','context13-readback.private.json','commercial-price-monitor-20261105-context13-approved-manual1',41979357309,5),('context14','context14-readback.private.json','commercial-price-monitor-20261105-context14-owned-button-proof1',41980439161,49)]:
 d=json.loads(one(folder,name).read_text().splitlines()[-1]);assert d['operation']==op and d['ok'] and len(d['bindings'])==n
 for x in d['bindings']:
  h=x['hotel'];f=x['canonical_context'];hid=int(h['local_tv_id']);b=f['binding'];assert int(f['capture_run'])==run and f['capture_operation']==op and int(b['native_hotel_id'])==hid and int(b['expected_country_id'])==int(h['expected_country_id'])
  pool=[]
  for o in h['observations']:
   assert int(o['original_source_search_id']) in searches and o['retained_tour_fingerprint'] not in blocked
   if not 0<=int(__import__('time').time())-int(o['created_at_stored_epoch_utc'])<=259200:continue
   if not datetime.datetime.now(datetime.timezone.utc).date().isoformat()<o['departure_date']<='2026-11-12':continue
   if o['departure_date']<'2026-11-06' or not 5<=int(o['nights'])<=14 or int(o['adults'])!=2 or int(o['children_count'])!=0 or o['child_ages_signature']!='' or o['currency']!='RUB' or not str(o['room_type']).strip():continue
   pool.append(o)
  if not pool:continue
  pool.sort(key=lambda o:(decimal.Decimal(str(o['raw_observed_price'])),-int(o['created_at_stored_epoch_utc'])))
  obs=pool[0];frameRaw=json.dumps(f,ensure_ascii=False,separators=(',',':')).encode()
  allitems.append({'schema_version':1,'candidate_kind':'canonical_current_manual_source_quote_candidate_v1','native_hotel_id':hid,'original_publisher_hotel_id':b['publisher_hotel_id'],'hotel':{k:h[k] for k in ['local_tv_id','expected_name','expected_country_id','identity_provenance']},'catalog_name_for_quote':b['catalog_name'],'retained':obs,'canonical_context':f,'canonical_context_sha256':hashlib.sha256(frameRaw).hexdigest(),'retained_source_run':41978466494,'retained_source_artifact':13393205782,'retained_source_installed_sha':'627a9c6284624e63b24287bea61277ef6c8f5364','original_manual_source_run':41976677950})
assert {x['native_hotel_id'] for x in allitems}<=set([240,381,268,46672,28666,235,110,132,111,262,237,174,178,273,408,416,342,267,371,280,32575,12112,4191,26044,435,9374,13870,79059,55574,78898,43204,37668,77914,69409,111801,115918,112914,108090,1348,1379,1583,1559,1453,1543,1582,1449,1199,111129,109900,111045,15869,74503,27316,3405]) and len({x['native_hotel_id'] for x in allitems})==len(allitems)<=54
counts=collections.Counter(x['retained']['retained_tour_fingerprint'] for x in allitems);selected=[x for x in allitems if counts[x['retained']['retained_tour_fingerprint']]==1]
assert selected;scope=[x['native_hotel_id'] for x in selected]
root=temp/'price-manual1-pass1';root.mkdir(mode=0o700);s=(base/'check-current-approved-price-20261105-manual1-pass1.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert s.startswith(prefix);index=[];intents=[]
for chosen in selected:
 hid=chosen['native_hotel_id'];h=chosen['hotel'];f=chosen['canonical_context'];folder=root/str(hid);folder.mkdir(mode=0o700);op='commercial-price-monitor-20261105-manual1-h'+str(hid)+'-v1'
 approved=dict(chosen['retained']);approved['hotel_id']=hid;raw=(json.dumps(approved,ensure_ascii=False,separators=(',',':'))+'\n').encode();fr=json.dumps(f,ensure_ascii=False,separators=(',',':')).encode()
 constants={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_QUOTE_OPERATION':op,'APPROVED_CANDIDATE_B64':base64.b64encode(raw).decode(),'APPROVED_CANDIDATE_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_HOTEL_ID':hid,'APPROVED_COUNTRY_ID':int(h['expected_country_id']),'APPROVED_HOTEL_NAME_B64':base64.b64encode(chosen['catalog_name_for_quote'].encode()).decode(),'APPROVED_CURRENT_FRAME_B64':base64.b64encode(fr).decode(),'APPROVED_CURRENT_FRAME_SHA256':hashlib.sha256(fr).hexdigest(),'APPROVED_CURRENT_FRAME_RUN':int(f['capture_run']),'APPROVED_SCOPE_NATIVE_IDS_B64':base64.b64encode(json.dumps(scope).encode()).decode(),'APPROVED_OLD_FINGERPRINTS_B64':base64.b64encode(json.dumps(sorted(blocked)).encode()).decode(),'APPROVED_ORIGINAL_SEARCH_IDS_B64':base64.b64encode(json.dumps(sorted(searches)).encode()).decode()}
 header=prefix+''.join('const '+k+'='+json.dumps(v,ensure_ascii=False)+';\n' for k,v in constants.items());(folder/'quote.php').write_text(header+s[len(prefix):]);(folder/'candidate.json').write_text(json.dumps(chosen,ensure_ascii=False)+'\n')
 intent={'operation':op,'catalog_id':hid,'state':'EXACT_ONE_NEW_FINGERPRINT_QUOTE_INTENT_BEFORE_PROVIDER','expected_source':os.environ['EXPECTED_PRODUCTION_SHA'],'approved_candidate_sha256':hashlib.sha256(raw).hexdigest(),'current_context_sha256':hashlib.sha256(fr).hexdigest(),'retained_source_run':41978466494,'retained_source_artifact':13393205782,'retained_source_installed_sha':'627a9c6284624e63b24287bea61277ef6c8f5364','current_frame_capture_run':f['capture_run'],'current_frame_capture_source':f['capture_source_sha'],'original_manual_source_run':41976677950,'old_retained_fingerprints_excluded':85,'max_age_seconds':259200,'max_gateway_requests':1,'automatic_retry_allowed':False}
 (folder/'quote-intent.json').write_text(json.dumps(intent)+'\n');index.append({'hotel_id':hid,'quote_operation':op,'folder':str(hid)});intents.append(intent)
(root/'index.json').write_text(json.dumps(index)+'\n');(root/'all-quote-intents.json').write_text(json.dumps(intents)+'\n')
print(json.dumps({'operation':'commercial-price-monitor-20261105-manual1-quote-pass1','selected_catalog_ids':scope,'selected_unique_hotels':len(index),'max_justified_gateway_calls':len(index),'old_fingerprints_excluded':85,'current_canonical_contexts_bound_before_gateway':True,'cross_hotel_fingerprint_conflicts_excluded':len(allitems)-len(selected),'old_fixtures_rerun':0,'provider_calls':0}))
