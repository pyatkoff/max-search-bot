'use strict';

const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const {test} = require('node:test');
const R = require('../tools/deployment_release');
const C = require('../tools/release_slot_close');
const {BASE, exactTimeoutLog} = require('../tools/exhausted_verify_1664_forward');

function fixture() {
  const deploy = 45176020801;
  const date = n => '2026-11-23T12:' + n + ':00Z';
  const run = attempt => ({
    id:deploy, path:R.DEPLOY, name:'Deploy production', event:'push', head_branch:'main',
    head_repository:{full_name:R.REPOSITORY}, head_sha:BASE, head_commit:{id:BASE}, run_attempt:attempt,
    created_at:date('11'), run_started_at:date(attempt===1?'11':'14'),
    updated_at:date(attempt===1?'13':'15'), status:'completed', conclusion:'failure'
  });
  const jobs = [];
  const step = (name,conclusion,number) => ({
    name,conclusion,number,status:'completed',started_at:date('11'),completed_at:date('12')
  });
  const installer = ['verify-inventory','verify-syntax','verify / application','verify / diagnostics','deploy'];
  for(const [n,name] of installer.entries()) {
    const original = {id:200+n, name,run_id:deploy,head_sha:BASE,status:'completed',conclusion:'success',
      started_at:date('11'),completed_at:date('12'),runner_id:27,runner_name:'runner',labels:['ubuntu-latest'],
      run_attempt:1,steps:[step('original check','success',1)]};
    jobs.push(original,{...structuredClone(original),id:300+n,run_attempt:2});
  }
  for(const attempt of [1,2]) {
    const steps = [
      ['Verify installed production checks','failure'],
      ['Download exact installed diagnostic snapshot','skipped'],
      ['Publish verification stage status','success'],
      [R.PENDING_BINDING_STEP+'e'.repeat(64),'success'],
      ['Remove acknowledged diagnostic snapshot','skipped']
    ].map(([name,outcome],n)=>step(name,outcome,n+1));
    jobs.push({id:500+attempt,name:'verify',run_id:deploy,head_sha:BASE,status:'completed',conclusion:'failure',
      run_attempt:attempt,steps});
  }
  return {history:{total_count:jobs.length,jobs},original:run(1),retry:run(2),ctx:{sha:BASE,deploy}};
}
function exhausted(x) {
  return C.deploymentHistory(x.history,x.original,x.retry,x.ctx,false,true);
}
test('two recorded verification failures authenticate with the same initial installer',()=>{
  assert.deepEqual(exhausted(fixture()),{firstVerifyId:501,latestVerifyId:502});
});
test('ordinary verification admission still refuses failed and third attempts',()=>{
  const p=fixture();
  assert.throws(()=>C.deploymentHistory(p.history,p.original,p.retry,p.ctx),/invalid_recovery_attempt/);
  p.retry.run_attempt=3;
  assert.throws(()=>exhausted(p),/untrusted_deployment_attempt/);
  p.retry.run_attempt=2;
  assert.throws(()=>C.deploymentHistory(p.history,p.original,p.retry,p.ctx,true,true),/invalid_verification_history_mode/);
});
test('copying metadata cannot hide a second installation',()=>{
  const p=fixture();
  const repeated=p.history.jobs.find(job=>job.run_attempt===2&&job.name==='deploy');
  repeated.started_at='2026-11-23T12:14:00Z';
  assert.throws(()=>exhausted(p),/installation_or_source_replayed/);
});
test('exhausted evidence cannot stand in for a published verification result',()=>{
  const p=fixture();
  p.history.jobs.find(job=>job.id===502).steps[3].conclusion='skipped';
  assert.throws(()=>exhausted(p),/unclassified_exhausted_verification/);
  assert.throws(()=>exactTimeoutLog('fabricated HEAD result',137760498372),/changed_log/);
});
test('successor checks the actual old checkout and redirect before upload',()=>{
  const yaml=readFileSync(join(__dirname,'../.github/workflows/deploy.yml'),'utf8');
  const first=yaml.indexOf('test "$PREVIOUS_MAIN_SHA" = '+BASE);
  const probe=yaml.indexOf('php tools/search_destination_smoke.php --http',first);
  const locked=yaml.indexOf('PR1664 predecessor checkout changed',probe);
  const upload=yaml.indexOf('scp "${ssh_opts[@]}" "$bundle_file"',locked);
  assert(first>0 && probe>first && locked>probe && upload>locked);
  assert(yaml.includes('PR1662 predecessor checkout changed'));
});
