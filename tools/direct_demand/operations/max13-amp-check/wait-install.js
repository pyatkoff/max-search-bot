'use strict';

const crypto = require('node:crypto');
const REPO = 'pyatkoff/max-search-bot';
const OWNER = 226193297;
const SOURCE = 6961029169;
const DEPLOY = 45145240005;
const PUBLISHER = 7077907800;
const TERMINAL = 'PR1659: установлено, выпуск свободен';
const LIMIT_MS = 900000;
const INTERVAL_MS = 15000;
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const defaultSleep = ms => new Promise(resolve => setTimeout(resolve, ms));
function need(ok, code) {
  if (!ok) { const error = new Error(code); error.code = code; throw error; }
}

/** Readiness hint only. The existing full canonical admission MUST follow. */
async function waitForInstallation(get, {
  applicationSha, deployRunId = DEPLOY, publisherId = PUBLISHER, expectedSource,
  now = Date.now, sleep = defaultSleep, observe = () => {},
} = {}) {
  need(typeof get === 'function' && typeof now === 'function' && typeof sleep === 'function'
    && typeof observe === 'function' && /^[a-f0-9]{40}$/.test(applicationSha)
    && Number.isSafeInteger(deployRunId) && deployRunId > 0 && publisherId === PUBLISHER,
    'installation_wait_binding_invalid');
  need(expectedSource?.id === SOURCE && typeof expectedSource.updated_at === 'string'
    && Number.isFinite(Date.parse(expectedSource.updated_at))
    && typeof expectedSource.body_sha256 === 'string' && /^[a-f0-9]{64}$/.test(expectedSource.body_sha256),
    'installation_wait_source_binding_invalid');
  const started = now();
  need(Number.isFinite(started) && Number.isFinite(new Date(started).getTime()), 'installation_wait_clock_invalid');
  let last = started, pass = 0, stage = 'main', timer;
  const controller = new AbortController();
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => {
      controller.abort();
      const error = new Error('installation_wait_timeout'); error.code = error.message; reject(error);
    }, LIMIT_MS);
  });
  const clock = () => {
    const value = now();
    need(Number.isFinite(value) && value >= last && Number.isFinite(new Date(value).getTime()), 'installation_wait_clock_invalid');
    last = value; return value;
  };
  const remaining = () => {
    const value = LIMIT_MS - (clock() - started);
    need(value > 0, 'installation_wait_timeout'); return value;
  };
  const bounded = promise => Promise.race([promise, timeout]);
  const emit = fields => bounded(Promise.resolve(observe(Object.freeze({
    pass, observed_at_utc: new Date(last).toISOString(), elapsed_ms: last - started,
    application_sha: applicationSha, deploy_run_id: deployRunId, publisher_id: publisherId,
    ...fields,
  }))));
  const read = async (path, label) => {
    stage = label; remaining();
    let result;
    try { result = await bounded(Promise.resolve().then(() => get(path, {signal: controller.signal}))); }
    catch (error) { need(error?.code === 'installation_wait_timeout', 'installation_wait_read_failed'); throw error; }
    remaining(); return result;
  };
  const guards = async () => {
    const main = await read('/git/ref/heads/main', 'main');
    need(main?.object?.sha === applicationSha, 'installation_wait_main_changed');
    const source = await read('/issues/comments/' + SOURCE, 'source');
    need(source?.id === SOURCE && source.user?.id === OWNER
      && source.issue_url === 'https://api.github.com/repos/' + REPO + '/issues/1566'
      && source.updated_at === expectedSource.updated_at && typeof source.body === 'string'
      && sha(Buffer.from(source.body, 'utf8')) === expectedSource.body_sha256,
      'installation_wait_source_changed');
  };
  try {
    // Independent pass bound also prevents a faulty injected clock/sleeper
    // from creating an unbounded loop. Native elapsed time has its own timer.
    for (pass = 1; pass <= 60; pass++) {
      remaining(); await guards();
      const run = await read('/actions/runs/' + deployRunId, 'deploy');
      need(run?.id === deployRunId && run.run_attempt === 1 && run.head_sha === applicationSha
        && run.head_branch === 'main' && run.event === 'push' && run.path === '.github/workflows/deploy.yml'
        && run.repository?.full_name === REPO && run.head_repository?.full_name === REPO
        && run.actor?.id === OWNER && run.triggering_actor?.id === OWNER,
        'installation_wait_deploy_identity_changed');
      need(['queued', 'in_progress', 'waiting', 'requested', 'pending', 'completed'].includes(run.status),
        'installation_wait_deploy_status_invalid');
      if (run.status === 'completed') need(run.conclusion === 'success', 'installation_wait_deploy_failed');
      else need(run.conclusion === null, 'installation_wait_deploy_status_invalid');
      let publisherObserved = false, publisherReady = false;
      if (run.status === 'completed') {
        const publisher = await read('/issues/comments/' + publisherId, 'publisher');
        need(publisher?.id === publisherId && publisher.user?.id === OWNER
          && publisher.issue_url === 'https://api.github.com/repos/' + REPO + '/issues/1122'
          && typeof publisher.body === 'string', 'installation_wait_publisher_identity_changed');
        publisherObserved = true; publisherReady = publisher.body === TERMINAL;
      }
      if (publisherReady) {
        await guards(); remaining();
        const hint = {ready: true, kind: 'INSTALLATION_READINESS_HINT', canonical_admission_required: true,
          application_sha: applicationSha, deploy_run_id: deployRunId, publisher_id: publisherId,
          observed_at_utc: new Date(last).toISOString(), elapsed_ms: last - started, passes: pass};
        await emit({state: 'readiness_hint', deploy_status: 'completed', deploy_conclusion: 'success',
          publisher_observed: true, publisher_ready: true, canonical_admission_required: true});
        remaining(); return hint;
      }
      await emit({state: run.status === 'completed' ? 'waiting_publisher' : 'waiting_deploy',
        deploy_status: run.status, deploy_conclusion: run.conclusion,
        publisher_observed: publisherObserved, publisher_ready: false, canonical_admission_required: true});
      const delay = Math.min(INTERVAL_MS, remaining());
      stage = 'sleep'; await bounded(Promise.resolve(sleep(delay))); remaining();
    }
    need(false, 'installation_wait_timeout');
  } catch (error) {
    const code = typeof error?.code === 'string' && /^installation_wait_[a-z_]+$/.test(error.code)
      ? error.code : 'installation_wait_failed';
    try { await emit({state: 'failed', stage, reason: code, canonical_admission_required: true}); } catch (_) {}
    need(false, code);
  } finally {
    clearTimeout(timer); controller.abort();
  }
}

async function main() {
  const fs = require('node:fs'), path = require('node:path');
  need(process.argv.length === 5, 'installation_wait_arguments');
  const bindingPath = path.resolve(process.argv[2]), canonical = path.resolve(process.argv[3]);
  const output = path.resolve(process.argv[4]);
  need(output === path.resolve('max13-read/wait-evidence.json'), 'installation_wait_evidence_path_invalid');
  fs.mkdirSync(path.dirname(output), {recursive: true, mode: 0o700});
  need(fs.realpathSync(path.dirname(output)) === path.dirname(output)
    && (!fs.existsSync(output) || !fs.lstatSync(output).isSymbolicLink()), 'installation_wait_evidence_path_invalid');
  const evidence = {schema: 1, kind: 'MAX13_INSTALLATION_WAIT', canonical_admission_required: true, observations: []};
  const save = () => fs.writeFileSync(output, JSON.stringify(evidence, null, 2) + '\n', {mode: 0o600});
  try {
    const binding = JSON.parse(fs.readFileSync(bindingPath, 'utf8'));
    need(binding.schema === 1 && binding.stage === 'read' && binding.operation === 'commercial-max13-amp-read-20261123-v1'
      && binding.source_comment?.id === SOURCE && binding.approval_comment?.id === 7072422617,
      'installation_wait_binding_invalid');
    need(binding.release_modules && Object.keys(binding.release_modules).sort().join(',') === 'deployment_release.js,release_slot_close.js',
      'installation_wait_canonical_binding_invalid');
    for (const [file, expected] of Object.entries(binding.release_modules)) {
      const bytes = fs.readFileSync(path.join(canonical, file));
      const digest = crypto.createHash('sha1').update(Buffer.from('blob ' + bytes.length + '\0')).update(bytes).digest('hex');
      need(typeof expected === 'string' && /^[a-f0-9]{40}$/.test(expected) && digest === expected,
        'installation_wait_canonical_module_changed');
    }
    const R = require(path.join(canonical, 'deployment_release.js'));
    need(R.API_ROOT === '/repos/' + REPO, 'installation_wait_canonical_repository_changed');
    const api = R.makeReadApi(process.env.GITHUB_TOKEN);
    const get = (p, {signal} = {}) => api('GET', R.API_ROOT + p, undefined, false, signal);
    evidence.outcome = await waitForInstallation(get, {
      applicationSha: binding.application_sha, expectedSource: binding.source_comment,
      observe: record => { evidence.observations.push(record); save(); process.stdout.write(JSON.stringify({wait_install: record}) + '\n'); },
    });
    save(); process.stdout.write(JSON.stringify(evidence.outcome) + '\n');
  } catch (error) {
    const code = typeof error?.code === 'string' && /^installation_wait_[a-z_]+$/.test(error.code)
      ? error.code : 'installation_wait_failed';
    evidence.outcome = {ready: false, reason: code, canonical_admission_required: true}; save(); need(false, code);
  }
}

module.exports = Object.freeze({waitForInstallation});
if (require.main === module) main().catch(error => {
  const code = typeof error?.code === 'string' && /^installation_wait_[a-z_]+$/.test(error.code)
    ? error.code : 'installation_wait_failed';
  process.stderr.write(code + '\n'); process.exitCode = 1;
});
