import copy,hashlib,json,os,pathlib,re
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
name=os.environ['LINK_PROJECTION_PLAN_FILENAME'];assert pathlib.Path(name).name==name
raw=(b/name).read_bytes();assert hashlib.sha256(raw).hexdigest()==os.environ['EXPECTED_LINK_PROJECTION_PLAN_SHA256'];plan=json.loads(raw)
ids=plan['native_hotel_ids'];a=json.loads((b/'commercial-hotel-materials-20261105-161-acceptance-state.json').read_text())
assert 1<=len(ids)==len(set(ids))<=10 and a['search_link_hotels_completed']==len(a['search_link_hotel_ids'])==plan['expected_linked_count'] and a['price_hotels_total']==len(a['price_hotel_ids'])==plan['expected_priced_count'] and a['logical_hotels_published_per_channel']==plan['expected_published_pairs']
assert set(ids)<=set(a['price_hotel_ids']) and not set(ids)&set(a['search_link_hotel_ids']) and set(plan['legacy_roles'])==set(map(str,ids))==set(plan['prior_price_operations'])
paths=list((t/'legacy-original-intents').rglob('update-index.json'));assert len(paths)==1;root=paths[0].parent;index=json.loads(paths[0].read_text())
items=[];cases=0
for position,hid in enumerate(ids):
 entries=[x for x in index if x['hotel_id']==hid];assert len(entries)==1
 folder=entries[0]['folder'];assert isinstance(folder,str) and pathlib.PurePosixPath(folder).parts and all(x not in ('..','.') for x in pathlib.PurePosixPath(folder).parts) and not pathlib.PurePosixPath(folder).is_absolute()
 target=root/folder
 raw=(target/'update-manifest.json').read_bytes();m=json.loads(raw);candidates=list(target.rglob('candidate.json'));assert len(candidates)==1;candidate=json.loads(candidates[0].read_text());role=plan['legacy_roles'][str(hid)]
 def validate(z,c):
  assert z['operation']==plan['prior_price_operations'][str(hid)] and len(z['rows'])==1
  r=z['rows'][0];f=c['canonical_context'];v=f['binding'];h=c['hotel']
  fraw=json.dumps(f,ensure_ascii=False,separators=(',',':')).encode();fsha=hashlib.sha256(fraw).hexdigest()
  assert fsha==c['canonical_context_sha256']==r['legacy_context_sha256'] and f['capture_source_sha']==plan['original_preprovider_source']['original_source_sha'] and f['capture_operation']==role['capture_operation'] and f['capture_run']==role['capture_run']
  assert r['hotel_id']==h['local_tv_id']==v['native_hotel_id']==hid and r['key']==v['key'] and r['logical_id']==v['logical_id'] and r['update_text'] is True
  assert isinstance(h['expected_name'],str) and h['expected_name'] and h['identity_provenance']==v['identity_provenance']==role['identity_provenance'] and h['expected_country_id']==v['expected_country_id']==role['expected_country_id']
  assert 'original_publication_hotel_id' in r and 'publisher_hotel_id' in v and r['original_publication_hotel_id']==v['publisher_hotel_id']==role['publisher_hotel_id']
  assert r['old_text']==v['caption'] and r['old_text_sha256']==v['caption_sha256']==hashlib.sha256(r['old_text'].encode()).hexdigest() and r['new_text_sha256']==hashlib.sha256(r['new_text'].encode()).hexdigest() and 'https://anytour.online/poisk-turov/' not in r['new_text']
  assert set(r['expected_existing_photo_signatures'])=={'max','telegram'} and r['expected_existing_photo_signatures']=={p:v['contexts'][p]['photo_signature'] for p in ['max','telegram']} and all(isinstance(x,str) and x for x in r['expected_existing_photo_signatures'].values()) and 'cover_sha256' not in r
  assert re.fullmatch('[a-f0-9]{64}',z['quote_response_sha256']) and type(z['quote_epoch_utc']) is int and z['quote_epoch_utc']>0
 validate(m,candidate)
 for label in ['foreign_operation','wrong_native','caption_sha','publisher','publisher_missing','origin_frame']:
  bad=copy.deepcopy(m);bc=copy.deepcopy(candidate)
  if label=='foreign_operation':bad['operation']+='-foreign'
  elif label=='wrong_native':bad['rows'][0]['hotel_id']=ids[(position+1)%len(ids)] if len(ids)>1 else 999999999
  elif label=='caption_sha':bad['rows'][0]['new_text']+='foreign'
  elif label=='publisher':bad['rows'][0]['original_publication_hotel_id']=hid if role['publisher_hotel_id'] is None else None
  elif label=='publisher_missing':del bad['rows'][0]['original_publication_hotel_id']
  else:bc['canonical_context']['capture_run']+=1
  try:validate(bad,bc)
  except (AssertionError,KeyError):cases+=1
  else:raise AssertionError('NEW_LEGACY_PRICE_INPUT_NEGATIVE_ACCEPTED:'+label)
 frame=candidate['canonical_context'];v=frame['binding'];h={k:candidate['hotel'][k] for k in ['local_tv_id','expected_name','expected_country_id','identity_provenance']}
 if h['identity_provenance']=='accepted_typed_bridge':h['anytour_id']=role['publisher_hotel_id']
 proof={'native_hotel_id':hid,'publisher_hotel_id':role['publisher_hotel_id'],'identity_provenance':role['identity_provenance'],'expected_country_id':role['expected_country_id'],'origin_frame_sha256':candidate['canonical_context_sha256'],'capture_operation':frame['capture_operation'],'capture_run':frame['capture_run'],'capture_source_sha':frame['capture_source_sha'],'capture_input_sha256':frame['capture_input_sha256'],'original_photo_signatures':m['rows'][0]['expected_existing_photo_signatures'],'origin_witness_only_not_current_publication':True}
 mh=hashlib.sha256(json.dumps(m,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
 items.append({'hotel':h,'kind':plan['role'],'publisher_hotel_id':role['publisher_hotel_id'],'legacy_role_proof':proof,'owned_price_payload':m,'owned_price_manifest_sha256':hashlib.sha256(raw).hexdigest(),'source_manifest_hash':mh})
out={'operation':plan['operation'],'quote_state':'NOQUOTE','items':items,'deferred_before_server':[],'scope_native_ids':ids,'allowed_source_operations':sorted(x['owned_price_payload']['operation'] for x in items),'prior_price_amounts_or_checktimes_changed':False,'old_quote_bodies_read':0,'old_provider_results_read':0,'historical_public_private_IDs_used_as_canonical':False}
(t/'priced-original-input.private.json').write_text(json.dumps(out,ensure_ascii=False,separators=(',',':'))+'\n')
summary={'operation':plan['operation'],'state':'NEW_LEGACY_SIX_PRICE_MANIFESTS_EXPLICIT_ORIGIN_ROLES_PROJECTED_CURRENT_PROOF_REQUIRED','native_hotel_ids':ids,'prepared_hotels':len(items),'explicit_copy_publisher_null_hotels':sum(x['publisher_hotel_id'] is None for x in items),'typed_bridge_hotels':sum(x['hotel']['identity_provenance']=='accepted_typed_bridge' for x in items),'new_projection_cases':cases,'old_fixtures':0,'ssh_calls':0,'db_calls':0,'provider_calls':0,'source_searches':0,'quotes':0,'edits':0,'old_quote_bodies_read':0,'old_provider_results_read':0,'observation_fields_projected':0,'historical_public_private_IDs_used_as_canonical':False}
(t/'priced-original-summary.json').write_text(json.dumps(summary)+'\n');print(json.dumps(summary))
