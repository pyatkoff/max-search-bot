import base64,copy,hashlib,html,json,os,re,urllib.parse,urllib.request
from pathlib import Path
from html.parser import HTMLParser
operation='commercial-batch-20261105-55-primary-preparation1'
base=Path('tools/direct_demand/operations')
filename=os.environ['PRIMARY_SOURCE_INDEX_FILENAME'];assert Path(filename).name==filename
raw=(base/filename).read_bytes();assert hashlib.sha256(raw).hexdigest()==os.environ['EXPECTED_PRIMARY_SOURCE_INDEX_SHA256'];index=json.loads(raw)
nativefile=index['native_scope_file'];assert Path(nativefile).name==nativefile
raw=(base/nativefile).read_bytes();assert hashlib.sha256(raw).hexdigest()==os.environ['EXPECTED_PRIMARY_NATIVE_SCOPE_SHA256'];scope=json.loads(raw)['native_identities']
expected_ids=[102031];expected_sources=index['sources']
def validate_native_scope(rows):
 assert len(rows)==1 and [r['hotel_id'] for r in rows]==expected_ids
 r=rows[0]
 assert r['name']=='STEIGENBERGER NILE PALACE LUXOR' and r['country_id']==1 and r['is_active']==1
 assert r['registry_matches']=={'telegram':0,'max':0} and r['publication_eligibility']=='CURRENT_PUBLICATION_PROOF_REQUIRED_BEFORE_SEND'
 assert r['provenance']=={"inventory":"commercial-stock-20261105-51-inventory.json","inventory_ref":"ed3b9ce311ce77b229e0a0b85cb1c138049530d1","exact_candidate_sha256":"37731c617fe6e9cea7d16ca991215d090c648bcf86f5ef98929e331d2e9c5bf5"}
def validate_declared_sources(rows):
 assert len(rows)==1 and [r['hotel_id'] for r in rows]==expected_ids
 row=rows[0];p=urllib.parse.urlsplit(row['url'])
 assert row['url']==row['declared_url']=='https://hrewards.com/en/steigenberger-nile-palace-luxor'
 assert row['discovery_proof']=='public_primary_official_property_search_result' and row['discovery_title']=='Steigenberger Nile Palace - Luxor | H Rewards' and row['discovery_query']=='"Steigenberger Nile Palace Luxor" official gallery'
 assert row['native_country_id']==1 and row['group']==0 and row['identity_tokens']==['steigenberger','nile','palace','luxor']
 assert p.scheme=='https' and p.hostname==row['owner_host']=='hrewards.com' and not p.query and not p.fragment and not p.username and not p.password and p.port is None
validate_native_scope(scope);validate_declared_sources(expected_sources)
group=int(os.environ['MEDIA_GROUP']);assert group==0
cases=0
for label in ['foreign_native','wrong_name','country','inactive','registered','publish_eligible','foreign_path','query','foreign_host','foreign_source_native','duplicate']:
 n=copy.deepcopy(scope);s=copy.deepcopy(expected_sources)
 if label=='foreign_native':n[0]['hotel_id']=74258
 elif label=='wrong_name':n[0]['name']='STEIGENBERGER ALCAZAR'
 elif label=='country':n[0]['country_id']=4
 elif label=='inactive':n[0]['is_active']=0
 elif label=='registered':n[0]['registry_matches']={'telegram':0,'max':1}
 elif label=='publish_eligible':n[0]['publication_eligibility']='READY'
 elif label=='foreign_path':s[0]['url']+='foreign/'
 elif label=='query':s[0]['url']+='?foreign=1'
 elif label=='foreign_host':s[0]['url']=s[0]['declared_url']='https://foreign.invalid/property'
 elif label=='foreign_source_native':s[0]['hotel_id']=74258
 else:n.append(copy.deepcopy(n[0]));s.append(copy.deepcopy(s[0]))
 try:validate_native_scope(n);validate_declared_sources(s)
 except AssertionError:cases+=1
 else:raise RuntimeError('NEW_NILE_PALACE_SCOPE_NEGATIVE_ACCEPTED:'+label)
assert cases==11
print(json.dumps({'operation':operation,'state':'NEW_ACTUAL_OWNER_INPUT_CASES_PASSED','new_input_cases':cases,'old_parser_fixtures_rerun':0,'server_calls':0,'photo_gets':0}),flush=True)
def metadata_urls(v):
 if isinstance(v,str):return [v] if v.startswith('https://') else []
 if isinstance(v,list):return [u for x in v for u in metadata_urls(x)]
 if isinstance(v,dict):return [u for x in v.values() for u in metadata_urls(x)]
 return []
excluded=set(index['closed_source_exclusions']);metadata_files=[]
for path in sorted(set(base.glob('commercial-*-source-index.json'))|set(base.glob('commercial-*-primary*plan.json'))):
 if path.name.startswith('commercial-batch-20261105-55-') or 'miniapp' in path.name:continue
 excluded.update(metadata_urls(json.loads(path.read_text())));metadata_files.append(path.name)
def source_equivalent(uri):
 p=urllib.parse.urlsplit(uri)
 return (p.scheme,(p.hostname or '').removeprefix('www.'),p.path.rstrip('/'),p.query)
closed_equivalent={source_equivalent(u) for u in excluded}
selected=[r for r in expected_sources if r['group']==group]
deferred=[{'hotel_id':r['hotel_id'],'source_url':r['url'],'reason':'ALREADY_CLOSED_SOURCE_OR_URL_VARIANT_NO_GET'} for r in selected if r['url'] in excluded or source_equivalent(r['url']) in closed_equivalent]
pages=[r['url'] for r in selected if all(d['hotel_id']!=r['hotel_id'] for d in deferred)]
assert len(selected)==1 and len(pages)<=1 and os.environ.get('SOURCE_INSPECTOR_FIXTURE')!='1'
hosts={r['owner_host'] for r in expected_sources}
print(json.dumps({'operation':operation,'state':'CLOSED_METADATA_DEDUP_BEFORE_FIRST_OWNER_GET','metadata_files_checked':len(metadata_files),'new_owner_gets_planned_this_group':len(pages),'old_owner_get_repeats':0,'deferred_before_http':deferred,'server_calls':0,'photo_gets':0}),flush=True)
class Redirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_primary_source_redirect')
opener=urllib.request.build_opener(Redirect())
def declared_public_page_href(value,final):
 if not isinstance(value,str) or not value.strip():return None
 absolute=urllib.parse.urljoin(final,html.unescape(value.strip()))
 p=urllib.parse.urlsplit(absolute);origin=urllib.parse.urlsplit(final)
 host=(p.hostname or '').removeprefix('www.');own=(origin.hostname or '').removeprefix('www.')
 if p.scheme!='https' or p.hostname not in hosts or host!=own or p.username or p.password or p.port is not None or p.query or p.fragment:return None
 if re.search(r'/(?:admin|api|booking|reserve|reservation|login|register|account|payment|checkout|search|ajax|customer|user)(?:/|$)',p.path,re.I):return None
 if re.search(r'\.(?:jpe?g|png|webp|gif|svg|pdf|zip|rar|7z|css|js|mp4|webm)(?:$|/)',p.path,re.I):return None
 return absolute
def safe_declared_reference(value,final):
 if not isinstance(value,str) or not value.strip():return None
 joined=urllib.parse.urljoin(final,html.unescape(value.strip()))
 try:pp=urllib.parse.urlsplit(joined);port=pp.port
 except ValueError:return None
 decoded=urllib.parse.unquote(joined)
 if pp.scheme!='https' or not pp.hostname or pp.username or pp.password or pp.fragment or port is not None or joined.count('https://')!=1:return None
 if not re.fullmatch(r'[A-Za-z0-9.-]+',pp.hostname):return None
 if 'firebasestorage' in decoded.lower() or re.search(r'(?:[?&])(?:token|access_token|api_key|auth|key|signature|sig|x-amz-signature|x-goog-signature)=',decoded,re.I):return None
 if pp.hostname in ('localhost','127.0.0.1','::1') or pp.hostname.endswith(('.internal','.local')):return None
 if not re.search(r'\.(?:jpe?g|png|webp)(?:\?|$)',joined,re.I):return None
 return urllib.parse.quote(joined,safe=':/?&=%#')
out={'operation':operation,'mode':'read_public_photo_sources_only','message_sends':0,'db_writes':0,'server_connections':0,'pages':[]}
fixture_mode=os.environ.get('SOURCE_INSPECTOR_FIXTURE')=='1'
if fixture_mode:pages=pages[:1]
for url in pages:
 try:
  if fixture_mode:
   raw=b'<html><img alt src="https://assets.builderassets.com/fixture.jpg"><img alt src="https://assets.builderassets.com/blocked.jpg?token=private"></html>'
   final=url
  else:
   parsed_source=urllib.parse.urlsplit(url)
   assert parsed_source.scheme=='https' and parsed_source.hostname in hosts and not parsed_source.username and not parsed_source.password
   response=opener.open(urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'}),timeout=25)
   with response as r:
    if r.status!=200 or r.url!=url:raise ValueError('unexpected_primary_status_or_url')
    if r.headers.get_content_type() not in ('text/html','application/xhtml+xml'):raise ValueError('unexpected_primary_source_type')
    raw=r.read(2000001)
    if len(raw)>2000000:raise ValueError('source_size_limit')
    final=r.url
  text=raw.decode('utf-8',errors='replace').replace('\\/','/')
 except Exception as e:
  out['pages'].append({'source_url':url,'error':type(e).__name__,'http_status':getattr(e,'code',None)});continue
 candidates=[]
 declared_references=[]
 documents=[]
 image_details=[]
 album_context=[]
 image_hosts=set()
 for value in re.findall(r"""["']([^"'<>\r\n]+\.(?:jpe?g|png|webp)(?:\?[^"'<>\r\n]*)?)["']""",text,re.I):
  absolute=urllib.parse.quote(urllib.parse.urljoin(final,html.unescape(value.strip())),safe=':/?&=%#')
  public_reference=safe_declared_reference(value,final)
  if public_reference and public_reference not in declared_references:declared_references.append(public_reference)
  p=urllib.parse.urlsplit(absolute)
  if p.scheme=='https' and not p.username and not p.password and absolute.count('https://')==1 and 'firebasestorage' not in absolute.lower():
   image_hosts.add(p.hostname)
   if p.hostname in hosts and not re.search(r'(?:^|&)(?:token|access_token|api_key|auth|key)=',p.query,re.I) and absolute not in candidates:candidates.append(absolute)
 for value in re.findall(r"""["']([^"'<>\r\n]+\.pdf(?:\?[^"'<>\r\n]*)?)["']""",text,re.I):
  absolute=urllib.parse.quote(urllib.parse.urljoin(final,html.unescape(value.strip())),safe=':/?&=%#');p=urllib.parse.urlsplit(absolute)
  if p.scheme=='https' and p.hostname in hosts and not p.username and not p.password and absolute not in documents:documents.append(absolute)
 for marker in re.finditer(r'(?:data-category|data-filter|data-gallery|data-album|data-title|data-hotel|data-name)=["\x27][^"\x27]{1,120}["\x27]|Abu Soma|Queen Resort|Jannah Resort|Sun Resort|Star Resort',text,re.I):
  fragment=text[max(0,marker.start()-220):marker.end()+420]
  if not re.search(r'token|password|secret|firebase',fragment,re.I):album_context.append(fragment)
  if len(album_context)>=40:break
 for tag in re.findall(r'<img\b[^>]*>',text,re.I):
  alt=re.search(r'\balt=["\x27]([^"\x27]*)["\x27]',tag,re.I)
  alt=html.unescape(alt.group(1)) if alt else ''
  for value in re.findall(r'["\x27]([^"\x27<>]+\.(?:jpe?g|png|webp)(?:\?[^"\x27<>]*)?)["\x27]',tag,re.I):
   absolute=urllib.parse.quote(urllib.parse.urljoin(final,html.unescape(value.strip())),safe=':/?&=%#')
   if absolute in candidates:image_details.append({'url':absolute,'alt':alt[:180]})
 class Visible(HTMLParser):
  def __init__(self):super().__init__();self.skip=0;self.parts=[]
  def handle_starttag(self,tag,attrs):
   if tag in ('script','style'):self.skip+=1
  def handle_endtag(self,tag):
   if tag in ('script','style') and self.skip:self.skip-=1
  def handle_data(self,data):
   if not self.skip and data.strip():self.parts.append(data.strip())
 visible=Visible();visible.feed(text)
 public_text=re.sub(r'https?://\S+','[url omitted]',' '.join(visible.parts))
 public_text=re.sub(r'\s+',' ',public_text)[:10000]
 title_match=re.search(r'<title[^>]*>(.*?)</title>',text,re.I|re.S)
 title=html.unescape(re.sub('<[^>]+>',' ',title_match.group(1)))[:220] if title_match else None
 website_links=[]
 for value in re.findall(r'href=["\x27]([^"\x27<>]+)["\x27]',text,re.I):
  absolute=declared_public_page_href(value,final)
  if absolute and absolute not in website_links:website_links.append(absolute)

 def declared_public_image(value):
  if not isinstance(value,str):return None
  absolute=urllib.parse.quote(urllib.parse.urljoin(final,html.unescape(value.strip())),safe=':/?&=%#')
  pp=urllib.parse.urlsplit(absolute)
  if pp.hostname=='www.elgouna.com' and pp.path=='/_next/image':
   original=urllib.parse.parse_qs(pp.query).get('url',[])
   if len(original)==1:absolute=original[0];pp=urllib.parse.urlsplit(absolute)
  if pp.scheme!='https' or pp.hostname not in hosts or pp.username or pp.password or pp.fragment or absolute.count('https://')!=1:return None
  if 'firebasestorage' in absolute.lower() or re.search(r'(?:^|&)(?:token|access_token|api_key|auth|key)=',pp.query,re.I):return None
  if not re.search(r'\.(?:jpe?g|png|webp)(?:\?|$)',absolute,re.I):return None
  return absolute
 class Membership(HTMLParser):
  def __init__(self):super().__init__();self.stack=[];self.rows=[]
  def handle_starttag(self,tag,attrs):
   a=dict(attrs)
   lineage=self.stack+[(tag,a)]
   if tag in ('img','a','source'):
    for key in ('src','data-src','href','data-image','data-original'):
     uri=declared_public_image(a.get(key,''))
     if not uri:continue
     categories=[]
     for _,aa in lineage:
      for kk,vv in aa.items():
       if kk in ('data-category','data-categories','data-album','data-gallery-category','data-filter') and vv:categories.append({kk:vv[:100]})
     if uri not in candidates:candidates.append(uri)
     row={'url':uri,'tag':tag,'alt':(a.get('alt') or '')[:180],'categories':categories[-4:]}
     if row not in self.rows:self.rows.append(row)
   if tag not in ('area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'):self.stack.append((tag,a))
  def handle_endtag(self,tag):
   for i in range(len(self.stack)-1,-1,-1):
    if self.stack[i][0]==tag:self.stack=self.stack[:i];break
 membership=Membership();membership.feed(text)
 gallery_memberships=membership.rows[:500]

 fragments=[]
 def selected_json(v,path='',depth=0):
  if depth>14 or len(fragments)>=16:return
  if isinstance(v,dict):
   for k,value in v.items():
    if re.search(r'token|auth|secret|password|price|rate|booking|customer|guest',str(k),re.I):continue
    child=path+'.'+str(k)
    if isinstance(value,str) and re.search(r'description|overview|amenit|facilit|feature|highlight|aboutHotel',str(k),re.I) and 40<len(value)<12000:
     plain=html.unescape(re.sub('<[^>]+>',' ',value))
     plain=re.sub(r'https?://\S+','[url omitted]',plain)
     if not re.search(r'token|Bearer|firebase|password',plain,re.I):fragments.append({'path':child[-180:],'text':plain[:1800]})
    else:selected_json(value,child,depth+1)
  elif isinstance(v,list):
   for value in v[:100]:selected_json(value,path,depth+1)
 for body in re.findall(r'<script[^>]*(?:type=["\x27]application/(?:ld\\+)?json["\x27]|id=["\x27]__NEXT_DATA__["\x27])[^>]*>(.*?)</script>',text,re.I|re.S):
  try:selected_json(json.loads(html.unescape(body)))
  except (ValueError,TypeError):pass
 out['pages'].append({'source_url':url,'final_url':final,'page_title':title,'image_hosts':sorted(image_hosts),'image_urls':candidates[:180],'declared_public_image_references':declared_references[:300],'image_details':image_details[:160],'document_urls':documents[:12],'website_links':website_links[:30],'public_text':public_text,'json_fact_fragments':fragments,'album_context':album_context,'gallery_memberships':gallery_memberships})

out['ok']=True
if fixture_mode:
 assert len(out['pages'])==1
 rows=out['pages'][0]['gallery_memberships']
 assert len(rows)==1 and rows[0]['alt']=='' and rows[0]['url']=='https://assets.builderassets.com/fixture.jpg'
 assert all('token=' not in u for u in out['pages'][0]['image_urls'])
 print('PRIMARY_SOURCE_NULL_ALT_FIXTURE_OK public_image=kept token_query=rejected http_calls=0')
 raise SystemExit(0)
for page in out['pages']:
 row=next(r for r in selected if r['url']==page['source_url']);page['hotel_id']=row['hotel_id']
 if not page.get('error'):
  normalized=re.sub('[^a-z0-9]+',' ',((page.get('page_title') or '')+' '+page.get('public_text','')[:1200]).lower())
  page['property_identity_tokens_matched']=all(token in normalized.split() for token in row['identity_tokens'])
  if not page['property_identity_tokens_matched']:page['error']='declared_property_text_identity_not_confirmed'
out['deferred_before_http']=deferred
Path('public-media-sources.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
safe={'operation':operation,'state':'NEW_OWNER_SOURCE_SCOPE_CLOSED_NO_MEDIA_OR_DELIVERY_ACCEPTANCE','group':group,'new_owner_http_gets':len(pages),'readable_identity_bound_pages':sum(not p.get('error') for p in out['pages']),'old_owner_get_repeats':0,'deferred_before_http':deferred,'server_connections':0,'db_reads':0,'db_writes':0,'provider_calls':0,'photo_gets':0,'message_sends':0,'canva_transactions':0,'new_posts':0,'ready_for_send':0,'old_parser_fixtures_rerun':0,'pages':[{'hotel_id':p['hotel_id'],'source_url':p['source_url'],'error':p.get('error'),'http_status':p.get('http_status',200 if not p.get('error') else None),'property_identity_tokens_matched':p.get('property_identity_tokens_matched',False),'public_text_characters':len(p.get('public_text','')),'public_text_sha256':hashlib.sha256(p.get('public_text','').encode()).hexdigest(),'declared_public_images':len(p.get('declared_public_image_references',[])),'owner_host_images':len(p.get('image_urls',[]))} for p in out['pages']]}
Path('public-media-sources-summary.json').write_text(json.dumps(safe)+'\n');print(json.dumps(safe),flush=True)
