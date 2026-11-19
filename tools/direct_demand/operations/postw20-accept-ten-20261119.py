import base64,hashlib,io,json,os,pathlib,re,runpy,subprocess,zipfile,urllib.request
REPO='pyatkoff/max-search-bot';APP='9b1e17f94f39d8d2cc6260a9a6f45b1d1f38bb3e';ROOT=pathlib.Path('accepted-source');ROOT.mkdir()
def api(p):return subprocess.check_output(['gh','api','repos/'+REPO+'/'+p],timeout=100)
def info(p):return json.loads(api(p))
def sha(b):return hashlib.sha256(b).hexdigest()
def fetch(path,ref):return base64.b64decode(info('contents/'+path+'?ref='+ref)['content'])
raw=fetch('tools/direct_demand/operations/postw20-funnel-20261119/publisher-ten-rows.json','eab9d9fbd60e532dbbf9536a7f89e9f925017593')
assert sha(raw)=='9ae04528ca611b4e10510264edcb6481d01c713bae629a6ca47ff68282267773'
source=json.loads(raw);rows=source['rows'][:3];IDS=[116719,28569,32354];assert [r['hotel_id'] for r in rows]==IDS
run=info('actions/runs/44606529618');assert run['conclusion']=='success' and run['run_attempt']==1 and run['head_sha']=='96f827fda086f3c6c03db9318f06a3fcb6f33f10'
a=info('actions/artifacts/14570780923');assert a['workflow_run']['id']==44606529618 and not a['expired'] and a['digest']=='sha256:cf74c122e9cdb8802e52b1a36031779f50460fea0a1ff2d2338c812227be8d81'
zraw=api('actions/artifacts/14570780923/zip');assert sha(zraw)=='cf74c122e9cdb8802e52b1a36031779f50460fea0a1ff2d2338c812227be8d81'
registry_raw=zipfile.ZipFile(io.BytesIO(zraw)).read('registry-receipt.json');registry=json.loads(registry_raw);assert registry['installed_sha']==APP and not registry['matches']
assert set(IDS).isdisjoint(int(x['native_id']) for x in registry['rows'])
assert all(not any(a.lower() in x['title'].lower() for a in r['aliases']) for r in rows for x in registry['rows'])
assert info('git/ref/heads/main')['object']['sha']==APP
d=json.loads(fetch('deploy_status.json','diagnostics'));release=d['repository_release'];assert release['kind']=='installed' and release['repository_sha']==release['application_sha']==d['sha']==APP and all(s['result']=='success' for s in release['stages'].values())
required={'deploy.yml','retired-domain-guard.yml','max-tls-preflight.yml','max-production-health.yml','telegram-production-health.yml','publish-conversation-diagnostics.yml','live-session-diagnostics.yml','tour-selection-host.yml','modeled-conversion-monitor.yml'}
g={v['path'].rsplit('/',1)[-1]:v for v in release['closure']['gates']};assert required<=g.keys() and all(g[x]['conclusion']=='success' and g[x]['head_sha']==APP and g[x]['run_attempt']==1 for x in required)
s=pathlib.Path('tools/direct_demand/operations');compiler=s/'prepare-commercial-batch.py';assert subprocess.check_output(['git','hash-object',str(compiler)],text=True).strip()=='b5320e670f0d030d59d77ec32f19a2b6cc1c039f';mod=runpy.run_path(str(compiler));template=(s/'commercial-batch-template-20261102.php').read_text();assert sha(template.encode())==mod['TEMPLATE_SHA']
changes={'f5975ab95de5f45e087eb7a0f768f566c5bca648':'c6386e155d42bc83970edc95f35b3ebeb5140533','f0e04e9b4e0ed6859ced96279cbf0619931596e6':'1982ff7368763edf8b7238c3142e3cc28579e991','db8181426091a685a79ee55991cde22671f0d9dd':'cf42a44257d1a58d1ff516678615cf9d01f45b55','43133f576845c14446102dedfffb7f0de2d4ba1c':'1fa260e9720a8421227ecbe740bfbe33a42e398d','300634d77cb9fddf51adf9869974f956cca6e3f9':'704c1587e59a5cff6a33c656296af0cb29b4bfd5'}
t=info('git/trees/'+APP+'?recursive=1');assert not t['truncated'];pins={x['path']:x['sha'] for x in t['tree']};parts=re.findall(r'"([^"]+)"=>"([a-f0-9]{40})"',template);assert len(parts)==22 and all(pins[p]==changes.get(v,v) for p,v in parts)
subprocess.run(['php',str(s/'test-commercial-publisher-silent.php'),str(s/'commercial-batch-template-20261102.php')],check=True)
countries={};images=[]
for r in rows:
 i=r['hotel_id']
 with urllib.request.urlopen('https://anytoour.ru/api-v2.php?action=hotel_details&hotelId='+str(i),timeout=30) as response:current=json.load(response)
 assert current['id']==i and current['country']['id'] in [2,16] and current['country']['id']!=11;countries[str(i)]=current['country']['id']
 b=subprocess.check_output(['curl','--fail','--silent','--show-error','--max-time','40',r['photo_url']],timeout=45);assert sha(b)==r['photo_sha256'] and b.startswith(b'\x89PNG\r\n\x1a\n');images.append({'hotel_id':i,'sha256':sha(b),'bytes':len(b),'url':r['photo_url']})
 assert sha(r['text'].encode())==r['text_sha256']
reviews={
116719:{'sources':['https://blisshoian.com/our-resort/','https://blisshoian.com/sightseeing-tours-services/free-shuttle-bus/'],'beach':'Binh Minh first-line resort; accepted primary source packet; no distance promised','pools':'55m outdoor sea-facing pool; no private-pool generalisation'},
28569:{'sources':['https://www.twinlotusresort.com/'],'beach':'Only area of Klong Dao beach; 300m conflict held; beachfront property only named villa category','pools':'Pool Villa is a specific booking category, no pool entitlement promised for all rooms'},
32354:{'sources':['https://www.phuquocecobeachresort.com/en/','https://www.phuquocecobeachresort.com/en/reservation'],'beach':'Ong Lang; 100m applies only Superior Garden View on slope; Beach Front separate category','pools':'Outdoor sea-view swimming pool; no enclosed or heated facility claimed'}}
qualified={}
for i in IDS:
 v=reviews[i];checks={k:{'status':'verified' if k in ['beach','pools'] else 'not_applicable','evidence':v[k] if k in v else 'No waterpark/kids-club promise in this approved caption; no assertion of absence','sources':v['sources']} for k in ['beach','waterpark','kids_club','pools']}
 qualified[str(i)]={'operational_review':{'status':'confirmed_open','current_identity_verified':True,'material_conditions_verified':True,'future_opening_or_rebrand_unresolved':False,'sources':v['sources'],'evidence_source':'User-approved current source7018162446 and immutable e8b269af packet; exact live provider ID/country checked above'},'material_checks':checks}
 for key,check in checks.items():assert check['status'] in ['verified','not_applicable'] and check['evidence'] and check['sources']
for r in rows:r['sources']+=reviews[r['hotel_id']]['sources']
op='commercial-hotel-batch-20261119-1-v1';manifest={'schema_version':1,'operation':op,'state':'PREPARED_NOT_SENT','button_text':source['button_text'],'expected_new_full_pairs':3,'photo_freeze_commit':source['photo_freeze_commit'],'native_id_namespace':'Tourvisor','rows':rows};raw=(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n').encode();approval={'schema_version':1,'operation':op,'expected_installed_sha':APP,'hotel_ids':IDS,'manifest_sha256':sha(raw)};php,report=mod['prepare'](template,raw,approval)
for old,new in changes.items():assert php.count(old)==1;php=php.replace(old,new)
package={'registry-receipt.json':registry_raw,'p1-manifest.json':raw,'p1-approval.json':(json.dumps(approval,ensure_ascii=False,indent=2)+'\n').encode(),'p1-publisher-unexecuted.php':php.encode(),'image-receipt.json':(json.dumps(images,indent=2)+'\n').encode(),'qualification-receipt.json':(json.dumps(qualified,indent=2)+'\n').encode()}
for n,b in package.items():(ROOT/n).write_bytes(b)
subprocess.run(['php',str(s/'test-commercial-publisher-silent.php'),str(ROOT/'p1-publisher-unexecuted.php')],check=True)
assert info('git/ref/heads/main')['object']['sha']==APP
rec={'state':'POSTW20_SOURCE_ACCEPTED_COVERS_STAGED','groups':[{'number':1,'operation':op,'hotel_ids':IDS}],'native_hotel_ids':IDS,'countries':countries,'expected_app_sha':APP,'cover_commit':source['photo_freeze_commit'],'source_commit':'eab9d9fbd60e532dbbf9536a7f89e9f925017593','telegram_disable_notification':True,'notification_policy_evidence':'OFFLINE_REQUEST_PAYLOAD_TEST_PASS','template_sha256':mod['TEMPLATE_SHA'],'poster_calls':0,'feed_writes':0,'source_sha256':{n:sha(b) for n,b in package.items()}}
(ROOT/'source-receipt.json').write_text(json.dumps(rec,indent=2)+'\n');print(json.dumps(rec))
