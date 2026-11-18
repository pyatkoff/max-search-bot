"""Source-only, exact proved zero-send recovery. Never invokes a publisher."""
import copy,hashlib,io,json,pathlib,re,runpy,subprocess,urllib.request,zipfile
REPO='pyatkoff/max-search-bot'
APP='bd8e248f7138ea75cb9fda2dcf44beb631c633a5'
BEFORE='769dbd6192ba3c34721a39fa55d2e024c0a50c59'
SOURCE=(44412442988,14478115847,'8d653a5601d6a8f880bf11f60e71e2704ec162c1b2b9e105d1927a3de770e55c','8c2d75e7a1ebd81dbc53ee99931cf3c3ee0dbf9a')
FAILED=(44412999450,14478670904,'b7a5017db63f2094d7f5e16216c5306a1e7b154fa7b834af32ce137450de97f4','9d0573c455ad7838f9a91d4422074b7889337800')
DIAG=(44414562662,14478172656,'c66edc2bbcb7c5a9629cf8e22257ad70ef5427a99fa5ad73217eb576cb15fe37',BEFORE)
TEMPLATE_SHA='426b57458bf31eb5c96a85ba9c54df9d8939d885ba706b6de26b026b16a04041'
COMPILER_BLOB='b5320e670f0d030d59d77ec32f19a2b6cc1c039f'
CHANGES={'f5975ab95de5f45e087eb7a0f768f566c5bca648':'c6386e155d42bc83970edc95f35b3ebeb5140533','f0e04e9b4e0ed6859ced96279cbf0619931596e6':'1982ff7368763edf8b7238c3142e3cc28579e991','db8181426091a685a79ee55991cde22671f0d9dd':'cf42a44257d1a58d1ff516678615cf9d01f45b55','43133f576845c14446102dedfffb7f0de2d4ba1c':'1fa260e9720a8421227ecbe740bfbe33a42e398d'}
def sha(b):return hashlib.sha256(b).hexdigest()
def rawjson(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def api(p):return subprocess.check_output(['gh','api','repos/'+REPO+'/'+p],timeout=120)
def info(p):return json.loads(api(p))
def artifact(spec,conclusion):
 run,aid,digest,head=spec;r=info('actions/runs/'+str(run))
 assert r['status']=='completed' and r['conclusion']==conclusion and r['run_attempt']==1 and r['head_sha']==head
 a=info('actions/artifacts/'+str(aid));assert not a['expired'] and a['workflow_run']['id']==run and a['digest']=='sha256:'+digest
 b=api('actions/artifacts/'+str(aid)+'/zip');assert sha(b)==digest
 z=zipfile.ZipFile(io.BytesIO(b));assert z.testzip() is None and len(z.namelist())==len(set(z.namelist()))
 return {n:z.read(n) for n in z.namelist()}
def validate_proof(stopped,diag,jobs):
 assert len(jobs)==1 and jobs[0]['id']==135185814185 and jobs[0]['conclusion']=='failure'
 steps={s['name']:s for s in jobs[0]['steps']}
 for n,c in [(119,'failure'),(120,'skipped'),(121,'skipped')]:assert steps[f'P{n} invoke original bounded publisher ONCE; no retry']['conclusion']==c
 native=[json.loads(l) for l in stopped['original-native-output.jsonl'].decode().splitlines() if l.startswith('{')]
 assert len(native)==1;n=native[0]
 assert n['operation']=='commercial-hotel-batch-20261118-119-v1' and n['ok'] is False and n['post_write_attempts']==0 and n['error']=='channel_rights_not_ready' and n['channels']==[] and n.get('posts',[])==[]
 assert stopped['original-exit.txt'].strip()==b'2'
 d=json.loads(diag['result.json']);assert d['mode']=='P119_STOP_AND_CHANNEL_CAPABILITY_READ_ONLY' and d['installed_sha']==APP
 assert d['ok'] is True and d['all_ready'] is True and d['db_writes']==d['sender_calls']==d['provider_write_attempts']==0
 assert d['original_operation_files']==['publication-summary.json','three-hotels.lock']
 assert d['original_summary_sha256']=='6997d0e4646852fa8e4fa69ebe7690cffefa223c5673dd261dbe53d67d9f9d73' and d['original_summary_bytes']==181
 assert d['original_summary']=={'operation':n['operation'],'ok':False,'error':'channel_rights_not_ready','post_write_attempts':0,'posts_count':0}
 channels=d['channels'];assert len(channels)==2 and {(c['platform'],str(c['channel_id'])) for c in channels}=={('telegram','-1002262666616'),('max','-80492244209683')}
 assert all(c['state']=='ready' and c['reason']=='capability_verified' and c['receipt']['write_attempts']==0 and c['receipt']['request_count']==3 and len(c['receipt']['http_results'])==3 and all(h['http']==200 and h['transport_error'] is False for h in c['receipt']['http_results']) for c in channels)
 return d

def rebuild(blobs,source_dir,proof):
 rec=json.loads(blobs['source-receipt.json'])
 assert sha(blobs['source-receipt.json'])=='5dfcaea2d2bec37625cafe3c916d825c65882d8abb2ac8f8da525f3c1b9e730c'
 assert set(blobs)==set(rec['source_sha256'])|{'source-receipt.json'}
 assert all(sha(blobs[n])==h for n,h in rec['source_sha256'].items())
 assert rec['state']=='WAVE20_SOURCE_ACCEPTED_COVERS_STAGED' and rec['expected_app_sha']==APP and rec['poster_calls']==rec['feed_writes']==0
 assert rec['telegram_disable_notification'] is True and rec['notification_policy_evidence']=='OFFLINE_REQUEST_PAYLOAD_TEST_PASS' and rec['template_sha256']==TEMPLATE_SHA
 assert [g['number'] for g in rec['groups']]==[119,120,121]
 ids=[i for g in rec['groups'] for i in g['hotel_ids']];assert ids==rec['native_hotel_ids'] and len(ids)==len(set(ids))==30
 template=(source_dir/'commercial-batch-template-20261102.php').read_text();assert sha(template.encode())==TEMPLATE_SHA
 compiler=source_dir/'prepare-commercial-batch.py';assert subprocess.check_output(['git','hash-object',str(compiler)],text=True).strip()==COMPILER_BLOB
 mod=runpy.run_path(str(compiler));assert mod['TEMPLATE_SHA']==TEMPLATE_SHA
 def compile_bytes(raw,approval):
  php,_=mod['prepare'](template,raw,approval)
  for old,new in CHANGES.items():assert php.count(old)==1;php=php.replace(old,new)
  assert "'disable_notification'=>'true'" in php
  return php.encode()
 # Reproduce every original publisher exactly before any operation renaming.
 for g in rec['groups']:
  n=g['number'];m=json.loads(blobs[f'p{n}-manifest.json']);a=json.loads(blobs[f'p{n}-approval.json'])
  assert m['operation']==a['operation']==g['operation'] and m['state']=='PREPARED_NOT_SENT'
  assert m['photo_freeze_commit']==rec['cover_commit'] and m['button_text']=='✈️ Узнать стоимость тура'
  assert [r['hotel_id'] for r in m['rows']]==a['hotel_ids']==g['hotel_ids'] and a['expected_installed_sha']==APP
  assert a['manifest_sha256']==sha(blobs[f'p{n}-manifest.json'])
  assert compile_bytes(blobs[f'p{n}-manifest.json'],a)==blobs[f'p{n}-publisher-unexecuted.php']
 manifest=json.loads(blobs['p119-manifest.json']);old=copy.deepcopy(manifest);manifest['operation']='commercial-hotel-batch-20261118-122-v1'
 raw=rawjson(manifest);approval=json.loads(blobs['p119-approval.json']);approval['operation']=manifest['operation'];approval['manifest_sha256']=sha(raw)
 assert {k:v for k,v in manifest.items() if k!='operation'}=={k:v for k,v in old.items() if k!='operation'}
 package={n:b for n,b in blobs.items() if n!='source-receipt.json' and not n.startswith('p119-')}
 package.update({'p122-manifest.json':raw,'p122-approval.json':rawjson(approval),'p122-publisher-unexecuted.php':compile_bytes(raw,approval),'recovery-proof.json':rawjson(proof)})
 for n in [120,121]:
  for suffix in ['manifest.json','approval.json','publisher-unexecuted.php']:assert package[f'p{n}-{suffix}']==blobs[f'p{n}-{suffix}']
 outrec=copy.deepcopy(rec);outrec['groups'][0]['number']=122;outrec['groups'][0]['operation']=manifest['operation'];assert [g['number'] for g in outrec['groups']]==[122,120,121]
 outrec['source_sha256']={n:sha(b) for n,b in package.items()};outrec['recovery']=proof['recovery'];return package,outrec

def main():
 assert subprocess.check_output(['git','rev-parse','HEAD^'],text=True).strip()==BEFORE
 assert info('git/ref/heads/main')['object']['sha']==APP
 stopped=artifact(FAILED,'failure');diag=artifact(DIAG,'success');jobs=info(f'actions/runs/{FAILED[0]}/attempts/1/jobs?per_page=100')['jobs'];d=validate_proof(stopped,diag,jobs)
 blobs=artifact(SOURCE,'success');rec=json.loads(blobs['source-receipt.json'])
 assert stopped['manifest.json']==blobs['p119-manifest.json'] and stopped['approval.json']==blobs['p119-approval.json'] and stopped['source-receipt.json']==blobs['source-receipt.json']
 source=pathlib.Path('tools/direct_demand/operations');config_raw=(source/'wave20-acceptance.json').read_bytes();assert sha(config_raw)=='e72a5c55d5dee8bc3ccda1ae12b877ffa248448820a672d9bb27791fd0cc3c61';config=json.loads(config_raw);assert config['accepted_ids']==rec['native_hotel_ids'];template=(source/'commercial-batch-template-20261102.php').read_text()
 tree=info('git/trees/'+APP+'?recursive=1');assert not tree['truncated'];pins={x['path']:x['sha'] for x in tree['tree']};parts=re.findall(r'"([^"]+)"=>"([a-f0-9]{40})"',template);assert len(parts)==22 and all(pins[p]==CHANGES.get(v,v) for p,v in parts)
 # Fresh native identities/countries plus immutable cover bytes for every original row.
 verified=[]
 for g in rec['groups']:
  for row in json.loads(blobs[f'p{g["number"]}-manifest.json'])['rows']:
   i=row['hotel_id']
   with urllib.request.urlopen('https://anytoour.ru/api-v2.php?action=hotel_details&hotelId='+str(i),timeout=30) as response:current=json.load(response)
   assert current['id']==i and isinstance(current['country']['id'],int) and current['country']['id']==config['countries'][str(i)]!=11
   url=f'https://raw.githubusercontent.com/{REPO}/{rec["cover_commit"]}/.github/traffic-publish/commercial-wave20-20261118/{i}.png';assert row['photo_url']==url
   b=subprocess.check_output(['curl','--fail','--silent','--show-error','--max-time','30',url],timeout=35);assert sha(b)==row['photo_sha256'] and b.startswith(b'\x89PNG\r\n\x1a\n')
   verified.append({'id':i,'country_id':current['country']['id'],'photo_sha256':sha(b)})
 assert [x['id'] for x in verified]==rec['native_hotel_ids']
 proof={'recovery':{'old_operation':'commercial-hotel-batch-20261118-119-v1','new_operation':'commercial-hotel-batch-20261118-122-v1','failed_run':FAILED[0],'failed_head':FAILED[3],'failed_artifact':FAILED[1],'failed_zip_sha256':FAILED[2],'failed_job':135185814185,'diagnosis_run':DIAG[0],'diagnosis_head':DIAG[3],'diagnosis_artifact':DIAG[1],'diagnosis_zip_sha256':DIAG[2],'source_run':SOURCE[0],'source_head':SOURCE[3],'source_artifact':SOURCE[1],'source_zip_sha256':SOURCE[2],'old_operation_retained_not_replayed':True,'old_post_write_attempts':0,'baseline_full_pairs':1662,'expected_full_pairs':1692,'group_order':[122,120,121]},'native_failure':json.loads(stopped['original-native-output.jsonl']),'diagnosis':d,'fresh_identity_and_media_checks':verified}
 package,outrec=rebuild(blobs,source,proof);out=pathlib.Path('accepted-source');out.mkdir()
 for n,b in package.items():(out/n).write_bytes(b)
 subprocess.run(['php',str(source/'test-commercial-publisher-silent.php'),str(source/'commercial-batch-template-20261102.php')],check=True)
 for n in [122,120,121]:
  path=out/f'p{n}-publisher-unexecuted.php';subprocess.run(['php','-l',str(path)],check=True);subprocess.run(['php',str(source/'test-commercial-publisher-silent.php'),str(path)],check=True)
 assert info('git/ref/heads/main')['object']['sha']==APP
 (out/'source-receipt.json').write_bytes(rawjson(outrec));print(json.dumps(outrec))
if __name__=='__main__':main()
