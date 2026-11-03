import base64,html,json,os,re,urllib.parse,urllib.request
from pathlib import Path
from html.parser import HTMLParser
operation='commercial-batch24-six-new-photo-source-pages'
pages=["https://partner.jazhotels.com/hotel/4","https://partner.jazhotels.com/hotel/3","https://partner.jazhotels.com/hotel/6","https://www.swissinn.net/corporate/Photo-gallery.php?hotelid=Hurghada&page=2","https://www.swissinn.net/corporate/Photo-gallery.php?hotelid=Hurghada&page=3","https://www.hwaidak-hotels.com/hotels/titanic-palace"]
group=int(os.environ['MEDIA_GROUP'])
assert group in (0,1)
pages=pages[group*3:group*3+3]
assert 1<=len(pages)<=3
hosts=set(["partner.jazhotels.com","www.swissinn.net","swissinn.net","www.hwaidak-hotels.com","storage.googleapis.com"])
class Redirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_primary_source_redirect')
opener=urllib.request.build_opener(Redirect())
out={'operation':operation,'mode':'read_public_photo_sources_only','message_sends':0,'db_writes':0,'server_connections':0,'pages':[]}
for url in pages:
 try:
  response=opener.open(urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'}),timeout=25)
  with response as r:
   raw=r.read(2000001)
   if len(raw)>2000000:raise ValueError('source_size_limit')
   final=r.url
   text=raw.decode('utf-8',errors='replace').replace('\\/','/')
 except Exception as e:
  out['pages'].append({'source_url':url,'error':type(e).__name__});continue
 candidates=[]
 documents=[]
 image_details=[]
 album_context=[]
 image_hosts=set()
 for value in re.findall(r"""["']([^"'<>\r\n]+\.(?:jpe?g|png|webp)(?:\?[^"'<>\r\n]*)?)["']""",text,re.I):
  absolute=urllib.parse.quote(urllib.parse.urljoin(final,html.unescape(value.strip())),safe=':/?&=%#')
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
  absolute=urllib.parse.urljoin(final,html.unescape(value));p=urllib.parse.urlsplit(absolute)
  if p.scheme=='https' and p.hostname in hosts and not p.username and not p.password and not re.search(r'token|authkey|api_key|password|firebase',absolute,re.I):
   if p.hostname in ('www.jazhotels.com','jazhotels.com') and '/hoteldetail/' in p.path and absolute not in website_links:website_links.append(absolute)
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
 out['pages'].append({'source_url':url,'final_url':final,'page_title':title,'image_hosts':sorted(image_hosts),'image_urls':candidates[:180],'image_details':image_details[:160],'document_urls':documents[:12],'website_links':website_links[:10],'public_text':public_text,'json_fact_fragments':fragments,'album_context':album_context})

out['ok']=True
Path('public-media-sources.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(out,ensure_ascii=False))
