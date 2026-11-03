import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":62504,\"url\":\"https://export-download.canva.com/44ec97ad-34a3-402f-adb4-fc0d08226380/0/0001-7196147312990382369.png?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAQYCGKMUH4R7NKVBA%2F20261102%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261102T104419Z&X-Amz-Expires=91738&X-Amz-Signature=586f01ee1bbf194203837129d99ae13b13c598de55fbfee2c2dba60c5262f8d2&X-Amz-SignedHeaders=host%3Bx-amz-expected-bucket-owner&response-expires=Tue%2C%2003%20Nov%202026%2012%3A13%3A17%20GMT\"}]")
group=int(os.environ['COVER_GROUP'])
assert group==0 and len(rows)==1
rows=rows[group*3:group*3+3]
assert 1<=len(rows)<=3
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_asset_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch20-covers-20261103-read2','mode':'inspect_native_promotional_images_only','message_sends':0,'db_writes':0,'server_connections':0,'group':group,'covers':[]}
Path('covers').mkdir(exist_ok=True)
for row in rows:
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='export-download.canva.com' and p.port in (None,443) and not p.username and not p.password
 assert row['hotel_id'] in (62504,)
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

# One different first-party general-view candidate;inspect bytes only,no design or server mutation.
candidate_url="https://images.squarespace-cdn.com/content/v1/687def6f61ca990db754f20c/701b9c1c-25b1-495c-adc4-25034de2d2c9/DJI_20250129170253_0058_D.JPG"

p=urllib.parse.urlsplit(candidate_url);assert p.scheme=='https' and p.hostname=='images.squarespace-cdn.com' and not p.query and not p.username and not p.password
with opener.open(candidate_url,timeout=35) as r:
 raw=r.read(5000001);assert r.status==200 and 10000<len(raw)<=5000000 and raw[:2]==b'\xff\xd8'
pos=2;dims=None
while pos<len(raw)-10:
 assert raw[pos]==255
 while raw[pos]==255:pos+=1
 marker=raw[pos];pos+=1
 if marker in (0xD8,0xD9):continue
 size=struct.unpack('>H',raw[pos:pos+2])[0];assert size>=2
 if marker in (0xC0,0xC1,0xC2,0xC3,0xC5,0xC6,0xC7,0xC9,0xCA,0xCB,0xCD,0xCE,0xCF):
  height,width=struct.unpack('>HH',raw[pos+3:pos+7]);dims=(width,height);break
 pos+=size
assert dims and 500<=dims[0]<=6000 and 500<=dims[1]<=6000
b64=base64.b64encode(raw).decode();parts=[b64[i:i+48000] for i in range(0,len(b64),48000)]
meta={'kind':'source_image','hotel_id':62504,'role':'different_general_view_candidate','mime':'image/jpeg','width':dims[0],'height':dims[1],'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'chunks':len(parts)}
print(json.dumps(meta),flush=True)
for i,value in enumerate(parts):print(json.dumps({'kind':'source_image_chunk','hotel_id':62504,'index':i,'total':len(parts),'data':value}),flush=True)
