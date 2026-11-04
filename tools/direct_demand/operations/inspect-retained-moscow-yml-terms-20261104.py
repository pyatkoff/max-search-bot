import collections,json,os,pathlib,re,urllib.parse,xml.etree.ElementTree as ET
temp=pathlib.Path(os.environ['RUNNER_TEMP']);paths=list((temp/'moscow-source').rglob('moscow_any.source.xml'));assert len(paths)==1
data=paths[0].read_bytes();assert __import__('hashlib').sha256(data).hexdigest()=='21ca10ff39422e7509a8fc1835b2046f68243e0c1103b1660aac6e6cf36a5996'
root=ET.fromstring(data);offers=root.findall('.//offer');assert len(offers)==140
hosts=collections.Counter();keys=collections.Counter();price_kinds=collections.Counter();old_kinds=collections.Counter();counts=collections.Counter();names=[];idkinds=collections.Counter();datefields=collections.Counter()
for offer in offers:
 name=offer.findtext('name','');desc=offer.findtext('description','');u=urllib.parse.urlsplit(offer.findtext('url',''))
 hosts[u.hostname or '']=hosts[u.hostname or '']+1
 for key in urllib.parse.parse_qs(u.query,keep_blank_values=True):keys[key]+=1
 for tag,counter in [('price',price_kinds),('oldprice',old_kinds)]:
  v=offer.findtext(tag,'').strip()
  try:counter['positive' if __import__('decimal').Decimal(v)>0 else 'zero_or_negative']+=1
  except Exception:counter['nonnumeric']+=1
 text=name+' '+desc
 checks={'departure_date_expression':r'\d{2}[./]\d{2}[./]\d{2,4}','nights_expression':r'\d+\s*(?:ноч|nights)','party_expression':r'(?:\d+\s*(?:взросл|adult)|на\s*двоих|за\s*двоих)','meal_expression':r'\b(?:AI|UAI|HB|FB|BB|RO)\b|вс[её] включено','room_expression':r'номер|room|superior|standard|deluxe','flight_expression':r'перел[её]т|авиабилет|flight','price_from_expression':r'(?:от\s*\d|from\s*\d)','checked_timestamp_expression':r'проверен|checked|обновлен'}
 for k,pat in checks.items():
  if re.search(pat,text,re.I):counts[k]+=1
 ident=offer.attrib.get('id','');idkinds['numeric' if ident.isdigit() else 'other']+=1
 if len(names)<5:names.append(name[:140])
 outurl=offer.findtext('url','')
 if u.hostname in ('t.me','telegram.me','max.ru'):counts['channel_or_post_link']+=1
 if any(k.lower() in ('tourid','tour_id') for k in urllib.parse.parse_qs(u.query)):counts['tour_identifier_in_url']+=1
report={'operation':'commercial-price-source-20261104-moscow-yml-inspection2','state':'ORIGINAL_SOURCE_ONLY_SCHEMA_AND_PRICE_TERMS_REVIEWED_NO_NEW_GET','offers':len(offers),'source_sha256':__import__('hashlib').sha256(data).hexdigest(),'price_value_kinds':dict(price_kinds),'oldprice_value_kinds':dict(old_kinds),'offer_destination_host_counts':dict(hosts),'offer_url_parameter_name_counts':dict(keys),'offer_id_shapes':dict(idkinds),'text_term_presence_counts':dict(counts),'first_five_public_offer_names':names,'source_gets':0,'gateway_calls':0,'db_calls':0,'price_edits':0,'producer_date_raw':root.attrib.get('date')}
(temp/'moscow-yml-inspection2-summary.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(report,ensure_ascii=False))
