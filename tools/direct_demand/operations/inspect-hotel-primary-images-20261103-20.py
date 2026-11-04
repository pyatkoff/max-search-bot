import base64,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":1338,\"url\":\"https://irp-cdn.multiscreensite.com/11191c87/dms3rep/multi/Genel%2BAlan4-2.png\",\"primary_source_url\":\"https://www.limakhotels.com/limak-lara-gallery\",\"role\":\"h1338_new40_0\"},{\"hotel_id\":1338,\"url\":\"https://irp-cdn.multiscreensite.com/11191c87/dms3rep/multi/Genel%2BAlan1.png\",\"primary_source_url\":\"https://www.limakhotels.com/limak-lara-gallery\",\"role\":\"h1338_new40_1\"},{\"hotel_id\":1338,\"url\":\"https://irp-cdn.multiscreensite.com/11191c87/dms3rep/multi/GENEL%2BALAN%2B%283%29.png\",\"primary_source_url\":\"https://www.limakhotels.com/limak-lara-gallery\",\"role\":\"h1338_new40_2\"},{\"hotel_id\":1338,\"url\":\"https://irp-cdn.multiscreensite.com/11191c87/dms3rep/multi/Genel%2BAlan2.jpg\",\"primary_source_url\":\"https://www.limakhotels.com/limak-lara-gallery\",\"role\":\"h1338_new40_3\"}],[{\"hotel_id\":1338,\"url\":\"https://irp-cdn.multiscreensite.com/11191c87/dms3rep/multi/GENEL%2BALAN%2B%285%29.jpg\",\"primary_source_url\":\"https://www.limakhotels.com/limak-lara-gallery\",\"role\":\"h1338_new40_4\"},{\"hotel_id\":1338,\"url\":\"https://irp-cdn.multiscreensite.com/11191c87/dms3rep/multi/GENEL%2BALAN%2B%286%29.jpg\",\"primary_source_url\":\"https://www.limakhotels.com/limak-lara-gallery\",\"role\":\"h1338_new40_5\"},{\"hotel_id\":65895,\"url\":\"https://www.palomahotels.com/Content/imgUpload/12-1-2020-1-15-58-PMorenda-rooms-slider.jpg\",\"primary_source_url\":\"https://www.palomahotels.com/en/paloma-orenda/stay\",\"role\":\"h65895_new40_6\"},{\"hotel_id\":65895,\"url\":\"https://www.palomahotels.com/Content/imgUpload/16-12-2020-14-58-56orendaroom.jpg\",\"primary_source_url\":\"https://www.palomahotels.com/en/paloma-orenda/stay\",\"role\":\"h65895_new40_7\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert 0<=group<2 and len(groups)==2
rows=groups[group];assert 1<=len(rows)<=10
hosts=set(["irp-cdn.multiscreensite.com","www.palomahotels.com"])
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
assert len(hosts)==2 and len({r['url'] for g in groups for r in g})==8
for host in hosts:
 assert not safe('https://'+host+'/photo.jpg?token=private')
 assert not safe('https://'+host+'.foreign.invalid/photo.jpg')
print('BATCH40_EXACT_PROVEN_HOST_SCOPE_OK new_input_cases=3 old_guard_fixtures_rerun=0 http_calls=0',flush=True)
assert len({(r['hotel_id'],r['role']) for rs in groups for r in rs})==sum(map(len,groups))
assert all(safe(r['url']) for rs in groups for r in rs)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_photo_redirect')
opener=urllib.request.build_opener(NoRedirect())
sheet=Image.new('RGB',(960,math.ceil(len(rows)/3)*270),'white');draw=ImageDraw.Draw(sheet)
out={'operation':'commercial-batch40-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
