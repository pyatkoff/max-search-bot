import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":74258,\"role\":\"aerial_from_exact_hotel_listing\",\"url\":\"https://cms.elgouna.com/api/media/file/Casa-Cook-El-Gouna-Hotel-Red-Sea-Egypt171-X2-c3a471ce.webp\"},{\"hotel_id\":74258,\"role\":\"general_view4\",\"url\":\"https://cms.elgouna.com/api/media/file/Casa-Cook-El-Gouna-Hotel-Red-Sea-Egypt-General-view-4.webp\"},{\"hotel_id\":74258,\"role\":\"general_view14\",\"url\":\"https://cms.elgouna.com/api/media/file/Casa-Cook-El-Gouna-Hotel-Red-Sea-Egypt-General-view-14-1-2-d7fd73a2.webp\"},{\"hotel_id\":74258,\"role\":\"junior_suite_pool\",\"url\":\"https://cms.elgouna.com/api/media/file/Casa-Cook-El-Gouna-Hotel-Red-Sea-Junior-suite-shared-pool-1-2654bc92.webp\"},{\"hotel_id\":74258,\"role\":\"pool_details\",\"url\":\"https://cms.elgouna.com/api/media/file/Casa_Cook_El_Gouna_pool-detail.webp\"}],[{\"hotel_id\":129,\"role\":\"external_general\",\"url\":\"https://cms.elgouna.com/api/media/file/Cooks_Club_El_Gouna_Red_Sea_Egypt_General-0782f186.webp\"},{\"hotel_id\":129,\"role\":\"exterior\",\"url\":\"https://cms.elgouna.com/api/media/file/Cooks_Club_El_gouna_Exterior-19-90cedaa1.webp\"},{\"hotel_id\":129,\"role\":\"twin_room\",\"url\":\"https://cms.elgouna.com/api/media/file/Cooks_Club_El_Gouna_Twin_Room_French_Window_1-2-1-ea768096.webp\"},{\"hotel_id\":432,\"role\":\"owner_aerial_from_exact_listing\",\"url\":\"https://cms.elgouna.com/api/media/file/1706355676-f199301a.webp\"},{\"hotel_id\":73343,\"role\":\"owner_reviews_photo\",\"url\":\"https://redseahotels.com/wp-content/uploads/2023/03/The-Grand-Palace-Reviews.jpg\"},{\"hotel_id\":73343,\"role\":\"owner_new_project_photo\",\"url\":\"https://redseahotels.com/wp-content/uploads/2023/03/Neues-Projekt-33-1.jpg\"}],[{\"hotel_id\":68907,\"role\":\"album1_new0\",\"url\":\"https://wa-uploads.profitroom.com/amarinahotels/17348185027893_03a0634edit.jpg\"},{\"hotel_id\":68907,\"role\":\"album1_new1\",\"url\":\"https://wa-uploads.profitroom.com/amarinahotels/17348185047711_i6a9987edit.jpg\"},{\"hotel_id\":68907,\"role\":\"album1_new2\",\"url\":\"https://wa-uploads.profitroom.com/amarinahotels/17348185148615_mg2036.jpg\"},{\"hotel_id\":68907,\"role\":\"album1_new3\",\"url\":\"https://wa-uploads.profitroom.com/amarinahotels/17348184916885_03a0387edit.jpg\"},{\"hotel_id\":68907,\"role\":\"album1_new4\",\"url\":\"https://wa-uploads.profitroom.com/amarinahotels/17348184981213_03a0598edit.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<3 and len(groups)==3
rows=groups[group];assert 1<=len(rows)<=7
hosts={'cms.elgouna.com','wa-uploads.profitroom.com','www.cooksclub.com','casacook.com','assets.hrewards.com','cms.elgouna.com','d31zyqkijjipvo.cloudfront.net','partner.jazhotels.com','cache.marriott.com','redseahotels.com','d1wo7kaelp5eck.cloudfront.net','www.swissinn.net','storage.googleapis.com'}
def safe(url):
 p=urllib.parse.urlsplit(url)
 if p.scheme!='https' or p.hostname not in hosts or p.port is not None or p.username or p.password or p.fragment or url.count('https://')!=1:return False
 if p.query:
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
out={'operation':'commercial-batch26-new-overview-contact2','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
for index,row in enumerate(rows):
 x=(index%3)*320;y=(index//3)*270
 label=str(row['hotel_id'])+' '+row['role']
 draw.text((x+6,y+6),label,fill='black')
 try:
  with opener.open(urllib.request.Request(row['url'],headers={'User-Agent':'Mozilla/5.0'}),timeout=25) as r:
   raw=r.read(5000001);assert r.status==200 and 10000<len(raw)<=5000000
  im=Image.open(io.BytesIO(raw));im.load();assert im.format in ('JPEG','PNG','WEBP')
  width,height=im.size;assert max(width,height)>=500 and min(width,height)>=250 and max(width,height)<=6000
  meta={'hotel_id':row['hotel_id'],'role':row['role'],'index':index,'source_url':row['url'],'format':im.format,'width':width,'height':height,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
  out['images'].append(meta)
  preview=ImageOps.contain(im.convert('RGB'),(310,230))
  sheet.paste(preview,(x+5+(310-preview.width)//2,y+30+(230-preview.height)//2))
 except Exception as e:
  error={'hotel_id':row['hotel_id'],'role':row['role'],'index':index,'error':type(e).__name__}
  if isinstance(e,urllib.error.HTTPError):error['http_status']=e.code
  out['rejections'].append(error);draw.text((x+8,y+45),error['error'],fill='red')
buf=io.BytesIO();sheet.save(buf,format='PNG');raw=buf.getvalue()
Path('contact.png').write_bytes(raw);Path('primary-image-inspection.json').write_text(json.dumps(out,ensure_ascii=False)+'\n')
parts=[base64.b64encode(raw).decode()[i:i+48000] for i in range(0,len(base64.b64encode(raw)),48000)]
print(json.dumps(out,ensure_ascii=False),flush=True)
print(json.dumps({'kind':'contact','group':group,'width':sheet.width,'height':sheet.height,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'chunks':len(parts)}),flush=True)
for i,p in enumerate(parts):print(json.dumps({'kind':'contact_chunk','group':group,'index':i,'total':len(parts),'data':p}),flush=True)
