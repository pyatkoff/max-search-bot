import copy,csv,hashlib,json,pathlib,re,xml.etree.ElementTree as ET
BASE=pathlib.Path('.github/traffic-publish')
COPY_PATH=pathlib.Path('tools/direct_demand/operations/commercial-channel-hotel-ad-copy-20261103.json')
CSV_HASHES={"hotel-posts-telegram.csv":"4c919310039d86115e2480ce8a10570cfb52b3904528d7ed90ba315a6a9501ee","hotel-posts-max.csv":"f77c160a734d7864e77a456c606e216f724d1d61940b62df4eab1fce30b5d079","hotel-posts-all.csv":"86ac313807d7452a55e37c2ca8e3ecaa78f9e9a80b07bd5fb3a5fc1f41d73414"}
ADS={str(r['hotel_id']):r for r in json.loads(COPY_PATH.read_text())['hotels']}
def insist(ok,why):
 if not ok:raise ValueError(why)
def load_baseline(name):
 raw=(BASE/name).read_bytes()
 insist(hashlib.sha256(raw).hexdigest()==CSV_HASHES[name],'accepted_csv_changed')
 return list(csv.DictReader(raw.decode('utf-8').splitlines()))
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
tables=[load_baseline('hotel-posts-'+p+'.csv') for p in ['telegram','max','all']]
raws=[(BASE/('hotel-posts-'+p+'.xml')).read_bytes() for p in ['telegram','max','all']]
checked=[check(raw,base) for raw,base in zip(raws,tables)]
insist([len(x[0]) for x in checked]==[124,124,248],'feed_scope_changed')
insist(sorted(checked[2][0])==sorted(checked[0][0]+checked[1][0]),'combined_not_exact_union')
insist([sorted(x[1]) for x in checked]==[[381,469],[381,469],[381,381,469,469]],'explicit_image_gaps_changed')
first=raws[0];base=tables[0]
cases=[
 ('name_limit',lambda o:setattr(o[0].find('name'),'text','Путевки в '+'x'*50),'name_too_long'),
 ('description_limit',lambda o:setattr(o[0].find('description'),'text','я'*82),'description_too_long'),
 ('channel_title',lambda o:setattr(o[0].find('name'),'text',o[0].findtext('name').replace('Путевки в ','Туры в ')),'ad_title_changed'),
 ('private_destination',lambda o:setattr(o[0].find('url'),'text','https://app.anytoour.ru/bot?token=blocked'),'canonical_public_destination_changed'),
 ('photo_substitution',lambda o:setattr(o[0].find('picture'),'text','https://example.com/unapproved.jpg'),'accepted_photo_changed'),
 ('duplicate_id',lambda o:o[1].set('id',o[0].get('id')),'duplicate_or_unknown_offer'),
 ('invented_price',lambda o:ET.SubElement(o[0],'price').__setattr__('text','1'),'unsupported_offer_fields'),
 ('invented_availability',lambda o:o[0].set('available','true'),'unsupported_offer_attrs'),
 ('missing_description',lambda o:o[0].remove(o[0].find('description')),'unsupported_offer_fields'),
 ('wrong_channel_filter',lambda o:setattr(o[0].find('custom_label_0'),'text','max'),'channel_label_changed'),
 ('description_dash',lambda o:setattr(o[0].find('description'),'text','Тур - фото'),'dash_in_ad_copy'),
]
for label,change,why in cases:
 try:check(altered(first,change),base)
 except ValueError as e:insist(str(e)==why,'fixture_wrong_rejection_'+label)
 else:raise ValueError('fixture_not_rejected_'+label)
try:check(b'<!DOCTYPE x [<!ENTITY e SYSTEM "file:///invalid">]>'+first,base)
except ValueError as e:insist(str(e)=='external_xml_declaration','fixture_external_declaration')
else:raise ValueError('fixture_external_not_rejected')
insist(any('&amp;' in raw.decode() for raw in raws) and any("J'adore" in row[2] for row in checked[0][0]),'xml_escaping_roundtrip_missing')
print('DIRECT_YML_PREPARATION_OK native_new_cases=13 offers=124,124,248 union=exact title_limit=56 description_limit=81 canonical_destinations=unchanged accepted_images=unchanged image_gaps=2,2,4 price_fields=0 availability_fields=0 provider_calls=0 server_calls=0 writes=0')
