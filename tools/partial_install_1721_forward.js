'use strict';

const {createHash} = require('node:crypto');
const {isDeepStrictEqual: equal} = require('node:util');
const {
  binding, census, complete, installationSteps, jobs, nativeLog, proveCheckedSuccessor, verifyPull, verifyRun
} = require('./partial_install_1662_forward');

const MAIN = '3073195685c8d57808256af6836182262f25fead';
const HEAD = '3d1504663c3b109921ac5f2eb56dcfa35e4295f2';
const BRANCH = 'fix/pr1721-checked-forward-install-20261125';
const PRIOR = 'ad970aaa2c89ccc6eb2fcded3c56b5146fffeaf5';
const DIAGNOSTICS = '6f6034d560821b99b64d74d6a90ff0fe6423c499';
const BASELINE_BLOB = '8f5d51cb0083aabe7b667d08cebda7b093ab11f1';
const BASELINE_DIGEST = '96ede11ff7547a2d1e6496b39bca1e3bf107f7689103c922a44c6e2f0d3e7fb5';
const DEPLOY = 45638205090;
const INSTALLER = 139299134637;
const FINAL_VERIFY = 139301040565;
const BIND = {"comment_id":6928374515,"head_sha":"3d1504663c3b109921ac5f2eb56dcfa35e4295f2","owner_id":226193297,"updated_at":"2026-11-25T14:48:12Z"};
const AUDIT = Object.freeze({
  run:45644958455, job:139313325305, workflow:430512750,
  source:'2d4861103c9fd2cb4304090d1ae54c94f60ba701', branch:'audit/pr1721-partial-install-preflight-20261125',
  path:'.github/workflows/pr1721-partial-install-preflight.yml', blob:'26f5dd64ddd60698681263943c13dfc777e4292c',
  digest:'228fbd014bdfe59b0b1b0e864b5fa31a05a69a9976e6c575609a3d27b16e619e'
});
const FILES = {
  '.github/workflows/deploy.yml':'modified',
  'resources/max2-common-v2/release-manifest.json':'modified',
  'tests/run_deployment_release_regression.js':'modified',
  'tests/run_partial_install_1662_forward_sync.py':'modified',
  'tools/deployment_release.js':'modified',
  'tools/release_priority.js':'modified',
  'tests/run_partial_install_1721_forward_regression.js':'added',
  'tools/partial_install_1721_forward.js':'added'
};
const ORIGINAL_FILES = [
  ['manager/api.php','4c3c550f58e3b637c0874d6552651b1196ef6020'],
  ['manager/pipeline-api.php','83aa29a924f39ec061964d393798615968d258e1'],
  ['tests/run_manager_pipeline_session_regression.php','d92440aa39ac1083189d21266e75a74b0776f01d']
];
const ORIGINAL_CHILDREN = [
  ["modeled-pipeline-monitor-activation.yml",45641530337,406292013,{"activate":139301219716}],
  ["max-tls-preflight.yml",45641512005,349298737,{"strict-tls-preflight":139301115417}],
  ["modeled-conversion-monitor.yml",45641512590,406167317,{"monitor":139301114924}],
  ["max-production-health.yml",45641512129,346592110,{"inspect":139301112217}],
  ["publish-conversation-diagnostics.yml",45641512125,341141254,{"publish":139301221720}],
  ["live-session-diagnostics.yml",45641512929,341564607,{"publish":139301212024}],
  ["modeled-conversion-value-export.yml",45641512137,404979772,{"configure":139301118361,"release-slot":139301121292,"release-slot-recovery":139301208502}],
  ["tour-selection-host.yml",45641512015,396440679,{"independent-editor":139301114244,"host":139301186113}],
  ["telegram-production-health.yml",45641512271,345804963,{"inspect":139301116263}]
];

function must(test, reason) {
  if (!test) throw new Error('partial-install-1721: ' + reason);
}

function baselineBytes(file) {
  must(file?.type === 'file' && file.path === 'deploy_status.json' &&
    file.sha === BASELINE_BLOB && file.encoding === 'base64' &&
    file.size === 4206 && typeof file.content === 'string', 'previous record changed');
  const encoded = file.content.replace(/\n/g, '');
  const bytes = Buffer.from(encoded, 'base64');
  must(bytes.length === file.size && bytes.toString('base64') === encoded &&
    createHash('sha256').update(bytes).digest('hex') === BASELINE_DIGEST &&
    createHash('sha1').update('blob ' + bytes.length + '\0').update(bytes).digest('hex') === BASELINE_BLOB,
  'previous record bytes changed');
  return bytes;
}

async function priorInstallation(api) {
  const deploy = 45629187135, closer = 45632250339;
  const [file, run, terminalRun, pr, terminalJobs, artifacts] = await Promise.all([
    api.get('/contents/deploy_status.json?ref=' + DIAGNOSTICS),
    api.get('/actions/runs/' + deploy), api.get('/actions/runs/' + closer),
    api.get('/pulls/1719'), jobs(api,closer,PRIOR),
    api.get('/actions/runs/' + closer + '/artifacts?per_page=100')
  ]);
  const record = JSON.parse(baselineBytes(file));
  const release = record.repository_release;
  must(record.sha === PRIOR && record.run_id === String(deploy) &&
    release.application_sha === PRIOR && release.repository_sha === PRIOR &&
    release.kind === 'installed' && release.phase === 'pending_terminal' &&
    release.release.run_id === String(deploy) && release.closure.run_id === String(closer),
  'previous installation changed');
  verifyPull(pr,1719,PRIOR,'cdac2729bd4849c871a93db053ed412bdbe80e7f');
  const {pr:boundPr,...saved} = release.closure.binding;
  must(boundPr === 1719 && equal(binding(pr),saved) && saved.comment_id === BIND.comment_id &&
    saved.owner_id === BIND.owner_id && Date.parse(saved.updated_at) < Date.parse(BIND.updated_at),
  'previous publication lineage changed');
  verifyRun(run,deploy,'deploy.yml',340734782,PRIOR,'main','push','success');
  verifyRun(terminalRun,closer,'modeled-conversion-value-export.yml',404979772,PRIOR,'main','workflow_run','success');
  must(terminalRun.display_title === 'deploy-proof:v1:' + deploy + ':1', 'wrong previous closer');
  const terminal = terminalJobs.find(row => row.name === 'release-slot');
  must(terminal?.id === 139268257319 && terminal.conclusion === 'success' && Array.isArray(terminal.steps),
  'previous terminal failed');
  for (const name of ['Bind the terminal candidate to the original closer run — ' + BASELINE_DIGEST,
    release.closure.terminal_step]) {
    const steps = terminal.steps.filter(row => row.name === name);
    must(steps.length === 1 && steps[0].status === 'completed' && steps[0].conclusion === 'success',
    'previous terminal did not bind accepted bytes');
  }
  const proof = complete(artifacts,'artifacts');
  must(proof.length === 1 && proof[0].id === 15032311025 && proof[0].expired === false &&
    proof[0].name === 'deployment-release-terminal-' + closer + '-1' &&
    proof[0].digest === 'sha256:b47999c7d28f1c37b3a59bdf5e9db7e32ec9e6ec2825f43a707df46105ec42b3' &&
    proof[0].workflow_run?.id === closer && proof[0].workflow_run?.head_sha === PRIOR,
  'previous terminal artifact changed');
}

function requireCheckoutTimeout(lines) {
  const marker = 'production_sha=' + MAIN;
  const index = lines.indexOf(marker);
  must(index > 0 && index === lines.lastIndexOf(marker), 'no unique installed checkout');
  const guard = JSON.parse(lines[index - 1]);
  must(['ok','containment_ok','write_checked','write_ok','outside_document_root','legacy_fenced',
    'directory_private','file_private'].every(key => guard[key] === true) &&
    guard.check_only === false, 'local containment unconfirmed');
  for (const attempt of [1,2]) {
    const timeout = new RegExp('^curl: \\(28\\) Operation timed out after 15[0-9]{3} milliseconds with 0 bytes received$');
    const probe = new RegExp('^public_runtime_log_fence path=structured_events\\.log attempt=' + attempt +
      ' curl_exit=28 http=000 dns_s=[0-9.]+ connect_s=[0-9.]+ tls_s=[0-9.]+ first_byte_s=0\\.000000 total_s=[0-9.]+$');
    must(timeout.test(lines[index + 2 * attempt - 1] ?? '') &&
      probe.test(lines[index + 2 * attempt] ?? ''), 'original HTTPS timeout changed');
  }
  must(lines[index+5] === '##[error]Process completed with exit code 28.' &&
    lines.slice(index+5).includes('  SYNC_OUTCOME: failure'), 'original sync outcome changed');
}

async function originalInstallation(api) {
  const [run, commit, pr, files, all, records, artifacts, log] = await Promise.all([
    api.get('/actions/runs/' + DEPLOY), api.get('/git/commits/' + MAIN),
    api.get('/pulls/1721'), api.get('/pulls/1721/files?per_page=100&page=1'),
    census(api,MAIN), jobs(api,DEPLOY,MAIN),
    api.get('/actions/runs/' + DEPLOY + '/artifacts?per_page=100'), api.jobLog(INSTALLER)
  ]);
  verifyRun(run,DEPLOY,'deploy.yml',340734782,MAIN,'main','push','failure');
  verifyPull(pr,1721,MAIN,HEAD);
  must(equal(binding(pr),BIND) && commit.sha === MAIN &&
    equal(commit.parents.map(row => row.sha),[PRIOR,HEAD]), 'original Manager2 merge changed');
  must(pr.changed_files === ORIGINAL_FILES.length && Array.isArray(files) &&
    equal(files.map(row => [row.filename,row.sha,row.status]),ORIGINAL_FILES.map(([path,blob]) =>
      [path,blob,'modified'])), 'original Manager2 files changed');
  must(complete(artifacts,'artifacts').length === 0 &&
    equal(all.filter(row => row.path === '.github/workflows/deploy.yml').map(row => row.id),[DEPLOY]),
  'original deployment replay or artifact');
  const outcomes = Object.fromEntries(records.map(row => [row.name,row.conclusion]));
  must(equal(outcomes,{'verify-inventory':'success','verify-syntax':'success',
    'verify / diagnostics':'success','verify / application':'success',deploy:'failure',verify:'skipped'}),
  'original verification outcomes changed');
  const install = records.find(row => row.name === 'deploy');
  const verify = records.find(row => row.name === 'verify');
  must(install.id === INSTALLER && verify.id === FINAL_VERIFY && equal(verify.steps,[]) &&
    install.steps.every(row => row.status === 'completed') &&
    equal(install.steps.map(row => [row.name,row.conclusion]),installationSteps),
  'original installation steps changed');
  requireCheckoutTimeout(nativeLog(log,'4ffb6383f87cd5bb0b261b1500695d0e792680f5c0a3fda52b884be27673993a'));

  const downstream = all.filter(row => row.event === 'workflow_run');
  must(downstream.length === ORIGINAL_CHILDREN.length, 'original downstream coverage changed');
  await Promise.all(ORIGINAL_CHILDREN.map(async ([file,id,workflow,expected]) => {
    const child = downstream.find(row => row.id === id);
    verifyRun(child,id,file,workflow,MAIN,'main','workflow_run','skipped');
    const title = file === 'modeled-pipeline-monitor-activation.yml'
      ? 'monitor-proof:v1:45641512590:1' : 'deploy-proof:v1:' + DEPLOY + ':1';
    const childJobs = await jobs(api,id,MAIN);
    must(child.display_title === title &&
      equal(Object.fromEntries(childJobs.map(row => [row.name,row.id])),expected) &&
      childJobs.every(row => row.conclusion === 'skipped' && equal(row.steps,[])),
    'a downstream job ran or changed');
  }));
}

function requireRecoveredHttps(lines) {
  const status = lines.filter(line => line.startsWith('original_guard_current_http='));
  const timing = lines.filter(line => line.startsWith('curl_exit='));
  must(status.length === 1 && /^original_guard_current_http=(403|404)$/.test(status[0]) &&
    timing.length === 1 && /^curl_exit=0 connect_s=[0-9.]+ tls_s=[0-9.]+ first_byte_s=[0-9.]+$/.test(timing[0]),
  'public HTTPS recovery unconfirmed');
  return Number(status[0].split('=')[1]);
}

async function checkedHttpsPreflight(api) {
  const a = AUDIT;
  const [run, commit, relation, all, records, artifacts, log] = await Promise.all([
    api.get('/actions/runs/' + a.run), api.get('/git/commits/' + a.source),
    api.get('/compare/' + MAIN + '...' + a.source + '?per_page=1'), census(api,a.source),
    jobs(api,a.run,a.source), api.get('/actions/runs/' + a.run + '/artifacts?per_page=100'),
    api.jobLog(a.job)
  ]);
  verifyRun(run,a.run,a.path.split('/').at(-1),a.workflow,a.source,a.branch,'push','success');
  must(commit.sha === a.source && equal(commit.parents.map(row => row.sha),[MAIN]) &&
    relation.status === 'ahead' && relation.merge_base_commit?.sha === MAIN &&
    relation.files.length === 1 && relation.files[0].filename === a.path &&
    relation.files[0].status === 'added' && relation.files[0].sha === a.blob &&
    equal(all.filter(row => row.path === a.path).map(row => row.id),[a.run]) &&
    complete(artifacts,'artifacts').length === 0, 'HTTPS audit source changed');
  const steps = ['Set up job','Verify exact source, original job history and active publisher',
    'Check the original public containment header without reading content','Acknowledge preflight scope','Complete job'];
  must(records.length === 1 && records[0].id === a.job && records[0].name === 'preflight' &&
    records[0].conclusion === 'success' && equal(records[0].steps.map(row => row.name),steps) &&
    records[0].steps.every(row => row.status === 'completed' && row.conclusion === 'success'),
  'HTTPS audit did not finish');
  return requireRecoveredHttps(nativeLog(log,a.digest));
}

function proveForward(options) {
  return proveCheckedSuccessor(options,{
    previousPr:1721, main:MAIN, head:HEAD, binding:BIND, pr:options.pr, branch:BRANCH,
    files:FILES, manifestBlob:'c64600b443c096d4c3d7c3b6a39ed026ec74153d', diagnostics:DIAGNOSTICS, baselineBytes,
    history:async api => {
      const [, , status] = await Promise.all([priorInstallation(api),originalInstallation(api),checkedHttpsPreflight(api)]);
      return {previous_pr:1721, previous_sha:MAIN, previous_run_id:String(DEPLOY),
        previous_installation:'partial_after_checkout', previous_install_job_id:INSTALLER,
        previous_application_sha:PRIOR, previous_accepted_record_sha256:BASELINE_DIGEST,
        previous_public_check_run:AUDIT.run, previous_observed_http:status};
    }
  });
}

module.exports = {BRANCH, proveForward, requireCheckoutTimeout, requireRecoveredHttps};
