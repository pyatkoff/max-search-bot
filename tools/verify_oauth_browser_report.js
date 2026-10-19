#!/usr/bin/env node
'use strict';

const fs = require('node:fs');

function verifyReport(report, expectedBrowser) {
  const requireThat = (condition, message) => { if (!condition) throw new Error(message); };
  requireThat(['chromium', 'webkit'].includes(expectedBrowser), 'Expected browser must be chromium or webkit');
  requireThat(report && typeof report === 'object', 'Missing Playwright report');
  requireThat(Array.isArray(report.config?.projects) && report.config.projects.some(project => project?.name === expectedBrowser), 'Browser report configuration does not contain the expected browser');
  requireThat(Array.isArray(report.errors) && report.errors.length === 0, 'Playwright reported errors');
  requireThat(Array.isArray(report.suites), 'Missing Playwright suites');
  const specs = [];
  function visit(suite) {
    requireThat(suite && typeof suite === 'object', 'Invalid Playwright suite');
    if (suite.specs !== undefined) {
      requireThat(Array.isArray(suite.specs), 'Invalid Playwright specs');
      specs.push(...suite.specs);
    }
    if (suite.suites !== undefined) {
      requireThat(Array.isArray(suite.suites), 'Invalid nested Playwright suites');
      suite.suites.forEach(visit);
    }
  }
  report.suites.forEach(visit);
  requireThat(specs.length > 0, 'Browser run contains zero specs');
  const titles = new Set();
  const ids = new Set();
  let testCount = 0;
  for (const spec of specs) {
    requireThat(spec && typeof spec.title === 'string' && spec.title.length > 0, 'Missing spec title');
    requireThat(!titles.has(spec.title), `Duplicate browser spec: ${spec.title}`);
    titles.add(spec.title);
    if (spec.id !== undefined) {
      requireThat(typeof spec.id === 'string' && spec.id.length > 0 && !ids.has(spec.id), 'Duplicate or invalid browser spec id');
      ids.add(spec.id);
    }
    requireThat(Array.isArray(spec.tests) && spec.tests.length > 0, `No tests for ${spec.title}`);
    for (const test of spec.tests) {
      requireThat(test && test.projectName === expectedBrowser, `Unexpected or missing browser project: ${spec.title}`);
      requireThat(test && test.status === 'expected' && test.expectedStatus === 'passed', `Browser test did not pass: ${spec.title}`);
      requireThat(Array.isArray(test.results) && test.results.length > 0, `No browser results: ${spec.title}`);
      requireThat(test.results.every(result => result && result.status === 'passed'), `Browser test failed, skipped or retried after failure: ${spec.title}`);
      testCount++;
    }
  }
  const oauth = [...titles].filter(title => title.startsWith('OAuth native '));
  requireThat(oauth.length === 3 && specs.length === 3, 'Browser report must contain exactly the three OAuth native consent specs');
  requireThat(['allow', 'deny', 'legacy'].every(action => oauth.some(title => new RegExp(`^OAuth native ${action}\\b`).test(title))), 'Missing distinct OAuth allow, deny or legacy coverage');
  const stats = report.stats;
  requireThat(stats && stats.skipped === 0 && stats.unexpected === 0, 'Browser report contains skipped or unexpected tests');
  requireThat(Number.isInteger(stats.expected) && stats.expected === testCount, 'Browser expected-test count does not match consent results');
  return { specs: specs.length, tests: testCount, oauthSpecs: oauth.length };
}

if (require.main === module) {
  try {
    if (process.argv.length !== 4) throw new Error('Usage: node tools/verify_oauth_browser_report.js REPORT.json chromium|webkit');
    const result = verifyReport(JSON.parse(fs.readFileSync(process.argv[2], 'utf8')), process.argv[3]);
    process.stdout.write(`OAuth browser report verified: ${JSON.stringify(result)}\n`);
  } catch (error) {
    process.stderr.write(`OAuth browser report rejected: ${error.message}\n`);
    process.exitCode = 1;
  }
}

module.exports = { verifyReport };
