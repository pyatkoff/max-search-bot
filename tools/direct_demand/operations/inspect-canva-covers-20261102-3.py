import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":264,\"url\":\"https://export-download.canva.com/8b913b25-1f91-4161-85a3-d8fa4561e878/0/0001-5905865976345983179.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T172643Z&X-Amz-Expires=30024&X-Amz-Signature=592ce721d741bfbf17b3fac4a353238b9575c12611b84981a1aa5610b24d8afe&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2001%3A47%3A07%20GMT\"},{\"hotel_id\":73342,\"url\":\"https://export-download.canva.com/b4f4e42b-a534-4076-b6d5-93ff0e730b9b/0/0001-7821021715970782101.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T025102Z&X-Amz-Expires=82761&X-Amz-Signature=1b9b66742dfd9cb3fea43b40df5ee1bd25963e4cbc04eac218dd322b4350ec79&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2001%3A50%3A23%20GMT\"},{\"hotel_id\":278,\"url\":\"https://export-download.canva.com/d48b6541-3a90-4445-8e73-fe17f19e110f/0/0001-7770356222649952232.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T054001Z&X-Amz-Expires=70960&X-Amz-Signature=0603345a4d7a8f096b2b7ecdb037599208ad093de488769c5cab86647752cad0&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2001%3A22%3A41%20GMT\"},{\"hotel_id\":246,\"url\":\"https://export-download.canva.com/f82114cc-2290-445e-a956-ddb5f732f3ce/0/0001-1849248611545126008.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T052100Z&X-Amz-Expires=74366&X-Amz-Signature=18d506aae8e9aa1760b078b793bfe3b9c6ebf6f58a976f3770e189408932c08c&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2002%3A00%3A26%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert 0<=group<=1 and len(rows)==4
rows=rows[group*3:group*3+3]
assert 1<=len(rows)<=3
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch17-covers-20261103-read2','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (264,73342,278,246)
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
