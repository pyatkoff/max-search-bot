import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":129,\"role\":\"owner_declared0\",\"url\":\"https://www.cooksclub.com/wp-content/uploads/2025/07/5K3A8928-scaled.jpg\"},{\"hotel_id\":129,\"role\":\"owner_declared1\",\"url\":\"https://www.cooksclub.com/wp-content/uploads/2025/06/EG_HRG_Cooks_Club_El_Gouna_r9wd6kf_00061-scaled.jpg\"}],[{\"hotel_id\":74258,\"role\":\"owner_gallery0\",\"url\":\"https://casacook.com/wp-content/uploads/2025/05/Canto-5K3A3770-474x600.jpg\"},{\"hotel_id\":74258,\"role\":\"owner_gallery1\",\"url\":\"https://casacook.com/wp-content/uploads/2025/05/Canto-5K3A3864-474x600.jpg\"},{\"hotel_id\":74258,\"role\":\"owner_gallery2\",\"url\":\"https://casacook.com/wp-content/uploads/2025/05/Canto-5K3A9342-474x600.jpg\"},{\"hotel_id\":74258,\"role\":\"owner_gallery3\",\"url\":\"https://casacook.com/wp-content/uploads/2025/05/Canto-5K3A9712-474x600.jpg\"},{\"hotel_id\":74258,\"role\":\"owner_gallery4\",\"url\":\"https://casacook.com/wp-content/uploads/2025/05/5K3A1304-474x600.jpg\"}],[{\"hotel_id\":74258,\"role\":\"owner_gallery5\",\"url\":\"https://casacook.com/wp-content/uploads/2025/05/5K3A1111-1-474x600.jpg\"},{\"hotel_id\":74258,\"role\":\"owner_gallery6\",\"url\":\"https://casacook.com/wp-content/uploads/2025/05/casa-cook-el-gouna-graf-22-1-474x600.jpg\"},{\"hotel_id\":74258,\"role\":\"owner_gallery7\",\"url\":\"https://casacook.com/wp-content/uploads/2025/04/5K3A0753-1303x846.jpg\"}],[{\"hotel_id\":73343,\"role\":\"different_gallery0\",\"url\":\"https://redseahotels.com/wp-content/uploads/2021/10/The-Grand-Palace-07.jpg\"},{\"hotel_id\":73343,\"role\":\"different_gallery1\",\"url\":\"https://redseahotels.com/wp-content/uploads/2021/10/The-Grand-Palace-08.jpg\"},{\"hotel_id\":73343,\"role\":\"different_gallery2\",\"url\":\"https://redseahotels.com/wp-content/uploads/2021/10/The-Grand-Palace-09.jpg\"},{\"hotel_id\":73343,\"role\":\"different_gallery3\",\"url\":\"https://redseahotels.com/wp-content/uploads/2021/10/The-Grand-Palace-10.jpg\"},{\"hotel_id\":73343,\"role\":\"different_gallery4\",\"url\":\"https://redseahotels.com/wp-content/uploads/2021/10/The-Grand-Palace-11.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=7
hosts={'www.cooksclub.com','casacook.com','assets.hrewards.com','cms.elgouna.com','d31zyqkijjipvo.cloudfront.net','partner.jazhotels.com','cache.marriott.com','redseahotels.com','d1wo7kaelp5eck.cloudfront.net','www.swissinn.net','storage.googleapis.com'}
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
out={'operation':'commercial-batch26-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
