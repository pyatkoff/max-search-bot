import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":12107,\"role\":\"resort_overview0\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/jazfanararesort/resort-overview-2-202192013.jpg\"},{\"hotel_id\":12107,\"role\":\"resort_overview1\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/jazfanararesort/resort-overview-3-202192013.jpg\"},{\"hotel_id\":12107,\"role\":\"resort_overview2\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/jazfanararesort/resort-overview-4-202192013.jpg\"},{\"hotel_id\":12107,\"role\":\"resort_overview3\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/jazfanararesort/resort-overview1-202192013.jpg\"},{\"hotel_id\":12107,\"role\":\"residence_overview\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/JazFanaraResidence/Overview-202192614.jpg\"}],[{\"hotel_id\":282,\"role\":\"club_overview2\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/new/021029Club%20Overview%202.jpg\"},{\"hotel_id\":282,\"role\":\"club_overview3\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/new/021056Club%20Overview%203.jpg\"},{\"hotel_id\":282,\"role\":\"club_overview1\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/new/021000Club.Overview-1.jpg\"},{\"hotel_id\":282,\"role\":\"club_family\",\"url\":\"https://partner.jazhotels.com/Images/uploads/gallery/photos/jazmirabelclub/club-family-deluxe-queen-bed-pool-view-20222214.jpg\"}],[{\"hotel_id\":432,\"role\":\"islands_overview\",\"url\":\"https://cache.marriott.com/is/image/marriotts7prod/si-hrgsi-islands-lagoons-37353-94965%3AWide-Hor?fit=constrain&wid=750\"},{\"hotel_id\":432,\"role\":\"exterior\",\"url\":\"https://cache.marriott.com/content/dam/marriott-renditions/HRGSI/hrgsi-exterior-7757-hor-wide.jpg?downsize=750px%3A%2A&interpolation=progressive-bilinear&output-quality=70\"},{\"hotel_id\":432,\"role\":\"buildings\",\"url\":\"https://cache.marriott.com/content/dam/marriott-renditions/HRGSI/hrgsi-exterior-3839-hor-wide.jpg?downsize=750px%3A%2A&interpolation=progressive-bilinear&output-quality=70\"},{\"hotel_id\":382,\"role\":\"front_facade\",\"url\":\"https://cache.marriott.com/is/image/marriotts7prod/br-sshbr-front-facade-20496%3AWide-Hor?fit=constrain&wid=750\"},{\"hotel_id\":382,\"role\":\"resort_view\",\"url\":\"https://cache.marriott.com/is/image/marriotts7prod/br-sshbr-resort-view-13639%3AWide-Hor?fit=constrain&wid=750\"},{\"hotel_id\":235,\"role\":\"declared_overview\",\"url\":\"https://redseahotels.com/wp-content/uploads/2023/02/The-Grand-Resort-in-Hurghada-Egypt-by-Red-Sea-Hotels.jpg\"},{\"hotel_id\":235,\"role\":\"pool\",\"url\":\"https://redseahotels.com/wp-content/uploads/2023/03/Beautiful-pools-at-the-Grand-Resort-by-Red-Sea-Hotels.jpg\"}],[{\"hotel_id\":483,\"role\":\"gallery_view1\",\"url\":\"https://d1wo7kaelp5eck.cloudfront.net/sunrise-resorts.com-1611976553/cms/cache/v2/64d8c9b891f67.jpg/1920x1080/fit/80/c49b17a559319a4f0bb27766e82f4f5d.jpg\"},{\"hotel_id\":483,\"role\":\"gallery_view2\",\"url\":\"https://d1wo7kaelp5eck.cloudfront.net/sunrise-resorts.com-1611976553/cms/cache/v2/64bfa0ee8794c.jpg/1920x1080/fit/80/97ee13caf35d5ce15e507122ae1f03de.jpg\"},{\"hotel_id\":250,\"role\":\"main_pool\",\"url\":\"https://www.swissinn.net/hotels/config/gallery/pic/1606213017Main%20Pool.jpg\"},{\"hotel_id\":250,\"role\":\"room_balcony_view\",\"url\":\"https://www.swissinn.net/hotels/config/gallery/pic/1606213348Room_Balcony%20view.jpg\"},{\"hotel_id\":65779,\"role\":\"owner_aerial\",\"url\":\"https://storage.googleapis.com/hwaidak-hotels.appspot.com/hotels%2Fimages%2F1738087613523-DJI_20240925234422_0065_D%20-%20Copy.jpg\"},{\"hotel_id\":932,\"role\":\"owner_aerial\",\"url\":\"https://storage.googleapis.com/hwaidak-hotels.appspot.com/hotels%2Fimages%2F1738091103846-DJI_0005-min.JPG\"},{\"hotel_id\":497,\"role\":\"owner_aerial\",\"url\":\"https://storage.googleapis.com/hwaidak-hotels.appspot.com/hotels%2Fimages%2F1738091155382-DJI_20240925225100_0048_D.jpg\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<4 and len(groups)==4
rows=groups[group];assert 1<=len(rows)<=7
hosts={'partner.jazhotels.com','cache.marriott.com','redseahotels.com','d1wo7kaelp5eck.cloudfront.net','www.swissinn.net','storage.googleapis.com'}
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
out={'operation':'commercial-batch24-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
