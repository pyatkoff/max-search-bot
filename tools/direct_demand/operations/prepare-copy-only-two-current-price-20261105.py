import base64,collections,datetime,decimal,hashlib,json,os,pathlib,time
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
def one(folder,name):
 p=list((t/folder).rglob(name));assert len(p)==1,(folder,name);return p[0]
source=json.loads(one('source-scope','approved-source-new-scope.private.json').read_text());assert source['source_run']==41976677950 and source['old_retained_rows']==85
read=json.loads(one('original-read13','price-readback.json').read_text().splitlines()[-1]);assert read['ok'] and len(read['hotels'])==56
blocked=set(source['old_retained_fingerprints']);searches=set(source['original_search_ids']);consumed_rows=0
# Only anti-replay fingerprints are extracted from the other54 retained hotel contexts; no quote/body reinspection.
for h in read['hotels']:
 if int(h['local_tv_id']) not in {67910,68323}:
  for o in h['observations']:blocked.add(o['retained_tour_fingerprint']);consumed_rows+=1
d=json.loads(one('context15','context15-readback.private.json').read_text().splitlines()[-1]);assert d['ok'] and d['operation']=='commercial-price-monitor-20261105-context15-copy-only-two' and len(d['bindings'])==2 and not d['deferred']
selected=[]
for x in d['bindings']:
 h=x['hotel'];f=x['canonical_context'];hid=int(h['local_tv_id']);bb=f['binding'];assert hid in {67910,68323} and f['capture_run']==41990234208 and f['capture_operation']==d['operation'] and bb['native_hotel_id']==hid and bb['publisher_hotel_id'] is None and bb['expected_country_id']==1
 pool=[]
 for o in h['observations']:
  assert int(o['original_source_search_id']) in searches and o['retained_tour_fingerprint'] not in blocked
  if 0<=int(time.time())-int(o['created_at_stored_epoch_utc'])<=259200 and datetime.datetime.now(datetime.timezone.utc).date().isoformat()<o['departure_date']<='2026-11-12' and o['departure_date']>='2026-11-06' and 5<=int(o['nights'])<=14 and int(o['adults'])==2 and int(o['children_count'])==0 and o['child_ages_signature']=='' and o['currency']=='RUB' and str(o['room_type']).strip():pool.append(o)
 assert pool,'NEW_UNQUOTED_COPY_CONTEXT_HAS_NO_STILL_ADMITTED_SOURCE'
 pool.sort(key=lambda o:(decimal.Decimal(str(o['raw_observed_price'])),-int(o['created_at_stored_epoch_utc'])));obs=pool[0];fr=json.dumps(f,ensure_ascii=False,separators=(',',':')).encode()
 selected.append({'schema_version':1,'candidate_kind':'canonical_current_manual_source_quote_candidate_v1','native_hotel_id':hid,'original_publisher_hotel_id':None,'hotel':{k:h[k] for k in ['local_tv_id','expected_name','expected_country_id','identity_provenance']},'catalog_name_for_quote':bb['catalog_name'],'retained':obs,'canonical_context':f,'canonical_context_sha256':hashlib.sha256(fr).hexdigest(),'retained_source_run':41978466494,'retained_source_artifact':13393205782,'retained_source_installed_sha':'627a9c6284624e63b24287bea61277ef6c8f5364','original_manual_source_run':41976677950})
assert {x['native_hotel_id'] for x in selected}=={67910,68323} and len({x['retained']['retained_tour_fingerprint'] for x in selected})==2
root=t/'price-manual1-two-copy';root.mkdir(mode=0o700);s=(b/'check-copy-only-two-current-price-20261105.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert s.startswith(prefix);index=[];intents=[]
for chosen in selected:
 hid=chosen['native_hotel_id'];h=chosen['hotel'];f=chosen['canonical_context'];folder=root/str(hid);folder.mkdir(mode=0o700);op='commercial-price-monitor-20261105-manual1-h'+str(hid)+'-v1'
 approved=dict(chosen['retained']);approved['hotel_id']=hid;raw=(json.dumps(approved,ensure_ascii=False,separators=(',',':'))+'\n').encode();fr=json.dumps(f,ensure_ascii=False,separators=(',',':')).encode()
 values={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_QUOTE_OPERATION':op,'APPROVED_CANDIDATE_B64':base64.b64encode(raw).decode(),'APPROVED_CANDIDATE_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_HOTEL_ID':hid,'APPROVED_COUNTRY_ID':1,'APPROVED_HOTEL_NAME_B64':base64.b64encode(chosen['catalog_name_for_quote'].encode()).decode(),'APPROVED_CURRENT_FRAME_B64':base64.b64encode(fr).decode(),'APPROVED_CURRENT_FRAME_SHA256':hashlib.sha256(fr).hexdigest(),'APPROVED_CURRENT_FRAME_RUN':41990234208,'APPROVED_SCOPE_NATIVE_IDS_B64':base64.b64encode(json.dumps([67910,68323]).encode()).decode(),'APPROVED_OLD_FINGERPRINTS_B64':base64.b64encode(json.dumps(sorted(blocked)).encode()).decode(),'APPROVED_ORIGINAL_SEARCH_IDS_B64':base64.b64encode(json.dumps(sorted(searches)).encode()).decode()}
 (folder/'quote.php').write_text(prefix+''.join('const '+k+'='+json.dumps(v,ensure_ascii=False)+';\n' for k,v in values.items())+s[len(prefix):]);(folder/'candidate.json').write_text(json.dumps(chosen,ensure_ascii=False)+'\n')
 intent={'operation':op,'catalog_id':hid,'state':'EXACT_ONE_PREVIOUSLY_UNQUOTED_COPY_ONLY_NEW_FINGERPRINT_INTENT_BEFORE_PROVIDER','expected_source':os.environ['EXPECTED_PRODUCTION_SHA'],'approved_candidate_sha256':hashlib.sha256(raw).hexdigest(),'current_context_sha256':hashlib.sha256(fr).hexdigest(),'source_run':41976677950,'read13_run':41978466494,'current_capture_run':41990234208,'old_original_rows_excluded':85,'other54_retained_rows_conservatively_excluded':consumed_rows,'max_age_seconds':259200,'max_gateway_requests':1,'automatic_retry_allowed':False}
 (folder/'quote-intent.json').write_text(json.dumps(intent)+'\n');index.append({'hotel_id':hid,'quote_operation':op,'folder':str(hid)});intents.append(intent)
(root/'index.json').write_text(json.dumps(index)+'\n');(root/'all-quote-intents.json').write_text(json.dumps(intents)+'\n')
print(json.dumps({'operation':'commercial-price-monitor-20261105-manual1-quote-pass2-two-copy','selected_catalog_ids':[x['hotel_id'] for x in index],'max_justified_gateway_calls':2,'previously_consumed_hotels_requoted':0,'old85_rows_excluded':85,'other54_retained_rows_conservatively_excluded':consumed_rows,'provider_calls':0,'old_fixtures_rerun':0}))
