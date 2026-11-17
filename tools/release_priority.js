'use strict';

// One bounded reservation in existing PR metadata. This is not a deployment
// lease: the normal publisher, required checks and expected-head CAS still apply.
const MAX_WINDOW_MS = 30 * 60 * 1000;
const MAX_PAGES = 3;
const TRUSTED = new Set(['OWNER', 'MEMBER', 'COLLABORATOR']);
const SHA = /^[0-9a-f]{40}$/;
const REPO = /^[A-Za-z0-9][A-Za-z0-9-]*\/[A-Za-z0-9_.-]+$/;
const validRepository = value => typeof value === 'string' && REPO.test(value) && !['.', '..'].includes(value.split('/')[1]);
const ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

function refusal(code, pr) {
  const error = new Error('release-priority: ' + code + (pr ? ' PR#' + pr : ''));
  error.code = code;
  error.isReleasePriorityRefusal = true;
  throw error;
}

function claimFor(pr, repository, baseSha, now) {
  if (!pr || pr.state !== 'open' || pr.draft || pr.base?.ref !== 'main' ||
      !TRUSTED.has(pr.author_association) ||
      String(pr.head?.repo?.full_name).toLowerCase() !== repository.toLowerCase()) return null;
  const body = String(pr.body || '');
  const prefixes = body.match(/<!--\s*anytour-release-priority:/g) || [];
  if (!prefixes.length) return null;
  const matches = Array.from(body.matchAll(/<!--\s*anytour-release-priority:v(\d+)\s+([\s\S]*?)-->/g));
  if (prefixes.length !== 1 || matches.length !== 1 || matches[0][1] !== '1') refusal('invalid_marker', pr.number);
  let claim;
  try { claim = JSON.parse(matches[0][2]); } catch { refusal('invalid_json', pr.number); }
  if (!claim || typeof claim !== 'object' || Array.isArray(claim)) refusal('invalid_claim', pr.number);
  const allowed = new Set(['head', 'base', 'started_at', 'expires_at', 'kind', 'incident']);
  if (Object.keys(claim).some(key => !allowed.has(key)) ||
      typeof claim.head !== 'string' || !SHA.test(claim.head) ||
      typeof claim.base !== 'string' || !SHA.test(claim.base) ||
      typeof claim.started_at !== 'string' || !ISO.test(claim.started_at) ||
      typeof claim.expires_at !== 'string' || !ISO.test(claim.expires_at) ||
      !['product', 'incident'].includes(claim.kind)) refusal('invalid_fields', pr.number);
  const start = Date.parse(claim.started_at), end = Date.parse(claim.expires_at);
  if (!Number.isFinite(start) || !Number.isFinite(end) ||
      new Date(start).toISOString() !== claim.started_at.replace('Z', '.000Z') ||
      new Date(end).toISOString() !== claim.expires_at.replace('Z', '.000Z') ||
      end <= start || end - start > MAX_WINDOW_MS || start > now) {
    refusal('invalid_window', pr.number);
  }
  if (claim.kind === 'incident') {
    const prefix = 'https://github.com/' + repository + '/issues/';
    if (typeof claim.incident !== 'string' || !claim.incident.startsWith(prefix) ||
        !/^[1-9]\d*$/.test(claim.incident.slice(prefix.length))) refusal('incident_reference_required', pr.number);
  } else if (claim.incident !== undefined) refusal('unexpected_incident_reference', pr.number);
  // An updated head, an expired reservation or a draft releases priority.
  // Neither a new heartbeat nor a failed test extends this window.
  if (claim.head !== pr.head?.sha || claim.base !== baseSha || end <= now) return null;
  return { number: pr.number, kind: claim.kind, head: claim.head, start, end };
}

function decidePriority(pulls, context) {
  const { repository, prNumber, headSha, baseSha, now } = context || {};
  if (!validRepository(repository) ||
      !Number.isSafeInteger(prNumber) || prNumber < 1 || typeof headSha !== 'string' || !SHA.test(headSha) ||
      typeof baseSha !== 'string' || !SHA.test(baseSha) ||
      !Number.isFinite(now) || !Array.isArray(pulls)) refusal('invalid_context');
  const ids = new Set();
  for (const pr of pulls) {
    if (!Number.isSafeInteger(pr?.number) || ids.has(pr.number)) refusal('ambiguous_snapshot');
    ids.add(pr.number);
  }
  const target = pulls.find(pr => pr.number === prNumber);
  if (!target || target.state !== 'open' || target.base?.ref !== 'main' ||
      target.head?.sha !== headSha || target.base?.sha !== baseSha) refusal('superseded_target', prNumber);
  const claims = pulls.map(pr => claimFor(pr, repository, baseSha, now)).filter(Boolean)
    .sort((a, b) => Number(a.kind !== 'incident') - Number(b.kind !== 'incident') ||
      a.start - b.start || a.number - b.number);
  const holder = claims[0];
  return {
    allowed: !holder || holder.number === prNumber,
    reason: !holder ? 'no_active_reservation' :
      holder.number === prNumber ? 'priority_owned' : 'earlier_ready_release',
    holder_pr: holder?.number || null,
    expires_at: holder ? new Date(holder.end).toISOString() : null,
    active_reservations: claims.length
  };
}

async function readJson(repository, resource, token, fetchImpl, raw = false) {
  if (!token || typeof fetchImpl !== 'function') refusal('missing_read_capability');
  const controller = new AbortController();
  const deadline = setTimeout(() => controller.abort(), 10000);
  try {
    const response = await fetchImpl('https://api.github.com/repos/' + repository + '/' + resource, {
      headers: { Accept: raw ? 'application/vnd.github.raw+json' : 'application/vnd.github+json', Authorization: 'Bearer ' + token,
        'X-GitHub-Api-Version': '2022-11-28' },
      redirect: 'error', signal: controller.signal
    });
    if (!response?.ok) refusal('api_http_' + Number(response?.status || 0));
    try { return await response.json(); } catch { refusal('invalid_api_response'); }
  } catch (error) {
    if (error?.isReleasePriorityRefusal) throw error;
    refusal('read_failed');
  } finally { clearTimeout(deadline); }
}

async function readOpenPulls(repository, token, fetchImpl) {
  const pulls = [];
  for (let page = 1; page <= MAX_PAGES; page++) {
    const batch = await readJson(repository,
      'pulls?state=open&base=main&sort=created&direction=desc&per_page=100&page=' + page, token, fetchImpl);
    if (!Array.isArray(batch) || batch.length > 100) refusal('invalid_api_response');
    pulls.push(...batch);
    if (batch.length < 100) return pulls;
  }
  refusal('snapshot_limit');
}

function pinnedPublisher(value, now) {
  if (!value || typeof value !== 'object' || Array.isArray(value) ||
      Object.keys(value).sort().join(',') !== 'comment_id,owner_id,updated_at' ||
      !Number.isSafeInteger(value.comment_id) || value.comment_id < 1 ||
      !Number.isSafeInteger(value.owner_id) || value.owner_id < 1 ||
      typeof value.updated_at !== 'string' || !ISO.test(value.updated_at)) refusal('invalid_publisher_context');
  const acquired = Date.parse(value.updated_at);
  if (!Number.isFinite(acquired) || acquired > now ||
      new Date(acquired).toISOString() !== value.updated_at.replace('Z', '.000Z')) refusal('invalid_publisher_context');
  return acquired;
}

function verifyPublisherBinding(pr, context, expected) {
  if (!pr || pr.number !== context.prNumber || pr.state !== 'open' || pr.draft !== false || !TRUSTED.has(pr.author_association) ||
      pr.base?.ref !== 'main' || pr.base?.sha !== context.baseSha || pr.head?.sha !== context.headSha ||
      String(pr.head?.repo?.full_name).toLowerCase() !== context.repository.toLowerCase() ||
      typeof pr.body !== 'string' || pr.body.length > 65536) refusal('invalid_publisher_binding');
  const prefixes = pr.body.match(/<!--\s*release-slot-close:/g) || [];
  const matches = Array.from(pr.body.matchAll(/<!--\s*release-slot-close:v1\s+([\s\S]*?)-->/g));
  if (prefixes.length !== 1 || matches.length !== 1) refusal('invalid_publisher_binding');
  let value;
  try { value = JSON.parse(matches[0][1]); } catch { refusal('invalid_publisher_binding'); }
  if (!value || typeof value !== 'object' || Array.isArray(value) ||
      Object.keys(value).sort().join(',') !== 'comment_id,head_sha,owner_id,updated_at' ||
      value.comment_id !== expected.comment_id || value.owner_id !== expected.owner_id ||
      value.updated_at !== expected.updated_at || value.head_sha !== context.headSha) refusal('invalid_publisher_binding');
}

function verifyPublisherRecord(record, context, expected) {
  if (record?.id !== expected.comment_id || record.user?.id !== expected.owner_id || !TRUSTED.has(record.author_association) ||
      record.updated_at !== expected.updated_at ||
      record.issue_url !== 'https://api.github.com/repos/' + context.repository + '/issues/1122' ||
      record.body !== 'PR' + context.prNumber + ': выпуск занят') refusal('publisher_changed');
}

async function continuePublisher(pulls, context, expected, token, fetchImpl) {
  const acquired = pinnedPublisher(expected, context.now);
  // Validate the complete snapshot before excluding any later reservation.
  decidePriority(pulls, context);
  verifyPublisherBinding(pulls.find(pr => pr.number === context.prNumber), context, expected);
  const resource = 'issues/comments/' + expected.comment_id;
  verifyPublisherRecord(await readJson(context.repository, resource, token, fetchImpl), context, expected);
  const freshTarget = await readJson(context.repository, 'pulls/' + context.prNumber, token, fetchImpl);
  verifyPublisherBinding(freshTarget, context, expected);
  const currentPulls = pulls.map(pr => pr.number === context.prNumber ? freshTarget : pr);
  const original = decidePriority(currentPulls, context);
  const main = await readJson(context.repository, 'git/ref/heads/main', token, fetchImpl);
  if (main?.object?.sha !== context.baseSha) refusal('base_changed');
  verifyPublisherRecord(await readJson(context.repository, resource, token, fetchImpl), context, expected);
  let ignored = 0;
  const eligible = currentPulls.filter(pr => {
    const claim = claimFor(pr, context.repository, context.baseSha, context.now);
    if (pr.number !== context.prNumber && claim && claim.start > acquired) { ignored++; return false; }
    return true;
  });
  const decision = decidePriority(eligible, context);
  return { ...decision, reason: decision.allowed ? 'active_publisher' : decision.reason,
    active_reservations: original.active_reservations, ignored_later_reservations: ignored,
    publisher_comment: expected.comment_id, publisher_acquired_at: expected.updated_at };
}

// Final admission alone authenticates the previous technical release. Ordinary
// source CI must remain independent of an unrelated production wait.
async function verifyPreviousRelease(context, token, fetchImpl) {
  const R = require('./deployment_release');
  const C = require('./release_slot_close');
  if (context.repository !== R.REPOSITORY) refusal('unsupported_release_repository');
  const api = async (method, resource, body, raw = false) => {
    if (method !== 'GET' || body !== undefined || !resource.startsWith(R.API_ROOT + '/'))
      refusal('invalid_release_read');
    return readJson(context.repository, resource.slice(R.API_ROOT.length + 1), token, fetchImpl, raw);
  };
  // Reuse the canonical pinned-log reader and its bounded signed-URL policy.
  // Final admission is a single fresh read: it never retries temporary failures.
  const logReader = R.makeReadApi(token, fetchImpl);
  logReader.transientReads = false;
  api.jobLog = logReader.jobLog;
  const runs = R.boundedList(await api('GET', R.API_ROOT +
    '/actions/workflows/deploy.yml/runs?head_sha=' + context.baseSha + '&branch=main&per_page=100'),
    'workflow_runs', 'incomplete_previous_deployments');
  if (runs.length !== 1) refusal('ambiguous_previous_deployment');
  const native = runs[0];
  R.runIdentity(native, { id: native.id, attempt: native.run_attempt, head: context.baseSha,
    workflow: R.DEPLOY, event: 'push' });
  if (![1, 2].includes(native.run_attempt)) refusal('untrusted_previous_deployment');
  const location = await R.readRecord(api);
  const r = R.validateRelease(location.record).release;
  if (r.repository_sha !== context.baseSha || r.release.run_id !== String(native.id) ||
      r.release.run_attempt !== native.run_attempt || r.release.event !== 'push')
    refusal('previous_release_source_changed');
  if (r.phase !== 'pending_terminal') refusal('previous_release_unclosed');
  // The canonical resolver authenticates actual installation, exact digest-bound
  // terminal job, and any already supported, explicitly typed recovery lineage.
  const resolved = await R.resolveCurrent(api, { sha: context.baseSha }, location);
  if (resolved.ready !== true || resolved.repository_sha !== context.baseSha ||
      resolved.application_sha !== r.application_sha) refusal('previous_release_unconfirmed');
  const binding = await R.currentBinding(api, context.baseSha);
  const bound = binding && Object.fromEntries(['pr', 'head_sha', 'comment_id', 'owner_id', 'updated_at']
    .map(key => [key, binding[key]]));
  if (!binding || !R.same(bound, r.closure.binding)) refusal('previous_binding_changed');
  const path = R.API_ROOT + '/issues/comments/' + binding.comment_id;
  // A completed shared publisher can already belong to this exact next PR.
  // Its old native terminal remains authoritative; validate the new acquisition
  // against the current PR rather than demanding the old free text reappear.
  const shared = context.publisher?.comment_id === binding.comment_id;
  if (shared && context.publisher.owner_id !== binding.owner_id) refusal('previous_publisher_changed');
  const verifyAcquisition = async () => {
    if (!shared) return;
    pinnedPublisher(context.publisher, context.now);
    verifyPublisherBinding(await api('GET', R.API_ROOT + '/pulls/' + context.prNumber),
      context, context.publisher);
  };
  const verifyRecord = value => {
    if (shared) verifyPublisherRecord(value, context, context.publisher);
    else if (C.recordState(value, binding) !== 'released' ||
        value.body !== r.closure.expected_terminal) refusal('previous_publisher_unclosed');
  };
  await verifyAcquisition();
  const record = await api('GET', path);
  verifyRecord(record);
  const fresh = await R.readRecord(api);
  if (fresh.blob !== location.blob || fresh.record_digest !== location.record_digest)
    refusal('previous_release_changed');
  const last = await api('GET', path);
  verifyRecord(last);
  if (last.body !== record.body || last.updated_at !== record.updated_at)
    refusal('previous_publisher_changed');
  await verifyAcquisition();
  // A different candidate record can also change during the long old-release proof.
  if (context.publisher) {
    pinnedPublisher(context.publisher, context.now);
    verifyPublisherBinding(await api('GET', R.API_ROOT + '/pulls/' + context.prNumber),
      context, context.publisher);
    verifyPublisherRecord(await api('GET', R.API_ROOT + '/issues/comments/' + context.publisher.comment_id),
      context, context.publisher);
  }
  if ((await api('GET', R.API_ROOT + '/git/ref/heads/main'))?.object?.sha !== context.baseSha)
    refusal('base_changed');
}

async function runGuard({ env, readEvent, fetchImpl, admission, now = Date.now(), clock = () => performance.now() }) {
  // Manual validation remains full validation, but cannot grant a merge.
  if (!admission && env.GITHUB_EVENT_NAME === 'workflow_dispatch') {
    return { allowed: true, reason: 'manual_checks_only', holder_pr: null, expires_at: null };
  }
  if (!admission && env.GITHUB_EVENT_NAME !== 'pull_request') refusal('unsupported_event');
  let event;
  if (!admission) {
    try { event = JSON.parse(readEvent(env.GITHUB_EVENT_PATH)); }
    catch { refusal('invalid_event'); }
  }
  const context = admission ? { ...admission, now } : {
    repository: env.GITHUB_REPOSITORY,
    prNumber: event?.pull_request?.number,
    headSha: event?.pull_request?.head?.sha,
    baseSha: event?.pull_request?.base?.sha,
    now
  };
  if (!validRepository(context.repository) ||
      !Number.isSafeInteger(context.prNumber) || !SHA.test(context.headSha || '') ||
      !SHA.test(context.baseSha || '')) refusal('invalid_context');
  if (typeof clock !== 'function') refusal('invalid_clock');
  const started = clock();
  if (!Number.isFinite(started)) refusal('invalid_clock');
  const continuing = admission && Object.prototype.hasOwnProperty.call(admission, 'publisher');
  if (continuing) pinnedPublisher(admission.publisher, now);
  const main = await readJson(context.repository, 'git/ref/heads/main', env.GITHUB_TOKEN, fetchImpl);
  if (main?.object?.sha !== context.baseSha) refusal('base_changed');
  const pulls = await readOpenPulls(context.repository, env.GITHUB_TOKEN, fetchImpl);
  const decision = continuing ? await continuePublisher(pulls, context, admission.publisher, env.GITHUB_TOKEN, fetchImpl) :
    decidePriority(pulls, context);
  if (admission && decision.allowed) {
    await verifyPreviousRelease(context, env.GITHUB_TOKEN, fetchImpl);
    // The predecessor proof can take time. Refresh the existing priority rules
    // rather than admitting a superseded target or ignoring a new reservation.
    const fresh = await readOpenPulls(context.repository, env.GITHUB_TOKEN, fetchImpl);
    const elapsed = clock() - started;
    if (!Number.isFinite(elapsed) || elapsed < 0 || !Number.isFinite(context.now + elapsed)) refusal('invalid_clock');
    // Keep the supplied absolute clock, advancing it only by monotonic elapsed
    // time so proof latency cannot preserve an expired or reject a fresh claim.
    const finalContext = { ...context, now: context.now + elapsed };
    const final = continuing ? await continuePublisher(fresh, finalContext, admission.publisher, env.GITHUB_TOKEN, fetchImpl) :
      decidePriority(fresh, finalContext);
    if ((await readJson(context.repository, 'git/ref/heads/main', env.GITHUB_TOKEN, fetchImpl))?.object?.sha !== context.baseSha)
      refusal('base_changed');
    return final;
  }
  return decision;
}

module.exports = { claimFor, decidePriority, readOpenPulls, runGuard, verifyPreviousRelease, MAX_WINDOW_MS };
if (typeof require === 'function' && require.main === module) {
  // --admit is the read-only last check before the existing publisher/CAS merge.
  // It has no merge permission and does not acquire a deployment lease.
  const args = process.argv.slice(2);
  let admission;
  if (args.length) {
    if (![4, 8].includes(args.length) || args[0] !== '--admit' || !/^[1-9]\d*$/.test(args[1]) ||
        (args.length === 8 && (args[4] !== '--publisher' || !/^[1-9]\d*$/.test(args[5]) || !/^[1-9]\d*$/.test(args[6])))) {
      process.stderr.write('Usage: release_priority.js [--admit PR HEAD_SHA BASE_SHA [--publisher COMMENT_ID OWNER_ID ACQUIRED_AT]]\n');
      process.exitCode = 1;
    } else {
      admission = { repository: process.env.GITHUB_REPOSITORY,
        prNumber: Number(args[1]), headSha: args[2], baseSha: args[3] };
      if (args.length === 8) admission.publisher = {
        comment_id: Number(args[5]), owner_id: Number(args[6]), updated_at: args[7]
      };
    }
  }
  if (!process.exitCode) runGuard({ env: process.env, admission,
    readEvent: file => require('node:fs').readFileSync(file, 'utf8'),
    fetchImpl: globalThis.fetch }).then(result => {
    process.stdout.write('release-priority: ' + JSON.stringify(result) + '\n');
    if (!result.allowed) process.exitCode = 1;
  }).catch(error => {
    process.stderr.write(String(error.message) + '\n');
    process.exitCode = 1;
  });
}
