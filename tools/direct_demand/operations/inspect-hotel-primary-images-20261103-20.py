import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":165,\"role\":\"owner_0\",\"url\":\"https://assets.builderassets.com/site-media/828/overview-1.jpg?format=jpg&quality=80&width=1920&height=1080\"},{\"hotel_id\":165,\"role\":\"owner_1\",\"url\":\"https://assets.builderassets.com/site-media/828/overview-3.jpg?format=jpg&quality=80&width=1920&height=1080\"},{\"hotel_id\":165,\"role\":\"owner_2\",\"url\":\"https://assets.builderassets.com/site-media/828/main-pool-overview.jpg?format=jpg&quality=80&width=1920&height=1080\"},{\"hotel_id\":165,\"role\":\"owner_3\",\"url\":\"https://assets.builderassets.com/site-media/828/beach-2.jpg?format=jpg&quality=80&width=1920&height=1080\"},{\"hotel_id\":165,\"role\":\"owner_4\",\"url\":\"https://assets.builderassets.com/site-media/828/kidsden.jpg?format=jpg&quality=80&width=1920&height=1080\"}],[{\"hotel_id\":12116,\"role\":\"owner_0\",\"url\":\"https://assets.builderassets.com/site-media/829/over-view-1-1.jpg?format=jpg&quality=80&width=1920&height=1080\"},{\"hotel_id\":12116,\"role\":\"owner_1\",\"url\":\"https://assets.builderassets.com/site-media/829/over-view-1-2.jpg?format=jpg&quality=80&width=1920&height=1080\"},{\"hotel_id\":12116,\"role\":\"owner_2\",\"url\":\"https://assets.builderassets.com/site-media/829/over-view.jpg?format=jpg&quality=80&width=1920&height=1080\"},{\"hotel_id\":12116,\"role\":\"owner_3\",\"url\":\"https://assets.builderassets.com/site-media/829/kidsden-room.jpg?format=jpg&quality=80&width=1920&height=1080\"},{\"hotel_id\":12116,\"role\":\"owner_4\",\"url\":\"https://assets.builderassets.com/site-media/829/lazy-river-v.jpg?format=jpg&quality=80&width=1920&height=1080\"}],[{\"hotel_id\":111,\"role\":\"overview_0\",\"url\":\"https://image-tc.galaxy.tf/wijpeg-bc7wglngbmzcvjkelpia5eanq/over-view-3.jpg\"},{\"hotel_id\":111,\"role\":\"overview_1\",\"url\":\"https://image-tc.galaxy.tf/wijpeg-dau3vjxuki07fq5rua0712nw8/overview-sunset.jpg?rotate=0&crop=0%2C0%2C2000%2C1333&width=1920\"},{\"hotel_id\":111,\"role\":\"overview_2\",\"url\":\"https://image-tc.galaxy.tf/wijpeg-33ty8g7m1g327fhy1dhdyzm9l/overview-3.jpg?rotate=0&crop=0%2C0%2C2000%2C1333&width=1920\"},{\"hotel_id\":111,\"role\":\"overview_3\",\"url\":\"https://image-tc.galaxy.tf/wijpeg-723a24gpw5kwvpy07dh89x3jd/img-9818.jpg?rotate=0&crop=0%2C0%2C1920%2C1280&width=1920\"},{\"hotel_id\":1962,\"role\":\"owner_0\",\"url\":\"https://baronhotels.com/wp-content/uploads/2024/05/baron-palms-banner1440.jpg\"},{\"hotel_id\":1962,\"role\":\"owner_1\",\"url\":\"https://baronhotels.com/wp-content/uploads/2023/12/Superior-Classic-Room1-scaled-e1726752479427.jpg\"},{\"hotel_id\":1962,\"role\":\"owner_2\",\"url\":\"https://baronhotels.com/wp-content/uploads/2023/12/Paradise-Pool-Restaurant-Bar-scaled-e1767252934362.jpg\"}],[{\"hotel_id\":163,\"role\":\"owner_0\",\"url\":\"https://assets.builderassets.com/site-media/826/overview.jpg?format=jpg&quality=80&width=400&height=550&mode=crop\"},{\"hotel_id\":163,\"role\":\"owner_1\",\"url\":\"https://assets.builderassets.com/site-media/826/overview-2.jpg?format=jpg&quality=80&width=400&height=550&mode=crop\"},{\"hotel_id\":163,\"role\":\"owner_2\",\"url\":\"https://assets.builderassets.com/site-media/826/over-view-night-2.jpg?format=jpg&quality=80&width=400&height=550&mode=crop\"},{\"hotel_id\":163,\"role\":\"owner_3\",\"url\":\"https://assets.builderassets.com/site-media/826/family-suite.jpg?format=jpg&quality=80&width=1920&height=1080\"},{\"hotel_id\":1962,\"role\":\"owner_0\",\"url\":\"https://baronhotels.com/wp-content/uploads/2024/01/DSCF2596-HDR-scaled-e1726746247193.jpg\"},{\"hotel_id\":1962,\"role\":\"owner_1\",\"url\":\"https://baronhotels.com/wp-content/uploads/2024/03/gallery-activities-baron-palms-sharm-el-sheikh-2.jpeg\"},{\"hotel_id\":1962,\"role\":\"owner_2\",\"url\":\"https://baronhotels.com/wp-content/uploads/2023/12/1-7-e1726752122437.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=7
hosts={'assets.builderassets.com','image-tc.galaxy.tf','baronhotels.com','partner.jazhotels.com','cache.marriott.com','redseahotels.com','d1wo7kaelp5eck.cloudfront.net','www.swissinn.net','storage.googleapis.com'}
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
out={'operation':'commercial-batch27-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
