import base64,hashlib,io,json,os,pathlib,runpy,subprocess
from concurrent.futures import ThreadPoolExecutor
from PIL import Image,ImageDraw
REPO='pyatkoff/max-search-bot';HEAD=os.environ['GITHUB_SHA'];PREFIX='.github/traffic-publish/commercial-wave11-20261118'
def api(path,method='GET',data=None):
 c=['gh','api','--method',method,'repos/'+REPO+'/'+path]
 if data is not None:c+=['--input','-']
 return json.loads(subprocess.check_output(c,input=None if data is None else json.dumps(data).encode(),timeout=100))
def source(path,ref,sha):
 r=api('contents/tools/direct_demand/operations/'+path+'?ref='+ref);b=base64.b64decode(r['content']);assert hashlib.sha256(b).hexdigest()==sha;return json.loads(b)
e=source('commercial-wave11-editorial-drafts50-20261118.json','b319881abb21496dcf453af1680680bae3279558','9a075ccf0905b9aa7fd3cf067539ec8f85f6b7321b9ad62735fd1395a060828c')
m=source('commercial-wave11-media-reviewed50-20261118.json','6d7d22de9517d2348ea2b7fed57f6934fa951b65','b0efb7566988d7165f49ccaec6512acece7df4356798bfd262cf5efeb79cb400')
r=api('contents/tools/direct_demand/operations/commercial-expansion-wave11-50-native-candidates-20261118.json?ref=78085d38183f59094cbd48ab6d6749e44df3e35f');a=json.loads(base64.b64decode(r['content']));a={r['native_tourvisor_id']:r for r in a['rows']+a['reserve_unselected']};m={r['native_tourvisor_id']:r for r in m['rows']}
mod=runpy.run_path('tools/direct_demand/operations/prepare-wave2-native-tourvisor-covers-20261117.py');raw=pathlib.Path('.github/traffic-publish/brand/anytour-logo-white.png').read_bytes();assert hashlib.sha256(raw).hexdigest()==mod['LOGO_SHA256'];logo=Image.open(io.BytesIO(raw)).convert('RGBA');out=pathlib.Path('wave11');out.mkdir();rows=[r for r in e['rows'] if r['native_tourvisor_id'] not in [8028,12184]];assert len(rows)==48

def prepare(r):
 i=r['native_tourvisor_id'];h=a[i];hero=m[i];result={'native_tourvisor_id':i,'status':'held','publication_allowed':False}
 try:
  assert r['country_id']==h['country_id']==hero['country_id'] and r['country_id']!=11
  assert r['source_profile_file_sha256']==hero['source_profile_sha256'] and hero['visual_reviewed']
  urls=[hero['url']]+[u for u in h['source_photo_samples'] if u!=hero['url']];images=[];proof=[]
  for u in urls:
   try:
    assert mod['check_photo_url'](u)==r['country_id'];im,p=mod['download_photo'](u)
    if not images:assert u==hero['url'] and p['sha256']==hero['sha256']
    if p['sha256'] in [x['sha256'] for x in proof]:continue
    images.append(im);proof.append(p)
    if len(images)==3:break
   except Exception as ex:
    result.setdefault('errors',[]).append({'url':u,'error':str(ex)[:120]})
    if not images:raise
  assert len(images)==3
  title=r['caption'].split('\n')[0].removeprefix('✈️ Туры в ');png=mod['encode_png'](mod['draw_cover'](title,images,logo.copy()));(out/(str(i)+'.png')).write_bytes(png)
  result.update(status='prepared_needs_visual_acceptance',title=title,png_sha256=hashlib.sha256(png).hexdigest(),photo_sources=proof,country_id=r['country_id'],caption_sha256=r['caption_sha256'])
 except Exception as ex:result['reason']=str(ex)[:180]
 return result
with ThreadPoolExecutor(max_workers=5) as pool: receipts=list(pool.map(prepare,rows))
ready=[r for r in receipts if r['status']=='prepared_needs_visual_acceptance'];sheets=[]
for offset in range(0,len(ready),10):
 group=ready[offset:offset+10];canvas=Image.new('RGB',(1800,5*390),'white');draw=ImageDraw.Draw(canvas)
 for n,r in enumerate(group):
  x=(n%2)*900;y=(n//2)*390;draw.text((x+5,y+3),str(r['native_tourvisor_id'])+' '+r['title'],font=mod['font'](18),fill='black')
  im=Image.open(out/(str(r['native_tourvisor_id'])+'.png'));canvas.paste(im.resize((360,360)),(x,y+26))
  for z,p in enumerate(r['photo_sources']):draw.text((x+365,y+40+z*40),p['sha256'][:12],font=mod['font'](14),fill='black')
 name='sheet-'+str(offset//10+1)+'.jpg';canvas.save(out/name,quality=92);sheets.append(name)
assert api('git/ref/heads/audit/content100-bot-publication-20261101')['object']['sha']==HEAD
parent=api('git/commits/'+HEAD);entries=[]
for r in ready:
 b=(out/(str(r['native_tourvisor_id'])+'.png')).read_bytes();blob=api('git/blobs','POST',{'content':base64.b64encode(b).decode(),'encoding':'base64'});entries.append({'path':PREFIX+'/'+str(r['native_tourvisor_id'])+'.png','mode':'100644','type':'blob','sha':blob['sha']})
tree=api('git/trees','POST',{'base_tree':parent['tree']['sha'],'tree':entries});commit=api('git/commits','POST',{'message':'CONTENT W11: staged48 original-composer covers pending visual acceptance; no sends','tree':tree['sha'],'parents':[HEAD]})
receipt={'stage':'SOURCE_COVERS_PENDING_VISUAL_ACCEPTANCE','source_head':HEAD,'cover_commit':commit['sha'],'rows':receipts,'prepared':len(ready),'held':48-len(ready),'caption_source':'b319881abb21496dcf453af1680680bae3279558','media_source':'6d7d22de9517d2348ea2b7fed57f6934fa951b65','sender_calls':0,'registry_run':44361317016,'registry_artifact':14454181466,'sheets':sheets}
(out/'receipt.json').write_text(json.dumps(receipt,ensure_ascii=False,indent=2)+'\n');print(json.dumps({'prepared':len(ready),'held':48-len(ready),'cover_commit':commit['sha'],'sender_calls':0}))
