#!/usr/bin/env node
'use strict';

// Full coverage is partitioned, never sampled. Per-part browser executions are
// retained independently of the runner which assembles their canonical proof.
const fs=require('node:fs');
const path=require('node:path');
const inventory=require('./manager2_visual_inventory.js');
const reuse=require('./manager2_visual_reuse.js');
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
function partSpecs(browser,part,plan) {
  return inventory.partitionSpecs(browser,part).filter(spec=>reuse.selectedSpecs(browser,plan).includes(spec));
}
function reportCases(root,browser,part,plan) {
  const expected=reuse.reports(root,browser,true), selected=partSpecs(browser,part,plan);
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
  reuse.exactCases(fresh,expected.filter(row=>selected.includes(row.spec)||(part===0&&row.spec===inventory.allSpecs(browser)[0])),'Partition execution');
  return {expected,fresh};
}
function envelope({root,browser,part,plan,p,artifacts}) {
  inventory.partitionSpecs(browser,part);
  check(['full','affected'].includes(plan.mode)&&inventory.validManifest(plan.inputs),'Invalid partition plan/inputs');
  const {expected,fresh}=reportCases(root,browser,part,plan);
  for(const name of ['cache','main']) {
    const file=path.join(root,'visual-reports',name+'.json');
    if(fs.existsSync(file))reuse.validatePNGAttachments(read(file),artifacts);
  }
  const captureCount=browser==='chromium'&&part===0?34:0;
  check(artifacts.filter(file=>file.path.startsWith('visual-artifacts/')).length===captureCount,'Partition capture inventory is incomplete');
  return {schema:inventory.SCHEMA,protocol:PROTOCOL,...p,browser,part,partCount:inventory.PART_COUNT,
    executionRole:'browser-partition',mode:plan.mode,inputs:plan.inputs,changedSpecs:plan.changedSpecs,donor:plan.donor,
    toolchain:inventory.TOOLCHAIN,specs:partSpecs(browser,part,plan),captureCount,completed:true,
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
  const fresh=parts.flatMap(part=>part.freshCases),plan=receiptPlan(first);
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
      check(/^[01]$/.test(partOrRoot||''),'Explicit partition 0 or 1 required');const part=Number(partOrRoot);
      const plan=reuse.validatePlan(read(path.join(root,'selection-plan.json')),p,root);
      if(command==='inventory')reuse.runPlaywright(root,browser,inventory.allSpecs(browser),{listing:true,report:'expected'});
      else if(command==='run-part') {
        const specs=partSpecs(browser,part,plan);
        if(specs.length)reuse.runPlaywright(root,browser,specs,{report:'main'});
        else check(!fs.existsSync(path.join(root,'visual-reports','main.json')),'Unexpected report in empty selected partition');
      } else if(command==='record-part') {
        const names=['main',...(part===0?['cache']:[])].filter(name=>fs.existsSync(path.join(root,'visual-reports',name+'.json')));
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
module.exports={PROTOCOL,partSpecs,recordPart,validatePart,validateParts,ownedFiles,mergedMain,assemble,verifyPartitionedEvidence,assertPartsResult};
