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
// The same fail-closed browser-profile gate also owns the complete selection
// component. Its errors cannot be hidden by a smaller matching --list report.
const tour=require('../tools/verify_tour_selection_browser_report');
function tourPair(browser='chromium'){
  const report={config:{forbidOnly:true,projects:[{name:browser}]},errors:[],
    suites:[{title:'selection file',file:tour.SPEC,specs:tour.mandatoryTitles.map((title,index)=>({id:'tour-'+index,title,file:tour.SPEC,
      tests:[{projectName:browser,expectedStatus:'passed',status:'expected',results:[{status:'passed',retry:0}]}]}))}],
    stats:{expected:tour.mandatoryTitles.length,skipped:0,unexpected:0,flaky:0,startTime:'2026-10-22T12:00:00Z',duration:1000}};
  const expected=structuredClone(report);expected.stats.expected=0;
  for(const spec of expected.suites[0].specs)spec.tests[0].results=[];
  return {report,expected,browser};
}
function tourCheck(pair){return tour.verifyReport(pair.report,pair.expected,pair.browser);}
function tourReject(change){const pair=tourPair();change(pair);assert.throws(()=>tourCheck(pair));assertions++;}
for(const browser of ['chromium','webkit']){assert.equal(tourCheck(tourPair(browser)).length,29);assertions++;}
tourReject(pair=>{pair.browser='firefox';});
tourReject(pair=>{pair.browser='webkit';});
tourReject(pair=>{pair.report.config.forbidOnly=false;});
tourReject(pair=>{pair.report.config.projects.push({name:'webkit'});});
tourReject(pair=>{delete pair.report.errors;});
tourReject(pair=>{pair.report.errors=[{message:'worker crashed'}];});
tourReject(pair=>{pair.report.suites=[];});
tourReject(pair=>{pair.expected.suites=[];});
tourReject(pair=>{pair.report.suites[0].specs.pop();});
tourReject(pair=>{pair.expected.suites[0].specs.pop();});
tourReject(pair=>{pair.report.suites[0].specs.pop();pair.expected.suites[0].specs.pop();pair.report.stats.expected--;});
tourReject(pair=>{pair.report.suites[0].specs.push(structuredClone(pair.report.suites[0].specs[0]));});
tourReject(pair=>{pair.report.suites[0].specs[0].file='tests/visual/manager-ai-draft.spec.js';});
for(const status of ['failed','timedOut','skipped','interrupted'])tourReject(pair=>{pair.report.suites[0].specs[0].tests[0].results[0].status=status;});
tourReject(pair=>{pair.report.suites[0].specs[0].tests[0].status='flaky';});
tourReject(pair=>{pair.report.suites[0].specs[0].tests[0].expectedStatus='failed';});
tourReject(pair=>{pair.report.suites[0].specs[0].tests[0].annotations=[{type:'skip'}];});
tourReject(pair=>{pair.report.suites[0].specs[0].tests[0].results.push({status:'passed',retry:1});});
tourReject(pair=>{pair.report.suites[0].specs[0].tests[0].results[0].retry=1;});
tourReject(pair=>{pair.expected.suites[0].specs[0].tests[0].results=[{status:'passed',retry:0}];});
for(const field of ['expected','skipped','unexpected','flaky'])tourReject(pair=>{pair.report.stats[field]++;});
tourReject(pair=>{delete pair.report.stats.startTime;});
tourReject(pair=>{pair.report.stats.duration=-1;});
// Additional feature coverage is permitted, but all of it must execute.
const more=tourPair();
const extra=structuredClone(more.report.suites[0].specs[0]);extra.title='additional selection contract';extra.id='extra';
more.report.suites[0].specs.push(extra);more.report.stats.expected++;
const listedExtra=structuredClone(extra);listedExtra.tests[0].results=[];more.expected.suites[0].specs.push(listedExtra);
assert.equal(tourCheck(more).length,30);assertions++;
process.stdout.write(`Scoped browser report regression (OAuth and selection): ${assertions} assertions passed\n`);
