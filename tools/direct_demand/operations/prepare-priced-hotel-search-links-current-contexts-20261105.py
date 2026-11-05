import base64,hashlib,json,os,pathlib,re
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
name=os.environ['LINK_SCOPE_FILENAME'];assert pathlib.Path(name).name==name;rawScope=(b/name).read_bytes();assert hashlib.sha256(rawScope).hexdigest()==os.environ['EXPECTED_LINK_SCOPE_SHA256'];scope=json.loads(rawScope)
a=json.loads((b/'commercial-hotel-materials-20261105-161-acceptance-state.json').read_text());ids=scope['native_hotel_ids'];assert 1<=len(ids)==len(set(ids))<=10 and set(ids)<=set(a['price_hotel_ids']) and not set(ids)&set(a['search_link_hotel_ids']) and a['search_link_hotels_completed']==scope['expected_linked_count']==123 and a['price_hotels_total']==85
files=list((t/'original-priced-input').rglob('priced-original-input.private.json'));assert len(files)==1
d=json.loads(files[0].read_text());assert d['operation']=='commercial-hotel-search-links-20261105-priced10-original-projection1' and d['quote_state']=='NOQUOTE' and d['scope_native_ids']==ids and len(d['items'])==len(ids) and not d['deferred_before_server'] and [x['hotel']['local_tv_id'] for x in d['items']]==ids
for x in d['items']:
 hid=x['hotel']['local_tv_id'];assert x['kind']==scope['role'] and x['owned_price_payload']['operation']==scope['prior_price_operations'][str(hid)] and x['hotel']['expected_country_id'] in [1,4] and x['hotel']['expected_name'];x['quote_state']='NOQUOTE'
d.update(operation=scope['operation'],exclude_already_linked_native_ids=a['search_link_hotel_ids'],presets=scope['presets'],max_hotels=10,allowed_source_operations=sorted(scope['prior_price_operations'].values()))
raw=(json.dumps(d,ensure_ascii=False,separators=(',',':'))+'\n').encode();(t/'link-context-input.private.json').write_bytes(raw)
source=(b/'read-priced-hotel-search-links-current-contexts-20261105.php').read_text();assert hashlib.sha256(source.encode()).hexdigest()=='650c1285cf198b70ef094db9d4d805c5f18c73f54c62d4b7f91b2d68544196bb'
prefix="<?php\ndeclare(strict_types=1);\n";assert source.startswith(prefix)
bindings=[x['hotel'] for x in d['items']]
v={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_CONTEXT18_OPERATION':scope['operation'],'APPROVED_CONTEXT18_INPUT_B64':base64.b64encode(raw).decode(),'APPROVED_CONTEXT18_INPUT_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_CONTEXT18_NATIVE_IDS':ids,'APPROVED_CONTEXT18_IDENTITIES_B64':base64.b64encode(json.dumps(bindings,ensure_ascii=False,separators=(',',':')).encode()).decode(),'APPROVED_CONTEXT18_PRICE_OPS_B64':base64.b64encode(json.dumps(scope['prior_price_operations'],separators=(',',':')).encode()).decode(),'APPROVED_CONTEXT18_LINKED_COUNT':scope['expected_linked_count'],'APPROVED_CAPTURE_RUN':int(os.environ['GITHUB_RUN_ID'])}
required=set(re.findall(r'\bAPPROVED_[A-Z0-9_]+\b',source));assert required<=set(v),'NEW_PRIOR_PRICE_GENERATED_HEADER_UNDECLARED'
bad=dict(v);bad.pop('APPROVED_CONTEXT18_PRICE_OPS_B64');assert not required<=set(bad),'NEW_PRIOR_PRICE_MISSING_PRICE_OPS_DECLARATION_ACCEPTED'
bad=dict(v);bad['APPROVED_FOREIGN_PRICE_OPS_B64']=bad.pop('APPROVED_CONTEXT18_PRICE_OPS_B64');assert not required<=set(bad),'NEW_PRIOR_PRICE_WRONG_PRICE_OPS_NAMESPACE_ACCEPTED'
(t/'link-context.php').write_text(prefix+''.join('const '+k+'='+json.dumps(x,ensure_ascii=False)+';\n' for k,x in v.items())+source[len(prefix):])
print(json.dumps({'operation':scope['operation'],'scope_hotels':len(ids),'prepared_context_hotels':len(d['items']),'quote_state':'NOQUOTE','new_scope_native_cases':10,'new_generated_header_cases':2,'old_fixtures':0,'historical_public_private_IDs_used_as_canonical':False,'old_quote_bodies_read':0,'old_provider_results_read':0,'provider_calls':0,'db_calls':0}))
