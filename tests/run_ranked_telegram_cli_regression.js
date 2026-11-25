'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const {spawnSync} = require('node:child_process');
const R = require('../tools/deployment_release');
const {fixture, receipt} = require('./run_deployment_release_regression');

const root = path.resolve(__dirname, '..');
const readJson = file => JSON.parse(fs.readFileSync(path.join(root, file)));
const editor = R.PREFIX + 'edit-1566-ranked-46-historical-telegram.yml';
const owner = {id:226193297, login:'pyatkoff'};
const repository = {id:1342493152, full_name:R.REPOSITORY};
const parentId = 45372056681;
const parentHead = 'e3e1a9bdf29dd0d7530fe6b3b393f1286834d092';
const refusedId = 45403460387;
const refusedHead = 'dca4085f9a63ac1e7a5179c2a9b1b20febce4f82';
const currentId = refusedId + 1;
const head = 'b'.repeat(40);
const indexes = readJson('config/anytour_ranked_approved_indexes.json');

function approvedCase(digest) {
  const entry = indexes.find(row => row.sha256 === digest);
  const index = readJson(entry.path);
  const branch = index.native_source ? 'ops/anytour-hotel-ranked-plan-' + digest :
    'ops/anytour-hotel-ranked-after-' + parentId;
  return {index, branch, operation:index.native_source ? 'hotel_ranked_approved_input' : 'hotel_ranked_unreached'};
}

// Only the external read transport is simulated. The child executes the actual CLI,
// uses the checked-in HOTEL data and parses the original sanitized parent ZIP.
function offlineFetch(digest) {
  if (digest === 'publisher-keyboard') return publisherOfflineFetch();
  const {index, branch} = approvedCase(digest);
  const history = fixture(head, receipt(head));
  const run = (id, workflow, ref, sha, status, conclusion) => ({
    id, path:workflow, head_branch:ref, head_sha:sha, status, conclusion, run_attempt:1,
    created_at:'2026-11-24T13:26:00Z', event:'push', repository, head_repository:repository,
    actor:owner, triggering_actor:owner
  });
  const current = run(currentId, editor, branch, head, 'in_progress', null);
  const read = index.native_preflight, source = index.native_source;
  const native = run(Number(read.run_id), R.PREFIX + 'read-1566-telegram-46-20261123.yml',
    'ops/1566-telegram-46-native-read-20261123', source?.head_sha || 'db1157a5091ff0f708d7825acf6ca4e1dc9a5339', 'completed', 'success');
  const parent = run(parentId, editor, 'ops/anytour-hotel-ranked-resume-20261124', parentHead, 'completed', 'failure');
  const refused = run(refusedId, editor, 'ops/anytour-hotel-ranked-after-' + parentId, refusedHead, 'completed', 'failure');
  for (const row of [current, native, parent, refused]) history.runs.set(row.id, row);
  const step = (name, conclusion) => ({name, conclusion, status:'completed'});
  const job = (id, run, name, steps) => ({id, run_id:run.id, run_attempt:1, head_sha:run.head_sha,
    name, steps, status:run.status, conclusion:run.conclusion});
  history.jobs.set(current.id, [job(100, current, 'transfer', [])]);
  if (source) history.jobs.set(native.id,[job(source.job_id, native, 'registry',[step(source.step,'success')])]);
  history.jobs.set(parent.id, [job(138368539666, parent, 'transfer', [
    step('Resolve accepted repository and application','success'),
    step('Require exact APPLY admission before loading credentials','success'),
    step('Apply the installed editor once and retain its original receipts','failure'),
    step('Save the original sanitized transfer result','success')
  ])]);
  history.jobs.set(refused.id, [job(138477791041, refused, 'transfer', [
    step('Check out the native run source','success'), step('Resolve accepted repository and application','failure'),
    step('Require exact APPLY admission before loading credentials','skipped'),
    step('Pin established SSH host continuity and authentication identity','skipped'),
    step('Apply the installed editor once and retain its original receipts','skipped'),
    step('Save the original sanitized transfer result','success')
  ])]);
  const artifact = (id, name, sha, bytes, run) => ({id, name, expired:false, digest:'sha256:' + sha, size_in_bytes:bytes,
    workflow_run:{id:run.id, head_sha:run.head_sha, head_branch:run.head_branch, repository_id:repository.id}});
  history.artifacts.set(native.id, [artifact(read.artifact_id, source?.artifact_name || 'issue-1566-telegram-46-preflight',
    read.artifact_zip_sha256, index.native_zip_bytes, native)]);
  const archive = fs.readFileSync(path.join(__dirname, 'fixtures/ranked_telegram_parent_45372056681.zip'));
  history.artifacts.set(parent.id, [artifact(14909137840, 'issue-1566-hotel-ranked-46-transfer',
    '3f0e0462ddd4fd0ab0e186de8e2b17f4f92752cd5700525f54e9cf9f0dd3034c', archive.length, parent)]);
  return async (address, options) => {
    assert.equal(options.method, 'GET');
    const url = new URL(address);
    assert.equal(url.origin, 'https://api.github.com');
    const route = url.pathname.slice(R.API_ROOT.length);
    if (route === '/actions/artifacts/14909137840/zip') return new Response(archive);
    if (route === '/actions/jobs/138477791041/logs') {
      return new Response('2026-11-24T13:26:49.1103064Z deployment_release=refused reason=invalid_output\n');
    }
    let data;
    if (route.startsWith('/contents/config/')) {
      assert.equal(url.searchParams.get('ref'), head);
      const file = route.slice('/contents/'.length);
      const bytes = fs.readFileSync(path.join(root,file));
      data = {type:'file',path:file,size:bytes.length,encoding:'base64',content:bytes.toString('base64'),sha:R.gitBlob(bytes)};
    } else if (route === '/git/ref/heads/' + branch) {
      data = {object:{sha:head}};
    } else if (route === '/compare/' + parentHead + '...' + head) {
      data = {status:'ahead',merge_base_commit:{sha:parentHead}};
    } else if (route === '/compare/' + refusedHead + '...' + head) {
      data = {status:'ahead',merge_base_commit:{sha:refusedHead}};
    } else if (url.searchParams.has('branch') && route === '/actions/workflows/' + path.basename(editor) + '/runs') {
      const rows = [current,parent,refused].filter(row => row.head_branch === url.searchParams.get('branch'));
      data = {total_count:rows.length,workflow_runs:rows};
    } else {
      data = await history.api('GET', url.pathname + url.search, undefined, options.headers.Accept.includes('raw'));
    }
    return Response.json(data);
  };
}

function publisherOfflineFetch() {
  const branch='ops/anytour-publisher-keyboard-1047-20261125';
  const history=fixture(head,receipt(head));
  const current={id:currentId,path:editor,head_branch:branch,head_sha:head,status:'in_progress',conclusion:null,
    run_attempt:1,created_at:'2026-11-25T21:00:00Z',event:'push',repository,head_repository:repository,
    actor:owner,triggering_actor:owner};
  history.runs.set(current.id,current);
  history.jobs.set(current.id,[{id:100,run_id:current.id,run_attempt:1,head_sha:head,name:'transfer',
    steps:[],status:'in_progress',conclusion:null}]);
  return async (address,options) => {
    assert.equal(options.method,'GET');
    const url=new URL(address);
    assert.equal(url.origin,'https://api.github.com');
    const route=url.pathname.slice(R.API_ROOT.length);
    let data;
    if (route==='/contents/config/anytour_publisher_keyboard_pilot_20261125.json') {
      assert.equal(url.searchParams.get('ref'),head);
      const file=route.slice('/contents/'.length),bytes=fs.readFileSync(path.join(root,file));
      data={type:'file',path:file,size:bytes.length,encoding:'base64',content:bytes.toString('base64'),sha:R.gitBlob(bytes)};
    } else if (route==='/git/ref/heads/'+branch) {
      data={object:{sha:head}};
    } else if (url.searchParams.has('branch') && route==='/actions/workflows/'+path.basename(editor)+'/runs') {
      assert.equal(url.searchParams.get('branch'),branch);
      data={total_count:1,workflow_runs:[current]};
    } else {
      data=await history.api('GET',url.pathname+url.search,undefined,options.headers.Accept.includes('raw'));
    }
    return Response.json(data);
  };
}

function publisherCliCase(temp,command,field,preload) {
  const branch='ops/anytour-publisher-keyboard-1047-20261125',ref='refs/heads/'+branch;
  const digest=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,
    'config/anytour_publisher_keyboard_pilot_20261125.json'))).digest('hex');
  const output=path.join(temp,'publisher-output'),eventPath=output+'.json';
  fs.writeFileSync(eventPath,JSON.stringify({repository,sender:owner,ref,after:head,
    before:'0'.repeat(40),created:true,deleted:false,forced:false}));
  const child=spawnSync('bash',['-eu','-c',command],{cwd:root,encoding:'utf8',env:{
    PATH:process.env.PATH,NODE_OPTIONS:'--require '+JSON.stringify(preload),RANKED_CLI_FIXTURE:'publisher-keyboard',
    GITHUB_TOKEN:'offline-fixture',GITHUB_OUTPUT:output,GITHUB_EVENT_PATH:eventPath,GITHUB_SHA:head,
    GITHUB_REPOSITORY:R.REPOSITORY,GITHUB_ACTOR:owner.login,GITHUB_TRIGGERING_ACTOR:owner.login,
    GITHUB_REF:ref,GITHUB_EVENT_NAME:'push',GITHUB_JOB:'transfer',GITHUB_RUN_ATTEMPT:'1',
    GITHUB_WORKFLOW_REF:R.REPOSITORY+'/'+editor+'@'+ref,GITHUB_RUN_ID:String(currentId)
  }});
  assert.equal(child.status,0,child.stderr);
  const values=Object.fromEntries(fs.readFileSync(output,'utf8').trimEnd().split('\n').map(line=>{
    const boundary=line.indexOf('=');return [line.slice(0,boundary),line.slice(boundary+1)];
  }));
  assert.equal(values[field('PILOT_SHA')],digest);
  assert.equal(values[field('WRITE_OPERATION')],'hotel_publisher_keyboard_pilot');
  assert.equal(values[field('READY')],'true');assert.equal(values[field('PERFORM_ACTIONS')],'true');
  assert.equal(values[field('REPOSITORY_SHA')],head);assert.equal(values[field('APPLICATION_SHA')],head);
  assert.equal(values[field('INDEX_SHA')],undefined);assert.equal(values[field('NATIVE_ARTIFACT_ID')],undefined);
  assert.equal(values[field('PARENT_EVIDENCE')],undefined);
  return digest;
}

// Execute the actual YAML shell blocks with only the external PHP/SSH boundaries
// replaced. A pilot must never download a ranked artifact or invoke a second edit.
function publisherWorkflowCases(temp,workflow,digest) {
  const branch='ops/anytour-publisher-keyboard-1047-20261125';
  const script=name=>{
    const start=workflow.indexOf('      - name: '+name+'\n');assert.ok(start>=0);
    const end=workflow.indexOf('\n      - name: ',start+1);
    const block=workflow.slice(start,end<0?workflow.length:end),marker='        run: |\n';
    assert.ok(block.includes(marker));
    return block.slice(block.indexOf(marker)+marker.length).split('\n').map(l=>l.startsWith('          ')?l.slice(10):l).join('\n');
  };
  const checkScript=script('Require exact APPLY admission before loading credentials');
  const applyScript=script('Apply the installed editor once and retain its original receipts');
  const fake=path.join(temp,'pilot-tools');fs.mkdirSync(fake);
  const executable=(name,source)=>fs.writeFileSync(path.join(fake,name),'#!'+process.execPath+'\n'+source,{mode:0o700});
  executable('git',"if(process.argv.slice(2).join(' ')!=='rev-parse HEAD')process.exit(81);console.log(process.env.GITHUB_SHA);\n");
  executable('gh',"require('node:fs').appendFileSync(process.env.CALL_LOG,'ranked-artifact-forbidden\\n');process.exit(82);\n");
  executable('php',"const fs=require('node:fs');fs.appendFileSync(process.env.CALL_LOG,JSON.stringify({php:process.argv.slice(2)})+'\\n');process.stdout.write(process.env.CHECK_JSON);\n");
  executable('ssh',"const fs=require('node:fs');fs.appendFileSync(process.env.SSH_LOG,JSON.stringify(process.argv.slice(2))+'\\n');process.stdout.write(process.env.APPLY_JSON);process.exit(Number(process.env.APPLY_EXIT));\n");
  const checked={ok:true,mode:'offline_plan_check',operation:'hotel_publisher_keyboard_pilot',plan_sha256:digest,
    message_id:'1047',full_transfer:false,production_writes:0,provider_writes:0};
  const result={ok:true,operation:'hotel_publisher_keyboard_pilot',plan_sha256:digest,message_id:'1047',full_transfer:false,
    native_run_id:String(currentId),application_sha:head,provider_write_attempts:1,supplier_requests:0,bot_starts:0,
    state:'publisher_keyboard_verified',caption_preserved:true,photo_preserved:true,date_preserved:true,button_urls_preserved:true};
  let sequence=0;
  const setup=(check=checked,reply=result,exit=0,extra={})=>{
    const dir=path.join(temp,'pilot-case-'+sequence++);fs.mkdirSync(dir);fs.mkdirSync(path.join(dir,'tmp'));
    const env={PATH:fake+path.delimiter+process.env.PATH,RUNNER_TEMP:path.join(dir,'tmp'),
      READY:'true',PERFORM_ACTIONS:'true',WRITE_OPERATION:'hotel_publisher_keyboard_pilot',PILOT_SHA:digest,
      INDEX_SHA:'',NATIVE_ARTIFACT_ID:'',PARENT_EVIDENCE:'',GITHUB_REF:'refs/heads/'+branch,
      GITHUB_REF_NAME:branch,REPOSITORY_SHA:head,GITHUB_SHA:head,APPLICATION_SHA:head,GITHUB_RUN_ID:String(currentId),
      HOST_VALUE:'offline.invalid',USER_VALUE:'offline',CALL_LOG:path.join(dir,'calls'),SSH_LOG:path.join(dir,'ssh'),
      CHECK_JSON:JSON.stringify(check),APPLY_JSON:typeof reply==='string'?reply:JSON.stringify(reply),APPLY_EXIT:String(exit),...extra};
    const run=source=>spawnSync('bash',['-eu','-c',source],{cwd:dir,env,encoding:'utf8'});
    const summary=()=>JSON.parse(fs.readFileSync(path.join(dir,'pub/summary.json')))[digest];
    return {dir,env,run,summary};
  };
  for(const [name,patch,env] of [['wrong MID',{message_id:'1048'},{}],['transfer',{full_transfer:true},{}],
    ['wrong digest',{plan_sha256:'0'.repeat(64)},{}],['write in check',{provider_writes:1},{}],
    ['missing boolean',{full_transfer:undefined},{}],['uninstalled editor',{}, {APPLICATION_SHA:'a'.repeat(40)}],
    ['wrong operation',{}, {WRITE_OPERATION:'hotel_ranked_approved_input'}]]) {
    const c=setup({...checked,...patch},result,0,env),out=c.run(checkScript);
    assert.notEqual(out.status,0,'offline pilot gate must reject '+name);
    assert.equal(fs.existsSync(c.env.SSH_LOG),false,'no SSH before a valid offline control');
  }
  for(const [name,reply,exit,accepted] of [
    ['verified',result,0,true],
    ['NOT_MODIFIED',{...result,ok:false,state:'publisher_keyboard_not_modified'},2,false],
    ['UNKNOWN',{...result,ok:false,state:'publisher_keyboard_unknown'},2,false],
    ['provider reject',{...result,ok:false,state:'provider_rejected_edit'},2,false],
    ['two attempts',{...result,provider_write_attempts:2},0,false],
    ['boolean attempts',{...result,provider_write_attempts:true},0,false],
    ['wrong target',{...result,message_id:'1048'},0,false],
    ['wrong application',{...result,application_sha:'a'.repeat(40)},0,false],
    ['missing full-transfer distinction',{...result,full_transfer:undefined},0,false],
    ['changed photo',{...result,photo_preserved:false},0,false],
    ['unconfirmed accepted response',{...result,state:'publisher_keyboard_not_modified'},0,false],
    ['lost result','',255,false]
  ]) {
    const c=setup(checked,reply,exit),preflight=c.run(checkScript);
    assert.equal(preflight.status,0,preflight.stderr);
    const checks=fs.readFileSync(c.env.CALL_LOG,'utf8').trim().split('\n').map(JSON.parse);
    assert.deepEqual(checks,[{php:['tools/anytour_existing_post_batch.php','--check-publisher-keyboard-pilot',digest]}]);
    const out=c.run(applyScript);assert.equal(out.status===0,accepted,name+'\n'+out.stderr);
    const calls=fs.readFileSync(c.env.SSH_LOG,'utf8').trim().split('\n').map(JSON.parse);
    assert.equal(calls.length,1,'exactly one external invocation for '+name);
    assert.ok(calls[0].at(-1).endsWith('php tools/anytour_existing_post_batch.php --apply-publisher-keyboard-pilot '+digest+' '+head+' '+currentId));
    assert.equal(c.summary().full_transfer,false);
    if(accepted)assert.equal(c.summary().state,'complete');
    assert.notEqual(c.run(applyScript).status,0,'local invocation fence refuses replay after '+name);
    assert.equal(fs.readFileSync(c.env.SSH_LOG,'utf8').trim().split('\n').length,1,'replay cannot reach SSH');
  }
}

function runTests() {
  const workflow = fs.readFileSync(path.join(root, editor), 'utf8');
  const command = 'node tools/deployment_release.js resolve-consumer >> "$GITHUB_OUTPUT"';
  assert.ok(workflow.includes('run: ' + command));
  const field = name => {
    const ref = workflow.match(new RegExp('^ +'+name+': \\$\\{\\{ steps\\.release\\.outputs\\.([a-z_0-9]+) \\}\\}$','m'));
    assert.ok(ref, 'Missing workflow input ' + name);
    return ref[1];
  };
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'ranked-telegram-cli-'));
  try {
    const preload = path.join(temp, 'offline.cjs');
    fs.writeFileSync(preload, 'let client; globalThis.fetch = (...args) => (client ||= require(' + JSON.stringify(__filename)
      + ').offlineFetch(process.env.RANKED_CLI_FIXTURE))(...args);');
    for (const digest of ['30d58e6649877a0eb07deb7e2fddcfa99c92081aac5fbef8c3c2b1d7a4a92833',
      '0d9d9ed0e2bcb537d829acd36547d92ad3d468d48c1d508d35368acb5c879709',
      'c469e510576dea8eefe50b0e2405f4899d5af155627182d34877390227922658']) {
      const {index,branch,operation} = approvedCase(digest);
      const ref = 'refs/heads/' + branch;
      const output = path.join(temp,digest), eventPath = output + '.json';
      const created = Boolean(index.native_source);
      fs.writeFileSync(eventPath, JSON.stringify({repository,sender:owner,ref,after:head,
        before:created ? '0'.repeat(40) : refusedHead, created,deleted:false,forced:false}));
      const child = spawnSync('bash',['-eu','-c',command],{cwd:root,encoding:'utf8',env:{
        PATH:process.env.PATH, NODE_OPTIONS:'--require ' + JSON.stringify(preload), RANKED_CLI_FIXTURE:digest,
        GITHUB_TOKEN:'offline-fixture', GITHUB_OUTPUT:output, GITHUB_EVENT_PATH:eventPath, GITHUB_SHA:head,
        GITHUB_REPOSITORY:R.REPOSITORY, GITHUB_ACTOR:owner.login, GITHUB_TRIGGERING_ACTOR:owner.login,
        GITHUB_REF:ref, GITHUB_EVENT_NAME:'push', GITHUB_JOB:'transfer', GITHUB_RUN_ATTEMPT:'1',
        GITHUB_WORKFLOW_REF:R.REPOSITORY + '/' + editor + '@' + ref, GITHUB_RUN_ID:String(currentId)
      }});
      assert.equal(child.status,0,child.stderr);
      const values = Object.fromEntries(fs.readFileSync(output,'utf8').trimEnd().split('\n').map(line => {
        const boundary = line.indexOf('=');
        return [line.slice(0,boundary),line.slice(boundary+1)];
      }));
      assert.equal(values[field('INDEX_SHA')],digest);
      assert.equal(values[field('NATIVE_ARTIFACT_ID')],String(index.native_preflight.artifact_id));
      assert.equal(values[field('WRITE_OPERATION')],operation);
      assert.equal(values[field('READY')],'true');
      assert.equal(values[field('PERFORM_ACTIONS')],'true');
      assert.equal(values[field('REPOSITORY_SHA')],head);
      assert.equal(values[field('APPLICATION_SHA')],head);
      if (!index.native_source) {
        const evidence = JSON.parse(values[field('PARENT_EVIDENCE')]);
        assert.equal(evidence.parent_run_id, String(parentId));
        assert.equal(evidence.summary.unreached.length, 17);
      } else {
        assert.equal(values[field('PARENT_EVIDENCE')], undefined);
      }
    }
    const pilotDigest=publisherCliCase(temp,command,field,preload);
    publisherWorkflowCases(temp,workflow,pilotDigest);
    for (const invalid of [{bad1:'x'},{'bad-key':'x'},{good:'x\ny'},{good:'x\ry'}]) {
      assert.throws(() => R.outputs(invalid), error => error.code === 'invalid_output');
    }
  } finally { fs.rmSync(temp,{recursive:true,force:true}); }
}

module.exports = {offlineFetch};
if (require.main === module) runTests();
