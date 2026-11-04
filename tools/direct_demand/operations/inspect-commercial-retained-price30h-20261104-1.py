import datetime,json,os,pathlib,re,time
MAX_AGE=108000
def age_ok(age): return 0<=age<=MAX_AGE
assert age_ok(108000) and not age_ok(108001) and not age_ok(-1)
print('PRICE30H_BOUNDARY_OK new_cases=3 at30h=accepted after30h=rejected future_timestamp=rejected server_calls=0')
paths=list(pathlib.Path(os.environ['RETAINED_PATH']).rglob('channel-preflight.json'));assert len(paths)==1
d=json.loads(paths[0].read_text().splitlines()[-1]);assert d['ok'] and d['operation']=='commercial-price-monitor-20261104-read6'
assert d['mode']=='read_existing_monitored_price_inputs' and len(d['hotels'])==146
assert d['db_writes']==d['provider_calls']==d['message_sends']==d['price_block_updates']==0
now=int(time.time());today=datetime.datetime.now(datetime.timezone.utc).date().isoformat();eligible=[];review=[]
for h in d['hotels']:
 assert len(h['observations'])<=2
 if not h.get('current_name_matches_expected',False): review.append(h['local_tv_id']);continue
 catalog=h.get('catalog') or {}
 if int(catalog.get('id',0))!=h['local_tv_id'] or int(catalog.get('is_active',0))!=1 or int(catalog.get('country_id',0))!=h['expected_country_id']: continue
 for r in h['observations']:
  age=now-int(r.get('created_at_stored_epoch_utc') or 0)
  if not age_ok(age): continue
  if not re.fullmatch('[a-fA-F0-9]{64}',r.get('retained_tour_fingerprint') or ''): continue
  if not (int(r['adults'])==2 and int(r['children_count'])==0 and r['child_ages_signature']=='' and r['currency']=='RUB' and float(r['raw_observed_price'])>0 and int(r['nights'])>0 and int(r['departure_id'])>0): continue
  if not (today<=r['departure_date']<='2027-02-02' and str(r.get('room_type') or '').strip() and r.get('native_meal_label')):continue
  eligible.append({'hotel':{k:h[k] for k in ['local_tv_id','expected_name','identity_provenance','expected_country_id','identity_state','current_name_matches_expected']},'catalog':catalog,'retained':r,'age_seconds_at_inspection':age})
eligible.sort(key=lambda x:(x['age_seconds_at_inspection'],0 if int(x['retained']['nights'])==7 else 1,x['hotel']['local_tv_id']))
private={'operation':'commercial-price-monitor-20261104-retained30h1','original_run':41786947561,'max_age_seconds':MAX_AGE,'checked_epoch_utc':now,'eligible':eligible,'selected':eligible[:1],'full_total_verified':False,'current_availability_verified':False}
pathlib.Path(os.environ['RUNNER_TEMP'],'quote-candidate-input.json').write_text(json.dumps(private,ensure_ascii=False,indent=2)+'\n')
safe={'operation':private['operation'],'original_run':41786947561,'scope_hotels':146,'retained_rows':sum(len(h['observations']) for h in d['hotels']),'eligible_rows_within30h':len(eligible),'eligible_catalog_ids':sorted(set(x['hotel']['local_tv_id'] for x in eligible)),'selected_catalog_ids':[x['hotel']['local_tv_id'] for x in eligible[:1]],'checked_epoch_utc':now,'max_age_hours':30,'server_connections':0,'provider_calls':0,'db_writes':0,'message_edits':0}
print(json.dumps(safe,sort_keys=True))
pathlib.Path(os.environ['RUNNER_TEMP'],'retained30h-summary.json').write_text(json.dumps(safe,sort_keys=True)+'\n')
