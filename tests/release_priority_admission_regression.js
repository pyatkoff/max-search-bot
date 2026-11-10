'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const { runGuard } = require('../tools/release_priority');

module.exports = async function verifyPublisherAdmission(check = assert.equal) {
  const repository = 'pyatkoff/max-search-bot';
  const head = 'a'.repeat(40), base = 'b'.repeat(40);
  const acquired = '2026-11-10T10:26:27Z';
  const now = Date.parse('2026-11-10T10:28:00Z');
  const publisher = { comment_id: 648, owner_id: 17, updated_at: acquired };
  const binding = { ...publisher, head_sha: head };
  const slot = value => '<!-- release-slot-close:v1 ' + JSON.stringify(value) + ' -->';
  const reservation = (started_at, patch = {}) => '<!-- anytour-release-priority:v1 ' +
    JSON.stringify({ head, base, started_at, expires_at: '2026-11-10T10:47:19Z', kind: 'product', ...patch }) + ' -->';
  const pr = (number, body = '', patch = {}) => ({ number, body, state: 'open', draft: false,
    author_association: 'OWNER', head: { sha: head, repo: { full_name: repository } },
    base: { ref: 'main', sha: base }, ...patch });
  const record = { id: 648, user: { id: 17 }, author_association: 'OWNER', updated_at: acquired,
    created_at: '2026-10-28T00:00:00Z', body: 'PR1438: выпуск занят',
    issue_url: 'https://api.github.com/repos/' + repository + '/issues/1122' };
  const copy = value => JSON.parse(JSON.stringify(value));
  let target, other, comment, freshTarget, mainReads, calls;
  const reset = () => {
    target = pr(1438, slot(binding)); other = pr(1441, reservation('2026-11-10T10:27:10Z'));
    comment = copy(record); freshTarget = null; mainReads = 0; calls = [];
  };
  reset();
  const response = (value, status = 200) => ({ ok: status === 200, status, json: async () => copy(value) });
  const fetchImpl = async (url, options) => {
    calls.push(url);
    check(options.method || 'GET', 'GET', 'publisher continuation performs no mutation');
    check(options.redirect, 'error', 'publisher proof does not follow redirects');
    if (url.endsWith('/git/ref/heads/main')) { mainReads++; return response({ object: { sha: base } }); }
    if (url.includes('/pulls?')) return response([target, other]);
    if (url.endsWith('/issues/comments/648')) return response(comment);
    if (url.endsWith('/pulls/1438')) return response(freshTarget || target);
    throw Error('Unexpected proof request');
  };
  const env = { GITHUB_REPOSITORY: repository, GITHUB_TOKEN: 'synthetic-read-token' };
  const admission = { repository, prNumber: 1438, headSha: head, baseSha: base };
  const run = (patch = {}) => runGuard({ env, admission: { ...admission, publisher: copy(publisher) }, fetchImpl, now, ...patch });
  const rejects = async (action, code, message) => {
    let observed = null;
    try { await action(); } catch (error) { observed = error.code; }
    check(observed, code, message);
  };

  // Reproduce the actual interleaving: admission, acquisition, then a new reservation.
  other.body = '';
  check((await runGuard({ env, admission, fetchImpl, now: Date.parse(acquired) - 8000 })).allowed,
    true, 'ordinary admission succeeds before the publisher is acquired');
  reset();
  check((await runGuard({ env, admission, fetchImpl, now })).allowed,
    false, 'a candidate without pinned acquisition cannot ignore the later reservation');
  reset();
  const continuation = await run();
  check(continuation.allowed, true, 'the same admitted publisher survives a strictly later reservation');
  check(continuation.reason, 'active_publisher', 'continuation names its actual authority');
  check(continuation.ignored_later_reservations, 1, 'the late reservation is reported explicitly');
  check(calls.length, 6, 'continuation uses a finite sequence of read-only proof requests');

  for (const started of ['2026-11-10T10:26:26Z', acquired]) {
    reset(); other.body = reservation(started);
    check((await run()).allowed, false, 'earlier or simultaneous reservation is never overridden: ' + started);
  }
  reset(); other.body = reservation('2026-11-10T10:27:10Z', {
    kind: 'incident', incident: 'https://github.com/' + repository + '/issues/1122' });
  check((await run()).allowed, true, 'a later incident retains the existing non-preemption rule for active publishers');

  for (const [description, change, code] of [
    ['missing binding', () => { target.body = ''; }, 'invalid_publisher_binding'],
    ['duplicate binding', () => { target.body += slot(binding); }, 'invalid_publisher_binding'],
    ['malformed binding', () => { target.body = '<!-- release-slot-close:v1 broken -->'; }, 'invalid_publisher_binding'],
    ['unsupported binding', () => { target.body = slot(binding).replace(':v1', ':v2'); }, 'invalid_publisher_binding'],
    ['unknown binding field', () => { target.body = slot({ ...binding, renew: true }); }, 'invalid_publisher_binding'],
    ['foreign binding owner', () => { target.body = slot({ ...binding, owner_id: 18 }); }, 'invalid_publisher_binding'],
    ['changed binding head', () => { target.body = slot({ ...binding, head_sha: 'c'.repeat(40) }); }, 'invalid_publisher_binding'],
    ['changed comment id', () => { comment.id++; }, 'publisher_changed'],
    ['foreign comment owner', () => { comment.user.id++; }, 'publisher_changed'],
    ['untrusted comment author', () => { comment.author_association = 'CONTRIBUTOR'; }, 'publisher_changed'],
    ['missing comment association', () => { delete comment.author_association; }, 'publisher_changed'],
    ['changed comment version', () => { comment.updated_at = '2026-11-10T10:26:28Z'; }, 'publisher_changed'],
    ['foreign issue', () => { comment.issue_url = 'https://api.github.com/repos/other/repo/issues/1122'; }, 'publisher_changed'],
    ['wrong issue', () => { comment.issue_url = 'https://api.github.com/repos/' + repository + '/issues/55'; }, 'publisher_changed'],
    ['terminal publisher', () => { comment.body = 'PR1438: установлено, выпуск свободен'; }, 'publisher_changed'],
    ['another PR owns publisher', () => { comment.body = 'PR1441: выпуск занят'; }, 'publisher_changed'],
    ['untrusted target', () => { target.author_association = 'CONTRIBUTOR'; }, 'invalid_publisher_binding'],
    ['fork target', () => { target.head.repo.full_name = 'outside/fork'; }, 'invalid_publisher_binding'],
    ['draft target', () => { target.draft = true; }, 'invalid_publisher_binding'],
    ['changed target head', () => { target.head.sha = 'c'.repeat(40); }, 'superseded_target'],
    ['changed final target', () => { freshTarget = { ...copy(target), body: slot({ ...binding, owner_id: 18 }) }; }, 'invalid_publisher_binding'],
    ['foreign final target number', () => { freshTarget = { ...copy(target), number: 1441 }; }, 'invalid_publisher_binding'],
    ['missing final target number', () => { freshTarget = copy(target); delete freshTarget.number; }, 'invalid_publisher_binding'],
    ['malformed fresh reservation', () => { freshTarget = { ...copy(target), body: target.body + '<!-- anytour-release-priority:v1 broken -->' }; }, 'invalid_json'],
    ['malformed late reservation', () => { other.body = '<!-- anytour-release-priority:v1 broken -->'; }, 'invalid_json']
  ]) {
    reset(); change(); await rejects(() => run(), code, description + ' refuses without fallback');
  }
  for (const expected of [{ ...publisher, owner_id: 0 }, { ...publisher, extra: true },
    { ...publisher, updated_at: '2026-11-10T10:29:00Z' }, { ...publisher, updated_at: '2026-02-30T10:26:27Z' }, null]) {
    reset(); await rejects(() => run({ admission: { ...admission, publisher: expected } }),
      'invalid_publisher_context', 'invalid pinned acquisition cannot fall back to ordinary admission');
  }
  reset();
  await rejects(() => run({ fetchImpl: async (url, options) => url.endsWith('/issues/comments/648') ?
    response({}, 429) : fetchImpl(url, options) }), 'api_http_429', 'proof rate limit refuses without retry');
  reset();
  await rejects(() => run({ fetchImpl: async (url, options) => url.endsWith('/git/ref/heads/main') && mainReads ?
    response({ object: { sha: 'c'.repeat(40) } }) : fetchImpl(url, options) }),
    'base_changed', 'main changing during publisher proof refuses continuation');
  for (const [description, change] of [
    ['body', () => { comment.body = 'PR1441: выпуск занят'; }],
    ['version', () => { comment.updated_at = '2026-11-10T10:26:28Z'; }],
    ['owner', () => { comment.user.id++; }],
    ['association', () => { comment.author_association = 'CONTRIBUTOR'; }],
    ['issue', () => { comment.issue_url += '/other'; }]
  ]) {
    reset();
    await rejects(() => run({ fetchImpl: async (url, options) => {
      const result = await fetchImpl(url, options);
      if (url.endsWith('/git/ref/heads/main') && mainReads === 2) change();
      return result;
    } }), 'publisher_changed', 'publisher ' + description + ' changing during proof refuses continuation');
  }
  for (const invalidJson of [false, true]) {
    reset(); let recordReads = 0;
    await rejects(() => run({ fetchImpl: async (url, options) => {
      if (url.endsWith('/issues/comments/648') && ++recordReads === 2) return invalidJson ?
        { ok: true, json: async () => { throw Error('private transport failure'); } } : response({}, 429);
      return fetchImpl(url, options);
    } }), invalidJson ? 'invalid_api_response' : 'api_http_429', 'failed final proof read refuses without fallback');
    check(recordReads, 2, 'failed final proof read is not retried');
  }
  reset();
  const ciEnv = { ...env, GITHUB_EVENT_NAME: 'pull_request', GITHUB_EVENT_PATH: 'event.json' };
  check((await runGuard({ env: ciEnv, readEvent: () => JSON.stringify({ pull_request: target }), fetchImpl, now })).allowed,
    false, 'ordinary required CI retains its original priority decision');
  const tool = path.resolve(__dirname, '../tools/release_priority.js');
  const cli = spawnSync(process.execPath, [tool, '--admit', '1438', head, base, '--publisher',
    '648', '17', acquired], { encoding: 'utf8', env: { GITHUB_REPOSITORY: repository } });
  check(cli.status, 1, 'pinned CLI cannot continue without read capability');
  check(cli.stderr.includes('missing_read_capability'), true, 'pinned CLI reaches the same read-only guard');
};

if (require.main === module) {
  let count = 0;
  module.exports((actual, expected, message) => { assert.equal(actual, expected, message); count++; })
    .then(() => process.stdout.write('Publisher admission: ' + count + ' assertions passed\n'))
    .catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
}
