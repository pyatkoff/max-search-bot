import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":1449,\"role\":\"g0_declared_0\",\"url\":\"https://www.ahstatic.com/photos/b1j5_ho_00_p_1024x768.jpg\"},{\"hotel_id\":1449,\"role\":\"g0_declared_1\",\"url\":\"https://www.ahstatic.com/photos/b1j5_ho_01_p_1024x768.jpg\"},{\"hotel_id\":1449,\"role\":\"g0_declared_2\",\"url\":\"https://www.ahstatic.com/photos/b1j5_ho_02_p_1024x768.jpg\"},{\"hotel_id\":1449,\"role\":\"g0_declared_4\",\"url\":\"https://www.ahstatic.com/photos/b1j5_rotwags_00_p_1024x768.jpg\"},{\"hotel_id\":1453,\"role\":\"g0_declared_0\",\"url\":\"https://www.ahstatic.com/photos/b1j3_ho_00_p_1024x768.jpg\"},{\"hotel_id\":1453,\"role\":\"g0_declared_1\",\"url\":\"https://www.ahstatic.com/photos/b1j3_ho_01_p_1024x768.jpg\"},{\"hotel_id\":1453,\"role\":\"g0_declared_2\",\"url\":\"https://www.ahstatic.com/photos/b1j3_ho_02_p_1024x768.jpg\"},{\"hotel_id\":1453,\"role\":\"g0_declared_4\",\"url\":\"https://www.ahstatic.com/photos/b1j3_rokgags_00_p_1024x768.jpg\"}],[{\"hotel_id\":1035,\"role\":\"g1_target_0\",\"url\":\"https://api.baruthotelsgroup.com/uploads/menu_items/bc01e1af-5b5c-b5d4-9726-634e5cdf6502/958b50a096ce14d6a319310de5621678.jpg\"},{\"hotel_id\":1035,\"role\":\"g1_target_1\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/dc47548a-093b-fd5f-8172-0ef5bd8db218/a467522d8202f59966a90ec8e98003a1.jpg\"},{\"hotel_id\":1035,\"role\":\"g1_target_2\",\"url\":\"https://api.baruthotelsgroup.com/uploads/room_types/9d2a97ec-570a-e13b-b159-f51964d6ed66/b614d0d379a09704b0658d5077ee90b1.webp\"},{\"hotel_id\":1035,\"role\":\"g1_target_3\",\"url\":\"https://api.baruthotelsgroup.com/uploads/room_types/d11d50ea-2f95-15b2-87a5-21d0edbeba6e/f65310bcc680479c30696610c218b373.jpg\"},{\"hotel_id\":109655,\"role\":\"g1_signa_0\",\"url\":\"https://www.libertyhotels.com/wp-content/uploads/2023/07/liberty-signa-scaled.webp\"},{\"hotel_id\":109655,\"role\":\"g1_signa_1\",\"url\":\"https://www.libertyhotels.com/wp-content/uploads/2025/11/liberty-signa-baglantili-oda-1-1200x686.webp\"},{\"hotel_id\":109655,\"role\":\"g1_signa_2\",\"url\":\"https://www.libertyhotels.com/wp-content/uploads/2025/11/liberty-signa-restaurant-bar.png\"},{\"hotel_id\":9432,\"role\":\"g1_imperial_0\",\"url\":\"https://www.delphinhotel.com/main_pics/pages/medium/1823.jpg\"},{\"hotel_id\":9432,\"role\":\"g1_imperial_1\",\"url\":\"https://www.delphinhotel.com/main_pics/pages/medium/2996.png\"},{\"hotel_id\":9432,\"role\":\"g1_imperial_2\",\"url\":\"https://www.delphinhotel.com/main_pics/pages/medium/709.png\"}],[{\"hotel_id\":1451,\"role\":\"g2_delphin_0\",\"url\":\"https://www.delphinhotel.com/main_pics/pages/medium/1829.jpg\"},{\"hotel_id\":1451,\"role\":\"g2_delphin_1\",\"url\":\"https://www.delphinhotel.com/main_pics/pages/medium/3029.png\"},{\"hotel_id\":1451,\"role\":\"g2_delphin_2\",\"url\":\"https://www.delphinhotel.com/main_pics/pages/medium/730.png\"},{\"hotel_id\":1451,\"role\":\"g2_delphin_3\",\"url\":\"https://www.delphinhotel.com/main_pics/pages/medium/3033.png\"},{\"hotel_id\":1151,\"role\":\"g2_delphin_0\",\"url\":\"https://www.delphinhotel.com/main_pics/pages/medium/1818.jpg\"},{\"hotel_id\":1151,\"role\":\"g2_delphin_1\",\"url\":\"https://www.delphinhotel.com/main_pics/pages/medium/753.png\"},{\"hotel_id\":1151,\"role\":\"g2_delphin_2\",\"url\":\"https://www.delphinhotel.com/main_pics/pages/medium/795.png\"},{\"hotel_id\":1151,\"role\":\"g2_delphin_3\",\"url\":\"https://www.delphinhotel.com/main_pics/pages/medium/801.png\"}],[{\"hotel_id\":1583,\"role\":\"g3_sorgun_0\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/medialist/637683624816267708.jpg\"},{\"hotel_id\":1583,\"role\":\"g3_sorgun_1\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/medialist/637683625866630972.jpg\"},{\"hotel_id\":1583,\"role\":\"g3_sorgun_2\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/VSO_Gorsel/1140x570_Beach2.jpg\"},{\"hotel_id\":1583,\"role\":\"g3_sorgun_3\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/VSO_Gorsel/596x333_Relax_Pool.jpg\"},{\"hotel_id\":59116,\"role\":\"g3_lujo_0\",\"url\":\"https://lujohotel.com/opengraph-image.png\"},{\"hotel_id\":59116,\"role\":\"g3_lujo_1\",\"url\":\"https://lujohotel.com/assets/img/refreshing/new/service-from-bars.jpg\"},{\"hotel_id\":59116,\"role\":\"g3_lujo_2\",\"url\":\"https://lujohotel.com/assets/img/refreshing/new/646X384_SWIMLESSON2.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=10
hosts={"www.ahstatic.com","api.baruthotelsgroup.com","www.libertyhotels.com","www.delphinhotel.com","voyagecdn.blob.core.windows.net","lujohotel.com"}
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
out={'operation':'commercial-batch32-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
