import copy,hashlib,json,os,pathlib,re
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
plan=json.loads((b/'commercial-hotel-search-links-20261105-priced10-original-projection1-plan.json').read_text());ids=plan['native_hotel_ids'];a=json.loads((b/'commercial-hotel-materials-20261105-161-acceptance-state.json').read_text())
assert len(ids)==len(set(ids))==10 and a['search_link_hotels_completed']==123 and a['price_hotels_total']==85 and set(ids)<=set(a['price_hotel_ids']) and not set(ids)&set(a['search_link_hotel_ids'])
def one(folder,name):
 files=list((t/folder).rglob(name));assert len(files)==1,(folder,name);return files[0]
identity=json.loads(one('identity10','price-readback.json').read_text().splitlines()[-1]);assert identity['ok'] and len(identity['hotels'])==159
fields=['local_tv_id','expected_name','expected_country_id','identity_provenance','anytour_id']
native={int(h['local_tv_id']):{k:h[k] for k in fields if k in h} for h in identity['hotels'] if int(h['local_tv_id']) in ids}
assert set(native)==set(ids) and all(isinstance(h['expected_name'],str) and h['expected_country_id'] in [1,4] for h in native.values())
inputs={12114:(b/plan['legacy_first_manifest']['path']).read_bytes()}
for source in plan['original_sources']:
 if source.get('identity_only'):continue
 if source['folder']=='prior19':
  index=json.loads(one(source['folder'],'update-index.json').read_text());root=one(source['folder'],'update-index.json').parent
  for hid in source['hotel_ids']:
   entry=[x for x in index if x['hotel_id']==hid];assert len(entry)==1 and entry[0]['folder']==str(hid)
   inputs[hid]=(root/str(hid)/'update-manifest.json').read_bytes()
 else:inputs[source['hotel_ids'][0]]=one(source['folder'],source['file']).read_bytes()
assert set(inputs)==set(ids)
items=[];cases=0
for hid in ids:
 raw=inputs[hid];m=json.loads(raw);h=native[hid]
 expected='commercial-price-update-20261103-1-v1' if hid==12114 else ('commercial-price-update-20261104-72h-pass1-h'+str(hid)+'-v1' if hid in [253,165,389] else 'commercial-price-update-20261104-'+str({9432:2,109:3,13873:4,13869:5,73342:6,233:8}[hid])+'-v1')
 def validate(z):
  assert z['operation']==expected and len(z['rows'])==1
  r=z['rows'][0];assert r['hotel_id']==hid and r['key'] and r['logical_id'] and r['update_text'] is True and hashlib.sha256(r['old_text'].encode()).hexdigest()==r['old_text_sha256'] and hashlib.sha256(r['new_text'].encode()).hexdigest()==r['new_text_sha256']
  assert re.fullmatch('[a-f0-9]{64}',z['quote_response_sha256']) and type(z['quote_epoch_utc']) is int and z['quote_epoch_utc']>0 and 'https://anytour.online/poisk-turov/' not in r['new_text']
 validate(m)
 for label in ['foreign_operation','wrong_native_id','caption_sha_mismatch']:
  bad=copy.deepcopy(m)
  if label=='foreign_operation':bad['operation']+='-foreign'
  elif label=='wrong_native_id':bad['rows'][0]['hotel_id']=999999999
  else:bad['rows'][0]['new_text']+='foreign'
  try:validate(bad)
  except AssertionError:cases+=1
  else:raise AssertionError('NEW_PRICED_PROJECTION_CASE_ACCEPTED:'+label)
 mh=hashlib.sha256(json.dumps(m,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
 publisher=int(h['anytour_id']) if h['identity_provenance']=='accepted_typed_bridge' else hid
 items.append({'hotel':h,'kind':'current_confirmed_own_price_manifest_and_receipt','publisher_hotel_id':publisher,'owned_price_payload':m,'owned_price_manifest_sha256':hashlib.sha256(raw).hexdigest(),'source_manifest_hash':mh})
out={'operation':plan['operation'],'quote_state':'NOQUOTE','items':items,'deferred_before_server':[],'scope_native_ids':ids,'allowed_source_operations':sorted(x['owned_price_payload']['operation'] for x in items),'prior_price_amounts_or_checktimes_changed':False,'old_quote_bodies_read':0,'old_provider_results_read':0,'historical_public_private_IDs_used_as_canonical':False}
(t/'priced-original-input.private.json').write_text(json.dumps(out,ensure_ascii=False,separators=(',',':'))+'\n')
summary={'operation':plan['operation'],'state':'ORIGINAL10_PRIOR_PRICE_MANIFESTS_AND_NATIVE_IDENTITY_PROJECTED_CURRENT_PROOF_REQUIRED','native_hotel_ids':ids,'prepared_hotels':len(items),'new_projection_cases':cases,'old_fixtures':0,'ssh_calls':0,'db_calls':0,'provider_calls':0,'source_searches':0,'quotes':0,'edits':0,'old_quote_bodies_read':0,'old_provider_results_read':0,'observation_rows_projected':0,'historical_public_private_IDs_used_as_canonical':False}
(t/'priced-original-summary.json').write_text(json.dumps(summary)+'\n');print(json.dumps(summary))
