import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":165,\"url\":\"https://export-download.canva.com/d47106ce-950d-41d1-a4ea-e96ff30818ee/0/0001-6236880627684650511.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T202457Z&X-Amz-Expires=85007&X-Amz-Signature=78aa4295eb40a5525381362e7111f0b8d468f55dab09e19adba704b46662cdaf&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2020%3A01%3A44%20GMT\"},{\"hotel_id\":12116,\"url\":\"https://export-download.canva.com/293d9a17-1adb-42a7-9211-37bd744ccaac/0/0001-6324700816748120107.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T070503Z&X-Amz-Expires=46965&X-Amz-Signature=b174b7c88233ec048ea20db82eac4d34682a77cdae4abf92059c0b9991ff488b&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2020%3A07%3A48%20GMT\"},{\"hotel_id\":111,\"url\":\"https://export-download.canva.com/5817ffc9-01b4-468d-89c1-9746a85658ae/0/0001-2029392672816365266.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T230603Z&X-Amz-Expires=74272&X-Amz-Signature=e8af10a58aa4524671433edb840ca44b88ec71459a28e3e5ed93fd8bf6782e5b&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2019%3A43%3A55%20GMT\"},{\"hotel_id\":163,\"url\":\"https://export-download.canva.com/d21af37f-8ccd-4bbf-8ffd-10607a9b174d/0/0001-1005949660180187417.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261103%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261103T040403Z&X-Amz-Expires=56568&X-Amz-Signature=ac8afe83cbfb78080da978672e3c74738c5af354dcae03678be46acbfee7611a&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2019%3A46%3A51%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert group in (0,1) and len(rows)==4
rows=rows[group*3:group*3+3]
assert 1<=len(rows)<=3
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch27-covers-20261103-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (165,12116,111,163)
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
