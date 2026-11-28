'use strict';

const R = require('./deployment_release');
const C = require('./release_slot_close');
const {verifyUnboundPredecessor} = require('./release_priority');

const profile = Object.freeze({
  pr: 1758,
  successor: 1763,
  source: 'c05e0b84f18caa7610e2d50115f9774acd6a6889',
  head: 'b00c63c9945c3c79e366e074550a1b08da41e832',
  pullUpdatedAt: '2026-11-28T03:53:04Z',
  blob: '8a7e4adebeb7650757d6ee7bd26ae4973a8ad3b2',
  installationDigest: 'b1a892ed2dec3698c872ea84e3c2058b2c980954c82001574cee66bb923d1715',
  deploy: 46104950782,
  closer: 46105952679,
  closerJob: 140858609034,
  owner: 226193297,
  preparationBranch: 'ops/prepare-pr1758-terminal-20261128',
  branch: 'ops/recover-pr1758-terminal-20261128',
});
const ref = 'refs/heads/' + profile.branch;
const sourcePaths = [
  '.github/workflows/modeled-conversion-value-export.yml',
  'docs/RELEASE_PRIORITY.md',
  'tests/check_ci_workflow_scopes.py',
  'tests/release_priority_admission_regression.js',
  'tools/release_priority.js',
  'tools/release_slot_1758_receipt.js',
];
const receiptProtocol = 'anytour-unbound-release-terminal-v1';
const preparationStep = 'Prepare continuation of the original pre-write read refusal';
const ok = R.requireValue;
const reader = api => path => api('GET', R.API_ROOT + path);
const runPath = runId => '/actions/runs/' + runId;

function authorizeEvent(env, event) {
  ok(env.GITHUB_REPOSITORY === R.REPOSITORY && env.GITHUB_EVENT_NAME === 'push'
    && env.GITHUB_REF === ref && env.GITHUB_JOB === 'release-slot-recovery'
    && env.GITHUB_WORKFLOW_REF === R.REPOSITORY + '/' + C.CLOSER + '@' + ref
    && env.GITHUB_RUN_ATTEMPT === '1' && env.GITHUB_ACTOR === 'pyatkoff'
    && env.GITHUB_TRIGGERING_ACTOR === 'pyatkoff' && R.sha(env.GITHUB_SHA)
    && /^(?:[1-9][0-9]*)$/.test(env.GITHUB_RUN_ID || '')
    && event?.repository?.full_name === R.REPOSITORY && event.repository.id === 1342493152
    && event.sender?.login === 'pyatkoff' && event.sender.id === profile.owner
    && event.ref === ref && event.before === '0'.repeat(40) && event.after === env.GITHUB_SHA
    && event.created === true && event.deleted === false && event.forced === false,
  'unbound_receipt_event_invalid');
}

async function verifySource(get, source) {
  const [branch, changes, raw] = await Promise.all([
    get('/git/ref/heads/' + profile.branch),
    get('/compare/' + profile.source + '...' + source + '?per_page=100'),
    get('/actions/runs?head_sha=' + source + '&per_page=100'),
  ]);
  ok(branch?.object?.sha === source && changes.status === 'ahead' && changes.ahead_by === 1
    && changes.behind_by === 0 && changes.merge_base_commit?.sha === profile.source
    && Array.isArray(changes.files) && R.same(changes.files.map(f => f.filename).sort(), sourcePaths)
    && changes.files.every(f => !f.previous_filename && f.status ===
      (f.filename === 'tools/release_slot_1758_receipt.js' ? 'added' : 'modified')),
  'unbound_receipt_source_changed');
  const runs = R.boundedList(raw, 'workflow_runs', 'unbound_receipt_ci_incomplete');
  for (const file of ['regression-tests.yml', 'retired-domain-guard.yml']) {
    const checks = runs.filter(run => run.path === R.PREFIX + file && run.event === 'pull_request');
    ok(checks.length === 1, 'unbound_receipt_ci_unconfirmed');
    R.runIdentity(checks[0], {id:checks[0].id, attempt:1, head:source,
      workflow:R.PREFIX + file, event:'pull_request', branch:profile.preparationBranch});
  }
  return runs;
}

async function nativeRun(get, {closed, runId = null}) {
  if (closed) {
    const history = await get('/actions/runs?branch=' + profile.branch + '&event=push&per_page=100');
    const runs = R.boundedList(history, 'workflow_runs', 'unbound_receipt_runs_incomplete');
    ok(runs.length === 1 && runs[0].path === C.CLOSER, 'unbound_receipt_run_ambiguous');
    runId = runs[0].id;
  }
  const run = await get(runPath(runId));
  R.runIdentity(run, {id:runId, attempt:1, head:run.head_sha, workflow:C.CLOSER,
    event:'push', branch:profile.branch, completed:closed});
  ok(R.sha(run.head_sha) && run.head_commit?.id === run.head_sha && run.actor?.id === profile.owner
    && run.triggering_actor?.id === profile.owner
    && (closed || ['queued', 'in_progress'].includes(run.status)), 'unbound_receipt_run_invalid');
  const runs = await verifySource(get, run.head_sha);
  const recoveries = runs.filter(r => r.path === C.CLOSER && r.event === 'push' && r.head_branch === profile.branch);
  ok((!closed && recoveries.length === 0) || (recoveries.length === 1 && recoveries[0].id === run.id),
    'unbound_receipt_run_ambiguous');
  const jobs = R.boundedList(await get(runPath(run.id) + '/attempts/1/jobs?per_page=100'),
    'jobs', 'unbound_receipt_jobs_incomplete');
  ok(jobs.length === 3 && jobs.every(j => j.run_id === run.id && j.head_sha === run.head_sha
    && j.run_attempt === 1 && Array.isArray(j.steps)), 'unbound_receipt_jobs_invalid');
  for (const name of ['configure', 'release-slot']) {
    const skipped = jobs.filter(j => j.name === name && j.status === 'completed'
      && j.conclusion === 'skipped' && j.steps.length === 0);
    ok(skipped.length === 1, 'unbound_receipt_unexpected_job');
  }
  const job = jobs.find(j => j.name === 'release-slot-recovery');
  ok(job && R.positive(job.runner_id) && (closed
    ? job.status === 'completed' && job.conclusion === 'success'
    : job.status === 'in_progress' && job.conclusion === null), 'unbound_receipt_job_invalid');
  return {run, job};
}

async function originalProof(api, location, installation) {
  const r = R.validateRelease(location.record).release;
  ok(location.record_digest === profile.installationDigest && r.phase === 'pending_closure'
    && r.repository_sha === profile.source && r.application_sha === profile.source
    && r.release.event === 'push' && r.release.run_id === String(profile.deploy),
  'unbound_receipt_installation_changed');
  R.runIdentity(installation, {id:profile.deploy, attempt:1, head:profile.source,
    workflow:R.DEPLOY, event:'push'});
  return verifyUnboundPredecessor(api, location, installation, profile);
}

function receipt(run, gates) {
  return {schema:1, protocol:receiptProtocol, pull_request:profile.pr, successor:profile.successor,
    repository_sha:profile.source, installation_run_id:profile.deploy,
    installation_digest:profile.installationDigest, original_closer:profile.closer,
    original_closure:'skipped_unbound', recovery_run_id:run.id,
    recovery_source_sha:run.head_sha, gates};
}

async function verifyUpload(get, native, proof, closed) {
  const {run, job} = native;
  const names = [preparationStep, R.TERMINAL_BINDING_STEP + R.digest(proof)];
  if (closed) names.push(R.TERMINAL_STEP);
  let previous = 0;
  for (const name of names) {
    const matches = job.steps.filter(s => s.name === name && s.status === 'completed'
      && s.conclusion === 'success' && Number.isSafeInteger(s.number));
    ok(matches.length === 1 && matches[0].number > previous, 'unbound_receipt_upload_unconfirmed');
    previous = matches[0].number;
  }
  const artifacts = R.boundedList(await get(runPath(run.id) + '/artifacts?per_page=100'),
    'artifacts', 'unbound_receipt_artifacts_incomplete');
  ok(artifacts.length === 1 && artifacts[0].name === R.publicationName('pending_terminal', run.id, 1)
    && /^sha256:[0-9a-f]{64}$/.test(artifacts[0].digest) && artifacts[0].size_in_bytes > 0
    && artifacts[0].workflow_run?.id === run.id && artifacts[0].workflow_run.head_sha === run.head_sha
    && artifacts[0].workflow_run.head_branch === profile.branch, 'unbound_receipt_upload_unconfirmed');
}

async function verifyPublished(api, location, installation) {
  const get = reader(api);
  const gates = await originalProof(api, location, installation);
  const native = await nativeRun(get, {closed:true});
  const proof = receipt(native.run, gates);
  await verifyUpload(get, native, proof, true);
  const fresh = await R.readRecord(api);
  ok(fresh.blob === location.blob && fresh.record_digest === location.record_digest
    && (await get('/git/ref/heads/main'))?.object?.sha === profile.source,
  'unbound_receipt_base_changed');
  return proof;
}

async function publish({env, event, api, prepare, proofFile}) {
  authorizeEvent(env, event);
  const get = reader(api);
  const native = await nativeRun(get, {closed:false, runId:env.GITHUB_RUN_ID});
  ok(native.run.head_sha === env.GITHUB_SHA, 'unbound_receipt_source_changed');
  const location = await R.readRecord(api);
  const gates = await originalProof(api, location, await get(runPath(profile.deploy)));
  const proof = receipt(native.run, gates);
  if (prepare) {
    ok(typeof proofFile === 'string' && proofFile.length > 0, 'unbound_receipt_path_missing');
    R.saveJson(proofFile, proof);
    return {publication_artifact:R.publicationName('pending_terminal', native.run.id, 1),
      record_digest:R.digest(proof)};
  }
  ok(env.PREPARED_RECORD_DIGEST === R.digest(proof), 'unbound_receipt_digest_changed');
  await verifyUpload(get, native, proof, false);
  return {release_slot:'unbound_receipt_verified'};
}

module.exports = {profile, authorizeEvent, verifyPublished, publish};

if (require.main === module) {
  Promise.resolve().then(() => {
    const args = R.argumentsOf(process.argv.slice(2), ['--prepare', '--finish']);
    const prepare = args.command === '--prepare';
    ok(R.keys(args, prepare ? ['command', 'proof'] : ['command']), 'invalid_arguments');
    return publish({env:process.env, event:R.readJson(process.env.GITHUB_EVENT_PATH),
      api:R.makeReadApi(process.env.GITHUB_TOKEN), prepare, proofFile:args.proof});
  }).then(R.outputs).catch(error => {
    const reason = error.code || (error.isReleasePriorityRefusal ? error.message : 'internal_failure');
    process.stderr.write('pr1758_receipt=refused reason=' + reason + '\n');
    process.exitCode = 1;
  });
}
