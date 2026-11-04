import base64,copy,hashlib,json,os,pathlib,re,time
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
rawfiles=list((temp/'reviewed-quote2').rglob('quote2-update-manifest.json'));assert len(rawfiles)==1
manifest=json.loads(rawfiles[0].read_text());manifest['button_text']='✈️ Узнать стоимость тура';manifest['rows'][0]['update_text']=True
savedfiles=list((temp/'original-quote2').rglob('channel-preflight.json'));assert len(savedfiles)==1
saved=json.loads(savedfiles[0].read_text().splitlines()[-1])
def validate(d,m):
 assert d['operation']=='commercial-price-monitor-20261104-quote2' and d['catalog_id']==9432 and d['ok'] and d['http_status']==200 and d['gateway_requests_attempted']==1
 assert d['response_sha256']==m['quote_response_sha256']=='135bb236f2c90750fcaa20a4021a03107cc1c4193a33f5aa2b7d804f4547cda9'
 assert d['response_received_epoch_utc']==m['quote_epoch_utc']==1793776598
 assert 0<=time.time()-d['response_received_epoch_utc']<=108000
 t=d['retained_terms'];assert (t['departure_date'],int(t['nights']),int(t['adults']),int(t['children']),int(t['meal_id']),t['room_type'])==('2026-11-05',11,2,0,9,'deluxe family sea side room')
 q=d['gateway_response'];assert q['error'] is None and q['info']['surcharges'] in (None,[])
 assert q['info']['flags']=={'noTransfer':False,'noInsurance':False,'noFlight':False,'noMeal':False}
 assert m['operation']=='commercial-price-update-20261104-2-v1' and m['quote_index']==1 and len(m['rows'])==1
 f=q['flights'][1];assert f['price']=={'value':726423,'currency':'RUB'}
 assert f['dateForward']=='05.11.2026' and f['dateBackward']=='16.11.2026'
 assert len(f['forward'])==len(f['backward'])==1
 assert f['forward'][0]['number']=='2S142' and f['backward'][0]['number']=='2S011'
 assert all(x.get('onDemand') is False and x.get('noPlaces') is False for x in f['forward']+f['backward'])
 r=m['rows'][0];assert r['key']=='delphin-imperial-lara' and r['hotel_id']==9432 and r['logical_id']=='commercial-delphin-imperial-lara-9432-v1' and r['original_publication_operation']=='commercial-hotel-batch-20261103-32-v1'
 original=json.loads((base/'commercial-batch-20261103-32-manifest.json').read_text());old=[x for x in original['rows'] if x['hotel_id']==9432];assert len(old)==1 and old[0]['text']==r['old_text']
 assert hashlib.sha256(r['old_text'].encode()).hexdigest()==r['old_text_sha256']==old[0]['text_sha256']
 assert hashlib.sha256(r['new_text'].encode()).hexdigest()==r['new_text_sha256'] and len(r['new_text'].encode('utf-16-le'))//2<=1024 and not re.search(r'[-\u2010-\u2015\u2212]',r['new_text'])
 before,cta=r['old_text'].rsplit('\n',1);assert r['new_text'].startswith(before+'\n\n') and r['new_text'].endswith('\n\n'+cta)
 assert all(s in r['new_text'] for s in ['726 423 ₽','05.11.2026','16.11.2026','11 ночей','2 взрослых','UAI','2S142','2S011','04.11.2026 07:16 UTC'])
 assert r['cover_sha256']==old[0]['photo_sha256']=='76ba80696fc28fe24bcf3662dc9e8d4371132bebce358df48dabca496ce4fb8d'
validate(saved,manifest)
for mutate in [lambda d,m:d.update(catalog_id=12114),lambda d,m:d.update(response_received_epoch_utc=int(time.time())-108001),lambda d,m:d['gateway_response']['flights'][1]['price'].update(value=726424)]:
 d=copy.deepcopy(saved);m=copy.deepcopy(manifest);mutate(d,m)
 try:validate(d,m)
 except AssertionError:pass
 else:raise AssertionError('unsafe_changed_quote_binding_accepted')
raw=(json.dumps(manifest,ensure_ascii=False,separators=(',',':'))+'\n').encode()
approval={'schema_version':1,'operation':manifest['operation'],'expected_installed_sha':os.environ['EXPECTED_PRODUCTION_SHA'],'hotel_ids':[9432],'manifest_sha256':hashlib.sha256(raw).hexdigest()}
(temp/'price-update2-manifest.json').write_bytes(raw);(temp/'price-update2-approval.json').write_text(json.dumps(approval))
intent={'operation':manifest['operation'],'state':'EXACT_ONE_QUOTE_TWO_EXISTING_EDITS_INTENT_BEFORE_PROVIDER','catalog_ids':[9432],'manifest_sha256':approval['manifest_sha256'],'expected_source':os.environ['EXPECTED_PRODUCTION_SHA'],'quote_response_sha256':manifest['quote_response_sha256'],'quote_epoch_utc':manifest['quote_epoch_utc'],'max_age_seconds':108000,'new_posts':0,'max_content_edits':2}
(temp/'price-update2-intent.json').write_text(json.dumps(intent))
source=(base/'update-commercial-price-20261104-2.php').read_text()
constants='\ndefine("EXPECTED_INSTALLED_SHA", "'+os.environ['EXPECTED_PRODUCTION_SHA']+'");\ndefine("APPROVED_MANIFEST_SHA256", "'+approval['manifest_sha256']+'");\ndefine("MANIFEST_BASE64", "'+base64.b64encode(raw).decode()+'");\ndefine("VERIFIED_QUOTE_RESPONSE_SHA256", "'+manifest['quote_response_sha256']+'");\ndefine("VERIFIED_QUOTE_EPOCH", '+str(manifest['quote_epoch_utc'])+');'
assert source.count('declare(strict_types=1);')==1 and 'time()-VERIFIED_QUOTE_EPOCH<=108000' in source and 'time()-VERIFIED_QUOTE_EPOCH<=3600' not in source
target=temp/'channel-preflight.php';target.write_text(source.replace('declare(strict_types=1);','declare(strict_types=1);'+constants,1));target.chmod(0o600)
print('PRICE_UPDATE2_EXACT_BINDING_OK new_cases=3 max_age_hours=30 old8cases_rerun=0 additional_gateway_calls=0 server_calls=0 existing_posts_to_edit=2 new_posts=0')
