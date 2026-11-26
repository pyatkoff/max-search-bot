'use strict';

const {execFileSync} = require('node:child_process');
const {createHash} = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const R = require('./deployment_release');

const repositoryId = 1342493152;
const ownerId = 226193297;
const maxArtifactBytes = 4 * 1024 * 1024;
const archiveReader = {
  branch: 'ops/1566-max-archive-inventory-20261123',
  workflow: '.github/workflows/read-1566-max-archive-20261123.yml',
  name: 'issue-1566-main-max-archive-inventory',
};
const sourceReader = {
  branch: 'audit/anytour-hotel-ads-20261117',
  workflow: '.github/workflows/anytour-hotel-ads-readonly-20261117.yml',
  name: 'max-seven-source-rights',
};
const hexDigest = bytes => createHash('sha256').update(bytes).digest('hex');

async function originalArtifact(api, reader) {
  R.requireValue(R.positive(reader.run) && /^[a-f0-9]{64}$/.test(reader.digest), 'bad_max_receipt_reference');
  const endpoint = R.API_ROOT + '/actions/runs/' + reader.run;
  const [run, jobList, artifactList] = await Promise.all([
    api('GET', endpoint),
    api('GET', endpoint + '/attempts/1/jobs?per_page=100'),
    api('GET', endpoint + '/artifacts?per_page=100'),
  ]);
  R.runIdentity(run, {id: reader.run, attempt: 1, head: reader.head ?? run.head_sha,
    workflow: reader.workflow, event: 'push', branch: reader.branch});
  R.requireValue(R.sha(run.head_sha) && run.repository?.id === repositoryId
    && run.head_repository?.id === repositoryId && run.actor?.id === ownerId
    && run.triggering_actor?.id === ownerId, 'untrusted_max_receipt_producer');

  const jobs = R.boundedList(jobList, 'jobs', 'incomplete_max_receipt_jobs');
  const job = jobs[0];
  R.requireValue(jobs.length === 1 && (reader.job === undefined || job.id === reader.job)
    && job.run_id === run.id && job.head_sha === run.head_sha && job.run_attempt === 1
    && job.status === 'completed' && job.conclusion === 'success', 'untrusted_max_receipt_job');

  const artifacts = R.boundedList(artifactList, 'artifacts', 'incomplete_max_receipt_artifacts');
  const artifact = artifacts[0];
  R.requireValue(artifacts.length === 1 && R.positive(artifact.id)
    && (reader.artifact === undefined || artifact.id === reader.artifact)
    && artifact.name === reader.name && artifact.expired === false
    && artifact.digest === 'sha256:' + reader.digest && Number.isSafeInteger(artifact.size_in_bytes)
    && artifact.size_in_bytes > 0 && artifact.size_in_bytes <= maxArtifactBytes
    && artifact.workflow_run?.id === run.id && artifact.workflow_run.head_sha === run.head_sha
    && artifact.workflow_run.head_branch === reader.branch && artifact.workflow_run.repository_id === repositoryId
    && artifact.workflow_run.head_repository_id === repositoryId, 'untrusted_max_receipt_artifact');
  const bytes = await api.artifact(artifact);
  R.requireValue(Buffer.isBuffer(bytes) && bytes.length === artifact.size_in_bytes
    && hexDigest(bytes) === reader.digest, 'max_receipt_artifact_digest_mismatch');
  return bytes;
}

async function inspect(args, api) {
  R.requireValue(R.sha(args['data-source-sha']) && /^[a-f0-9]{64}$/.test(args['scope-sha256']),
    'invalid_max_data_reference');
  const repo = args['git-root'];
  const dataSha = args['data-source-sha'];
  const scopeDigest = args['scope-sha256'];
  const scopePath = 'config/anytour_max_photo_scope_' + scopeDigest + '.json';
  const raw = execFileSync('git', ['--no-replace-objects', '-C', repo, 'show', dataSha + ':' + scopePath],
    {maxBuffer: 256_001, timeout: 15_000});
  R.requireValue(raw.length <= 256_000 && hexDigest(raw) === scopeDigest, 'max_scope_digest_mismatch');
  const scope = JSON.parse(raw.toString('utf8'));
  const native = scope.native_source;
  R.requireValue(R.sha(native.workflow_head) && R.positive(native.job_id) && R.positive(native.artifact_id)
    && native.attempt === 1, 'invalid_max_native_receipt_reference');

  const mainPath = R.API_ROOT + '/git/ref/heads/main';
  const main = (await api('GET', mainPath))?.object?.sha;
  R.requireValue(R.sha(main), 'max_main_unavailable');
  const release = await R.readRecord(api);
  const installed = await R.resolveCurrent(api, {sha: main}, release);
  R.requireValue(installed.ready === true && installed.repository_sha === main
    && installed.repository_sha === native.repository_sha && installed.application_sha === native.application_sha,
    'max_installed_release_changed');

  const [archive, source] = await Promise.all([
    originalArtifact(api, {...archiveReader, run: scope.archive_run_id, digest: scope.archive_sha256}),
    originalArtifact(api, {...sourceReader, run: native.run_id, head: native.workflow_head, job: native.job_id,
      artifact: native.artifact_id, digest: native.zip_sha256}),
  ]);
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'max-photo-receipts-'));
  try {
    const archiveFile = path.join(directory, 'archive.zip');
    const sourceFile = path.join(directory, 'source.zip');
    fs.writeFileSync(archiveFile, archive, {mode: 0o600});
    fs.writeFileSync(sourceFile, source, {mode: 0o600});
    const proof = JSON.parse(execFileSync('python3', ['-B', path.join(__dirname, 'verify_max_photo_packet.py'),
      '--git-root', repo, '--application-sha', installed.application_sha, '--repository-sha', main,
      '--data-source-sha', dataSha,
      '--scope-sha256', scopeDigest, '--archive', archiveFile, '--native-source', sourceFile, '--registry-zips',
      '--editor-preview'],
    {encoding: 'utf8', maxBuffer: 64_000, timeout: 30_000, stdio: ['ignore', 'pipe', 'pipe']}));
    const [finalMain, finalRelease] = await Promise.all([api('GET', mainPath), R.readRecord(api)]);
    R.requireValue(proof.evidence_only === true && proof.write_admission === false
      && finalMain?.object?.sha === main && finalRelease.record_digest === release.record_digest,
      'max_release_or_proof_changed');
    return {...proof, release_record_sha256: release.record_digest, original_artifacts_verified: true};
  } finally {
    fs.rmSync(directory, {recursive: true, force: true});
  }
}

module.exports = {originalArtifact, inspect};

if (require.main === module) {
  (async () => {
    const args = R.argumentsOf(process.argv.slice(2), ['inspect']);
    R.requireValue(R.keys(args, ['command', 'git-root', 'data-source-sha', 'scope-sha256']), 'invalid_arguments');
    console.log(JSON.stringify(await inspect(args, R.makeReadApi(process.env.GITHUB_TOKEN))));
  })().catch(error => {
    const reason = error instanceof R.Refusal ? error.code : 'max_data_or_receipt_unavailable';
    console.error('MAX photo packet refused: ' + reason);
    process.exitCode = 1;
  });
}
