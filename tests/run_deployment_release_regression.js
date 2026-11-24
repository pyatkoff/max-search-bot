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
    if(p.startsWith(R.API_ROOT+'/commits/')&&!p.includes('/pulls')){const sha=p.split('/').at(-1);return copy(commits.get(sha)||{sha,parents:[{sha:H('unrelated source parent')}]});}
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
    get head(){return head;},set head(v){assert.ok(records.has(v)&&commits.has(v));head=v;},get record(){return records.get(head).value;},get wave(){return wave;},
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
    await commonValueStateConsumer(f, a, a);
    await publicationOperationConsumer(f, a, a);
    await hotelRegistryReadConsumer(f, a, a);
    await max2HoldPairReadConsumer(f, a, a);
    await hotelFourTransferConsumer(f, a, a);
    await hotelMaxTransferConsumer(f, a, a);
    await remainingTransferOriginalProof();
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
    await commonValueStateConsumer(f, r, a);
    await publicationOperationConsumer(f, r, a);
    await hotelRegistryReadConsumer(f, r, a);
    await max2HoldPairReadConsumer(f, r, a);
    await hotelFourTransferConsumer(f, r, a);
    await hotelMaxTransferConsumer(f, r, a);
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
async function commonValueStateConsumer(f,repositorySha,applicationSha){
  const workflow=R.PREFIX+'max2-common-value-state-v2.yml';
  const run={...copy(f.wave.parent),id:6503,path:workflow,event:'workflow_dispatch',status:'in_progress',conclusion:null};
  f.runs.set(run.id,run);
  const event={repository:{full_name:R.REPOSITORY}};
  const env=consumerEnv(run,workflow,'workflow_dispatch','state');
  const before=f.calls.length;
  const result=await R.resolveConsumer({env,event,api:f.api});
  equal([result.repository_sha,result.application_sha,result.perform_actions,result.ready],
    [repositorySha,applicationSha,true,true],'new finite MAX2 state carrier resolves the actual accepted application');
  equal(f.calls.slice(before).every(c=>c.method==='GET'),true,'MAX2 state admission has no mutation or provider operation');
  run.run_attempt=2;
  await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_RUN_ATTEMPT:'2'},event,api:f.api}),
    'consumer_rerun_forbidden','MAX2 state rerun grants no mutation authority');
  run.run_attempt=1;run.event='push';
  await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_EVENT_NAME:'push'},
    event:{...event,ref:'refs/heads/main',after:repositorySha},api:f.api}),
    'untrusted_push_consumer','MAX2 state registration does not add automatic push execution');
  run.event='workflow_run';
  await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_EVENT_NAME:'workflow_run'},event,api:f.api}),
    'untrusted_consumer_event','MAX2 state registration does not add a deployment tail');
  run.event='schedule';
  await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_EVENT_NAME:'schedule'},event:{...event,schedule:'0 * * * *'},api:f.api}),
    'untrusted_consumer_event','MAX2 state registration does not add a schedule');
  run.event='workflow_dispatch';run.path=R.PREFIX+'arbitrary-max2-state.yml';
  await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+run.path+'@refs/heads/main'},event,api:f.api}),
    'untrusted_consumer_event','MAX2 state registration never admits another workflow');
  run.path=workflow;f.main='f'.repeat(40);
  await refuses(()=>R.resolveConsumer({env,event,api:f.api}),'main_changed','MAX2 state still requires current main');
  f.main=repositorySha;run.status='completed';run.conclusion='success';
  await refuses(()=>R.resolveConsumer({env,event,api:f.api}),'workflow_not_active','completed MAX2 state run grants no new operation');
  f.runs.delete(run.id);
}
function hotelRegistryReadWorkflow(){
  const workflow=fs.readFileSync(path.join(__dirname,'../.github/workflows/read-1566-main-hotel-registry.yml'),'utf8');
  const embedded=/          cat > pub\/read-only\.php <<'PHP'\n([\s\S]*?)\n          PHP\n/.exec(workflow);
  equal(Boolean(embedded),true,'the fixed reader remains reviewable in its existing workflow');
  const program=embedded[1].replace(/^          /gm,''),main=program.indexOf("\n$out = ['schema'");
  equal(main>0,true,'pure reader declarations are separate from production execution');
  equal([...program.matchAll(/^\s+(\d+) => \['name' => '[^']+', 'source' => '(\d+)', 'target' => '(\d+)'/gm)]
    .map(m=>m.slice(1)),[['17595','455','862'],['358','65','328'],['393','85','1083'],['1260','548','288']],
    'exactly the four unconfirmed source and target pairs can be observed');
  const admission=workflow.indexOf('node tools/deployment_release.js resolve-consumer'),
    marker=workflow.indexOf('test "$READ_OPERATION" = hotel_four_registry'),secret=workflow.indexOf('$'+'{{ secrets.');
  equal(admission>=0&&marker>admission&&secret>marker
    &&workflow.includes('test "$READY" = true')&&workflow.includes('test "$PERFORM_ACTIONS" = true')
    &&workflow.includes('test "$(git rev-parse HEAD)" = "$GITHUB_SHA"')
    &&!workflow.includes('continue-on-error:'),true,'canonical admission and exact checkout precede every SSH credential');
  const sql=[...program.matchAll(/\$pdo->(?:prepare|query|exec)\(\s*(['"])([\s\S]*?)\1/g)].map(m=>m[2]);
  equal(sql.length>=4&&sql.every(q=>/^SELECT\b/.test(q)||q==='SET SESSION TRANSACTION READ ONLY')
    &&program.includes("$pdo->exec('SET SESSION TRANSACTION READ ONLY');")&&program.includes('$pdo->rollBack();')
    &&!/\b(?:commit|registerPublication|registerObservation|claimPublication|pendingPublications|markExternalWriteStarted|finishPublication|sendPhoto|sendMessage|editMessage\w*|file_put_contents|fwrite|mkdir)\s*\(/.test(program),
    true,'the reader cannot write publications, queue work, journal files or committed database state');
  const lint=spawnSync('php',['-l'],{input:program,encoding:'utf8'});
  if(lint.error?.code==='ENOENT'){
    process.stdout.write('SKIP fixed reader PHP fixtures: native PHP unavailable locally; required architecture runtime executes them\n');
    return;
  }
  equal(lint.status,0,'the exact embedded reader is valid PHP: '+lint.stderr);
  // Execute the actual pure parser over synthetic HTML. Production main is not
  // loaded, and native curl/exec/file writers are disabled for this child process.
  const fixture=String.raw`$source=stream_get_contents(STDIN);
eval(preg_replace('/\A<\?php\s*declare\(strict_types=1\);/','',$source));
$checks=0;
function checkFixture(bool $condition,string $why):void{global $checks;if(!$condition)throw new RuntimeException($why);$checks++;}
$photo='https://cdn4.cdn-telegram.org/file/synthetic-hotel-photo.jpg';
$avatar='https://cdn4.cdn-telegram.org/file/synthetic-channel-avatar.jpg';
function widgetFixture(string $channel,string $message,string $photo,string $avatar):string{
  $chat=$channel==='anytour'?'1845835645':'2262666616';
  return '<!doctype html><html><head><meta charset="utf-8"></head><body><div data-post="'.$channel.'/'.$message.'">'
    .'<a class="tgme_widget_message_user_photo" href="https://t.me/'.$channel.'"><img src="'.$avatar.'"></a>'
    .'<div class="tgme_widget_message_text"><b>Отель</b><br>7 ночей &amp; море</div>'
    .'<a class="tgme_widget_message_photo_wrap '.$chat.'_'.$message.'" href="https://t.me/'.$channel.'/'.$message
    .'" style="background-image:url('.$photo.')"></a><time datetime="2024-09-19T07:42:11Z"></time>'
    .'<a class="tgme_widget_message_inline_button" href="https://t.me/anyru_bot?start=p_'.str_repeat('A',24).'">Рассчитать</a>'
    .'</div></body></html>';
}
foreach([['anytour','862'],['any_toour','455']] as [$channel,$message]){
  $base=widgetFixture($channel,$message,$photo,$avatar);$read=parsePublicRead($base,$channel,$message);
  checkFixture($read['single_photo']&&$read['photo_url']===$photo&&$read['photo_url']!==$avatar,'hotel photo, not channel avatar, is extracted');
  checkFixture($read['caption']==="Отель\n7 ночей & море",'native DOM preserves Unicode, line breaks and entities');
  checkFixture($read['buttons'][0]['hotel_url']==='https://t.me/anyru_bot?start=p_'.str_repeat('A',24),'public hotel button remains exact');
}
$base=widgetFixture('anytour','862',$photo,$avatar);
foreach([
  ['foreign CDN',str_replace($photo,'https://cdn-telegram.org.attacker.invalid/image.jpg',$base)],
  ['credentials in image URL',str_replace($photo,'https://user@cdn4.cdn-telegram.org/image.jpg',$base)],
  ['explicit image port',str_replace($photo,'https://cdn4.cdn-telegram.org:8443/image.jpg',$base)],
  ['neighboring photo',str_replace('href="https://t.me/anytour/862"','href="https://t.me/anytour/863"',$base)],
  ['missing native photo marker',str_replace('1845835645_862','unbound',$base)],
  ['avatar only',preg_replace('~<a class="tgme_widget_message_photo_wrap[^>]*></a>~','',$base)],
  ['video',str_replace('</div></body>','<video></video></div></body>',$base)],
  ['album',str_replace('</div></body>','<div class="tgme_widget_message_grouped"></div></div></body>',$base)],
] as [$reason,$html]){
  checkFixture(parsePublicRead($html,'anytour','862')['single_photo']===false,$reason.' cannot prove a single native photo');
}
$foreignButton=str_replace('https://t.me/anyru_bot?start=','https://t.me/other_bot?start=',$base);
checkFixture(parsePublicRead($foreignButton,'anytour','862')['buttons'][0]['hotel_url']===null,'a foreign bot is not an expected hotel button');
foreach([['other','862'],['anytour','1678'],['any_toour','862']] as [$channel,$message]){
  $reason='';try{publicRead($channel,$message);}catch(RuntimeException $e){$reason=$e->getMessage();}
  checkFixture($reason==='public_scope_invalid','wrong channel/message is rejected before any native HTTP function');
}
$wrong=str_replace('data-post="anytour/862"','data-post="anytour/863"',$base);$reason='';
try{parsePublicRead($wrong,'anytour','862');}catch(RuntimeException $e){$reason=$e->getMessage();}
checkFixture($reason==='public_identity_changed','a neighboring returned widget cannot stand in for the target');
echo json_encode(['checks'=>$checks],JSON_THROW_ON_ERROR);
`;
  const parsed=spawnSync('php',['-d','disable_functions=curl_init,curl_exec,exec,file_put_contents,fwrite','-r',fixture],
    {input:program.slice(0,main),encoding:'utf8'});
  equal(parsed.status,0,'the exact pure parser handles synthetic widgets with native HTTP disabled: '+parsed.stderr);
  equal(JSON.parse(parsed.stdout).checks>=19,true,'photo fixtures cover source/target identity, CDN, avatar, media and unsafe URLs');
}
async function hotelRegistryReadConsumer(f,repositorySha,applicationSha){
  const branch='ops/anytour-hotel-four-read-20261123',ref='refs/heads/'+branch;
  const workflow=R.PREFIX+'read-1566-main-hotel-registry.yml',owner={id:226193297,login:'pyatkoff'};
  const run={...copy(f.wave.parent),id:6505,path:workflow,event:'push',head_branch:branch,
    status:'in_progress',conclusion:null,actor:copy(owner),triggering_actor:copy(owner)};
  f.runs.set(run.id,run);
  const env={...consumerEnv(run,workflow,'push','registry'),GITHUB_REF:ref,
    GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@'+ref,GITHUB_ACTOR:owner.login,GITHUB_TRIGGERING_ACTOR:owner.login};
  const event={repository:{full_name:R.REPOSITORY,id:1342493152},sender:copy(owner),ref,
    after:repositorySha,before:'0'.repeat(40),created:true,deleted:false,forced:false};
  const inventoryPath=R.API_ROOT+'/actions/workflows/read-1566-main-hotel-registry.yml/runs?branch='
    +encodeURIComponent(branch)+'&per_page=100';
  let inventory=[run],total=null,branchHead=repositorySha,mainReads=0,advanceMain=false,branchReads=0,advanceBranch=false;
  f.hook=({path})=>{
    if(path===inventoryPath)return {total_count:total??inventory.length,workflow_runs:inventory};
    if(path===R.API_ROOT+'/git/ref/heads/'+branch)return {object:{sha:advanceBranch&&++branchReads>1?H('moved read branch'):branchHead}};
    if(path===R.API_ROOT+'/git/ref/heads/main'&&advanceMain&&++mainReads>1)return {object:{sha:H('new main after observation admission')}};
  };
  const resolve=()=>R.resolveConsumer({env,event,api:f.api});
  try{
    const before=f.calls.length,result=await resolve();
    equal([result.repository_sha,result.application_sha,result.ready,result.perform_actions,result.read_operation],
      [repositorySha,applicationSha,true,true,'hotel_four_registry'],
      'the first fixed read resolves the actual canonical application, including an unchanged application');
    equal(result.write_operation,undefined,'the read-only branch never grants a hotel transfer');
    equal(f.calls.slice(before).every(c=>c.method==='GET'),true,'read admission uses native observation only before SSH');
    const rejectedBeforeApi=f.calls.length;
    for(const [label,patch] of [['existing branch',{created:false}],['noninitial before',{before:H('old read source')}],
      ['forced',{forced:true}],['deleted',{deleted:true}],['wrong event source',{after:H('different source')}],
      ['main event',{ref:'refs/heads/main'}],['foreign repository',{repository:{full_name:R.REPOSITORY,id:1}}],
      ['foreign sender',{sender:{id:1,login:owner.login}}]]){
      await refuses(()=>R.resolveConsumer({env,event:{...event,...patch},api:f.api}),
        'untrusted_hotel_registry_read','fixed observation rejects '+label);
    }
    for(const [label,patch] of [['attempt2',{GITHUB_RUN_ATTEMPT:'2'}],['other job',{GITHUB_JOB:'publish'}],
      ['manual dispatch',{GITHUB_EVENT_NAME:'workflow_dispatch'}],['foreign actor',{GITHUB_ACTOR:'other'}],
      ['foreign triggering actor',{GITHUB_TRIGGERING_ACTOR:'other'}],
      ['foreign workflow',{GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.PREFIX+'other.yml@'+ref}]]){
      await refuses(()=>R.resolveConsumer({env:{...env,...patch},event,api:f.api}),
        'untrusted_hotel_registry_read','fixed observation refuses '+label);
    }
    equal(f.calls.length,rejectedBeforeApi,'invalid fixed read envelopes fail before any native or private access');
    await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_REF:'refs/heads/ops/another-hotel-read'},event,api:f.api}),
      'untrusted_context','the new read does not admit arbitrary operation branches');
    for(const [label,patch,code] of [['different source',{head_sha:H('foreign checkout')},'untrusted_workflow_run'],
      ['different branch',{head_branch:'main'},'untrusted_workflow_run'],
      ['different workflow',{path:R.PREFIX+'other.yml'},'untrusted_workflow_run'],
      ['native rerun',{run_attempt:2},'untrusted_workflow_run'],
      ['finished run',{status:'completed',conclusion:'success'},'workflow_not_active'],
      ['foreign native actor',{actor:{id:1,login:owner.login}},'foreign_hotel_registry_read_actor'],
      ['foreign native triggering actor',{triggering_actor:{id:1,login:owner.login}},'foreign_hotel_registry_read_actor']]){
      const original=copy(run);Object.assign(run,patch);
      await refuses(resolve,code,'fixed read authenticates '+label);Object.assign(run,original);
    }
    total=2;
    await refuses(resolve,'hotel_registry_read_inventory_incomplete','a partial history cannot prove a first read');total=null;
    inventory=[run,{...copy(run),id:run.id+1,status:'completed',conclusion:'success'}];
    await refuses(resolve,'hotel_registry_read_replay_forbidden','delete/recreate cannot replay the fixed read');
    inventory=[{...copy(run),id:run.id+1}];
    await refuses(resolve,'hotel_registry_read_replay_forbidden','another native run cannot stand in for the current read');
    inventory=[run];branchHead=H('updated operation branch');
    await refuses(resolve,'hotel_registry_read_branch_changed','the current fixed branch must still name the native source');
    branchHead=repositorySha;advanceBranch=true;branchReads=0;
    await refuses(resolve,'hotel_registry_read_branch_changed','moving the read branch during provenance resolution fails closed');
    advanceBranch=false;f.main=H('new main before admission');
    await refuses(resolve,'main_changed','an old accepted checkout does not admit a new read');f.main=repositorySha;
    advanceMain=true;mainReads=0;
    await refuses(resolve,'main_changed','fresh main is checked again after canonical resolution');advanceMain=false;
    const closer=f.wave.own,oldStatus=closer.status,oldConclusion=closer.conclusion;
    closer.status='in_progress';closer.conclusion=null;
    await refuses(resolve,'release_pending','a source still waiting for its original terminal cannot reach SSH');
    closer.status=oldStatus;closer.conclusion=oldConclusion;
  }finally{f.hook=null;f.runs.delete(run.id);f.main=repositorySha;}
}
async function max2HoldPairReadConsumer(f,repositorySha,applicationSha){
  const startAssertions=assertions,branch='ops/max2-hold-pair-read-20261123',ref='refs/heads/'+branch;
  const workflow=R.PREFIX+'max2-hold-pair-read.yml',owner={id:226193297,login:'pyatkoff'};
  const run={...copy(f.wave.parent),id:6506,path:workflow,event:'push',head_branch:branch,
    status:'in_progress',conclusion:null,actor:copy(owner),triggering_actor:copy(owner)};
  const own=job('diagnose',run,[],null);own.status='in_progress';own.runner_id=123;
  f.runs.set(run.id,run);f.jobs.set(run.id,[own]);
  const env={...consumerEnv(run,workflow,'push','diagnose'),GITHUB_REF:ref,
    GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@'+ref,GITHUB_ACTOR:owner.login,GITHUB_TRIGGERING_ACTOR:owner.login};
  const event={repository:{full_name:R.REPOSITORY,id:1342493152},sender:copy(owner),ref,
    after:repositorySha,before:'0'.repeat(40),created:true,deleted:false,forced:false};
  const inventoryPath=R.API_ROOT+'/actions/workflows/max2-hold-pair-read.yml/runs?branch='
    +encodeURIComponent(branch)+'&per_page=100',jobsPath=R.API_ROOT+'/actions/runs/'+run.id+'/attempts/1/jobs?per_page=100';
  let inventory=[run],total=null,jobsTotal=null,branchHead=repositorySha;
  let mainReads=0,advanceMain=false,branchReads=0,advanceBranch=false;
  f.hook=({path})=>{
    if(path===inventoryPath)return {total_count:total??inventory.length,workflow_runs:inventory};
    if(path===jobsPath&&jobsTotal!==null)return {total_count:jobsTotal,jobs:f.jobs.get(run.id)};
    if(path===R.API_ROOT+'/git/ref/heads/'+branch)return {object:{sha:advanceBranch&&++branchReads>1?H('moved MAX2 read branch'):branchHead}};
    if(path===R.API_ROOT+'/git/ref/heads/main'&&advanceMain&&++mainReads>1)return {object:{sha:H('new main after MAX2 admission')}};
  };
  const resolve=()=>R.resolveConsumer({env,event,api:f.api});
  try{
    const before=f.calls.length,result=await resolve();
    equal([result.repository_sha,result.application_sha,result.ready,result.perform_actions,result.read_operation],
      [repositorySha,applicationSha,true,true,'max2_hold_pair_20261123'],
      'the original MAX2 pair read resolves the accepted application, including application_unchanged');
    equal(result.write_operation,undefined,'MAX2 observation never grants a write operation');
    equal(f.calls.slice(before).every(c=>c.method==='GET'),true,'MAX2 admission reads evidence without mutation');
    run.status='queued';
    equal((await resolve()).read_operation,'max2_hold_pair_20261123',
      'a lagging run summary is admitted only with its exact assigned active native job');run.status='in_progress';
    const rejectedBeforeApi=f.calls.length;
    for(const [label,patch] of [['existing branch',{created:false}],['noninitial before',{before:H('old MAX2 read source')}],
      ['forced',{forced:true}],['deleted',{deleted:true}],['wrong event source',{after:H('different source')}],
      ['main event',{ref:'refs/heads/main'}],['foreign repository',{repository:{full_name:R.REPOSITORY,id:1}}],
      ['foreign sender',{sender:{id:1,login:owner.login}}]]){
      await refuses(()=>R.resolveConsumer({env,event:{...event,...patch},api:f.api}),
        'untrusted_max2_hold_pair_read','MAX2 observation rejects '+label);
    }
    for(const [label,patch] of [['attempt2',{GITHUB_RUN_ATTEMPT:'2'}],['other job',{GITHUB_JOB:'registry'}],
      ['manual dispatch',{GITHUB_EVENT_NAME:'workflow_dispatch'}],['scheduled run',{GITHUB_EVENT_NAME:'schedule'}],
      ['deployment tail',{GITHUB_EVENT_NAME:'workflow_run'}],['foreign actor',{GITHUB_ACTOR:'other'}],
      ['foreign triggering actor',{GITHUB_TRIGGERING_ACTOR:'other'}],['foreign repository',{GITHUB_REPOSITORY:'other/repository'}],
      ['hotel workflow',{GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.PREFIX+'read-1566-main-hotel-registry.yml@'+ref}],
      ['hotel writer',{GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.PREFIX+'edit-1566-historical-telegram-with-channel-default.yml@'+ref}]]){
      await refuses(()=>R.resolveConsumer({env:{...env,...patch},event,api:f.api}),
        'untrusted_max2_hold_pair_read','MAX2 observation refuses '+label);
    }
    equal(f.calls.length,rejectedBeforeApi,'invalid MAX2 envelopes fail before native metadata or private access');
    await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_REF:'refs/heads/ops/another-max2-read'},event,api:f.api}),
      'untrusted_context','the fixed read never admits an arbitrary operation branch');
    for(const eventName of ['push','workflow_dispatch','workflow_run','schedule']){
      const original=copy(run);run.head_branch='main';run.event=eventName;
      await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_REF:'refs/heads/main',GITHUB_EVENT_NAME:eventName,
        GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@refs/heads/main'},
        event:{...event,ref:'refs/heads/main',schedule:'0 * * * *'},api:f.api}),
        eventName==='push'?'untrusted_push_consumer':'untrusted_consumer_event',
        'the fixed MAX2 registration does not admit '+eventName+' on main');Object.assign(run,original);
    }
    for(const [label,patch,code] of [['different source',{head_sha:H('foreign checkout')},'untrusted_workflow_run'],
      ['different branch',{head_branch:'main'},'untrusted_workflow_run'],
      ['different workflow',{path:R.PREFIX+'other.yml'},'untrusted_workflow_run'],
      ['native rerun',{run_attempt:2},'untrusted_workflow_run'],
      ['finished run',{status:'completed',conclusion:'success'},'workflow_not_active'],
      ['foreign native actor',{actor:{id:1,login:owner.login}},'foreign_max2_hold_pair_read_actor'],
      ['foreign native triggering actor',{triggering_actor:{id:1,login:owner.login}},'foreign_max2_hold_pair_read_actor']]){
      const original=copy(run);Object.assign(run,patch);
      await refuses(resolve,code,'MAX2 read authenticates '+label);Object.assign(run,original);
    }
    for(const [label,patch] of [['other job',{name:'registry'}],['other run',{run_id:run.id+1}],
      ['other source',{head_sha:H('foreign job source')}],['second attempt',{run_attempt:2}],
      ['queued job',{status:'queued'}],['finished job',{status:'completed',conclusion:'success'}],
      ['missing runner',{runner_id:0}]]){
      const original=copy(own);Object.assign(own,patch);
      await refuses(resolve,'max2_hold_pair_read_job_not_active','MAX2 read refuses '+label);Object.assign(own,original);
    }
    f.jobs.set(run.id,[]);
    await refuses(resolve,'max2_hold_pair_read_job_not_active','a run summary cannot replace the actual job');
    f.jobs.set(run.id,[own,{...copy(own),id:own.id+1,name:'unexpected'}]);
    await refuses(resolve,'max2_hold_pair_read_job_not_active','the one-time reader cannot acquire a second job');
    f.jobs.set(run.id,[own]);jobsTotal=2;
    await refuses(resolve,'max2_hold_pair_read_jobs_incomplete','a partial job inventory grants no authority');jobsTotal=null;
    total=2;
    await refuses(resolve,'max2_hold_pair_read_inventory_incomplete','a partial history cannot prove the first read');total=null;
    inventory=[run,{...copy(run),id:run.id+1,status:'completed',conclusion:'success'}];
    await refuses(resolve,'max2_hold_pair_read_replay_forbidden','deleting and recreating the branch cannot repeat the read');
    inventory=[{...copy(run),id:run.id+1}];
    await refuses(resolve,'max2_hold_pair_read_replay_forbidden','another native run cannot stand in for this read');
    inventory=[run];branchHead=H('updated MAX2 read branch');
    await refuses(resolve,'max2_hold_pair_read_branch_changed','the fixed branch must still name the current source');
    branchHead=repositorySha;advanceBranch=true;branchReads=0;
    await refuses(resolve,'max2_hold_pair_read_branch_changed','the operation ref is checked after accepted release resolution');
    advanceBranch=false;f.main=H('new main before MAX2 admission');
    await refuses(resolve,'main_changed','an older accepted release cannot admit a read of the new main');f.main=repositorySha;
    advanceMain=true;mainReads=0;
    await refuses(resolve,'main_changed','current main is rechecked after accepted release resolution');advanceMain=false;
    const closer=f.wave.own,oldStatus=closer.status,oldConclusion=closer.conclusion;
    closer.status='in_progress';closer.conclusion=null;
    await refuses(resolve,'release_pending','an unfinished original terminal cannot reach SSH');
    closer.status=oldStatus;closer.conclusion=oldConclusion;
    const closureJob=f.jobs.get(closer.id).find(j=>j.name==='release-slot');
    for(const [prefix,code] of [[R.TERMINAL_BINDING_STEP,'publication_step_unconfirmed'],
      [R.TERMINAL_STEP,'terminal_step_unconfirmed']]){
      const step=closureJob.steps.find(s=>s.name.startsWith(prefix)),old=step.conclusion;step.conclusion='failure';
      await refuses(resolve,code,'an unconfirmed original terminal step cannot authorize MAX2 observation');step.conclusion=old;
    }
    equal(f.calls.slice(before).every(c=>c.method==='GET'),true,'all MAX2 admission failures remain read only');
  }finally{f.hook=null;f.runs.delete(run.id);f.jobs.delete(run.id);f.main=repositorySha;}
  return assertions-startAssertions;
}
async function remainingTransferOriginalProof(){
  const sha='bb7d0bde64092cbd24a69084e651ff44336ba8b5';
  const owned={id:226193297,login:'pyatkoff'};
  const branch='ops/anytour-hotel-next-apply-20261123';
  const run={id:45235830990,run_attempt:1,path:R.PREFIX+'edit-1566-next-historical-telegram.yml',
    head_sha:sha,head_branch:branch,head_repository:{full_name:R.REPOSITORY},event:'push',
    created_at:'2026-11-23T18:12:00Z',status:'completed',conclusion:'failure',
    actor:copy(owned),triggering_actor:copy(owned)};
  const steps=[
    {name:'Apply the installed editor once and retain its original receipts',status:'completed',conclusion:'failure'},
    {name:'Save the original sanitized transfer result',status:'completed',conclusion:'success'}
  ];
  const job={id:137907167978,run_id:run.id,head_sha:sha,run_attempt:1,name:'transfer',
    status:'completed',conclusion:'failure',steps};
  const artifact={id:14850280926,digest:'sha256:8df0ba31b546c644f800a71324d959c8ebdaa1a0801ffdc2f0d8ef5cebf3034c',
    name:'issue-1566-hotel-next-transfer',workflow_run:{id:run.id,head_sha:sha,head_branch:branch}};
  const api=async (method,path)=>{
    equal(method,'GET','remaining transfer evidence is read-only');
    if(path===R.API_ROOT+'/actions/runs/'+run.id)return run;
    if(path===R.API_ROOT+'/actions/runs/'+run.id+'/attempts/1/jobs?per_page=100')return {total_count:1,jobs:[job]};
    if(path===R.API_ROOT+'/actions/artifacts/'+artifact.id)return artifact;
    throw Error('Unexpected remaining transfer evidence request');
  };
  await R.proveOriginalNextTransfer(api);
  steps[1].conclusion='failure';
  await refuses(()=>R.proveOriginalNextTransfer(api),'original_hotel_transfer_result_changed');
  steps[1].conclusion='success';
  artifact.digest='sha256:'+'0'.repeat(64);
  await refuses(()=>R.proveOriginalNextTransfer(api),'original_hotel_transfer_artifact_changed');
}

async function hotelFourTransferConsumer(f,repositorySha,applicationSha){
  const branch='ops/anytour-hotel-four-apply-20261123',ref='refs/heads/'+branch;
  const workflow=R.PREFIX+'edit-1566-historical-telegram-with-channel-default.yml',owner={id:226193297,login:'pyatkoff'};
  const run={...copy(f.wave.parent),id:6506,path:workflow,event:'push',head_branch:branch,
    status:'in_progress',conclusion:null,actor:copy(owner),triggering_actor:copy(owner)};
  f.runs.set(run.id,run);
  const own={...job('transfer',run,[],null),status:'in_progress'};f.jobs.set(run.id,[own]);
  const env={...consumerEnv(run,workflow,'push','transfer'),GITHUB_REF:ref,
    GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@'+ref,GITHUB_ACTOR:owner.login,GITHUB_TRIGGERING_ACTOR:owner.login};
  const event={repository:{full_name:R.REPOSITORY,id:1342493152},sender:copy(owner),ref,
    after:repositorySha,before:'0'.repeat(40),created:true,deleted:false,forced:false};
  const planPath='config/anytour_existing_post_transfer_four_20261123.json',bytes=fs.readFileSync(path.join(__dirname,'..',planPath));
  const originalPlan={type:'file',path:planPath,sha:R.gitBlob(bytes),size:bytes.length,encoding:'base64',content:bytes.toString('base64')};
  equal([originalPlan.sha,D(bytes)],['648e68b7395fcab65c321448b1a6a68554de17dc','fdda41801951a033715b5ca5d567ef348f2ee885ab40074d35dfa8151d52de56'],
    'the reviewed plan remains exact data and cannot silently become a different transfer');
  equal(JSON.parse(bytes).rows.map(r=>[r.hotel_id,r.source_message_id,r.target_message_id]),
    [[17595,'455','862'],[358,'65','328'],[393,'85','1083'],[1260,'548','288']],
    'the pinned review plan contains only the four selected pairs');
  const inventoryPath=R.API_ROOT+'/actions/workflows/edit-1566-historical-telegram-with-channel-default.yml/runs?branch='
    +encodeURIComponent(branch)+'&per_page=100';
  let inventory=[run],total=null,nativePlan=copy(originalPlan),branchHead=repositorySha,mainReads=0,advanceMain=false,branchReads=0,advanceBranch=false;
  f.hook=({path})=>{
    if(path===inventoryPath)return {total_count:total??inventory.length,workflow_runs:inventory};
    if(path===R.API_ROOT+'/contents/'+planPath+'?ref='+repositorySha)return nativePlan;
    if(path===R.API_ROOT+'/git/ref/heads/'+branch)return {object:{sha:advanceBranch&&++branchReads>1?H('moved transfer branch'):branchHead}};
    if(path===R.API_ROOT+'/git/ref/heads/main'&&advanceMain&&++mainReads>1)return {object:{sha:H('new main after transfer admission')}};
  };
  const resolve=()=>R.resolveConsumer({env,event,api:f.api});
  try{
    const before=f.calls.length,result=await resolve();
    equal([result.repository_sha,result.application_sha,result.ready,result.perform_actions,result.write_operation],
      [repositorySha,applicationSha,true,true,'hotel_four_transfer'],
      'one exact first native apply event selects only the existing four-hotel editor at the accepted application');
    equal(result.read_operation,undefined,'the transfer result is distinct from read-only observation');
    equal(f.calls.slice(before).every(c=>c.method==='GET'),true,'apply admission itself never writes or loads provider credentials');
    const rejectedBeforeApi=f.calls.length;
    for(const [label,patch] of [['existing branch',{created:false}],['noninitial before',{before:H('old transfer source')}],
      ['forced',{forced:true}],['deleted',{deleted:true}],['wrong event source',{after:H('different source')}],
      ['wrong repository',{repository:{full_name:R.REPOSITORY,id:1}}],['wrong owner',{sender:{id:1,login:owner.login}}]]){
      await refuses(()=>R.resolveConsumer({env,event:{...event,...patch},api:f.api}),
        'untrusted_hotel_four_transfer','fixed transfer rejects '+label);
    }
    for(const [label,patch] of [['attempt2',{GITHUB_RUN_ATTEMPT:'2'}],['reader job',{GITHUB_JOB:'registry'}],
      ['manual dispatch',{GITHUB_EVENT_NAME:'workflow_dispatch'}],['foreign actor',{GITHUB_ACTOR:'other'}],
      ['reader workflow',{GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.PREFIX+'read-1566-main-hotel-registry.yml@'+ref}]]){
      await refuses(()=>R.resolveConsumer({env:{...env,...patch},event,api:f.api}),
        'untrusted_hotel_four_transfer','fixed transfer refuses '+label);
    }
    equal(f.calls.length,rejectedBeforeApi,'wrong transfer envelopes stop before native or privileged access');
    const readRef='refs/heads/ops/anytour-hotel-four-read-20261123';
    await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_REF:readRef,GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@'+readRef},
      event:{...event,ref:readRef},api:f.api}),'untrusted_hotel_registry_read','the original read carrier cannot select apply');
    run.head_branch='main';
    await refuses(()=>R.resolveConsumer({env:{...env,GITHUB_REF:'refs/heads/main',GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@refs/heads/main'},
      event:{...event,ref:'refs/heads/main'},api:f.api}),'untrusted_push_consumer','ordinary deploy/main push cannot turn on the editor');
    run.head_branch=branch;
    for(const [patch,code] of [[{id:run.id+1},'untrusted_workflow_run'],[{head_sha:H('foreign source')},'untrusted_workflow_run'],
      [{head_branch:'main'},'untrusted_workflow_run'],[{run_attempt:2},'untrusted_workflow_run'],
      [{status:'completed',conclusion:'success'},'workflow_not_active'],
      [{actor:{id:1,login:owner.login}},'foreign_hotel_transfer_actor'],
      [{triggering_actor:{id:1,login:owner.login}},'foreign_hotel_transfer_actor']]){
      const original=copy(run);Object.assign(run,patch);await refuses(resolve,code);Object.assign(run,original);
    }
    for(const patch of [{name:'registry'},{run_id:run.id+1},{head_sha:H('other job source')},{run_attempt:2},
      {status:'completed',conclusion:'success'}]){
      const original=copy(own);Object.assign(own,patch);
      await refuses(resolve,'hotel_transfer_job_unconfirmed','only the active native transfer job can admit SSH');Object.assign(own,original);
    }
    total=2;await refuses(resolve,'hotel_transfer_inventory_incomplete','partial history cannot prove a first transfer');total=null;
    inventory=[run,{...copy(run),id:run.id+1,status:'completed',conclusion:'success'}];
    await refuses(resolve,'hotel_transfer_replay_forbidden','delete/recreate cannot replay a previous operation');inventory=[run];
    for(const patch of [{type:'symlink'},{path:'config/other.json'},{sha:H('other plan')},{size:bytes.length+1},
      {encoding:'none'},{content:'!invalid-base64'},
      {content:Buffer.from(bytes.toString().replace('"target_message_id": "862"','"target_message_id": "863"')).toString('base64')}]){
      nativePlan={...originalPlan,...patch};
      await refuses(resolve,'hotel_transfer_plan_changed','metadata or body drift cannot retain a valid plan binding');
    }
    nativePlan=copy(originalPlan);branchHead=H('updated transfer branch');
    await refuses(resolve,'hotel_transfer_branch_changed');branchHead=repositorySha;advanceBranch=true;branchReads=0;
    await refuses(resolve,'hotel_transfer_branch_changed','the fixed branch is rechecked after accepted release resolution');advanceBranch=false;
    f.main=H('new main before transfer');
    await refuses(resolve,'main_changed','an old source cannot be applied after main advances');f.main=repositorySha;
    advanceMain=true;mainReads=0;
    await refuses(resolve,'main_changed','final main drift cannot reach credentials');advanceMain=false;
    const closer=f.wave.own,oldStatus=closer.status,oldConclusion=closer.conclusion;
    closer.status='in_progress';closer.conclusion=null;
    await refuses(resolve,'release_pending','an unclosed release cannot enable the editor');
    closer.status=oldStatus;closer.conclusion=oldConclusion;
  }finally{f.hook=null;f.runs.delete(run.id);f.jobs.delete(run.id);f.main=repositorySha;}
}
async function hotelMaxTransferConsumer(f,repositorySha,applicationSha){
  const branch='ops/anytour-max-sugar-marina-800-apply-20261123',ref='refs/heads/'+branch;
  const workflow=R.PREFIX+'edit-1566-max-sugar-marina.yml',owner={id:226193297,login:'pyatkoff'};
  const planPath='config/anytour_existing_post_transfer_max_800.json',bytes=fs.readFileSync(path.join(__dirname,'..',planPath));
  const plan={type:'file',path:planPath,sha:R.gitBlob(bytes),size:bytes.length,encoding:'base64',content:bytes.toString('base64')};
  const run={...copy(f.wave.parent),id:6507,path:workflow,event:'push',head_branch:branch,
    status:'in_progress',conclusion:null,actor:copy(owner),triggering_actor:copy(owner)};
  const env={...consumerEnv(run,workflow,'push','transfer'),GITHUB_REF:ref,
    GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@'+ref,GITHUB_ACTOR:owner.login,GITHUB_TRIGGERING_ACTOR:owner.login};
  const event={repository:{full_name:R.REPOSITORY,id:1342493152},sender:copy(owner),ref,
    after:repositorySha,before:'0'.repeat(40),created:true,deleted:false,forced:false};
  f.runs.set(run.id,run);
  f.jobs.set(run.id,[{...job('transfer',run,[],null),status:'in_progress'}]);
  const inventory=R.API_ROOT+'/actions/workflows/'+workflow.slice(R.PREFIX.length)+'/runs?branch='
    +encodeURIComponent(branch)+'&per_page=100';
  f.hook=({path})=>{
    if(path===inventory)return {total_count:1,workflow_runs:[run]};
    if(path===R.API_ROOT+'/contents/'+planPath+'?ref='+repositorySha)return plan;
    if(path===R.API_ROOT+'/git/ref/heads/'+branch)return {object:{sha:repositorySha}};
  };
  const resolve=()=>R.resolveConsumer({env,event,api:f.api});
  try{
    if(repositorySha!==applicationSha){
      await refuses(resolve,'max_transfer_editor_not_installed','a repository-only release cannot select the MAX editor');
    }else{
      const result=await resolve();
      equal([result.repository_sha,result.application_sha,result.write_operation],
        [repositorySha,applicationSha,'hotel_max_sugar_transfer'],'the exact first MAX apply push binds the installed editor');
    }
  }finally{f.hook=null;f.runs.delete(run.id);f.jobs.delete(run.id);}
}

async function publicationOperationConsumer(f,repositorySha,applicationSha){
  const branch='ops/appleservice39-coverage-20261112',ref='refs/heads/'+branch;
  const workflow=R.PREFIX+'publish-appleservice39-pilot.yml';
  const run={...copy(f.wave.parent),id:6502,path:workflow,event:'push',head_branch:branch,status:'in_progress',conclusion:null};
  f.runs.set(run.id,run);
  const event={repository:{full_name:R.REPOSITORY},ref,after:repositorySha,before:'0'.repeat(40),created:true,deleted:false,forced:false};
  const env={...consumerEnv(run,workflow,'push','publish'),GITHUB_REF:ref,GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@'+ref};
  const before=f.calls.length;
  const result=await R.resolvePublicationOperation({env,event,api:f.api});
  equal([result.repository_sha,result.application_sha,result.perform_actions,result.ready],
    [repositorySha,applicationSha,true,true],'original fixed coverage branch creation resolves the actually accepted application');
  equal(result.publication_operation,'coverage_batch','coverage branch selects only its original content batch');
  equal(f.calls.slice(before).every(c=>c.method==='GET'),true,'publication source admission is read-only before credentials');
  const deviceBranch='ops/appleservice39-device-v1-20261119',deviceRef='refs/heads/'+deviceBranch;
  const deviceRun={...run,id:6599,head_branch:deviceBranch};
  const deviceEnv={...env,GITHUB_RUN_ID:String(deviceRun.id),GITHUB_REF:deviceRef,GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@'+deviceRef};
  const deviceEvent={...event,ref:deviceRef};
  f.runs.set(deviceRun.id,deviceRun);
  const admitted=await R.resolvePublicationOperation({env:deviceEnv,event:deviceEvent,api:f.api});
  equal([admitted.publication_operation,admitted.repository_sha,admitted.application_sha,admitted.ready,admitted.perform_actions],
    ['device_batch',repositorySha,applicationSha,true,true],'the first device push selects the four-card operation at the accepted source');
  const beforeRecovery=f.calls.length;
  await refuses(()=>R.resolvePublicationOperation({env:deviceEnv,event:{...deviceEvent,created:false,before:'c5c0276d94337eee130467716c9794176b2ab25a'},api:f.api}),
    'untrusted_publication_operation','the old coverage predecessor cannot authorize any device push or inspection');
  equal(f.calls.length,beforeRecovery,'device predecessor is refused before GitHub or publication access');
  await refuses(()=>R.resolvePublicationOperation({env:{...deviceEnv,GITHUB_RUN_ATTEMPT:'2'},event:deviceEvent,api:f.api}),
    'untrusted_publication_operation','a workflow rerun cannot publish device posts');
  await refuses(()=>R.resolvePublicationOperation({env:deviceEnv,event,api:f.api}),
    'untrusted_publication_operation','coverage and device branch identities cannot be interchanged');
  const recoveryBranch='ops/appleservice39-device-recovery-20261119',recoveryRef='refs/heads/'+recoveryBranch;
  const recoveryRun={...run,id:6600,head_branch:recoveryBranch};
  f.runs.set(recoveryRun.id,recoveryRun);
  const repairEnv={...env,GITHUB_RUN_ID:String(recoveryRun.id),GITHUB_REF:recoveryRef,GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+workflow+'@'+recoveryRef};
  const repairEvent={...event,ref:recoveryRef};
  const originalSha='8299a835d9b896c57dd4ffb9b34db75f613b3de0',originalRunId=44436183201;
  const original={...deviceRun,id:originalRunId,head_sha:originalSha,status:'completed',conclusion:'failure'};
  const originalJob={id:135269278628,run_id:originalRunId,name:'publish',status:'completed',conclusion:'failure',
    steps:[{name:'Run the installed fixed pilot operation once',conclusion:'failure'}]};
  const ancestry={status:'ahead',merge_base_commit:{sha:originalSha}};
  const repairApi=async(method,path,...args)=>{
    if(path===R.API_ROOT+'/actions/runs/'+originalRunId)return copy(original);
    if(path===R.API_ROOT+'/actions/jobs/'+originalJob.id)return copy(originalJob);
    if(path===R.API_ROOT+'/compare/'+originalSha+'...'+repositorySha+'?per_page=1')return copy(ancestry);
    return f.api(method,path,...args);
  };
  const repair=()=>R.resolvePublicationOperation({env:repairEnv,event:repairEvent,api:repairApi});
  const recoveryReceipt=await repair();
  equal([recoveryReceipt.publication_operation,recoveryReceipt.repository_sha,recoveryReceipt.application_sha],
    ['device_recovery',repositorySha,applicationSha],'only the bounded device recovery can follow the original failed publication');
  original.run_attempt=2;
  await refuses(repair,'untrusted_workflow_run','a rerun cannot provide the original operation evidence');
  original.run_attempt=1;originalJob.steps[0].name='Verify current repository provenance';
  await refuses(repair,'device_original_operation_unproven','failure in another stage grants no continuation');
  originalJob.steps[0].name='Run the installed fixed pilot operation once';ancestry.merge_base_commit.sha='f'.repeat(40);
  await refuses(repair,'publication_source_not_descendant','a different source history cannot use the device continuation');
  ancestry.merge_base_commit.sha=originalSha;
  await refuses(()=>R.resolvePublicationOperation({env:repairEnv,event:{...repairEvent,created:false,before:originalSha},api:repairApi}),
    'untrusted_publication_operation','updating a previously used recovery branch cannot start another continuation');
  await refuses(()=>R.resolvePublicationOperation({env:{...repairEnv,GITHUB_RUN_ATTEMPT:'2'},event:repairEvent,api:repairApi}),
    'untrusted_publication_operation','rerunning the continuation cannot authorize sends');
  f.runs.delete(recoveryRun.id);
  f.runs.delete(deviceRun.id);
  for(const [label,patch] of [['not created',{created:false}],['forced',{forced:true}],['deleted',{deleted:true}],
    ['existing branch update',{before:'b'.repeat(40)}],['other source',{after:'b'.repeat(40)}],['main event',{ref:'refs/heads/main'}]]){
    await refuses(()=>R.resolvePublicationOperation({env,event:{...event,...patch},api:f.api}),
      'untrusted_publication_operation','fixed publication rejects '+label);
  }
  for(const [label,patch] of [['attempt2',{GITHUB_RUN_ATTEMPT:'2'}],['other job',{GITHUB_JOB:'setup'}],
    ['fake main',{GITHUB_REF:'refs/heads/main'}],['manual dispatch',{GITHUB_EVENT_NAME:'workflow_dispatch'}],
    ['foreign workflow',{GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.PREFIX+'other.yml@'+ref}]]){
    await refuses(()=>R.resolvePublicationOperation({env:{...env,...patch},event,api:f.api}),
      'untrusted_publication_operation','fixed publication refuses '+label);
  }
  const predecessor='c5c0276d94337eee130467716c9794176b2ab25a',oldId=43186776772;
  let oldRun={...copy(run),id:oldId,head_sha:predecessor,status:'completed',conclusion:'cancelled'};
  const oldJobs=[
    {id:6503,run_id:oldId,run_attempt:1,head_sha:predecessor,name:'AppleService39 pilot publication regression',status:'completed',conclusion:'cancelled',steps:[]},
    {id:6504,run_id:oldId,run_attempt:1,head_sha:predecessor,name:'publish',status:'completed',conclusion:'skipped',steps:[]}
  ];
  let jobs=copy(oldJobs),relation={status:'ahead',merge_base_commit:{sha:predecessor}};
  const recoveryApi=async(method,path,...args)=>{
    if(path===R.API_ROOT+'/actions/runs/'+oldId)return copy(oldRun);
    if(path===R.API_ROOT+'/actions/runs/'+oldId+'/attempts/1/jobs?per_page=100')return {total_count:jobs.length,jobs:copy(jobs)};
    if(path===R.API_ROOT+'/compare/'+predecessor+'...'+repositorySha+'?per_page=1')return copy(relation);
    return f.api(method,path,...args);
  };
  const recoveryEvent={...event,before:predecessor,created:false};
  const recovered=await R.resolvePublicationOperation({env,event:recoveryEvent,api:recoveryApi});
  equal([recovered.ready,recovered.perform_actions,recovered.repository_sha,recovered.application_sha],
    [true,true,repositorySha,applicationSha],'one original native fast-forward can replace the exact cancelled zero-publication predecessor');
  for(const mutate of [
    ()=>{jobs[1].conclusion='success';},
    ()=>{jobs[1].steps=[{name:'Prepare deployment credential',status:'completed',conclusion:'success'}];},
    ()=>{jobs[1].status='in_progress';},
    ()=>{jobs[1].run_attempt=2;},
    ()=>{jobs[1].head_sha='f'.repeat(40);},
    ()=>{jobs[1].run_id++;},
    ()=>{jobs[0].conclusion='success';},
    ()=>{jobs.push({...jobs[1],id:6505});},
    ()=>{jobs.pop();}
  ]){
    jobs=copy(oldJobs);mutate();
    await refuses(()=>R.resolvePublicationOperation({env,event:recoveryEvent,api:recoveryApi}),
      'publication_predecessor_has_writes','missing, executed, changed or unknown original job evidence cannot grant publication recovery');
  }
  jobs=copy(oldJobs);oldRun.run_attempt=2;
  await refuses(()=>R.resolvePublicationOperation({env,event:recoveryEvent,api:recoveryApi}),
    'untrusted_workflow_run','a rerun of the cancelled predecessor is not the original zero-write proof');
  oldRun.run_attempt=1;oldRun.conclusion='success';
  await refuses(()=>R.resolvePublicationOperation({env,event:recoveryEvent,api:recoveryApi}),
    'producer_not_successful','a successful or differently concluded predecessor cannot use this finite cancellation recovery');
  oldRun.conclusion='cancelled';relation={status:'ahead',merge_base_commit:{sha:'f'.repeat(40)}};
  await refuses(()=>R.resolvePublicationOperation({env,event:recoveryEvent,api:recoveryApi}),
    'publication_source_not_descendant','recovery cannot install an unrelated publication source');
  await refuses(()=>R.resolvePublicationOperation({env,event:{...recoveryEvent,before:'f'.repeat(40)},api:recoveryApi}),
    'untrusted_publication_operation','a later fast-forward cannot become another automatic recovery');
  f.main='f'.repeat(40);
  await refuses(()=>R.resolvePublicationOperation({env,event,api:f.api}),'main_changed','coverage cannot run a stale accepted checkout');
  f.main=repositorySha;run.status='completed';run.conclusion='success';
  await refuses(()=>R.resolvePublicationOperation({env,event,api:f.api}),'workflow_not_active','historical coverage success grants no replay');
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
  for(const mode of ['transport','malformed_json']){
    const f=fixture(a),record=recordFor(f),base=f.api;
    const writer=C.makeApi('fixture',async(url,options)=>{
      const parsed=new URL(url),route=parsed.pathname+parsed.search;
      const body=options.body===undefined?undefined:JSON.parse(options.body);
      const result=await base(options.method,route,body);
      if(options.method==='PATCH'&&route===ref){
        if(mode==='transport')throw Error('private transport detail after the committed ref update');
        return {ok:true,status:200,arrayBuffer:async()=>Buffer.from('{')};
      }
      return {ok:true,status:200,arrayBuffer:async()=>Buffer.from(JSON.stringify(result))};
    });
    const out=await C.publishDiagnostics(writer,record);
    equal(out.record,record,'the real API adapter reconciles a committed '+mode+' failure');
    equal(f.calls.filter(x=>x.method==='PATCH').length,1,'the real API adapter never resends after '+mode);
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
async function terminalContinuationCases(){
  // A synthetic API trace reproduces the real failure shape. These fixtures
  // never authorize a native run, receipt or publisher operation.
  const original={"bundle":"success","diagnostics":"success","diagnostics_attempt":1,"install_attempt":1,"install_checks":"success","key":"success","max_webhook":"success","metrika_cron":"success","migration":"success","provenance":"success","reported_at":"2026-11-12T13:54:04Z","repository_release":{"application_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","baseline":null,"baseline_eligible":false,"closure":{"binding":{"comment_id":6789986080,"head_sha":"e6161e9c8789e3058db24b78ae97aecd13c15b3f","owner_id":226193297,"pr":1505,"updated_at":"2026-11-12T13:41:14Z"},"expected_terminal":"PR1505: установлено, выпуск свободен","gates":[{"conclusion":"success","head_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","path":".github/workflows/deploy.yml","run_attempt":1,"run_id":"43229715899"},{"conclusion":"success","head_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","path":".github/workflows/retired-domain-guard.yml","run_attempt":1,"run_id":"43229715901"},{"conclusion":"success","head_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","path":".github/workflows/max-tls-preflight.yml","run_attempt":1,"run_id":"43231780721"},{"conclusion":"success","head_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","path":".github/workflows/max-production-health.yml","run_attempt":1,"run_id":"43231780533"},{"conclusion":"success","head_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","path":".github/workflows/telegram-production-health.yml","run_attempt":1,"run_id":"43231780453"},{"conclusion":"success","head_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","path":".github/workflows/publish-conversation-diagnostics.yml","run_attempt":1,"run_id":"43231780317"},{"conclusion":"success","head_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","path":".github/workflows/live-session-diagnostics.yml","run_attempt":1,"run_id":"43231780307"},{"conclusion":"success","head_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","path":".github/workflows/tour-selection-host.yml","run_attempt":1,"run_id":"43231780829"},{"conclusion":"success","head_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","path":".github/workflows/modeled-conversion-monitor.yml","run_attempt":1,"run_id":"43231780545"},{"conclusion":"skipped","head_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","path":".github/workflows/modeled-pipeline-monitor-activation.yml","run_attempt":1,"run_id":"43231926847"}],"job":"release-slot","run_attempt":1,"run_id":"43231780686","terminal_step":"Release only the admitted publisher after all original mandatory gates"},"content":{"candidate_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","classification":"unconfirmed","installed_sha":"0a397bd1b2b84648bd5ca23dcd113d567d0cc5bd","policy":"anytour-development-content-v1","release_path":"full_required","runtime_fingerprint":null},"intent_scan":{"checked_commits":2,"coverage":"registered_markers_and_reserved_families","matched_commits":[],"status":"no_registered_intent"},"kind":"installed","observation":{"application_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","components":{"editor":null,"public":null},"configuration_key_id":null,"core_digest":null,"digest":null,"mode":"all","protocol":"anytour-deployment-runtime-v1","reason_code":"configuration_loader_unsupported","schema":1,"status":"unknown"},"observation_producer":{"run_attempt":1,"run_id":"43231780829"},"phase":"pending_terminal","release":{"event":"push","run_attempt":1,"run_id":"43229715899"},"repository_sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","schema":1,"stages":{"bundle":{"execution":"performed","result":"success"},"diagnostics":{"execution":"performed","result":"success"},"install_checks":{"execution":"performed","result":"success"},"key":{"execution":"performed","result":"success"},"max_webhook":{"execution":"performed","result":"success"},"metrika_cron":{"execution":"performed","result":"success"},"migration":{"execution":"performed","result":"success"},"provenance":{"execution":"performed","result":"success"},"smoke":{"execution":"performed","result":"success"},"sync":{"execution":"performed","result":"success"}}},"run_id":"43229715899","schema":2,"sha":"6e1c61915d0afc35ec8198d59021fe5a6bddadb6","smoke":"success","smoke_attempt":1,"sync":"success"},p=R.TERMINAL_RECOVERY;
  function setup(){
    const source=H('checked terminal continuation'),runId=600000;
    const f=fixture(p.sha,copy(original));f.runs.clear();f.jobs.clear();
    const wave=f.start(p.sha,Number(original.run_id),Number(p.run_id),p.pr);
    f.runs.clear();
    for(const g of original.repository_release.closure.gates){
      const def=C.REQUIRED.find(x=>R.PREFIX+x[0]===g.path),r={id:Number(g.run_id),path:g.path,name:def[2],event:def[1],
        head_sha:p.sha,head_branch:'main',head_repository:{full_name:R.REPOSITORY},run_attempt:1,status:'completed',
        conclusion:g.conclusion,created_at:T,run_started_at:T,updated_at:T,head_commit:{id:p.sha,message:'Product release'}};
      if(r.event==='workflow_run')r.display_title=g.path.endsWith(C.ACTIVATION.file)?
        'monitor-proof:v1:'+original.repository_release.closure.gates.find(x=>x.path.endsWith('modeled-conversion-monitor.yml')).run_id+':1':
        'deploy-proof:v1:'+original.run_id+':1';
      f.runs.set(r.id,r);
      if(g.path.endsWith(C.ACTIVATION.file))f.jobs.set(r.id,[job('activate',r,[],'skipped')]);
    }
    const old=f.runs.get(Number(original.run_id));f.jobs.set(old.id,fullJobs(old,original));
    const failed={...copy(wave.own),status:'completed',conclusion:'failure',
      display_title:'deploy-proof:v1:'+original.run_id+':1'};
    f.runs.set(failed.id,failed);
    const oldJob=job('release-slot',failed,[step(R.TERMINAL_BINDING_STEP+p.record_digest),step(R.TERMINAL_STEP,'failure')],'failure');
    oldJob.id=p.job_id;
    f.jobs.set(failed.id,[job('configure',failed,[],'skipped'),oldJob]);
    const native={id:runId,path:R.CLOSER,name:'Configure AnyTour modeled value goal',event:'push',head_sha:source,
      head_branch:p.branch,head_repository:{full_name:R.REPOSITORY},run_attempt:1,status:'in_progress',conclusion:null,
      actor:{id:p.owner_id},created_at:T,run_started_at:T,updated_at:T};
    f.runs.set(runId,native);
    for(const [i,name]of ['Regression tests','Retired domain guard'].entries())f.runs.set(runId+i+1,
      {...copy(native),id:runId+i+1,name,path:R.PREFIX+(i===0?'regression-tests.yml':'retired-domain-guard.yml'),event:'pull_request',status:'completed',conclusion:'success'});
    const currentJob=job('release-slot-recovery',native,[],null);currentJob.status='in_progress';currentJob.runner_id=123;f.jobs.set(runId,[currentJob]);
    const stopped={...copy(native),id:Number(p.continuation_run_id),head_sha:p.continuation_sha,status:'completed',conclusion:'failure'};
    const stoppedJob=job('release-slot-recovery',stopped,[step('Prepare continuation of the original pre-write read refusal','failure'),
      step(R.TERMINAL_BINDING_STEP,'skipped'),step(R.TERMINAL_STEP,'skipped')],'failure');stoppedJob.id=p.continuation_job_id;
    f.runs.set(stopped.id,stopped);f.jobs.set(stopped.id,[stoppedJob]);
    const observed={...copy(native),id:Number(p.observation_run_id),head_sha:p.observation_sha,status:'completed',conclusion:'success'};
    const observationJob=job('release-slot-recovery',observed,[step('Read only allowlisted native event and run identity')]);
    observationJob.id=p.observation_job_id;f.runs.set(observed.id,observed);f.jobs.set(observed.id,[observationJob]);
    const readFailed={...copy(native),id:Number(p.read_failure_run_id),head_sha:p.read_failure_sha,status:'completed',conclusion:'failure'};
    const readFailedJob=job('release-slot-recovery',readFailed,[step('Prepare continuation of the original pre-write read refusal','failure'),
      step(R.TERMINAL_BINDING_STEP,'skipped'),step(R.TERMINAL_STEP,'skipped')],'failure');readFailedJob.id=p.read_failure_job_id;
    f.runs.set(readFailed.id,readFailed);f.jobs.set(readFailed.id,[readFailedJob]);
    const bound=original.repository_release.closure.binding;
    f.pr={...f.pr,head:{sha:bound.head_sha,repo:{full_name:R.REPOSITORY}},
      body:'<!-- release-slot-close:v1 '+JSON.stringify({comment_id:p.comment_id,head_sha:bound.head_sha,
        owner_id:p.owner_id,updated_at:p.updated_at})+' -->'};
    f.comment={...f.comment,id:p.comment_id,user:{id:p.owner_id},updated_at:p.updated_at};
    const at=f.head,entry=f.records.get(at),bytes=Buffer.from(R.wire(original));
    equal(R.gitBlob(bytes),p.blob,'continuation: fixed original byte envelope');
    equal(R.digest(original),p.record_digest,'continuation: fixed original digest');
    f.records.set(p.commit,{...entry,bytes,blob:p.blob,value:copy(original)});
    const originalCommit=copy(f.commits.get(at));originalCommit.sha=p.commit;originalCommit.parents=[];
    f.commits.set(p.commit,originalCommit);f.commits.get(at).parents=[{sha:p.commit}];
    const proof=R.publicationProof(original,{commit:p.commit,blob:p.blob},p.run_id,1);
    const archive=storedZip(R.PUBLICATION_FILE,proof),artifact={id:700001,
      name:R.publicationName('pending_terminal',p.run_id,1),expired:false,size_in_bytes:archive.length,
      digest:'sha256:'+D(archive),created_at:T,workflow_run:{id:failed.id,head_sha:p.sha,head_branch:'main'}};
    f.artifacts.set(failed.id,[artifact]);f.archives.set(artifact.id,archive);
    const base=f.api;
    const api=async(method,path,body,raw)=>{
      if(path===R.API_ROOT+'/git/commits/'+source)return {sha:source,parents:[{sha:p.read_failure_sha}]};
      if(path===R.API_ROOT+'/compare/'+p.read_failure_sha+'...'+source+'?per_page=1')
        return {status:'ahead',merge_base_commit:{sha:p.read_failure_sha}};
      if(path===R.API_ROOT+'/contents/tools/release_slot_close.js?ref='+p.sha)
        return {sha:'66cf67b48a40517dc4b2afd38b891c5396baeefe'};
      if(path===R.API_ROOT+'/contents/tools/release_slot_close.js?ref='+p.continuation_sha)
        return {sha:'4bce0a0fe076ffc4fa481a8f0ba1cce98477ec4d'};
      if(path===R.API_ROOT+'/contents/tools/release_slot_close.js?ref='+p.read_failure_sha)
        return {sha:'dba0df10089ba3dd98ab6b1ef735bbe11b434547'};
      if(path===R.API_ROOT+'/issues/comments/'+p.comment_id){
        f.calls.push({method,path,body});if(method==='PATCH')f.comment={...f.comment,body:body.body};return copy(f.comment);
      }
      return base(method,path,body,raw);
    };
    api.artifact=base.artifact;
    api.jobLog=async id=>Buffer.from(id===p.observation_job_id?T+' TERMINAL_CONTEXT '+JSON.stringify({
      env:{GITHUB_SHA:p.observation_sha,GITHUB_RUN_ID:p.observation_run_id,GITHUB_RUN_ATTEMPT:'1',
        GITHUB_REF:'refs/heads/'+p.branch,GITHUB_JOB:'release-slot-recovery'},
      event:{before:p.continuation_sha,after:p.observation_sha,created:false,deleted:false,forced:false},
      native:{id:Number(p.observation_run_id),actor_id:p.owner_id,status:'in_progress'}})+'\n':
      T+' release_slot=held reason='+(id===p.continuation_job_id?'untrusted_terminal_continuation':'api_http_503')+'\n');
    const env={GITHUB_REPOSITORY:R.REPOSITORY,GITHUB_EVENT_NAME:'push',GITHUB_REF:'refs/heads/'+p.branch,
      GITHUB_SHA:source,GITHUB_RUN_ID:String(runId),GITHUB_RUN_ATTEMPT:'1',GITHUB_JOB:'release-slot-recovery',
      GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.CLOSER+'@refs/heads/'+p.branch};
    const event={repository:{full_name:R.REPOSITORY},ref:env.GITHUB_REF,after:source,before:p.read_failure_sha,
      created:false,deleted:false,forced:false};
    return {f,api,env,event,native,currentJob,oldJob,stoppedJob,observationJob,source,options:()=>({env,event,api})};
  }
  for(const bad of [
    ['GITHUB_EVENT_NAME','workflow_dispatch'],['GITHUB_RUN_ATTEMPT','2'],
    ['GITHUB_JOB','release-slot'],['GITHUB_REF','refs/heads/main']]){
    const x=setup();x.env[bad[0]]=bad[1];
    await refuses(()=>C.resumeTerminal({...x.options(),prepare:true}),'untrusted_terminal_continuation');
    equal(x.f.calls.length,0,'continuation: bad context refuses before API');
  }
  for(const text of ['write_unknown','api_http_429','api_http_502','api_http_503\n'+T+' release_slot=released']){
    const x=setup();x.api.jobLog=async()=>Buffer.from(T+' release_slot=held reason='+text+'\n');
    await refuses(()=>C.resumeTerminal({...x.options(),prepare:true}),'original_terminal_not_prewrite');
    equal(x.f.calls.some(q=>q.method!=='GET'),false,'continuation: ambiguous/other failure cannot mutate');
  }
  {
    const x=setup();x.f.main=H('changed main');
    await refuses(()=>C.resumeTerminal({...x.options(),prepare:true}),'main_changed');
  }
  {
    const x=setup();x.f.comment={...x.f.comment,updated_at:T};
    await refuses(()=>C.resumeTerminal({...x.options(),prepare:true}),'record_changed');
    equal(x.f.calls.some(q=>q.method!=='GET'),false,'continuation: changed publisher cannot mutate');
  }
  {
    const x=setup();x.oldJob.steps.at(-1).conclusion='success';
    await refuses(()=>C.resumeTerminal({...x.options(),prepare:true}),'original_terminal_not_failed');
  }
  {
    const x=setup();x.stoppedJob.steps.at(-1).conclusion='success';
    await refuses(()=>C.resumeTerminal({...x.options(),prepare:true}),'continuation_predecessor_has_writes');
    equal(x.f.calls.some(q=>q.method!=='GET'),false,'an executed predecessor terminal cannot be replayed');
  }
  {
    const x=setup();x.observationJob.steps.push(step('unexpected private operation'));
    await refuses(()=>C.resumeTerminal({...x.options(),prepare:true}),'observation_predecessor_changed');
  }
  {
    const x=setup();x.event.before=p.continuation_sha;
    await refuses(()=>C.resumeTerminal({...x.options(),prepare:true}),'untrusted_terminal_continuation');
  }
  {
    const x=setup();x.native.status='queued';
    await C.resumeTerminal({...x.options(),prepare:true});
    equal(x.f.comment.body,'PR1505: выпуск занят','queued run metadata with a proven executing job is safe');
  }
  {
    const x=setup();x.native.status='queued';x.currentJob.status='queued';x.currentJob.runner_id=0;
    await refuses(()=>C.resumeTerminal({...x.options(),prepare:true}),'continuation_job_not_active');
    equal(x.f.calls.some(q=>q.method!=='GET'),false,'a genuinely queued execution cannot mutate');
  }
  {
    const x=setup();x.f.runs.get(Number(original.repository_release.closure.gates[2].run_id)).conclusion='failure';
    await refuses(()=>C.resumeTerminal({...x.options(),prepare:true}),'required_gate_failed');
    equal(x.f.calls.some(q=>q.method!=='GET'),false,'continuation: failed mandatory gate cannot mutate');
  }
  {
    const x=setup();x.f.runs.get(600001).conclusion='failure';
    await refuses(()=>C.resumeTerminal({...x.options(),prepare:true}),'recovery_ci_unconfirmed');
  }
  {
    const x=setup();
    const prepared=await C.resumeTerminal({...x.options(),prepare:true});
    equal(x.f.comment.body,'PR1505: выпуск занят','continuation preparation retains publisher');
    equal(x.f.calls.filter(q=>q.method==='PATCH'&&q.path.includes('/issues/comments/')).length,0);
    x.env.PREPARED_RECORD_DIGEST=prepared.record_digest;
    x.currentJob.steps.push(step(R.TERMINAL_BINDING_STEP+prepared.record_digest));
    equal(await C.resumeTerminal(x.options()),'released','continuation closes through real validation path');
    equal(x.f.calls.filter(q=>q.method==='PATCH'&&q.path.includes('/issues/comments/')).length,1);
    equal(x.oldJob.steps.at(-1).conclusion,'failure','continuation never relabels original failure');
    x.native.status='completed';x.native.conclusion='success';x.currentJob.status='completed';x.currentJob.conclusion='success';
    x.currentJob.steps.push(step(R.TERMINAL_STEP));
    const location=await R.readRecord(x.api);
    equal((await R.resolveCurrent(x.api,{sha:p.sha},location)).ready,true,
      'ordinary consumer authenticates actual continuation and original installation');
    x.currentJob.steps.at(-1).conclusion='failure';
    await refuses(()=>R.authenticatePublication(x.api,location,{closed:true}),'terminal_step_unconfirmed');
  }
  {
    const x=setup(),prepared=await C.resumeTerminal({...x.options(),prepare:true});
    x.env.PREPARED_RECORD_DIGEST=prepared.record_digest;
    x.currentJob.steps.push(step(R.TERMINAL_BINDING_STEP+prepared.record_digest));
    let attempts=0;const base=x.api,broken=async(m,p,b,raw)=>{
      if(m==='PATCH'&&p.includes('/issues/comments/')){attempts++;throw new R.Refusal('api_http_503');}
      return base(m,p,b,raw);
    };broken.artifact=base.artifact;broken.jobLog=base.jobLog;
    await refuses(()=>C.resumeTerminal({...x.options(),api:broken}),'write_unknown');
    equal(attempts,1,'continuation: UNKNOWN publisher write is never retried');
    equal(x.f.comment.body,'PR1505: выпуск занят','continuation: uncertain write does not invent a release');
  }
}
async function terminalReadCases(){
  let reads=0,writes=0,waits=0;
  const raw=async(method)=>{if(method!=='GET'){writes++;throw new R.Refusal('api_http_503');}
    reads++;if(reads<=2)throw new R.Refusal('api_http_503');return 'actual read';};
  const api=C.terminalReads(raw,{sleep:async()=>{waits++;}});
  equal(await api('GET',R.API_ROOT+'/actions/runs/100'),'actual read','two transient reads can converge');
  equal(reads,3);equal(waits,2);
  await refuses(()=>api('PATCH',R.API_ROOT+'/issues/comments/900',{body:'unused'}),'api_http_503');
  equal(writes,1,'no mutation retry after503');equal(waits,2,'writes receive no read budget');
  for(const reason of ['api_http_429','api_http_403','transport_failed']){
    let calls=0;const blocked=C.terminalReads(async()=>{calls++;throw new R.Refusal(reason);},{sleep:async()=>{throw Error('unexpected wait');}});
    await refuses(()=>blocked('GET',R.API_ROOT+'/actions/runs/100'),reason);equal(calls,1,'other errors remain stops');
  }
  let failures=0;const outage=C.terminalReads(async()=>{failures++;throw new R.Refusal('api_http_503');},{sleep:async()=>{}});
  await refuses(()=>outage('GET',R.API_ROOT+'/actions/runs/100'),'api_http_503');equal(failures,3,'a sustained outage stops after two additional GETs');
}
async function transientReadCases(){
  const before=assertions,endpoint=R.API_ROOT+'/git/ref/heads/main';
  {
    let calls=0;const read=R.makeReadApi('test-read-token',async()=>{calls++;
      return calls===1?new Response('temporary',{status:502}):new Response('{"ok":true}',{status:200});},
      {sleep:async()=>{}});
    equal((await read('GET',endpoint)).ok,true);equal(calls,2,'502: repeat only the idempotent failed read');
  }
  for(const status of [502,503])for(const method of ['POST','PATCH']){
    let calls=0;const write=C.makeApi('test-write-token',async()=>{calls++;return new Response('temporary',{status});});
    await refuses(()=>write(method,method==='PATCH'?R.API_ROOT+'/issues/comments/1':R.API_ROOT+'/git/trees',
      method==='PATCH'?{body:'terminal'}:{base_tree:H('tree'),tree:[]}),'api_http_'+status);
    equal(calls,1,'gateway responses never retry writes');
  }
  {
    let calls=0;const api=C.makeApi('test-read-token',async()=>{calls++;return new Response('temporary',{status:503});});
    const limited=C.terminalReads(api,{sleep:async()=>{}});
    await refuses(()=>limited('GET',endpoint),'api_http_503');equal(calls,3,'legacy phase ceiling cannot multiply common retries');
  }
  {
    const calls=[],delays=[],current=H('current after temporary refusal');
    const read=R.makeReadApi('test-read-token',async(url,options)=>{
      calls.push({url,method:options.method,authorization:options.headers.Authorization});
      return calls.length===1?new Response('temporary',{status:503}):
        new Response(JSON.stringify({object:{sha:current}}),{status:200});
    },{sleep:async(ms)=>{delays.push(ms);}});
    equal((await read('GET',endpoint)).object.sha,current,'503: use the actual later read, never cached source');
    equal(calls.map(x=>x.method),['GET','GET'],'503: only repeat the failed read');
    equal(calls[0],calls[1],'503: same endpoint and authority');
    equal(delays,[250],'503: one bounded backoff');
  }
  {
    let calls=0;const delays=[];
    const read=R.makeReadApi('test-read-token',async()=>{calls++;return new Response('temporary',{status:503});},
      {sleep:async(ms)=>{delays.push(ms);}});
    await refuses(()=>read('GET',endpoint),'api_http_503');
    equal(calls,3,'503: finite ceiling of three reads');
    equal(delays,[250,750],'503: two finite delays');
  }
  for(const status of [400,401,403,404,429,500,504]){
    let calls=0;const read=R.makeReadApi('test-read-token',async()=>{calls++;return new Response('refused',{status});},
      {sleep:async()=>{throw Error('unexpected delay');}});
    await refuses(()=>read('GET',endpoint),'api_http_'+status);
    equal(calls,1,'non-temporary refusal is not retried');
  }
  {
    let calls=0;const delays=[];
    const read=R.makeReadApi('test-read-token',async()=>{calls++;return calls===1?
      new Response('temporary',{status:503,headers:{'retry-after':'1'}}):
      new Response('{"available":true}',{status:200});},{sleep:async(ms)=>{delays.push(ms);}});
    equal((await read('GET',endpoint)).available,true);
    equal(delays,[1000],'503: respect the server delay');
  }
  {
    let calls=0;const read=R.makeReadApi('test-read-token',async()=>{calls++;return new Response('temporary',
      {status:503,headers:{'retry-after':'120'}});},{sleep:async()=>{throw Error('outside the read deadline');}});
    await refuses(()=>read('GET',endpoint),'api_http_503');
    equal(calls,1,'503: no wait or retry beyond the existing total deadline');
  }
  {
    let calls=0;const external=new AbortController();
    const read=R.makeReadApi('test-read-token',async()=>{calls++;return new Response('temporary',{status:503});},
      {sleep:async(ms,value,options)=>{external.abort();equal(options.signal.aborted,true);throw Error('aborted');}});
    await refuses(()=>read('GET',endpoint,undefined,false,external.signal),'transport_failed');
    equal(calls,1,'503: abort stops the retry before another read');
  }
  for(const [method,path,body]of [
    ['PATCH',R.API_ROOT+'/issues/comments/1',{body:'same terminal'}],
    ['POST',R.API_ROOT+'/git/commits',{message:'test',tree:H('tree'),parents:[H('parent')]}]]){
    let calls=0;const write=C.makeApi('test-write-token',async()=>{calls++;return new Response('temporary',{status:503});});
    await refuses(()=>write(method,path,body),'api_http_503');
    equal(calls,1,'503: never retry a metadata mutation');
  }
  return assertions-before;
}


function gateContinuationFixture(){
  const p=R.GATE_RECOVERY,record={"bundle":"success","diagnostics":"success","diagnostics_attempt":1,"install_attempt":1,"install_checks":"success","key":"success","max_webhook":"success","metrika_cron":"success","migration":"success","provenance":"success","reported_at":"2026-11-12T16:04:56Z","repository_release":{"application_sha":"0cb62969d0a8fa59b1872df465b3810bf5b569d4","baseline":null,"baseline_eligible":false,"closure":null,"content":{"candidate_sha":"0cb62969d0a8fa59b1872df465b3810bf5b569d4","classification":"unconfirmed","installed_sha":"0cb62969d0a8fa59b1872df465b3810bf5b569d4","policy":"anytour-development-content-v1","release_path":"full_required","runtime_fingerprint":null},"intent_scan":{"status":"unknown"},"kind":"installed","observation":null,"observation_producer":null,"phase":"pending_closure","release":{"event":"push","run_attempt":2,"run_id":"43250756234"},"repository_sha":"0cb62969d0a8fa59b1872df465b3810bf5b569d4","schema":1,"stages":{"bundle":{"execution":"performed","result":"success"},"diagnostics":{"execution":"performed","result":"success"},"install_checks":{"execution":"performed","result":"success"},"key":{"execution":"performed","result":"success"},"max_webhook":{"execution":"performed","result":"success"},"metrika_cron":{"execution":"performed","result":"success"},"migration":{"execution":"performed","result":"success"},"provenance":{"execution":"performed","result":"success"},"smoke":{"execution":"performed","result":"success"},"sync":{"execution":"performed","result":"success"}}},"run_id":"43250756234","schema":2,"sha":"0cb62969d0a8fa59b1872df465b3810bf5b569d4","smoke":"success","smoke_attempt":1,"sync":"success"};
  const f=fixture(p.sha,record);f.runs.clear();f.jobs.clear();
  const w=f.start(p.sha,Number(p.deploy),Number(p.run_id),p.pr);
  const b={comment_id:p.comment_id,head_sha:p.head_sha,owner_id:p.owner_id,updated_at:p.updated_at};
  f.pr={...f.pr,body:'<!-- release-slot-close:v1 '+JSON.stringify(b)+' -->',head:{...f.pr.head,sha:p.head_sha}};
  f.comment={...f.comment,id:p.comment_id,user:{id:p.owner_id},updated_at:p.updated_at,body:'PR1506: выпуск занят'};
  f.records.set(p.commit,{...f.records.get(f.head),bytes:Buffer.from(f.records.get(f.head).bytes)});
  f.commits.set(p.commit,{...copy(f.commits.get(f.head)),sha:p.commit});
  f.commits.get(f.head).parents=[{sha:p.commit}];
  const later='2026-11-06T07:00:00Z',first={...copy(w.parent),status:'completed',conclusion:'failure'};
  const current={...copy(w.parent),run_attempt:2,run_started_at:later,updated_at:later};
  f.runs.set(current.id,current);
  for(const r of w.wave.filter(r=>r.event==='workflow_run'))r.display_title=r.path.endsWith(C.ACTIVATION.file)
    ?'monitor-proof:v1:'+w.wave.find(r=>r.path.endsWith('modeled-conversion-monitor.yml')).id+':1'
    :'deploy-proof:v1:'+p.deploy+':2';
  const execution=j=>({...j,runner_id:123,runner_name:'synthetic',labels:['synthetic'],started_at:T,completed_at:T});
  const old=C.INSTALL_JOBS.map(name=>execution(job(name,first,[step('Required original check')])));
  old.push(execution(job('verify',first,[step(C.VERIFY_STEPS.check),step(C.VERIFY_STEPS.download),
    step(C.VERIFY_STEPS.status,'failure'),step(R.PENDING_BINDING_STEP,'skipped'),step(C.VERIFY_STEPS.cleanup,'skipped')],'failure')));
  const latest=old.filter(j=>j.name!=='verify').map(j=>({...copy(j),id:jobSerial++,run_attempt:2}));
  latest.push(execution(job('verify',current,[step(C.VERIFY_STEPS.check,'skipped'),step(C.VERIFY_STEPS.download,'skipped'),
    step(C.VERIFY_STEPS.status),step(R.PENDING_BINDING_STEP+R.digest(record)),step(C.VERIFY_STEPS.cleanup)])));
  const oldTls=w.wave.find(r=>r.path===p.tls_workflow);f.runs.delete(oldTls.id);oldTls.id=Number(p.tls_run_id);
  oldTls.status='completed';oldTls.conclusion='failure';f.runs.set(oldTls.id,oldTls);
  const tlsFailure=job('strict-tls-preflight',oldTls,[step(R.RESOLVER_STEP,'failure'),
    step('Prepare SSH private key','skipped'),step(p.tls_step,'skipped')],'failure');tlsFailure.id=p.tls_job_id;f.jobs.set(oldTls.id,[tlsFailure]);
  w.own.status='completed';w.own.conclusion='failure';w.own.display_title='deploy-proof:v1:'+p.deploy+':2';
  const closeFailure=job('release-slot',w.own,[step('Prepare the exact release proof before terminal publication','failure'),
    step(R.TERMINAL_BINDING_STEP,'skipped'),step(R.TERMINAL_STEP,'skipped')],'failure');closeFailure.id=p.job_id;
  f.jobs.set(w.own.id,[job('configure',w.own,[],'skipped'),closeFailure]);
  const source=H('checked unstarted gate continuation'),native={...copy(w.own),id:7100,path:R.CLOSER,event:'push',head_sha:source,
    head_branch:p.branch,status:'in_progress',conclusion:null,actor:{id:p.owner_id}};
  const tlsNative={...copy(native),id:7101,path:p.tls_workflow,status:'completed',conclusion:'success'};
  f.runs.set(native.id,native);f.runs.set(tlsNative.id,tlsNative);
  const own=job('release-slot-recovery',native,[],null);own.status='in_progress';own.runner_id=124;f.jobs.set(native.id,[own]);
  f.jobs.set(tlsNative.id,[job('strict-tls-preflight',tlsNative,[step(R.RESOLVER_STEP),step('Prepare SSH private key'),step(p.tls_step)])]);
  for(const [i,name,file]of [[0,'Regression tests','regression-tests.yml'],[1,'Retired domain guard','retired-domain-guard.yml']]){
    f.runs.set(7200+i,{...copy(tlsNative),id:7200+i,path:R.PREFIX+file,event:'pull_request',name});
  }
  f.commits.set(source,{sha:source,parents:[{sha:p.before}]});
  const originalHook=async({method,path,body})=>{
    if(path===R.API_ROOT+'/actions/runs/'+p.deploy+'/attempts/1')return copy(first);
    if(path===R.API_ROOT+'/actions/runs/'+p.deploy+'/attempts/2')return copy(current);
    if(path===R.API_ROOT+'/actions/runs/'+p.deploy+'/jobs?filter=all&per_page=100')return {total_count:old.length+latest.length,jobs:copy([...old,...latest])};
    if(path===R.API_ROOT+'/actions/runs/'+p.deploy+'/attempts/2/jobs?per_page=100')return {total_count:latest.length,jobs:copy(latest)};
    if(path===R.API_ROOT+'/issues/comments/'+p.comment_id){
      if(method==='PATCH')f.comment={...f.comment,body:body.body};return copy(f.comment);
    }
  };
  f.hook=originalHook;f.api.jobLog=async id=>{assert.equal(id,p.tls_job_id);return Buffer.from(T+' deployment_release=refused reason=api_http_502\n');};
  const env={GITHUB_REPOSITORY:R.REPOSITORY,GITHUB_EVENT_NAME:'push',GITHUB_REF:'refs/heads/'+p.branch,
    GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.CLOSER+'@refs/heads/'+p.branch,GITHUB_JOB:'release-slot-recovery',
    GITHUB_RUN_ATTEMPT:'1',GITHUB_RUN_ID:String(native.id),GITHUB_SHA:source};
  const event={repository:{full_name:R.REPOSITORY},ref:env.GITHUB_REF,before:p.before,after:source,
    created:false,deleted:false,forced:false};
  return {f,source,native,tlsNative,own,tlsFailure,closeFailure,old,latest,env,event,originalHook,
    options:()=>({env,event,api:f.api,sleep:async()=>{throw Error('unexpected gate wait');}})};
}
async function gateContinuationCases(){
  for(const [mutate,reason]of [
    [x=>{x.f.main=H('new main');},'main_changed'],
    [x=>{x.f.comment={...x.f.comment,updated_at:T};},'record_changed'],
    [x=>{x.f.comment={...x.f.comment,user:{id:1}};},'foreign_record'],
    [x=>{x.f.runs.get(7200).conclusion='failure';},'recovery_ci_unconfirmed'],
    [x=>{x.f.runs.get(Number(R.GATE_RECOVERY.tls_run_id)).conclusion='success';},'producer_not_successful'],
    [x=>{x.f.runs.get(Number(R.GATE_RECOVERY.run_id)).conclusion='success';},'producer_not_successful']
  ]){
    const x=gateContinuationFixture();mutate(x);
    await refuses(()=>C.resumeGateTerminal({...x.options(),prepare:true}),reason);
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'changed authority or original history cannot mutate');
  }
  {
    const x=gateContinuationFixture(),p=R.GATE_RECOVERY;
    const tlsEnv={...x.env,GITHUB_RUN_ID:String(x.tlsNative.id),GITHUB_JOB:'strict-tls-preflight',
      GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+p.tls_workflow+'@refs/heads/'+p.branch};
    x.tlsNative.status='in_progress';x.tlsNative.conclusion=null;
    const j=x.f.jobs.get(x.tlsNative.id)[0];j.status='in_progress';j.conclusion=null;j.runner_id=125;
    equal((await R.resolveConsumer({env:tlsEnv,event:x.event,api:x.f.api})).application_sha,p.sha,
      'only the exact native source can enter the previously unstarted TLS gate');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'TLS admission is read only before credentials');
  }
  {
    const x=gateContinuationFixture(),p=R.GATE_RECOVERY;
    const proof=await C.resumeGateTerminal({...x.options(),prepare:true});
    equal(x.f.comment.body,'PR1506: выпуск занят','preparation cannot release publisher');
    equal(R.validateRelease(x.f.record).release.closure.recovery.kind,'unstarted_tls_v1');
    equal(x.f.record.install_attempt,1);equal(x.f.record.smoke_attempt,1);equal(x.f.record.diagnostics_attempt,1);
    const gate=x.f.record.repository_release.closure.gates.find(g=>g.path===p.tls_workflow);
    equal(gate.run_id,String(x.tlsNative.id),'terminal cites actual new TLS run, never the failed original');
    equal(x.tlsFailure.conclusion,'failure');equal(x.closeFailure.conclusion,'failure');
    x.own.steps.push(step(R.TERMINAL_BINDING_STEP+proof.record_digest));x.env.PREPARED_RECORD_DIGEST=proof.record_digest;
    equal(await C.resumeGateTerminal(x.options()),'released');
    equal(x.f.calls.filter(c=>c.method==='PATCH'&&c.path.endsWith('/issues/comments/'+p.comment_id)).length,1);
    x.own.steps.push(step(R.TERMINAL_STEP));x.own.status='completed';x.own.conclusion='success';
    x.native.status='completed';x.native.conclusion='success';
    await R.authenticatePublication(x.f.api,await R.readRecord(x.f.api),{closed:true});
    equal(x.f.comment.body,'PR1506: установлено, выпуск свободен','consumer authenticates actual native terminal lineage');
  }
  for(const mutate of [
    x=>{x.env.GITHUB_RUN_ATTEMPT='2';},x=>{x.event.forced=true;},x=>{x.event.created=true;},
    x=>{x.event.before=H('foreign predecessor');},x=>{x.env.GITHUB_JOB='configure';},
    x=>{x.env.GITHUB_WORKFLOW_REF='foreign';},x=>{x.event.after=H('other source');}
  ]){
    const x=gateContinuationFixture();mutate(x);await refuses(()=>C.resumeGateTerminal({...x.options(),prepare:true}),'untrusted_gate_continuation');
    equal(x.f.calls.length,0,'invalid context cannot reach any credential, proof or mutation');
  }
  for(const mutate of [
    x=>{x.tlsFailure.steps[1].conclusion='success';},x=>{x.tlsFailure.steps[2].conclusion='success';},
    x=>{x.tlsFailure.steps[0].conclusion='success';},x=>{x.tlsFailure.steps.pop();},
    x=>{x.tlsFailure.steps.push(copy(x.tlsFailure.steps[1]));}
  ]){
    const x=gateContinuationFixture();mutate(x);
    await refuses(()=>C.resumeGateTerminal({...x.options(),prepare:true}),'failed_gate_executed');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'executed or ambiguous original gate cannot be repeated');
  }
  for(const mutate of [
    x=>{x.closeFailure.steps[1].conclusion='success';},x=>{x.closeFailure.steps[2].conclusion='success';}
  ]){
    const x=gateContinuationFixture();mutate(x);
    await refuses(()=>C.resumeGateTerminal({...x.options(),prepare:true}),mutate.toString().includes('steps[2]')?'failed_gate_executed':'failed_terminal_executed');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'old prepared or terminal work is never repeated');
  }
  {
    const x=gateContinuationFixture();x.f.jobs.get(x.tlsNative.id)[0].steps[2].conclusion='skipped';
    await refuses(()=>C.resumeGateTerminal({...x.options(),prepare:true}),'tls_continuation_unconfirmed');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'no accepted terminal without actual successful TLS');
  }
  {
    const x=gateContinuationFixture();x.f.api.jobLog=async()=>Buffer.from(T+' deployment_release=refused reason=api_http_429\n');
    await refuses(()=>C.resumeGateTerminal({...x.options(),prepare:true}),'failed_gate_reason_changed');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'unknown or other original refusal cannot use this recipe');
  }
  {
    const x=gateContinuationFixture(),p=R.GATE_RECOVERY;
    x.f.runs.get(Number(p.deploy)+3).conclusion='failure';
    await refuses(()=>C.resumeGateTerminal({...x.options(),prepare:true}),'required_gate_failed');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'every other original gate remains mandatory');
  }
  {
    const x=gateContinuationFixture(),p=R.GATE_RECOVERY;
    const proof=await C.resumeGateTerminal({...x.options(),prepare:true});
    x.own.steps.push(step(R.TERMINAL_BINDING_STEP+proof.record_digest));x.env.PREPARED_RECORD_DIGEST=proof.record_digest;
    x.f.hook=async call=>{if(call.method==='PATCH'&&call.path.endsWith('/issues/comments/'+p.comment_id))throw new C.Refusal('api_http_503');
      return x.originalHook(call);};
    await refuses(()=>C.resumeGateTerminal(x.options()),'write_unknown');
    equal(x.f.calls.filter(c=>c.method==='PATCH'&&c.path.endsWith('/issues/comments/'+p.comment_id)).length,1,'UNKNOWN terminal never resends');
    equal(x.f.comment.body,'PR1506: выпуск занят');
  }
}


// The public, immutable original receipts are used as fixed negative/positive
// boundary input. All API/run/job identities below are synthetic in-memory.
function unpublishedTerminalFixture(){
  const pending={"bundle":"success","diagnostics":"success","diagnostics_attempt":1,"install_attempt":1,"install_checks":"success","key":"success","max_webhook":"success","metrika_cron":"success","migration":"success","provenance":"success","reported_at":"2026-11-13T16:00:56Z","repository_release":{"application_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","baseline":null,"baseline_eligible":false,"closure":null,"content":{"candidate_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","classification":"unconfirmed","installed_sha":"5fbb61990706ae4289e539df2c26c60e409032f4","policy":"anytour-development-content-v1","release_path":"full_required","runtime_fingerprint":null},"intent_scan":{"checked_commits":5,"coverage":"registered_markers_and_reserved_families","matched_commits":[],"status":"no_registered_intent"},"kind":"installed","observation":null,"observation_producer":null,"phase":"pending_closure","release":{"event":"push","run_attempt":1,"run_id":"43456259794"},"repository_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","schema":1,"stages":{"bundle":{"execution":"performed","result":"success"},"diagnostics":{"execution":"performed","result":"success"},"install_checks":{"execution":"performed","result":"success"},"key":{"execution":"performed","result":"success"},"max_webhook":{"execution":"performed","result":"success"},"metrika_cron":{"execution":"performed","result":"success"},"migration":{"execution":"performed","result":"success"},"provenance":{"execution":"performed","result":"success"},"smoke":{"execution":"performed","result":"success"},"sync":{"execution":"performed","result":"success"}}},"run_id":"43456259794","schema":2,"sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","smoke":"success","smoke_attempt":1,"sync":"success"},terminal={"bundle":"success","diagnostics":"success","diagnostics_attempt":1,"install_attempt":1,"install_checks":"success","key":"success","max_webhook":"success","metrika_cron":"success","migration":"success","provenance":"success","reported_at":"2026-11-13T16:00:56Z","repository_release":{"application_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","baseline":null,"baseline_eligible":false,"closure":{"binding":{"comment_id":6775467253,"head_sha":"51a900b316cad03a41b07c6a79e36e6ee322c029","owner_id":226193297,"pr":1529,"updated_at":"2026-11-13T15:47:04Z"},"expected_terminal":"PR1529: установлено, выпуск свободен","gates":[{"conclusion":"success","head_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","path":".github/workflows/deploy.yml","run_attempt":1,"run_id":"43456259794"},{"conclusion":"success","head_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","path":".github/workflows/retired-domain-guard.yml","run_attempt":1,"run_id":"43456259765"},{"conclusion":"success","head_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","path":".github/workflows/max-tls-preflight.yml","run_attempt":1,"run_id":"43458415792"},{"conclusion":"success","head_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","path":".github/workflows/max-production-health.yml","run_attempt":1,"run_id":"43458412674"},{"conclusion":"success","head_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","path":".github/workflows/telegram-production-health.yml","run_attempt":1,"run_id":"43458412715"},{"conclusion":"success","head_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","path":".github/workflows/publish-conversation-diagnostics.yml","run_attempt":1,"run_id":"43458415744"},{"conclusion":"success","head_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","path":".github/workflows/live-session-diagnostics.yml","run_attempt":1,"run_id":"43458412698"},{"conclusion":"success","head_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","path":".github/workflows/tour-selection-host.yml","run_attempt":1,"run_id":"43458412489"},{"conclusion":"success","head_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","path":".github/workflows/modeled-conversion-monitor.yml","run_attempt":1,"run_id":"43458412487"},{"conclusion":"skipped","head_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","path":".github/workflows/modeled-pipeline-monitor-activation.yml","run_attempt":1,"run_id":"43458573580"}],"job":"release-slot","run_attempt":1,"run_id":"43458412572","terminal_step":"Release only the admitted publisher after all original mandatory gates"},"content":{"candidate_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","classification":"unconfirmed","installed_sha":"5fbb61990706ae4289e539df2c26c60e409032f4","policy":"anytour-development-content-v1","release_path":"full_required","runtime_fingerprint":null},"intent_scan":{"checked_commits":5,"coverage":"registered_markers_and_reserved_families","matched_commits":[],"status":"no_registered_intent"},"kind":"installed","observation":{"application_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","components":{"editor":null,"public":null},"configuration_key_id":null,"core_digest":null,"digest":null,"mode":"all","protocol":"anytour-deployment-runtime-v1","reason_code":"configuration_loader_unsupported","schema":1,"status":"unknown"},"observation_producer":{"run_attempt":1,"run_id":"43458412489"},"phase":"pending_terminal","release":{"event":"push","run_attempt":1,"run_id":"43456259794"},"repository_sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","schema":1,"stages":{"bundle":{"execution":"performed","result":"success"},"diagnostics":{"execution":"performed","result":"success"},"install_checks":{"execution":"performed","result":"success"},"key":{"execution":"performed","result":"success"},"max_webhook":{"execution":"performed","result":"success"},"metrika_cron":{"execution":"performed","result":"success"},"migration":{"execution":"performed","result":"success"},"provenance":{"execution":"performed","result":"success"},"smoke":{"execution":"performed","result":"success"},"sync":{"execution":"performed","result":"success"}}},"run_id":"43456259794","schema":2,"sha":"8d6ac35dd17614b2cadb3aa3af7637c74280c01c","smoke":"success","smoke_attempt":1,"sync":"success"},p=R.UNPUBLISHED_TERMINAL_RECOVERY;
  const f=fixture(p.sha,pending),initial=f.head;
  const alias=(from,to,parent=null)=>{const x=f.records.get(from),c=f.commits.get(from);f.records.set(to,x);
    f.commits.set(to,{...copy(c),sha:to,parents:parent?[{sha:parent}]:[]});};
  alias(initial,p.pending_commit);
  f.putRecord(terminal,p.pending_commit);const originalHead=f.head;alias(originalHead,p.commit,p.pending_commit);f.head=p.commit;
  const wave=f.start(p.sha,Number(p.deploy),Number(p.run_id),p.pr);f.runs.clear();f.jobs.clear();
  for(const r of wave.wave){const g=terminal.repository_release.closure.gates.find(g=>g.path===r.path);r.id=Number(g.run_id);
    r.conclusion=g.conclusion;f.runs.set(r.id,r);}
  const monitor=wave.wave.find(r=>r.path.endsWith('modeled-conversion-monitor.yml'));
  const activation=wave.wave.find(r=>r.path.endsWith(C.ACTIVATION.file));activation.display_title='monitor-proof:v1:'+monitor.id+':1';
  f.jobs.set(activation.id,[job('activate',activation,[],'skipped')]);
  f.jobs.set(Number(p.deploy),fullJobs(wave.parent,pending));
  const failed=wave.own;failed.status='completed';failed.conclusion='failure';f.runs.set(failed.id,failed);
  const failure=job('release-slot',failed,[step('Prepare the exact release proof before terminal publication','failure'),
    step(R.TERMINAL_BINDING_STEP,'skipped'),step(R.TERMINAL_STEP,'skipped')],'failure');failure.id=p.job_id;
  f.jobs.set(failed.id,[job('configure',failed,[],'skipped'),failure,job('release-slot-recovery',failed,[],'skipped')]);
  const b={comment_id:p.comment_id,head_sha:p.head_sha,owner_id:p.owner_id,updated_at:p.updated_at};
  f.pr={...f.pr,number:p.pr,head:{sha:p.head_sha,repo:{full_name:R.REPOSITORY}},body:'<!-- release-slot-close:v1 '+JSON.stringify(b)+' -->'};
  f.comment={...f.comment,id:p.comment_id,user:{id:p.owner_id},updated_at:p.updated_at,body:'PR1529: выпуск занят'};
  const source=H('source-checked unpublished1529 completion'),runId=700000;
  const native={...copy(failed),id:runId,head_sha:source,head_branch:p.branch,event:'push',status:'in_progress',conclusion:null,
    actor:{id:p.owner_id}};delete native.display_title;f.runs.set(runId,native);
  const own=job('release-slot-recovery',native,[],null);own.status='in_progress';own.runner_id=345;
  f.jobs.set(runId,[own,job('configure',native,[],'skipped'),job('release-slot',native,[],'skipped')]);
  for(const [i,name,file]of [[1,'Regression tests','regression-tests.yml'],[2,'Retired domain guard','retired-domain-guard.yml']])
    f.runs.set(runId+i,{...copy(native),id:runId+i,name,path:R.PREFIX+file,event:'pull_request',status:'completed',conclusion:'success'});
  f.api.jobLog=async id=>{equal(id,p.job_id,'only the fixed stopped original log is read');return Buffer.from(T+' release_slot=held reason=diagnostics_write_unknown\n');};
  const originalHook=async call=>{
    const q=call.path;
    if(q===R.API_ROOT+'/git/ref/heads/diagnostics'&&f.head===originalHead)return {object:{sha:p.commit}};
    if(q===R.API_ROOT+'/compare/'+p.sha+'...'+source+'?per_page=1')return {status:'ahead',merge_base_commit:{sha:p.sha}};
    if(q===R.API_ROOT+'/contents/tools/release_slot_close.js?ref='+p.sha)return {sha:p.original_code};
    if(q===R.API_ROOT+'/issues/comments/'+p.comment_id){if(call.method==='PATCH')f.comment={...f.comment,body:call.body.body};return copy(f.comment);}
  };
  f.hook=originalHook;
  const env={GITHUB_REPOSITORY:R.REPOSITORY,GITHUB_EVENT_NAME:'push',GITHUB_REF:'refs/heads/'+p.branch,GITHUB_SHA:source,
    GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.CLOSER+'@refs/heads/'+p.branch,GITHUB_JOB:'release-slot-recovery',
    GITHUB_RUN_ID:String(runId),GITHUB_RUN_ATTEMPT:'1'};
  const event={repository:{full_name:R.REPOSITORY},ref:env.GITHUB_REF,before:'0'.repeat(40),after:source,created:true,deleted:false,forced:false};
  return {f,p,env,event,source,native,own,failure,originalHook,options:()=>({env,event,api:f.api})};
}
async function unpublishedTerminalCases(){
  {
    const x=unpublishedTerminalFixture();const original=await R.failedUnpublishedTerminal(x.f.api);
    equal(original.record_digest,x.p.record_digest,'actual written original is proved without upload or terminal invention');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'original reconciliation is read-only');
    const proof=await C.resumeUnpublishedTerminal({...x.options(),prepare:true});
    equal(x.f.comment.body,'PR1529: выпуск занят','new proof preparation holds same publisher');
    equal(x.f.record.repository_release.closure.recovery.kind,'unpublished_terminal_v1','new native lineage is explicit');
    equal(x.f.calls.filter(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')).length,0,'preparation never frees');
    x.own.steps.push(step(R.TERMINAL_BINDING_STEP+proof.record_digest));x.env.PREPARED_RECORD_DIGEST=proof.record_digest;
    equal(await C.resumeTerminal(x.options()),'released','existing recovery entry point completes this fixed case');
    equal(x.f.calls.filter(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')).length,1,'one terminal PATCH only');
    x.native.status='completed';x.native.conclusion='success';x.own.status='completed';x.own.conclusion='success';x.own.steps.push(step(R.TERMINAL_STEP));
    const location=await R.readRecord(x.f.api);await R.authenticatePublication(x.f.api,location,{closed:true});
    equal(x.failure.conclusion,'failure','failed original retains failure after accepted new completion');
    equal(x.f.record.repository_release.observation.status,'unknown','runtime UNKNOWN stays unknown');
    equal((await R.resolveCurrent(x.f.api,{sha:x.p.sha},location)).application_sha,x.p.sha,'canonical consumers accept honest continuation');
    const before=x.f.calls.filter(c=>c.method!=='GET').length;
    await refuses(()=>C.resumeUnpublishedTerminal(x.options()),'unpublished_continuation_not_active');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,before,'completed native continuation cannot repeat writes');
  }
  for(const mutate of [x=>x.env.GITHUB_RUN_ATTEMPT='2',x=>x.env.GITHUB_REF='refs/heads/foreign',x=>x.event.before=H('old'),
    x=>x.event.created=false,x=>x.event.forced=true,x=>x.event.deleted=true,x=>x.event.after=H('other'),x=>x.env.GITHUB_SHA=x.p.sha]){
    const x=unpublishedTerminalFixture();mutate(x);await refuses(()=>C.resumeUnpublishedTerminal({...x.options(),prepare:true}),'untrusted_unpublished_terminal_continuation');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'changed origin refuses before writes');
  }
  for(const [mutate,reason]of [
    [x=>x.f.main=H('new main'),'main_changed'],[x=>x.native.actor.id++,'unpublished_continuation_not_active'],
    [x=>x.own.runner_id=0,'unpublished_continuation_not_active'],[x=>x.f.runs.get(x.native.id+1).conclusion='failure','recovery_ci_unconfirmed'],
    [x=>x.f.comment.updated_at='2026-11-13T15:47:05Z','record_changed'],[x=>x.f.comment.user.id++,'foreign_record'],
    [x=>x.failure.steps[1].conclusion='success','failed_terminal_executed'],[x=>x.failure.steps[2].conclusion='success','failed_terminal_executed'],
    [x=>x.failure.run_attempt=2,'failed_terminal_not_original'],[x=>x.f.runs.get(43458415792).conclusion='failure','required_gate_failed'],
    [x=>x.f.jobs.get(43458573580)[0].steps.push(step('protected operation')),'activation_executed'],
    [x=>x.f.jobs.get(Number(x.p.run_id))[0].steps.push(step('configuration')),'configuration_executed'],
    [x=>x.f.artifacts.set(Number(x.p.run_id),[{id:123}]),'original_terminal_already_published']
  ]){
    const x=unpublishedTerminalFixture();mutate(x);await refuses(()=>C.resumeUnpublishedTerminal({...x.options(),prepare:true}),reason);
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'changed fixed evidence keeps publisher without writes');
  }
  {
    const x=unpublishedTerminalFixture();x.f.api.jobLog=async()=>Buffer.from(T+' release_slot=held reason=write_unknown\n');
    await refuses(()=>C.resumeUnpublishedTerminal({...x.options(),prepare:true}),'failed_terminal_reason_changed');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'unknown comment write cannot use this exception');
  }
  {
    const x=unpublishedTerminalFixture();const proof=await C.resumeUnpublishedTerminal({...x.options(),prepare:true});
    x.env.PREPARED_RECORD_DIGEST=proof.record_digest;
    await refuses(()=>C.resumeUnpublishedTerminal(x.options()),'publication_step_unconfirmed');
    equal(x.f.calls.filter(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')).length,0,'missing real upload holds');
    x.own.steps.push(step(R.TERMINAL_BINDING_STEP+proof.record_digest));
    x.f.hook=async c=>{if(c.method==='PATCH'&&c.path.includes('/issues/comments/'))throw new C.Refusal('api_http_503');return x.originalHook(c);};
    await refuses(()=>C.resumeUnpublishedTerminal(x.options()),'write_unknown');
    equal(x.f.calls.filter(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/')).length,1,'uncertain terminal write never retries');
    equal(x.f.comment.body,'PR1529: выпуск занят','unknown terminal is not accepted');
  }
}

// Fixed incident pins use the real public receipt bytes; every API/job below is
// synthetic. No code under test is replaced and no remote service is contacted.
function priorWaveFixture(){
  const p=R.PRIOR_WAVE_RECOVERY,q=p.previous;
  const pending={"bundle":"success","diagnostics":"success","diagnostics_attempt":1,"install_attempt":1,"install_checks":"success","key":"success","max_webhook":"success","metrika_cron":"success","migration":"success","provenance":"success","reported_at":"2026-11-17T12:17:04Z","repository_release":{"application_sha":p.sha,"baseline":null,"baseline_eligible":false,"closure":null,"content":{"candidate_sha":p.sha,"classification":"unconfirmed","installed_sha":"6d3da4fb7602de9e6dcd7ba3af06f70749606f1f","policy":"anytour-development-content-v1","release_path":"full_required","runtime_fingerprint":null},"intent_scan":{"checked_commits":9,"coverage":"registered_markers_and_reserved_families","matched_commits":[],"status":"no_registered_intent"},"kind":"installed","observation":null,"observation_producer":null,"phase":"pending_closure","release":{"event":"push","run_attempt":1,"run_id":p.deploy},"repository_sha":p.sha,"schema":1,"stages":Object.fromEntries(R.INSTALL_STAGES.map(s=>[s,{execution:'performed',result:'success'}]))},"run_id":p.deploy,"schema":2,"sha":p.sha,"smoke":"success","smoke_attempt":1,"sync":"success"};
  equal(R.gitBlob(Buffer.from(R.wire(pending))),p.blob,'fixed prior-wave pending blob is reproduced');
  equal(R.digest(pending),p.record_digest,'fixed prior-wave pending digest is reproduced');
  const f=fixture(p.sha,pending),original=f.head,originalCommit=f.commits.get(original);
  f.records.set(p.commit,f.records.get(original));
  f.commits.set(p.commit,{...copy(originalCommit),sha:p.commit});f.head=p.commit;
  const wave=f.start(p.sha,Number(p.deploy),Number(p.run_id),p.pr);f.runs.clear();f.jobs.clear();
  for(const r of [...wave.wave,wave.own])f.runs.set(r.id,r);
  f.jobs.set(wave.parent.id,fullJobs(wave.parent,pending));
  const activation=wave.wave.find(r=>r.path.endsWith(C.ACTIVATION.file));
  f.jobs.set(activation.id,[job('activate',activation,[],'skipped')]);
  const failed=wave.own;failed.status='completed';failed.conclusion='failure';
  const failure=job('release-slot',failed,[step('Prepare the exact release proof before terminal publication','failure'),
    step(R.TERMINAL_BINDING_STEP,'skipped'),step(R.TERMINAL_STEP,'skipped')],'failure');failure.id=p.job_id;
  f.jobs.set(failed.id,[job('configure',failed,[],'skipped'),failure,job('release-slot-recovery',failed,[],'skipped')]);
  const marker=x=>({comment_id:x.comment_id,head_sha:x.head_sha,owner_id:x.owner_id,updated_at:x.updated_at});
  const pull=x=>({number:x.pr,state:'closed',merged:true,merge_commit_sha:x.sha,
    head:{sha:x.head_sha,repo:{full_name:R.REPOSITORY}},base:{ref:'main',repo:{full_name:R.REPOSITORY}},
    body:'<!-- release-slot-close:v1 '+JSON.stringify(marker(x))+' -->'});
  const comment=x=>({id:x.comment_id,body:'PR'+x.pr+': выпуск занят',user:{id:x.owner_id},updated_at:x.updated_at,
    author_association:'OWNER',issue_url:'https://api.github.com'+R.API_ROOT+'/issues/1122'});
  f.pr=pull(p);f.comment=comment(p);const oldPr=pull(q),oldComment=comment(q);
  const previous={...copy(wave.parent),id:Number(q.deploy),head_sha:q.sha,head_commit:{id:q.sha,message:'Original previous source'},
    created_at:'2026-11-06T05:00:00Z',updated_at:'2026-11-06T05:30:00Z',status:'completed',conclusion:'failure'};
  f.runs.set(previous.id,previous);
  const denied=['Prepare canonical SSH private key','Verify installed production checks','Download exact installed diagnostic snapshot',
    'Publish verification stage status','Remove acknowledged diagnostic snapshot'];
  const previousVerify=job('verify',previous,[step('Admit only unfinished verification without installation','failure'),
    step('Require complete installed verification','failure'),...denied.map(n=>step(n,'skipped'))],'failure');
  previousVerify.id=q.verify_job_id;
  f.jobs.set(previous.id,[...C.INSTALL_JOBS.map(n=>job(n,previous,n==='deploy'?INSTALL_EXECUTION.map(n=>step(n)):[])),previousVerify]);
  const oldTag='deploy-proof:v1:'+q.deploy+':1';
  const childNames={'max-tls-preflight.yml':['strict-tls-preflight'],'max-production-health.yml':['inspect'],
    'telegram-production-health.yml':['inspect'],'publish-conversation-diagnostics.yml':['publish'],'live-session-diagnostics.yml':['publish'],
    'tour-selection-host.yml':['host','independent-editor'],'modeled-conversion-monitor.yml':['monitor']};
  const oldChildren=C.REQUIRED.filter(([file,event])=>event==='workflow_run'&&file!==C.ACTIVATION.file).map(([file],i)=>{
    const child={...copy(wave.wave.find(r=>r.path===R.PREFIX+file)),id:({'max-production-health.yml':44079622025,'telegram-production-health.yml':44079621974,'max-tls-preflight.yml':44079622090,'tour-selection-host.yml':44079622065,'live-session-diagnostics.yml':44079621931,'publish-conversation-diagnostics.yml':44079621993,'modeled-conversion-monitor.yml':44079622033})[file],name:oldTag,display_title:oldTag,
      status:'completed',conclusion:'skipped'};
    f.runs.set(child.id,child);f.jobs.set(child.id,childNames[file].map(n=>job(n,child,[],'skipped')));return child;
  });
  const oldMonitor=oldChildren.find(r=>r.path.endsWith('modeled-conversion-monitor.yml'));
  const oldCloser={...copy(failed),id:44079622034,name:oldTag,display_title:oldTag,conclusion:'skipped'};
  f.runs.set(oldCloser.id,oldCloser);f.jobs.set(oldCloser.id,['configure','release-slot','release-slot-recovery'].map(n=>job(n,oldCloser,[],'skipped')));
  const oldActivationTag='monitor-proof:v1:'+oldMonitor.id+':1';
  const oldActivation={...copy(activation),id:44079634544,name:oldActivationTag,display_title:oldActivationTag,conclusion:'skipped'};
  f.runs.set(oldActivation.id,oldActivation);f.jobs.set(oldActivation.id,[job('activate',oldActivation,[],'skipped')]);
  const source=H('source-checked prior1597 completion'),runId=800000;
  const native={...copy(failed),id:runId,head_sha:source,head_commit:{id:source,message:'Finite metadata continuation'},head_branch:p.branch,
    event:'push',status:'in_progress',conclusion:null,actor:{id:p.owner_id}};
  delete native.display_title;f.runs.set(runId,native);
  const own=job('release-slot-recovery',native,[],null);own.status='in_progress';own.runner_id=345;
  f.jobs.set(runId,[own,job('configure',native,[],'skipped'),job('release-slot',native,[],'skipped')]);
  for(const [i,name,file]of [[1,'Regression tests','regression-tests.yml'],[2,'Retired domain guard','retired-domain-guard.yml']])
    f.runs.set(runId+i,{...copy(native),id:runId+i,name,path:R.PREFIX+file,event:'pull_request',status:'completed',conclusion:'success'});
  f.api.jobLog=async id=>{
    assert.ok([p.job_id,q.verify_job_id].includes(id),'only the two pinned original logs are read');
    return Buffer.from(T+' release_slot=held reason='+(id===p.job_id?'ambiguous_run':'main_changed')+'\n');
  };
  const originalHook=async call=>{
    const u=call.path;
    if(u===R.API_ROOT+'/git/ref/heads/'+p.branch)return {object:{sha:source}};
    if(u===R.API_ROOT+'/actions/workflows/modeled-conversion-value-export.yml/runs?branch='+p.branch+'&event=push&per_page=100'){
      const list=[...f.runs.values()].filter(r=>r.event==='push'&&r.head_branch===p.branch&&r.path===R.CLOSER);
      return {total_count:list.length,workflow_runs:copy(list)};
    }
    if(u===R.API_ROOT+'/compare/'+p.sha+'...'+source+'?per_page=1')return {status:'ahead',merge_base_commit:{sha:p.sha}};
    if(u===R.API_ROOT+'/compare/'+q.sha+'...'+p.sha)return {status:'ahead',merge_base_commit:{sha:q.sha},ahead_by:1,behind_by:0};
    if(u===R.API_ROOT+'/contents/tools/release_slot_close.js?ref='+p.sha)return {sha:p.original_code};
    if(u===R.API_ROOT+'/pulls/'+q.pr)return copy(oldPr);
    if(u===R.API_ROOT+'/issues/comments/'+q.comment_id){if(call.method==='PATCH')oldComment.body=call.body.body;return copy(oldComment);}
    if(u===R.API_ROOT+'/issues/comments/'+p.comment_id){if(call.method==='PATCH')f.comment={...f.comment,body:call.body.body};return copy(f.comment);}
  };
  f.hook=originalHook;
  const env={GITHUB_REPOSITORY:R.REPOSITORY,GITHUB_EVENT_NAME:'push',GITHUB_REF:'refs/heads/'+p.branch,GITHUB_SHA:source,
    GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.CLOSER+'@refs/heads/'+p.branch,GITHUB_JOB:'release-slot-recovery',
    GITHUB_RUN_ID:String(runId),GITHUB_RUN_ATTEMPT:'1'};
  const event={repository:{full_name:R.REPOSITORY},ref:env.GITHUB_REF,before:'0'.repeat(40),after:source,created:true,deleted:false,forced:false};
  return {f,p,q,env,event,source,native,own,failure,previous,previousVerify,oldChildren,oldCloser,oldActivation,activation,
    oldPr,oldComment,originalHook,options:()=>({env,event,api:f.api}),writes:()=>f.calls.filter(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/'))};
}
async function priorWaveCases(){
  {
    const x=priorWaveFixture(),initial=copy(x.f.record);
    const evidence=await R.failedPriorWaveTerminal(x.f.api);
    equal(evidence.original.record_digest,x.p.record_digest,'fixed pending record authenticated');
    equal(evidence.snapshot.workflow_runs.some(r=>r.id===x.oldCloser.id),false,'actual selector excludes proved skipped previous closer');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'original evidence authentication is read-only');
    const prepared=await C.resumePriorWaveTerminal({...x.options(),prepare:true});
    equal(x.writes().length,0,'preparation does not release either publisher');
    equal(x.f.comment.body,'PR1597: выпуск занят');equal(x.oldComment.body,'PR1596: выпуск занят');
    equal(x.f.record.repository_release.closure.recovery.superseded,x.q,'lineage retains separate honest prior failure');
    equal(x.f.record.repository_release.observation,null,'no synthetic runtime observation');
    equal(x.f.record.repository_release.baseline_eligible,false,'metadata recovery does not manufacture optimization baseline');
    x.own.steps.push(step(R.TERMINAL_BINDING_STEP+prepared.record_digest));x.env.PREPARED_RECORD_DIGEST=prepared.record_digest;
    equal(await C.resumeTerminal(x.options()),'released','canonical entry point finishes both original records');
    equal(x.writes().map(c=>c.path.split('/').at(-1)),[String(x.q.comment_id),String(x.p.comment_id)],'superseded record first, current record last');
    equal(x.oldComment.body,x.q.terminal,'old failure is superseded, not relabelled installed');
    equal(x.f.comment.body,'PR1597: установлено, выпуск свободен');
    equal(x.previous.conclusion,'failure');equal(x.previousVerify.conclusion,'failure');equal(x.failure.conclusion,'failure');
    x.native.status='completed';x.native.conclusion='success';x.own.status='completed';x.own.conclusion='success';x.own.steps.push(step(R.TERMINAL_STEP));
    const location=await R.readRecord(x.f.api);await R.authenticatePublication(x.f.api,location,{closed:true});
    equal((await R.resolveCurrent(x.f.api,{sha:x.p.sha},location)).application_sha,x.p.sha,'canonical consumer accepts real continuation');
    x.oldComment.body='PR1700: выпуск занят';x.f.comment={...x.f.comment,body:'PR1701: выпуск занят'};
    await R.authenticatePublication(x.f.api,location,{closed:true});assertions++;
    equal(x.f.records.get(x.p.commit).value,initial,'immutable original receipt remains byte-equivalent after recovery');
    x.failure.conclusion='success';
    await refuses(()=>R.authenticatePublication(x.f.api,location,{closed:true}),'failed_terminal_not_original');
    x.failure.conclusion='failure';
    const allowedWrites=[R.API_ROOT+'/git/trees',R.API_ROOT+'/git/commits',R.API_ROOT+'/git/refs/heads/diagnostics',
      R.API_ROOT+'/issues/comments/'+x.q.comment_id,R.API_ROOT+'/issues/comments/'+x.p.comment_id];
    equal(x.f.calls.filter(c=>c.method!=='GET').every(c=>allowedWrites.includes(c.path)),true,'only the fixed metadata writers run');
    const writes=x.f.calls.filter(c=>c.method!=='GET').length;
    await refuses(()=>C.resumePriorWaveTerminal(x.options()),'unpublished_continuation_not_active');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,writes,'completed continuation never repeats writes');
  }
  for(const mutate of [x=>x.env.GITHUB_RUN_ATTEMPT='2',x=>x.event.created=false,x=>x.event.forced=true,
    x=>x.event.before=H('previous recovery'),x=>x.event.after=H('different head'),x=>x.env.GITHUB_REF='refs/heads/foreign']){
    const x=priorWaveFixture();mutate(x);await refuses(()=>C.resumePriorWaveTerminal({...x.options(),prepare:true}),'untrusted_unpublished_terminal_continuation');
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0);
  }
  for(const [mutate,reason]of [
    [x=>x.f.main=H('main changed'),'main_changed'],[x=>x.native.actor.id++,'unpublished_continuation_not_active'],
    [x=>x.own.runner_id=0,'unpublished_continuation_not_active'],[x=>x.f.runs.get(x.native.id+1).conclusion='failure','recovery_ci_unconfirmed'],
    [x=>x.f.runs.set(x.native.id+3,{...copy(x.native),id:x.native.id+3}),'replayed_terminal_continuation'],
    [x=>x.oldComment.user.id++,'foreign_record'],[x=>x.oldComment.updated_at='2026-11-17T11:49:30Z','record_changed'],
    [x=>x.oldPr.head.sha=H('changed previous head'),'invalid_binding'],
    [x=>x.f.comment.updated_at='2026-11-17T12:01:42Z','record_changed'],
    [x=>x.failure.steps[1].conclusion='success','failed_terminal_executed'],[x=>x.failure.steps[2].conclusion='success','failed_terminal_executed'],
    [x=>x.previousVerify.id++,'previous_deploy_jobs_untrusted'],
    [x=>x.previousVerify.steps.find(s=>s.name==='Verify installed production checks').conclusion='success','previous_verification_executed'],
    [x=>x.f.jobs.get(x.oldCloser.id)[0].steps.push(step('executed close')),'previous_closer_executed'],
    [x=>x.f.jobs.get(x.oldActivation.id)[0].steps.push(step('executed activation')),'previous_activation_executed'],
    [x=>x.oldChildren[0].conclusion='success','previous_wave_executed'],
    [x=>{for(const r of [...x.oldChildren,x.oldCloser,x.oldActivation])x.f.runs.delete(r.id);},'original_previous_wave_missing'],
    [x=>x.f.artifacts.set(Number(x.p.run_id),[{id:123}]),'original_terminal_already_published'],
    [x=>x.f.jobs.get(x.activation.id)[0].steps.push(step('executed current activation')),'activation_executed'],
    [x=>x.f.runs.get(Number(x.p.deploy)).conclusion='failure','producer_not_successful'],
    [x=>x.f.runs.get(Number(x.p.deploy)+2).conclusion='failure','required_gate_failed'],
    [x=>{const record=x.f.records.get(x.p.commit);record.value.reported_at='2026-11-17T12:17:05Z';record.bytes=Buffer.from(R.wire(record.value));record.blob=R.gitBlob(record.bytes);},'original_prior_wave_record_changed']
  ]){
    const x=priorWaveFixture();mutate(x);await refuses(()=>C.resumePriorWaveTerminal({...x.options(),prepare:true}),reason);
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'tampering refuses before any metadata write');
  }
  for(const [target,result,reason]of [
    ['branch',{object:{sha:H('moved recovery branch')}},'recovery_source_changed'],
    ['code',{sha:H('other original code')},'original_terminal_source_changed'],
    ['ancestry',{status:'diverged',merge_base_commit:{sha:H('foreign base')},ahead_by:1,behind_by:1},'previous_deploy_not_ancestor']
  ]){
    const x=priorWaveFixture();
    const endpoint=target==='branch'?'/git/ref/heads/'+x.p.branch:target==='code'?'/contents/tools/release_slot_close.js?ref='+x.p.sha:
      '/compare/'+x.q.sha+'...'+x.p.sha;
    x.f.hook=async c=>c.path===R.API_ROOT+endpoint?copy(result):x.originalHook(c);
    await refuses(()=>C.resumePriorWaveTerminal({...x.options(),prepare:true}),reason);
    equal(x.f.calls.filter(c=>c.method!=='GET').length,0,'foreign pinned source refuses before writes');
  }
  for(const idKey of ['current','previous']){
    const x=priorWaveFixture(),original=x.f.api.jobLog;
    x.f.api.jobLog=async id=>id===(idKey==='current'?x.p.job_id:x.q.verify_job_id)
      ?Buffer.from(T+' release_slot=held reason=write_unknown\n'):original(id);
    await refuses(()=>C.resumePriorWaveTerminal({...x.options(),prepare:true}),'failed_terminal_reason_changed');equal(x.writes().length,0);
  }
  {
    const x=priorWaveFixture(),proof=await C.resumePriorWaveTerminal({...x.options(),prepare:true});x.env.PREPARED_RECORD_DIGEST=proof.record_digest;
    await refuses(()=>C.resumePriorWaveTerminal(x.options()),'publication_step_unconfirmed');equal(x.writes().length,0,'no upload no terminal');
  }
  for(const mode of ['first_patch_unknown','first_readback_unknown','second_patch_unknown','main_after_first']){
    const x=priorWaveFixture(),proof=await C.resumePriorWaveTerminal({...x.options(),prepare:true});
    x.env.PREPARED_RECORD_DIGEST=proof.record_digest;x.own.steps.push(step(R.TERMINAL_BINDING_STEP+proof.record_digest));
    let firstWritten=false;
    x.f.hook=async call=>{
      const old=call.path===R.API_ROOT+'/issues/comments/'+x.q.comment_id,newer=call.path===R.API_ROOT+'/issues/comments/'+x.p.comment_id;
      if(old&&call.method==='PATCH'){
        if(mode==='first_patch_unknown')throw new Error('lost transport');
        const result=await x.originalHook(call);firstWritten=true;
        if(mode==='main_after_first')x.f.main=H('concurrent main');return result;
      }
      if(old&&call.method==='GET'&&firstWritten&&mode==='first_readback_unknown')throw new Error('lost readback');
      if(newer&&call.method==='PATCH'&&mode==='second_patch_unknown')throw new Error('lost transport');
      return x.originalHook(call);
    };
    await refuses(()=>C.resumePriorWaveTerminal(x.options()),mode==='main_after_first'?'main_changed':'write_unknown');
    equal(x.writes().length,mode==='second_patch_unknown'?2:1,'unknown write is never replayed');
    equal(x.f.comment.body,'PR1597: выпуск занят','partial recovery cannot claim current acceptance');
    x.native.status='completed';x.native.conclusion='failure';x.own.status='completed';x.own.conclusion='failure';
    const failedLocation=await R.readRecord(x.f.api);
    await refuses(()=>R.authenticatePublication(x.f.api,failedLocation,{closed:true}),'producer_not_successful');
  }
}


function unstartedFixture(){
  const p=R.UNSTARTED_FORWARD,head=H('checked forward source'),source=H('forward merge');
  const native={id:Number(p.deploy),path:R.DEPLOY,event:'push',name:'Deploy production',head_sha:p.sha,
    head_branch:'main',head_repository:{full_name:R.REPOSITORY},head_commit:{id:p.sha},run_attempt:1,
    status:'completed',conclusion:'failure',created_at:T,run_started_at:T,updated_at:T};
  const jobs=p.jobs.map(expected=>({...job(expected.name,native,expected.steps.map(([n,c])=>step(n,c)),expected.conclusion),id:expected.id}));
  const names={'max-tls-preflight.yml':['strict-tls-preflight'],'max-production-health.yml':['inspect'],
    'telegram-production-health.yml':['inspect'],'publish-conversation-diagnostics.yml':['publish'],
    'live-session-diagnostics.yml':['publish'],'tour-selection-host.yml':['host','independent-editor'],
    'modeled-conversion-monitor.yml':['monitor'],'modeled-pipeline-monitor-activation.yml':['activate'],
    'modeled-conversion-value-export.yml':['configure','release-slot','release-slot-recovery']};
  const children=p.children.map(c=>({...copy(native),id:c.id,path:c.path,event:'workflow_run',conclusion:'skipped',display_title:c.title}));
  const childJobs=new Map(children.map(c=>[c.id,names[c.path.slice(R.PREFIX.length)].map(n=>job(n,c,[],'skipped'))]));
  const marker={previous_pr:p.pr,previous_sha:p.sha,previous_run_id:p.deploy,head_sha:head};
  const original={number:p.pr,merged:true,state:'closed',merge_commit_sha:p.sha,
    head:{sha:p.head_sha,repo:{full_name:R.REPOSITORY}},base:{ref:'main',sha:p.sha,repo:{full_name:R.REPOSITORY}},
    body:'<!-- release-slot-close:v1 '+JSON.stringify({comment_id:p.comment_id,head_sha:p.head_sha,owner_id:p.owner_id,updated_at:p.updated_at})+' -->'};
  const target={...copy(original),number:1700,state:'open',merged:false,draft:false,
    head:{sha:head,repo:{full_name:R.REPOSITORY}},body:'<!-- release-forward-unstarted:v1 '+JSON.stringify(marker)+' -->'};
  const x={p,head,source,native,jobs,children,childJobs,original,target,marker,runs:[native,...children],artifacts:[],
    main:p.sha,calls:[],workflow:p.workflow_blob,comment:{id:p.comment_id,user:{id:p.owner_id},updated_at:p.updated_at,
      author_association:'OWNER',issue_url:'https://api.github.com'+R.API_ROOT+'/issues/1122',body:'PR1600: выпуск занят'}};
  x.api=async(method,u)=>{
    x.calls.push({method,u});assert.equal(method,'GET','bounded forward proof never writes');
    if(x.hook){const result=await x.hook(u);if(result!==undefined)return result;}
    const route=u.slice(R.API_ROOT.length);
    if(route==='/git/ref/heads/main')return {object:{sha:x.main}};
    if(route==='/pulls/'+p.pr)return copy(original);
    if(route==='/pulls/'+target.number)return copy(target);
    if(route==='/issues/comments/'+p.comment_id)return copy(x.comment);
    if(route==='/actions/runs/'+p.deploy)return copy(native);
    if(route==='/contents/'+R.DEPLOY+'?ref='+p.sha)return {sha:x.workflow};
    if(route==='/actions/runs/'+p.deploy+'/jobs?filter=all&per_page=100')return {total_count:jobs.length,jobs:copy(jobs)};
    if(route==='/actions/runs/'+p.deploy+'/artifacts?per_page=100')return {total_count:x.artifacts.length,artifacts:copy(x.artifacts)};
    if(route==='/actions/runs?head_sha='+p.sha+'&per_page=100')return {total_count:x.runs.length,workflow_runs:copy(x.runs)};
    if(route==='/commits/'+source)return {sha:source,parents:[{sha:p.sha},{sha:head}]};
    const m=/^\/actions\/runs\/(\d+)\/attempts\/1\/jobs\?per_page=100$/.exec(route);
    if(m&&childJobs.has(Number(m[1]))){const js=childJobs.get(Number(m[1]));return {total_count:js.length,jobs:copy(js)};}
    if(x.fallback)return x.fallback(method,u);
    if(route==='/git/ref/heads/diagnostics')throw new R.Refusal('fixture_reached_accepted_record');
    throw Error('Unexpected forward fixture route '+route);
  };
  x.handoff=()=>{const updated='2026-11-17T15:00:00Z';x.comment.updated_at=updated;x.comment.body='PR1700: выпуск занят';
    target.body+='\n<!-- release-slot-close:v1 '+JSON.stringify({comment_id:p.comment_id,head_sha:head,owner_id:p.owner_id,updated_at:updated})+' -->';};
  x.options=phase=>({api:x.api,pr:target.number,head,phase,source});
  return x;
}
function acceptedForwardFixture(){
  const x=unstartedFixture(),a=priorWaveFixture(),f=a.f;
  const accepted={"bundle":"success","diagnostics":"success","diagnostics_attempt":1,"install_attempt":1,"install_checks":"success","key":"success","max_webhook":"success","metrika_cron":"success","migration":"success","provenance":"success","reported_at":"2026-11-17T12:17:04Z","repository_release":{"application_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","baseline":null,"baseline_eligible":false,"closure":{"binding":{"comment_id":6928374515,"head_sha":"46f2ab2c01fcf1da5cea2d2f0c042fbbc0a676ff","owner_id":226193297,"pr":1597,"updated_at":"2026-11-17T12:01:41Z"},"expected_terminal":"PR1597: установлено, выпуск свободен","gates":[{"conclusion":"success","head_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","path":".github/workflows/deploy.yml","run_attempt":1,"run_id":"44079440323"},{"conclusion":"success","head_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","path":".github/workflows/retired-domain-guard.yml","run_attempt":1,"run_id":"44079440259"},{"conclusion":"success","head_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","path":".github/workflows/max-tls-preflight.yml","run_attempt":1,"run_id":"44081722763"},{"conclusion":"success","head_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","path":".github/workflows/max-production-health.yml","run_attempt":1,"run_id":"44081722860"},{"conclusion":"success","head_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","path":".github/workflows/telegram-production-health.yml","run_attempt":1,"run_id":"44081722748"},{"conclusion":"success","head_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","path":".github/workflows/publish-conversation-diagnostics.yml","run_attempt":1,"run_id":"44081722897"},{"conclusion":"success","head_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","path":".github/workflows/live-session-diagnostics.yml","run_attempt":1,"run_id":"44081722731"},{"conclusion":"success","head_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","path":".github/workflows/tour-selection-host.yml","run_attempt":1,"run_id":"44081722792"},{"conclusion":"success","head_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","path":".github/workflows/modeled-conversion-monitor.yml","run_attempt":1,"run_id":"44081722695"},{"conclusion":"skipped","head_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","path":".github/workflows/modeled-pipeline-monitor-activation.yml","run_attempt":1,"run_id":"44081882142"}],"job":"release-slot-recovery","recovery":{"kind":"prior_failed_wave_v1","original_blob":"dfc7eef713d4b6ab7f606420002f96c544e9e937","original_commit":"af7ee5c9aad44951cda9fd6b3f61f7947c232d44","original_job_id":134033721866,"original_record_digest":"3ffb405894009e90885b68cb3db652240d4a587c0547674d8ca6cc1c9ada65c3","original_run_id":"44081722753","source_sha":"c1d34ae37aca71377c4b63541f0f92e137b3e296","superseded":{"comment_id":6682640708,"deploy":"44077678956","head_sha":"96fd2b5ef87a8592cfc324308a72232b39e2a545","owner_id":226193297,"pr":1596,"sha":"c45b29452f8909eb190a04c6a54645400bb87c11","terminal":"PR1596: замещено PR1597; исходная проверка не завершена, выпуск свободен","updated_at":"2026-11-17T11:49:29Z","verify_job_id":134026179047}},"run_attempt":1,"run_id":"44099272903","terminal_step":"Release only the admitted publisher after all original mandatory gates"},"content":{"candidate_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","classification":"unconfirmed","installed_sha":"6d3da4fb7602de9e6dcd7ba3af06f70749606f1f","policy":"anytour-development-content-v1","release_path":"full_required","runtime_fingerprint":null},"intent_scan":{"checked_commits":9,"coverage":"registered_markers_and_reserved_families","matched_commits":[],"status":"no_registered_intent"},"kind":"installed","observation":null,"observation_producer":null,"phase":"pending_terminal","release":{"event":"push","run_attempt":1,"run_id":"44079440323"},"repository_sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","schema":1,"stages":{"bundle":{"execution":"performed","result":"success"},"diagnostics":{"execution":"performed","result":"success"},"install_checks":{"execution":"performed","result":"success"},"key":{"execution":"performed","result":"success"},"max_webhook":{"execution":"performed","result":"success"},"metrika_cron":{"execution":"performed","result":"success"},"migration":{"execution":"performed","result":"success"},"provenance":{"execution":"performed","result":"success"},"smoke":{"execution":"performed","result":"success"},"sync":{"execution":"performed","result":"success"}}},"run_id":"44079440323","schema":2,"sha":"fd9b28dab2147f13f3b0d0f7f856b14392b141d2","smoke":"success","smoke_attempt":1,"sync":"success"};
  equal(R.digest(accepted),x.p.record_digest,'actual accepted digest reproduced');
  equal(R.gitBlob(Buffer.from(R.wire(accepted))),x.p.blob,'actual accepted blob reproduced');
  for(const gate of accepted.repository_release.closure.gates){
    const run=f.wave.wave.find(r=>r.path===gate.path),old=run.id,newId=Number(gate.run_id);
    const jobs=f.jobs.get(old);f.runs.delete(old);f.jobs.delete(old);run.id=newId;run.conclusion=gate.conclusion;f.runs.set(newId,run);
    if(jobs){for(const j of jobs)j.run_id=newId;f.jobs.set(newId,jobs);}
  }
  const monitor=f.wave.wave.find(r=>r.path.endsWith('modeled-conversion-monitor.yml'));
  a.activation.display_title='monitor-proof:v1:'+monitor.id+':1';
  const oldNative=a.native.id,ownJobs=f.jobs.get(oldNative),closure=accepted.repository_release.closure;
  f.runs.delete(oldNative);f.jobs.delete(oldNative);a.native.id=Number(closure.run_id);
  a.native.head_sha=closure.recovery.source_sha;a.native.head_commit.id=a.native.head_sha;
  a.native.status='completed';a.native.conclusion='success';f.runs.set(a.native.id,a.native);
  for(const j of ownJobs){j.run_id=a.native.id;j.head_sha=a.native.head_sha;}
  a.own.status='completed';a.own.conclusion='success';
  a.own.steps=[step(R.TERMINAL_BINDING_STEP+x.p.record_digest),step(R.TERMINAL_STEP)];
  f.jobs.set(a.native.id,ownJobs);
  const commit=f.putRecord(accepted,a.p.commit),location=f.records.get(commit),metadata=f.commits.get(commit);
  f.records.set(x.p.commit,location);f.commits.set(x.p.commit,{...copy(metadata),sha:x.p.commit});f.head=x.p.commit;
  f.hook=async call=>{
    if(call.path===R.API_ROOT+'/compare/'+a.p.sha+'...'+a.native.head_sha+'?per_page=1')return {status:'ahead',merge_base_commit:{sha:a.p.sha}};
    return a.originalHook(call);
  };
  x.api.jobLog=f.api.jobLog;x.fallback=(method,u)=>f.api(method,u);x.accepted=a;
  return x;
}
async function unstartedForwardCases(){
  {
    const x=acceptedForwardFixture();
    const before=await R.proveUnstartedForward(x.options('before_handoff'));
    equal(before.application_sha,x.p.application_sha,'full real canonical acceptance precedes ownership transfer');
    x.handoff();equal((await R.proveUnstartedForward(x.options('after_handoff'))).binding.pr,1700);
    x.main=x.source;x.target.merged=true;x.target.state='closed';x.target.merge_commit_sha=x.source;
    equal((await R.proveUnstartedForward(x.options('installation'))).phase,'installation');
    equal(x.calls.every(c=>c.method==='GET'),true,'all three phases perform no external mutation');
    const newRun={...copy(x.native),id:90000,head_sha:x.source,head_commit:{id:x.source},status:'in_progress',conclusion:null};
    x.hook=u=>{
      if(u===R.API_ROOT+'/commits/'+x.source+'/pulls?per_page=100')return [copy(x.target)];
      if(u===R.API_ROOT+'/actions/runs?head_sha='+x.source+'&per_page=100')return {total_count:1,workflow_runs:[copy(newRun)]};
      if(u===R.API_ROOT+'/actions/runs/90000')return copy(newRun);
    };
    const event={repository:{full_name:R.REPOSITORY},ref:'refs/heads/main',before:x.p.sha,after:x.source,forced:false};
    equal((await R.admitForwardInstallation(x.api,{sha:x.source,id:'90000',event:'push',attempt:1},event)).installation,'not_started');
    const env=consumerEnv(newRun,R.DEPLOY,'push','deploy');
    const planned=await R.planDeployment({api:x.api,env,event,compare:()=>({decision:'no_install_candidate'})});
    equal([planned.plan,planned.application_sha],['install',x.source],
      'real plan keeps ordinary install: original recovered baseline was never optimization-eligible');
    // The real plan entry point must not reach credentials/comparison after an ownership change.
    x.comment.user.id++;
    await refuses(()=>R.planDeployment({api:x.api,env,event,compare:()=>{throw Error('must not compare or install');}}),'foreign_record');

  }

  {
    const x=unstartedFixture();equal(R.forwardMarker(x.target,x.head),x.marker);
    equal(await R.proveUnstartedDeployment(x.api),{previous_pr:1600,previous_sha:x.p.sha,previous_run_id:x.p.deploy,installation:'not_started'});
    equal(x.calls.every(c=>c.method==='GET'),true,'unstarted proof is read-only');
    await refuses(()=>R.proveUnstartedForward(x.options('before_handoff')),'fixture_reached_accepted_record');
    x.handoff();await refuses(()=>R.proveUnstartedForward(x.options('after_handoff')),'fixture_reached_accepted_record');
    x.main=x.source;x.target.merged=true;x.target.state='closed';x.target.merge_commit_sha=x.source;
    await refuses(()=>R.proveUnstartedForward(x.options('installation')),'fixture_reached_accepted_record');
  }

  for(const [change,code]of [
    [x=>x.accepted.native.conclusion='failure','producer_not_active'],
    [x=>x.accepted.own.steps.pop(),'terminal_step_unconfirmed'],
    [x=>x.accepted.failure.conclusion='success','failed_terminal_not_original'],
  ]){
    const x=acceptedForwardFixture();change(x);await refuses(()=>R.proveUnstartedForward(x.options('before_handoff')),code);
  }
  {
    const x=acceptedForwardFixture();let reads=0;
    x.hook=u=>{if(u===R.API_ROOT+'/pulls/'+x.target.number&&++reads===2)return {...copy(x.target),head:{sha:H('changed while proving')}};};
    await refuses(()=>R.proveUnstartedForward(x.options('before_handoff')),'forward_pull_changed');
  }
  {
    const x=acceptedForwardFixture();x.hook=u=>u===R.API_ROOT+'/contents/ops_status.json?ref=diagnostics'
      ?{sha:H('other application'),...Object.fromEntries(R.OPS_STAGES.map(s=>[s,'success']))}:undefined;
    await refuses(()=>R.proveUnstartedForward(x.options('before_handoff')),'diagnostics_unconfirmed');
  }
  for(const mutate of [x=>x.target.body='',x=>x.target.body+='\n'+x.target.body,x=>x.target.body=x.target.body.replace('1600','1597'),
    x=>x.target.body=x.target.body.replace(x.head,H('wrong head')),x=>x.target.body=x.target.body.replace('"previous_pr":','"extra":1,"previous_pr":')]){
    const x=unstartedFixture();mutate(x);await refuses(async()=>R.forwardMarker(x.target,x.head),'invalid_forward_marker');
  }
  for(const [mutate,code]of [
    [x=>x.native.run_attempt=2,'untrusted_workflow_run'],[x=>x.workflow=H('wrong source'),'forward_source_changed'],
    [x=>x.jobs.pop(),'forward_jobs_incomplete'],[x=>x.jobs.push(copy(x.jobs[0])),'forward_jobs_incomplete'],
    [x=>x.jobs[0].run_attempt=2,'forward_installation_not_unstarted'],
    [x=>x.jobs[3].conclusion='success','forward_installation_not_unstarted'],
    [x=>x.jobs[4].steps[2].conclusion='success','forward_installation_not_unstarted'],
    [x=>x.jobs[4].steps.push(step('Unclassified operation')),'forward_installation_not_unstarted'],
    [x=>x.jobs[5].steps.push(step('Unexpected verification')),'forward_installation_not_unstarted'],
    [x=>x.artifacts.push({id:100}),'forward_original_published'],
    [x=>x.runs.push({...copy(x.native),id:300,event:'workflow_dispatch'}),'forward_deploy_replayed'],
    [x=>x.runs=x.runs.filter(r=>r.id!==x.children[0].id),'forward_tail_incomplete'],
    [x=>x.runs.push({...copy(x.children[0]),id:300}),'forward_tail_incomplete'],
    [x=>x.children[0].head_sha=H('later main'),'untrusted_workflow_run'],
    [x=>x.children[0].conclusion=null,'producer_not_successful'],
    [x=>x.children[0].display_title='deploy-proof:v1:123:1','forward_tail_changed'],
    [x=>x.childJobs.get(x.children[0].id)[0].steps.push(step('Mutation')),'forward_tail_executed'],
    [x=>x.childJobs.get(x.children[0].id)[0].run_attempt=2,'forward_tail_executed']]){
    const x=unstartedFixture();mutate(x);await refuses(()=>R.proveUnstartedDeployment(x.api),code);
    equal(x.calls.every(c=>c.method==='GET'),true);
  }
  for(const [mutate,code]of [
    [x=>x.main=H('new main'),'main_changed'],[x=>x.target.head.sha=H('new head'),'forward_pull_changed'],
    [x=>x.target.draft=true,'forward_pull_changed'],[x=>x.comment.body='PR1600: установлено, выпуск свободен','forward_publisher_changed'],
    [x=>x.comment.user.id++,'foreign_record'],[x=>x.comment.updated_at='2026-11-17T15:00:00Z','record_changed']]){
    const x=unstartedFixture();mutate(x);await refuses(()=>R.proveUnstartedForward(x.options('before_handoff')),code);
  }
  {
    const x=unstartedFixture();x.handoff();x.comment.body='PR1600: выпуск занят';
    await refuses(()=>R.proveUnstartedForward(x.options('after_handoff')),'record_changed');
  }
  {
    const x=unstartedFixture();x.handoff();x.main=x.source;x.target.merged=true;x.target.state='closed';x.target.merge_commit_sha=x.source;
    x.hook=u=>u===R.API_ROOT+'/commits/'+x.source?{sha:x.source,parents:[{sha:H('wrong base')},{sha:x.head}]}:undefined;
    await refuses(()=>R.proveUnstartedForward(x.options('installation')),'forward_not_direct_successor');
  }


  {
    const x=acceptedForwardFixture();let reads=0;
    x.hook=u=>u===R.API_ROOT+'/pulls/'+x.target.number&&++reads===2
      ?{...copy(x.target),body:x.target.body.replace('"previous_pr":1600','"previous_pr":1597')}:undefined;
    await refuses(()=>R.proveUnstartedForward(x.options('before_handoff')),'invalid_forward_marker');
  }
  {
    const x=acceptedForwardFixture();
    x.hook=u=>{
      if(u===R.API_ROOT+'/contents/ops_status.json?ref=diagnostics'){
        const f=x.accepted.f;f.putRecord({...copy(f.record),reported_at:T},f.head);
        return {sha:x.p.application_sha,...Object.fromEntries(R.OPS_STAGES.map(s=>[s,'success']))};
      }
    };
    await refuses(()=>R.proveUnstartedForward(x.options('before_handoff')),'forward_accepted_record_changed');
  }
  for(const commit of [null,{}, {sha:H('wrong'),parents:[{sha:R.UNSTARTED_FORWARD.sha}]},
    {sha:H('forward merge')}, {sha:H('forward merge'),parents:[]},
    {sha:H('forward merge'),parents:[{}]}]){
    const x=unstartedFixture();x.hook=u=>u===R.API_ROOT+'/commits/'+x.source?commit:undefined;
    await refuses(()=>R.admitForwardInstallation(x.api,{sha:x.source,id:'90000',attempt:1,event:'push'},
      {before:x.p.sha,after:x.source,forced:false}),'forward_source_unconfirmed');
  }
  {
    const x=unstartedFixture();x.hook=u=>u===R.API_ROOT+'/commits/'+x.source?{sha:x.source,parents:[{sha:H('other') }]}:undefined;
    await refuses(()=>R.admitForwardInstallation(x.api,{sha:x.source,id:'90000',attempt:1,event:'push'},
      {before:x.p.sha,after:x.source,forced:false}),'forward_not_direct_successor');
  }
  {
    const x=acceptedForwardFixture();let reads=0;
    x.hook=u=>u===R.API_ROOT+'/actions/runs/'+x.p.deploy&&++reads===2?{...copy(x.native),run_attempt:2}:undefined;
    await refuses(()=>R.proveUnstartedForward(x.options('before_handoff')),'untrusted_workflow_run');
  }
  for(const mutate of [x=>x.c.attempt=2,x=>x.c.event='workflow_dispatch',x=>x.event.forced=true,x=>x.event.before=H('other base')]){
    const x=unstartedFixture();x.c={sha:x.source,id:'90000',attempt:1,event:'push'};x.event={before:x.p.sha,after:x.source,forced:false};mutate(x);
    await refuses(()=>R.admitForwardInstallation(x.api,x.c,x.event),'untrusted_forward_installation');
  }
}


// Exact public PR1643 preimages; all API, jobs and writes below are synthetic.
function publisherBodyFixture(){
  const p=R.PUBLISHER_BODY_RECOVERY,pending={"bundle":"success","diagnostics":"success","diagnostics_attempt":1,"install_attempt":1,"install_checks":"success","key":"success","max_webhook":"success","metrika_cron":"success","migration":"success","provenance":"success","reported_at":"2026-11-21T14:28:11Z","repository_release":{"application_sha":"cabc34b7166994c65816ec8c81e48895ea889683","baseline":null,"baseline_eligible":false,"closure":null,"content":{"candidate_sha":"cabc34b7166994c65816ec8c81e48895ea889683","classification":"unconfirmed","installed_sha":"b678bc2f211c5f141751137453b199036491222d","policy":"anytour-development-content-v1","release_path":"full_required","runtime_fingerprint":null},"intent_scan":{"checked_commits":2,"coverage":"registered_markers_and_reserved_families","matched_commits":[],"status":"no_registered_intent"},"kind":"installed","observation":null,"observation_producer":null,"phase":"pending_closure","release":{"event":"push","run_attempt":1,"run_id":"44912653990"},"repository_sha":"cabc34b7166994c65816ec8c81e48895ea889683","schema":1,"stages":{"bundle":{"execution":"performed","result":"success"},"diagnostics":{"execution":"performed","result":"success"},"install_checks":{"execution":"performed","result":"success"},"key":{"execution":"performed","result":"success"},"max_webhook":{"execution":"performed","result":"success"},"metrika_cron":{"execution":"performed","result":"success"},"migration":{"execution":"performed","result":"success"},"provenance":{"execution":"performed","result":"success"},"smoke":{"execution":"performed","result":"success"},"sync":{"execution":"performed","result":"success"}}},"run_id":"44912653990","schema":2,"sha":"cabc34b7166994c65816ec8c81e48895ea889683","smoke":"success","smoke_attempt":1,"sync":"success"},body="### MAX2 #211 — ordinary app release of the two new goals\n\nPR1643: выпуск занят\n\nSame MAX2 owner and original [tour-max #211 coordination](https://github.com/pyatkoff/tour-max/pull/211#issuecomment-7054553165), continuing the scoped app integration in this existing record. This is the only publisher record for [PR1643](https://github.com/pyatkoff/max-search-bot/pull/1643).\n\nExact candidate HEAD de603d8c5b53a5dbf3e972e166da96c42c4e5500; BASE b678bc2f211c5f141751137453b199036491222d; tree5bb2649a3f5ff16622b8b9f0c4cef22163f6d1b2; release-manifest2e44744e98851dd68df2062f001009d764bde20adcb71748be0733a0cde8b0d6. All six original PR workflows succeeded on that exact HEAD, including required regression44911483984/job136870771490 and guard44911483993/job136868059497, attempt1. Final14-suite MAX2 wrapper passed in diagnostics job136868292160.\n\nFresh unchanged-BASE full canonical --admit succeeded in original audit sourcec4e5e2e0a088bd51316dd059b5263398ab2886b7, run44912485838/job136871049337/a1. It authenticated the prior original technical release and returned allowed=true, priority_owned for1643. Original artifact14710277291 ZIPsha2569fff1e641b91b46f139f6173ef707cbc9665c31fae564abf078dd34701f22bb3, resultsha2562ec42226dba5d6225b92662f8a87ffcf78e6f8703a2efcd3f280c1286319560f. Fresh main/PR/source-record readback remained exact immediately before this acquisition.\n\nScope remains exactly50 new +6 modified files: NEW Max2*V2 services, own112/113 migrations, resources/tests/docs/finite state CLI and manual workflow. Existing app hooks are only webhook.php, ChannelPostEntryService.php and cron_metrika.php; required_checks_manifest.php adds one wrapper. deployment_release.js adds one explicit finite MANUAL_OPERATIONS member, with its focused existing JS regression. No canonical resolver bypass, new schedule, workflow_run tail, secret, SSH principal or permission grant.\n\nUser-authorized goals are only687931224/max2ok and687941321/anytour_modeled_value_v2_max2 on98615635. Protected678187097/anytour_modeled_value_v1, its300RUB CPA campaign, model/config/full values/export/ledger/sender remain unchanged. Old URL563541588/max2, all campaign selections/budgets, Avito, other projects and SOURCE owners remain unchanged. Existing early JS installation and signed sidecar are accepted terminal operations and must never be replayed.\n\nThis ordinary app installation starts defaultOFF, prospective-only with an independent positive-delta ledger. Await the original exact-source deploy, migrations, smoke, diagnostics, telemetry and every mandatory technical tail. Bind this acquisition's actual author/id/updated_at in PR1643 before expected-head merge. Only the existing automatic terminal may release this publisher; no manual FREE, rerun or replacement record. The finite new-state activation follows the accepted installation separately; later natural business acceptance does not retain a technically completed shared slot.\n";
  equal(R.gitBlob(Buffer.from(R.wire(pending))),p.blob,'actual original pending bytes stay exact');
  equal([Buffer.byteLength(body,'utf8'),D(body)],[p.body_bytes,p.body_sha256],'actual original long publisher is pinned');
  const f=fixture(p.sha,pending),initial=f.head,old=f.commits.get(initial);
  f.records.set(p.commit,f.records.get(initial));f.commits.set(p.commit,{...copy(old),sha:p.commit});f.head=p.commit;
  // A later diagnostics commit preserves the same record and original ancestry.
  f.putRecord(pending,p.commit);
  const wave=f.start(p.sha,Number(p.deploy),Number(p.run_id),p.pr);f.runs.clear();f.jobs.clear();
  for(const r of wave.wave)f.runs.set(r.id,r);
  const activation=wave.wave.find(r=>r.path.endsWith(C.ACTIVATION.file));
  f.jobs.set(activation.id,[job('activate',activation,[],'skipped')]);
  f.jobs.set(Number(p.deploy),fullJobs(wave.parent,pending));
  const failed=wave.own;failed.status='completed';failed.conclusion='failure';f.runs.set(failed.id,failed);
  const failure=job('release-slot',failed,[step('Prepare the exact release proof before terminal publication','failure'),
    step(R.TERMINAL_BINDING_STEP,'skipped'),step(R.TERMINAL_STEP,'skipped')],'failure');failure.id=p.job_id;
  f.jobs.set(failed.id,[job('configure',failed,[],'skipped'),failure,job('release-slot-recovery',failed,[],'skipped')]);
  const b={comment_id:p.comment_id,head_sha:p.head_sha,owner_id:p.owner_id,updated_at:p.updated_at};
  f.pr={...f.pr,number:p.pr,head:{sha:p.head_sha,repo:{full_name:R.REPOSITORY}},body:'<!-- release-slot-close:v1 '+JSON.stringify(b)+' -->'};
  f.comment={...f.comment,id:p.comment_id,user:{id:p.owner_id},updated_at:p.updated_at,body};
  const source=H('source-checked exact PR1643 body recovery'),runId=900000;
  const native={...copy(failed),id:runId,head_sha:source,head_branch:p.branch,event:'push',status:'in_progress',conclusion:null,
    actor:{id:p.owner_id},triggering_actor:{id:p.owner_id},head_commit:{id:source,message:'Checked metadata recovery'}};
  delete native.display_title;f.runs.set(runId,native);
  const own=job('release-slot-recovery',native,[],null);own.status='in_progress';own.runner_id=345;
  f.jobs.set(runId,[own,job('configure',native,[],'skipped'),job('release-slot',native,[],'skipped')]);
  for(const [i,name,file]of [[1,'Regression tests','regression-tests.yml'],[2,'Retired domain guard','retired-domain-guard.yml']])
    f.runs.set(runId+i,{...copy(native),id:runId+i,name,path:R.PREFIX+file,event:'pull_request',status:'completed',conclusion:'success'});
  const sourceFiles={};
  for(const name of p.source_files){const raw=fs.readFileSync(path.join(__dirname,'..',name));
    sourceFiles[name]={type:'file',path:name,sha:R.gitBlob(raw),size:raw.length};}
  const relation={status:'ahead',merge_base_commit:{sha:p.sha},files:p.changed_files.map(filename=>({filename,status:'modified'}))};
  const branchRuns=[native];
  f.api.jobLog=async id=>{equal(id,p.job_id,'only the fixed stopped original log is read');return Buffer.from(T+' release_slot=held reason=record_changed\n');};
  const originalHook=async call=>{
    const q=call.path;
    if(q===R.API_ROOT+'/compare/'+p.sha+'...'+source+'?per_page=1')return relation;
    if(q===R.API_ROOT+'/git/ref/heads/'+p.branch)return {object:{sha:source}};
    if(q===R.API_ROOT+'/actions/workflows/modeled-conversion-value-export.yml/runs?branch='+p.branch+'&event=push&per_page=100')
      return {total_count:branchRuns.length,workflow_runs:copy(branchRuns)};
    if(q===R.API_ROOT+'/contents/tools/release_slot_close.js?ref='+p.sha)return {sha:p.original_code};
    for(const [name,meta]of Object.entries(sourceFiles))if(q===R.API_ROOT+'/contents/'+name+'?ref='+source)return meta;
    if(q===R.API_ROOT+'/issues/comments/'+p.comment_id){if(call.method==='PATCH')f.comment={...f.comment,body:call.body.body};return copy(f.comment);}
  };
  f.hook=originalHook;
  const env={GITHUB_REPOSITORY:R.REPOSITORY,GITHUB_EVENT_NAME:'push',GITHUB_REF:'refs/heads/'+p.branch,GITHUB_SHA:source,
    GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+R.CLOSER+'@refs/heads/'+p.branch,GITHUB_JOB:'release-slot-recovery',
    GITHUB_RUN_ID:String(runId),GITHUB_RUN_ATTEMPT:'1',GITHUB_ACTOR:'pyatkoff',GITHUB_TRIGGERING_ACTOR:'pyatkoff'};
  const event={repository:{full_name:R.REPOSITORY,id:1342493152},sender:{id:p.owner_id,login:'pyatkoff'},ref:env.GITHUB_REF,
    before:'0'.repeat(40),after:source,created:true,deleted:false,forced:false};
  const writes=()=>f.calls.filter(c=>c.method!=='GET');
  const terminalWrites=()=>writes().filter(c=>c.method==='PATCH'&&c.path.includes('/issues/comments/'));
  const complete=()=>{native.status='completed';native.conclusion='success';own.status='completed';own.conclusion='success';own.steps.push(step(R.TERMINAL_STEP));};
  const options=()=>({env,event,api:f.api});
  return {f,p,pending,body,env,event,source,native,own,failure,activation,relation,sourceFiles,branchRuns,originalHook,writes,terminalWrites,complete,options};
}
// The original PR1643 operation checked both the source API and its actual
// checkout. Exercise exactly that checkout even when today's priority code differs.
async function publisherBodyRecoveryCases(){
  const project=path.resolve(__dirname,'..'),p=R.PUBLISHER_BODY_RECOVERY;
  const priority='tools/release_priority.js',current=fs.readFileSync(path.join(project,priority));
  if(R.gitBlob(current)===p.priority_code)return publisherBodyFixtureCases();
  const pinned=fs.readFileSync(path.join(__dirname,'fixtures/pr1643/release_priority.js.txt'));
  assert.equal(R.gitBlob(pinned),p.priority_code,'PR1643 priority fixture must be its authentic Git blob');
  const replica=fs.mkdtempSync(path.join(os.tmpdir(),'pr1643-source-'));
  try{
    for(const name of p.source_files){
      const dest=path.join(replica,name);
      fs.mkdirSync(path.dirname(dest),{recursive:true});
      if(name===priority)fs.writeFileSync(dest,pinned);
      else fs.copyFileSync(path.join(project,name),dest);
    }
    const isolated=require(path.join(replica,'tests/run_deployment_release_regression.js'));
    const completed=await isolated.publisherBodyRecoveryCases();
    assertions+=completed;
    return completed;
  }finally{fs.rmSync(replica,{recursive:true,force:true});}
}

async function publisherBodyFixtureCases(){
  const startAssertions=assertions;
  {
    const x=publisherBodyFixture(),b=C.binding(x.f.pr,x.p.sha);
    await refuses(async()=>C.recordState(x.f.comment,b),'record_changed','ordinary parser still rejects a long active body');
    equal(C.publisherBodyRecord(x.f.comment,b),'active','only the exact independently observed body is admitted');
    const original=await R.failedPublisherBodyTerminal(x.f.api);
    equal(original.original.record_digest,x.p.record_digest,'original prepare failed before any terminal existed');
    equal(x.writes().length,0,'original evidence verification is read only');
    const proof=await C.resumeTerminal({...x.options(),prepare:true});
    equal(x.f.comment.body,x.body,'preparation never normalizes the publisher');
    equal(x.terminalWrites().length,0,'proof publication cannot free a publisher');
    equal(x.f.record.repository_release.closure.recovery.kind,'publisher_body_format_v1','actual new lineage is explicit');
    equal([x.f.record.repository_release.observation,x.f.record.repository_release.observation_producer,
      x.f.record.repository_release.baseline_eligible],[null,null,false],'unobserved optional runtime stays unobserved');
    const restored=copy(x.f.record);restored.repository_release.phase='pending_closure';restored.repository_release.closure=null;
    equal(restored,x.pending,'no original installation evidence is rewritten');
    x.own.steps.push(step(R.TERMINAL_BINDING_STEP+proof.record_digest));x.env.PREPARED_RECORD_DIGEST=proof.record_digest;
    equal(await C.resumeTerminal(x.options()),'released','the fixed original native recovery can finish once');
    equal(x.terminalWrites().map(c=>[c.path,c.body.body]),[[R.API_ROOT+'/issues/comments/'+x.p.comment_id,b.released]],
      'exactly one final same-comment PATCH, without intermediate BUSY');
    x.complete();const location=await R.readRecord(x.f.api);
    await R.authenticatePublication(x.f.api,location,{closed:true});
    equal((await R.resolveCurrent(x.f.api,{sha:x.p.sha},location)).application_sha,x.p.sha,'canonical reader accepts the real installation through typed recovery');
    await commonValueStateConsumer(x.f,x.p.sha,x.p.sha);
    const priority=require('../tools/release_priority'),acquired='2026-11-21T15:00:00Z',nextPr=1644;
    const publisher={comment_id:x.p.comment_id,owner_id:x.p.owner_id,updated_at:acquired};
    const next={number:nextPr,state:'open',draft:false,author_association:'OWNER',
      base:{ref:'main',sha:x.p.sha,repo:{full_name:R.REPOSITORY}},head:{sha:x.source,repo:{full_name:R.REPOSITORY}},
      body:'<!-- anytour-release-priority:v1 '+JSON.stringify({head:x.source,base:x.p.sha,kind:'product',
        started_at:acquired,expires_at:'2026-11-21T15:20:00Z'})+' -->\n<!-- release-slot-close:v1 '+
        JSON.stringify({...publisher,head_sha:x.source})+' -->'};
    x.f.comment={...x.f.comment,updated_at:acquired,body:'PR'+nextPr+': выпуск занят'};
    const fetchImpl=async(url,options)=>{
      equal(options.method||'GET','GET','complete next-release admission remains read only');
      const u=new URL(url);equal(u.origin,'https://api.github.com','synthetic admission uses only the canonical origin');
      const q=u.pathname+u.search;
      if(q===R.API_ROOT+'/actions/jobs/'+x.p.job_id+'/logs'){
        const bytes=await x.f.api.jobLog(x.p.job_id);return {ok:true,status:200,headers:{get:()=>null},arrayBuffer:async()=>bytes};}
      const value=q===R.API_ROOT+'/pulls?state=open&base=main&sort=created&direction=desc&per_page=100&page=1'?[next]:
        q===R.API_ROOT+'/pulls/'+nextPr?next:await x.f.api('GET',q,undefined,
          options.headers?.Accept==='application/vnd.github.raw+json');
      return {ok:true,status:200,json:async()=>copy(value)};
    };
    const beforeAdmission=x.writes().length;
    const decision=await priority.runGuard({env:{GITHUB_REPOSITORY:R.REPOSITORY,GITHUB_TOKEN:'synthetic-only'},
      admission:{repository:R.REPOSITORY,prNumber:nextPr,headSha:x.source,baseSha:x.p.sha,publisher},
      now:Date.parse('2026-11-21T15:01:00Z'),fetchImpl});
    equal([decision.allowed,decision.reason,decision.publisher_comment],[true,'active_publisher',x.p.comment_id],
      'unchanged full canonical admission accepts a legitimate later acquisition of the same publisher');
    equal(x.writes().length,beforeAdmission,'historical recovery authentication never rewrites the new acquisition');
    equal(x.failure.conclusion,'failure','the original failed closer is never relabelled');
    const before=x.writes().length;
    await refuses(()=>C.resumeTerminal(x.options()),'publisher_continuation_not_active');
    equal(x.writes().length,before,'completed continuation cannot repeat writes');
  }
  {
    const x=publisherBodyFixture();x.native.status='queued';
    const proof=await C.resumePublisherBodyTerminal({...x.options(),prepare:true});
    x.env.PREPARED_RECORD_DIGEST=proof.record_digest;x.own.steps.push(step(R.TERMINAL_BINDING_STEP+proof.record_digest));
    equal(await C.resumePublisherBodyTerminal(x.options()),'released',
      'lagging queued run summary is admitted only with the exact assigned active native job');
    equal(x.terminalWrites().length,1,'summary lag does not cause a second mutation');
  }
  for(const mutate of [x=>x.env.GITHUB_RUN_ATTEMPT='2',x=>x.env.GITHUB_EVENT_NAME='workflow_dispatch',
    x=>x.env.GITHUB_ACTOR='foreign',x=>x.env.GITHUB_TRIGGERING_ACTOR='foreign',x=>x.event.before=x.p.sha,
    x=>x.event.created=false,x=>x.event.forced=true,x=>x.event.deleted=true,x=>x.event.after=H('wrong'),
    x=>x.env.GITHUB_SHA=x.p.sha,x=>x.event.sender.id++,x=>x.event.repository.id++]){
    const x=publisherBodyFixture();mutate(x);
    await refuses(()=>C.resumePublisherBodyTerminal({...x.options(),prepare:true}),'untrusted_publisher_body_continuation');
    equal(x.writes().length,0,'wrong original event has no write');
  }
  for(const [mutate,reason]of [
    [x=>x.f.main=H('changed main'),'main_changed'],
    [x=>x.native.actor.id++,'publisher_continuation_not_active'],[x=>x.native.triggering_actor.id++,'publisher_continuation_not_active'],
    [x=>x.own.runner_id=0,'publisher_continuation_not_active'],
    [x=>{x.native.status='queued';x.own.status='queued';},'publisher_continuation_not_active'],
    [x=>x.f.jobs.get(x.native.id)[1].steps.push(step('Unexpected configure')),'publisher_continuation_not_active'],
    [x=>x.branchRuns.push({...copy(x.native),id:x.native.id+100}),'replayed_terminal_continuation'],
    [x=>x.relation.files.pop(),'publisher_recovery_source_changed'],
    [x=>x.relation.files[0].filename='config.php','publisher_recovery_source_changed'],
    [x=>x.sourceFiles['tools/release_priority.js'].sha=H('changed priority'),'publisher_recovery_checkout_changed'],
    [x=>x.sourceFiles['tools/deployment_release.js'].sha=H('wrong checked source'),'publisher_recovery_checkout_changed'],
    [x=>x.f.runs.get(x.native.id+1).conclusion='failure','recovery_ci_unconfirmed'],
    [x=>x.f.runs.get(x.native.id+2).run_attempt=2,'recovery_ci_unconfirmed'],
    [x=>x.f.comment.body+=' ','publisher_body_changed'],
    [x=>x.f.comment.body=x.f.comment.body.replace('MAX2','MAX3'),'publisher_body_changed'],
    [x=>x.f.comment.body='PR1643: выпуск занят','publisher_body_changed'],
    [x=>x.f.comment.updated_at='2026-11-21T14:11:19Z','publisher_body_changed'],
    [x=>x.f.comment.user.id++,'foreign_record'],
    [x=>x.failure.steps[1].conclusion='success','failed_terminal_executed'],
    [x=>x.failure.steps[2].conclusion='success','failed_terminal_executed'],
    [x=>x.failure.run_attempt=2,'failed_terminal_not_original'],
    [x=>x.f.artifacts.set(Number(x.p.run_id),[{id:123}]),'original_terminal_already_published'],
    [x=>x.f.runs.get(Number(x.p.deploy)+2).conclusion='failure','required_gate_failed'],
    [x=>x.f.jobs.get(x.activation.id)[0].steps.push(step('Unexpected activation')),'activation_executed'],
    [x=>x.f.jobs.get(Number(x.p.run_id))[0].steps.push(step('Unexpected configure')),'configuration_executed']
  ]){
    const x=publisherBodyFixture();mutate(x);
    await refuses(()=>C.resumePublisherBodyTerminal({...x.options(),prepare:true}),reason);
    equal(x.writes().length,0,'changed bound evidence refuses before any publication');
  }
  {
    const x=publisherBodyFixture();x.f.api.jobLog=async()=>Buffer.from(T+' release_slot=held reason=write_unknown\n');
    await refuses(()=>C.resumePublisherBodyTerminal({...x.options(),prepare:true}),'failed_terminal_reason_changed');
    equal(x.writes().length,0,'an uncertain original write cannot use a proven-prewrite exception');
  }
  {
    const x=publisherBodyFixture(),proof=await C.resumePublisherBodyTerminal({...x.options(),prepare:true});
    x.env.PREPARED_RECORD_DIGEST=proof.record_digest;
    await refuses(()=>C.resumePublisherBodyTerminal(x.options()),'publication_step_unconfirmed');
    equal(x.terminalWrites().length,0,'no successful native proof binding means no comment PATCH');
  }
  for(const mode of ['patch_unknown','readback_changed','source_after_prepare','body_after_prepare','diagnostics_unknown']){
    const x=publisherBodyFixture();
    if(mode==='diagnostics_unknown'){
      x.f.hook=async c=>{if(c.method==='PATCH'&&c.path.endsWith('/git/refs/heads/diagnostics'))throw Error('synthetic unknown ref write');return x.originalHook(c);};
      await refuses(()=>C.resumePublisherBodyTerminal({...x.options(),prepare:true}),'diagnostics_write_unknown');
      equal(x.terminalWrites().length,0,'uncertain diagnostics publication retains the exact publisher');continue;
    }
    const proof=await C.resumePublisherBodyTerminal({...x.options(),prepare:true});
    x.env.PREPARED_RECORD_DIGEST=proof.record_digest;x.own.steps.push(step(R.TERMINAL_BINDING_STEP+proof.record_digest));
    if(mode==='source_after_prepare')x.sourceFiles['tools/release_slot_close.js'].sha=H('late source drift');
    if(mode==='body_after_prepare')x.f.comment.body+='changed';
    if(mode==='patch_unknown')x.f.hook=async c=>{if(c.method==='PATCH'&&c.path.includes('/issues/comments/'))throw Error('synthetic unknown PATCH');return x.originalHook(c);};
    if(mode==='readback_changed')x.f.hook=async c=>{if(c.method==='GET'&&c.path.includes('/issues/comments/')&&x.terminalWrites().length)
      return {...copy(x.f.comment),user:{id:1}};return x.originalHook(c);};
    await refuses(()=>C.resumePublisherBodyTerminal(x.options()),mode==='source_after_prepare'?'publisher_recovery_checkout_changed':
      mode==='body_after_prepare'?'publisher_body_changed':'write_unknown');
    equal(x.terminalWrites().length,mode.endsWith('prepare')?0:1,'mutation outcome never causes an automatic repeat');
  }
  for(const mutate of [x=>x.native.triggering_actor.id++,x=>x.own.steps.at(-1).conclusion='failure',
    x=>x.own.steps.pop()]){
    const x=publisherBodyFixture(),proof=await C.resumePublisherBodyTerminal({...x.options(),prepare:true});
    x.env.PREPARED_RECORD_DIGEST=proof.record_digest;x.own.steps.push(step(R.TERMINAL_BINDING_STEP+proof.record_digest));
    await C.resumePublisherBodyTerminal(x.options());x.complete();mutate(x);
    const before=x.writes().length;let rejected=false;
    try{await R.resolveCurrent(x.f.api,{sha:x.p.sha},await R.readRecord(x.f.api));}catch(e){rejected=true;}
    equal(rejected,true,'canonical acceptance requires actual owner and the original successful native terminal step');
    equal(x.writes().length,before,'reader refusal never repairs a publisher');
  }
  return assertions-startAssertions;
}

async function closedPrewriteOriginCases(){
  function fixture(){
    const source={id:45349080956,run_attempt:1,path:'.github/workflows/edit-1566-next-historical-telegram.yml',head_sha:'8d723f1c08f094fd845c7c34da29607fc294ff46',head_branch:'ops/anytour-hotel-stella-alternate-apply-20261123',head_repository:{full_name:'pyatkoff/max-search-bot'},event:'push',created_at:'2026-11-24T07:34:40Z',status:'completed',conclusion:'failure',actor:{id:226193297},triggering_actor:{id:226193297}};
    const own={id:138290722061,run_id:source.id,head_sha:source.head_sha,run_attempt:1,name:'transfer',status:'completed',conclusion:'failure',steps:[{name:'Apply the installed editor once and retain its original receipts',status:'completed',conclusion:'failure'},{name:'Save the original sanitized transfer result',status:'completed',conclusion:'success'}]};
    const original={id:14898229789,digest:'sha256:9245b40e6ebd9a0ff0f4e3f37870d2d410b9d5b5805bd648138e1421676f6259',name:'issue-1566-hotel-next-transfer',workflow_run:{id:source.id,head_sha:source.head_sha,head_branch:source.head_branch},expired:false,size_in_bytes:634};
    const read={...copy(source),id:45349424365,path:'.github/workflows/read-1566-stella-alternate-candidates.yml',head_sha:'162e2653e5617ff28f48a9168ccc62ac34a55006',head_branch:'audit/1566-next-release-admission-1664',conclusion:'success'};
    const readJob={id:138291848487,run_id:read.id,head_sha:read.head_sha,run_attempt:1,name:'read',status:'completed',conclusion:'success',steps:[{name:'Read existing archive posts, registry, and current ads',status:'completed',conclusion:'success'}]};
    const proof={id:14898804239,digest:'sha256:9b071432b9713f8ca46a4820ae2f8ed719f9a39d1cf20206b68e30ba7deda4a9',name:'stella925-original-readback',workflow_run:{id:read.id,head_sha:read.head_sha,head_branch:read.head_branch},expired:false,size_in_bytes:738};
    let reads=0;const api=async(method,p)=>{assert.equal(method,'GET');reads++;for(const r of [source,read])if(p.endsWith('/actions/runs/'+r.id))return copy(r);for(const j of [own,readJob])if(p.includes('/actions/runs/'+j.run_id+'/attempts/1/jobs?'))return {total_count:1,jobs:[copy(j)]};for(const a of [original,proof])if(p.endsWith('/actions/artifacts/'+a.id))return copy(a);throw Error('unlisted_request');};
    return {source,own,original,read,readJob,proof,api,reads:()=>reads};
  }
  const good=fixture();await R.proveClosedPrewriteOriginal(good.api);equal(good.reads(),6,'zero-write admission authenticates both the failed operation and its exact independent read');
  for(const mutate of [x=>x.source.status='in_progress',x=>x.original.digest='sha256:'+'0'.repeat(64),x=>x.original.size_in_bytes++,x=>x.read.conclusion='failure',x=>x.read.head_sha='a'.repeat(40),x=>x.read.triggering_actor.id++,x=>x.readJob.steps=[],x=>x.proof.workflow_run.id++,x=>x.proof.expired=true,x=>x.proof.digest='sha256:'+'0'.repeat(64)]){
    const x=fixture();mutate(x);await assert.rejects(()=>R.proveClosedPrewriteOriginal(x.api));assertions++;
  }
}

async function rankedResumeOriginCases(){
  const run=45321557294,job=138201073452,artifact=14887229025;
  function fixture(){
    const source={id:run,run_attempt:1,path:'.github/workflows/edit-1566-ranked-46-historical-telegram.yml',head_sha:'fdcbf29f831b68005295dfa398ecb5878d94e6c7',head_branch:'ops/anytour-hotel-ranked-37-continue-20261123',head_repository:{full_name:'pyatkoff/max-search-bot'},event:'push',created_at:'2026-11-24T03:43:00Z',status:'completed',conclusion:'failure',actor:{id:226193297},triggering_actor:{id:226193297}};
    const own={id:job,run_id:run,head_sha:source.head_sha,run_attempt:1,name:'transfer',status:'completed',conclusion:'failure',steps:[{name:'Apply the installed editor once and retain its original receipts',status:'completed',conclusion:'failure'},{name:'Save the original sanitized transfer result',status:'completed',conclusion:'success'}]};
    const proof={id:artifact,digest:'sha256:14741cf575a1999287fbd8c2b73ceb9a8c2146edeb3ad8a345d173bf8bd34b81',name:'issue-1566-hotel-ranked-46-transfer',workflow_run:{id:run,head_sha:source.head_sha,head_branch:source.head_branch},expired:false,size_in_bytes:4063};
    let reads=0;const api=async(method,p)=>{assert.equal(method,'GET');reads++;if(p.endsWith('/actions/runs/'+run))return copy(source);if(p.includes('/actions/runs/'+run+'/attempts/1/jobs?'))return {total_count:1,jobs:[copy(own)]};if(p.endsWith('/actions/artifacts/'+artifact))return copy(proof);throw Error('unlisted_request');};
    return {source,own,proof,api,reads:()=>reads};
  }
  const good=fixture();await R.proveRankedResumeOriginal(good.api);equal(good.reads(),3,'closed original and exact artifact require only authenticated reads');
  for(const mutate of [x=>x.source.status='in_progress',x=>x.source.head_sha='a'.repeat(40),x=>x.source.triggering_actor.id++,x=>x.own.run_attempt=2,x=>x.own.steps.pop(),x=>x.proof.digest='sha256:'+'0'.repeat(64),x=>x.proof.expired=true,x=>x.proof.size_in_bytes++,x=>x.proof.workflow_run.id++]){
    const x=fixture();mutate(x);await assert.rejects(()=>R.proveRankedResumeOriginal(x.api));assertions++;
  }
}

async function main(){await closedPrewriteOriginCases();await rankedResumeOriginCases();hotelRegistryReadWorkflow();await pureValidation();await gitBridge();await metadataWaitCases();await failureBoundaries();await scheduledActivationCases();await publishedStatusReadbackCases();await transportBoundaries();await transientReadCases();await terminalContinuationCases();await terminalReadCases();await gateContinuationCases();await unpublishedTerminalCases();await priorWaveCases();await publisherBodyRecoveryCases();await unstartedForwardCases();
  execFileSync(process.execPath,['--test',path.join(__dirname,'run_partial_install_1662_forward_regression.js')],{stdio:'inherit'});
  execFileSync(process.execPath,['--test',path.join(__dirname,'run_exhausted_verify_1664_forward_regression.js')],{stdio:'inherit'});
  execFileSync('python3',[path.join(__dirname,'run_partial_install_1662_forward_sync.py')],{stdio:'inherit'});
  process.stdout.write('Deployment release regression: '+assertions+' assertions passed\n');}
module.exports={closedPrewriteOriginCases,rankedResumeOriginCases,main,fixture,receipt,observation,installed,installNoopJobs,storedZip,terminalContinuationCases,publisherBodyRecoveryCases,max2HoldPairReadConsumer};
if(require.main===module)main().catch(e=>{process.stderr.write(String(e.stack||e)+'\n');process.exitCode=1;});


