"""Bounded existing CONTENT source admission; GET-only metadata, no release mutation.

The unchanged verify-commercial-source.py admits the exact publication surface.
The unchanged main readers add actual installation/ops proof. This is deliberately
not resolve-consumer, resolve-editor, accepted release, or authority over a slot.
"""
import argparse, base64, hashlib, importlib.util, json, os, subprocess
from pathlib import Path

PREFIX = 'commercial-caption-review73-20261113'
CURRENT = '8d6ac35dd17614b2cadb3aa3af7637c74280c01c'
DEPLOY, CLOSER, CLOSER_JOB = 43456259794, 43458412572, 131998597573
REPO = 'repos/pyatkoff/max-search-bot'
CONTENT_OWNER = {'id':6818332618,'owner_id':226193297,'updated_at':'2026-11-13T15:25:14Z',
                 'body_sha256':'90de12e3defd5f6d79c116d46f7f976e0ae6e6c84d1cd9e26a4d09658d6b2895'}
BINDING = {'pr':1529,'head_sha':'51a900b316cad03a41b07c6a79e36e6ee322c029','comment_id':6775467253,
           'owner_id':226193297,'updated_at':'2026-11-13T15:47:04Z'}
DIAGNOSTICS = {'commit':'b367a1aac9d5ae58ecf2be7fbc7a00be5080b51c',
               'blob':'a46b8d215b6719be2ba9b6bd8361dcdc728e388d',
               'record_digest':'6ece5ff4fad0e479b039c2dba2debbd40742a703f41b3632068f9c98239a5ddc'}
SOURCE_VERIFIER_SHA = '5e2a31e62984792225fc57f512b508c5975d1ec463413f18bddb29bfa0aa82fb'
SOURCE_VERIFIER_BLOB = 'd79228dacc35d09af8ad838d34febd8413dbd7b2'
READERS = {
 'deployment_release.js':('f0b5b023851911dafcca7d2d8dcde06cc47cb87f','0c37232891fe384eb04165033dfc896ef8fefb6e6e5d9d336a014fe311331fe2'),
 'release_slot_close.js':('5b81f5e8f4e2d98aff760fc5d093f2d8f7d8ea88','0d0ee1dac37039e46ed7cee3fa9a28af7b89497902102e5b2021e9ff947505b3'),
}
GATES = {
 'deploy.yml':43456259794,'retired-domain-guard.yml':43456259765,'max-tls-preflight.yml':43458415792,
 'telegram-production-health.yml':43458412715,'max-production-health.yml':43458412674,
 'publish-conversation-diagnostics.yml':43458415744,'live-session-diagnostics.yml':43458412698,
 'tour-selection-host.yml':43458412489,'modeled-conversion-monitor.yml':43458412487,
}
TERMINAL = 'Release only the admitted publisher after all original mandatory gates'
PREPARE = 'Prepare the exact release proof before terminal publication'
BIND_STEP = 'Bind the terminal candidate to the original closer run — '

def require(ok, reason):
    if not ok: raise ValueError(reason)
def sha(b): return hashlib.sha256(b).hexdigest()
def blob(b): return hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
def encoded_file(meta, expected_path, expected_blob=None):
    require(meta.get('type')=='file' and meta.get('path')==expected_path and meta.get('encoding')=='base64', 'invalid_content_file')
    raw=base64.b64decode(meta['content'].replace('\n',''),validate=True)
    require(len(raw)==meta['size'] and blob(raw)==meta['sha'] and (expected_blob is None or meta['sha']==expected_blob), 'content_blob_changed')
    return raw
def api(path):
    require(path.startswith(REPO+'/') and '\n' not in path, 'untrusted_read_path')
    # No implicit POST from gh fields; one GET, no retries, no credential output.
    p=subprocess.run(['gh','api','--method','GET','-H','Cache-Control: no-cache',path],capture_output=True,timeout=45)
    require(p.returncode==0 and len(p.stdout)<=2500000, 'github_metadata_read_failed')
    return json.loads(p.stdout)
def native_identity(run, rid, filename, event, outcome):
    require(run['id']==rid and run['run_attempt']==1 and run['head_sha']==CURRENT
        and run['head_branch']=='main' and run['head_repository']['full_name']=='pyatkoff/max-search-bot'
        and run['path']=='.github/workflows/'+filename and run['event']==event
        and run['status']=='completed' and run['conclusion']==outcome, 'native_source_run_changed:'+filename)
    if event=='workflow_run': require(run['display_title']==f'deploy-proof:v1:{DEPLOY}:1','native_origin_changed')
def steps(job, name, outcome):
    rows=[s for s in job['steps'] if s['name']==name]
    require(len(rows)==1 and rows[0]['status']=='completed' and rows[0]['conclusion']==outcome, 'closer_step_changed:'+name)
    return rows[0]
def existing_verifier(path, evidence):
    raw=path.read_bytes();require(sha(raw)==SOURCE_VERIFIER_SHA and blob(raw)==SOURCE_VERIFIER_BLOB,'accepted_content_verifier_changed')
    spec=importlib.util.spec_from_file_location('accepted_content_source',path)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    result=module.verify(evidence,CURRENT)
    require(result['ok'] is True and result['publishing_blob_count']==22,'content_surface_refused')
    return result,module.PINS

CANONICAL = r'''
const fs=require('node:fs'),path=require('node:path');
const root=process.argv[2],r=require(path.join(root,'deployment_release.js'));
const e=JSON.parse(fs.readFileSync(process.argv[3],'utf8'));
let calls=[];
const api=async(method,url,_body,raw)=>{
 r.requireValue(method==='GET'&&_body===undefined,'non_read_method');calls.push(url);
 if(url===r.API_ROOT+'/git/ref/heads/diagnostics')return e.diagnostics_ref;
 if(url===r.API_ROOT+'/contents/deploy_status.json?ref='+e.diagnostics_ref.object.sha)return e.deploy_status_source;
 if(url===r.API_ROOT+'/actions/runs/'+e.verify_run.id)return e.verify_run;
 if(url===r.API_ROOT+'/actions/runs/'+e.verify_run.id+'/attempts/1/jobs?per_page=100')return e.verify_jobs;
 if(url===r.API_ROOT+'/contents/ops_status.json?ref=diagnostics'&&raw===true)return e.ops;
 throw new Error('unlisted_read_path');
};
(async()=>{
 const location=await r.readRecord(api),checked=r.validateRelease(location.record);
 const proof=await r.deploymentProof(api,checked.installation);
 const ops=await r.requireOps(api,checked.installation.sha);
 process.stdout.write(JSON.stringify({ok:true,repository_sha:checked.release.repository_sha,
  application_sha:checked.installation.sha,record_digest:location.record_digest,
  installation:{run_id:proof.run.id,run_attempt:proof.attempt,publication_attempt:proof.publicationAttempt,
   stages:Object.fromEntries(r.INSTALL_STAGES.map(k=>[k,checked.installation[k]]))},
  ops:{sha:ops.sha,stages:Object.fromEntries(r.OPS_STAGES.map(k=>[k,ops[k]]))},
  calls,write_calls:0,release_consumer_invoked:false,release_closure_authenticated:false}));
})().catch(()=>{process.stderr.write('CANONICAL_INSTALLED_CONTENT_PROOF_REFUSED\n');process.exit(1)});
'''

def verify(e, verifier_path, reader_root, tmp):
    prior,pins=existing_verifier(verifier_path,e)
    native_identity(e['verify_run'],DEPLOY,'deploy.yml','push','success')
    require(e['verify_jobs']['total_count']==len(e['verify_jobs']['jobs'])==6,'incomplete_deploy_jobs')
    jobs=e['verify_jobs']['jobs']
    require(len({j['name'] for j in jobs})==6 and all(j['run_id']==DEPLOY and j['head_sha']==CURRENT
        and j['run_attempt']==1 and j['status']=='completed' and j['conclusion']=='success' for j in jobs),'actual_deploy_jobs_unconfirmed')
    require(set(e['gates'])==set(GATES),'content_gate_scope_changed')
    gate_receipts={}
    for name,rid in GATES.items():
        run=e['gates'][name];event='push' if name in ['deploy.yml','retired-domain-guard.yml'] else 'workflow_run'
        native_identity(run,rid,name,event,'success')
        gate_receipts[name]={k:run[k] for k in ['head_sha','run_attempt','conclusion','head_branch','event','path','display_title']}
        gate_receipts[name]['run_id']=run['id']
    require(e['tls_run']==e['gates']['max-tls-preflight.yml'] and e['verify_run']==e['gates']['deploy.yml'],'inconsistent_gate_snapshots')
    owner=e['content_owner']
    require(owner['id']==CONTENT_OWNER['id'] and owner['user']['id']==CONTENT_OWNER['owner_id']
        and owner['updated_at']==CONTENT_OWNER['updated_at'] and sha(owner['body'].encode())==CONTENT_OWNER['body_sha256'],'content_owner_changed')
    require(e['diagnostics_ref']['object']['sha']==DIAGNOSTICS['commit'],'observed_diagnostics_changed')
    record=json.loads(encoded_file(e['deploy_status_source'],'deploy_status.json',DIAGNOSTICS['blob']))
    ops=json.loads(encoded_file(e['ops_status_source'],'ops_status.json'))
    require(record['sha']==record['repository_release']['repository_sha']==record['repository_release']['application_sha']==ops['sha']==CURRENT,'installed_source_mismatch')
    require(record['schema']==2 and record['run_id']==str(DEPLOY) and record['install_attempt']==record['smoke_attempt']==record['diagnostics_attempt']==1,'original_installation_changed')
    release=record['repository_release'];closure=release['closure']
    require(release['kind']=='installed' and release['phase']=='pending_terminal' and release['baseline_eligible'] is False
        and release['release']=={'event':'push','run_attempt':1,'run_id':str(DEPLOY)}
        and closure['run_id']==str(CLOSER) and closure['run_attempt']==1 and closure['job']=='release-slot'
        and closure['binding']==BINDING and closure['terminal_step']==TERMINAL
        and closure['expected_terminal']=='PR1529: установлено, выпуск свободен' and 'recovery' not in closure,'original_release_lineage_changed')
    native_identity(e['closer_run'],CLOSER,'modeled-conversion-value-export.yml','workflow_run','failure')
    cj=e['closer_jobs'];require(cj['total_count']==len(cj['jobs'])==3,'original_closer_jobs_incomplete')
    own=[j for j in cj['jobs'] if j['id']==CLOSER_JOB and j['name']=='release-slot']
    require(len(own)==1 and all(j['run_id']==CLOSER and j['head_sha']==CURRENT and j['run_attempt']==1 and j['status']=='completed' for j in cj['jobs']),'original_closer_identity_changed')
    own=own[0];require(own['conclusion']=='failure','original_closer_failure_changed')
    prepare=steps(own,PREPARE,'failure');upload=steps(own,BIND_STEP,'skipped');terminal=steps(own,TERMINAL,'skipped')
    require(prepare['number']<upload['number']<terminal['number'],'original_closer_boundary_changed')
    require({j['name'] for j in cj['jobs']}=={'configure','release-slot','release-slot-recovery'}
        and all(j['conclusion']=='skipped' and j['steps']==[] for j in cj['jobs'] if j['name']!='release-slot'),'closer_side_job_executed')
    require(e['closer_artifacts']['total_count']==0 and e['closer_artifacts']['artifacts']==[],'unexpected_closer_publication')
    publisher=e['release_publisher']
    require(publisher['id']==BINDING['comment_id'] and publisher['user']['id']==BINDING['owner_id']
        and publisher['updated_at']==BINDING['updated_at'] and publisher['body']=='PR1529: выпуск занят','observed_release_publisher_changed')
    for name,(git,checksum) in READERS.items():
        raw=encoded_file(e['reader_sources'][name],'tools/'+name,git)
        require(sha(raw)==checksum,'canonical_reader_source_changed')
        (reader_root/name).write_bytes(raw)
    canonical_input={**e,'ops':ops}
    (tmp/'canonical-input.json').write_text(json.dumps(canonical_input,ensure_ascii=False))
    (tmp/'canonical-read.js').write_text(CANONICAL)
    child=subprocess.run(['node',str(tmp/'canonical-read.js'),str(reader_root),str(tmp/'canonical-input.json')],capture_output=True,timeout=30)
    require(child.returncode==0 and len(child.stdout)<10000,'canonical_installed_proof_failed')
    canonical=json.loads(child.stdout)
    require(canonical['ok'] is True and canonical['repository_sha']==canonical['application_sha']==CURRENT
        and canonical['record_digest']==DIAGNOSTICS['record_digest'] and canonical['write_calls']==0
        and canonical['release_consumer_invoked'] is False and canonical['release_closure_authenticated'] is False,'canonical_scope_changed')
    failed={'schema_version':1,'operation':PREFIX+'-release-closure-status-v1','status':'not_accepted',
        'repository_sha':CURRENT,'application_sha':CURRENT,'release_run_id':DEPLOY,
        'original_closer_run_id':CLOSER,'original_closer_job_id':CLOSER_JOB,'original_closer_conclusion':'failure',
        'preparation':'failure','proof_upload':'skipped','terminal':'skipped','diagnostics':DIAGNOSTICS,
        'phase':'pending_terminal','publisher':{'binding':BINDING,'body':publisher['body'],'updated_at':publisher['updated_at']},
        'recovery_performed':False,'publisher_mutation_authorized':False,'publisher_mutations':0,
        'evidence':{k:e[k] for k in ['closer_run','closer_jobs','closer_artifacts','diagnostics_ref','deploy_status_source','release_publisher']}}
    accepted={'schema_version':1,'operation':PREFIX+'-content-source-acceptance-v1','scope':'existing_authorized_content_push',
        'status':'accepted','repository_sha':CURRENT,'application_sha':CURRENT,'source_verifier_result':prior,
        'source_verifier':{'git_blob_sha':SOURCE_VERIFIER_BLOB,'sha256':SOURCE_VERIFIER_SHA},
        'canonical_readers':{k:{'git_blob_sha':v[0],'sha256':v[1]} for k,v in READERS.items()},
        'canonical_installed_proof':canonical,'publishing_blob_count':22,'publishing_blobs':pins,'content_owner':CONTENT_OWNER,
        'mandatory_source_gate_count':9,'release_closure_status':'not_accepted','release_consumer_invoked':False,
        'release_slot_mutations':0,'evidence':{k:e[k] for k in ['main','verify_run','verify_jobs','tls_run','source_commit','source_tree','gates','content_owner','ops_status_source']}}
    return accepted,failed,gate_receipts

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--evidence-dir',type=Path)
    parser.add_argument('--safe-dir',type=Path)
    parser.add_argument('--private-dir',type=Path)
    args=parser.parse_args()
    require(os.environ['GITHUB_RUN_ATTEMPT']=='1' and os.environ['EXPECTED_PRODUCTION_SHA']==CURRENT,'carrier_context_changed')
    runtime=Path(os.environ['RUNNER_TEMP']);existing=args.evidence_dir or runtime/'commercial-source-evidence';safe=args.safe_dir or runtime/'caption-repair-safe'
    tmp=(args.private_dir or runtime/'caption-repair-private')/'content-source';tmp.mkdir(mode=0o700)
    readers=tmp/'readers';readers.mkdir(mode=0o700)
    e={k:json.loads((existing/(k+'.json')).read_bytes()) for k in ['main','verify_run','verify_jobs','tls_run','source_commit','source_tree']}
    e['gates']={name:json.loads((runtime/f'commercial-source-gate-{rid}.json').read_bytes()) for name,rid in GATES.items()}
    # Actual current Git content is read at one immutable diagnostics commit.
    e['diagnostics_ref']=api(REPO+'/git/ref/heads/diagnostics');commit=e['diagnostics_ref']['object']['sha']
    require(commit==DIAGNOSTICS['commit'],'observed_diagnostics_changed')
    e['deploy_status_source']=api(REPO+'/contents/deploy_status.json?ref='+commit)
    e['ops_status_source']=api(REPO+'/contents/ops_status.json?ref='+commit)
    e['closer_run']=api(REPO+f'/actions/runs/{CLOSER}')
    e['closer_jobs']=api(REPO+f'/actions/runs/{CLOSER}/attempts/1/jobs?per_page=100')
    e['closer_artifacts']=api(REPO+f'/actions/runs/{CLOSER}/artifacts?per_page=100')
    e['content_owner']=api(REPO+'/issues/comments/'+str(CONTENT_OWNER['id']))
    e['release_publisher']=api(REPO+'/issues/comments/'+str(BINDING['comment_id']))
    e['reader_sources']={name:api(REPO+'/contents/tools/'+name+'?ref='+CURRENT) for name in READERS}
    verifier=Path('tools/direct_demand/operations/verify-commercial-source.py')
    accepted,failed,gates=verify(e,verifier,readers,tmp)
    require(api(REPO+'/git/ref/heads/main')['object']['sha']==CURRENT,'main_changed_during_source_read')
    require(api(REPO+'/git/ref/heads/diagnostics')['object']['sha']==commit,'diagnostics_changed_during_source_read')
    def save(name,value):
        raw=(json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode();(safe/name).write_bytes(raw);return sha(raw)
    accepted['source_gate_receipts_sha256']=save('source-gate-receipts.json',gates)
    accepted['release_closure_status_sha256']=save('release-closure-status.json',failed)
    accepted['carrier']={'run_id':int(os.environ['GITHUB_RUN_ID']),'run_attempt':1,'head_sha':os.environ['GITHUB_SHA']}
    save('content-source-acceptance.json',accepted)
    print(json.dumps({'content_source_admission':'accepted','installation':'success','ops':'success',
        'source_gates':9,'publishing_blobs':22,'release_closure':'not_accepted','original_closer':'failure',
        'publisher_mutations':0,'release_consumer_invoked':False}))

if __name__=='__main__': main()
