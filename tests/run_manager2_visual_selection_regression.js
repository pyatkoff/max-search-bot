'use strict';

const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const {execFileSync}=require('node:child_process');
const crypto=require('node:crypto');
const Module=require('node:module');
const productionInventory=require('../tools/manager2_visual_inventory');
// Unit-test the unchanged implementation against a miniature reviewed harness.
// Only this isolated module instance's pin is substituted; production has no
// configurable pin or override. This avoids relying on a historical Git object
// (unavailable in ordinary shallow CI checkouts), and future fixture edits may
// correctly select FULL without breaking these independent unit fixtures.
const inventoryFile=require.resolve('../tools/manager2_visual_inventory');
const inventorySource=fs.readFileSync(inventoryFile,'utf8');
const pinDeclaration=/const reviewedHarnessDigest = '[a-f0-9]{64}';/g;
assert.equal([...inventorySource.matchAll(pinDeclaration)].length,1);
const fixturePin=crypto.createHash('sha256').update(JSON.stringify([])).digest('hex');
const fixtureModule=new Module(inventoryFile,module);fixtureModule.filename=inventoryFile;fixtureModule.paths=module.paths;
fixtureModule._compile(inventorySource.replace(pinDeclaration,"const reviewedHarnessDigest = '"+fixturePin+"';"),inventoryFile);
const {SCHEMA,TOOLCHAIN,LEAF_SPECS,reviewedHarnessDigest,PART_COUNT,partitionSpecs,mainSpecs,allSpecs,alwaysSpecs,sourceManifest,assertCleanSource,assertSafeEnvironment,classifyChanges,collectCases}=fixtureModule.exports;
const root=fs.mkdtempSync(path.join(os.tmpdir(),'manager2-visual-selection-'));
const cleanRoot=fs.mkdtempSync(path.join(os.tmpdir(),'manager2-visual-clean-source-'));
const git=(...args)=>execFileSync('git',args,{cwd:root,encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim();
const clone=value=>JSON.parse(JSON.stringify(value));
let passed=0;
function check(name,run){run();passed++;process.stdout.write('PASS '+name+'\n');}
function write(file,body){const target=path.join(root,file);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,body);}
function commit(){git('add','--all');git('-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','--quiet','--allow-empty','-m','fixture');return git('rev-parse','HEAD');}
const spec=LEAF_SPECS[0];
function report(){return {config:{rootDir:'/fixture/repo'},errors:[],suites:[{
  title:spec,file:spec,suites:[{title:'mobile 390',file:spec,specs:[{
    title:'preserves draft',file:spec,line:40,column:1,ok:true,
    tests:[{projectName:'chromium',expectedStatus:'passed',annotations:[],status:'expected',results:[{status:'passed',errors:[]}]}],
  }]}],
}]};}
function testCase(r){return r.suites[0].suites[0].specs[0].tests[0];}
const options={browser:'chromium',expectedFiles:[spec]};
try {
  git('init','--quiet');
  write('manager/assets/shared.js','shared source');
  for(const file of LEAF_SPECS)write(file,'original leaf '+file);
  const original=commit(),baseline=sourceManifest(root,original);
  check('inventory preserves main commands and daily cache separately',()=>{
    assert.equal(SCHEMA,2);assert.equal(TOOLCHAIN.playwright,'1.55.0');
    assert.equal(mainSpecs('chromium').length,26);assert.equal(mainSpecs('webkit').length,25);
    assert.equal(allSpecs('chromium').length,27);assert.equal(allSpecs('webkit').length,26);
    assert.equal(new Set(allSpecs('chromium')).size,27);
    for(const browser of ['chromium','webkit']){
      const events='tests/visual/manager-client-events.spec.js';
      assert(allSpecs(browser).includes(events));
      assert.equal(Array.from({length:PART_COUNT},(_,part)=>partitionSpecs(browser,part)).flat().filter(spec=>spec===events).length,1);
    }
    for(const browser of ['chromium','webkit'])assert(mainSpecs(browser).includes('tests/visual/manager-ai-draft.spec.js'));
    for(const browser of ['chromium','webkit'])assert(mainSpecs(browser).includes('tests/visual/manager-assistant.spec.js'));
    assert.deepEqual(alwaysSpecs('webkit'),['tests/visual/manager2-workspace.spec.js']);
    assert(alwaysSpecs('chromium').includes('tests/visual/workspace-v2-layout.spec.js'));
    assert.throws(()=>allSpecs('firefox'));
  });
  check('full tracked source manifest retains common and leaf blobs',()=>{
    assert.equal(baseline.entries.length,3);assert.equal(baseline.leaves[spec].mode,'100644');
    assert.equal(baseline.harnessDigest,reviewedHarnessDigest);
    assert.match(baseline.commonDigest,/^[a-f0-9]{64}$/);assert.equal(sourceManifest(root,original).commonDigest,baseline.commonDigest);
  });
  check('whole-spec partitions preserve every main spec exactly once with layout and history owned by zero',()=>{
    assert.equal(PART_COUNT,4);
    for(const browser of ['chromium','webkit']) {
      const parts=Array.from({length:PART_COUNT},(_,part)=>partitionSpecs(browser,part));
      const union=parts.flat();assert.equal(new Set(union).size,union.length);
      assert.deepEqual([...union].sort(),[...mainSpecs(browser)].sort());
      assert(parts.every(part=>part.length>0));
      assert(!union.includes('tests/visual/manager-avatar-cache.spec.js'));
      const history='tests/visual/manager-transcript-history.spec.js';
      assert(parts[0].includes(history));
      assert(parts.slice(1).every(part=>!part.includes(history)));
      assert.deepEqual(parts[3],['tests/visual/manager-tour-selection.spec.js']);
      for(const invalid of [-1,PART_COUNT,'0',null,0.5])assert.throws(()=>partitionSpecs(browser,invalid),/Invalid browser partition/);
    }
    assert(partitionSpecs('chromium',0).includes('tests/visual/workspace-v2-layout.spec.js'));
    for(let part=1;part<PART_COUNT;part++)assert(!partitionSpecs('chromium',part).includes('tests/visual/workspace-v2-layout.spec.js'));
    assert.throws(()=>partitionSpecs('firefox',0));
  });
  check('production uses a fixed non-configurable reviewed harness pin',()=>{
    assert.match(productionInventory.reviewedHarnessDigest,/^[a-f0-9]{64}$/);
    assert.notEqual(productionInventory.reviewedHarnessDigest,fixturePin);
    // A new-schema tiny harness is not silently accepted by the real module.
    assert.equal(productionInventory.classifyChanges(baseline,baseline).mode,'full');
    const current=productionInventory.sourceManifest(path.resolve(__dirname,'..'),'HEAD');
    assert.match(current.harnessDigest,/^[a-f0-9]{64}$/);
  });
  check('identical trees conservatively require full',()=>assert.equal(classifyChanges(baseline,baseline).mode,'full'));
  write(spec,'changed reviewed leaf');const leaf=commit(),leafManifest=sourceManifest(root,leaf);
  check('one modified existing audited leaf enables affected selection',()=>{
    assert.deepEqual(classifyChanges(baseline,leafManifest).changedSpecs,[spec]);
    assert.equal(classifyChanges(baseline,leafManifest).mode,'affected');
    assert.equal(baseline.commonDigest,leafManifest.commonDigest);
  });
  write(LEAF_SPECS[1],'second changed reviewed leaf');const both=commit();
  check('both audited existing leaves may select together',()=>assert.equal(classifyChanges(baseline,sourceManifest(root,both)).changedSpecs.length,2));
  write('manager/assets/shared.js','base branch runtime change');const shared=commit();
  check('base/runtime dependency changes cannot hide behind a leaf-only PR diff',()=>assert.equal(classifyChanges(baseline,sourceManifest(root,shared)).mode,'full'));
  write('unrecognized.txt','unknown addition');const added=commit();
  check('unknown added path requires full',()=>assert.equal(classifyChanges(sourceManifest(root,shared),sourceManifest(root,added)).mode,'full'));
  fs.unlinkSync(path.join(root,spec));const removed=commit();
  check('deleted leaf requires full',()=>assert.equal(classifyChanges(sourceManifest(root,added),sourceManifest(root,removed)).mode,'full'));
  write(spec,'added leaf');const restored=commit();
  check('added leaf requires full',()=>assert.equal(classifyChanges(sourceManifest(root,removed),sourceManifest(root,restored)).mode,'full'));
  fs.renameSync(path.join(root,spec),path.join(root,spec+'.renamed'));const renamed=commit();
  check('renamed leaf requires full',()=>assert.equal(classifyChanges(sourceManifest(root,restored),sourceManifest(root,renamed)).mode,'full'));
  write(LEAF_SPECS[1],'executable leaf');fs.chmodSync(path.join(root,LEAF_SPECS[1]),0o755);const executable=commit();
  check('leaf mode changes require full',()=>assert.equal(classifyChanges(sourceManifest(root,renamed),sourceManifest(root,executable)).mode,'full'));
  fs.symlinkSync('manager/assets/shared.js',path.join(root,'unsafe-link'));const linked=commit();
  check('tracked symlinks cannot enter source evidence',()=>assert.throws(()=>sourceManifest(root,linked),/forbids symlinks/));
  check('registry and manifest tampering fail closed',()=>{
    for(const mutate of [m=>m.registryDigest='unknown',m=>m.schema=1,m=>m.commonDigest='forged',m=>m.harnessDigest='forged',m=>m.entries.reverse(),m=>m.leaves[spec].blob='0'.repeat(40)]){
      const forged=clone(leafManifest);mutate(forged);assert.equal(classifyChanges(baseline,forged).mode,'full');
    }
  });
  fs.unlinkSync(path.join(root,'unsafe-link'));
  write('tests/visual/fixtures/new-leaf-import.js',"require('../manager2-messenger-layout.spec.js');");const importing=commit(),newHarness=sourceManifest(root,importing);
  write(LEAF_SPECS[1],'subsequent leaf-only change');const afterImport=commit(),nextLeaf=sourceManifest(root,afterImport);
  check('new helper/incoming leaf import stays full even after a same-harness full donor',()=>{
    assert.equal(newHarness.commonDigest,nextLeaf.commonDigest);assert.equal(newHarness.harnessDigest,nextLeaf.harnessDigest);
    assert.notEqual(newHarness.harnessDigest,reviewedHarnessDigest);
    assert.match(classifyChanges(newHarness,nextLeaf).reason,/new leaf-independence audit/);
    assert.equal(classifyChanges(newHarness,nextLeaf).mode,'full');
    const realDonor=productionInventory.sourceManifest(root,importing),realNext=productionInventory.sourceManifest(root,afterImport);
    assert.match(productionInventory.classifyChanges(realDonor,realNext).reason,/new leaf-independence audit/);
  });
  write('tests/fixtures/outside-visual.js',"require('../visual/manager2-inbox-compact.spec.js');");const outsideImport=commit(),outsideHarness=sourceManifest(root,outsideImport);
  write(LEAF_SPECS[1],'another leaf-only change');const afterOutsideImport=commit(),afterOutside=sourceManifest(root,afterOutsideImport);
  check('out-of-visual transitive fixture/import prevents reuse after a new full donor',()=>{
    assert.equal(outsideHarness.commonDigest,afterOutside.commonDigest);
    assert.equal(outsideHarness.harnessDigest,afterOutside.harnessDigest);
    assert.notEqual(outsideHarness.harnessDigest,newHarness.harnessDigest);
    assert.match(classifyChanges(outsideHarness,afterOutside).reason,/new leaf-independence audit/);
  });
  const cleanGit=(...args)=>execFileSync('git',args,{cwd:cleanRoot,encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim();
  const cleanWrite=(file,body)=>{const target=path.join(cleanRoot,file);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,body);};
  cleanGit('init','--quiet');cleanWrite('manager/runtime.php','original runtime');cleanWrite(spec,'original leaf');cleanGit('add','--all');
  cleanGit('-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','--quiet','-m','clean fixture');
  const cleanSha=cleanGit('rev-parse','HEAD');
  check('exact clean checkout passes source check',()=>assert.equal(assertCleanSource(cleanRoot,cleanSha),true));
  check('dirty tracked runtime and leaf are rejected independently',()=>{
    for(const [file,original]of [['manager/runtime.php','original runtime'],[spec,'original leaf']]){
      cleanWrite(file,'dirty source');assert.throws(()=>assertCleanSource(cleanRoot,cleanSha),/Tracked source/);cleanWrite(file,original);
    }
    assert.equal(assertCleanSource(cleanRoot,cleanSha),true);
  });
  check('staged changes are rejected even when working bytes match tested HEAD',()=>{
    cleanWrite(spec,'staged leaf');cleanGit('add',spec);cleanWrite(spec,'original leaf');
    assert.throws(()=>assertCleanSource(cleanRoot,cleanSha),/Tracked source/);cleanGit('add',spec);
    assert.equal(assertCleanSource(cleanRoot,cleanSha),true);
  });
  check('untracked and ignored executable dependency sources are rejected',()=>{
    for(const file of ['manager/extra.js','manager2/extra.css','tests/visual/extra.spec.js','tests/fixtures/extra.php','tests/support/extra.html']){
      cleanWrite(file,'untracked dependency');assert.throws(()=>assertCleanSource(cleanRoot,cleanSha),/Untracked/);fs.unlinkSync(path.join(cleanRoot,file));
    }
    cleanWrite('.git/info/exclude','tests/fixtures/ignored.js\n');cleanWrite('tests/fixtures/ignored.js','ignored dependency');
    assert.throws(()=>assertCleanSource(cleanRoot,cleanSha),/Untracked/);fs.unlinkSync(path.join(cleanRoot,'tests/fixtures/ignored.js'));
  });
  check('normal browser dependencies screenshots and reports do not dirty source',()=>{
    cleanWrite('node_modules/package/index.js','installed dependency');cleanWrite('visual-artifacts/capture.png','image');cleanWrite('manager2-artifacts/report.json','report');cleanWrite('tests/visual/screenshot.png','image');
    assert.equal(assertCleanSource(cleanRoot,cleanSha),true);
  });
  check('different HEAD or malformed tested SHA cannot attest source',()=>{
    assert.throws(()=>assertCleanSource(cleanRoot,'not-a-sha'),/Exact tested SHA/);
    cleanGit('-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','--quiet','--allow-empty','-m','different head');
    assert.throws(()=>assertCleanSource(cleanRoot,cleanSha),/HEAD differs/);
  });
  check('source and process override variables are rejected even when empty',()=>{
    for(const key of ['M2_QA_SOURCE_ROOT','M2_HISTORY_BASELINE','M2_HISTORY_BASELINE_ROOT','M2_ARTIFACT_ROOT','M2_CAPACITY_SOURCE','M2_CAPACITY_BASE_ROOT','M2_CAPACITY_STAGE','NODE_OPTIONS','NODE_PATH','PLAYWRIGHT_BROWSERS_PATH'])assert.throws(()=>assertSafeEnvironment({[key]:''}),/override forbidden/);
    assert.equal(assertSafeEnvironment({PATH:'/usr/bin',QA_BROWSER:'chromium'}),true);
  });
  check('real nested Playwright report shape creates stable case identity',()=>{
    const rows=collectCases(report(),options);assert.equal(rows.length,1);
    assert.deepEqual(rows[0],{key:JSON.stringify(['chromium','chromium',spec,['mobile 390','preserves draft']]),spec,title:['mobile 390','preserves draft'],project:'chromium'});
    const moved=report();moved.suites[0].suites[0].specs[0].line=999;
    assert.deepEqual(collectCases(moved,options),rows);
  });
  check('absolute report source paths normalize against config root',()=>{
    const absolute=report();absolute.suites[0].file='/fixture/repo/'+spec;absolute.suites[0].suites[0].specs[0].file='/fixture/repo/'+spec;
    assert.deepEqual(collectCases(absolute,options),collectCases(report(),options));
  });
  check('test-directory report root normalizes repository spec names',()=>{
    const r=report();r.config.rootDir='/fixture/repo/tests/visual';r.suites[0].file='manager2-inbox-compact.spec.js';r.suites[0].suites[0].specs[0].file='manager2-inbox-compact.spec.js';
    assert.deepEqual(collectCases(r,options),collectCases(report(),options));
  });
  check('list collection accepts zero execution results only in listing mode',()=>{
    const r=report();testCase(r).results=[];delete testCase(r).status;
    assert.equal(collectCases(r,{...options,listing:true}).length,1);assert.throws(()=>collectCases(r,options),/Incomplete/);
  });
  check('missing expected spec cannot pass',()=>assert.throws(()=>collectCases(report(),{...options,expectedFiles:LEAF_SPECS}),/Missing expected/));
  check('duplicate full-title case cannot pass',()=>{
    const r=report();r.suites[0].suites[0].specs.push(clone(r.suites[0].suites[0].specs[0]));assert.throws(()=>collectCases(r,options),/Duplicate/);
  });
  check('foreign swapped-engine and unnamed projects cannot attest the browser',()=>{
    for(const project of ['firefox','webkit','second project','']){const r=report();testCase(r).projectName=project;assert.throws(()=>collectCases(r,options),/mismatched/);assert.throws(()=>collectCases(r,{...options,listing:true}),/mismatched/);}
  });
  check('skipped fixme and expected-failure cases cannot pass even listing',()=>{
    for(const type of ['skip','fixme','fail']){const r=report();testCase(r).annotations=[{type}];assert.throws(()=>collectCases(r,options));assert.throws(()=>collectCases(r,{...options,listing:true}));}
    const r=report();testCase(r).expectedStatus='failed';assert.throws(()=>collectCases(r,options));
  });
  check('failed interrupted timed-out skipped and retry-flaky results cannot pass',()=>{
    for(const status of ['failed','interrupted','timedOut','skipped']){const r=report();testCase(r).results[0].status=status;assert.throws(()=>collectCases(r,options),/Incomplete/);}
    const r=report();testCase(r).results.unshift({status:'failed'});assert.throws(()=>collectCases(r,options),/Incomplete/);
    const f=report();testCase(f).status='flaky';assert.throws(()=>collectCases(f,options),/Incomplete/);
  });
  check('report-level and result-level errors cannot pass',()=>{
    const r=report();r.errors=[{message:'worker crashed'}];assert.throws(()=>collectCases(r,options));
    const t=report();testCase(t).results[0].errors=[{message:'late error'}];assert.throws(()=>collectCases(t,options));
  });
  check('outside unexpected and traversal report files cannot pass',()=>{
    for(const file of ['/another/repo/'+spec,'tests/visual/../visual/manager2-inbox-compact.spec.js','tests/visual/unlisted.spec.js']){const r=report();r.suites[0].suites[0].specs[0].file=file;assert.throws(()=>collectCases(r,options));}
  });
  check('empty report, empty spec and duplicate expected files cannot pass',()=>{
    const empty=report();empty.suites=[];assert.throws(()=>collectCases(empty,options));
    const r=report();r.suites[0].suites[0].specs[0].tests=[];assert.throws(()=>collectCases(r,options));
    assert.throws(()=>collectCases(report(),{...options,expectedFiles:[spec,spec]}));
  });
  const prepared=require('../tools/browser_qa_environment');
  const pinned=prepared.pin();
  const native={...pinned.runtime,containerImage:pinned.image,recipeSha:pinned.recipeSha};
  check('native prepared image and exact runtime are required when the source has an environment pin',()=>{
    assert.equal(prepared.assertExecution(clone(native)).containerImage,pinned.image);
    prepared.assertSourceExecution(clone(native),{entries:[{path:'tools/browser_qa_environment.json'}]});
    assert.throws(()=>prepared.assertSourceExecution({}, {entries:[{path:'tools/browser_qa_environment.json'}]}));
    prepared.assertSourceExecution({}, {entries:[]}); // Existing isolated legacy protocol fixtures.
    for(const key of Object.keys(native)){
      const missing=clone(native);delete missing[key];assert.throws(()=>prepared.assertExecution(missing));
      const wrong=clone(native);wrong[key]='foreign';assert.throws(()=>prepared.assertExecution(wrong));
    }
  });
  check('planning host cannot manufacture a prepared browser receipt through an environment override',()=>{
    assert.deepEqual(prepared.fromEnvironment({}, {node:'host'}),{node:'host'});
    for(const value of ['',pinned.image.replace(/.$/,'x'),'foreign'])assert.throws(()=>prepared.fromEnvironment({QA_BROWSER_IMAGE:value},{node:'host'}));
    assert.throws(()=>prepared.fromEnvironment({QA_BROWSER_IMAGE:pinned.image},{node:'host'}));
  });
  check('every prepared recipe input changes its deterministic identity',()=>{
    const recipe=require('../.github/browser-qa/verify-runtime');
    const original=path.resolve(__dirname,'../.github/browser-qa');
    const temp=path.join(cleanRoot,'prepared-recipe');fs.mkdirSync(temp,{recursive:true});
    for(const file of recipe.RECIPE)fs.copyFileSync(path.join(original,file),path.join(temp,file));
    assert.equal(recipe.recipeSha(temp),pinned.recipeSha);
    for(const file of recipe.RECIPE){
      fs.appendFileSync(path.join(temp,file),'\n');assert.notEqual(recipe.recipeSha(temp),pinned.recipeSha);
      fs.copyFileSync(path.join(original,file),path.join(temp,file));
    }
  });
  process.stdout.write(passed+' visual inventory/selection checks passed\n');
} finally {fs.rmSync(root,{recursive:true,force:true});fs.rmSync(cleanRoot,{recursive:true,force:true});}
