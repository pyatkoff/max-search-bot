#!/usr/bin/env node
'use strict';

const crypto = require('node:crypto');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

const SCHEMA = 2;
const TOOLCHAIN = Object.freeze({node:'22', playwright:'1.55.0', php:'8.3', runner:'ubuntu-latest'});
// These reviewed leaf specs are not imported by another spec. Shared fixtures,
// source, workflow and inventory changes deliberately remain full-suite changes.
const LEAF_SPECS = Object.freeze([
  'tests/visual/manager2-inbox-compact.spec.js',
  'tests/visual/manager2-messenger-layout.spec.js',
]);
const MAIN = Object.freeze([
  'workspace-send-feedback', 'workspace-notification-diagnostics', 'manager-browser-push-state',
  'workspace-emoji-picker', 'workspace-timezone', 'workspace-conversation-scroll',
  'manager2-workspace', 'manager2-messenger-layout', 'manager2-inbox-compact',
  'manager-admin-load', 'manager-admin-workspace', 'manager-quick-replies',
  'manager-personal-workspace', 'manager-manual-groups', 'manager-internal-notes', 'manager-tour-selection', 'manager-assistant',
  'manager-customer-name', 'manager2-customer-avatar', 'manager-media-compose', 'manager-ai-draft',
  'manager-handoff-cancellation', 'manager-handoff-cancellation-shell', 'manager-transcript-history',
].map(name => 'tests/visual/' + name + '.spec.js'));
const CACHE_SPEC = 'tests/visual/manager-avatar-cache.spec.js';
function mainSpecs(browser) {
  if (!['chromium','webkit'].includes(browser)) throw new Error('Unknown main browser');
  return browser === 'chromium' ? ['tests/visual/workspace-v2-layout.spec.js', ...MAIN] : [...MAIN];
}
function allSpecs(browser) { return [CACHE_SPEC, ...mainSpecs(browser)]; }
// Whole-spec, reviewed output ownership: part zero also owns all layout/CLI
// captures and the daily-cache spec. Never split a spec's shared fixtures.
const PART_ZERO = Object.freeze([
  'workspace-v2-layout','workspace-notification-diagnostics','manager-browser-push-state',
  'workspace-timezone','workspace-conversation-scroll','manager2-workspace',
  'manager2-inbox-compact','manager-admin-load','manager-personal-workspace',
  'manager-customer-name','manager-media-compose',
].map(name=>'tests/visual/'+name+'.spec.js'));
const PART_COUNT = 2;
function partitionSpecs(browser, part) {
  if (!Number.isInteger(part)||part<0||part>=PART_COUNT) throw new Error('Invalid browser partition');
  return mainSpecs(browser).filter(spec=>PART_ZERO.includes(spec)===(part===0));
}
function alwaysSpecs(browser) {
  mainSpecs(browser);
  return browser === 'chromium'
    ? ['tests/visual/manager2-workspace.spec.js','tests/visual/workspace-v2-layout.spec.js']
    : ['tests/visual/manager2-workspace.spec.js'];
}
const hash = value => crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
// Independently reviewed tests/** tree at 04a35786e2f2521d9e06eebc721fef4cc85dec66
// plus this CI-only PHP wiring guard (344 non-exempt entries),
// excluding the two leaves and only the finite CI-only selftest/manifest files
// below. Fixtures imported from tests/fixtures or future tests subdirectories
// are covered too. Merely running a new full donor after a
// helper/spec change does NOT renew the leaf-independence audit: another spec
// could now import a leaf. A deliberate re-audit and versioned pin update are
// required before that new harness may reuse evidence. Product/CSS changes do
// not change this pin, but still require an identical-product full donor.
const HARNESS_CI_EXEMPTIONS = Object.freeze([
  'tests/run_manager2_visual_selection_regression.js',
  'tests/run_manager2_visual_gate_regression.js',
  'tests/required_checks_manifest.php',
]);
// Independently re-audited all 23 incoming #982/#983/#984 test/fixture deltas
// since #980, plus the partition workflow text guard. No imports of either
// leaf outside exempt synthetic selftests; no additional exemptions introduced.
// Re-audited the manual-groups native reading-intent readiness helper separately:
// imports and all post-action assertions unchanged; still the same 344 entries.
// Re-audited the CI-only workflow contract guard for prepared-image wiring.
// Native specs/fixtures and leaf imports are unchanged; no new exemptions.
const reviewedHarnessDigest = 'c2b2a6e4100e07374f41198d53217b99d02625905bfc6c325727fc1a0f878988';
const registryDigest = hash({SCHEMA,TOOLCHAIN,LEAF_SPECS,HARNESS_CI_EXEMPTIONS,reviewedHarnessDigest,PART_COUNT,PART_ZERO,chromium:allSpecs('chromium'),webkit:allSpecs('webkit'),
  firefoxHistory:{spec:'tests/visual/manager-transcript-history.spec.js',grep:'older transport'},
  firefoxNotifications:['tests/visual/workspace-notification-diagnostics.spec.js','tests/visual/manager-browser-push-state.spec.js']});
const comparePaths = (a,b) => a.path < b.path ? -1 : a.path > b.path ? 1 : 0;
function commonDigest(entries) { return hash(entries.filter(entry => !LEAF_SPECS.includes(entry.path))); }
function harnessDigest(entries) { return hash(entries.filter(entry => entry.path.startsWith('tests/')&&!LEAF_SPECS.includes(entry.path)&&!HARNESS_CI_EXEMPTIONS.includes(entry.path))); }
function sourceManifest(root, ref='HEAD') {
  const git = args => execFileSync('git',args,{cwd:root,encoding:'utf8',maxBuffer:32*1024*1024});
  const tree = git(['rev-parse','--verify','--end-of-options',ref+'^{tree}']).trim();
  const entries = git(['ls-tree','-r','-z',tree]).split('\0').filter(Boolean).map(record => {
    const match = /^(\d{6}) (\w+) ([a-f0-9]{40,64})\t([\s\S]+)$/.exec(record);
    if (!match || !['100644','100755'].includes(match[1]) || match[2] !== 'blob') {
      throw new Error('Source manifest forbids symlinks, submodules and unknown modes');
    }
    return {path:match[4],mode:match[1],blob:match[3]};
  }).sort(comparePaths);
  const leaves = Object.fromEntries(LEAF_SPECS.map(file => [file,entries.find(entry => entry.path===file)||null]));
  return {schema:SCHEMA,registryDigest,toolchain:TOOLCHAIN,commonDigest:commonDigest(entries),harnessDigest:harnessDigest(entries),entries,leaves};
}
function assertCleanSource(root,testedSha) {
  if (typeof testedSha!=='string'||!/^[a-f0-9]{40,64}$/.test(testedSha)) throw new Error('Exact tested SHA is required for clean source');
  const git=args=>execFileSync('git',args,{cwd:root,encoding:'utf8',maxBuffer:32*1024*1024});
  if (git(['rev-parse','HEAD']).trim()!==testedSha) throw new Error('Source HEAD differs from tested SHA');
  if (git(['diff','--name-only','-z',testedSha,'--']).length || git(['diff','--cached','--name-only','-z',testedSha,'--']).length) {
    throw new Error('Tracked source worktree/index differs from tested SHA');
  }
  // Include ignored files too: an ignored helper is still executable source.
  // Browser installs and normal screenshots/reports live outside these source
  // prefixes or use non-source extensions, so they do not make CI dirty.
  const untracked=git(['ls-files','--others','-z','--','manager/','manager2/','tests/visual/','tests/fixtures/','tests/support/']).split('\0').filter(Boolean);
  if (untracked.some(file=>/\.(?:[cm]?js|jsx|tsx?|css|php|html?)$/i.test(file))) throw new Error('Untracked executable/source file in visual dependency tree');
  return true;
}
function assertSafeEnvironment(env=process.env) {
  const forbidden = Object.keys(env).filter(key =>
    /^M2_(?:QA_|HISTORY_BASELINE|ARTIFACT_ROOT|CAPACITY_)/.test(key) ||
    ['NODE_OPTIONS','NODE_PATH','PLAYWRIGHT_TEST_DIR','PLAYWRIGHT_TEST_CONFIG','PLAYWRIGHT_JSON_OUTPUT_FILE','PLAYWRIGHT_JSON_OUTPUT_NAME','PLAYWRIGHT_JSON_OUTPUT_DIR','PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD','PLAYWRIGHT_BROWSERS_PATH','PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH','PLAYWRIGHT_FIREFOX_EXECUTABLE_PATH','PLAYWRIGHT_WEBKIT_EXECUTABLE_PATH'].includes(key)
  );
  if (forbidden.length) throw new Error('Release source/environment override forbidden: '+forbidden.sort().join(', '));
  return true;
}
function validManifest(manifest) {
  if (!manifest || manifest.schema!==SCHEMA || manifest.registryDigest!==registryDigest ||
      JSON.stringify(manifest.toolchain)!==JSON.stringify(TOOLCHAIN) || !Array.isArray(manifest.entries)) return false;
  let previous='';
  for (const entry of manifest.entries) {
    if (!entry || typeof entry.path!=='string' || !entry.path || entry.path.startsWith('/') ||
        entry.path.split('/').some(part => part==='..'||part==='.'||part==='') ||
        !['100644','100755'].includes(entry.mode) || !/^[a-f0-9]{40,64}$/.test(entry.blob) ||
        (previous && previous>=entry.path)) return false;
    previous=entry.path;
  }
  return manifest.commonDigest===commonDigest(manifest.entries) && manifest.harnessDigest===harnessDigest(manifest.entries) && LEAF_SPECS.every(file =>
    JSON.stringify(manifest.leaves?.[file])===JSON.stringify(manifest.entries.find(entry => entry.path===file)||null));
}
function classifyChanges(donorManifest,currentManifest) {
  const full = reason => ({mode:'full',reason,changedSpecs:[]});
  if (!validManifest(donorManifest)||!validManifest(currentManifest)) return full('Invalid source manifest or registry');
  if (donorManifest.harnessDigest!==reviewedHarnessDigest || currentManifest.harnessDigest!==reviewedHarnessDigest) return full('Visual harness requires a new leaf-independence audit');
  if (donorManifest.commonDigest!==currentManifest.commonDigest) return full('Shared or unknown source changed');
  if (donorManifest.entries.length!==currentManifest.entries.length || donorManifest.entries.some((entry,index) =>
    entry.path!==currentManifest.entries[index].path || entry.mode!==currentManifest.entries[index].mode)) return full('Source paths or modes changed');
  const changedSpecs=donorManifest.entries.filter((entry,index) => entry.blob!==currentManifest.entries[index].blob).map(entry=>entry.path);
  if (!changedSpecs.length) return full('No changed leaf spec');
  if (changedSpecs.some(file=>!LEAF_SPECS.includes(file))) return full('Non-leaf change');
  return {mode:'affected',reason:'Only audited existing leaf specs changed',changedSpecs};
}

function collectCases(report,{browser,expectedFiles,listing=false}={}) {
  if (!['chromium','webkit','firefox'].includes(browser)) throw new Error('Unknown report browser');
  if (!report || !Array.isArray(report.suites) || report.errors?.length) throw new Error('Invalid or errored Playwright report');
  const allowed=expectedFiles ? new Set(expectedFiles) : null;
  if (allowed && (allowed.size!==expectedFiles.length || !allowed.size)) throw new Error('Invalid expected spec inventory');
  const root=report.config?.rootDir;
  function specPath(file) {
    if (typeof file!=='string' || !file || file.includes('\\') || file.split('/').includes('..')) throw new Error('Invalid report spec path');
    let relative=file;
    if (path.isAbsolute(file)) {
      if (typeof root!=='string'||!path.isAbsolute(root)||!file.startsWith(root.replace(/\/$/,'')+'/')) throw new Error('Spec outside report root');
      relative=path.relative(root,file).split(path.sep).join('/');
    }
    // Playwright may set rootDir to tests/visual instead of the repository.
    if (!relative.startsWith('tests/visual/')) {
      const base=typeof root==='string' ? root.replace(/\\/g,'/') : '';
      if (base.endsWith('/tests/visual')) relative='tests/visual/'+relative;
      else if (base.endsWith('/tests') && relative.startsWith('visual/')) relative='tests/'+relative;
    }
    if (!/^tests\/visual\/[\w./-]+\.spec\.js$/.test(relative) || (allowed&&!allowed.has(relative))) throw new Error('Unexpected report spec '+relative);
    return relative;
  }
  const cases=[],keys=new Set(),seenFiles=new Set();
  function visit(suite,titles=[],inheritedFile) {
    if (!suite || !Array.isArray(suite.suites||[]) || !Array.isArray(suite.specs||[])) throw new Error('Invalid Playwright suite');
    const file=suite.file||inheritedFile;
    // The outer file-suite label duplicates the spec path and may be absolute.
    const isFileSuite=suite.file && !inheritedFile;
    const nextTitles=isFileSuite||!suite.title ? titles : [...titles,suite.title];
    for (const spec of suite.specs||[]) {
      const relative=specPath(spec.file||file);
      if (typeof spec.title!=='string'||!spec.title||!Array.isArray(spec.tests)||!spec.tests.length) throw new Error('Empty Playwright spec');
      seenFiles.add(relative);
      for (const test of spec.tests) {
        const project=test.projectName??'';
        // The pinned CLI always uses --browser=<engine>, producing the named
        // engine project. Default/foreign projects cannot attest this engine.
        if (project!==browser || !Array.isArray(test.results)) throw new Error('Invalid or mismatched Playwright browser project');
        if (test.expectedStatus!=='passed' || (test.annotations||[]).some(annotation=>['skip','fixme','fail'].includes(annotation.type))) throw new Error('Skipped, fixme or expected failure case');
        if (!listing && (test.status!=='expected'||!test.results.length||test.results.some(result=>result.status!=='passed'||result.error||result.errors?.length))) throw new Error('Incomplete, flaky or failed Playwright case');
        const title=[...nextTitles,spec.title];
        const key=JSON.stringify([browser,project,relative,title]);
        if (keys.has(key)) throw new Error('Duplicate Playwright case');
        keys.add(key);cases.push({key,spec:relative,title,project});
      }
    }
    for (const child of suite.suites||[]) visit(child,nextTitles,file);
  }
  for (const suite of report.suites) visit(suite);
  if (!cases.length || (allowed&&[...allowed].some(file=>!seenFiles.has(file)))) throw new Error('Missing expected Playwright cases/specs');
  return cases.sort((a,b)=>a.key<b.key?-1:a.key>b.key?1:0);
}

module.exports={SCHEMA,TOOLCHAIN,LEAF_SPECS,reviewedHarnessDigest,PART_COUNT,partitionSpecs,mainSpecs,allSpecs,alwaysSpecs,sourceManifest,assertCleanSource,assertSafeEnvironment,validManifest,classifyChanges,collectCases};
