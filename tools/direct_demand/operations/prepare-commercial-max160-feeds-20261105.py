"""Append only the newly bound batch49 pair, preserving all previous159 offer bytes."""
import base64,copy,datetime,hashlib,json,os,pathlib,re,xml.etree.ElementTree as ET
from xml.sax.saxutils import escape
B=pathlib.Path('.github/traffic-publish');T=pathlib.Path(os.environ['RUNNER_TEMP'])
P=pathlib.Path('tools/direct_demand/operations')
plan=json.loads((P/'commercial-channel-hotel-feeds-20261105-update15-plan.json').read_text())
assert plan['new_hotel_ids']==[1422] and plan['target_rows']==[160,160,320]
PREVIOUS={f['name']:f for f in plan['previous_files']}
ADS=plan['new_hotel_names'];IDS=plan['new_hotel_ids']
e=json.loads((P/'commercial-max160-installed-safe-evidence-20261105.json').read_text())
assert e['run_id']==41964055740 and e['job_id']==127036830809 and e['source_commit']=='5798d5b68f58433575009522cb917e9a8400b1bd'
d=e['result'];assert d['ok'] and d['state']=='INSTALLED_AND_NATIVE_VERIFIED'
assert d['checks']['exact_total_routes']==160 and d['checks']['new_native_routes_verified']==1 and d['checks']['previous159_rows_identical']
assert d['checks']['private_projection_http_status']==404 and d['checks']['save_calls']==0
assert d['candidate_module_sha256']=='3e5b56451eee15b33e9c2acf4319ce2920ed39853142825b13d37d3ebff7dae1'
p=json.loads((T/'new1'/'channel-preflight.json').read_text().splitlines()[-1])
assert p['ok'] and p['operation']=='commercial-channel-hotel-feeds-20261105-read16' and p['feed_rows']==1 and p['deferred_count']==0 and p['private_binding_count']==1
rows=p['rows'];bindings=p['private_max_bindings'];assert [r['hotel_id'] for r in rows]==IDS and [r['hotel_id'] for r in bindings]==IDS
source_manifest=json.loads((P/'commercial-batch-20261105-49-manifest.json').read_text())
for row in rows:assert row['image_url']==next(r['photo_url'] for r in source_manifest['rows'] if r['hotel_id']==row['hotel_id'])
def blocks(raw):
 return {m[1]:m[0] for m in re.finditer(r'<offer\b[^>]*\bid="([^"]+)"[^>]*>[\s\S]*?</offer>',raw.decode())}
old={name:(B/name).read_bytes() for name in PREVIOUS}
for name,raw in old.items():assert len(raw)==PREVIOUS[name]['bytes'] and hashlib.sha256(raw).hexdigest()==PREVIOUS[name]['sha256'] and len(blocks(raw))==PREVIOUS[name]['rows']
oldblocks={name:blocks(raw) for name,raw in old.items()}
extra={'telegram':[],'max':[]}
for r in rows:
 a=next(x for x in ADS if x['hotel_id']==r['hotel_id']);binding=next(x for x in bindings if x['hotel_id']==r['hotel_id'])
 assert binding['post_url']==r['max_post_url'] and binding['project_key']=='anytour' and binding['platform']=='max' and binding['post_bot_key']=='id9704048781_1_bot'
 offer='max_'+str(r['hotel_id']);alias='h1_'+hashlib.sha256(('anytour|'+offer+'|'+r['max_post_url']).encode()).hexdigest()[:32]
 assert binding['public_offer_id']==offer and binding['public_route_alias']==alias
 token=base64.urlsafe_b64encode(json.dumps({'post_id':alias,'offer_id':offer},separators=(',',':')).encode()).decode().rstrip('=')
 maxurl='https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_'+token
 assert len(maxurl.split('startapp=',1)[1])<=512
 for platform,url,titleprefix in [('telegram',r['telegram_post_url'],'Путевки в '),('max',maxurl,'Туры в ')]:
  oid=('tg_' if platform=='telegram' else 'max_')+str(r['hotel_id']);name=titleprefix+a['name'];desc='Тур с перелётом. '+r['destination'].replace('-',' ')+'. Фото и особенности отеля. Узнайте стоимость.'
  x='      <offer id="'+oid+'">\n        <url>'+escape(url)+'</url>\n        <categoryId>1</categoryId>\n        <picture>'+escape(r['image_url'])+'</picture>\n        <name>'+escape(name)+'</name>\n        <description>'+escape(desc)+'</description>\n        <custom_label_0>'+platform+'</custom_label_0>\n        <custom_label_1>'+escape(r['destination'].replace('-',' '))+'</custom_label_1>\n      </offer>\n'
  extra[platform].append(x)
date=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M')
outputs={}
for name,raw in old.items():
 platform='all' if name=='hotel-posts-all.xml' else ('max' if name=='hotel-posts-max.xml' else 'telegram')
 addition=''.join(extra['telegram']+extra['max']) if platform=='all' else ''.join(extra[platform])
 text=re.sub(r'date="[^"]+"','date="'+date+'"',raw.decode(),count=1)
 assert text.count('    </offers>')==1
 outputs[name]=text.replace('    </offers>',addition+'    </offers>').encode()
baseline={name:{'previous_sha256':PREVIOUS[name]['sha256'],'previous_blocks_sha256':{k:hashlib.sha256(v.encode()).hexdigest() for k,v in oldblocks[name].items()},'new_blocks_sha256':{k:hashlib.sha256(v.encode()).hexdigest() for k,v in blocks(raw).items() if k not in oldblocks[name]},'rows':320 if name=='hotel-posts-all.xml' else 160} for name,raw in outputs.items()}
def outside(raw):
 text=re.sub(r'<offers>[\s\S]*?</offers>','<offers>__OFFERS__</offers>',raw.decode(),count=1)
 return re.sub(r'<yml_catalog date="[^"]+">','<yml_catalog date="__DATE__">',text).encode()
for name in baseline:baseline[name]['outside_offers_sha256']=hashlib.sha256(outside(old[name])).hexdigest()
def check(name,raw):
 b=baseline[name];assert b'<!DOCTYPE' not in raw.upper() and b'<!ENTITY' not in raw.upper()
 assert hashlib.sha256(outside(raw)).hexdigest()==b['outside_offers_sha256']
 doc=ET.fromstring(raw);offers=doc.findall('shop/offers/offer');rb=blocks(raw)
 assert len(offers)==b['rows'] and len(rb)==len(offers) and set(rb)==set(b['previous_blocks_sha256'])|set(b['new_blocks_sha256'])
 for oid,h in {**b['previous_blocks_sha256'],**b['new_blocks_sha256']}.items():assert hashlib.sha256(rb[oid].encode()).hexdigest()==h
 for o in offers:
  n=o.findtext('name');d=o.findtext('description');assert n and d and len(n)<=56 and len(d)<=81 and len(n.split())<=22 and len(d.split())<=23
  assert not re.search(r'[-‐‑‒–—−]',n+d) and len(re.findall(r'[^\w\s]',d))<=15 and o.find('price') is None and 'available' not in o.attrib
  u=o.findtext('url')
  if o.attrib['id'].startswith('max_'):
   prefix='https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_';assert u.startswith(prefix)
   tok=u[len(prefix):];ctx=json.loads(base64.urlsafe_b64decode(tok+'='*((4-len(tok)%4)%4)))
   assert list(ctx)==['post_id','offer_id'] and ctx['offer_id']==o.attrib['id'] and re.fullmatch(r'h1_[a-f0-9]{32}',ctx['post_id'])
  else:assert re.fullmatch(r'https://t\.me/any_toour/[1-9][0-9]*',u)
 return rb
checked={n:check(n,r) for n,r in outputs.items()}
assert checked['hotel-posts-all.xml']=={**checked['hotel-posts-telegram.xml'],**checked['hotel-posts-max.xml']}
sample=outputs['hotel-posts-max.xml']
oldblock=next(iter(oldblocks['hotel-posts-max.xml'].values())).encode()
newblock=extra['max'][0].encode()
assert oldblock in sample and newblock in sample
for bad in [sample.replace(oldblock,oldblock.replace(b'<name>',b'<name>Changed ',1),1),sample.replace(newblock,newblock.replace(b'id9704048781_2_bot',b'id9704048781_1_bot',1),1),sample.replace(newblock,newblock.replace(b'<description>',b'<description>Changed ',1),1)]:
 try:check('hotel-posts-max.xml',bad);raise RuntimeError('BAD_NEW160_APPEND_ACCEPTED')
 except AssertionError:pass
for name,raw in outputs.items():
 baseline[name]['bytes']=len(raw);baseline[name]['sha256']=hashlib.sha256(raw).hexdigest()
def emit(kind,name,raw,rows):
 b64=base64.b64encode(raw).decode();parts=[b64[i:i+48000] for i in range(0,len(b64),48000)]
 print(json.dumps({'kind':kind+'_meta','name':name,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'rows':rows,'chunks':len(parts)}),flush=True)
 for i,x in enumerate(parts):print(json.dumps({'kind':kind+'_chunk','name':name,'index':i,'total':len(parts),'data':x}),flush=True)
for name,raw in outputs.items():
 (T/name).write_bytes(raw);emit('feed',name,raw,baseline[name]['rows'])
raw=(json.dumps(baseline,sort_keys=True,indent=2)+'\n').encode()
(T/'feed160-baseline.json').write_bytes(raw);emit('baseline','feed160-baseline.json',raw,3)
print(json.dumps({'operation':'commercial-channel-hotel-feeds-20261105-update15-prepare','ok':True,'new_cases':3,'previous159_offer_blocks_identical':True,'offers':[160,160,320],'server_connections':0,'db_writes':0,'provider_calls':0,'message_sends':0}),flush=True)
