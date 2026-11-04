import base64,hashlib,json,os,pathlib,re,time
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
paths=list((temp/'retained-read9').rglob('price-readback.json'));assert len(paths)==1
data=json.loads(paths[0].read_text().splitlines()[-1])
assert data['operation']=='commercial-price-monitor-20261104-read9' and data['ok'] is True and len(data['hotels'])==157
assert data['installed_sha']==os.environ['EXPECTED_PRODUCTION_SHA'] and data['max_age_seconds']==108000
blocked={12114,9432,482,476,81173,32577,478,80964}
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
  pool.append({'hotel':{k:hotel[k] for k in ['local_tv_id','expected_name','expected_country_id','identity_provenance']},'catalog_name':cat['name'],'retained':obs,'original_manifest_row':row,'original_publication_operation':m['operation'],'original_manifest_path':path})
assert pool,'NO_NEW_EXACT_RETAINED_CANDIDATE_WITH_UNIQUE_ORIGINAL_MANIFEST'
pool.sort(key=lambda x:(-int(x['retained']['created_at_stored_epoch_utc']),float(x['retained']['raw_observed_price']),int(x['hotel']['local_tv_id'])))
chosen=pool[0];hid=int(chosen['hotel']['local_tv_id']);approved=dict(chosen['retained']);approved['hotel_id']=hid
raw=(json.dumps(approved,ensure_ascii=False,separators=(',',':'))+'\n').encode()
source=(base/'check-commercial-price-20261104-quote3.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert source.startswith(prefix)
header=prefix+"const EXPECTED_INSTALLED_SHA='"+os.environ['EXPECTED_PRODUCTION_SHA']+"';\n"+"const APPROVED_CANDIDATE_B64='"+base64.b64encode(raw).decode()+"';\n"+"const APPROVED_CANDIDATE_SHA256='"+hashlib.sha256(raw).hexdigest()+"';\n"+"const APPROVED_HOTEL_ID="+str(hid)+";\n"+"const APPROVED_COUNTRY_ID="+str(int(chosen['hotel']['expected_country_id']))+";\n"+"const APPROVED_HOTEL_NAME_B64='"+base64.b64encode(chosen['catalog_name'].strip().encode()).decode()+"';\n"
(temp/'channel-preflight.php').write_text(header+source[len(prefix):])
(temp/'quote3-candidate.json').write_text(json.dumps(chosen,ensure_ascii=False)+'\n')
intent={'operation':'commercial-price-monitor-20261104-quote3','state':'EXACT_ONE_JUSTIFIED_QUOTE_INTENT_BEFORE_PROVIDER','catalog_id':hid,'expected_source':os.environ['EXPECTED_PRODUCTION_SHA'],'approved_candidate_sha256':hashlib.sha256(raw).hexdigest(),'retained_artifact_run':41898354901,'max_age_seconds':108000,'max_gateway_requests':1,'automatic_retry_allowed':False,'excluded_consumed_hotel_ids':[12114,9432]}
(temp/'quote-intent.json').write_text(json.dumps(intent)+'\n')
print(json.dumps({'operation':intent['operation'],'selected_catalog_id':hid,'new_eligible_contexts':len(pool),'max_gateway_requests':1,'provider_calls_so_far':0}))
