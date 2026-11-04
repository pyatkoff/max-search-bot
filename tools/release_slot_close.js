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
    && run.conclusion === 'success' && run.run_attempt === 1 && id(run.id)
    && sha(run.head_sha) && env.GITHUB_SHA === run.head_sha
    && run.head_commit?.id === run.head_sha && typeof run.head_commit.message === 'string'
    && run.head_commit.message.length <= 65536
    && ['success', 'skipped'].includes(env.CONFIGURE_RESULT), 'untrusted_context');
  return { sha: run.head_sha, deploy: run.id, run: Number(env.GITHUB_RUN_ID),
    configure: env.CONFIGURE_RESULT, activation: run.head_commit.message.includes(ACTIVATION.marker) };
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
    && run.run_attempt === 1 && stamp(run.created_at), 'untrusted_run');
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
    const state = gateState(await get('/actions/runs?head_sha=' + c.sha + '&per_page=100'), c);
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
  const finalRuns = await get('/actions/runs?head_sha=' + c.sha + '&per_page=100');
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
  verifyConfigure, verifyActivation, closeRelease, makeApi };
if (typeof require === 'function' && require.main === module) {
  (async () => {
    const api = makeApi(process.env.GITHUB_TOKEN);
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
