#!/usr/bin/env node
'use strict';

// Synthetic Git/API/runtime proofs only. Never reads a credential or production.
// This suite is registered once in the architecture lane, independently of the
// existing release_lifecycle_regression entry point.
const assert=require('node:assert/strict');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto');
const {execFileSync,spawnSync}=require('node:child_process');
const R=require('../tools/deployment_release'),C=require('../tools/release_slot_close');
let assertions=0;
function equal(a,b,message){assert.deepEqual(a&&typeof a==='object'?copy(a):a,b&&typeof b==='object'?copy(b):b,message);assertions++;}
async function refuses(fn,code,message){
  let error;try{await fn();}catch(e){error=e;}
  assert.equal(error?.code,code,message||code);assertions++;
}
const copy=R.clone,T='2026-11-06T06:00:00Z',H=x=>crypto.createHash('sha1').update(x).digest('hex');
const D=x=>crypto.createHash('sha256').update(x).digest('hex');
function receipt(sha,run='1000'){
  return {schema:2,sha,run_id:run,install_attempt:1,smoke_attempt:1,diagnostics_attempt:1,reported_at:T,
    ...Object.fromEntries(R.INSTALL_STAGES.map(s=>[s,'success']))};
}
function observation(sha){
  const o={schema:1,protocol:R.FINGERPRINT_PROTOCOL,mode:'all',status:'known',reason_code:null,
    application_sha:sha,core_digest:D('core'),configuration_key_id:D('key'),components:{
      public:{package_id:D('public'),source_sha:sha,backend_fingerprint:D('public-backend'),state_digest:D('public-state')},
      editor:{package_id:D('editor'),source_sha:sha,backend_fingerprint:D('editor-backend'),state_digest:D('editor-state'),
        release_kind:'normal',acceptance:'installed'}},digest:null};
  return sealObservation(o);
}
function sealObservation(o){o.digest=R.digest({schema:1,application_sha:o.application_sha,core_digest:o.core_digest,
  configuration_key_id:o.configuration_key_id,components:o.components});return o;}
function unknown(o,status='unknown',reason='observation_unavailable'){
  return {...copy(o),status,reason_code:reason,core_digest:null,configuration_key_id:null,
    components:{public:null,editor:null},digest:null};
}
function installed(sha,run='1000'){
  return R.releaseRecord(receipt(sha,run),{sha,id:run,attempt:1,event:'push'},
    {content:R.contentSummary(null,sha,sha),intent:{status:'no_registered_intent'}});
}
let stepSerial=1,jobSerial=1;
function step(name,conclusion='success'){
  return {number:stepSerial++,name,status:'completed',conclusion,started_at:T,completed_at:T};
}
function job(name,run,steps=[],conclusion='success'){
  return {id:jobSerial++,name,run_id:run.id,run_attempt:run.run_attempt,head_sha:run.head_sha,
    status:'completed',conclusion,steps};
}
const INSTALL_EXECUTION=['Build exact deployment bundle','Sync canonical production checkout',
  'Apply database migrations and fence the old hotel contour','Reconcile canonical MAX webhook ownership',
  'Ensure Metrika offline uploader cron','Apply profile and retain exact diagnostic snapshot'];
function fullJobs(run,record){
  const rows=C.INSTALL_JOBS.map(name=>job(name,run,name==='deploy'?INSTALL_EXECUTION.map(n=>step(n)):[step('Required source checks')]));
  rows.push(job('verify',run,[...['check','download','status'].map(k=>step(C.VERIFY_STEPS[k])),
    step(R.PENDING_BINDING_STEP+R.digest(record)),step(C.VERIFY_STEPS.cleanup)]));return rows;
}
function storedZip(name,value){
  const text=Buffer.from(R.wire(value)),file=Buffer.from(name),local=Buffer.alloc(30),central=Buffer.alloc(46),end=Buffer.alloc(22);
  let crc=0xffffffff;for(const b of text){crc^=b;for(let k=0;k<8;k++)crc=(crc>>>1)^((crc&1)?0xedb88320:0);}crc=(crc^0xffffffff)>>>0;
  local.writeUInt32LE(0x04034b50,0);local.writeUInt16LE(20,4);local.writeUInt32LE(crc,14);
  local.writeUInt32LE(text.length,18);local.writeUInt32LE(text.length,22);local.writeUInt16LE(file.length,26);
  central.writeUInt32LE(0x02014b50,0);central.writeUInt16LE(20,4);central.writeUInt16LE(20,6);
  central.writeUInt32LE(crc,16);central.writeUInt32LE(text.length,20);central.writeUInt32LE(text.length,24);
  central.writeUInt16LE(file.length,28);
  end.writeUInt32LE(0x06054b50,0);end.writeUInt16LE(1,8);end.writeUInt16LE(1,10);
  end.writeUInt32LE(central.length+file.length,12);end.writeUInt32LE(local.length+file.length+text.length,16);
  return Buffer.concat([local,file,text,central,file,end]);
}
function fixture(source,record=installed(source)){
  const runs=new Map(),jobs=new Map(),records=new Map(),commits=new Map(),trees=new Map(),artifacts=new Map(),archives=new Map();
  const calls=[],privateRoot=[{path:'production-diagnostics',type:'tree',mode:'040000',sha:H('private-tree')},
    {path:'migration.log',type:'blob',mode:'100644',sha:H('migration')},
    {path:'raw.json',type:'blob',mode:'100644',sha:H('raw')},
    {path:'ops_status.json',type:'blob',mode:'100644',sha:H('ops')}];
  let main=source,head,version=1,currentPr,comment,hook=null;
  function putRecord(value,parent=null){
    const bytes=Buffer.from(R.wire(value)),blob=R.gitBlob(bytes),treeSha=H('tree'+version),commit=H('commit'+version++);
    records.set(commit,{value:copy(value),bytes,blob});
    trees.set(treeSha,[{path:'deploy_status.json',mode:'100644',type:'blob',sha:blob},...copy(privateRoot)]);
    commits.set(commit,{sha:commit,tree:{sha:treeSha},parents:parent?[{sha:parent}]:[]});head=commit;return commit;
  }
  putRecord(record);
  function setupWave(sha,deployId,closerId,prNumber){
    main=sha;
    const wave=C.REQUIRED.map(([file,event,name],i)=>({id:deployId+i,path:R.PREFIX+file,event,name,
      head_sha:sha,head_branch:'main',head_repository:{full_name:R.REPOSITORY},run_attempt:1,
      status:'completed',conclusion:'success',created_at:T,run_started_at:T,updated_at:T,
      head_commit:{id:sha,message:'Product release'}}));
    const monitor=wave.find(r=>r.path.endsWith('modeled-conversion-monitor.yml'));
    for(const r of wave.filter(r=>r.event==='workflow_run'))r.display_title=r.path.endsWith(C.ACTIVATION.file)
      ?'monitor-proof:v1:'+monitor.id+':1':'deploy-proof:v1:'+deployId+':1';
    const own={...copy(wave[0]),id:closerId,path:R.CLOSER,event:'workflow_run',name:'Configure AnyTour modeled value goal',
      display_title:'deploy-proof:v1:'+deployId+':1',status:'in_progress',conclusion:null};
    for(const r of [...wave,own])runs.set(r.id,r);
    const marker={comment_id:900,head_sha:H('pr'+prNumber),owner_id:800,updated_at:T};
    currentPr={number:prNumber,state:'closed',merged:true,merge_commit_sha:sha,
      head:{sha:marker.head_sha,repo:{full_name:R.REPOSITORY}},base:{ref:'main',repo:{full_name:R.REPOSITORY}},
      body:'<!-- release-slot-close:v1 '+JSON.stringify(marker)+' -->'};
    comment={id:900,body:'PR'+prNumber+': выпуск занят',user:{id:800},updated_at:T,author_association:'OWNER',
      issue_url:'https://api.github.com'+R.API_ROOT+'/issues/1122'};
    const activation=wave.find(r=>r.path.endsWith(C.ACTIVATION.file));
    jobs.set(activation.id,[job('activate',activation,[],'skipped')]);
    const ownJob=job('release-slot',own,[],null);ownJob.status='in_progress';
    jobs.set(own.id,[job('configure',own,[],'skipped'),ownJob]);
    return {wave,own,parent:wave[0],env:{GITHUB_REPOSITORY:R.REPOSITORY,GITHUB_EVENT_NAME:'workflow_run',
      GITHUB_REF:'refs/heads/main',GITHUB_SHA:sha,GITHUB_RUN_ID:String(closerId),GITHUB_RUN_ATTEMPT:'1',
      GITHUB_JOB:'release-slot',GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.CLOSER+'@refs/heads/main',CONFIGURE_RESULT:'skipped'},
      event:{action:'completed',repository:{full_name:R.REPOSITORY},workflow_run:copy(wave[0])}};
  }
  let wave=setupWave(source,Number(record.run_id),2000,55);
  jobs.set(wave.parent.id,fullJobs(wave.parent,record));
  function addHost(obs){
    const host=wave.wave.find(r=>r.path===R.HOST),bytes=storedZip('runtime-fingerprint.json',obs),artifactId=7000+host.id;
    archives.set(artifactId,bytes);
    artifacts.set(host.id,[{id:artifactId,name:'selection-host-'+host.head_sha,expired:false,size_in_bytes:bytes.length,
      digest:'sha256:'+D(bytes),created_at:T,workflow_run:{id:host.id,head_sha:host.head_sha,head_branch:'main'}}]);
  }
  addHost(observation(source));
  function content(commit){
    const x=records.get(commit);assert.ok(x,'fixture record '+commit);
    return {type:'file',path:R.RECORD_PATH,encoding:'base64',sha:x.blob,size:x.bytes.length,content:x.bytes.toString('base64')};
  }
  function isAncestor(a,b){while(b){if(a===b)return true;b=commits.get(b)?.parents[0]?.sha;}return false;}
  const api=async(method,p,body,raw=false)=>{
    calls.push({method,path:p,body:body===undefined?undefined:copy(body),raw});
    if(hook){const value=await hook({method,path:p,body,raw});if(value!==undefined)return copy(value);}
    if(p===R.API_ROOT+'/git/ref/heads/main')return {object:{sha:main}};
    if(p===R.API_ROOT+'/git/ref/heads/diagnostics')return {object:{sha:head}};
    if(p.startsWith(R.API_ROOT+'/contents/deploy_status.json?ref=')){
      const ref=p.split('ref=')[1],commit=ref==='diagnostics'?head:ref;
      return raw?copy(records.get(commit).value):content(commit);
    }
    if(p===R.API_ROOT+'/contents/ops_status.json?ref=diagnostics')return {sha:records.get(head).value.sha,
      ...Object.fromEntries(R.OPS_STAGES.map(s=>[s,'success']))};
    if(p.startsWith(R.API_ROOT+'/compare/')){
      const [a,b]=p.split('/compare/')[1].split('?')[0].split('...');
      return {status:isAncestor(a,b)?'ahead':'diverged',merge_base_commit:{sha:isAncestor(a,b)?a:H('foreign')}};
    }
    if(p.includes('/git/commits/')&&method==='GET')return copy(commits.get(p.split('/').at(-1)));
    if(p.includes('/git/trees/')&&method==='GET'){
      const sha=p.split('/').at(-1);return {sha,truncated:false,tree:copy(trees.get(sha))};
    }
    if(p===R.API_ROOT+'/git/trees'&&method==='POST'){
      const entries=new Map(trees.get(body.base_tree).map(x=>[x.path,copy(x)]));
      for(const e of body.tree){if(e.sha===null)entries.delete(e.path);else entries.set(e.path,{path:e.path,mode:e.mode,type:e.type,sha:R.gitBlob(Buffer.from(e.content)),content:e.content});}
      const sha=H(JSON.stringify(body));trees.set(sha,[...entries.values()]);return {sha};
    }
    if(p===R.API_ROOT+'/git/commits'&&method==='POST'){
      const commit=H('published'+version++),entry=trees.get(body.tree).find(x=>x.path===R.RECORD_PATH);
      const bytes=Buffer.from(entry.content);records.set(commit,{value:R.parseJson(bytes),bytes,blob:R.gitBlob(bytes)});
      commits.set(commit,{sha:commit,tree:{sha:body.tree},parents:body.parents.map(sha=>({sha}))});return {sha:commit};
    }
    if(p===R.API_ROOT+'/git/refs/heads/diagnostics'&&method==='PATCH'){
      assert.equal(body.force,false);assert.ok(isAncestor(head,body.sha));head=body.sha;return {object:{sha:head}};
    }
    if(p.startsWith(R.API_ROOT+'/commits/')&&p.endsWith('/pulls?per_page=100'))return [copy(currentPr)];
    if(p===R.API_ROOT+'/pulls/'+currentPr.number)return copy(currentPr);
    if(p===R.API_ROOT+'/issues/comments/900'){
      if(method==='PATCH')comment={...comment,body:body.body};return copy(comment);
    }
    if(p.startsWith(R.API_ROOT+'/actions/runs?head_sha=')){
      const sha=p.split('head_sha=')[1].split('&')[0],list=[...runs.values()].filter(r=>r.head_sha===sha);
      return {total_count:list.length,workflow_runs:copy(list)};
    }
    if(p.startsWith(R.API_ROOT+'/actions/workflows/')){
      const file=p.split('/workflows/')[1].split('/')[0],sha=p.split('head_sha=')[1].split('&')[0];
      const list=[...runs.values()].filter(r=>r.head_sha===sha&&r.path===R.PREFIX+file);
      return {total_count:list.length,workflow_runs:copy(list)};
    }
    let m=/\/actions\/runs\/([0-9]+)\/attempts\/([0-9]+)\/jobs\?per_page=100$/.exec(p);
    if(m){assert.equal(Number(m[2]),1);const list=jobs.get(Number(m[1]))||[];return {total_count:list.length,jobs:copy(list)};}
    m=/\/actions\/runs\/([0-9]+)\/artifacts\?per_page=100$/.exec(p);
    if(m){const list=artifacts.get(Number(m[1]))||[];return {total_count:list.length,artifacts:copy(list)};}
    m=/\/actions\/runs\/([0-9]+)$/.exec(p);if(m)return copy(runs.get(Number(m[1])));
    throw new Error('Unexpected fixture request '+method+' '+p);
  };
  api.artifact=async meta=>archives.get(meta.id);
  return {api,calls,runs,jobs,records,commits,trees,artifacts,archives,
    get head(){return head;},get record(){return records.get(head).value;},get wave(){return wave;},
    get comment(){return comment;},set comment(v){comment=v;},get pr(){return currentPr;},set pr(v){currentPr=v;},
    set hook(fn){hook=fn;},set main(v){main=v;},addHost,putRecord,
    next(sha,record,deploy=1100,closer=2100){putRecord(record,head);wave=setupWave(sha,deploy,closer,56);return wave;},
    start(sha,deploy,closer,pr){wave=setupWave(sha,deploy,closer,pr);return wave;},
    options(){return {env:wave.env,event:wave.event,api,sleep:async()=>{throw Error('unexpected waiting');}};},
    bindTerminal(digest){wave.env.PREPARED_RECORD_DIGEST=digest;
      jobs.get(wave.own.id).find(j=>j.name==='release-slot').steps.push(step(R.TERMINAL_BINDING_STEP+digest));},
    complete(){const own=wave.own;own.status='completed';own.conclusion='success';
      const j=jobs.get(own.id).find(j=>j.name==='release-slot');j.status='completed';j.conclusion='success';j.steps.push(step(R.TERMINAL_STEP));},
  };
}

async function pureValidation(){
  const a='a'.repeat(40),o=observation(a);
  equal(R.validateObservation(o),o,'collector canonical digest is reproduced');
  equal(R.compareObservations(o,copy(o)).kind,'application_unchanged','same exact runtime');
  equal(R.compareObservations(o,unknown(o)).kind,'installed','unknown optimization observation takes full path');
  const changed=sealObservation({...copy(o),core_digest:D('changed')});
  equal(R.compareObservations(o,changed).kind,'installed','known config change takes authorized full path');
  await refuses(()=>R.compareObservations(o,unknown(o,'blocked','tracked_checkout_drift')),'runtime_observation_blocked');
  const advanced=copy(o);advanced.components.editor={...advanced.components.editor,source_sha:'b'.repeat(40),
    package_id:D('independent'),state_digest:D('new-state'),release_kind:'independent',acceptance:'independent_activated'};
  sealObservation(advanced);equal(R.compareObservations(o,advanced).editor,'accepted_independent_advance',
    'accepted independent editor may advance without installing backend again');
  for(const mutate of [x=>{x.components.public.package_id=D('foreign');},x=>{x.components.editor.backend_fingerprint=D('foreign');},
    x=>{x.components.editor.release_kind='normal';x.components.editor.acceptance='installed';}]){
    const x=copy(advanced);mutate(x);sealObservation(x);
    await refuses(()=>R.compareObservations(o,x),x.components.public.package_id!==o.components.public.package_id?'public_component_drift':'editor_component_drift');
  }
  const bad=copy(advanced);bad.components.editor.acceptance='installed';sealObservation(bad);
  await refuses(()=>R.validateObservation(bad),'unaccepted_editor_identity');
  await refuses(()=>R.validateObservation({...copy(o),digest:D('wrong')}),'runtime_digest_mismatch');
  const combined=copy(o);combined.core_digest=D('changed configuration');combined.components.public.package_id=D('foreign component');
  sealObservation(combined);await refuses(()=>R.compareObservations(o,combined),'public_component_drift',
    'configuration fallback cannot hide a confirmed component conflict');
  await refuses(()=>R.validateObservation({...unknown(o),core_digest:D('unvalidated')}),'invalid_runtime_observation');
  await refuses(()=>R.validateObservation({...unknown(o,'blocked'),components:copy(o.components)}),'invalid_runtime_observation');
  const payload={schema:1,application_sha:o.application_sha,core_digest:o.core_digest,
    configuration_key_id:o.configuration_key_id,components:o.components};
  const php=spawnSync('php',['-r','require $argv[1]; $v=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo hash("sha256", DeploymentRuntimeFingerprint::encoded($v));',
    path.resolve(__dirname,'../tools/deployment_runtime_fingerprint.php')],{input:JSON.stringify(payload),encoding:'utf8'});
  if(php.error?.code==='ENOENT')process.stdout.write('SKIP PHP/JS canonical digest: native PHP is unavailable locally; required architecture runtime executes it\n');
  else{equal(php.status,0,'native collector canonical encoder executes');equal(php.stdout,o.digest,'PHP collector newline digest equals JS proof digest');}
  await refuses(()=>R.parseJson('{"schema":1,"schema":2}'),'duplicate_json_key');
  await refuses(()=>R.parseJson('{"n":9007199254740992}'),'invalid_number');
  await refuses(()=>R.parseJson(Buffer.from([0xff])),'invalid_json_encoding');
  const record=installed(a),original=R.installationReceipt(record);
  equal(original,receipt(a),'typed extension preserves every original schema-2 field');
  const negative=copy(record);negative.smoke='failure';negative.repository_release.stages.smoke.result='failure';
  equal(R.validateRelease(negative,false).installation.smoke,'failure','writer preserves actual negative verification');
  await refuses(()=>R.validateRelease(negative),'installation_unconfirmed');
  await refuses(()=>R.installationReceipt({...receipt(a),private_payload:'must not publish'}),'invalid_installation_receipt');
  const zip=storedZip('runtime-fingerprint.json',o);
  equal(R.archiveJson(zip,'runtime-fingerprint.json'),R.parseJson(R.wire(o)),'bounded ZIP member reads original observation');
  await refuses(()=>R.archiveJson(storedZip('../runtime-fingerprint.json',o),'runtime-fingerprint.json'),'invalid_artifact_member');
  const corrupt=Buffer.from(zip);corrupt[60]^=1;
  await refuses(()=>R.archiveJson(corrupt,'runtime-fingerprint.json'),'artifact_checksum_mismatch');
  const edits=C.diagnosticsTree([{path:'deploy_status.json',mode:'100644',type:'blob',sha:H('old')},
    {path:'raw.json',mode:'100644',type:'blob',sha:H('raw')},
    {path:'production-diagnostics',mode:'040000',type:'tree',sha:H('capture')},
    {path:'migration.log',mode:'100644',type:'blob',sha:H('migration')},
    {path:'ops_status.json',mode:'100644',type:'blob',sha:H('ops')}],record);
  equal(edits.filter(e=>e.sha===null).map(e=>e.path),['raw.json','production-diagnostics','migration.log'],
    'fixed diagnostics writer keeps original privacy cleanup');
  equal(edits.at(-1).path,'deploy_status.json','only installation result receives public content');
}

async function gitBridge(){
  const dir=fs.mkdtempSync(path.join(os.tmpdir(),'deployment-policy-fixture-'));
  const git=(...args)=>execFileSync('git',['-c','user.name=Regression fixture','-c','user.email=fixture@example.invalid',...args],
    {cwd:dir,encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim();
  try{
    git('init','--quiet');fs.mkdirSync(path.join(dir,'tests'));
    fs.writeFileSync(path.join(dir,'app.php'),'<?php echo "fixture";\n');
    fs.writeFileSync(path.join(dir,'tests/run_purchase_study_runtime.py'),'# original\n');
    git('add','.');git('commit','--quiet','-m','Original installed source');const a=git('rev-parse','HEAD');
    fs.appendFileSync(path.join(dir,'tests/run_purchase_study_runtime.py'),'# development fixture\n');
    git('add','.');git('commit','--quiet','-m','Development fixture');const r=git('rev-parse','HEAD');
    const context={sha:r,id:'1100',attempt:1,event:'push'},event={forced:false,inputs:{}};
    const proposal=R.v2(dir,receipt(a),context,event);
    equal(proposal.decision,'no_install_candidate','adapter calls the existing V2 comparison');
    equal(proposal.skip_installation_authorized,false,'offline proposal never grants live authority');
    equal(R.intentScan(dir,a,r).status,'no_registered_intent','complete known intent range');
    const f=fixture(a);
    const prepared=await C.closeRelease({...f.options(),prepare:true});
    f.bindTerminal(prepared.record_digest);equal(await C.closeRelease(f.options()),'released','normal full release closes');
    f.complete();const baseline=await R.acceptedBaseline(f.api,await R.readRecord(f.api));
    equal(baseline.release.baseline_eligible,true,'first full real release becomes the accepted baseline');
    const nextRun={...context,event:'push'};
    const next=R.releaseRecord(receipt(a),nextRun,{kind:'application_unchanged',baseline:baseline.reference,
      content:R.contentSummary(proposal.comparison,a,r),intent:proposal.comparison.intent_scan,observation:observation(a)});
    f.next(r,next);installNoopJobs(f);
    // The real installation receipt is unchanged. This release performs fresh
    // provenance/key preparation for observation and inherits installation work.
    equal(R.installationReceipt(next),baseline.installation,'unchanged receipt is byte-semantically original');
    equal(next.repository_release.stages.provenance,{execution:'performed',result:'success'},'fresh repository provenance was performed');
    equal(next.repository_release.stages.key,{execution:'performed',result:'success'},'fresh observation credential preparation was performed');
    equal(Object.fromEntries(Object.entries(next.repository_release.stages).filter(([name])=>!['provenance','key'].includes(name))),
      Object.fromEntries(['bundle','sync','migration','max_webhook','metrika_cron','install_checks','smoke','diagnostics']
        .map(name=>[name,{execution:'not_performed',assurance:'inherited'}])),
      'all eight installation actions remain unperformed and inherited');
    equal(next.repository_release.stages.sync,{execution:'not_performed',assurance:'inherited'},'no fictional new sync PASS');
    await consumerCases(f,a,r);
    const monitoring=f.wave.wave.find(x=>x.path.endsWith('modeled-conversion-monitor.yml'));
    const operation=f.jobs.get(monitoring.id)[0].steps.find(s=>s.name==='A future operation');
    operation.conclusion='success';
    await refuses(()=>C.closeRelease({...f.options(),prepare:true}),'unchanged_operation_executed',
      'successful workflow alone cannot conceal an executed no-install operation');operation.conclusion='skipped';
    const out=await C.closeRelease({...f.options(),prepare:true});
    equal(f.record.repository_release.baseline,baseline.reference,'unchanged keeps original terminal proof');
    f.bindTerminal(out.record_digest);
    equal(await C.closeRelease(f.options()),'released','unchanged path closes through the same single publisher');
    equal(f.comment.body,'PR56: приложение без изменений, выпуск свободен','terminal never calls no-install an installation');
    f.complete();
    const result=await resolveSchedule(f);
    equal([result.repository_sha,result.application_sha,result.perform_actions,result.ready],[r,a,true,true],
      'scheduled monitoring resumes against original application after terminal acceptance');
    equal(f.calls.filter(c=>c.method==='PATCH'&&c.path.endsWith('/issues/comments/900')).length,2,
      'two independent waves each make exactly one terminal mutation');
    equal(f.calls.filter(c=>c.path.includes('/actions/artifacts/')).length,0,
      'accepted authority does not depend on expiring publication archive downloads');
    // A second development-only release resolves the original installation from
    // durable Git history; it never treats the first no-install as an installation.
    fs.appendFileSync(path.join(dir,'tests/run_purchase_study_runtime.py'),'# next development fixture\n');
    git('add','.');git('commit','--quiet','-m','Second development fixture');const r2=git('rev-parse','HEAD');
    f.start(r2,1200,2200,57);f.wave.parent.status='in_progress';f.wave.parent.conclusion=null;
    const deployEnv=consumerEnv(f.wave.parent,R.DEPLOY,'push','deploy'),push={repository:{full_name:R.REPOSITORY},
      ref:'refs/heads/main',after:r2,forced:false,inputs:{}};
    const plan=await R.planDeployment({env:deployEnv,event:push,api:f.api,repository:dir});
    equal([plan.plan,plan.application_sha],['observe_unchanged',a],'second release plans against original application');
    equal(plan.baseline,baseline.reference,'second plan reuses the exact original accepted baseline');
    const observe=observation(a);observe.components.editor={...observe.components.editor,
      source_sha:r,package_id:D('independent editor after first release'),state_digest:D('sealed terminal'),
      release_kind:'independent',acceptance:'independent_activated'};sealObservation(observe);
    const admitted=await R.admitUnchanged({env:deployEnv,event:push,api:f.api,repository:dir,plan,observation:observe});
    equal(admitted.kind,'application_unchanged','fresh accepted independent editor does not force backend reinstall');
    equal(admitted.record.repository_release.observation.components.editor,observe.components.editor,
      'current component identities are preserved in each map');
    equal((await R.admitUnchanged({env:deployEnv,event:push,api:f.api,repository:dir,plan,observation:unknown(observe)})).kind,
      'installed','unknown live observation selects ordinary full path');
    await refuses(()=>R.admitUnchanged({env:deployEnv,event:push,api:f.api,repository:dir,plan,
      observation:unknown(observe,'blocked','pending_component_operation')}),'runtime_observation_blocked');
    await refuses(()=>R.admitUnchanged({env:deployEnv,event:push,api:f.api,repository:dir,plan:{...plan,run_id:'999'},observation:observe}),
      'deployment_plan_changed','tampered saved plan never falls back to deployment');
    const published=await C.publishPending({env:{...deployEnv,GITHUB_JOB:'verify'},event:push,api:f.api,
      record:admitted.record,repository:dir});
    equal(published.record_digest,R.digest(admitted.record),'verified inter-job record is bound to original publisher');
    f.wave.parent.status='completed';f.wave.parent.conclusion='success';installNoopJobs(f);
    const second=await C.closeRelease({...f.options(),prepare:true});f.bindTerminal(second.record_digest);
    equal(await C.closeRelease(f.options()),'released','second consecutive unchanged release closes');f.complete();
    equal((await resolveSchedule(f)).application_sha,a,'second accepted map still monitors original application');
    const editorRun={...copy(f.wave.parent),id:6000,path:R.HOST,event:'workflow_dispatch',status:'in_progress',conclusion:null};
    f.runs.set(editorRun.id,editorRun);
    const editor=await R.resolveEditor({env:consumerEnv(editorRun,R.HOST,'workflow_dispatch','independent-editor'),
      event:{repository:{full_name:R.REPOSITORY}},api:f.api,repositorySha:r2,applicationSha:a});
    equal(editor.installation_receipt,receipt(a),'independent editor gets actual installation and separate repository authority');
    equal(f.calls.filter(c=>c.method==='PATCH'&&c.path.endsWith('/issues/comments/900')).length,3,
      'two no-installs add no extra terminal mutations');
  }finally{fs.rmSync(dir,{recursive:true,force:true});}
}
function installNoopJobs(f){
  const parent=f.wave.parent,record=f.record;
  const rows=C.INSTALL_JOBS.map(name=>job(name,parent,name==='deploy'?
    [step('Select installation or prove the unchanged application without mutation'),...INSTALL_EXECUTION.map(n=>step(n,'skipped'))]:[step('Required source checks')]));
  rows.push(job('verify',parent,[step('Publish the proven unchanged result without installed stages'),
    step(R.PENDING_BINDING_STEP+R.digest(record)),...['check','download','status','cleanup'].map(k=>step(C.VERIFY_STEPS[k],'skipped'))]));
  f.jobs.set(parent.id,rows);
  const names={'live-session-diagnostics.yml':'publish','max-production-health.yml':'inspect','max-tls-preflight.yml':'strict-tls-preflight',
    'modeled-conversion-monitor.yml':'monitor','publish-conversation-diagnostics.yml':'publish','telegram-production-health.yml':'inspect',
    'tour-selection-host.yml':'host'};
  for(const [file,name]of Object.entries(names)){
    const run=f.wave.wave.find(r=>r.path===R.PREFIX+file);
    f.jobs.set(run.id,[job(name,run,[step(R.RESOLVER_STEP),step('Prepare SSH private key','skipped'),step('A future operation','skipped')])]);
    if(file==='tour-selection-host.yml')f.jobs.get(run.id).push(job('independent-editor',run,[],'skipped'));
  }
}
function consumerEnv(run,workflow,event,jobName){
  return {GITHUB_REPOSITORY:R.REPOSITORY,GITHUB_EVENT_NAME:event,GITHUB_REF:'refs/heads/main',GITHUB_SHA:run.head_sha,
    GITHUB_RUN_ID:String(run.id),GITHUB_RUN_ATTEMPT:'1',GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@refs/heads/main',GITHUB_JOB:jobName};
}
async function resolveSchedule(f,eventName='schedule'){
  const workflow=R.PREFIX+'modeled-conversion-monitor.yml';
  const run={...copy(f.wave.parent),id:5000,path:workflow,event:eventName,status:'in_progress',conclusion:null};
  f.runs.set(run.id,run);
  const event={repository:{full_name:R.REPOSITORY},schedule:'2-59/5 * * * *'};
  const env=consumerEnv(run,workflow,eventName,'monitor');
  return R.resolveConsumer({env,event,api:f.api});
}
async function consumerCases(f,a,r){
  const monitor=f.wave.wave.find(x=>x.path.endsWith('modeled-conversion-monitor.yml'));
  const saved=copy(monitor);monitor.status='in_progress';monitor.conclusion=null;
  const resolved=await R.resolveConsumer({env:consumerEnv(monitor,monitor.path,'workflow_run','monitor'),
    event:{repository:{full_name:R.REPOSITORY},action:'completed',workflow_run:copy(f.wave.parent)},api:f.api});
  equal(resolved,{repository_sha:r,application_sha:a,kind:'application_unchanged',perform_actions:false,ready:true},
    'original direct workflow proves a no-op before loading credentials');Object.assign(monitor,saved);
  equal((await resolveSchedule(f)).ready,false,'identified active closer defers schedule without a false monitoring receipt');
  await refuses(()=>resolveSchedule(f,'workflow_dispatch'),'release_pending','manual operation is never a green deferred no-op');
  // A legacy-looking schema 2 naming a real no-install Deploy is not proof of an
  // installation, even if all hand-written stages say success.
  await refuses(()=>R.deploymentProof(f.api,receipt(r,String(f.wave.parent.id))),'installation_not_performed');
  const closeRun=f.wave.own;closeRun.status='completed';closeRun.conclusion='failure';
  await refuses(()=>resolveSchedule(f),'producer_not_active','failed closer cannot suppress monitoring as active work');
  closeRun.status='in_progress';closeRun.conclusion=null;
}

async function failureBoundaries(){
  const a='a'.repeat(40);
  let f=fixture(a),out=await C.closeRelease({...f.options(),prepare:true});
  f.wave.env.PREPARED_RECORD_DIGEST=out.record_digest;
  equal(f.calls.some(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')),false,'prepare does not release the slot');
  await refuses(()=>C.closeRelease(f.options()),'publication_step_unconfirmed','missing upload prevents terminal mutation');
  equal(f.calls.some(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')),false,'failed publication retains slot');
  f.bindTerminal(out.record_digest);f.comment={...f.comment,body:'PR55: установлено, выпуск свободен'};
  await refuses(()=>C.closeRelease(f.options()),'prepared_terminal_already_released','foreign/pre-existing terminal is not original proof');
  f=fixture(a);out=await C.closeRelease({...f.options(),prepare:true});f.bindTerminal(out.record_digest);f.pr={...f.pr,body:''};
  await refuses(()=>C.closeRelease(f.options()),'prepared_binding_removed','removed binding cannot produce a success terminal step');
  f=fixture(a);out=await C.closeRelease({...f.options(),prepare:true});f.bindTerminal(out.record_digest);
  f.putRecord(receipt(a),f.head);f.pr={...f.pr,body:''};
  await refuses(()=>C.closeRelease(f.options()),'prepared_result_changed','mutable legacy overwrite cannot turn a prepared proof into successful no-op');
  f=fixture(a);out=await C.closeRelease({...f.options(),prepare:true});f.bindTerminal(out.record_digest);
  let statusReads=0;f.hook=({path:p,raw})=>{
    if(raw&&p.includes('/contents/deploy_status.json')&&++statusReads===2){
      f.comment={...f.comment,body:'PR55: установлено, выпуск свободен'};return receipt(a);
    }
  };
  await refuses(()=>C.closeRelease(f.options()),'prepared_result_changed','second read cannot downgrade an exact prepared invocation');
  equal(f.calls.filter(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')).length,0,
    'second-read downgrade cannot create an accepted historical terminal proof');
  f=fixture(a);out=await C.closeRelease({...f.options(),prepare:true});f.bindTerminal(D('different-record'));
  f.wave.env.PREPARED_RECORD_DIGEST=out.record_digest;
  await refuses(()=>C.closeRelease(f.options()),'publication_step_unconfirmed','wrong upload digest cannot adopt a new result');
  f=fixture(a);f.addHost(unknown(observation(a)));out=await C.closeRelease({...f.options(),prepare:true});
  equal(f.record.repository_release.baseline_eligible,false,'unknown optimization evidence preserves ordinary release but not baseline');
  f.bindTerminal(out.record_digest);equal(await C.closeRelease(f.options()),'released','unknown does not replay or hold a successful install');
  f=fixture(a);f.addHost(unknown(observation(a),'blocked','tracked_checkout_drift'));
  await refuses(()=>C.closeRelease({...f.options(),prepare:true}),'installed_runtime_contradiction','known drift holds release');
  equal(f.calls.filter(c=>c.method!=='GET').length,0,'blocked observation creates neither map nor terminal');
  f=fixture(a);f.record.repository_release.intent_scan={status:'deployment_intent_present'};
  // Update the synthetic source publication with its changed authenticated digest.
  f.putRecord(f.record);f.jobs.set(f.wave.parent.id,fullJobs(f.wave.parent,f.record));
  out=await C.closeRelease({...f.options(),prepare:true});
  equal(f.record.repository_release.baseline_eligible,false,'marker/configuration release cannot seed a later skip');
  f=fixture(a);f.hook=({method,path:p})=>{if(method==='PATCH'&&p.endsWith('/git/refs/heads/diagnostics'))throw new Error('lost response');};
  await refuses(()=>C.closeRelease({...f.options(),prepare:true}),'diagnostics_write_unknown');
  equal(f.calls.filter(c=>c.method==='PATCH'&&c.path.endsWith('/git/refs/heads/diagnostics')).length,1,'unknown CAS write is never retried');
  equal(f.calls.some(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')),false,'unknown map write cannot release slot');
  f=fixture(a);out=await C.closeRelease({...f.options(),prepare:true});f.bindTerminal(out.record_digest);
  let afterProof=false;f.hook=({path:p})=>{
    if(p.includes('/actions/runs/2000/attempts/1/jobs?'))afterProof=true;
    if(afterProof&&p.endsWith('/git/ref/heads/main'))return {object:{sha:'b'.repeat(40)}};
  };
  await refuses(()=>C.closeRelease(f.options()),'main_changed','late source change is checked after slow proof reads');
  equal(f.calls.some(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')),false,'late source change retains slot');
  f=fixture(a);f.wave.parent.run_attempt=2;f.wave.parent.status='in_progress';f.wave.parent.conclusion=null;
  const env={...consumerEnv(f.wave.parent,R.DEPLOY,'push','verify'),GITHUB_RUN_ATTEMPT:'2'};
  const raw=C.stageStatus({...env,SMOKE_ATTEMPT:'2',DIAGNOSTICS_ATTEMPT:'2',CHECK_OUTCOME:'success',DOWNLOAD_OUTCOME:'success'});
  const wrapped=await R.wrapInstalled({env,event:{repository:{full_name:R.REPOSITORY},ref:'refs/heads/main',after:a,forced:false},api:f.api,installation:raw});
  equal(wrapped.repository_release.intent_scan.status,'unknown','rewritten recovery cannot shrink an original marker DAG into R..R');
}
async function transportBoundaries(){
  const api=R.makeReadApi('fixture',async()=>{throw new Error('secret transport details');});
  await refuses(()=>api('PATCH',R.API_ROOT+'/issues/comments/900',{body:'x'}),'invalid_read_request');
  await refuses(()=>api('GET','/repos/foreign/repository/actions/runs/1'),'invalid_read_request');
  await refuses(()=>api('GET',R.API_ROOT+'/actions/runs/1'),'transport_failed');
  const writer=C.makeApi('fixture',async()=>{throw Error('not reached');});
  await refuses(()=>writer('PATCH',R.API_ROOT+'/git/refs/heads/main',{sha:'a'.repeat(40),force:false}),'invalid_write_target');
  await refuses(()=>writer('PATCH',R.API_ROOT+'/git/refs/heads/diagnostics',{sha:'a'.repeat(40),force:true}),'invalid_write_target');
  await refuses(()=>writer('POST',R.API_ROOT+'/actions/workflows/deploy.yml/dispatches',{ref:'main'}),'invalid_write_target');
}
async function main(){await pureValidation();await gitBridge();await failureBoundaries();await transportBoundaries();
  process.stdout.write('Deployment release regression: '+assertions+' assertions passed\n');}
module.exports={main,fixture,receipt,observation,installed,installNoopJobs,storedZip};
if(require.main===module)main().catch(e=>{process.stderr.write(String(e.stack||e)+'\n');process.exitCode=1;});
