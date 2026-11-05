import base64,hashlib,json,os,pathlib,time,datetime
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
paths=list((temp/'canonical-inputs').rglob('legacy9-price-candidates.private.json'));assert len(paths)==1
selected=json.loads(paths[0].read_text());assert len(selected)==9 and {int(x['native_hotel_id']) for x in selected}=={240,1981,46672,498,9373,469,381,310,1971}
assert os.environ['EXPECTED_PRODUCTION_SHA']=='25a2d5e31aec7243b45c47e836a544e659e28b1d'
source=(base/'check-commercial-price-20261105-legacy9-pass1.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert source.startswith(prefix)
root=temp/'price-legacy9-pass1';root.mkdir(mode=0o700);index=[];intents=[]
for chosen in selected:
 hid=int(chosen['native_hotel_id']);h=chosen['hotel'];obs=chosen['retained'];frame=chosen['canonical_context'];b=frame['binding']
 frameRaw=json.dumps(frame,ensure_ascii=False,separators=(',',':')).encode()
 assert hashlib.sha256(frameRaw).hexdigest()==chosen['canonical_context_sha256']
 assert chosen['retained_source_run']==41934633857 and chosen['retained_source_artifact']==13372822872 and chosen['retained_source_installed_sha']=='0a80b95fa31e3a90267daa8984adae24d6aaa6bb'
 assert frame['capture_source_sha']=='25a2d5e31aec7243b45c47e836a544e659e28b1d' and int(b['native_hotel_id'])==int(h['local_tv_id'])==hid and b['publisher_hotel_id']==chosen['original_publisher_hotel_id']
 assert 0<=int(time.time())-int(obs['created_at_stored_epoch_utc'])<=259200
 assert datetime.datetime.now(datetime.timezone.utc).date().isoformat()<obs['departure_date']<='2027-02-02'
 assert int(obs['adults'])==2 and int(obs['children_count'])==0 and obs['child_ages_signature']=='' and obs['currency']=='RUB'
 folder=root/str(hid);folder.mkdir(mode=0o700);op='commercial-price-monitor-20261105-legacy9-pass1-h'+str(hid)
 approved=dict(obs);approved['hotel_id']=hid;raw=(json.dumps(approved,ensure_ascii=False,separators=(',',':'))+'\n').encode()
 constants={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_QUOTE_OPERATION':op,'APPROVED_CANDIDATE_B64':base64.b64encode(raw).decode(),'APPROVED_CANDIDATE_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_HOTEL_ID':hid,'APPROVED_COUNTRY_ID':int(h['expected_country_id']),'APPROVED_HOTEL_NAME_B64':base64.b64encode(chosen['catalog_name_for_quote'].strip().encode()).decode(),'APPROVED_LEGACY_CONTEXT_B64':base64.b64encode(frameRaw).decode(),'APPROVED_LEGACY_CONTEXT_SHA256':chosen['canonical_context_sha256']}
 header=prefix+''.join('const '+k+'='+json.dumps(v,ensure_ascii=False)+';\n' for k,v in constants.items())
 (folder/'quote.php').write_text(header+source[len(prefix):]);(folder/'candidate.json').write_text(json.dumps(chosen,ensure_ascii=False)+'\n')
 intent={'operation':op,'catalog_id':hid,'state':'EXACT_ONE_JUSTIFIED_QUOTE_INTENT_BEFORE_PROVIDER','expected_source':os.environ['EXPECTED_PRODUCTION_SHA'],'approved_candidate_sha256':hashlib.sha256(raw).hexdigest(),'legacy_context_sha256':chosen['canonical_context_sha256'],'retained_artifact_run':41934633857,'retained_artifact_id':13372822872,'original_retained_source':'0a80b95fa31e3a90267daa8984adae24d6aaa6bb','canonical_capture_run':frame['capture_run'],'max_age_seconds':259200,'max_gateway_requests':1,'automatic_retry_allowed':False}
 (folder/'quote-intent.json').write_text(json.dumps(intent)+'\n');intents.append(intent);index.append({'hotel_id':hid,'quote_operation':op,'folder':str(hid)})
assert len({x['retained']['retained_tour_fingerprint'] for x in selected})==len(selected)
(root/'index.json').write_text(json.dumps(index)+'\n');(root/'all-quote-intents.json').write_text(json.dumps(intents)+'\n')
print(json.dumps({'operation':'commercial-price-monitor-20261105-legacy9-pass1','selected_catalog_ids':[x['hotel_id'] for x in index],'selected_unique_hotels':len(index),'maximum_justified_gateway_calls':len(index),'canonical_contexts_from_original_artifact':True,'retained_source_provenance_preserved':True,'utc_date_evaluated':datetime.datetime.now(datetime.timezone.utc).date().isoformat(),'provider_calls':0,'old_age_native_fixtures_rerun':0}))
