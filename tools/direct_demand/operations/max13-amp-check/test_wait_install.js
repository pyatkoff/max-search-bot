'use strict';
// Offline GET/clock fixtures only; no native run, credential or production access.
const assert = require('node:assert/strict');
const test = require('node:test');
const crypto = require('node:crypto');
const {waitForInstallation} = require('./wait-install.js');
const APP = 'a'.repeat(40);
const REPO = 'pyatkoff/max-search-bot';
const OWNER = 226193297;
const SOURCE = 6961029169;
const DEPLOY = 45145240005;
const PUBLISHER = 7077907800;
const BODY = 'OFFLINE_SOURCE_FIXTURE_DO_NOT_LOG';
const updated = '2026-11-23T09:30:00Z';
const expectedSource = {id: SOURCE, updated_at: updated,
  body_sha256: crypto.createHash('sha256').update(BODY).digest('hex')};
const source = () => ({id: SOURCE, user: {id: OWNER}, updated_at: updated, body: BODY,
  issue_url: 'https://api.github.com/repos/' + REPO + '/issues/1566'});
const publisher = () => ({id: PUBLISHER, user: {id: OWNER}, body: 'PR1659: установлено, выпуск свободен',
  issue_url: 'https://api.github.com/repos/' + REPO + '/issues/1122'});
const deploy = () => ({id: DEPLOY, run_attempt: 1, head_sha: APP, head_branch: 'main', event: 'push',
  path: '.github/workflows/deploy.yml', repository: {full_name: REPO}, head_repository: {full_name: REPO},
  actor: {id: OWNER}, triggering_actor: {id: OWNER}, status: 'completed', conclusion: 'success'});

function fixture(change = (value) => value) {
  let elapsed = 0, pass = 1;
  const calls = [], observations = [], sleeps = [];
  const get = async (path, {signal} = {}) => {
    assert.ok(signal instanceof AbortSignal);
    assert.equal(signal.aborted, false);
    calls.push(path);
    let value;
    if (path === '/git/ref/heads/main') value = {object: {sha: APP}};
    else if (path === '/issues/comments/' + SOURCE) value = source();
    else if (path === '/actions/runs/' + DEPLOY) value = deploy();
    else if (path === '/issues/comments/' + PUBLISHER) value = publisher();
    else assert.fail('Unexpected read endpoint: ' + path);
    return change(value, path, pass, calls.length);
  };
  const options = {applicationSha: APP, expectedSource, now: () => Date.parse(updated) + elapsed,
    sleep: async ms => { assert.ok(ms > 0 && ms <= 15000); sleeps.push(ms); elapsed += ms; ++pass; },
    observe: async record => { observations.push(record); }};
  return {get, options, calls, observations, sleeps, elapsed: () => elapsed};
}

test('waits for original deploy success and exact publisher hint, then still requires canonical admission', async () => {
  const f = fixture((value, path, pass) => {
    if (path === '/actions/runs/' + DEPLOY && pass === 1) return {...value, status: 'in_progress', conclusion: null};
    if (path === '/issues/comments/' + PUBLISHER && pass === 2) return {...value, body: 'OFFLINE_BUSY_NOT_READY'};
    return value;
  });
  const result = await waitForInstallation(f.get, f.options);
  assert.equal(result.ready, true);
  assert.equal(result.kind, 'INSTALLATION_READINESS_HINT');
  assert.equal(result.canonical_admission_required, true);
  assert.equal(result.application_sha, APP);
  assert.equal(result.passes, 3);
  assert.deepEqual(f.sleeps, [15000, 15000]);
  assert.deepEqual(f.observations.map(row => row.state), ['waiting_deploy', 'waiting_publisher', 'readiness_hint']);
  assert.equal(f.calls.filter(path => path === '/issues/comments/' + PUBLISHER).length, 2);
  assert.ok(!JSON.stringify(f.observations).includes(BODY));
  assert.ok(!JSON.stringify(f.observations).includes('OFFLINE_BUSY'));
});

test('completed failure is terminal and does not poll or inspect publisher', async () => {
  const f = fixture((value, path) => path === '/actions/runs/' + DEPLOY ? {...value, conclusion: 'failure'} : value);
  await assert.rejects(() => waitForInstallation(f.get, f.options), {code: 'installation_wait_deploy_failed'});
  assert.deepEqual(f.sleeps, []);
  assert.ok(!f.calls.includes('/issues/comments/' + PUBLISHER));
});

test('wrong main fails before deploy or publisher reads', async () => {
  const f = fixture((value, path) => path === '/git/ref/heads/main' ? {object: {sha: 'b'.repeat(40)}} : value);
  await assert.rejects(() => waitForInstallation(f.get, f.options), {code: 'installation_wait_main_changed'});
  assert.deepEqual(f.calls, ['/git/ref/heads/main']);
  assert.deepEqual(f.sleeps, []);
});

test('changed SOURCE identity, owner, timestamp or exact body immediately fails', async () => {
  for (const delta of [{id: SOURCE + 1}, {user: {id: 1}}, {updated_at: '2026-11-23T09:31:00Z'},
    {body: BODY + ' altered'}, {issue_url: 'https://api.github.com/repos/' + REPO + '/issues/1122'}]) {
    const f = fixture((value, path) => path === '/issues/comments/' + SOURCE ? {...value, ...delta} : value);
    await assert.rejects(() => waitForInstallation(f.get, f.options), {code: 'installation_wait_source_changed'});
    assert.deepEqual(f.sleeps, []);
    assert.ok(!f.calls.includes('/actions/runs/' + DEPLOY));
  }
});

test('the 900-second deadline throws and never reports success', async () => {
  const f = fixture((value, path) => path === '/actions/runs/' + DEPLOY ? {...value, status: 'in_progress', conclusion: null} : value);
  await assert.rejects(() => waitForInstallation(f.get, f.options), {code: 'installation_wait_timeout'});
  assert.equal(f.elapsed(), 900000);
  assert.equal(f.sleeps.length, 60);
  assert.equal(f.calls.filter(path => path === '/actions/runs/' + DEPLOY).length, 60);
  assert.ok(!f.observations.some(row => row.state === 'readiness_hint'));
  assert.equal(f.observations.at(-1).reason, 'installation_wait_timeout');
});

test('a spoofed publisher owner cannot turn exact terminal text into readiness', async () => {
  const f = fixture((value, path) => path === '/issues/comments/' + PUBLISHER ? {...value, user: {id: 1}} : value);
  await assert.rejects(() => waitForInstallation(f.get, f.options), {code: 'installation_wait_publisher_identity_changed'});
  assert.deepEqual(f.sleeps, []);
});

test('original deploy identity cannot be replaced by rerun, another head, branch, event or actor', async () => {
  for (const delta of [{id: DEPLOY + 1}, {run_attempt: 2}, {head_sha: 'b'.repeat(40)}, {head_branch: 'other'},
    {event: 'workflow_dispatch'}, {path: '.github/workflows/other.yml'}, {actor: {id: 1}},
    {triggering_actor: {id: 1}}, {repository: {full_name: 'other/repo'}}, {head_repository: {full_name: 'other/repo'}}]) {
    const f = fixture((value, path) => path === '/actions/runs/' + DEPLOY ? {...value, ...delta} : value);
    await assert.rejects(() => waitForInstallation(f.get, f.options), {code: 'installation_wait_deploy_identity_changed'});
    assert.deepEqual(f.sleeps, []);
  }
});

test('SOURCE is checked on later passes and again before returning readiness', async () => {
  let reads = 0;
  const f = fixture((value, path) => {
    if (path === '/issues/comments/' + SOURCE && ++reads === 2) return {...value, body: BODY + ' changed'};
    return value;
  });
  await assert.rejects(() => waitForInstallation(f.get, f.options), {code: 'installation_wait_source_changed'});
  assert.ok(!f.observations.some(row => row.state === 'readiness_hint'));
});

test('transport exception credentials are not exposed and no read retries are invented', async () => {
  const f = fixture(() => { throw new Error('OFFLINE_CREDENTIAL_SECRET'); });
  await assert.rejects(() => waitForInstallation(f.get, f.options), error => {
    assert.equal(error.code, 'installation_wait_read_failed');
    assert.ok(!error.message.includes('OFFLINE_CREDENTIAL_SECRET'));
    return true;
  });
  assert.equal(f.calls.length, 1);
  assert.deepEqual(f.sleeps, []);
  assert.ok(!JSON.stringify(f.observations).includes('OFFLINE_CREDENTIAL_SECRET'));
});
