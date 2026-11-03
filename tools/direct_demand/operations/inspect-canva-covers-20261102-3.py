import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":1379,\"url\":\"https://export-download.canva.com/0d3e0adf-0851-446c-9090-1bb8e1f7b81e/0/0001-6734528407900088160.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T211616Z&X-Amz-Expires=12818&X-Amz-Signature=0e0d2e71cdf8ce6eba559d832874ad4259bdc4b1f3b92c10bdc9b4f9c87b15e3&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2000%3A49%3A54%20GMT\"},{\"hotel_id\":1133,\"url\":\"https://export-download.canva.com/00b91fad-6a83-4fa3-8d10-02431067ca4c/0/0001-1700629922366207510.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T162012Z&X-Amz-Expires=28841&X-Amz-Signature=cccc029b973895b5065ec3a5414c1a176fe3c428b9df49484a8c1dfaf2fe8334&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2000%3A20%3A53%20GMT\"},{\"hotel_id\":27316,\"url\":\"https://export-download.canva.com/0e4e5d2e-2385-4f7d-9f41-2b2b6160f477/0/0001-7158992671315420662.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T005509Z&X-Amz-Expires=87303&X-Amz-Signature=57c29dac1b4a409fde285329527cab214e6625b2b7e0f43c542714188da093f5&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2001%3A10%3A12%20GMT\"},{\"hotel_id\":1559,\"url\":\"https://export-download.canva.com/96b6b6be-7e25-4a56-985d-d71e1e4cb842/0/0001-4611081183121228636.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T122758Z&X-Amz-Expires=43793&X-Amz-Signature=ab8480fb2ec6479334d81e9c1ea7c1a3576f164527c7ace005d5665105a3dfb5&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2000%3A37%3A51%20GMT\"},{\"hotel_id\":3405,\"url\":\"https://export-download.canva.com/a8a073ab-762a-41b8-ad82-5f9d5fffa2cc/0/0001-862960390572850743.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T180138Z&X-Amz-Expires=25322&X-Amz-Signature=3be050b039d80048ebb0e69528c96b0312cb82c42ec31bced21439efb28bde39&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2001%3A03%3A40%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert group in (0,1,2) and len(rows)==5
rows=rows[group*2:group*2+2]
assert 1<=len(rows)<=2
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch33-covers-20261103-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (1379,1133,27316,1559,3405)
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
