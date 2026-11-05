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
const editorWrapper = 'modules/tour-selections/editor-tours.php';
const editorFixture = 'tests/visual/fixtures/selection-private-editor.js';
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

// The wrapper and fixture are selection inputs, not a shared-module exemption.
// Keep the real editor-only package shape and its mixed-backend counterexample.
function verifyPrivateEditorScope() {
  const changed = path => ({status:'M',path,oldMode:'100644',newMode:'100644'});
  const editorPackage = [tour,'manager/assets/workspace-v2-tour-selection.css',
    editorManifest,editorWrapper,editorFixture,'tests/visual/manager-tour-selection.spec.js',tourDoc];
  check(classify(editorPackage.map(changed)),'tour-selection','complete private editor package retains component coverage');
  check(classify([...editorPackage,'tests/run_manager_prepared_reply_regression.php'].map(changed)),
    'full','PR1305 diagnostic companion remains full; no shared-test exemption');
  for (const file of [editorWrapper,editorFixture]) {
    check(classify([changed(file)]),'tour-selection','standalone reviewed editor dependency: '+file);
    for (const status of ['A','D','R100','C100','T','U']) {
      check(classify([{...changed(file),status}]),'full','editor dependency status is not silently exempt: '+file+'/'+status);
    }
    for (const [oldMode,newMode] of [['100644','100755'],['120000','120000'],['160000','160000']]) {
      check(classify([{...changed(file),oldMode,newMode}]),'full','editor dependency mode/type stays full: '+file);
    }
    check(classify([{...changed(file),previousPath:file}]),'full','editor dependency copies cannot inherit scope');
    for (const adjacent of ['./'+file,file+'.bak',file+'/extra']) {
      check(classify([changed(file),changed(adjacent)]),'full','lookalike editor path stays full: '+adjacent);
    }
  }
  for (const dependency of ['modules/tour-selections/editor-app.php','manager/tour-selection-editor.php',
    'modules/tour-selections/unknown-helper.php','manager/_bootstrap.php',
    'tests/visual/fixtures/internal-notes.js','tests/visual/manager-ai-draft.spec.js',
    'tests/support/prepared-input-fixture.php','tests/run_manager_prepared_reply_regression.php',
    'services/ManagerAuthService.php','migrations/999_changed.sql',
    'tools/ci_change_scope.js','.github/workflows/workspace-v2-visual-qa.yml']) {
    check(classify([changed(editorWrapper),changed(editorFixture),changed(dependency)]),
      'full','shared, unknown or policy dependency retains full coverage: '+dependency);
  }
}
verifyPrivateEditorScope();

const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'ci-scope-'));
try {
  const git = args => execFileSync('git', args, { cwd: temp, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  const put = (name, content) => { fs.mkdirSync(path.dirname(path.join(temp, name)), { recursive: true }); fs.writeFileSync(path.join(temp, name), content); };
  git(['init', '-q']); git(['config', 'user.name', 'CI scope regression']); git(['config', 'user.email', 'ci@example.invalid']);
  git(['config', 'core.filemode', 'true']);
  put(oauth, 'base\n'); put(doc, 'docs\n'); put('manager/_bootstrap.php', 'shared\n');
  put(tour,'base selection\n');put(tourService,'<?php // base\n');put(tourDoc,'selection docs\n');
  put(editorManifest, JSON.stringify(editorBase));
  put(editorWrapper,'<?php // standalone wrapper\n');put(editorFixture,'module.exports = {};\n');
  put('tests/run_manager_prepared_reply_regression.php','<?php // shared backend fixture\n');
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
  commitCase('standalone editor wrapper-only edit has native component ownership',()=>put(editorWrapper,'<?php // changed wrapper\n'),'tour-selection');
  commitCase('private editor fixture-only edit has native component ownership',()=>put(editorFixture,'module.exports = {changed:true};\n'),'tour-selection');
  commitCase('complete private editor package retains exact dependencies',()=>{
    put(tour,'changed editor\n');put(editorManifest,JSON.stringify(editorNext));
    put(editorWrapper,'<?php // changed wrapper\n');put(editorFixture,'module.exports = {changed:true};\n');
    put('tests/visual/manager-tour-selection.spec.js','changed complete editor cases\n');
  },'tour-selection');
  commitCase('private editor and shared backend diagnostic stay full',()=>{
    put(editorWrapper,'<?php // changed wrapper\n');put(editorFixture,'module.exports = {changed:true};\n');
    put('tests/run_manager_prepared_reply_regression.php','<?php // changed shared diagnostic\n');
  },'full');
  commitCase('private editor wrapper deletion stays full',()=>fs.unlinkSync(path.join(temp,editorWrapper)),'full');
  commitCase('private editor fixture rename stays full',()=>fs.renameSync(path.join(temp,editorFixture),path.join(temp,editorFixture+'.renamed')),'full');
  commitCase('private editor wrapper mode change stays full',()=>fs.chmodSync(path.join(temp,editorWrapper),0o755),'full');
  commitCase('wrapper and editor contract change stay full',()=>{
    put(editorWrapper,'<?php // changed wrapper\n');
    put(editorManifest,JSON.stringify({...editorNext,contract:'tour-selection-editor-host-v2'}));
  },'full');
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


async function verifyReleaseSlot() {
  const { REQUIRED, CLOSER, ACTIVATION, context, binding, gateState, recordState, verifyTelemetry,
    verifyConfigure, closeRelease, makeApi } = require('../tools/release_slot_close');
  const repository = 'pyatkoff/max-search-bot';
  const prefix = '/repos/' + repository;
  const source = 'a'.repeat(40), head = 'b'.repeat(40), created = '2026-11-03T19:00:00Z';
  const copy = value => JSON.parse(JSON.stringify(value));
  const c = { sha: source, deploy: 100, run: 200, configure: 'skipped', activation: false };
  const env = { GITHUB_REPOSITORY: repository, GITHUB_EVENT_NAME: 'workflow_run',
    GITHUB_REF: 'refs/heads/main', GITHUB_RUN_ID: '200', GITHUB_RUN_ATTEMPT: '1',
    GITHUB_SHA: source, CONFIGURE_RESULT: 'skipped' };
  const runs = REQUIRED.map(([file, event, name], i) => ({ id: 100 + i,
    path: '.github/workflows/' + file, name, event, head_sha: source, head_branch: 'main',
    head_repository: { full_name: repository }, run_attempt: 1, created_at: created,
    status: 'completed', conclusion: 'success' }));
  runs.push({ id: 200, path: CLOSER, name: 'Configure AnyTour modeled value goal',
    event: 'workflow_run', head_sha: source, head_branch: 'main', head_repository: { full_name: repository },
    run_attempt: 1, created_at: created, status: 'in_progress', conclusion: null });
  const snapshot = { total_count: runs.length, workflow_runs: runs };
  const event = { action: 'completed', repository: { full_name: repository },
    workflow_run: { ...copy(runs[0]), head_commit: { id: source, message: 'Ordinary product release' } } };
  const marker = { comment_id: 900, head_sha: head, owner_id: 800, updated_at: created };
  const pr = { number: 55, state: 'closed', merged: true, merge_commit_sha: source,
    head: { sha: head, repo: { full_name: repository } },
    base: { ref: 'main', repo: { full_name: repository } },
    body: '<!-- release-slot-close:v1 ' + JSON.stringify(marker) + ' -->' };
  const b = binding(pr, source);
  const comment = { id: 900, body: b.active, user: { id: 800 }, updated_at: created,
    author_association: 'OWNER', issue_url: 'https://api.github.com' + prefix + '/issues/1122' };
  const deploy = { sha: source, run_id: '100', provenance: 'success', key: 'success',
    bundle: 'success', sync: 'success', migration: 'success', max_webhook: 'success',
    metrika_cron: 'success', smoke: 'success', diagnostics: 'success' };
  const ops = { sha: source, migration: 'success', snapshot: 'success', live_sessions: 'success',
    daily_sessions: 'success', yesterday_sessions: 'success', architecture_inventory: 'success',
    website_smoke: 'success', health: 'success' };
  const jobs = { total_count: 2, jobs: [
    { name: 'configure', run_id: 200, head_sha: source, status: 'completed', conclusion: 'skipped' },
    { name: 'release-slot', run_id: 200, head_sha: source, status: 'in_progress', conclusion: null },
  ] };
  const rejects = async (fn, expected, label) => {
    let actual = 'no_refusal'; try { await fn(); } catch (error) { actual = error.code; }
    check(actual, expected, 'release slot: ' + label);
  };
  check(JSON.stringify(context(env, event)), JSON.stringify(c), 'release slot: exact original push context');
  for (const [field, value] of [['GITHUB_EVENT_NAME','workflow_dispatch'], ['GITHUB_RUN_ATTEMPT','2'],
    ['GITHUB_REF','refs/heads/foreign'], ['GITHUB_SHA','c'.repeat(40)], ['CONFIGURE_RESULT','failure']]) {
    await rejects(() => context({ ...env, [field]: value }, event), 'untrusted_context', 'reject ' + field);
  }
  for (const [field, value] of [['event','workflow_dispatch'], ['run_attempt',3], ['conclusion','failure'],
    ['head_repository',{ full_name:'foreign/repo' }], ['path','.github/workflows/other.yml']]) {
    const e = copy(event); e.workflow_run[field] = value;
    await rejects(() => context(env, e), 'untrusted_context', 'upstream ' + field);
  }
  check(binding({ ...pr, body:'No automatic closure admitted.' }, source), null, 'release slot: no opt-in stays untouched');
  for (const body of [pr.body + '\n' + pr.body, '<!-- release-slot-close:v1 {broken} -->',
    pr.body.replace(head, source), pr.body.replace('"owner_id":800','"owner_id":0')]) {
    await rejects(() => binding({ ...pr, body }, source), 'invalid_binding', 'malformed or foreign binding');
  }
  await rejects(() => binding({ ...pr, merged:false }, source), 'untrusted_pull', 'unmerged source');
  check(REQUIRED.filter(([file, event, name]) => file === 'modeled-conversion-monitor.yml' && event === 'workflow_run' && name === 'Monitor modeled conversion delivery').length,
    1, 'release slot: installed canonical monitor is a mandatory original gate');
  check(gateState(snapshot, c), 'ready', 'release slot: all original gates ready');
  for (let i = 0; i < REQUIRED.length; i++) {
    const missing = copy(snapshot); missing.workflow_runs.splice(i, 1); missing.total_count--;
    if (i === 0) await rejects(() => gateState(missing,c), 'deployment_missing', 'deploy absent');
    else check(gateState(missing,c), 'waiting', 'release slot: missing gate ' + i + ' waits');
    for (const failure of ['failure','cancelled','skipped','timed_out',null]) {
      const changed = copy(snapshot); changed.workflow_runs[i].conclusion = failure;
      if (REQUIRED[i][0] === ACTIVATION.file && failure === 'skipped')
        check(gateState(changed,c), 'ready', 'release slot: declared activation skip still requires job proof');
      else await rejects(() => gateState(changed,c), 'required_gate_failed', 'mandatory gate ' + i + ' ' + failure);
    }
    const pending = copy(snapshot); pending.workflow_runs[i].status = 'in_progress'; pending.workflow_runs[i].conclusion = null;
    check(gateState(pending,c), 'waiting', 'release slot: running gate ' + i + ' waits');
  }
  for (const [field, value] of [['head_sha','c'.repeat(40)], ['run_attempt',2],
    ['head_repository',{ full_name:'foreign/repo' }], ['head_branch','feature']]) {
    const changed = copy(snapshot); changed.workflow_runs[2][field] = value;
    await rejects(() => gateState(changed,c), 'untrusted_run', 'reject gate ' + field);
  }
  const duplicate = copy(snapshot); duplicate.workflow_runs.push({ ...runs[2], id: 800 }); duplicate.total_count++;
  await rejects(() => gateState(duplicate,c), 'ambiguous_run', 'duplicate workflow origin');
  const manual = copy(snapshot); manual.workflow_runs[2].event = 'workflow_dispatch';
  check(gateState(manual,c), 'waiting', 'release slot: manual success never replaces original gate');
  const scheduled = copy(snapshot); scheduled.workflow_runs.push({ ...runs[6], event:'schedule', id:801 }); scheduled.total_count++;
  check(gateState(scheduled,c), 'ready', 'release slot: natural later monitoring is separate');
  await rejects(() => gateState({ ...snapshot, total_count:100 },c), 'incomplete_runs', 'pagination incomplete');
  const unknown = copy(snapshot); unknown.workflow_runs.push({ ...runs[2], id:802, path:'.github/workflows/new-required.yml' }); unknown.total_count++;
  await rejects(() => gateState(unknown,c), 'unclassified_gate', 'new postdeploy gate cannot be silently ignored');
  const stale = copy(snapshot); stale.workflow_runs[2].created_at = '2026-11-03T18:59:59Z';
  await rejects(() => gateState(stale,c), 'stale_gate', 'old postdeploy result');
  const closer = copy(snapshot); closer.workflow_runs.at(-1).status = 'completed';
  await rejects(() => gateState(closer,c), 'closer_not_active', 'cancelled or replayed closer');
  check(recordState(comment,b), 'active', 'release slot: admitted exact owner and body');
  check(recordState({ ...comment, body:b.released },b), 'released', 'release slot: terminal is idempotent');
  for (const changed of [{ ...comment, body:'PR55: UNKNOWN' }, { ...comment, updated_at:'2026-11-03T19:00:01Z' }])
    await rejects(() => recordState(changed,b), 'record_changed', 'owner update cannot be overwritten');
  for (const changed of [{ ...comment, id:901 }, { ...comment, user:{id:801} },
    { ...comment, issue_url:comment.issue_url+'1' }, { ...comment, author_association:'NONE' }])
    await rejects(() => recordState(changed,b), 'foreign_record', 'foreign record never writable');
  verifyTelemetry(deploy,ops,c); check(true,true,'release slot: actual installation evidence accepted');
  for (const key of ['sha','run_id','provenance','key','bundle','sync','migration','max_webhook','metrika_cron','smoke','diagnostics'])
    await rejects(() => verifyTelemetry({ ...deploy,[key]:'wrong' },ops,c), 'installation_unconfirmed', 'installation ' + key);
  for (const key of Object.keys(ops))
    await rejects(() => verifyTelemetry(deploy,{ ...ops,[key]:'wrong' },c), 'diagnostics_unconfirmed', 'diagnostics ' + key);
  verifyConfigure(jobs,c); check(true,true,'release slot: declared configuration skip accepted');
  const configured=copy(jobs); configured.jobs[0].conclusion='success'; verifyConfigure(configured,{...c,configure:'success'});
  check(true,true,'release slot: actual configuration success accepted');
  await rejects(() => verifyConfigure(configured,c), 'configuration_unconfirmed','skip cannot mask configured job');
  function fixture(hook) {
    let time=0, saved=copy(comment), writes=0, reads=0;
    const calls=[];
    const api=async(method,path,body,raw) => {
      calls.push({method,path,body,raw});
      const override=hook && await hook({method,path,body,raw,calls,time,writes,reads});
      if(override !== undefined) return override;
      if(method==='PATCH'){ writes++; saved={...saved,body:body.body}; return copy(saved); }
      if(path.endsWith('/git/ref/heads/main')) return {object:{sha:source}};
      if(path.includes('/commits/')) return [{number:55,state:'closed',merge_commit_sha:source}];
      if(path.endsWith('/pulls/55')) return copy(pr);
      if(path.includes('/issues/comments/')) { reads++; return copy(saved); }
      if(path.includes('/actions/runs?')) return copy(snapshot);
      const activation = runs.find(r => r.path.endsWith('/' + ACTIVATION.file));
      if(path.includes('/actions/runs/' + activation.id + '/attempts/1/jobs?'))
        return {total_count:1,jobs:[{name:'activate',run_id:activation.id,head_sha:source,status:'completed',conclusion:'skipped'}]};
      if(path.includes('/attempts/1/jobs?')) return copy(jobs);
      if(path.includes('/contents/deploy_status')) return copy(deploy);
      if(path.includes('/contents/ops_status')) return copy(ops);
      throw Error('unexpected synthetic request');
    };
    return { options:{env,event,api,now:()=>time,sleep:async(ms)=>{time+=ms;}},
      calls, writes:()=>writes, time:()=>time };
  }
  let f=fixture();
  check(await closeRelease(f.options),'released','release slot: successful original path closes');
  check(f.writes(),1,'release slot: exactly one update, no create/dispatch');
  check(f.calls.at(-1).method,'GET','release slot: exactly one final readback');
  check(f.calls.filter(x=>x.method!=='GET').every(x=>x.method==='PATCH'&&x.path===prefix+'/issues/comments/900'),
    true,'release slot: only bound record writable');
  f=fixture(({path,reads})=>path.includes('/issues/comments/')&&reads===0?{...comment,body:b.released}:undefined);
  check(await closeRelease(f.options),'already_released','release slot: accepted terminal never repeated');
  check(f.writes(),0,'release slot: no repeated write');
  f=fixture(({path,time})=>{
    if(path.includes('/actions/runs?')&&time<40000){const v=copy(snapshot);v.workflow_runs[3].status='in_progress';v.workflow_runs[3].conclusion=null;return v;}
  });
  check(await closeRelease(f.options),'released','release slot: parallel original gates complete without dispatch');
  check(f.time(),40000,'release slot: only incomplete gates wait');
  f=fixture(({path})=>{
    if(path.includes('/actions/runs?')) {const v=copy(snapshot);v.workflow_runs[3].status='in_progress';v.workflow_runs[3].conclusion=null;return v;}
  });
  await rejects(()=>closeRelease(f.options),'gate_deadline','bounded pending wait');
  check(f.writes(),0,'release slot: timeout retains slot');
  for (const [label, hook, code] of [
    ['failed gate',({path})=>{if(path.includes('/actions/runs?')){const v=copy(snapshot);v.workflow_runs[3].conclusion='failure';return v;}},'required_gate_failed'],
    ['late main change',({path,calls})=>path.endsWith('/git/ref/heads/main')&&calls.filter(x=>x.path===path).length===3?{object:{sha:head}}:undefined,'main_changed'],
    ['late owner edit',({path,reads})=>path.includes('/issues/comments/')&&reads===1?{...comment,body:'held for repair'}:undefined,'record_changed'],
    ['binding revoked',({path,calls})=>path.endsWith('/pulls/55')&&calls.filter(x=>x.path===path).length===2?{...pr,body:''}:undefined,'binding_changed'],
    ['API refusal',({path})=>{if(path.includes('/actions/runs?'))throw Object.assign(Error('private'),{code:'api_http_429'});},'api_http_429'],
  ]) {
    f=fixture(hook);await rejects(()=>closeRelease(f.options),code,label);check(f.writes(),0,'release slot: '+label+' cannot write');
  }
  let attempts=0;
  f=fixture(({method})=>{if(method==='PATCH'){attempts++;throw Error('unknown transport private');}});
  await rejects(()=>closeRelease(f.options),'write_unknown','unknown write outcome');
  check(attempts,1,'release slot: unknown mutation never retried');
  f=fixture(({path,writes})=>{if(path.includes('/issues/comments/')&&writes)throw Error('readback unavailable');});
  await rejects(()=>closeRelease(f.options),'write_unknown','unknown readback');
  check(f.writes(),1,'release slot: unavailable readback never resends');
  let requests=0;
  await rejects(()=>makeApi('fixture',async()=>{requests++;return{ok:false,status:429};})('GET',prefix+'/git/ref/heads/main'),
    'api_http_429','transport rate limit');
  check(requests,1,'release slot: no transport retry');
  await rejects(()=>makeApi('fixture',async()=>{throw Object.assign(Error('private token'),{code:'ECONNRESET'});})('GET',prefix+'/git/ref/heads/main'),
    'transport_failed','private transport details sanitized');
  await rejects(()=>makeApi('fixture')('PATCH',prefix+'/issues/1122',{body:'cannot create'}),'invalid_write_target','no new records');
  await rejects(()=>makeApi(''),'missing_capability','no ambient fallback credential');
}


async function verifyReleaseSlotActivation() {
  const { REQUIRED, CLOSER, ACTIVATION, context, gateState, verifyActivation, closeRelease } = require('../tools/release_slot_close');
  const repository='pyatkoff/max-search-bot', source='a'.repeat(40), head='b'.repeat(40);
  const created='2026-11-03T23:00:00Z', prefix='/repos/'+repository;
  const copy=v=>JSON.parse(JSON.stringify(v));
  const rejects=async(fn,code,label)=>{
    let actual='no_refusal';try{await fn();}catch(e){actual=e.code;}
    check(actual,code,'release activation: '+label);
  };
  function fixture(requested=false,hook) {
    const runs=REQUIRED.map(([file,event,name],i)=>({id:100+i,path:'.github/workflows/'+file,
      name,event,head_sha:source,head_branch:'main',head_repository:{full_name:repository},
      run_attempt:1,created_at:created,status:'completed',conclusion:'success'}));
    runs.push({id:200,path:CLOSER,name:'Configure AnyTour modeled value goal',event:'workflow_run',
      head_sha:source,head_branch:'main',head_repository:{full_name:repository},
      run_attempt:1,created_at:created,status:'in_progress',conclusion:null});
    const activation=runs.find(r=>r.path.endsWith('/'+ACTIVATION.file));
    const snapshot={total_count:runs.length,workflow_runs:runs};
    const c={sha:source,deploy:100,run:200,configure:'skipped',activation:requested};
    const env={GITHUB_REPOSITORY:repository,GITHUB_EVENT_NAME:'workflow_run',GITHUB_REF:'refs/heads/main',
      GITHUB_RUN_ID:'200',GITHUB_RUN_ATTEMPT:'1',GITHUB_SHA:source,CONFIGURE_RESULT:'skipped'};
    const event={action:'completed',repository:{full_name:repository},
      workflow_run:{...copy(runs[0]),head_commit:{id:source,message:requested?ACTIVATION.marker:'Product release'}}};
    const marker={comment_id:900,head_sha:head,owner_id:800,updated_at:created};
    const pr={number:55,state:'closed',merged:true,merge_commit_sha:source,
      head:{sha:head,repo:{full_name:repository}},base:{ref:'main',repo:{full_name:repository}},
      body:'<!-- release-slot-close:v1 '+JSON.stringify(marker)+' -->'};
    let record={id:900,body:'PR55: выпуск занят',user:{id:800},updated_at:created,author_association:'OWNER',
      issue_url:'https://api.github.com'+prefix+'/issues/1122'};
    const activationJobs={total_count:1,jobs:[{name:'activate',run_id:activation.id,head_sha:source,
      status:'completed',conclusion:requested?'success':'skipped'}]};
    const configJobs={total_count:1,jobs:[{name:'configure',run_id:200,head_sha:source,status:'completed',conclusion:'skipped'}]};
    const deploy={sha:source,run_id:'100'};
    for(const key of ['provenance','key','bundle','sync','migration','max_webhook','metrika_cron','smoke','diagnostics'])deploy[key]='success';
    const ops={sha:source};
    for(const key of ['migration','snapshot','live_sessions','daily_sessions','yesterday_sessions','architecture_inventory','website_smoke','health'])ops[key]='success';
    let time=0,writes=0;const calls=[];
    const activationPath=prefix+'/actions/runs/'+activation.id+'/attempts/1/jobs?per_page=100';
    const api=async(method,path,body)=>{
      calls.push({method,path,body});
      const override=hook&&await hook({method,path,body,calls,activationPath,snapshot,activationJobs,activation,requested});
      if(override!==undefined)return copy(override);
      if(method==='PATCH'){writes++;record={...record,body:body.body};return copy(record);}
      if(path.endsWith('/git/ref/heads/main'))return{object:{sha:source}};
      if(path.includes('/commits/'))return[{number:55,state:'closed',merge_commit_sha:source}];
      if(path.endsWith('/pulls/55'))return copy(pr);
      if(path.includes('/issues/comments/'))return copy(record);
      if(path.includes('/actions/runs?'))return copy(snapshot);
      if(path===activationPath)return copy(activationJobs);
      if(path.includes('/actions/runs/200/attempts/1/jobs?'))return copy(configJobs);
      if(path.includes('/contents/deploy_status'))return copy(deploy);
      if(path.includes('/contents/ops_status'))return copy(ops);
      throw Error('unexpected synthetic activation request');
    };
    return{c,env,event,snapshot,activation,activationJobs,activationPath,calls,writes:()=>writes,
      options:{env,event,api,now:()=>time,sleep:async ms=>{time+=ms;}}};
  }
  check(REQUIRED.filter(([file])=>file===ACTIVATION.file).length,1,'release activation: existing installed gate included once');
  for(const requested of [false,true]){
    const f=fixture(requested);
    check(context(f.env,f.event).activation,requested,'release activation: bound original commit determines expected outcome');
    check(gateState(f.snapshot,f.c),'ready','release activation: completed metadata is only preliminary readiness');
    verifyActivation(f.activationJobs,f.activation,f.c);
    check(await closeRelease(f.options),'released','release activation: '+(requested?'approved success':'declared job skip')+' closes');
    check(f.writes(),1,'release activation: one existing record update only');
    check(f.calls.filter(x=>x.path===f.activationPath).length,1,'release activation: original job read once before update');
  }
  for(const change of [e=>{delete e.workflow_run.head_commit;},e=>{e.workflow_run.head_commit.id=head;},
    e=>{e.workflow_run.head_commit.message=null;},e=>{e.workflow_run.head_commit.message='x'.repeat(65537);}]){
    const f=fixture();change(f.event);await rejects(()=>context(f.env,f.event),'untrusted_context','unknown commit cannot imply skipped activation');
  }
  const skippedRun=fixture();skippedRun.activation.conclusion='skipped';
  check(gateState(skippedRun.snapshot,skippedRun.c),'ready','release activation: explicitly skipped run still needs exact job skip');
  verifyActivation(skippedRun.activationJobs,skippedRun.activation,skippedRun.c);
  for(const [label,requested,mutate,code] of [
    ['missing run',false,f=>{f.snapshot.workflow_runs=f.snapshot.workflow_runs.filter(r=>r.id!==f.activation.id);f.snapshot.total_count--;},'gate_deadline'],
    ['pending run',false,f=>{f.activation.status='in_progress';f.activation.conclusion=null;},'gate_deadline'],
    ['failed run',false,f=>{f.activation.conclusion='failure';},'required_gate_failed'],
    ['cancelled run',false,f=>{f.activation.conclusion='cancelled';},'required_gate_failed'],
    ['required run skipped',true,f=>{f.activation.conclusion='skipped';},'required_gate_failed'],
    ['unexpected activation',false,f=>{f.activationJobs.jobs[0].conclusion='success';},'activation_unconfirmed'],
    ['required job skipped',true,f=>{f.activationJobs.jobs[0].conclusion='skipped';},'activation_unconfirmed'],
    ['job wrong source',false,f=>{f.activationJobs.jobs[0].head_sha=head;},'activation_unconfirmed'],
    ['job foreign run',false,f=>{f.activationJobs.jobs[0].run_id=999;},'activation_unconfirmed'],
    ['job missing identity',false,f=>{f.activationJobs.jobs[0].name='unknown';},'activation_unconfirmed'],
    ['job still active',false,f=>{f.activationJobs.jobs[0].status='in_progress';},'activation_unconfirmed'],
    ['job missing',false,f=>{f.activationJobs.jobs=[];f.activationJobs.total_count=0;},'incomplete_activation_jobs'],
    ['jobs incomplete',false,f=>{f.activationJobs.total_count=2;},'incomplete_activation_jobs'],
    ['extra job',false,f=>{f.activationJobs.jobs.push({...f.activationJobs.jobs[0],name:'new_gate'});f.activationJobs.total_count++;},'incomplete_activation_jobs'],
    ['rerun cannot substitute',false,f=>{f.activation.run_attempt=2;},'untrusted_run'],
    ['duplicate origin',false,f=>{f.snapshot.workflow_runs.push({...f.activation,id:999});f.snapshot.total_count++;},'ambiguous_run'],
  ]){
    const f=fixture(requested);mutate(f);await rejects(()=>closeRelease(f.options),code,label);
    check(f.writes(),0,'release activation: '+label+' retains publisher');
  }
  let f=fixture(false,({path,activationPath})=>{
    if(path===activationPath)throw Object.assign(Error('unavailable'),{code:'api_http_429'});
  });
  await rejects(()=>closeRelease(f.options),'api_http_429','activation metadata refusal');
  check(f.writes(),0,'release activation: refused read cannot release');
  check(f.calls.filter(x=>x.path===f.activationPath).length,1,'release activation: refused read is not retried');
  f=fixture(false,({path,calls,snapshot,activation})=>{
    if(path.includes('/actions/runs?')&&calls.filter(x=>x.path===path).length===2){
      const changed=copy(snapshot);changed.workflow_runs.find(r=>r.id===activation.id).conclusion='failure';return changed;
    }
  });
  await rejects(()=>closeRelease(f.options),'required_gate_failed','late failed activation');
  check(f.writes(),0,'release activation: late failure cannot release');
}


async function verifyReleaseRecovery() {
  const M=require('../tools/release_slot_close');
  const copy=v=>JSON.parse(JSON.stringify(v));
  const repository='pyatkoff/max-search-bot',source='a'.repeat(40);
  const prefix='/repos/'+repository;
  const rejects=async(fn,code,label)=>{
    let actual='no_refusal';try{await fn();}catch(e){actual=e.code;}
    check(actual,code,'verification recovery: '+label);
  };
  function fixture(kind='download') {
    let serial=1000;
    const c={sha:source,deploy:100};
    const first={id:100,path:'.github/workflows/deploy.yml',name:'Deploy production',event:'push',
      head_branch:'main',head_repository:{full_name:repository},head_sha:source,head_commit:{id:source},
      run_attempt:1,created_at:'2026-11-01T01:00:00Z',run_started_at:'2026-11-01T01:00:00Z',
      updated_at:'2026-11-01T01:09:00Z',status:'completed',conclusion:'failure'};
    const current={...copy(first),run_attempt:2,run_started_at:'2026-11-01T01:10:00Z',
      updated_at:'2026-11-01T01:19:00Z',conclusion:'success'};
    const step=(name,conclusion,attempt)=>({number:++serial,name,status:'completed',conclusion,
      started_at:attempt===1?'2026-11-01T01:03:00Z':'2026-11-01T01:13:00Z',
      completed_at:attempt===1?'2026-11-01T01:04:00Z':'2026-11-01T01:14:00Z'});
    const job=(name,attempt,conclusion,steps)=>({id:++serial,name,run_id:100,run_attempt:attempt,
      head_sha:source,status:'completed',conclusion,runner_id:1234,runner_name:'Hosted Agent',labels:['ubuntu-latest'],
      started_at:attempt===1?'2026-11-01T01:02:00Z':'2026-11-01T01:12:00Z',
      completed_at:attempt===1?'2026-11-01T01:08:00Z':'2026-11-01T01:18:00Z',steps});
    const originals=M.INSTALL_JOBS.map(name=>job(name,1,'success',[step('Original required install gate','success',1)]));
    const copied=originals.map(j=>({...copy(j),id:++serial,run_attempt:2}));
    const states={check:'success',download:'success',status:'success',cleanup:'success'};
    if(kind==='check'){states.check='failure';states.download='skipped';states.cleanup='skipped';}
    if(kind==='download'){states.download='failure';states.cleanup='skipped';}
    if(kind==='status'){states.status='failure';states.cleanup='skipped';}
    if(kind==='cleanup')states.cleanup='failure';
    const old=job('verify',1,'failure',Object.entries(M.VERIFY_STEPS).map(([k,n])=>step(n,states[k],1)));
    const needed=k=>states[k]!=='success'||(k==='status'&&(states.check!=='success'||states.download!=='success'));
    const latest=job('verify',2,'success',Object.entries(M.VERIFY_STEPS).map(([k,n])=>step(n,needed(k)?'success':'skipped',2)));
    const jobs=[...originals,old,...copied,latest];
    return {c,first,current,old,latest,originals,copied,history:{total_count:jobs.length,jobs}};
  }
  for(const kind of ['check','download','status','cleanup']) {
    const f=fixture(kind),r=M.deploymentHistory(f.history,f.first,f.current,f.c);
    check(r.smokeAttempt,kind==='check'?2:1,'verification recovery: original smoke reused '+kind);
    check(r.diagnosticsAttempt,['check','download'].includes(kind)?2:1,'verification recovery: original copy reused '+kind);
    check(r.plan.run_check,kind==='check','verification recovery: only failed check reexecutes '+kind);
    check(r.plan.run_download,['check','download'].includes(kind),'verification recovery: only unfinished copy '+kind);
    check(r.plan.run_status,kind!=='cleanup','verification recovery: negative result must be republished '+kind);
  }
  const invalid=[
    ['new installer execution',f=>{f.copied.at(-1).started_at='2026-11-01T01:11:00Z';},'installation_or_source_replayed'],
    ['new source runner',f=>{f.copied[0].runner_id++;},'installation_or_source_replayed'],
    ['changed original install result',f=>{f.originals.at(-1).conclusion='failure';},'installation_unconfirmed'],
    ['failed original install step',f=>{f.originals.at(-1).steps[0].conclusion='failure';},'installation_unconfirmed'],
    ['third attempt',f=>{f.current.run_attempt=3;},'untrusted_deployment_attempt'],
    ['manual run',f=>{f.current.event='workflow_dispatch';},'untrusted_deployment_attempt'],
    ['foreign source',f=>{f.current.head_sha='c'.repeat(40);},'untrusted_deployment_attempt'],
    ['foreign repository',f=>{f.current.head_repository.full_name='other/repo';},'untrusted_deployment_attempt'],
    ['unfinished original',f=>{f.first.status='in_progress';},'original_attempt_not_failed'],
    ['successful original',f=>{f.first.conclusion='success';},'original_attempt_not_failed'],
    ['unfinished recovery',f=>{f.current.status='in_progress';f.current.conclusion=null;},'invalid_recovery_attempt'],
    ['truncated jobs',f=>{f.history.total_count++;},'incomplete_deployment_jobs'],
    ['duplicate ids',f=>{f.copied[0].id=f.originals[0].id;},'incomplete_deployment_jobs'],
    ['foreign job',f=>{f.latest.head_sha='c'.repeat(40);},'untrusted_deployment_job'],
    ['missing install job',f=>{f.history.jobs.splice(0,1);f.history.total_count--;},'ambiguous_deployment_job'],
    ['accepted check replay',f=>{f.latest.steps[0].conclusion='success';},'accepted_step_replayed_or_unconfirmed'],
    ['lost step metadata',f=>{f.old.steps.pop();},'incomplete_job_steps'],
    ['recovery still failed',f=>{f.latest.conclusion='failure';},'recovery_not_confirmed'],
  ];
  for(const [label,mutate,code] of invalid) {
    const f=fixture();mutate(f);
    await rejects(()=>M.deploymentHistory(f.history,f.first,f.current,f.c),code,label);
  }
  const f=fixture();
  const marker={comment_id:900,head_sha:'b'.repeat(40),owner_id:800,updated_at:'2026-11-01T01:00:00Z'};
  const pr={number:55,state:'closed',merged:true,merge_commit_sha:source,
    head:{sha:marker.head_sha,repo:{full_name:repository}},base:{ref:'main',repo:{full_name:repository}},
    body:'<!-- release-slot-close:v1 '+JSON.stringify(marker)+' -->'};
  const record={id:900,user:{id:800},author_association:'OWNER',updated_at:marker.updated_at,
    issue_url:'https://api.github.com'+prefix+'/issues/1122',body:'PR55: выпуск занят'};
  const env={GITHUB_REPOSITORY:repository,GITHUB_EVENT_NAME:'push',GITHUB_REF:'refs/heads/main',
    GITHUB_JOB:'verify',GITHUB_SHA:source,GITHUB_RUN_ID:'100',GITHUB_RUN_ATTEMPT:'2'};
  const current=copy(f.current);current.status='in_progress';current.conclusion=null;
  const history=copy(f.history);history.jobs.at(-1).status='in_progress';history.jobs.at(-1).conclusion=null;
  const calls=[];
  const api=async(method,path)=>{
    calls.push({method,path});
    if(method!=='GET')throw Error('recovery attempted a write');
    if(path.endsWith('/git/ref/heads/main'))return{object:{sha:source}};
    if(path.includes('/commits/'))return[{number:55,state:'closed',merge_commit_sha:source}];
    if(path.endsWith('/pulls/55'))return copy(pr);
    if(path.includes('/issues/comments/'))return copy(record);
    if(path.endsWith('/attempts/1'))return copy(f.first);
    if(path.endsWith('/attempts/2'))return copy(current);
    if(path.includes('/jobs?filter=all'))return copy(history);
    throw Error('unexpected recovery API request '+path);
  };
  const plan=await M.admitVerification({env,api});
  check(plan.run_check,false,'verification recovery: API admission preserves successful check');
  check(plan.run_download,true,'verification recovery: API admission resumes failed copy');
  check(calls.every(x=>x.method==='GET'),true,'verification recovery: admission cannot mutate');
  check(calls.filter(x=>x.path.includes('/issues/comments/')).length,2,'verification recovery: fresh publisher version');
  await rejects(()=>M.admitVerification({env:{...env,GITHUB_RUN_ATTEMPT:'3'},api}),'untrusted_verification_context','third attempt before credentials');
  await rejects(()=>M.admitVerification({env,api:async()=>{throw Object.assign(Error('private'),{code:'api_http_429'});}}),'api_http_429','rate limit is not retried');
  await rejects(()=>M.admitVerification({env,api:async(m,p)=>p.endsWith('/git/ref/heads/main')?{object:{sha:'c'.repeat(40)}}:api(m,p)}),'main_changed','changed source before credentials');
  await rejects(()=>M.admitVerification({env,api:async(m,p)=>p.includes('/issues/comments/')?{...record,updated_at:'2026-11-01T01:00:01Z'}:api(m,p)}),'record_changed','publisher changed');
  const deploy={...M.stageStatus({...env,SMOKE_ATTEMPT:'1',DIAGNOSTICS_ATTEMPT:'2',CHECK_ACCEPTED:'success',DOWNLOAD_OUTCOME:'success'})};
  check(deploy.smoke,'success','verification recovery: stage status reuses accepted check');
  check(deploy.diagnostics_attempt,2,'verification recovery: receipt binds actual transfer attempt');
  const makeRun=(id,path,title)=>({id,path:'.github/workflows/'+path,event:'workflow_run',display_title:title,
    head_sha:source,head_branch:'main',head_repository:{full_name:repository},run_attempt:1,status:'completed',conclusion:'success'});
  const oldMonitor=makeRun(301,'modeled-conversion-monitor.yml','deploy-proof:v1:100:1');
  const newMonitor=makeRun(401,'modeled-conversion-monitor.yml','deploy-proof:v1:100:2');
  const oldGate=makeRun(302,'max-tls-preflight.yml','deploy-proof:v1:100:1');
  const newGate=makeRun(402,'max-tls-preflight.yml','deploy-proof:v1:100:2');
  const oldActivation=makeRun(303,M.ACTIVATION.file,'monitor-proof:v1:301:1');
  const newActivation=makeRun(403,M.ACTIVATION.file,'monitor-proof:v1:401:1');
  const snapshot={total_count:6,workflow_runs:[oldMonitor,newMonitor,oldGate,newGate,oldActivation,newActivation]};
  const skipped=async path=>{
    const runId=Number(path.match(/runs\/([0-9]+)/)[1]);
    return{total_count:1,jobs:[{run_id:runId,head_sha:source,status:'completed',conclusion:'skipped',steps:[]}]};
  };
  const selected=await M.selectRecoveryRuns(snapshot,{...f.c,recovery:{}},skipped);
  check(selected.total_count,3,'verification recovery: only exact all-skipped first wave ignored');
  const duplicate=copy(snapshot);duplicate.workflow_runs.push({...oldGate,id:304});duplicate.total_count++;
  await rejects(()=>M.selectRecoveryRuns(duplicate,{...f.c,recovery:{}},skipped),'ambiguous_run','duplicate failed-wave origin');
  await rejects(()=>M.selectRecoveryRuns(snapshot,{...f.c,recovery:{}},async p=>{
    const x=await skipped(p);x.jobs[0].conclusion='success';return x;
  }),'original_wave_executed','executed notification or gate cannot replay');
  const unknown=copy(snapshot);unknown.workflow_runs[2].display_title='unknown';
  await rejects(()=>M.selectRecoveryRuns(unknown,{...f.c,recovery:{}},skipped),'untrusted_gate_origin','timing is not provenance');
}

verifyReleasePriority().then(verifyReleaseRecovery).then(verifyReleaseSlot).then(verifyReleaseSlotActivation).then(() => {
  const admission = spawnSync(process.execPath, [path.resolve(__dirname, '../tools/release_priority.js'),
    '--admit', '1306', 'a'.repeat(40), 'b'.repeat(40)], {
    encoding: 'utf8', env: { GITHUB_REPOSITORY: 'pyatkoff/max-search-bot' }
  });
  check(admission.status, 1, 'CLI admission without token stops before I/O');
  check(admission.stderr.includes('missing_read_capability'), true, 'CLI admits valid arguments to the guard');
  const invalid = spawnSync(process.execPath, [path.resolve(__dirname, '../tools/release_priority.js'), '--admit'],
    { encoding: 'utf8', env: {} });
  check(invalid.status, 1, 'incomplete CLI admission cannot fall back to CI success');
  process.stdout.write('CI scope, release priority and release slot regression: ' + assertions + ' assertions passed\n');
}).catch(error => { process.stderr.write(String(error.stack || error) + '\n'); process.exitCode = 1; });

