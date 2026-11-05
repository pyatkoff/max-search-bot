#!/usr/bin/env node
'use strict';

// Full coverage is partitioned, never sampled. Per-part browser executions are
// retained independently of the runner which assembles their canonical proof.
const fs=require('node:fs');
const path=require('node:path');
const inventory=require('./manager2_visual_inventory.js');
const reuse=require('./manager2_visual_reuse.js');
const caseParts = (() => {
// Only the large, per-test isolated selection spec is distributed by cases.
// Other specs, daily-cache ownership and workers=1 retain their existing path.
const cp = require('node:child_process');
const SELECTION_SPEC = 'tests/visual/manager-tour-selection.spec.js';
const MIN_CASES = 128;
// Original run 42072803983: non-selection WebKit parts took about 435/593/523s.
// Equal quarters add work to the already slowest part. Keep its whole specs,
// and send 20/0/10/70 percent of selection identities to residual capacity.
// This fixed cycle changes ownership only; it never removes a case or runner.
const CASE_PART_CYCLE = Object.freeze([0, 3, 3, 2, 3, 0, 3, 3, 3, 3]);
function requireValue(value, message) { if (!value) throw new Error(message); }
const escape = text => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

function selection({browser, part, partCount, selectedSpecs, wholeSpecs, expected}) {
  requireValue(['chromium', 'webkit'].includes(browser), 'Unknown partition browser');
  requireValue(partCount === 4 && Number.isInteger(part) && part >= 0 && part < partCount,
    'Expected one of the existing four partitions');
  requireValue(Array.isArray(selectedSpecs) && new Set(selectedSpecs).size === selectedSpecs.length &&
    Array.isArray(wholeSpecs) && new Set(wholeSpecs).size === wholeSpecs.length,
    'Invalid spec selection');
  requireValue(Array.isArray(expected), 'Original native listing is required');
  const keys = new Set();
  for (const row of expected) {
    requireValue(row && typeof row.spec === 'string' && row.project === browser &&
      Array.isArray(row.title) && row.title.length && row.title.every(title => typeof title === 'string' && title.length > 0) &&
      row.key === JSON.stringify([browser, browser, row.spec, row.title]) && !keys.has(row.key),
      'Invalid or duplicate case in native listing');
    keys.add(row.key);
  }
  const ordered = expected.filter(row => row.spec === SELECTION_SPEC)
    .sort((left, right) => left.key < right.key ? -1 : left.key > right.key ? 1 : 0);
  const split = selectedSpecs.includes(SELECTION_SPEC) && ordered.length >= MIN_CASES;
  const owned = new Set(split ? ordered.filter((_, index) => CASE_PART_CYCLE[index % CASE_PART_CYCLE.length] === part).map(row => row.key) : []);
  const cases = expected.filter(row => selectedSpecs.includes(row.spec) &&
    (split && row.spec === SELECTION_SPEC ? owned.has(row.key) : wholeSpecs.includes(row.spec)));
  const specs = selectedSpecs.filter(spec => cases.some(row => row.spec === spec));
  if (!split) return {split: false, specs, cases, grep: null};

  // The literal full title is anchored after the file label. Reject flattening
  // collisions: a nested suite title must never select another case by accident.
  const flattened = new Set();
  for (const row of expected.filter(row => selectedSpecs.includes(row.spec))) {
    const full = path.posix.basename(row.spec) + ' ' + row.title.join(' ');
    requireValue(!/[\r\n\0]/.test(full) && !flattened.has(full), 'Ambiguous full test title');
    flattened.add(full);
  }
  const alternatives = cases.map(row => {
    const file = escape(path.posix.basename(row.spec));
    return row.spec === SELECTION_SPEC
      ? file + ' ' + escape(row.title.join(' ')) + '$'
      : file + ' ';
  });
  const grep = '^' + escape(browser) + ' (?:tests/visual/|visual/)?(?:' + [...new Set(alternatives)].join('|') + ')';
  requireValue(cases.length > 0 && Buffer.byteLength(grep) < 60000, 'Invalid or oversized case filter');
  const regex = new RegExp(grep);
  for (const row of expected.filter(row => selectedSpecs.includes(row.spec))) {
    requireValue(regex.test(browser + ' ' + path.posix.basename(row.spec) + ' ' + row.title.join(' ')) ===
      cases.some(item => item.key === row.key), 'Case filter differs from intended ownership');
  }
  return {split: true, specs, cases, grep};
}

function run({root, browser, selection: plan, collectCases, exactCases, exec = cp.execFileSync}) {
  requireValue(plan.split === true && plan.specs.length && plan.cases.length && typeof plan.grep === 'string',
    'An exact split selection is required');
  const directory = path.join(root, 'visual-reports');
  fs.mkdirSync(directory, {recursive: true});
  const args = ['playwright', 'test', ...plan.specs, '--browser=' + browser,
    '--workers=1', '--grep=' + plan.grep];
  const env = {...process.env};
  for (const key of ['PLAYWRIGHT_JSON_OUTPUT_NAME', 'PLAYWRIGHT_JSON_OUTPUT_FILE', 'PLAYWRIGHT_JSON_OUTPUT_DIR']) delete env[key];
  // Verify what the pinned native runner will actually execute, before opening
  // a browser. Selection errors are not successful empty or partial runs.
  const listed = JSON.parse(exec('npx', [...args, '--reporter=json', '--list'],
    {cwd: root, env, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024}));
  exactCases(collectCases(listed, {browser, expectedFiles: plan.specs, listing: true}), plan.cases,
    'Native filtered partition listing');
  env.PLAYWRIGHT_JSON_OUTPUT_NAME = path.join(directory, 'main.json');
  exec('npx', [...args, '--reporter=line,json'], {cwd: root, env, stdio: 'inherit'});
}

// Several existing cases intentionally reuse a screenshot basename. Each
// native part must retain its own bytes before the ordinary strict assembly.
function namespaceScreenshots({root, part, outputDirs}) {
  requireValue(Number.isInteger(part) && part >= 0 && part < 4, 'Invalid screenshot part');
  requireValue(Array.isArray(outputDirs) && outputDirs.length && new Set(outputDirs).size === outputDirs.length,
    'Invalid screenshot directories');
  // Keep PNGs at the existing upload glob's top level; encode nested names
  // injectively rather than losing them or changing workflow collection rules.
  const namespace = 'case-part-' + part + '--', moves = [];
  for (const directory of outputDirs) {
    requireValue(typeof directory === 'string' && /^[a-z0-9-]+$/.test(directory), 'Invalid screenshot directory');
    if (directory === 'report-attachment-artifacts') continue;
    const top = path.join(root, directory);
    if (!fs.lstatSync(top, {throwIfNoEntry: false})) continue;
    const visit = current => {
      const stat = fs.lstatSync(current);
      requireValue(stat.isDirectory() && !stat.isSymbolicLink(), 'Unsafe screenshot directory');
      for (const entry of fs.readdirSync(current, {withFileTypes: true})) {
        requireValue(!entry.isSymbolicLink(), 'Screenshot symlinks are forbidden');
        requireValue(current !== top || !/^case-part-[0-9]+(?:--|$)/.test(entry.name), 'Screenshot namespace already exists');
        const source = path.join(current, entry.name);
        if (entry.isDirectory()) visit(source);
        else if (entry.isFile() && entry.name.endsWith('.png')) {
          const target = path.join(top, namespace + encodeURIComponent(path.relative(top, source).split(path.sep).join('/')));
          requireValue(!fs.existsSync(target), 'Screenshot namespace collision');
          moves.push({source, target});
        }
      }
    };
    visit(top);
  }
  // Complete validation before moving any file; a failed operation is not retried.
  for (const {source, target} of moves) {
    fs.mkdirSync(path.dirname(target), {recursive: true});
    fs.renameSync(source, target);
  }
  return moves.length;
}
function assertScreenshotNamespace(files, part) {
  requireValue(Array.isArray(files) && Number.isInteger(part) && part >= 0 && part < 4,
    'Invalid screenshot ownership');
  for (const file of files) {
    requireValue(typeof file?.path === 'string', 'Invalid screenshot path');
    if (file.path.startsWith('report-attachment-artifacts/')) continue;
    const pieces = file.path.split('/');
    requireValue(pieces.length === 2 && pieces[1].startsWith('case-part-' + part + '--'),
      'Screenshot belongs to another or unpartitioned namespace');
  }
}

return {SELECTION_SPEC, MIN_CASES, selection, run, namespaceScreenshots, assertScreenshotNamespace};
})();
const PROTOCOL='manager2-partition-v1';
const read=file=>JSON.parse(fs.readFileSync(file,'utf8'));
const save=(file,value)=>{fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,JSON.stringify(value,null,2)+'\n');};
const same=(a,b)=>JSON.stringify(a)===JSON.stringify(b);
const check=(condition,message)=>{if(!condition)throw new Error(message);};
const digest=value=>reuse.hash(JSON.stringify(value));
function nativeStats(report,cases) {
  const stats=report.stats;
  check(stats&&Number.isFinite(Date.parse(stats.startTime))&&Number.isFinite(stats.duration)&&stats.duration>=0,'Invalid native partition report timing');
  check(stats.expected===cases.length&&stats.skipped===0&&stats.unexpected===0&&stats.flaky===0,'Native partition statistics differ from successful case union');
}
function partSelection(browser,part,plan,expected) {
  return caseParts.selection({browser,part,partCount:inventory.PART_COUNT,
    selectedSpecs:reuse.selectedSpecs(browser,plan),
    wholeSpecs:inventory.partitionSpecs(browser,part),expected});
}
function partSpecs(browser,part,plan,expected=null) {
  if(expected)return partSelection(browser,part,plan,expected).specs;
  return inventory.partitionSpecs(browser,part).filter(spec=>reuse.selectedSpecs(browser,plan).includes(spec));
}
function reportCases(root,browser,part,plan) {
  const expected=reuse.reports(root,browser,true), allocation=partSelection(browser,part,plan,expected), selected=allocation.specs;
  const mainFile=path.join(root,'visual-reports','main.json');
  let main=[];
  if(selected.length) {
    const report=read(mainFile);main=inventory.collectCases(report,{browser,expectedFiles:selected});nativeStats(report,main);
  }
  else check(!fs.existsSync(mainFile),'Empty partition cannot supply an execution report');
  const cacheFile=path.join(root,'visual-reports','cache.json');
  const cache=part===0?inventory.collectCases(read(cacheFile),{browser,expectedFiles:[inventory.allSpecs(browser)[0]]}):[];
  if(part===0)nativeStats(read(cacheFile),cache);
  if(part!==0)check(!fs.existsSync(cacheFile),'Daily cache may execute only in partition zero');
  const fresh=[...cache,...main];
  reuse.exactCases(fresh,[...allocation.cases,...expected.filter(row=>part===0&&row.spec===inventory.allSpecs(browser)[0])],'Partition execution');
  return {expected,fresh};
}
function envelope({root,browser,part,plan,p,artifacts}) {
  inventory.partitionSpecs(browser,part);
  check(['full','affected'].includes(plan.mode)&&inventory.validManifest(plan.inputs),'Invalid partition plan/inputs');
  require('./browser_qa_environment.js').assertSourceExecution(p.execution,plan.inputs);
  const {expected,fresh}=reportCases(root,browser,part,plan);
  if(partSelection(browser,part,plan,expected).split)caseParts.assertScreenshotNamespace(artifacts,part);
  for(const name of ['cache','main']) {
    const file=path.join(root,'visual-reports',name+'.json');
    if(fs.existsSync(file))reuse.validatePNGAttachments(read(file),artifacts);
  }
  const captureCount=browser==='chromium'&&part===0?34:0;
  check(artifacts.filter(file=>file.path.startsWith('visual-artifacts/')).length===captureCount,'Partition capture inventory is incomplete');
  return {schema:inventory.SCHEMA,protocol:PROTOCOL,...p,browser,part,partCount:inventory.PART_COUNT,
    executionRole:'browser-partition',mode:plan.mode,inputs:plan.inputs,changedSpecs:plan.changedSpecs,donor:plan.donor,
    toolchain:inventory.TOOLCHAIN,specs:partSpecs(browser,part,plan,expected),captureCount,completed:true,
    expectedCases:expected,expectedDigest:digest(expected),freshCases:fresh,freshDigest:digest(fresh),files:artifacts};
}
function recordPart({root,browser,part,plan,p,sourceRoot=root}) {
  reuse.validatePlan(plan,p,sourceRoot);
  return envelope({root,browser,part,plan,p,artifacts:reuse.files(root)});
}
function receiptPlan(receipt) {
  return {schema:inventory.SCHEMA,mode:receipt.mode,inputs:receipt.inputs,changedSpecs:receipt.changedSpecs,donor:receipt.donor};
}
function origin(receipt) {
  return Object.fromEntries(['testedSha','headSha','runId','runAttempt','repository','prNumber','workflow','execution'].map(key=>[key,receipt[key]]));
}
function validatePart({root,browser,part,p,canonicalFiles}) {
  const receipt=read(path.join(root,'partition-receipt.json'));
  check(receipt.protocol===PROTOCOL&&receipt.part===part&&receipt.partCount===inventory.PART_COUNT&&receipt.browser===browser,'Wrong partition identity or protocol');
  for(const [key,value]of Object.entries(p))if(!['execution','runAttempt'].includes(key))check(same(receipt[key],value),'Partition provenance mismatch: '+key);
  check(/^[1-9][0-9]*$/.test(String(receipt.runAttempt))&&BigInt(receipt.runAttempt)<=BigInt(p.runAttempt),'Partition belongs to a malformed or future run attempt');
  check(receipt.execution&&typeof receipt.execution.node==='string'&&typeof receipt.execution.imageVersion==='string','Missing partition browser runner identity');
  let artifacts;
  if(canonicalFiles) {
    check(Array.isArray(receipt.files),'Missing partition file ownership');
    const actual=new Map(canonicalFiles.map(file=>[file.path,file]));
    artifacts=receipt.files.map(file=>{check(same(actual.get(file.path),file),'Partition PNG missing or changed: '+file.path);return file;});
  } else artifacts=reuse.files(root);
  const rebuilt=envelope({root,browser,part,plan:receiptPlan(receipt),p:origin(receipt),artifacts});
  check(same(receipt,rebuilt),'Partition execution, source or artifact envelope is invalid');
  return receipt;
}
function ownedFiles(parts) {
  const owned=new Map();
  for(const part of parts)for(const file of part.files) {
    check(file&&typeof file.path==='string'&&reuse.OUTPUT_DIRS.some(directory=>file.path.startsWith(directory+'/'))&&
      !file.path.includes('\\')&&!file.path.split('/').some(piece=>!piece||piece==='.'||piece==='..')&&file.path.endsWith('.png')&&
      Number.isSafeInteger(file.size)&&file.size>0&&/^[a-f0-9]{64}$/.test(file.sha256),'Invalid partition PNG path/metadata');
    const previous=owned.get(file.path);
    if(previous) {
      // Only immutable content-addressed report attachment bytes may be shared.
      // Ordinary screenshots have one spec owner even when bytes happen to match.
      check(/^report-attachment-artifacts\/[a-f0-9]{64}\.png$/.test(file.path)&&same(previous,file)&&file.path==='report-attachment-artifacts/'+file.sha256+'.png','Screenshot ownership collision: '+file.path);
    } else owned.set(file.path,file);
  }
  return [...owned.values()].sort((a,b)=>a.path.localeCompare(b.path));
}
function validateParts(parts) {
  check(parts.length===inventory.PART_COUNT,'Missing partition');
  const first=parts[0];
  for(const part of parts) {
    for(const field of ['mode','inputs','changedSpecs','donor','expectedCases','expectedDigest'])check(same(part[field],first[field]),'Partitions disagree on '+field);
  }
  const fresh=parts.flatMap(part=>part.freshCases),plan=receiptPlan(parts[0]);
  reuse.exactCases(fresh,first.expectedCases.filter(row=>reuse.selectedSpecs(first.browser,plan).includes(row.spec)||row.spec===inventory.allSpecs(first.browser)[0]),'All partitions');
  return ownedFiles(parts);
}
function mergedMain(reports) {
  const first=reports[0];check(first,'No main execution reports');
  const counts={expected:0,skipped:0,unexpected:0,flaky:0};let start=Infinity,end=-Infinity;
  for(const report of reports) {
    const stats=report.stats,time=Date.parse(stats?.startTime);
    check(stats&&Number.isFinite(time)&&Number.isFinite(stats.duration)&&stats.duration>=0,'Invalid native partition report timing');
    for(const key of Object.keys(counts)) {
      check(Number.isSafeInteger(stats[key])&&stats[key]>=0,'Invalid native partition report counts');counts[key]+=stats[key];
    }
    start=Math.min(start,time);end=Math.max(end,time+stats.duration);
  }
  return {...first,suites:reports.flatMap(report=>report.suites),errors:reports.flatMap(report=>report.errors||[]),
    stats:{startTime:new Date(start).toISOString(),duration:end-start,...counts}};
}
function addProof(receipt,parts) {
  return {...receipt,executionRole:'assembly',partitionProtocol:PROTOCOL,partitionCount:inventory.PART_COUNT,partitionDigest:digest(parts)};
}
function donorExecution(parts) {
  if(parts[0].mode==='affected')check(parts.every(part=>same(part.execution,parts[0].execution)),'Affected partitions have different browser executor environments');
  return parts[0].execution;
}
function assertPartsResult(result) {check(result==='success','Every partition job must succeed; missing/skipped/cancelled is not evidence');}
function parsePart(value) {
  check(typeof value==='string'&&/^(0|[1-9][0-9]*)$/.test(value)&&Number.isSafeInteger(Number(value))&&Number(value)<inventory.PART_COUNT,
    'Explicit partition 0 through '+(inventory.PART_COUNT-1)+' required');
  return Number(value);
}
function assemble({sourceRoot,partsRoot,destination,browser,p,partsResult}) {
  assertPartsResult(partsResult);
  inventory.assertCleanSource(sourceRoot,p.testedSha);
  check(!fs.existsSync(destination),'Assembly destination must be fresh');
  const browserRoot=path.join(partsRoot,browser);
  check(same(fs.readdirSync(browserRoot).sort(),Array.from({length:inventory.PART_COUNT},(_,part)=>'part-'+part)),'Unexpected or missing original partition directory');
  const roots=Array.from({length:inventory.PART_COUNT},(_,part)=>path.join(partsRoot,browser,'part-'+part));
  for(const root of roots)check(fs.lstatSync(root).isDirectory()&&!fs.lstatSync(root).isSymbolicLink(),'Partition root must be an original directory');
  const parts=roots.map((root,part)=>validatePart({root,browser,part,p}));
  const artifacts=validateParts(parts), plan=receiptPlan(parts[0]);
  check(same(plan.inputs,inventory.sourceManifest(sourceRoot,p.testedSha)),'Partition inputs differ from assembly source');
  // Validate every ownership conflict and provenance field before writing any
  // canonical output. This prevents cross-job artifact overwrite by extraction.
  fs.mkdirSync(destination,{recursive:true});
  for(const file of artifacts) {
    const owner=parts.findIndex(part=>part.files.some(entry=>entry.path===file.path));
    const target=path.join(destination,file.path);fs.mkdirSync(path.dirname(target),{recursive:true});
    fs.copyFileSync(path.join(roots[owner],file.path),target,fs.constants.COPYFILE_EXCL);
  }
  const mains=[];
  for(let part=0;part<parts.length;part++) {
    const proof=path.join(destination,'partition-proof','part-'+part);
    save(path.join(proof,'partition-receipt.json'),parts[part]);
    for(const name of ['expected','cache','main']) {
      const file=path.join(roots[part],'visual-reports',name+'.json');
      if(fs.existsSync(file)) {
        const report=read(file);save(path.join(proof,'visual-reports',name+'.json'),report);
        if(name==='main')mains.push(report);
      }
    }
  }
  save(path.join(destination,'visual-reports','expected.json'),read(path.join(roots[0],'visual-reports','expected.json')));
  save(path.join(destination,'visual-reports','cache.json'),read(path.join(roots[0],'visual-reports','cache.json')));
  save(path.join(destination,'visual-reports','main.json'),mergedMain(mains));
  if(plan.mode==='affected')fs.cpSync(path.join(roots[0],'donor'),path.join(destination,'donor'),{recursive:true,dereference:false,errorOnExist:true});
  const receipt=addProof(reuse.buildReceipt({root:destination,browser,p,plan,donorRoot:path.join(destination,'donor'),donorExecution:donorExecution(parts)}),parts);
  save(path.join(destination,'visual-receipt.json'),receipt);
  verifyPartitionedEvidence({root:destination,browser,p,sourceRoot});
  return receipt;
}
function verifyPartitionedEvidence({root,browser,p,sourceRoot}) {
  const current=reuse.basicReceipt(root,browser,p), receipt=current.receipt;
  check(receipt.partitionProtocol===PROTOCOL&&receipt.partitionCount===inventory.PART_COUNT&&receipt.executionRole==='assembly','Invalid partition assembly protocol');
  if(sourceRoot) {
    inventory.assertCleanSource(sourceRoot,p.testedSha);
    check(same(receipt.inputs,inventory.sourceManifest(sourceRoot,p.testedSha)),'Assembly inputs differ from checked-out source');
  } else check(inventory.validManifest(receipt.inputs),'Invalid donor partition source manifest');
  const artifacts=reuse.files(root);
  const proofRoot=path.join(root,'partition-proof');
  check(same(fs.readdirSync(proofRoot).sort(),Array.from({length:inventory.PART_COUNT},(_,part)=>'part-'+part)),'Unexpected or missing partition proof');
  const parts=Array.from({length:inventory.PART_COUNT},(_,part)=>validatePart({root:path.join(proofRoot,'part-'+part),browser,part,p,canonicalFiles:artifacts}));
  check(same(validateParts(parts),artifacts),'Partition screenshot ownership does not cover canonical files');
  check(same(parts[0].inputs,receipt.inputs),'Partition source differs from canonical source');
  check(same(parts[0].expectedCases,current.expected),'Partition listing differs from canonical listing');
  reuse.exactCases(parts.flatMap(part=>part.freshCases),current.fresh,'Canonical partition execution');
  const mainReports=parts.map((part,index)=>path.join(proofRoot,'part-'+index,'visual-reports','main.json')).filter(file=>fs.existsSync(file)).map(read);
  check(same(read(path.join(root,'visual-reports','main.json')),mergedMain(mainReports)),'Canonical report/attachments/statistics differ from original partition execution');
  check(same(read(path.join(root,'visual-reports','cache.json')),read(path.join(proofRoot,'part-0','visual-reports','cache.json'))),'Canonical cache report differs from original partition execution');
  const rebuilt=addProof(reuse.buildReceipt({root,browser,p:{...p,execution:receipt.execution},plan:receiptPlan(parts[0]),donorRoot:path.join(root,'donor'),donorExecution:donorExecution(parts)}),parts);
  check(same(receipt,rebuilt),'Assembled case, PNG or partition provenance is invalid');
  return {receipt,parts};
}
if(require.main===module) {
  try {
    const [command,browser,partOrRoot,destination]=process.argv.slice(2),root=process.cwd(),p=reuse.provenance(process.env);
    if(command==='assemble')assemble({sourceRoot:root,partsRoot:path.resolve(partOrRoot),destination:path.resolve(destination),browser,p,partsResult:process.env.QA_PARTS_RESULT});
    else {
      const part=parsePart(partOrRoot);
      const plan=reuse.validatePlan(read(path.join(root,'selection-plan.json')),p,root);
      if(command==='inventory')reuse.runPlaywright(root,browser,inventory.allSpecs(browser),{listing:true,report:'expected'});
      else if(command==='run-part') {
        const allocation=partSelection(browser,part,plan,reuse.reports(root,browser,true)),specs=allocation.specs;
        if(allocation.split)caseParts.run({root,browser,selection:allocation,collectCases:inventory.collectCases,exactCases:reuse.exactCases});
        else if(specs.length)reuse.runPlaywright(root,browser,specs,{report:'main'});
        else check(!fs.existsSync(path.join(root,'visual-reports','main.json')),'Unexpected report in empty selected partition');
      } else if(command==='record-part') {
        const names=['main',...(part===0?['cache']:[])].filter(name=>fs.existsSync(path.join(root,'visual-reports',name+'.json')));
        if(partSelection(browser,part,plan,reuse.reports(root,browser,true)).split)
          caseParts.namespaceScreenshots({root,part,outputDirs:reuse.OUTPUT_DIRS});
        reuse.compactReports(root,names);
        if(part===0&&plan.mode==='affected') {
          const baseline=path.join(root,'baseline',browser),target=path.join(root,'donor');
          check(!fs.existsSync(target),'Donor namespace must be fresh');reuse.verifiedFullDonor(baseline,browser,plan.donor,p);
          fs.cpSync(baseline,target,{recursive:true,dereference:false,errorOnExist:true});
        }
        save(path.join(root,'partition-receipt.json'),recordPart({root,browser,part,plan,p}));
      } else throw new Error('Unknown partition command');
    }
  } catch(error){process.stderr.write(error.stack+'\n');process.exitCode=1;}
}
module.exports={PROTOCOL,caseParts,partSelection,parsePart,partSpecs,recordPart,validatePart,validateParts,ownedFiles,mergedMain,assemble,verifyPartitionedEvidence,assertPartsResult};
