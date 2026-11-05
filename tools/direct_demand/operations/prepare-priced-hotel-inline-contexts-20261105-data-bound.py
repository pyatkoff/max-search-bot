import base64,copy,hashlib,json,os,pathlib,re
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
name=os.environ['LINK_SCOPE_FILENAME'];assert pathlib.Path(name).name==name
scope_raw=(b/name).read_bytes();assert hashlib.sha256(scope_raw).hexdigest()==os.environ['EXPECTED_LINK_SCOPE_SHA256'];scope=json.loads(scope_raw)
a=json.loads((b/'commercial-hotel-materials-20261105-161-acceptance-state.json').read_text());ids=scope['native_hotel_ids']
def eligible(a,s):
 target=s['native_hotel_ids']
 return 1<=len(target)==len(set(target))<=s['max_hotels']<=10 and set(target)<=set(a['price_hotel_ids']) and not set(target)&set(a['search_link_hotel_ids']) and a['search_link_hotels_completed']==len(a['search_link_hotel_ids'])==s['expected_linked_count'] and a['price_hotels_total']==len(a['price_hotel_ids'])==s['expected_priced_count'] and a['logical_hotels_published_per_channel']==s['expected_published_pairs']
def scope_bound(s):
 target=s['native_hotel_ids']
 return 1<=len(target)==len(set(target))<=s['max_hotels']<=10 and s['max_owned_max_reads']==len(target) and set(s['prior_price_operations'])==set(map(str,target)) and all(s['prior_price_operations'][str(h)]=='commercial-price-update-20261104-72h-pass1-h'+str(h)+'-v1' for h in target) and [h for h in s['projection_native_ids'] if h in target]==target and pathlib.Path(s['context_reader_filename']).name==s['context_reader_filename'] and s['context_reader_filename'].endswith('.php') and re.fullmatch('[a-f0-9]{64}',s['context_reader_sha256']) is not None
assert eligible(a,scope) and scope_bound(scope)
for label in ['foreign_operation_map','read_limit','projection_order','reader_path']:
 bad=copy.deepcopy(scope)
 if label=='foreign_operation_map':bad['prior_price_operations'][str(ids[0])]='foreign'
 elif label=='read_limit':bad['max_owned_max_reads']-=1
 elif label=='projection_order':bad['projection_native_ids']=list(reversed(bad['projection_native_ids']))
 else:bad['context_reader_filename']='../foreign.php'
 assert not scope_bound(bad),'NEW_INLINE9_SCOPE_NEGATIVE_ACCEPTED:'+label
bad=copy.deepcopy(a);bad['search_link_hotel_ids'].append(ids[0]);assert not eligible(bad,scope)
bad=copy.deepcopy(a);bad['price_hotel_ids'].remove(ids[0]);assert not eligible(bad,scope)
bad=copy.deepcopy(scope);bad['expected_linked_count']-=1;assert not eligible(a,bad)
files=list((t/'original-priced-input').rglob('priced-original-input.private.json'));assert len(files)==1
d=json.loads(files[0].read_text());assert d['operation']==scope['projection_operation'] and d['quote_state']=='NOQUOTE' and d['scope_native_ids']==scope['projection_native_ids'] and not d['deferred_before_server']
items=[x for x in d['items'] if x['hotel']['local_tv_id'] in ids];assert [x['hotel']['local_tv_id'] for x in items]==ids
for x in items:
 hid=x['hotel']['local_tv_id'];assert x['kind']==scope['role'] and x['owned_price_payload']['operation']==scope['prior_price_operations'][str(hid)] and x['hotel']['expected_country_id'] in [1,4] and x['hotel']['expected_name'];x['quote_state']='NOQUOTE'
d.update(operation=scope['operation'],items=items,scope_native_ids=ids,exclude_already_linked_native_ids=a['search_link_hotel_ids'],presets=scope['presets'],max_hotels=scope['max_hotels'],allowed_source_operations=sorted(scope['prior_price_operations'].values()))
raw=(json.dumps(d,ensure_ascii=False,separators=(',',':'))+'\n').encode();(t/'link-context-input.private.json').write_bytes(raw)
source=(b/scope['context_reader_filename']).read_text();assert hashlib.sha256(source.encode()).hexdigest()==scope['context_reader_sha256']==os.environ['EXPECTED_INLINE_CONTEXT_READER_SHA256']
prefix='<?php\ndeclare(strict_types=1);\n';assert source.startswith(prefix)
bindings=[x['hotel'] for x in items]
v={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_CONTEXT18_OPERATION':scope['operation'],'APPROVED_CONTEXT18_INPUT_B64':base64.b64encode(raw).decode(),'APPROVED_CONTEXT18_INPUT_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_CONTEXT18_NATIVE_IDS':ids,'APPROVED_CONTEXT18_IDENTITIES_B64':base64.b64encode(json.dumps(bindings,ensure_ascii=False,separators=(',',':')).encode()).decode(),'APPROVED_CONTEXT18_PRICE_OPS_B64':base64.b64encode(json.dumps(scope['prior_price_operations'],separators=(',',':')).encode()).decode(),'APPROVED_CONTEXT18_LINKED_COUNT':scope['expected_linked_count'],'APPROVED_CAPTURE_RUN':int(os.environ['GITHUB_RUN_ID'])}
required=set(re.findall(r'\bAPPROVED_[A-Z0-9_]+\b',source));assert required<=set(v),'INLINE_CONTEXT_HEADER_REFERENCE_UNDECLARED'
(t/'link-context.php').write_text(prefix+''.join('const '+k+'='+json.dumps(value,ensure_ascii=False)+';\n' for k,value in v.items())+source[len(prefix):])
print(json.dumps({'operation':scope['operation'],'scope_hotels':len(ids),'prepared_context_hotels':len(items),'quote_state':'NOQUOTE','new_actual_scope_python_cases':7,'new_native_input_cases':14,'old_fixtures':0,'provider_calls':0,'db_calls':0,'native_entities_required':True}))
