import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":21476,\"role\":\"hero\",\"url\":\"https://www.cleopatraluxuryhotels.com/wp-content/uploads/sites/55/2023/07/Cleopatra-Luxury-Resort-Makadi-Bay-Exterior-6.jpg\"},{\"hotel_id\":21476,\"role\":\"left\",\"url\":\"https://www.cleopatraluxuryhotels.com/wp-content/uploads/sites/55/2023/07/Untitled-design-39.jpg\"},{\"hotel_id\":21476,\"role\":\"right\",\"url\":\"https://www.cleopatraluxuryhotels.com/wp-content/uploads/sites/55/2023/07/Cleopatra-Luxury-Resort-Makadi-Bay-Hippo-Kids-Club-Playing-Room-2.jpg\"}]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<2
rows=rows[group*2:group*2+2];assert 1<=len(rows)<=2
hosts={'www.cleopatraluxuryhotels.com'}
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_photo_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'rejections':[],'operation':'commercial-batch21-cleopatra-images-20261103-read1','mode':'inspect_exact_public_primary_images','server_connections':0,'message_sends':0,'db_writes':0,'images':[]}
for row in rows:
 p=urllib.parse.urlsplit(row['url']);assert p.scheme=='https' and p.hostname in hosts and p.port is None and not p.username and not p.password and not p.query and not p.fragment
 with opener.open(row['url'],timeout=30) as r:
  raw=r.read(5000001);assert r.status==200 and 10000<len(raw)<=5000000
 if raw[:8]==b'\x89PNG\r\n\x1a\n':width,height=struct.unpack('>II',raw[16:24]);mime='image/png'
 else:
  assert raw[:2]==b'\xff\xd8';mime='image/jpeg';pos=2;dims=None
  while pos<len(raw)-10:
   assert raw[pos]==255
   while raw[pos]==255:pos+=1
   marker=raw[pos];pos+=1
   if marker in (0xD8,0xD9):continue
   size=struct.unpack('>H',raw[pos:pos+2])[0];assert size>=2
   if marker in (0xC0,0xC1,0xC2,0xC3,0xC5,0xC6,0xC7,0xC9,0xCA,0xCB,0xCD,0xCE,0xCF):
    height,width=struct.unpack('>HH',raw[pos+3:pos+7]);dims=(width,height);break
   pos+=size
  assert dims
 print(json.dumps({'kind':'source_dimensions','hotel_id':row['hotel_id'],'width':width,'height':height}),flush=True)
 if max(width,height)<500 or min(width,height)<250 or max(width,height)>6000:
  out['rejections'].append({'hotel_id':row['hotel_id'],'state':'SOURCE_IMAGE_DIMENSION_GUARD','width':width,'height':height});continue
 b64=base64.b64encode(raw).decode();parts=[b64[i:i+48000] for i in range(0,len(b64),48000)]
 meta={'hotel_id':row['hotel_id'],'role':row['role'],'mime':mime,'width':width,'height':height,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'chunks':len(parts)}
 out['images'].append(meta);print(json.dumps({'kind':'source_image',**meta}),flush=True)
 for i,value in enumerate(parts):print(json.dumps({'kind':'source_image_chunk','hotel_id':row['hotel_id'],'index':i,'total':len(parts),'data':value}),flush=True)
out['ok']=True
Path('primary-image-inspection.json').write_text(json.dumps(out,sort_keys=True))
print(json.dumps(out,sort_keys=True),flush=True)
