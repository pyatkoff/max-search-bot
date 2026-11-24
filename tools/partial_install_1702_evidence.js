'use strict';

const {createHash} = require('node:crypto');
const {isDeepStrictEqual: equal} = require('node:util');
const {
  complete, verifyRun, census, jobs, binding, verifyPull, nativeLog, installationSteps,
} = require('./partial_install_1662_forward');

const MAIN = '32155b9eb2c8606b61554951bfdc988d70bbcb39';
const HEAD = '9b0a8a5a4dc252b977f95bee2b890287cdbbec1f';
const PRIOR = '5e0eeefd0a673fe492329a21f980351f80c2eccb';
const DIAGNOSTICS = 'a22ddee1b6993123b0a0ed6a1fe7e5ab24f211f6';
const BASELINE_BLOB = '1e0ebc1ff760e4fa4a5bd4fcd777d100fd4cf88c';
const BASELINE_DIGEST = '9119e170cb788184c8ebff5b3fdeea69d35dec6b57f887aea59ba70a2903da9a';
const DEPLOY = 45416010115;
const INSTALL_JOB = 138531045324;
const DEPLOY_LOG_DIGEST = '439d370aea0c6f5879f57ef94e15ed50cf5dc5866b5748da4aa94f8b664fa49e';
const REPO = 'pyatkoff/max-search-bot';
const OWNER = 226193297;
const PREFIX = '.github/workflows/';

const wave = [
  ['modeled-pipeline-monitor-activation.yml',45418934589,406292013,{activate:138532775781}],
  ['modeled-conversion-value-export.yml',45418910433,404979772,{configure:138532694293,'release-slot':138532695302,'release-slot-recovery':138532693161}],
  ['max-production-health.yml',45418911245,346592110,{inspect:138532695631}],
  ['tour-selection-host.yml',45418910962,396440679,{host:138532694541,'independent-editor':138532780075}],
  ['telegram-production-health.yml',45418911457,345804963,{inspect:138532698463}],
  ['max-tls-preflight.yml',45418910722,349298737,{'strict-tls-preflight':138532694536}],
  ['publish-conversation-diagnostics.yml',45418910989,341141254,{publish:138532697515}],
  ['live-session-diagnostics.yml',45418910943,341564607,{publish:138532695204}],
  ['modeled-conversion-monitor.yml',45418910775,406167317,{monitor:138532699476}],
];

function must(ok, reason) {
  if (!ok) throw new Error('partial-install-1702: ' + reason);
}

function baselineBytes(file) {
  must(file?.type === 'file' && file.path === 'deploy_status.json' && file.encoding === 'base64' &&
    file.sha === BASELINE_BLOB && file.size === 4206 && typeof file.content === 'string', 'baseline source changed');
  const encoded = file.content.replace(/\n/g, '');
  const bytes = Buffer.from(encoded, 'base64');
  must(bytes.length === file.size && bytes.toString('base64') === encoded &&
    createHash('sha256').update(bytes).digest('hex') === BASELINE_DIGEST &&
    createHash('sha1').update('blob ' + bytes.length + '\0').update(bytes).digest('hex') === BASELINE_BLOB,
    'baseline bytes changed');
  return bytes;
}

async function acceptedBaseline(api) {
  const [file, run, closer, pull, publisher, terminalJobs] = await Promise.all([
    api.get('/contents/deploy_status.json?ref=' + DIAGNOSTICS), api.get('/actions/runs/45407723584'),
    api.get('/actions/runs/45410906737'), api.get('/pulls/1700'), api.get('/issues/comments/7097821694'),
    jobs(api,45410906737,PRIOR),
  ]);
  const record = JSON.parse(baselineBytes(file));
  const release = record.repository_release;
  must(record.sha === PRIOR && record.run_id === '45407723584' && release.repository_sha === PRIOR &&
    release.application_sha === PRIOR && release.phase === 'pending_terminal' && release.kind === 'installed' &&
    release.release.run_id === '45407723584' && release.closure.run_id === '45410906737', 'wrong prior installation');
  verifyRun(run,45407723584,'deploy.yml',340734782,PRIOR,'main','push','success');
  verifyRun(closer,45410906737,'modeled-conversion-value-export.yml',404979772,PRIOR,'main','workflow_run','success');
  must(closer.display_title === 'deploy-proof:v1:45407723584:1', 'wrong prior terminal source');
  const terminal = terminalJobs.find(j => j.name === 'release-slot');
  must(terminal?.id === 138504089396 && terminal.conclusion === 'success', 'prior publisher terminal failed');
  for (const name of ['Bind the terminal candidate to the original closer run — ' + BASELINE_DIGEST, release.closure.terminal_step]) {
    const matched = terminal.steps.filter(step => step.name === name);
    must(matched.length === 1 && matched[0].status === 'completed' && matched[0].conclusion === 'success',
      'prior closure did not bind accepted bytes');
  }
  const {pr, ...saved} = release.closure.binding;
  verifyPull(pull,1700,PRIOR,saved.head_sha);
  must(pr === 1700 && equal(binding(pull),saved) && publisher.id === saved.comment_id &&
    publisher.user.id === OWNER && publisher.body === release.closure.expected_terminal &&
    publisher.issue_url === 'https://api.github.com/repos/' + REPO + '/issues/1122', 'prior terminal owner changed');
}

async function partialOriginal(api) {
  const [run, commit, pull, files, history, rows, artifacts, log] = await Promise.all([
    api.get('/actions/runs/' + DEPLOY), api.get('/git/commits/' + MAIN), api.get('/pulls/1702'),
    api.get('/pulls/1702/files?per_page=100&page=1'), census(api,MAIN), jobs(api,DEPLOY,MAIN),
    api.get('/actions/runs/' + DEPLOY + '/artifacts?per_page=100'), api.jobLog(INSTALL_JOB),
  ]);
  verifyRun(run,DEPLOY,'deploy.yml',340734782,MAIN,'main','push','failure');
  verifyPull(pull,1702,MAIN,HEAD);
  must(equal(binding(pull),{comment_id:6818332618,head_sha:HEAD,owner_id:OWNER,updated_at:'2026-11-24T14:35:09Z'}) &&
    commit.sha === MAIN && equal(commit.parents.map(p => p.sha),[PRIOR,HEAD]), 'original route merge changed');
  must(pull.changed_files === 1 && Array.isArray(files) && files.length === 1 &&
    files[0].filename === 'hotel-posts-telegram-main19.php' && files[0].status === 'modified' &&
    files[0].sha === '9c56a05cb9b3617def5b4a9ee897637ca03e3873', 'original route source changed');
  must(complete(artifacts,'artifacts').length === 0 &&
    equal(history.filter(r => r.path === PREFIX + 'deploy.yml').map(r => r.id),[DEPLOY]),
    'original deployment replay or artifact');
  const outcomes = Object.fromEntries(rows.map(j => [j.name, j.conclusion]));
  must(equal(outcomes,{'verify-syntax':'success','verify-inventory':'success','verify / application':'success',
    'verify / diagnostics':'success',deploy:'failure',verify:'skipped'}), 'original deployment jobs changed');
  const install = rows.find(j => j.name === 'deploy');
  const verify = rows.find(j => j.name === 'verify');
  must(install.id === INSTALL_JOB && verify.id === 138532634860 && equal(verify.steps,[]) &&
    install.steps.every(s => s.status === 'completed') &&
    equal(install.steps.map(s => [s.name,s.conclusion]),installationSteps), 'partial installation stages changed');
  const lines = nativeLog(log,DEPLOY_LOG_DIGEST);
  const index = lines.indexOf('production_sha=' + MAIN);
  must(index > 0 && lines[index + 1] === 'curl: (28) Operation timed out after 15002 milliseconds with 0 bytes received' &&
    lines[index + 2] === '##[error]Process completed with exit code 28.' &&
    lines.slice(index + 2).includes('  SYNC_OUTCOME: failure'), 'partial checkout timeout changed');
  const guard = JSON.parse(lines[index - 1]);
  must(guard.ok === true && guard.check_only === false && guard.containment_ok === true && guard.write_checked === true &&
    guard.write_ok === true && guard.outside_document_root === true && guard.legacy_fenced === true &&
    guard.directory_private === true && guard.file_private === true, 'local checkout marker unconfirmed');
  const downstream = history.filter(r => r.event === 'workflow_run');
  must(downstream.length === wave.length, 'original downstream coverage changed');
  await Promise.all(wave.map(async ([file,id,workflow,expected]) => {
    const child = downstream.find(r => r.id === id);
    verifyRun(child,id,file,workflow,MAIN,'main','workflow_run','skipped');
    const title = file === 'modeled-pipeline-monitor-activation.yml' ?
      'monitor-proof:v1:45418910775:1' : 'deploy-proof:v1:' + DEPLOY + ':1';
    const children = await jobs(api,id,MAIN);
    must(child.display_title === title && equal(Object.fromEntries(children.map(j => [j.name,j.id])),expected) &&
      children.every(j => j.conclusion === 'skipped' && equal(j.steps,[])), 'downstream source or execution changed');
  }));
}

async function historical(api) {
  await Promise.all([acceptedBaseline(api), partialOriginal(api)]);
}

module.exports = {DIAGNOSTICS, BASELINE_DIGEST, baselineBytes, historical};
