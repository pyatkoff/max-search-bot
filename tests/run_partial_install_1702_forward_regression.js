'use strict';
const assert = require('node:assert/strict');
const {test} = require('node:test');
const {provePartialInstall1702Forward: prove} = require('../tools/partial_install_1702_forward');

test('an untrusted installation cannot read proof data or advance the publisher', async () => {
  let count = 0;
  const api = Object.assign(async () => { count++; throw Error('unexpected API read'); },
    {jobLog: async () => { count++; throw Error('unexpected native log read'); }});
  const options = {api, pr:1702, head:'9b0a8a5a4dc252b977f95bee2b890287cdbbec1f',
    phase:'installation', source:'32155b9eb2c8606b61554951bfdc988d70bbcb39', runId:45416010115,
    event:{ref:'refs/heads/main', forced:true}};
  await assert.rejects(prove(options));
  assert.equal(count, 0);
});

test('the failed public preflight cannot release the occupied publisher', async () => {
  const proof = require('../tools/partial_install_1702_forward');
  assert.equal(proof.allowedLog(138531045324), true);
  assert.equal(proof.allowedLog(138535934949), false);
  assert.equal(proof.allowedLog(null), false);
  await assert.rejects(proof.publicEvidence({}), /positive public containment receipt pending/);
});

const {proveCheckedSuccessor} = require('../tools/partial_install_1662_forward');

// These in-memory cases exercise admission and rereads. Their history has no HTTP
// status, network evidence or credentials and is never used by the product entry.
const repo = {id:1342493152, full_name:'pyatkoff/max-search-bot'};
const actor = {id:226193297, login:'pyatkoff'};
const main = '32155b9eb2c8606b61554951bfdc988d70bbcb39';
const original = '9b0a8a5a4dc252b977f95bee2b890287cdbbec1f';
const diagnostics = 'a22ddee1b6993123b0a0ed6a1fe7e5ab24f211f6';
const source = '1'.repeat(40), head = '2'.repeat(40);
const branch = 'fix/1702-checked-forward-install';
const slot = 6818332618, runId = 900001;
const oldStamp = '2026-11-24T14:35:09Z', newStamp = '2026-11-24T18:00:00Z';
const oldBinding = {comment_id:slot, head_sha:original, owner_id:actor.id, updated_at:oldStamp};
const newBinding = {...oldBinding, head_sha:head, updated_at:newStamp};
const marker = value => '<!-- release-slot-close:v1 ' + JSON.stringify(value) + ' -->';
const census = sha => '/actions/runs?head_sha=' + sha + '&per_page=100&page=1';
const filesPath = '/pulls/1704/files?per_page=100&page=1';

function simulate(phase) {
  const merged = phase === 'installation';
  const before = phase === 'before_handoff';
  const file = {filename:'tools/partial_install_1702_forward.js', status:'added', sha:'3'.repeat(40)};
  const files = [file];
  const baseline = {fixture:'previous accepted release'};
  const profile = {
    previousPr:1702, pr:1704, main, head:original, binding:oldBinding, branch, diagnostics,
    files:{[file.filename]:file.status}, baselineBytes:body=>assert.deepEqual(body,baseline),
    history: async () => ({fixture_evidence:true}),
  };
  const target = {
    number:1704, user:actor, state:merged?'closed':'open', merged, draft:false, changed_files:1,
    head:{ref:branch,repo,sha:head}, base:{ref:'main',repo,sha:main},
    merge_commit_sha:merged?source:null, body:before?'':marker(newBinding),
  };
  const state = {
    '/git/ref/heads/main':{ref:'refs/heads/main',object:{type:'commit',sha:merged?source:main}},
    '/git/ref/heads/diagnostics':{ref:'refs/heads/diagnostics',object:{type:'commit',sha:diagnostics}},
    ['/contents/deploy_status.json?ref='+diagnostics]:baseline,
    '/pulls/1702':{number:1702,state:'closed',merged:true,merge_commit_sha:main,head:{repo,sha:original},
      base:{ref:'main',repo},body:marker(oldBinding)},
    '/pulls/1704':target,
    ['/issues/comments/'+slot]:{id:slot,user:{...actor},author_association:'OWNER',
      issue_url:'https://api.github.com/repos/'+repo.full_name+'/issues/1122',
      updated_at:before?oldStamp:newStamp,body:'PR'+(before?1702:1704)+': выпуск занят'},
    ['/compare/'+main+'...'+head+'?per_page=1']:{status:'ahead',merge_base_commit:{sha:main},files:structuredClone(files)},
    [filesPath]:files,
  };
  const checks = [[340550044,'regression-tests.yml'],[348353174,'retired-domain-guard.yml']].map(([workflow_id,path],i)=>{
    const run = {id:910001+i, workflow_id, path:'.github/workflows/'+path, run_attempt:1, event:'pull_request',
      head_sha:head, head_branch:branch, status:'completed', conclusion:'success', repository:repo, head_repository:repo,
      pull_requests:[{number:1704,head:target.head,base:target.base}]};
    state['/actions/runs/'+run.id] = structuredClone(run);
    return run;
  });
  state[census(head)] = {total_count:checks.length,workflow_runs:checks};
  const api = async (method, path) => {
    assert.equal(method,'GET');
    const prefix='/repos/'+repo.full_name;
    assert.ok(path.startsWith(prefix+'/'));
    const key=path.slice(prefix.length);
    assert.ok(Object.hasOwn(state,key), 'Unexpected test read '+key);
    return structuredClone(state[key]);
  };
  api.jobLog = async () => assert.fail('fixture does not include a real public preflight');
  const options = {api,pr:1704,phase,head};
  if (merged) {
    const run = {id:runId,workflow_id:340734782,path:'.github/workflows/deploy.yml',event:'push',run_attempt:1,
      head_sha:source,head_branch:'main',head_commit:{id:source},status:'in_progress',conclusion:null,
      repository:repo,head_repository:repo,actor,triggering_actor:actor};
    state['/git/commits/'+source] = {sha:source,parents:[{sha:main},{sha:head}]};
    state['/commits/'+source+'/pulls?per_page=100'] = [{number:1704,state:'closed',merge_commit_sha:source,head:{sha:head}}];
    state['/actions/runs/'+runId] = run;
    state[census(source)] = {total_count:1,workflow_runs:[structuredClone(run)]};
    Object.assign(options,{source,runId,event:{repository:repo,sender:actor,ref:'refs/heads/main',before:main,
      after:source,head_commit:{id:source},forced:false}});
  }
  return {options,profile,state};
}

for (const phase of ['before_handoff','after_handoff','installation']) {
  test('fixture admits '+phase+' only at its bound publisher and source',async () => {
    const {options, profile} = simulate(phase);
    const result = await proveCheckedSuccessor(options,profile);
    const before = phase === 'before_handoff';
    assert.deepEqual(result,{phase,head_sha:head,source_sha:phase==='installation'?source:main,
      binding:{pr:before?1702:1704,...(before?oldBinding:newBinding)},source_check_runs:[910001,910002],fixture_evidence:true});
  });
}

for (const [name,phase,modify,expected] of [
  ['foreign publisher','after_handoff',({state})=>{state['/issues/comments/'+slot].user.id++;},/foreign_record/],
  ['changed source check','after_handoff',({state})=>{state['/actions/runs/910001'].head_sha=source;},/source check not successful/],
  ['out-of-scope file','before_handoff',({state})=>{state[filesPath][0].filename='hotel-posts-telegram-main19.php';},/file identity changed/],
  ['wrong merge parent','installation',({state})=>{state['/git/commits/'+source].parents.reverse();},/not the direct successor/],
  ['replayed deploy','installation',({state})=>{state['/actions/runs/'+runId].run_attempt=2;},/wrong live deployment/],
  ['duplicate deploy','installation',({state})=>{
    const inv=state[census(source)]; inv.workflow_runs.push({...inv.workflow_runs[0],id:runId+1}); inv.total_count++;
  },/duplicate or replayed/],
  ['unexpected main change during historical checks','installation',({state,profile})=>{
    profile.history=async()=>{state['/git/ref/heads/main'].object.sha=head;return {fixture_evidence:true};};
  },/current main changed/],
  ['missing historical proof','before_handoff',({profile})=>{
    profile.history=async()=>{throw new Error('original historical evidence missing');};
  },/original historical evidence missing/],
]) {
  test('fixture rejects '+name, async () => {
    const entry=simulate(phase);
    modify(entry);
    await assert.rejects(proveCheckedSuccessor(entry.options,entry.profile),expected);
  });
}
