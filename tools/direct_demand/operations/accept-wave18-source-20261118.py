import base64,hashlib,io,json,os,pathlib,re,runpy,subprocess,zipfile,urllib.request
REPO='pyatkoff/max-search-bot';APP='bd8e248f7138ea75cb9fda2dcf44beb631c633a5';HEAD=os.environ['GITHUB_SHA'];ROOT=pathlib.Path('accepted-source');ROOT.mkdir()
def api(p):return subprocess.check_output(['gh','api','repos/'+REPO+'/'+p],timeout=120)
def info(p):return json.loads(api(p))
def sha(b):return hashlib.sha256(b).hexdigest()
def source(p,ref,expected):
 b=base64.b64decode(info('contents/tools/direct_demand/operations/'+p+'?ref='+ref)['content']);assert sha(b)==expected;return json.loads(b)
def artifact(run,aid,digest):
 r=info('actions/runs/'+str(run));assert r['conclusion']=='success' and r['run_attempt']==1
 a=info('actions/artifacts/'+str(aid));assert a['workflow_run']['id']==run and a['digest']=='sha256:'+digest and not a['expired'];b=api('actions/artifacts/'+str(aid)+'/zip');assert sha(b)==digest;return zipfile.ZipFile(io.BytesIO(b))
config=json.loads(pathlib.Path('tools/direct_demand/operations/wave18-acceptance.json').read_bytes());IDS=config['accepted_ids'];assert len(IDS)==len(set(IDS)) and 0<len(IDS)<=100
registry_raw=artifact(**config['registry_artifact']).read('registry-receipt.json');registry=json.loads(registry_raw);assert registry['installed_sha']==APP
assert set(IDS).isdisjoint(int(x['native_id']) for x in registry['rows'])
assert all(not any(a.lower() in x['title'].lower() for a in config['aliases'][str(i)]) for i in IDS for x in registry['rows'])
z=artifact(**config['cover_artifact']);cover_raw=z.read('receipt.json');assert sha(cover_raw)==config['cover_receipt_sha256'];covers=json.loads(cover_raw);COMMIT=covers['cover_commit'];reviewed={r['id']:r for r in covers['rows']}
assert info('git/ref/heads/main')['object']['sha']==APP
s=pathlib.Path('tools/direct_demand/operations');compiler=s/'prepare-commercial-batch.py';assert subprocess.check_output(['git','hash-object',str(compiler)],text=True).strip()=='b5320e670f0d030d59d77ec32f19a2b6cc1c039f';mod=runpy.run_path(str(compiler));template=(s/'commercial-batch-template-20261102.php').read_text();assert sha(template.encode())==mod['TEMPLATE_SHA']
changes={'f5975ab95de5f45e087eb7a0f768f566c5bca648':'c6386e155d42bc83970edc95f35b3ebeb5140533','f0e04e9b4e0ed6859ced96279cbf0619931596e6':'1982ff7368763edf8b7238c3142e3cc28579e991','db8181426091a685a79ee55991cde22671f0d9dd':'cf42a44257d1a58d1ff516678615cf9d01f45b55','43133f576845c14446102dedfffb7f0de2d4ba1c':'1fa260e9720a8421227ecbe740bfbe33a42e398d'}
t=info('git/trees/'+APP+'?recursive=1');assert not t['truncated'];pins={x['path']:x['sha'] for x in t['tree']};parts=re.findall(r'"([^"]+)"=>"([a-f0-9]{40})"',template);assert len(parts)==22 and all(pins[p]==changes.get(v,v) for p,v in parts)
subprocess.run(['php',str(s/'test-commercial-publisher-silent.php'),str(s/'commercial-batch-template-20261102.php')],check=True)
rows=[]
for i in IDS:
 review=config['operational_review'][str(i)];assert review['status']=='confirmed_open' and review['current_identity_verified'] is True and review['material_conditions_verified'] is True and review['future_opening_or_rebrand_unresolved'] is False and review['sources']
 checks=config['editorial'][str(i)]['material_checks'];assert set(checks)=={'beach','waterpark','kids_club','pools'}
 for key,check in checks.items():assert check['status'] in ['verified','not_applicable'] and check['evidence'] and check['sources'],('material_condition_unresolved',i,key)
 c=reviewed[i];r=config['editorial'][str(i)];assert c['status']=='prepared_needs_editorial_and_visual_acceptance';assert sha(r['caption'].encode())==r['caption_sha256'];assert c['png_sha256']==config['accepted_cover_hashes'][str(i)]
 with urllib.request.urlopen('https://anytoour.ru/api-v2.php?action=hotel_details&hotelId='+str(i),timeout=30) as resp: current=json.load(resp)
 assert current['id']==i and current['country']['id']==config['countries'][str(i)]!=11
 url=f'https://raw.githubusercontent.com/{REPO}/{COMMIT}/.github/traffic-publish/commercial-wave18-20261118/{i}.png';b=subprocess.check_output(['curl','--fail','--silent','--show-error','--max-time','30',url],timeout=35);assert sha(b)==c['png_sha256'] and b.startswith(b'\x89PNG\r\n\x1a\n')
 rows.append({'hotel_id':i,'logical_id':f'commercial-hotel-{i}-v1','aliases':config['aliases'][str(i)],'text':r['caption'],'text_sha256':r['caption_sha256'],'photo_sha256':c['png_sha256'],'photo_url':url,'media_state':'REAL_OFFICIAL_PHOTOGRAPHY_BRANDED_COVER_VISUALLY_VERIFIED','sources':[c['profile_url']]+[x['source_url'] for x in c['photo_sources']]})
package={'registry-receipt.json':registry_raw,'image-receipt.json':cover_raw};groups=[]
for off in range(0,len(rows),10):
 n=113+off//10;rs=rows[off:off+10];op=f'commercial-hotel-batch-20261118-{n}-v1';ids=[r['hotel_id'] for r in rs];manifest={'schema_version':1,'operation':op,'state':'PREPARED_NOT_SENT','button_text':'✈️ Узнать стоимость тура','expected_new_full_pairs':len(ids),'photo_freeze_commit':COMMIT,'native_id_namespace':'Tourvisor','rows':rs};raw=(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n').encode();approval={'schema_version':1,'operation':op,'expected_installed_sha':APP,'hotel_ids':ids,'manifest_sha256':sha(raw)};php,report=mod['prepare'](template,raw,approval)
 for old,new in changes.items():assert php.count(old)==1;php=php.replace(old,new)
 package[f'p{n}-manifest.json']=raw;package[f'p{n}-approval.json']=(json.dumps(approval,ensure_ascii=False,indent=2)+'\n').encode();package[f'p{n}-publisher-unexecuted.php']=php.encode();groups.append({'number':n,'operation':op,'hotel_ids':ids})
for n,b in package.items():(ROOT/n).write_bytes(b)
for name in package:
 if name.endswith('publisher-unexecuted.php'):subprocess.run(['php',str(s/'test-commercial-publisher-silent.php'),str(ROOT/name)],check=True)
assert info('git/ref/heads/main')['object']['sha']==APP
rec={'state':'WAVE18_SOURCE_ACCEPTED_COVERS_STAGED','groups':groups,'native_hotel_ids':IDS,'expected_app_sha':APP,'cover_commit':COMMIT,'telegram_disable_notification':True,'notification_policy_evidence':'OFFLINE_REQUEST_PAYLOAD_TEST_PASS','template_sha256':mod['TEMPLATE_SHA'],'poster_calls':0,'feed_writes':0,'source_sha256':{n:sha(b) for n,b in package.items()}}
(ROOT/'source-receipt.json').write_text(json.dumps(rec,indent=2)+'\n');print(json.dumps(rec))
