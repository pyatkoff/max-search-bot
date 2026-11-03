import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":74503,\"url\":\"https://export-download.canva.com/291dc299-b54e-4ee0-9b80-b4d6dc47404e/0/0001-5023160542403656737.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T141153Z&X-Amz-Expires=32950&X-Amz-Signature=23372a8e3dc8dda6d6fef195f42f746ad6dfcff08930d238f45237db43e6819b&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2023%3A21%3A03%20GMT\"},{\"hotel_id\":1582,\"url\":\"https://export-download.canva.com/9406460c-5ce0-4548-ba5d-9ee126e0c874/0/0001-5408218311815948298.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T011735Z&X-Amz-Expires=78721&X-Amz-Signature=8a2e6c58a10b1bcaa37b56ef4032a4a110db8302521eab57ccbd58d60f265168&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2023%3A09%3A36%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert group in (0,) and len(rows)==2
rows=rows[group*2:group*2+2]
assert 1<=len(rows)<=2
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch31-covers-20261103-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (74503,1582)
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
