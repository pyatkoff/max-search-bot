import base64,copy,html,json,os,re,urllib.parse,urllib.request
from pathlib import Path
from html.parser import HTMLParser
operation='commercial-batch-20261105-51-primary-preparation1'
base=Path('tools/direct_demand/operations')
index=json.loads((base/'commercial-batch-20261105-51-source-index.json').read_text())
scope=json.loads((base/'commercial-batch-20261105-50-native-scope.json').read_text())['new_native_identities']
parents={p['source_url']:p for p in json.loads((base/'commercial-batch-20261105-51-parent-proof.json').read_text())['pages']}
expected_ids=[1420,1421,1253,15904,15913,78251,27349,108942,1260,1217]
expected_pages=["https://www.cullinanhotels.com/gallery","https://www.cullinanhotels.com/multimedia","https://www.maxxroyal.com/maxx-royal-kemer/experiences/pool-and-beach","https://www.maxxroyal.com/maxx-royal-bodrum/experiences/pool-and-beach"]
expected_native=[78251,78251,27349,108942]
def validate_native_scope(rows):
 assert len(rows)==10 and len({r['hotel_id'] for r in rows})==10 and {r['hotel_id'] for r in rows}==set(expected_ids)
 assert all(r['country_id']==4 and r['is_active']==1 and r['registry_matches']=={'telegram':0,'max':0} for r in rows)
def validate_declared_sources(rows):
 assert [r['url'] for r in rows]==expected_pages and [r['hotel_id'] for r in rows]==expected_native
 for row in rows:
  assert row['declared_by'] in parents
  parent=parents[row['declared_by']]
  assert parent['hotel_id']==row['hotel_id'] and row['url'] in parent['website_links']
  p=urllib.parse.urlsplit(row['url'])
  assert p.scheme=='https' and p.hostname in {'www.cullinanhotels.com','www.maxxroyal.com'} and not p.query and not p.fragment and not p.username and not p.password and p.port is None
validate_native_scope(scope);validate_declared_sources(index['sources'])
group=int(os.environ['MEDIA_GROUP']);assert group in (0,1)
if group==0:
 bad=copy.deepcopy(scope);bad[0]['hotel_id']=1422
 try:validate_native_scope(bad);raise RuntimeError('publishednativeaccepted')
 except AssertionError:pass
 bad=copy.deepcopy(scope);bad[0]['country_id']=1
 try:validate_native_scope(bad);raise RuntimeError('wrongcountryaccepted')
 except AssertionError:pass
 bad=copy.deepcopy(index['sources']);bad[0]['url']+='-not-declared'
 try:validate_declared_sources(bad);raise RuntimeError('undeclaredhrefaccepted')
 except AssertionError:pass
 print(json.dumps({'operation':operation,'state':'NEW_EXACT_SCOPE_INPUT_CASES_PASSED','new_input_cases':3,'old_parser_fixtures_rerun':0,'server_calls':0,'photo_gets':0}),flush=True)
def metadata_urls(v):
 if isinstance(v,str):return [v] if v.startswith('https://') else []
 if isinstance(v,list):return [u for x in v for u in metadata_urls(x)]
 if isinstance(v,dict):return [u for x in v.values() for u in metadata_urls(x)]
 return []
excluded=set(index['closed_source_exclusions']);metadata_files=[]
for p in sorted(set(base.glob('commercial-*-source-index.json'))|set(base.glob('commercial-*-primary*plan.json'))):
 if p.name.startswith('commercial-batch-20261105-51-') or 'miniapp' in p.name:continue
 d=json.loads(p.read_text());excluded.update(metadata_urls(d));metadata_files.append(p.name)
hits=[u for u in expected_pages if u in excluded]
assert not hits,('CLOSED_OLD_PRIMARY_URL_DEFERRED_NO_GET',hits)
print(json.dumps({'operation':operation,'state':'NEW_DECLARED_OWNER_HREFS_METADATA_DEDUP_PASSED','metadata_files_checked':len(metadata_files),'metadata_filenames':metadata_files,'old_metadata_url_count':len(excluded),'new_owner_pages':4,'old_owner_get_repeats':0,'server_calls':0,'photo_gets':0}),flush=True)
pages=[r['url'] for r in index['sources'] if r['group']==group]
assert len(pages)==2 and os.environ.get('SOURCE_INSPECTOR_FIXTURE')!='1'
hosts=set(['www.cullinanhotels.com','www.maxxroyal.com'])
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
Path('public-media-sources.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(out,ensure_ascii=False))
