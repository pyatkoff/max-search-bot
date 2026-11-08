#!/usr/bin/env node
'use strict';

const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const inventory=require('./manager2_visual_inventory');
const reuse=require('./manager2_visual_reuse');
const shards=require('./manager2_visual_shards');
const environment=require('./browser_qa_environment');
const SPEC='tests/visual/manager-tour-selection.spec.js';
const WIDTHS=[390,430,768,1440];
const PART_COUNT=2;
const PROTOCOL='tour-selection-partition-v1';
const REPORT='tour-selection-browser.json',EXPECTED='tour-selection-expected.json',FILTERED='tour-selection-filtered.json';
const PART_RECEIPT='tour-selection-part-receipt.json',RECEIPT='tour-selection-receipt.json';
const ORIGIN=['testedSha','headSha','runId','runAttempt','repository','prNumber','workflow','execution'];
// These existing contracts may not disappear behind an empty/partial --list.
// Additional cases are welcome; every listed case must execute exactly once.
const mandatoryTitles=[
  ...['selection editor preserves order conditions and reply at ',
    'selection customer preview and explicit send preserve composer at ',
    'selection shared link explicit publish and revoke preserve composer at ',
    'public selection has exact terms and no private metadata at '].flatMap(prefix=>WIDTHS.map(width=>prefix+width)),
  'selection conflict keeps local conditions until explicit choice',
  'lost save response reconciles without another mutation',
  'known missing receipt retries only the identical draft command',
  'invalid children input survives close and blocks stale autosave',
  'late original commit and colleague edit cannot silently replace retry conditions',
  'readonly selection cannot add or save offers',
  'selection lost creation response checks saved result without another send',
  'selection unknown creation prevents another send after reopening',
  'selection missing photos cannot become a sendable quote',
  'selection photo failure keeps the preview unsent',
  'pending selection photos cannot send or overwrite another clients editor',
  'selection lost publish response reads status without another publication',
  'selection publish becomes available when held preview photos finish',
];
const check=(condition,message)=>{if(!condition)throw new Error(message);};
const same=(left,right)=>JSON.stringify(left)===JSON.stringify(right);
const digest=value=>reuse.hash(JSON.stringify(value));
function verifyConfiguration(value,browser){
  check(['chromium','webkit'].includes(browser),'Expected Chromium or WebKit');
  check(value?.config?.projects?.length===1&&value.config.projects[0].name===browser&&value.config.forbidOnly===true,'Expected one pinned browser project with forbid-only');
  check(Array.isArray(value.errors)&&value.errors.length===0,'Missing or failed browser worker inventory');
}
function verifyResults(report,listing){
  function visit(suite){
    for(const spec of suite.specs||[])for(const test of spec.tests||[])check(listing?test.results.length===0:test.results.length===1&&test.results[0].retry===0,listing?'Expected fresh --list inventory':'Selection cases must execute without retries');
    for(const child of suite.suites||[])visit(child);
  }
  report.suites.forEach(visit);
}
function listedCases(expected,browser,mandatory=true){
  verifyConfiguration(expected,browser);
  const listed=inventory.collectCases(expected,{browser,expectedFiles:[SPEC],listing:true});
  verifyResults(expected,true);
  const titles=new Set(listed.map(row=>row.title.join(' › ')));
  if(mandatory)for(const title of mandatoryTitles)check(titles.has(title),'Missing mandatory selection contract: '+title);
  return listed;
}
function executedCases(report,expected,browser,label){
  verifyConfiguration(report,browser);
  const actual=inventory.collectCases(report,{browser,expectedFiles:[SPEC]});
  reuse.exactCases(actual,expected,label);
  const stats=report.stats;
  check(stats&&stats.expected===actual.length&&stats.skipped===0&&stats.unexpected===0&&stats.flaky===0,'Incomplete selection execution statistics');
  check(Number.isFinite(Date.parse(stats.startTime))&&Number.isFinite(stats.duration)&&stats.duration>=0,'Missing native execution timing');
  verifyResults(report,false);
  return actual;
}
function verifyReport(report,expected,browser){
  return executedCases(report,listedCases(expected,browser),browser,'Complete tour-selection execution');
}
function allocation(browser,part,expected){
  return shards.caseParts.selection({browser,part,partCount:PART_COUNT,profile:'tour-selection',selectedSpecs:[SPEC],wholeSpecs:[],expected});
}
function verifyPartReport(report,expected,filtered,browser,part){
  const listed=listedCases(expected,browser),selected=allocation(browser,part,listed);
  for(const value of [report,expected,filtered])check(value?.config?.workers===1,'Component parts must retain one native worker');
  reuse.exactCases(listedCases(filtered,browser,false),selected.cases,'Native filtered component listing');
  const cases=executedCases(report,selected.cases,browser,'Exact component part execution');
  return {listed,cases};
}
function capturePaths(browser){return ['tour-selection-','tour-selection-preview-','tour-selection-share-','tour-selection-public-'].flatMap(prefix=>WIDTHS.map(width=>'manager2-artifacts/'+prefix+browser+'-'+width+'.png'));}
function verifyCaptures(root,browser){
  const files=new Map(reuse.files(root).map(file=>[file.path,file]));
  return capturePaths(browser).map(name=>{
    const file=files.get(name),width=Number(/-(\d+)\.png$/.exec(name)[1]);
    check(file&&file.size>10000,'Missing selection capture: '+name);
    const bytes=fs.readFileSync(path.join(root,name));
    check(bytes.subarray(12,16).toString()==='IHDR'&&bytes.readUInt32BE(16)===width&&bytes.readUInt32BE(20)>=900,'Wrong selection capture viewport: '+name);
    return file;
  });
}
function parsePart(value){
  check(typeof value==='string'&&/^[01]$/.test(value),'Explicit component part 0 or 1 required');
  return Number(value);
}
function directory(root){
  const stat=fs.lstatSync(root);
  check(stat.isDirectory()&&!stat.isSymbolicLink(),'Evidence directory must not be a symlink');
}
function original(root,name){
  directory(root);
  const file=path.join(root,name),stat=fs.lstatSync(file);
  check(stat.isFile()&&!stat.isSymbolicLink(),'Original JSON must be a regular file: '+name);
  const bytes=fs.readFileSync(file);
  return {value:JSON.parse(bytes.toString('utf8')),digest:reuse.hash(bytes)};
}
function save(root,name,value){
  fs.mkdirSync(root,{recursive:true});
  fs.writeFileSync(path.join(root,name),JSON.stringify(value,null,2)+'\n',{flag:'wx'});
}
function treeAt(root,sha){
  return execFileSync('git',['rev-parse',sha+'^{tree}'],{cwd:root,encoding:'utf8'}).trim();
}
function origin(value){return Object.fromEntries(ORIGIN.map(key=>[key,value[key]]));}
function assertOrigin(value){
  check(value&&['testedSha','headSha'].every(key=>/^[a-f0-9]{40}$/.test(value[key]||'')),'Exact component source/head required');
  check(['runId','runAttempt'].every(key=>typeof value[key]==='string'&&/^[1-9][0-9]*$/.test(value[key])),'Exact component run/attempt required');
  check(value.repository==='pyatkoff/max-search-bot'&&value.workflow==='.github/workflows/workspace-v2-visual-qa.yml','Wrong component repository/workflow');
  check(typeof value.prNumber==='string'&&(value.prNumber===''||/^[1-9][0-9]*$/.test(value.prNumber)),'Invalid component PR');
  check(value.execution&&typeof value.execution.node==='string'&&typeof value.execution.imageVersion==='string','Missing original execution identity');
}
function matchOrigin(receipt,p){
  assertOrigin(receipt);assertOrigin(p);
  for(const key of ORIGIN)if(!['execution','runAttempt'].includes(key))check(same(receipt[key],p[key]),'Component provenance mismatch: '+key);
  // A failed-only retry retains successful siblings with their original attempt.
  // It may not import another run, a future attempt or rename the old execution.
  check(BigInt(receipt.runAttempt)<=BigInt(p.runAttempt),'Component belongs to a future run attempt');
}
function evidenceFiles(root){
  directory(root);
  for(const name of reuse.OUTPUT_DIRS){
    const stat=fs.lstatSync(path.join(root,name),{throwIfNoEntry:false});
    if(stat)check(stat.isDirectory()&&!stat.isSymbolicLink(),'Unsafe screenshot directory');
  }
  return reuse.files(root);
}
function logicalPath(file,part){
  const prefix='manager2-artifacts/case-part-'+part+'--';
  check(typeof file?.path==='string'&&file.path.startsWith(prefix),'Wrong component screenshot namespace');
  const encoded=file.path.slice(prefix.length),name=decodeURIComponent(encoded);
  check(encoded===encodeURIComponent(name)&&/^tour-selection-[^/\\\x00-\x1f]+\.png$/.test(name),'Unsafe component screenshot path');
  return 'manager2-artifacts/'+name;
}
function componentFiles(root,part,canonicalFiles){
  const prefix='manager2-artifacts/case-part-'+part+'--tour-selection-';
  let files;
  if(canonicalFiles)files=canonicalFiles.filter(file=>file.path.startsWith(prefix));
  else{
    const native=evidenceFiles(root).filter(file=>file.path.startsWith('manager2-artifacts/'));
    shards.caseParts.assertScreenshotNamespace(native,part);
    // Keep the component's original tour-selection-*.png scope. In particular,
    // OneTouch's separately named capture remains full-suite evidence only.
    files=native.filter(file=>file.path.startsWith(prefix));
  }
  for(const file of files)logicalPath(file,part);
  return files;
}
function captureBindings(browser,listed){
  return capturePaths(browser).map((name,index)=>{
    const matches=listed.filter(row=>row.title.join(' › ')===mandatoryTitles[index]);
    check(matches.length===1,'Mandatory capture must have one exact case owner');
    return {path:name,key:matches[0].key,width:Number(/-(\d+)\.png$/.exec(name)[1])};
  });
}
function partCaptures(root,browser,part,listed,cases,files){
  const owned=new Set(cases.map(row=>row.key)),byName=new Map();
  for(const file of files){
    const name=logicalPath(file,part);
    check(!byName.has(name),'Component screenshot collision: '+name);byName.set(name,file);
  }
  return captureBindings(browser,listed).flatMap(binding=>{
    const file=byName.get(binding.path);
    if(!owned.has(binding.key)){check(!file,'Mandatory capture belongs to another part: '+binding.path);return [];}
    check(file&&file.size>10000,'Missing owned selection capture: '+binding.path);
    const bytes=fs.readFileSync(path.join(root,file.path));
    check(bytes.subarray(12,16).toString()==='IHDR'&&bytes.readUInt32BE(16)===binding.width&&bytes.readUInt32BE(20)>=900,'Wrong selection capture viewport: '+binding.path);
    return [{...file,logicalPath:binding.path,caseKey:binding.key}];
  });
}
function loadPart(root,browser,part){
  const report=original(root,REPORT),expected=original(root,EXPECTED),filtered=original(root,FILTERED);
  const verified=verifyPartReport(report.value,expected.value,filtered.value,browser,part);
  return {report,expected,filtered,...verified};
}
function partReceipt({root,browser,part,p,sourceTree,artifactRoot=root,canonicalFiles,loaded=loadPart(root,browser,part)}){
  assertOrigin(p);environment.assertExecution(p.execution);
  check(/^[a-f0-9]{40}$/.test(sourceTree||''),'Exact component source tree required');
  const files=componentFiles(artifactRoot,part,canonicalFiles);
  const captures=partCaptures(artifactRoot,browser,part,loaded.listed,loaded.cases,files);
  return {schema:1,profile:'tour-selection',protocol:PROTOCOL,...origin(p),browser,part,partCount:PART_COUNT,
    executionRole:'browser-partition',sourceTree,completed:true,
    expectedCases:loaded.listed,expectedCaseDigest:digest(loaded.listed),cases:loaded.cases,caseDigest:digest(loaded.cases),files,captures,
    reportDigest:loaded.report.digest,expectedDigest:loaded.expected.digest,filteredDigest:loaded.filtered.digest};
}
function recordPart({root,browser,part,p,sourceRoot=root}){
  inventory.assertCleanSource(sourceRoot,p.testedSha);environment.assertExecution(p.execution);
  const loaded=loadPart(root,browser,part);
  shards.caseParts.namespaceScreenshots({root,part,outputDirs:['manager2-artifacts']});
  return partReceipt({root,browser,part,p,sourceTree:treeAt(sourceRoot,p.testedSha),loaded});
}
function validatePart({root,browser,part,p,sourceTree,artifactRoot=root,canonicalFiles}){
  const receipt=original(root,PART_RECEIPT).value;
  check(receipt.protocol===PROTOCOL&&receipt.profile==='tour-selection'&&receipt.partCount===PART_COUNT&&receipt.part===part&&receipt.browser===browser,'Wrong component part identity');
  matchOrigin(receipt,p);
  const rebuilt=partReceipt({root,browser,part,p:origin(receipt),sourceTree,artifactRoot,canonicalFiles});
  check(same(receipt,rebuilt),'Component reports, source, captures or digests differ from original part');
  return receipt;
}
function validateParts(parts,browser){
  check(parts.length===PART_COUNT&&parts.every((part,index)=>part.part===index&&part.browser===browser),'Missing or duplicate component part');
  for(const part of parts){
    check(same(part.expectedCases,parts[0].expectedCases),'Component parts disagree on complete native inventory');
    check(same(part.execution,parts[0].execution),'Component parts used different native environments');
  }
  reuse.exactCases(parts.flatMap(part=>part.cases),parts[0].expectedCases,'Complete component part union');
  const names=parts.flatMap(part=>part.captures.map(file=>file.logicalPath)).sort();
  check(same(names,capturePaths(browser).sort()),'Missing or duplicate mandatory component capture');
  return shards.ownedFiles(parts);
}
function partRoots(root){
  directory(root);
  check(same(fs.readdirSync(root).sort(),['part-0','part-1']),'Expected exactly the two original component directories');
  return [0,1].map(part=>{const item=path.join(root,'part-'+part);directory(item);return item;});
}
function assemblyReceipt({root,browser,p,sourceTree,parts}){
  const partitionFiles=validateParts(parts,browser),expected=original(root,EXPECTED),report=original(root,REPORT);
  const cases=verifyReport(report.value,expected.value,browser),files=verifyCaptures(root,browser);
  const owned=new Map(parts.flatMap(part=>part.captures.map(file=>[file.logicalPath,file])));
  for(const file of files){const originalFile=owned.get(file.path);check(originalFile&&file.size===originalFile.size&&file.sha256===originalFile.sha256,'Canonical capture differs from its exact part owner');}
  const allFiles=shards.ownedFiles([...parts,{files}]);
  check(same(evidenceFiles(root),allFiles),'Assembled screenshot ownership is incomplete or changed');
  return {schema:1,profile:'tour-selection',...origin(p),execution:parts[0].execution,assemblyExecution:p.execution,browser,completed:true,
    executionRole:'assembly',sourceTree,partitionProtocol:PROTOCOL,partitionCount:PART_COUNT,partitionDigest:digest(parts),
    cases,caseDigest:digest(cases),files,partitionFiles,reportDigest:report.digest,expectedDigest:expected.digest};
}
function verifyAssembledEvidence({root,browser,p,sourceRoot}){
  inventory.assertCleanSource(sourceRoot,p.testedSha);
  const receipt=original(root,RECEIPT).value;
  check(receipt.partitionProtocol===PROTOCOL&&receipt.partitionCount===PART_COUNT&&receipt.executionRole==='assembly','Invalid component assembly protocol');
  matchOrigin(receipt,p);
  const assemblyP={...origin(receipt),execution:receipt.assemblyExecution};assertOrigin(assemblyP);
  const sourceTree=treeAt(sourceRoot,p.testedSha),artifacts=evidenceFiles(root),roots=partRoots(path.join(root,'partition-proof'));
  const parts=roots.map((partRoot,part)=>validatePart({root:partRoot,browser,part,p:assemblyP,sourceTree,artifactRoot:root,canonicalFiles:artifacts}));
  const reports=roots.map(partRoot=>original(partRoot,REPORT).value);
  check(same(original(root,REPORT).value,shards.mergedMain(reports)),'Canonical component report differs from original part reports');
  check(original(root,EXPECTED).digest===original(roots[0],EXPECTED).digest,'Canonical inventory differs from original unfiltered listing');
  const rebuilt=assemblyReceipt({root,browser,p:assemblyP,sourceTree,parts});
  check(same(receipt,rebuilt),'Invalid assembled component provenance or evidence');
  return {receipt,parts};
}
function assemble({sourceRoot,partsRoot,destination,browser,p,partsResult}){
  shards.assertPartsResult(partsResult);assertOrigin(p);inventory.assertCleanSource(sourceRoot,p.testedSha);
  check(!fs.existsSync(destination),'Component assembly destination must be fresh');
  const sourceTree=treeAt(sourceRoot,p.testedSha),roots=partRoots(path.join(partsRoot,browser));
  const parts=roots.map((root,part)=>validatePart({root,browser,part,p,sourceTree}));
  const files=validateParts(parts,browser);
  // All native reports, source identities, ownership and PNG bytes are checked
  // before any canonical file is created. Separate part directories avoid an
  // artifact download overwriting a same-named screenshot from another runner.
  fs.mkdirSync(destination,{recursive:true});
  function copy(from,name,toName=name){
    const target=path.join(destination,toName);fs.mkdirSync(path.dirname(target),{recursive:true});
    fs.copyFileSync(path.join(from,name),target,fs.constants.COPYFILE_EXCL);
  }
  for(const file of files){
    const owner=parts.findIndex(part=>part.files.some(item=>item.path===file.path));copy(roots[owner],file.path);
  }
  for(let part=0;part<PART_COUNT;part++){
    for(const name of [PART_RECEIPT,REPORT,EXPECTED,FILTERED])copy(roots[part],name,'partition-proof/part-'+part+'/'+name);
    for(const capture of parts[part].captures)copy(roots[part],capture.path,capture.logicalPath);
  }
  copy(roots[0],EXPECTED);
  save(destination,REPORT,shards.mergedMain(roots.map(root=>original(root,REPORT).value)));
  const receipt=assemblyReceipt({root:destination,browser,p,sourceTree,parts});
  save(destination,RECEIPT,receipt);
  verifyAssembledEvidence({root:destination,browser,p,sourceRoot});
  return receipt;
}
if(require.main===module){
  try{
    const args=process.argv.slice(2),[command,browser,partOrRoot,destination]=args;
    const root=process.cwd(),p=reuse.provenance(process.env);
    if(command==='assemble'){
      check(args.length===4,'Usage: verify_tour_selection_browser_report.js assemble BROWSER PARTS_ROOT DESTINATION');
      const receipt=assemble({sourceRoot:root,partsRoot:path.resolve(partOrRoot),destination:path.resolve(destination),browser,p,partsResult:process.env.QA_PARTS_RESULT});
      process.stdout.write(`Complete tour-selection union verified: ${receipt.cases.length} cases, ${receipt.files.length} captures (${browser}, two parts)\n`);
    }else if(command==='run-part'||command==='record-part'){
      check(args.length===3,'Usage: verify_tour_selection_browser_report.js run-part|record-part BROWSER PART');
      const part=parsePart(partOrRoot);environment.assertExecution(p.execution);inventory.assertCleanSource(root,p.testedSha);
      if(command==='run-part'){
        const expected=original(root,EXPECTED).value,listed=listedCases(expected,browser);
        check(expected.config.workers===1,'Component listing must retain one native worker');
        shards.caseParts.run({root,browser,selection:allocation(browser,part,listed),collectCases:inventory.collectCases,exactCases:reuse.exactCases,
          reportFile:path.join(root,REPORT),filteredFile:path.join(root,FILTERED),forbidOnly:true});
      }else{
        const receipt=recordPart({root,browser,part,p});save(root,PART_RECEIPT,receipt);
        process.stdout.write(`Exact tour-selection part verified: ${receipt.cases.length} cases, ${receipt.captures.length} owned mandatory captures (${browser}, part ${part})\n`);
      }
    }else{
      // Keep the original complete-report API for its existing callers/tests.
      const [reportFile,expectedFile,originalBrowser]=args;
      check(reportFile&&expectedFile&&args.length===3,'Usage: verify_tour_selection_browser_report.js REPORT EXPECTED chromium|webkit');
      environment.assertExecution(p.execution);inventory.assertCleanSource(root,p.testedSha);
      const report=JSON.parse(fs.readFileSync(reportFile,'utf8')),expected=JSON.parse(fs.readFileSync(expectedFile,'utf8'));
      const cases=verifyReport(report,expected,originalBrowser),files=verifyCaptures(root,originalBrowser);
      const receipt={schema:1,profile:'tour-selection',...p,browser:originalBrowser,completed:true,sourceTree:treeAt(root,p.testedSha),
        cases,caseDigest:digest(cases),files,reportDigest:reuse.hash(fs.readFileSync(reportFile)),expectedDigest:reuse.hash(fs.readFileSync(expectedFile))};
      save(root,RECEIPT,receipt);
      process.stdout.write(`Complete tour-selection browser evidence verified: ${cases.length} cases, ${files.length} captures (${originalBrowser})\n`);
    }
  }catch(error){process.stderr.write('Tour-selection evidence rejected: '+error.message+'\n');process.exitCode=1;}
}
module.exports={SPEC,WIDTHS,PART_COUNT,PROTOCOL,mandatoryTitles,verifyReport,verifyPartReport,parsePart,capturePaths,verifyCaptures,recordPart,validatePart,assemble,verifyAssembledEvidence};
