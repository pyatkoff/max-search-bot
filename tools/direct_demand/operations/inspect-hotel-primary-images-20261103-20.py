import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":231,\"role\":\"hero\",\"url\":\"https://redseahotels.com/wp-content/uploads/2023/03/The-Grand-Makadi.jpg\"},{\"hotel_id\":441,\"role\":\"hero\",\"url\":\"https://redseahotels.com/wp-content/uploads/2023/02/Siva-Grand-Beach-in-Hurghada-Egypt.jpg\"},{\"hotel_id\":483,\"role\":\"hero_candidate2\",\"url\":\"https://d1wo7kaelp5eck.cloudfront.net/sunrise-resorts.com-1611976553/cms/cache/v2/64bfa16ba5db4.jpg/1920x1080/fit/80/9f38aaf7584d314a088ff2a6822f65c6.jpg\"},{\"hotel_id\":483,\"role\":\"hero_candidate3\",\"url\":\"https://d1wo7kaelp5eck.cloudfront.net/sunrise-resorts.com-1611976553/cms/cache/v2/64bfa1848e62e.jpg/1920x1080/fit/80/a9a744933c79ea9fe8252c8a9c7ebcf7.jpg\"}]")
group=int(os.environ['MEDIA_GROUP']);assert group in (0,1)
rows=rows[group*2:group*2+2];assert len(rows)==2
hosts={'redseahotels.com','d18slle4wlf9ku.cloudfront.net','d1wo7kaelp5eck.cloudfront.net'}
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_photo_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'operation':'commercial-batch20-primary-heroes-20261103-read2','mode':'inspect_exact_public_primary_images','server_connections':0,'message_sends':0,'db_writes':0,'images':[]}
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
 assert 500<=width<=6000 and 500<=height<=6000
 b64=base64.b64encode(raw).decode();parts=[b64[i:i+48000] for i in range(0,len(b64),48000)]
 meta={'hotel_id':row['hotel_id'],'role':row['role'],'mime':mime,'width':width,'height':height,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'chunks':len(parts)}
 out['images'].append(meta);print(json.dumps({'kind':'source_image',**meta}),flush=True)
 for i,value in enumerate(parts):print(json.dumps({'kind':'source_image_chunk','hotel_id':row['hotel_id'],'index':i,'total':len(parts),'data':value}),flush=True)
out['ok']=True
Path('primary-image-inspection.json').write_text(json.dumps(out,sort_keys=True))
print(json.dumps(out,sort_keys=True),flush=True)
