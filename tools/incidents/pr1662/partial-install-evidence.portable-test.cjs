'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const evidence = require('./partial-install-evidence.cjs');

const repo = 'pyatkoff/max-search-bot';
const main = '302ba66b5739e369a4df94471c8cfe9c054dd070';
const head = '3bdb9dbe6a6c706a5f870cd7d6f85aad2cf1b495';
const diagnostics = 'db9f222955811721ee30fc7d1ffce435a2f48e90';
const source = '688addacfc55438751b4738adafebafca896c0e1';
const receiptRun = 45164611732;
const receiptJob = 137658227484;
const owner = 226193297;
const publisherPath = '/issues/comments/7077907800';
const jobsPath = id => '/actions/runs/' + id + '/attempts/1/jobs?per_page=100';
const censusPath = sha => '/actions/runs?head_sha=' + sha + '&per_page=100&page=1';
const artifactsPath = id => '/actions/runs/' + id + '/artifacts?per_page=100';

// All metadata is constructed in memory. Missing parallel evidence fails on the next
// event-loop turn so each test can assert the precise refusal from the branch it exercises.
function adapter(responses, logs = {}) {
  function read(table, key) {
    if (Object.hasOwn(table, key)) return Promise.resolve(table[key]);
    return new Promise((_, reject) => setImmediate(() => reject(new Error('offline evidence absent'))));
  }
  return {get: path => read(responses, path), jobLog: id => read(logs, id)};
}

function anchors() {
  const updated = '2026-11-23T10:42:51Z';
  const binding = {comment_id: 7077907800, head_sha: head, owner_id: owner, updated_at: updated};
  const ref = (branch, sha) => ({ref: 'refs/heads/' + branch, object: {type: 'commit', sha}});
  return {
    '/git/ref/heads/main': ref('main', main),
    '/git/ref/heads/diagnostics': ref('diagnostics', diagnostics),
    [publisherPath]: {id: binding.comment_id, user: {id: owner}, body: 'PR1662: выпуск занят',
      updated_at: updated, issue_url: 'https://api.github.com/repos/' + repo + '/issues/1122'},
    '/pulls/1662': {number: 1662, state: 'closed', merged: true, merge_commit_sha: main,
      head: {sha: head, repo: {full_name: repo}}, base: {ref: 'main', repo: {full_name: repo}},
      body: '<!-- release-slot-close:v1 ' + JSON.stringify(binding) + ' -->'}
  };
}

function receipt() {
  const run = {id: receiptRun, workflow_id: 427711337,
    path: '.github/workflows/pr1662-partial-install-preflight.yml', head_sha: source,
    head_branch: 'audit/pr1662-partial-install-preflight-20261123', event: 'push',
    run_attempt: 1, status: 'completed', conclusion: 'success',
    repository: {id: 1342493152, full_name: repo}, head_repository: {id: 1342493152, full_name: repo},
    actor: {id: owner, login: 'pyatkoff'}, triggering_actor: {id: owner, login: 'pyatkoff'}};
  const job = {id: receiptJob, run_id: receiptRun, head_sha: source, status: 'completed',
    conclusion: 'success', name: 'preflight', steps: [
      'Set up job', 'Verify exact source, original job history and active publisher',
      'Check the original public containment header without reading content',
      'Acknowledge preflight scope', 'Complete job'
    ].map(name => ({name, status: 'completed', conclusion: 'success'}))};
  return {...anchors(), ['/actions/runs/' + receiptRun]: run, [censusPath(source)]: {total_count: 1, workflow_runs: [run]},
    ['/git/commits/' + source]: {sha: source, parents: [{sha: main}]},
    [jobsPath(receiptRun)]: {total_count: 1, jobs: [job]}, [artifactsPath(receiptRun)]: {total_count: 0, artifacts: []}};
}

function refused(client, reason) {
  return assert.rejects(evidence.inspectPartialInstall1662(client), {message: 'partial-install-1662: ' + reason});
}

test('the public interface exposes only inspection and requires both evidence readers', async () => {
  assert.deepEqual(Object.keys(evidence), ['inspectPartialInstall1662']);
  for (const client of [undefined, {}, {get() {}}, {jobLog() {}}]) {
    await refused(client, 'read-only adapter missing');
  }
});

test('an unavailable source cannot be replaced by an empty history', async () => {
  await assert.rejects(evidence.inspectPartialInstall1662(adapter({})), {message: 'offline evidence absent'});
});

for (const [name, change, reason] of [
  ['advanced main', rows => { rows['/git/ref/heads/main'].object.sha = head; }, 'main changed'],
  ['changed diagnostics', rows => { rows['/git/ref/heads/diagnostics'].object.sha = main; }, 'diagnostics changed'],
  ['foreign pull source', rows => { rows['/pulls/1662'].head.repo.full_name = 'other/fork'; }, 'original pull changed'],
  ['duplicate lease marker', rows => { rows['/pulls/1662'].body += rows['/pulls/1662'].body; }, 'publisher marker changed'],
  ['released publisher', rows => { rows[publisherPath].body = 'free'; }, 'held publisher changed'],
  ['publisher from another issue', rows => { rows[publisherPath].issue_url += '0'; }, 'held publisher changed']
]) {
  test('refuses ' + name, async () => {
    const rows = anchors();
    change(rows);
    await refused(adapter(rows), reason);
  });
}

test('refuses a truncated original deployment census', async () => {
  const rows = {...anchors(), [censusPath(main)]: {total_count: 1, workflow_runs: []}};
  await refused(adapter(rows), 'incomplete run census');
});

test('matching a baseline blob label and size cannot substitute for matching its bytes', async () => {
  const rows = {...anchors(),
    ['/contents/deploy_status.json?ref=' + diagnostics]: {type: 'file', path: 'deploy_status.json',
      encoding: 'base64', sha: '472593b8fb3c1418b8b6b43b008278ab7a8125b7', size: 4206,
      content: Buffer.alloc(4206, 33).toString('base64')},
    '/actions/runs/45155614604': {}, '/actions/runs/45158055637': {}, '/pulls/1658': {},
    '/issues/comments/7097821694': {}, [jobsPath(45158055637)]: {total_count: 0, jobs: []}};
  await refused(adapter(rows), 'baseline bytes changed');
});

for (const [name, change, reason] of [
  ['an actor with the same login and a different ID', rows => { rows['/actions/runs/' + receiptRun].actor.id = 1; }, 'untrusted actor'],
  ['a different repository with the same name', rows => { rows['/actions/runs/' + receiptRun].repository.id = 1; }, 'untrusted repository'],
  ['a rerun', rows => { rows['/actions/runs/' + receiptRun].run_attempt = 2; }, 'wrong native run ' + receiptRun],
  ['a dispatched run', rows => { rows['/actions/runs/' + receiptRun].event = 'workflow_dispatch'; }, 'wrong native run ' + receiptRun],
  ['a receipt on a different parent', rows => { rows['/git/commits/' + source].parents[0].sha = head; }, 'read-only receipt origin changed'],
  ['a receipt with an extra artifact', rows => { rows[artifactsPath(receiptRun)] = {total_count: 1, artifacts: [{id: 7}]}; }, 'read-only receipt origin changed'],
  ['an incomplete job listing', rows => { rows[jobsPath(receiptRun)].total_count = 2; }, 'incomplete jobs'],
  ['a skipped check step', rows => { rows[jobsPath(receiptRun)].jobs[0].steps[2].conclusion = 'skipped'; }, 'public check stage changed']
]) {
  test('refuses ' + name, async () => {
    const rows = receipt();
    change(rows);
    await refused(adapter(rows, {[receiptJob]: 'synthetic log'}), reason);
  });
}

for (const status of [200, 403, 404]) {
  test('refuses a fabricated native log that prints HTTP ' + status, async () => {
    const line = '2026-11-23T00:00:00.000Z original_guard_current_http=' + status + '\n';
    await refused(adapter(receipt(), {[receiptJob]: line}), 'native log hash changed');
  });
}
