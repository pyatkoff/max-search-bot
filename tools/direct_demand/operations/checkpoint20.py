"""Exact local checkpoint20 verifier. No network, extraction or native writes.
Original prefix10 and PRICE evolution remain immutable nested evidence.
"""
import hashlib,json,io,zipfile,pathlib,datetime,re
REVIEWED_CHECKPOINT20_SHA256='7190b7f83853574f27319f130dda5dba3cf5e2f54d3433a72b201e96bdc267c3'
RUN=44222070996
HEAD='ac591a90ff4362656e922b0371b27eaa565e4281'
PLAN_SHA='24f3d1ad0816958c1766c91039090c3464aab1fc8110c2a8c7b3d2a4c099198f'
RECEIPT_SHA='5da6d64f89008743b0d5d5572d9e893aca4ef9737e8609711f8760f3b13fd40e'
OLD_RECEIPT_SHA='2dcf4f7d9698934cf7df022606c42b4d80f42778318fb7db17e719b1de226a12'
ORDER=[1,2,3,4,5,6,8,9,10,11,12,13,14,15,18,17,19,20,21,22,23,24,25,26]
ARTIFACT_IDS={'plan':14391785488,'p02':14391980188,'p03':14390554217}
MEMBERS={'plan':{'plan.json'},'p02':{'progress/p02/'+n for n in ['started.json','native-result.json','verified.json','waiting.json']},'p03':{'failure.json','progress/p03/waiting.json'}}
EXPECTED_FEEDS={'hotel-posts-telegram.xml':'a1df3930c9adcfbb986ab9e8d057364f459b83a81e0bad3bbf0faf5ec627f4b5','hotel-posts-max.xml':'38f270726d34bc90b3ec7a9c45cc4b6979b536a78e32d9dcf8ef45de78d71699','hotel-posts-all.xml':'a914b2976507de4d98df96dd0ffb15451ed9276be9aaf57e9c265b6e80d06b2e'}
IDS=[12120,64957,76029,66198,49750,123247,48184,32322,28272,7458,2490,2458,113544,73477,2588,2611,2612,2610,2931,78456]
HOOK='1ad41a8a3a8d10632e0e6127289481675f655c7f7f9a5bbe39b698c03e73e592'
RUNTIME='cc017ee5bb669f4c7c13efc91d5c5812a50e55f2'
sha=lambda b:hashlib.sha256(b).hexdigest()
canonical=lambda x:json.dumps(x,sort_keys=True,ensure_ascii=False,separators=(',',':')).encode()
def need(v,m):
 if not v:raise ValueError(m)
def same(a,b,m):need(canonical(a)==canonical(b),m)
def select(x,keys):return {k:x[k] for k in keys}
def metadata_projection(api):
 run=select(api['run'],['id','head_sha','run_attempt','status','conclusion','event'])
 js=api['jobs'];need(js['total_count']==len(js['jobs'])==1,'jobs_scope');j=js['jobs'][0]
 job=select(j,['id','name','run_id','status','conclusion']);job['steps']=[select(x,['number','name','status','conclusion']) for x in j['steps']]
 artifacts={}
 for key,id in ARTIFACT_IDS.items():
  a=api['artifacts'][key];need(a['id']==id,'artifact_id');artifacts[key]=select(a,['id','name','expired','digest']);artifacts[key]['workflow_run']=select(a['workflow_run'],['id','head_sha','repository_id','head_repository_id'])
 return {'run':run,'job':job,'artifacts':artifacts}
def authenticate_checkpoint20(descriptor_raw,api_metadata,archives):
 need(type(descriptor_raw) is bytes and REVIEWED_CHECKPOINT20_SHA256 is not None and sha(descriptor_raw)==REVIEWED_CHECKPOINT20_SHA256,'closed_checkpoint20_gate')
 d=json.loads(descriptor_raw);need(set(d)=={'kind','metadata','archives','completed_hotel_ids','inventory_sha256','historical_prefix10','price_evolution'},'descriptor_scope')
 need(d['kind']=='EXACT_FAILED44222070996_VERIFIED_PREFIX20_ONLY','descriptor_kind')
 same(d['completed_hotel_ids'],IDS,'inventory_ids');need(d['inventory_sha256']=='c8cee3897d32ae513b537ba55ce4d8fe5d850017789b60fea53eca8a9f9ce565','inventory_hash')
 m=metadata_projection(api_metadata);same(m,d['metadata'],'metadata_changed');same(m['run'],{'id':RUN,'head_sha':HEAD,'run_attempt':1,'status':'completed','conclusion':'failure','event':'push'},'run_scope')
 j=m['job'];need(j['id']==134526113618 and j['name']=='inspect' and j['run_id']==RUN and j['status']=='completed' and j['conclusion']=='failure','job_scope')
 steps=j['steps'];need(len({x['name'] for x in steps})==len(steps),'ambiguous_steps');by={x['name']:x for x in steps}
 need('One native feed write for original batch 1' not in by,'replayed_prefix1')
 for b in ORDER[1:]:
  x=by[f'One native feed write for original batch {b}'];need(x['status']=='completed' and x['conclusion']==('success' if b==2 else 'failure' if b==3 else 'skipped'),'writer_outcome')
 for n in ['Prepare sanitized root-coordinated finite plan (no writes)','Publish sanitized plan artifact while job is running','Publish bounded progress for batch 2 while job is running','Publish bounded progress for batch 3 while job is running']:
  need(by[n]['conclusion']=='success','evidence_step')
 need(set(archives)==set(d['archives'])==set(ARTIFACT_IDS),'archive_scope');blobs={}
 for key,raw in archives.items():
  a=m['artifacts'][key];entry=d['archives'][key];need(a['expired'] is False,'artifact_expired');same(a['workflow_run'],{'id':RUN,'head_sha':HEAD,'repository_id':1342493152,'head_repository_id':1342493152},'artifact_run')
  need(sha(raw)==entry['sha256'] and a['digest']=='sha256:'+sha(raw) and len(raw)<10000000,'archive_digest')
  with zipfile.ZipFile(io.BytesIO(raw)) as z:
   names=z.namelist();need(len(names)==len(set(names)) and set(names)==MEMBERS[key]==set(entry['members']),'archive_members')
   for n in names:
    info=z.getinfo(n);need(not info.is_dir() and info.file_size<2000000 and ((info.external_attr>>16)&0o170000)!=0o120000,'archive_member_type');b=z.read(n);need(sha(b)==entry['members'][n],'member_digest');blobs[n]=b
 plan=json.loads(blobs['plan.json']);need(plan['plan_sha256']==PLAN_SHA and plan['execution_mode']=='WRITE_SUFFIX','plan_scope');same(plan['execution'],{'repository':'pyatkoff/max-search-bot','run_id':RUN,'run_attempt':1,'head_sha':HEAD},'plan_execution')
 same([g['batch'] for g in plan['groups']],ORDER[1:],'plan_order');need(len(plan['groups'])==23 and sum(g['hotels'] for g in plan['groups'])==207 and plan['initial_pairs']==670,'plan_counts')
 same(plan['resume_prefix'],d['historical_prefix10'],'original_prefix_changed');same(plan['price_evolution'],d['price_evolution'],'price_evolution_changed');same(plan['initial_preimages'],d['price_evolution']['current_feeds'],'initial_price_preimage')
 need(plan['resume_prefix']['descriptor_sha256']=='a9b483bb76b7d07d1ad727ea12c9c5d7749b6030329f6a381ee14e3905eaca4b' and plan['price_evolution']['descriptor_sha256']=='c66c2fbde42e118b05fb9edd8efd898644c8a9e4572d17a4d8268e72e9d7f466','historical_descriptor_identity')
 need(plan['resume_prefix']['previous_native_receipt_sha256']==OLD_RECEIPT_SHA and plan['resume_prefix']['prefix_hotel_ids']==IDS[:10],'prefix10_identity')
 g=plan['groups'][0];v=json.loads(blobs['progress/p02/verified.json']);start=json.loads(blobs['progress/p02/started.json']);wait=json.loads(blobs['progress/p02/waiting.json']);receipt=json.loads(blobs['progress/p02/native-result.json']);nxt=json.loads(blobs['progress/p03/waiting.json']);failure=json.loads(blobs['failure.json'])
 need(sha(blobs['progress/p02/native-result.json'])==RECEIPT_SHA==v['native_result_sha256'],'receipt_hash');need(v['ok'] is True and v['batch']==2 and v['pairs']==680 and v['next_index']==1 and g['hotels']==10,'completed_batch_scope')
 same(v['files'],g['files'],'verified_plan_files');same({x['name']:x['sha256'] for x in g['files']},EXPECTED_FEEDS,'postimages');same({x['name']:x['expected_previous_sha256'] for x in g['files']},plan['initial_preimages'],'price_to_batch2_chain')
 need(receipt['ok'] is True and receipt['mode']=='publish_three_static_public_direct_yml_feeds' and all(type(receipt[k]) is int and receipt[k]==0 for k in ['db_writes','provider_calls','message_sends']),'native_boundary')
 need(len(receipt['files'])==3 and len({x['name'] for x in receipt['files']})==3,'native_files')
 for f in receipt['files']:
  want=next(x for x in g['files'] if x['name']==f['name']);same(select(f,['name','sha256','bytes','rows']),select(want,['name','sha256','bytes','rows']),'native_files');need(f['write_action']=='replace','write_action')
 same(start['expected'],wait['expected'],'started_waiting');need(start['no_retry'] is True,'no_retry');need(g['operation']==v['operation']==receipt['operation'],'operation')
 for x,b,index in [(start['expected'],2,0),(nxt['expected'],3,1)]:
  gp=plan['groups'][index];need(x['execution_run_id']==RUN and x['execution_head_sha']==HEAD and x['run_attempt']==1 and x['plan_sha256']==PLAN_SHA and x['batch']==b and x['operation']==gp['operation'] and x['script_sha256']==gp['script_sha256'],'step_identity')
  need(x['private_branch_sha']==plan['private_branch_sha']=='bbef3101f8c8f6eae15355c0d6fa4b8c1848dfa2' and x['private_route_proof_sha256']==plan['root_projection_sha256']=='1f9677f64fc7b89ac9a0f7d22cdeb93a67b388421b815da7d4701afe1588f87b','route_identity')
  need(x['repository']=='pyatkoff/max-search-bot' and x['permit_comment_id']==6957283506 and x['claim_id']==6955394658,'step_coordination');need(x['nonce']==sha(canonical({k:v for k,v in x.items() if k not in ['permit_comment_id','nonce']})),'nonce')
 same(start['expected']['preimage_sha256'],plan['initial_preimages'],'started_preimage');need(start['expected']['previous_native_receipt_sha256']==OLD_RECEIPT_SHA and nxt['expected']['previous_native_receipt_sha256']==RECEIPT_SHA,'receipt_chain');same(nxt['expected']['preimage_sha256'],EXPECTED_FEEDS,'next_preimages')
 need(nxt['writer_started'] is False and failure['ok'] is False and failure['state']=='STOP_NO_RETRY' and failure['mode']=='step' and failure['step']=='1','batch3_not_started')
 return {'kind':'AUTHENTICATED_EXACT_PREFIX20_NOT_CURRENT_ADMISSION','descriptor_sha256':sha(descriptor_raw),'historical_run_id':RUN,'historical_head_sha':HEAD,'historical_plan_sha256':PLAN_SHA,'completed_prefix':[1,2],'previous_pairs':680,'remaining_hotels':197,'suffix_batches':ORDER[2:],'previous_native_receipt_sha256':RECEIPT_SHA,'expected_feeds':dict(EXPECTED_FEEDS),'historical_prefix10':d['historical_prefix10'],'price_evolution':d['price_evolution'],'completed_hotel_ids':list(IDS),'historical_after_probe_sha256':v['after_probe_sha256'],'historical_probe_bytes_available':False}
def validate_fresh_preimage20(continuation,probe,preimages,execution):
 need(continuation['kind']=='AUTHENTICATED_EXACT_PREFIX20_NOT_CURRENT_ADMISSION' and REVIEWED_CHECKPOINT20_SHA256 is not None and continuation['descriptor_sha256']==REVIEWED_CHECKPOINT20_SHA256,'checkpoint20_not_authenticated')
 same(continuation['expected_feeds'],EXPECTED_FEEDS,'checkpoint20_hashes');same(continuation['completed_hotel_ids'],IDS,'checkpoint20_ids');same(continuation['completed_prefix'],[1,2],'checkpoint20_scope');need(continuation['previous_pairs']==680 and continuation['remaining_hotels']==197 and continuation['previous_native_receipt_sha256']==RECEIPT_SHA,'checkpoint20_counts')
 need(execution['repository']=='pyatkoff/max-search-bot' and type(execution['run_id']) is int and execution['run_id'] not in [RUN,44163849869] and execution['run_attempt']==1,'new_execution_required')
 need(probe['ok'] is True and probe['state']=='READONLY_PREIMAGE_PROBE_COMPLETE_NOT_EXECUTION_ADMISSION' and probe['tracked_tree_clean'] is True and probe['local_public_verified'] is True,'fresh_probe_required')
 need(probe['installed_hook_sha256']==HOOK and probe['installed_sha']==RUNTIME,'hook_runtime_changed');need(all(type(probe[k]) is int and probe[k]==0 for k in ['production_writes','provider_calls','message_sends','writer_runs']),'probe_boundary')
 for k in ['monitor_before','monitor_after']:need(probe[k]['pending_null'] is True and probe[k]['global_ex_nb_available'] is True and probe[k]['lock_released'] is True,'monitor_not_idle')
 dt=datetime.datetime.fromisoformat(probe['observed_at'].replace('Z','+00:00'));need(dt.tzinfo is not None and 0<=(datetime.datetime.now(datetime.timezone.utc)-dt).total_seconds()<=900,'probe_stale')
 need(len(probe['files'])==3 and {x['name'] for x in probe['files']}==set(EXPECTED_FEEDS),'probe_files')
 for f in probe['files']:
  need(f['public_local_identical'] is True and f['sha256']==EXPECTED_FEEDS[f['name']] and f['offers']==(1360 if f['name']=='hotel-posts-all.xml' else 680),'current_preimage_changed');raw=(pathlib.Path(preimages)/f['name']).read_bytes();need(sha(raw)==f['sha256'] and len(raw)==f['bytes'],'current_preimage_bytes')
 return dict(EXPECTED_FEEDS)
