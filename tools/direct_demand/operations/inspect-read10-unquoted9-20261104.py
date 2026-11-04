import collections,datetime,hashlib,json,os,pathlib,re
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
def one(folder,name):
 p=list((temp/folder).rglob(name));assert len(p)==1;return p[0]
d=json.loads(one('retained-read10','price-readback.json').read_text().splitlines()[-1]);assert d['operation']=='commercial-price-monitor-20261104-read10-72h-extension1' and d['max_age_seconds']==259200 and len(d['hotels'])==159
index=json.loads(one('original-quotes','index.json').read_text());quoted={int(x['hotel_id']) for x in index};assert len(quoted)==28
unselected=[h for h in d['hotels'] if h['observations'] and int(h['local_tv_id']) not in quoted];assert len(unselected)==9
selection_epoch=1793835481;now=int(__import__('time').time());result=[]
for h in unselected:
 hid=int(h['local_tv_id']);cat=h.get('catalog',{});reasons=collections.Counter();eligible_then=0;eligible_now=0
 for obs in h['observations']:
  why=[]
  if h['identity_provenance'] not in ('accepted_native_catalog_read','accepted_typed_bridge') or h.get('current_name_matches_expected') is not True:why.append('CURRENT_IDENTITY_OR_NAME_NOT_ADMITTED')
  if int(cat.get('id',0))!=hid or int(cat.get('is_active',0))!=1 or int(cat.get('country_id',0))!=int(h['expected_country_id']):why.append('NATIVE_COUNTRY_OR_ACTIVITY_NOT_ADMITTED')
  if obs.get('retained_source') not in ('scheduled_monitor','user_search','hot_tours'):why.append('UNAPPROVED_SOURCE')
  if not (obs['departure_date']>datetime.datetime.fromtimestamp(selection_epoch,datetime.timezone.utc).date().isoformat() and obs['departure_date']<='2027-02-02'):why.append('DEPARTURE_NOT_FUTURE_AT_ORIGINAL_SELECTION')
  if int(obs['adults'])!=2 or int(obs['children_count'])!=0 or obs['child_ages_signature']!='' or obs['currency']!='RUB':why.append('PARTY_OR_CURRENCY_NOT_ADMITTED')
  if not str(obs['room_type']).strip():why.append('ROOM_TYPE_MISSING')
  matches=[]
  for p in base.glob('commercial-batch-*-manifest.json'):
   text=p.read_text()
   if not re.search(r'"hotel_id"\s*:\s*'+str(hid)+r'\b',text):continue
   m=json.loads(text)
   for row in m.get('rows',[]):
    if int(row.get('hotel_id',0))==hid:matches.append((row,str(p)))
  unique={(r['logical_id'],r['text_sha256'],r['photo_sha256']) for r,p in matches}
  if len(unique)!=1:why.append('ORIGINAL_MANIFEST_NOT_UNIQUE' if matches else 'ORIGINAL_MANIFEST_NOT_FOUND_IN_ACCEPTED_MANIFEST_GLOB')
  else:
   row,path=matches[-1]
   if hashlib.sha256(row['text'].encode()).hexdigest()!=row['text_sha256']:why.append('ORIGINAL_MANIFEST_TEXT_HASH_MISMATCH')
   closing=row['text'].rstrip('\r\n').split('\n')[-1]
   if not re.search(r'стоимост|[Нн]ажмите|[Нн]апишите',closing):why.append('LEGACY_CLOSING_WORDING_FILTER_NOT_ADMITTED')
   if re.search(r'[-\u2010-\u2015\u2212]',row['text']):why.append('LEGACY_CAPTION_DASH_FILTER_NOT_ADMITTED')
  if not 0<=selection_epoch-int(obs['created_at_stored_epoch_utc'])<=259200:why.append('OUTSIDE72H_AT_ORIGINAL_SELECTION')
  for reason in set(why):reasons[reason]+=1
  if not why:
   eligible_then+=1
   if 0<=now-int(obs['created_at_stored_epoch_utc'])<=259200 and obs['departure_date']>datetime.datetime.now(datetime.timezone.utc).date().isoformat():eligible_now+=1
 result.append({'catalog_id':hid,'retained_rows':len(h['observations']),'reasons':dict(reasons),'eligible_by_original_rules_at_logged_selection':eligible_then,'eligible_by_original_rules_now':eligible_now})
report={'operation':'commercial-price-monitor-20261104-read10-unselected9-inspection1','state':'ORIGINAL_UNQUOTED_CONTEXTS_CLASSIFIED_WITHOUT_ANY_PROVIDER_CALL','source_gets':0,'db_calls':0,'gateway_calls':0,'edits':0,'original_selection_log_epoch':selection_epoch,'unquoted_hotels':len(result),'results':result}
(temp/'read10-unselected9-summary.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n');print(json.dumps(report,ensure_ascii=False))
