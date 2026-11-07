"""Append only the four current, bound hotel pairs to the accepted183 feed bytes."""
import base64,datetime,hashlib,json,re,xml.etree.ElementTree as ET
from pathlib import Path
from xml.sax.saxutils import escape
P=Path(__file__).parent
OLD={'hotel-posts-telegram.xml': (118329, 183, '53d77c00a553cbf320b38674c9a3eeba3538eda528bbad9714c65832dbc7b98a'), 'hotel-posts-max.xml': (148974, 183, '44d907c2d07676ce702bac416ed207c6aec28b79ec5aa787496c8594c7daf48e'), 'hotel-posts-all.xml': (266930, 366, 'cebe5518493c3f616af870c10b0e22f51f7a6b6e8150888fd5bd23ba015b962f')}
IDS=[128087,969,55647,120117]
d=json.loads((P/'new4-read22-private.json').read_text());assert d['ok'] and d['feed_rows']==4 and [r['hotel_id'] for r in d['rows']]==IDS
inputs=json.loads((P/'commercial-channel-hotel-feeds-20261107-read22-inputs.json').read_text())
def digest(b):return hashlib.sha256(b).hexdigest()
def blocks(raw):return {m[1]:m[0] for m in re.finditer(r'<offer\b[^>]*\bid="([^"]+)"[^>]*>[\s\S]*?</offer>',raw.decode())}
def outside(raw):return re.sub(r'<yml_catalog date="[^"]+">','<yml_catalog date="__DATE__">',re.sub(r'<offers>[\s\S]*?</offers>','<offers>__OFFERS__</offers>',raw.decode(),count=1)).encode()
old={n:(P/'feed183'/n).read_bytes() for n in OLD}
for n,b in old.items():assert (len(b),len(blocks(b)),digest(b))==OLD[n],n
extra={'telegram':[],'max':[]}
for r in d['rows']:
 destination=r['destination']
 b=next(x for x in d['private_max_bindings'] if x['hotel_id']==r['hotel_id']);i=next(x for x in inputs if x['hotel_id']==r['hotel_id'])
 assert r['image_url']==i['image_url'] and b['post_url']==r['max_post_url']
 offer='max_'+str(r['hotel_id']);alias='h1_'+digest(('anytour|'+offer+'|'+r['max_post_url']).encode())[:32]
 assert b['public_offer_id']==offer and b['public_route_alias']==alias
 token=base64.urlsafe_b64encode(json.dumps({'post_id':alias,'offer_id':offer},separators=(',',':')).encode()).decode().rstrip('=')
 maxurl='https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_'+token
 for platform,url,prefix in [('telegram',r['telegram_post_url'],'Путевки в '),('max',maxurl,'Туры в ')]:
  oid=('tg_' if platform=='telegram' else 'max_')+str(r['hotel_id']);name=prefix+r['name'];desc='Тур с перелётом. '+destination.replace('-',' ')+'. Фото и особенности отеля. Узнайте стоимость.'
  assert len(name)<=56 and len(desc)<=81 and len(name.split())<=22 and len(desc.split())<=23
  assert not re.search(r'[-‐‑‒–—−]',name+desc) and len(re.findall(r'[^\w\s]',desc))<=15
  text='      <offer id="'+oid+'">\n        <url>'+escape(url)+'</url>\n        <categoryId>1</categoryId>\n        <picture>'+escape(r['image_url'])+'</picture>\n        <name>'+escape(name)+'</name>\n        <description>'+escape(desc)+'</description>\n        <custom_label_0>'+platform+'</custom_label_0>\n        <custom_label_1>'+escape(destination.replace('-',' '))+'</custom_label_1>\n      </offer>\n'
  extra[platform].append(text)
date=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M');out=P/'feed187';out.mkdir(exist_ok=True)
baseline={};files=[]
for n,b in old.items():
 platform='all' if n=='hotel-posts-all.xml' else ('max' if n=='hotel-posts-max.xml' else 'telegram')
 add=''.join(extra['telegram']+extra['max']) if platform=='all' else ''.join(extra[platform])
 text=re.sub(r'date="[^"]+"','date="'+date+'"',b.decode(),count=1);assert text.count('    </offers>')==1
 new=text.replace('    </offers>',add+'    </offers>').encode();before=blocks(b);after=blocks(new);total=374 if platform=='all' else 187
 assert len(after)==total and outside(new)==outside(b) and all(after[k]==v for k,v in before.items())
 expected={('tg_' if x=='telegram' else 'max_')+str(h) for x in (['telegram','max'] if platform=='all' else [platform]) for h in IDS}
 assert set(after)-set(before)==expected
 doc=ET.fromstring(new);assert len(doc.findall('shop/offers/offer'))==total and b'<!DOCTYPE' not in new.upper() and b'<!ENTITY' not in new.upper()
 (out/n).write_bytes(new)
 row={'name':n,'bytes':len(new),'sha256':digest(new),'rows':total,'expected_previous_sha256':digest(b)};files.append(row)
 baseline[n]={**row,'previous_offer_blocks_sha256':{k:digest(v.encode()) for k,v in before.items()},'new_offer_blocks_sha256':{k:digest(v.encode()) for k,v in after.items() if k not in before},'outside_offers_sha256':digest(outside(b))}
assert blocks((out/'hotel-posts-all.xml').read_bytes())=={**blocks((out/'hotel-posts-telegram.xml').read_bytes()),**blocks((out/'hotel-posts-max.xml').read_bytes())}
plan={'operation':'commercial-channel-hotel-feeds-20261107-update21','state':'PREPARED_REQUIRES_CONFIRMED_MAX187_INSTALL','new_hotel_ids':IDS,'previous_rows':[183,183,366],'target_rows':[187,187,374],'previous183_offer_blocks_identical':True,'max_route_install_run':42369981545,'max_route_install_commit':'d81dc9a0f902989b1379c45be140c0a56ce74eed','new4_binding_run':42369597241,'new4_binding_artifact':13573530233,'files':files,'provider_calls':0,'db_writes':0,'message_sends':0}
(P/'commercial-channel-hotel-feeds-20261107-update21-plan.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2)+'\n')
(P/'commercial-channel-hotel-feeds-20261107-update21-baseline.json').write_text(json.dumps(baseline,sort_keys=True,indent=2)+'\n')
print(json.dumps(plan,ensure_ascii=False))
