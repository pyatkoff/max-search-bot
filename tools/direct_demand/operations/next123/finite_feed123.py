"""Finite staged workflow: private-repo authorization is a fresh root connector permit.
No private-repo API calls, persistent worker, credentials or workflow mutation.
"""
import datetime,hashlib,json,os,pathlib,re,sys,time
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parent.parent))
import execute_feed123 as e
import owner_permit_gate as gate
from route_read_lineage import rebind
import nonwriting_queued217_guard as nonwriting
from immutable_source_cache import cached_api
REVIEWED_CONFIG_SHA256=os.environ.get('REVIEWED_FEED123_CONFIG_SHA256')
MAX_WAIT_SECONDS=120
MAX_POLLS_PER_STEP=12
MAX_CHAIN_POLL_READS=144
MAX_CHAIN_PERMIT_GUARDS=26
MAX_REFRESHES=2
PREPARE_GET_CAP=200
FIRST_WRITER_REQUIRED_REMAINING=782
BASE_API=e.api
def install_cache():
 root=pathlib.Path(os.environ["RUNNER_TEMP"])/"feed123-immutable-source-cache"
 e.api=lambda repo,path,raw=False:cached_api(BASE_API,root,repo,path,raw)
def rate_snapshot():
 p=e.subprocess.run(["gh","api","--method","GET","rate_limit"],capture_output=True,timeout=30);e.need(p.returncode==0,"rate_snapshot_failed")
 r=json.loads(p.stdout)["resources"]["core"];e.need(type(r.get("remaining")) is int and type(r.get("reset")) is int and type(r.get("used")) is int,"rate_snapshot_invalid");return r

def initialize_prepare_rate_window(c,root):
 # Exactly one uncached charged GET before the budget baseline. No retry/reset tolerance.
 before=rate_snapshot()
 diagnostic={'warmup_requests':1,'state':'BEFORE_SINGLE_MAIN_GET','before':{k:before[k] for k in ('used','reset','remaining')}}
 e.save(root/'prepare-rate-warmup.json',diagnostic)
 main=BASE_API('pyatkoff/max-search-bot','git/ref/heads/main')
 e.need(main['object']['sha']==c['runtime_acceptance']['head_sha'],'warmup_runtime_main_changed')
 baseline=rate_snapshot()
 diagnostic.update(state='AFTER_SINGLE_MAIN_GET',after={k:baseline[k] for k in ('used','reset','remaining')},runtime_main_verified=True)
 e.save(root/'prepare-rate-warmup.json',diagnostic)
 e.need(type(baseline['used']) is int and baseline['used']>=1,'warmup_baseline_not_charged')
 return baseline

def validate_prepare_rate_budget(before,after):
 delta=after['used']-before['used']
 e.need(after['reset']==before['reset'] and delta>=0 and delta+1<=PREPARE_GET_CAP,'prepare_rate_window_or_budget_changed')

def public_exclusive(c):
 execution=e.origin(c);current=int(os.environ['GITHUB_RUN_ID'])
 e.pin(pathlib.Path(__file__).with_name('nonwriting_queued217_guard.py'),'5fa9881727735a875b9c6ae53343443d01ec7ec7bac09530e7cfd25353e2b349')
 for status in gate.STATUSES:
  for repo,path in [('pyatkoff/max-search-bot','actions/runs?'),('pyatkoff/poisk-turov-test','actions/runs?')]:
   d=e.api(repo,path+'status='+status+'&per_page=100')
   if not (d['total_count']==len(d['workflow_runs']) and d['total_count']<=100):
    diagnostic={'reason':'ambiguous_public_runs','observed_at':e.now(),'repository':repo,'queried_status':status,'total_count':d['total_count'],'returned_count':len(d['workflow_runs']),'page_limit':100}
    diagnostic_root=pathlib.Path(os.environ['RUNNER_TEMP'])/'feed123';diagnostic_root.mkdir(mode=0o700,parents=True,exist_ok=True);e.save(diagnostic_root/'blocked-guard.json',diagnostic)
   e.need(d['total_count']==len(d['workflow_runs']) and d['total_count']<=100,'ambiguous_public_runs')
   if repo=='pyatkoff/poisk-turov-test':
    e.need(all(type(r.get('workflow_id')) is int and r['workflow_id']>0 for r in d['workflow_runs']),'unknown_collector_workflow')
    d['workflow_runs']=[r for r in d['workflow_runs'] if r['workflow_id']==346454799]
   for r in d['workflow_runs']:
    allowed=repo=='pyatkoff/max-search-bot' and (r['id']==current or e.stale_deploy.allowed(r,c['runtime_acceptance']['head_sha'],lambda short,path:e.api('pyatkoff/'+short,path)) or nonwriting.allowed(r,c['runtime_acceptance']['head_sha'],lambda short,path:e.api('pyatkoff/'+short,path)))
    if not allowed:
     # Bounded scalar metadata only. No logs, credentials, arbitrary titles or messages.
     diagnostic={'reason':'competing_public_writer_or_collector','observed_at':e.now(),'repository':repo,'queried_status':status,'total_count':d['total_count'],'blocked_runs':[{'id':r.get('id'),'workflow_id':r.get('workflow_id'),'status':r.get('status'),'run_attempt':r.get('run_attempt'),'head_sha':r.get('head_sha'),'path':str(r.get('path',''))[:300],'event':str(r.get('event',''))[:40]}]}
     diagnostic_root=pathlib.Path(os.environ['RUNNER_TEMP'])/'feed123';diagnostic_root.mkdir(mode=0o700,parents=True,exist_ok=True);e.save(diagnostic_root/'blocked-guard.json',diagnostic)
    e.need(allowed,'competing_public_writer_or_collector')
 return execution
 # Private coordination is deliberately NOT represented as clear here. EACH write
 # requires authenticated fresh owner permit immediately before native invocation.

def config(path):
 raw=pathlib.Path(path).read_bytes()
 e.need(type(REVIEWED_CONFIG_SHA256) is str and re.fullmatch('[a-f0-9]{64}',REVIEWED_CONFIG_SHA256) and e.sha(raw)==REVIEWED_CONFIG_SHA256,'closed_reviewed_config_gate')
 c=json.loads(raw);e.need(c['gate']=='REVIEWED_NEXT123_FINITE' and c['execution_mode'] in ('PREPARE_ONLY','WRITE_NEXT123'),'execution_gate_closed')
 e.need(c['permit_comment_id']!=c['claim_id'] and type(c['permit_comment_id']) is int and c['permit_comment_id']>0,'new_exact_permit_required')
 e.need(not any(k in c for k in ['prefix_resume','price_evolution','continuation_prefix','runtime_transition']),'no_prefix_replay_or_crossruntime')
 e.need(c['root_verified_route_source']['run_id']==44259319651 and c['root_verified_route_source']['artifact_id']==14406754252 and c['root_verified_route_source']['archive_sha256']=='b29dace25c25f64b49c331d7246cb621666fadbecba97ec6669e1dff40d25f04','actual_routes1000_required')
 e.need(c['baseline877']['previous_native_receipt_sha256']=='78318d0d578714b6073e31eb3eed9999d1d0c7f20ef83dc1f7c1906a5f4ce632','historical877_chain_seed_changed')
 e.need(c['baseline877']['seed_proof_sha256']=='3d2928e97c42a5bdcb41d8cc9d652410092c9eced1b9a63404fa967a93c6cda5','historical877_seed_proof_changed')
 e.need(c['permit_comment_id']==6972314498,'exact_new_permit_slot')
 e.need(c['runtime_acceptance']['head_sha']=='cc017ee5bb669f4c7c13efc91d5c5812a50e55f2','no_runtime_rebinding')
 return c,raw

def prepare(c,raw):
 root=pathlib.Path(os.environ['RUNNER_TEMP'])/'feed123';root.mkdir(mode=0o700,exist_ok=False);(root/'reviewed-config.json').write_bytes(raw)
 before_rate=initialize_prepare_rate_window(c,root);execution=public_exclusive(c);e.save(root/'execution-context.json',execution)
 originals=root/'originals';originals.mkdir(mode=0o700)
 frozen=pathlib.Path(__file__).with_name('inventory')
 for source in sorted(frozen.rglob('*')):
  e.need(not source.is_symlink(),'frozen_inventory_symlink')
  if source.is_file():
   rel=source.relative_to(pathlib.Path.cwd()).as_posix();e.need(rel in c['source_sha256'],'frozen_inventory_not_source_pinned');data=e.pin(source,c['source_sha256'][rel]);target=originals/source.relative_to(frozen);target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(data)
 inv=e.compiler.inventory_projection(originals);e.need([x['batch'] for x in inv['operations']]==e.ORDER,'original_order_changed')
 seedraw=e.pin(e.safe(pathlib.Path.cwd(),c['baseline877']['seed_proof_path']),c['baseline877']['seed_proof_sha256']);seed=json.loads(seedraw)
 e.need(seed['initial_previous_native_receipt_sha256']==c['baseline877']['previous_native_receipt_sha256'] and seed['historical_receipt_rewritten'] is False and seed['price_readback']['current_feed_sha256']==c['baseline877']['expected_feeds'],'seed877_proof_binding_changed')
 # PRICE877 is separately authenticated; never rewrite final historical877 writer receipt.
 baseline_packet=e.materialize_artifact(c['price877_artifact'],root/'price877')
 baseline_raw=e.pin(e.safe(baseline_packet,c['price877_result_file']),c['price877_result_sha256']);baseline_native=json.loads(baseline_raw)
 from price_readback_handoff import validate_summary
 validate_summary(baseline_raw)
 e.need(c['baseline877']['expected_feeds']=={name:x['sha256'] for name,x in baseline_native['feeds'].items()},'price877_preimages_changed')
 original_reads=e.materialize_artifact(c['original_read_artifact'],root/'original-reads')
 native=e.materialize_artifact(c['runtime_native_artifact'],root/'runtime-native');nr=json.loads(e.pin(e.safe(native,c['runtime_native_file']),c['runtime_acceptance']['native_acceptance_sha256']))
 def field(obj,path):
  for key in path.split('.'):obj=obj[int(key)] if isinstance(obj,list) else obj[key]
  return obj
 e.need(field(nr,c['runtime_native_runtime_path'])==c['runtime_acceptance']['head_sha'] and c['runtime_native_expected_fields'],'native_acceptance_missing')
 for key,value in c['runtime_native_expected_fields'].items():e.need(field(nr,key)==value,'native_acceptance_changed')
 # Source/config-pinned root projection is public-safe, verified by root against actual
 # private maps/receipts beforehand. This workflow does NOT authenticate private API access.
 packet_raw=e.pin(e.safe(pathlib.Path.cwd(),c['route_projection_packet']['path']),c['route_projection_packet']['sha256']);packet=json.loads(packet_raw)
 e.need(packet['source']==c['root_verified_route_source'] and packet.get('root_verified_actual_private_artifact') is True and [g['batch'] for g in packet['groups']]==e.ORDER,'root_projection_scope_changed')
 # Accepted unchanged refresh runner. Its own native read_at_utc is kept, not normalized.
 e.exclusive=public_exclusive
 _,refresh=e.refresh_read(c,root,execution);reads=root/'fresh-current123';routes=root/'routes';routes.mkdir(mode=0o700)
 public_exclusive(c);preimages,pr=e.run_probe(c,root,inv,execution,'initial',877,c['baseline877']['expected_feeds'])
 ad={'schema_version':1,'state':'AUTHENTICATED_NEXT123_FEED_COMPILATION_ONLY','inventory_sha256':e.compiler.INVENTORY_SHA,'expected_installed_sha':c['runtime_acceptance']['head_sha'],'runtime_acceptance':c['runtime_acceptance'],'execution':execution,'original_batches':e.ORDER,'hotels':123,'previous_pairs':877,'final_pairs':1000,'current_read':e.local_evidence(execution,(root/'read-refresh-proof.json').read_bytes(),refresh['started_at']),'route_install':dict(packet['source']),'preimage_read':e.local_evidence(execution,(preimages/'probe-result.json').read_bytes(),pr['observed_at']),'previous_feed_sha256':{f['name']:f['sha256'] for f in pr['files']},'groups':[],'current_registry_receipts_verified':True,'old877_routes_preserved':True,'monitor_pending_null':True,'installed_hook_sha256':e.probe.HOOK_SHA,'feed_date':c['feed_date'],'public_label_overrides':{str(k):v for k,v in e.compiler.PUBLIC_LABELS.items()},'public_label_originals':{str(k):v for k,v in e.compiler.PUBLIC_LABEL_ORIGINALS.items()}}
 ad['preimage_read']['local_public_verified']=True
 for item in packet['groups']:
  b=item['batch'];old=e.pin(e.safe(original_reads,item['original_read_file']),item['proof']['bound_read_sha256']);new=(reads/f'p{b:02d}.stdout.json').read_bytes();proofraw=json.dumps(item['proof'],ensure_ascii=False,separators=(',',':')).encode();derived,record=rebind(proofraw,old,new)
  d=routes/f'p{b:02d}';d.mkdir(mode=0o700)
  blobs={'route-receipt.json':item['receipt_utf8'].encode(),'route-manifest.json':item['manifest_projection_utf8'].encode(),'route-proof.json':derived,'original-root-proof.json':proofraw,'original-read.json':old,'fresh-read.json':new,'read-proof-lineage.json':json.dumps(record).encode()}
  for name,data in blobs.items():(d/name).write_bytes(data)
  ad['groups'].append({'batch':b,'operation':e.compiler.feed_operation(c['feed_date'],execution,b),'read_file':f'p{b:02d}.stdout.json','read_sha256':e.sha(new),'route_receipt_file':f'p{b:02d}/route-receipt.json','route_receipt_sha256':e.sha(blobs['route-receipt.json']),'route_manifest_file':f'p{b:02d}/route-manifest.json','route_manifest_sha256':e.sha(blobs['route-manifest.json']),'route_proof_file':f'p{b:02d}/route-proof.json','route_proof_sha256':e.sha(derived),'original_route_manifest_sha256':item['proof']['original_route_manifest_sha256']})
 admission=root/'compilation-admission.json';e.save(admission,ad);plan=e.compiler.prepare(pathlib.Path.cwd(),originals,reads,routes,preimages,admission,root/'compiled',e.sha(admission.read_bytes()))
 e.need([g['batch'] for g in plan['groups']]==e.ORDER and len(plan['groups'])==14,'new123_scope_changed')
 plan_sha=e.sha((root/'compiled'/'intent.json').read_bytes());progress=root/'progress';progress.mkdir(mode=0o700)
 e.save(root/'state.json',{'next_index':0,'previous_native_receipt_sha256':c['baseline877']['previous_native_receipt_sha256'],'pairs':877,'expected_feeds':ad['previous_feed_sha256'],'plan_sha256':plan_sha,'root_projection_sha256':e.sha(packet_raw),'read_started_at':refresh['started_at'],'original_read_root':str(reads),'completed':[],'poll_reads_used':0,'permit_guards_used':0,'read_refreshes':0,'first_writer_budget_checked':False})
 after_rate=rate_snapshot()
 # Persist only bounded numeric evidence BEFORE the unchanged safety predicate.
 e.save(root/'prepare-rate-diagnostic.json',{'stage':'AFTER_COMPILATION_BEFORE_PREPARE_BUDGET_GATE','before':{k:before_rate[k] for k in ('used','reset','remaining')},'after':{k:after_rate[k] for k in ('used','reset','remaining')},'used_delta':after_rate['used']-before_rate['used'],'warmup_requests':1,'charged_prepare_reads':after_rate['used']-before_rate['used']+1,'same_reset_window':after_rate['reset']==before_rate['reset'],'prepare_cap':PREPARE_GET_CAP})
 validate_prepare_rate_budget(before_rate,after_rate)
 e.save(root/'prepare-rate-budget.json',{'before':before_rate,'after':after_rate,'warmup_requests':1,'charged_prepare_reads':after_rate['used']-before_rate['used']+1,'conservative_prepare_cap':PREPARE_GET_CAP,'writer_suffix_cap':682,'reserved_margin':100})
 # Only hash/operation/count metadata for live root coordination, never raw reads/maps.
 deliver=root/'deliver-plan';deliver.mkdir(mode=0o700);e.save(deliver/'plan.json',{'execution':execution,'plan_sha256':plan_sha,'root_projection_sha256':e.sha(packet_raw),'groups':[{k:g[k] for k in ['batch','operation','hotels','script_sha256','files']} for g in plan['groups']],'initial_preimages':ad['previous_feed_sha256'],'execution_mode':c['execution_mode'],'initial_pairs':877,'hotels':123,'initial_previous_native_receipt_sha256':c['baseline877']['previous_native_receipt_sha256'],'permit_comment_id':c['permit_comment_id'],'claim_id':c['claim_id'],'private_branch_sha':c['root_verified_route_source']['head_sha'],'permit_ttl_seconds':60,'permit_nonce_encoding':'SHA256 of sorted compact UTF8 expected object excluding permit_comment_id and nonce','api_budget':{'prepare_cap':200,'suffix_cap':682,'margin':100,'per_step_polls':12,'chain_poll_cap':144,'chain_guard_cap':26,'refresh_cap':2},'root_private_checks_required_before_every_write':True});print('FINITE_FEED_PLAN_READY_NO_WRITES')

def step(c,index):
 e.need(c.get('execution_mode')=='WRITE_NEXT123','writes_not_enabled_for_prepare_only')
 root=pathlib.Path(os.environ['RUNNER_TEMP'])/'feed123';state=json.loads((root/'state.json').read_bytes());e.need(index==state['next_index'],'step_replay_or_order_changed');plan=json.loads(e.pin(root/'compiled'/'intent.json',state['plan_sha256']));e.need([g['batch'] for g in plan['groups']]==e.ORDER and len(plan['groups'])==14 and not plan.get('completed_prefix'),'suffix_step_scope_changed');g=plan['groups'][index];execution=json.loads((root/'execution-context.json').read_bytes());e.need(execution['repository']==os.environ['GITHUB_REPOSITORY'] and str(execution['run_id'])==os.environ['GITHUB_RUN_ID'] and execution['head_sha']==os.environ['GITHUB_SHA'] and os.environ['GITHUB_RUN_ATTEMPT']=='1','local_execution_context_changed')
 e.need(state['completed']==e.ORDER[:index] and state['pairs']==877+sum(x['hotels'] for x in plan['groups'][:index]),'state123_progress_changed')
 if index==0:
  e.need(state['previous_native_receipt_sha256']==c['baseline877']['previous_native_receipt_sha256'] and state['expected_feeds']==c['baseline877']['expected_feeds'],'initial_prefix_receipt_or_preimage_changed')
 else:
  previous_group=plan['groups'][index-1];previous_dir=root/'progress'/f"p{previous_group['batch']:02d}";prior_raw=(previous_dir/'native-result.json').read_bytes();prior=json.loads(prior_raw);e.validate_writer(prior,previous_group);verified=json.loads((previous_dir/'verified.json').read_bytes())
  e.need(verified['ok'] is True and verified['batch']==previous_group['batch'] and verified['next_index']==index and verified['native_result_sha256']==e.sha(prior_raw)==state['previous_native_receipt_sha256'],'previous_local_receipt_changed')
  e.need(state['expected_feeds']=={f['name']:f['sha256'] for f in previous_group['files']},'previous_local_preimage_changed')
 if index==0 and not state['first_writer_budget_checked']:
  budget=rate_snapshot();e.save(root/'first-writer-rate-budget.json',{k:budget[k] for k in ('remaining','used','reset')});e.need(budget['remaining']>=FIRST_WRITER_REQUIRED_REMAINING,'insufficient_api_budget_before_first_writer');state['first_writer_budget_checked']=True;e.save(root/'state.json',state)
 try:e.compiler.fresh(state['read_started_at'])
 except ValueError:
  e.need(state['read_refreshes']<MAX_REFRESHES,'bounded_read_refresh_budget_exhausted');state['read_refreshes']+=1;e.save(root/'state.json',state)
  rf=root/f'refresh-{index}';rf.mkdir(mode=0o700);e.exclusive=public_exclusive;e.refresh_read(c,rf,execution)
  for batch in e.ORDER:
   old=(pathlib.Path(state['original_read_root'])/f'p{batch:02d}.stdout.json').read_bytes();new=(rf/'fresh-current123'/f'p{batch:02d}.stdout.json').read_bytes();synthetic_proof=json.dumps({'kind':'root_authenticated_private_route_extension','bound_read_sha256':e.sha(old)}).encode();rebind(synthetic_proof,old,new)
  proof=json.loads((rf/'read-refresh-proof.json').read_bytes());state['read_started_at']=proof['started_at'];state['original_read_root']=str(rf/'fresh-current123')
 _,pre=e.run_probe(c,root,inv=e.compiler.inventory_projection(root/'originals'),execution=execution,serial=f'before-{index}',pairs=state['pairs'],expected=state['expected_feeds'])
 program=e.pin(root/'compiled'/f"p{g['batch']:02d}"/'write.php',g['script_sha256']);expected={'permit_comment_id':c['permit_comment_id'],'private_branch_sha':c['root_verified_route_source']['head_sha'],'repository':execution['repository'],'execution_run_id':execution['run_id'],'execution_head_sha':execution['head_sha'],'run_attempt':1,'batch':g['batch'],'operation':g['operation'],'claim_id':c['claim_id'],'plan_sha256':state['plan_sha256'],'script_sha256':g['script_sha256'],'preimage_sha256':state['expected_feeds'],'previous_native_receipt_sha256':state['previous_native_receipt_sha256'],'private_route_proof_sha256':state['root_projection_sha256']}
 expected['nonce']=e.sha(json.dumps({k:v for k,v in expected.items() if k!='permit_comment_id'},sort_keys=True,separators=(',',':')).encode())
 evidence=root/'progress'/f'p{g["batch"]:02d}';evidence.mkdir(mode=0o700)
 e.save(evidence/'waiting.json',{'expected':expected,'bounded_wait_seconds':MAX_WAIT_SECONDS,'writer_started':False})
 deadline=time.monotonic()+MAX_WAIT_SECONDS;permit=None;polls=0
 while time.monotonic()<deadline and polls<MAX_POLLS_PER_STEP:
  e.need(state['poll_reads_used']<MAX_CHAIN_POLL_READS,'bounded_chain_permit_poll_budget_exhausted');state['poll_reads_used']+=1;polls+=1;e.save(root/'state.json',state)
  comment=e.api('pyatkoff/max-search-bot','issues/comments/'+str(c['permit_comment_id']))
  try:
   gate.admit(comment,expected,datetime.datetime.now(datetime.timezone.utc))
  except (ValueError,KeyError,TypeError,json.JSONDecodeError):
   if polls<MAX_POLLS_PER_STEP and time.monotonic()<deadline:time.sleep(10)
   continue
  # A valid permit is not a reason to disguise runtime/source/public refusal as
  # permit waiting. Stop immediately, preserving its exact safe reason and stage.
  e.need(state['permit_guards_used']<MAX_CHAIN_PERMIT_GUARDS,'bounded_permit_guard_budget_exhausted');state['permit_guards_used']+=1;e.save(root/'state.json',state)
  try:
   public_exclusive(c);e.compiler.fresh(state['read_started_at'])
  except (ValueError,KeyError,TypeError,json.JSONDecodeError) as exc:
   allowed={'runtime_main_changed','carrier_head_changed','claim_changed','competing_public_writer_or_collector','read_timestamp_stale','artifact_run_not_accepted','ambiguous_public_runs','source_pins_missing','pinned_file_changed'}
   code=str(exc) if str(exc) in allowed else 'PUBLIC_ADMISSION_REFUSED'
   e.save(evidence/'admission-refusal.json',{'state':'STOP_BEFORE_NATIVE_WRITE','stage':'public_admission_after_valid_permit','reason':code,'error_class':type(exc).__name__,'writer_started':False})
   raise
  latest=e.api('pyatkoff/max-search-bot','issues/comments/'+str(c['permit_comment_id']))
  try:permit=gate.admit(latest,expected,datetime.datetime.now(datetime.timezone.utc))
  except (ValueError,KeyError,TypeError,json.JSONDecodeError):
   if polls<MAX_POLLS_PER_STEP and time.monotonic()<deadline:time.sleep(10)
   continue
  break
 e.need(permit is not None,'bounded_owner_permit_timeout_no_write')
 # No network check or child work between fresh permit validation and original writer.
 with (evidence/'started.json').open('x') as f:json.dump({'expected':expected,'permit':permit,'no_retry':True},f);f.flush();os.fsync(f.fileno())
 try:
  result=e.ssh_php(program);(evidence/'native-result.json').write_bytes(result.stdout);e.need(result.returncode==0,'original_writer_failed_no_retry');receipt=json.loads(result.stdout);e.validate_writer(receipt,g)
  feeds={f['name']:f['sha256'] for f in g['files']};pairs=state['pairs']+g['hotels'];_,after=e.run_probe(c,root,e.compiler.inventory_projection(root/'originals'),execution,f'after-{index}',pairs,feeds)
  result_sha=e.sha(result.stdout);state.update(next_index=index+1,previous_native_receipt_sha256=result_sha,pairs=pairs,expected_feeds=feeds);state['completed'].append(g['batch']);e.save(root/'state.json',state)
  if index==13:
   e.need(state['pairs']==1000 and state['completed']==e.ORDER,'final_chain_scope_changed');e.save(evidence/'complete.json',{'ok':True,'pairs':1000,'original_batches':state['completed'],'writer_steps':14,'prior_verified_writer_steps':0,'new_posts':0,'provider_calls':0,'three_public_local_feeds_verified':True})
  e.save(evidence/'verified.json',{'ok':True,'batch':g['batch'],'operation':g['operation'],'native_result_sha256':result_sha,'pairs':pairs,'files':g['files'],'after_probe_sha256':e.sha(json.dumps(after,sort_keys=True).encode()),'next_index':index+1,'api_budget_used':{'poll_reads':state['poll_reads_used'],'permit_guards':state['permit_guards_used'],'read_refreshes':state['read_refreshes']},'permit_body_sha256':permit['permit_body_sha256']})
 except Exception:
  e.save(evidence/'STOP_NO_RETRY.json',{'operation':g['operation'],'state':'INSPECT_ORIGINAL_BEFORE_ANY_SUCCESSOR','completed':state['completed']});raise
 print('FINITE_FEED_STEP_VERIFIED batch='+str(g['batch'])+' pairs='+str(pairs))

def main():
 os.umask(0o077);c,raw=config(sys.argv[1]);install_cache();mode=sys.argv[2]
 if mode=='prepare':prepare(c,raw)
 elif mode=='step':step(c,int(sys.argv[3]))
 else:raise ValueError('mode_invalid')
if __name__=='__main__':
 try:main()
 except Exception as ex:
  root=pathlib.Path(os.environ.get('RUNNER_TEMP','/tmp'))/'feed123'
  if root.is_dir():
   failure={'ok':False,'state':'STOP_NO_RETRY','mode':sys.argv[2] if len(sys.argv)>2 else None,'step':sys.argv[3] if len(sys.argv)>3 else None,'error_class':type(ex).__name__}
   if (root/'blocked-guard.json').is_file():failure['guard_diagnostic']=json.loads((root/'blocked-guard.json').read_bytes())
   if (root/'prepare-rate-warmup.json').is_file():failure['prepare_rate_warmup']=json.loads((root/'prepare-rate-warmup.json').read_bytes())
   if (root/'prepare-rate-diagnostic.json').is_file():
    diagnostic=json.loads((root/'prepare-rate-diagnostic.json').read_bytes())
    failure['prepare_rate_budget']={'stage':'AFTER_COMPILATION_BEFORE_PREPARE_BUDGET_GATE','before':{k:diagnostic['before'][k] for k in ('used','reset','remaining') if type(diagnostic.get('before',{}).get(k)) is int},'after':{k:diagnostic['after'][k] for k in ('used','reset','remaining') if type(diagnostic.get('after',{}).get(k)) is int},'prepare_cap':PREPARE_GET_CAP}
   if (root/'first-writer-rate-budget.json').is_file():
    budget=json.loads((root/'first-writer-rate-budget.json').read_bytes());failure['first_writer_rate_budget']={k:budget[k] for k in ('remaining','used','reset') if type(budget.get(k)) is int}
   e.save(root/'failure.json',failure)
  code=str(ex) if isinstance(ex,ValueError) and re.fullmatch('[a-z0-9_]{1,100}',str(ex)) else type(ex).__name__;print('FINITE_FEED_STOP '+code);sys.exit(2)
