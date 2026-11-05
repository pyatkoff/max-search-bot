import base64,collections,datetime,decimal,hashlib,json,os,pathlib,re,time
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
def one(folder,name):
 p=list((temp/folder).rglob(name));assert len(p)==1,(folder,name);return p[0]
inputs=[('context-read1','commercial-price-legacy9-contexts-20261105-read1',41941025252,{310,469,381,1971},0),('context-read2','commercial-price-legacy9-contexts-20261105-read2',41941689331,{240,1981},0),('context-read4','commercial-price-legacy9-contexts-20261105-read4',41943048307,{46672,498,9373},3)]
frames=[]
for folder,op,run,ids,reads in inputs:
 d=json.loads(one(folder,'legacy9-readback.json').read_text().splitlines()[-1])
 assert d['operation']==op and d['ok'] and d['installed_sha']=='25a2d5e31aec7243b45c47e836a544e659e28b1d'
 assert d['db_writes']==d['gateway_calls']==d['message_sends']==d['price_edits']==0 and d['provider_calls']==reads
 found=[b for b in d['bindings'] if int(b['native_hotel_id']) in ids];assert {int(b['native_hotel_id']) for b in found}==ids
 for b in found:
  assert set(b['contexts'])=={'max','telegram'} and hashlib.sha256(b['caption'].encode()).hexdigest()==b['caption_sha256']
  assert all(c['old_text']==b['caption'] and c['old_text_sha256']==b['caption_sha256'] and c['logical_id']==b['logical_id'] and c['photo_signature'] for c in b['contexts'].values())
  frames.append({'capture_operation':op,'capture_run':run,'capture_epoch_utc':d['captured_epoch_utc'],'capture_source_sha':d['installed_sha'],'capture_input_sha256':d['input_sha256'],'binding':b})
frames.sort(key=lambda f:int(f['binding']['native_hotel_id']))
assert len(frames)==9 and {int(f['binding']['native_hotel_id']) for f in frames}=={240,1981,46672,498,9373,469,381,310,1971}
raw=(json.dumps(frames,ensure_ascii=False,separators=(',',':'))+'\n').encode();(temp/'legacy9-canonical-frames.private.json').write_bytes(raw)
source=(base/'update-commercial-price-20261105-legacy9-pass1.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert source.startswith(prefix)
header=prefix+"const APPROVED_UPDATE_OPERATION='PURE_LEGACY_PRICE_BINDING_VALIDATION';\n"+"const APPROVED_LEGACY_FRAMES_SHA256='"+hashlib.sha256(raw).hexdigest()+"';\n"+"const APPROVED_LEGACY_FRAMES_B64='"+base64.b64encode(raw).decode()+"';\n"
(temp/'legacy9-price-guard-fixture.php').write_text(header+source[len(prefix):])
d=json.loads(one('original-read10','price-readback.json').read_text().splitlines()[-1]);assert d['operation']=='commercial-price-monitor-20261104-read10-72h-extension1' and d['installed_sha']=='0a80b95fa31e3a90267daa8984adae24d6aaa6bb' and d['max_age_seconds']==259200
hotels={int(h['local_tv_id']):h for h in d['hotels']};selected=[];deferred=[]
meals={2:'RO',3:'BB',4:'HB',5:'FB',7:'AI',9:'UAI'}
for frame in frames:
 b=frame['binding'];hid=int(b['native_hotel_id']);h=hotels[hid];pool=[];reasons=collections.Counter()
 assert h['identity_provenance']==b['identity_provenance'] and int(h['expected_country_id'])==int(b['expected_country_id'])
 for obs in h['observations']:
  why=[]
  if obs.get('retained_source') not in ('scheduled_monitor','user_search','hot_tours'):why.append('UNAPPROVED_SOURCE')
  if not 0<=int(time.time())-int(obs['created_at_stored_epoch_utc'])<=259200:why.append('OUTSIDE72H_NOW')
  if not datetime.datetime.now(datetime.timezone.utc).date().isoformat()<obs['departure_date']<='2027-02-02':why.append('DEPARTURE_NOT_FUTURE_NOW')
  if int(obs['adults'])!=2 or int(obs['children_count'])!=0 or obs['child_ages_signature']!='' or obs['currency']!='RUB':why.append('PARTY_OR_CURRENCY_NOT_ADMITTED')
  if not str(obs['room_type']).strip():why.append('ROOM_MISSING')
  if meals.get(int(obs['meal_id']))!=obs.get('native_meal_label') or int(obs['meal_id']) not in meals:why.append('MEAL_NOT_BOUND')
  fp=obs.get('retained_tour_fingerprint','')
  if not re.fullmatch('[0-9a-f]{64}',fp) or fp==hashlib.sha256(b'').hexdigest():why.append('TOUR_FINGERPRINT_UNUSABLE')
  if re.search(r'[-\u2010-\u2015\u2212]',b['caption']):why.append('APPROVED_LEGACY_CAPTION_DASH_REQUIRES_EXPLICIT_HANDLING')
  closing=b['caption'].rstrip('\r\n').split('\n')[-1]
  if not closing or not re.search(r'стоимост|[Нн]ажмите|[Нн]апишите',closing):why.append('ACTUAL_LEGACY_CLOSING_REQUIRES_BINDING')
  for reason in why:reasons[reason]+=1
  if not why:pool.append(obs)
 if not pool:deferred.append({'native_hotel_id':hid,'reasons':dict(reasons)});continue
 pool.sort(key=lambda x:(-int(x['created_at_stored_epoch_utc']),decimal.Decimal(str(x['raw_observed_price']))))
 chosen=pool[0];frame_bytes=json.dumps(frame,ensure_ascii=False,separators=(',',':')).encode()
 selected.append({'schema_version':1,'candidate_kind':'canonical_legacy_quote_candidate_v1','native_hotel_id':hid,'original_publisher_hotel_id':b['publisher_hotel_id'],'hotel':{k:h[k] for k in ['local_tv_id','expected_name','expected_country_id','identity_provenance']},'catalog_name_for_quote':b['catalog_name'],'retained':chosen,'canonical_context':frame,'canonical_context_sha256':hashlib.sha256(frame_bytes).hexdigest(),'retained_source_run':41934633857,'retained_source_artifact':13372822872,'retained_source_installed_sha':'0a80b95fa31e3a90267daa8984adae24d6aaa6bb'})
counts=collections.Counter(x['retained']['retained_tour_fingerprint'] for x in selected)
safe=[]
for entry in selected:
 if counts[entry['retained']['retained_tour_fingerprint']]>1:deferred.append({'native_hotel_id':entry['native_hotel_id'],'reasons':{'CROSS_HOTEL_FINGERPRINT_NOT_UNIQUE':1}})
 else:safe.append(entry)
assert len(safe)+len(deferred)==9
(temp/'legacy9-price-candidates.private.json').write_text(json.dumps(safe,ensure_ascii=False)+'\n')
summary={'operation':'commercial-price-legacy9-20261105-canonical-merge-and-updater-prepare','canonical_context_hotels':9,'canonical_platform_contexts':18,'frame_sha256':hashlib.sha256(raw).hexdigest(),'eligible_unquoted_native_ids':[r['native_hotel_id'] for r in safe],'eligible_unquoted_hotels':len(safe),'deferred':deferred,'maximum_future_justified_gateway_calls':len(safe),'max_age_seconds':259200,'utc_date_evaluated':datetime.datetime.now(datetime.timezone.utc).date().isoformat(),'old_source_installed_sha_preserved':True,'source_or_photo_gets':0,'provider_calls':0,'db_calls':0,'price_edits':0,'execution_approval':False}
(temp/'legacy9-merge-summary.json').write_text(json.dumps(summary)+'\n');print(json.dumps(summary))
