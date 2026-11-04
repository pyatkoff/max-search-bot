"""Prepare exactly three authorized public YML exports from new6 accepted canonical bindings."""
import base64,copy,datetime,hashlib,json,os,pathlib,re,xml.etree.ElementTree as ET
from xml.sax.saxutils import escape
B=pathlib.Path('.github/traffic-publish');T=pathlib.Path(os.environ['RUNNER_TEMP'])
PREVIOUS=json.loads("{\"hotel-posts-telegram.xml\":{\"rows\":150,\"sha256\":\"807da5b17cbda96860c952417bd880091246991e39504ba1e65520f392d70d70\"},\"hotel-posts-max.xml\":{\"rows\":150,\"sha256\":\"b8d5bc0b5818279dd9968e963ea6a5b91fb837bf1aa8b2b08eec3046ca1782a3\"},\"hotel-posts-all.xml\":{\"rows\":300,\"sha256\":\"e392c65f507e754f6a08d83f1e5a15bc1b90ea9f7320e4b8380d4832dc6d513b\"}}")
ADS=json.loads("[{\"hotel_id\":1659,\"name\":\"TUI BLUE Sarigerme Park\"},{\"hotel_id\":21695,\"name\":\"TUI BLUE Seno\"},{\"hotel_id\":28668,\"name\":\"Rixos Premium Gocek\"},{\"hotel_id\":55205,\"name\":\"Eftalia Ocean\"},{\"hotel_id\":1334,\"name\":\"Leodikya Kirman Premium\"},{\"hotel_id\":1715,\"name\":\"Voyage Torba\"}]")
IDS=[1659,21695,28668,55205,1334,1715]
e=json.loads(pathlib.Path('tools/direct_demand/operations/commercial-max156-installed-safe-evidence-20261104.json').read_text())
assert e['run_id']==41835696728 and e['job_id']==126593895286 and e['source_commit']=='256eff5d0d214a550764afb0b11e7a9c6612b158'
d=e['result'];assert d['ok'] and d['state']=='INSTALLED_AND_NATIVE_VERIFIED' and d['checks']['exact_total_routes']==156 and d['checks']['new_native_routes_verified']==6 and d['checks']['previous150_rows_identical'] and d['checks']['private_projection_http_status']==404
p=json.loads((T/'new6'/'channel-preflight.json').read_text().splitlines()[-1])
assert p['ok'] and p['operation']=='commercial-channel-hotel-feeds-20261104-read13' and p['feed_rows']==6 and p['deferred_count']==0 and p['private_binding_count']==6
rows=p['rows'];bindings=p['private_max_bindings'];assert [r['hotel_id'] for r in rows]==IDS and [r['hotel_id'] for r in bindings]==IDS
def blocks(raw):
 return {m[1]:m[0] for m in re.finditer(r'<offer\b[^>]*\bid="([^"]+)"[^>]*>[\s\S]*?</offer>',raw.decode())}
old={name:(B/name).read_bytes() for name in PREVIOUS}
for name,raw in old.items():assert hashlib.sha256(raw).hexdigest()==PREVIOUS[name]['sha256']
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
baseline={name:{'previous_sha256':PREVIOUS[name]['sha256'],'previous_blocks_sha256':{k:hashlib.sha256(v.encode()).hexdigest() for k,v in oldblocks[name].items()},'new_blocks_sha256':{k:hashlib.sha256(v.encode()).hexdigest() for k,v in blocks(raw).items() if k not in oldblocks[name]},'rows':312 if name=='hotel-posts-all.xml' else 156} for name,raw in outputs.items()}
def check(name,raw):
 b=baseline[name];assert b'<!DOCTYPE' not in raw.upper() and b'<!ENTITY' not in raw.upper()
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
for kind in ['old_offer','wrong_bot','description']:
 sample=outputs['hotel-posts-max.xml']
 if kind=='old_offer':bad=sample.replace(b'<name>',b'<name>Changed ',1)
 elif kind=='wrong_bot':bad=sample.replace(b'id9704048781_2_bot',b'id9704048781_1_bot',1)
 else:bad=sample.replace(b'<description>',b'<description>Changed ',1)
 try:check('hotel-posts-max.xml',bad);raise RuntimeError('BAD_APPEND_ACCEPTED')
 except AssertionError:pass
(T/'feed156-route-baseline.json').write_text(json.dumps(baseline,sort_keys=True))
for name,raw in outputs.items():
 (T/name).write_bytes(raw);b64=base64.b64encode(raw).decode();parts=[b64[i:i+48000] for i in range(0,len(b64),48000)]
 print(json.dumps({'kind':'feed_meta','name':name,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'rows':baseline[name]['rows'],'chunks':len(parts)}),flush=True)
 for i,x in enumerate(parts):print(json.dumps({'kind':'feed_chunk','name':name,'index':i,'total':len(parts),'data':x}),flush=True)
print(json.dumps({'operation':'commercial-channel-hotel-feeds-20261104-update12-prepare','ok':True,'new_cases':3,'previous150_offer_blocks_identical':True,'offers':[156,156,312],'server_connections':0,'db_writes':0,'provider_calls':0,'message_sends':0}),flush=True)
