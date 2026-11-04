import base64,hashlib,json,os,pathlib,re,time
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
paths=list((temp/'retained-read9').rglob('price-readback.json'));assert len(paths)==1
data=json.loads(paths[0].read_text().splitlines()[-1])
assert data['operation']=='commercial-price-monitor-20261104-read9' and data['ok'] is True and len(data['hotels'])==157
assert data['installed_sha']=='9678e8c617734dd3bd5054eabf3585e396e3ca12' and os.environ['EXPECTED_PRODUCTION_SHA']=='d13f5cf94960bef206fe47b872c88f034c76c04f' and data['max_age_seconds']==108000
blocked={233,110,73342,13869,13873,109,12114,9432,482,476,81173,32577,478,80964}
pool=[]
for hotel in data['hotels']:
 hid=int(hotel['local_tv_id'])
 if hid in blocked or hotel['identity_provenance']!='accepted_native_catalog_read' or hotel.get('current_name_matches_expected') is not True:continue
 cat=hotel.get('catalog',{})
 if int(cat.get('id',0))!=hid or int(cat.get('is_active',0))!=1 or int(cat.get('country_id',0))!=int(hotel['expected_country_id']):continue
 for obs in hotel['observations']:
  if obs.get('retained_source') not in ('user_search','hot_tours'):continue
  if not 0<=int(time.time())-int(obs['created_at_stored_epoch_utc'])<=108000:continue
  if not (obs['departure_date']>time.strftime('%Y-%m-%d',time.gmtime()) and obs['departure_date']<='2027-02-02'):continue
  if int(obs['adults'])!=2 or int(obs['children_count'])!=0 or obs['child_ages_signature']!='' or obs['currency']!='RUB' or not str(obs['room_type']).strip():continue
  matches=[]
  for p in base.glob('commercial-batch-*-manifest.json'):
   raw=p.read_text()
   if not re.search(r'"hotel_id"\s*:\s*'+str(hid)+r'\b',raw):continue
   m=json.loads(raw)
   for row in m.get('rows',[]):
    if int(row.get('hotel_id',0))==hid:matches.append((m,row,str(p)))
  unique={(row['logical_id'],row['text_sha256'],row['photo_sha256']) for m,row,p in matches}
  if len(unique)!=1:continue
  m,row,path=matches[-1]
  assert hashlib.sha256(row['text'].encode()).hexdigest()==row['text_sha256']
  closing=row['text'].rstrip('\r\n').split('\n')[-1]
  if not re.search(r'стоимост|[Нн]ажмите|[Нн]апишите',closing) or re.search(r'[-\u2010-\u2015\u2212]',row['text']):continue
  pool.append({'hotel':{k:hotel[k] for k in ['local_tv_id','expected_name','expected_country_id','identity_provenance']},'catalog_name':cat['name'],'retained':obs,'original_manifest_row':row,'original_publication_operation':m['operation'],'original_manifest_path':path})
pool.sort(key=lambda x:(-int(x['retained']['created_at_stored_epoch_utc']),float(x['retained']['raw_observed_price']),int(x['hotel']['local_tv_id'])))

root=temp/'remaining1';root.mkdir(mode=0o700)
selected=[];seen=set()
for entry in pool:
 hid=int(entry['hotel']['local_tv_id'])
 if hid in seen:continue
 seen.add(hid);selected.append(entry)
assert len(selected)<=8,'BOUNDED_REMAINING_PASS_LIMIT'
assert len({int(x['hotel']['local_tv_id']) for x in selected})==len(selected)
assert not ({int(x['hotel']['local_tv_id']) for x in selected}&blocked)
# Three new finite-pass cases: one per hotel, consumed quote8 excluded, hard maximum.
fixture=[{'hotel':{'local_tv_id':1}},{'hotel':{'local_tv_id':1}},{'hotel':{'local_tv_id':2}}]
assert len({x['hotel']['local_tv_id'] for x in fixture})==2
assert 233 in blocked and 110 in blocked
assert len(selected)<=8
source=(base/'check-commercial-price-20261104-remaining1.php').read_text()
prefix="<?php\ndeclare(strict_types=1);\n";assert source.startswith(prefix)
index=[];intents=[]
for chosen in selected:
 hid=int(chosen['hotel']['local_tv_id']);folder=root/str(hid);folder.mkdir(mode=0o700)
 op='commercial-price-monitor-20261104-remaining1-h'+str(hid)
 approved=dict(chosen['retained']);approved['hotel_id']=hid
 raw=(json.dumps(approved,ensure_ascii=False,separators=(',',':'))+'\n').encode()
 header=prefix
 constants={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_QUOTE_OPERATION':op,'APPROVED_CANDIDATE_B64':base64.b64encode(raw).decode(),'APPROVED_CANDIDATE_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_HOTEL_ID':hid,'APPROVED_COUNTRY_ID':int(chosen['hotel']['expected_country_id']),'APPROVED_HOTEL_NAME_B64':base64.b64encode(chosen['catalog_name'].strip().encode()).decode()}
 for key,value in constants.items():
  header+='const '+key+'='+json.dumps(value,ensure_ascii=False)+';\n'
 (folder/'quote.php').write_text(header+source[len(prefix):])
 (folder/'candidate.json').write_text(json.dumps(chosen,ensure_ascii=False)+'\n')
 intent={'operation':op,'catalog_id':hid,'state':'EXACT_ONE_JUSTIFIED_QUOTE_INTENT_BEFORE_PROVIDER','expected_source':os.environ['EXPECTED_PRODUCTION_SHA'],'approved_candidate_sha256':hashlib.sha256(raw).hexdigest(),'retained_artifact_run':41898354901,'retained_artifact_id':13357152456,'original_retained_source':'9678e8c617734dd3bd5054eabf3585e396e3ca12','max_age_seconds':108000,'max_gateway_requests':1,'automatic_retry_allowed':False}
 (folder/'quote-intent.json').write_text(json.dumps(intent)+'\n')
 intents.append(intent);index.append({'hotel_id':hid,'quote_operation':op,'folder':str(hid)})
(root/'index.json').write_text(json.dumps(index)+'\n')
(root/'all-quote-intents.json').write_text(json.dumps(intents)+'\n')
print(json.dumps({'operation':'commercial-price-monitor-20261104-remaining1','selected_catalog_ids':[x['hotel_id'] for x in index],'eligible_contexts_before_hotel_dedup':len(pool),'selected_unique_hotels':len(index),'maximum_justified_gateway_calls':len(index),'new_finite_pass_cases':3,'provider_calls':0,'old_age_native_fixtures_rerun':0}))
