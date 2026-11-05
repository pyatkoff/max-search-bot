import base64,copy,hashlib,io,json,math,os,re,urllib.parse,urllib.request
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw
operation='commercial-batch-20261105-56-contact1'
base=Path('tools/direct_demand/operations')
filename=os.environ['CONTACT_SOURCE_INDEX_FILENAME'];assert Path(filename).name==filename
raw=(base/filename).read_bytes();assert hashlib.sha256(raw).hexdigest()==os.environ['EXPECTED_CONTACT_SOURCE_INDEX_SHA256']
idx=json.loads(raw);group=int(os.environ['MEDIA_GROUP']);assert group==0
rows=idx['new_photos'];assert len(rows)==2 and len({r['url'] for r in rows})==2 and len({r['role'] for r in rows})==2
source=json.loads((base/'commercial-stock-20261102-12-source-index.json').read_text())
declaration=next(r for r in source['hotels'] if r['hotel_id']==67897)
candidate=next(r for r in json.loads((base/'commercial-stock-20261105-51-inventory.json').read_text())['candidates'] if r['hotel_id']==67897)
assert hashlib.sha256(json.dumps(declaration,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()==idx['retained_declaration']['exact_hotel_metadata_sha256']
assert hashlib.sha256(json.dumps(candidate,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()==idx['retained_stock']['exact_candidate_sha256']
assert declaration['catalog_name']==candidate['hotel']=='JAZ MARAYA' and declaration['photo_pool_url']=='https://partner.jazhotels.com/hotel/64'
assert idx['retained_stock']['country_id'] is None and idx['retained_stock']['is_active'] is None and idx['retained_stock']['registry_matches']=={'telegram':None,'max':None}
assert idx['retained_stock']['publication_eligibility']=='CURRENT_EXACT_PROOF_REQUIRED_NO_SEND'
old_urls=set(idx['closed_url_exclusions']);old_basenames={s.lower() for s in idx['closed_decoded_basename_exclusions']}
assert len(old_urls)==289 and len(old_basenames)==288
def basename(uri):return urllib.parse.unquote(urllib.parse.urlsplit(uri).path.rsplit('/',1)[-1]).lower()
def metadata_urls(v):
 if isinstance(v,str):return [v] if v.startswith('https://') else []
 if isinstance(v,list):return [u for x in v for u in metadata_urls(x)]
 if isinstance(v,dict):return [u for x in v.values() for u in metadata_urls(x)]
 return []
metadata_files=[]
for path in sorted(set(base.glob('commercial-*-contact*source-index.json'))|set(base.glob('commercial-*-contact*plan.json'))):
 if path.name.startswith('commercial-batch-20261105-56-'):continue
 o=json.loads(path.read_text());metadata_files.append(path.name)
 for uri in metadata_urls(o):
  if re.search(r'\.(?:jpe?g|png|webp)(?:\?|$)',uri,re.I):old_urls.add(uri);old_basenames.add(basename(uri))
def validate_row(row):
 assert row['hotel_id']==67897 and row['primary_source_url']==declaration['photo_pool_url']
 assert row['url'] in declaration['photo_candidates'] and row['role'] in {'h67897_new56_0','h67897_new56_1'}
 p=urllib.parse.urlsplit(row['url'])
 assert p.scheme=='https' and p.hostname=='partner.jazhotels.com' and p.path.startswith('/Images/uploads/gallery/photos/JazMaraya/')
 assert not p.query and not p.fragment and not p.username and not p.password and p.port is None
def unconsumed(row):return row['url'] not in old_urls and basename(row['url']) not in old_basenames
for row in rows:validate_row(row)
baseline_urls=set(old_urls);baseline_basenames=set(old_basenames)
cases=0
for label in ['foreign_native','wrong_source','foreign_property','foreign_host','query','closed_url','closed_basename']:
 r=copy.deepcopy(rows[0])
 if label=='foreign_native':r['hotel_id']=4184
 elif label=='wrong_source':r['primary_source_url']='https://partner.jazhotels.com/hotel/65'
 elif label=='foreign_property':r['url']='https://partner.jazhotels.com/Images/uploads/gallery/photos/JazSister/Overview.jpg'
 elif label=='foreign_host':r['url']='https://foreign.invalid/Overview.jpg'
 elif label=='query':r['url']+='?token=forbidden'
 elif label=='closed_url':old_urls.add(r['url'])
 else:old_basenames.add(basename(r['url']))
 try:
  validate_row(r)
  if label in ('closed_url','closed_basename'):assert unconsumed(r)
 except AssertionError:cases+=1
 else:raise RuntimeError('NEW_JAZ_PHOTO_NEGATIVE_ACCEPTED:'+label)
 old_urls=set(baseline_urls);old_basenames=set(baseline_basenames)
assert cases==7 and old_urls==baseline_urls and old_basenames==baseline_basenames
deferred=[dict(row,reason='CLOSED_PHOTO_URL_OR_DECODED_BASENAME_NO_GET') for row in rows if not unconsumed(row)]
selected=[row for row in rows if unconsumed(row)]
print(json.dumps({'operation':operation,'state':'NEW_INPUT_CASES_AND_CLOSED_CONTACT_METADATA_GUARDS_PASSED','new_actual_input_cases':cases,'old_fixtures_rerun':0,'contact_metadata_files_checked':len(metadata_files),'selected_new_raw_gets':len(selected),'deferred_before_http':deferred,'photo_gets_before_cases':0,'provider_calls':0,'server_calls':0}),flush=True)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_photo_redirect')
opener=urllib.request.build_opener(NoRedirect())
sheet=Image.new('RGB',(640,270),'white');draw=ImageDraw.Draw(sheet)
out={'operation':operation,'mode':'pure_cloud_public_source_inspection','group':group,'server_connections':0,'provider_calls':0,'db_writes':0,'message_sends':0,'images':[],'rejections':[],'deferred_before_http':deferred,'publication_eligibility':'CURRENT_EXACT_PROOF_REQUIRED_NO_SEND'}
scope_http403=False
for index,row in enumerate(selected):
 if scope_http403:
  out['rejections'].append({'hotel_id':row['hotel_id'],'role':row['role'],'index':index,'stage':'before_http','error':'OWNER_SCOPE403_STOP_UNATTEMPTED'});continue
 x=(index%3)*320;y=(index//3)*270
 label=str(row['hotel_id'])+' '+row['role']
 draw.text((x+6,y+6),label,fill='black')
 try:
  stage='fetch';observed={}
  with opener.open(urllib.request.Request(row['url'],headers={'User-Agent':'Mozilla/5.0'}),timeout=25) as r:
   raw=r.read(5000001);observed={'http_status':r.status,'content_type':r.headers.get('Content-Type','')[:100],'received_prefix_bytes':len(raw)}
   stage='byte_guard'
   if r.status!=200 or not 10000<len(raw)<=5000000:raise ValueError('source_image_byte_guard')
   if r.geturl()!=row['url']:raise ValueError('source_image_final_url_guard')
   if r.headers.get_content_type() not in ('image/jpeg','image/png','image/webp'):raise ValueError('source_image_mime_guard')
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
  if isinstance(e,urllib.error.HTTPError):
   error['http_status']=e.code
   if e.code==403:scope_http403=True
  error['stage']=stage;error['observed']=observed
  out['rejections'].append(error);draw.text((x+8,y+45),error['error'],fill='red')
buf=io.BytesIO();sheet.save(buf,format='PNG');raw=buf.getvalue()
Path('contact.png').write_bytes(raw);Path('primary-image-inspection.json').write_text(json.dumps(out,ensure_ascii=False)+'\n')
parts=[base64.b64encode(raw).decode()[i:i+48000] for i in range(0,len(base64.b64encode(raw)),48000)]
print(json.dumps(out,ensure_ascii=False),flush=True)
print(json.dumps({'kind':'contact','group':group,'width':sheet.width,'height':sheet.height,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'chunks':len(parts)}),flush=True)
for i,p in enumerate(parts):print(json.dumps({'kind':'contact_chunk','group':group,'index':i,'total':len(parts),'data':p}),flush=True)
