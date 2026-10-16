'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {verifyEvidence} = require('../tools/verify_manager2_visual_evidence.js');
const root = fs.mkdtempSync(path.join(os.tmpdir(), 'manager2-ci-evidence-'));
const testedSha = 'a'.repeat(40), headSha = 'b'.repeat(40);
const options = {root, testedSha, headSha, results:['success','success','success'], runId:'123', runAttempt:'1'};
function reset() {
  fs.rmSync(root, {recursive:true, force:true});
  for (const [browser, specCount, captureCount] of [['chromium',22,34], ['webkit',21,0]]) {
    const directory = path.join(root, browser);
    fs.mkdirSync(path.join(directory, 'visual-artifacts'), {recursive:true});
    fs.writeFileSync(path.join(directory, 'visual-receipt.json'), JSON.stringify({mode:'full',browser,testedSha,headSha,runId:'123',runAttempt:'1',specCount,captureCount,completed:true}));
    for (let index=0; index<captureCount; index++) fs.writeFileSync(path.join(directory,'visual-artifacts',index+'.png'), 'synthetic evidence');
    fs.writeFileSync(path.join(directory,'browser.png'), 'synthetic evidence');
  }
}
let passed=0;
function check(name, action) {reset(); action(); passed++; process.stdout.write('PASS '+name+'\n');}
try {
  check('complete successful exact-run evidence passes',()=>assert.equal(verifyEvidence(options).receipts.length,2));
  check('successful same-run exact-tree evidence survives a failed-jobs-only rerun',()=>assert.equal(verifyEvidence({...options,runAttempt:'2'}).receipts.length,2));
  for (const result of ['failure','skipped','cancelled',undefined]) {
    check('a '+String(result)+' child cannot make the release gate green',()=>{
      for(let index=0;index<3;index++) {
        const results=[...options.results]; results[index]=result;
        assert.throws(()=>verifyEvidence({...options,results}),/Every browser/);
      }
    });
  }
  check('missing browser artifact fails',()=>{fs.rmSync(path.join(root,'webkit'),{recursive:true});assert.throws(()=>verifyEvidence(options));});
  check('incomplete screenshots fail',()=>{fs.unlinkSync(path.join(root,'chromium','visual-artifacts','0.png'));assert.throws(()=>verifyEvidence(options),/missing or incomplete/);});
  check('an empty browser evidence directory fails',()=>{fs.unlinkSync(path.join(root,'webkit','browser.png'));assert.throws(()=>verifyEvidence(options),/missing or incomplete/);});
  for (const [key,value] of Object.entries({mode:'preview',browser:'chromium',testedSha:'c'.repeat(40),headSha:'c'.repeat(40),runId:'124',runAttempt:'2',specCount:20,completed:false})) {
    check('mismatched '+key+' fails',()=>{
      const file=path.join(root,'webkit','visual-receipt.json'), receipt=JSON.parse(fs.readFileSync(file,'utf8'));
      receipt[key]=value; fs.writeFileSync(file,JSON.stringify(receipt)); assert.throws(()=>verifyEvidence(options));
    });
  }
  process.stdout.write(passed+' CI evidence checks passed\n');
} finally {fs.rmSync(root,{recursive:true,force:true});}
