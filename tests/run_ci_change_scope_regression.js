#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { classifyChanges, parseRawDiff, oauthSpecOnlyChange } = require('../tools/ci_change_scope');
const selector = path.resolve(__dirname, '../tools/ci_change_scope.js');
const oauth = 'manager/ai-oauth.php';
const fixture = 'tests/visual/fixtures/oauth-consent.js';
const doc = 'docs/MANAGER_PREPARED_REPLIES.md';
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
  put(browserSpec, sharedSpec);
  git(['add', '.']); git(['commit', '-qm', 'base']);
  const base = git(['rev-parse', 'HEAD']);
  const eventPath = path.join(temp, 'event.json');
  const outputPath = path.join(temp, 'output.txt');
  function run(event, overrides = {}) {
    fs.writeFileSync(eventPath, typeof event === 'string' ? event : JSON.stringify(event));
    fs.writeFileSync(outputPath, '');
    const env = { ...process.env, GITHUB_EVENT_NAME: 'pull_request', GITHUB_EVENT_PATH: eventPath, GITHUB_OUTPUT: outputPath, ...overrides };
    const result = JSON.parse(execFileSync(process.execPath, [selector], { cwd: temp, env, encoding: 'utf8' }));
    check(fs.readFileSync(outputPath, 'utf8'), `mode=${result.mode}\n`, 'GitHub output matches JSON');
    return result.mode;
  }
  const event = head => ({ pull_request: { base: { sha: base }, head: { sha: head } } });
  function commitCase(message, mutate, expected) {
    git(['reset', '--hard', '-q', base]); git(['clean', '-fdq']);
    mutate(); git(['add', '-A']); git(['commit', '-qm', message]);
    check(run(event(git(['rev-parse', 'HEAD']))), expected, message);
  }
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
  const brokenHead = git(['rev-parse', 'HEAD']);
  const brokenTree = git(['rev-parse', `${brokenHead}^{tree}`]);
  fs.unlinkSync(path.join(temp, '.git', 'objects', brokenTree.slice(0, 2), brokenTree.slice(2)));
  check(run(event(brokenHead)), 'full', 'diff failure after commit refs validate');
} finally { fs.rmSync(temp, { recursive: true, force: true }); }
process.stdout.write(`CI change scope regression: ${assertions} assertions passed\n`);
