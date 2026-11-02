import base64,html,json,re,urllib.parse,urllib.request
from pathlib import Path
operation='commercial-three-media-20261102-3-read1'
pages=['https://dreamfunworld.com.tr/en/','https://www.jadorehotel.com/en/','https://www.sidemoonpalace.com/tr']
hosts={'dreamfunworld.com.tr','www.dreamfunworld.com.tr','jadorehotel.com','www.jadorehotel.com','sidemoonpalace.com','www.sidemoonpalace.com'}
class Redirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,req,fp,code,msg,headers,newurl):
  p=urllib.parse.urlsplit(newurl)
  if p.scheme!='https' or p.hostname not in hosts or p.username or p.password or p.port not in (None,443):raise ValueError('redirect_outside_public_sources')
  return super().redirect_request(req,fp,code,msg,headers,newurl)
opener=urllib.request.build_opener(Redirect())
out={'operation':operation,'mode':'read_public_photo_sources_only','message_sends':0,'db_writes':0,'server_connections':0,'pages':[]}
for url in pages:
 with opener.open(urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'}),timeout=30) as r:
  raw=r.read(2000001)
  if len(raw)>2000000:raise ValueError('source_size_limit')
  final=r.url
  text=raw.decode('utf-8',errors='replace').replace('\\/','/')
 candidates=[]
 for value in re.findall(r"""["'(]([^"'<>()[\]\s]+\.(?:jpe?g|png|webp)(?:\?[^"'<>()[\]\s]*)?)""",text,re.I):
  absolute=urllib.parse.urljoin(final,html.unescape(value))
  p=urllib.parse.urlsplit(absolute)
  if p.scheme=='https' and p.hostname in hosts and not p.username and not p.password and absolute not in candidates:candidates.append(absolute)
 out['pages'].append({'source_url':url,'final_url':final,'image_urls':candidates[:80]})
out['ok']=True
Path('public-media-sources.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(out,ensure_ascii=False))
