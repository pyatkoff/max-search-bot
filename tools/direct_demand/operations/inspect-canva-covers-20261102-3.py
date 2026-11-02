import base64,hashlib,json,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":416,\"design_id\":\"DAHZxbmX-To\",\"url\":\"https://export-download.canva.com/265c73fa-ca54-4c45-b5e9-daac7f8db3ad/0/0001-1685993076153830160.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T081912Z&X-Amz-Expires=24130&X-Amz-Signature=1b44d34aed7ddfd6551b113298bd602ce6d4d984ae752d04da1ed28f38927b05&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2015%3A01%3A22%20GMT\"},{\"hotel_id\":12112,\"design_id\":\"DAHZxZSncto\",\"url\":\"https://export-download.canva.com/ac3b1475-6c28-4bd0-9e06-17aaa0bd18f8/0/0001-535323371143943621.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T114845Z&X-Amz-Expires=12891&X-Amz-Signature=080a890756dc1dcdec436d0c250772e94163291a8a24bb45d0b81c29b572b25d&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2015%3A23%3A36%20GMT\"},{\"hotel_id\":478,\"design_id\":\"DAHZxXXP7TQ\",\"url\":\"https://export-download.canva.com/605ff25a-ff84-4bb4-b463-0dd73eac160d/0/0001-6276286997498769637.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T014602Z&X-Amz-Expires=48614&X-Amz-Signature=5015886e69c1028c28cbad11166624a32f6d3b73f02db07c3dd2b4213d10bf65&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2015%3A16%3A16%20GMT\"}]")
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-three-covers-20261102-8-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (416,12112,478)
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
