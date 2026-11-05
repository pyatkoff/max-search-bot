#!/usr/bin/env node
'use strict';

// Metadata-only terminal step of the existing post-deploy workflow.
// Never deploys, dispatches checks, creates records, or retries a write.
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
function validRun(run, c, path, event, name) {
  requireValue(id(run?.id) && run.path === path && run.event === event
    && run.name === name && run.head_sha === c.sha && run.head_branch === 'main'
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
  for (const [file, event, name] of REQUIRED) {
    const path = PREFIX + file;
    // A rerun/manual deployment of this source makes provenance ambiguous.
    const candidates = runs.filter(r => r.path === path
      && (file === 'deploy.yml' || r.event === event));
    requireValue(candidates.length <= 1, 'ambiguous_run');
    if (!candidates.length) { waiting = true; continue; }
    const run = candidates[0];
    validRun(run, c, path, event, name);
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
  validRun(own[0], c, CLOSER, 'workflow_run', 'Configure AnyTour modeled value goal');
  requireValue(own[0].status === 'in_progress' && own[0].conclusion === null, 'closer_not_active');
  return waiting ? 'waiting' : 'ready';
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
function verifyActivation(jobs, run, c) {
  validRun(run, c, PREFIX + ACTIVATION.file, 'workflow_run', ACTIVATION.name);
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
async function closeRelease({ env, event, api, sleep, now = Date.now }) {
  const c = context(env, event);
  const endpoint = path => '/repos/' + REPOSITORY + path;
  const get = path => api('GET', endpoint(path));
  const main = async () => {
    requireValue((await get('/git/ref/heads/main'))?.object?.sha === c.sha, 'main_changed');
  };
  await main();
  if(c.deployAttempt===2)c.recovery=await readRecovery(get,c);
  const snapshot=async()=>selectRecoveryRuns(await get('/actions/runs?head_sha='+c.sha+'&per_page=100'),c,get);
  const pulls = await get('/commits/' + c.sha + '/pulls?per_page=100');
  requireValue(Array.isArray(pulls) && pulls.length < 100, 'incomplete_pulls');
  const merged = pulls.filter(p => p.merge_commit_sha === c.sha && p.state === 'closed');
  requireValue(merged.length === 1, 'ambiguous_pull');
  const pr = await get('/pulls/' + merged[0].number);
  const b = binding(pr, c.sha);
  if (!b) return 'not_opted_in';
  const recordPath = '/issues/comments/' + b.comment_id;
  if (recordState(await get(recordPath), b) === 'released') return 'already_released';
  const deadline = now() + 18 * 60 * 1000;
  while (true) {
    await main();
    const state = gateState(await snapshot(), c);
    if (state === 'ready') break;
    requireValue(now() < deadline, 'gate_deadline');
    await sleep(Math.min(20000, deadline - now()));
  }
  verifyConfigure(await get('/actions/runs/' + c.run + '/attempts/1/jobs?per_page=100'), c);
  // Read the existing exact installation evidence; do not execute any probes.
  const deploy = await api('GET', endpoint('/contents/deploy_status.json?ref=diagnostics'), undefined, true);
  const ops = await api('GET', endpoint('/contents/ops_status.json?ref=diagnostics'), undefined, true);
  verifyTelemetry(deploy, ops, c);
  // Fresh admission to update this one record, never another owner's slot.
  const current = binding(await get('/pulls/' + b.pr), c.sha);
  requireValue(JSON.stringify(current) === JSON.stringify(b), 'binding_changed');
  await main();
  if(c.recovery)c.recovery=await readRecovery(get,c);
  const finalRuns = await snapshot();
  requireValue(gateState(finalRuns, c) === 'ready', 'gates_changed');
  const activation = finalRuns.workflow_runs.find(r => r.path === PREFIX + ACTIVATION.file
    && r.event === 'workflow_run');
  verifyActivation(await get('/actions/runs/' + activation.id + '/attempts/1/jobs?per_page=100'), activation, c);
  if (recordState(await get(recordPath), b) === 'released') return 'already_released';
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
  status:'Publish verification stage status',cleanup:'Remove acknowledged diagnostic snapshot'};
function stepResult(job,name) {
  requireValue(job && Array.isArray(job.steps),'incomplete_job_steps');
  const rows=job.steps.filter(s=>s?.name===name);
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
  requireValue(results.cleanup!=='success'||(results.download==='success'&&results.status==='success'),'invalid_original_verification');
  const plan={};
  for(const key of Object.keys(VERIFY_STEPS)) {
    // A successful status publisher may have recorded a failed check/copy.
    const needed=results[key]!=='success'||(key==='status'&&(results.check!=='success'||results.download!=='success'));
    plan['run_'+key]=needed;
    plan[key+'_result']=needed?'skipped':'success';
    if(!admitting)requireValue(stepResult(latest,VERIFY_STEPS[key])===(needed?'success':'skipped'),'accepted_step_replayed_or_unconfirmed');
  }
  return {plan,smokeAttempt:results.check==='success'?1:2,
    diagnosticsAttempt:results.download==='success'?1:2};
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
    env.GITHUB_REF==='refs/heads/main'&&env.GITHUB_JOB==='verify'&&
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
    return {run_check:true,run_download:true,run_status:true,run_cleanup:true,
      check_result:'skipped',download_result:'skipped',status_result:'skipped',cleanup_result:'skipped',
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
  return {...proof.plan,smoke_attempt:proof.smokeAttempt,diagnostics_attempt:proof.diagnosticsAttempt};
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
  requireValue(sha(env.GITHUB_SHA)&&id(Number(env.GITHUB_RUN_ID))&&['1','2'].includes(env.GITHUB_RUN_ATTEMPT),'invalid_status_context');
  const outcome=(key)=>env[key+'_OUTCOME']==='success'||env[key+'_ACCEPTED']==='success'?'success':'failure';
  return {schema:2,sha:env.GITHUB_SHA,run_id:env.GITHUB_RUN_ID,install_attempt:1,
    smoke_attempt:Number(env.SMOKE_ATTEMPT),diagnostics_attempt:Number(env.DIAGNOSTICS_ATTEMPT),
    provenance:'success',key:'success',bundle:'success',sync:'success',migration:'success',
    max_webhook:'success',metrika_cron:'success',install_checks:'success',
    smoke:outcome('CHECK'),diagnostics:outcome('DOWNLOAD')};
}

function makeApi(token, fetchImpl = globalThis.fetch) {
  requireValue(typeof token === 'string' && token.length > 0 && typeof fetchImpl === 'function', 'missing_capability');
  return async (method, path, body, raw = false) => {
    requireValue(['GET', 'PATCH'].includes(method) && path.startsWith('/repos/' + REPOSITORY + '/')
      && !/[\r\n\\]/.test(path), 'invalid_request');
    requireValue(method !== 'PATCH' || /^\/repos\/pyatkoff\/max-search-bot\/issues\/comments\/[1-9][0-9]*$/.test(path),
      'invalid_write_target');
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
      try { return await response.json(); } catch { throw new Refusal('invalid_response'); }
    } catch (error) {
      if (error instanceof Refusal) throw error;
      throw new Refusal('transport_failed');
    } finally { clearTimeout(timer); }
  };
}
module.exports = { REQUIRED, CLOSER, ACTIVATION, context, binding, gateState, recordState, verifyTelemetry,
  verifyConfigure, verifyActivation, closeRelease, makeApi, INSTALL_JOBS, VERIFY_STEPS, stepResult, copiedExecution,
  deploymentHistory, admitVerification, selectRecoveryRuns, stageStatus };
if (typeof require === 'function' && require.main === module) {
  (async () => {
    const mode=process.argv.slice(2);
    if(mode.length) {
      requireValue(mode.length===1&&['--admit-verification','--stage-status'].includes(mode[0]),'invalid_mode');
      const result=mode[0]==='--stage-status'?stageStatus(process.env):await admitVerification({env:process.env,api:makeApi(process.env.GITHUB_TOKEN)});
      if(mode[0]==='--stage-status')process.stdout.write(JSON.stringify(result)+'\n');
      else for(const [key,value] of Object.entries(result))process.stdout.write(key+'='+String(value)+'\n');
      return;
    }
    const api=makeApi(process.env.GITHUB_TOKEN);
    let event;
    try { event = JSON.parse(require('node:fs').readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8')); }
    catch { throw new Refusal('invalid_event'); }
    const result = await closeRelease({ env: process.env, event, api,
      sleep: ms => new Promise(resolve => setTimeout(resolve, ms)) });
    process.stdout.write('release_slot=' + result + '\n');
  })().catch(error => {
    process.stderr.write('release_slot=held reason=' + (error instanceof Refusal ? error.code : 'internal_failure') + '\n');
    process.exitCode = 1;
  });
}
