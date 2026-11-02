import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":358,\"url\":\"https://export-download.canva.com/b7eaba2f-a168-4b9a-86d1-67556fade440/0/0001-8176806062104913540.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T014257Z&X-Amz-Expires=65173&X-Amz-Signature=c8d94e988c573bfd271b35191c1cae5858166bd406baf59c921a69b587b4a319&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2019%3A49%3A10%20GMT\"},{\"hotel_id\":37668,\"url\":\"https://export-download.canva.com/abf86a05-9921-42c7-b07a-aa68a8e95df8/0/0001-864086167110800459.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T084612Z&X-Amz-Expires=42430&X-Amz-Signature=6d1a695f282dd8f3c80fed223ad8524ba8e649d5596cef7a6bfcdf1e10c8b140&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2020%3A33%3A22%20GMT\"},{\"hotel_id\":174,\"url\":\"https://export-download.canva.com/1455895f-f912-4075-9caa-378bdf26a4fb/0/0001-8559612031043429479.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261101%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261101T195116Z&X-Amz-Expires=87225&X-Amz-Signature=516a5d8cc0648e5cf9463f5c25ea2baeb131042d92ae92bfdcd9b6eb099285be&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2020%3A05%3A01%20GMT\"},{\"hotel_id\":55574,\"url\":\"https://export-download.canva.com/d7bcb142-4025-40aa-af2b-1416a7a6d34d/0/0001-8747637315924178052.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T024430Z&X-Amz-Expires=60822&X-Amz-Signature=ee1339def580a55ff6e2ecaa3dd9d02932c2b3d4abcc787cfda2968aec41ec9c&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2019%3A38%3A12%20GMT\"},{\"hotel_id\":80964,\"url\":\"https://export-download.canva.com/4ed65eca-5947-4c78-b579-92b9ac546c23/0/0001-1026215752274184124.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T180717Z&X-Amz-Expires=5437&X-Amz-Signature=fd665366d6dfca10d8b76a0e2c2f1eb8efdc56f6143f4846e4f501d050e3b366&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Mon%2C%2002%20Nov%202026%2019%3A37%3A54%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert 0<=group<=1 and len(rows)==5
rows=rows[group*3:group*3+3]
assert 1<=len(rows)<=3
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch11-covers-20261102-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (358,37668,174,55574,80964)
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
