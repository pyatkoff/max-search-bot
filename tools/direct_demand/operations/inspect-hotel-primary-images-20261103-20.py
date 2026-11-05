import base64,copy,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":47025,\"url\":\"https://www.adalyahotels.com/media/yupafx1o/adalya-elit-lara-plaj-havuz-banner-1.jpg\",\"primary_source_url\":\"https://www.adalyahotels.com/en/our-hotels/adalya-elite-lara/beaches-pools/\",\"role\":\"h47025_new52_0\"},{\"hotel_id\":47025,\"url\":\"https://www.adalyahotels.com/media/purnokyl/adalya-elite-lara-plaj-iskele-card.jpg\",\"primary_source_url\":\"https://www.adalyahotels.com/en/our-hotels/adalya-elite-lara/beaches-pools/\",\"role\":\"h47025_new52_1\"},{\"hotel_id\":47025,\"url\":\"https://www.adalyahotels.com/media/oicjagn3/adalya-elite-lara-acik-havuz-card.jpg\",\"primary_source_url\":\"https://www.adalyahotels.com/en/our-hotels/adalya-elite-lara/beaches-pools/\",\"role\":\"h47025_new52_2\"}],[{\"hotel_id\":21642,\"url\":\"https://www.adalyahotels.com/media/dl5cl400/adalya-ocean-deluxe-plaj-havuz-banner-1-1.jpg\",\"primary_source_url\":\"https://www.adalyahotels.com/en/our-hotels/adalya-ocean-deluxe/beaches-pools/\",\"role\":\"h21642_new52_0\"},{\"hotel_id\":21642,\"url\":\"https://www.adalyahotels.com/media/iiyd4dy0/adalya-ocean-plaj-list-card-1.jpg\",\"primary_source_url\":\"https://www.adalyahotels.com/en/our-hotels/adalya-ocean-deluxe/beaches-pools/\",\"role\":\"h21642_new52_1\"},{\"hotel_id\":21642,\"url\":\"https://www.adalyahotels.com/media/giqa4rpn/adalya-hotels-ocean-beach-and-pool-outdoor-pool-list-card.jpg\",\"primary_source_url\":\"https://www.adalyahotels.com/en/our-hotels/adalya-ocean-deluxe/beaches-pools/\",\"role\":\"h21642_new52_2\"}],[{\"hotel_id\":1217,\"url\":\"https://www.adalyahotels.com/media/kezbknjh/adalya-art-side-plaj-havuz-banner11.jpg\",\"primary_source_url\":\"https://www.adalyahotels.com/en/our-hotels/adalya-art-side/beach-pools/\",\"role\":\"h1217_new52_0\"},{\"hotel_id\":1217,\"url\":\"https://www.adalyahotels.com/media/cjzobanp/adalya-art-side-plaj-havuz-plaj-card.jpg\",\"primary_source_url\":\"https://www.adalyahotels.com/en/our-hotels/adalya-art-side/beach-pools/\",\"role\":\"h1217_new52_1\"},{\"hotel_id\":1217,\"url\":\"https://www.adalyahotels.com/media/lgidt5gs/adalya-art-side-acik-havuz-list-card.jpg\",\"primary_source_url\":\"https://www.adalyahotels.com/en/our-hotels/adalya-art-side/beach-pools/\",\"role\":\"h1217_new52_2\"}],[{\"hotel_id\":1369,\"url\":\"https://static.melashotels.com/networks/1/properties/3/multimedia/20260601100949957_org.webp\",\"primary_source_url\":\"https://www.melashotels.com/en/melas-lara-hotel/gallery\",\"role\":\"h1369_new52_0\"},{\"hotel_id\":1369,\"url\":\"https://static.melashotels.com/networks/1/properties/3/multimedia/20260430125018613_org.webp\",\"primary_source_url\":\"https://www.melashotels.com/en/melas-lara-hotel\",\"role\":\"h1369_new52_1\"},{\"hotel_id\":1369,\"url\":\"https://static.melashotels.com/networks/1/properties/3/multimedia/20260430125018608_org.webp\",\"primary_source_url\":\"https://www.melashotels.com/en/melas-lara-hotel\",\"role\":\"h1369_new52_2\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert group in (0,1,2,3) and len(groups)==4
rows=groups[group];assert len(rows)==3
hosts=set(['www.adalyahotels.com','static.melashotels.com'])
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
base=Path('tools/direct_demand/operations')
idx=json.loads((base/'commercial-batch-20261105-52-contact-source-index.json').read_text())
old_urls=set(idx['closed_url_exclusions']);old_basenames=set(idx['closed_decoded_basename_exclusions'])
assert len(old_urls)==277 and len(old_basenames)==276
bindings={r['hotel_id']:r for r in idx['native_bindings']}
by_url={p['source_url']:p for p in json.loads((base/'commercial-batch-20261105-52-primary-retained.json').read_text())['pages']}
source_native={r['source_url']:r['hotel_id'] for r in idx['source_scope']}
flat=[r for g in groups for r in g]
assert len(flat)==len({r['url'] for r in flat})==12 and len({r['role'] for r in flat})==12 and {r['hotel_id'] for r in flat}=={47025,21642,1217,1369}
def prior_basename(url):
 return urllib.parse.unquote(urllib.parse.urlsplit(url).path.rsplit('/',1)[-1]).lower()
def validate_binding(row):
 assert row['hotel_id'] in {47025,21642,1217,1369}
 n=bindings[row['hotel_id']];p=by_url[row['primary_source_url']]
 assert n['country_id']==4 and n['is_active']==1 and n['registry_matches']=={'max':0,'telegram':0}
 assert source_native[row['primary_source_url']]==row['hotel_id']==p['hotel_id'] and row['url'] in p['declared_public_image_references']
 uri=urllib.parse.urlsplit(row['url']);name=prior_basename(row['url'])
 assert safe(row['url']) and not uri.query and row['url'] not in old_urls and name not in old_basenames
 if row['hotel_id']==1369:assert row['url'].startswith('https://static.melashotels.com/networks/1/properties/3/multimedia/') and '/melas-lara-hotel' in p['source_url']
 elif row['hotel_id']==47025:assert '/adalya-elite-lara/beaches-pools/' in p['source_url'] and name.startswith('adalya-eli') and 'bliss' not in name
 elif row['hotel_id']==21642:assert '/adalya-ocean-deluxe/beaches-pools/' in p['source_url'] and (name.startswith('adalya-ocean') or name.startswith('adalya-hotels-ocean'))
 else:assert '/adalya-art-side/beach-pools/' in p['source_url'] and name.startswith('adalya-art-side')
for r in flat:validate_binding(r)
if group==0:
 for mutation in ('publishednative','foreignproperty','closedurl'):
  bad=copy.deepcopy(flat[0])
  if mutation=='publishednative':bad['hotel_id']=78251
  elif mutation=='foreignproperty':bad['primary_source_url']=flat[-1]['primary_source_url']
  else:bad['url']=next(iter(old_urls))
  try:validate_binding(bad);raise RuntimeError('newbindingnegativeaccepted')
  except (AssertionError,KeyError):pass
 print('BATCH52_EXACT_NEW_OWNER_PROPERTY_INPUT_OK new_input_cases=3 old_guard_fixtures_rerun=0 raw_photo_gets_before_cases=0',flush=True)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_photo_redirect')
opener=urllib.request.build_opener(NoRedirect())
sheet=Image.new('RGB',(960,math.ceil(len(rows)/3)*270),'white');draw=ImageDraw.Draw(sheet)
out={'operation':'commercial-batch52-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
  raw_dir=Path('accepted-primary-raw');raw_dir.mkdir(exist_ok=True)
  extension={'JPEG':'jpg','PNG':'png','WEBP':'webp'}[im.format]
  retained_file=raw_dir/(row['role']+'.'+extension);retained_file.write_bytes(raw)
  meta['retained_raw_file']=str(retained_file)
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
