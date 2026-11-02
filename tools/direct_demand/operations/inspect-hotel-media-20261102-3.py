import base64,html,json,re,urllib.parse,urllib.request
from pathlib import Path
operation='commercial-batch9-public-media-20261102-read1'
pages=["https://www.sunrise-resorts.com/tucana-resort","https://www.sunrise-resorts.com/alma-bay-resort","https://www.sunrise-resorts.com/alora-aqua-park-resort","https://www.sunrise-resorts.com/anjum-resort","https://meraki-resort.com/hrg/","https://www.jazhotels.com/hoteldetail/25/overview","https://partner.jazhotels.com/hotel/26","https://hrewards.com/en/steigenberger-aldau-beach-hotel-hurghada","https://hrewards.com/en/steigenberger-aqua-magic-hurghada","https://hrewards.com/en/steigenberger-pure-lifestyle-hurghada"]
hosts=set(["sunrise-resorts.com","www.sunrise-resorts.com","d1wo7kaelp5eck.cloudfront.net","meraki-resort.com","www.meraki-resort.com","jazhotels.com","www.jazhotels.com","partner.jazhotels.com","images.jazhotels.com","storage.googleapis.com","jaz-resorts.net","www.jaz-resorts.net","hrewards.com","www.hrewards.com","assets.hrewards.com"])
class Redirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,req,fp,code,msg,headers,newurl):
  p=urllib.parse.urlsplit(newurl)
  if p.scheme!='https' or p.hostname not in hosts or p.username or p.password or p.port not in (None,443):raise ValueError('redirect_outside_public_sources')
  return super().redirect_request(req,fp,code,msg,headers,newurl)
opener=urllib.request.build_opener(Redirect())
out={'operation':operation,'mode':'read_public_photo_sources_only','message_sends':0,'db_writes':0,'server_connections':0,'pages':[]}
for url in pages:
 try:
  response=opener.open(urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'}),timeout=25)
 except Exception as e:
  out['pages'].append({'source_url':url,'error':type(e).__name__});continue
 with response as r:
  raw=r.read(2000001)
  if len(raw)>2000000:raise ValueError('source_size_limit')
  final=r.url
  text=raw.decode('utf-8',errors='replace').replace('\\/','/')
 candidates=[]
 for value in re.findall(r"""["'(]([^"'<>()[\]\s]+\.(?:jpe?g|png|webp)(?:\?[^"'<>()[\]\s]*)?)""",text,re.I):
  absolute=urllib.parse.urljoin(final,html.unescape(value))
  p=urllib.parse.urlsplit(absolute)
  if p.scheme=='https' and p.hostname in hosts and not p.username and not p.password and absolute not in candidates:candidates.append(absolute)
  documents=[]
 for value in re.findall(r"""["'(]([^"'<>()[\]\s]+\.pdf(?:\?[^"'<>()[\]\s]*)?)""",text,re.I):
  absolute=urllib.parse.urljoin(final,html.unescape(value));p=urllib.parse.urlsplit(absolute)
  if p.scheme=='https' and p.hostname in hosts and not p.username and not p.password and absolute not in documents:documents.append(absolute)
 out['pages'].append({'source_url':url,'final_url':final,'image_urls':candidates[:140],'document_urls':documents[:12]})
out['ok']=True
Path('public-media-sources.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(out,ensure_ascii=False))
