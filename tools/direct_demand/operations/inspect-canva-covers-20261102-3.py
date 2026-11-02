import base64,hashlib,json,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":416,\"design_id\":\"DAHZxbmX-To\",\"url\":\"https://export-download.canva.com/e5f7af30-fada-4691-a6df-a94d1af0d26e/0/0001-8172302442482739662.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261101%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261101T170924Z&X-Amz-Expires=81015&X-Amz-Signature=70e13efb332345659f1183d3b772c84e0302056f8ccf6db3127e372c87d3208a&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2015%3A39%3A39%20GMT\"},{\"hotel_id\":478,\"design_id\":\"DAHZxXXP7TQ\",\"url\":\"https://export-download.canva.com/3ea4926b-847f-4d41-8b7f-751354045d09/0/0001-236959898353060852.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261101%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261101T151730Z&X-Amz-Expires=86287&X-Amz-Signature=a07bfd566c564e9665d42273d786e23930dce92fba5588ecda9f9e51a9ab1580&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2015%3A15%3A37%20GMT\"}]")
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-three-covers-20261102-8-read2','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (416,478)
 with opener.open(row['url'],timeout=35) as r:
  raw=r.read(1600001)
  assert r.status==200
 assert 10000<len(raw)<=1600000 and raw[:8]==b'\x89PNG\r\n\x1a\n'
 width,height=struct.unpack('>II',raw[16:24]);assert 500<=width<=2500 and 500<=height<=2500
 digest=hashlib.sha256(raw).hexdigest();filename=str(row['hotel_id'])+'.png';Path('covers',filename).write_bytes(raw)
 b64=base64.b64encode(raw).decode();parts=[b64[i:i+48000] for i in range(0,len(b64),48000)]
 meta={'hotel_id':row['hotel_id'],'design_id':row['design_id'],'mime':'image/png','width':width,'height':height,'bytes':len(raw),'sha256':digest,'file':filename,'chunks':len(parts)}
 out['covers'].append(meta);print(json.dumps({'kind':'cover',**meta}),flush=True)
 for i,value in enumerate(parts):print(json.dumps({'kind':'cover_chunk','hotel_id':row['hotel_id'],'index':i,'total':len(parts),'data':value}),flush=True)
out['ok']=True
Path('native-cover-receipt.json').write_text(json.dumps(out,indent=2)+'\n');print(json.dumps(out),flush=True)
