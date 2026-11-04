import copy,datetime,hashlib,json,os,pathlib,re,time
root=pathlib.Path(os.environ['QUOTE2_PATH']);paths=list(root.rglob('channel-preflight.json'));assert len(paths)==1
q=json.loads(paths[0].read_text().splitlines()[-1]);assert q['operation']=='commercial-price-monitor-20261104-quote2' and q['ok'] and q['catalog_id']==9432 and q['gateway_requests_attempted']==1
assert q['response_sha256']=='135bb236f2c90750fcaa20a4021a03107cc1c4193a33f5aa2b7d804f4547cda9'
assert 0<=int(time.time())-q['response_received_epoch_utc']<=108000
g=q['gateway_response'];terms=q['retained_terms'];assert g['error'] is None and isinstance(g['flights'],list)
def date(s):
 s=str(s)
 for f in ['%Y-%m-%d','%d.%m.%Y','%d/%m/%Y']:
  try:return datetime.datetime.strptime(s[:10],f).date()
  except ValueError:pass
 raise ValueError('unrecognized_flight_date')
def segment_ok(s):
 n=re.sub(r'\s+','',str(s.get('number','')))
 return bool(re.fullmatch(r'[A-Z0-9]{2,3}[1-9][0-9]{1,4}',n)) and not n.endswith('000') and s.get('noPlaces') is False and s.get('onDemand') is False
def option_reasons(f):
 reasons=[]
 legs=f.get('forward',[])+f.get('backward',[])
 if not f.get('forward') or not f.get('backward') or not all(segment_ok(s) for s in legs):reasons.append('concrete_available_flights_not_verified')
 if not isinstance(f.get('price'),dict) or f['price'].get('currency')!='RUB' or not isinstance(f['price'].get('value'),int) or f['price']['value']<=0:reasons.append('final_rub_basket_not_verified')
 try:
  if date(f['dateForward'])!=date(terms['departure_date']) or (date(f['dateBackward'])-date(f['dateForward'])).days!=int(terms['nights']):reasons.append('retained_dates_nights_mismatch')
 except (KeyError,ValueError,TypeError):reasons.append('dates_unusable')
 return reasons
assert not segment_ok({'number':'SU000','noPlaces':False,'onDemand':False})
assert segment_ok({'number':'SU2125','noPlaces':False,'onDemand':False})
assert not segment_ok({'number':'SU2125','noPlaces':False,'onDemand':True})
flags=g['info']['flags'];assert all(isinstance(flags[k],bool) for k in ['noFlight','noInsurance','noMeal','noTransfer'])
meals={2:'RO',3:'BB',4:'HB',5:'FB',7:'AI',9:'UAI'}
global_reasons=[]
if flags['noFlight']:global_reasons.append('package_excludes_flight')
if int(terms['meal_id']) not in meals:global_reasons.append('unknown_native_meal')
if int(terms['meal_id']) in [3,4,5,7,9] and flags['noMeal']:global_reasons.append('package_meal_conflict')
ranked=[]
for i,f in enumerate(g['flights']):
 reasons=global_reasons+option_reasons(f)
 if not reasons:ranked.append((not bool(f.get('isDefault')),i,f))
summary={'operation':'commercial-price-monitor-20261104-quote2-contract-inspection2','catalog_id':9432,'quote_option_count':len(g['flights']),'eligible_concrete_option_count':len(ranked),'new_native_flight_cases':3,'provider_calls':0,'server_connections':0,'message_edits':0,'response_sha256':q['response_sha256'],'response_epoch_utc':q['response_received_epoch_utc']}
temp=pathlib.Path(os.environ['RUNNER_TEMP'])
if ranked:
 _,idx,f=sorted(ranked,key=lambda x:(x[0],x[1]))[0]
 manifest=json.loads(pathlib.Path('tools/direct_demand/operations/commercial-batch-20261103-32-manifest.json').read_text())
 original=[r for r in manifest['rows'] if int(r['hotel_id'])==9432];assert len(original)==1
 r=original[0];assert r['key']=='delphin-imperial-lara' and hashlib.sha256(r['text'].encode()).hexdigest()==r['text_sha256']
 room=re.sub(r'[-\u2010-\u2015\u2212]',' ',str(terms['room_type'])).strip();assert room and len(room)<200
 start=date(f['dateForward']);end=date(f['dateBackward'])
 flights=[str(s['number']) for s in f['forward']+f['backward']]
 port=str(f['forward'][0]['departure']['port'].get('shortName') or f['forward'][0]['departure']['port']['name'])
 assert port and len(port)<120 and not re.search(r'[-\u2010-\u2015\u2212]',port)
 amount=f['price']['value'];stamp=datetime.datetime.fromtimestamp(q['response_received_epoch_utc'],datetime.timezone.utc).strftime('%d.%m.%Y %H:%M UTC')
 block='💰 '+format(amount,',').replace(',',' ')+' ₽ за двоих\n✈️ Вылет из аэропорта '+port+'\n📅 '+start.strftime('%d.%m.%Y')+' по '+end.strftime('%d.%m.%Y')+', '+str(terms['nights'])+' ночей\n👥 2 взрослых\n🛏 '+room+'\n🍽 '+meals[int(terms['meal_id'])]+'\n🛫 Рейсы '+', '.join(flights)+'\n🕒 Стоимость проверена '+stamp
 if flags['noTransfer']:block+='\nТрансфер не включён.'
 if flags['noInsurance']:block+='\nСтраховка не включена.'
 before,cta=r['text'].rsplit('\n',1);new=before+'\n\n'+block+'\n\n'+cta
 assert len(new.encode('utf-16-le'))//2<=1024 and not re.search(r'[-\u2010-\u2015\u2212]',new)
 row={'key':r['key'],'hotel_id':9432,'logical_id':r['logical_id'],'old_text':r['text'],'old_text_sha256':r['text_sha256'],'new_text':new,'new_text_sha256':hashlib.sha256(new.encode()).hexdigest(),'cover_sha256':r['photo_sha256'],'original_publication_operation':'commercial-hotel-batch-20261103-32-v1'}
 update={'operation':'commercial-price-update-20261104-2-v1','quote_response_sha256':q['response_sha256'],'quote_epoch_utc':q['response_received_epoch_utc'],'quote_index':idx,'rows':[row]}
 (temp/'quote2-update-manifest.json').write_text(json.dumps(update,ensure_ascii=False,separators=(',',':'))+'\n')
 summary.update({'state':'ONE_CONCRETE_QUOTE_READY_FOR_EXISTING_POST_EDIT','selected_quote_index':idx,'final_price_rub':amount,'separate_fuel_charge_not_added':True,'price_block':block,'text_utf16':len(new.encode('utf-16-le'))//2})
else:
 summary.update({'state':'NO_PUBLISHABLE_CONCRETE_FLIGHT_OPTION','rejection_reasons':[option_reasons(f)+global_reasons for f in g['flights']]})
(temp/'quote2-contract-review.json').write_text(json.dumps(summary,ensure_ascii=False)+'\n')
print(json.dumps(summary,ensure_ascii=False,sort_keys=True))
