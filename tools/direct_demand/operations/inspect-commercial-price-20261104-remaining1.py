import datetime,decimal,json,os,pathlib,re,subprocess
temp=pathlib.Path(os.environ['RUNNER_TEMP']);root=temp/'remaining1'
folder=root/'235';d=json.loads((folder/'quote-result.json').read_text().splitlines()[-1]);c=json.loads((folder/'candidate.json').read_text());q=d.get('gateway_response',{});t=d.get('retained_terms',{})
assert d['operation']=='commercial-price-monitor-20261104-remaining1-h235' and d['ok'] and d['gateway_requests_attempted']==1
assert int(c['hotel']['local_tv_id'])==235
start=datetime.date.fromisoformat(t['departure_date']);end=start+datetime.timedelta(days=int(t['nights']))
report={'operation':'commercial-price-monitor-20261104-remaining1-inspection2','hotel_id':235,'catalog_name':c['catalog_name'],'gateway_calls':0,'db_calls':0,'edits':0,'gateway_declared_error':bool(q.get('error')),'surcharge_present':q.get('info',{}).get('surcharges') not in (None,[]),'inclusion_flags_exact':q.get('info',{}).get('flags')=={'noTransfer':False,'noInsurance':False,'noFlight':False,'noMeal':False},'options':[]}
for index,f in enumerate(q.get('flights',[])):
 forward=f.get('forward');back=f.get('backward')
 legs=(forward if isinstance(forward,list) else [])+(back if isinstance(back,list) else [])
 numbers=[re.sub(r'\s+','',str(leg.get('number',''))).upper() for leg in legs]
 option={'index':index,'currency_rub':f.get('price',{}).get('currency')=='RUB','dates_match_retained':f.get('dateForward')==start.strftime('%d.%m.%Y') and f.get('dateBackward')==end.strftime('%d.%m.%Y'),'forward_segment_count':len(forward) if isinstance(forward,list) else -1,'backward_segment_count':len(back) if isinstance(back,list) else -1,'noPlaces_false_for_all':all(x.get('noPlaces') is False for x in legs),'onDemand_false_for_all':all(x.get('onDemand') is False for x in legs),'nonplaceholder_numbers':all(re.fullmatch(r'[A-Z0-9]{2}([0-9]{1,5})',x) and int(re.fullmatch(r'[A-Z0-9]{2}([0-9]{1,5})',x).group(1))>0 and x!='SU000' for x in numbers)}
 report['options'].append(option)
raw=c['original_manifest_row']['text'];report['original_caption_utf16_length']=len(raw.encode('utf-16-le'))//2
# Inspection only; no full tuple, amount, flight number, private error string or publication destination is printed.
report['real_number_check_corrected_whitespace_normalization']=True
print(json.dumps(report,ensure_ascii=False));(temp/'price-focus-inspection.json').write_text(json.dumps(report,ensure_ascii=False)+'\n')
