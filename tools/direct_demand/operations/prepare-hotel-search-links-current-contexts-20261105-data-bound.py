import base64,collections,hashlib,json,os,pathlib,re
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
name=os.environ['LINK_SCOPE_FILENAME'];assert pathlib.Path(name).name==name and name.endswith('-scope.json')
rawScope=(b/name).read_bytes();assert hashlib.sha256(rawScope).hexdigest()==os.environ['EXPECTED_LINK_SCOPE_SHA256']
scope=json.loads(rawScope);a=json.loads((b/'commercial-hotel-materials-20261105-161-acceptance-state.json').read_text())
ids=scope['native_hotel_ids'];assert scope['scope_filename']==name and scope['quote_state']=='NOQUOTE' and 0<len(ids)==len(set(ids))==10 and scope['max_hotels']==scope['max_owned_max_reads']==10
assert a['logical_hotels_published_per_channel']==scope['expected_published_count']==161 and a['price_hotels_total']==scope['expected_priced_count'] and a['search_link_hotels_completed']==scope['expected_linked_count']
assert not set(ids)&set(a['price_hotel_ids']) and not set(ids)&set(a['search_link_hotel_ids']) and scope['exclude_priced_native_ids']==a['price_hotel_ids'] and scope['exclude_already_linked_native_ids']==a['search_link_hotel_ids'] and {h['local_tv_id'] for h in scope['native_bindings']}==set(ids)
names=["commercial-batch-20261102-10-manifest.json","commercial-batch-20261102-11-manifest.json","commercial-batch-20261102-12-manifest.json","commercial-batch-20261102-13-manifest.json","commercial-batch-20261102-14-manifest.json","commercial-batch-20261102-9-manifest.json","commercial-batch-20261103-15-manifest.json","commercial-batch-20261103-16-manifest.json","commercial-batch-20261103-17-manifest.json","commercial-batch-20261103-18-manifest.json","commercial-batch-20261103-19-manifest.json","commercial-batch-20261103-20-manifest.json","commercial-batch-20261103-21-manifest.json","commercial-batch-20261103-22-manifest.json","commercial-batch-20261103-23-manifest.json","commercial-batch-20261103-24-manifest.json","commercial-batch-20261103-25-manifest.json","commercial-batch-20261103-26-manifest.json","commercial-batch-20261103-27-manifest.json","commercial-batch-20261103-28-manifest.json","commercial-batch-20261103-29-manifest.json","commercial-batch-20261103-30-manifest.json","commercial-batch-20261103-31-manifest.json","commercial-batch-20261103-32-manifest.json","commercial-batch-20261103-33-manifest.json","commercial-batch-20261103-34-manifest.json","commercial-batch-20261104-35-manifest.json","commercial-batch-20261104-36-manifest.json","commercial-batch-20261104-37-manifest.json","commercial-batch-20261104-38-manifest.json","commercial-batch-20261104-43-manifest.json","commercial-batch-20261105-49-manifest.json","commercial-batch-20261105-51-manifest.json","commercial-final-two-manifest.json","commercial-four-update-manifest.json","commercial-human-copy-early-nine-manifest.json","commercial-human-copy-three-manifest.json","commercial-next-three-manifest.json","commercial-three-20261102-2-manifest.json","commercial-three-20261102-3-manifest.json","commercial-three-20261102-4-manifest.json","commercial-three-20261102-5-manifest.json","commercial-three-20261102-6-manifest.json","commercial-three-20261102-7-manifest.json","commercial-three-20261102-8-manifest.json"]
needed={int(h['anytour_id']) if h['identity_provenance']=='accepted_typed_bridge' else int(h['local_tv_id']) for h in scope['native_bindings']};pub=collections.defaultdict(list);manifests={}
for name in names:
 m=json.loads((b/name).read_text());manifests[name]=m
 for r in m.get('rows',[]):
  if isinstance(r,dict) and all(k in r for k in ('hotel_id','logical_id','text','text_sha256','photo_sha256')) and int(r['hotel_id']) in needed:
   assert hashlib.sha256(r['text'].encode()).hexdigest()==r['text_sha256'];pub[int(r['hotel_id'])].append((m,r,name))
items=[];deferred=[];ops=set()
for h in scope['native_bindings']:
 hid=int(h['local_tv_id']);expected=int(h['anytour_id']) if h['identity_provenance']=='accepted_typed_bridge' else hid
 unique={(r['logical_id'],r['text_sha256'],r['photo_sha256']):(m,r,name) for m,r,name in pub.get(expected,[])}
 if len(unique)!=1:
  deferred.append({'native_hotel_id':hid,'reason':'ORIGINAL_PUBLISHER_MANIFEST_NOT_UNIQUE_OR_MISSING','matching_original_publishers':len(unique)});continue
 m,r,name=next(iter(unique.values()));row={k:r[k] for k in ('hotel_id','logical_id','text','text_sha256','photo_sha256')};ops.add(m['operation'])
 variants=[{'caption':r['text'],'caption_sha256':r['text_sha256'],'source_kind':'original','source_operation':m['operation']}]
 for cn,cm in manifests.items():
  for cr in cm.get('rows',[]):
   if isinstance(cr,dict) and cr.get('logical_id')==r['logical_id'] and all(k in cr for k in ('new_text','new_text_sha256','key')):
    assert hashlib.sha256(cr['new_text'].encode()).hexdigest()==cr['new_text_sha256'];ops.add(cm['operation']);mh=hashlib.sha256(json.dumps(cm,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
    variants.append({'caption':cr['new_text'],'caption_sha256':cr['new_text_sha256'],'source_kind':'confirmed_copy','source_operation':cm['operation'],'source_key':cr['key'],'source_manifest_hash':mh})
 items.append({'hotel':h,'kind':'original_publisher_and_latest_confirmed_caption','publisher_hotel_id':expected,'original_publication_operation':m['operation'],'original_manifest_row':row,'original_manifest_path':name,'caption_variants':variants,'quote_state':'NOQUOTE'})
assert len(items)+len(deferred)==10 and len({x['hotel']['local_tv_id'] for x in items})==len(items) and items
out={'operation':scope['operation'],'quote_state':'NOQUOTE','items':items,'deferred_before_server':deferred,'allowed_source_operations':sorted(ops),'scope_native_ids':ids,'exclude_already_linked_native_ids':a['search_link_hotel_ids'],'presets':scope['presets'],'max_hotels':10}
raw=(json.dumps(out,ensure_ascii=False,separators=(',',':'))+'\n').encode();(t/'link-context-input.private.json').write_bytes(raw)
s=(b/'read-hotel-search-links-current-contexts-20261105-data-bound.php').read_text();assert hashlib.sha256(s.encode()).hexdigest()=='b662a2263f5d7f4be0417918fdce498b0d5db46a2c3eab75ca8b5bbc8c29a7d5';prefix="<?php\ndeclare(strict_types=1);\n";assert s.startswith(prefix)
identities=json.dumps(scope['native_bindings'],ensure_ascii=False,separators=(',',':')).encode()
v={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_CONTEXT18_OPERATION':scope['operation'],'APPROVED_CONTEXT18_INPUT_B64':base64.b64encode(raw).decode(),'APPROVED_CONTEXT18_INPUT_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_CONTEXT18_NATIVE_IDS':ids,'APPROVED_CONTEXT18_IDENTITIES_B64':base64.b64encode(identities).decode(),'APPROVED_CONTEXT18_LINKED_COUNT':scope['expected_linked_count'],'APPROVED_CAPTURE_RUN':int(os.environ['GITHUB_RUN_ID'])}
required=set(re.findall(r'\bAPPROVED_[A-Z0-9_]+\b',s))
def complete(definitions):return required<=set(definitions)
assert complete(v),'GENERATED_CONTEXT_OPERATION_CONSTANT_REFERENCES_UNDECLARED'
bad=dict(v);bad.pop('APPROVED_CONTEXT18_OPERATION');assert not complete(bad),'NEW_INPUT_MISSING_DECLARATION_ACCEPTED'
bad=dict(v);bad['APPROVED_UNRELATED_CONTEXT_OPERATION']=bad.pop('APPROVED_CONTEXT18_OPERATION');assert not complete(bad),'NEW_INPUT_WRONG_NAMESPACE_ACCEPTED'
(t/'link-context.php').write_text(prefix+''.join('const '+k+'='+json.dumps(x,ensure_ascii=False)+';\n' for k,x in v.items())+s[len(prefix):])
print(json.dumps({'operation':out['operation'],'scope_hotels':10,'prepared_context_hotels':len(items),'deferred':deferred,'whitelisted_manifest_files':len(names),'historical_private_id_fields_projected':0,'quote_state':'NOQUOTE','provider_calls':0,'db_calls':0,'new_scope_native_cases':3,'new_generated_header_cases':2,'all_operation_owned_constants_declared':True,'old_fixtures':0}))
