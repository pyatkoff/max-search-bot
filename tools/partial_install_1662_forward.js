'use strict';

const {createHash} = require('node:crypto');
const {isDeepStrictEqual: equal} = require('node:util');

const REPO = 'pyatkoff/max-search-bot';
const REPO_ID = 1342493152;
const OWNER = 226193297;
const MAIN = '302ba66b5739e369a4df94471c8cfe9c054dd070';
const HEAD = '3bdb9dbe6a6c706a5f870cd7d6f85aad2cf1b495';
const PRIOR = 'f2894cdf9a14031d53ee8622934364c35dab5730';
const DIAGNOSTICS = 'db9f222955811721ee30fc7d1ffce435a2f48e90';
const BASELINE_BLOB = '472593b8fb3c1418b8b6b43b008278ab7a8125b7';
const BASELINE_DIGEST = '900d3330d6618343891313b64d23f2f95b62f39a0a7c542371670dccc322f41d';
const DEPLOY = 45158784181;
const INSTALL_JOB = 137646126584;
const CHECK = 45164611732;
const CHECK_JOB = 137658227484;
const CHECK_SOURCE = '688addacfc55438751b4738adafebafca896c0e1';
const CHECK_BRANCH = 'audit/pr1662-partial-install-preflight-20261123';
const CHECK_FILE = 'pr1662-partial-install-preflight.yml';
const PREFIX = '.github/workflows/';
const BIND = {comment_id:7077907800, head_sha:HEAD, owner_id:OWNER, updated_at:'2026-11-23T10:42:51Z'};

const installationSteps = [
  ['Set up job','success'],
  ['Require the complete deployment verification','success'],
  ['Checkout exact deployment SHA','success'],
  ['Verify deployment SHA is current main','success'],
  ['Resolve original accepted installation and complete release intent','success'],
  ['Prepare canonical SSH private key','success'],
  ['Select installation or prove the unchanged application without mutation','success'],
  ['Build exact deployment bundle','success'],
  ['Sync canonical production checkout','success'],
  ['Apply database migrations and fence the old hotel contour','skipped'],
  ['Reconcile canonical MAX webhook ownership','skipped'],
  ['Ensure Metrika offline uploader cron','skipped'],
  ['Apply profile and retain exact diagnostic snapshot','skipped'],
  ['Fail failed installation','failure'],
  ['Post Checkout exact deployment SHA','success'],
  ['Complete job','success']
];
const wave = [
  ['modeled-pipeline-monitor-activation.yml',45161354079,406292013,['activate']],
  ['modeled-conversion-value-export.yml',45161339253,404979772,['configure','release-slot','release-slot-recovery']],
  ['max-production-health.yml',45161338990,346592110,['inspect']],
  ['tour-selection-host.yml',45161338874,396440679,['host','independent-editor']],
  ['telegram-production-health.yml',45161339121,345804963,['inspect']],
  ['max-tls-preflight.yml',45161339155,349298737,['strict-tls-preflight']],
  ['publish-conversation-diagnostics.yml',45161338955,341141254,['publish']],
  ['live-session-diagnostics.yml',45161338908,341564607,['publish']],
  ['modeled-conversion-monitor.yml',45161339149,406167317,['monitor']]
];

function must(test, reason) {
  if (!test) throw new Error('partial-install-1662: ' + reason);
}
function hash(name, bytes) {
  return createHash(name).update(bytes).digest('hex');
}
function complete(payload, key) {
  const list = payload?.[key];
  must(Array.isArray(list) && list.length < 100 && list.length === payload.total_count, 'incomplete ' + key);
  must(new Set(list.map(row => row.id)).size === list.length, 'duplicate ' + key);
  return list;
}
function verifyRun(r, id, file, workflow, sha, branch, event, result) {
  const identity = [r?.id,r?.workflow_id,r?.path,r?.head_sha,r?.head_branch,r?.event,r?.run_attempt,r?.status,r?.conclusion];
  must(equal(identity,[id,workflow,PREFIX+file,sha,branch,event,1,'completed',result]), 'wrong native run ' + id);
  for (const k of ['repository','head_repository'])
    must(r[k]?.id === REPO_ID && r[k]?.full_name === REPO, 'untrusted repository');
  for (const k of ['actor','triggering_actor'])
    must(r[k]?.id === OWNER && r[k]?.login === 'pyatkoff', 'untrusted actor');
}
async function census(api, sha) {
  let total;
  const all = [];
  for (let page = 1; page <= 3; page++) {
    const response = await api.get('/actions/runs?head_sha='+sha+'&per_page=100&page='+page);
    const batch = response?.workflow_runs;
    if (page === 1) total=response?.total_count;
    must(Number.isSafeInteger(total) && total >= 0 && total < 300 && response.total_count === total &&
      Array.isArray(batch) && batch.length === Math.min(100,total-all.length), 'incomplete run census');
    all.push(...batch);
    if (all.length === total) {
      must(all.every(r => r.head_sha === sha) && new Set(all.map(r=>r.id)).size === all.length, 'invalid run census');
      return all;
    }
  }
  throw new Error('partial-install-1662: run census overflow');
}
async function jobs(api, run, sha) {
  const rows=complete(await api.get('/actions/runs/'+run+'/attempts/1/jobs?per_page=100'),'jobs');
  must(rows.every(j=>j.run_id===run && j.head_sha===sha && j.run_attempt===1 && j.status==='completed') &&
    new Set(rows.map(j=>j.name)).size===rows.length, 'ambiguous native jobs');
  return rows;
}
function binding(pr) {
  const markers = (pr.body || '').match(/<!--\s*release-slot-close:[\s\S]*?-->/g) || [];
  must(markers.length === 1 && markers[0].startsWith('<!-- release-slot-close:v1 '), 'publisher marker changed');
  return JSON.parse(markers[0].slice('<!-- release-slot-close:v1 '.length,-3).trim());
}
function verifyPull(p, number, merge, head) {
  must(p.number===number && p.state==='closed' && p.merged===true && p.merge_commit_sha===merge
    && p.head.sha===head && p.head.repo.full_name===REPO && p.base.ref==='main' && p.base.repo.full_name===REPO,
    'original pull changed');
}
function checkedBaselineBytes(file) {
  must(file?.type==='file' && file.path==='deploy_status.json' && file.encoding==='base64' &&
    file.sha===BASELINE_BLOB && file.size===4206 && typeof file.content==='string', 'baseline file changed');
  const b64=file.content.replace(/\n/g,'');
  const bytes=Buffer.from(b64,'base64');
  must(bytes.length===file.size && bytes.toString('base64')===b64 && hash('sha256',bytes)===BASELINE_DIGEST &&
    hash('sha1',Buffer.concat([Buffer.from('blob '+bytes.length+'\0'),bytes]))===BASELINE_BLOB, 'baseline bytes changed');
  return bytes;
}
async function baseline(api) {
  const [file, run, closer, priorPull, previousPublisher, terminalJobs] = await Promise.all([
    api.get('/contents/deploy_status.json?ref='+DIAGNOSTICS),
    api.get('/actions/runs/45155614604'), api.get('/actions/runs/45158055637'),
    api.get('/pulls/1658'), api.get('/issues/comments/7097821694'), jobs(api,45158055637,PRIOR)
  ]);
  const bytes=checkedBaselineBytes(file);
  const record=JSON.parse(bytes);
  const release=record.repository_release;
  must(release.repository_sha===PRIOR && release.application_sha===PRIOR && release.phase==='pending_terminal'
    && release.kind==='installed' && release.release.run_id==='45155614604' && release.closure.run_id==='45158055637',
    'baseline identity changed');
  verifyRun(run,45155614604,'deploy.yml',340734782,PRIOR,'main','push','success');
  verifyRun(closer,45158055637,'modeled-conversion-value-export.yml',404979772,PRIOR,'main','workflow_run','success');
  must(closer.display_title==='deploy-proof:v1:45155614604:1', 'baseline producer changed');
  const terminal=terminalJobs.find(j=>j.name==='release-slot');
  must(terminal?.id===137635983368 && terminal.conclusion==='success', 'missing terminal job');
  for(const name of ['Bind the terminal candidate to the original closer run — '+BASELINE_DIGEST,release.closure.terminal_step]) {
    const steps=terminal.steps.filter(s=>s.name===name);
    must(steps.length===1 && steps[0].status==='completed' && steps[0].conclusion==='success','missing native terminal proof');
  }
  verifyPull(priorPull,1658,PRIOR,release.closure.binding.head_sha);
  const priorBinding=binding(priorPull);
  const {pr,...saved}=release.closure.binding;
  must(pr===1658 && equal(priorBinding,saved) && previousPublisher.id===saved.comment_id
    && previousPublisher.user.id===saved.owner_id && previousPublisher.body===release.closure.expected_terminal &&
    previousPublisher.issue_url==='https://api.github.com/repos/'+REPO+'/issues/1122', 'prior publisher changed');
}
function nativeLog(raw, digest) {
  must(typeof raw==='string' || Buffer.isBuffer(raw), 'native log unavailable');
  const log=raw.toString().replace(/^\uFEFF/,'').replaceAll('\r\n','\n');
  must(hash('sha256',log)===digest, 'native log hash changed');
  return log.split('\n').filter(l=>/^\d{4}-\d\d-\d\dT[\d:.]+Z /.test(l)).map(l=>l.slice(l.indexOf(' ')+1));
}
async function original(api) {
  const [run, commit, all, rows, artifact, log] = await Promise.all([
    api.get('/actions/runs/'+DEPLOY),api.get('/git/commits/'+MAIN),
    census(api,MAIN),jobs(api,DEPLOY,MAIN),api.get('/actions/runs/'+DEPLOY+'/artifacts?per_page=100'),api.jobLog(INSTALL_JOB)
  ]);
  verifyRun(run,DEPLOY,'deploy.yml',340734782,MAIN,'main','push','failure');
  must(complete(artifact,'artifacts').length===0 && equal(all.filter(r=>r.path===PREFIX+'deploy.yml').map(r=>r.id),[DEPLOY]),
    'unexpected deploy or artifacts');
  must(commit.sha===MAIN && equal(commit.parents.map(p=>p.sha),[PRIOR,HEAD]), 'merge ancestry changed');
  const states=Object.fromEntries(rows.map(j=>[j.name,j.conclusion]));
  must(equal(states,{'verify-syntax':'success','verify-inventory':'success','verify / application':'success',
    'verify / diagnostics':'success',deploy:'failure',verify:'skipped'}), 'original installation jobs changed');
  const install=rows.find(j=>j.name==='deploy'), last=rows.find(j=>j.name==='verify');
  must(install.id===INSTALL_JOB && last.id===137647207851 && equal(last.steps,[]) &&
    install.steps.every(s=>s.status==='completed') && equal(install.steps.map(s=>[s.name,s.conclusion]),installationSteps),
    'unexpected original stage execution');
  const lines=nativeLog(log,'109d92df82bcda13aac140a15bc148e1f37c5cf36870e14a3d515e85e46de144');
  const n=lines.indexOf('production_sha='+MAIN);
  must(n>0 && lines[n+1]==='curl: (28) Operation timed out after 15001 milliseconds with 0 bytes received' &&
    lines[n+2]==='##[error]Process completed with exit code 28.' && lines.slice(n+2).includes('  SYNC_OUTCOME: failure'),
    'sync result changed');
  const local=JSON.parse(lines[n-1]);
  must(local.ok===true && local.containment_ok===true && local.write_ok===true && local.outside_document_root===true,
    'local containment proof missing');
  const downstream=all.filter(r=>r.event==='workflow_run');
  must(downstream.length===wave.length, 'unexpected downstream wave');
  await Promise.all(wave.map(async ([file,id,workflow,expectedJobs])=>{
    const r=downstream.find(r=>r.id===id);
    verifyRun(r,id,file,workflow,MAIN,'main','workflow_run','skipped');
    const title=file==='modeled-pipeline-monitor-activation.yml'?'monitor-proof:v1:45161339149:1':'deploy-proof:v1:'+DEPLOY+':1';
    must(r.display_title===title, 'foreign downstream producer');
    const list=await jobs(api,id,MAIN);
    must(equal(list.map(j=>j.name).sort(),[...expectedJobs].sort()) &&
      list.every(j=>j.conclusion==='skipped' && equal(j.steps,[])), 'downstream stage executed');
  }));
}
async function publicReceipt(api) {
  const [run,commit,all,rows,artifact,log]=await Promise.all([
    api.get('/actions/runs/'+CHECK),api.get('/git/commits/'+CHECK_SOURCE),census(api,CHECK_SOURCE),jobs(api,CHECK,CHECK_SOURCE),
    api.get('/actions/runs/'+CHECK+'/artifacts?per_page=100'),api.jobLog(CHECK_JOB)
  ]);
  verifyRun(run,CHECK,CHECK_FILE,427711337,CHECK_SOURCE,CHECK_BRANCH,'push','success');
  must(commit.sha===CHECK_SOURCE && equal(commit.parents.map(p=>p.sha),[MAIN]) &&
    equal(all.filter(r=>r.path===PREFIX+CHECK_FILE).map(r=>r.id),[CHECK]) &&
    complete(artifact,'artifacts').length===0, 'read-only receipt origin changed');
  const expected=['Set up job','Verify exact source, original job history and active publisher',
    'Check the original public containment header without reading content','Acknowledge preflight scope','Complete job'];
  must(rows.length===1 && rows[0].id===CHECK_JOB && rows[0].name==='preflight' && rows[0].conclusion==='success' &&
    equal(rows[0].steps.map(s=>s.name),expected) && rows[0].steps.every(s=>s.status==='completed' && s.conclusion==='success'),
    'public check stage changed');
  const lines=nativeLog(log,'b76bf5d1a48270aea96cb1a4903db8523e1911a33035caef8b70d45dfcb02414');
  const observed=lines.filter(l=>l.startsWith('original_guard_current_http='));
  must(observed.length===1 && /^original_guard_current_http=(403|404)$/.test(observed[0]), 'public evidence changed');
  return Number(observed[0].split('=')[1]);
}

const SUCCESSOR_PR = 1667;
const SUCCESSOR_BRANCH = 'review/pr1662-partial-install-20261123';
const API_ROOT = '/repos/' + REPO;
const sha = value => typeof value === 'string' && /^[a-f0-9]{40}$/.test(value);
const FORWARD_FILES = {
  '.github/workflows/deploy.yml': 'modified',
  'tools/deployment_release.js': 'modified',
  'tools/release_priority.js': 'modified',
  'tools/partial_install_1662_forward.js': 'added',
  'tests/run_partial_install_1662_forward_regression.js': 'added',
  'tests/run_partial_install_1662_forward_sync.py': 'added',
  'tests/run_deployment_release_regression.js': 'modified',
  'tests/fixtures/pr1643/release_priority.js.txt': 'added',
  'resources/max2-common-v2/release-manifest.json': 'modified'
};

function fileSnapshot(rows) {
  must(Array.isArray(rows) && rows.length === Object.keys(FORWARD_FILES).length, 'successor file count changed');
  const snapshot = {};
  for (const file of rows) {
    must(file && Object.hasOwn(FORWARD_FILES, file.filename) && !Object.hasOwn(snapshot, file.filename) &&
      file.status === FORWARD_FILES[file.filename] && !Object.hasOwn(file, 'previous_filename') && sha(file.sha),
      'successor file identity changed');
    snapshot[file.filename] = file.sha;
  }
  return snapshot;
}

function successorContext({pr, head, phase, source, event, runId}) {
  must(pr === SUCCESSOR_PR && sha(head) && head !== MAIN && head !== HEAD &&
    ['before_handoff', 'after_handoff', 'installation'].includes(phase), 'wrong successor');
  if (phase !== 'installation') {
    must(source === undefined && runId === undefined && event === undefined, 'unexpected installation context');
    return {pr, head, phase, main: MAIN};
  }
  must(sha(source) && source !== MAIN && source !== head &&
    (typeof runId === 'string' || typeof runId === 'number') && /^[1-9]\d*$/.test(String(runId)) &&
    Number.isSafeInteger(Number(runId)), 'invalid installation context');
  must(event?.repository?.id === REPO_ID && event.repository.full_name === REPO &&
    event?.sender?.id === OWNER && event.sender.login === 'pyatkoff' &&
    event.ref === 'refs/heads/main' && event.before === MAIN && event.after === source &&
    event.head_commit?.id === source && event.forced === false &&
    Object.keys(event.inputs || {}).length === 0, 'untrusted successor push');
  return {pr, head, phase, main: source, runId: Number(runId)};
}

function checkTarget(target, c) {
  const {pr, head, phase, main} = c;
  must(target?.number === pr && target.user?.id === OWNER && target.user.login === 'pyatkoff' &&
    target.head?.repo?.full_name === REPO && target.head.repo.id === REPO_ID &&
    target.head.ref === SUCCESSOR_BRANCH && target.head.sha === head &&
    target.base?.repo?.full_name === REPO && target.base.repo.id === REPO_ID &&
    target.base.ref === 'main' && target.draft === false, 'successor pull changed');
  if (phase === 'installation') {
    must(target.state === 'closed' && target.merged === true &&
      target.merge_commit_sha === main, 'successor merge changed');
  } else {
    must(target.state === 'open' && target.merged === false && target.base.sha === MAIN, 'successor base changed');
  }
}

async function liveBaseline(api) {
  const ref = await api.get('/git/ref/heads/diagnostics');
  must(ref?.ref === 'refs/heads/diagnostics' && ref.object?.type === 'commit' &&
    sha(ref.object.sha), 'diagnostics ref changed');
  const current = ref.object.sha;
  if (current !== DIAGNOSTICS) {
    const relation = await api.get('/compare/' + DIAGNOSTICS + '...' + current + '?per_page=1');
    must(relation?.status === 'ahead' && relation.merge_base_commit?.sha === DIAGNOSTICS,
      'diagnostics ancestry changed');
  }
  checkedBaselineBytes(await api.get('/contents/deploy_status.json?ref=' + current));
  return current;
}

async function livePublisher(api, c) {
  const close = require('./release_slot_close');
  const [ref, previous, target, comment, relation, files] = await Promise.all([
    api.get('/git/ref/heads/main'), api.get('/pulls/1662'), api.get('/pulls/' + c.pr),
    api.get('/issues/comments/' + BIND.comment_id),
    api.get('/compare/' + MAIN + '...' + c.head + '?per_page=1'),
    api.get('/pulls/' + SUCCESSOR_PR + '/files?per_page=100&page=1')
  ]);
  must(ref?.ref === 'refs/heads/main' && ref.object?.type === 'commit' &&
    ref.object.sha === c.main, 'current main changed');
  must(relation?.status === 'ahead' && relation.merge_base_commit?.sha === MAIN,
    'successor head ancestry changed');
  verifyPull(previous, 1662, MAIN, HEAD);
  const previousBinding = close.binding(previous, MAIN);
  must(previousBinding && equal(close.bindingSnapshot(previousBinding), {pr:1662, ...BIND}), 'original publisher binding changed');
  checkTarget(target, c);
  const blobs = fileSnapshot(relation.files);
  must(target.changed_files === Object.keys(FORWARD_FILES).length && equal(fileSnapshot(files), blobs),
    'successor pull content changed');
  let active = previousBinding;
  if (c.phase !== 'before_handoff') {
    // The shared parser verifies merge metadata as well as the marker. For an open
    // PR its actual state and base were verified above, before parsing the marker.
    const forParser = c.phase === 'installation' ? target : {...target, merged:true, state:'closed', merge_commit_sha:MAIN};
    active = close.binding(forParser, c.main);
    must(active?.comment_id === BIND.comment_id && active.owner_id === BIND.owner_id &&
      active.pr === SUCCESSOR_PR && active.head_sha === c.head &&
      Date.parse(active.updated_at) > Date.parse(BIND.updated_at), 'successor binding changed');
  }
  must(close.recordState(comment, active) === 'active', 'publisher inactive');
  return {binding:close.bindingSnapshot(active), blobs};
}

async function nativeInstallation(api, c) {
  const [commit, pulls, run, all] = await Promise.all([
    api.get('/git/commits/' + c.main), api.get('/commits/' + c.main + '/pulls?per_page=100'),
    api.get('/actions/runs/' + c.runId), census(api, c.main)
  ]);
  must(commit?.sha === c.main && Array.isArray(commit.parents) &&
    equal(commit.parents.map(p => p.sha), [MAIN, c.head]), 'not the direct successor merge');
  must(Array.isArray(pulls) && pulls.length < 100 && new Set(pulls.map(p => p.number)).size === pulls.length,
    'incomplete successor pull listing');
  const merged = pulls.filter(p => p.state === 'closed' && p.merge_commit_sha === c.main);
  must(merged.length === 1 && merged[0].number === SUCCESSOR_PR &&
    merged[0].head?.sha === c.head, 'ambiguous successor merge');
  // A currently executing original main push is the only useful installation context.
  must(run?.id === c.runId && run.workflow_id === 340734782 && run.path === PREFIX + 'deploy.yml' &&
    run.event === 'push' && run.run_attempt === 1 && run.head_branch === 'main' && run.head_sha === c.main &&
    run.head_commit?.id === c.main && run.status === 'in_progress' && run.conclusion === null, 'wrong live deployment');
  for (const k of ['repository','head_repository']) {
    must(run[k]?.id === REPO_ID && run[k]?.full_name === REPO, 'untrusted live repository');
  }
  for (const k of ['actor','triggering_actor']) {
    must(run[k]?.id === OWNER && run[k]?.login === 'pyatkoff', 'untrusted live actor');
  }
  const deploys = all.filter(r => r.path === PREFIX + 'deploy.yml');
  must(deploys.length === 1 && deploys[0].id === c.runId && deploys[0].run_attempt === 1 &&
    deploys[0].event === 'push' && deploys[0].head_branch === 'main', 'duplicate or replayed successor deploy');
}

async function sourceChecks(api, head) {
  const runs = await census(api, head);
  const required = [
    [340550044, PREFIX + 'regression-tests.yml'],
    [348353174, PREFIX + 'retired-domain-guard.yml']
  ];
  return Promise.all(required.map(async ([workflow, path]) => {
    const candidates = runs.filter(r => r.workflow_id === workflow || r.path === path);
    must(candidates.length === 1 && Number.isSafeInteger(candidates[0].id) && candidates[0].id > 0,
      'missing or duplicate source check');
    const listed = candidates[0];
    const fetched = await api.get('/actions/runs/' + listed.id);
    for (const run of [listed, fetched]) {
      must(run?.id === listed.id && run.workflow_id === workflow && run.path === path &&
        run.event === 'pull_request' && run.run_attempt === 1 && run.head_sha === head &&
        run.head_branch === SUCCESSOR_BRANCH && run.status === 'completed' && run.conclusion === 'success',
        'source check not successful for successor');
      for (const key of ['repository', 'head_repository']) {
        must(run[key]?.id === REPO_ID && run[key]?.full_name === REPO, 'source check repository changed');
      }
      if (run.pull_requests != null) {
        must(Array.isArray(run.pull_requests) && run.pull_requests.length <= 1, 'ambiguous source check pull');
        for (const pull of run.pull_requests) {
          must(pull.number === SUCCESSOR_PR && pull.head?.sha === head && pull.head.ref === SUCCESSOR_BRANCH &&
            pull.base?.ref === 'main' && pull.head.repo?.id === REPO_ID && pull.base.repo?.id === REPO_ID,
            'source check pull changed');
        }
      }
    }
    return listed.id;
  }));
}

async function liveAnchors(api, c) {
  const [publisher, diagnosticsHead, sourceCheckRuns] = await Promise.all([
    livePublisher(api, c), liveBaseline(api), sourceChecks(api, c.head),
    c.phase === 'installation' ? nativeInstallation(api, c) : undefined]);
  return {...publisher, diagnosticsHead, sourceCheckRuns};
}

// Only the ordinary full installation may act. This proof never supplies an
// installation receipt, an unchanged-application result, or a terminal release.
async function provePartialInstall1662Forward(options) {
  must(typeof options?.api === 'function' && typeof options.api.jobLog === 'function', 'read-only adapter missing');
  const c = successorContext(options);
  const read = {get: path => options.api('GET', API_ROOT + path), jobLog: id => options.api.jobLog(id)};
  const initial = await liveAnchors(read, c);
  const [, , status] = await Promise.all([baseline(read), original(read), publicReceipt(read)]);
  const final = await liveAnchors(read, c);
  must(equal(initial, final), 'live forward anchors changed');
  return {phase:c.phase, head_sha:c.head, source_sha:c.main, binding:final.binding,
    source_check_runs:final.sourceCheckRuns,
    previous_pr:1662, previous_sha:MAIN, previous_run_id:String(DEPLOY),
    previous_installation:'partial_after_checkout', previous_install_job_id:INSTALL_JOB,
    previous_application_sha:PRIOR, previous_accepted_record_sha256:BASELINE_DIGEST,
    previous_public_check_run:CHECK, previous_observed_http:status};
}
module.exports = {provePartialInstall1662Forward};
