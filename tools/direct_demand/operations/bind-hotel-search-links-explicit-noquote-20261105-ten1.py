import base64,hashlib,json,os,pathlib
t=pathlib.Path(os.environ['RUNNER_TEMP']);b=pathlib.Path('tools/direct_demand/operations')
ps=list((t/'prepared-links').rglob('update-index.json'));assert len(ps)==1;root=ps[0].parent;index=json.loads(ps[0].read_text())
ids=[129,139,150,172,173,232,241,249,250,263]
assert [x['hotel_id'] for x in index]==ids and len(index)==10
summary=json.loads((root/'prepare-summary.json').read_text());assert summary['prepared_hotels']==10 and summary['prepared_native_ids']==ids and summary['quote_state']=='NOQUOTE' and summary['prepared_prices']==0 and not summary['closed_no_edit']
template=(b/'update-hotel-search-links-explicit-noquote-20261105-ten1.php').read_bytes();assert hashlib.sha256(template).hexdigest()=='d5315073bf1eb9677936b5065b85d27ebc59599e2f8533bfb1ff1061e5a275af'
scope=json.loads((b/'commercial-hotel-search-links-20261105-context17-ten1-scope.json').read_text());identities=scope['native_bindings']
a=json.loads((b/'commercial-hotel-materials-20261105-161-acceptance-state.json').read_text());assert len(a['price_hotel_ids'])==85 and len(a['search_link_hotel_ids'])==60 and not set(ids)&set(a['price_hotel_ids']) and not set(ids)&set(a['search_link_hotel_ids'])
source='a95f6e3514eb48f2b7dcda36ac1f40ab31d0ff99';assert os.environ['EXPECTED_PRODUCTION_SHA']==source
intents=[]
for e in index:
 hid=int(e['hotel_id']);assert e['folder']==str(hid) and e['operation']=='commercial-hotel-search-links-20261105-ten1-h'+str(hid)+'-v1' and e['quote_state']=='NOQUOTE'
 folder=root/e['folder'];raw=(folder/'update-manifest.json').read_bytes();m=json.loads(raw);a=json.loads((folder/'update-approval.json').read_text());fr=(folder/'context-frame.private.json').read_bytes();f=json.loads(fr);v=f['binding'];fh=hashlib.sha256(fr).hexdigest();r=m['rows'][0]
 assert set(a)=={'schema_version','operation','expected_installed_sha','hotel_ids','manifest_sha256'} and a['schema_version']==1 and a['expected_installed_sha']==source and a['hotel_ids']==[hid] and a['operation']==m['operation']==e['operation'] and a['manifest_sha256']==hashlib.sha256(raw).hexdigest()==e['manifest_sha256']
 assert f['capture_run']==42009478360 and f['capture_source_sha']==source and m['quote_state']==v['quote_state']=='NOQUOTE' and m['price_total_verified'] is False and r['hotel_id']==v['native_hotel_id']==hid and r['legacy_context_sha256']==fh and m['update_kind']=='search_link_only'
 assert not any(k in m for k in ['quote_response_sha256','quote_epoch_utc','quote_index','quote_total_amount','quote_currency','quote_origin_operation'])
 values={'EXPECTED_INSTALLED_SHA':source,'APPROVED_MANIFEST_SHA256':a['manifest_sha256'],'MANIFEST_BASE64':base64.b64encode(raw).decode(),'APPROVED_UPDATE_HOTEL_ID':hid,'APPROVED_UPDATE_KEY_B64':base64.b64encode(v['key'].encode()).decode(),'APPROVED_UPDATE_LOGICAL_B64':base64.b64encode(v['logical_id'].encode()).decode(),'APPROVED_UPDATE_OPERATION':m['operation'],'APPROVED_LEGACY_CONTEXT_B64':base64.b64encode(fr).decode(),'APPROVED_LEGACY_CONTEXT_SHA256':fh,'APPROVED_IDENTITY_BINDINGS_B64':base64.b64encode(json.dumps(identities,ensure_ascii=False,separators=(',',':')).encode()).decode(),'APPROVED_SEARCH_URL_B64':base64.b64encode(r['search_link_url'].encode()).decode()}
 prefix=b"<?php\ndeclare(strict_types=1);\n";assert template.startswith(prefix)
 expected=prefix+b''.join(('const '+k+'='+json.dumps(x,ensure_ascii=False)+';\n').encode() for k,x in values.items())+template[len(prefix):]
 assert (folder/'update.php').read_bytes()==expected and hashlib.sha256(expected).hexdigest()==e['script_sha256'],'FROZEN_NOQUOTE_LINK_SCRIPT_BYTES_CHANGED'
 (folder/'execution.php').write_bytes(expected)
 intent=json.loads((folder/'update-intent.json').read_text());assert intent['operation']==m['operation'] and intent['manifest_sha256']==a['manifest_sha256'] and intent['expected_source']==source and intent['quote_state']=='NOQUOTE' and intent['max_content_edits']==2 and intent['new_posts']==intent['gateway_calls']==intent['source_searches']==0
 intent.update(frozen_script_sha256=e['script_sha256']);intents.append(intent)
(t/'link-execution-root.txt').write_text(str(root)+'\n');(t/'all-link-execution-intents.private.json').write_text(json.dumps(intents)+'\n')
proof={'operation':'commercial-hotel-search-links-20261105-ten1-pass1','state':'FROZEN10_NOQUOTE_MANIFESTS_EXACT_APPROVALS_AND_SCRIPTS_BOUND_BEFORE_EDITS','prepared_run':42010191220,'context_run':42009478360,'expected_source':source,'quote_state':'NOQUOTE','template_sha256':'d5315073bf1eb9677936b5065b85d27ebc59599e2f8533bfb1ff1061e5a275af','hotels':10,'price_hotels':0,'max_content_edits':20,'new_posts':0,'gateway_calls':0,'source_searches':0,'old_fixtures':0,'provider_calls':0}
(t/'link-execution-binding-safe.json').write_text(json.dumps(proof)+'\n');print(json.dumps(proof))
