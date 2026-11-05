import base64,copy,datetime,hashlib,json,os,pathlib,re,time,urllib.parse
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP']);root=t/'hotel-search-links-prepared'
name=os.environ['LINK_SCOPE_FILENAME'];assert pathlib.Path(name).name==name and name.endswith('-scope.json');scopeRaw=(b/name).read_bytes();assert hashlib.sha256(scopeRaw).hexdigest()==os.environ['EXPECTED_LINK_SCOPE_SHA256'];scope=json.loads(scopeRaw)
ps=list((t/'original-link-context').rglob('link-context-readback.private.json'));assert len(ps)==1
def link_scope_ids_valid(ids,bindings,max_hotels,max_reads):
 return isinstance(ids,list) and 1<=len(ids)<=10 and all(type(x) is int and x>0 for x in ids) and len(set(ids))==len(ids) and isinstance(bindings,list) and len(bindings)==len(ids) and all(isinstance(x,dict) and type(x.get('local_tv_id')) is int for x in bindings) and [x['local_tv_id'] for x in bindings]==ids and max_hotels==10 and max_reads==len(ids)
def link_context_partition_valid(ids,rows,deferred):
 if not isinstance(rows,list) or not rows or not isinstance(deferred,list) or len(rows)+len(deferred)!=len(ids):return False
 parts=[x.get('hotel',{}).get('local_tv_id') for x in rows]+[x.get('native_hotel_id') for x in deferred]
 return all(type(x) is int and x>0 for x in parts) and len(set(parts))==len(parts) and set(parts)==set(ids)
ids=scope['native_hotel_ids']
d=json.loads(ps[0].read_text().splitlines()[-1]);assert d['ok'] is True and d['operation']==scope['operation'] and d['quote_state']=='NOQUOTE' and d['gateway_calls']==d['db_writes']==d['price_edits']==0 and d['provider_post_reads']<=scope['max_owned_max_reads']
captureRun=int(os.environ['LINK_CONTEXT_RUN']);captureSource=os.environ['LINK_CONTEXT_SOURCE_SHA'];assert captureRun>0 and re.fullmatch('[a-f0-9]{40}',captureSource) and d['installed_sha']==captureSource
input_files=list((t/'original-link-context').rglob('link-context-input.private.json'));assert len(input_files)==1
input_raw=input_files[0].read_bytes();assert hashlib.sha256(input_raw).hexdigest()==d['input_sha256'];original_input=json.loads(input_raw);assert original_input['operation']==scope['operation'] and original_input['scope_native_ids']==ids
scope['native_bindings']=[x['hotel'] for x in original_input['items']];assert link_scope_ids_valid(ids,scope['native_bindings'],scope['max_hotels'],scope['max_owned_max_reads'])
ids=scope['native_hotel_ids'];a=json.loads((b/'commercial-hotel-materials-20261105-161-acceptance-state.json').read_text());assert len(a['search_link_hotel_ids'])==scope['expected_linked_count'] and len(a['price_hotel_ids'])==scope['expected_priced_count']==85 and not set(ids)&set(a['search_link_hotel_ids']) and set(ids)<=set(a['price_hotel_ids'])
assert link_context_partition_valid(ids,d['bindings'],d.get('deferred',[])+d.get('deferred_before_server',[]))
template=(b/'update-priced-hotel-search-links-explicit-noquote-20261105.php').read_text();assert hashlib.sha256(template.encode()).hexdigest()=='dc2a1f05ae2e24b2d599d811a8e933a02349504c0dc96658c61607f54479be6b'
assert re.fullmatch('commercial-hotel-search-links-20261105-priced[1-9][0-9]{0,2}[a-z]-h',scope['update_hotel_operation_prefix']) and scope['update_hotel_operation_suffix']=='-v1'
root.mkdir(mode=0o700);ready=[];closed=[]
def units(v):return len(v.encode('utf-16-le'))//2
for x in d['bindings']:
 f=x['canonical_context'];fr=(json.dumps(f,ensure_ascii=False,separators=(',',':'))+'\n').encode();fh=hashlib.sha256(fr).hexdigest();h=x['hotel'];v=f['binding'];hid=int(h['local_tv_id'])
 assert f['capture_operation']==d['operation'] and f['capture_run']==captureRun and f['capture_source_sha']==captureSource and 0<=time.time()-int(f['capture_epoch_utc'])<=7200 and f['capture_input_sha256']==d['input_sha256']
 assert h in scope['native_bindings'] and v['native_hotel_id']==hid and v['quote_state']=='NOQUOTE' and 'prior_owned_offer' not in v and v['expected_country_id']==h['expected_country_id']
 publisher=int(h['anytour_id']) if h['identity_provenance']=='accepted_typed_bridge' else hid
 assert v['publisher_hotel_id']==publisher and v['identity_provenance']==h['identity_provenance'] and v['original_role_proof_kind']=='current_confirmed_own_price_manifest_and_receipt' and 'prior_owned_price' in v and v['prior_owned_price']['confirmed_own_receipt_and_current_version'] is True and all(v['contexts'][z]['prior_price_metadata']==v['prior_owned_price']['price_metadata'] for z in ['max','telegram'])
 old=v['caption'];assert hashlib.sha256(old.encode()).hexdigest()==v['caption_sha256'] and all(v['contexts'][p]['old_text']==old for p in ['max','telegram']) and 'https://anytour.online/poisk-turov/' not in old
 preset=v['search_presets'];assert preset==scope['presets'] and preset['departure_id']==1 and preset['adults']==2 and preset['children']==0 and preset['currency']=='RUB'
 start=datetime.date.fromisoformat(preset['departure_date_from']);end=datetime.date.fromisoformat(preset['departure_date_to']);assert start>datetime.datetime.now(datetime.timezone.utc).date() and end-start==datetime.timedelta(days=6) and end<=datetime.date(2027,2,2)
 query=[('days_from',str(preset['nights_from'])),('days_till',str(preset['nights_to'])),('from','1'),('count_people','2'),('country',str(v['expected_country_id'])),('hotel',str(hid)),('date_from',start.isoformat()),('date_till',end.isoformat())];assert preset['nights_from']==5 and preset['nights_to']==14
 search='https://anytour.online/poisk-turov/?'+urllib.parse.urlencode(query);added='🔎 Подобрать тур:\n'+search
 base=old.rstrip('\r\n');trail=old[len(base):];before,cta=base.rsplit('\n',1);assert cta and not re.search(r'[-\u2010-\u2015\u2212]',added.replace(search,''));new=before+'\n\n'+added+'\n\n'+cta+trail
 if units(new)>1024:
  closed.append({'hotel_id':hid,'state':'WHOLE_CURRENT_CAPTION_AND_LINK_EXCEED1024_NO_TRUNCATION_NO_EDIT'});continue
 op=scope['update_hotel_operation_prefix']+str(hid)+scope['update_hotel_operation_suffix']
 row={'key':v['key'],'hotel_id':hid,'logical_id':v['logical_id'],'legacy_context_sha256':fh,'original_publication_hotel_id':publisher,'old_text':old,'old_text_sha256':v['caption_sha256'],'new_text':new,'new_text_sha256':hashlib.sha256(new.encode()).hexdigest(),'expected_existing_photo_signatures':{p:v['contexts'][p]['photo_signature'] for p in ['max','telegram']},'update_text':True,'added_text':added,'search_link_url':search,'search_link_included':True}
 row['expected_prior_price_metadata']=v['prior_owned_price']['price_metadata']
 m={'schema_version':1,'operation':op,'state':'PREPARED_NOT_SENT','button_text':'✈️ Узнать стоимость тура','quote_state':'NOQUOTE','update_kind':'search_link_only','price_total_verified':False,'search_link_included':True,'search_filters':dict(query),'fuel_separately_added':False,'rows':[row]}
 def validate(z):
  assert z['operation']==op and z['quote_state']=='NOQUOTE' and z['update_kind']=='search_link_only' and z['price_total_verified'] is False and all(k not in z for k in ['quote_response_sha256','quote_epoch_utc','quote_index','quote_total_amount','quote_currency','quote_origin_operation'])
  r=z['rows'][0];assert r['hotel_id']==hid and r['logical_id']==v['logical_id'] and r['old_text']==old and r['old_text_sha256']==v['caption_sha256'] and r['expected_existing_photo_signatures']=={p:v['contexts'][p]['photo_signature'] for p in ['max','telegram']} and r['expected_prior_price_metadata']==v['prior_owned_price']['price_metadata']
  assert r['added_text']=='🔎 Подобрать тур:\n'+r['search_link_url'] and r['new_text']==before+'\n\n'+r['added_text']+'\n\n'+cta+trail and units(r['new_text'])<=1024
  u=urllib.parse.urlsplit(r['search_link_url']);assert u.scheme=='https' and u.netloc=='anytour.online' and u.path=='/poisk-turov/' and not u.fragment and urllib.parse.parse_qsl(u.query)==query and z['search_filters']==dict(query)
 validate(m)
 for label in ['foreign_operation','publisher_id_in_native_url','fake_quote_index','prior_price_epoch_changed']:
  bad=copy.deepcopy(m)
  if label=='foreign_operation':bad['operation']+='-foreign'
  elif label=='fake_quote_index':bad['quote_index']=0
  elif label=='prior_price_epoch_changed':bad['rows'][0]['expected_prior_price_metadata']['price_checked_epoch_utc']='0'
  else:
   badHotel=publisher if publisher!=hid else scope['exclude_already_linked_native_ids'][-1]
   q=dict(query);q['hotel']=str(badHotel);u='https://anytour.online/poisk-turov/?'+urllib.parse.urlencode(q);r=bad['rows'][0];r['search_link_url']=u;r['added_text']='🔎 Подобрать тур:\n'+u;r['new_text']=before+'\n\n'+r['added_text']+'\n\n'+cta+trail
  try:validate(bad)
  except AssertionError:pass
  else:raise AssertionError('new_data_bound_offer_case_accepted:'+label)
 target=root/str(hid);target.mkdir(mode=0o700);raw=(json.dumps(m,ensure_ascii=False,separators=(',',':'))+'\n').encode();mh=hashlib.sha256(raw).hexdigest()
 approval={'schema_version':1,'operation':op,'expected_installed_sha':os.environ['EXPECTED_PRODUCTION_SHA'],'hotel_ids':[hid],'manifest_sha256':mh}
 (target/'update-manifest.json').write_bytes(raw);(target/'update-approval.json').write_text(json.dumps(approval)+'\n');(target/'context-frame.private.json').write_bytes(fr)
 constants={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_MANIFEST_SHA256':mh,'MANIFEST_BASE64':base64.b64encode(raw).decode(),'APPROVED_UPDATE_HOTEL_ID':hid,'APPROVED_UPDATE_KEY_B64':base64.b64encode(v['key'].encode()).decode(),'APPROVED_UPDATE_LOGICAL_B64':base64.b64encode(v['logical_id'].encode()).decode(),'APPROVED_UPDATE_OPERATION':op,'APPROVED_LEGACY_CONTEXT_B64':base64.b64encode(fr).decode(),'APPROVED_LEGACY_CONTEXT_SHA256':fh,'APPROVED_IDENTITY_BINDINGS_B64':base64.b64encode(json.dumps(scope['native_bindings'],ensure_ascii=False,separators=(',',':')).encode()).decode(),'APPROVED_SEARCH_URL_B64':base64.b64encode(search.encode()).decode(),'APPROVED_LINK_CAPTURE_OPERATION':f['capture_operation'],'APPROVED_LINK_CAPTURE_RUN':f['capture_run'],'APPROVED_LINK_CAPTURE_SOURCE':f['capture_source_sha'],'APPROVED_LINK_UPDATE_PREFIX':scope['update_hotel_operation_prefix'],'APPROVED_LINK_UPDATE_SUFFIX':scope['update_hotel_operation_suffix']}
 assert set(re.findall(r'\bAPPROVED_[A-Z0-9_]+\b',template))<=set(constants),'GENERATED_OFFER_CONSTANT_REFERENCES_UNDECLARED'
 prefix="<?php\ndeclare(strict_types=1);\n";assert template.startswith(prefix);script=prefix+''.join('const '+k+'='+json.dumps(val,ensure_ascii=False)+';\n' for k,val in constants.items())+template[len(prefix):];(target/'update.php').write_text(script)
 intent={'operation':op,'state':'EXPLICIT_NOQUOTE_SEARCH_LINK_TWO_EXISTING_POST_EDITS_INTENT_BEFORE_PROVIDER','native_hotel_ids':[hid],'manifest_sha256':mh,'expected_source':os.environ['EXPECTED_PRODUCTION_SHA'],'context_run':captureRun,'quote_state':'NOQUOTE','max_content_edits':2,'new_posts':0,'gateway_calls':0,'source_searches':0};(target/'update-intent.json').write_text(json.dumps(intent)+'\n')
 ready.append({'hotel_id':hid,'operation':op,'folder':str(hid),'quote_state':'NOQUOTE','update_kind':'search_link_only','price_total_verified':False,'search_link_included':True,'manifest_sha256':mh,'script_sha256':hashlib.sha256(script.encode()).hexdigest(),'source_template_sha256':'dc2a1f05ae2e24b2d599d811a8e933a02349504c0dc96658c61607f54479be6b'})
 print(json.dumps({'operation':op,'native_hotel_id':hid,'quote_state':'NOQUOTE','utf16_units':units(new),'whole_current_caption_closing_photo_button_preserved':True,'new_python_cases':4,'all_operation_owned_constants_declared':True,'old_fixtures':0,'provider_calls':0,'gateway_calls':0,'existing_posts_to_edit':2}),flush=True)
assert len(ready)+len(closed)==len(d['bindings']) and len({x['hotel_id'] for x in ready})==len(ready)
(root/'update-index.json').write_text(json.dumps(ready)+'\n');(root/'closed-no-edit.json').write_text(json.dumps(closed)+'\n');(root/'all-update-intents.json').write_text(json.dumps([json.loads((root/x['folder']/'update-intent.json').read_text()) for x in ready])+'\n')
summary={'operation':scope['update_operation'],'prepared_hotels':len(ready),'prepared_native_ids':[x['hotel_id'] for x in ready],'scope_native_ids':ids,'quote_state':'NOQUOTE','prepared_prices':0,'max_existing_content_edits':2*len(ready),'closed_no_edit':closed,'context_deferred':d.get('deferred',[])+d.get('deferred_before_server',[]),'new_python_cases_per_hotel':4,'new_native_php_cases_per_hotel':8,'old_fixtures':0,'provider_calls':0,'gateway_calls':0,'new_posts':0,'template_sha256':'dc2a1f05ae2e24b2d599d811a8e933a02349504c0dc96658c61607f54479be6b','context_run':captureRun,'context_source_sha':captureSource,'prepared_source_sha':os.environ['EXPECTED_PRODUCTION_SHA'],'scope_filename':scope['scope_filename'],'scope_sha256':hashlib.sha256(scopeRaw).hexdigest()}
summary['native_bindings']=scope['native_bindings']
(root/'prepare-summary.json').write_text(json.dumps(summary)+'\n');safe_summary=dict(summary);safe_summary.pop('native_bindings');print(json.dumps(safe_summary))
