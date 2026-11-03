import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":245,\"url\":\"https://export-download.canva.com/1a08e6b1-8035-4eed-a347-5cf84f202955/0/0001-2476374888884069259.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T061930Z&X-Amz-Expires=6983&X-Amz-Signature=fc8e9f4f57b53a29635cb4bd1cd8bf47a94bf6a5e1538614b962ce7cee3d74a5&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2008%3A15%3A53%20GMT\"},{\"hotel_id\":13869,\"url\":\"https://export-download.canva.com/ee0629d3-26b9-45cc-b57e-884130f28587/0/0001-1347097284552948872.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T104043Z&X-Amz-Expires=78577&X-Amz-Signature=e920ff80bcc745f08e3c4ba9e66bfca6017825e4fb94bbbdc7a175a59c9527a2&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2008%3A30%3A20%20GMT\"},{\"hotel_id\":267,\"url\":\"https://export-download.canva.com/d0f2381a-9201-4437-afdf-d2f7a23fc062/0/0001-6830229827811525572.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T105831Z&X-Amz-Expires=79060&X-Amz-Signature=0d30a7de30b977be7b2d81dbdcda59a1877cc9b74aeeb1651b586c39ff464b29&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2008%3A56%3A11%20GMT\"},{\"hotel_id\":12114,\"url\":\"https://export-download.canva.com/e3e54dd4-54df-4c00-8f20-8c64eac39226/0/0001-7196147298567438023.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T000035Z&X-Amz-Expires=30147&X-Amz-Signature=0c0e673bf0b89a256fbbc64fcab23f55683069b0cde0b69cbc91fb730f4eafa3&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2008%3A23%3A02%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert 0<=group<=1 and len(rows)==4
rows=rows[group*3:group*3+3]
assert 1<=len(rows)<=3
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch17-covers-20261103-read3','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (245,13869,267,12114)
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
