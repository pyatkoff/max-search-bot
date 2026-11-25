'use strict';

// One metadata-only continuation. The installed application receipt stays
// readable by the deployed resolver while the old publisher is reconciled.
const R = require('./deployment_release');
const C = require('./release_slot_close');

const P = Object.freeze({
  branch: 'ops/recover-pr1701-terminal-20261125',
  sha: '7b96c69c1f6974fb6ff09f52551a4581ffcdf171',
  previous: '514d18ab127cc551b8e829c7692ad275eeb3ef28',
  previousHead: '51f6ec5105ee0d43c30c6bd452e65622c2b9ce49',
  head: 'b79b2d78c244bf98d8ec7b93383439ae0e3da544',
  comment: 6928374515,
  owner: 226193297,
  updated: '2026-11-24T22:15:47Z',
  install: 45501864193,
  oldInstall: 45499396729,
  oldDeployJob: 138826764785,
  oldVerifyJob: 138827051967,
  closer: 45504353946,
  closeJob: 138835564512,
  diagnostic: 45504352857,
  diagnosticJob: 138835556816,
  diagnosticRetry: 138849924897,
  receiptCommit: '0af78b8d2952fc95dd0337113ceca8a0cdc9e5ac',
  receiptBlob: 'f2162b1030ccfc28129744a8bb90177c12290b1f',
  receiptDigest: 'ba4ff77fdb4987e66b1e58249c2399cf0e1024b6918a017df1ff4384d23c27d7',
});
const TERMINAL = 'PR1701: установлено в составе PR1706, выпуск свободен';
const REF = 'refs/heads/' + P.branch;
const PREPARE = 'Prepare continuation of the original pre-write read refusal';
const CLOSE_PREPARE = 'Prepare the exact release proof before terminal publication';
const oldChildren = new Map([
  [45501912974, ['telegram-production-health.yml', ['inspect']]],
  [45501912960, ['live-session-diagnostics.yml', ['publish']]],
  [45501912917, ['publish-conversation-diagnostics.yml', ['publish']]],
  [45501912959, ['max-production-health.yml', ['inspect']]],
  [45501913297, ['modeled-conversion-monitor.yml', ['monitor']]],
  [45501913128, ['max-tls-preflight.yml', ['strict-tls-preflight']]],
  [45501913099, ['modeled-conversion-value-export.yml', ['configure', 'release-slot', 'release-slot-recovery']]],
  [45501912953, ['tour-selection-host.yml', ['host', 'independent-editor']]],
  [45501926171, ['modeled-pipeline-monitor-activation.yml', ['activate']]],
]);
const ok = R.requireValue;
const read = api => path => api('GET', R.API_ROOT + path);
const jobPath = (id, attempt = 1) => '/actions/runs/' + id + '/attempts/' + attempt + '/jobs?per_page=100';
const runPath = id => '/actions/runs/' + id;
const artefactPath = id => runPath(id) + '/artifacts?per_page=100';

function matching(rows, predicate, reason) {
  const found = rows.filter(predicate);
  ok(found.length === 1, reason);
  return found[0];
}
function step(job, name, result, reason) {
  return matching(job.steps, s => s.name === name && s.status === 'completed' && s.conclusion === result, reason);
}
function jobs(raw, run, source, attempt) {
  const rows = R.boundedList(raw, 'jobs', 'recovery_jobs_incomplete');
  ok(rows.every(j => j.run_id === run && j.head_sha === source && j.run_attempt === attempt
    && Array.isArray(j.steps)), 'recovery_jobs_changed');
  return rows;
}
function skipped(job) {
  return job.status === 'completed' && job.conclusion === 'skipped' && job.steps.length === 0;
}
function diagnosticRetry(job) {
  const code = 'diagnostic_retry_executed';
  const refused = step(job, R.RESOLVER_STEP, 'failure', code);
  const protectedSteps = ['Prepare evidence encryption', 'Prepare SSH private key',
    'Verify deployed SHA and capture production', 'Publish production snapshot',
    'Upload encrypted dialogue evidence', 'Upload Metrika delivery diagnostic'];
  ok(job.id === P.diagnosticRetry && job.status === 'completed' && job.conclusion === 'failure'
    && protectedSteps.every(name => step(job, name, 'skipped', code).number > refused.number)
    && job.steps.every(s => s === refused || ['success', 'skipped'].includes(s.conclusion)), code);
}
async function exactLog(api, job, suffix, code) {
  ok(typeof api.jobLog === 'function', code);
  const bytes = await api.jobLog(job);
  ok(Buffer.isBuffer(bytes) && bytes.length > 0 && bytes.length <= 2 * 1024 * 1024, code);
  const lines = bytes.toString('utf8').split(/\r?\n/).filter(line =>
    /^\d{4}-\d\d-\d\dT[0-9:.]+Z /.test(line) && line.endsWith(suffix));
  ok(lines.length === 1, code);
}
async function bindings(get) {
  const [previous, current, commit] = await Promise.all([
    get('/pulls/1701'), get('/pulls/1706'), get('/git/commits/' + P.sha),
  ]);
  const b = C.binding(previous, P.previous);
  ok(b && R.same(C.bindingSnapshot(b), {pr:1701, head_sha:P.previousHead,
    comment_id:P.comment, owner_id:P.owner, updated_at:P.updated}), 'publisher_binding_changed');
  ok(current.number === 1706 && current.base.sha === P.previous && current.head.sha === P.head
    && C.binding(current, P.sha) === null && commit.sha === P.sha
    && R.same(commit.parents.map(x => x.sha), [P.previous, P.head]), 'successor_binding_changed');
  return {...b, released:TERMINAL};
}
function sameReceipt(location) {
  ok(location.blob === P.receiptBlob && location.record_digest === P.receiptDigest, 'installation_receipt_changed');
}

async function provePrevious(api, get) {
  const run = await get(runPath(P.oldInstall));
  R.runIdentity(run, {id:P.oldInstall, attempt:1, head:P.previous, workflow:R.DEPLOY, event:'push', conclusion:'failure'});
  ok(run.head_commit?.id === P.previous && run.actor?.id === P.owner, 'old_deployment_changed');
  const rows = jobs(await get(jobPath(P.oldInstall)), P.oldInstall, P.previous, 1);
  ok(rows.length === C.INSTALL_JOBS.length + 1 && C.INSTALL_JOBS.every(name => rows.some(j => j.name === name))
    && rows.every(j => j.status === 'completed'), 'old_deployment_changed');
  const deploy = matching(rows, j => j.name === 'deploy' && j.id === P.oldDeployJob && j.conclusion === 'failure',
    'old_deployment_changed');
  matching(rows, j => j.name === 'verify' && j.id === P.oldVerifyJob && skipped(j), 'old_verification_executed');
  ok(rows.filter(j => !['deploy','verify'].includes(j.name)).every(j => j.conclusion === 'success'), 'old_checks_changed');
  const denied = ['Resolve original accepted installation and complete release intent', 'Prepare canonical SSH private key',
    'Select installation or prove the unchanged application without mutation', 'Build exact deployment bundle',
    'Sync canonical production checkout', 'Apply database migrations and fence the old hotel contour',
    'Reconcile canonical MAX webhook ownership', 'Ensure Metrika offline uploader cron',
    'Apply profile and retain exact diagnostic snapshot'];
  for (const name of denied) step(deploy, name, 'skipped', 'old_deployment_executed');
  const failure = step(deploy, 'Fail failed installation', 'failure', 'old_deployment_changed');
  ok(deploy.steps.every(s => s === failure || ['success','skipped'].includes(s.conclusion)), 'old_deployment_changed');
  await exactLog(api, P.oldDeployJob, 'ERROR: deployment SHA ' + P.previous + ' is not current authoritative main ' + P.sha,
    'old_main_refusal_changed');
  ok(R.boundedList(await get(artefactPath(P.oldInstall)), 'artifacts', 'old_deployment_artifacts_incomplete').length === 0,
    'old_deployment_already_published');
  return run;
}

async function proveGates(api, get, previous) {
  const scope = {sha:P.sha, deploy:P.install, run:P.closer, configure:'skipped', activation:false, skippedTerminal:true};
  const raw = await get('/actions/runs?head_sha=' + P.sha + '&per_page=100');
  const listed = R.boundedList(raw, 'workflow_runs', 'recovery_gates_incomplete');
  const old = listed.filter(run => oldChildren.has(run.id));
  ok(old.length === oldChildren.size, 'old_wave_changed');
  await Promise.all(old.map(async run => {
    const [file, names] = oldChildren.get(run.id);
    R.runIdentity(run, {id:run.id, attempt:1, head:P.sha, workflow:R.PREFIX+file, event:'workflow_run', conclusion:'skipped'});
    const title = run.id === 45501926171 ? 'monitor-proof:v1:45501913297:1' : 'deploy-proof:v1:'+P.oldInstall+':1';
    ok(run.display_title === title && run.head_commit?.id === P.sha
      && Date.parse(run.created_at) >= Date.parse(previous.updated_at), 'old_wave_changed');
    const rows = jobs(await get(jobPath(run.id)), run.id, P.sha, 1);
    ok(rows.length === names.length && names.every(name => rows.some(j => j.name === name && skipped(j))), 'old_wave_executed');
  }));

  // The later attempt was refused before credentials and made no capture.
  // The original completed attempt remains the only diagnostic proof used.
  const latest = matching(listed, r => r.id === P.diagnostic, 'diagnostic_missing');
  const workflow = R.PREFIX+'publish-conversation-diagnostics.yml';
  R.runIdentity(latest, {id:P.diagnostic, attempt:2, head:P.sha, workflow, event:'workflow_run', conclusion:'failure'});
  const [first, firstRaw, retryRaw] = await Promise.all([
    get(runPath(P.diagnostic)+'/attempts/1'), get(jobPath(P.diagnostic)), get(jobPath(P.diagnostic, 2)),
  ]);
  R.runIdentity(first, {id:P.diagnostic, attempt:1, head:P.sha, workflow, event:'workflow_run'});
  ok(first.display_title === 'deploy-proof:v1:'+P.install+':1' && latest.display_title === first.display_title
    && first.created_at === latest.created_at, 'diagnostic_origin_changed');
  const firstJobs = jobs(firstRaw, P.diagnostic, P.sha, 1);
  const retry = jobs(retryRaw, P.diagnostic, P.sha, 2);
  ok(firstJobs.length === 1 && firstJobs[0].id === P.diagnosticJob && firstJobs[0].status === 'completed'
    && firstJobs[0].conclusion === 'success' && retry.length === 1, 'diagnostic_proof_changed');
  step(firstJobs[0], 'Publish production snapshot', 'success', 'diagnostic_proof_changed');
  diagnosticRetry(retry[0]);
  await exactLog(api, P.diagnosticRetry, 'deployment_release=refused reason=consumer_rerun_forbidden', 'diagnostic_refusal_changed');

  const workflow_runs = listed.filter(r => !oldChildren.has(r.id)).map(r => r.id === P.diagnostic ? first : r);
  const snapshot = await C.selectReleaseRuns({...raw, total_count:workflow_runs.length, workflow_runs}, scope, get);
  ok(!snapshot.waiting && C.gateState(snapshot.runs, scope) === 'ready', 'recovery_gates_changed');
  const activation = snapshot.runs.workflow_runs.find(r => r.path === R.PREFIX+C.ACTIVATION.file && r.event === 'workflow_run');
  const monitor = snapshot.runs.workflow_runs.find(r => r.path === R.PREFIX+'modeled-conversion-monitor.yml' && r.event === 'workflow_run');
  C.verifyActivation(await get(jobPath(activation.id)), activation, scope, monitor.id);

  const tailRaw = await get(jobPath(P.closer));
  const tail = jobs(tailRaw, P.closer, P.sha, 1);
  ok(tail.length === 3 && ['configure','release-slot-recovery'].every(name =>
    tail.some(j => j.name === name && skipped(j))), 'original_closer_changed');
  C.verifyConfigure(tailRaw, scope);
  const closer = matching(tail, j => j.name === 'release-slot' && j.id === P.closeJob && j.conclusion === 'success'
    && j.status === 'completed', 'original_closer_changed');
  const reason = 'original_closer_executed';
  const prepare = step(closer, CLOSE_PREPARE, 'success', reason);
  const upload = step(closer, R.TERMINAL_BINDING_STEP, 'skipped', reason);
  const finish = step(closer, R.TERMINAL_STEP, 'skipped', reason);
  ok(prepare.number < upload.number && upload.number < finish.number, reason);
  ok(R.boundedList(await get(artefactPath(P.closer)), 'artifacts', 'original_closer_artifacts_incomplete').length === 0, reason);
  return C.gateReferences(snapshot.runs);
}

async function verifyOriginal(api, location) {
  const get = read(api);
  sameReceipt(location);
  const original = await R.readRecord(api, P.receiptCommit);
  sameReceipt(original);
  await R.requireAncestry(api, original.commit, location.commit);
  const r = R.validateRelease(original.record).release;
  ok(r.phase === 'pending_closure' && r.kind === 'installed' && r.repository_sha === P.sha
    && r.application_sha === P.sha && r.release.run_id === String(P.install)
    && r.release.run_attempt === 1 && r.release.event === 'push', 'installation_receipt_changed');
  await R.authenticatePublication(api, original);
  await R.requireOps(api, P.sha);
  const b = await bindings(get);
  const previous = await provePrevious(api, get);
  return {b, gates:await proveGates(api, get, previous)};
}

const changedFiles = [
  '.github/workflows/modeled-conversion-value-export.yml',
  'docs/RELEASE_PRIORITY.md',
  'resources/max2-common-v2/release-manifest.json',
  'tests/check_ci_workflow_scopes.py',
  'tests/run_deployment_release_regression.js',
  'tools/deployment_release.js',
  'tools/release_priority.js',
  'tools/release_slot_1701_recovery.js',
  'tools/release_slot_close.js',
];
async function sourceProof(get, source) {
  const relation = await get('/compare/'+P.sha+'...'+source+'?per_page=100');
  ok(R.sha(source) && source !== P.sha && relation.status === 'ahead' && relation.ahead_by === 3
    && relation.behind_by === 0 && relation.merge_base_commit?.sha === P.sha
    && R.same(relation.files.map(f => f.filename).sort(), changedFiles)
    && relation.files.every(f => !f.previous_filename && f.status ===
      (f.filename === 'tools/release_slot_1701_recovery.js' ? 'added' : 'modified')), 'recovery_source_changed');
  const ci = R.boundedList(await get('/actions/runs?head_sha='+source+'&per_page=100'), 'workflow_runs', 'recovery_ci_incomplete');
  for (const [file, name] of [['regression-tests.yml','Regression tests'], ['retired-domain-guard.yml','Retired domain guard']]) {
    const run = matching(ci, r => r.path === R.PREFIX+file && r.event === 'pull_request', 'recovery_ci_unconfirmed');
    ok(run.name === name && run.head_sha === source && run.head_repository?.full_name === R.REPOSITORY
      && run.run_attempt === 1 && run.status === 'completed' && run.conclusion === 'success', 'recovery_ci_unconfirmed');
  }
}
async function nativeRun(get, {closed, runId = null}) {
  const raw = await get('/actions/workflows/modeled-conversion-value-export.yml/runs?branch='+P.branch+'&event=push&per_page=100');
  const rows = R.boundedList(raw, 'workflow_runs', 'recovery_runs_incomplete');
  ok(rows.length === 1 && (runId === null || String(rows[0].id) === runId), 'recovery_run_changed');
  const run = await get(runPath(rows[0].id));
  R.runIdentity(run, {id:rows[0].id, attempt:1, head:rows[0].head_sha, workflow:C.CLOSER,
    event:'push', branch:P.branch, completed:closed});
  ok(run.actor?.id === P.owner && run.triggering_actor?.id === P.owner && run.head_commit?.id === run.head_sha
    && (closed || ['queued','in_progress'].includes(run.status)), 'recovery_run_changed');
  ok((await get('/git/ref/heads/'+P.branch))?.object?.sha === run.head_sha, 'recovery_source_changed');
  await sourceProof(get, run.head_sha);
  const all = jobs(await get(jobPath(run.id)), run.id, run.head_sha, 1);
  ok(all.length === 3 && ['configure','release-slot'].every(name => all.some(j => j.name === name && skipped(j))),
    'recovery_unexpected_job');
  const job = matching(all, j => j.name === 'release-slot-recovery'
    && (closed ? j.status === 'completed' && j.conclusion === 'success'
      : j.status === 'in_progress' && j.conclusion === null)
    && R.positive(j.runner_id), 'recovery_job_changed');
  return {run, job};
}
function receipt(run, {b,gates}) {
  return {protocol:'anytour-release-slot-reconciliation-v1', run_id:String(run.id), run_attempt:1,
    source_sha:run.head_sha, repository_sha:P.sha, installation_run_id:String(P.install),
    installation_record_digest:P.receiptDigest, closer_run_id:String(P.closer), replaced_run_id:String(P.oldInstall),
    binding:C.bindingSnapshot(b), terminal:TERMINAL, gates};
}
async function uploaded(get, run, job, proof, closed) {
  const reason = 'recovery_publication_unconfirmed';
  const preparation = step(job, PREPARE, 'success', reason);
  const artifactStep = step(job, R.TERMINAL_BINDING_STEP + R.digest(proof), 'success', reason);
  ok(preparation.number < artifactStep.number, reason);
  if (closed) ok(artifactStep.number < step(job, R.TERMINAL_STEP, 'success', reason).number, reason);
  const artifacts = R.boundedList(await get(artefactPath(run.id)), 'artifacts', 'recovery_artifacts_incomplete');
  const name = R.publicationName('pending_terminal', run.id, 1);
  ok(artifacts.length === 1 && artifacts[0].name === name
    && /^sha256:[0-9a-f]{64}$/.test(artifacts[0].digest)
    && Number.isSafeInteger(artifacts[0].size_in_bytes) && artifacts[0].size_in_bytes > 0
    && artifacts[0].workflow_run?.id === run.id && artifacts[0].workflow_run.head_sha === run.head_sha
    && artifacts[0].workflow_run.head_branch === P.branch, reason);
}
async function active(api, b) {
  const get = read(api);
  ok((await get('/git/ref/heads/main'))?.object?.sha === P.sha, 'main_changed');
  sameReceipt(await R.readRecord(api));
  ok(R.same(C.bindingSnapshot(await bindings(get)), C.bindingSnapshot(b)), 'publisher_binding_changed');
  ok(C.recordState(await get('/issues/comments/'+P.comment), b) === 'active', 'publisher_not_active');
}
function authorizeEvent(env,event) {
  ok(env.GITHUB_REPOSITORY === R.REPOSITORY && env.GITHUB_EVENT_NAME === 'push' && env.GITHUB_REF === REF
    && env.GITHUB_WORKFLOW_REF === R.REPOSITORY+'/'+C.CLOSER+'@'+REF && env.GITHUB_JOB === 'release-slot-recovery'
    && env.GITHUB_RUN_ATTEMPT === '1' && env.GITHUB_ACTOR === 'pyatkoff' && env.GITHUB_TRIGGERING_ACTOR === 'pyatkoff'
    && R.sha(env.GITHUB_SHA) && /^[1-9][0-9]*$/.test(env.GITHUB_RUN_ID || '')
    && event?.repository?.full_name === R.REPOSITORY && event.repository.id === 1342493152
    && event.sender?.id === P.owner && event.sender.login === 'pyatkoff'
    && event.ref === REF && event.after === env.GITHUB_SHA && event.before === '0'.repeat(40)
    && event.created === true && event.deleted === false && event.forced === false, 'untrusted_recovery_event');
}
async function resume({env,event,api,prepare=false,proofFile=null}) {
  authorizeEvent(env,event);
  const get = read(api), {run,job} = await nativeRun(get,{closed:false,runId:env.GITHUB_RUN_ID});
  ok(run.head_sha === env.GITHUB_SHA, 'recovery_source_changed');
  const original = await verifyOriginal(api, await R.readRecord(api));
  const proof = receipt(run,original);
  await active(api,original.b);
  if (prepare) {
    ok(typeof proofFile === 'string' && proofFile.length > 0, 'recovery_proof_path_missing');
    R.saveJson(proofFile,proof);
    return {publication_artifact:R.publicationName('pending_terminal',run.id,1), record_digest:R.digest(proof)};
  }
  ok(env.PREPARED_RECORD_DIGEST === R.digest(proof), 'recovery_publication_changed');
  await uploaded(get,run,job,proof,false);
  await active(api,original.b);
  try {
    const written = await api('PATCH',R.API_ROOT+'/issues/comments/'+P.comment,{body:TERMINAL});
    ok(written?.id === P.comment && written.body === TERMINAL, 'write_unknown');
    ok(C.recordState(await get('/issues/comments/'+P.comment),original.b) === 'released', 'write_unknown');
  } catch {
    throw new R.Refusal('write_unknown');
  }
  return 'released';
}
async function verifyClosed(api,location) {
  const original = await verifyOriginal(api,location);
  const get = read(api), {run,job} = await nativeRun(get,{closed:true});
  await uploaded(get,run,job,receipt(run,original),true);
  return original.b;
}

module.exports = {P,TERMINAL,authorizeEvent,diagnosticRetry,verifyOriginal,resume,verifyClosed};
