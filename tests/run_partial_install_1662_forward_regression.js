'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const {provePartialInstall1662Forward: prove} = require('../tools/partial_install_1662_forward');

const repo = {id:1342493152, full_name:'pyatkoff/max-search-bot'};
const owner = {id:226193297, login:'pyatkoff'};
const original = '302ba66b5739e369a4df94471c8cfe9c054dd070';
const oldHead = '3bdb9dbe6a6c706a5f870cd7d6f85aad2cf1b495';
const diagnostics = 'db9f222955811721ee30fc7d1ffce435a2f48e90';
const head = '1'.repeat(40);
const source = '2'.repeat(40);
const runId = 90000001;
const oldStamp = '2026-11-23T10:42:51Z';
const newStamp = '2026-11-23T13:50:00Z';
const slot = '/issues/comments/7077907800';
const filesPath = '/pulls/1667/files?per_page=100&page=1';
const comparePath = '/compare/' + original + '...' + head + '?per_page=1';
const liveRun = '/actions/runs/' + runId;
const census = '/actions/runs?head_sha=' + source + '&per_page=100&page=1';
const baseFile = '/contents/deploy_status.json?ref=' + diagnostics;
const mergeCommit = '/git/commits/' + source;
const mergePulls = '/commits/' + source + '/pulls?per_page=100';
const scope = {
  '.github/workflows/deploy.yml':'modified', 'tools/deployment_release.js':'modified',
  'tools/release_priority.js':'modified', 'tools/partial_install_1662_forward.js':'added',
  'tests/run_partial_install_1662_forward_regression.js':'added', 'tests/run_partial_install_1662_forward_sync.py':'added',
  'tests/run_deployment_release_regression.js':'modified', 'tests/fixtures/pr1643/release_priority.js.txt':'added'
};
const sourceCensus='/actions/runs?head_sha='+head+'&per_page=100&page=1';
function sourceRuns(state) {
  const checks=[[340550044,'regression-tests.yml'],[348353174,'retired-domain-guard.yml']];
  const runs=checks.map(([workflow_id,file],index)=>{
    const run={id:92000001+index,workflow_id,path:'.github/workflows/'+file,run_attempt:1,event:'pull_request',
      head_sha:head,head_branch:'review/pr1662-partial-install-20261123',status:'completed',conclusion:'success',
      repository:repo,head_repository:repo,pull_requests:[{number:1667,head:{ref:'review/pr1662-partial-install-20261123',sha:head,repo},
        base:{ref:'main',repo}}]};
    state['/actions/runs/'+run.id]=structuredClone(run);
    return run;
  });
  state[sourceCensus]={total_count:runs.length,workflow_runs:runs};
}
function marker(sha, date) {
  const value = {comment_id:7077907800, head_sha:sha, owner_id:owner.id, updated_at:date};
  return '<!-- release-slot-close:v1 ' + JSON.stringify(value) + ' -->';
}

// Provide plausible metadata but no accepted baseline or real job log. A missing
// parallel read fails on the next event-loop turn, after a supplied malformed
// response is checked. This suite never requests network access or credentials.
function simulation(phase) {
  const merged = phase === 'installation';
  const priorSlot = phase === 'before_handoff';
  const target = {number:1667, state:merged?'closed':'open', merged, draft:false, changed_files:8,
    user:owner, base:{ref:'main',repo,sha:original}, head:{ref:'review/pr1662-partial-install-20261123',repo,sha:head},
    merge_commit_sha:merged?source:null, body:priorSlot?'':marker(head,newStamp)};
  const files = Object.entries(scope).map(([filename,status],i)=>({filename,status,sha:String(i+1).repeat(40)}));
  const run = {id:runId, workflow_id:340734782, path:'.github/workflows/deploy.yml', head_sha:source, head_branch:'main',
    event:'push', head_commit:{id:source},run_attempt:1,status:'in_progress',conclusion:null,
    repository:repo,head_repository:repo,actor:owner,triggering_actor:owner};
  const state = {
    '/git/ref/heads/main': {ref:'refs/heads/main',object:{type:'commit',sha:merged?source:original}},
    '/git/ref/heads/diagnostics': {ref:'refs/heads/diagnostics',object:{type:'commit',sha:diagnostics}},
    '/pulls/1662': {number:1662,state:'closed',merged:true,head:{repo,sha:oldHead},base:{ref:'main',repo},
      merge_commit_sha:original,body:marker(oldHead,oldStamp)},
    '/pulls/1667':target,
    [slot]:{id:7077907800,user:{...owner},author_association:'OWNER',issue_url:'https://api.github.com/repos/'+repo.full_name+'/issues/1122',
      updated_at:priorSlot?oldStamp:newStamp,body:priorSlot?'PR1662: выпуск занят':'PR1667: выпуск занят'},
    [comparePath]:{status:'ahead',merge_base_commit:{sha:original},files:structuredClone(files)},
    [filesPath]:files
  };
  sourceRuns(state);
  const missing = [];
  function absent(key) {
    missing.push(key);
    return new Promise((_,reject)=>setImmediate(()=>reject(new Error('offline evidence absent: '+key))));
  }
  async function api(method, path) {
    assert.equal(method,'GET');
    const prefix='/repos/'+repo.full_name;
    assert.ok(path.startsWith(prefix+'/'));
    const key=path.slice(prefix.length);
    return Object.hasOwn(state,key) ? structuredClone(state[key]) : absent(key);
  }
  api.jobLog=id=>absent('job log '+id);
  const options={pr:1667,head,phase,api};
  if (merged) {
    state[mergeCommit]={sha:source,parents:[{sha:original},{sha:head}]};
    state[mergePulls]=[{number:1667,state:'closed',merge_commit_sha:source,head:{sha:head}}];
    state[liveRun]=run;
    state[census]={total_count:1,workflow_runs:[run]};
    Object.assign(options,{source,runId,event:{ref:'refs/heads/main',before:original,after:source,forced:false,
      head_commit:{id:source},sender:{...owner},repository:{...repo}}});
  }
  return {state,options,missing};
}

test('a getter and native log reader are both required',async()=>{
  for(const api of [undefined,{},()=>{}]) await assert.rejects(prove({api}),/read-only adapter missing/);
});
for(const phase of ['before_handoff','after_handoff','installation']) {
  test(phase+' cannot be admitted by PR metadata or a plausible publisher alone',async()=>{
    const {options,missing}=simulation(phase);
    await assert.rejects(prove(options),{message:'offline evidence absent: '+baseFile});
    assert.deepEqual(missing,[baseFile]);
  });
}
for(const [label,phase,alter,error] of [
  ['different PR','before_handoff',({options})=>{options.pr=1668;},/wrong successor/],
  ['self-declared forward phase','before_handoff',({options})=>{options.phase='approved';},/wrong successor/],
  ['install parameters supplied before handoff','before_handoff',({options})=>{options.source=source;},/unexpected installation context/],
  ['main advanced before acquisition','before_handoff',({state})=>{state['/git/ref/heads/main'].object.sha=source;},/current main changed/],
  ['foreign fork','after_handoff',({state})=>{state['/pulls/1667'].head.repo={...repo,id:1};},/successor pull changed/],
  ['different branch','before_handoff',({state})=>{state['/pulls/1667'].head.ref='other';},/successor pull changed/],
  ['draft pull','before_handoff',({state})=>{state['/pulls/1667'].draft=true;},/successor pull changed/],
  ['unrelated candidate','after_handoff',({state})=>{state[comparePath].merge_base_commit.sha=head;},/head ancestry changed/],
  ['changed file count','before_handoff',({state})=>{state['/pulls/1667'].changed_files=9;},/pull content changed/],
  ['omitted required test','after_handoff',({state})=>{state[filesPath].pop();},/file count changed/],
  ['extra application file','before_handoff',({state})=>{state[comparePath].files.push({filename:'app.php'});},/file count changed/],
  ['business file substitution','installation',({state})=>{state[filesPath][0].filename='app.php';},/file identity changed/],
  ['renamed file','before_handoff',({state})=>{state[filesPath][0].previous_filename='old';},/file identity changed/],
  ['allowed path with unexpected change type','installation',({state})=>{state[filesPath][0].status='removed';},/file identity changed/],
  ['duplicate path','before_handoff',({state})=>{state[filesPath][0]=state[filesPath][1];},/file identity changed/],
  ['unbound blob SHA','after_handoff',({state})=>{state[filesPath][0].sha='e'.repeat(40);},/pull content changed/],
  ['successor claiming the publisher before acquisition','before_handoff',({state})=>{state[slot].body='PR1667: выпуск занят';},/record_changed/],
  ['original publisher after acquisition','after_handoff',({state})=>{state[slot].body='PR1662: выпуск занят';},/record_changed/],
  ['foreign publisher','after_handoff',({state})=>{state[slot].user.id++;},/foreign_record/],
  ['publisher marker without corresponding write','after_handoff',({state})=>{state['/pulls/1667'].body=marker(head,'2026-11-23T13:49:00Z');},/record_changed/],
  ['already released publisher','installation',({state})=>{state[slot].body='PR1667: установлено, выпуск свободен';},/publisher inactive/],
  ['missing retired-domain source check','before_handoff',({state})=>{state[sourceCensus].workflow_runs.pop();state[sourceCensus].total_count--;},/missing or duplicate source check/],
  ['truncated source census','installation',({state})=>{state[sourceCensus].total_count++;},/incomplete run census/],
  ['duplicate run for source workflow','after_handoff',({state})=>{state[sourceCensus].workflow_runs.push({...state[sourceCensus].workflow_runs[0],id:999});state[sourceCensus].total_count++;},/missing or duplicate source check/],
  ['source check with missing successful conclusion','before_handoff',({state})=>{state['/actions/runs/92000001'].conclusion=null;},/source check not successful/],
  ['source check manual dispatch','after_handoff',({state})=>{state[sourceCensus].workflow_runs[1].event='workflow_dispatch';},/source check not successful/],
  ['source check on second attempt','installation',({state})=>{state['/actions/runs/92000002'].run_attempt=2;},/source check not successful/],
  ['foreign source repository','before_handoff',({state})=>{state['/actions/runs/92000001'].head_repository.id++;},/source check repository changed/],
  ['foreign pull in source check','after_handoff',({state})=>{state['/actions/runs/92000002'].pull_requests[0].number++;},/source check pull changed/],
  ['multiple pull references in source check','installation',({state})=>{state['/actions/runs/92000002'].pull_requests.push({number:1668});},/ambiguous source check pull/],
  ['forced push','installation',({options})=>{options.event.forced=true;},/untrusted successor push/],
  ['foreign sender','installation',({options})=>{options.event.sender.id++;},/untrusted successor push/],
  ['wrong second merge parent','installation',({state})=>{state[mergeCommit].parents[1].sha=original;},/direct successor/],
  ['ambiguous merge','installation',({state})=>{state[mergePulls].push({...state[mergePulls][0],number:1668});},/ambiguous successor/],
  ['rerun','installation',({state})=>{state[liveRun].run_attempt=2;},/wrong live deployment/],
  ['dispatch','installation',({state})=>{state[liveRun].event='workflow_dispatch';},/wrong live deployment/],
  ['completed deploy','installation',({state})=>{state[liveRun].status='completed';},/wrong live deployment/],
  ['second deploy at same source','installation',({state})=>{state[census].workflow_runs.push({...state[liveRun],id:2});state[census].total_count++;},/duplicate or replayed/],
  ['fabricated baseline bytes','after_handoff',({state})=>{state[baseFile]={type:'file',path:'deploy_status.json',encoding:'base64',
    sha:'472593b8fb3c1418b8b6b43b008278ab7a8125b7',size:4206,content:Buffer.alloc(4206).toString('base64')};},/baseline bytes changed/]
]) test('rejects '+label,async()=>{
  const x=simulation(phase);
  alter(x);
  await assert.rejects(prove(x.options),error);
});

test('premerge reader requests the JSON contents envelope and checks its bytes',async()=>{
  const {verifyPreviousRelease}=require('../tools/release_priority');
  const {state}=simulation('before_handoff');
  state[baseFile]={type:'file',path:'deploy_status.json',encoding:'base64',
    sha:'472593b8fb3c1418b8b6b43b008278ab7a8125b7',size:4206,content:Buffer.alloc(4206).toString('base64')};
  const media=[];
  async function localFetch(address,request) {
    const url=new URL(address);
    assert.equal(url.origin,'https://api.github.com');
    const root='/repos/'+repo.full_name;
    assert.ok(url.pathname.startsWith(root+'/'));
    const path=url.pathname.slice(root.length)+url.search;
    assert.ok(Object.hasOwn(state,path),'unexpected test path '+path);
    if(path===baseFile)media.push(request.headers.Accept);
    return {ok:true,status:200,json:async()=>structuredClone(state[path])};
  }
  await assert.rejects(verifyPreviousRelease({repository:repo.full_name,prNumber:1667,headSha:head,baseSha:original},
    'offline-test-token',localFetch),/baseline bytes changed/);
  assert.deepEqual(media,['application/vnd.github+json']);
});
