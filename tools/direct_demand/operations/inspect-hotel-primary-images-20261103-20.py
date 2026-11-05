import base64,copy,hashlib,io,json,math,os,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
groups=json.loads("[[{\"hotel_id\":78251,\"url\":\"https://image-tc.galaxy.tf/wijpeg-13stbqytzq47csslb2kngj1ho/007-cullinan-belek-exterior.jpg\",\"primary_source_url\":\"https://www.cullinanhotels.com/gallery\",\"role\":\"h78251_new51_0\"},{\"hotel_id\":78251,\"url\":\"https://image-tc.galaxy.tf/wijpeg-5gx04isz2w9g2cujfy4vetctz/008-cullinan-belek-exterior.jpg\",\"primary_source_url\":\"https://www.cullinanhotels.com/gallery\",\"role\":\"h78251_new51_1\"},{\"hotel_id\":78251,\"url\":\"https://image-tc.galaxy.tf/wijpeg-2xvc1jdz3mnv4lf40agrcil2n/009-cullinan-belek-exterior.jpg\",\"primary_source_url\":\"https://www.cullinanhotels.com/gallery\",\"role\":\"h78251_new51_2\"},{\"hotel_id\":78251,\"url\":\"https://image-tc.galaxy.tf/wijpeg-d52psyauht4tcyu9974qjuhe3/010-cullinan-belek-exterior.jpg\",\"primary_source_url\":\"https://www.cullinanhotels.com/gallery\",\"role\":\"h78251_new51_3\"},{\"hotel_id\":78251,\"url\":\"https://image-tc.galaxy.tf/wijpeg-8qhea7whg0b1eye3nktl430bt/cullinan-belek-general-view-3.jpg\",\"primary_source_url\":\"https://www.cullinanhotels.com/gallery\",\"role\":\"h78251_new51_4\"},{\"hotel_id\":78251,\"url\":\"https://image-tc.galaxy.tf/wijpeg-xoe8lp1vl5vws44p7iav7b81/cullinan-belek-general-view-4.jpg\",\"primary_source_url\":\"https://www.cullinanhotels.com/gallery\",\"role\":\"h78251_new51_5\"}],[{\"hotel_id\":27349,\"url\":\"https://www.maxxroyal.com/media/13if4tbi/maxxroyalkemerlongbeach1.jpg\",\"primary_source_url\":\"https://www.maxxroyal.com/maxx-royal-kemer/experiences/pool-and-beach\",\"role\":\"h27349_new51_0\"},{\"hotel_id\":27349,\"url\":\"https://www.maxxroyal.com/media/ohfiagxb/maxxroyalkemermainpool2.jpg\",\"primary_source_url\":\"https://www.maxxroyal.com/maxx-royal-kemer/experiences/pool-and-beach\",\"role\":\"h27349_new51_1\"},{\"hotel_id\":27349,\"url\":\"https://www.maxxroyal.com/media/wr2p1mr0/maxxroyalkemermiddlebay4.jpg\",\"primary_source_url\":\"https://www.maxxroyal.com/maxx-royal-kemer/experiences/pool-and-beach\",\"role\":\"h27349_new51_2\"},{\"hotel_id\":27349,\"url\":\"https://www.maxxroyal.com/media/lgrlu3tj/maxxroyalkemerlongbeach4.jpg\",\"primary_source_url\":\"https://www.maxxroyal.com/maxx-royal-kemer/experiences/pool-and-beach\",\"role\":\"h27349_new51_3\"}],[{\"hotel_id\":108942,\"url\":\"https://www.maxxroyal.com/media/haofx0sw/maxx_royal_bodrum_mainpool_main.jpg\",\"primary_source_url\":\"https://www.maxxroyal.com/maxx-royal-bodrum/experiences/pool-and-beach\",\"role\":\"h108942_new51_0\"},{\"hotel_id\":108942,\"url\":\"https://www.maxxroyal.com/media/zheduupv/maxx_royal_bodrum_mainpool.jpg\",\"primary_source_url\":\"https://www.maxxroyal.com/maxx-royal-bodrum/experiences/pool-and-beach\",\"role\":\"h108942_new51_1\"},{\"hotel_id\":108942,\"url\":\"https://www.maxxroyal.com/media/cy0ppelm/maxx_royal-bodrum_whitedeck.jpg\",\"primary_source_url\":\"https://www.maxxroyal.com/maxx-royal-bodrum/experiences/pool-and-beach\",\"role\":\"h108942_new51_2\"},{\"hotel_id\":108942,\"url\":\"https://www.maxxroyal.com/media/myynqk1g/maxx_royal_bodrum_beach.jpg\",\"primary_source_url\":\"https://www.maxxroyal.com/maxx-royal-bodrum/experiences/pool-and-beach\",\"role\":\"h108942_new51_3\"}]]")
group=int(os.environ['MEDIA_GROUP']);assert group in (0,1,2) and len(groups)==3
rows=groups[group];assert len(rows)==[6,4,4][group]
hosts=set(['image-tc.galaxy.tf','www.maxxroyal.com'])
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
idx=json.loads((base/'commercial-batch-20261105-51-contact-source-index.json').read_text())
old_urls=set(idx['closed_url_exclusions']);old_basenames=set(idx['closed_decoded_basename_exclusions'])
assert len(old_urls)==263 and len(old_basenames)==262
bindings={r['hotel_id']:r for r in idx['native_bindings']}
by_url={p['source_url']:p for p in json.loads((base/'commercial-batch-20261105-51-primary-retained.json').read_text())['pages']}
source_native={r['source_url']:r['hotel_id'] for r in idx['source_scope']}
flat=[r for g in groups for r in g]
assert len(flat)==len({r['url'] for r in flat})==14 and len({r['role'] for r in flat})==14 and {r['hotel_id'] for r in flat}=={78251,27349,108942}
def prior_basename(url):
 return urllib.parse.unquote(urllib.parse.urlsplit(url).path.rsplit('/',1)[-1]).lower()
def validate_binding(row):
 assert row['hotel_id'] in {78251,27349,108942}
 n=bindings[row['hotel_id']];p=by_url[row['primary_source_url']]
 assert n['country_id']==4 and n['is_active']==1 and n['registry_matches']=={'max':0,'telegram':0}
 assert source_native[row['primary_source_url']]==row['hotel_id']==p['hotel_id'] and row['url'] in p['declared_public_image_references']
 uri=urllib.parse.urlsplit(row['url'])
 assert safe(row['url']) and not uri.query and row['url'] not in old_urls and prior_basename(row['url']) not in old_basenames
 name=prior_basename(row['url'])
 assert (row['hotel_id']==78251 and 'cullinan-belek-' in name and p['page_title']=='Gallery | Cullinan Belek | All inclusive luxury hotel in Belek') or (row['hotel_id']==27349 and name.startswith('maxxroyalkemer') and '/maxx-royal-kemer/experiences/pool-and-beach' in p['source_url']) or (row['hotel_id']==108942 and name.startswith('maxx_royal') and '/maxx-royal-bodrum/experiences/pool-and-beach' in p['source_url'])
for r in flat:validate_binding(r)
if group==0:
 for mutation in ('publishednative','foreignsource','closedurl'):
  bad=copy.deepcopy(flat[0])
  if mutation=='publishednative':bad['hotel_id']=1422
  elif mutation=='foreignsource':bad['primary_source_url']=flat[-1]['primary_source_url']
  else:bad['url']=next(iter(old_urls))
  try:validate_binding(bad);raise RuntimeError('newbindingnegativeaccepted')
  except (AssertionError,KeyError):pass
 print('BATCH51_EXACT_NEW_OWNER_PROPERTY_INPUT_OK new_input_cases=3 old_guard_fixtures_rerun=0 raw_photo_gets_before_cases=0',flush=True)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_photo_redirect')
opener=urllib.request.build_opener(NoRedirect())
sheet=Image.new('RGB',(960,math.ceil(len(rows)/3)*270),'white');draw=ImageDraw.Draw(sheet)
out={'operation':'commercial-batch51-bounded-primary-contact-inspection','mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[]}
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
