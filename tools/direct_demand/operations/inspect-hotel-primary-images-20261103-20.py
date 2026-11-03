import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":56091,\"role\":\"g0_main_building\",\"url\":\"https://cms.elgouna.com/api/media/file/Ancient-Sands-Golf-Resort-and-Residence-El-Gouna-Red-sea-Main-building-dba45139.webp\"},{\"hotel_id\":56091,\"role\":\"g0_infinity_pool\",\"url\":\"https://cms.elgouna.com/api/media/file/1703028391-7185aa4e.webp\"},{\"hotel_id\":56091,\"role\":\"g0_golf_view\",\"url\":\"https://cms.elgouna.com/api/media/file/_Ancient_Sands_Golf_Course_El_Gouna_Red_Sea__3_-Ghada___s_MacBook_Pro.webp\"},{\"hotel_id\":56091,\"role\":\"g0_deluxe_room\",\"url\":\"https://cms.elgouna.com/api/media/file/Ancient-Sands-Golf-and-Residence-El-Gouna-Red-Sea-Delux-Room-KingDeluxe_Room_3.webp\"},{\"hotel_id\":102031,\"role\":\"g0_hotel_pool_view\",\"url\":\"https://assets.hrewards.com/assets/562_SHR_Luxor_Nile_Palace_spa_poolview_01_5e6d125c91.JPG\"},{\"hotel_id\":102031,\"role\":\"g0_family_room\",\"url\":\"https://assets.hrewards.com/assets/SHR_Luxor_Nile_Palace_Family_Room_3_76c170238b.jpg\"},{\"hotel_id\":102031,\"role\":\"g0_nile_lounge\",\"url\":\"https://assets.hrewards.com/assets/SHR_Luxor_Nile_Palace_restaurant_Nile_Lounge_Bar_2_fe0cac8db9.png\"}],[{\"hotel_id\":103124,\"role\":\"g1_nile_exterior\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/IberotelLuxor/Hotel-Exterior-Nile-Side-20221911.jpg\"},{\"hotel_id\":103124,\"role\":\"g1_street_exterior\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/iberotelluxor/Hotel-Exterior---Street-Side-2021123015.jpg\"},{\"hotel_id\":103124,\"role\":\"g1_exterior_other\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/iberotelluxor/Exterior-1-202312514.jpg\"},{\"hotel_id\":103124,\"role\":\"g1_floating_pool\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/IberotelLuxor/Pool-Mornng-View-20221911.jpg\"},{\"hotel_id\":103124,\"role\":\"g1_room\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/iberotelluxor/Queen-Bed---Nile-View-2021123015.jpg\"}],[{\"hotel_id\":254,\"role\":\"g2_buildings\",\"url\":\"https://threecorners.com/wp-content/uploads/2021/06/thethreecorners_redsea_marsa_alam_sea_beach_resort_buildings_2-scaled.jpg\"},{\"hotel_id\":254,\"role\":\"g2_resort_overview\",\"url\":\"https://threecorners.com/wp-content/uploads/2024/11/marsa-alam-sea-beach-resort-94-1024x683.jpg\"},{\"hotel_id\":254,\"role\":\"g2_waterpark\",\"url\":\"https://threecorners.com/wp-content/uploads/2021/06/thethreecorners_redsea_marsa_alam_sea_beach_resort_waterpark_4-1024x678.jpg\"},{\"hotel_id\":254,\"role\":\"g2_family_room\",\"url\":\"https://threecorners.com/wp-content/uploads/2024/09/sea-beach-family-room-1-scaled.jpg\"},{\"hotel_id\":254,\"role\":\"g2_beach\",\"url\":\"https://threecorners.com/wp-content/uploads/2021/06/thethreecorners_redsea_marsa_alam_sea_beach_resort_beach_6.jpg\"}],[{\"hotel_id\":488,\"role\":\"g3_overview1\",\"url\":\"https://threecorners.com/wp-content/uploads/2021/06/thethreecorners_redsea_marsa_alam_fayrouz_plaza_beach_resort_overview_1.jpg\"},{\"hotel_id\":488,\"role\":\"g3_overview2\",\"url\":\"https://threecorners.com/wp-content/uploads/2021/06/thethreecorners_redsea_marsa_alam_fayrouz_plaza_beach_resort_overview_2.jpg\"},{\"hotel_id\":488,\"role\":\"g3_buildings\",\"url\":\"https://threecorners.com/wp-content/uploads/2021/06/thethreecorners_redsea_marsa_alam_fayrouz_plaza_beach_resort_building-scaled.jpg\"},{\"hotel_id\":488,\"role\":\"g3_room\",\"url\":\"https://threecorners.com/wp-content/uploads/2021/06/thethreecorners_redsea_marsa_alam_fayrouz_plaza_beach_premium_room_2-scaled.jpg\"},{\"hotel_id\":488,\"role\":\"g3_beach\",\"url\":\"https://threecorners.com/wp-content/uploads/2021/06/thethreecorners_redsea_marsa_alam_fayrouz_plaza_resort_beach_2.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=7
hosts={'cms.elgouna.com','assets.hrewards.com','partner.jazhotels.com','threecorners.com'}
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
out={'operation':'commercial-batch28-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
