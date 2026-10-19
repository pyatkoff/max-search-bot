#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { verifyReport } = require('../tools/verify_oauth_browser_report');
const validator = path.resolve(__dirname, '../tools/verify_oauth_browser_report.js');
let assertions = 0;
function valid(browser = 'chromium') {
  const titles = ['OAuth native allow redirects', 'OAuth native deny redirects', 'OAuth native legacy control'];
  return {
    config: { projects: [{ name: browser }] },
    errors: [],
    suites: [{ title: 'file', suites: [{ title: 'nested', specs: titles.map((title, index) => ({
      id: `spec-${index}`, title, tests: [{ projectName: browser, status: 'expected', expectedStatus: 'passed', results: [{ status: 'passed' }] }],
    })) }] }],
    stats: { expected: 3, skipped: 0, unexpected: 0 },
  };
}
function rejected(change, message) {
  const report = valid(); change(report);
  assert.throws(() => verifyReport(report, 'chromium'), undefined, message); assertions++;
}
const specs = report => report.suites[0].suites[0].specs;
assert.deepEqual(verifyReport(valid(), 'chromium'), { specs: 3, tests: 3, oauthSpecs: 3 }); assertions++;
assert.deepEqual(verifyReport(valid('webkit'), 'webkit'), { specs: 3, tests: 3, oauthSpecs: 3 }); assertions++;
assert.throws(() => verifyReport(valid('webkit'), 'chromium')); assertions++;
assert.throws(() => verifyReport(valid(), undefined)); assertions++;
assert.throws(() => verifyReport(valid(), 'firefox')); assertions++;
rejected(report => { delete report.config; }, 'missing project configuration');
rejected(report => { report.config.projects = []; }, 'missing browser in configuration');
rejected(report => { specs(report)[0].tests[0].projectName = 'webkit'; }, 'wrong project in test results');
rejected(report => { delete specs(report)[0].tests[0].projectName; }, 'missing project in test results');
for (const status of ['failed', 'timedOut', 'skipped', 'interrupted', undefined]) {
  rejected(report => { specs(report)[0].tests[0].results[0].status = status; }, `reject result status ${status}`);
}
for (const status of ['unexpected', 'flaky', 'skipped', undefined]) {
  rejected(report => { specs(report)[0].tests[0].status = status; }, `reject test status ${status}`);
}
rejected(report => { specs(report)[0].tests[0].expectedStatus = 'failed'; }, 'expected failure is not successful consent');
rejected(report => { specs(report)[0].tests[0].results.unshift({ status: 'failed' }); }, 'later successful retry cannot hide failure');
rejected(report => { const extra = structuredClone(specs(report)[0]); extra.id = 'AI-extra'; extra.title = 'AI draft editor'; specs(report).push(extra); report.stats.expected++; }, 'AI UI tests must not appear in OAuth browser report');
rejected(report => { const extra = structuredClone(specs(report)[0]); extra.id = 'OAuth-extra'; extra.title = 'OAuth native extra control'; specs(report).push(extra); report.stats.expected++; }, 'extra OAuth tests cannot silently broaden the consent run');
rejected(report => { specs(report)[0].tests = []; }, 'zero tests');
rejected(report => { specs(report)[0].tests[0].results = []; }, 'zero results');
rejected(report => { specs(report)[0].tests.push({ status: 'skipped', expectedStatus: 'passed', results: [] }); }, 'skipped extra project test');
rejected(report => { specs(report)[1].title = specs(report)[0].title; }, 'duplicate titles');
rejected(report => { specs(report)[1].id = specs(report)[0].id; }, 'duplicate IDs');
rejected(report => { specs(report)[0].title = ''; }, 'missing title');
rejected(report => { specs(report).pop(); specs(report).pop(); }, 'fewer than three OAuth cases');
rejected(report => { specs(report)[0].title = 'OAuth native consent redirects'; }, 'missing allow');
rejected(report => { specs(report)[1].title = 'OAuth native cancel redirects'; }, 'missing deny');
rejected(report => { specs(report)[2].title = 'OAuth native extra control'; }, 'missing legacy control');
rejected(report => { specs(report)[0].title = 'OAuth native allow deny legacy'; specs(report)[1].title = 'OAuth native other control'; }, 'one title cannot supply several distinct consent cases');
rejected(report => { report.suites = []; }, 'empty report');
rejected(report => { report.suites[0].suites[0].specs = null; }, 'malformed nested specs');
rejected(report => { report.errors.push({ message: 'worker crashed' }); }, 'worker error');
rejected(report => { delete report.errors; }, 'missing errors inventory');
rejected(report => { report.stats.skipped = 1; }, 'aggregate skipped');
rejected(report => { report.stats.unexpected = 1; }, 'aggregate unexpected');
rejected(report => { report.stats.expected = 2; }, 'incomplete expected count');
rejected(report => { report.stats.expected = 4; }, 'inflated expected count');
rejected(report => { report.stats.expected = '4'; }, 'malformed expected count');
rejected(report => { report.stats = null; }, 'missing statistics');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'oauth-browser-report-'));
try {
  const reportPath = path.join(temp, 'report.json');
  fs.writeFileSync(reportPath, JSON.stringify(valid()));
  assert.equal(spawnSync(process.execPath, [validator, reportPath, 'chromium']).status, 0); assertions++;
  assert.equal(spawnSync(process.execPath, [validator, reportPath, 'webkit']).status, 1); assertions++;
  assert.equal(spawnSync(process.execPath, [validator, reportPath]).status, 1); assertions++;
  fs.writeFileSync(reportPath, '{invalid');
  assert.equal(spawnSync(process.execPath, [validator, reportPath, 'chromium']).status, 1); assertions++;
  assert.equal(spawnSync(process.execPath, [validator, path.join(temp, 'missing.json'), 'chromium']).status, 1); assertions++;
  assert.equal(spawnSync(process.execPath, [validator]).status, 1); assertions++;
} finally { fs.rmSync(temp, { recursive: true, force: true }); }
process.stdout.write(`OAuth browser report regression: ${assertions} assertions passed\n`);
