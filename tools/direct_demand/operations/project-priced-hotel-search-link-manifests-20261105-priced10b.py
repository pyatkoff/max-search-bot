import copy,hashlib,json,os,pathlib,re
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
name=os.environ['LINK_PROJECTION_PLAN_FILENAME'];assert pathlib.Path(name).name==name
raw=(b/name).read_bytes();assert hashlib.sha256(raw).hexdigest()==os.environ['EXPECTED_LINK_PROJECTION_PLAN_SHA256'];plan=json.loads(raw)
ids=plan['native_hotel_ids'];a=json.loads((b/'commercial-hotel-materials-20261105-161-acceptance-state.json').read_text())
assert 1<=len(ids)==len(set(ids))<=10 and a['search_link_hotels_completed']==plan['expected_linked_count'] and a['price_hotels_total']==plan['expected_priced_count'] and a['logical_hotels_published_per_channel']==plan['expected_published_pairs']
assert set(ids)<=set(a['price_hotel_ids']) and not set(ids)&set(a['search_link_hotel_ids'])
def one(folder,name):
 files=list((t/folder).rglob(name));assert len(files)==1,(folder,name);return files[0]
identity=json.loads(one('identity10','price-readback.json').read_text().splitlines()[-1]);assert identity['ok'] and len(identity['hotels'])==159
fields=['local_tv_id','expected_name','expected_country_id','identity_provenance','anytour_id']
native={int(h['local_tv_id']):{k:h[k] for k in fields if k in h} for h in identity['hotels'] if int(h['local_tv_id']) in ids}
assert set(native)==set(ids) and all(isinstance(h['expected_name'],str) and h['expected_country_id'] in [1,4] for h in native.values())
indexfile=one('prior19','update-index.json');index=json.loads(indexfile.read_text());root=indexfile.parent
items=[];cases=0
for position,hid in enumerate(ids):
 entries=[x for x in index if x['hotel_id']==hid];assert len(entries)==1 and entries[0]['folder']==str(hid)
 raw=(root/str(hid)/'update-manifest.json').read_bytes();m=json.loads(raw);h=native[hid];expected=plan['prior_price_operations'][str(hid)]
 def validate(z):
  assert z['operation']==expected and len(z['rows'])==1
  r=z['rows'][0];assert r['hotel_id']==hid and r['key'] and r['logical_id'] and r['update_text'] is True and hashlib.sha256(r['old_text'].encode()).hexdigest()==r['old_text_sha256'] and hashlib.sha256(r['new_text'].encode()).hexdigest()==r['new_text_sha256']
  assert re.fullmatch('[a-f0-9]{64}',z['quote_response_sha256']) and type(z['quote_epoch_utc']) is int and z['quote_epoch_utc']>0 and 'https://anytour.online/poisk-turov/' not in r['new_text']
 validate(m)
 for label in ['foreign_operation','different_actual_native_id','caption_sha_mismatch']:
  bad=copy.deepcopy(m)
  if label=='foreign_operation':bad['operation']+='-foreign'
  elif label=='different_actual_native_id':bad['rows'][0]['hotel_id']=ids[(position+1)%len(ids)] if len(ids)>1 else 999999999
  else:bad['rows'][0]['new_text']+='foreign'
  try:validate(bad)
  except AssertionError:cases+=1
  else:raise AssertionError('NEW_PRICED10B_PROJECTION_NEGATIVE_ACCEPTED:'+label)
 mh=hashlib.sha256(json.dumps(m,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
 publisher=int(h['anytour_id']) if h['identity_provenance']=='accepted_typed_bridge' else hid
 items.append({'hotel':h,'kind':plan['role'],'publisher_hotel_id':publisher,'owned_price_payload':m,'owned_price_manifest_sha256':hashlib.sha256(raw).hexdigest(),'source_manifest_hash':mh})
out={'operation':plan['operation'],'quote_state':'NOQUOTE','items':items,'deferred_before_server':[],'scope_native_ids':ids,'allowed_source_operations':sorted(x['owned_price_payload']['operation'] for x in items),'prior_price_amounts_or_checktimes_changed':False,'old_quote_bodies_read':0,'old_provider_results_read':0,'historical_public_private_IDs_used_as_canonical':False}
(t/'priced-original-input.private.json').write_text(json.dumps(out,ensure_ascii=False,separators=(',',':'))+'\n')
summary={'operation':plan['operation'],'state':'NEW_TEN_PRIOR_PRICE_MANIFESTS_AND_NATIVE_IDENTITY_PROJECTED_CURRENT_PROOF_REQUIRED','native_hotel_ids':ids,'prepared_hotels':len(items),'new_projection_cases':cases,'old_fixtures':0,'ssh_calls':0,'db_calls':0,'provider_calls':0,'source_searches':0,'quotes':0,'edits':0,'old_quote_bodies_read':0,'old_provider_results_read':0,'observation_rows_projected':0,'historical_public_private_IDs_used_as_canonical':False}
(t/'priced-original-summary.json').write_text(json.dumps(summary)+'\n');print(json.dumps(summary))
