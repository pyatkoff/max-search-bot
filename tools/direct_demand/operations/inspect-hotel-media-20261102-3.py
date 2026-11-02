import base64,html,json,re,urllib.parse,urllib.request
from pathlib import Path
operation='commercial-batch12-public-media-20261102-read2'
pages=["https://partner.jazhotels.com/hotel/58","https://partner.jazhotels.com/hotel/12","https://partner.jazhotels.com/hotel/64","https://partner.jazhotels.com/hotel/93","https://partner.jazhotels.com/hotel/66","https://partner.jazhotels.com/hotel/2","https://partner.jazhotels.com/hotel/5","https://partner.jazhotels.com/hotel/68","https://partner.jazhotels.com/hotel/61"]
hosts=set(["jazhotels.com","www.jazhotels.com","partner.jazhotels.com","images.jazhotels.com","storage.googleapis.com","jaz-resorts.net","www.jaz-resorts.net"])
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
 for value in re.findall(r"""["']([^"'<>\r\n]+\.(?:jpe?g|png|webp)(?:\?[^"'<>\r\n]*)?)["']""",text,re.I):
  absolute=urllib.parse.quote(urllib.parse.urljoin(final,html.unescape(value.strip())),safe=':/?&=%#')
  p=urllib.parse.urlsplit(absolute)
  if p.scheme=='https' and p.hostname in hosts and not p.username and not p.password and absolute not in candidates:candidates.append(absolute)
  documents=[]
 for value in re.findall(r"""["']([^"'<>\r\n]+\.pdf(?:\?[^"'<>\r\n]*)?)["']""",text,re.I):
  absolute=urllib.parse.quote(urllib.parse.urljoin(final,html.unescape(value.strip())),safe=':/?&=%#');p=urllib.parse.urlsplit(absolute)
  if p.scheme=='https' and p.hostname in hosts and not p.username and not p.password and absolute not in documents:documents.append(absolute)
 out['pages'].append({'source_url':url,'final_url':final,'image_urls':candidates[:320],'document_urls':documents[:12]})
out['ok']=True
Path('public-media-sources.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(out,ensure_ascii=False))
