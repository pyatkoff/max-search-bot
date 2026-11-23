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
  must(rows.every(j=>j.run_id===run && j.head_sha===sha && j.status==='completed') &&
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
async function anchors(api) {
  const [main,diag,publisher,pr] = await Promise.all([
    api.get('/git/ref/heads/main'),api.get('/git/ref/heads/diagnostics'),api.get('/issues/comments/'+BIND.comment_id),api.get('/pulls/1662')
  ]);
  verifyPull(pr,1662,MAIN,HEAD);
  must(equal(binding(pr),BIND), 'wrong source binding');
  must(main.ref==='refs/heads/main' && main.object.type==='commit' && main.object.sha===MAIN, 'main changed');
  must(diag.ref==='refs/heads/diagnostics' && diag.object.type==='commit' && diag.object.sha===DIAGNOSTICS, 'diagnostics changed');
  must(publisher.id===BIND.comment_id && publisher.user.id===OWNER && publisher.body==='PR1662: выпуск занят'
    && publisher.updated_at===BIND.updated_at && publisher.issue_url==='https://api.github.com/repos/'+REPO+'/issues/1122',
    'held publisher changed');
}
async function baseline(api) {
  const [file, run, closer, priorPull, previousPublisher, terminalJobs] = await Promise.all([
    api.get('/contents/deploy_status.json?ref='+DIAGNOSTICS),
    api.get('/actions/runs/45155614604'), api.get('/actions/runs/45158055637'),
    api.get('/pulls/1658'), api.get('/issues/comments/7097821694'), jobs(api,45158055637,PRIOR)
  ]);
  must(file?.type==='file' && file.path==='deploy_status.json' && file.encoding==='base64' &&
    file.sha===BASELINE_BLOB && file.size===4206 && typeof file.content==='string', 'baseline file changed');
  const b64=file.content.replace(/\n/g,'');
  const bytes=Buffer.from(b64,'base64');
  must(bytes.length===file.size && bytes.toString('base64')===b64 && hash('sha256',bytes)===BASELINE_DIGEST &&
    hash('sha1',Buffer.concat([Buffer.from('blob '+bytes.length+'\0'),bytes]))===BASELINE_BLOB, 'baseline bytes changed');
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

// The adapter must perform authenticated GETs for this repository and retrieve the original
// Actions job log bytes. A successful return authenticates history only, never production admission.
async function inspectPartialInstall1662(api) {
  must(typeof api?.get==='function' && typeof api?.jobLog==='function','read-only adapter missing');
  await anchors(api);
  const [, , status] = await Promise.all([baseline(api),original(api),publicReceipt(api)]);
  await anchors(api);
  return {decision:'HOLD', originalDeployment:DEPLOY, originalInstaller:INSTALL_JOB,
    checkoutObserved:MAIN, acceptedBaseline:PRIOR, acceptedBaselineDigest:BASELINE_DIGEST,
    localContainmentAtSync:true, publicCheckRun:CHECK, observedHttpStatus:status,
    missingInstallStages:4, originalVerification:'skipped', originalDownstreamJobsExecuted:0,
    noProductionWriteAuthorization:true};
}
module.exports={inspectPartialInstall1662};
