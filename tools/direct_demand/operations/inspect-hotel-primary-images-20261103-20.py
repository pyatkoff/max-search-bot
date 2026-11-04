import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":9322,\"role\":\"h9322_main\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/10-06-2024-15-40-08pasha%20%281%29.jpg\"},{\"hotel_id\":9322,\"role\":\"h9322_beach\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-8-14-16-PM10-8-2020-4-33-47-pm2.jpg\"},{\"hotel_id\":9322,\"role\":\"h9322_family\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-8-15-06-PM10-8-2020-4-35-10-pmidealforfamilie.jpg\"},{\"hotel_id\":9322,\"role\":\"h9322_room\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-8-15-43-PM10-8-2020-4-35-38-pmd1.jpg\"},{\"hotel_id\":65895,\"role\":\"h65895_videohero\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/16-10-2026-15-07-45orenda-videokapak-1920x1080.jpg\"},{\"hotel_id\":65895,\"role\":\"h65895_main\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/10-06-2024-15-24-40orenda%20%282%29.jpg\"},{\"hotel_id\":65895,\"role\":\"h65895_overview\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-20-2020-5-50-27-PM10-6-2020-1-19-55-pmorenda-index1.jpg\"},{\"hotel_id\":65895,\"role\":\"h65895_room\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/9-23-2020palomaqua-logo.png\"},{\"hotel_id\":9322,\"role\":\"h9322_extra_general\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/image-9-12-2025-12-56-57pash.jpg\"},{\"hotel_id\":65895,\"role\":\"h65895_extra_general\",\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-5-2020-1-15-47-PMorendabeachbanner.jpg\"}],[{\"hotel_id\":970,\"role\":\"h970_main\",\"url\":\"https://www.alibey.com/content/upload/hotel-promo/alibeyresort-promo-2.jpg\"},{\"hotel_id\":970,\"role\":\"h970_room\",\"url\":\"https://www.alibey.com/content/upload/room-images/alibey-resort-sorgun-promo-2.jpg\"},{\"hotel_id\":970,\"role\":\"h970_aquapark\",\"url\":\"https://www.alibey.com/content/upload/homepage-images/resort-aqua.jpg\"},{\"hotel_id\":967,\"role\":\"h967_main\",\"url\":\"https://www.alibey.com/content/upload/hotel-promo/alibeyclub-promo-1_1.jpg\"},{\"hotel_id\":967,\"role\":\"h967_room\",\"url\":\"https://www.alibey.com/content/upload/room-images/Alibey_Club_Manavgat_standart_oda_orta_1.jpg\"},{\"hotel_id\":967,\"role\":\"h967_aquapark\",\"url\":\"https://www.alibey.com/content/upload/hotel-promo-area/alibey-club-aquapark-promo-2.jpg\"},{\"hotel_id\":968,\"role\":\"h968_main\",\"url\":\"https://www.alibey.com/content/upload/hotel-promo/alibeypark-promo-2.jpg\"},{\"hotel_id\":968,\"role\":\"h968_room\",\"url\":\"https://www.alibey.com/content/upload/room-images/Alibey_Club_Manavgat_standart_oda_orta_1.jpg\"},{\"hotel_id\":968,\"role\":\"h968_aquapark\",\"url\":\"https://www.alibey.com/content/upload/hotel-promo-area/alibey-club-aquapark-promo-2.jpg\"}],[{\"hotel_id\":2175,\"role\":\"h2175_general\",\"url\":\"https://www.hillsidebeachclub.com/assets/img/default-og-image.jpg\"},{\"hotel_id\":2175,\"role\":\"h2175_gallery\",\"url\":\"https://www.hillsidebeachclub.com/assets/img/gallery/bg.jpg\"},{\"hotel_id\":1672,\"role\":\"h1672_hotel\",\"url\":\"https://storage.kempinski.com/cdn-cgi/image/w=1920%2Cf=auto%2Cfit=scale-down/ki-cms-prod/images/2/7/2/4/764272-1-eng-GB/685581c4d1ba-74532214_4K.jpg\"},{\"hotel_id\":1672,\"role\":\"h1672_gallery0\",\"url\":\"https://storage.kempinski.com/cdn-cgi/image/w=1200%2Ch=800%2Cf=auto%2Cfit=scale-down%2Cg=auto/ki-cms-prod/images/4/1/1/6/1856114-1-eng-GB/6518fce3fb13-76882790_4K.jpg\"},{\"hotel_id\":1672,\"role\":\"h1672_gallery1\",\"url\":\"https://storage.kempinski.com/cdn-cgi/image/w=1200%2Ch=800%2Cf=auto%2Cfit=scale-down%2Cg=auto/ki-cms-prod/images/4/4/1/6/1856144-1-eng-GB/6b7d354253fd-76882792_4K.jpg\"},{\"hotel_id\":1672,\"role\":\"h1672_gallery2\",\"url\":\"https://storage.kempinski.com/cdn-cgi/image/w=1200%2Ch=800%2Cf=auto%2Cfit=scale-down%2Cg=auto/ki-cms-prod/images/4/2/0/6/1856024-1-eng-GB/106b967d141f-76882784_4K.jpg\"}],[{\"hotel_id\":1201,\"role\":\"h1201_new_pool0\",\"url\":\"https://www.gloria.com.tr/media/h1xny1al/main_pool13_gvr.jpg\"},{\"hotel_id\":1201,\"role\":\"h1201_new_pool1\",\"url\":\"https://www.gloria.com.tr/media/cqopblko/main_pool22_gvr.jpg\"},{\"hotel_id\":1198,\"role\":\"h1198_new_view0\",\"url\":\"https://www.gloria.com.tr/media/fiujysrq/mainpool.jpg\"},{\"hotel_id\":1198,\"role\":\"h1198_new_view1\",\"url\":\"https://www.gloria.com.tr/media/atgfu15v/general_view27_ggr-2.jpg\"},{\"hotel_id\":1198,\"role\":\"h1198_new_view2\",\"url\":\"https://www.gloria.com.tr/media/2eukbfxv/general_view33_ggr.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=10
hosts={"www.palomahotels.com","www.alibey.com","www.hillsidebeachclub.com","storage.kempinski.com","www.gloria.com.tr"}
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
out={'operation':'commercial-batch35-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
