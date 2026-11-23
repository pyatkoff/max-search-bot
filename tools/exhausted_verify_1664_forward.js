'use strict';

const {createHash} = require('node:crypto');

const BASE = 'b3386d558f72e9eb80acefad9e87b1ab91b4426f';
const OLD_HEAD = '02c25df61dce7448889779e0b5f2d02d485b8162';
const INSTALL_PARENT = 'cc3bad7cc7377b598c0cd34e63463d1a887acf09';
const BRANCH = 'review/pr1664-exhausted-verification-20261123';
const RECORD_BLOB = '8c3681cffc3c0f22955101ef5f7250ad2d8fa10b';
const RECORD_DIGEST = '6a395d8717ec141c507ea8a5367c7b93747b424ce55af36038bb628c7d885c09';
const DEPLOY = 45176020801;
const DEPLOY_WORKFLOW = 340734782;
const OWNER = 226193297;
const REPOSITORY_ID = 1342493152;
const COMMENT = 7077907800;
const ACQUIRED = '2026-11-23T12:36:23Z';
const PROOF_ARTIFACT = 14828025431;
const PROOF_ZIP = 'sha256:d962cb37361bfef88e1837666fff812d6eff473ed6abbdcd82ab91e5d99e9e76';
const FAILED_LOGS = new Map([
  [137707302320, '6e0085e7323e3f75887c7f3fa3e6b7e5c1261a9f52b5eff05b2241a0c8262657'],
  [137760498372, '85dc9d10a3e7692b6926974c8f714e929a11b44fff0391a09c96b09cc313b121']
]);
const SCOPE = new Map([
  ['.github/workflows/deploy.yml', 'modified'],
  ['resources/max2-common-v2/release-manifest.json', 'modified'],
  ['tests/run_deployment_release_regression.js', 'modified'],
  ['tests/run_exhausted_verify_1664_forward_regression.js', 'added'],
  ['tools/deployment_release.js', 'modified'],
  ['tools/exhausted_verify_1664_forward.js', 'added'],
  ['tools/release_priority.js', 'modified'],
  ['tools/release_slot_close.js', 'modified']
]);

function requireProof(ok, code) {
  if (!ok) throw new Error('pr1664_forward_' + code);
}

function exactTimeoutLog(raw, job) {
  requireProof(Buffer.isBuffer(raw) || typeof raw === 'string', 'missing_log');
  const log = raw.toString().replace(/^\uFEFF/, '').replaceAll('\r\n', '\n');
  requireProof(FAILED_LOGS.get(job) === createHash('sha256').update(log).digest('hex'), 'changed_log');
  const results = log.split('\n').filter(line =>
    /^\d{4}-\d\d-\d\dT[\d:.]+Z SEARCH_REDIRECT_HTTP_SMOKE_FAILED: method=HEAD status=0 errno=28$/.test(line));
  requireProof(results.length === 1, 'unexpected_http_failure');
}

function sourceFiles(rows, R) {
  requireProof(Array.isArray(rows) && rows.length === SCOPE.size, 'wrong_file_count');
  const files = {};
  for (const row of rows) {
    requireProof(SCOPE.has(row.filename) && SCOPE.get(row.filename) === row.status
      && !Object.hasOwn(files, row.filename) && !Object.hasOwn(row, 'previous_filename') && R.sha(row.sha), 'wrong_file');
    files[row.filename] = row.sha;
  }
  return files;
}

function verifyRepository(value, R) {
  return value?.id === REPOSITORY_ID && value.full_name === R.REPOSITORY;
}
function verifyOwner(value) {
  return value?.id === OWNER && value.login === 'pyatkoff';
}

function checkTarget(pr, ctx, R) {
  requireProof(pr?.number === ctx.pr && verifyOwner(pr.user) && pr.draft === false
    && verifyRepository(pr.head?.repo, R) && verifyRepository(pr.base?.repo, R)
    && pr.head.ref === BRANCH && pr.head.sha === ctx.head && pr.base.ref === 'main', 'changed_successor');
  requireProof(ctx.phase === 'installation'
    ? pr.merged === true && pr.state === 'closed' && pr.merge_commit_sha === ctx.source
    : pr.merged === false && pr.state === 'open' && pr.base.sha === BASE, 'wrong_successor_state');
}

async function sourceChecks(get, ctx, R) {
  const runs = R.boundedList(await get('/actions/runs?head_sha=' + ctx.head + '&per_page=100'),
    'workflow_runs', 'incomplete_1664_successor_runs');
  const required = [[340550044, 'regression-tests.yml'], [348353174, 'retired-domain-guard.yml']];
  return Promise.all(required.map(async ([id, file]) => {
    const path = R.PREFIX + file;
    const candidates = runs.filter(run => run.workflow_id === id || run.path === path);
    requireProof(candidates.length === 1 && R.positive(candidates[0].id), 'source_checks_missing');
    const listed = candidates[0];
    for (const run of [listed, await get('/actions/runs/' + listed.id)]) {
      R.runIdentity(run, {id:listed.id, attempt:1, head:ctx.head, workflow:path, event:'pull_request', branch:BRANCH});
      requireProof(run.workflow_id === id && verifyRepository(run.repository, R)
        && verifyRepository(run.head_repository, R) && Array.isArray(run.pull_requests) && run.pull_requests.length <= 1,
        'source_check_identity_changed');
      if (run.pull_requests.length === 0) {
        requireProof(ctx.phase === 'installation', 'source_check_pull_missing');
      } else {
        const [pull] = run.pull_requests;
        requireProof(pull.number === ctx.pr && pull.head?.sha === ctx.head && pull.head.ref === BRANCH
          && pull.base?.ref === 'main' && pull.head.repo?.id === REPOSITORY_ID && pull.base.repo?.id === REPOSITORY_ID,
          'source_check_pull_changed');
      }
    }
    return listed.id;
  }));
}

async function original(api, get, R, C) {
  const [run, one, two, jobs, merge, audit, location] = await Promise.all([
    get('/actions/runs/' + DEPLOY), get('/actions/runs/' + DEPLOY + '/attempts/1'),
    get('/actions/runs/' + DEPLOY + '/attempts/2'), get('/actions/runs/' + DEPLOY + '/jobs?filter=all&per_page=100'),
    get('/git/commits/' + BASE), get('/actions/artifacts/' + PROOF_ARTIFACT), R.readRecord(api)
  ]);
  for (const [native, attempt] of [[run,2],[one,1],[two,2]]) {
    R.runIdentity(native, {id:DEPLOY, attempt, head:BASE, workflow:R.DEPLOY, event:'push', conclusion:'failure'});
    requireProof(native.workflow_id === DEPLOY_WORKFLOW && verifyRepository(native.repository, R)
      && verifyRepository(native.head_repository,R) && verifyOwner(native.actor) && verifyOwner(native.triggering_actor),
      'original_run_identity_changed');
  }
  requireProof(merge.sha === BASE && R.same(merge.parents.map(parent=>parent.sha),[INSTALL_PARENT,OLD_HEAD]),
    'original_merge_changed');
  const stages = C.deploymentHistory(jobs,one,two,{sha:BASE,deploy:DEPLOY},false,true);
  requireProof(stages.firstVerifyId === 137707302320 && stages.latestVerifyId === 137760498372, 'original_jobs_changed');
  const {record} = location;
  const {release} = R.validateRelease(record,false);
  requireProof(location.blob === RECORD_BLOB && location.record_digest === RECORD_DIGEST
    && release.kind === 'installed' && release.phase === 'pending_closure' && release.application_sha === BASE
    && release.repository_sha === BASE && R.same(release.release,{run_id:String(DEPLOY),run_attempt:2,event:'push'})
    && record.install_attempt === 1 && record.smoke_attempt === 2 && record.diagnostics_attempt === 2
    && R.INSTALL_STAGES.every(stage => record[stage] === (['smoke','diagnostics'].includes(stage)?'failure':'success')),
    'negative_record_changed');
  const latest = jobs.jobs.find(job=>job.id === stages.latestVerifyId);
  requireProof(latest.steps.filter(step=>step.name === R.PENDING_BINDING_STEP + RECORD_DIGEST
    && step.status === 'completed' && step.conclusion === 'success').length === 1, 'negative_publication_missing');
  requireProof(audit?.digest === PROOF_ZIP, 'negative_artifact_changed');
  const proof = await R.artifactJson(api,audit,R.PUBLICATION_FILE,two,'deployment-release-pending-' + DEPLOY + '-2');
  const published = await R.readRecord(api,proof.commit);
  requireProof(published.blob === location.blob && published.record_digest === location.record_digest
    && R.same(proof,R.publicationProof(record,published,DEPLOY,2)), 'negative_artifact_mismatch');
  await R.requireAncestry(api,published.commit,location.commit);
  await Promise.all([...FAILED_LOGS.keys()].map(async id=>exactTimeoutLog(await api.jobLog(id),id)));
  return {blob:location.blob,record_digest:location.record_digest,commit:location.commit};
}

async function liveAnchors(get, ctx, R, C) {
  const [main, oldPull, newPull, current, commits, files] = await Promise.all([
    get('/git/ref/heads/main'), get('/pulls/1664'), get('/pulls/' + ctx.pr), get('/issues/comments/' + COMMENT),
    get('/compare/' + BASE + '...' + ctx.head + '?per_page=1'), get('/pulls/' + ctx.pr + '/files?per_page=100&page=1')
  ]);
  requireProof(main?.ref === 'refs/heads/main' && main.object?.type === 'commit'
    && main.object.sha === (ctx.source || BASE), 'main_changed');
  requireProof(oldPull.number === 1664 && oldPull.head.sha === OLD_HEAD && verifyOwner(oldPull.user)
    && verifyRepository(oldPull.head.repo,R) && verifyRepository(oldPull.base.repo,R), 'original_pull_changed');
  const old = C.binding(oldPull,BASE);
  requireProof(old?.comment_id === COMMENT && old.owner_id === OWNER && old.updated_at === ACQUIRED, 'original_binding_changed');
  checkTarget(newPull,ctx,R);
  requireProof(commits?.status === 'ahead' && commits.merge_base_commit?.sha === BASE
    && newPull.changed_files === SCOPE.size && R.same(sourceFiles(commits.files,R),sourceFiles(files,R)), 'candidate_scope_changed');
  const bound = ctx.phase === 'before_handoff' ? old : C.binding(ctx.phase === 'installation' ? newPull :
    {...newPull, state:'closed', merged:true, merge_commit_sha:BASE}, ctx.source || BASE);
  requireProof(bound?.comment_id === COMMENT && bound.owner_id === OWNER &&
    (ctx.phase === 'before_handoff' || Date.parse(bound.updated_at) > Date.parse(ACQUIRED))
    && C.recordState(current,bound) === 'active', 'changed_publisher');
  return {binding:C.bindingSnapshot(bound),files:sourceFiles(files,R)};
}

async function nativeInstallation(get, ctx, R) {
  const [commit, pulls, run, runs] = await Promise.all([
    get('/git/commits/' + ctx.source), get('/commits/' + ctx.source + '/pulls?per_page=100'),
    get('/actions/runs/' + ctx.runId),
    get('/actions/workflows/deploy.yml/runs?head_sha=' + ctx.source + '&branch=main&per_page=100')
  ]);
  requireProof(commit?.sha === ctx.source && R.same(commit.parents.map(parent=>parent.sha),[BASE,ctx.head]),
    'not_direct_successor');
  requireProof(Array.isArray(pulls) && pulls.length < 100, 'incomplete_merge_pulls');
  const merged = pulls.filter(pull=>pull.state==='closed' && pull.merge_commit_sha===ctx.source);
  requireProof(merged.length === 1 && merged[0].number===ctx.pr && merged[0].head?.sha===ctx.head, 'wrong_merged_source');
  R.runIdentity(run,{id:ctx.runId,attempt:1,head:ctx.source,workflow:R.DEPLOY,event:'push',completed:false});
  requireProof(run.status === 'in_progress' && run.workflow_id===DEPLOY_WORKFLOW && run.head_commit?.id===ctx.source
    && verifyOwner(run.actor) && verifyOwner(run.triggering_actor) && verifyRepository(run.repository,R)
    && verifyRepository(run.head_repository,R), 'wrong_new_run');
  const listed = R.boundedList(runs,'workflow_runs','incomplete_1664_successor_deploys');
  requireProof(listed.length===1 && listed[0].id===run.id && listed[0].run_attempt===1, 'replayed_new_deploy');
}

async function proveExhaustedVerify1664Forward({api,pr,head,phase,source,runId,event}) {
  const R = require('./deployment_release');
  const C = require('./release_slot_close');
  requireProof(typeof api?.jobLog === 'function' && typeof api?.artifact === 'function'
    && R.positive(pr) && pr !== 1664 && R.sha(head) && head !== BASE
    && ['before_handoff','after_handoff','installation'].includes(phase), 'invalid_context');
  if (phase === 'installation') {
    requireProof(R.sha(source) && R.positive(runId) && verifyRepository(event?.repository,R) && verifyOwner(event.sender)
      && event.ref==='refs/heads/main' && event.before===BASE && event.after===source && event.head_commit?.id===source
      && event.forced===false && Object.keys(event.inputs || {}).length===0, 'invalid_install_event');
  } else requireProof(source === undefined && runId === undefined && event === undefined, 'unexpected_install_event');
  const ctx={pr,head,phase,source,runId};
  const get = path => api('GET',R.API_ROOT+path);
  const before = await liveAnchors(get,ctx,R,C);
  const [negative,checks] = await Promise.all([original(api,get,R,C),sourceChecks(get,ctx,R),
    phase === 'installation' ? nativeInstallation(get,ctx,R):undefined]);
  const [after,last] = await Promise.all([liveAnchors(get,ctx,R,C),R.readRecord(api)]);
  requireProof(R.same(before,after) && negative.blob===last.blob && negative.commit===last.commit &&
    negative.record_digest===last.record_digest, 'forward_anchors_changed');
  return {expectedCheckout:BASE,binding:after.binding,source_checks:checks};
}

module.exports = { BASE, BRANCH, proveExhaustedVerify1664Forward, exactTimeoutLog };
