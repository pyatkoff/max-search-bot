import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":1033,\"role\":\"g0_general_og\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/6e3e5919-7d5e-1915-544b-2f05dcc1ce49/d6e2fe74932d8bcf03ac176309a19dcc.webp\"},{\"hotel_id\":1033,\"role\":\"g0_pool_page_og\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/65338e37-9283-7ef9-3157-662abd45c8d8/1976b0e6dda48bf111894b894fffdc1e.webp\"},{\"hotel_id\":1033,\"role\":\"g0_main_pool\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/a3b80c91-9264-4417-b9e8-329d47c292b3/8bb366cbc3ffc7ca621bd7eeef6b217d.webp\"},{\"hotel_id\":1033,\"role\":\"g0_relax_pool\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/40f2d1d0-ae74-45e2-bbb3-167d83ac3333/6d058c33f0437ca39f64f61917ded77c.webp\"},{\"hotel_id\":1031,\"role\":\"g0_gallery_og\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/07f66fa6-66db-1db4-a0f2-743eaf074a43/2495cbeb8956613ef78efc9e3303960d.webp\"},{\"hotel_id\":1031,\"role\":\"g0_root_feature\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/28e9c0c4-1fbe-4b14-a782-b668d3225a72/9a1d749befac6195129cac5fd916d930.jpg\"},{\"hotel_id\":1031,\"role\":\"g0_gallery_experience3\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/00373166-4246-9aca-7b8b-1855295f0729/a050a4603218e35d48aaf39298959632.jpg\"},{\"hotel_id\":1031,\"role\":\"g0_gallery_experience4\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/00373166-4246-9aca-7b8b-1855295f0729/afad0232df85765a1a5911c94ff94472.jpg\"}],[{\"hotel_id\":1032,\"role\":\"g1_gallery0\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/44cd5a59-71df-f571-8699-fe372a9eb493/18eef3e70195ac587d9ec7dadd17eefd.jpg\"},{\"hotel_id\":1032,\"role\":\"g1_gallery1\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/44cd5a59-71df-f571-8699-fe372a9eb493/effe9a4cf9d82dd7b204fe626f53b418.jpg\"},{\"hotel_id\":1032,\"role\":\"g1_gallery2\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/44cd5a59-71df-f571-8699-fe372a9eb493/ca64088f527e4861bcdf959b3f1b5c55.jpg\"},{\"hotel_id\":1032,\"role\":\"g1_gallery3\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/44cd5a59-71df-f571-8699-fe372a9eb493/5affa6002a31bdfd59be0beba16a5ae1.jpg\"},{\"hotel_id\":1032,\"role\":\"g1_main_extra0\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/12e0c34a-ae65-1835-d76f-b7e7e66fa130/0662483c115bab784b0fb86f17fc8e18.webp\"},{\"hotel_id\":1032,\"role\":\"g1_main_extra1\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/12e0c34a-ae65-1835-d76f-b7e7e66fa130/b7af3c418b07b0d1a083c9e177378c0f.webp\"},{\"hotel_id\":512,\"role\":\"g1_overview_other\",\"url\":\"https://irp.cdn-website.com/7bfa4f38/dms3rep/multi/opt/Overview%2B1-1920w.jpg\"},{\"hotel_id\":512,\"role\":\"g1_dahab2023\",\"url\":\"https://irp.cdn-website.com/7bfa4f38/dms3rep/multi/opt/Dahab%2B2023%2B%28118%29u-1920w.jpg\"}],[{\"hotel_id\":74503,\"role\":\"g2_ng0\",\"url\":\"https://ngphaselisbay.com/media/i34pkitt/ng-phaselis-bay-general2.jpg\"},{\"hotel_id\":74503,\"role\":\"g2_ng1\",\"url\":\"https://ngphaselisbay.com/media/3eepcelu/beach-slider.jpg\"},{\"hotel_id\":74503,\"role\":\"g2_ng2\",\"url\":\"https://ngphaselisbay.com/media/tp4aivya/beluga-bay.jpg\"},{\"hotel_id\":74503,\"role\":\"g2_ng3\",\"url\":\"https://ngphaselisbay.com/media/3jfls1da/ng-dji_0179.jpg\"},{\"hotel_id\":74503,\"role\":\"g2_ng4\",\"url\":\"https://ngphaselisbay.com/media/d2bpsbxi/ng-phaselis-bay-general-17-rev.jpg\"},{\"hotel_id\":512,\"role\":\"g2_dahab0\",\"url\":\"https://irp.cdn-website.com/7bfa4f38/dms3rep/multi/opt/Dahab%2B%2818%29-1920w.JPG\"},{\"hotel_id\":512,\"role\":\"g2_dahab1\",\"url\":\"https://irp.cdn-website.com/7bfa4f38/dms3rep/multi/opt/Dahab%2B%2811%29-1920w.JPG\"}],[{\"hotel_id\":1582,\"role\":\"g3_facilities_native\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/medialist/637685251602090528.jpg\"},{\"hotel_id\":1582,\"role\":\"g3_facilities_cached\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/medialist/637685253176602567.jpg\"},{\"hotel_id\":1582,\"role\":\"g3_sports\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/medialist/637685251476721814.jpg\"},{\"hotel_id\":1582,\"role\":\"g3_room\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/medialist/637685251697734366.jpg\"},{\"hotel_id\":1582,\"role\":\"g3_pool_beach\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/medialist/637685252232418106.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=10
hosts={"api.baruthotelsgroup.com","ngphaselisbay.com","voyagecdn.blob.core.windows.net","irp.cdn-website.com"}
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
out={'operation':'commercial-batch31-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
