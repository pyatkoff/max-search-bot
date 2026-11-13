"""Pure validation of the real 54 + 92 caption completion artifacts.

No network, storage or execution. The caller authenticates both GitHub artifacts.
Original results never acquire the completion run's provenance.
"""
import base64, copy, hashlib, json, re
from datetime import datetime

PREFIX='commercial-caption-review73-20261113'
NAMES=['canary','batch001','batch002','batch003']
COUNTS=[8,46,46,46]
ORIGINAL_SOURCE='5fbb61990706ae4289e539df2c26c60e409032f4'
COMPLETION_SOURCE='8d6ac35dd17614b2cadb3aa3af7637c74280c01c'
DEPLOY_RUN=43456259794
SOURCE_ADMISSION='existing_authorized_content_push'
GATES={'deploy.yml':43456259794,'retired-domain-guard.yml':43456259765,'max-tls-preflight.yml':43458415792,
    'telegram-production-health.yml':43458412715,'max-production-health.yml':43458412674,
    'publish-conversation-diagnostics.yml':43458415744,'live-session-diagnostics.yml':43458412698,
    'tour-selection-host.yml':43458412489,'modeled-conversion-monitor.yml':43458412487}
GATE_NAMES=set(GATES)
CONTENT_OWNER={'id':6818332618,'owner_id':226193297,'updated_at':'2026-11-13T15:25:14Z',
    'body_sha256':'90de12e3defd5f6d79c116d46f7f976e0ae6e6c84d1cd9e26a4d09658d6b2895'}
SOURCE_VERIFIER={'git_blob_sha':'d79228dacc35d09af8ad838d34febd8413dbd7b2',
    'sha256':'5e2a31e62984792225fc57f512b508c5975d1ec463413f18bddb29bfa0aa82fb'}
SOURCE_READERS={
    'deployment_release.js':{'git_blob_sha':'f0b5b023851911dafcca7d2d8dcde06cc47cb87f',
        'sha256':'0c37232891fe384eb04165033dfc896ef8fefb6e6e5d9d336a014fe311331fe2'},
    'release_slot_close.js':{'git_blob_sha':'5b81f5e8f4e2d98aff760fc5d093f2d8f7d8ea88',
        'sha256':'0d0ee1dac37039e46ed7cee3fa9a28af7b89497902102e5b2021e9ff947505b3'}}
PUBLISHER_PINS_SHA='3232defb6fa18ac047f8bbe222cff84a2743224cf243d3fbd91a654b95f0646a'
RELEASE_BINDING={'pr':1529,'head_sha':'51a900b316cad03a41b07c6a79e36e6ee322c029','comment_id':6775467253,
    'owner_id':226193297,'updated_at':'2026-11-13T15:47:04Z'}
DIAGNOSTICS={'commit':'b367a1aac9d5ae58ecf2be7fbc7a00be5080b51c','blob':'a46b8d215b6719be2ba9b6bd8361dcdc728e388d',
    'record_digest':'6ece5ff4fad0e479b039c2dba2debbd40742a703f41b3632068f9c98239a5ddc'}
INSTALL_STAGES=['provenance','key','bundle','sync','migration','max_webhook','metrika_cron','install_checks','smoke','diagnostics']
OPS_STAGES=['migration','snapshot','live_sessions','daily_sessions','yesterday_sessions','architecture_inventory','website_smoke','health']
ORIGINAL_MANIFESTS={
    'canary':'65595a177031b4240ff0dcf757870ba2dc5a01132789e84bcd64080ce64ab14a',
    'batch001':'870960c2dd9b17c43b07a83cd6fe484e3a41effe771a06537aa686646053b94d',
    'batch002':'ab928395858e39eecf579eb9cf1be5678c9353ffa12047a5254ce8239c31cde0',
    'batch003':'24d3a28ffeae94ef7839c5a9331065d143fa74b393683f1cfd1f0e118408be5e',
}
FILES={n+s for n in NAMES for s in ['.started','.result.json']}|{
    'original-partial.zip','original-run.json','original-jobs.json','original-artifact.json','original-failure-proof.json',
    'batch002.prepared-not-executed.manifest.json','batch003.prepared-not-executed.manifest.json',
    'batch002.manifest.json','batch003.manifest.json','before-completion-snapshot.json','final-snapshot.json',
    'summary.json','completion-plan-provenance.json','completion-provenance.json','source-gate-receipts.json',
    'content-source-acceptance.json','release-closure-status.json',
}

def need(ok,why):
    if not ok:raise RuntimeError(why)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def decimal(v):
    need(type(v) is int and v>0 or isinstance(v,str) and re.fullmatch('[1-9][0-9]*',v),'noncanonical_decimal_identity')
    return int(v)

def native_source_run(run,rid,name,outcome):
    event='push' if name in ['deploy.yml','retired-domain-guard.yml'] else 'workflow_run'
    need(run['id']==rid and run['run_attempt']==1 and run['head_sha']==COMPLETION_SOURCE
        and run['head_branch']=='main' and run['head_repository']['full_name']=='pyatkoff/max-search-bot'
        and run['path']=='.github/workflows/'+name and run['event']==event and run['status']=='completed'
        and run['conclusion']==outcome,'native_content_source_run_changed:'+name)
    if event=='workflow_run':need(run['display_title']=='deploy-proof:v1:'+str(DEPLOY_RUN)+':1','native_content_deploy_origin_changed')

def content_file(meta,path,expected_blob=None):
    need(meta['type']=='file' and meta['path']==path and meta['encoding']=='base64','content_evidence_file_invalid')
    raw=base64.b64decode(meta['content'].replace('\n',''),validate=True)
    blob=hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
    need(len(raw)==meta['size'] and blob==meta['sha'] and (expected_blob is None or blob==expected_blob),'content_evidence_blob_changed')
    return json.loads(raw)

def native_step(job,name,outcome):
    rows=[s for s in job['steps'] if s['name']==name]
    need(len(rows)==1 and rows[0]['status']=='completed' and rows[0]['conclusion']==outcome,'content_source_step_changed:'+name)
    return rows[0]

def source_admission(plan,completion):
    """Check only the separate CONTENT proof; the failed release remains unaccepted."""
    gates=json.loads(completion['source-gate-receipts.json']);accepted=json.loads(completion['content-source-acceptance.json'])
    failed=json.loads(completion['release-closure-status.json']);e=accepted['evidence'];failure_e=failed['evidence']
    need(plan['source_admission']==SOURCE_ADMISSION and plan['current_source_gates']==GATES,'content_source_admission_binding_changed')
    need(set(e)=={'main','verify_run','verify_jobs','tls_run','source_commit','source_tree','gates','content_owner','ops_status_source'}
        and set(failure_e)=={'closer_run','closer_jobs','closer_artifacts','diagnostics_ref','deploy_status_source','release_publisher'},'content_source_evidence_scope_changed')
    need(set(gates)==set(e['gates'])==GATE_NAMES and len({g['run_id'] for g in gates.values()})==9,'completion_nine_content_source_gates_required')
    for name,rid in GATES.items():
        run=e['gates'][name];native_source_run(run,rid,name,'success')
        receipt={k:run[k] for k in ['head_sha','run_attempt','conclusion','head_branch','event','path','display_title']};receipt['run_id']=rid
        need(gates[name]==receipt,'completion_content_source_gate_receipt_changed')
    need(e['verify_run']==e['gates']['deploy.yml'] and e['tls_run']==e['gates']['max-tls-preflight.yml']
        and e['main']['object']['sha']==COMPLETION_SOURCE,'content_source_snapshot_inconsistent')
    jobs=e['verify_jobs']['jobs'];names={'verify-inventory','verify-syntax','verify / application','verify / diagnostics','deploy','verify'}
    need(e['verify_jobs']['total_count']==len(jobs)==6 and {j['name'] for j in jobs}==names
        and all(j['run_id']==DEPLOY_RUN and j['head_sha']==COMPLETION_SOURCE and j['run_attempt']==1
            and j['status']=='completed' and j['conclusion']=='success' for j in jobs),'actual_content_installation_jobs_unconfirmed')
    byname={j['name']:j for j in jobs}
    for name in ['Build exact deployment bundle','Sync canonical production checkout','Apply database migrations and fence the old hotel contour',
                 'Reconcile canonical MAX webhook ownership','Ensure Metrika offline uploader cron','Apply profile and retain exact diagnostic snapshot']:
        native_step(byname['deploy'],name,'success')
    for name in ['Verify installed production checks','Download exact installed diagnostic snapshot','Publish verification stage status']:
        native_step(byname['verify'],name,'success')
    pins=accepted['publishing_blobs']
    need(len(pins)==22 and sha(json.dumps(pins,sort_keys=True,separators=(',',':')).encode())==PUBLISHER_PINS_SHA,'content_publishing_blobs_changed')
    commit=e['source_commit'];tree=e['source_tree']
    need(commit['sha']==COMPLETION_SOURCE and commit['tree']['sha']==tree['sha'] and tree['truncated'] is False
        and len(tree['tree'])==len({x['path'] for x in tree['tree']}),'content_source_tree_changed')
    blobs={x['path']:x for x in tree['tree']}
    need(all(blobs.get(p,{}).get('sha')==v and blobs[p]['type']=='blob' for p,v in pins.items()),'content_source_tree_publisher_changed')
    owner=e['content_owner']
    need(owner['id']==CONTENT_OWNER['id'] and owner['user']['id']==CONTENT_OWNER['owner_id'] and owner['updated_at']==CONTENT_OWNER['updated_at']
        and sha(owner['body'].encode())==CONTENT_OWNER['body_sha256'],'completion_content_owner_changed')
    need(failure_e['diagnostics_ref']['object']['sha']==DIAGNOSTICS['commit'],'content_diagnostics_source_changed')
    record=content_file(failure_e['deploy_status_source'],'deploy_status.json',DIAGNOSTICS['blob'])
    ops=content_file(e['ops_status_source'],'ops_status.json')
    need(record['schema']==2 and record['sha']==ops['sha']==COMPLETION_SOURCE and record['run_id']==str(DEPLOY_RUN)
        and record['install_attempt']==record['smoke_attempt']==record['diagnostics_attempt']==1
        and all(record[s]=='success' for s in INSTALL_STAGES) and all(ops[s]=='success' for s in OPS_STAGES),'content_installation_or_ops_unconfirmed')
    terminal='Release only the admitted publisher after all original mandatory gates'
    release=record['repository_release'];closure=release['closure']
    need(release['repository_sha']==release['application_sha']==COMPLETION_SOURCE and release['kind']=='installed'
        and release['phase']=='pending_terminal' and release['baseline_eligible'] is False
        and release['release']=={'event':'push','run_attempt':1,'run_id':str(DEPLOY_RUN)}
        and closure['run_id']=='43458412572' and closure['run_attempt']==1 and closure['job']=='release-slot'
        and closure['binding']==RELEASE_BINDING and closure['terminal_step']==terminal
        and closure['expected_terminal']=='PR1529: установлено, выпуск свободен' and 'recovery' not in closure,'unaccepted_release_history_changed')
    native_source_run(failure_e['closer_run'],43458412572,'modeled-conversion-value-export.yml','failure')
    cj=failure_e['closer_jobs'];close_jobs=cj['jobs']
    need(cj['total_count']==len(close_jobs)==3 and {j['name'] for j in close_jobs}=={'configure','release-slot','release-slot-recovery'}
        and all(j['run_id']==43458412572 and j['head_sha']==COMPLETION_SOURCE and j['run_attempt']==1 and j['status']=='completed' for j in close_jobs),'content_closer_jobs_changed')
    own=next(j for j in close_jobs if j['name']=='release-slot')
    need(own['id']==131998597573 and own['conclusion']=='failure' and all(j['conclusion']=='skipped' and j['steps']==[] for j in close_jobs if j is not own),'content_closer_failure_changed')
    prepare=native_step(own,'Prepare the exact release proof before terminal publication','failure')
    upload=native_step(own,'Bind the terminal candidate to the original closer run — ','skipped');end=native_step(own,terminal,'skipped')
    need(prepare['number']<upload['number']<end['number'] and failure_e['closer_artifacts']['total_count']==0
        and failure_e['closer_artifacts']['artifacts']==[],'unaccepted_closer_boundary_changed')
    publisher=failure_e['release_publisher']
    need(publisher['id']==RELEASE_BINDING['comment_id'] and publisher['user']['id']==RELEASE_BINDING['owner_id']
        and publisher['updated_at']==RELEASE_BINDING['updated_at'] and publisher['body']=='PR1529: выпуск занят','foreign_publisher_history_changed')
    expected_failure={'schema_version':1,'operation':PREFIX+'-release-closure-status-v1','status':'not_accepted',
        'repository_sha':COMPLETION_SOURCE,'application_sha':COMPLETION_SOURCE,'release_run_id':DEPLOY_RUN,
        'original_closer_run_id':43458412572,'original_closer_job_id':131998597573,'original_closer_conclusion':'failure',
        'preparation':'failure','proof_upload':'skipped','terminal':'skipped','diagnostics':DIAGNOSTICS,'phase':'pending_terminal',
        'publisher':{'binding':RELEASE_BINDING,'body':publisher['body'],'updated_at':publisher['updated_at']},
        'recovery_performed':False,'publisher_mutation_authorized':False,'publisher_mutations':0,'evidence':failure_e}
    need(failed==expected_failure,'content_closer_status_misrepresented')
    api='/repos/pyatkoff/max-search-bot'
    canonical={'ok':True,'repository_sha':COMPLETION_SOURCE,'application_sha':COMPLETION_SOURCE,'record_digest':DIAGNOSTICS['record_digest'],
        'installation':{'run_id':DEPLOY_RUN,'run_attempt':1,'publication_attempt':1,'stages':{s:'success' for s in INSTALL_STAGES}},
        'ops':{'sha':COMPLETION_SOURCE,'stages':{s:'success' for s in OPS_STAGES}},
        'calls':[api+'/git/ref/heads/diagnostics',api+'/contents/deploy_status.json?ref='+DIAGNOSTICS['commit'],api+'/actions/runs/'+str(DEPLOY_RUN),
            api+'/actions/runs/'+str(DEPLOY_RUN)+'/attempts/1/jobs?per_page=100',api+'/contents/ops_status.json?ref=diagnostics'],
        'write_calls':0,'release_consumer_invoked':False,'release_closure_authenticated':False}
    expected_acceptance={'schema_version':1,'operation':PREFIX+'-content-source-acceptance-v1','scope':SOURCE_ADMISSION,'status':'accepted',
        'repository_sha':COMPLETION_SOURCE,'application_sha':COMPLETION_SOURCE,
        'source_verifier_result':{'ok':True,'publishing_blob_count':22,'source_verify':'success','strict_tls':'success','aggregate_deploy_conclusion':'success'},
        'source_verifier':SOURCE_VERIFIER,'canonical_readers':SOURCE_READERS,'canonical_installed_proof':canonical,
        'publishing_blob_count':22,'publishing_blobs':pins,'content_owner':CONTENT_OWNER,'mandatory_source_gate_count':9,
        'release_closure_status':'not_accepted','release_consumer_invoked':False,'release_slot_mutations':0,'evidence':e,
        'source_gate_receipts_sha256':sha(completion['source-gate-receipts.json']),
        'release_closure_status_sha256':sha(completion['release-closure-status.json']),
        'carrier':{'run_id':plan['completion_caption']['run_id'],'run_attempt':1,'head_sha':plan['completion_caption']['head_sha']}}
    need(accepted==expected_acceptance,'content_source_acceptance_changed')
    return {'source_admission':SOURCE_ADMISSION,'release_closure_status':'not_accepted',
        'content_source_acceptance_sha256':sha(completion['content-source-acceptance.json']),
        'release_closure_status_sha256':sha(completion['release-closure-status.json'])}

def original_jobs(jobs,binding):
    need(len(jobs['jobs'])==1,'original_job_scope_changed');job=jobs['jobs'][0]
    need(job['id']==binding['job_id'] and job['head_sha']==binding['head_sha'] and job['status']=='completed'
        and job['conclusion']=='failure' and job['name']=='inspect','original_job_changed')
    steps={s['name']:s for s in job['steps']}
    need(steps['Apply canary and three sequential batches once with exact predecessors']['conclusion']=='failure'
        and steps['Read back146 revised captions and retained photo/button identities']['conclusion']=='skipped'
        and steps['Retain actual edit receipts and final snapshots']['conclusion']=='success','original_failure_boundary_changed')
    for name in ['Compile and validate four exact finite caption batches without sends','Authenticate original read artifact and exact preimage',
                 'Verify nine original source gates and the unchanged 22 publishing pins']:
        need(steps[name]['conclusion']=='success','original_admission_gates_changed')

def receipt_result(result,manifest,raw):
    need(result['validated'] is True and result['ssh_status']==0 and result.get('runtime_error') is None and result['retry_authorized'] is False
        and re.fullmatch('[a-f0-9]{64}',result['runtime_sha256']) and result['operation']==manifest['operation']
        and result['manifest_sha256']==sha(raw) and len(result['posts'])==result['confirmed_posts']==manifest['row_count'],'caption_operation_unconfirmed')
    targets={x['publication_id']:x for x in manifest['rows']};seen={}
    for a in result['posts']:
        pid=a['publication_id'];need(pid in targets and pid not in seen,'caption_receipt_scope_changed');r=targets[pid]
        for k in ['hotel_id','platform','publication_id','message_id','post_url','photo_signature','button_url_sha256','source_key']:
            need(a[k]==r[k],'caption_receipt_identity_changed:'+k)
        need(a['previous_version_id']==r['version_id'] and type(a['version_id']) is int and a['version_id']>0 and a['version_id']!=r['version_id']
            and a['previous_text_sha256']==r['old_text_sha256'] and a['text_sha256']==r['new_text_sha256']
            and a['previous_attachments_sha256']==r['attachments_sha256'] and re.fullmatch('[a-f0-9]{64}',a['attachments_sha256'])
            and a['state']=='sent' and a['button_text']=='✈️ Узнать стоимость тура'
            and a.get('monitor_price_record_id')==r.get('monitor_price_record_id')
            and a['caption_correction_operation']==manifest['operation'],'caption_receipt_content_changed')
        for k in ['body_photo_button_verified','formatting_verified','existing_message_reused','existing_token_resolves_to_current_version']:
            need(a[k] is True,'caption_receipt_not_verified:'+k)
        seen[pid]=a
    return seen

def snapshot_checked(raw,rows,receipts,complete):
    snapshot=json.loads(raw)
    need(snapshot['operation']==PREFIX+'-read-v1' and snapshot['ok'] is True and snapshot['installed_sha']==COMPLETION_SOURCE
        and snapshot['provider_calls']==73 and snapshot['post_write_attempts']==0 and snapshot['monitor_pending'] is False
        and len(snapshot['posts'])==146,'completion_snapshot_scope_changed')
    seen=set()
    for x in snapshot['posts']:
        pid=x['publication_id'];need(pid in rows and pid not in seen,'completion_snapshot_identity_changed');seen.add(pid);r=rows[pid]
        for k in ['hotel_id','platform','publication_id','message_id','post_url','source_key','config_version','photo_signature','button_url_sha256']:
            need(x[k]==r[k],'completion_snapshot_binding_changed:'+k)
        need(x['status']=='sent' and x['button_observed_in_snapshot'] is (True if pid in receipts else r['button_observed_in_snapshot']) and x['button_resolves_to_current_post'] is True
            and x['media_count']==1 and x['provider_live_verified'] is (x['platform']=='max')
            and x.get('monitor_price_record_id')==r.get('monitor_price_record_id'),'completion_snapshot_preservation_changed')
        if pid in receipts:
            a=receipts[pid]
            need(x['caption']==r['new_text'] and x['caption_sha256']==r['new_text_sha256'] and x['version_id']==a['version_id']
                and x['attachments_sha256']==a['attachments_sha256'] and x['caption_correction_operation']==a['caption_correction_operation'],'completed_caption_changed')
        else:
            need(not complete and x['caption']==r['old_text'] and x['caption_sha256']==r['old_text_sha256'] and x['version_id']==r['version_id']
                and x['attachments_sha256']==r['attachments_sha256'],'unstarted_caption_preimage_changed')
    need(len(seen)==146 and (not complete or len(receipts)==146),'completion_snapshot_incomplete')
    return snapshot

def verify(plan,cohort_raw,original_manifests,original,completion,jobs):
    old=plan['original_caption'];done=plan['completion_caption'];cohort=json.loads(cohort_raw)
    need(old['run_id']==43455664622 and old['head_sha']=='ea88349442e926ea39b409347eb14635eb891760'
        and old['artifact_id']==14054371257 and old['artifact_sha256']=='ca308824dcf688d179d09705ffbc90752be6bec28124330a8bb842adb343e596'
        and old['job_id']==131989003936 and old['conclusion']=='failure' and old['run_attempt']==1,'original_partial_binding_changed')
    need(done['conclusion']=='success' and done['run_attempt']==1 and done['run_id']!=old['run_id']
        and done['artifact_name']==PREFIX+'-completion-result' and plan['completion_installed_sha']==COMPLETION_SOURCE
        and plan['cohort_installed_sha']==ORIGINAL_SOURCE and cohort['expected_installed_sha']==ORIGINAL_SOURCE
        and sha(cohort_raw)==plan['cohort_sha256'],'completion_binding_changed')
    rows={r['publication_id']:r for r in cohort['rows']}
    need(len(rows)==146 and len({r['hotel_id'] for r in rows.values()})==73
        and sum(r.get('monitor_price_record_id') is not None for r in rows.values())==54,'cohort_scope_changed')
    expected_original={n+s for n in NAMES[:2] for s in ['.started','.result.json']}
    need(set(original)==expected_original and set(completion)==FILES,'completion_artifact_scope_changed')
    original_jobs(jobs,old);original_jobs(json.loads(completion['original-jobs.json']),old)
    need(sha(completion['original-partial.zip'])==old['artifact_sha256'],'original_archive_history_changed')
    run=json.loads(completion['original-run.json']);meta=json.loads(completion['original-artifact.json'])
    need(decimal(run['id'])==old['run_id'] and run['head_sha']==old['head_sha'] and run['run_attempt']==1 and run['status']=='completed'
        and run['conclusion']=='failure' and meta['id']==old['artifact_id'] and meta['name']==old['artifact_name']
        and meta['digest']=='sha256:'+old['artifact_sha256'] and meta['workflow_run']['id']==old['run_id']
        and meta['workflow_run']['head_sha']==old['head_sha'],'embedded_original_provenance_changed')
    originals={};union=set();receipts={};raws={}
    for name,count in zip(NAMES,COUNTS):
        raw=original_manifests[name];m=json.loads(raw);originals[name]=m
        need(sha(raw)==ORIGINAL_MANIFESTS[name] and m['operation']==PREFIX+'-'+name+'-v1' and m['expected_installed_sha']==ORIGINAL_SOURCE and m['quality_cohort_sha256']==sha(cohort_raw)
            and m['row_count']==len(m['rows'])==count,'original_manifest_changed')
        for r in m['rows']:
            pid=r['publication_id'];need(pid in rows and rows[pid]==r and pid not in union,'original_manifest_cohort_changed');union.add(pid)
        if name in NAMES[:2]:
            for suffix in ['.started','.result.json']:need(completion[name+suffix]==original[name+suffix],'original_54_receipts_rewritten')
            need(original[name+'.started'].decode().strip()==str(old['run_id'])+'/1','original_attempt_changed')
            found=receipt_result(json.loads(original[name+'.result.json']),m,raw)
            need(not set(receipts).intersection(found),'original_receipt_duplicate');receipts.update(found);raws[name]=raw
    need(len(union)==146 and len(receipts)==54,'original_confirmed_scope_changed')
    before=snapshot_checked(completion['before-completion-snapshot.json'],rows,receipts,False)
    need(datetime.fromisoformat(before['observed_at_utc'])>=datetime.fromisoformat(originals['canary']['snapshot']['observed_at_utc']),'completion_before_time_order_changed')
    for name in NAMES[2:]:
        need(completion[name+'.prepared-not-executed.manifest.json']==original_manifests[name],'prepared_unexecuted_history_changed')
        raw=completion[name+'.manifest.json'];actual=json.loads(raw);expected=copy.deepcopy(originals[name])
        expected['expected_installed_sha']=COMPLETION_SOURCE
        expected['snapshot']={'operation':before['operation'],'installed_sha':COMPLETION_SOURCE,'post_count':146,
            'sha256':sha(completion['before-completion-snapshot.json']),'observed_at_utc':before['observed_at_utc']}
        if name=='batch003':expected['predecessor']['manifest_sha256']=sha(raws['batch002'])
        need(actual==expected,'completion_manifest_rebound_outside_scope')
        need(completion[name+'.started'].decode().strip()==str(done['run_id'])+'/1','completion_attempt_changed')
        found=receipt_result(json.loads(completion[name+'.result.json']),actual,raw)
        need(not set(receipts).intersection(found),'completion_receipt_duplicate');receipts.update(found);raws[name]=raw
    operations=[{'name':name,'operation':PREFIX+'-'+name+'-v1','manifest_sha256':sha(raws[name]),
        'installed_sha':ORIGINAL_SOURCE if name in NAMES[:2] else COMPLETION_SOURCE,'row_count':count} for name,count in zip(NAMES,COUNTS)]
    need(operations==plan['operations'],'operation_source_pins_changed')
    admission=source_admission(plan,completion)
    proof=json.loads(completion['completion-provenance.json'])
    expected_proof={'schema_version':1,'operation':PREFIX+'-completion-v1','phase':'confirmed','cohort_sha256':sha(cohort_raw),'source_read_run_id':cohort['source_read_run_id'],
        'source_before':ORIGINAL_SOURCE,'source_current':COMPLETION_SOURCE,'original_run_id':old['run_id'],'original_head_sha':old['head_sha'],
        'original_job_id':old['job_id'],'original_run_attempt':1,'original_artifact_id':old['artifact_id'],'original_artifact_sha256':old['artifact_sha256'],
        'completed_posts_reverified':54,'unstarted_posts_reverified':92,'before_completion_snapshot_sha256':sha(completion['before-completion-snapshot.json']),
        'completion_run_id':done['run_id'],'completion_head_sha':done['head_sha'],'completion_run_attempt':1,
        'same_operation_ids':True,'earlier_batches_replayed':False,'publisher_blob_count':22,'source_gate_receipts_sha256':sha(completion['source-gate-receipts.json']),
        **admission,'batches':[]}
    for op in operations:
        name=op['name'];source=old if name in NAMES[:2] else done
        expected_proof['batches'].append({'name':name,'operation':op['operation'],'post_count':op['row_count'],
            'execution_run_id':source['run_id'],'execution_head_sha':source['head_sha'],'run_attempt':1,'installed_sha':op['installed_sha'],
            'original_manifest_sha256':sha(original_manifests[name]),'executed_manifest_sha256':sha(raws[name]),'predecessor':json.loads(raws[name])['predecessor'],
            'result_sha256':sha(completion[name+'.result.json']),'started_sha256':sha(completion[name+'.started'])})
    need(proof==expected_proof,'completion_provenance_changed')
    expected_prepared=copy.deepcopy(expected_proof);expected_prepared['phase']='prepared'
    for batch in expected_prepared['batches'][2:]:batch['result_sha256']=batch['started_sha256']=None
    need(json.loads(completion['completion-plan-provenance.json'])==expected_prepared,'completion_preparation_provenance_changed')
    failure=json.loads(completion['original-failure-proof.json'])
    need(failure['operation']==PREFIX+'-original-failure-proof-v1' and failure['original_run_id']==old['run_id']
        and failure['original_head_sha']==old['head_sha'] and failure['original_job_id']==old['job_id'] and failure['original_run_attempt']==1
        and failure['original_artifact_id']==old['artifact_id'] and failure['original_artifact_sha256']==old['artifact_sha256']
        and failure['validated_batches']==[{'name':n,'operation':PREFIX+'-'+n+'-v1','confirmed_posts':count} for n,count in zip(NAMES[:2],COUNTS[:2])]
        and failure['completed_posts']==54 and failure['unstarted_batch_names']==NAMES[2:] and failure['no_unstarted_artifact_members'] is True
        and failure['traceback_stdin_line']==3 and failure['original_job_log_sha256']=='55f9d5b39300b3aad6ed94f557ee78a867f86db1df1c5cc41a945d21f4ff3cb3','original_partial_failure_proof_changed')
    summary=json.loads(completion['summary.json']);final=snapshot_checked(completion['final-snapshot.json'],rows,receipts,True)
    need(sha(completion['summary.json'])==done['summary_sha256'] and summary['ok'] is True and summary['operation']==PREFIX+'-edit-v1'
        and summary['source_read_run_id']==cohort['source_read_run_id'] and summary['cohort_sha256']==sha(cohort_raw) and summary['installed_sha']==COMPLETION_SOURCE
        and decimal(summary['run_id'])==done['run_id'] and summary['run_attempt']==1 and summary['confirmed_caption_edits']==146 and summary['corrected_hotels']==73
        and summary['price_blocks_retained']==54 and summary['timestamp_display_conversions']==0 and summary['new_posts_created']==0
        and summary['existing_buttons_and_photos_verified']==146 and summary['final_snapshot_sha256']==sha(completion['final-snapshot.json'])
        and summary['original_completed_run_id']==old['run_id'] and summary['original_completed_posts']==54 and summary['first_execution_remaining_posts']==92
        and summary['earlier_batches_replayed'] is False and summary['completion_provenance_sha256']==sha(completion['completion-provenance.json']),'completion_terminal_summary_changed')
    need(summary['observed_at_utc']==final['observed_at_utc'] and datetime.fromisoformat(final['observed_at_utc'])>=datetime.fromisoformat(before['observed_at_utc']),'completion_final_time_order_changed')
    return {'receipts':{a['platform']+':'+str(pid):a for pid,a in receipts.items()},'manifests':raws,
        'operations':operations,'summary':summary,'final_snapshot':final,'provenance':proof,'source_admission':admission}
