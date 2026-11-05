import base64,copy,datetime,hashlib,json,os,pathlib,re,time,urllib.parse
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP']);root=t/'hotel-search-links-ten1'
ps=list((t/'original-context17').rglob('context17-readback.private.json'));assert len(ps)==1
d=json.loads(ps[0].read_text().splitlines()[-1]);assert d['ok'] and d['operation']=='commercial-hotel-search-links-20261105-context17-ten1' and d['quote_state']=='NOQUOTE' and d['provider_post_reads']==10 and d['gateway_calls']==d['db_writes']==d['price_edits']==0
scope=json.loads((b/'commercial-hotel-search-links-20261105-context17-ten1-scope.json').read_text());ids=[129,139,150,172,173,232,241,249,250,263]
a=json.loads((b/'commercial-hotel-materials-20261105-161-acceptance-state.json').read_text());assert len(a['search_link_hotel_ids'])==60 and len(a['price_hotel_ids'])==85 and not set(ids)&set(a['search_link_hotel_ids']) and not set(ids)&set(a['price_hotel_ids'])
assert {x['hotel']['local_tv_id'] for x in d['bindings']}==set(ids) and len(d['bindings'])==10
template=(b/'update-hotel-search-links-explicit-noquote-20261105-ten1.php').read_text();assert hashlib.sha256(template.encode()).hexdigest()=='d5315073bf1eb9677936b5065b85d27ebc59599e2f8533bfb1ff1061e5a275af'
root.mkdir(mode=0o700);ready=[];closed=[]
def units(v):return len(v.encode('utf-16-le'))//2
for x in d['bindings']:
 frame=x['canonical_context'];fr=(json.dumps(frame,ensure_ascii=False,separators=(',',':'))+'\n').encode();fh=hashlib.sha256(fr).hexdigest();h=x['hotel'];v=frame['binding'];hid=int(h['local_tv_id'])
 assert frame['capture_operation']==d['operation'] and frame['capture_run']==42009478360 and frame['capture_source_sha']=='a95f6e3514eb48f2b7dcda36ac1f40ab31d0ff99' and 0<=time.time()-int(frame['capture_epoch_utc'])<=7200
 assert v['native_hotel_id']==hid and v['quote_state']=='NOQUOTE' and 'prior_owned_offer' not in v and v['expected_country_id']==h['expected_country_id']
 expected=int(h['anytour_id']) if h['identity_provenance']=='accepted_typed_bridge' else hid
 assert v['publisher_hotel_id']==expected and v['identity_provenance']==h['identity_provenance'] and v['original_role_proof_kind']=='original_publisher_and_latest_confirmed_caption'
 old=v['caption'];assert hashlib.sha256(old.encode()).hexdigest()==v['caption_sha256'] and all(v['contexts'][p]['old_text']==old for p in ['max','telegram']) and 'https://anytour.online/poisk-turov/' not in old
 preset=v['search_presets'];assert preset==scope['presets'] and preset['departure_id']==1 and preset['adults']==2 and preset['children']==0 and preset['currency']=='RUB'
 start=datetime.date.fromisoformat(preset['departure_date_from']);end=datetime.date.fromisoformat(preset['departure_date_to'])
 assert start>datetime.datetime.now(datetime.timezone.utc).date() and end-start==datetime.timedelta(days=6) and end<=datetime.date(2027,2,2)
 query=[('days_from',str(preset['nights_from'])),('days_till',str(preset['nights_to'])),('from','1'),('count_people','2'),('country',str(v['expected_country_id'])),('hotel',str(hid)),('date_from',start.isoformat()),('date_till',end.isoformat())]
 assert preset['nights_from']==5 and preset['nights_to']==14
 search='https://anytour.online/poisk-turov/?'+urllib.parse.urlencode(query)
 added='🔎 Подобрать тур:\n'+search
 base=old.rstrip('\r\n');trail=old[len(base):];before,cta=base.rsplit('\n',1);assert cta and not re.search(r'[-\u2010-\u2015\u2212]',added.replace(search,''))
 new=before+'\n\n'+added+'\n\n'+cta+trail
 if units(new)>1024:
  closed.append({'hotel_id':hid,'state':'WHOLE_CURRENT_CAPTION_AND_LINK_EXCEED1024_NO_TRUNCATION_NO_EDIT'});continue
 op='commercial-hotel-search-links-20261105-ten1-h'+str(hid)+'-v1'
 row={'key':v['key'],'hotel_id':hid,'logical_id':v['logical_id'],'legacy_context_sha256':fh,'original_publication_hotel_id':expected,'old_text':old,'old_text_sha256':v['caption_sha256'],'new_text':new,'new_text_sha256':hashlib.sha256(new.encode()).hexdigest(),'expected_existing_photo_signatures':{p:v['contexts'][p]['photo_signature'] for p in ['max','telegram']},'update_text':True,'added_text':added,'search_link_url':search,'search_link_included':True}
 m={'schema_version':1,'operation':op,'state':'PREPARED_NOT_SENT','button_text':'✈️ Узнать стоимость тура','quote_state':'NOQUOTE','update_kind':'search_link_only','price_total_verified':False,'search_link_included':True,'search_filters':dict(query),'fuel_separately_added':False,'rows':[row]}
 def validate(z):
  assert z['quote_state']=='NOQUOTE' and z['update_kind']=='search_link_only' and z['price_total_verified'] is False
  assert all(k not in z for k in ['quote_response_sha256','quote_epoch_utc','quote_index','quote_total_amount','quote_currency','quote_origin_operation'])
  r=z['rows'][0];assert r['hotel_id']==hid and r['logical_id']==v['logical_id'] and r['old_text']==old and r['old_text_sha256']==v['caption_sha256'] and r['expected_existing_photo_signatures']=={p:v['contexts'][p]['photo_signature'] for p in ['max','telegram']}
  assert r['added_text']=='🔎 Подобрать тур:\n'+r['search_link_url'] and r['new_text']==before+'\n\n'+r['added_text']+'\n\n'+cta+trail and units(r['new_text'])<=1024
  u=urllib.parse.urlsplit(r['search_link_url']);assert u.scheme=='https' and u.netloc=='anytour.online' and u.path=='/poisk-turov/' and not u.fragment and urllib.parse.parse_qsl(u.query)==query and z['search_filters']==dict(query)
 validate(m)
 for label in ['fake_quote','fake_price','logical','body','photo','url_native','url_country','url_date']:
  bad=copy.deepcopy(m)
  if label=='fake_quote':bad['quote_epoch_utc']=int(time.time())
  elif label=='fake_price':bad['price_total_verified']=True
  elif label=='logical':bad['rows'][0]['logical_id']+=':foreign'
  elif label=='body':bad['rows'][0]['old_text']+='foreign'
  elif label=='photo':bad['rows'][0]['expected_existing_photo_signatures']['max']='foreign'
  else:
   u=urllib.parse.urlsplit(search);q=dict(urllib.parse.parse_qsl(u.query));q[{'url_native':'hotel','url_country':'country','url_date':'date_from'}[label]]={'url_native':'1422','url_country':'99','url_date':'2026-11-05'}[label];bad['rows'][0]['search_link_url']=urllib.parse.urlunsplit((u.scheme,u.netloc,u.path,urllib.parse.urlencode(q),''))
  try:validate(bad)
  except AssertionError:pass
  else:raise AssertionError('noquote_new_binding_case_accepted:'+label)
 target=root/str(hid);target.mkdir(mode=0o700);raw=(json.dumps(m,ensure_ascii=False,separators=(',',':'))+'\n').encode();mh=hashlib.sha256(raw).hexdigest()
 approval={'schema_version':1,'operation':op,'expected_installed_sha':os.environ['EXPECTED_PRODUCTION_SHA'],'hotel_ids':[hid],'manifest_sha256':mh}
 (target/'update-manifest.json').write_bytes(raw);(target/'update-approval.json').write_text(json.dumps(approval)+'\n')
 (target/'context-frame.private.json').write_bytes(fr)
 constants={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_MANIFEST_SHA256':mh,'MANIFEST_BASE64':base64.b64encode(raw).decode(),'APPROVED_UPDATE_HOTEL_ID':hid,'APPROVED_UPDATE_KEY_B64':base64.b64encode(v['key'].encode()).decode(),'APPROVED_UPDATE_LOGICAL_B64':base64.b64encode(v['logical_id'].encode()).decode(),'APPROVED_UPDATE_OPERATION':op,'APPROVED_LEGACY_CONTEXT_B64':base64.b64encode(fr).decode(),'APPROVED_LEGACY_CONTEXT_SHA256':fh,'APPROVED_IDENTITY_BINDINGS_B64':base64.b64encode(json.dumps(scope['native_bindings'],ensure_ascii=False,separators=(',',':')).encode()).decode(),'APPROVED_SEARCH_URL_B64':base64.b64encode(search.encode()).decode()}
 prefix="<?php\ndeclare(strict_types=1);\n";assert template.startswith(prefix)
 script=prefix+''.join('const '+k+'='+json.dumps(val,ensure_ascii=False)+';\n' for k,val in constants.items())+template[len(prefix):]
 (target/'update.php').write_text(script)
 intent={'operation':op,'state':'EXPLICIT_NOQUOTE_SEARCH_LINK_TWO_EXISTING_POST_EDITS_INTENT_BEFORE_PROVIDER','native_hotel_ids':[hid],'manifest_sha256':mh,'expected_source':os.environ['EXPECTED_PRODUCTION_SHA'],'context_run':42009478360,'quote_state':'NOQUOTE','max_content_edits':2,'new_posts':0,'gateway_calls':0,'source_searches':0}
 (target/'update-intent.json').write_text(json.dumps(intent)+'\n')
 record={'hotel_id':hid,'operation':op,'folder':str(hid),'quote_state':'NOQUOTE','update_kind':'search_link_only','price_total_verified':False,'search_link_included':True,'manifest_sha256':mh,'script_sha256':hashlib.sha256(script.encode()).hexdigest(),'source_template_sha256':'d5315073bf1eb9677936b5065b85d27ebc59599e2f8533bfb1ff1061e5a275af'}
 ready.append(record)
 print(json.dumps({'operation':op,'native_hotel_id':hid,'quote_state':'NOQUOTE','utf16_units':units(new),'whole_current_caption_closing_photo_button_preserved':True,'new_python_cases':8,'old_fixtures':0,'provider_calls':0,'gateway_calls':0,'existing_posts_to_edit':2}),flush=True)
assert len(ready)+len(closed)==10 and len({x['hotel_id'] for x in ready})==len(ready)
(root/'update-index.json').write_text(json.dumps(ready)+'\n');(root/'closed-no-edit.json').write_text(json.dumps(closed)+'\n')
(root/'all-update-intents.json').write_text(json.dumps([json.loads((root/x['folder']/'update-intent.json').read_text()) for x in ready])+'\n')
summary={'operation':'commercial-hotel-search-links-20261105-ten1-pass1','prepared_hotels':len(ready),'prepared_native_ids':[x['hotel_id'] for x in ready],'quote_state':'NOQUOTE','prepared_prices':0,'max_existing_content_edits':2*len(ready),'closed_no_edit':closed,'new_python_cases_per_hotel':8,'new_native_php_cases_per_hotel':6,'old_fixtures':0,'provider_calls':0,'gateway_calls':0,'new_posts':0,'template_sha256':'d5315073bf1eb9677936b5065b85d27ebc59599e2f8533bfb1ff1061e5a275af'}
(root/'prepare-summary.json').write_text(json.dumps(summary)+'\n');print(json.dumps(summary))
