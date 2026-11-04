import base64,hashlib,json,os,pathlib,re,time
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP']);root=temp/'price72-pass1'
plan=json.loads((base/'commercial-price-update-20261104-72h-pass1-plan.json').read_text())
assert plan['execution_approval'] is True and plan['max_age_seconds']==259200
assert os.environ['EXPECTED_PRODUCTION_SHA']==plan['expected_source']=='704ab1c4b0ee17fec46c471180fe7cfb0a6a5711'
assert hashlib.sha256((base/'update-commercial-price-20261104-72h-pass1.php').read_bytes()).hexdigest()=='34fd3712684141fa80adcbf9377a94cd3cae0d35bf2956dfa1c162dd30979046'
marker=json.loads((root/'preparation-only-not-execution-approval.json').read_text());assert marker['execution_approval'] is False and marker['original_quote_source']=='0a80b95fa31e3a90267daa8984adae24d6aaa6bb' and marker['provisional_target_source']==os.environ['EXPECTED_PRODUCTION_SHA']
entries=json.loads((root/'update-index.json').read_text());assert {x['hotel_id'] for x in entries}==set(plan['accepted_catalog_ids']) and len(entries)==19
for entry in entries:
 hid=int(entry['hotel_id']);assert entry['folder']==str(hid)
 folder=root/entry['folder'];update_op=entry['operation'];assert update_op=='commercial-price-update-20261104-72h-pass1-h'+str(hid)+'-v1'
 raw=(folder/'update-manifest.json').read_bytes();manifest=json.loads(raw);approval=json.loads((folder/'update-approval.json').read_text());chosen=json.loads((folder/'candidate.json').read_text());old=chosen['original_manifest_row']
 d=json.loads((folder/'quote-result.json').read_text().splitlines()[-1])
 assert set(approval)=={'schema_version','operation','expected_installed_sha','hotel_ids','manifest_sha256'}
 assert approval['expected_installed_sha']==os.environ['EXPECTED_PRODUCTION_SHA'] and approval['operation']==manifest['operation']==update_op and approval['hotel_ids']==[hid]
 assert hashlib.sha256(raw).hexdigest()==approval['manifest_sha256']
 assert d['installed_sha']=='0a80b95fa31e3a90267daa8984adae24d6aaa6bb' and d['operation']==manifest['quote_origin_operation']=='commercial-price-monitor-20261104-72h-pass1-h'+str(hid)
 assert d['response_sha256']==manifest['quote_response_sha256'] and int(d['response_received_epoch_utc'])==manifest['quote_epoch_utc']
 assert 0<=time.time()-int(d['response_received_epoch_utc'])<=259200
 assert len(manifest['rows'])==1 and manifest['rows'][0]['hotel_id']==hid and manifest['rows'][0]['key']==old['key'] and manifest['rows'][0]['logical_id']==old['logical_id']
 assert manifest['rows'][0]['old_text']==old['text'] and manifest['rows'][0]['cover_sha256']==old['photo_sha256']
 index=int(manifest['quote_index'])
 source=(base/'update-commercial-price-20261104-72h-pass1.php').read_text()
 constants='\ndefine("EXPECTED_INSTALLED_SHA", "'+os.environ['EXPECTED_PRODUCTION_SHA']+'");\ndefine("APPROVED_MANIFEST_SHA256", "'+approval['manifest_sha256']+'");\ndefine("MANIFEST_BASE64", "'+base64.b64encode(raw).decode()+'");\ndefine("VERIFIED_QUOTE_RESPONSE_SHA256", "'+manifest['quote_response_sha256']+'");\ndefine("VERIFIED_QUOTE_EPOCH", '+str(manifest['quote_epoch_utc'])+');\ndefine("VERIFIED_QUOTE_INDEX", '+str(index)+');\ndefine("APPROVED_UPDATE_HOTEL_ID",'+str(hid)+');\ndefine("APPROVED_UPDATE_KEY_B64", "'+base64.b64encode(old['key'].encode()).decode()+'");\ndefine("APPROVED_UPDATE_LOGICAL_B64", "'+base64.b64encode(old['logical_id'].encode()).decode()+'");'
 constants+='\ndefine("APPROVED_UPDATE_OPERATION", "'+update_op+'");'
 assert source.count('declare(strict_types=1);')==1 and 'time()-VERIFIED_QUOTE_EPOCH<=259200' in source
 expected_script=source.replace('declare(strict_types=1);','declare(strict_types=1);'+constants,1)
 assert (folder/'update.php').read_text()==expected_script,'FROZEN_PREPARED_SCRIPT_CHANGED'

 intent=json.loads((folder/'update-intent.json').read_text());assert intent['operation']==update_op and intent['expected_source']==os.environ['EXPECTED_PRODUCTION_SHA'] and intent['manifest_sha256']==approval['manifest_sha256'] and intent['max_age_seconds']==259200
(root/'execution-frozen-input-proof.json').write_text(json.dumps({'operation':'commercial-price-update-20261104-72h-pass1','execution_approval':True,'expected_source':os.environ['EXPECTED_PRODUCTION_SHA'],'original_quote_source':'0a80b95fa31e3a90267daa8984adae24d6aaa6bb','accepted_catalog_ids':[x['hotel_id'] for x in entries],'frozen_manifest_and_script_hashes_verified':True,'wholebasket_cases_already_passed_in_run':41936372259,'old_case_reruns':0,'gateway_calls':0,'edits_so_far':0})+'\n')
print(json.dumps({'operation':'commercial-price-update-20261104-72h-pass1-frozen-bindings','frozen_ready_hotels':len(entries),'maximum_existing_edits':len(entries)*2,'original_wholebaskets_unchanged':True,'source_provenance_preserved':True,'new_gateway_calls':0,'old_age_updater_fixture_reruns':0}))
