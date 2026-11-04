import collections,datetime,html,json,os,pathlib,re,unicodedata,urllib.parse,xml.etree.ElementTree as ET
temp=pathlib.Path(os.environ['RUNNER_TEMP'])
def one(folder,name):
 p=list((temp/folder).rglob(name));assert len(p)==1;return p[0]
raw=one('moscow-source','moscow_any.source.xml').read_bytes()
assert __import__('hashlib').sha256(raw).hexdigest()=='21ca10ff39422e7509a8fc1835b2046f68243e0c1103b1660aac6e6cf36a5996'
read9=json.loads(one('retained-read9','price-readback.json').read_text().splitlines()[-1]);assert read9['operation']=='commercial-price-monitor-20261104-read9' and len(read9['hotels'])==157
review={482,476,81173,32577,478,80964}
def norm(s):return re.sub(r'\s+',' ',unicodedata.normalize('NFKC',html.unescape(s)).strip()).casefold()
lookup=collections.defaultdict(set);provenance=collections.Counter()
for h in read9['hotels']:
 provenance[h.get('identity_provenance','')]+=1
 hid=int(h['local_tv_id']);cat=h.get('catalog',{})
 if hid in review or h.get('identity_provenance')!='accepted_native_catalog_read' or h.get('current_name_matches_expected') is not True or int(cat.get('id',0))!=hid or int(cat.get('is_active',0))!=1 or int(cat.get('country_id',0))!=int(h['expected_country_id']):continue
 for name in [cat.get('name',''),h.get('expected_name','')]:
  if name:lookup[norm(name)].add(hid)
offers=ET.fromstring(raw).findall('.//offer');counts=collections.Counter();candidates=[];date_ranges=[];night_ranges=collections.Counter();parties=collections.Counter();departures=collections.Counter();sourcecountries=collections.Counter();availability=collections.Counter()
def value(q,k):return q.get(k,[''])[0]
def date(s):
 for f in ['%Y-%m-%d','%d.%m.%Y','%Y%m%d','%d/%m/%Y']:
  try:return datetime.datetime.strptime(s,f).date()
  except ValueError:pass
 return None
today=datetime.datetime.now(datetime.timezone.utc).date()
for offer in offers:
 q=urllib.parse.parse_qs(urllib.parse.urlsplit(offer.findtext('url','')).query,keep_blank_values=True)
 availability[offer.attrib.get('available','')]+=1
 if not value(q,'hotel'):counts['general_resort_offer']+=1;continue
 counts['hotel_offer']+=1
 name=offer.findtext('name','')
 if name.startswith('Туры в '):name=name[len('Туры в '):]
 if name.endswith(' из Москвы'):name=name[:-len(' из Москвы')]
 ids=lookup.get(norm(name),set())
 if len(ids)==1:candidates.append({'native_hotel_id':next(iter(ids)),'binding_state':'POTENTIAL_EXACT_NAME_MATCH_ONLY_COUNTRY_SOURCE_ID_AND_TERMS_NOT_REVALIDATED'})
 elif ids:counts['ambiguous_name_match']+=1
 else:counts['no_exact_retained_native_name_match']+=1
 f=date(value(q,'date_from'));t=date(value(q,'date_till'))
 if f and t:
  date_ranges.append((f,t));counts['exact_departure_date' if f==t else 'departure_date_range']+=1
  if t<today:counts['date_window_already_ended']+=1
 else:counts['unparsed_date_filters']+=1
 nf=value(q,'days_from');nt=value(q,'days_till');night_ranges[nf+'..'+nt]+=1;counts['exact_nights' if nf and nf==nt else 'nights_range_or_missing']+=1
 parties[value(q,'count_people')]+=1;departures[value(q,'from')]+=1;sourcecountries[value(q,'country')]+=1
report={'operation':'commercial-price-source-20261104-moscow-yml-binding1','state':'OFFLINE_ORIGINAL_SOURCE_CROSSWALK_REVIEW_NO_SEARCHES_OR_FINAL_PRICES','original_offers':len(offers),'counts':dict(counts),'potential_exact_native_name_matches':len(candidates),'potential_unique_native_ids':sorted({r['native_hotel_id'] for r in candidates}),'actual_native_country_source_id_binding_completed':False,'typed_provenance_counts_preserved':dict(provenance),'name_review_force':False,'date_filter_aggregate':{'min_from':min((f for f,t in date_ranges),default=None).isoformat() if date_ranges else None,'max_till':max((t for f,t in date_ranges),default=None).isoformat() if date_ranges else None},'night_filter_counts':dict(night_ranges),'source_party_filter_counts':dict(parties),'source_departure_filter_value_counts_unmapped':dict(departures),'source_country_filter_value_counts_not_native_ids':dict(sourcecountries),'declared_available_attribute_counts_not_verified_availability':dict(availability),'source_gets':0,'db_calls':0,'searches':0,'gateway_calls':0,'price_edits':0}
assert len(offers)==140 and counts['hotel_offer']+counts['general_resort_offer']==140
assert not ({r['native_hotel_id'] for r in candidates}&review)
assert len(candidates)<=counts['hotel_offer']
(temp/'moscow-yml-binding-summary.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n');print(json.dumps(report,ensure_ascii=False))
