import datetime,decimal,json,os,pathlib,re
temp=pathlib.Path(os.environ['RUNNER_TEMP'])
def one(root,name):
 p=list((temp/root).rglob(name));assert len(p)==1,(root,name);return p[0]
qroot=temp/'legacy-quotes';paths=list(qroot.rglob('381/quote-result.json'));assert len(paths)==1
d=json.loads(paths[0].read_text().splitlines()[-1]);chosen=json.loads(paths[0].with_name('candidate.json').read_text())
assert d['operation']=='commercial-price-monitor-20261105-legacy9-pass1-h381' and d['ok'] and d['gateway_requests_attempted']==1 and d['http_status']==200 and d['installed_sha']=='25a2d5e31aec7243b45c47e836a544e659e28b1d'
rej=list((temp/'legacy-updates').rglob('381/preparation-rejection.private.json'));assert len(rej)==1;rejection=json.loads(rej[0].read_text())
q=d['gateway_response'];terms=d['retained_terms'];start=datetime.date.fromisoformat(terms['departure_date']);end=start+datetime.timedelta(days=int(terms['nights']))
opts=[]
for f in q.get('flights',[]):
 number_ok=[];place_ok=[];demand_ok=[]
 for leg in f.get('forward',[])+f.get('backward',[]):
  number=re.sub(r'\s+','',str(leg.get('number',''))).upper();m=re.fullmatch(r'[A-Z0-9]{2}([0-9]{1,5})',number)
  number_ok.append(bool(m and int(m.group(1))>0 and number!='SU000'));place_ok.append(leg.get('noPlaces') is False);demand_ok.append(leg.get('onDemand') is False)
 opts.append({'dates_match':f.get('dateForward')==start.strftime('%d.%m.%Y') and f.get('dateBackward')==end.strftime('%d.%m.%Y'),'real_flight_numbers':bool(number_ok) and all(number_ok),'no_places_all_false':bool(place_ok) and all(place_ok),'on_demand_all_false':bool(demand_ok) and all(demand_ok),'RUB':f.get('price',{}).get('currency')=='RUB'})
body=chosen['canonical_context']['binding']['caption'];info=q.get('info',{})
summary={'operation':'commercial-price-legacy381-20261105-contract-inspection1','state':'CLOSED_ORIGINAL_QUOTE_ONLY_NO_PROVIDER_RETRY','native_hotel_id':381,'original_quote_run':41944924563,'original_update_run':41945166136,'gateway_declared_error':bool(q.get('error')),'all_inclusion_flags_false':info.get('flags')=={'noTransfer':False,'noInsurance':False,'noFlight':False,'noMeal':False},'surcharges_empty':info.get('surcharges') in (None,[]),'retained_options':len(opts),'option_contract_flags':opts,'current_caption_utf16_units':len(body.encode('utf-16-le'))//2,'preparation_rejection_type':rejection['type'],'private_rejection_message_present':bool(rejection.get('message')),'provider_calls':0,'db_calls':0,'price_edits':0}
(temp/'legacy381-contract-summary.json').write_text(json.dumps(summary)+'\n');print(json.dumps(summary))
