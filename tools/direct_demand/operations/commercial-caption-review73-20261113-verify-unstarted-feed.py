"""Authenticate one failed read-only capture before update19's first write.

No HTTP, SSH, provider, database or production filesystem actions. The existing
workflow downloads native metadata once; this helper validates and retains it.
"""
import hashlib, json, os, zipfile
from pathlib import Path
from runpy import run_path

PREFIX='commercial-caption-review73-20261113'
OP='commercial-channel-hotel-feeds-20261113-update19'
RUN=43466635418
HEAD='6b75cec7d88c4774eb84bc90e7803e1c9ca182ba'
CONTINUATION_PARENT='b806854c865c2b960848d15e45a6f13ce59acc80'
JOB=132027406620
ARTIFACT=14058672089
ZIP_SHA='a2f2e05114b60336c1d5bb1446ca54592710783120e931ebb6351aa6b11f9533'
PLAN_SHA='a7331833670d3f315294fdfed51c49573d5453eba42b8524e2b323db75e6a01a'
CONTRACT_SHA='5d6dc194ea3341bfc86e96882b0c9b8eb1d4cb159547ac5c1d43bd0c95f1fd6a'
FILES={'batch001.result.json','batch002.result.json','canary.result.json','batch003.result.json',
    'caption-completion-provenance.json','caption-release-closure-status.json','caption-content-source-acceptance.json',
    'caption-source-gate-receipts.json','caption-terminal-summary.json','capture.json','content-source-acceptance.json',
    'feed-plan.json','lease-before-capture.json','price-native-validation.json','release-closure-status.json','source-gate-receipts.json'}
WRITE_FILES={'feed-writer.started','writer-receipt.json','writer-verification.json','public-readback.json',
    'actual-feed-input.json','preparation.json','summary.json'}
CAPTURE={'operation':OP,'ok':False,'filesystem_writes':0,'database_calls':0,'provider_calls':0,'error':'caption_existing_operation_busy'}
SUCCESS_STEPS=['Prepare immutable 73-pair caption sources and existing feed contract',
    'Authenticate original 54 and completion 92 edits and exact final receipts',
    'Validate actual price baselines through unchanged PHP contracts',
    'Verify installed CONTENT source and retain the failed release closure','Prepare existing server connection',
    'Pin existing server host-key continuity','Retain actual input and original feed verification']
CAPTURE_STEP='Capture fresh 604 feeds and original 146 caption journals without writes'
SKIPPED_STEPS=['Project only 146 confirmed descriptions through the existing writer once',
    'Verify three exact local and public XML hashes without retries']

def need(ok,why):
    if not ok:raise RuntimeError(why)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def read(path):return json.loads(path.read_bytes())

def verify(run,jobs,meta,archive,contract):
    need(run['id']==RUN and run['head_sha']==HEAD and run['run_attempt']==1 and run['status']=='completed'
        and run['conclusion']=='failure' and run['head_branch']=='audit/content100-bot-publication-20261101'
        and run['path']=='.github/workflows/content100-bot-publication-20261101.yml' and run['event']=='push'
        and run['actor']['login']==run['triggering_actor']['login']=='pyatkoff','original_feed_run_changed')
    need(meta['id']==ARTIFACT and meta['name']==OP+'-result' and meta['expired'] is False
        and meta['digest']=='sha256:'+ZIP_SHA and meta['workflow_run']['id']==RUN and meta['workflow_run']['head_sha']==HEAD,
        'original_feed_artifact_changed')
    need(sha(archive)==ZIP_SHA,'original_feed_archive_changed')
    import io
    with zipfile.ZipFile(io.BytesIO(archive)) as z:
        infos=z.infolist();names=z.namelist()
        need(len(names)==len(set(names))==16 and set(names)==FILES and not set(names).intersection(WRITE_FILES)
            and sum(i.file_size for i in infos)<30000000 and all(i.file_size<8000000 for i in infos),'original_feed_scope_or_write_marker_changed')
        values={n:z.read(n) for n in names}
    need(jobs['total_count']==len(jobs['jobs'])==1,'original_feed_job_scope_changed');job=jobs['jobs'][0]
    need(job['id']==JOB and job['run_id']==RUN and job['run_attempt']==1 and job['head_sha']==HEAD
        and job['name']=='inspect' and job['status']=='completed' and job['conclusion']=='failure','original_feed_job_changed')
    steps={s['name']:s for s in job['steps']};need(len(steps)==len(job['steps']),'original_feed_step_duplicate')
    for name in SUCCESS_STEPS:need(steps[name]['status']=='completed' and steps[name]['conclusion']=='success','original_feed_required_step_changed:'+name)
    need(steps[CAPTURE_STEP]['status']=='completed' and steps[CAPTURE_STEP]['conclusion']=='failure','original_feed_failure_boundary_changed')
    for name in SKIPPED_STEPS:
        need(steps[name]['status']=='completed' and steps[name]['conclusion']=='skipped'
            and steps[name]['number']>steps[CAPTURE_STEP]['number'],'original_feed_write_may_have_started')
    need(json.loads(values['capture.json'])==CAPTURE and sha(values['feed-plan.json'])==PLAN_SHA,'original_feed_capture_or_plan_changed')
    p=json.loads(values['feed-plan.json']);lease=json.loads(values['lease-before-capture.json'])
    need(p['operation']==OP and p['expected_parent_head']=='f793943c6b32ae95ca002a792abc78a54103d45b'
        and lease['ok'] is True and lease['carrier_head']==HEAD and lease['run_id']==RUN and lease['run_attempt']==1
        and lease['parent_head']==p['expected_parent_head'] and lease['installed_sha']==p['current_installed_sha']
        and lease['phase']=='before-capture' and lease['owner_comment_id']==p['owner_pin']['comment_id']
        and lease['owner_updated_at']==p['owner_pin']['updated_at'] and lease['owner_body_sha256']==p['owner_pin']['body_sha256'],
        'original_feed_capture_lease_changed')
    admission_plan={**p,'completion_caption':{'run_id':RUN,'head_sha':HEAD}}
    admission=contract['source_admission'](admission_plan,{n:values[n] for n in ['source-gate-receipts.json','content-source-acceptance.json','release-closure-status.json']})
    native=json.loads(values['price-native-validation.json']);v=p['price_validation']
    need(native['ok'] is True and native['fixture_only'] is True and native['roster_sha256']==v['roster_sha256']
        and native['fixture_sha256']==v['fixture_sha256'],'original_native_price_validation_changed')
    for name in ['original_caption_run_id','completion_caption_run_id','editorial_pairs','template_only_pairs','drafts_validated',
                 'priced_baseline_pairs','unpriced_pairs_without_baseline','negative_admission_checks','assertions']:
        need(native[name]==v[name],'original_native_price_scope_changed:'+name)
    need(native['special_proofs_validated']=={'legacy':5,'caption':3} and native['original_cta_failures_reproduced']==6
        and native['supplier_calls']==native['provider_calls']==native['database_calls']==native['external_writes']==0,'original_native_price_side_effects_changed')
    return {'schema_version':1,'operation':PREFIX+'-unstarted-feed-proof-v1','feed_operation':OP,
        'original_run_id':RUN,'original_head_sha':HEAD,'original_job_id':JOB,'original_run_attempt':1,
        'original_artifact_id':ARTIFACT,'original_artifact_sha256':ZIP_SHA,'original_plan_sha256':PLAN_SHA,
        'original_artifact_members':sorted(FILES),'capture':CAPTURE,'skipped_writer_steps':SKIPPED_STEPS,
        'original_writer_attempts':0,'writer_and_preparation_markers_absent':True,'first_writer_execution_pending':True,
        'new_readonly_capture_limit':1,'write_retry_authorized':False,'source_admission':admission,
        'native_price_validation_sha256':sha(values['price-native-validation.json']),
        'original_native_price_validation':native,'evidence':{'run':run,'jobs':jobs,'artifact':meta}}

def main():
    need(os.environ['GITHUB_RUN_ATTEMPT']=='1','feed_continuation_retry_not_authorized')
    root=Path(os.environ['RUNNER_TEMP']);private=root/'caption-repair-private';safe=root/'caption-repair-safe';ops=Path('tools/direct_demand/operations')
    p=read(ops/(PREFIX+'-feed-plan.json'))
    need(p['operation']==OP and p['expected_parent_head']==CONTINUATION_PARENT and p['prior_unstarted_feed']==
        {'run_id':RUN,'head_sha':HEAD,'job_id':JOB,'run_attempt':1,'artifact_id':ARTIFACT,'artifact_sha256':ZIP_SHA,
         'plan_sha256':PLAN_SHA,'original_writer_attempts':0,'new_readonly_capture_limit':1,'write_retry_authorized':False},'feed_continuation_plan_changed')
    path=ops/(PREFIX+'-completion-contract.py');need(sha(path.read_bytes())==CONTRACT_SHA,'reviewed_content_source_contract_changed')
    archive=(private/'prior-unstarted-feed.zip').read_bytes()
    proof=verify(read(private/'prior-unstarted-feed-run.json'),read(private/'prior-unstarted-feed-jobs.json'),
        read(private/'prior-unstarted-feed-artifact.json'),archive,run_path(str(path)))
    need(not (safe/'prior-unstarted-feed-proof.json').exists() and not (safe/'prior-unstarted-feed.zip').exists(),'feed_prior_failure_proof_replay_stop')
    (safe/'prior-unstarted-feed.zip').write_bytes(archive)
    (safe/'prior-unstarted-feed-proof.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'original_feed_run_id':RUN,'original_writer_attempts':0,'original_readonly_capture_failure':'caption_existing_operation_busy',
        'first_update19_writer_execution_pending':True,'new_readonly_capture_limit':1,'write_retry_authorized':False}))

if __name__=='__main__':main()
