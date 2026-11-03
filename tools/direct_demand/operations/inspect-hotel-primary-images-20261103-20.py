import base64,hashlib,json,os,struct,urllib.parse,urllib.request
from pathlib import Path
rows=json.loads("[{\"hotel_id\":310,\"role\":\"feed\",\"url\":\"https://image-tc.galaxy.tf/wijpeg-bunhs9glqpbhgr07p92hv73nb/028-ls-landscape-104108318.jpg\"},{\"hotel_id\":1971,\"role\":\"feed\",\"url\":\"https://image-tc.galaxy.tf/wijpeg-43nq1ttkef5hcqekzjvfa0g4j/over-view.jpg\"},{\"hotel_id\":381,\"role\":\"feed\",\"url\":\"https://static.wixstatic.com/media/ba792b_95b54e203a9344ad8bf3a2a6f28f5fe2~mv2.jpg\"},{\"hotel_id\":469,\"role\":\"feed\",\"url\":\"https://irp.cdn-website.com/cfc540a7/dms3rep/multi/opt/Picture3-1920w.jpg\"},{\"hotel_id\":68323,\"role\":\"feed\",\"url\":\"https://d1wo7kaelp5eck.cloudfront.net/sunrise-resorts.com-1611976553/cms/cache/v2/6a7dadb70fe86.jpg/1920x1080/fit/80/03cf3dca760da83c5d65e9d27666d25f.webp\"},{\"hotel_id\":67910,\"role\":\"feed\",\"url\":\"https://www.ahstatic.com/photos/b6f4_ho_00_p_1024x768.jpg\"}]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<3
rows=rows[group*2:group*2+2];assert len(rows)==2
hosts={'image-tc.galaxy.tf','static.wixstatic.com','irp.cdn-website.com','d1wo7kaelp5eck.cloudfront.net','www.ahstatic.com'}
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_photo_redirect')
opener=urllib.request.build_opener(NoRedirect())
out={'rejections':[],'operation':'commercial-hotel-feed-20261103-six-image-bytes-read1','mode':'inspect_exact_public_primary_images','server_connections':0,'message_sends':0,'db_writes':0,'images':[]}
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
