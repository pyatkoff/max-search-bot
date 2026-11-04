import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":1416,\"role\":\"h1416_photo_0\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/10-06-2024-15-36-00oceana%20%281%29.jpg\"},{\"hotel_id\":1416,\"role\":\"h1416_photo_1\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-7-31-34-PM10-9-2020-10-58-49-am2.jpg\"},{\"hotel_id\":1416,\"role\":\"h1416_photo_2\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-7-32-44-PM10-9-2020-10-59-41-amd2.jpg\"},{\"hotel_id\":1416,\"role\":\"h1416_photo_3\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-7-32-44-PM10-13-2020-10-47-29-amoceanabeach1.jpg\"},{\"hotel_id\":1491,\"role\":\"h1491_photo_0\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/10-06-2024-15-34-21perissia%20%281%29.jpg\"},{\"hotel_id\":1491,\"role\":\"h1491_photo_1\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-7-13-38-PM10-8-2020-12-33-43-pmperissia-aerial-4-copy_edit.jpg\"},{\"hotel_id\":1491,\"role\":\"h1491_photo_2\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-7-12-55-PM10-8-2020-12-33-13-pmdji_0274_edit.jpg\"},{\"hotel_id\":1491,\"role\":\"h1491_photo_3\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-7-14-25-PM10-8-2020-12-34-19-pmstandard-sea_edit.jpg\"}],[{\"hotel_id\":65782,\"role\":\"h65782_photo_0\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/19-01-2026-11-16-22finesse-web-anaslider%20%282%29.jpg\"},{\"hotel_id\":65782,\"role\":\"h65782_photo_1\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-8-31-25-PM10-10-2020-9-02-32-pminfess.jpg\"},{\"hotel_id\":65782,\"role\":\"h65782_photo_2\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-8-30-29-PM12-12-2020-12-57-54-pmspotlight-room.jpg\"},{\"hotel_id\":65782,\"role\":\"h65782_photo_3\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/image-1-6-2021-10-52-38-AMfinessepool.jpg\"},{\"hotel_id\":1417,\"role\":\"h1417_photo_0\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/18-08-2025-10-38-19pg_otel_ana_sayfa_ana_fotograf_foto1.jpg\"},{\"hotel_id\":1417,\"role\":\"h1417_photo_1\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/18-08-2025-10-38-19pg_otel_ana_sayfa_ana_fotograf_foto2.jpg\"},{\"hotel_id\":1417,\"role\":\"h1417_photo_2\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/18-08-2025-10-38-19pg_otel_ana_sayfa_ana_fotograf_foto3.jpg\"},{\"hotel_id\":1417,\"role\":\"h1417_photo_3\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/image-18-08-2025-10-01-37pg_palomaqua.jpg\"},{\"hotel_id\":1417,\"role\":\"h1417_photo_4\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/image-18-08-2025-10-05-32pg_lake_suite.jpg\"}],[{\"hotel_id\":1348,\"role\":\"h1348_photo_0\",\"url\":\"https://fileservice.sevenseashotels.com/files/md/resources/img/ad1d1cb8-3dec-4fbb-b174-a41e6ec3edc5.jpg\"},{\"hotel_id\":1348,\"role\":\"h1348_photo_1\",\"url\":\"https://fileservice.sevenseashotels.com/files/md/resources/img/9708b25a-113f-4bea-8fbd-f916cb6ba03a.jpg\"},{\"hotel_id\":1348,\"role\":\"h1348_photo_2\",\"url\":\"https://fileservice.sevenseashotels.com/files/md/resources/img/87e247b1-61d2-4c4e-9c87-d3746281fa96.jpg\"},{\"hotel_id\":1348,\"role\":\"h1348_photo_3\",\"url\":\"https://fileservice.sevenseashotels.com/files/md/resources/img/efdef9e6-d3bb-4b4a-a9ec-3a57d15df0c4.jpg\"},{\"hotel_id\":1348,\"role\":\"h1348_photo_4\",\"url\":\"https://fileservice.sevenseashotels.com/files/md/resources/img/bfbbcbc6-4ede-4ea7-9784-2e3dacede681.jpg\"}],[{\"hotel_id\":15879,\"role\":\"h15879_photo_0\",\"url\":\"https://www.belazur.com.tr/image/manset/693017858ed9e.jpg\"},{\"hotel_id\":15879,\"role\":\"h15879_photo_1\",\"url\":\"https://www.belazur.com.tr/image/manset/685d3e321de1d.jpg\"},{\"hotel_id\":15879,\"role\":\"h15879_gallery_0\",\"url\":\"https://www.belazur.com.tr/image/icerik/galeri_65cc673ed2e37.jpg\"},{\"hotel_id\":15879,\"role\":\"h15879_gallery_1\",\"url\":\"https://www.belazur.com.tr/image/icerik/galeri_65cc673ed32df.jpg\"},{\"hotel_id\":15879,\"role\":\"h15879_gallery_2\",\"url\":\"https://www.belazur.com.tr/image/icerik/galeri_65cc673ed379d.jpg\"},{\"hotel_id\":15879,\"role\":\"h15879_room\",\"url\":\"https://www.belazur.com.tr/image/icerik/standard-land-view-room_668d43f717b6e.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=10
hosts={"www.palomahotels.com","fileservice.sevenseashotels.com","www.belazur.com.tr"}
def safe(url):
 p=urllib.parse.urlsplit(url)
 if p.scheme!='https' or p.hostname not in hosts or p.port is not None or p.username or p.password or p.fragment or url.count('https://')!=1:return False
 if p.query:
  if p.hostname in ('assets.builderassets.com','image-tc.galaxy.tf'):
   q=urllib.parse.parse_qs(p.query,strict_parsing=True)
   if p.hostname=='assets.builderassets.com':
    if not set(q).issubset({'format','quality','width','height','mode'}):return False
    if q.get('format',['jpg'])[0] not in ('jpg','jpeg','png','webp'):return False
    if q.get('mode',['crop'])[0] not in ('crop','fit','cover','contain'):return False
   else:
    if not set(q).issubset({'rotate','crop','width','height'}):return False
    if q.get('rotate',['0'])!=['0']:return False
    if 'crop' in q and not __import__('re').fullmatch(r'\d+,\d+,\d+,\d+',q['crop'][0]):return False
   for key in ('width','height','quality'):
    if key in q and (len(q[key])!=1 or not q[key][0].isdigit() or not 1<=int(q[key][0])<=6000):return False
   return True
  if p.hostname!='cache.marriott.com':return False
  q=urllib.parse.parse_qs(p.query,strict_parsing=True)
  if not set(q).issubset({'fit','wid','downsize','interpolation','output-quality'}):return False
  if q.get('wid',['750'])!=['750']:return False
  if any(v not in ('constrain','750','750px:*','progressive-bilinear','70') for vs in q.values() for v in vs):return False
 return True
for bad in ['http://partner.jazhotels.com/a.jpg','https://partner.jazhotels.com/a.jpg?token=private','https://firebasestorage.googleapis.com/a.jpg','https://user@partner.jazhotels.com/a.jpg','https://partner.jazhotels.com:443/a.jpg','https://cache.marriott.com/a?access_token=private']:
 assert not safe(bad)
assert len({(r['hotel_id'],r['role']) for rs in groups for r in rs})==sum(map(len,groups))
assert all(safe(r['url']) for rs in groups for r in rs)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_photo_redirect')
opener=urllib.request.build_opener(NoRedirect())
sheet=Image.new('RGB',(960,math.ceil(len(rows)/3)*270),'white');draw=ImageDraw.Draw(sheet)
out={'operation':'commercial-batch34-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
for index,row in enumerate(rows):
 x=(index%3)*320;y=(index//3)*270
 label=str(row['hotel_id'])+' '+row['role']
 draw.text((x+6,y+6),label,fill='black')
 try:
  stage='fetch';observed={}
  with opener.open(urllib.request.Request(row['url'],headers={'User-Agent':'Mozilla/5.0'}),timeout=25) as r:
   raw=r.read(5000001);observed={'http_status':r.status,'content_type':r.headers.get('Content-Type','')[:100],'received_prefix_bytes':len(raw)}
   stage='byte_guard'
   if r.status!=200 or not 10000<len(raw)<=5000000:raise ValueError('source_image_byte_guard')
  stage='decode';im=Image.open(io.BytesIO(raw));im.load()
  observed['format']=im.format;observed['width'],observed['height']=im.size
  stage='format_guard'
  if im.format not in ('JPEG','PNG','WEBP'):raise ValueError('source_image_format_guard')
  width,height=im.size;stage='dimension_guard'
  if not (max(width,height)>=500 and min(width,height)>=250 and max(width,height)<=6000):raise ValueError('source_image_dimension_guard')
  meta={'hotel_id':row['hotel_id'],'role':row['role'],'index':index,'source_url':row['url'],'format':im.format,'width':width,'height':height,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
  out['images'].append(meta)
  preview=ImageOps.contain(im.convert('RGB'),(310,230))
  sheet.paste(preview,(x+5+(310-preview.width)//2,y+30+(230-preview.height)//2))
 except Exception as e:
  error={'hotel_id':row['hotel_id'],'role':row['role'],'index':index,'error':type(e).__name__}
  if isinstance(e,urllib.error.HTTPError):error['http_status']=e.code
  error['stage']=stage;error['observed']=observed
  out['rejections'].append(error);draw.text((x+8,y+45),error['error'],fill='red')
buf=io.BytesIO();sheet.save(buf,format='PNG');raw=buf.getvalue()
Path('contact.png').write_bytes(raw);Path('primary-image-inspection.json').write_text(json.dumps(out,ensure_ascii=False)+'\n')
parts=[base64.b64encode(raw).decode()[i:i+48000] for i in range(0,len(base64.b64encode(raw)),48000)]
print(json.dumps(out,ensure_ascii=False),flush=True)
print(json.dumps({'kind':'contact','group':group,'width':sheet.width,'height':sheet.height,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'chunks':len(parts)}),flush=True)
for i,p in enumerate(parts):print(json.dumps({'kind':'contact_chunk','group':group,'index':i,'total':len(parts),'data':p}),flush=True)
