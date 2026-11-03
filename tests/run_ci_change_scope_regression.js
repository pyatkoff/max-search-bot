#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync, spawnSync } = require('node:child_process');
const { classifyChanges, parseRawDiff, oauthSpecOnlyChange, editorManifestVersionOnlyChange } = require('../tools/ci_change_scope');
const selector = path.resolve(__dirname, '../tools/ci_change_scope.js');
const oauth = 'manager/ai-oauth.php';
const fixture = 'tests/visual/fixtures/oauth-consent.js';
const doc = 'docs/MANAGER_PREPARED_REPLIES.md';
const tour = 'manager/assets/workspace-v2-tour-selection.js';
const tourService = 'services/ManagerTourSelectionShareService.php';
const tourDoc = 'docs/MANAGER_TOUR_SELECTIONS.md';
const editorManifest = 'modules/tour-selections/editor.json';
const editorBase = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', editorManifest), 'utf8'));
const editorNext = { ...editorBase, version: editorBase.version.replace(/\d+$/, patch => String(Number(patch) + 1)) };
const collectorPage = 'manager/tour-selection-collector.php';
const collectorPrice = 'extensions/selection-collector/price-adjustment.js';
// Actual PR1108 changed only this component, despite touching more than its
// original editor allowlist. Keep its complete path shape as a regression.
const collector1108 = [
  tourDoc, 'extensions/selection-collector/README.md',
  'extensions/selection-collector/anex-content.js',
  'extensions/selection-collector/anytour-selection-collector.zip',
  'extensions/selection-collector/collector-client.js',
  'extensions/selection-collector/content.css',
  'extensions/selection-collector/content.js',
  'extensions/selection-collector/funsun.js',
  'extensions/selection-collector/manifest.json',
  'extensions/selection-collector/popup.css',
  'extensions/selection-collector/tourvisor-content.js',
  'services/ManagerTourSelectionImportDraft.php',
  'tests/support/manager-tour-collector.php',
  'tests/visual/fixtures/selection-collector.js',
  'tests/visual/manager-tour-selection.spec.js',
];
const browserSpec = 'tests/visual/manager-ai-draft.spec.js';
const sharedSpec = "const {test}=require('@playwright/test');\ntest('AI baseline',()=>{});\n";
const fixtureImport = "const {startOAuthFixture}=require('./fixtures/oauth-consent');\n";
const consentLoop = "\nfor(const choice of ['allow','deny','legacy policy'])test('OAuth native '+choice,async()=>{\n  await startOAuthFixture(choice);\n});\n";
let assertions = 0;
function check(actual, expected, message) { assert.equal(actual, expected, message); assertions++; }
const classify = changes => classifyChanges(changes).mode;
check(classify([{ status: 'M', path: oauth }]), 'oauth', 'bounded OAuth modification');
check(classify([{ status: 'M', path: oauth }, { status: 'M', path: doc }]), 'oauth', 'OAuth with documentation');
check(classify([{ status: 'A', path: fixture }]), 'oauth', 'new isolated browser fixture');
check(classify([{ status: 'M', path: 'tests/visual/manager-ai-draft.spec.js' }]), 'oauth', 'whole browser spec remains selected');
for(const file of [tour,tourService,'selection/view.php','selection/selection.css','manager/tour-selections-api.php','tests/visual/manager-tour-selection.spec.js'])check(classify([{status:'M',path:file}]),'tour-selection','existing isolated selection owner: '+file);
check(classify([{status:'M',path:tour},{status:'M',path:tourDoc}]),'tour-selection','selection with its documentation');
check(classify([{status:'M',path:tourDoc}]),'full','selection documentation alone does not narrow');
check(classify([{status:'M',path:collectorPage}]),'tour-selection','PR1104 collector-page copy stays component-scoped');
check(classify(collector1108.map(path=>({status:'M',path}))),'tour-selection','PR1108 collector package and import-boundary path shape');
for(const file of ['manager/assets/tour-selection-collector.js','manager/assets/tour-selection-collector.css',
  'services/ManagerTourSelectionAnexImportDraft.php','services/ManagerTourSelectionTourvisorImportDraft.php',
  'tests/run_manager_tour_collector_behavior_regression.js','tests/run_manager_tour_collector_regression.php',
  'tests/mysql/manager-tour-collector.php','extensions/selection-collector/background.js',
  'extensions/selection-collector/bridge.js','extensions/selection-collector/build_package.py',
  'extensions/selection-collector/anex.js','extensions/selection-collector/tourvisor.js',
  'extensions/selection-collector/popup.html','extensions/selection-collector/popup.js',collectorPrice]) {
  check(classify([{status:'M',path:file}]),'tour-selection','reviewed collector dependency: '+file);
}
check(classify([{status:'M',path:'extensions/selection-collector/README.md'}]),'full','collector README alone does not narrow');
check(classify([{status:'M',path:tourDoc},{status:'M',path:'extensions/selection-collector/README.md'}]),'full','component documentation only stays full');
check(classify([{status:'A',path:collectorPrice,oldMode:'000000',newMode:'100644'}]),'tour-selection','reviewed price-calculator addition is component-only');
for(const change of [
  {status:'A',path:collectorPrice},
  {status:'A',path:collectorPrice,oldMode:'100644',newMode:'100644'},
  ...['100755','120000','160000'].map(newMode=>({status:'A',path:collectorPrice,oldMode:'000000',newMode})),
  {status:'M',path:collectorPage,oldMode:'100644',newMode:'100755'},
  ...['D','R100','C100','T','U'].map(status=>({status,path:collectorPrice})),
])check(classify([change]),'full','collector addition/status/mode boundary');
for(const file of ['extensions/selection-collector/new-adapter.js','extensions/selection-collector/popup.js/extra',
  './extensions/selection-collector/popup.js','manager/assets/tour-selection-collector.js.bak',
  'services/ManagerTourSelectionDraft.php','services/ManagerTourSelectionCollectorSession.php',
  'services/TourSelectionBotEntryService.php','tests/visual/fixtures/internal-notes.js','manager/lib/http.php']) {
  check(classify([{status:'M',path:collectorPage},{status:'M',path:file}]),'full','collector unknown/shared neighbor stays full: '+file);
}
check(classify([{status:'A',path:'extensions/selection-collector/new-adapter.js',oldMode:'000000',newMode:'100644'}]),'full','new unreviewed adapters never inherit component scope');
for(const file of ['manager/_bootstrap.php','manager/assets/workspace-v2.js','services/ManagerAuthService.php','services/TourOfferCallbackService.php','tests/visual/fixtures/internal-notes.js','tests/visual/manager-media-compose.spec.js','migrations/056_future.sql',oauth,'tools/ci_change_scope.js','.github/workflows/workspace-v2-visual-qa.yml'])check(classify([{status:'M',path:tour},{status:'M',path:file}]),'full','selection mixed/shared change: '+file);
for(const status of ['A','D','R100','C100','T','U',undefined])check(classify([{status,path:tour}]),'full','selection requires existing regular modification: '+status);
check(classify([{status:'M',path:tour,oldMode:'100644',newMode:'100755'}]),'full','selection mode changes stay full');
for (const status of ['A','D','R100','C100','T','U']) {
  check(classify([{status,path:editorManifest,oldMode:'100644',newMode:'100644'}]),'full','editor manifest requires an existing modification: '+status);
}
check(classify([{status:'M',path:editorManifest,oldMode:'100644',newMode:'100755'}]),'full','editor manifest mode change stays full');
for (const changes of [[], null, [{}], [{ status: 'M', path: doc }], [{ status: 'M', path: oauth, previousPath: oauth }]]) {
  check(classify(changes), 'full', 'malformed, empty, documentation or rename');
}
for (const status of ['A', 'D', 'R', 'R100', 'C100', 'T', 'U', 'X', 'B', 'M100', '', undefined]) {
  check(classify([{ status, path: oauth }]), 'full', `reject status ${status}`);
}
for (const file of ['manager/_bootstrap.php', 'manager/ai-mcp.php', 'services/ManagerAuth.php', '.github/workflows/test.yml', 'migrations/100.sql', `${oauth}/extra`, `./${oauth}`]) {
  check(classify([{ status: 'M', path: oauth }, { status: 'M', path: file }]), 'full', `mixed shared path: ${file}`);
}
for (const [oldMode, newMode] of [['100644', '100755'], ['120000', '120000'], ['160000', '160000'], ['100644', undefined]]) {
  check(classify([{ status: 'M', path: oauth, oldMode, newMode }]), 'full', 'mode/type boundary');
}
check(classify([{ status: 'M', path: oauth, oldMode: '100755', newMode: '100755' }]), 'oauth', 'unchanged regular executable mode');
check(classify([{ status: 'A', path: fixture, oldMode: '000000', newMode: '120000' }]), 'full', 'added fixture must be regular');
check(classify([{ status: 'A', path: fixture, oldMode: '100644', newMode: '100644' }]), 'full', 'malformed fixture addition');
assert.throws(() => parseRawDiff(':garbage\0file\0')); assertions++;
assert.throws(() => parseRawDiff('unterminated')); assertions++;
check(oauthSpecOnlyChange(sharedSpec, fixtureImport + sharedSpec + consentLoop), true, 'native consent append leaves shared AI source unchanged');
const editorChange = head => editorManifestVersionOnlyChange(JSON.stringify(editorBase), JSON.stringify(head));
check(editorChange(editorNext), true, 'an editor version bump does not change package or backend dependencies');
check(editorChange(Object.fromEntries(Object.entries(editorNext).reverse())), true, 'manifest key order does not change its contract');
check(editorChange(editorBase), false, 'formatting alone is not an editor release');
for (const patch of [
  { schema: 2 }, { name: 'other-module' }, { contract: 'tour-selection-editor-host-v2' },
  { files: { ...editorBase.files, 'http.js': 'manager/assets/other-http-client.js' } },
  { files: { ...editorBase.files, 'extra.js': 'manager/assets/workspace-v2.js' } },
  { backend_inputs: [...editorBase.backend_inputs, 'services/ManagerAuthService.php'] },
  { backend_inputs: [...editorBase.backend_inputs].reverse() },
  { future_contract: true }, { files: null }, { files: [] }, { backend_inputs: [] },
]) check(editorChange({ ...editorNext, ...patch }), false, 'version bump cannot hide a package/host/backend contract change');
for (const version of [null, 2, '', '0.2', '0.2.2-dev', '0.2.2\n', '0.2.2\r\n', '../../0.2.2']) {
  check(editorChange({ ...editorNext, version }), false, 'invalid editor version keeps full coverage');
}
for (const source of ['{broken', 'null', '[]', '"manifest"', '{}']) {
  check(editorManifestVersionOnlyChange(source, JSON.stringify(editorNext)), false, 'invalid base manifest keeps full coverage');
  check(editorManifestVersionOnlyChange(JSON.stringify(editorBase), source), false, 'invalid head manifest keeps full coverage');
}
check(oauthSpecOnlyChange(fixtureImport + sharedSpec + consentLoop, fixtureImport + sharedSpec + consentLoop.replace('await startOAuthFixture(choice);', 'await startOAuthFixture(choice, 1);')), true, 'future OAuth-only assertion edit');
for (const changed of [
  fixtureImport + sharedSpec.replace('AI baseline', 'AI altered') + consentLoop,
  fixtureImport + sharedSpec + consentLoop + "test('extra AI',()=>{});\n",
  fixtureImport + sharedSpec + consentLoop + consentLoop,
  fixtureImport + sharedSpec + consentLoop.replace('});', '}'),
  fixtureImport + sharedSpec + consentLoop.replace("test('OAuth native '", "test.skip('OAuth native '"),
  fixtureImport + sharedSpec + consentLoop.replace('await startOAuthFixture(choice);', 'test.fixme();'),
  fixtureImport + fixtureImport + sharedSpec + consentLoop,
  fixtureImport + sharedSpec,
]) check(oauthSpecOnlyChange(sharedSpec, changed), false, 'malformed or shared-browser change falls back');

const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'ci-scope-'));
try {
  const git = args => execFileSync('git', args, { cwd: temp, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  const put = (name, content) => { fs.mkdirSync(path.dirname(path.join(temp, name)), { recursive: true }); fs.writeFileSync(path.join(temp, name), content); };
  git(['init', '-q']); git(['config', 'user.name', 'CI scope regression']); git(['config', 'user.email', 'ci@example.invalid']);
  git(['config', 'core.filemode', 'true']);
  put(oauth, 'base\n'); put(doc, 'docs\n'); put('manager/_bootstrap.php', 'shared\n');
  put(tour,'base selection\n');put(tourService,'<?php // base\n');put(tourDoc,'selection docs\n');
  put(editorManifest, JSON.stringify(editorBase));
  put('tests/visual/manager-tour-selection.spec.js', 'base selection cases\n');
  put(collectorPage,'collector page base\n');
  for(const file of collector1108)put(file,'collector dependency base\n');
  put(browserSpec, sharedSpec);
  git(['add', '.']); git(['commit', '-qm', 'base']);
  const base = git(['rev-parse', 'HEAD']);
  const eventPath = path.join(temp, 'event.json');
  const outputPath = path.join(temp, 'output.txt');
  let lastProof=false;
  function run(event, overrides = {}) {
    fs.writeFileSync(eventPath, typeof event === 'string' ? event : JSON.stringify(event));
    fs.writeFileSync(outputPath, '');
    const env = { ...process.env, GITHUB_EVENT_NAME: 'pull_request', GITHUB_EVENT_PATH: eventPath, GITHUB_OUTPUT: outputPath, ...overrides };
    const result = JSON.parse(execFileSync(process.execPath, [selector], { cwd: temp, env, encoding: 'utf8' }));
    lastProof=result.proveTourSelection===true;
    check(fs.readFileSync(outputPath, 'utf8'), `mode=${result.mode}\ntour-proof=${lastProof}\n`, 'GitHub outputs match JSON');
    return result.mode;
  }
  const event = head => ({ pull_request: { base: { sha: base }, head: { sha: head } } });
  function commitCase(message, mutate, expected,proof=false) {
    git(['reset', '--hard', '-q', base]); git(['clean', '-fdq']);
    mutate(); git(['add', '-A']); git(['commit', '-qm', message]);
    check(run(event(git(['rev-parse', 'HEAD']))), expected, message);
    check(lastProof,proof,'additional component proof only for its CI-rule edits');
  }
  commitCase('selection UI change',()=>put(tour,'changed selection\n'),'tour-selection');
  commitCase('PR1171 editor UI, complete component spec and version-only manifest',()=>{
    put(tour,'changed editor history boundary\n');
    put('tests/visual/manager-tour-selection.spec.js','changed complete selection cases\n');
    put(editorManifest,JSON.stringify(editorNext));
  },'tour-selection');
  commitCase('editor manifest-only version bump retains complete component proof',()=>put(editorManifest,JSON.stringify(editorNext)),'tour-selection');
  for (const patch of [
    { files: { ...editorBase.files, 'http.js': 'manager/assets/other-http-client.js' } },
    { backend_inputs: [...editorBase.backend_inputs, 'services/ManagerAuthService.php'] },
    { contract: 'tour-selection-editor-host-v2' }, { schema: 2 },
  ]) commitCase('editor version with changed contract '+Object.keys(patch)[0],()=>{
    put(tour,'changed editor\n');put(editorManifest,JSON.stringify({ ...editorNext, ...patch }));
  },'full');
  commitCase('editor malformed manifest with UI change',()=>{put(tour,'changed editor\n');put(editorManifest,'{broken');},'full');
  commitCase('editor version with shared auth change',()=>{
    put(editorManifest,JSON.stringify(editorNext));put('manager/_bootstrap.php','changed shared\n');
  },'full');
  commitCase('editor manifest addition remains full',()=>put('modules/tour-selections/other.json',JSON.stringify(editorNext)),'full');
  commitCase('editor manifest deletion remains full',()=>fs.unlinkSync(path.join(temp,editorManifest)),'full');
  commitCase('editor manifest rename remains full',()=>fs.renameSync(path.join(temp,editorManifest),path.join(temp,'modules/tour-selections/renamed.json')),'full');
  commitCase('editor manifest mode change remains full',()=>fs.chmodSync(path.join(temp,editorManifest),0o755),'full');
  commitCase('PR1104 isolated collector copy',()=>put(collectorPage,'collector title changed\n'),'tour-selection');
  commitCase('PR1108 complete collector diff',()=>{for(const file of collector1108)put(file,'changed collector dependency\n');},'tour-selection');
  commitCase('reviewed collector calculator addition',()=>put(collectorPrice,'module.exports = {};\n'),'tour-selection');
  commitCase('unreviewed new adapter addition',()=>put('extensions/selection-collector/new-adapter.js','unknown\n'),'full');
  commitCase('collector with shared bootstrap',()=>{put(collectorPage,'changed\n');put('manager/_bootstrap.php','shared changed\n');},'full');
  commitCase('collector rename',()=>fs.renameSync(path.join(temp,collectorPage),path.join(temp,'manager/renamed-collector.php')),'full');
  commitCase('collector deletion',()=>fs.unlinkSync(path.join(temp,collectorPage)),'full');
  commitCase('collector symlink addition',()=>fs.symlinkSync('popup.js',path.join(temp,collectorPrice)),'full');
  commitCase('collector executable addition',()=>{put(collectorPrice,'module.exports = {};\n');fs.chmodSync(path.join(temp,collectorPrice),0o755);},'full');
  commitCase('collector mode change',()=>fs.chmodSync(path.join(temp,collectorPage),0o755),'full');
  commitCase('collector documentation only',()=>put('extensions/selection-collector/README.md','collector docs changed\n'),'full');
  commitCase('selection private/public server owner',()=>put(tourService,'<?php // changed\n'),'tour-selection');
  commitCase('selection with docs',()=>{put(tour,'changed\n');put(tourDoc,'updated\n');},'tour-selection');
  commitCase('selection plus shared bootstrap',()=>{put(tour,'changed\n');put('manager/_bootstrap.php','shared changed\n');},'full');
  commitCase('selection runtime addition',()=>put('selection/photo.php','<?php\n'),'full');
  commitCase('CI edits retain full and commission scoped browser runner',()=>put('tools/ci_change_scope.js','// changed CI\n'),'full',true);
  commitCase('OAuth change', () => put(oauth, 'changed\n'), 'oauth');
  commitCase('new browser fixture', () => put(fixture, 'module.exports = {};\n'), 'oauth');
  commitCase('documentation only', () => put(doc, 'changed docs\n'), 'full');
  commitCase('mixed shared auth', () => { put(oauth, 'changed\n'); put('manager/_bootstrap.php', 'changed shared\n'); }, 'full');
  commitCase('deleted OAuth', () => fs.unlinkSync(path.join(temp, oauth)), 'full');
  commitCase('renamed OAuth', () => fs.renameSync(path.join(temp, oauth), path.join(temp, 'manager/renamed.php')), 'full');
  commitCase('symlink OAuth', () => { fs.unlinkSync(path.join(temp, oauth)); fs.symlinkSync('../docs/MANAGER_PREPARED_REPLIES.md', path.join(temp, oauth)); }, 'full');
  commitCase('mode change', () => fs.chmodSync(path.join(temp, oauth), 0o755), 'full');
  commitCase('added runtime file', () => put('services/ManagerPreparationOAuth.php', '<?php\n'), 'full');
  commitCase('isolated consent spec append', () => put(browserSpec, fixtureImport + sharedSpec + consentLoop), 'oauth');
  commitCase('shared AI browser change', () => put(browserSpec, fixtureImport + sharedSpec.replace('AI baseline', 'AI changed') + consentLoop), 'full');
  check(run(event(base)), 'full', 'empty real diff');
  check(run(event('a'.repeat(40))), 'full', 'missing commit ref');
  check(run(event('--invalid-ref')), 'full', 'malformed commit SHA');
  check(run({ pull_request: { head: { sha: base } } }), 'full', 'missing base SHA');
  check(run('{broken'), 'full', 'invalid event JSON');
  check(run(event(base), { GITHUB_EVENT_PATH: '' }), 'full', 'no event path');
  check(run(event(base), { GITHUB_EVENT_PATH: path.join(temp, 'missing.json') }), 'full', 'missing event file');
  check(run(event(base), { GITHUB_EVENT_NAME: 'push' }), 'full', 'push event never narrows');
  check(run(event(base), { GITHUB_EVENT_NAME: '' }), 'full', 'missing event name never narrows');
  // A green synthetic merge is insufficient for the repository's strict
  // required checks when the PR branch itself predates main. Fail before scopes.
  git(['reset', '--hard', '-q', base]); git(['clean', '-fdq']);
  put('docs/unrelated-ci-base.txt', 'accepted independent base update\n');
  git(['add', '-A']); git(['commit', '-qm', 'advance main independently']);
  const advancedBase = git(['rev-parse', 'HEAD']);
  git(['reset', '--hard', '-q', base]); git(['clean', '-fdq']);
  put(oauth, 'candidate on the previous base\n');
  git(['add', '-A']); git(['commit', '-qm', 'stale PR candidate']);
  const staleHead = git(['rev-parse', 'HEAD']);
  const staleEvent = { pull_request: { base: { sha: advancedBase }, head: { sha: staleHead } } };
  fs.writeFileSync(eventPath, JSON.stringify(staleEvent));
  fs.writeFileSync(outputPath, '');
  const blocked = spawnSync(process.execPath, [selector], { cwd: temp, encoding: 'utf8',
    env: { ...process.env, GITHUB_EVENT_NAME: 'pull_request', GITHUB_EVENT_PATH: eventPath, GITHUB_OUTPUT: outputPath } });
  check(blocked.status, 1, 'stale branch is a failing gate, not success or a full retry');
  const blockedResult = JSON.parse(blocked.stdout);
  check(blockedResult.blocked, true, 'known stale ancestry is explicit');
  check(blockedResult.reason, 'base_not_in_head', 'diagnosis identifies the required update');
  check(fs.readFileSync(outputPath, 'utf8'), '', 'blocked scope cannot publish a usable mode');
  check(blocked.stderr.includes('integrate the current main'), true, 'stale branch explains its next action');
  check(run(staleEvent, { GITHUB_EVENT_NAME: 'workflow_dispatch' }), 'full', 'standalone manual runs retain full coverage');
  git(['merge', '--no-edit', advancedBase]);
  check(run({ pull_request: { base: { sha: advancedBase }, head: { sha: git(['rev-parse', 'HEAD']) } } }),
    'oauth', 'after integrating main, the unchanged candidate follows its ordinary scope');

  const brokenHead = git(['rev-parse', 'HEAD']);
  const brokenTree = git(['rev-parse', `${brokenHead}^{tree}`]);
  fs.unlinkSync(path.join(temp, '.git', 'objects', brokenTree.slice(0, 2), brokenTree.slice(2)));
  check(run(event(brokenHead)), 'full', 'diff failure after commit refs validate');
} finally { fs.rmSync(temp, { recursive: true, force: true }); }
// Priority is mutable metadata, so its read-only admission can be repeated
// without rerunning accepted source, browser or production checks.
async function verifyReleasePriority() {
  const { decidePriority, readOpenPulls, runGuard } = require('../tools/release_priority');
  const repository = 'pyatkoff/max-search-bot';
  const now = Date.parse('2026-11-03T18:20:00Z');
  const head = 'a'.repeat(40), base = 'b'.repeat(40);
  const context = { repository, prNumber: 1306, headSha: head, baseSha: base, now };
  const claim = { head, base, started_at: '2026-11-03T18:10:00Z',
    expires_at: '2026-11-03T18:30:00Z', kind: 'product' };
  const marker = value => '<!-- anytour-release-priority:v1 ' + JSON.stringify(value) + ' -->';
  const pr = (number, body = '', patch = {}) => ({ number, body, state: 'open', draft: false,
    author_association: 'OWNER', head: { sha: head, repo: { full_name: repository } },
    base: { ref: 'main', sha: base }, ...patch });
  const target = pr(1306), ready = pr(1305, marker(claim));
  const decide = pulls => decidePriority(pulls, context);
  const rejects = async (action, code, message) => {
    let observed = null;
    try { await action(); } catch (error) { observed = error.code; }
    check(observed, code, message);
  };
  check(decide([target]).allowed, true, 'no reservation does not invent a blocker');
  check(decide([target, ready]).holder_pr, 1305, 'ready product retains priority over later candidate');
  check(decide([target, ready]).allowed, false, 'later candidate cannot pass the release gate');
  check(decidePriority([target, ready], { ...context, prNumber: 1305 }).allowed, true, 'holder can finish');
  check(decide([pr(1304, marker({ ...claim, started_at: '2026-11-03T18:11:00Z' })), ready, target])
    .holder_pr, 1305, 'time ordering precedes PR number');
  check(decide([pr(1304, marker(claim)), ready, target]).holder_pr, 1304, 'equal times have one deterministic owner');

  for (const [description, other] of [
    ['expired', pr(1305, marker({ ...claim, expires_at: '2026-11-03T18:20:00Z' }))],
    ['updated head', pr(1305, marker({ ...claim, head: 'c'.repeat(40) }))],
    ['advanced base', pr(1305, marker({ ...claim, base: 'c'.repeat(40) }))],
    ['draft', pr(1305, marker(claim), { draft: true })],
    ['closed', pr(1305, marker(claim), { state: 'closed' })],
    ['untrusted author', pr(1305, marker(claim), { author_association: 'CONTRIBUTOR' })],
    ['fork', pr(1305, marker(claim), { head: { sha: head, repo: { full_name: 'outside/fork' } } })],
    ['different base branch', pr(1305, marker(claim), { base: { ref: 'other', sha: base } })]
  ]) check(decide([target, other]).allowed, true, description + ' cannot hold the release');
  check(decide([target, pr(1305, 'Documentation mentions anytour-release-priority:v1 only.')]).allowed, true,
    'mentioning the policy without a marker is not a reservation');
  const incident = pr(1307, marker({ ...claim, kind: 'incident',
    incident: 'https://github.com/' + repository + '/issues/1122' }));
  check(decide([target, ready, incident]).holder_pr, 1307, 'explicit confirmed-incident reference outranks product');
  check(decidePriority([target, ready, incident], { ...context, prNumber: 1307 }).allowed, true,
    'incident still uses the same admission, never bypasses checks or the publisher');

  for (const [body, code] of [
    [marker({ ...claim, expires_at: '2026-11-03T19:00:00Z' }), 'invalid_window'],
    [marker({ ...claim, started_at: '2026-11-03T18:21:00Z' }), 'invalid_window'],
    [marker({ ...claim, expires_at: claim.started_at }), 'invalid_window'],
    [marker({ ...claim, expires_at: '2026-02-30T18:30:00Z' }), 'invalid_window'],
    [marker({ ...claim, expires_at: '2026-11-03T18:30:00+00:00' }), 'invalid_fields'],
    [marker({ ...claim, head: 'short' }), 'invalid_fields'],
    [marker({ ...claim, kind: 'cleanup' }), 'invalid_fields'],
    [marker({ ...claim, renew: true }), 'invalid_fields'],
    [marker({ ...claim, kind: 'incident' }), 'incident_reference_required'],
    [marker({ ...claim, kind: 'incident', incident: 'https://github.com/other/repo/issues/1' }), 'incident_reference_required'],
    [marker({ ...claim, incident: 'https://github.com/' + repository + '/issues/1' }), 'unexpected_incident_reference'],
    [marker(claim) + marker(claim), 'invalid_marker'],
    [marker(claim) + '<!-- anytour-release-priority:v1', 'invalid_marker'],
    [marker(claim).replace(':v1', ':v2'), 'invalid_marker'],
    ['<!-- anytour-release-priority:v1 nope -->', 'invalid_json'],
    ['<!-- anytour-release-priority:v1 [] -->', 'invalid_claim'],
    ['<!-- anytour-release-priority:v1', 'invalid_marker']
  ]) await rejects(() => decide([target, pr(1305, body)]), code, 'malformed authoritative claim stops safely: ' + code);
  await rejects(() => decide([target, target]), 'ambiguous_snapshot', 'duplicate snapshot cannot authorize a merge');
  await rejects(() => decide([ready]), 'superseded_target', 'removed target cannot merge');
  await rejects(() => decide([pr(1306, '', { head: { sha: 'c'.repeat(40) } })]),
    'superseded_target', 'changed target cannot reuse a successful decision');
  await rejects(() => decidePriority([target], { ...context, repository: '../other' }),
    'invalid_context', 'repository identity is not an arbitrary URL');

  const event = JSON.stringify({ pull_request: target });
  const env = { GITHUB_EVENT_NAME: 'pull_request', GITHUB_REPOSITORY: repository,
    GITHUB_EVENT_PATH: 'event.json', GITHUB_TOKEN: 'fixture-token' };
  let calls = [];
  const response = (value, status = 200) => ({ ok: status === 200, status, json: async () => value });
  const fetchGood = async (url, options) => {
    calls.push(url);
    check(options.redirect, 'error', 'REST redirect is not an alternate host');
    check(Boolean(options.signal), true, 'each read has cancellation');
    return response(url.endsWith('/git/ref/heads/main') ? { object: { sha: base } } : [target, ready]);
  };
  const options = { env, readEvent: () => event, fetchImpl: fetchGood, now };
  check((await runGuard(options)).allowed, false, 'CI executes fresh admission against main and open PRs');
  check(calls.length, 2, 'one main read and one bounded PR read');
  calls = [];
  check((await runGuard({ ...options, admission: context,
    env: { ...env, GITHUB_EVENT_NAME: '' }, readEvent: () => { throw Error('not used'); } })).allowed,
    false, 'explicit pre-merge admission does not rely on an old CI event');
  check(calls.length, 2, 'explicit admission stays read only and bounded');
  calls = [];
  check((await runGuard({ ...options, env: { ...env, GITHUB_EVENT_NAME: 'workflow_dispatch' } })).reason,
    'manual_checks_only', 'manual validation does not claim merge authorization');
  check(calls.length, 0, 'manual validation does not reread merge state');
  await rejects(() => runGuard({ ...options, env: { ...env, GITHUB_EVENT_NAME: 'push' } }),
    'unsupported_event', 'unknown events do not get admission');
  await rejects(() => runGuard({ ...options, readEvent: () => 'broken' }),
    'invalid_event', 'unreadable event cannot authorize');
  await rejects(() => runGuard({ ...options, env: { ...env, GITHUB_TOKEN: '' } }),
    'missing_read_capability', 'missing capability does not pass open');
  let requests = 0;
  await rejects(() => runGuard({ ...options, fetchImpl: async () => {
    requests++; return response({ object: { sha: 'c'.repeat(40) } });
  } }), 'base_changed', 'current main must match the frozen admission');
  check(requests, 1, 'changed main stops without fetching more state');

  requests = 0;
  await rejects(() => runGuard({ ...options, fetchImpl: async () => {
    requests++; return response({}, 429);
  } }), 'api_http_429', 'rate limit is a stop, not a bypass');
  check(requests, 1, 'rate limit is never retried');
  await rejects(() => runGuard({ ...options, fetchImpl: async () => { throw Error('fixture-token must not leak'); } }),
    'read_failed', 'network failure is sanitized and closed');
  await rejects(() => runGuard({ ...options, fetchImpl: async () => {
    const error = new Error('private transport detail'); error.code = 'ECONNRESET'; throw error;
  } }), 'read_failed', 'coded transport failures cannot print private error details');
  await rejects(() => runGuard({ ...options, fetchImpl: async () => ({
    ok: true, json: async () => { throw Error('invalid or timed out response body'); }
  }) }), 'invalid_api_response', 'body parsing failure is closed');

  requests = 0;
  const hundred = Array.from({ length: 100 }, (_, i) => pr(i + 1));
  const pages = [hundred, [target, ready]];
  check((await readOpenPulls(repository, 'fixture-token', async () => response(pages[requests++]))).length,
    102, 'second page is read before deciding absence of priority');
  check(requests, 2, 'pagination is bounded to returned pages');
  requests = 0;
  await rejects(() => readOpenPulls(repository, 'fixture-token', async () => {
    requests++; return response(hundred);
  }), 'snapshot_limit', 'truncated snapshot never means no priority');
  check(requests, 3, 'snapshot limit bounds API work');
  await rejects(() => readOpenPulls(repository, 'fixture-token', async () => response({})),
    'invalid_api_response', 'non-array PR list is not an empty list');
}

verifyReleasePriority().then(() => {
  const admission = spawnSync(process.execPath, [path.resolve(__dirname, '../tools/release_priority.js'),
    '--admit', '1306', 'a'.repeat(40), 'b'.repeat(40)], {
    encoding: 'utf8', env: { GITHUB_REPOSITORY: 'pyatkoff/max-search-bot' }
  });
  check(admission.status, 1, 'CLI admission without token stops before I/O');
  check(admission.stderr.includes('missing_read_capability'), true, 'CLI admits valid arguments to the guard');
  const invalid = spawnSync(process.execPath, [path.resolve(__dirname, '../tools/release_priority.js'), '--admit'],
    { encoding: 'utf8', env: {} });
  check(invalid.status, 1, 'incomplete CLI admission cannot fall back to CI success');
  process.stdout.write('CI change scope and release priority regression: ' + assertions + ' assertions passed\n');
}).catch(error => { process.stderr.write(String(error.stack || error) + '\n'); process.exitCode = 1; });

