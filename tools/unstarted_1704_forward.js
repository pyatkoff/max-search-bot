'use strict';

const {isDeepStrictEqual: equal} = require('node:util');
const {complete, verifyRun, census, jobs, nativeLog, proveCheckedSuccessor} = require('./partial_install_1662_forward');
const {DIAGNOSTICS, BASELINE_DIGEST, baselineBytes, historical} = require('./partial_install_1702_evidence');

const BASE = 'c3307bb811aefaa964c78f855b4d5e405fd606fb';
const HEAD = '3a1f4c933653631b929b9a61ff92bcc2d8125d5a';
const CHECKOUT = '32155b9eb2c8606b61554951bfdc988d70bbcb39';
const BRANCH = 'fix/recover-pr1704-head-preflight';
const RUN = 45427028025;
const JOB = 138571849473;
const LOG_DIGEST = '192b48513fc6426c694431ea62a1aaebabe6ed2bdf3775ea5827d39938f00a3a';
const FILES = {
  '.github/workflows/deploy.yml':'modified',
  'resources/max2-common-v2/release-manifest.json':'modified',
  'tests/run_deploy_monitor_lock_regression.py':'modified',
  'tests/run_ai_runtime_log_boundary_regression.php':'modified',
  'tests/run_webhook_runtime_log_boundary_regression.php':'modified',
  'tests/run_deploy_diagnostics_contract_regression.php':'modified',
  'tests/run_deployment_release_regression.js':'modified',
  'tests/run_partial_install_1662_forward_sync.py':'modified',
  'tools/check_public_runtime_log_fence.sh':'added',
  'tools/deployment_release.js':'modified',
  'tools/release_priority.js':'modified',
  'tools/unstarted_1704_forward.js':'added',
};
const CHILDREN = [45430070786,45430071148,45430071370,45430071755,45430071836,45430071990,45430072022,45430072320];
const ORIGINAL_JOBS = new Map([
  ['verify-inventory',[138561699016,'success']], ['verify-syntax',[138561699586,'success']],
  ['verify / diagnostics',[138570342227,'success']], ['verify / application',[138570342341,'success']],
  ['deploy',[JOB,'failure']], ['verify',[138572542851,'skipped']],
]);

function must(condition, reason) {
  if (!condition) throw new Error('unstarted-install-1704: ' + reason);
}

function checkOriginalSync(lines) {
  const timeout = 'curl: (28) Operation timed out after 15000 milliseconds with 0 bytes received';
  const clean = lines.map(line => line.replace(/\x1b\[[0-9;]*[A-Za-z]/g,''));
  const index = clean.indexOf(timeout);
  must(index > 0 && clean.lastIndexOf(timeout) === index && clean[index-1] === '##[endgroup]' &&
    clean[index+1] === '##[error]Process completed with exit code 28.' &&
    clean[index+2] === '##[group]Run test "$PROVENANCE_OUTCOME" = success' &&
    clean.slice(index+2).includes('  SYNC_OUTCOME: failure'), 'original preflight refusal changed');
  must(!clean.some(line => /^production_sha=|^monitor_journal_lock=/.test(line)), 'original sync write observed');
}

async function proveOriginal(api) {
  const [run, commit, all, rows, artifacts, rawLog] = await Promise.all([
    api.get('/actions/runs/'+RUN), api.get('/git/commits/'+BASE), census(api,BASE), jobs(api,RUN,BASE),
    api.get('/actions/runs/'+RUN+'/artifacts?per_page=100'), api.jobLog(JOB),
  ]);
  verifyRun(run,RUN,'deploy.yml',340734782,BASE,'main','push','failure');
  must(run.head_commit?.id === BASE && commit.sha === BASE &&
    equal(commit.parents.map(parent => parent.sha),[CHECKOUT,HEAD]), 'original merge changed');
  must(rows.length === ORIGINAL_JOBS.size &&
    rows.every(row => equal([row.id,row.conclusion],ORIGINAL_JOBS.get(row.name))) &&
    complete(artifacts,'artifacts').length === 0 &&
    equal(all.filter(row => row.path === '.github/workflows/deploy.yml').map(row => row.id),[RUN]),
  'original job census or publication changed');
  const installer = rows.find(row => row.id === JOB);
  const skipped = ['Apply database migrations and fence the old hotel contour','Reconcile canonical MAX webhook ownership',
    'Ensure Metrika offline uploader cron','Apply profile and retain exact diagnostic snapshot'];
  const hasStep = (name,outcome) => installer.steps.filter(step => step.name === name &&
    step.status === 'completed' && step.conclusion === outcome).length === 1;
  must(hasStep('Sync canonical production checkout','success') && skipped.every(name=>hasStep(name,'skipped')) &&
    hasStep('Fail failed installation','failure') && equal(rows.find(row=>row.name==='verify').steps,[]),
  'original installation stages changed');
  checkOriginalSync(nativeLog(rawLog,LOG_DIGEST));
  const wave = all.filter(row => row.name === 'deploy-proof:v1:'+RUN+':1');
  must(equal(wave.map(row=>row.id).sort((a,b)=>a-b),CHILDREN) &&
    wave.every(row => row.event === 'workflow_run' && row.run_attempt === 1 &&
      row.status === 'completed' && row.conclusion === 'skipped'), 'original post-deploy workflow changed');
  await Promise.all(CHILDREN.map(async id=>{
    const children = await jobs(api,id,BASE);
    must(children.length > 0 && children.every(row=>row.conclusion === 'skipped' && equal(row.steps,[])),
      'original post-deploy job executed');
  }));
}

function proveUnstarted1704Forward(options) {
  const spec = {
    previousPr:1704, main:BASE, head:HEAD,
    binding:{comment_id:6818332618,head_sha:HEAD,owner_id:226193297,updated_at:'2026-11-24T15:33:05Z'},
    pr:options.pr, branch:BRANCH, files:FILES, manifestBlob:'f946373dd1c0c54973eb8e8fee73d6245b49b306',
    diagnostics:DIAGNOSTICS, baselineBytes,
    history:async api=>{
      await Promise.all([historical(api),proveOriginal(api)]);
      return {previous_pr:1704,previous_sha:BASE,previous_run_id:String(RUN),
        previous_installation:'unstarted_sync',previous_install_job_id:JOB,
        previous_accepted_record_sha256:BASELINE_DIGEST,expected_checkout_sha:CHECKOUT};
    },
  };
  return proveCheckedSuccessor(options,spec);
}
module.exports = {BASE,BRANCH,CHECKOUT,JOB,proveUnstarted1704Forward,checkOriginalSync};
