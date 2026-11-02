import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":109149,\"url\":\"https://export-download.canva.com/bf989c53-ab23-4c6d-923a-aec32b5777ed/0/0001-7101571673426510367.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T112646Z&X-Amz-Expires=49094&X-Amz-Signature=17b418acd7e8c7a3bd44f73e958622ce3fb422d144495a7cda90541d117f7f44&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2001%3A05%3A00%20GMT\"},{\"hotel_id\":62505,\"url\":\"https://export-download.canva.com/a96fdf29-0656-4c01-be16-dfd717ad238f/0/0001-2726324632754596799.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T144228Z&X-Amz-Expires=36772&X-Amz-Signature=c600afb757de5681ab4189de36e0b48d6ea65a64b9f0a34aa2f2738c7c24d789&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2000%3A55%3A20%20GMT\"},{\"hotel_id\":4184,\"url\":\"https://export-download.canva.com/e87e3e5f-f3a2-4def-86f9-41990646babc/0/0001-433992424141631150.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T073918Z&X-Amz-Expires=61177&X-Amz-Signature=d646ddcd25af0caf44391d6e158c828d96bd847f4a3d044e65eadf9c01796f3e&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2000%3A38%3A55%20GMT\"},{\"hotel_id\":80964,\"url\":\"https://export-download.canva.com/1c13266c-e814-41bc-a0ee-71be0f42f74d/0/0001-5832682476745200964.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T134045Z&X-Amz-Expires=39772&X-Amz-Signature=a631b4e8fc704a4efe0769a3e577e8ca5856a1f03e0270cc9c352f3ba558a964&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2000%3A43%3A37%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert 0<=group<=1 and len(rows)==4
rows=rows[group*3:group*3+3]
assert 1<=len(rows)<=3
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch16-corrected-covers-20261103-read1','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (109149,62505,4184,80964)
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
