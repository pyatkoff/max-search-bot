import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":43204,\"url\":\"https://export-download.canva.com/6bc4e2fd-901b-4572-a8eb-f96e090f7fc1/0/0001-4804735856635978829.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T185009Z&X-Amz-Expires=18751&X-Amz-Signature=923343d858a94b1299303442a5c5b93c55950b710e9e16267c05ab3efddd525b&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2000%3A02%3A40%20GMT\"},{\"hotel_id\":13870,\"url\":\"https://export-download.canva.com/3da31c74-008b-4c4d-b9de-5963cf746d37/0/0001-4623465974534614855.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T121328Z&X-Amz-Expires=42467&X-Amz-Signature=e23e60ca454170b0d98d07b28ba7a0a1799846dd192d73611492ff024459a317&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2000%3A01%3A15%20GMT\"},{\"hotel_id\":1983,\"url\":\"https://export-download.canva.com/8dc1c6c8-801a-432b-98ef-d487b1dcb9d6/0/0001-5053559738584206876.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T143505Z&X-Amz-Expires=33948&X-Amz-Signature=82a19196e8d91b566314ba2367134e325a1b5ecddf935dd2d375265718fa7f1e&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2000%3A00%3A53%20GMT\"},{\"hotel_id\":111801,\"url\":\"https://export-download.canva.com/40956709-ad74-4b28-a17b-cedf11406b1b/0/0001-7582330926689670572.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T123931Z&X-Amz-Expires=39557&X-Amz-Signature=84db3e95d2e0aa537c26a731f3360fcd1f658c460bef46db466bee53a311c015&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2023%3A38%3A48%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert 0<=group<=1 and len(rows)==4
rows=rows[group*3:group*3+3]
assert 1<=len(rows)<=3
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch15-covers-20261103-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (43204,13870,1983,111801)
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
