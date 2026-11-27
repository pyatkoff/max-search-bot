'use strict';

const R = require('./deployment_release');
const C = require('./release_slot_close');
const original = require('./pr1751_monitor_recovery');
const branch = 'ops/close-pr1751-heartbeat-20261127';
const kind = 'pr1751_heartbeat_v1';
const app = '263cc9a7855bd11f3597572d08c7f463faebdbd6';
const parent = '65a1996e2bdbd6fbea1ab5cb42ab5e339440148a';
const install = 45917012313, closer = 45918089456, monitor = 45918089407;
const comment = 6818332618, owner = 226193297;
const inspectStep = 'Read exact installed pipeline heartbeat for PR1751';
const ok = R.requireValue;
const get = (api, path) => api('GET', R.API_ROOT + path);
const jobPath = id => '/actions/runs/' + id + '/attempts/1/jobs?per_page=100';
const binding = {pr:1751, head_sha:'d97c556f1ad5cd3a5cb3c1ae44169e9ddc153871',
  comment_id:comment, owner_id:owner, updated_at:'2026-11-27T00:26:35Z'};

function isOriginalMonitor(gate) {
  return gate.path === R.PREFIX + 'modeled-conversion-monitor.yml' && gate.run_id === String(monitor)
    && gate.run_attempt === 1 && gate.head_sha === app && gate.conclusion === 'failure';
}

function validateHeartbeat(value) {
  const p = value?.pending, h = value?.heartbeat;
  ok(R.keys(value, ['application_sha','pending','last_confirmed_at','heartbeat']) && value.application_sha === app
    && value.last_confirmed_at === '2026-11-19T23:20:03Z'
    && R.same(p, {intent_ref:'7cbd05f24e09d0a9', kind:'failure', state:'unknown',
      prepared_at:'2026-11-20T00:10:01Z', matches_last_confirmation:false})
    && R.keys(h, ['cycle_id','checked_at','status','notification']) && /^[0-9a-f]{32}$/.test(h.cycle_id)
    && R.stamp(h.checked_at) && h.status === 'degraded' && h.notification === 'unknown', 'heartbeat_unconfirmed');
}

function validateLineage(r) {
  const x = r.closure?.recovery;
  ok(R.keys(x, ['kind','source_sha','heartbeat']) && x.kind === kind && R.sha(x.source_sha)
    && x.source_sha !== app && r.repository_sha === app && r.application_sha === app && r.kind === 'installed'
    && r.release.run_id === String(install) && r.release.run_attempt === 1 && r.release.event === 'push'
    && r.closure.run_id !== String(closer) && R.same(r.closure.binding, binding)
    && r.closure.gates.filter(isOriginalMonitor).length === 1 && r.baseline_eligible === false, 'heartbeat_lineage_invalid');
  validateHeartbeat(x.heartbeat);
  return x;
}

async function active(api) {
  const [main, pr, publisher] = await Promise.all([get(api, '/git/ref/heads/main'), get(api, '/pulls/1751'),
    get(api, '/issues/comments/' + comment)]);
  const b = C.binding(pr, app);
  ok(main?.object?.sha === app && b && R.same(C.bindingSnapshot(b), binding)
    && C.recordState(publisher, b) === 'active', 'publisher_changed');
  return b;
}

async function sourceProof(api, source) {
  const [commit, ref, runs] = await Promise.all([get(api, '/git/commits/' + source), get(api, '/git/ref/heads/' + branch),
    get(api, '/actions/runs?head_sha=' + source + '&per_page=100')]);
  ok(commit?.sha === source && R.same(commit.parents.map(p => p.sha), [parent])
    && ref?.object?.sha === source, 'heartbeat_source_changed');
  const ci = R.boundedList(runs, 'workflow_runs', 'heartbeat_ci_incomplete');
  for (const file of ['regression-tests.yml','retired-domain-guard.yml']) {
    const checks = ci.filter(run => run.path === R.PREFIX + file && run.event === 'pull_request');
    ok(checks.length === 1, 'heartbeat_ci_unconfirmed');
    R.runIdentity(checks[0], {id:checks[0].id, attempt:1, head:source, workflow:R.PREFIX + file,
      event:'pull_request', branch:'ops/prepare-pr1751-monitor-20261127'});
  }
  return ci;
}

async function native(api, id, source, state) {
  const ci = await sourceProof(api, source);
  const continuation = ci.filter(r => r.path === C.CLOSER && r.event === 'push' && r.head_branch === branch);
  ok(continuation.length === 1 && String(continuation[0].id) === id, 'heartbeat_continuation_ambiguous');
  const run = await get(api, '/actions/runs/' + id);
  R.runIdentity(run, {id, attempt:1, head:source, workflow:C.CLOSER, event:'push', branch, completed:state === 'closed'});
  ok(run.actor?.id === owner && run.triggering_actor?.id === owner, 'heartbeat_owner_changed');
  const rows = R.boundedList(await get(api, jobPath(id)), 'jobs', 'heartbeat_jobs_incomplete');
  ok(rows.length === 4 && rows.every(j => j.run_id === run.id && j.head_sha === source && j.run_attempt === 1)
    && ['configure','release-slot'].every(name => rows.some(j => j.name === name && j.status === 'completed'
      && j.conclusion === 'skipped' && j.steps.length === 0)), 'heartbeat_unexpected_jobs');
  const job = rows.find(j => j.name === 'release-slot-recovery');
  const observation = rows.find(j => j.name === 'pr1751-heartbeat');
  if (state === 'reading') {
    ok(observation?.status === 'in_progress' && observation.conclusion === null, 'heartbeat_job_invalid');
  } else {
    ok(observation?.status === 'completed' && observation.conclusion === 'success' && job
      && (state === 'closed' ? job.status === 'completed' && job.conclusion === 'success'
        : job.status === 'in_progress' && job.conclusion === null), 'heartbeat_job_invalid');
  }
  return observation;
}

async function originalGates(api) {
  const scope = {sha:app, deploy:install, run:closer, configure:'skipped', activation:false,
    terminalRecovery:true, pr1751Heartbeat:true};
  const result = await C.selectReleaseRuns(await get(api, '/actions/runs?head_sha=' + app + '&per_page=100'),
    scope, path => get(api, path));
  ok(!result.waiting && C.gateState(result.runs, scope) === 'ready', 'original_gates_changed');
  const activation = result.runs.workflow_runs.find(r => r.path === R.PREFIX + C.ACTIVATION.file && r.event === 'workflow_run');
  C.verifyActivation(await get(api, jobPath(activation.id)), activation, scope, monitor);
  C.verifyConfigure(await get(api, jobPath(closer)), scope);
  return C.gateReferences(result.runs);
}

function authorized(env, event, job = 'release-slot-recovery') {
  const ref = 'refs/heads/' + branch;
  ok(env.GITHUB_REPOSITORY === R.REPOSITORY && env.GITHUB_EVENT_NAME === 'push' && env.GITHUB_REF === ref
    && env.GITHUB_WORKFLOW_REF === R.REPOSITORY + '/' + C.CLOSER + '@' + ref
    && env.GITHUB_JOB === job && env.GITHUB_RUN_ATTEMPT === '1'
    && env.GITHUB_ACTOR === 'pyatkoff' && env.GITHUB_TRIGGERING_ACTOR === 'pyatkoff'
    && R.sha(env.GITHUB_SHA) && R.positive(env.GITHUB_RUN_ID)
    && event?.repository?.full_name === R.REPOSITORY && event.repository.id === 1342493152
    && event.sender?.id === owner && event.sender.login === 'pyatkoff'
    && event.ref === ref && event.before === '0'.repeat(40) && event.after === env.GITHUB_SHA
    && event.created === true && event.deleted === false && event.forced === false, 'heartbeat_event_invalid');
}

function nativeHeartbeat(job, receipt) {
  validateHeartbeat(receipt);
  ok(C.stepResult(job, inspectStep) === 'success', 'heartbeat_step_unconfirmed');
  const step = job.steps.find(s => s.name === inspectStep);
  const age = Date.parse(step.completed_at) - Date.parse(receipt.heartbeat.checked_at);
  ok(age >= 0 && age <= 900000, 'heartbeat_is_stale');
}

async function authenticate(api, record, {closed}) {
  const r = record.repository_release, x = validateLineage(r);
  const [earlier, gates, job] = await Promise.all([original.proveOriginalGate(api, true), originalGates(api),
    native(api, r.closure.run_id, x.source_sha, closed ? 'closed' : 'closing')]);
  const restored = R.clone(record);
  restored.repository_release.phase = 'pending_closure';
  restored.repository_release.closure = null;
  ok(R.same(restored, earlier.record) && R.same(gates, r.closure.gates), 'heartbeat_origin_changed');
  nativeHeartbeat(job, x.heartbeat);
  return earlier;
}

async function preflight({env,event,api}) {
  authorized(env,event,'pr1751-heartbeat');
  await native(api, env.GITHUB_RUN_ID, env.GITHUB_SHA, 'reading');
  await Promise.all([original.proveOriginalGate(api), originalGates(api)]);
  await active(api);
  return {application_sha:app};
}

async function resume({env,event,api,prepare,proofFile}) {
  authorized(env,event);
  const b = await active(api);
  const current = await R.readRecord(api);
  if (prepare) {
    const job = await native(api, env.GITHUB_RUN_ID, env.GITHUB_SHA, 'closing');
    const heartbeat = R.parseJson(Buffer.from(env.PR1751_HEARTBEAT_JSON || ''), 1024);
    nativeHeartbeat(job, heartbeat);
    const [earlier, gates] = await Promise.all([original.proveOriginalGate(api), originalGates(api)]);
    ok(current.commit === earlier.commit && current.blob === earlier.blob, 'diagnostics_changed');
    const record = R.clone(current.record), r = record.repository_release;
    r.phase = 'pending_terminal';
    r.closure = {run_id:env.GITHUB_RUN_ID, run_attempt:1, job:'release-slot-recovery', terminal_step:R.TERMINAL_STEP,
      binding, expected_terminal:b.released, gates, recovery:{kind, source_sha:env.GITHUB_SHA, heartbeat}};
    R.validateRelease(record);
    await active(api);
    const saved = await C.publishDiagnostics(api, record, current);
    R.saveJson(proofFile, R.publicationProof(record, saved, env.GITHUB_RUN_ID, 1));
    return {publication_artifact:R.publicationName('pending_terminal', env.GITHUB_RUN_ID, 1), record_digest:R.digest(record)};
  }
  const r = R.validateRelease(current.record).release;
  const lineage = validateLineage(r);
  ok(r.closure.run_id === env.GITHUB_RUN_ID && lineage.source_sha === env.GITHUB_SHA
    && current.record_digest === env.PREPARED_RECORD_DIGEST, 'heartbeat_terminal_changed');
  await R.authenticatePublication(api,current);
  const artifact = await R.findArtifact(api,{id:env.GITHUB_RUN_ID},R.publicationName('pending_terminal', env.GITHUB_RUN_ID,1));
  ok(artifact.expired === false && /^sha256:[0-9a-f]{64}$/.test(artifact.digest)
    && String(artifact.workflow_run?.id) === env.GITHUB_RUN_ID && artifact.workflow_run?.head_sha === env.GITHUB_SHA,
  'heartbeat_terminal_artifact_unconfirmed');
  await active(api);
  const last = await R.readRecord(api);
  ok(last.blob === current.blob && last.record_digest === current.record_digest, 'diagnostics_changed');
  const path = '/issues/comments/' + comment;
  try {
    const written = await api('PATCH', R.API_ROOT + path, {body:b.released});
    ok(written?.id === comment && written.body === b.released, 'write_unknown');
    ok(C.recordState(await get(api,path),b) === 'released', 'write_unknown');
  } catch {
    throw new R.Refusal('write_unknown');
  }
  return 'released';
}

if (require.main === module) {
  Promise.resolve().then(() => preflight({env:process.env, event:R.readJson(process.env.GITHUB_EVENT_PATH),
    api:R.makeReadApi(process.env.GITHUB_TOKEN)})).then(R.outputs).catch(error => {
    process.stderr.write('pr1751_heartbeat=refused reason=' + (error.code || 'internal_failure') + '\n');
    process.exitCode = 1;
  });
}

module.exports = {branch,kind,isOriginalMonitor,validateLineage,authenticate,resume,validateHeartbeat};
