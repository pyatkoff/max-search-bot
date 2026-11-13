"""Artifact-only admission for the single qualified update10 continuation.

All terminal inputs must come from the actual original successful recovery. The
default generated draft remains disabled; this verifier performs no runtime write.
"""
import datetime,hashlib,io,json,pathlib,re,types,zipfile

OPERATION='commercial-channel-hotel-feeds-20261113-update10'
CONTINUATION='commercial-channel-hotel-feeds-20261113-update10-continuation1'
PUBLICATION_SOURCE='498c5a752cd6f99c2d0e8c343dc5d44b9e80843f'
PENDING_OPERATION='commercial-monitor-price-h112914-d7617e5a491183b8f8e73d26a8b419b6147d6a8323b84799d0b6057819f93e70'
RECOVERY_OPERATION='commercial-monitor-price-h112914-recovery-20261113-1'
RECOVERY_CONTRACT_SHA='de4c15c2a3d95b94a0c25367fee5a3a13edd72c8990fb8f8b5a102a2151c4acf'
CORE_HEAD='2a1f13ed4f766f5d1371c6275e3d4f69bac0e558'
CORE_MERGE='77dfb182148d9df9903e8e21fb8f29e986d4fd5f'
PIN_MAP_SHA='3232defb6fa18ac047f8bbe222cff84a2743224cf243d3fbd91a654b95f0646a'
RECOVERY_MEMBERS=('recovery-plan.json','recovery-evidence-contract.py','result.json','transport.json',
 'recovery.started','summary.json','current-source-acceptance.json','source-gate-receipts.json',
 'accepted-core-pr.json','readiness2-original.zip','readiness2-run.json','readiness2-artifact.json')

def need(ok,reason):
 if not ok:raise ValueError(reason)

def sha(raw):return hashlib.sha256(raw).hexdigest()
def canonical(value):return json.dumps(value,ensure_ascii=False,separators=(',',':')).encode()

def bound_archive(binding,run,meta,raw):
 need(run['id']==binding['run_id'] and run['run_attempt']==1 and run['head_sha']==binding['head_sha'],'original_run_binding_changed')
 need(run['head_branch']=='audit/content100-bot-publication-20261101' and run['path']=='.github/workflows/content100-bot-publication-20261101.yml','original_carrier_changed')
 need(run['status']=='completed' and run['conclusion']==binding['conclusion'],'original_terminal_changed')
 a=binding['artifact'];need(meta['id']==a['id'] and meta['name']==a['name'] and meta['expired'] is False,'original_artifact_changed')
 need(meta['workflow_run']['id']==run['id'] and meta['workflow_run']['head_sha']==run['head_sha'],'original_artifact_run_changed')
 need(meta['digest']=='sha256:'+a['sha256'] and sha(raw)==a['sha256'],'original_archive_digest_changed')
 files={}
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  need(len(z.infolist())<=80 and sum(i.file_size for i in z.infolist())<20000000,'original_archive_scope')
  for name,h in binding['files'].items():
   matches=[i for i in z.infolist() if not i.is_dir() and pathlib.PurePosixPath(i.filename).name==name]
   need(len(matches)==1 and matches[0].file_size<4000000,'original_file_not_unique')
   member=pathlib.PurePosixPath(matches[0].filename);need(not member.is_absolute() and '..' not in member.parts,'original_member_path_invalid')
   value=z.read(matches[0]);need(sha(value)==h,'original_file_digest_changed');files[name]=value
 return files

def terminal(raw):return json.loads(raw.splitlines()[-1])

def verify_historical(bundles):
 failed=bundles['original_feed'];r=terminal(failed['writer-receipt.json']);marker=json.loads(failed['feed-writer.started']);wrapper=json.loads(failed['wrapper-proof.json'])
 need(r['operation']==OPERATION and r['ok'] is False and r['error']=='monitor_already_running' and r['files']==[],'original_not_prewrite_guard_refusal')
 need(all(r[k]==0 for k in ['db_writes','provider_calls','message_sends']),'original_write_boundary_changed')
 need(marker['operation']==OPERATION and str(marker['run_id'])=='43340115672' and marker['writer_attempts']==1 and marker['retry_authorized'] is False,'original_marker_changed')
 need(marker['input_sha256']=='ebdbd6b7310d46ff7bb61300ab16bdf620b3bdc50b4cfaae9118a1000b4fa83d','original_intent_changed')
 need(wrapper['nightly_guard_sha256']=='0c60e5a851ae014de9a1542f268449b7a1d0fb0ba9f3104db8da650035ea8486' and wrapper['frozen_writer_body_preserved'] is True and wrapper['journal_write_attempts']==0,'original_wrapper_changed')
 one=terminal(bundles['readiness1']['result.json']);two=terminal(bundles['readiness2']['result.json'])
 for suffix,d in [('1',one),('2',two)]:
  need(d['operation']==OPERATION+'-readiness'+suffix and d['ok'] is True and d['global_pending_is_null'] is False,'original_readiness_changed')
  need(d['pending']['operation']==PENDING_OPERATION and d['pending']['tourvisor_hotel_id']==112914,'original_pending_identity_changed')
  need(d['feed_continuation_authorized'] is False and all(d[k]==0 for k in ['db_write_attempts','journal_write_attempts','post_write_attempts','configuration_write_attempts','provider_write_attempts','feed_write_attempts']),'original_readiness_write_boundary')
  need(d['child_journal']['history_lines_scanned']==d['child_journal']['lines']==4 and d['child_journal']['historical_post_keys']==['max:anjum'] and d['child_journal']['telegram_intent_seen'] is False,'original_child_history_changed')
 n=one['native_max'];need(n['attempted'] is False and n['provider_gets']==0 and n['error']=='native_original_journal_not_incomplete','original_readiness1_get0_changed')
 n=two['native_max'];need(n['attempted'] is True and n['ok'] is True and n['native_confirmed'] is True and n['provider_gets']==3,'original_readiness2_native_changed')
 need(n['transport_state']=='sent' and n['transport_reason']=='already_present' and n['publication_id']==184 and n['version_id']==4323,'original_native_binding_changed')
 need(n['registry_state']=='requires_attention' and n['registry_write_attempts']==0 and n['registry_claim_present'] is False,'original_registry_state_changed')
 need(all(n[k] is True for k in ['text_matches','photo_matches','url_matches','only_expected_button','registry_unchanged_during_read']),'original_native_content_changed')
 need(two['journal_cas_verified'] is True and two['original_readiness_result_sha256']==sha(bundles['readiness1']['result.json']),'original_readiness_chain_changed')
 need(bundles['readiness2']['previous-readiness-result.json']==bundles['readiness1']['result.json'],'original_readiness1_bytes_changed')
 for kind in ['global_journal','child_journal']:
  need(all(one[kind][k]==two[kind][k] for k in ['sha256','last_line_sha256','bytes','lines']),'original_journal_pin_changed')
 return {'verified':True,'original_guard_refusal_zero_writes':True,'original_frozen_writer_invoked':False,'original_readiness1_gets':0,'original_readiness2_gets':3,'original_pending_closed':False}

def require_recovery_binding(plan):
 b=plan.get('recovery_terminal')
 need(isinstance(b,dict) and type(b.get('run_id')) is int and b['run_id']>0
      and b.get('run_attempt')==1 and b.get('conclusion')=='success'
      and isinstance(b.get('head_sha'),str) and re.fullmatch('[a-f0-9]{40}',b['head_sha']),
      'actual_recovery_terminal_required')
 artifact=b.get('artifact');files=b.get('files')
 need(isinstance(artifact,dict) and type(artifact.get('id')) is int and artifact['id']>0
      and artifact.get('name')==RECOVERY_OPERATION+'-result'
      and isinstance(artifact.get('sha256'),str) and re.fullmatch('[a-f0-9]{64}',artifact['sha256'])
      and isinstance(files,dict) and set(RECOVERY_MEMBERS)<=set(files) and len(files)<=80,
      'actual_recovery_artifact_and_members_required')
 for name,digest in files.items():
  need(pathlib.PurePosixPath(name).name==name and isinstance(digest,str) and re.fullmatch('[a-f0-9]{64}',digest),
       'actual_recovery_member_pin_invalid')
 need(files['recovery-evidence-contract.py']==RECOVERY_CONTRACT_SHA,'reviewed_recovery_predicate_changed')
 a=plan.get('current_source_acceptance')
 need(isinstance(a,dict) and a.get('installed_sha')==plan.get('current_installed_sha')
      and a.get('core_head_sha')==CORE_HEAD and a.get('core_merge_sha')==CORE_MERGE
      and a.get('runtime_pins_sha256')==PIN_MAP_SHA,'accepted_current_source_required')
 return b

def verify_recovery_bundle(plan,run,meta,raw):
 """Pure byte authentication and canonical closure predicates; no API or writes."""
 b=require_recovery_binding(plan)
 need(run.get('event')=='push' and run.get('actor',{}).get('login')=='pyatkoff'
      and run.get('triggering_actor',{}).get('login')=='pyatkoff','original_recovery_actor_changed')
 files=bound_archive(b,run,meta,raw)
 need(sha(files['recovery-evidence-contract.py'])==RECOVERY_CONTRACT_SHA,'reviewed_recovery_predicate_changed')
 verifier=types.ModuleType('qualified_original_recovery_predicate')
 verifier.__file__='recovery-evidence-contract.py'
 exec(compile(files['recovery-evidence-contract.py'],verifier.__file__,'exec'),verifier.__dict__)
 recovered,result_summary,transport,marker=(json.loads(files[name]) for name in
     ('recovery-plan.json','summary.json','transport.json','recovery.started'))
 result=terminal(files['result.json'])
 need(sha(files['result.json'])==transport.get('stdout_sha256') and len(files['result.json'])==transport.get('stdout_bytes')
      and sha(files['recovery.started'])==transport.get('started_sha256')
      and str(transport.get('run_id'))==str(run['id']) and transport.get('head_sha')==run['head_sha'],
      'actual_recovery_transport_binding_changed')
 need(sha(canonical(recovered['current_runtime_pins']))==PIN_MAP_SHA,'reviewed_runtime22_changed')
 acceptance=verifier.require_admission(recovered)
 need(acceptance==json.loads(files['current-source-acceptance.json'])==plan['current_source_acceptance']
      and acceptance['source_gates']==json.loads(files['source-gate-receipts.json']),
      'accepted_current_source_lineage_changed')
 core=json.loads(files['accepted-core-pr.json'])
 need(core.get('number')==1522 and core.get('merged') is True and core['head']['sha']==CORE_HEAD
      and core.get('merge_commit_sha')==CORE_MERGE and core['base']['ref']=='main',
      'actual_accepted_core_pull_request_changed')
 verifier.verify_readiness(recovered,json.loads(files['readiness2-run.json']),
                           json.loads(files['readiness2-artifact.json']),files['readiness2-original.zip'])
 expected=verifier.verify_success(recovered,result,transport,marker)
 expected.update({'result_sha256':sha(files['result.json']),'transport_sha256':sha(files['transport.json']),
                  'started_sha256':sha(files['recovery.started']),'plan_sha256':sha(files['recovery-plan.json']),
                  'compiled_sha256':marker['compiled_sha256'],'evidence_contract_sha256':RECOVERY_CONTRACT_SHA})
 need(result_summary==expected,'actual_recovery_summary_changed')
 dated=lambda value:datetime.datetime.fromisoformat(value.replace('Z','+00:00'))
 need(dated(result['completed_at_utc'])<=dated(run['updated_at']),'recovery_completion_order_changed')
 return files,expected

def verify_future_recovery(plan,api,evidence):
 # Readiness2 proves native presence only. This requires the later original
 # recovery's canonical registry, child-pair and global pending closure.
 b=require_recovery_binding(plan)
 run_raw=api('actions/runs/'+str(b['run_id']));meta_raw=api('actions/artifacts/'+str(b['artifact']['id']))
 raw=api('actions/artifacts/'+str(b['artifact']['id'])+'/zip')
 files,summary=verify_recovery_bundle(plan,json.loads(run_raw),json.loads(meta_raw),raw)
 directory=pathlib.Path(evidence)/'recovery';directory.mkdir(mode=0o700)
 (directory/'run.json').write_bytes(run_raw);(directory/'artifact.json').write_bytes(meta_raw)
 (directory/'original.zip').write_bytes(raw)
 for name,value in files.items():(directory/name).write_bytes(value)
 (pathlib.Path(evidence)/'recovery-verification.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
 return summary

def runner(plan,api,evidence):
 need(plan.get('ready') is True and plan['continuation']['final_admission_bound'] is True,'qualified_continuation_not_ready')
 need(plan['operation']==OPERATION and plan['continuation']['id']==CONTINUATION,'continuation_identity_changed')
 need(plan['expected_installed_sha']==PUBLICATION_SOURCE,'historical_publication_source_changed')
 evidence=pathlib.Path(evidence);evidence.mkdir(parents=True,mode=0o700)
 bundles={}
 for name,b in plan['continuation']['historical'].items():
  directory=evidence/name;directory.mkdir(mode=0o700)
  run_raw=api('actions/runs/'+str(b['run_id']));meta_raw=api('actions/artifacts/'+str(b['artifact']['id']));raw=api('actions/artifacts/'+str(b['artifact']['id'])+'/zip')
  bundles[name]=bound_archive(b,json.loads(run_raw),json.loads(meta_raw),raw)
  (directory/'run.json').write_bytes(run_raw);(directory/'artifact.json').write_bytes(meta_raw);(directory/'original.zip').write_bytes(raw)
  for filename,content in bundles[name].items():(directory/filename).write_bytes(content)
 historical=verify_historical(bundles)
 (evidence/'historical-verification.json').write_text(json.dumps(historical,indent=2)+'\n')
 verify_future_recovery(plan,api,evidence)
