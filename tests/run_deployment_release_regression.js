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
  return error;
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
    await connectionSetupConsumer(f, a, a);
    await pilotPublisherConsumer(f, a, a);
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
    for(const [file,cleanupName]of [
      ['publish-conversation-diagnostics.yml','Remove private capture files'],
      ['modeled-conversion-monitor.yml','Remove private ephemeral monitor data'],
    ]){
      const run=f.wave.wave.find(x=>x.path.endsWith(file)),ownJob=f.jobs.get(run.id)[0];
      const cleanup=ownJob.steps.find(s=>s.name===cleanupName);
      cleanup.conclusion='failure';
      await refuses(()=>C.closeRelease({...f.options(),prepare:true}),'noop_resolution_unconfirmed',
        'failed local cleanup cannot close an unchanged release');cleanup.conclusion='success';
      ownJob.conclusion='failure';
      await refuses(()=>C.closeRelease({...f.options(),prepare:true}),'noop_job_unconfirmed',
        'failed cleanup job cannot close an unchanged release');ownJob.conclusion='success';
      const other=f.wave.wave.find(x=>x.path.endsWith('max-production-health.yml'));
      const foreignSteps=f.jobs.get(other.id)[0].steps;foreignSteps.push(step(cleanupName));
      await refuses(()=>C.closeRelease({...f.options(),prepare:true}),'unchanged_operation_executed',
        'a local cleanup name grants no operation authority in another workflow');foreignSteps.pop();
      const ownName=ownJob.name;ownJob.name='unrecognized-cleanup-job';
      await refuses(()=>C.closeRelease({...f.options(),prepare:true}),'noop_job_unconfirmed',
        'a permitted cleanup still requires the exact known job');ownJob.name=ownName;
    }
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
    await connectionSetupConsumer(f, r, a);
    await pilotPublisherConsumer(f, r, a);
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
    const steps=[step(R.RESOLVER_STEP),step('Prepare SSH private key','skipped'),step('A future operation','skipped')];
    if(file==='publish-conversation-diagnostics.yml')steps.push(step('Remove private capture files'));
    if(file==='modeled-conversion-monitor.yml')steps.push(step('Remove private ephemeral monitor data'));
    f.jobs.set(run.id,[job(name,run,steps)]);
    if(file==='tour-selection-host.yml')f.jobs.get(run.id).push(job('independent-editor',run,[],'skipped'));
  }
}
function consumerEnv(run,workflow,event,jobName){
  return {GITHUB_REPOSITORY:R.REPOSITORY,GITHUB_EVENT_NAME:event,GITHUB_REF:'refs/heads/main',GITHUB_SHA:run.head_sha,
    GITHUB_RUN_ID:String(run.id),GITHUB_RUN_ATTEMPT:'1',GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@refs/heads/main',GITHUB_JOB:jobName};
}
async function connectionSetupConsumer(f,repositorySha,applicationSha){
  const workflow=R.PREFIX+'setup-appleservice39-connection.yml';
  const run={...copy(f.wave.parent),id:6500,path:workflow,event:'workflow_dispatch',status:'in_progress',conclusion:null};
  f.runs.set(run.id,run);
  const event={repository:{full_name:R.REPOSITORY}};
  const env=consumerEnv(run,workflow,'workflow_dispatch','setup');
  const before=f.calls.length;
  const result=await R.resolveConsumer({env,event,api:f.api});
  equal([result.repository_sha,result.application_sha,result.perform_actions,result.ready],
    [repositorySha,applicationSha,true,true],'fixed manual repair setup resolves the accepted application');
  equal(f.calls.slice(before).every(c=>c.method==='GET'),true,'setup admission remains read-only');
  run.event='push';
  await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_EVENT_NAME:'push'},
    event:{...event,ref:'refs/heads/main',after:repositorySha},api:f.api}),'untrusted_push_consumer',
    'repair setup never gains automatic push authority');
  run.event='workflow_dispatch';run.path=R.PREFIX+'unknown-connection-setup.yml';
  await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+run.path+'@refs/heads/main'},event,api:f.api}),
    'untrusted_consumer_event','allowing this setup does not allow arbitrary manual operations');
  f.runs.delete(run.id);
}
async function pilotPublisherConsumer(f,repositorySha,applicationSha){
  const workflow=R.PREFIX+'publish-appleservice39-pilot.yml';
  const run={...copy(f.wave.parent),id:6501,path:workflow,event:'workflow_dispatch',status:'in_progress',conclusion:null};
  f.runs.set(run.id,run);
  const event={repository:{full_name:R.REPOSITORY}};
  const env=consumerEnv(run,workflow,'workflow_dispatch','publish');
  const before=f.calls.length;
  const result=await R.resolveConsumer({env,event,api:f.api});
  equal([result.repository_sha,result.application_sha,result.perform_actions,result.ready],
    [repositorySha,applicationSha,true,true],'fixed manual pilot publisher resolves the accepted actual application');
  equal(f.calls.slice(before).every(c=>c.method==='GET'),true,'pilot publisher admission is read-only before credentials');
  run.run_attempt=2;
  await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_RUN_ATTEMPT:'2'},event,api:f.api}),
    'consumer_rerun_forbidden','pilot workflow reruns gain no publication authority');
  run.run_attempt=1;run.event='push';
  await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_EVENT_NAME:'push'},
    event:{...event,ref:'refs/heads/main',after:repositorySha},api:f.api}),
    'untrusted_push_consumer','fixed pilot permission never adds automatic push publication');
  run.event='schedule';
  await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_EVENT_NAME:'schedule'},event:{...event,schedule:'0 * * * *'},api:f.api}),
    'untrusted_consumer_event','fixed pilot permission never adds scheduled publication');
  run.event='workflow_dispatch';run.path=R.PREFIX+'arbitrary-pilot-publisher.yml';
  await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+run.path+'@refs/heads/main'},event,api:f.api}),
    'untrusted_consumer_event','fixed pilot permission does not admit arbitrary workflows');
  run.path=workflow;f.main='f'.repeat(40);
  await refuses(()=>R.resolveConsumer({env,event,api:f.api}),'main_changed','pilot admission still binds current source');
  f.main=repositorySha;run.status='completed';run.conclusion='success';
  await refuses(()=>R.resolveConsumer({env,event,api:f.api}),'workflow_not_active','historical pilot runs grant no new authority');
  f.runs.delete(run.id);
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

function metadataConsumer({legacy=false,event='workflow_run',parentEvent='push'}={}){
  const a='a'.repeat(40),f=fixture(a,legacy?receipt(a):installed(a));
  f.wave.parent.event=parentEvent;
  const workflow=R.PREFIX+(event==='schedule'?'modeled-conversion-monitor.yml':'max-production-health.yml');
  const run=f.wave.wave.find(x=>x.path===workflow);run.status='in_progress';run.conclusion=null;run.event=event;
  let clock=0,onSleep=null;const delays=[];
  const options={api:f.api,env:consumerEnv(run,workflow,event,'inspect'),
    event:{repository:{full_name:R.REPOSITORY},action:'completed',workflow_run:copy(f.wave.parent),schedule:'2-59/5 * * * *'},
    now:()=>clock,sleep:async ms=>{delays.push(ms);clock+=ms;if(onSleep)await onSleep(delays.length);}};
  return {f,options,delays,resolve:()=>R.resolveConsumer(options),
    set onSleep(value){onSleep=value;},set clock(value){clock=value;},
    get reads(){return f.calls.filter(c=>c.path.endsWith('/attempts/1/jobs?per_page=100')).length;},
    job:name=>f.jobs.get(f.wave.parent.id).find(j=>j.name===name),
    pending(name=INSTALL_EXECUTION.at(-1),jobName='deploy'){
      const row=f.jobs.get(f.wave.parent.id).find(j=>j.name===jobName).steps.find(s=>s.name===name);
      row.status='in_progress';row.conclusion=null;row.completed_at=null;return row;
    }};
}
function completeMetadata(row){row.status='completed';row.conclusion='success';row.completed_at=T;}
async function metadataWaitCases(){
  let h=metadataConsumer(),result=await h.resolve();
  equal(result,{repository_sha:'a'.repeat(40),application_sha:'a'.repeat(40),kind:'installed',perform_actions:true,ready:true},
    'confirmed original installation resolves without changing authority');
  equal(h.delays,[],'consistent metadata never waits');equal(h.reads,3,'existing two proofs and publication read stay unchanged');
  equal(h.f.calls.every(c=>c.method==='GET'),true,'original consumer remains read-only before credentials');

  for(const legacy of [false,true]){
    h=metadataConsumer({legacy});const row=h.pending();h.onSleep=()=>completeMetadata(row);
    equal((await h.resolve()).ready,true,'same original '+(legacy?'legacy':'versioned')+' installation can settle metadata');
    equal(h.delays,[10000],'one pending observation causes exactly one bounded wait');
    equal(h.reads,legacy?2:4,'settlement adds only one proof snapshot');
    equal(h.f.calls.every(c=>c.method==='GET'),true,'settlement performs no provider action or state mutation');
  }

  h=metadataConsumer();let snapshots=0;
  h.f.hook=({path:p})=>{
    if(!p.endsWith('/attempts/1/jobs?per_page=100'))return;
    const jobs=copy(h.f.jobs.get(h.f.wave.parent.id));snapshots++;
    if([1,3].includes(snapshots))Object.assign(jobs.find(j=>j.name==='deploy').steps.at(-1),{status:'in_progress',conclusion:null});
    return {total_count:jobs.length,jobs};
  };
  equal((await h.resolve()).ready,true,'both original proof readers share metadata grace');
  equal(h.delays,[10000,10000],'two proof readers share at most two waits');equal(h.reads,5,'two waits add exactly two snapshots');

  h=metadataConsumer();snapshots=0;
  h.f.hook=({path:p})=>{
    if(!p.endsWith('/attempts/1/jobs?per_page=100'))return;
    const jobs=copy(h.f.jobs.get(h.f.wave.parent.id));snapshots++;
    if([1,2,4].includes(snapshots))Object.assign(jobs.find(j=>j.name==='deploy').steps.at(-1),{status:'in_progress',conclusion:null});
    return {total_count:jobs.length,jobs};
  };
  await refuses(()=>h.resolve(),'deployment_metadata_deadline','nested publication cannot reset the reread budget');
  equal(h.delays,[10000,10000],'exhausted shared budget never waits a third time');equal(h.reads,4,'no third extra snapshot after exhaustion');

  h=metadataConsumer();snapshots=0;
  h.f.hook=({path:p})=>{
    if(!p.endsWith('/attempts/1/jobs?per_page=100'))return;
    const jobs=copy(h.f.jobs.get(h.f.wave.parent.id));snapshots++;
    if([1,3].includes(snapshots))Object.assign(jobs.find(j=>j.name==='deploy').steps.at(-1),{status:'in_progress',conclusion:null});
    if(snapshots===2)h.clock=25000;
    return {total_count:jobs.length,jobs};
  };
  await refuses(()=>h.resolve(),'deployment_metadata_deadline','second proof also keeps the original clock deadline');
  equal(h.delays,[10000],'remaining five seconds cannot admit another ten-second wait');

  h=metadataConsumer();h.pending();
  await refuses(()=>h.resolve(),'deployment_metadata_deadline','permanently pending steps stop after bounded observation');
  equal(h.delays,[10000,10000],'permanent pending cannot poll indefinitely');equal(h.reads,3,'permanent pending reads at most three snapshots');

  const invalid=[
    ['failed step',x=>x.job('deploy').steps.at(-1).conclusion='failure','installation_not_performed'],
    ['skipped step',x=>x.job('deploy').steps.at(-1).conclusion='skipped','installation_not_performed'],
    ['cancelled step',x=>x.job('deploy').steps.at(-1).conclusion='cancelled','incomplete_job_steps'],
    ['missing step',x=>x.job('deploy').steps.pop(),'incomplete_job_steps'],
    ['duplicate step',x=>x.job('deploy').steps.push(copy(x.job('deploy').steps.at(-1))),'incomplete_job_steps'],
    ['unknown status',x=>Object.assign(x.job('deploy').steps.at(-1),{status:'waiting',conclusion:null}),'incomplete_job_steps'],
    ['queued status',x=>Object.assign(x.job('deploy').steps.at(-1),{status:'queued',conclusion:null}),'incomplete_job_steps'],
    ['contradictory status',x=>Object.assign(x.job('deploy').steps.at(-1),{status:'in_progress',conclusion:'success'}),'incomplete_job_steps'],
    ['failed verification',x=>x.job('verify').steps[0].conclusion='failure','verification_not_performed'],
    ['missing verification',x=>x.job('verify').steps.shift(),'incomplete_job_steps'],
    ['missing step array',x=>x.job('verify').steps=null,'incomplete_job_steps'],
    ['pending job',x=>Object.assign(x.job('verify'),{status:'in_progress',conclusion:null}),'installation_job_unconfirmed'],
    ['failed job',x=>x.job('verify').conclusion='failure','installation_job_unconfirmed'],
    ['foreign job SHA',x=>x.job('verify').head_sha='b'.repeat(40),'installation_job_unconfirmed'],
    ['duplicate job name',x=>x.f.jobs.get(x.f.wave.parent.id).push({...copy(x.job('verify')),id:99999}),'installation_job_unconfirmed'],
    ['duplicate job id',x=>x.job('verify').id=x.job('deploy').id,'incomplete_installation_jobs'],
  ];
  for(const [name,change,code]of invalid){
    h=metadataConsumer();h.pending(INSTALL_EXECUTION[0]);change(h);
    const error=await refuses(()=>h.resolve(),code,'pending never masks '+name);
    equal(error instanceof R.Refusal,true,'known '+name+' refusal keeps a safe local reason');
    equal(h.delays,[],name+' fails before any wait');equal(h.reads,1,name+' is never retried');
  }

  const drift=[
    ['main',x=>{x.f.main='b'.repeat(40);},'main_changed'],
    ['attempt',x=>{x.f.wave.parent.run_attempt=2;},'deployment_metadata_changed'],
    ['run SHA',x=>{x.f.wave.parent.head_sha='b'.repeat(40);},'untrusted_workflow_run'],
    ['run event',x=>{x.f.wave.parent.event='workflow_dispatch';},'deployment_metadata_changed'],
    ['run failure',x=>{x.f.wave.parent.conclusion='failure';},'producer_not_successful'],
    ['job identity',x=>{x.job('deploy').id+=10000;},'deployment_metadata_changed'],
    ['result record',x=>{const record=copy(x.f.record);record.reported_at='2026-11-06T06:00:01Z';x.f.putRecord(record);},'deployment_result_changed'],
  ];
  for(const [name,change,code]of drift){
    h=metadataConsumer();const row=h.pending();h.onSleep=()=>{completeMetadata(row);change(h);};
    await refuses(()=>h.resolve(),code,'metadata settlement cannot adopt changed '+name);
    equal(h.delays,[10000],name+' drift never requests another wait');
    equal(h.f.calls.every(c=>c.method==='GET'),true,name+' drift cannot authorize a mutation');
  }

  for(const code of ['api_http_403','api_http_429','api_http_500','transport_failed','invalid_json']){
    h=metadataConsumer();h.pending();snapshots=0;
    h.f.hook=({path:p})=>{if(p.endsWith('/attempts/1/jobs?per_page=100')&&++snapshots===2)throw new R.Refusal(code);};
    await refuses(()=>h.resolve(),code,'API failure is not pending metadata: '+code);
    equal(h.delays,[10000],code+' stops on the first API error');equal(h.reads,2,code+' response is not retried');
  }
  h=metadataConsumer();const foreign=Object.assign(new Error('SYNTHETIC_PRIVATE_DETAIL'),{code:'SYNTHETIC_PRIVATE_CODE'});
  h.f.hook=({path:p})=>{if(p.endsWith('/attempts/1/jobs?per_page=100'))throw foreign;};
  const unexpected=await refuses(()=>h.resolve(),foreign.code);
  equal(unexpected instanceof R.Refusal,false,'foreign API errors do not acquire a printable trusted reason');equal(h.delays,[],'foreign errors do not retry');

  for(const options of [{legacy:true,event:'workflow_dispatch'},{legacy:true,event:'schedule'},{legacy:true,parentEvent:'workflow_dispatch'}]){
    h=metadataConsumer(options);h.pending();
    await refuses(()=>h.resolve(),'incomplete_job_steps','manual/scheduled authority has no metadata grace');
    equal(h.delays,[],'metadata grace is reserved for the original push consumer');equal(h.reads,1,'no other consumer repeats a snapshot');
  }
  for(const options of [{completed:false},{admitting:true}]){
    h=metadataConsumer();h.pending();
    const error=await refuses(()=>R.deploymentProof(h.f.api,receipt('a'.repeat(40)),options),'incomplete_job_steps');
    equal(error instanceof R.Refusal,true,'shared step refusal is normalized without granting admission grace');equal(h.reads,1,'admission does not await metadata');
  }

  h=metadataConsumer();const pending=h.pending();h.onSleep=()=>{completeMetadata(pending);h.clock=29990;};
  let signal;
  const base=h.options.api;
  h.options.api=(method,p,body,raw,abort)=>{
    if(h.delays.length&&p.endsWith('/git/ref/heads/main')){signal=abort;return new Promise(()=>{});}
    return base(method,p,body,raw);
  };
  await refuses(()=>h.resolve(),'deployment_metadata_deadline','an unsettled API read is bounded by the remaining consumer deadline');
  equal(signal?.aborted,true,'expired metadata read receives cancellation');equal(h.delays,[10000],'deadline expiry never retries an unsettled API read');
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
function scheduledActivation(f) {
  const monitor=f.wave.wave.find(r=>r.path.endsWith('modeled-conversion-monitor.yml'));
  const original=f.wave.wave.find(r=>r.path.endsWith(C.ACTIVATION.file));
  const parent={...copy(monitor),id:5000,event:'schedule',display_title:'Scheduled monitoring'};
  const child={...copy(original),id:5001,display_title:'monitor-proof:v1:5000:1',conclusion:'skipped'};
  f.runs.set(parent.id,parent);f.runs.set(child.id,child);
  f.jobs.set(child.id,[job('activate',child,[],'skipped')]);
  return {parent,child,original,job:f.jobs.get(child.id)[0]};
}
async function scheduledActivationCases(){
  const a='a'.repeat(40);
  const read=async f=>{
    const snapshot=await f.api('GET',R.API_ROOT+'/actions/runs?head_sha='+a+'&per_page=100');
    const c=C.context(f.options().env,f.options().event);
    return C.selectReleaseRuns(snapshot,c,p=>f.api('GET',R.API_ROOT+p));
  };
  let f=fixture(a),extra=scheduledActivation(f),out=await read(f);
  equal(out.waiting,false,'authenticated scheduled activation has no pending operation');
  equal(C.gateState(out.runs,C.context(f.options().env,f.options().event)),'ready',
    'same-source scheduled child cannot replace or collide with the original activation');
  equal(C.gateReferences(out.runs).some(r=>r.run_id===String(extra.child.id)),false,
    'scheduled child is not recorded as release proof');
  equal(f.calls.every(c=>c.method==='GET'),true,'classification is metadata-only');
  f.runs.delete(extra.original.id);out=await read(f);
  equal(C.gateState(out.runs,C.context(f.options().env,f.options().event)),'waiting',
    'an early scheduled child cannot satisfy a missing original activation');
  f.runs.set(extra.original.id,extra.original);
  // Keep the parent available to the exact-ID fixture endpoint, outside the list.
  f=fixture(a);extra=scheduledActivation(f);
  const safeParent=copy(extra.parent);f.runs.delete(extra.parent.id);
  f.hook=({path:p})=>p.endsWith('/actions/runs/5000')?safeParent:undefined;
  out=await read(f);equal(out.waiting,false,'exact-ID fallback proves the original scheduled parent');

  f=fixture(a);extra=scheduledActivation(f);
  f.jobs.get(extra.original.id)[0].conclusion='failure';
  await refuses(()=>C.closeRelease({...f.options(),prepare:true}),'activation_unconfirmed',
    'a scheduled skip cannot conceal the failed original activation job');
  equal(f.calls.some(c=>c.method!=='GET'),false,'failed original activation retains publication and publisher');

  f=fixture(a);extra=scheduledActivation(f);
  const prepared=await C.closeRelease({...f.options(),prepare:true});f.bindTerminal(prepared.record_digest);
  equal(await C.closeRelease(f.options()),'released','the ordinary two-phase closer finishes after every original gate');
  equal(f.calls.filter(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')).length,1,
    'scheduled-child classification grants no extra terminal write');
  equal(f.record.repository_release.closure.gates.some(r=>r.run_id==='5001'),false,
    'published terminal retains original deployment provenance');

  // A scheduled consumer may fail because this release is still pending.
  // Its zero-step child is unrelated to the original successful release gates.
  f=fixture(a);extra=scheduledActivation(f);extra.parent.conclusion='failure';
  out=await read(f);
  equal(C.gateState(out.runs,C.context(f.options().env,f.options().event)),'ready',
    'a failed scheduled monitor does not turn its proved skipped child into a release gate');
  equal(out.runs.workflow_runs.find(r=>r.id===extra.parent.id).conclusion,'failure',
    'the independent monitor failure is retained, not relabeled as success');
  const failedSchedulePrepared=await C.closeRelease({...f.options(),prepare:true});
  f.bindTerminal(failedSchedulePrepared.record_digest);
  equal(await C.closeRelease(f.options()),'released','original mandatory gates can close despite an independent schedule failure');
  equal(f.calls.filter(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')).length,1,
    'failed scheduled parent grants only the original single terminal write');
  equal(f.record.repository_release.closure.gates.some(r=>['5000','5001'].includes(r.run_id)),false,
    'neither the failed schedule nor its skipped child becomes accepted release proof');

  f=fixture(a);extra=scheduledActivation(f);extra.parent.conclusion='failure';
  f.wave.wave.find(r=>r.path.endsWith('modeled-conversion-monitor.yml')).conclusion='failure';
  await refuses(()=>C.closeRelease({...f.options(),prepare:true}),'required_gate_failed',
    'a failed original monitor remains a mandatory failure');
  equal(f.calls.some(c=>c.method!=='GET'),false,'failed original monitor retains publication and publisher');

  f=fixture(a);extra=scheduledActivation(f);
  Object.assign(extra.child,{status:'in_progress',conclusion:null});
  let clock=0,sleeps=0;
  const waited=await C.closeRelease({...f.options(),prepare:true,now:()=>clock,sleep:async ms=>{
    equal(ms,20000,'independent pending child uses the existing observation interval');
    clock+=ms;sleeps++;Object.assign(extra.child,{status:'completed',conclusion:'skipped'});
  }});
  equal(sleeps,1,'a pending independent activation is awaited before publication');
  equal(typeof waited.record_digest,'string','only the settled exact skip permits preparation');

  f=fixture(a);extra=scheduledActivation(f);
  Object.assign(extra.child,{status:'queued',conclusion:null});clock=0;sleeps=0;
  const independentDeadline=await refuses(()=>C.closeRelease({...f.options(),prepare:true,now:()=>clock,sleep:async ms=>{
    clock+=ms;sleeps++;
  }}),'gate_deadline','pending unrelated activation cannot be silently ignored');
  equal(independentDeadline.pending,[{workflow:C.ACTIVATION.file,status:'queued',run_id:'5001',
    origin:'scheduled_monitor',parent_run_id:'5000'}],'deadline names the exact pending independent child');
  equal(clock,18*60*1000,'independent waiting shares the existing finite deadline');
  equal(f.calls.some(c=>c.method!=='GET'),false,'deadline retains diagnostics and publisher without a write');

  f=fixture(a);extra=scheduledActivation(f);extra.parent.conclusion='failure';
  f.runs.delete(extra.original.id);
  const tls=f.wave.wave.find(r=>r.path.endsWith('max-tls-preflight.yml'));
  Object.assign(tls,{status:'in_progress',conclusion:null});clock=0;
  const missingDeadline=await refuses(()=>C.closeRelease({...f.options(),prepare:true,now:()=>clock,
    sleep:async ms=>{clock+=ms;}}),'gate_deadline','a scheduled skip never substitutes for a late original activation');
  equal(missingDeadline.pending,[{workflow:'max-tls-preflight.yml',status:'in_progress',run_id:String(tls.id)},
    {workflow:C.ACTIVATION.file,status:'missing'}],'deadline distinguishes an existing pending gate from a missing original run');
  equal(clock,18*60*1000,'missing original proof does not extend the wait or dispatch another run');
  equal(f.calls.some(c=>c.method!=='GET'),false,'late original activation retains the untouched publisher');

  const invalid=[
    ['parent fork',x=>x.parent.head_repository.full_name='other/repository','untrusted_run'],
    ['child fork',x=>x.child.head_repository.full_name='other/repository','untrusted_run'],
    ['parent source',x=>x.parent.head_sha='b'.repeat(40),'untrusted_run'],
    ['child rerun',x=>x.child.run_attempt=2,'untrusted_run'],
    ['parent rerun',x=>x.parent.run_attempt=2,'untrusted_run'],
    ['parent commit',x=>x.parent.head_commit.id='b'.repeat(40),'independent_monitor_unconfirmed'],
    ['cancelled parent',x=>x.parent.conclusion='cancelled','independent_monitor_unconfirmed'],
    ['unknown parent',x=>x.parent.conclusion=null,'independent_monitor_unconfirmed'],
    ['pending parent',x=>{x.parent.status='in_progress';x.parent.conclusion=null;},'independent_monitor_unconfirmed'],
    ['failed child',x=>x.child.conclusion='failure','independent_activation_unconfirmed'],
    ['executed activation',x=>x.job.steps.push(step('Protected operation')),'independent_activation_executed'],
    ['successful job',x=>x.job.conclusion='success','independent_activation_executed'],
    ['unknown job steps',x=>x.job.steps=null,'independent_activation_executed'],
    ['foreign job',x=>x.job.run_id=9999,'independent_activation_executed'],
    ['foreign job source',x=>x.job.head_sha='b'.repeat(40),'independent_activation_executed'],
    ['job rerun',x=>x.job.run_attempt=2,'independent_activation_executed'],
    ['missing job',(_x,f)=>f.jobs.set(5001,[]),'incomplete_independent_activation_jobs'],
    ['extra job',(x,f)=>f.jobs.get(5001).push(copy(x.job)),'incomplete_independent_activation_jobs'],
    ['duplicate child',(x,f)=>f.runs.set(5002,{...copy(x.child),id:5002}),'ambiguous_activation_origin'],
    ['manual parent',x=>x.parent.event='workflow_dispatch','ambiguous_run'],
  ];
  for(const conclusion of ['success','failure'])for(const [label,mutate,reason]of invalid){
    f=fixture(a);extra=scheduledActivation(f);extra.parent.conclusion=conclusion;mutate(extra,f);
    await refuses(()=>C.closeRelease({...f.options(),prepare:true}),reason,label);
    equal(f.calls.some(c=>c.method!=='GET'),false,label+' retains the publisher and publication');
  }
  f=fixture(a);extra=scheduledActivation(f);
  f.hook=({path:p})=>p.includes('/5001/attempts/1/jobs')?{total_count:2,jobs:[copy(extra.job)]}:undefined;
  await refuses(()=>C.closeRelease({...f.options(),prepare:true}),'incomplete_independent_activation_jobs',
    'partial independent job pagination is not an explicit skip');
  f=fixture(a);extra=scheduledActivation(f);
  f.hook=({path:p})=>{if(p.includes('/5001/attempts/1/jobs'))throw new R.Refusal('rate_limited');};
  await refuses(()=>C.closeRelease({...f.options(),prepare:true}),'rate_limited','API refusal is not retried or ignored');
  equal(f.calls.filter(c=>c.path.includes('/5001/attempts/1/jobs')).length,1,'one failed read is terminal');

  f=fixture(a);extra=scheduledActivation(f);
  const proposal=await C.closeRelease({...f.options(),prepare:true});f.bindTerminal(proposal.record_digest);
  extra.job.steps.push(step('Unexpected late operation'));
  await refuses(()=>C.closeRelease(f.options()),'independent_activation_executed',
    'a fresh independent-child proof is required before the terminal mutation');
  equal(f.calls.some(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')),false,
    'late contradictory execution retains the same admitted publisher');
}


async function publishedStatusReadbackCases(){
  const a='a'.repeat(40),ref=R.API_ROOT+'/git/refs/heads/diagnostics';
  const recordFor=f=>({...copy(f.record),reported_at:'2026-11-06T06:00:01Z'});
  for(const mode of ['transport','invalid_response','descendant']){
    const f=fixture(a),record=recordFor(f),original=copy(record),base=f.api;
    let patches=0;
    const api=async(method,p,body,raw=false)=>{
      const result=await base(method,p,body,raw);
      if(method==='PATCH'&&p===ref){
        patches++;
        if(mode==='descendant')f.putRecord(record,f.head);
        throw new R.Refusal(mode==='invalid_response'?'invalid_response':'transport_failed');
      }
      return result;
    };
    const out=await C.publishDiagnostics(api,record);
    equal(out.record,original,'readback preserves the exact status after '+mode);
    equal(R.installationReceipt(out.record),R.installationReceipt(original),
      'readback never recreates an installation receipt after '+mode);
    equal(patches,1,'one uncertain PATCH after '+mode);
    equal(f.calls.filter(x=>x.method==='POST').length,2,'no replacement tree/commit after '+mode);
    equal(f.calls.filter(x=>x.method==='PATCH').length,1,'no second mutation after '+mode);
    equal(f.comment.body,'PR55: выпуск занят','readback does not release the publisher');
  }
  for(const [mode,reason] of [
    ['not_applied','diagnostics_write_unknown'],
    ['unrelated','unpublished_diagnostics_proof'],
    ['overwritten','diagnostics_write_unknown'],
    ['read_unavailable','transport_failed'],
    ['ancestry_unavailable','transport_failed'],
    ['wrong_success','diagnostics_write_unknown'],
    ['programmer_refusal','diagnostics_write_unknown'],
    ...[403,409,429].map(code=>['api_http_'+code,'diagnostics_write_unknown']),
  ]){
    const f=fixture(a),record=recordFor(f),base=f.api;
    let patches=0,after=false,reads=0;
    const api=async(method,p,body,raw=false)=>{
      if(after&&method==='GET'){
        reads++;
        if(mode==='read_unavailable'||(mode==='ancestry_unavailable'&&p.includes('/compare/')))
          throw new R.Refusal('transport_failed');
      }
      if(method!=='PATCH'||p!==ref)return base(method,p,body,raw);
      patches++;after=true;
      if(mode==='not_applied')throw new R.Refusal('transport_failed');
      if(mode.startsWith('api_http_'))throw new R.Refusal(mode);
      if(mode==='programmer_refusal')throw new R.Refusal('invalid_write_target');
      const result=await base(method,p,body,raw);
      if(mode==='wrong_success')return {object:{sha:'b'.repeat(40)}};
      if(mode==='unrelated')f.putRecord(record);
      if(mode==='overwritten')f.putRecord({...record,reported_at:'2026-11-06T06:00:02Z'},f.head);
      if(mode==='ancestry_unavailable')f.putRecord(record,f.head);
      throw new R.Refusal('transport_failed');
    };
    await refuses(()=>C.publishDiagnostics(api,record),reason,'uncertain status holds: '+mode);
    equal(patches,1,'failed publication never retries PATCH: '+mode);
    equal(f.calls.filter(x=>x.method==='POST').length,2,'failed publication never recreates status: '+mode);
    equal(f.calls.some(x=>x.method==='PATCH'&&x.path.includes('/issues/comments/')),false,
      'failed publication retains the publisher: '+mode);
    if(mode.startsWith('api_http_')||['wrong_success','programmer_refusal'].includes(mode))
      equal(reads,0,'explicit contradiction/refusal is final: '+mode);
  }
  {
    const f=fixture(a),record=recordFor(f),before=await R.readRecord(f.api);
    await refuses(()=>C.publishDiagnostics(f.api,record,{...before,blob:H('changed')}),
      'diagnostics_compare_conflict','an expected-state conflict is checked before writing');
    equal(f.calls.some(x=>x.method!=='GET'),false,'compare conflict performs no mutation');
  }
  {
    const f=fixture(a),record=recordFor(f),base=f.api;
    const writer=C.makeApi('fixture',async(url,options)=>{
      const parsed=new URL(url),route=parsed.pathname+parsed.search;
      const body=options.body===undefined?undefined:JSON.parse(options.body);
      const result=await base(options.method,route,body);
      if(options.method==='PATCH'&&route===ref)
        throw Error('private transport detail after the committed ref update');
      return {ok:true,status:200,arrayBuffer:async()=>Buffer.from(JSON.stringify(result))};
    });
    const out=await C.publishDiagnostics(writer,record);
    equal(out.record,record,'the real API adapter reconciles a committed transport failure');
    equal(f.calls.filter(x=>x.method==='PATCH').length,1,'the real API adapter never resends');
  }
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
async function main(){await pureValidation();await gitBridge();await metadataWaitCases();await failureBoundaries();await scheduledActivationCases();await publishedStatusReadbackCases();await transportBoundaries();
  process.stdout.write('Deployment release regression: '+assertions+' assertions passed\n');}
module.exports={main,fixture,receipt,observation,installed,installNoopJobs,storedZip};
if(require.main===module)main().catch(e=>{process.stderr.write(String(e.stack||e)+'\n');process.exitCode=1;});

