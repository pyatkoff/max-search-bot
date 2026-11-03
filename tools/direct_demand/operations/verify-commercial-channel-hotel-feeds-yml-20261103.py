import copy,csv,hashlib,json,pathlib,re,xml.etree.ElementTree as ET
BASE=pathlib.Path('.github/traffic-publish')
COPY_PATH=pathlib.Path('tools/direct_demand/operations/commercial-channel-hotel-ad-copy-20261103.json')
ADS={str(r['hotel_id']):r for r in json.loads(COPY_PATH.read_text())['hotels']}
def insist(ok,why):
 if not ok:raise ValueError(why)
def check(raw,baseline):
 insist(b'<!DOCTYPE' not in raw.upper() and b'<!ENTITY' not in raw.upper(),'external_xml_declaration')
 root=ET.fromstring(raw)
 insist(root.tag=='yml_catalog' and set(root.attrib)=={'date'} and re.fullmatch(r'\d{4}-\d{2}-\d{2} \d{2}:\d{2}',root.get('date','')),'invalid_catalog')
 shop=root.find('shop')
 insist(len(root)==1 and shop is not None,'invalid_shop')
 insist([e.tag for e in shop]==['name','company','url','currencies','categories','offers'],'invalid_shop_fields')
 insist(shop.findtext('name')=='AnyTour' and shop.findtext('url')=='https://app.anytoour.ru/','invalid_shop_identity')
 cats=shop.find('categories')
 insist(len(cats)==1 and cats[0].attrib=={'id':'1'} and cats[0].text=='Туры в отели','invalid_category')
 offers=shop.find('offers')
 expected={r['Property ID']:r for r in baseline}
 insist(len(offers)==len(expected),'offer_count_changed')
 seen=set();gaps=[];out=[]
 for offer in offers:
  oid=offer.get('id')
  insist(offer.tag=='offer' and set(offer.attrib)=={'id'},'unsupported_offer_attrs')
  insist(oid not in seen and oid in expected,'duplicate_or_unknown_offer')
  seen.add(oid);old=expected[oid];native=oid.split('_',1)[1];ad=ADS[native];tg=oid.startswith('tg_')
  wanted=['url','categoryId']+(['picture'] if old['Image URL'] else [])+['name','description','custom_label_0','custom_label_1']
  insist([e.tag for e in offer]==wanted,'unsupported_offer_fields')
  insist(all(not e.attrib and len(e)==0 for e in offer),'nested_offer_field')
  title=offer.findtext('name') or '';desc=offer.findtext('description') or ''
  insist(0<len(title)<=56,'name_too_long')
  insist(0<len(desc)<=81,'description_too_long')
  insist(all(len(w)<=22 for w in title.split()) and all(len(w)<=23 for w in desc.split()),'word_too_long')
  insist(len(re.findall(r'[^\w\s]',desc))<=15,'description_punctuation')
  insist(not re.search(r'[-\u2010-\u2015\u2212]',title+desc),'dash_in_ad_copy')
  insist(title==ad['telegram_name' if tg else 'max_name'] and title.startswith('Путевки в ' if tg else 'Туры в '),'ad_title_changed')
  insist(desc==ad['description'],'ad_description_changed')
  url=offer.findtext('url') or ''
  insist(url==old['Final URL'],'canonical_public_destination_changed')
  insist(bool(re.fullmatch(r'https://t\.me/any_toour/[1-9][0-9]*',url)) if tg else bool(re.fullmatch(r'https://max\.ru/(?:channel_anytour|c/-80492244209683)/[A-Za-z0-9_-]{1,100}',url)),'unsafe_destination')
  insist(offer.findtext('categoryId')=='1','offer_category_changed')
  insist((offer.findtext('picture') or '')==old['Image URL'],'accepted_photo_changed')
  insist(offer.findtext('custom_label_0')==('telegram' if tg else 'max'),'channel_label_changed')
  insist(offer.findtext('custom_label_1')==old['Destination name'].replace('-',' '),'destination_label_changed')
  if not old['Image URL']:gaps.append(int(native))
  out.append((oid,url,title,desc,old['Image URL']))
 insist(seen==set(expected),'missing_offer')
 return out,gaps
def altered(raw,change):
 root=ET.fromstring(raw);change(root.find('shop/offers'))
 return ET.tostring(root,encoding='utf-8')
NEW_IDS=[1291,15869,253,75377]
NEW_BINDING_SHA={"253":"39a8ea26bde8240cd80beea16c8ee5c627e72014346018e94b7599de44ff59c4","1291":"0326a0dd205296db9a9c91558a90027d0664a7ad51f20806b61e90cbf1ac847f","15869":"0b73a6fd0a51dba609dcef736bc95636fd82131dbc3edbd863180c441f0685cf","75377":"47da25386e1a8f4302508a4c2473854fcfc912cf6b6175d3cabaa04a8563a1c9"}
NEW_DESTINATIONS={"253":"Шарм-эль-Шейх","1291":"Кемер","15869":"Сиде","75377":"Фетхие"}
PREVIOUS_XML_SHA={"hotel-posts-telegram.xml":"957b48c4c08fcb3ca48cbc535d5a45b593ffcce9d61a6e589db81a7bb7fd5e81","hotel-posts-max.xml":"b34c2754672f88667116d4a4636f243a9c1b1c994eca9dfd292c10bb269ec667","hotel-posts-all.xml":"f0dbf7d47a368c69dd4a9253caeb290f0ad05044fbc7f5e4388a2d02ba0165b2"}
CURRENT_DATE="2026-11-03 21:24"
OLD_DATE="2026-11-03 20:39"
def previous_bytes(raw,name):
 restored=raw.replace(('date="'+CURRENT_DATE+'"').encode(),('date="'+OLD_DATE+'"').encode(),1)
 prefixes=['tg'] if name.endswith('-telegram.xml') else ['max'] if name.endswith('-max.xml') else ['tg','max']
 for native in NEW_IDS:
  for platform in prefixes:
   pattern=rb'      <offer id="'+(platform+'_'+str(native)).encode()+rb'">.*?      </offer>\n'
   restored,count=re.subn(pattern,b'',restored,count=1,flags=re.S)
   insist(count==1,'new_offer_block_missing')
 insist(hashlib.sha256(restored).hexdigest()==PREVIOUS_XML_SHA[name],'previous127_offer_bytes_changed')
 return restored
def new_rows_for_baseline():
 tg={o.get('id'):o for o in ET.fromstring((BASE/'hotel-posts-telegram.xml').read_bytes()).findall('shop/offers/offer')}
 mx={o.get('id'):o for o in ET.fromstring((BASE/'hotel-posts-max.xml').read_bytes()).findall('shop/offers/offer')}
 out=[[],[]]
 manifest=json.loads(pathlib.Path('tools/direct_demand/operations/commercial-batch-20261103-30-manifest.json').read_text())
 photos={str(r['hotel_id']):r['photo_url'] for r in manifest['rows']}
 for native in NEW_IDS:
  a=tg['tg_'+str(native)];b=mx['max_'+str(native)];photo=a.findtext('picture')
  insist(photo==photos[str(native)]==b.findtext('picture'),'new_accepted_photo_changed')
  raw=(json.dumps([native,a.findtext('url'),b.findtext('url'),photo],ensure_ascii=False,separators=(',',':'))+'\n').encode()
  insist(hashlib.sha256(raw).hexdigest()==NEW_BINDING_SHA[str(native)],'new_canonical_public_binding_changed')
  for i,o in enumerate([a,b]):
   out[i].append({'Property ID':o.get('id'),'Property name':ADS[str(native)]['short_name'],'Final URL':o.findtext('url'),'Destination name':NEW_DESTINATIONS[str(native)],'Image URL':photo,'Category':'отель'})
 return out

raws=[(BASE/('hotel-posts-'+p+'.xml')).read_bytes() for p in ['telegram','max','all']]
tables=[]
for platform,raw in zip(['telegram','max','all'],raws):
 old=previous_bytes(raw,'hotel-posts-'+platform+'.xml')
 restored=ET.fromstring(old)
 tables.append([{'Property ID':o.get('id'),'Final URL':o.findtext('url'),'Destination name':o.findtext('custom_label_1'),'Image URL':o.findtext('picture') or ''} for o in restored.findall('shop/offers/offer')])
new_tables=new_rows_for_baseline()
tables[0].extend(new_tables[0]);tables[1].extend(new_tables[1]);tables[2].extend(new_tables[0]+new_tables[1])
checked=[check(raw,base) for raw,base in zip(raws,tables)]
insist([len(x[0]) for x in checked]==[131,131,262],'feed_scope_changed')
insist(sorted(checked[2][0])==sorted(checked[0][0]+checked[1][0]),'combined_not_exact_union')
insist([sorted(x[1]) for x in checked]==[[381,469],[381,469],[381,381,469,469]],'explicit_image_gaps_changed')

for platform,raw in zip(['telegram','max','all'],raws):previous_bytes(raw,'hotel-posts-'+platform+'.xml')
name='hotel-posts-telegram.xml'
for before,after in [(b'<categoryId>1</categoryId>',b'<categoryId>2</categoryId>'),('Путевки в Pickalbatros Makadi Resort'.encode(),'Путевки в другой отель'.encode())]:
 changed=raws[0].replace(before,after,1)
 insist(changed!=raws[0],'previous_fixture_not_changed')
 try:previous_bytes(changed,name)
 except ValueError as e:insist(str(e)=='previous127_offer_bytes_changed','previous_fixture_wrong_rejection')
 else:raise ValueError('previous_fixture_not_rejected')
print('DIRECT_YML_APPEND_PREPARATION_OK new_cases=3 offers=131,131,262 previous127_offer_bytes=unchanged union=exact title_limit=56 description_limit=81 canonical_new_bindings=4 image_gaps=2,2,4 price_fields=0 availability_fields=0 provider_calls=0 server_calls=0 writes=0')
