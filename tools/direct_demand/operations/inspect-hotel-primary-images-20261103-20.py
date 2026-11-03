import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":1379,\"role\":\"g0_miracle_0\",\"url\":\"https://miraclehotel.com/img/slider/miracle-resort-hotel-havuz-bina.jpg\"},{\"hotel_id\":1379,\"role\":\"g0_miracle_1\",\"url\":\"https://miraclehotel.com/img/slider/miracle-resort-hotel-aquapark-deniz-manzara.jpg\"},{\"hotel_id\":1379,\"role\":\"g0_miracle_2\",\"url\":\"https://miraclehotel.com/wp-content/uploads/2025/03/PIER-1.jpg\"},{\"hotel_id\":1379,\"role\":\"g0_miracle_3\",\"url\":\"https://miraclehotel.com/wp-content/uploads/2025/03/STANDART-ROOM-1.jpg\"},{\"hotel_id\":1338,\"role\":\"g0_limak_0\",\"url\":\"https://irp-cdn.multiscreensite.com/11191c87/dms3rep/multi/GENEL%2BALAN%2B%282%29.jpg\"},{\"hotel_id\":1338,\"role\":\"g0_limak_1\",\"url\":\"https://irp-cdn.multiscreensite.com/11191c87/dms3rep/multi/Genel%2BAlan%2B7.png\"},{\"hotel_id\":1338,\"role\":\"g0_limak_2\",\"url\":\"https://irp-cdn.multiscreensite.com/11191c87/dms3rep/multi/JAKUZI%2BSUI%CC%87T%2BROOM3.jpg\"},{\"hotel_id\":1338,\"role\":\"g0_limak_3\",\"url\":\"https://irp-cdn.multiscreensite.com/11191c87/dms3rep/multi/Restorant.jpg\"}],[{\"hotel_id\":1133,\"role\":\"g1_concorde_0\",\"url\":\"https://concordehotels.com/media/ty3m5y5w/image.png\"},{\"hotel_id\":1133,\"role\":\"g1_concorde_1\",\"url\":\"https://concordehotels.com/media/0eqnibnk/image.png\"},{\"hotel_id\":1133,\"role\":\"g1_concorde_2\",\"url\":\"https://concordehotels.com/media/enflz1tg/image.png\"},{\"hotel_id\":1133,\"role\":\"g1_concorde_3\",\"url\":\"https://concordehotels.com/media/ltjf0isa/deluxe-room-general-view-1.webp\"},{\"hotel_id\":27316,\"role\":\"g1_regnum_0\",\"url\":\"https://www.regnumhotels.com/media/5idlahuv/regnumcarya_generaview-01-banner-v2.jpg\"},{\"hotel_id\":27316,\"role\":\"g1_regnum_1\",\"url\":\"https://www.regnumhotels.com/media/huuccry4/alia_sahil_s%C4%B1cak_renk_reg25-banner.jpg\"},{\"hotel_id\":27316,\"role\":\"g1_regnum_2\",\"url\":\"https://www.regnumhotels.com/media/rgefe2nc/regnumcarya_mainpool-01-slider.jpg\"},{\"hotel_id\":27316,\"role\":\"g1_regnum_3\",\"url\":\"https://www.regnumhotels.com/media/4lfbgvy4/regnumcarya_jaderoom_seaview_bedroom-01-3-card.jpg\"}],[{\"hotel_id\":1559,\"role\":\"g2_titanic_0\",\"url\":\"https://image-tc.galaxy.tf/wijpeg-5wc2v9t20fponc03wqg7kg3n0/titanic-beach-lara-general-view-8.jpg\"},{\"hotel_id\":1559,\"role\":\"g2_titanic_1\",\"url\":\"https://image-tc.galaxy.tf/wijpeg-ddp76uip3isgompm7yhid9j5p/titanic-beach-lara-general-view-4.jpg\"},{\"hotel_id\":1559,\"role\":\"g2_titanic_2\",\"url\":\"https://image-tc.galaxy.tf/wijpeg-bwvwfu7di3k9xml1w13zgo3jw/titanic-beach-lara-beach-5.jpg\"},{\"hotel_id\":1559,\"role\":\"g2_titanic_3\",\"url\":\"https://image-tc.galaxy.tf/wijpeg-6fll4hsx9oc0ib7n39avn9hs2/titanic-deluxe-lara-standart-sea-2_standard.jpg?crop=107%2C0%2C1707%2C1280&height=600&width=728\"},{\"hotel_id\":3405,\"role\":\"g2_maxx_0\",\"url\":\"https://www.maxxroyal.com/media/zdijandq/1600x900.jpg\"},{\"hotel_id\":3405,\"role\":\"g2_maxx_1\",\"url\":\"https://www.maxxroyal.com/media/klshcv15/1600x900_3.jpg\"},{\"hotel_id\":3405,\"role\":\"g2_maxx_2\",\"url\":\"https://www.maxxroyal.com/media/q1ibbfeq/1200x1600.jpg\"},{\"hotel_id\":3405,\"role\":\"g2_maxx_3\",\"url\":\"https://www.maxxroyal.com/media/sdsfo4j1/maxx-royal-belek-pools-and-sea-hero.jpeg\"},{\"hotel_id\":3405,\"role\":\"g2_maxx_4\",\"url\":\"https://www.maxxroyal.com/media/kivnergy/maxx-royal-belek-aquapark.jpeg\"},{\"hotel_id\":3405,\"role\":\"g2_maxx_5\",\"url\":\"https://www.maxxroyal.com/media/sfkncjzw/maxx-royal-belek-pavilion.jpeg\"}],[{\"hotel_id\":109655,\"role\":\"g3_signa_0\",\"url\":\"https://www.libertyhotels.com/wp-content/uploads/2025/11/liberty-signa-slider-4.jpg\"},{\"hotel_id\":109655,\"role\":\"g3_signa_1\",\"url\":\"https://www.libertyhotels.com/wp-content/uploads/2025/11/liberty-signa-baglantili-oda-2-1200x686.webp\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=10
hosts={"miraclehotel.com","irp-cdn.multiscreensite.com","concordehotels.com","www.regnumhotels.com","image-tc.galaxy.tf","www.maxxroyal.com","www.libertyhotels.com"}
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
out={'operation':'commercial-batch33-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
