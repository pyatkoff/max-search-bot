import base64,hashlib,json,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":9373,\"design_id\":\"DAHZw80g18E\",\"url\":\"https://export-download.canva.com/080513fd-d7dc-41eb-b610-a94712e4cb48/0/0001-2139730731802971800.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T062704Z&X-Amz-Expires=22150&X-Amz-Signature=38f3522933c644f03ba7df1e0c0156b31e30128605332c91cccf04beca363427&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2012%3A36%3A14%20GMT\"},{\"hotel_id\":32577,\"design_id\":\"DAHZw3tUPNs\",\"url\":\"https://export-download.canva.com/a2aa8314-03e9-4800-97d1-6c193bb8ea62/0/0001-2139730733631198885.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261101%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261101T205702Z&X-Amz-Expires=55844&X-Amz-Signature=348239d25aa20ff57eed7b1d74a243597d1d1e487fb840923755bfd25490223b&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2012%3A27%3A46%20GMT\"},{\"hotel_id\":4191,\"design_id\":\"DAHZw6uno4A\",\"url\":\"https://export-download.canva.com/4f66f7e2-52bc-48f5-ac5f-85b9d80ea078/0/0001-1545255578845923074.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261101%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261101T153447Z&X-Amz-Expires=75136&X-Amz-Signature=a816a711d781b13efc32bb6cc5a02e31b97907489dd0f181516f629f104a3152&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2012%3A27%3A03%20GMT\"}]")
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-three-covers-20261102-7-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (9373,32577,4191)
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
