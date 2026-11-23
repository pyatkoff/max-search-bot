'use strict';
// Offline transport fixtures only; these are never native execution evidence.
const assert = require('node:assert/strict');
const test = require('node:test');
const {readActiveInventory} = require('./active-inventory.js');

const STATUSES = ['in_progress', 'queued', 'waiting', 'requested', 'pending'];
const requestPath = status => '/actions/runs?status=' + status + '&per_page=100';
const complete = ids => ({total_count: ids.length, workflow_runs: ids.map(id => ({id}))});

function fixture(answer) {
  const calls = [], sleeps = [], observations = [];
  return {
    calls, sleeps, observations,
    get: async path => {
      calls.push(path);
      return answer(path, calls.length);
    },
    options: {
      sleep: async ms => { sleeps.push(ms); },
      observe: async record => { observations.push(record); },
    },
  };
}

function assertSafeObservations(records) {
  for (const record of records) {
    assert.deepEqual(Object.keys(record).sort(),
      ['status', 'attempt', 'observed_at_utc', 'elapsed_ms', 'total', 'returned', 'complete', 'reason'].sort());
    assert.match(record.observed_at_utc, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/);
    assert.ok(Number.isInteger(record.elapsed_ms) && record.elapsed_ms >= 0);
    assert.ok(STATUSES.includes(record.status));
    assert.ok(record.attempt >= 1 && record.attempt <= 3);
  }
}

test('stable complete inventory retains every run and reads exactly five statuses', async () => {
  const responses = new Map(STATUSES.map((status, i) => [requestPath(status), complete(i < 2 ? [i + 1] : [])]));
  const f = fixture(path => responses.get(path));
  const active = await readActiveInventory(f.get, f.options);
  assert.deepEqual(active, [{id: 1}, {id: 2}]);
  assert.deepEqual(f.calls, STATUSES.map(requestPath));
  assert.deepEqual(f.sleeps, []);
  assert.equal(f.observations.length, 5);
  assert.ok(f.observations.every(row => row.complete && row.reason === 'complete' && row.attempt === 1));
  assertSafeObservations(f.observations);
});

test('temporary partial responses get bounded GET retries and no partial rows survive', async () => {
  const f = fixture((path, n) => {
    if (path !== requestPath('in_progress')) return complete([]);
    if (n < 3) return {total_count: 2, workflow_runs: [{id: 999}]};
    return complete([11, 12]);
  });
  const active = await readActiveInventory(f.get, f.options);
  assert.deepEqual(active, [{id: 11}, {id: 12}]);
  assert.deepEqual(f.sleeps, [250, 750]);
  assert.deepEqual(f.calls, [requestPath('in_progress'), requestPath('in_progress'), ...STATUSES.map(requestPath)]);
  assert.deepEqual(f.observations.slice(0, 3).map(row => [row.attempt, row.total, row.returned, row.complete]),
    [[1, 2, 1, false], [2, 2, 1, false], [3, 2, 2, true]]);
  assertSafeObservations(f.observations);
});

test('permanent partial collection fails after three attempts with exact safe counts', async () => {
  const f = fixture(() => ({total_count: 10, workflow_runs: [{id: 1}]}));
  await assert.rejects(() => readActiveInventory(f.get, f.options), error => {
    assert.equal(error.code, 'incomplete_workflow_runs');
    assert.equal(error.status, 'in_progress');
    assert.equal(error.attempt, 3);
    assert.equal(error.total, 10);
    assert.equal(error.returned, 1);
    assert.match(error.message, /status=in_progress attempt=3 total=10 returned=1$/);
    return true;
  });
  assert.equal(f.calls.length, 3);
  assert.deepEqual(f.sleeps, [250, 750]);
  assert.equal(f.observations.length, 3);
  assert.ok(f.observations.every(row => !row.complete));
  assertSafeObservations(f.observations);
});

test('duplicate IDs never form a complete inventory despite matching counts', async () => {
  const f = fixture(() => ({total_count: 2, workflow_runs: [{id: 17}, {id: 17}]}));
  await assert.rejects(() => readActiveInventory(f.get, f.options), {code: 'duplicate_workflow_run_ids'});
  assert.equal(f.calls.length, 1);
  assert.deepEqual(f.sleeps, []);
  assert.equal(f.observations[0].complete, false);
  assertSafeObservations(f.observations);
});

test('100 is rejected without pagination or retry; the existing 99 boundary is accepted', async () => {
  const f = fixture(() => complete(Array.from({length: 100}, (_, i) => i + 1)));
  await assert.rejects(() => readActiveInventory(f.get, f.options), {code: 'workflow_runs_cap_exceeded'});
  assert.equal(f.calls.length, 1);
  assert.deepEqual(f.sleeps, []);
  const boundary = fixture(path => complete(path === requestPath('in_progress')
    ? Array.from({length: 99}, (_, i) => i + 1) : []));
  assert.equal((await readActiveInventory(boundary.get, boundary.options)).length, 99);
  assert.equal(boundary.calls.length, 5);
});

test('total_count must be an integer, not a coerced string, negative, fraction or missing value', async () => {
  for (const total_count of ['1', -1, 0.5, undefined, NaN, null]) {
    const f = fixture(() => ({total_count, workflow_runs: []}));
    await assert.rejects(() => readActiveInventory(f.get, f.options), {code: 'invalid_workflow_runs_total'});
    assert.equal(f.calls.length, 1);
    assert.deepEqual(f.sleeps, []);
    assertSafeObservations(f.observations);
  }
});

test('malformed collection and invalid run ID cannot be accepted or cause writes', async () => {
  for (const response of [
    {total_count: 0, workflow_runs: null},
    {total_count: 1, workflow_runs: [{id: '1'}]},
    {total_count: 1, workflow_runs: [{id: 0}]},
    {total_count: 1, workflow_runs: [null]},
  ]) {
    const f = fixture(() => response);
    await assert.rejects(() => readActiveInventory(f.get, f.options));
    assert.equal(f.calls.length, 1);
    assert.deepEqual(f.sleeps, []);
    assert.ok(f.calls.every(path => STATUSES.map(requestPath).includes(path)));
  }
});

test('observations and transport errors never expose response bodies or exception credentials', async () => {
  const secret = 'OFFLINE_SECRET_DO_NOT_LOG';
  const f = fixture(() => ({total_count: 1, workflow_runs: [{id: 7, body: secret, token: secret}], body: secret}));
  await readActiveInventory(f.get, f.options);
  assert.ok(!JSON.stringify(f.observations).includes(secret));
  assertSafeObservations(f.observations);
  const broken = fixture(() => { throw new Error('https://credential:' + secret + '@fixture.invalid/body'); });
  await assert.rejects(() => readActiveInventory(broken.get, broken.options), error => {
    assert.equal(error.code, 'workflow_runs_read_failed');
    assert.ok(!error.message.includes(secret));
    assert.equal(error.cause, undefined);
    return true;
  });
  assert.equal(broken.calls.length, 1);
  assert.deepEqual(broken.sleeps, []);
  assert.ok(!JSON.stringify(broken.observations).includes(secret));
  assertSafeObservations(broken.observations);
});
