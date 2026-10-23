#!/usr/bin/env node
'use strict';

const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const inventory=require('./manager2_visual_inventory');
const reuse=require('./manager2_visual_reuse');
const SPEC='tests/visual/manager-tour-selection.spec.js';
const WIDTHS=[390,430,768,1440];
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
function verifyReport(report,expected,browser){
  check(['chromium','webkit'].includes(browser),'Expected Chromium or WebKit');
  for(const value of [report,expected]){
    check(value?.config?.projects?.length===1&&value.config.projects[0].name===browser&&value.config.forbidOnly===true,'Expected one pinned browser project with forbid-only');
    check(Array.isArray(value.errors)&&value.errors.length===0,'Missing or failed browser worker inventory');
  }
  const listed=inventory.collectCases(expected,{browser,expectedFiles:[SPEC],listing:true});
  const actual=inventory.collectCases(report,{browser,expectedFiles:[SPEC]});
  const titles=new Set(listed.map(row=>row.title.join(' › ')));
  for(const title of mandatoryTitles)check(titles.has(title),'Missing mandatory selection contract: '+title);
  reuse.exactCases(actual,listed,'Complete tour-selection execution');
  const stats=report.stats;
  check(stats&&stats.expected===actual.length&&stats.skipped===0&&stats.unexpected===0&&stats.flaky===0,'Incomplete selection execution statistics');
  check(Number.isFinite(Date.parse(stats.startTime))&&Number.isFinite(stats.duration)&&stats.duration>=0,'Missing native execution timing');
  function visit(suite,listing=false){
    for(const spec of suite.specs||[])for(const test of spec.tests||[])check(listing?test.results.length===0:test.results.length===1&&test.results[0].retry===0,listing?'Expected fresh --list inventory':'Selection cases must execute without retries');
    for(const child of suite.suites||[])visit(child,listing);
  }
  report.suites.forEach(suite=>visit(suite));
  expected.suites.forEach(suite=>visit(suite,true));
  return actual;
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
if(require.main===module){
  try{
    const [reportFile,expectedFile,browser,...extra]=process.argv.slice(2);
    check(reportFile&&expectedFile&&!extra.length,'Usage: verify_tour_selection_browser_report.js REPORT EXPECTED chromium|webkit');
    const root=process.cwd(),p=reuse.provenance(process.env);
    require('./browser_qa_environment.js').assertExecution(p.execution);
    inventory.assertCleanSource(root,p.testedSha);
    const report=JSON.parse(fs.readFileSync(reportFile,'utf8')),expected=JSON.parse(fs.readFileSync(expectedFile,'utf8'));
    const cases=verifyReport(report,expected,browser),files=verifyCaptures(root,browser);
    const receipt={schema:1,profile:'tour-selection',...p,browser,completed:true,
      sourceTree:execFileSync('git',['rev-parse',p.testedSha+'^{tree}'],{cwd:root,encoding:'utf8'}).trim(),
      cases,caseDigest:reuse.hash(JSON.stringify(cases)),files,
      reportDigest:reuse.hash(fs.readFileSync(reportFile)),expectedDigest:reuse.hash(fs.readFileSync(expectedFile))};
    fs.writeFileSync('tour-selection-receipt.json',JSON.stringify(receipt,null,2)+'\n');
    process.stdout.write(`Complete tour-selection browser evidence verified: ${cases.length} cases, ${files.length} captures (${browser})\n`);
  }catch(error){process.stderr.write('Tour-selection evidence rejected: '+error.message+'\n');process.exitCode=1;}
}
module.exports={SPEC,WIDTHS,mandatoryTitles,verifyReport,capturePaths,verifyCaptures};
