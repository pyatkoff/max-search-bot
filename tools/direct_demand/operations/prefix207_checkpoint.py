"""Offline exact failed-run checkpoint verifier. No fetching, extraction or writes.
All admission CLOSED until independent descriptor pin review. Never accepts current runtime.
"""
import hashlib,json,io,zipfile,datetime,re,pathlib
REVIEWED_CHECKPOINT_SHA256='a9b483bb76b7d07d1ad727ea12c9c5d7749b6030329f6a381ee14e3905eaca4b'
RUN=44163849869
HEAD='216df1858b16c193a2382f0f294b3b6599c82df7'
PLAN_SHA='25aa74e29b4aeee0b6609f69c9d3d0d882efd44782460512e08615b8a351acc2'
RECEIPT_SHA='2dcf4f7d9698934cf7df022606c42b4d80f42778318fb7db17e719b1de226a12'
ORDER=[1,2,3,4,5,6,8,9,10,11,12,13,14,15,18,17,19,20,21,22,23,24,25,26]
HASHES={'hotel-posts-telegram.xml':'dd0ca832b3e8721c19b990e2acc2ddaf55361c07f72c5584d7cbae18b90266c7','hotel-posts-max.xml':'2230ff13613de02f7c4fe637bc3442dc4da4eef49826c16904835860ad8d1335','hotel-posts-all.xml':'397e4fef3a4004beaff124bbebcf9860144a2163c076b514b7522b023d0327ec'}
ARTIFACT_IDS={'plan':14363828412,'p01':14363953318,'p02':14365042137}
MEMBERS={'plan':{'plan.json'},'p01':{'progress/p01/'+n for n in ['started.json','native-result.json','verified.json','waiting.json']},'p02':{'failure.json','progress/p02/waiting.json'}}
sha=lambda b:hashlib.sha256(b).hexdigest()
canonical=lambda x:json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()
def need(x,m):
 if not x:raise ValueError(m)
def same(x,y,m):need(canonical(x)==canonical(y),m)
def select(x,keys):return {k:x[k] for k in keys}
def metadata_projection(meta):
 run=select(meta['run'],['id','head_sha','run_attempt','status','conclusion','event'])
 jobs=meta['jobs']['jobs'];need(len(jobs)==1,'job_scope');j=jobs[0]
 job=select(j,['id','name','run_id','status','conclusion']);job['steps']=[select(x,['number','name','status','conclusion']) for x in j['steps']]
 artifacts={}
 allart=meta['artifacts'];allart=allart['artifacts'] if 'artifacts' in allart else list(allart.values())
 for key,id in ARTIFACT_IDS.items():
  matches=[x for x in allart if x['id']==id];need(len(matches)==1,'artifact_scope:'+key);a=matches[0]
  artifacts[key]=select(a,['id','name','expired','digest']);artifacts[key]['workflow_run']=select(a['workflow_run'],['id','head_sha','repository_id','head_repository_id'])
 return {'run':run,'job':job,'artifacts':artifacts}
def authenticate_checkpoint(descriptor_raw,api_metadata,archives):
 need(isinstance(descriptor_raw,bytes) and REVIEWED_CHECKPOINT_SHA256 is not None and sha(descriptor_raw)==REVIEWED_CHECKPOINT_SHA256,'closed_checkpoint_gate')
 d=json.loads(descriptor_raw);need(set(d)=={'kind','metadata','archives','prefix_hotel_ids'},'checkpoint_descriptor_scope')
 need(d['kind']=='EXACT_FAILED44163849869_VERIFIED_PREFIX1_ONLY','checkpoint_kind')
 m=metadata_projection(api_metadata);same(m,d['metadata'],'checkpoint_metadata_changed')
 same(m['run'],{'id':RUN,'head_sha':HEAD,'run_attempt':1,'status':'completed','conclusion':'failure','event':'push'},'historical_run_scope')
 j=m['job'];need(j['id']==134324517775 and j['run_id']==RUN and j['name']=='inspect' and j['status']=='completed' and j['conclusion']=='failure','historical_job_scope')
 steps=j['steps'];need(len({x['name'] for x in steps})==len(steps),'ambiguous_steps')
 byname={x['name']:x for x in steps}
 for batch in ORDER:
  name=f'One native feed write for original batch {batch}';s=byname[name];need(s['status']=='completed' and s['conclusion']==('success' if batch==1 else 'failure' if batch==2 else 'skipped'),'historical_writer_step:'+str(batch))
 for name in ['Prepare sanitized root-coordinated finite plan (no writes)','Publish sanitized plan artifact while job is running','Publish bounded progress for batch 1 while job is running','Publish bounded progress for batch 2 while job is running']:
  need(byname[name]['conclusion']=='success','historical_evidence_step')
 need(set(archives)==set(d['archives'])==set(ARTIFACT_IDS),'archive_scope')
 blobs={}
 for key,raw in archives.items():
  a=m['artifacts'][key];entry=d['archives'][key];need(a['expired'] is False and a['workflow_run']=={'id':RUN,'head_sha':HEAD,'repository_id':1342493152,'head_repository_id':1342493152},'artifact_identity')
  need(sha(raw)==entry['sha256'] and a['digest']=='sha256:'+sha(raw),'archive_digest')
  need(len(raw)<10000000,'archive_too_large')
  with zipfile.ZipFile(io.BytesIO(raw)) as z:
   need(len(z.namelist())==len(set(z.namelist())) and set(z.namelist())==MEMBERS[key]==set(entry['members']),'archive_member_scope')
   for n in z.namelist():
    info=z.getinfo(n);need(not info.is_dir() and info.file_size<2000000 and ((info.external_attr>>16)&0o170000)!=0o120000,'archive_member_type')
    b=z.read(n);need(sha(b)==entry['members'][n],'archive_member_hash');blobs[n]=b
 plan=json.loads(blobs['plan.json']);same([g['batch'] for g in plan['groups']],ORDER,'plan_order')
 need(plan['plan_sha256']==PLAN_SHA and len(plan['groups'])==24 and sum(g['hotels'] for g in plan['groups'])==217,'historical_plan')
 need(plan['execution']['run_id']==RUN and plan['execution']['head_sha']==HEAD and plan['execution']['run_attempt']==1,'plan_execution')
 v=json.loads(blobs['progress/p01/verified.json']);start=json.loads(blobs['progress/p01/started.json']);waiting=json.loads(blobs['progress/p01/waiting.json']);receipt=json.loads(blobs['progress/p01/native-result.json']);nextwait=json.loads(blobs['progress/p02/waiting.json']);failure=json.loads(blobs['failure.json']);g=plan['groups'][0]
 need(sha(blobs['progress/p01/native-result.json'])==RECEIPT_SHA==v['native_result_sha256'],'native_receipt_hash')
 need(v['ok'] is True and v['batch']==1 and v['pairs']==670 and v['next_index']==1 and g['hotels']==10,'prefix_scope')
 same(v['files'],g['files'],'prefix_plan_files');need(g['operation']==v['operation']==receipt['operation']==start['expected']['operation'],'prefix_operation')
 same({f['name']:f['sha256'] for f in v['files']},HASHES,'prefix_postimage_hashes')
 need(receipt['ok'] is True and all(type(receipt[k]) is int and receipt[k]==0 for k in ['db_writes','provider_calls','message_sends']),'native_boundary')
 for f in receipt['files']:
  match=next(x for x in g['files'] if x['name']==f['name']);same(select(f,['name','sha256','bytes','rows']),select(match,['name','sha256','bytes','rows']),'native_plan_file')
 need(len(receipt['files'])==3,'native_file_scope')
 same(start['expected'],waiting['expected'],'started_waiting_binding');need(start['no_retry'] is True,'started_no_retry')
 for x,batch in [(start['expected'],1),(nextwait['expected'],2)]:
  need(x['execution_run_id']==RUN and x['execution_head_sha']==HEAD and x['run_attempt']==1 and x['plan_sha256']==PLAN_SHA and x['batch']==batch,'step_execution')
  gp=plan['groups'][batch-1];need(x['operation']==gp['operation'] and x['script_sha256']==gp['script_sha256'],'step_script')
 need(start['expected']['previous_native_receipt_sha256'] is None and nextwait['expected']['previous_native_receipt_sha256']==RECEIPT_SHA,'receipt_chain')
 same(nextwait['expected']['preimage_sha256'],HASHES,'next_preimage')
 need(nextwait['writer_started'] is False and failure['state']=='STOP_NO_RETRY' and failure['mode']=='step' and failure['step']=='1' and failure['ok'] is False,'second_step_not_started')
 ids=d['prefix_hotel_ids'];need(len(ids)==10 and len(set(ids))==10 and all(type(i) is int and i>0 for i in ids),'prefix_ids')
 return {'kind':'AUTHENTICATED_EXACT_PREFIX1_NOT_CURRENT_ADMISSION','descriptor_sha256':sha(descriptor_raw),'historical_run_id':RUN,'historical_head_sha':HEAD,'historical_plan_sha256':PLAN_SHA,'completed_prefix':[1],'prefix_hotel_ids':ids,'previous_pairs':670,'remaining_hotels':207,'suffix_batches':ORDER[1:],'previous_native_receipt_sha256':RECEIPT_SHA,'expected_feeds':dict(HASHES),'historical_after_probe_sha256':v['after_probe_sha256'],'historical_probe_bytes_available':False}
def validate_fresh_preimage(prefix,probe,preimages,execution):
 need(prefix['kind']=='AUTHENTICATED_EXACT_PREFIX1_NOT_CURRENT_ADMISSION','prefix_not_authenticated')
 need(execution['repository']=='pyatkoff/max-search-bot' and type(execution['run_id']) is int and execution['run_id']!=RUN and execution['run_attempt']==1,'new_execution_required')
 need(probe['ok'] is True and probe['state']=='READONLY_PREIMAGE_PROBE_COMPLETE_NOT_EXECUTION_ADMISSION' and probe['tracked_tree_clean'] is True and probe['local_public_verified'] is True,'fresh_probe_required')
 need(probe['installed_hook_sha256']=='5bd038a50c756b951f9bbc29055b9bf755469066fb9e2719083cd544271abd7e','hook_changed')
 need(all(type(probe[k]) is int and probe[k]==0 for k in ['production_writes','provider_calls','message_sends','writer_runs']),'probe_boundary')
 for k in ['monitor_before','monitor_after']:need(probe[k]['pending_null'] is True and probe[k]['global_ex_nb_available'] is True and probe[k]['lock_released'] is True,'monitor_not_idle')
 stamp=datetime.datetime.fromisoformat(probe['observed_at'].replace('Z','+00:00'));need(stamp.tzinfo is not None and 0<=(datetime.datetime.now(datetime.timezone.utc)-stamp).total_seconds()<=900,'probe_stale')
 need(len(probe['files'])==3 and {x['name'] for x in probe['files']}==set(HASHES),'probe_files')
 for f in probe['files']:
  need(f['public_local_identical'] is True and f['sha256']==HASHES[f['name']] and f['offers']==(1340 if f['name']=='hotel-posts-all.xml' else 670),'prefix_current_feed_changed')
  raw=(pathlib.Path(preimages)/f['name']).read_bytes();need(sha(raw)==f['sha256'] and len(raw)==f['bytes'],'prefix_preimage_bytes')
