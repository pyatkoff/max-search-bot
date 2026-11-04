import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
groups=json.loads("[[{\"hotel_id\":1659,\"url\":\"https://export-download.canva.com/0040bc2b-003c-4547-98f5-0df76471976c/0/0001-2029392742105609120.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T182450Z&X-Amz-Expires=62901&X-Amz-Signature=3d2dad16cf9974a038cbfd7c8c93c544fa90a1365755cbcdeb43ab1e1e0e63e6&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2011%3A53%3A11%20GMT\"},{\"hotel_id\":21695,\"url\":\"https://export-download.canva.com/16c82016-c139-40da-b8e4-c2106205f6c8/0/0001-3353451033442440585.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261104%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261104T092259Z&X-Amz-Expires=8229&X-Amz-Signature=b0eb102122702b3acf602ec2ab5388d16c9346ee5e168dadffab63abac9716a4&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2011%3A40%3A08%20GMT\"}],[{\"hotel_id\":28668,\"url\":\"https://export-download.canva.com/c122a484-24fc-48a2-b6c0-f3b86299e7ef/0/0001-6832481743951325736.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261104%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261104T012605Z&X-Amz-Expires=38386&X-Amz-Signature=89f041f80c4d9734d03618adb4c0fba24daeb671a3e55c2a3a5977a8aaa99783&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2012%3A05%3A51%20GMT\"},{\"hotel_id\":55205,\"url\":\"https://export-download.canva.com/d7035c9a-99a9-4c33-b8ea-1532ee5eb755/0/0001-8882745476667241554.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261104%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261104T024406Z&X-Amz-Expires=34348&X-Amz-Signature=7fc72a43dd79181a1c9a5c62fd5fbfb66bf5033c39c5f78a4e6ecf9c9090238e&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2012%3A16%3A34%20GMT\"}],[{\"hotel_id\":1334,\"url\":\"https://export-download.canva.com/102f1fae-b381-4a93-ab1b-7d92bfa45a69/0/0001-4347620652104625515.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261104%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261104T075420Z&X-Amz-Expires=15599&X-Amz-Signature=cf746cdf604f0b97ec9e9b31237f158323b745e7ce024af928757e11c6592b64&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2012%3A14%3A19%20GMT\"},{\"hotel_id\":1715,\"url\":\"https://export-download.canva.com/1f2841d5-3a41-4bd3-b79e-4436a32b1e42/0/0001-4303710553476792724.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T173509Z&X-Amz-Expires=64844&X-Amz-Signature=90722472842b9f7b2a62dcaa13a1974e5d689dfbd59bd5d73c5df8d9490cdd85&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2011%3A35%3A53%20GMT\"}]]")
group=int(os.environ['COVER_GROUP'])
assert group in (0,1,2) and len(groups)==3 and all(len(g)==2 for g in groups)
rows=groups[group]
assert [r['hotel_id'] for g in groups for r in g]==[1659,21695,28668,55205,1334,1715]
assert 1<=len(rows)<=2
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch37-covers-20261104-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (1659,21695,28668,55205,1334,1715)
 with opener.open(row['url'],timeout=35) as r:
  raw=r.read(1600001)
  assert r.status==200
 assert 10000<len(raw)<=1600000 and raw[:8]==b'\x89PNG\r\n\x1a\n'
 width,height=struct.unpack('>II',raw[16:24]);assert 500<=width<=2500 and 500<=height<=2500
 digest=hashlib.sha256(raw).hexdigest();filename=str(row['hotel_id'])+'.png';Path('covers',filename).write_bytes(raw)
 b64=base64.b64encode(raw).decode();parts=[b64[i:i+48000] for i in range(0,len(b64),48000)]
 meta={'hotel_id':row['hotel_id'],'mime':'image/png','width':width,'height':height,'bytes':len(raw),'sha256':digest,'file':filename,'chunks':len(parts)}
 out['covers'].append(meta);print(json.dumps({'kind':'cover',**meta}),flush=True)
 for i,value in enumerate(parts):print(json.dumps({'kind':'cover_chunk','hotel_id':row['hotel_id'],'index':i,'total':len(parts),'data':value}),flush=True)
out['ok']=True
Path('native-cover-receipt.json').write_text(json.dumps(out,indent=2)+'\n');print(json.dumps(out),flush=True)
