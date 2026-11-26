'use strict';

const {createHash} = require('node:crypto');
const assert = require('node:assert/strict');
const test = require('node:test');
const {originalArtifact} = require('../tools/resolve_max_photo_packet');

function fixture() {
  const bytes = Buffer.from('synthetic receipt archive bytes');
  const head = 'a'.repeat(40);
  const repo = {id: 1342493152, full_name: 'pyatkoff/max-search-bot'};
  const identity = {id: 226193297};
  const digest = createHash('sha256').update(bytes).digest('hex');
  const reader = {run: 37, job: 38, artifact: 39, head, digest, branch: 'registry-reader',
    workflow: '.github/workflows/registry.yml', name: 'registry'};
  const run = {id: 37, run_attempt: 1, path: reader.workflow, head_sha: head, head_branch: reader.branch,
    repository: repo, head_repository: repo, actor: identity, triggering_actor: identity, event: 'push',
    status: 'completed', conclusion: 'success', created_at: '2026-11-24T09:00:00Z'};
  const job = {id: 38, run_id: 37, run_attempt: 1, head_sha: head, status: 'completed', conclusion: 'success'};
  const metadata = {id: 39, name: 'registry', digest: 'sha256:' + digest, expired: false,
    size_in_bytes: bytes.length, workflow_run: {id: 37, head_sha: head, head_branch: reader.branch,
      repository_id: repo.id, head_repository_id: repo.id}};
  let downloads = 0;
  const api = async (method, endpoint) => {
    assert.equal(method, 'GET');
    if (endpoint.endsWith('/jobs?per_page=100')) return {total_count: 1, jobs: [job]};
    if (endpoint.endsWith('/artifacts?per_page=100')) return {total_count: 1, artifacts: [metadata]};
    return run;
  };
  api.artifact = async artifact => {
    downloads++;
    assert.equal(artifact.id, reader.artifact);
    return bytes;
  };
  return {api, reader, run, job, metadata, bytes, downloads: () => downloads};
}

test('reads the artifact from the exact successful native attempt', async () => {
  const data = fixture();
  assert.deepEqual(await originalArtifact(data.api, data.reader), data.bytes);
  assert.equal(data.downloads(), 1);
});

test('rejects repeat attempts, a foreign actor and a replaced job before downloading', async () => {
  for (const update of [d => { d.run.run_attempt = 2; }, d => { d.run.triggering_actor = {id: 5}; },
    d => { d.job.id++; }, d => { d.metadata.workflow_run.head_sha = 'b'.repeat(40); }]) {
    const data = fixture();
    update(data);
    await assert.rejects(originalArtifact(data.api, data.reader));
    assert.equal(data.downloads(), 0);
  }
});

test('rejects a modified download even when the artifact metadata matches', async () => {
  const data = fixture();
  data.api.artifact = async () => Buffer.alloc(data.bytes.length);
  await assert.rejects(originalArtifact(data.api, data.reader), /max_receipt_artifact_digest_mismatch/);
});
