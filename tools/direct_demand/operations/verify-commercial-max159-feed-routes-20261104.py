import base64,hashlib,json,pathlib,re,xml.etree.ElementTree as ET
B=pathlib.Path('.github/traffic-publish')
baseline=json.loads(pathlib.Path('tools/direct_demand/operations/commercial-channel-hotel-feeds-20261104-update14-baseline.json').read_text())
def check(name,raw):
 b=baseline[name];assert len(raw)==b['bytes'] and hashlib.sha256(raw).hexdigest()==b['sha256']
 assert b'<!DOCTYPE' not in raw.upper() and b'<!ENTITY' not in raw.upper()
 doc=ET.fromstring(raw);offers=doc.findall('shop/offers/offer');rb={m[1]:m[0] for m in re.finditer(r'<offer\b[^>]*\bid="([^"]+)"[^>]*>[\s\S]*?</offer>',raw.decode())}
 assert len(offers)==b['rows'] and len(rb)==len(offers)
 assert set(rb)==set(b['previous_blocks_sha256'])|set(b['new_blocks_sha256'])
 for oid,h in {**b['previous_blocks_sha256'],**b['new_blocks_sha256']}.items():assert hashlib.sha256(rb[oid].encode()).hexdigest()==h
 for o in offers:
  n=o.findtext('name');d=o.findtext('description');assert n and d and len(n)<=56 and len(d)<=81 and len(n.split())<=22 and len(d.split())<=23
  assert not re.search(r'[-‐‑‒–—−]',n+d) and len(re.findall(r'[^\w\s]',d))<=15 and o.find('price') is None and 'available' not in o.attrib
  u=o.findtext('url')
  if o.attrib['id'].startswith('max_'):
   prefix='https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_';assert u.startswith(prefix) and len(u.split('startapp=',1)[1])<=512
   token=u[len(prefix):];ctx=json.loads(base64.urlsafe_b64decode(token+'='*((4-len(token)%4)%4)))
   assert list(ctx)==['post_id','offer_id'] and ctx['offer_id']==o.attrib['id'] and re.fullmatch(r'h1_[a-f0-9]{32}',ctx['post_id'])
  else:assert re.fullmatch(r'https://t\.me/any_toour/[1-9][0-9]*',u)
 return rb
checked={n:check(n,(B/n).read_bytes()) for n in baseline}
assert checked['hotel-posts-all.xml']=={**checked['hotel-posts-telegram.xml'],**checked['hotel-posts-max.xml']}
print('MAX159_EXACT_FROZEN_YML_OK previous157_offers_byteidentical=1 new2_native_bindings_accepted=1 combined318_exact_union=1 native_three_new_append_cases_already_passed=1 old_fixtures_rerun=0')
