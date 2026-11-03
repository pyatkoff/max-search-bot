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

async function readJson(repository, resource, token, fetchImpl) {
  if (!token || typeof fetchImpl !== 'function') refusal('missing_read_capability');
  const controller = new AbortController();
  const deadline = setTimeout(() => controller.abort(), 10000);
  try {
    const response = await fetchImpl('https://api.github.com/repos/' + repository + '/' + resource, {
      headers: { Accept: 'application/vnd.github+json', Authorization: 'Bearer ' + token,
        'X-GitHub-Api-Version': '2022-11-28' },
      redirect: 'error', signal: controller.signal
    });
    if (!response?.ok) refusal('api_http_' + Number(response?.status || 0));
    try { return await response.json(); } catch { refusal('invalid_api_response'); }
  } catch (error) {
    if (error?.code) throw error;
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

async function runGuard({ env, readEvent, fetchImpl, admission, now = Date.now() }) {
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
  const main = await readJson(context.repository, 'git/ref/heads/main', env.GITHUB_TOKEN, fetchImpl);
  if (main?.object?.sha !== context.baseSha) refusal('base_changed');
  return decidePriority(await readOpenPulls(context.repository, env.GITHUB_TOKEN, fetchImpl), context);
}

module.exports = { claimFor, decidePriority, readOpenPulls, runGuard, MAX_WINDOW_MS };
if (typeof require === 'function' && require.main === module) {
  // --admit is the read-only last check before the existing publisher/CAS merge.
  // It has no merge permission and does not acquire a deployment lease.
  const args = process.argv.slice(2);
  let admission;
  if (args.length) {
    if (args.length !== 4 || args[0] !== '--admit' || !/^[1-9]\d*$/.test(args[1])) {
      process.stderr.write('Usage: release_priority.js [--admit PR HEAD_SHA BASE_SHA]\n');
      process.exitCode = 1;
    } else {
      admission = { repository: process.env.GITHUB_REPOSITORY,
        prNumber: Number(args[1]), headSha: args[2], baseSha: args[3] };
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
