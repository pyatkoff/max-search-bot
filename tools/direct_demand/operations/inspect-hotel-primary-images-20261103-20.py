import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":1659,\"role\":\"h1659_hero1\",\"url\":\"https://www.tui-blue.com/media/hotels/Sarigerme/NEW_2025/overview/TB-SarigermePark-Hotel-Overview-1580.jpg\"},{\"hotel_id\":1659,\"role\":\"h1659_hero2\",\"url\":\"https://www.tui-blue.com/media/hotels/Sarigerme/NEW_2025/overview/TB-SarigermePark-Hotel-Overview-1054.jpg\"},{\"hotel_id\":1659,\"role\":\"h1659_beach\",\"url\":\"https://www.tui-blue.com/media/hotels/Sarigerme/NEW_2025/overview/TB-SarigermePark-Beach-3195.jpg\"},{\"hotel_id\":1659,\"role\":\"h1659_room\",\"url\":\"https://www.tui-blue.com/media/hotels/Sarigerme/NEW_2025/rooms_new_2025/TB-SarigermePark-Comfort-Room-Mainbuilding-Bedroom-0878.jpg\"},{\"hotel_id\":21695,\"role\":\"h21695_hero1\",\"url\":\"https://www.tui-blue.com/media/hotels/Seno/UEbersicht/1920x1080_NEU/tui-blue-seno-von-oben.JPG\"},{\"hotel_id\":21695,\"role\":\"h21695_hero2\",\"url\":\"https://www.tui-blue.com/media/hotels/Seno/UEbersicht/1920x1080/TUI-blue-Seno-overview-hotel.jpg\"},{\"hotel_id\":21695,\"role\":\"h21695_beach\",\"url\":\"https://www.tui-blue.com/media/hotels/Seno/UEbersicht/1920x1080/tui-blue-Seno-Beach.jpg\"},{\"hotel_id\":21695,\"role\":\"h21695_room\",\"url\":\"https://www.tui-blue.com/media/hotels/Seno/Zimmer/1920x1080/tui-blue-seno-doppelzimmer-meerblick-balkon.jpg\"}],[{\"hotel_id\":28668,\"role\":\"h28668_hero1\",\"url\":\"https://www.ahstatic.com/photos/b1j7_ho_00_p_1024x768.jpg\"},{\"hotel_id\":28668,\"role\":\"h28668_hero2\",\"url\":\"https://www.ahstatic.com/photos/b1j7_ho_01_p_1024x768.jpg\"},{\"hotel_id\":28668,\"role\":\"h28668_hero3\",\"url\":\"https://www.ahstatic.com/photos/b1j7_ho_02_p_1024x768.jpg\"},{\"hotel_id\":28668,\"role\":\"h28668_hero4\",\"url\":\"https://www.ahstatic.com/photos/b1j7_ho_03_p_1024x768.jpg\"},{\"hotel_id\":28668,\"role\":\"h28668_room\",\"url\":\"https://www.ahstatic.com/photos/b1j7_roskd_00_p_1024x768.jpg\"},{\"hotel_id\":55205,\"role\":\"h55205_hero1\",\"url\":\"https://eftaliahotels.com/wp-content/uploads/2021/03/ocean2048-1-1-2048x941.jpg\"},{\"hotel_id\":55205,\"role\":\"h55205_aquapark\",\"url\":\"https://eftaliahotels.com/wp-content/uploads/2021/03/ocean-kaydirak2048-1-2048x941.jpg\"},{\"hotel_id\":55205,\"role\":\"h55205_room\",\"url\":\"https://eftaliahotels.com/wp-content/uploads/2021/03/ocean-oda-2048-2048x941.jpg\"},{\"hotel_id\":55205,\"role\":\"h55205_category\",\"url\":\"https://eftaliahotels.com/wp-content/uploads/2021/03/ocean-swimup-balkon-2048-2048x941.jpg\"}],[{\"hotel_id\":1334,\"role\":\"h1334_hero1\",\"url\":\"https://www.kirmanpremium.com/image/icerik/_67a21508225c6.jpg\"},{\"hotel_id\":1334,\"role\":\"h1334_hero2\",\"url\":\"https://www.kirmanpremium.com/image/icerik/_67a2151d9b599.jpg\"},{\"hotel_id\":1334,\"role\":\"h1334_hero3\",\"url\":\"https://www.kirmanpremium.com/image/icerik/_67a2151d9aa16.jpg\"},{\"hotel_id\":1334,\"role\":\"h1334_hero4\",\"url\":\"https://www.kirmanpremium.com/image/icerik/_67a2151d99f2d.jpg\"},{\"hotel_id\":1334,\"role\":\"h1334_hero5\",\"url\":\"https://www.kirmanpremium.com/image/icerik/_67a2151d9715a.jpg\"},{\"hotel_id\":1334,\"role\":\"h1334_room\",\"url\":\"https://www.kirmanpremium.com/image/icerik/67a2118a790b8.png\"},{\"hotel_id\":1715,\"role\":\"h1715_hero1\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/VTO-Gunce-12.05/1803x720_Genel2.jpg\"},{\"hotel_id\":1715,\"role\":\"h1715_room\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/VTO_Yepyeni_04.11/617x537_Executive_Sea_Side_Family_1.jpg\"},{\"hotel_id\":1715,\"role\":\"h1715_hero2\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/voyage_torba/Beach_Pool_Aquapark/VTO_General_View_9_1803x720.jpg\"},{\"hotel_id\":1715,\"role\":\"h1715_beach\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/voyage_torba/Beach_Pool_Aquapark/VTO_Beach_5_1140x570.jpg\"}],[{\"hotel_id\":28327,\"role\":\"h28327_experience1\",\"url\":\"https://www.nirvanahotels.com.tr/_nuxt/img/ME-experience-top.a4cadf5.jpg\"},{\"hotel_id\":28327,\"role\":\"h28327_experience2\",\"url\":\"https://www.nirvanahotels.com.tr/_nuxt/img/ME-experience-bottom.035ddfa.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=10
hosts={"www.tui-blue.com","www.ahstatic.com","eftaliahotels.com","www.kirmanpremium.com","voyagecdn.blob.core.windows.net","www.nirvanahotels.com.tr"}
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
out={'operation':'commercial-batch37-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
