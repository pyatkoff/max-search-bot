import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":9322,\"url\":\"https://export-download.canva.com/23d34714-37bd-4527-9dde-dbc4c71d6442/0/0001-613010644882922373.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261104%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261104T052720Z&X-Amz-Expires=15729&X-Amz-Signature=278f0983b4fffa4209dbaf4ae0cb264f99750b8452ab91da97b1d3c98144b9ff&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2009%3A49%3A29%20GMT\"},{\"hotel_id\":1672,\"url\":\"https://export-download.canva.com/1e52b2f7-a07f-4115-833c-a552b4f5098c/0/0001-5192045569393059706.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261104%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261104T055528Z&X-Amz-Expires=11050&X-Amz-Signature=d1fe405a4c27faceedaba779b9a82c559be0f1a3cdc704763ba49ed748c426f8&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Wed%2C%2004%20Nov%202026%2008%3A59%3A38%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert group==0 and len(rows)==2
assert 1<=len(rows)<=2
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch36-covers-20261104-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (9322,1672)
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
