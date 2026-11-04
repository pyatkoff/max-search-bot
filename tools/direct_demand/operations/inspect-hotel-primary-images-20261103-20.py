import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":15879,\"role\":\"h15879_new38_0\",\"url\":\"https://www.belazur.com.tr/image/icerik/pools-waterpark-6_63510794cf960.jpg\",\"primary_source_url\":\"https://www.belazur.com.tr/en/pools-waterparks.html\"},{\"hotel_id\":15879,\"role\":\"h15879_new38_1\",\"url\":\"https://www.belazur.com.tr/image/icerik/pools-waterpark-6_63510794d3d21.jpg\",\"primary_source_url\":\"https://www.belazur.com.tr/en/pools-waterparks.html\"},{\"hotel_id\":15879,\"role\":\"h15879_new38_2\",\"url\":\"https://www.belazur.com.tr/image/icerik/pools-waterpark-10_635107a27e7d3.jpg\",\"primary_source_url\":\"https://www.belazur.com.tr/en/pools-waterparks.html\"},{\"hotel_id\":2144,\"role\":\"h2144_new38_3\",\"url\":\"https://fs.dmarisbay.com/cdn/uploads/000001061_d-maris-bay_natural-park-scenery.png\",\"primary_source_url\":\"https://dmarisbay.com/en/nature\"},{\"hotel_id\":2144,\"role\":\"h2144_new38_4\",\"url\":\"https://fs.dmarisbay.com/cdn/uploads/000001062_557x371-2.jpg\",\"primary_source_url\":\"https://dmarisbay.com/en/nature\"}],[{\"hotel_id\":2144,\"role\":\"h2144_new38_5\",\"url\":\"https://fs.dmarisbay.com/cdn/uploads/000002628_5.jpg\",\"primary_source_url\":\"https://dmarisbay.com/en/nature\"},{\"hotel_id\":3469,\"role\":\"h3469_new38_6\",\"url\":\"https://fs.dresortgocek.com/cdn/uploads/000000467_beach-hero.jpg\",\"primary_source_url\":\"https://www.dresortgocek.com/en/beaches/beach-pool\"},{\"hotel_id\":3469,\"role\":\"h3469_new38_7\",\"url\":\"https://fs.dresortgocek.com/cdn/uploads/000000712_plajhavuz_galeri_1_1520x844.jpg\",\"primary_source_url\":\"https://www.dresortgocek.com/en/beaches/beach-pool\"},{\"hotel_id\":3469,\"role\":\"h3469_new38_8\",\"url\":\"https://fs.dresortgocek.com/cdn/uploads/000000714_plajhavuz_galeri_2_1520x844.jpg\",\"primary_source_url\":\"https://www.dresortgocek.com/en/beaches/beach-pool\"},{\"hotel_id\":3469,\"role\":\"h3469_new38_9\",\"url\":\"https://fs.dresortgocek.com/cdn/uploads/000000718_plajhavuz_galeri_4_1520x844.jpg\",\"primary_source_url\":\"https://www.dresortgocek.com/en/beaches/beach-pool\"}],[{\"hotel_id\":9321,\"role\":\"h9321_new38_10\",\"url\":\"https://www.clubmarvy.com/adminweb/images_gallery/marvy_marvytime_1600x1200-08_ahkp4c3a.jpg\",\"primary_source_url\":\"https://www.clubmarvy.com/en/beach-club-resort-turkey/photos\"},{\"hotel_id\":9321,\"role\":\"h9321_new38_11\",\"url\":\"https://www.clubmarvy.com/adminweb/images_gallery/marvy_marvytime_1600x1200-32_txw4ro4x.jpg\",\"primary_source_url\":\"https://www.clubmarvy.com/en/beach-club-resort-turkey/photos\"},{\"hotel_id\":9321,\"role\":\"h9321_new38_12\",\"url\":\"https://www.clubmarvy.com/adminweb/images_gallery/marvy_marvytime_1600x1200-14_pput5mq5.jpg\",\"primary_source_url\":\"https://www.clubmarvy.com/en/beach-club-resort-turkey/photos\"},{\"hotel_id\":9321,\"role\":\"h9321_new38_13\",\"url\":\"https://www.clubmarvy.com/adminweb/images_gallery/marvy_marvytime_1600x1200-11_kvt2m8eb.jpg\",\"primary_source_url\":\"https://www.clubmarvy.com/en/beach-club-resort-turkey/photos\"},{\"hotel_id\":1543,\"role\":\"h1543_new38_14\",\"url\":\"https://www.susesihotel.com/media/n1ljsio2/susesi-hotels-plaj-ve-havuzlar-banner-v4.jpg\",\"primary_source_url\":\"https://www.susesihotel.com/en/experiences/beach-pools/\"}],[{\"hotel_id\":1543,\"role\":\"h1543_new38_15\",\"url\":\"https://www.susesihotel.com/media/ecgna5jq/susesi-hotels-plaj-ve-havuzlar-galeri.jpg\",\"primary_source_url\":\"https://www.susesihotel.com/en/experiences/beach-pools/\"},{\"hotel_id\":1543,\"role\":\"h1543_new38_16\",\"url\":\"https://www.susesihotel.com/media/ou3lchiw/susesi-hotels-plaj-ve-havuzlar-ana-havuz-card.jpg\",\"primary_source_url\":\"https://www.susesihotel.com/en/experiences/beach-pools/\"},{\"hotel_id\":1137,\"role\":\"h1137_new38_17\",\"url\":\"https://www.corneliaresort.com/uploads/640px/CORNELIA-DIAMOND-MAIN-VIEW-2.jpg\",\"primary_source_url\":\"https://www.corneliaresort.com/EN/Cornelia-Diamond-Luxury-Golf-Resort-Spa-Hotel-Belek-Antalya/photos-and-videos\"},{\"hotel_id\":1137,\"role\":\"h1137_new38_18\",\"url\":\"https://www.corneliaresort.com/uploads/640px/diamond-8.jpg\",\"primary_source_url\":\"https://www.corneliaresort.com/EN/Cornelia-Diamond-Luxury-Golf-Resort-Spa-Hotel-Belek-Antalya/photos-and-videos\"},{\"hotel_id\":1137,\"role\":\"h1137_new38_19\",\"url\":\"https://www.corneliaresort.com/uploads/640px/FOTORAF---VIDEO-SLIDER-01.jpg\",\"primary_source_url\":\"https://www.corneliaresort.com/EN/Cornelia-Diamond-Luxury-Golf-Resort-Spa-Hotel-Belek-Antalya/photos-and-videos\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=10
hosts=set(["www.belazur.com.tr","fs.dmarisbay.com","fs.dresortgocek.com","www.clubmarvy.com","www.susesihotel.com","www.corneliaresort.com"])
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
assert len(hosts)==6 and len({r['url'] for g in groups for r in g})==20
for host in hosts:
 assert not safe('https://'+host+'/photo.jpg?token=private')
 assert not safe('https://'+host+'.foreign.invalid/photo.jpg')
print('BATCH38_EXACT_PROVEN_HOST_SCOPE_OK new_input_cases=3 old_guard_fixtures_rerun=0 http_calls=0',flush=True)
assert len({(r['hotel_id'],r['role']) for rs in groups for r in rs})==sum(map(len,groups))
assert all(safe(r['url']) for rs in groups for r in rs)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_photo_redirect')
opener=urllib.request.build_opener(NoRedirect())
sheet=Image.new('RGB',(960,math.ceil(len(rows)/3)*270),'white');draw=ImageDraw.Draw(sheet)
out={'operation':'commercial-batch38-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
