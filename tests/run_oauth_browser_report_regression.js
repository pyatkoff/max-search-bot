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

// Exercise the component's real two-directory assembly, with independently
// assigned literal case keys. These are disposable reports/files, not browsers.
function partPair(browser='chromium',part=0,count=401){
  const pair=tourPair(browser);
  pair.report.config.workers=1;pair.expected.config.workers=1;
  for(let index=pair.report.suites[0].specs.length;index<count;index++){
    const spec=structuredClone(pair.report.suites[0].specs[0]);spec.id='extra-'+index;spec.title='additional component case '+index;
    pair.report.suites[0].specs.push(spec);
    const listed=structuredClone(spec);listed.tests[0].results=[];pair.expected.suites[0].specs.push(listed);
  }
  const ordered=pair.report.suites[0].specs.map(spec=>({spec,key:JSON.stringify([browser,browser,tour.SPEC,[spec.title]])}))
    .sort((left,right)=>left.key<right.key?-1:left.key>right.key?1:0);
  const selected=new Set(ordered.filter((_,index)=>index%2===part).map(row=>row.spec.title));
  pair.report.suites[0].specs=pair.report.suites[0].specs.filter(spec=>selected.has(spec.title));
  pair.report.stats.expected=selected.size;
  pair.filtered=structuredClone(pair.expected);
  pair.filtered.suites[0].specs=pair.filtered.suites[0].specs.filter(spec=>selected.has(spec.title));
  pair.part=part;
  return pair;
}
function partCheck(pair){return tour.verifyPartReport(pair.report,pair.expected,pair.filtered,pair.browser,pair.part);}
function partReject(change){const pair=partPair();change(pair);assert.throws(()=>partCheck(pair));assertions++;}
for(const browser of ['chromium','webkit'])for(const count of [401,402]){
  const parts=[0,1].map(part=>partCheck(partPair(browser,part,count)));
  assert.deepEqual(parts.map(part=>part.cases.length),[Math.ceil(count/2),Math.floor(count/2)]);
  const keys=parts.flatMap(part=>part.cases.map(row=>row.key));
  assert.equal(new Set(keys).size,count);assert.deepEqual(keys.sort(),parts[0].listed.map(row=>row.key));assertions++;
}
for(const value of ['report','expected','filtered']){
  partReject(pair=>{pair[value].config.forbidOnly=false;});
  partReject(pair=>{pair[value].config.workers=2;});
}
partReject(pair=>{pair.expected=structuredClone(pair.filtered);});
partReject(pair=>{pair.filtered.suites[0].specs.pop();});
partReject(pair=>{pair.filtered.suites[0].specs.push(structuredClone(pair.filtered.suites[0].specs[0]));});
partReject(pair=>{pair.filtered=structuredClone(pair.expected);});
partReject(pair=>{pair.report.suites[0].specs.pop();pair.report.stats.expected--;});
partReject(pair=>{pair.report.suites[0].specs[0].file='tests/visual/manager-ai-draft.spec.js';});
for(const status of ['failed','skipped','timedOut','interrupted'])partReject(pair=>{pair.report.suites[0].specs[0].tests[0].results[0].status=status;});
partReject(pair=>{pair.report.suites[0].specs[0].tests[0].status='flaky';});
partReject(pair=>{pair.report.suites[0].specs[0].tests[0].results[0].retry=1;});
partReject(pair=>{pair.filtered.suites[0].specs[0].tests[0].results=[{status:'passed',retry:0}];});
for(const part of ['0','1']){assert.equal(tour.parsePart(part),Number(part));assertions++;}
for(const part of ['2','-1','01','1.0',1,undefined]){assert.throws(()=>tour.parsePart(part));assertions++;}

const environment=require('../tools/browser_qa_environment');
const pin=environment.pin();
const nativeExecution={...pin.runtime,containerImage:pin.image,recipeSha:pin.recipeSha,imageOS:'ubuntu24',imageVersion:'native-fixture'};
const assemblyExecution={node:process.version,platform:process.platform,arch:process.arch,imageOS:'ubuntu24',imageVersion:'assembler-fixture'};
const names={report:'tour-selection-browser.json',expected:'tour-selection-expected.json',filtered:'tour-selection-filtered.json',receipt:'tour-selection-part-receipt.json'};
function writeJSON(root,name,value,spaces=2){fs.mkdirSync(root,{recursive:true});fs.writeFileSync(path.join(root,name),JSON.stringify(value,null,spaces)+'\n');}
function readJSON(root,name){return JSON.parse(fs.readFileSync(path.join(root,name),'utf8'));}
function png(root,name,width,byte){
  const bytes=Buffer.alloc(10032,byte);Buffer.from('89504e470d0a1a0a','hex').copy(bytes);
  bytes.write('IHDR',12);bytes.writeUInt32BE(width,16);bytes.writeUInt32BE(900,20);
  fs.mkdirSync(path.dirname(path.join(root,name)),{recursive:true});fs.writeFileSync(path.join(root,name),bytes);
}
function withEvidence(action,{browser='chromium',attempts=['1','1'],assemblyAttempt='1'}={}){
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'tour-component-evidence-'));
  try{
    const sourceRoot=path.join(root,'source');fs.mkdirSync(sourceRoot);
    const git=args=>{
      const result=spawnSync('git',args,{cwd:sourceRoot,encoding:'utf8'});
      assert.equal(result.status,0,result.stderr);return result.stdout.trim();
    };
    git(['init','-q']);fs.writeFileSync(path.join(sourceRoot,'source.txt'),'Disposable component evidence source\n');git(['add','source.txt']);
    git(['-c','core.hooksPath=/dev/null','-c','user.name=Browser fixture','-c','user.email=fixture@example.invalid','commit','-qm','Disposable evidence fixture']);
    const testedSha=git(['rev-parse','HEAD']),sourceTree=git(['rev-parse','HEAD^{tree}']);
    const p={testedSha,headSha:testedSha,runId:'12345',runAttempt:assemblyAttempt,repository:'pyatkoff/max-search-bot',prNumber:'1390',workflow:'.github/workflows/workspace-v2-visual-qa.yml',execution:assemblyExecution};
    const partsRoot=path.join(root,'parts'),destination=path.join(root,'assembled'),roots=[],receipts=[];
    for(const part of [0,1]){
      const partRoot=path.join(partsRoot,browser,'part-'+part),pair=partPair(browser,part);
      fs.mkdirSync(partRoot,{recursive:true});roots.push(partRoot);
      if(part===1){
        // Original --list bytes may differ while their canonical identities agree.
        pair.expected.suites[0].specs.reverse();pair.expected.config.rootDir='/different/runner/tests/visual';
        pair.expected.config.metadata={runner:'second'};pair.expected.stats.startTime='2026-11-06T20:00:10Z';
        pair.filtered.suites[0].specs.reverse();pair.report.stats.startTime='2026-11-06T20:00:11Z';
      }
      for(const key of ['report','expected','filtered'])writeJSON(partRoot,names[key],pair[key],part?4:2);
      for(const [index,name] of tour.capturePaths(browser).entries()){
        if(pair.report.suites[0].specs.some(spec=>spec.title===tour.mandatoryTitles[index]))png(partRoot,name,Number(/-(\d+)\.png$/.exec(name)[1]),part+1);
      }
      png(partRoot,'manager2-artifacts/tour-selection-photo-correction-'+browser+'-390.png',390,part+3);
      if(part===0)png(partRoot,'manager2-artifacts/onetouch-party-three-'+browser+'-430.png',430,8);
      const receipt=tour.recordPart({root:partRoot,sourceRoot,browser,part,p:{...p,runAttempt:attempts[part],execution:nativeExecution}});
      writeJSON(partRoot,names.receipt,receipt);receipts.push(receipt);
    }
    const fixture={root,sourceRoot,sourceTree,partsRoot,destination,browser,p,roots,receipts};
    fixture.assemble=()=>tour.assemble({...fixture,partsResult:'success'});
    fixture.verify=()=>tour.verifyAssembledEvidence({root:destination,browser,p,sourceRoot});
    action(fixture);
  }finally{fs.rmSync(root,{recursive:true,force:true});}
}
function rejectsAssembly(change,options){
  withEvidence(fixture=>{change(fixture);assert.throws(fixture.assemble);assert(!fs.existsSync(fixture.destination),'bad original evidence must fail before canonical output');},options);assertions++;
}
function changeReceipt(fixture,change,part=0){const value=readJSON(fixture.roots[part],names.receipt);change(value);writeJSON(fixture.roots[part],names.receipt,value);}
function changeReport(fixture,name,change,part=0){const value=readJSON(fixture.roots[part],names[name]);change(value);writeJSON(fixture.roots[part],names[name],value);}
for(const browser of ['chromium','webkit'])withEvidence(fixture=>{
  const receipt=fixture.assemble();assert.equal(receipt.cases.length,401);assert.equal(receipt.files.length,16);
  assert.deepEqual(fixture.receipts.map(part=>part.cases.length),[201,200]);
  assert.notEqual(fixture.receipts[0].expectedDigest,fixture.receipts[1].expectedDigest);
  assert.deepEqual(fixture.receipts[0].expectedCases,fixture.receipts[1].expectedCases);
  assert.equal(receipt.profile,'tour-selection');assert.equal(receipt.partitionCount,2);
  const ordinary=[0,1].map(part=>'manager2-artifacts/case-part-'+part+'--tour-selection-photo-correction-'+browser+'-390.png');
  assert.notDeepEqual(...ordinary.map(name=>fs.readFileSync(path.join(fixture.destination,name))));
  for(const part of [0,1])for(const name of Object.values(names))assert.deepEqual(fs.readFileSync(path.join(fixture.roots[part],name)),fs.readFileSync(path.join(fixture.destination,'partition-proof','part-'+part,name)));
  assert(!receipt.partitionFiles.some(file=>file.path.includes('onetouch-party-three')));
  assert(!fs.existsSync(path.join(fixture.destination,'manager2-artifacts','case-part-0--onetouch-party-three-'+browser+'-430.png')));
  assert.deepEqual(fixture.verify().receipt,receipt);assertions++;
},{browser});
withEvidence(fixture=>{
  const receipt=fixture.assemble();assert.equal(receipt.runAttempt,'2');
  assert.deepEqual(fixture.verify().parts.map(part=>part.runAttempt),['1','2']);assertions++;
},{attempts:['1','2'],assemblyAttempt:'2'});

rejectsAssembly(fixture=>{fs.rmSync(fixture.roots[1],{recursive:true});});
rejectsAssembly(fixture=>{fs.mkdirSync(path.join(fixture.partsRoot,fixture.browser,'part-2'));});
rejectsAssembly(fixture=>changeReceipt(fixture,receipt=>{receipt.part=0;},1));
for(const key of ['testedSha','headSha','sourceTree'])rejectsAssembly(fixture=>changeReceipt(fixture,receipt=>{receipt[key]='b'.repeat(40);}));
for(const [key,value] of [['runId','54321'],['repository','other/repo'],['workflow','other.yml'],['prNumber','1391'],['browser','webkit'],['partCount',4],['profile','full'],['completed',false]])rejectsAssembly(fixture=>changeReceipt(fixture,receipt=>{receipt[key]=value;}));
for(const runAttempt of ['0','01','-1','2',2])rejectsAssembly(fixture=>changeReceipt(fixture,receipt=>{receipt.runAttempt=runAttempt;}));
rejectsAssembly(fixture=>changeReceipt(fixture,receipt=>{receipt.execution.imageVersion='different native runner';},1));
rejectsAssembly(fixture=>changeReceipt(fixture,receipt=>{receipt.execution.containerImage='unapproved';}));
rejectsAssembly(fixture=>changeReceipt(fixture,receipt=>{receipt.reportDigest='0'.repeat(64);}));
rejectsAssembly(fixture=>changeReceipt(fixture,receipt=>{receipt.expectedCases.pop();}));
rejectsAssembly(fixture=>changeReceipt(fixture,receipt=>{receipt.files[0].path='../escape.png';}));
rejectsAssembly(fixture=>changeReport(fixture,'report',report=>{report.stats.duration++;}));
rejectsAssembly(fixture=>changeReport(fixture,'filtered',report=>{report.suites[0].specs.pop();}));
rejectsAssembly(fixture=>changeReport(fixture,'expected',report=>{report.suites[0].specs.pop();},1));
rejectsAssembly(fixture=>{fs.writeFileSync(path.join(fixture.roots[0],names.receipt),'{invalid');});
rejectsAssembly(fixture=>{fs.unlinkSync(path.join(fixture.roots[0],fixture.receipts[0].captures[0].path));});
rejectsAssembly(fixture=>{
  const name=fixture.receipts[0].captures[0].path,file=path.join(fixture.roots[0],name),bytes=fs.readFileSync(file);bytes[bytes.length-1]^=1;fs.writeFileSync(file,bytes);
});
rejectsAssembly(fixture=>{
  const file=path.join(fixture.roots[0],fixture.receipts[0].captures[0].path),bytes=fs.readFileSync(file);bytes.writeUInt32BE(123,16);fs.writeFileSync(file,bytes);
});
rejectsAssembly(fixture=>{
  const capture=fixture.receipts[0].captures[0];fs.copyFileSync(path.join(fixture.roots[0],capture.path),path.join(fixture.roots[1],capture.path.replace('case-part-0--','case-part-1--')));
});
rejectsAssembly(fixture=>{
  const file=fixture.receipts[0].files[0].path;fs.renameSync(path.join(fixture.roots[0],file),path.join(fixture.roots[0],file.replace('case-part-0--','case-part-1--')));
});
rejectsAssembly(fixture=>png(fixture.roots[0],'manager2-artifacts/case-part-0--tour-selection-..%2Fescape.png',390,4));
rejectsAssembly(fixture=>{
  const moved=path.join(fixture.root,'original-part');fs.renameSync(fixture.roots[0],moved);fs.symlinkSync(moved,fixture.roots[0]);
});
rejectsAssembly(fixture=>{
  const file=path.join(fixture.roots[0],names.report),moved=path.join(fixture.root,'original-report.json');fs.renameSync(file,moved);fs.symlinkSync(moved,file);
});
rejectsAssembly(fixture=>{
  const file=path.join(fixture.roots[0],'manager2-artifacts'),moved=path.join(fixture.root,'original-pngs');fs.renameSync(file,moved);fs.symlinkSync(moved,file);
});
withEvidence(fixture=>{
  for(const result of ['failure','cancelled','skipped',undefined])assert.throws(()=>tour.assemble({...fixture,partsResult:result}));
  assert(!fs.existsSync(fixture.destination));assertions++;
});
withEvidence(fixture=>{
  fixture.assemble();const receipt=readJSON(fixture.destination,'tour-selection-receipt.json'),capture=receipt.files[0];
  // Even a forged top-level hash cannot detach a canonical alias from its
  // original part/case owner, whose receipt and bytes remain unchanged.
  const file=path.join(fixture.destination,capture.path),bytes=fs.readFileSync(file);bytes[bytes.length-1]^=1;fs.writeFileSync(file,bytes);
  capture.sha256=require('../tools/manager2_visual_reuse').hash(bytes);writeJSON(fixture.destination,'tour-selection-receipt.json',receipt);
  assert.throws(fixture.verify,/exact part owner/);assertions++;
});
process.stdout.write(`Scoped browser report regression (OAuth and selection): ${assertions} assertions passed\n`);
