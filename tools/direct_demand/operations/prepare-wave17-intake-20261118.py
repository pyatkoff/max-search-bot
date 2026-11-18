import base64,concurrent.futures,datetime,hashlib,html,io,json,os,pathlib,re,runpy,subprocess,urllib.parse,urllib.request,zipfile
from PIL import Image,ImageDraw
REPO='pyatkoff/max-search-bot';HEAD=os.environ['GITHUB_SHA'];ROOT=pathlib.Path('wave17');ROOT.mkdir();PREFIX='.github/traffic-publish/commercial-wave17-20261118'
def sha(b):return hashlib.sha256(b).hexdigest()
def api(path,method='GET',data=None):
 cmd=['gh','api','--method',method,'repos/'+REPO+'/'+path]
 if data is not None:cmd+=['--input','-']
 return subprocess.check_output(cmd,input=None if data is None else json.dumps(data).encode(),timeout=120)
def info(path,method='GET',data=None):return json.loads(api(path,method,data))
def get(url):
 with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'AnyTour-authorized-cached-catalogue/1.0'}),timeout=35) as r:assert r.status==200;return r.read()
a=info('actions/artifacts/14467934983');assert a['workflow_run']['id']==44395164286 and a['digest']=='sha256:a45097e6a7dd178c7098b497f692cdcdfd8ffa493f12627a680be23ba7b432fc';raw=api('actions/artifacts/14467934983/zip');assert sha(raw)=='a45097e6a7dd178c7098b497f692cdcdfd8ffa493f12627a680be23ba7b432fc';reg=json.loads(zipfile.ZipFile(io.BytesIO(raw)).read('registry-receipt.json'));assert len(reg['rows'])>=3158
old=set(json.loads(pathlib.Path('tools/direct_demand/operations/wave17-prior-classified.json').read_text()));titles=[]
def normalize(s):return re.sub(r'[^a-zа-я0-9]+',' ',html.unescape(re.sub('<[^>]+>','',s)).lower()).strip()
for r in reg['rows']:
 if r['native_id']>0:old.add(r['native_id'])
 m=re.fullmatch(r'commercial-hotel-(\d+)-v1',r['logical_id'])
 if m:old.add(int(m[1]))
 titles.append(normalize(r['title']))
queries={4: ['Rixos', 'Barut', 'Delphin', 'Titanic', 'Voyage', 'Maxx', 'Regnum', 'Gloria', 'Ela', 'Cornelia', 'Kaya', 'Selectum', 'Akra', 'Liberty', 'Lara', 'Kemer', 'Belek', 'Side', 'Bodrum', 'Marmaris', 'Fethiye', 'Paloma', 'Kirman', 'Calista', 'Limak', 'Crystal', 'Arum', 'Acanthus', 'Melas', 'Tui Blue', 'Nirvana', 'NG Phaselis'], 1: ['Pickalbatros', 'Albatros', 'Jaz', 'Sunrise', 'Steigenberger', 'Rixos', 'Baron', 'Iberotel', 'Movenpick', 'Swissotel', 'Hilton', 'Marriott', 'Sheraton', 'Meraki', 'Cleopatra', 'Sentido', 'Fort Arabesque', 'Soma', 'Makadi', 'Sahl', 'Marsa', 'Sharm', 'Hurghada', 'Safaga', 'Concorde', 'Savoy', 'Sultan', 'Coral', 'Tropitel'], 9: ['Jumeirah', 'Atlantis', 'Rixos', 'Rotana', 'Hilton', 'Marriott', 'Sheraton', 'Fairmont', 'Sofitel', 'Raffles', 'Mandarin', 'One Only', 'Anantara', 'Address', 'Vida', 'Le Meridien', 'Waldorf', 'Doubletree', 'Centara', 'JA Beach', 'Jebel', 'Ajman', 'Fujairah', 'Saadiyat', 'Yas', 'Ras Al Khaimah', 'Intercontinental'], 8: ['Sun Siyam', 'Adaaran', 'Cinnamon', 'Atmosphere', 'Oblu', 'Obol', 'Coco', 'Constance', 'Furaveri', 'Dhigali', 'Reethi', 'Kuredu', 'Meeru', 'Lily', 'Kandima', 'Kuramathi', 'Villa', 'Fushifaru', 'Veligandu', 'Baros', 'Milaidhoo', 'Emerald', 'Heritance'], 12: ['Jetwing', 'Cinnamon', 'Heritance', 'Citrus', 'Riu', 'Avani', 'Anantara', 'Shangri', 'Taj', 'Uga', 'Centara', 'Radisson', 'Sheraton', 'Marriott', 'Hilton', 'Tangerine', 'Royal Palms', 'Club Palm', 'Occidental', 'Dickwella', 'Weligama', 'Pasikudah', 'Bentota'], 27: ['Lux', 'Constance', 'Beachcomber', 'Sunrise', 'Maradiva', 'One Only', 'Shangri', 'Heritage', 'Anantara', 'JW Marriott', 'Westin', 'Le Meridien', 'Intercontinental', 'Long Beach', 'Sugar Beach', 'La Pirogue', 'Tamassa', 'Veranda', 'Preskil', 'Sands', 'Ambre', 'Outrigger', 'Radisson'], 28: ['Constance', 'Hilton', 'Mango', 'Fisherman', 'Kempinski', 'Raffles', 'Four Seasons', 'Savoy', 'Coral', 'Carana', 'Story', 'Cerf', 'Denis', 'Le Duc', 'Le Domaine', 'Paradise', 'Avani', 'Coco', 'Doubletree', 'Laila', 'North Island']}
def query(pair):
 c,q=pair;u='https://anytoour.ru/data/hotel-search-v1.php?'+urllib.parse.urlencode({'q':q,'countryId':c,'limit':20})
 try:
  d=json.loads(get(u))
  for _ in range(2):
   if isinstance(d,dict):d=next((d[k] for k in ['data','hotels','results','items'] if k in d and isinstance(d[k],(dict,list))),d)
  assert isinstance(d,list);return {'country_id':c,'q':q,'url':u,'data':d,'error':None}
 except Exception as ex:return {'country_id':c,'q':q,'url':u,'data':[],'error':str(ex)[:150]}
with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:answers=list(pool.map(query,[(c,q) for c,qs in queries.items() for q in qs]))
rows={};holds=[]
for ans in answers:
 for x in ans['data']:
  i=int(x.get('id',0));name=str(x.get('name',''));country=x.get('country',{});rating=float(x.get('category',0) or 0)
  if not i or i in old or rating<4 or country.get('id')!=ans['country_id'] or country.get('id')==11:continue
  nm=normalize(name)
  if len(nm)<6 or any(nm in t for t in titles):holds.append({'id':i,'name':name,'reason':'current_title_alias_match'});continue
  if re.search(r'\bEX\b|RESIDENCES|RESIDENCE|VACATION CLUB|SERVICED|SANCTUARY',name.upper()):holds.append({'id':i,'name':name,'reason':'former_or_subproperty_qualification'});continue
  rows.setdefault(i,{**x,'catalogue_url':ans['url']})
def priority(x):
 n=x['name'].upper();coast=any(w in n for w in ['RESORT','BEACH','OCEAN','SPA','VILLA']);city=any(w in n for w in ['AIRPORT','HANOI','SAIGON','BANGKOK','CHIANG','SUKHUMVIT']);return (float(x.get('category',0)),int(coast and not city),float(x.get('rating',0) or 0),-x['id'])
selected=sorted(rows.values(),key=priority,reverse=True)[:100]
source=pathlib.Path('tools/direct_demand/operations/prepare-wave2-native-tourvisor-covers-20261117.py');assert subprocess.check_output(['git','hash-object',str(source)],text=True).strip()=='1d084059cd9ed24783fa7355acde9fddba992559';m=runpy.run_path(str(source));lb=pathlib.Path('.github/traffic-publish/brand/anytour-logo-white.png').read_bytes();assert sha(lb)==m['LOGO_SHA256'];logo=Image.open(io.BytesIO(lb)).convert('RGBA')
def work(x):
 i=x['id'];r={'id':i,'name':x['name'],'country_id':x['country']['id'],'catalogue':x,'status':'held','publication_allowed':False};profile=None
 try:
  u='https://anytoour.ru/api-v2.php?action=hotel_details&hotelId='+str(i);raw=get(u);d=json.loads(raw);assert d['id']==i and d['country']['id']==x['country']['id']!=11;assert html.unescape(d['name']).strip()==html.unescape(x['name']).strip();profile={'id':i,'profile':d,'profile_sha256':sha(raw),'profile_url':u,'observed_at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()};urls=[('https:'+u if u.startswith('//') else u) for u in d['images'] if isinstance(u,str)];assert len(urls)>=3
  images=[];proof=[]
  for u in urls[:8]:
   try:
    assert m['check_photo_url'](u)==r['country_id'];im,p=m['download_photo'](u)
    if p['sha256'] in [p0['sha256'] for p0 in proof]:continue
    images.append(im);proof.append(p)
    if len(images)==3:break
   except Exception as ex:r.setdefault('photo_errors',[]).append(str(ex)[:100])
  assert len(images)==3;title=html.unescape(d['name']).strip().title();png=m['encode_png'](m['draw_cover'](title,images,logo.copy()));(ROOT/(str(i)+'.png')).write_bytes(png);r.update(status='prepared_needs_editorial_and_visual_acceptance',title=title,photo_sources=proof,png_sha256=sha(png),profile_sha256=sha(raw),profile_url=profile['profile_url'])
 except Exception as ex:r['reason']=type(ex).__name__+':'+str(ex)[:160]
 return r,profile
with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:results=list(pool.map(work,selected))
recs=[x[0] for x in results];profiles=[x[1] for x in results if x[1] is not None];ready=[r for r in recs if r['status']=='prepared_needs_editorial_and_visual_acceptance'];sheets=[]
for off in range(0,len(ready),10):
 chunk=ready[off:off+10];can=Image.new('RGB',(1200,5*640),'white');draw=ImageDraw.Draw(can)
 for k,r in enumerate(chunk):
  x=(k%2)*600;y=(k//2)*640;draw.text((x+5,y+3),str(r['id'])+' '+r['title'][:52],font=m['font'](16),fill='black');im=Image.open(ROOT/(str(r['id'])+'.png'));can.paste(im.resize((600,600)),(x,y+30))
 name='sheet-'+str(off//10+1)+'.jpg';can.save(ROOT/name,quality=92);sheets.append(name)
assert info('git/ref/heads/audit/content100-bot-publication-20261101')['object']['sha']==HEAD
parent=info('git/commits/'+HEAD);entries=[]
for r in ready:
 b=(ROOT/(str(r['id'])+'.png')).read_bytes();blob=info('git/blobs','POST',{'content':base64.b64encode(b).decode(),'encoding':'base64'});entries.append({'path':PREFIX+'/'+str(r['id'])+'.png','mode':'100644','type':'blob','sha':blob['sha']})
t=info('git/trees','POST',{'base_tree':parent['tree']['sha'],'tree':entries});c=info('git/commits','POST',{'message':'CONTENT W17 source-only staged original covers, not approved or sent','tree':t['sha'],'parents':[HEAD]})
report={'stage':'W17_INTAKE_EDITORIAL_VISUAL_PENDING','cover_commit':c['sha'],'source_head':HEAD,'registry_run':44395164286,'registry_artifact':14467934983,'rows':recs,'catalogue_candidates':len(rows),'selected':len(selected),'prepared':len(ready),'holds':holds,'query_results':[{k:v for k,v in a.items() if k!='data'} for a in answers],'prior_excluded_count':len(old),'source_policy':'Existing accepted paid Tourvisor photo/description add-on, source handoff09ea3194; no unrelated stock.','sender_calls':0,'feed_writes':0,'price_queries':0,'sheets':sheets}
(ROOT/'receipt.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n');(ROOT/'profiles.json').write_text(json.dumps(profiles,ensure_ascii=False,indent=2)+'\n');print(json.dumps({k:v for k,v in report.items() if k not in ['rows','holds','query_results']},ensure_ascii=False))
