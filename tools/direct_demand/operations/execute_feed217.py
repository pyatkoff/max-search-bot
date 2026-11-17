"""Reviewed same-run artifact materializer, read refresh, compiler and finite serial executor.
Closed until independently reviewed config literal is set. No retries or new publisher.
"""
import base64,datetime,hashlib,io,json,os,pathlib,re,subprocess,sys,zipfile
import prepare_feed217 as compiler
import probe_feed217_remote as probe
import stale_deploy217_guard as stale_deploy
REVIEWED_CONFIG_SHA256='PIN_ONLY_AFTER_EXACT_ACCEPTED_READER_PACKET_AND_ROUTE_ARTIFACT_REVIEW'
ORDER=[1,2,3,4,5,6,8,9,10,11,12,13,14,15,18,17,19,20,21,22,23,24,25,26]
BRANCH='audit/price660-27316-resume-readiness-20261117'
sha=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v:raise ValueError(m)
def now():return datetime.datetime.now(datetime.timezone.utc).isoformat()
def save(p,x):p.write_bytes(json.dumps(x,ensure_ascii=False,separators=(',',':')).encode())
def pin(path,digest):
 raw=path.read_bytes();need(sha(raw)==digest,'pinned_file_changed');return raw
def api(repo,path,raw=False):
 p=subprocess.run(['gh','api','--method','GET','repos/'+repo+'/'+path],capture_output=True,timeout=90);need(p.returncode==0,'github_read_failed');return p.stdout if raw else json.loads(p.stdout)
def safe(root,name):
 p=root/name;need(not pathlib.Path(name).is_absolute() and root.resolve() in p.resolve().parents and not p.is_symlink(),'unsafe_artifact_path');return p

def origin(c):
 need(os.environ.get('GITHUB_REPOSITORY')=='pyatkoff/max-search-bot' and os.environ.get('GITHUB_ACTOR')=='pyatkoff' and os.environ.get('GITHUB_EVENT_NAME')=='push' and os.environ.get('GITHUB_RUN_ATTEMPT')=='1','one_shot_origin_required')
 event=json.loads(pathlib.Path(os.environ['GITHUB_EVENT_PATH']).read_bytes());head=os.environ['GITHUB_SHA'];run=int(os.environ['GITHUB_RUN_ID']);need(run>0 and event['before']==c['parent_sha'],'event_parent_changed')
 need(subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()==head and subprocess.check_output(['git','rev-parse','HEAD^'],text=True).strip()==c['parent_sha'],'checkout_lineage_changed')
 need(api('pyatkoff/max-search-bot','git/ref/heads/'+BRANCH)['object']['sha']==head,'carrier_head_changed')
 actual=api('pyatkoff/max-search-bot','actions/runs/'+str(run));need(actual['head_sha']==head and actual['run_attempt']==1 and actual['event']=='push','authenticated_run_changed')
 claim=api('pyatkoff/max-search-bot','issues/comments/'+str(c['claim_id']));need(claim['user']['id']==226193297 and sha(claim['body'].encode())==c['claim_body_sha256'],'claim_changed')
 for name,h in c['source_sha256'].items():pin(safe(pathlib.Path.cwd(),name),h)
 required={'prepare_feed217.py','probe_feed217_remote.py','run_feed217_probe.py','stale_deploy217_guard.py'};need(required<=set(pathlib.Path(x).name for x in c['source_sha256']),'source_pins_missing')
 pin(pathlib.Path(__file__).with_name('stale_deploy217_guard.py'),'5fe5944207a863e6d7bc7fb2ffd506777ab6b9e8521a2afc2043ffb87526c4d9')
 need(api('pyatkoff/max-search-bot','git/ref/heads/main')['object']['sha']==c['runtime_acceptance']['head_sha'],'runtime_main_changed')
 accepted_run('pyatkoff/max-search-bot',c['runtime_acceptance']['deploy_run_id'],c['runtime_acceptance']['head_sha'])
 return {'repository':'pyatkoff/max-search-bot','run_id':run,'run_attempt':1,'head_sha':head}
def accepted_run(repo,run,head):
 r=api(repo,'actions/runs/'+str(run));need(r['run_attempt']==1 and r['head_sha']==head and r['status']=='completed' and r['conclusion']=='success','artifact_run_not_accepted');return r

def exclusive(c):
 origin(c)
 current=int(os.environ['GITHUB_RUN_ID'])
 for status in ['in_progress','queued','waiting','pending','requested']:
  for repo,path in [('pyatkoff/tour-max','actions/runs?'),('pyatkoff/max-search-bot','actions/runs?'),('pyatkoff/poisk-turov-test','actions/workflows/346454799/runs?')]:
   d=api(repo,path+'status='+status+'&per_page=100');need(d['total_count']==len(d['workflow_runs']) and d['total_count']<=100,'ambiguous_competitor_list');need(not [r for r in d['workflow_runs'] if not(repo=='pyatkoff/max-search-bot' and (r['id']==current or stale_deploy.allowed(r,c['runtime_acceptance']['head_sha'],lambda short,path:api('pyatkoff/'+short,path))))],'competing_writer_or_collector')
 # The carrier concurrency group serializes known publication jobs; require no alternate active carrier.
 d=api('pyatkoff/max-search-bot','actions/runs?branch='+BRANCH+'&per_page=100');need(all(r['id']==current or r['status']=='completed' for r in d['workflow_runs']),'competing_publication_carrier')

def materialize_artifact(desc,out):
 accepted_run(desc['repository'],desc['run_id'],desc['head_sha']);a=api(desc['repository'],'actions/artifacts/'+str(desc['artifact_id']));need(a['expired'] is False and a['workflow_run']['id']==desc['run_id'] and a['digest']=='sha256:'+desc['archive_sha256'],'artifact_metadata_changed')
 raw=api(desc['repository'],'actions/artifacts/'+str(desc['artifact_id'])+'/zip',True);need(len(raw)<100000000 and sha(raw)==desc['archive_sha256'],'artifact_archive_changed')
 out.mkdir(mode=0o700,parents=True,exist_ok=False)
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  need(len(z.namelist())==len(set(z.namelist())),'duplicate_zip_entries')
  for member,item in desc['members'].items():
   info=z.getinfo(member);need(not info.is_dir() and info.file_size<=20000000 and not ((info.external_attr>>16)&0o170000)==0o120000,'artifact_member_invalid');b=z.read(member);need(sha(b)==item['sha256'],'artifact_member_changed');p=safe(out,item['path']);p.parent.mkdir(mode=0o700,parents=True,exist_ok=True);need(not p.exists(),'duplicate_output');p.write_bytes(b)
 return out

def reader_packet(c,out):
 p=c['reader_packet'];accepted_run('pyatkoff/max-search-bot',p['accepted_run_id'],p['source_commit']);need(p['runtime_sha']==c['runtime_acceptance']['head_sha'],'reader_runtime_mismatch_no_rebind')
 if p.get('artifact'):materialize_artifact(p['artifact'],out)
 else:
  out.mkdir(mode=0o700)
  for path,item in p['files'].items():
   need(re.fullmatch('[a-f0-9]{40}',p['source_commit']) is not None,'immutable_reader_source_missing');j=api('pyatkoff/max-search-bot','contents/'+item['source_path']+'?ref='+p['source_commit']);raw=base64.b64decode(j['content']);need(j['type']=='file' and sha(raw)==item['sha256'],'accepted_reader_source_changed');dest=safe(out,path);dest.parent.mkdir(parents=True,exist_ok=True,mode=0o700);dest.write_bytes(raw)
 packet_config=json.loads(pin(out/'admission-config.json',p['admission_config_sha256']));namespace_raw=pin(out/'namespace-provenance.json',p['namespace_provenance_sha256']);need(packet_config['source_sha256']['namespace-provenance.json']==sha(namespace_raw),'packet_namespace_pin_changed')
 intent=json.loads(pin(out/'intent.json',p['intent_sha256']));pin(out/'run217.py',p['runner_sha256']);need(intent['expected_installed_sha']==p['runtime_sha'] and [g['batch'] for g in intent['groups']]==ORDER,'reader_intent_changed')
 for g in intent['groups']:pin(out/f"p{g['batch']:02d}"/'read.php',g['reader_sha256'])
 return intent

def refresh_read(c,root,execution):
 packet=root/'accepted-reader';intent=reader_packet(c,packet);exclusive(c)
 ad={'state':'FRESH_READ_ONLY_ADMISSION','intent_sha256':sha((packet/'intent.json').read_bytes()),'expected_installed_sha':c['runtime_acceptance']['head_sha'],'source_acceptance_verified':True,'owner_and_concurrency_verified':True};save(root/'reader-admission.json',ad)
 started=now();out=root/'fresh-current217';p=subprocess.run([sys.executable,str(packet/'run217.py'),str(packet),str(out),str(root/'reader-admission.json')],capture_output=True,timeout=2400);need(p.returncode==0,'reader_refresh_failed_no_retry')
 summary=json.loads((out/'summary.json').read_bytes());need(summary.get('ok') is True and summary.get('hotels')==217 and len(summary.get('groups',[]))==24 and all(summary.get(k)==0 for k in ['writes','provider_calls','sender_replays']),'reader_refresh_incomplete');compiler.fresh(started)
 # Unchanged reader does not timestamp each result. This receipt bounds EVERY read by the
 # observed runner start and finish; raw bytes remain separately preserved and hash-bound.
 proof={'started_at':started,'finished_at':now(),'runner_sha256':c['reader_packet']['runner_sha256'],'groups':[],'execution':execution}
 normalized=root/'current217-normalized';normalized.mkdir(mode=0o700)
 for g in intent['groups']:
  raw=(out/f"p{g['batch']:02d}.stdout.json").read_bytes();r=json.loads(raw);need(r.get('installed_sha')==c['runtime_acceptance']['head_sha'] and len(r.get('current_registry_receipts',[]))==2*len(g['hotel_ids']),'registry_receipt_count_changed');need(all(x.get('current_registry_receipt_verified') is True and x.get('provider_read_performed') is False for x in r['current_registry_receipts']),'registry_receipt_not_verified');need([x['hotel_id'] for x in r['rows']]==g['hotel_ids'],'reader_namespace_changed')
  r['read_at_utc']=started;r['read_timestamp_provenance']={'kind':'authenticated_runner_start_lower_bound','original_raw_sha256':sha(raw)};dst=normalized/f"p{g['batch']:02d}.json";save(dst,r);proof['groups'].append({'batch':g['batch'],'original_raw_sha256':sha(raw),'normalized_sha256':sha(dst.read_bytes())})
 namespace_raw=(out/'namespace-proof.json').read_bytes();namespace=json.loads(namespace_raw);wanted=[hid for g in intent['groups'] for hid in g['hotel_ids']]
 need(namespace.get('ok') is True and namespace.get('provider_calls')==0 and namespace.get('additional_sql_queries')==0 and namespace.get('legacy_anytour_ids_inferred') is False,'namespace_boundary_changed')
 need([x['native_catalog_id'] for x in namespace.get('rows',[])]==wanted and len(set(wanted))==217,'namespace_scope_changed')
 hashes={g['batch']:g['original_raw_sha256'] for g in proof['groups']}
 for x in namespace['rows']:need(x['catalog_table']=='catalog_hotels' and x['local_tv_id']==x['native_catalog_id'] and x['source_result_sha256']==hashes[x['batch']],'namespace_identity_changed')
 proof['namespace_proof_sha256']=sha(namespace_raw)
 save(root/'read-refresh-proof.json',proof);return normalized,proof

def ssh_php(program):
 t=pathlib.Path(os.environ['RUNNER_TEMP']);host=os.environ['DEPLOY_HOST'];user=os.environ['DEPLOY_USER'];need(re.fullmatch('[A-Za-z0-9_.:-]+',host) is not None and not host.startswith('-') and re.fullmatch('[A-Za-z0-9_-]+',user) is not None,'ssh_destination_invalid')
 cmd=['ssh','-i',str(t/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=15','-o','ServerAliveCountMax=2','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(t/'channel_known_hosts'),user+'@'+host,'unset GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES; cd /var/www/anytoour/data/www/app.anytoour.ru && GIT_OPTIONAL_LOCKS=0 php']
 return subprocess.run(cmd,input=program,capture_output=True,timeout=120)

def validate_writer(receipt,g):
 need(receipt.get('ok') is True and receipt.get('operation')==g['operation'] and receipt.get('mode')=='publish_three_static_public_direct_yml_feeds','native_writer_not_complete')
 need(all(receipt.get(k)==0 for k in ['db_writes','provider_calls','message_sends']),'native_writer_boundary_changed')
 got=receipt.get('files',[]);need([f['name'] for f in got]==list(compiler.NAMES),'native_writer_file_scope')
 for f,w in zip(got,g['files']):need(all(f.get(k)==w[k] for k in ['name','sha256','bytes','rows']) and f.get('write_action')=='replace','native_writer_file_mismatch')

def run_probe(c,root,inv,execution,serial,pairs,expected=None):
 cfg={'state':'REVIEWED_READONLY_FEED217_PROBE','inventory_sha256':compiler.INVENTORY_SHA,'expected_installed_sha':c['runtime_acceptance']['head_sha'],'runtime_acceptance':c['runtime_acceptance'],'feed_date':c['feed_date'],'execution':execution,'originals':[{k:o[k] for k in ['batch','operation','summary_line_sha256']} for o in inv['operations']],'owner_and_concurrency_verified':True,'historical_operation_use_reviewed':True,'probe_repository':execution['repository'],'probe_run_id':execution['run_id'],'probe_run_attempt':1,'previous_pairs':pairs}
 if expected is not None:cfg['expected_feed_sha256']=expected
 config=root/f'probe-{serial}-config.json';save(config,cfg);folder=root/f'probe-{serial}';source=pathlib.Path(__file__).with_name('probe_feed217_remote.py');runner=source.with_name('run_feed217_probe.py')
 p=subprocess.run([sys.executable,str(runner),str(config),sha(config.read_bytes()),str(root/'originals'/'inventory217.json'),str(source),sha(source.read_bytes()),str(folder)],capture_output=True,timeout=260);need(p.returncode==0,'read_only_probe_failed_stop_no_retry');result=json.loads((folder/'probe-result.json').read_bytes());need(result['ok'] is True,'probe_not_complete');return folder,result

def local_evidence(execution,raw,stamp):return {**execution,'source':'THIS_AUTHENTICATED_WORKFLOW_RUN','conclusion':'success','local_result_sha256':sha(raw),'observed_at':stamp}
def main():
 os.umask(0o077);config=pathlib.Path(sys.argv[1]);raw=config.read_bytes();need(re.fullmatch('[a-f0-9]{64}',REVIEWED_CONFIG_SHA256) is not None and sha(raw)==REVIEWED_CONFIG_SHA256,'reviewed_config_literal_missing');c=json.loads(raw);execution=origin(c);exclusive(c)
 root=pathlib.Path(os.environ['RUNNER_TEMP'])/'feed217';root.mkdir(mode=0o700,exist_ok=False);save(root/'execution-context.json',execution);(root/'reviewed-config.json').write_bytes(raw)
 originals=materialize_artifact(c['originals_artifact'],root/'originals');inv=json.loads(pin(originals/'inventory217.json',compiler.INVENTORY_SHA));need([o['batch'] for o in inv['operations']]==ORDER,'original_order_changed')
 routes=materialize_artifact(c['route_artifact'],root/'routes')
 native=materialize_artifact(c['runtime_native_artifact'],root/'runtime-native');native_raw=pin(safe(native,c['runtime_native_file']),c['runtime_acceptance']['native_acceptance_sha256']);native_result=json.loads(native_raw)
 need(c.get('runtime_native_expected_fields') and c.get('runtime_native_runtime_path'),'native_acceptance_checks_missing')
 def field(obj,path):
  for key in path.split('.'):obj=obj[int(key)] if isinstance(obj,list) else obj[key]
  return obj
 need(field(native_result,c['runtime_native_runtime_path'])==c['runtime_acceptance']['head_sha'],'native_runtime_proof_mismatch')
 for path,value in c['runtime_native_expected_fields'].items():need(field(native_result,path)==value,'native_acceptance_result_changed')
 # Always refresh with accepted immutable packet: no freshness guess and no runtime rebinding.
 reads,readproof=refresh_read(c,root,execution)
 exclusive(c);preimages,pr=run_probe(c,root,inv,execution,'initial',660)
 ad={'schema_version':1,'state':'AUTHENTICATED_TAIL217_FEED_COMPILATION_ONLY','inventory_sha256':compiler.INVENTORY_SHA,'expected_installed_sha':c['runtime_acceptance']['head_sha'],'runtime_acceptance':c['runtime_acceptance'],'execution':execution,'original_batches':ORDER,'hotels':217,'previous_pairs':660,'final_pairs':877,'current_read':local_evidence(execution,(root/'read-refresh-proof.json').read_bytes(),readproof['started_at']),'route_install':{k:c['route_artifact'][k] for k in ['repository','run_id','artifact_id','archive_sha256','head_sha']},'preimage_read':local_evidence(execution,(preimages/'probe-result.json').read_bytes(),pr['observed_at']),'previous_feed_sha256':{f['name']:f['sha256'] for f in pr['files']},'groups':[],'current_registry_receipts_verified':True,'old660_routes_preserved':True,'monitor_pending_null':True,'installed_hook_sha256':probe.HOOK_SHA,'feed_date':c['feed_date'],'public_label_overrides':{str(k):v for k,v in compiler.PUBLIC_LABELS.items()},'public_label_originals':{str(k):v for k,v in compiler.PUBLIC_LABEL_ORIGINALS.items()}}
 ad['route_install'].update(run_attempt=1,conclusion='success');ad['preimage_read']['local_public_verified']=True
 need([g['batch'] for g in c['route_groups']]==ORDER,'route_group_order_changed')
 for op,rg in zip(inv['operations'],c['route_groups']):
  g=dict(rg);g['read_file']=f"p{op['batch']:02d}.json";g['read_sha256']=sha((reads/g['read_file']).read_bytes());g['operation']=compiler.feed_operation(c['feed_date'],execution,op['batch']);ad['groups'].append(g)
 admission=root/'compilation-admission.json';save(admission,ad)
 # This digest is derived only AFTER all artifact, source, context and runtime gates pass.
 # It is not an independent user approval; reviewed source/config authorize this bounded compiler.
 plan=compiler.prepare(pathlib.Path.cwd(),originals,reads,routes,preimages,admission,root/'compiled',sha(admission.read_bytes()));save(root/'compiled-plan-review.json',{'source_config_sha256':REVIEWED_CONFIG_SHA256,'plan_sha256':sha((root/'compiled'/'intent.json').read_bytes()),'execution':execution,'max_writers':24,'auto_retry':False})
 evidence=root/'execution';evidence.mkdir(mode=0o700)
 with (evidence/'chain-started.json').open('x') as f:json.dump({'execution':execution,'plan_sha256':sha((root/'compiled'/'intent.json').read_bytes()),'no_retry':True},f);f.flush();os.fsync(f.fileno())
 expected=ad['previous_feed_sha256'];pairs=660;completed=[]
 for g in plan['groups']:
  try:compiler.fresh(readproof['started_at'])
  except ValueError:
   refresh=root/f"refresh-before-{g['batch']}";refresh.mkdir(mode=0o700)
   fresh_reads,fresh_proof=refresh_read(c,refresh,execution)
   for original_group in inv['operations']:
    name=f"p{original_group['batch']:02d}.json";old=json.loads((reads/name).read_bytes());new=json.loads((fresh_reads/name).read_bytes())
    for r in [old,new]:r.pop('read_at_utc',None);r.pop('read_timestamp_provenance',None)
    need(old==new,'refreshed_registry_changed_recompile_required')
   reads,readproof=fresh_reads,fresh_proof
  exclusive(c)
  run_probe(c,root,inv,execution,f"before-{g['batch']}",pairs,expected)
  program=pin(root/'compiled'/f"p{g['batch']:02d}"/'write.php',g['script_sha256'])
  with (evidence/f"p{g['batch']:02d}-started.json").open('x') as f:json.dump({'operation':g['operation'],'script_sha256':g['script_sha256'],'expected_feed_sha256':expected},f);f.flush();os.fsync(f.fileno())
  try:
   # No global or child file lock is held by this process. Unchanged PHP nightly guard
   # obtains its own EX|NB and holds it only for this single original writer.
   p=ssh_php(program);(evidence/f"p{g['batch']:02d}-native-result.json").write_bytes(p.stdout);need(p.returncode==0,'native_writer_failed_no_retry');receipt=json.loads(p.stdout);validate_writer(receipt,g)
   expected={f['name']:f['sha256'] for f in g['files']};pairs+=g['hotels'];run_probe(c,root,inv,execution,f"after-{g['batch']}",pairs,expected)
  except Exception:
   save(evidence/'STOP_INSPECT_ORIGINAL.json',{'state':'STOP_NO_RETRY_OR_SUCCESSOR','operation':g['operation'],'completed':completed,'unknown_or_partial_result_requires_inspection':True});raise
  completed.append({'batch':g['batch'],'operation':g['operation'],'pairs':pairs,'native_result_sha256':sha(p.stdout),'files':g['files']});save(evidence/'progress.json',{'completed':completed,'current_pairs':pairs})
 need(pairs==877 and len(completed)==24,'feed_chain_incomplete');save(evidence/'complete.json',{'ok':True,'pairs':877,'groups':completed,'original660_offer_bytes_preserved':True,'new217_unpriced':True,'new_posts':0,'provider_calls':0,'writer_runs':24,'private_readback_verified':True});print('FEED217_CHAIN_COMPLETE pairs=877 groups=24 new_posts=0 provider_calls=0')
if __name__=='__main__':
 try:main()
 except Exception as e:
  code=str(e) if isinstance(e,ValueError) and re.fullmatch('[a-z0-9_]{1,100}',str(e)) else type(e).__name__
  print('FEED217_STOP '+code);sys.exit(2)
