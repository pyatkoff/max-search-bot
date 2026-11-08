#!/usr/bin/env node
'use strict';

// Metadata-only terminal step of the existing post-deploy workflow.
// Never deploys, dispatches checks, creates release slots, or retries a write.
// Its only content write extends the existing diagnostics installation receipt.
const release = require('./deployment_release');
const REPOSITORY = 'pyatkoff/max-search-bot';
const PREFIX = '.github/workflows/';
const CLOSER = PREFIX + 'modeled-conversion-value-export.yml';
const ACTIVATION = {
  file: 'modeled-pipeline-monitor-activation.yml',
  name: 'Activate the AnyTour pipeline monitor once',
  marker: '[modeled-pipeline-monitor-activation-v1]',
};
const REQUIRED = [
  ['deploy.yml', 'push', 'Deploy production'],
  ['retired-domain-guard.yml', 'push', 'Retired domain guard'],
  ['max-tls-preflight.yml', 'workflow_run', 'Verify production MAX TLS'],
  ['max-production-health.yml', 'workflow_run', 'Inspect production MAX webhook'],
  ['telegram-production-health.yml', 'workflow_run', 'Inspect production Telegram webhook'],
  ['publish-conversation-diagnostics.yml', 'workflow_run', 'Publish production diagnostics'],
  ['live-session-diagnostics.yml', 'workflow_run', 'Publish live session diagnostics'],
  ['tour-selection-host.yml', 'workflow_run', 'Deploy dedicated selection host'],
  ['modeled-conversion-monitor.yml', 'workflow_run', 'Monitor modeled conversion delivery'],
  [ACTIVATION.file, 'workflow_run', ACTIVATION.name],
];
const sha = value => typeof value === 'string' && /^[0-9a-f]{40}$/.test(value);
const id = value => Number.isSafeInteger(value) && value > 0;
const stamp = value => typeof value === 'string' && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(value) && Number.isFinite(Date.parse(value));
class Refusal extends Error { constructor(code) { super(code); this.code = code; } }
function requireValue(ok, code) { if (!ok) throw new Refusal(code); }
function context(env, event) {
  const run = event && event.workflow_run;
  requireValue(env.GITHUB_REPOSITORY === REPOSITORY && env.GITHUB_EVENT_NAME === 'workflow_run'
    && env.GITHUB_REF === 'refs/heads/main' && env.GITHUB_RUN_ATTEMPT === '1'
    && /^[1-9][0-9]*$/.test(env.GITHUB_RUN_ID || '') && id(Number(env.GITHUB_RUN_ID))
    && event.action === 'completed' && event.repository?.full_name === REPOSITORY
    && run?.name === 'Deploy production' && run.path === PREFIX + 'deploy.yml'
    && run.event === 'push' && run.head_branch === 'main'
    && run.head_repository?.full_name === REPOSITORY && run.status === 'completed'
    && run.conclusion === 'success' && [1,2].includes(run.run_attempt) && id(run.id)
    && sha(run.head_sha) && env.GITHUB_SHA === run.head_sha
    && run.head_commit?.id === run.head_sha && typeof run.head_commit.message === 'string'
    && run.head_commit.message.length <= 65536
    && ['success', 'skipped'].includes(env.CONFIGURE_RESULT), 'untrusted_context');
  return { sha: run.head_sha, deploy: run.id, run: Number(env.GITHUB_RUN_ID),
    ...(run.run_attempt===2?{deployAttempt:2}:{}), configure: env.CONFIGURE_RESULT, activation: run.head_commit.message.includes(ACTIVATION.marker) };
}
function binding(pr, source) {
  requireValue(pr?.merged === true && pr.state === 'closed' && id(pr.number)
    && pr.merge_commit_sha === source && pr.base?.ref === 'main'
    && pr.base.repo?.full_name === REPOSITORY && pr.head?.repo?.full_name === REPOSITORY
    && sha(pr.head.sha), 'untrusted_pull');
  const body = pr.body || '';
  requireValue(typeof body === 'string' && body.length <= 65536, 'invalid_binding');
  const occurrences = body.match(/<!-- release-slot-close:v1\b/g) || [];
  if (!occurrences.length) return null;
  const matches = [...body.matchAll(/<!-- release-slot-close:v1 (\{[^\r\n]*\}) -->/g)];
  requireValue(occurrences.length === 1 && matches.length === 1 && matches[0][1].length < 400, 'invalid_binding');
  let value;
  try { value = JSON.parse(matches[0][1]); } catch { throw new Refusal('invalid_binding'); }
  requireValue(Object.keys(value).sort().join(',') === 'comment_id,head_sha,owner_id,updated_at'
    && id(value.comment_id) && id(value.owner_id) && value.head_sha === pr.head.sha
    && stamp(value.updated_at), 'invalid_binding');
  return { ...value, pr: pr.number,
    active: 'PR' + pr.number + ': выпуск занят',
    released: 'PR' + pr.number + ': установлено, выпуск свободен' };
}
function validRun(run, c, path, event, name, origin = null) {
  requireValue(id(run?.id) && run.path === path && run.event === event
    && (run.name === name || (origin !== null && run.name === origin))
    && (origin === null || (run.display_title === undefined && run.name === name) || run.display_title === origin)
    && run.head_sha === c.sha && run.head_branch === 'main'
    && run.head_repository?.full_name === REPOSITORY
    && run.run_attempt === (path===PREFIX+'deploy.yml'&&c.recovery?2:1) && stamp(run.created_at), 'untrusted_run');
}
function gateState(snapshot, c) {
  requireValue(typeof c.activation === 'boolean', 'activation_context_missing');
  const runs = snapshot?.workflow_runs;
  requireValue(Array.isArray(runs) && Number.isSafeInteger(snapshot.total_count)
    && snapshot.total_count === runs.length && runs.length < 100
    && new Set(runs.map(r => r.id)).size === runs.length, 'incomplete_runs');
  const known = new Set(REQUIRED.map(([file]) => PREFIX + file).concat(CLOSER));
  requireValue(runs.every(r => r.event !== 'workflow_run' || known.has(r.path)), 'unclassified_gate');
  let waiting = false;
  let deployment;
  const monitor = runs.find(r => r.path === PREFIX + 'modeled-conversion-monitor.yml' && r.event === 'workflow_run');
  const origin = file => file === ACTIVATION.file
    ? (id(monitor?.id) ? 'monitor-proof:v1:' + monitor.id + ':1' : null)
    : 'deploy-proof:v1:' + c.deploy + ':' + (c.deployAttempt || 1);
  for (const [file, event, name] of REQUIRED) {
    const path = PREFIX + file;
    // A rerun/manual deployment of this source makes provenance ambiguous.
    const candidates = runs.filter(r => r.path === path
      && (file === 'deploy.yml' || r.event === event));
    requireValue(candidates.length <= 1, 'ambiguous_run');
    if (!candidates.length) { waiting = true; continue; }
    const run = candidates[0];
    validRun(run, c, path, event, name, event === 'workflow_run' ? origin(file) : null);
    if (file === 'deploy.yml') {
      requireValue(run.id === c.deploy, 'deployment_changed');
      deployment = run;
    }
    if (run.status !== 'completed') {
      requireValue(['queued', 'in_progress', 'waiting', 'pending', 'requested'].includes(run.status)
        && run.conclusion === null, 'invalid_run_state');
      waiting = true;
    } else if (file === ACTIVATION.file && !c.activation) {
      // The workflow is present on every deployment. Its job must also prove
      // the declared no-marker skip below; a successful run alone is insufficient.
      requireValue(['success', 'skipped'].includes(run.conclusion), 'required_gate_failed');
    } else requireValue(run.conclusion === 'success', 'required_gate_failed');
  }
  requireValue(deployment, 'deployment_missing');
  for (const [file, event] of REQUIRED.filter(x => x[1] === 'workflow_run')) {
    const run = runs.find(r => r.path === PREFIX + file && r.event === event);
    if (run) requireValue(Date.parse(run.created_at) >= Date.parse(deployment.created_at), 'stale_gate');
  }
  const own = runs.filter(r => r.path === CLOSER && r.event === 'workflow_run');
  requireValue(own.length === 1 && own[0].id === c.run, 'ambiguous_closer');
  validRun(own[0], c, CLOSER, 'workflow_run', 'Configure AnyTour modeled value goal', origin('modeled-conversion-value-export.yml'));
  requireValue(own[0].status === 'in_progress' && own[0].conclusion === null, 'closer_not_active');
  return waiting ? 'waiting' : 'ready';
}
// A scheduled monitor can create a separate, deliberately skipped activation
// for this same commit. It is never the original deployment's activation proof.
async function selectReleaseRuns(snapshot,c,get) {
  const runs=snapshot?.workflow_runs;
  requireValue(Array.isArray(runs)&&Number.isSafeInteger(snapshot.total_count)
    &&snapshot.total_count===runs.length&&runs.length<100
    &&new Set(runs.map(r=>r.id)).size===runs.length,'incomplete_runs');
  const selected=[],origins=new Set();
  let waiting=false;
  for(const run of runs) {
    const match=run.path===PREFIX+ACTIVATION.file&&run.event==='workflow_run'
      &&typeof run.display_title==='string'
      &&/^monitor-proof:v1:([1-9][0-9]*):1$/.exec(run.display_title);
    if(!match){selected.push(run);continue;}
    const parentId=Number(match[1]);
    requireValue(id(parentId)&&!origins.has(parentId),'ambiguous_activation_origin');
    origins.add(parentId);
    const present=runs.find(r=>r.id===parentId);
    const parent=present||await get('/actions/runs/'+parentId);
    if(parent?.event!=='schedule'){selected.push(run);continue;}
    validRun(parent,c,PREFIX+'modeled-conversion-monitor.yml','schedule','Monitor modeled conversion delivery');
    requireValue(parent.id===parentId&&parent.status==='completed'&&parent.conclusion==='success'
      &&parent.head_commit?.id===c.sha,'independent_monitor_unconfirmed');
    validRun(run,c,PREFIX+ACTIVATION.file,'workflow_run',ACTIVATION.name,match[0]);
    requireValue(Date.parse(run.created_at)>=Date.parse(parent.created_at),'stale_gate');
    if(run.status!=='completed'){
      requireValue(['queued','in_progress','waiting','pending','requested'].includes(run.status)
        &&run.conclusion===null,'invalid_run_state');
      waiting=true;continue;
    }
    requireValue(['success','skipped'].includes(run.conclusion),'independent_activation_unconfirmed');
    const jobs=await get('/actions/runs/'+run.id+'/attempts/1/jobs?per_page=100');
    requireValue(Array.isArray(jobs?.jobs)&&jobs.total_count===1&&jobs.jobs.length===1,
      'incomplete_independent_activation_jobs');
    const j=jobs.jobs[0];
    requireValue(id(j.id)&&j.name==='activate'&&j.run_id===run.id&&j.run_attempt===1
      &&j.head_sha===c.sha&&j.status==='completed'&&j.conclusion==='skipped'
      &&Array.isArray(j.steps)&&j.steps.length===0,'independent_activation_executed');
  }
  return {runs:await selectRecoveryRuns({...snapshot,total_count:selected.length,workflow_runs:selected},c,get),waiting};
}
function recordState(record, b) {
  requireValue(record?.id === b.comment_id && record.user?.id === b.owner_id
    && record.issue_url === 'https://api.github.com/repos/' + REPOSITORY + '/issues/1122'
    && ['OWNER', 'MEMBER', 'COLLABORATOR'].includes(record.author_association)
    && stamp(record.updated_at), 'foreign_record');
  if (record.body === b.released) return 'released';
  requireValue(record.body === b.active && record.updated_at === b.updated_at, 'record_changed');
  return 'active';
}
function verifyTelemetry(deploy, ops, c) {
  const stages = ['provenance', 'key', 'bundle', 'sync', 'migration', 'max_webhook',
    'metrika_cron', 'smoke', 'diagnostics'];
  requireValue(deploy?.sha === c.sha && String(deploy.run_id) === String(c.deploy)
    && stages.every(key => deploy[key] === 'success'), 'installation_unconfirmed');
  if(c.recovery)requireValue(deploy.schema===2&&deploy.install_attempt===1&&deploy.install_checks==='success'&&
    deploy.smoke_attempt===c.recovery.smokeAttempt&&deploy.diagnostics_attempt===c.recovery.diagnosticsAttempt,'recovery_telemetry_unconfirmed');
  requireValue(ops?.sha === c.sha && ['migration', 'snapshot', 'live_sessions',
    'daily_sessions', 'yesterday_sessions', 'architecture_inventory', 'website_smoke', 'health']
    .every(key => ops[key] === 'success'), 'diagnostics_unconfirmed');
}
function verifyConfigure(jobs, c) {
  requireValue(Array.isArray(jobs?.jobs) && jobs.total_count === jobs.jobs.length
    && jobs.jobs.length < 100, 'incomplete_jobs');
  const matches = jobs.jobs.filter(j => j.name === 'configure');
  requireValue(matches.length === 1 && matches[0].run_id === c.run
    && matches[0].head_sha === c.sha && matches[0].status === 'completed'
    && matches[0].conclusion === c.configure, 'configuration_unconfirmed');
}
function verifyActivation(jobs, run, c, monitorId = null) {
  validRun(run, c, PREFIX + ACTIVATION.file, 'workflow_run', ACTIVATION.name,
    id(monitorId) ? 'monitor-proof:v1:' + monitorId + ':1' : null);
  requireValue(typeof c.activation === 'boolean' && run.status === 'completed'
    && (c.activation ? run.conclusion === 'success' : ['success', 'skipped'].includes(run.conclusion)),
    'activation_unconfirmed');
  requireValue(Array.isArray(jobs?.jobs) && jobs.total_count === jobs.jobs.length
    && jobs.jobs.length === 1, 'incomplete_activation_jobs');
  const job = jobs.jobs[0];
  requireValue(job.name === 'activate' && job.run_id === run.id && job.head_sha === c.sha
    && job.status === 'completed' && job.conclusion === (c.activation ? 'success' : 'skipped'),
    'activation_unconfirmed');
}
async function closeRelease({ env, event, api, sleep, now = Date.now, prepare = false, proofFile = null }) {
  const c = context(env, event);
  const endpoint = path => '/repos/' + REPOSITORY + path;
  const get = path => api('GET', endpoint(path));
  const main = async () => {
    requireValue((await get('/git/ref/heads/main'))?.object?.sha === c.sha, 'main_changed');
  };
  await main();
  if(c.deployAttempt===2)c.recovery=await readRecovery(get,c);
  const snapshot=async()=>selectReleaseRuns(await get('/actions/runs?head_sha='+c.sha+'&per_page=100'),c,get);
  const pulls = await get('/commits/' + c.sha + '/pulls?per_page=100');
  requireValue(Array.isArray(pulls) && pulls.length < 100, 'incomplete_pulls');
  const merged = pulls.filter(p => p.merge_commit_sha === c.sha && p.state === 'closed');
  requireValue(merged.length === 1, 'ambiguous_pull');
  const pr = await get('/pulls/' + merged[0].number);
  const before = await api('GET', endpoint('/contents/deploy_status.json?ref=diagnostics'), undefined, true);
  if(!prepare&&env.PREPARED_RECORD_DIGEST!==undefined){
    requireValue(/^[0-9a-f]{64}$/.test(env.PREPARED_RECORD_DIGEST)
      &&before?.repository_release?.phase==='pending_terminal'
      &&before.repository_release.closure?.run_id===String(c.run)
      &&release.digest(before)===env.PREPARED_RECORD_DIGEST,'prepared_result_changed');
  }
  const terminalPrepared=before?.repository_release?.repository_sha===c.sha
    &&before.repository_release.phase==='pending_terminal';
  let b = binding(pr, c.sha);
  if (!b) {requireValue(!terminalPrepared,'prepared_binding_removed');return 'not_opted_in';}
  const recordPath = '/issues/comments/' + b.comment_id;
  // An unchanged terminal has its own honest text. Read the typed result before
  // interpreting a pre-existing terminal, never translate it into an installation.
  if(before?.repository_release?.repository_sha===c.sha&&before.repository_release.kind==='application_unchanged')
    b={...b,released:release.terminalBody('application_unchanged',b.pr)};
  if (recordState(await get(recordPath), b) === 'released') {
    requireValue(!terminalPrepared,'prepared_terminal_already_released');return 'already_released';
  }
  const deadline = now() + 18 * 60 * 1000;
  while (true) {
    await main();
    const observed = await snapshot();
    const state = gateState(observed.runs, c);
    if (state === 'ready' && !observed.waiting) break;
    requireValue(now() < deadline, 'gate_deadline');
    await sleep(Math.min(20000, deadline - now()));
  }
  verifyConfigure(await get('/actions/runs/' + c.run + '/attempts/1/jobs?per_page=100'), c);
  // Read the existing exact installation evidence; do not execute any probes.
  const deploy = await api('GET', endpoint('/contents/deploy_status.json?ref=diagnostics'), undefined, true);
  const ops = await api('GET', endpoint('/contents/ops_status.json?ref=diagnostics'), undefined, true);
  if(!prepare&&env.PREPARED_RECORD_DIGEST!==undefined)
    requireValue(release.digest(deploy)===env.PREPARED_RECORD_DIGEST&&deploy?.repository_release?.phase==='pending_terminal',
      'prepared_result_changed');
  let typed=null;
  if(deploy?.repository_release){
    requireValue(prepare||(/^[0-9a-f]{64}$/.test(env.PREPARED_RECORD_DIGEST||'')
      &&release.digest(deploy)===env.PREPARED_RECORD_DIGEST),'prepared_result_changed');
    typed=await release.readRecord(api);
    requireValue(release.same(typed.record,deploy),'deployment_result_changed');
    const r=release.validateRelease(deploy).release;
    requireValue(r.repository_sha===c.sha&&r.release.run_id===String(c.deploy)
      &&(r.kind==='installed'||(!c.recovery&&c.deployAttempt!==2)),'deployment_result_mismatch');
    if(r.kind==='application_unchanged'){
      requireValue(c.configure==='skipped'&&!c.activation,'unchanged_intent_executed');
      await release.verifyUnchanged(api,typed);
    }else{
      verifyTelemetry(deploy,ops,c);
      await release.authenticatePublication(api,typed);
    }
  }else{
    requireValue(!prepare,'typed_release_result_missing');verifyTelemetry(deploy, ops, c);
  }
  // Fresh admission to update this one record, never another owner's slot.
  let current = binding(await get('/pulls/' + b.pr), c.sha);
  if(current&&typed?.record.repository_release.kind==='application_unchanged')
    current={...current,released:release.terminalBody('application_unchanged',current.pr)};
  requireValue(JSON.stringify(current) === JSON.stringify(b), 'binding_changed');
  await main();
  if(c.recovery)c.recovery=await readRecovery(get,c);
  const finalSnapshot = await snapshot();
  const finalRuns = finalSnapshot.runs;
  requireValue(gateState(finalRuns, c) === 'ready' && !finalSnapshot.waiting, 'gates_changed');
  const activation = finalRuns.workflow_runs.find(r => r.path === PREFIX + ACTIVATION.file
    && r.event === 'workflow_run');
  verifyActivation(await get('/actions/runs/' + activation.id + '/attempts/1/jobs?per_page=100'), activation, c,
    finalRuns.workflow_runs.find(r => r.path === PREFIX + 'modeled-conversion-monitor.yml' && r.event === 'workflow_run')?.id);
  if (recordState(await get(recordPath), b) === 'released') {
    requireValue(!typed,'prepared_terminal_already_released');return 'already_released';
  }
  if(typed){
    if(typed.record.repository_release.kind==='application_unchanged')await verifyUnchangedJobs(api,finalRuns,c);
    if(prepare){
      requireValue(typed.record.repository_release.phase==='pending_closure','terminal_already_prepared');
      const prepared=await prepareRecord(api,typed,c,b,finalRuns);
      // Every fail-capable publication is before the only terminal mutation.
      // A competing diagnostics writer is a conflict, not a blind rebase/retry.
      const location=await publishDiagnostics(api,prepared,typed);
      const proof=release.publicationProof(prepared,location,String(c.run),1);
      if(proofFile)release.saveJson(proofFile,proof);
      return {publication_artifact:release.publicationName('pending_terminal',c.run,1),
        publication_file:proofFile||'',record_digest:release.digest(prepared)};
    }
    const r=typed.record.repository_release;
    requireValue(r.phase==='pending_terminal'&&r.closure.run_id===String(c.run)
      &&release.same(r.closure.binding,bindingSnapshot(b))
      &&release.same(r.closure.gates,gateReferences(finalRuns)),'terminal_proposal_changed');
    const fresh=await release.readRecord(api);
    requireValue(fresh.blob===typed.blob&&fresh.record_digest===typed.record_digest,'deployment_result_changed');
    await release.authenticatePublication(api,fresh);
    // No slow artifact/job verification after the last publisher admission.
    // Re-read main, the binding and the owned record immediately before PATCH.
    const lastSnapshot=await snapshot();
    requireValue(gateState(lastSnapshot.runs,c)==='ready'&&!lastSnapshot.waiting,'gates_changed');
    let lastBinding=binding(await get('/pulls/'+b.pr),c.sha);
    if(lastBinding&&r.kind==='application_unchanged')lastBinding={...lastBinding,released:release.terminalBody(r.kind,b.pr)};
    requireValue(release.same(lastBinding,b),'binding_changed');
    await main();
    requireValue(recordState(await get(recordPath),b)==='active','prepared_terminal_already_released');
  }
  // GitHub comments have no atomic compare-and-swap. This relies on the existing
  // single-publisher contract and this workflow's existing serialized execution.
  // One PATCH only. A transport/write/readback failure is UNKNOWN, never retried.
  let written;
  try { written = await api('PATCH', endpoint(recordPath), { body: b.released }); }
  catch { throw new Refusal('write_unknown'); }
  requireValue(written?.id === b.comment_id && written.body === b.released, 'write_unknown');
  let readback;
  try { readback = await get(recordPath); } catch { throw new Refusal('write_unknown'); }
  requireValue(readback?.id === b.comment_id && readback.body === b.released, 'write_unknown');
  return 'released';
}

const INSTALL_JOBS = ['verify-inventory','verify-syntax','verify / application','verify / diagnostics','deploy'];
const VERIFY_STEPS = {check:'Verify installed production checks',download:'Download exact installed diagnostic snapshot',
  status:'Publish verification stage status',publication:release.PENDING_BINDING_STEP,
  cleanup:'Remove acknowledged diagnostic snapshot'};
function stepResult(job,name) {
  requireValue(job && Array.isArray(job.steps),'incomplete_job_steps');
  const rows=job.steps.filter(s=>name===release.PENDING_BINDING_STEP
    ?typeof s?.name==='string'&&s.name.startsWith(name)&&(/^[0-9a-f]{64}$/.test(s.name.slice(name.length))
      ||(s.name===name&&s.conclusion==='skipped'))
    :s?.name===name);
  requireValue(rows.length===1&&rows[0].status==='completed'&&['success','failure','skipped'].includes(rows[0].conclusion),'incomplete_job_steps');
  return rows[0].conclusion;
}
function executionShape(j) {
  return {name:j.name,run_id:j.run_id,head_sha:j.head_sha,status:j.status,conclusion:j.conclusion,
    started_at:j.started_at,completed_at:j.completed_at,runner_id:j.runner_id,runner_name:j.runner_name,labels:j.labels,
    steps:(j.steps||[]).map(s=>({number:s.number,name:s.name,status:s.status,conclusion:s.conclusion,
      started_at:s.started_at,completed_at:s.completed_at}))};
}
function copiedExecution(a,b,retryStarted) {
  return !!b&&a?.status==='completed'&&a.conclusion==='success'&&stamp(a.started_at)&&stamp(a.completed_at)&&
    Date.parse(a.completed_at)<Date.parse(retryStarted)&&id(a.runner_id)&&typeof a.runner_name==='string'&&a.runner_name.length>0&&
    Array.isArray(a.labels)&&Array.isArray(a.steps)&&a.steps.length>0&&
    JSON.stringify(executionShape(a))===JSON.stringify(executionShape(b));
}
function trustedAttempt(run,c,attempt,event='push') {
  requireValue(run?.id===c.deploy&&run.path===PREFIX+'deploy.yml'&&run.name==='Deploy production'&&
    run.event===event&&run.head_branch==='main'&&run.head_repository?.full_name===REPOSITORY&&
    run.head_sha===c.sha&&run.head_commit?.id===c.sha&&run.run_attempt===attempt&&
    stamp(run.created_at)&&stamp(run.run_started_at)&&stamp(run.updated_at),'untrusted_deployment_attempt');
}
function deploymentHistory(history,first,current,c,admitting=false) {
  trustedAttempt(first,c,1);trustedAttempt(current,c,2);
  requireValue(first.status==='completed'&&first.conclusion==='failure'&&
    Date.parse(first.updated_at)<Date.parse(current.run_started_at),'original_attempt_not_failed');
  requireValue(admitting?current.status==='in_progress'&&current.conclusion===null:
    current.status==='completed'&&current.conclusion==='success','invalid_recovery_attempt');
  requireValue(Array.isArray(history?.jobs)&&history.total_count===history.jobs.length&&history.jobs.length<100&&
    history.jobs.every(j=>j&&typeof j==='object'&&!Array.isArray(j))&&
    new Set(history.jobs.map(j=>j.id)).size===history.jobs.length,'incomplete_deployment_jobs');
  const names=new Set([...INSTALL_JOBS,'verify']);
  for(const j of history.jobs)requireValue(id(j.id)&&j.run_id===c.deploy&&j.head_sha===c.sha&&
    names.has(j.name)&&[1,2].includes(j.run_attempt)&&Array.isArray(j.steps),'untrusted_deployment_job');
  const at=(name,attempt,required=true)=>{
    const rows=history.jobs.filter(j=>j.name===name&&j.run_attempt===attempt);
    requireValue(rows.length<=1&&(!required||rows.length===1),'ambiguous_deployment_job');return rows[0]||null;
  };
  for(const name of INSTALL_JOBS) {
    const old=at(name,1),latest=at(name,2,false);
    requireValue(old.status==='completed'&&old.conclusion==='success'&&
      old.steps.length>0&&old.steps.every(s=>s.status==='completed'&&s.conclusion==='success'),'installation_unconfirmed');
    requireValue(!latest||copiedExecution(old,latest,current.run_started_at),'installation_or_source_replayed');
  }
  const old=at('verify',1),latest=at('verify',2);
  requireValue(old.status==='completed'&&old.conclusion==='failure','original_verification_not_failed');
  requireValue(admitting?latest.status==='in_progress'&&latest.conclusion===null:
    latest.status==='completed'&&latest.conclusion==='success','recovery_not_confirmed');
  const results=Object.fromEntries(Object.entries(VERIFY_STEPS).map(([key,name])=>[key,stepResult(old,name)]));
  requireValue(results.check!=='success'||results.download!=='skipped','unclassified_original_verification');
  requireValue(results.cleanup!=='success'||(results.download==='success'&&results.status==='success'),'invalid_original_verification');
  const plan={};
  for(const key of Object.keys(VERIFY_STEPS)) {
    // A successful status publisher may have recorded a failed check/copy.
    const needed=results[key]!=='success'||(['status','publication'].includes(key)&&(results.check!=='success'||results.download!=='success'))
      ||(key==='publication'&&results.status!=='success');
    plan['run_'+key]=needed;
    plan[key+'_result']=needed?'skipped':'success';
    if(!admitting)requireValue(stepResult(latest,VERIFY_STEPS[key])===(needed?'success':'skipped'),'accepted_step_replayed_or_unconfirmed');
  }
  const published=old.steps.filter(s=>typeof s.name==='string'&&s.name.startsWith(release.PENDING_BINDING_STEP)
    &&/^[0-9a-f]{64}$/.test(s.name.slice(release.PENDING_BINDING_STEP.length)));
  return {plan,smokeAttempt:results.check==='success'?1:2,
    diagnosticsAttempt:results.download==='success'?1:2,
    publicationDigest:published.length===1?published[0].name.slice(release.PENDING_BINDING_STEP.length):null};
}
async function readRecovery(get,c,admitting=false) {
  const first=await get('/actions/runs/'+c.deploy+'/attempts/1');
  const current=await get('/actions/runs/'+c.deploy+'/attempts/2');
  const history=await get('/actions/runs/'+c.deploy+'/jobs?filter=all&per_page=100');
  return deploymentHistory(history,first,current,c,admitting);
}
async function admitVerification({env,api}) {
  requireValue(env.GITHUB_REPOSITORY===REPOSITORY&&(['push','workflow_dispatch'].includes(env.GITHUB_EVENT_NAME))&&
    (env.GITHUB_EVENT_NAME==='push'||env.GITHUB_RUN_ATTEMPT==='1')&&
    env.GITHUB_REF==='refs/heads/main'&&env.GITHUB_JOB==='verify'&&/^[1-9][0-9]*$/.test(env.GITHUB_RUN_ID||'')&&
    ['1','2'].includes(env.GITHUB_RUN_ATTEMPT)&&sha(env.GITHUB_SHA)&&id(Number(env.GITHUB_RUN_ID)),'untrusted_verification_context');
  const c={sha:env.GITHUB_SHA,deploy:Number(env.GITHUB_RUN_ID)};
  const get=path=>api('GET','/repos/'+REPOSITORY+path);
  requireValue((await get('/git/ref/heads/main'))?.object?.sha===c.sha,'main_changed');
  if(env.GITHUB_RUN_ATTEMPT==='1') {
    const run=await get('/actions/runs/'+c.deploy);
    trustedAttempt(run,c,1,env.GITHUB_EVENT_NAME);
    const jobs=await get('/actions/runs/'+c.deploy+'/attempts/1/jobs?per_page=100');
    requireValue(Array.isArray(jobs?.jobs)&&jobs.total_count===jobs.jobs.length&&jobs.jobs.length<100,'incomplete_deployment_jobs');
    for(const name of INSTALL_JOBS) {
      const rows=jobs.jobs.filter(j=>j.name===name);
      requireValue(rows.length===1&&rows[0].run_id===c.deploy&&rows[0].head_sha===c.sha&&
        rows[0].status==='completed'&&rows[0].conclusion==='success','installation_unconfirmed');
    }
    return {run_check:true,run_download:true,run_status:true,run_publication:true,run_cleanup:true,
      check_result:'skipped',download_result:'skipped',status_result:'skipped',publication_result:'skipped',cleanup_result:'skipped',
      smoke_attempt:1,diagnostics_attempt:1};
  }
  const pulls=await get('/commits/'+c.sha+'/pulls?per_page=100');
  requireValue(Array.isArray(pulls)&&pulls.length<100,'incomplete_pulls');
  const merged=pulls.filter(p=>p.merge_commit_sha===c.sha&&p.state==='closed');
  requireValue(merged.length===1,'ambiguous_pull');
  const b=binding(await get('/pulls/'+merged[0].number),c.sha);
  requireValue(b!==null,'recovery_not_bound');
  requireValue(recordState(await get('/issues/comments/'+b.comment_id),b)==='active','record_not_active');
  const proof=await readRecovery(get,c,true);
  requireValue((await get('/git/ref/heads/main'))?.object?.sha===c.sha,'main_changed');
  requireValue(recordState(await get('/issues/comments/'+b.comment_id),b)==='active','record_not_active');
  return {...proof.plan,smoke_attempt:proof.smokeAttempt,diagnostics_attempt:proof.diagnosticsAttempt,
    publication_digest:proof.publicationDigest||''};
}
async function selectRecoveryRuns(snapshot,c,get) {
  if(!c.recovery)return snapshot;
  const runs=snapshot?.workflow_runs;
  requireValue(Array.isArray(runs)&&snapshot.total_count===runs.length&&runs.length<100,'incomplete_runs');
  const deployTag=attempt=>'deploy-proof:v1:'+c.deploy+':'+attempt;
  const monitors=runs.filter(r=>r.path===PREFIX+'modeled-conversion-monitor.yml'&&r.event==='workflow_run');
  const oldMonitor=monitors.filter(r=>r.display_title===deployTag(1));
  const newMonitor=monitors.filter(r=>r.display_title===deployTag(2));
  requireValue(oldMonitor.length<=1&&newMonitor.length<=1,'ambiguous_run');
  const ignored=new Set(),oldPaths=new Set();
  const known=new Set(REQUIRED.filter(r=>r[1]==='workflow_run').map(r=>PREFIX+r[0]).concat(CLOSER));
  for(const run of runs.filter(r=>r.event==='workflow_run')) {
    requireValue(known.has(run.path),'unclassified_gate');
    const activation=run.path===PREFIX+ACTIVATION.file;
    const oldTag=activation&&oldMonitor.length?'monitor-proof:v1:'+oldMonitor[0].id+':1':deployTag(1);
    const newTag=activation&&newMonitor.length?'monitor-proof:v1:'+newMonitor[0].id+':1':deployTag(2);
    requireValue(run.display_title===oldTag||run.display_title===newTag,'untrusted_gate_origin');
    if(run.display_title===oldTag) {
      requireValue(!oldPaths.has(run.path),'ambiguous_run');oldPaths.add(run.path);
      requireValue(run.run_attempt===1&&run.head_sha===c.sha&&run.head_branch==='main'&&
        run.head_repository?.full_name===REPOSITORY&&run.status==='completed'&&
        ['success','skipped'].includes(run.conclusion),'original_wave_not_skipped');
      const jobs=await get('/actions/runs/'+run.id+'/attempts/1/jobs?per_page=100');
      requireValue(Array.isArray(jobs?.jobs)&&jobs.total_count===jobs.jobs.length&&jobs.jobs.length>0&&jobs.jobs.length<100&&
        jobs.jobs.every(j=>j.run_id===run.id&&j.head_sha===c.sha&&j.status==='completed'&&
          j.conclusion==='skipped'&&Array.isArray(j.steps)&&j.steps.length===0),'original_wave_executed');
      ignored.add(run.id);
    }
  }
  const selected=runs.filter(r=>!ignored.has(r.id));
  return {...snapshot,total_count:selected.length,workflow_runs:selected};
}
function stageStatus(env) {
  requireValue(['1','2'].includes(env.SMOKE_ATTEMPT)&&['1','2'].includes(env.DIAGNOSTICS_ATTEMPT),'invalid_status_attempt');
  requireValue(sha(env.GITHUB_SHA)&&id(Number(env.GITHUB_RUN_ID))&&['1','2'].includes(env.GITHUB_RUN_ATTEMPT),'invalid_status_context');
  const outcome=(key)=>env[key+'_OUTCOME']==='success'||env[key+'_ACCEPTED']==='success'?'success':'failure';
  return {schema:2,sha:env.GITHUB_SHA,run_id:env.GITHUB_RUN_ID,install_attempt:1,
    smoke_attempt:Number(env.SMOKE_ATTEMPT),diagnostics_attempt:Number(env.DIAGNOSTICS_ATTEMPT),
    provenance:'success',key:'success',bundle:'success',sync:'success',migration:'success',
    max_webhook:'success',metrika_cron:'success',install_checks:'success',
    smoke:outcome('CHECK'),diagnostics:outcome('DOWNLOAD')};
}

function bindingSnapshot(b) {
  return {pr:b.pr,head_sha:b.head_sha,comment_id:b.comment_id,owner_id:b.owner_id,updated_at:b.updated_at};
}
function gateReferences(snapshot) {
  return REQUIRED.map(([file,event])=>{
    const rows=snapshot.workflow_runs.filter(r=>r.path===PREFIX+file&&r.event===event);
    requireValue(rows.length===1,'ambiguous_run');const r=rows[0];
    return {path:r.path,run_id:String(r.id),run_attempt:r.run_attempt,head_sha:r.head_sha,conclusion:r.conclusion};
  });
}
const NOOP_JOBS = new Map([
  ['live-session-diagnostics.yml','publish'],['max-production-health.yml','inspect'],
  ['max-tls-preflight.yml','strict-tls-preflight'],['modeled-conversion-monitor.yml','monitor'],
  ['publish-conversation-diagnostics.yml','publish'],['telegram-production-health.yml','inspect'],
  ['tour-selection-host.yml','host'],
]);
const NOOP_PREPARATION = new Set(['Resolve exact production source','Checkout exact production source',
  'Verify exact source is current main','Checkout','Verify current main before private capture',
  'Run actions/checkout@v4','Require current authoritative main before loading deployment credentials',
  release.RESOLVER_STEP,'Set up job','Complete job','Post Checkout exact production source','Post Checkout',
  'Post Run actions/checkout@v4']);
// These exact workflow/job pairs also remove local runner files. Cleanup is
// unconditional, including when no production action was admitted.
const NOOP_LOCAL_CLEANUP = new Map([
  ['publish-conversation-diagnostics.yml','Remove private capture files'],
  ['modeled-conversion-monitor.yml','Remove private ephemeral monitor data'],
]);
function onlyMetadataSteps(job,allowed,required) {
  requireValue(Array.isArray(job.steps)&&job.steps.length>0&&job.steps.length<100
    &&new Set(job.steps.map(s=>s.number)).size===job.steps.length,'incomplete_noop_steps');
  for(const name of required)requireValue(job.steps.filter(s=>s.name===name&&s.status==='completed'&&s.conclusion==='success').length===1,
    'noop_resolution_unconfirmed');
  for(const step of job.steps)requireValue(step.status==='completed'
    &&(allowed.has(step.name)?['success','skipped'].includes(step.conclusion):step.conclusion==='skipped'),
    'unchanged_operation_executed');
}
async function verifyUnchangedJobs(api,snapshot,c) {
  const get=path=>api('GET','/repos/'+REPOSITORY+path);
  for(const [file,name]of NOOP_JOBS){
    const run=snapshot.workflow_runs.find(r=>r.path===PREFIX+file&&r.event==='workflow_run');
    requireValue(run,'noop_workflow_missing');
    const jobs=release.boundedList(await get('/actions/runs/'+run.id+'/attempts/1/jobs?per_page=100'),'jobs','incomplete_noop_jobs');
    requireValue(jobs.length===(file==='tour-selection-host.yml'?2:1),'unknown_noop_job');
    const job=jobs.find(j=>j.name===name);
    requireValue(job&&job.run_id===run.id&&job.head_sha===c.sha&&job.status==='completed'
      &&job.conclusion==='success','noop_job_unconfirmed');
    const cleanup=NOOP_LOCAL_CLEANUP.get(file);
    const allowed=new Set(NOOP_PREPARATION),required=[release.RESOLVER_STEP];
    if(cleanup){allowed.add(cleanup);required.push(cleanup);}
    onlyMetadataSteps(job,allowed,required);
    if(file==='tour-selection-host.yml'){
      const editor=jobs.find(j=>j.name==='independent-editor');
      requireValue(editor&&editor.run_id===run.id&&editor.head_sha===c.sha&&editor.status==='completed'
        &&editor.conclusion==='skipped'&&Array.isArray(editor.steps)&&editor.steps.length===0,'independent_editor_executed');
    }
  }
  const jobs=release.boundedList(await get('/actions/runs/'+c.deploy+'/attempts/1/jobs?per_page=100'),'jobs','incomplete_noop_jobs');
  requireValue(jobs.length===INSTALL_JOBS.length+1&&jobs.every(j=>[...INSTALL_JOBS,'verify'].includes(j.name)),'unknown_noop_job');
  const deployment=jobs.find(j=>j.name==='deploy'),verification=jobs.find(j=>j.name==='verify');
  for(const j of [deployment,verification])requireValue(j?.run_id===c.deploy&&j.head_sha===c.sha
    &&j.status==='completed'&&j.conclusion==='success','noop_job_unconfirmed');
  const deployAllowed=new Set(['Set up job','Complete job','Post Checkout exact deployment SHA',
    'Require the complete deployment verification','Checkout exact deployment SHA','Verify deployment SHA is current main',
    'Resolve original accepted installation and complete release intent','Prepare canonical SSH private key',
    'Select installation or prove the unchanged application without mutation','Fail failed installation']);
  onlyMetadataSteps(deployment,deployAllowed,['Select installation or prove the unchanged application without mutation']);
  const verificationAllowed=new Set(['Set up job','Complete job','Post Checkout exact installed verification SHA',
    'Checkout exact installed verification SHA','Publish the proven unchanged result without installed stages',
    'Require complete installed verification']);
  const publications=verification.steps.filter(s=>typeof s.name==='string'&&s.name.startsWith(release.PENDING_BINDING_STEP)
    &&/^[0-9a-f]{64}$/.test(s.name.slice(release.PENDING_BINDING_STEP.length)));
  requireValue(publications.length===1,'publication_step_unconfirmed');verificationAllowed.add(publications[0].name);
  onlyMetadataSteps(verification,verificationAllowed,['Publish the proven unchanged result without installed stages',publications[0].name]);
}
async function prepareRecord(api,location,c,b,snapshot) {
  const record=release.clone(location.record),r=record.repository_release;
  r.phase='pending_terminal';r.release.run_attempt=c.deployAttempt||1;
  r.closure={run_id:String(c.run),run_attempt:1,job:'release-slot',terminal_step:release.TERMINAL_STEP,
    binding:bindingSnapshot(b),expected_terminal:release.terminalBody(r.kind,b.pr),gates:gateReferences(snapshot)};
  if(r.kind==='installed'){
    const host=snapshot.workflow_runs.find(x=>x.path===release.HOST&&x.event==='workflow_run');
    const observed=await release.hostObservation(api,host,c.sha);
    requireValue(observed.observation.status!=='blocked','installed_runtime_contradiction');
    r.observation=observed.observation;r.observation_producer=observed.producer;
    r.baseline_eligible=r.release.event==='push'&&r.intent_scan.status==='no_registered_intent'
      &&r.observation.status==='known'&&c.configure==='skipped'&&!c.activation;
  }
  release.validateRelease(record);return record;
}
const PUBLIC_DIAGNOSTIC_JSON = new Set(['deploy_status.json','ops_status.json','autopilot_snapshot.json',
  'architecture_inventory.json','public_live_status.json','public_daily_status.json']);
function diagnosticsTree(entries,record) {
  requireValue(Array.isArray(entries)&&entries.length<10000&&new Set(entries.map(e=>e.path)).size===entries.length,
    'invalid_diagnostics_tree');
  const edits=[];let existing=false;
  for(const e of entries){
    requireValue(e&&typeof e.path==='string'&&e.path.length>0&&!e.path.includes('/')&&!/[\r\n\\]/.test(e.path)
      &&['blob','tree','commit'].includes(e.type)&&sha(e.sha),'invalid_diagnostics_tree');
    if(e.path==='deploy_status.json'){
      requireValue(e.type==='blob'&&e.mode==='100644','unsafe_status_entry');existing=true;continue;
    }
    // Preserve the exact old privacy allowlist: no copied raw capture, root
    // unknown JSON, production-diagnostics directory or legacy migration log.
    if(e.path==='production-diagnostics'||e.path==='migration.log'
      ||(e.type==='blob'&&e.path.endsWith('.json')&&!PUBLIC_DIAGNOSTIC_JSON.has(e.path)))
      edits.push({path:e.path,mode:e.mode,type:e.type,sha:null});
  }
  requireValue(existing,'installation_record_missing');
  edits.push({path:'deploy_status.json',mode:'100644',type:'blob',content:release.wire(record)});return edits;
}
async function publishDiagnostics(api,record,expected=null) {
  release.validateRelease(record,false);
  const endpoint='/repos/'+REPOSITORY;
  const previous=await release.readRecord(api);
  if(expected)requireValue(previous.blob===expected.blob&&previous.record_digest===expected.record_digest,
    'diagnostics_compare_conflict');
  const commit=await api('GET',endpoint+'/git/commits/'+previous.commit);
  requireValue(commit?.sha===previous.commit&&sha(commit.tree?.sha),'untrusted_diagnostics_commit');
  const root=await api('GET',endpoint+'/git/trees/'+commit.tree.sha);
  requireValue(root?.sha===commit.tree.sha&&root.truncated===false,'incomplete_diagnostics_tree');
  const edits=diagnosticsTree(root.tree,record);
  const tree=await api('POST',endpoint+'/git/trees',{base_tree:commit.tree.sha,tree:edits});
  requireValue(sha(tree?.sha),'diagnostics_tree_write_unknown');
  const written=await api('POST',endpoint+'/git/commits',{message:'Record the exact production release result',
    tree:tree.sha,parents:[previous.commit]});
  requireValue(sha(written?.sha),'diagnostics_commit_write_unknown');
  // One non-force ref update. A concurrent publisher holds the slot; no retry,
  // rebase or user-content overwrite is silently performed.
  let updated;
  try{updated=await api('PATCH',endpoint+'/git/refs/heads/diagnostics',{sha:written.sha,force:false});}
  catch{throw new Refusal('diagnostics_write_unknown');}
  requireValue(updated?.object?.sha===written.sha,'diagnostics_write_unknown');
  const exact=await release.readRecord(api,written.sha);
  requireValue(release.same(exact.record,record),'diagnostics_write_unknown');
  const fresh=await release.readRecord(api);
  requireValue(fresh.blob===exact.blob&&fresh.record_digest===exact.record_digest,'diagnostics_write_unknown');
  await release.requireAncestry(api,written.sha,fresh.commit);
  return exact;
}
async function publishPending({env,event,api,record,proofFile,repository=process.cwd()}) {
  const c=await release.sourceContext(env,event,api,release.DEPLOY);
  requireValue(c.job==='verify'&&['push','workflow_dispatch'].includes(c.event),'untrusted_publisher_context');
  const {release:r,installation}=release.validateRelease(record,false);
  requireValue(r.phase==='pending_closure'&&r.repository_sha===c.sha&&r.release.run_id===c.id
    &&r.release.run_attempt===c.attempt&&r.release.event===c.event,'publisher_result_mismatch');
  const previous=await release.readRecord(api);
  if(r.kind==='application_unchanged'){
    requireValue(c.attempt===1&&c.event==='push'&&event.forced===false,'untrusted_unchanged_publisher');
    await release.requireCurrentBinding(api,c.sha);
    const baseline=await release.readBaseline(api,r.baseline,previous.commit);
    requireValue(release.same(baseline.installation,installation)
      &&release.same(release.installationReceipt(previous.record),installation),'installation_receipt_changed');
    requireValue(release.compareObservations(baseline.release.observation,r.observation).kind==='application_unchanged',
      'runtime_observation_changed');
    const preview=release.v2(repository,installation,c,event);
    requireValue(preview.decision==='no_install_candidate'
      &&release.same(release.contentSummary(preview.comparison,installation.sha,c.sha),r.content)
      &&release.same(release.intentScan(repository,installation.sha,c.sha),r.intent_scan),'content_plan_changed');
    requireValue(String(r.observation_producer.run_id)===c.id&&r.observation_producer.run_attempt===1,
      'runtime_observer_mismatch');
  }else{
    // This admission preserves the original negative schema-2 status as well.
    // Success of the writer is never success of its failed check/download.
    await admitVerification({env,api});
  }
  const location=await publishDiagnostics(api,record,previous);
  const proof=release.publicationProof(record,location,c.id,c.attempt);
  if(proofFile)release.saveJson(proofFile,proof);
  return {publication_artifact:release.publicationName('pending_closure',c.id,c.attempt),
    publication_file:proofFile||'',record_digest:release.digest(record)};
}
async function resumePublication({env,event,api,proofFile}) {
  const c=await release.sourceContext(env,event,api,release.DEPLOY);
  requireValue(c.job==='verify'&&c.attempt===2&&c.event==='push','untrusted_publication_recovery');
  const plan=await admitVerification({env,api});
  requireValue(plan.run_status===false&&plan.run_publication===true,'publication_recovery_not_needed');
  const location=await release.readRecord(api),r=release.validateRelease(location.record).release;
  requireValue(r.kind==='installed'&&r.phase==='pending_closure'&&r.repository_sha===c.sha
    &&r.release.run_id===c.id&&r.release.run_attempt===1&&/^[0-9a-f]{64}$/.test(plan.publication_digest||'')
    &&location.record_digest===plan.publication_digest,'publication_recovery_source_changed');
  const proof=release.publicationProof(location.record,location,c.id,2);
  if(proofFile)release.saveJson(proofFile,proof);
  return {publication_artifact:release.publicationName('pending_closure',c.id,2),
    publication_file:proofFile||'',record_digest:location.record_digest};
}

function makeApi(token, fetchImpl = globalThis.fetch) {
  requireValue(typeof token === 'string' && token.length > 0 && typeof fetchImpl === 'function', 'missing_capability');
  const read=release.makeReadApi(token,fetchImpl);
  const api=async (method, path, body, raw = false) => {
    if(method==='GET')return read(method,path,body,raw);
    requireValue(['POST', 'PATCH'].includes(method) && path.startsWith('/repos/' + REPOSITORY + '/')
      && !/[\r\n\\]/.test(path), 'invalid_request');
    const comment=/^\/repos\/pyatkoff\/max-search-bot\/issues\/comments\/[1-9][0-9]*$/.test(path);
    const reference=path==='/repos/'+REPOSITORY+'/git/refs/heads/diagnostics';
    requireValue((method==='PATCH'&&((comment&&release.keys(body,['body']))
      ||(reference&&release.keys(body,['sha','force'])&&sha(body.sha)&&body.force===false)))
      ||(method==='POST'&&['/git/trees','/git/commits'].some(p=>path==='/repos/'+REPOSITORY+p)), 'invalid_write_target');
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetchImpl('https://api.github.com' + path, {
        method, redirect: 'error', signal: controller.signal,
        headers: { Authorization: 'Bearer ' + token, Accept: raw ? 'application/vnd.github.raw+json' : 'application/vnd.github+json',
          'Content-Type': 'application/json', 'X-GitHub-Api-Version': '2022-11-28' },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      });
      requireValue(response.ok, 'api_http_' + (Number.isInteger(response.status) ? response.status : 'unknown'));
      try { return release.parseJson(await release.responseBytes(response,release.MAX_API),release.MAX_API); }
      catch(error) { if(error instanceof release.Refusal)throw error;throw new Refusal('invalid_response'); }
    } catch (error) {
      if (error instanceof Refusal) throw error;
      throw new Refusal('transport_failed');
    } finally { clearTimeout(timer); }
  };
  api.artifact=read.artifact;return api;
}
module.exports = { REQUIRED, CLOSER, ACTIVATION, context, binding, gateState, recordState, verifyTelemetry,
  verifyConfigure, verifyActivation, closeRelease, makeApi, INSTALL_JOBS, VERIFY_STEPS, stepResult, copiedExecution,
  deploymentHistory, admitVerification, selectRecoveryRuns, selectReleaseRuns, stageStatus, bindingSnapshot, gateReferences,
  onlyMetadataSteps,verifyUnchangedJobs,prepareRecord,PUBLIC_DIAGNOSTIC_JSON,diagnosticsTree,publishDiagnostics,
  publishPending,resumePublication };
if (typeof require === 'function' && require.main === module) {
  (async () => {
    const mode=process.argv.slice(2);
    if(mode.length) {
      if(['--publish-pending','--resume-publication','--prepare-closure'].includes(mode[0])){
        const args=release.argumentsOf(mode,[mode[0]]),env=process.env,event=release.readJson(env.GITHUB_EVENT_PATH),
          api=makeApi(env.GITHUB_TOKEN);
        requireValue(release.keys(args,mode[0]==='--publish-pending'?['command','record','proof']:['command','proof']),'invalid_arguments');
        const result=mode[0]==='--publish-pending'
          ?await publishPending({env,event,api,record:release.readJson(args.record),proofFile:args.proof})
          :mode[0]==='--resume-publication'?await resumePublication({env,event,api,proofFile:args.proof})
          :await closeRelease({env,event,api,sleep:ms=>new Promise(resolve=>setTimeout(resolve,ms)),prepare:true,proofFile:args.proof});
        release.outputs(typeof result==='string'?{release_slot:result,publication_artifact:'',publication_file:'',record_digest:''}:result);return;
      }
      requireValue(mode.length===1&&['--admit-verification','--stage-status'].includes(mode[0]),'invalid_mode');
      const result=mode[0]==='--stage-status'?stageStatus(process.env):await admitVerification({env:process.env,api:makeApi(process.env.GITHUB_TOKEN)});
      if(mode[0]==='--stage-status')process.stdout.write(JSON.stringify(result)+'\n');
      else for(const [key,value] of Object.entries(result))process.stdout.write(key+'='+String(value)+'\n');
      return;
    }
    const api=makeApi(process.env.GITHUB_TOKEN);
    requireValue(/^[0-9a-f]{64}$/.test(process.env.PREPARED_RECORD_DIGEST||''),'prepared_result_missing');
    let event;
    try { event = JSON.parse(require('node:fs').readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8')); }
    catch { throw new Refusal('invalid_event'); }
    const result = await closeRelease({ env: process.env, event, api,
      sleep: ms => new Promise(resolve => setTimeout(resolve, ms)) });
    process.stdout.write('release_slot=' + result + '\n');
  })().catch(error => {
    process.stderr.write('release_slot=held reason=' + (error instanceof Refusal||error instanceof release.Refusal ? error.code : 'internal_failure') + '\n');
    process.exitCode = 1;
  });
}
