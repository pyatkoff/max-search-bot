#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {provenance,verifyBrowserEvidence} = require('./manager2_visual_reuse.js');
const {allSpecs} = require('./manager2_visual_inventory.js');

function pngCount(directory) {
  if (!fs.existsSync(directory)) return 0;
  return fs.readdirSync(directory, {withFileTypes:true}).reduce((total, entry) => {
    const file = path.join(directory, entry.name);
    if (entry.isSymbolicLink()) throw new Error('Evidence must not contain symlinks');
    return total + (entry.isDirectory() ? pngCount(file) : Number(entry.name.endsWith('.png') && fs.statSync(file).size > 0));
  }, 0);
}

function verifyEvidence({root, testedSha, headSha, results, runId, runAttempt, prNumber, repository, sourceRoot=process.cwd(), env=process.env, requireModern=false}) {
  if (!Array.isArray(results) || results.length !== 3 || results.some(result => result !== 'success')) {
    throw new Error('Every browser and Firefox job must succeed; missing/skipped/cancelled is not release evidence');
  }
  if (![testedSha, headSha].every(value => /^[a-f0-9]{40}$/.test(value)) ||
      ![runId, runAttempt].every(value => /^[1-9][0-9]*$/.test(String(value)))) {
    throw new Error('Exact tested/head SHA and run provenance are required');
  }
  const receipts = [];
  for (const [browser, specCount, captureCount] of [['chromium',22,34], ['webkit',21,0]]) {
    const directory = path.join(root, browser);
    const receipt = JSON.parse(fs.readFileSync(path.join(directory, 'visual-receipt.json'), 'utf8'));
    const modern=receipt.schema===2;
    if (requireModern&&!modern) throw new Error('Current release requires exact schema-2 case and artifact proof');
    const expected = {browser, testedSha, headSha, specCount:modern?allSpecs(browser).length:specCount, captureCount, completed:true};
    if (!modern) expected.mode='full';
    for (const [key, value] of Object.entries(expected)) {
      if (receipt[key] !== value) throw new Error(browser + ' has incomplete or mismatched ' + key);
    }
    // A failed-jobs-only rerun may reuse an already successful engine from the
    // same run and exact tree. Keep that attempt in the receipt, without forcing
    // unrelated green engines to run again.
    if (String(receipt.runId) !== String(runId) || !/^[1-9][0-9]*$/.test(String(receipt.runAttempt)) ||
        Number(receipt.runAttempt) > Number(runAttempt)) {
      throw new Error(browser + ' evidence belongs to another run or a future attempt');
    }
    if (pngCount(path.join(directory, 'visual-artifacts')) !== captureCount || pngCount(directory) === 0) {
      throw new Error(browser + ' screenshots are missing or incomplete');
    }
    if (modern) {
      const p=provenance({...env,GITHUB_REPOSITORY:repository||env.GITHUB_REPOSITORY,
        QA_PR_NUMBER:prNumber??env.QA_PR_NUMBER??'',QA_TESTED_SHA:testedSha,QA_HEAD_SHA:headSha,
        QA_RUN_ID:String(runId),QA_RUN_ATTEMPT:String(receipt.runAttempt)});
      verifyBrowserEvidence({root:directory,browser,p,sourceRoot});
      if (receipt.mode!=='full'&&receipt.mode!=='affected') throw new Error('Unrecognized coverage mode');
    }
    receipts.push(receipt);
  }
  return {testedSha, headSha, runId:String(runId), runAttempt:String(runAttempt), results, receipts};
}

if (require.main === module) {
  try {
    const [root, testedSha, headSha, browserResult, historyResult, notificationResult, runId, runAttempt] = process.argv.slice(2);
    const evidence = verifyEvidence({root, testedSha, headSha, results:[browserResult, historyResult, notificationResult], runId, runAttempt, requireModern:true});
    fs.writeFileSync(path.join(root, 'visual-evidence.json'), JSON.stringify(evidence, null, 2) + '\n');
    if (process.env.GITHUB_OUTPUT) fs.appendFileSync(process.env.GITHUB_OUTPUT,'mode='+
      (evidence.receipts.every(receipt=>receipt.mode==='full')?'full':'affected')+'\n');
    process.stdout.write('Full browser evidence verified for ' + testedSha + '\n');
  } catch (error) {
    process.stderr.write(error.message + '\n');
    process.exitCode = 1;
  }
}

module.exports = {verifyEvidence};
