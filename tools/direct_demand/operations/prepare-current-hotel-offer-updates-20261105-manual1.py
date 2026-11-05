import base64,collections,copy,datetime,decimal,hashlib,json,os,pathlib,re,time,urllib.parse
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP']);root=temp/'price-manual1-pass1'
paths=list((temp/'original-quotes').rglob('index.json'));assert len(paths)==1
quoteRoot=paths[0].parent
index=json.loads(paths[0].read_text());allowed=set([240,381,268,46672,28666,235,110,132,111,262,237,174,178,273,408,416,342,267,371,280,32575,12112,4191,26044,435,9374,13870,79059,55574,78898,43204,37668,77914,69409,111801,115918,112914,108090,1348,1379,1583,1559,1453,1543,1582,1449,1199,111129,109900,111045,15869,74503,27316,3405]);assert {int(x['hotel_id']) for x in index}==allowed and len(index)==54
manifestRaw=(base/'commercial-price-source-refresh-20261105-pass1-manifest.json').read_bytes();assert hashlib.sha256(manifestRaw).hexdigest()=='2035f0382b815f721a42dfe4a0045f75b0e1db9f0d16891ed0db8ac1081b0361'
identityBindings=json.loads(manifestRaw)['native_bindings'];priced=set([12114,9432,109,13873,13869,73342,233,253,165,389,57028,278,260,163,281,293,245,393,465,264,107965,125,1451,1100,21477,246,310,469,498,1971,1981,9373]);assert not allowed&priced
template=(base/'update-current-hotel-offers-20261105-manual1.php').read_text();assert hashlib.sha256(template.encode()).hexdigest()=='cc3e04f24e93178ef1ffa773fa9636abe4e2220b0c189802b19adbbf72cad246'
root.mkdir(mode=0o700);ready=[];closed=[];priceIds=[];linkIds=[];linkOnly=[];rejected=[]
def one(folder,name):
 p=list(folder.rglob(name));assert len(p)==1,(str(folder),name);return p[0]
def parts(text,cta):
 body=text.rstrip('\r\n');suffix=text[len(body):];before,last=body.rsplit('\n',1);assert last==cta and cta;return before,last,suffix
def display(value):return re.sub(r'\s+',' ',re.sub(r'[-\u2010-\u2015\u2212]',' ',str(value))).strip()
def rub(v):
 x=format(v,',.2f').replace(',',' ').replace('.',',');return x[:-3] if x.endswith(',00') else x
def units(s):return len(s.encode('utf-16-le'))//2
def priceOption(d,t):
 q=d.get('gateway_response')
 if d.get('http_status')!=200 or not isinstance(q,dict) or q.get('error'):return None,'DECLARED_GATEWAY_ERROR_OR_HTTP_NOT_ACCEPTED'
 if q.get('info',{}).get('flags')!={'noTransfer':False,'noInsurance':False,'noFlight':False,'noMeal':False}:return None,'PACKAGE_INCLUSION_FLAGS_CONTRACT_NOT_ACCEPTED'
 if q['info'].get('surcharges') not in (None,[]):return None,'SURCHARGES_CONTRACT_NOT_ACCEPTED'
 start=datetime.date.fromisoformat(t['departure_date']);end=start+datetime.timedelta(days=int(t['nights']));valid=[];invalid=[]
 for i,f in enumerate(q.get('flights',[])):
  try:
   assert f['price']['currency']=='RUB';amount=decimal.Decimal(str(f['price']['value']));assert amount.is_finite() and 0<amount<decimal.Decimal('100000000') and amount*100==(amount*100).to_integral_value()
   assert f['dateForward']==start.strftime('%d.%m.%Y') and f['dateBackward']==end.strftime('%d.%m.%Y')
   assert isinstance(f['forward'],list) and isinstance(f['backward'],list) and 1<=len(f['forward'])<=4 and 1<=len(f['backward'])<=4
   for leg in f['forward']+f['backward']:
    assert leg.get('noPlaces') is False and leg.get('onDemand') is False
    number=re.sub(r'\s+','',str(leg.get('number',''))).upper();m=re.fullmatch(r'[A-Z0-9]{2}([0-9]{1,5})',number);assert m and int(m.group(1))>0 and number!='SU000'
   valid.append((amount,i,f))
  except (AssertionError,KeyError,TypeError,decimal.InvalidOperation):invalid.append(i)
 if not valid:return None,'NO_VERIFIED_REAL_ROUNDTRIP_OPTION'
 valid.sort(key=lambda x:(x[0],x[1]));return {'amount':valid[0][0],'index':valid[0][1],'valid_options':len(valid),'rejected_option_indexes':invalid},None
for entry in index:
 hid=int(entry['hotel_id']);folder=quoteRoot/entry['folder'];d=json.loads(one(folder,'quote-result.json').read_text().splitlines()[-1]);chosen=json.loads(one(folder,'candidate.json').read_text());frame=chosen['canonical_context'];b=frame['binding'];h=chosen['hotel'];t=d['retained_terms']
 assert d['operation']==entry['quote_operation']=='commercial-price-monitor-20261105-manual1-h'+str(hid)+'-v1' and d['ok'] and d['gateway_requests_attempted']==1 and d['db_writes']==d['message_sends']==d['price_block_updates']==0
 assert d.get('quote_state')!='GATEWAY_ATTEMPT_OUTCOME_UNKNOWN_DO_NOT_REPEAT','UNKNOWN_ORIGINAL_QUOTE_FIRST'
 assert d['installed_sha']=='8f209b1ac93090ab399c1b8225151849fceb4fd0' and d['current_wholecaption_photo_button_source_bound_before_gateway'] is True
 assert chosen['retained_source_run']==41978466494 and chosen['retained_source_artifact']==13393205782 and chosen['retained_source_installed_sha']=='627a9c6284624e63b24287bea61277ef6c8f5364'
 fr=json.dumps(frame,ensure_ascii=False,separators=(',',':')).encode();frameSha=hashlib.sha256(fr).hexdigest();assert frameSha==chosen['canonical_context_sha256']==d['canonical_context_sha256']
 assert int(h['local_tv_id'])==int(d['catalog_id'])==int(b['native_hotel_id'])==hid and int(h['expected_country_id'])==int(b['expected_country_id'])
 assert 0<=time.time()-int(d['response_received_epoch_utc'])<=259200
 assert int(t['departure_id'])==1 and (int(t['adults']),int(t['children']),t['currency'])==(2,0,'RUB') and 5<=int(t['nights'])<=14
 start=datetime.date.fromisoformat(t['departure_date']);assert start>datetime.datetime.now(datetime.timezone.utc).date() and '2026-11-06'<=start.isoformat()<='2026-11-12'
 n=int(t['nights']);country=int(h['expected_country_id'])
 query=[('days_from',str(n)),('days_till',str(n)),('from','1'),('count_people','2'),('country',str(country)),('hotel',str(hid)),('date_from',start.isoformat()),('date_till',start.isoformat())]
 search='https://anytour.online/poisk-turov/?'+urllib.parse.urlencode(query)
 rawold=b['caption'];assert hashlib.sha256(rawold.encode()).hexdigest()==b['caption_sha256'];cta=rawold.rstrip('\r\n').split('\n')[-1];assert re.search(r'стоимост|[Нн]ажмите|[Нн]апишите',cta)
 before,cta,trailing=parts(rawold,cta);fixture='Отель\n'+cta
 assert parts(fixture,cta)==('Отель',cta,'') and parts(fixture+'\n\n',cta)==('Отель',cta,'\n\n')
 try:parts('Отель\nДругой текст',cta)
 except AssertionError:pass
 else:raise AssertionError('foreign_original_closing_accepted')
 price,why=priceOption(d,t);priceblock=None;linkblock='🔎 Подобрать тур:\n'+search
 if price:
  end=start+datetime.timedelta(days=n);meal={2:'RO',3:'BB',4:'HB',5:'FB',7:'AI',9:'UAI'}[int(t['meal_id'])];assert meal==chosen['retained']['native_meal_label']
  room=display(t['room_type']);departure=display(chosen['retained']['departure_name']);assert room and departure
  mod=n%100;word='ночей' if 11<=mod<=14 else ('ночь' if n%10==1 else ('ночи' if n%10 in (2,3,4) else 'ночей'))
  stamp=datetime.datetime.fromtimestamp(int(d['response_received_epoch_utc']),datetime.timezone.utc).strftime('%d.%m.%Y %H:%M UTC')
  priceblock='✈️ Вылет: '+departure+'\n📅 '+start.strftime('%d.%m.%Y')+' по '+end.strftime('%d.%m.%Y')+', '+str(n)+' '+word+'\n👥 2 взрослых\n🛏 Номер: '+room+'\n🍽 Питание: '+meal+'\n💰 '+rub(price['amount'])+' ₽ за тур\n🕒 Проверено '+stamp
 candidates=[]
 if priceblock:candidates=[('price_and_search_link',priceblock+'\n\n'+linkblock,True),('price_only',priceblock,False)]
 candidates.append(('search_link_only',linkblock,True));fit=[(kind,added,included,before+'\n\n'+added+'\n\n'+cta+trailing) for kind,added,included in candidates if units(before+'\n\n'+added+'\n\n'+cta+trailing)<=1024]
 if not fit:closed.append({'hotel_id':hid,'state':'WHOLE_CURRENT_CAPTION_AND_LINK_EXCEED1024_NO_TRUNCATION_NO_EDIT'});continue
 kind,added,included,new=fit[0];hasPrice=kind!='search_link_only'
 if price and not hasPrice:why='WHOLE_CAPTION_PLUS_VERIFIED_PRICE_EXCEEDS1024_LINK_ONLY'
 if why:rejected.append({'hotel_id':hid,'price_not_added_reason':why})
 update_op='commercial-hotel-offer-update-20261105-manual1-h'+str(hid)+'-v1'
 row={'key':b['key'],'hotel_id':hid,'logical_id':b['logical_id'],'legacy_context_sha256':frameSha,'original_publication_hotel_id':b['publisher_hotel_id'],'old_text':rawold,'old_text_sha256':b['caption_sha256'],'new_text':new,'new_text_sha256':hashlib.sha256(new.encode()).hexdigest(),'expected_existing_photo_signatures':{p:b['contexts'][p]['photo_signature'] for p in ['max','telegram']},'update_text':True,'added_text':added,'search_link_url':search,'search_link_included':included}
 payload={'schema_version':1,'operation':update_op,'state':'PREPARED_NOT_SENT','button_text':'✈️ Узнать стоимость тура','update_kind':kind,'price_total_verified':hasPrice,'search_link_included':included,'search_filters':{'native_hotel_id':hid,'native_country_id':country,'departure_id':1,'adults':2,'children':0,'nights':n,'departure_date':start.isoformat()},'quote_origin_operation':d['operation'],'quote_response_sha256':d['response_sha256'],'quote_epoch_utc':int(d['response_received_epoch_utc']),'quote_index':price['index'] if hasPrice else -1,'quote_total_amount':str(price['amount']) if hasPrice else None,'quote_currency':'RUB' if hasPrice else None,'fuel_separately_added':False,'rows':[row]}
 def validate(saved,m):
  assert saved['operation']==entry['quote_operation'] and int(saved['catalog_id'])==hid and saved['ok'] and saved['gateway_requests_attempted']==1
  assert saved['response_sha256']==m['quote_response_sha256'] and int(saved['response_received_epoch_utc'])==m['quote_epoch_utc'] and 0<=time.time()-m['quote_epoch_utc']<=259200
  r=m['rows'][0];assert r['hotel_id']==hid and r['key']==b['key'] and r['logical_id']==b['logical_id'] and r['old_text']==rawold and r['old_text_sha256']==b['caption_sha256'] and r['legacy_context_sha256']==frameSha and r['original_publication_hotel_id']==b['publisher_hotel_id'] and r['expected_existing_photo_signatures']=={p:b['contexts'][p]['photo_signature'] for p in ['max','telegram']} and 'cover_sha256' not in r
  assert r['new_text']==before+'\n\n'+r['added_text']+'\n\n'+cta+trailing and units(r['new_text'])<=1024 and hashlib.sha256(r['new_text'].encode()).hexdigest()==r['new_text_sha256']
  assert not re.search(r'[-\u2010-\u2015\u2212]',re.sub(r'https://[^\s]+','',r['added_text']))
  u=urllib.parse.urlsplit(r['search_link_url']);q=urllib.parse.parse_qs(u.query,keep_blank_values=True);expected={k:[v] for k,v in query}
  assert u.scheme=='https' and u.netloc=='anytour.online' and u.path=='/poisk-turov/' and not u.fragment and q==expected
  if m['price_total_verified']:
   assert m['update_kind'] in ('price_and_search_link','price_only') and m['quote_currency']=='RUB' and decimal.Decimal(str(saved['gateway_response']['flights'][m['quote_index']]['price']['value']))==decimal.Decimal(m['quote_total_amount']) and m['fuel_separately_added'] is False
  else:assert m['update_kind']=='search_link_only' and m['quote_index']==-1 and m['quote_total_amount'] is None and '💰' not in r['added_text']
 validate(d,payload)
 for label in ['native','logical','photo','url_native','url_country','url_date']:
  badD=copy.deepcopy(d);badM=copy.deepcopy(payload)
  if label=='native':badD['catalog_id']=1422
  elif label=='logical':badM['rows'][0]['logical_id']=b['logical_id']+':foreign'
  elif label=='photo':badM['rows'][0]['expected_existing_photo_signatures']['max']='foreign'
  else:
   r=badM['rows'][0];u=urllib.parse.urlsplit(r['search_link_url']);q=dict(urllib.parse.parse_qsl(u.query));q[{'url_native':'hotel','url_country':'country','url_date':'date_from'}[label]]={'url_native':'1422','url_country':'99','url_date':'2026-11-05'}[label];r['search_link_url']=urllib.parse.urlunsplit((u.scheme,u.netloc,u.path,urllib.parse.urlencode(q),''))
  try:validate(badD,badM)
  except AssertionError:pass
  else:raise AssertionError('new_exact_offer_binding_case_accepted:'+label)
 target=root/str(hid);target.mkdir(mode=0o700);raw=(json.dumps(payload,ensure_ascii=False,separators=(',',':'))+'\n').encode();manifestSHA=hashlib.sha256(raw).hexdigest()
 approval={'schema_version':1,'operation':update_op,'expected_installed_sha':os.environ['EXPECTED_PRODUCTION_SHA'],'hotel_ids':[hid],'manifest_sha256':manifestSHA}
 (target/'update-manifest.json').write_bytes(raw);(target/'update-approval.json').write_text(json.dumps(approval)+'\n')
 (target/'candidate.json').write_text(json.dumps(chosen,ensure_ascii=False)+'\n')
 constants={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_MANIFEST_SHA256':manifestSHA,'MANIFEST_BASE64':base64.b64encode(raw).decode(),'VERIFIED_QUOTE_RESPONSE_SHA256':d['response_sha256'],'VERIFIED_QUOTE_EPOCH':int(d['response_received_epoch_utc']),'VERIFIED_QUOTE_INDEX':payload['quote_index'],'APPROVED_UPDATE_HOTEL_ID':hid,'APPROVED_UPDATE_KEY_B64':base64.b64encode(b['key'].encode()).decode(),'APPROVED_UPDATE_LOGICAL_B64':base64.b64encode(b['logical_id'].encode()).decode(),'APPROVED_UPDATE_OPERATION':update_op,'APPROVED_LEGACY_CONTEXT_B64':base64.b64encode(fr).decode(),'APPROVED_LEGACY_CONTEXT_SHA256':frameSha,'APPROVED_IDENTITY_BINDINGS_B64':base64.b64encode(json.dumps(identityBindings,ensure_ascii=False,separators=(',',':')).encode()).decode(),'APPROVED_SEARCH_URL_B64':base64.b64encode(search.encode()).decode()}
 prefix="<?php\ndeclare(strict_types=1);\n";assert template.startswith(prefix);header=prefix+''.join('const '+k+'='+json.dumps(v,ensure_ascii=False)+';\n' for k,v in constants.items());(target/'update.php').write_text(header+template[len(prefix):])
 intent={'operation':update_op,'state':'CURRENT_BODY_PRICE_OR_SEARCH_LINK_TWO_EXISTING_EDITS_INTENT_BEFORE_PROVIDER','catalog_ids':[hid],'manifest_sha256':manifestSHA,'expected_source':os.environ['EXPECTED_PRODUCTION_SHA'],'original_quote_run':41980943154,'quote_response_sha256':d['response_sha256'],'quote_epoch_utc':payload['quote_epoch_utc'],'update_kind':kind,'max_content_edits':2,'new_posts':0,'additional_gateway_calls':0}
 (target/'update-intent.json').write_text(json.dumps(intent)+'\n')
 record={'hotel_id':hid,'operation':update_op,'folder':str(hid),'update_kind':kind,'price_total_verified':hasPrice,'search_link_included':included,'quote_checked_epoch_utc':payload['quote_epoch_utc'],'manifest_sha256':manifestSHA,'source_template_sha256':'cc3e04f24e93178ef1ffa773fa9636abe4e2220b0c189802b19adbbf72cad246'}
 ready.append(record)
 if hasPrice:priceIds.append(hid)
 if included:linkIds.append(hid)
 if kind=='search_link_only':linkOnly.append(hid)
 print(json.dumps({'operation':update_op,'catalog_id':hid,'update_kind':kind,'verified_total_as_received':hasPrice,'fuel_separately_added':False,'search_link_included':included,'whole_original_caption_closing_photo_button_preserved':True,'utf16_units':units(new),'new_python_binding_and_closing_cases':9,'existing_posts_to_edit':2,'additional_gateway_calls':0,'old_fixtures_rerun':0}),flush=True)
assert len(ready)+len(closed)==54 and len({x['hotel_id'] for x in ready})==len(ready)
(root/'update-index.json').write_text(json.dumps(ready)+'\n');(root/'closed-no-edit.json').write_text(json.dumps(closed)+'\n')
intents=[json.loads((root/x['folder']/'update-intent.json').read_text()) for x in ready];(root/'all-update-intents.json').write_text(json.dumps(intents)+'\n')
summary={'operation':'commercial-hotel-offer-update-20261105-manual1-pass1','prepared_hotels':len(ready),'prepared_price_hotel_ids':priceIds,'prepared_price_hotels':len(priceIds),'prepared_search_link_hotel_ids':linkIds,'prepared_search_link_hotels':len(linkIds),'prepared_link_only_hotel_ids':linkOnly,'prepared_link_only_hotels':len(linkOnly),'price_not_admitted_reasons':rejected,'closed_no_edit':closed,'max_existing_content_edits':2*len(ready),'new_posts':0,'provider_calls':0,'new_python_cases_per_hotel':9,'new_native_php_cases_per_hotel_prepared':3,'old_fixtures_rerun':0,'template_sha256':'cc3e04f24e93178ef1ffa773fa9636abe4e2220b0c189802b19adbbf72cad246'}
(root/'prepare-summary.json').write_text(json.dumps(summary)+'\n');print(json.dumps(summary))
