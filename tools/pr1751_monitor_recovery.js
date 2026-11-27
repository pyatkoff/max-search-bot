'use strict';

const R = require('./deployment_release');
const C = require('./release_slot_close');

const branch = 'ops/read-pr1751-notification-20261127';
const originalRecoveryBranch = 'ops/recover-pr1751-monitor-20261127';
const originalRecoverySource = 'f04aa3c98ce7329eeac7f33e3a76e79852e5940e';
const originalRecoveryRun = '46062743465';
const monitor = R.PREFIX + 'modeled-conversion-monitor.yml';
const jobName = 'read-pr1751-notification';
const installed = '263cc9a7855bd11f3597572d08c7f463faebdbd6';
const diagnostics = '0faa77d91e8508ccacaa3a5d0e6984d917096bf3';
const binding = {
  pr: 1751,
  head_sha: 'd97c556f1ad5cd3a5cb3c1ae44169e9ddc153871',
  comment_id: 6818332618,
  owner_id: 226193297,
  updated_at: '2026-11-27T00:26:35Z',
};
const deploy = '45917012313';
const monitorRun = '45918089407';
const closerRun = '45918089456';

const valid = R.requireValue;
const get = (api, path) => api('GET', R.API_ROOT + path);
const jobs = (api, id) => get(api, '/actions/runs/' + id + '/attempts/1/jobs?per_page=100');

async function failedJob(api, id, workflow, name, jobId) {
  const run = await get(api, '/actions/runs/' + id);
  R.runIdentity(run, {id, attempt: 1, head: installed, workflow, event: 'workflow_run', conclusion: 'failure'});
  valid(run.display_title === 'deploy-proof:v1:' + deploy + ':1', 'original_gate_origin_changed');
  const rows = R.boundedList(await jobs(api, id), 'jobs', 'incomplete_original_gate_jobs');
  const named = rows.filter(job => job.name === name);
  valid(named.length === 1 && named[0].id === jobId && String(named[0].run_id) === id
    && named[0].run_attempt === 1 && named[0].head_sha === installed
    && named[0].status === 'completed' && named[0].conclusion === 'failure', 'original_gate_job_changed');
  return named[0];
}

async function requireHeldRelease(api) {
  const [main, current, pr, publisher] = await Promise.all([
    get(api, '/git/ref/heads/main'), R.readRecord(api),
    get(api, '/pulls/' + binding.pr), get(api, '/issues/comments/' + binding.comment_id),
  ]);
  valid(main?.object?.sha === installed, 'main_changed');
  const b = C.binding(pr, installed);
  valid(b && R.same(C.bindingSnapshot(b), binding) && C.recordState(publisher, b) === 'active', 'publisher_changed');
  return validateOriginal(current);
}

function validateOriginal(current) {
  const {release, installation} = R.validateRelease(current.record);
  valid(current.commit === diagnostics && current.blob === 'f5a443bfaecc1a67cc2fb9550b6391018900dba2'
    && current.record_digest === '43fc4c4c4053cb3b542dd60aed2f3800eafc1c6a4a255dad3fe17c6afdc19c5a'
    && release.kind === 'installed' && release.phase === 'pending_closure' && release.application_sha === installed
    && release.release.run_id === deploy && release.release.run_attempt === 1 && release.release.event === 'push',
  'installation_receipt_changed');
  return {current, installation};
}

async function proveOriginalGate(api, historical = false) {
  const {current, installation} = historical
    ? validateOriginal(await R.readRecord(api, diagnostics)) : await requireHeldRelease(api);
  const [originalMonitor, originalCloser, artifacts] = await Promise.all([
    failedJob(api, monitorRun, monitor, 'monitor', 140242032998),
    failedJob(api, closerRun, C.CLOSER, 'release-slot', 140242034592),
    get(api, '/actions/runs/' + closerRun + '/artifacts?per_page=100'),
    R.authenticatePublication(api, current), R.deploymentProof(api, installation),
    historical ? null : R.requireOps(api, installed),
  ]);
  valid(C.stepResult(originalMonitor, 'Read the installed source before opening notification state') === 'success'
    && C.stepResult(originalMonitor, 'Locate latest retained encrypted monitor state') === 'failure', 'monitor_failure_changed');
  const skippedMonitorSteps = [
    'Download retained ciphertext only', 'Read fixed installed technical health and bootstrap route when required',
    'Prepare one immutable notification intent', 'Persist encrypted intent before any Telegram send',
    'Send only this original retained intent', 'Retain final encrypted state',
    'Record sanitized external monitoring receipt', 'Verify technical monitoring result',
  ];
  valid(skippedMonitorSteps.every(name => C.stepResult(originalMonitor, name) === 'skipped'), 'monitor_work_already_started');
  valid(C.stepResult(originalCloser, 'Prepare the exact release proof before terminal publication') === 'failure'
    && C.stepResult(originalCloser, R.TERMINAL_BINDING_STEP) === 'skipped'
    && C.stepResult(originalCloser, R.TERMINAL_STEP) === 'skipped'
    && R.boundedList(artifacts, 'artifacts', 'incomplete_original_closer_artifacts').length === 0, 'terminal_work_already_started');
  if (!historical) await requireHeldRelease(api);
  return current;
}

async function proveOriginalRecovery(api) {
  const [run, result] = await Promise.all([
    get(api, '/actions/runs/' + originalRecoveryRun), jobs(api, originalRecoveryRun),
  ]);
  R.runIdentity(run, {id: originalRecoveryRun, attempt: 1, head: originalRecoverySource, workflow: monitor,
    event: 'push', branch: originalRecoveryBranch, conclusion: 'failure'});
  const priorJobs = R.boundedList(result, 'jobs', 'incomplete_original_recovery_jobs');
  const health = priorJobs.find(job => job.id === 140720671460);
  const sender = priorJobs.find(job => job.id === 140720673523);
  valid(run.actor?.id === binding.owner_id && run.triggering_actor?.id === binding.owner_id
    && priorJobs.length === 2 && health?.name === 'recover-pr1751-health' && health.run_id === run.id
    && health.run_attempt === 1 && health.head_sha === originalRecoverySource && health.conclusion === 'failure'
    && C.stepResult(health, 'Verify installed monitor health without notification') === 'failure'
    && sender?.name === 'monitor' && sender.run_id === run.id && sender.run_attempt === 1
    && sender.head_sha === originalRecoverySource && sender.conclusion === 'skipped' && sender.steps.length === 0,
  'original_recovery_changed');
}

async function admit(env, event, api) {
  const ref = 'refs/heads/' + branch;
  const source = env.GITHUB_SHA;
  const id = env.GITHUB_RUN_ID;
  valid(env.GITHUB_REPOSITORY === R.REPOSITORY && env.GITHUB_EVENT_NAME === 'push'
    && env.GITHUB_REF === ref && env.GITHUB_WORKFLOW_REF === R.REPOSITORY + '/' + monitor + '@' + ref
    && env.GITHUB_JOB === jobName && env.GITHUB_RUN_ATTEMPT === '1'
    && env.GITHUB_ACTOR === 'pyatkoff' && env.GITHUB_TRIGGERING_ACTOR === 'pyatkoff'
    && R.sha(source) && source !== installed && R.positive(id)
    && event?.repository?.full_name === R.REPOSITORY && event.repository.id === 1342493152
    && event.sender?.id === binding.owner_id && event.sender.login === 'pyatkoff'
    && event.ref === ref && event.before === '0'.repeat(40) && event.after === source
    && event.created === true && event.deleted === false && event.forced === false, 'untrusted_monitor_recovery');

  const [run, commit, sourceRuns, recoveryRef, ownJobs] = await Promise.all([
    get(api, '/actions/runs/' + id), get(api, '/git/commits/' + source),
    get(api, '/actions/runs?head_sha=' + source + '&per_page=100'),
    get(api, '/git/ref/heads/' + branch), jobs(api, id),
  ]);
  R.runIdentity(run, {id, attempt: 1, head: source, workflow: monitor, event: 'push', branch, completed: false});
  valid(run.status === 'in_progress' && run.actor?.id === binding.owner_id && run.triggering_actor?.id === binding.owner_id,
    'monitor_recovery_owner_changed');
  valid(commit?.sha === source && commit.parents?.length === 1 && commit.parents[0].sha === originalRecoverySource
    && recoveryRef?.object?.sha === source, 'monitor_recovery_source_changed');
  const running = R.boundedList(ownJobs, 'jobs', 'incomplete_monitor_recovery_jobs').filter(job => job.name === jobName);
  valid(running.length === 1 && running[0].run_id === run.id && running[0].head_sha === source
    && running[0].run_attempt === 1 && running[0].status === 'in_progress' && running[0].conclusion === null,
  'monitor_recovery_job_not_active');
  const sourceChecks = R.boundedList(sourceRuns, 'workflow_runs', 'incomplete_monitor_recovery_ci');
  const candidates = sourceChecks.filter(row => row.path === monitor && row.event === 'push' && row.head_branch === branch);
  valid(candidates.length === 1 && candidates[0].id === run.id, 'monitor_recovery_already_started');
  for (const [file, name] of [['regression-tests.yml', 'Regression tests'], ['retired-domain-guard.yml', 'Retired domain guard']]) {
    const checks = sourceChecks.filter(row => row.path === R.PREFIX + file && row.event === 'pull_request');
    valid(checks.length === 1 && checks[0].name === name && checks[0].head_sha === source
      && checks[0].head_repository?.full_name === R.REPOSITORY && checks[0].run_attempt === 1
      && checks[0].status === 'completed' && checks[0].conclusion === 'success', 'monitor_recovery_ci_unconfirmed');
  }
  await proveOriginalRecovery(api);
  await proveOriginalGate(api);
  return {application_sha: installed};
}

if (require.main === module) {
  Promise.resolve().then(() => admit(process.env, R.readJson(process.env.GITHUB_EVENT_PATH), R.makeReadApi(process.env.GITHUB_TOKEN)))
    .then(R.outputs).catch(error => {
      process.stderr.write('pr1751_monitor_recovery=refused reason=' + (error.code || 'internal_failure') + '\n');
      process.exitCode = 1;
    });
}

module.exports = {admit, proveOriginalGate, requireHeldRelease};
