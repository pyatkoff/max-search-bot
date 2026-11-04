import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":2144,\"role\":\"h2144_photo_0\",\"url\":\"https://fs.dmarisbay.com/cdn/uploads/000002624_1.jpg\"},{\"hotel_id\":2144,\"role\":\"h2144_photo_1\",\"url\":\"https://fs.dmarisbay.com/cdn/uploads/000001090_hero.jpg\"},{\"hotel_id\":2144,\"role\":\"h2144_photo_2\",\"url\":\"https://fs.dmarisbay.com/cdn/uploads/000002631_3.jpg\"},{\"hotel_id\":2144,\"role\":\"h2144_photo_3\",\"url\":\"https://fs.dmarisbay.com/cdn/uploads/000002627_4.jpg\"},{\"hotel_id\":3469,\"role\":\"h3469_photo_0\",\"url\":\"https://fs.dresortgocek.com/cdn/uploads/000000577_home-hero-img-2.jpg\"},{\"hotel_id\":3469,\"role\":\"h3469_photo_1\",\"url\":\"https://fs.dresortgocek.com/cdn/uploads/000000360_spin-2.webp\"},{\"hotel_id\":3469,\"role\":\"h3469_photo_2\",\"url\":\"https://fs.dresortgocek.com/cdn/uploads/000000511_experience-img-2.jpg\"},{\"hotel_id\":3469,\"role\":\"h3469_photo_3\",\"url\":\"https://fs.dresortgocek.com/cdn/uploads/000000749_standartroomcard_870x640.jpg\"}],[{\"hotel_id\":1199,\"role\":\"h1199_photo_0\",\"url\":\"https://www.gloria.com.tr/media/tydpqcfs/gsr_anasayfa_1920x920.jpg\"},{\"hotel_id\":1199,\"role\":\"h1199_photo_1\",\"url\":\"https://www.gloria.com.tr/media/fnsibghh/1200x1200-1.jpg\"},{\"hotel_id\":1199,\"role\":\"h1199_photo_2\",\"url\":\"https://www.gloria.com.tr/media/vj1h32wa/beach20_gsr.jpg\"},{\"hotel_id\":1199,\"role\":\"h1199_photo_3\",\"url\":\"https://www.gloria.com.tr/media/zmjf1tpd/dlx.jpg\"},{\"hotel_id\":1201,\"role\":\"h1201_photo_0\",\"url\":\"https://www.gloria.com.tr/media/i4spr22o/gvr_anasayfa_1920x920.jpg\"},{\"hotel_id\":1201,\"role\":\"h1201_photo_1\",\"url\":\"https://www.gloria.com.tr/media/otcd5ubp/general_view16_gvr.jpg\"},{\"hotel_id\":1201,\"role\":\"h1201_photo_2\",\"url\":\"https://www.gloria.com.tr/media/u3gp4rdr/dock11_gvr.jpg\"},{\"hotel_id\":1201,\"role\":\"h1201_photo_3\",\"url\":\"https://www.gloria.com.tr/media/rithclpr/waterslider5-gvr.jpg\"},{\"hotel_id\":1201,\"role\":\"h1201_photo_4\",\"url\":\"https://www.gloria.com.tr/media/o1eh05pb/family-suite.jpg\"}],[{\"hotel_id\":1198,\"role\":\"h1198_photo_0\",\"url\":\"https://www.gloria.com.tr/media/mtgdloi2/ggr_anasayfa_1920x920.jpg\"},{\"hotel_id\":1198,\"role\":\"h1198_photo_1\",\"url\":\"https://www.gloria.com.tr/media/3bjbdqcg/ggr-beach-1200z720.jpg\"},{\"hotel_id\":1198,\"role\":\"h1198_photo_2\",\"url\":\"https://www.gloria.com.tr/media/xzynhkgc/aquapark-1200x720.jpg\"},{\"hotel_id\":1198,\"role\":\"h1198_photo_3\",\"url\":\"https://www.gloria.com.tr/media/o4bhv34y/gloria-golf-resort-king-suite.jpg\"},{\"hotel_id\":1137,\"role\":\"h1137_photo_0\",\"url\":\"https://www.corneliaresort.com/uploads/1920px/CORNELIA-DIAMOND-MAIN-VIEW.jpg\"},{\"hotel_id\":1137,\"role\":\"h1137_photo_1\",\"url\":\"https://www.corneliaresort.com/uploads/1920px/CORNELIA-DIAMOND-POOLS.jpg\"},{\"hotel_id\":1137,\"role\":\"h1137_photo_2\",\"url\":\"https://www.corneliaresort.com/uploads/1920px/CORNELIA-DIAMOND-PIER.jpg\"},{\"hotel_id\":1137,\"role\":\"h1137_photo_3\",\"url\":\"https://www.corneliaresort.com/uploads/1920px/CORNELIA-DIAMOND-GOLF-RESORT--SPA---WATERSLIDES-3.jpg\"}],[{\"hotel_id\":1543,\"role\":\"h1543_photo_0\",\"url\":\"https://www.susesihotel.com/images/susesi-video.jpg\"},{\"hotel_id\":1543,\"role\":\"h1543_photo_1\",\"url\":\"https://www.susesihotel.com/media/ne5hikm2/susesi-luxury-resort-mainpage-banner-desktop.jpg\"},{\"hotel_id\":1543,\"role\":\"h1543_photo_2\",\"url\":\"https://www.susesihotel.com/media/a4plhtna/susesi-luxury-resort-deluxe-odalar-banner.jpg\"},{\"hotel_id\":1543,\"role\":\"h1543_photo_3\",\"url\":\"https://www.susesihotel.com/media/lltm40gs/ana-sayfa-spa-galeri-1.jpg\"},{\"hotel_id\":1464,\"role\":\"h1464_photo_0\",\"url\":\"https://cdn.woxxtech.com/httpsroyalwingshotel/royal-wings-hotels-1786629517415.webp\"},{\"hotel_id\":1464,\"role\":\"h1464_photo_1\",\"url\":\"https://cdn.woxxtech.com/httpsroyalwingshotel/royal-wings-standard-room-09-1784809481892.webp\"},{\"hotel_id\":1464,\"role\":\"h1464_photo_2\",\"url\":\"https://cdn.woxxtech.com/httpsroyalwingshotel/olanaklarmz-1786629657366.webp\"},{\"hotel_id\":9321,\"role\":\"h9321_photo_0\",\"url\":\"https://www.clubmarvy.com/assets2018/img/og_image.jpg\"},{\"hotel_id\":9321,\"role\":\"h9321_photo_1\",\"url\":\"https://www.clubmarvy.com/AdminWeb/images_pages/ClubMarvy-Spirit_4n05uyg6.jpg\"},{\"hotel_id\":9321,\"role\":\"h9321_photo_2\",\"url\":\"https://www.clubmarvy.com/AdminWeb/images_rooms_main_images/1200x800.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=10
hosts={"fs.dmarisbay.com","fs.dresortgocek.com","www.gloria.com.tr","www.corneliaresort.com","www.susesihotel.com","cdn.woxxtech.com","www.clubmarvy.com"}
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
