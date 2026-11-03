import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":464,\"role\":\"aerial_new\",\"url\":\"https://assets.hrewards.com/assets/SHR_El_Gouna_exterior_Aerial_7_0d562bb40c.jpg\"},{\"hotel_id\":464,\"role\":\"general_overview\",\"url\":\"https://assets.hrewards.com/assets/steigenberger_golf_resort_el_gouna_general_web_bc82c7e4ff.jpg\"},{\"hotel_id\":464,\"role\":\"lagoon\",\"url\":\"https://assets.hrewards.com/assets/SHR_El_Gouna_exterior_Public_Beach_1_c61c271bcd.jpg\"},{\"hotel_id\":464,\"role\":\"deluxe_room\",\"url\":\"https://assets.hrewards.com/assets/SHR_El_Gouna_rooms_Deluxe_Room_King_41569e4802.jpg\"},{\"hotel_id\":464,\"role\":\"aerial_full\",\"url\":\"https://assets.hrewards.com/assets/550_9007346_SHR_El_Gouna_exterior_Aerial_0_9853fba71a.jpg\"}],[{\"hotel_id\":103021,\"role\":\"owner_aerial\",\"url\":\"https://cms.elgouna.com/api/media/file/New-Project-8-10-7b71a497.webp\"},{\"hotel_id\":103021,\"role\":\"hotel_gallery0\",\"url\":\"https://d31zyqkijjipvo.cloudfront.net/chedielgouna.com-1340227474/cms/cache/v2/63a4355187e91.jpg/1920x1080/resize/80/405f518cf8b687232f70aff0a4921f98.jpg\"},{\"hotel_id\":103021,\"role\":\"hotel_gallery1\",\"url\":\"https://d31zyqkijjipvo.cloudfront.net/chedielgouna.com-1340227474/cms/cache/v2/634bdce1d696d.jpg/1920x1080/resize/80/1efcdadb3051b6be7dd0fdb162000ed8.jpg\"},{\"hotel_id\":103021,\"role\":\"hotel_gallery2\",\"url\":\"https://d31zyqkijjipvo.cloudfront.net/chedielgouna.com-1340227474/cms/cache/v2/634bdce81ee93.jpg/1920x1080/resize/80/be9919985a5cf5ad4bc9479ca69cc333.jpg\"},{\"hotel_id\":103021,\"role\":\"hotel_gallery3\",\"url\":\"https://d31zyqkijjipvo.cloudfront.net/chedielgouna.com-1340227474/cms/cache/v2/634bdcf1aa59e.jpg/1920x1080/resize/80/ea860233a3e6d279af3c08077a2abfa7.jpg\"},{\"hotel_id\":103021,\"role\":\"hotel_gallery4\",\"url\":\"https://d31zyqkijjipvo.cloudfront.net/chedielgouna.com-1340227474/cms/cache/v2/634bdcf3c5988.jpg/1920x1080/resize/80/8ee2ba22cb8898582314866cabe5d3aa.jpg\"}],[{\"hotel_id\":73343,\"role\":\"owner_gallery0\",\"url\":\"https://redseahotels.com/wp-content/uploads/2021/10/The-Grand-Palace-Hurghada-01.jpg\"},{\"hotel_id\":73343,\"role\":\"owner_gallery1\",\"url\":\"https://redseahotels.com/wp-content/uploads/2021/10/The-Grand-Palace-Hurghada-02.jpg\"},{\"hotel_id\":73343,\"role\":\"owner_gallery2\",\"url\":\"https://redseahotels.com/wp-content/uploads/2021/10/The-Grand-Palace-03.jpg\"},{\"hotel_id\":73343,\"role\":\"owner_gallery3\",\"url\":\"https://redseahotels.com/wp-content/uploads/2021/10/The-Grand-Palace-04.jpg\"},{\"hotel_id\":73343,\"role\":\"owner_gallery4\",\"url\":\"https://redseahotels.com/wp-content/uploads/2021/10/The-Grand-Palace-05.jpg\"},{\"hotel_id\":73343,\"role\":\"owner_gallery5\",\"url\":\"https://redseahotels.com/wp-content/uploads/2021/10/The-Grand-Palace-06.jpg\"}],[{\"hotel_id\":73343,\"role\":\"declared_overview\",\"url\":\"https://redseahotels.com/wp-content/uploads/2023/02/The-Grand-Palace-adults-only-hotel-in-hurghada.jpg\"},{\"hotel_id\":73343,\"role\":\"beach\",\"url\":\"https://redseahotels.com/wp-content/uploads/2023/03/The-Grand-Palace-in-Hurghada-has-direct-beach-access-at-the-red-sea.jpg\"},{\"hotel_id\":103021,\"role\":\"beach_gallery\",\"url\":\"https://d31zyqkijjipvo.cloudfront.net/chedielgouna.com-1340227474/cms/cache/v2/63bd8742048bb.png/1920x1080/resize/80/eafc0e8447d85cc51d0ec0775a03006a.jpg\"},{\"hotel_id\":464,\"role\":\"main_pool\",\"url\":\"https://assets.hrewards.com/assets/SHR_El_Gouna_exterior_Main_Pool_2_8ead0bd847.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=7
hosts={'assets.hrewards.com','cms.elgouna.com','d31zyqkijjipvo.cloudfront.net','partner.jazhotels.com','cache.marriott.com','redseahotels.com','d1wo7kaelp5eck.cloudfront.net','www.swissinn.net','storage.googleapis.com'}
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
out={'operation':'commercial-batch25-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
