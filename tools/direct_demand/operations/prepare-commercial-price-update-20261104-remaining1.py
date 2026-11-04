import base64,copy,datetime,decimal,hashlib,json,os,pathlib,re,time
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP']);root=temp/'remaining1'
blocked={233,110,73342,13869,13873,109,12114,9432,482,476,81173,32577,478,80964}
def build(entry):
 hid=int(entry['hotel_id']);assert hid>0 and hid not in blocked
 folder=root/entry['folder'];quote_op=entry['quote_operation']
 assert quote_op=='commercial-price-monitor-20261104-remaining1-h'+str(hid)
 update_op='commercial-price-update-20261104-remaining1-h'+str(hid)+'-v1'
 def one(name):
  paths=list(folder.rglob(name));assert len(paths)==1,name
  return paths[0]
 d=json.loads(one('quote-result.json').read_text().splitlines()[-1])
 chosen=json.loads(one('candidate.json').read_text());h=chosen['hotel'];t=d['retained_terms'];old=chosen['original_manifest_row']
 assert int(h['local_tv_id'])==int(d['catalog_id'])==int(old['hotel_id'])==hid
 assert d['operation']==quote_op and d['ok'] is True and d['http_status']==200 and d['gateway_requests_attempted']==1
 assert 0<=time.time()-int(d['response_received_epoch_utc'])<=108000
 q=d['gateway_response'];assert q.get('error') is None and q['info'].get('surcharges') in (None,[])
 assert q['info']['flags']=={'noTransfer':False,'noInsurance':False,'noFlight':False,'noMeal':False}
 assert (int(t['adults']),int(t['children']),t['currency'])==(2,0,'RUB')
 start=datetime.date.fromisoformat(t['departure_date']);end=start+datetime.timedelta(days=int(t['nights']))
 assert start>datetime.datetime.now(datetime.timezone.utc).date()
 valid=[];rejected=[]
 for i,f in enumerate(q['flights']):
  try:
   assert f['price']['currency']=='RUB'
   amount=decimal.Decimal(str(f['price']['value']));assert amount.is_finite() and 0<amount<decimal.Decimal('100000000') and amount*100==(amount*100).to_integral_value()
   assert f['dateForward']==start.strftime('%d.%m.%Y') and f['dateBackward']==end.strftime('%d.%m.%Y')
   assert isinstance(f['forward'],list) and isinstance(f['backward'],list) and 1<=len(f['forward'])<=4 and 1<=len(f['backward'])<=4
   for leg in f['forward']+f['backward']:
    assert leg.get('noPlaces') is False and leg.get('onDemand') is False
    number=re.sub(r'\s+','',str(leg.get('number',''))).upper()
    match=re.fullmatch(r'[A-Z0-9]{2}([0-9]{1,5})',number)
    assert match and int(match.group(1))>0 and number!='SU000'
   valid.append((amount,i,f))
  except (AssertionError,KeyError,TypeError,decimal.InvalidOperation):
   rejected.append(i)
 assert valid,'NO_VERIFIED_REAL_ROUNDTRIP_OPTION_NO_EDIT'
 valid.sort(key=lambda x:(x[0],x[1]));amount,index,flight=valid[0]
 rawold=old['text'];assert hashlib.sha256(rawold.encode()).hexdigest()==old['text_sha256']
 def caption_parts(text,expected_cta):
  body=text.rstrip('\r\n');suffix=text[len(body):]
  prefix,cta=body.rsplit('\n',1)
  assert cta==expected_cta and expected_cta
  return prefix,cta,suffix
 expected_cta=rawold.rstrip('\r\n').split('\n')[-1]
 assert re.search(r'стоимост|[Нн]ажмите|[Нн]апишите',expected_cta)
 fixture='Отель\n'+expected_cta
 assert caption_parts(fixture,expected_cta)==('Отель',expected_cta,'')
 assert caption_parts(fixture+'\n\n',expected_cta)==('Отель',expected_cta,'\n\n')
 try:caption_parts('Отель\nДругой текст',expected_cta)
 except AssertionError:pass
 else:raise AssertionError('foreign_original_CTA_accepted')
 body=rawold.rstrip('\r\n')
 print(json.dumps({'operation':update_op+'-caption-binding','catalog_id':hid,'catalog_name':chosen['catalog_name'],'source_manifest_path':chosen['original_manifest_path'],'trailing_newline_characters':len(rawold)-len(body),'exact_original_cta_preserved':body.split('\n')[-1]==expected_cta,'new_exact_closing_paragraph_cases':3,'server_calls':0,'provider_calls':0}))
 before,cta,trailing=caption_parts(rawold,expected_cta)
 native_meals={2:'RO',3:'BB',4:'HB',5:'FB',7:'AI',9:'UAI'}
 meal=native_meals[int(t['meal_id'])];assert chosen['retained']['native_meal_label']==meal
 room=str(t['room_type']).strip();departure=str(chosen['retained']['departure_name']).strip();assert room and departure
 def display(value):return re.sub(r'\s+',' ',re.sub(r'[-\u2010-\u2015\u2212]',' ',str(value))).strip()
 def rub(v):
  x=format(v,',.2f').replace(',',' ').replace('.',',')
  return x[:-3] if x.endswith(',00') else x
 n=int(t['nights']);mod=n%100;word='ночей' if 11<=mod<=14 else ('ночь' if n%10==1 else ('ночи' if n%10 in (2,3,4) else 'ночей'))
 stamp=datetime.datetime.fromtimestamp(int(d['response_received_epoch_utc']),datetime.timezone.utc).strftime('%d.%m.%Y %H:%M UTC')
 block='✈️ Вылет: '+display(departure)+'\n📅 '+start.strftime('%d.%m.%Y')+' по '+end.strftime('%d.%m.%Y')+', '+str(n)+' '+word+'\n👥 2 взрослых\n🛏 Номер: '+display(room)+'\n🍽 Питание: '+meal+'\n💰 '+rub(amount)+' ₽ за тур\n🕒 Проверено '+stamp
 new=before+'\n\n'+block+'\n\n'+cta+trailing
 assert len(new.encode('utf-16-le'))//2<=1024 and not re.search(r'[-\u2010-\u2015\u2212]',new)
 row={'key':old['key'],'hotel_id':hid,'logical_id':old['logical_id'],'original_publication_operation':chosen['original_publication_operation'],'old_text':rawold,'old_text_sha256':old['text_sha256'],'new_text':new,'new_text_sha256':hashlib.sha256(new.encode()).hexdigest(),'cover_sha256':old['photo_sha256'],'update_text':True}
 manifest={'schema_version':1,'operation':update_op,'state':'PREPARED_NOT_SENT','button_text':'✈️ Узнать стоимость тура','quote_origin_operation':d['operation'],'quote_response_sha256':d['response_sha256'],'quote_epoch_utc':int(d['response_received_epoch_utc']),'quote_index':index,'quote_total_amount':str(amount),'quote_currency':'RUB','fuel_separately_added':False,'rows':[row]}
 def validate(saved,m):
  assert saved['operation']==quote_op and int(saved['catalog_id'])==hid and saved['ok'] and saved['http_status']==200 and saved['gateway_requests_attempted']==1
  assert saved['response_sha256']==m['quote_response_sha256'] and int(saved['response_received_epoch_utc'])==m['quote_epoch_utc']
  assert 0<=time.time()-int(saved['response_received_epoch_utc'])<=108000
  f=saved['gateway_response']['flights'][m['quote_index']]
  assert decimal.Decimal(str(f['price']['value']))==decimal.Decimal(m['quote_total_amount']) and f['price']['currency']==m['quote_currency']=='RUB'
  assert m['fuel_separately_added'] is False and len(m['rows'])==1
  r=m['rows'][0];assert r['hotel_id']==hid and r['key']==old['key'] and r['logical_id']==old['logical_id']
  assert r['old_text']==rawold and r['cover_sha256']==old['photo_sha256']
  assert r['new_text'].startswith(before+'\n\n') and r['new_text'].endswith('\n\n'+cta+trailing)
  assert hashlib.sha256(r['old_text'].encode()).hexdigest()==r['old_text_sha256']
  assert hashlib.sha256(r['new_text'].encode()).hexdigest()==r['new_text_sha256']
 validate(d,manifest)
 for change in [lambda x,m:x.update(catalog_id=110),lambda x,m:m['rows'][0].update(logical_id=old['logical_id']+':foreign'),lambda x,m:m['rows'][0].update(cover_sha256='0'*64)]:
  x=copy.deepcopy(d);m=copy.deepcopy(manifest);change(x,m)
  try:validate(x,m)
  except AssertionError:pass
  else:raise AssertionError('unsafe_remaining_exact_native_logical_cover_binding_accepted')
 raw=(json.dumps(manifest,ensure_ascii=False,separators=(',',':'))+'\n').encode()
 approval={'schema_version':1,'operation':manifest['operation'],'expected_installed_sha':os.environ['EXPECTED_PRODUCTION_SHA'],'hotel_ids':[hid],'manifest_sha256':hashlib.sha256(raw).hexdigest()}
 (folder/'update-manifest.json').write_bytes(raw);(folder/'update-approval.json').write_text(json.dumps(approval)+'\n')
 intent={'operation':manifest['operation'],'state':'EXACT_ONE_VERIFIED_QUOTE_TWO_EXISTING_EDITS_INTENT_BEFORE_PROVIDER','catalog_ids':[hid],'manifest_sha256':approval['manifest_sha256'],'expected_source':os.environ['EXPECTED_PRODUCTION_SHA'],'quote_response_sha256':manifest['quote_response_sha256'],'quote_epoch_utc':manifest['quote_epoch_utc'],'max_age_seconds':108000,'new_posts':0,'max_content_edits':2}
 (folder/'update-intent.json').write_text(json.dumps(intent)+'\n')
 source=(base/'update-commercial-price-20261104-remaining1.php').read_text()
 constants='\ndefine("EXPECTED_INSTALLED_SHA", "'+os.environ['EXPECTED_PRODUCTION_SHA']+'");\ndefine("APPROVED_MANIFEST_SHA256", "'+approval['manifest_sha256']+'");\ndefine("MANIFEST_BASE64", "'+base64.b64encode(raw).decode()+'");\ndefine("VERIFIED_QUOTE_RESPONSE_SHA256", "'+manifest['quote_response_sha256']+'");\ndefine("VERIFIED_QUOTE_EPOCH", '+str(manifest['quote_epoch_utc'])+');\ndefine("VERIFIED_QUOTE_INDEX", '+str(index)+');\ndefine("APPROVED_UPDATE_HOTEL_ID",'+str(hid)+');\ndefine("APPROVED_UPDATE_KEY_B64", "'+base64.b64encode(old['key'].encode()).decode()+'");\ndefine("APPROVED_UPDATE_LOGICAL_B64", "'+base64.b64encode(old['logical_id'].encode()).decode()+'");'
 constants+='\ndefine("APPROVED_UPDATE_OPERATION", "'+update_op+'");'
 assert source.count('declare(strict_types=1);')==1 and 'time()-VERIFIED_QUOTE_EPOCH<=108000' in source
 (folder/'update.php').write_text(source.replace('declare(strict_types=1);','declare(strict_types=1);'+constants,1))
 print(json.dumps({'operation':manifest['operation'],'catalog_id':hid,'catalog_name':chosen['catalog_name'],'valid_real_roundtrip_options':len(valid),'rejected_options':len(rejected),'selected_option_index':index,'verified_total_as_received':True,'separate_fuel_added':False,'existing_posts_to_edit':2,'new_posts':0,'new_binding_cases_passed':3,'old_age_and_updater_cases_rerun':0,'additional_gateway_calls':0}))
 return {'hotel_id':hid,'operation':update_op,'folder':str(folder.relative_to(root)),'quote_checked_epoch_utc':int(d['response_received_epoch_utc']),'catalog_name':chosen['catalog_name']}
index=json.loads((root/'index.json').read_text());assert {x['hotel_id'] for x in index}=={56092,32575,83105,250,132,235}
ready=[];closed=[]
for entry in index:
 folder=root/entry['folder'];d=json.loads((folder/'quote-result.json').read_text().splitlines()[-1])
 assert d['operation']==entry['quote_operation'] and d['ok'] and d['gateway_requests_attempted']==1
 assert d.get('quote_state')!='GATEWAY_ATTEMPT_OUTCOME_UNKNOWN_DO_NOT_REPEAT','UNKNOWN_ORIGINAL_MUST_RESOLVE_FIRST'
 q=d.get('gateway_response')
 if d.get('http_status')!=200 or not isinstance(q,dict) or q.get('error') or not isinstance(q.get('flights'),list) or not q['flights']:
  closed.append({'hotel_id':entry['hotel_id'],'state':'DECLARED_ERROR_OR_ZERO_FLIGHTS_NO_PRICE_EDIT','gateway_retries':0});continue
 try:ready.append(build(entry))
 except (AssertionError,KeyError,TypeError,ValueError,decimal.InvalidOperation) as exc:
  (folder/'preparation-rejection.private.json').write_text(json.dumps({'type':type(exc).__name__,'message':str(exc)})+'\n')
  closed.append({'hotel_id':entry['hotel_id'],'state':'FULL_BASKET_OR_ORIGINAL_CAPTION_CONTRACT_NOT_ACCEPTED_NO_EDIT','gateway_retries':0})
assert len({x['hotel_id'] for x in ready})==len(ready)
assert {x['hotel_id'] for x in ready}<={235},'ONLY_ACTUAL_NONERROR_FLIGHT_CONTEXT_CAN_REACH_EDIT'
assert not ({x['hotel_id'] for x in ready}&blocked)
assert len(ready)+len(closed)==len(index)
(root/'update-index.json').write_text(json.dumps(ready)+'\n')
(root/'closed-no-edit.json').write_text(json.dumps(closed)+'\n')
print(json.dumps({'operation':'commercial-price-update-20261104-remaining1','accepted_catalog_ids':[x['hotel_id'] for x in ready],'accepted_hotels':len(ready),'closed_no_edit':closed,'maximum_existing_edits':len(ready)*2,'new_posts':0,'gateway_calls':0,'new_input_cases_per_accepted_hotel':6,'new_pass_cases':3,'old_age_updater_cases_rerun':0}))
