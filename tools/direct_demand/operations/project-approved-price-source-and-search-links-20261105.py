import collections,copy,datetime,hashlib,json,os,pathlib,re,urllib.parse,xml.etree.ElementTree as ET
temp=pathlib.Path(os.environ['RUNNER_TEMP']);base=pathlib.Path('tools/direct_demand/operations')
def one(folder,name):
 p=list((temp/folder).rglob(name));assert len(p)==1,(folder,name);return p[0]
manifest=(base/'commercial-price-source-refresh-20261105-pass1-manifest.json').read_bytes()
assert hashlib.sha256(manifest).hexdigest()=='2035f0382b815f721a42dfe4a0045f75b0e1db9f0d16891ed0db8ac1081b0361'
m=json.loads(manifest);bindings={int(b['local_tv_id']):b for b in m['native_bindings']};assert len(bindings)==121
source=json.loads(one('actual-source','manual-refresh-result.private.json').read_text().splitlines()[-1])
def valid(d):
 assert d['operation']==m['operation'] and d['ok'] is True and d['complete'] is True
 assert d['manifest_sha256']==hashlib.sha256(manifest).hexdigest() and d['new_searches_attempted']==7 and d['http_attempts']<=154 and d['automatic_retry'] is False
 assert d['post_edits']==d['new_posts']==d['installed_runtime_changes']==0 and len(d['groups'])==7
 seen=set();sid=set()
 for i,g in enumerate(d['groups']):
  assert g['group_index']==i and g['state']=='success' and 0<=g['poll_requests']<=20 and g['result_requests']==1
  assert int(g['search_id'])>0 and int(g['search_id']) not in sid;sid.add(int(g['search_id']))
  assert len(g['provider_results'])==g['accepted_native_hotels']<=20
  for h in g['provider_results']:
   hid=int(h['id']);assert hid in m['groups'][i]['hotel_ids'] and hid in bindings and hid not in seen;seen.add(hid)
 return seen
actual=valid(source)
for kind in ('wrong_operation','incomplete_group','noncohort_hotel'):
 bad=copy.deepcopy(source)
 if kind=='wrong_operation':bad['operation']='wrong'
 elif kind=='incomplete_group':bad['groups']=bad['groups'][:-1]
 else:bad['groups'][0]['provider_results'][0]['id']=1422
 try:valid(bad);raise RuntimeError('new_input_case_failed')
 except (AssertionError,KeyError):pass
blocked=set();old_count=0;old_sources=[]
for folder,op,count in [('old-read9','commercial-price-monitor-20261104-read9',157),('old-read10','commercial-price-monitor-20261104-read10-72h-extension1',159)]:
 d=json.loads(one(folder,'price-readback.json').read_text().splitlines()[-1]);assert d['operation']==op and d['ok'] and len(d['hotels'])==count
 old_sources.append({'operation':op,'installed_sha':d['installed_sha']})
 for h in d['hotels']:
  for o in h['observations']:
   fp=o['retained_tour_fingerprint'];assert re.fullmatch('[0-9a-f]{64}',fp);blocked.add(fp);old_count+=1
assert old_count==85
scopes=[];reasons=collections.Counter();tour_keys=collections.Counter();hotel_keys=collections.Counter()
for i,g in enumerate(source['groups']):
 for h in g['provider_results']:
  hid=int(h['id']);novel=0;allcount=0
  hotel_keys.update(h.keys())
  for t in h.get('tours',[]):
   if not isinstance(t,dict):continue
   tour_keys.update(t.keys());allcount+=1
   value=str(t.get('id') or t.get('tourId') or '').strip()
   if not value:reasons['tour_id_missing']+=1;continue
   fp=hashlib.sha256(value.encode()).hexdigest()
   if fp in blocked:reasons['old_consumed_or_retained_fingerprint']+=1;continue
   novel+=1
  if novel:scopes.append({'binding':bindings[hid],'original_search_id':int(g['search_id']),'original_group_index':i,'raw_tour_rows':allcount,'novel_raw_tour_fingerprints':novel})
  else:reasons['hotel_without_new_fingerprint']+=1
private={'operation':'commercial-price-source-refresh-20261105-pass1-offline-input-and-search-links1','source_run':41976677950,'source_artifact':13391673842,'source_installed_sha':'627a9c6284624e63b24287bea61277ef6c8f5364','original_manifest_sha256':hashlib.sha256(manifest).hexdigest(),'source_http_attempts':source['http_attempts'],'source_observations_written':source['observation_rows_written'],'source_hotels':len(actual),'source_groups':7,'bindings':scopes,'all_original_source_hotels':sorted(actual),'old_retained_fingerprints':sorted(blocked),'old_retained_rows':old_count,'old_sources':old_sources,'original_search_ids':[int(g['search_id']) for g in source['groups']]}
(temp/'approved-source-new-scope.private.json').write_text(json.dumps(private,ensure_ascii=False)+'\n')
xml=one('original-xml','moscow_any.source.xml').read_bytes();assert hashlib.sha256(xml).hexdigest()=='21ca10ff39422e7509a8fc1835b2046f68243e0c1103b1660aac6e6cf36a5996'
offers=ET.fromstring(xml).findall('.//offer');assert len(offers)==140
known={111,150,174,293,125,1971,57028,358,67910,37668,1100,1559,1582,1543,9432,1449,27316,1199,3405}
urls=[];routes=collections.Counter();keys=collections.Counter()
for o in offers:
 url=o.findtext('url','');u=urllib.parse.urlsplit(url);q=urllib.parse.parse_qs(u.query,keep_blank_values=True);routes[u.scheme+'://'+(u.netloc or '')+u.path]+=1;keys.update(q.keys())
 assert u.scheme=='https' and u.hostname=='anytour.online'
 if q.get('hotel',[''])[0].isdigit() and int(q['hotel'][0]) in known:
  hid=int(q['hotel'][0]);assert q.get('count_people')==['2'] and q.get('from')==['1']
  urls.append({'native_hotel_id':hid,'name':o.findtext('name',''),'url':url,'filter_parameters':q,'is_search_filter_link_not_final_price_or_availability':True})
assert len(urls)==19 and {x['native_hotel_id'] for x in urls}==known
(temp/'existing-feed-hotel-search-links.public.json').write_text(json.dumps({'source_sha256':hashlib.sha256(xml).hexdigest(),'urls':urls},ensure_ascii=False,indent=2)+'\n')
report={'operation':private['operation'],'actual_completed_groups':7,'actual_source_hotels':len(actual),'actual_source_observations_written':source['observation_rows_written'],'candidate_hotels_with_new_fingerprints':len(scopes),'candidate_native_ids':[int(x['binding']['local_tv_id']) for x in scopes],'old_retained_rows_excluded_without_requoting':old_count,'old_source_provenance':old_sources,'rejection_counts':dict(reasons),'public_provider_hotel_key_names':sorted(hotel_keys),'public_provider_tour_key_names':sorted(tour_keys),'exact_original_xml_search_routes':dict(routes),'exact_original_xml_query_key_counts':dict(keys),'public_existing_search_link_examples':[x for x in urls if x['native_hotel_id'] in {67910,358,1582}],'verified_matching_existing_hotel_search_urls':len(urls),'new_input_cases':3,'old_fixtures_rerun':0,'source_gets':0,'db_calls':0,'provider_calls':0,'post_edits':0}
(temp/'source-projection-summary.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n');print(json.dumps(report,ensure_ascii=False))
