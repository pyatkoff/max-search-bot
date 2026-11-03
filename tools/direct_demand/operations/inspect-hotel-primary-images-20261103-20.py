import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":1291,\"role\":\"g0_general7\",\"url\":\"https://akrahotels-gvhpewgeadfsftcj.a01.azurefd.net/newakraweb/media/r31e05k1/akra-kemer-genel-galeri-slider-7.jpg\"},{\"hotel_id\":1291,\"role\":\"g0_general2\",\"url\":\"https://akrahotels-gvhpewgeadfsftcj.a01.azurefd.net/newakraweb/media/rfohpb5j/akra-kemer-genel-galeri-slider-2.jpg\"},{\"hotel_id\":1291,\"role\":\"g0_pool\",\"url\":\"https://akrahotels-gvhpewgeadfsftcj.a01.azurefd.net/newakraweb/media/rivhgdqs/akra-kemer-plaj-havuz-card.jpg\"},{\"hotel_id\":1291,\"role\":\"g0_family_room\",\"url\":\"https://akrahotels-gvhpewgeadfsftcj.a01.azurefd.net/newakraweb/media/1uqbd1m5/akk_family-suite_01.jpg\"},{\"hotel_id\":15869,\"role\":\"g0_general3\",\"url\":\"https://akrahotels-gvhpewgeadfsftcj.a01.azurefd.net/newakraweb/media/gscjazkx/akra-sorgun-genel-galeri-card3.jpg\"},{\"hotel_id\":15869,\"role\":\"g0_general5\",\"url\":\"https://akrahotels-gvhpewgeadfsftcj.a01.azurefd.net/newakraweb/media/h1udt5do/akra-sorgun-genel-galeri-card5.jpg\"},{\"hotel_id\":15869,\"role\":\"g0_general6\",\"url\":\"https://akrahotels-gvhpewgeadfsftcj.a01.azurefd.net/newakraweb/media/1iadxl4o/akra-sorgun-genel-galeri-card6.jpg\"},{\"hotel_id\":15869,\"role\":\"g0_family_room\",\"url\":\"https://akrahotels-gvhpewgeadfsftcj.a01.azurefd.net/newakraweb/media/5mhngzi5/akra-sorgun-deniz-manzarali-aile-odasi-galeri.jpg\"}],[{\"hotel_id\":1033,\"role\":\"g1_main\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/d11a190e-6f1a-7d61-05c0-8cf5be0e3c90/873c43f415a62705c7fc20308f912683.jpeg\"},{\"hotel_id\":1033,\"role\":\"g1_beach\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/d11a190e-6f1a-7d61-05c0-8cf5be0e3c90/136768070905499cdad81679d977159b.jpg\"},{\"hotel_id\":1033,\"role\":\"g1_deluxe_room\",\"url\":\"https://api.baruthotelsgroup.com/uploads/room_types/d1aaeaa3-3090-e84c-560b-4d9b77134b78/d17f51fff1e16c756b2ab25d9dc56ba1.webp\"},{\"hotel_id\":1033,\"role\":\"g1_garden\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/d11a190e-6f1a-7d61-05c0-8cf5be0e3c90/764663bb36d30bef101f6abdfd319419.webp\"},{\"hotel_id\":1031,\"role\":\"g1_main\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/67225f93-49b0-97fd-c9c9-c57bf6fc983c/0d77421f7d0837430a971f093c1b2ad1.webp\"},{\"hotel_id\":1031,\"role\":\"g1_gallery1\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/00373166-4246-9aca-7b8b-1855295f0729/8db06e84677f4316fe42e4296d0f927e.jpg\"},{\"hotel_id\":1031,\"role\":\"g1_gallery2\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/00373166-4246-9aca-7b8b-1855295f0729/4a01459ddd8fa754a6759403abc5bc74.jpg\"},{\"hotel_id\":1031,\"role\":\"g1_superior_room\",\"url\":\"https://api.baruthotelsgroup.com/uploads/room_types/535d2d82-5726-1fb7-830c-35bd317b425c/a2f8129bf63417612f83e68b5f0b3d89.webp\"}],[{\"hotel_id\":1032,\"role\":\"g2_main\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/12e0c34a-ae65-1835-d76f-b7e7e66fa130/ef35f94bb84d0cd92b72bca5b3f137ac.jpeg\"},{\"hotel_id\":1032,\"role\":\"g2_signature_room\",\"url\":\"https://api.baruthotelsgroup.com/uploads/room_types/fad50c9e-1415-ba27-8216-f220dd5db96a/ff72c90cb1862606137dafa05937abcf.webp\"},{\"hotel_id\":1032,\"role\":\"g2_palmiye_restaurant\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/a2668bc7-ba75-2278-0f30-8b905f80e405/5019030086bbafb21c2fd6954575ed18.webp\"},{\"hotel_id\":21673,\"role\":\"g2_main\",\"url\":\"https://api.baruthotelsgroup.com/uploads/pages/0cf56d82-89c7-7c37-743d-ee066792a44d/e6b4acd104538affe0dcfc9b3e176fbe.webp\"},{\"hotel_id\":21673,\"role\":\"g2_standard_room\",\"url\":\"https://api.baruthotelsgroup.com/uploads/rooms/5f0c902d-c54c-cf4d-4192-c26669ad8f23/6fce7d56b877d6772e70f90acb1e1ff7.jpeg\"},{\"hotel_id\":21673,\"role\":\"g2_swimup_category\",\"url\":\"https://api.baruthotelsgroup.com/uploads/rooms/12f72ee2-8ed2-22fb-2645-24bebbc37ece/50b53a468fd9f2b9df4203c4cbe38710.jpeg\"},{\"hotel_id\":253,\"role\":\"g2_hotel_photo1\",\"url\":\"https://www.safirhotels.com/storage/media/nEG568sTfqJzYcf6rqaZzeeZAuOU1ktPfKgHIW4e.webp\"},{\"hotel_id\":253,\"role\":\"g2_hotel_photo2\",\"url\":\"https://www.safirhotels.com/storage/media/FiUlZTxMl0o5QkHWn9IWYfDXrGtHpXbUbLEr5dfx.webp\"}],[{\"hotel_id\":75377,\"role\":\"g3_main\",\"url\":\"https://www.libertyhotels.com/wp-content/uploads/2026/01/liberty-fabay-main-hotel-photo.webp\"},{\"hotel_id\":75377,\"role\":\"g3_alternate_overview\",\"url\":\"https://www.libertyhotels.com/wp-content/uploads/2023/07/liberty-fabay-scaled.webp\"},{\"hotel_id\":75377,\"role\":\"g3_main_pool\",\"url\":\"https://www.libertyhotels.com/wp-content/uploads/2025/12/Liberty_Fabay_Main-Pool-scaled.jpg\"},{\"hotel_id\":75377,\"role\":\"g3_standard_room\",\"url\":\"https://www.libertyhotels.com/wp-content/uploads/2025/11/liberty-fabay-standart-bahce-manzarali-oda-1-780x446.webp\"},{\"hotel_id\":74503,\"role\":\"g3_main\",\"url\":\"https://ngphaselisbay.com/media/y2nnhycl/ng-phaselis-bay-slider-2025-1.jpg\"},{\"hotel_id\":74503,\"role\":\"g3_alternate_overview\",\"url\":\"https://ngphaselisbay.com/media/wrghdnse/ng-phaselis-bay-slider-2025-3.jpg\"},{\"hotel_id\":74503,\"role\":\"g3_superior_room\",\"url\":\"https://ngphaselisbay.com/media/qyemf5tu/ngp_superiorroomuniqueseaview01.jpg\"},{\"hotel_id\":1582,\"role\":\"g3_general_view\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/uploads/Voyage-Belek-General-View-2637207412574962909.jpg\"},{\"hotel_id\":1582,\"role\":\"g3_beach\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/uploads/Voyage-Belek-Beach-2637189349395430501.jpg\"},{\"hotel_id\":1582,\"role\":\"g3_aquapark\",\"url\":\"https://voyagecdn.blob.core.windows.net/mediafiles/uploads/Voyage-Belek-Aquapark637189349395430835.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=10
hosts={"akrahotels-gvhpewgeadfsftcj.a01.azurefd.net","api.baruthotelsgroup.com","www.safirhotels.com","www.libertyhotels.com","ngphaselisbay.com","voyagecdn.blob.core.windows.net"}
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
out={'operation':'commercial-batch30-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
