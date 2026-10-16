'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const Module = require('node:module');
const {execFileSync} = require('node:child_process');
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
  check('the current release CLI cannot accept legacy count-only evidence',()=>assert.throws(()=>verifyEvidence({...options,requireModern:true}),/schema-2/));
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
  modernProtocolChecks();
  process.stdout.write(passed+' CI evidence checks passed\n');
} finally {fs.rmSync(root,{recursive:true,force:true});}

// These tiny PNGs and one-case-per-spec reports exercise only the evidence
// protocol. They are deliberately NOT browser execution or visual acceptance.
function modernProtocolChecks() {
  const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'manager2-modern-protocol-'));
  const sourceRoot=path.join(temporary,'source'), baseline=path.join(temporary,'baseline');
  const currentEvidence=path.join(temporary,'full'), affectedEvidence=path.join(temporary,'affected');
  const repositoryRoot=path.resolve(__dirname,'..');
  // Pin substitution exists only in isolated test module instances. The
  // production modules expose no configurable pin. Ordinary future fixture
  // edits may safely force production FULL without invalidating this protocol
  // regression or requiring an unnecessary new independence audit.
  const inventoryFile=require.resolve('../tools/manager2_visual_inventory.js');
  const reuseFile=require.resolve('../tools/manager2_visual_reuse.js');
  const verifierFile=require.resolve('../tools/verify_manager2_visual_evidence.js');
  const inventorySource=fs.readFileSync(inventoryFile,'utf8');
  const pinDeclaration=/const reviewedHarnessDigest = '[a-f0-9]{64}';/g;
  assert.equal([...inventorySource.matchAll(pinDeclaration)].length,1);
  const fixturePin=crypto.createHash('sha256').update(JSON.stringify([])).digest('hex');
  const fixtureInventorySource=inventorySource.replace(pinDeclaration,"const reviewedHarnessDigest = '"+fixturePin+"';");
  const reuseSource=fs.readFileSync(reuseFile,'utf8');
  function isolatedModule(filename,source,dependencies={}) {
    const instance=new Module(filename,module);instance.filename=filename;instance.paths=module.paths;
    instance.require=function(request){return Object.hasOwn(dependencies,request)?dependencies[request]:Module.prototype.require.call(this,request);};
    instance._compile(source,filename);return instance.exports;
  }
  const inventory=isolatedModule(inventoryFile,fixtureInventorySource);
  const reuse=isolatedModule(reuseFile,reuseSource,{'./manager2_visual_inventory.js':inventory});
  const {verifyEvidence}=isolatedModule(verifierFile,fs.readFileSync(verifierFile,'utf8'),{
    './manager2_visual_inventory.js':inventory,'./manager2_visual_reuse.js':reuse,
  });
  const clone=value=>JSON.parse(JSON.stringify(value));
  const write=(file,bytes)=>{fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,bytes);};
  const save=(file,value)=>write(file,JSON.stringify(value,null,2)+'\n');
  const read=file=>JSON.parse(fs.readFileSync(file,'utf8'));
  const mutate=(file,change)=>{const value=read(file);change(value);save(file,value);};
  const git=(...args)=>execFileSync('git',args,{cwd:sourceRoot,encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim();
  const commit=()=>{git('add','--all');git('-c','user.name=Protocol fixture','-c','user.email=fixture@example.invalid','commit','--quiet','-m','Synthetic evidence protocol fixture');return git('rev-parse','HEAD');};
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9xkAAAAASUVORK5CYII=','base64');
  const env={GITHUB_REPOSITORY:'pyatkoff/max-search-bot',QA_PR_NUMBER:'991',ImageOS:'fixture-os',ImageVersion:'fixture-image'};
  const provenance=(sha,run)=>reuse.provenance({...env,QA_TESTED_SHA:sha,QA_HEAD_SHA:sha,QA_RUN_ID:String(run),QA_RUN_ATTEMPT:'1'});
  const leaf=inventory.LEAF_SPECS[0];
  const title=(spec,changed)=>'synthetic protocol case'+(changed&&spec===leaf?' changed title':'');
  function report(browser,specs,{listing=false,changed=false}={}) {
    return {config:{rootDir:sourceRoot},errors:[],suites:specs.map(spec=>({
      title:spec,file:spec,suites:[],specs:[{title:title(spec,changed),file:spec,tests:[{
        projectName:browser,expectedStatus:'passed',annotations:[],
        ...(listing?{}:{status:'expected'}),results:listing?[]:[{status:'passed',errors:[]}],
      }]}],
    }))};
  }
  function evidence(directory,browser,p,plan,{changed=false,donorRoot}={}) {
    save(path.join(directory,'visual-reports','expected.json'),report(browser,inventory.allSpecs(browser),{listing:true,changed}));
    save(path.join(directory,'visual-reports','cache.json'),report(browser,[inventory.allSpecs(browser)[0]],{changed}));
    save(path.join(directory,'visual-reports','main.json'),report(browser,reuse.selectedSpecs(browser,plan),{changed}));
    if(browser==='chromium')for(let index=0;index<34;index++)write(path.join(directory,'visual-artifacts',index+'.png'),png);
    write(path.join(directory,'manager2-artifacts','synthetic-protocol-only.png'),png);
    if(donorRoot)fs.cpSync(donorRoot,path.join(directory,'donor'),{recursive:true});
    const receipt=reuse.buildReceipt({root:directory,browser,p,plan,donorRoot:path.join(directory,'donor')});
    save(path.join(directory,'visual-receipt.json'),receipt);
    return receipt;
  }
  const fullPlan=p=>({schema:inventory.SCHEMA,...p,mode:'full',reason:'Synthetic full fixture',changedSpecs:[],inputs:inventory.sourceManifest(sourceRoot,p.testedSha),donor:null});
  function protocolCheck(name,action){action();passed++;process.stdout.write('PASS modern protocol: '+name+'\n');}
  function copiedEvidence(from,action) {
    const directory=fs.mkdtempSync(path.join(temporary,'mutation-'));
    fs.cpSync(from,directory,{recursive:true});
    return action(directory);
  }
  function verify(directory,browser,p){return reuse.verifyBrowserEvidence({root:directory,browser,p,sourceRoot});}
  try {
    fs.mkdirSync(sourceRoot,{recursive:true});git('init','--quiet');
    for(const spec of inventory.LEAF_SPECS)write(path.join(sourceRoot,spec),'// Synthetic reviewed leaf '+spec+'\n');
    write(path.join(sourceRoot,'manager','shared.js'),'// Synthetic product dependency\n');
    const donorSha=commit(),donorP=provenance(donorSha,100),donorPlan=fullPlan(donorP);
    assert.equal(donorPlan.inputs.harnessDigest,fixturePin);
    for(const browser of ['chromium','webkit'])evidence(path.join(baseline,browser),browser,donorP,donorPlan);
    write(path.join(sourceRoot,leaf),fs.readFileSync(path.join(sourceRoot,leaf),'utf8')+'\n// Synthetic leaf-only revision\n');
    const currentSha=commit(),p=provenance(currentSha,200),plan=fullPlan(p);
    for(const browser of ['chromium','webkit'])evidence(path.join(currentEvidence,browser),browser,p,plan,{changed:true});
    const metadata={repository:p.repository,prNumber:p.prNumber,workflow:p.workflow,runId:donorP.runId,runAttempt:'1',status:'completed',conclusion:'success',event:'pull_request',headSha:donorSha,testedSha:donorSha,artifactId:'901',artifactName:'workspace-v2-visual-'+donorSha,artifactDigest:'sha256:'+'d'.repeat(64)};
    const affected=reuse.planReuse({root:sourceRoot,baseline,metadata,p});
    protocolCheck('a real clean Git source and JSON-roundtripped provenance validate',()=>{
      assert.equal(reuse.validatePlan(clone(plan),clone(p),sourceRoot).mode,'full');
      const otherRunner={...p,execution:{...p.execution,imageVersion:'separate-selection-runner'}};
      assert.equal(reuse.validatePlan(clone(plan),otherRunner,sourceRoot).mode,'full');
      assert.throws(()=>reuse.validatePlan({...clone(plan),mode:'preview'},p,sourceRoot),/Invalid selection plan/);
    });
    protocolCheck('FULL receipts reconstruct every spec including the daily cache',()=>{
      for(const browser of ['chromium','webkit']){
        const receipt=verify(path.join(currentEvidence,browser),browser,p);
        assert.equal(receipt.specCount,inventory.allSpecs(browser).length);
        assert.equal(receipt.cases.length,inventory.allSpecs(browser).length);
        assert(receipt.cases.some(row=>row.spec==='tests/visual/manager-avatar-cache.spec.js'));
        assert(receipt.cases.every(row=>row.origin==='fresh'));
      }
    });
    const gateOptions={root:currentEvidence,testedSha:currentSha,headSha:currentSha,results:['success','success','success'],runId:p.runId,runAttempt:'1',prNumber:p.prNumber,repository:p.repository,sourceRoot,env,requireModern:true};
    protocolCheck('schema-2 outer release verifier succeeds and retains earlier successful attempts',()=>{
      assert.equal(verifyEvidence(gateOptions).receipts.length,2);
      assert.equal(verifyEvidence({...gateOptions,runAttempt:'2'}).receipts.length,2);
    });
    protocolCheck('audited leaf-only change selects an immutable same-PR FULL donor',()=>{
      assert.equal(affected.mode,'affected',affected.reason);assert.deepEqual(affected.changedSpecs,[leaf]);
      assert.equal(reuse.validatePlan(clone(affected),p,sourceRoot).mode,'affected');
    });
    for(const browser of ['chromium','webkit'])evidence(path.join(affectedEvidence,browser),browser,p,affected,{changed:true,donorRoot:path.join(baseline,browser)});
    protocolCheck('affected union permits changed leaf titles and preserves unchanged donor origins',()=>{
      for(const browser of ['chromium','webkit']){
        const receipt=verify(path.join(affectedEvidence,browser),browser,p);
        assert.equal(receipt.cases.length,inventory.allSpecs(browser).length);
        assert(receipt.cases.some(row=>row.spec===leaf&&row.origin==='fresh'&&row.title[0].endsWith('changed title')));
        assert(receipt.cases.some(row=>row.origin==='donor'));
        assert.equal(receipt.cases.filter(row=>row.origin==='fresh').length,reuse.selectedSpecs(browser,affected).length+1);
      }
      assert.equal(verifyEvidence({...gateOptions,root:affectedEvidence}).receipts.length,2);
    });
    protocolCheck('missing selected execution cannot pass',()=>copiedEvidence(path.join(affectedEvidence,'chromium'),directory=>{
      mutate(path.join(directory,'visual-reports','main.json'),report=>report.suites.pop());
      assert.throws(()=>reuse.buildReceipt({root:directory,browser:'chromium',p,plan:affected,donorRoot:path.join(directory,'donor')}),/missing, duplicate or unexpected/);
    }));
    protocolCheck('PNG report compaction preserves cases and deduplicates existing screenshot bytes',()=>copiedEvidence(path.join(currentEvidence,'chromium'),directory=>{
      mutate(path.join(directory,'visual-reports','main.json'),report=>{
        report.suites[0].specs[0].tests[0].results[0].attachments=[{name:'inline PNG',contentType:'image/png',body:png.toString('base64')}];
      });
      const before=reuse.files(directory);
      reuse.compactReports(directory);
      const attachment=read(path.join(directory,'visual-reports','main.json')).suites[0].specs[0].tests[0].results[0].attachments[0];
      assert.equal(attachment.body,undefined);assert.equal(attachment.sha256,reuse.hash(png));
      assert(fs.existsSync(path.join(directory,attachment.evidencePath)));
      assert.deepEqual(reuse.files(directory),before);verify(directory,'chromium',p);
    }));
    protocolCheck('new inline PNG bytes are retained in a hashed evidence file',()=>copiedEvidence(path.join(currentEvidence,'chromium'),directory=>{
      const extra=Buffer.concat([png,Buffer.from('synthetic distinct PNG bytes')]);
      mutate(path.join(directory,'visual-reports','main.json'),report=>{
        report.suites[0].specs[0].tests[0].results[0].attachments=[{name:'new PNG',contentType:'image/png',body:extra.toString('base64')}];
      });
      reuse.compactReports(directory);
      const attachment=read(path.join(directory,'visual-reports','main.json')).suites[0].specs[0].tests[0].results[0].attachments[0];
      assert(attachment.evidencePath.startsWith('report-attachment-artifacts/'));
      assert.deepEqual(fs.readFileSync(path.join(directory,attachment.evidencePath)),extra);
      save(path.join(directory,'visual-receipt.json'),reuse.buildReceipt({root:directory,browser:'chromium',p,plan}));
      verify(directory,'chromium',p);
    }));
    protocolCheck('non-PNG report attachments retain their original body',()=>copiedEvidence(path.join(currentEvidence,'chromium'),directory=>{
      mutate(path.join(directory,'visual-reports','main.json'),report=>{
        report.suites[0].specs[0].tests[0].results[0].attachments=[{name:'text',contentType:'text/plain',body:Buffer.from('synthetic log').toString('base64')}];
      });
      reuse.compactReports(directory);
      assert.equal(read(path.join(directory,'visual-reports','main.json')).suites[0].specs[0].tests[0].results[0].attachments[0].body,Buffer.from('synthetic log').toString('base64'));
    }));
    protocolCheck('corrupt inline PNG attachment cannot become screenshot proof',()=>copiedEvidence(path.join(currentEvidence,'chromium'),directory=>{
      mutate(path.join(directory,'visual-reports','main.json'),report=>{
        report.suites[0].specs[0].tests[0].results[0].attachments=[{name:'bad PNG',contentType:'image/png',body:Buffer.from('not an image').toString('base64')}];
      });
      assert.throws(()=>reuse.compactReports(directory),/Invalid attached PNG/);
    }));
    protocolCheck('duplicate selected execution cannot pass',()=>copiedEvidence(path.join(affectedEvidence,'chromium'),directory=>{
      mutate(path.join(directory,'visual-reports','main.json'),report=>report.suites.push(clone(report.suites[0])));
      assert.throws(()=>reuse.buildReceipt({root:directory,browser:'chromium',p,plan:affected,donorRoot:path.join(directory,'donor')}),/Duplicate Playwright case/);
    }));
    protocolCheck('missing daily cache execution cannot pass',()=>copiedEvidence(path.join(currentEvidence,'chromium'),directory=>{
      mutate(path.join(directory,'visual-reports','cache.json'),report=>{report.suites=[];});
      assert.throws(()=>verify(directory,'chromium',p),/Missing expected/);
    }));
    protocolCheck('missing cache from the listing cannot shrink the expected inventory',()=>copiedEvidence(path.join(currentEvidence,'chromium'),directory=>{
      mutate(path.join(directory,'visual-reports','expected.json'),report=>report.suites.shift());
      assert.throws(()=>verify(directory,'chromium',p),/Missing expected/);
    }));
    protocolCheck('mutated current PNG bytes invalidate the immutable screenshot receipt',()=>copiedEvidence(path.join(affectedEvidence,'chromium'),directory=>{
      fs.appendFileSync(path.join(directory,'visual-artifacts','0.png'),'changed');
      assert.throws(()=>verify(directory,'chromium',p),/screenshot evidence/);
    }));
    protocolCheck('mutated donor PNG bytes invalidate reused evidence',()=>copiedEvidence(path.join(affectedEvidence,'chromium'),directory=>{
      fs.appendFileSync(path.join(directory,'donor','visual-artifacts','0.png'),'changed');
      assert.throws(()=>verify(directory,'chromium',p),/screenshot evidence/);
    }));
    protocolCheck('missing unchanged donor case fails even with self-consistent report digests',()=>copiedEvidence(path.join(affectedEvidence,'chromium'),directory=>{
      const donor=path.join(directory,'donor');
      mutate(path.join(donor,'visual-reports','main.json'),report=>report.suites.pop());
      const fresh=['cache','main'].flatMap(name=>inventory.collectCases(read(path.join(donor,'visual-reports',name+'.json')),{browser:'chromium'}));
      mutate(path.join(donor,'visual-receipt.json'),receipt=>{
        receipt.freshDigest=reuse.hash(JSON.stringify(fresh));
        receipt.cases=fresh.map(row=>({...row,origin:'fresh'})).sort((a,b)=>a.key.localeCompare(b.key));
      });
      assert.throws(()=>verify(directory,'chromium',p),/Donor full execution/);
    }));
    protocolCheck('an unselected case title change cannot inherit the old donor identity',()=>copiedEvidence(path.join(affectedEvidence,'chromium'),directory=>{
      mutate(path.join(directory,'visual-reports','expected.json'),report=>{report.suites.find(suite=>!reuse.selectedSpecs('chromium',affected).includes(suite.file)&&suite.file!==inventory.allSpecs('chromium')[0]).specs[0].title+=' changed unexpectedly';});
      assert.throws(()=>reuse.buildReceipt({root:directory,browser:'chromium',p,plan:affected,donorRoot:path.join(directory,'donor')}),/Unchanged donor coverage/);
    }));
    for(const [name,change] of [
      ['foreign PR',meta=>{meta.prNumber='992';}],['foreign repository',meta=>{meta.repository='another/repo';}],
      ['future run',meta=>{meta.runId=p.runId;}],['wrong artifact name',meta=>{meta.artifactName+='-other';}],
      ['unverifiable digest',meta=>{meta.artifactDigest='unverified';}],['failed workflow',meta=>{meta.conclusion='failure';}],
    ])protocolCheck(name+' donor fails closed to FULL',()=>{
      const bad=clone(metadata);change(bad);assert.equal(reuse.planReuse({root:sourceRoot,baseline,metadata:bad,p}).mode,'full');
      assert.throws(()=>reuse.validateDonor(bad,p));
    });
    protocolCheck('swapped donor browser cannot attest the current engine',()=>copiedEvidence(path.join(affectedEvidence,'chromium'),directory=>{
      mutate(path.join(directory,'donor','visual-receipt.json'),receipt=>{receipt.browser='webkit';});
      assert.throws(()=>verify(directory,'chromium',p),/browser evidence/);
    }));
    protocolCheck('partial donors cannot become recursively trusted FULL donors',()=>copiedEvidence(baseline,directory=>{
      mutate(path.join(directory,'chromium','visual-receipt.json'),receipt=>{receipt.mode='affected';receipt.donor=metadata;});
      assert.equal(reuse.planReuse({root:sourceRoot,baseline:directory,metadata,p}).mode,'full');
    }));
    protocolCheck('actual browser runner drift prepares a safe FULL execution before tests',()=>{
      save(path.join(sourceRoot,'selection-plan.json'),affected);
      fs.cpSync(baseline,path.join(sourceRoot,'baseline'),{recursive:true});
      // Execute the original CLI implementation with only its fixture inventory
      // pin substituted in a standalone temporary module, never production.
      const fixtureCLI=path.join(temporary,'fixture-cli');
      write(path.join(fixtureCLI,'manager2_visual_inventory.js'),fixtureInventorySource);
      write(path.join(fixtureCLI,'manager2_visual_reuse.js'),reuseSource);
      execFileSync(process.execPath,[path.join(fixtureCLI,'manager2_visual_reuse.js'),'prepare','chromium'],{
        cwd:sourceRoot,env:{PATH:process.env.PATH,...env,ImageVersion:'different-actual-browser-runner',QA_TESTED_SHA:currentSha,QA_HEAD_SHA:currentSha,QA_RUN_ID:p.runId,QA_RUN_ATTEMPT:'1'},stdio:['ignore','pipe','pipe'],
      });
      const prepared=read(path.join(sourceRoot,'selection-plan.json'));
      assert.equal(prepared.mode,'full');assert.equal(prepared.donor,null);assert.deepEqual(prepared.changedSpecs,[]);
      assert.match(prepared.reason,/environment differs/);
    });
    protocolCheck('tracked dirty source is rejected despite an unchanged Git object manifest',()=>{
      const file=path.join(sourceRoot,'manager','shared.js'),original=fs.readFileSync(file);
      try{fs.appendFileSync(file,'// dirty\n');assert.throws(()=>reuse.validatePlan(plan,p,sourceRoot),/worktree\/index/);assert.throws(()=>verify(path.join(currentEvidence,'chromium'),'chromium',p),/worktree\/index/);}
      finally{fs.writeFileSync(file,original);}
    });
    protocolCheck('untracked executable fixture cannot escape clean-source attestation',()=>{
      const file=path.join(sourceRoot,'tests','fixtures','untracked-protocol.js');write(file,'// untracked\n');
      try{assert.throws(()=>reuse.validatePlan(plan,p,sourceRoot),/Untracked executable/);}finally{fs.unlinkSync(file);}
    });
    // Exercise the real ZIP verifier without network or a GitHub artifact. All
    // archives here are intentionally synthetic and confined to this fixture.
    const archiveScript=`
import hashlib, importlib.util, pathlib, stat, sys, tempfile, warnings, zipfile
sys.dont_write_bytecode=True
spec=importlib.util.spec_from_file_location('manager2_artifact',sys.argv[1])
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
directory=pathlib.Path(tempfile.mkdtemp(prefix='zip-fixture-',dir=sys.argv[2]))
archive=directory/'evidence.zip';destination=directory/'extracted';kind=sys.argv[3]
with warnings.catch_warnings():
 warnings.simplefilter('ignore',UserWarning)
 with zipfile.ZipFile(archive,'w') as zipped:
  if kind=='traversal': zipped.writestr('../escaped.txt','unsafe')
  elif kind=='symlink':
   entry=zipfile.ZipInfo('chromium/link');entry.create_system=3;entry.external_attr=(stat.S_IFLNK|0o777)<<16
   zipped.writestr(entry,'../../escaped.txt')
  elif kind=='duplicate':
   zipped.writestr('chromium/receipt.json','first');zipped.writestr('chromium/receipt.json','second')
  else: zipped.writestr('chromium/receipt.json','synthetic protocol fixture')
digest='sha256:'+hashlib.sha256(archive.read_bytes()).hexdigest()
if kind=='wrong-digest':digest='sha256:'+'0'*64
if kind=='existing':destination.mkdir()
if kind=='valid':
 module.extract_verified(archive,destination,digest)
 assert (destination/'chromium/receipt.json').read_text()=='synthetic protocol fixture'
else:
 try:module.extract_verified(archive,destination,digest)
 except (ValueError,FileExistsError):pass
 else:raise AssertionError('Unsafe archive accepted: '+kind)
 assert not (directory/'escaped.txt').exists()
 if kind!='existing':assert not destination.exists()
assert not list(directory.glob('manager2-donor-*'))
`;
    for(const kind of ['valid','wrong-digest','traversal','symlink','duplicate','existing']){
      protocolCheck('immutable ZIP '+kind+' validation',()=>execFileSync('python3',['-c',archiveScript,path.join(repositoryRoot,'tools','manager2_visual_artifact.py'),temporary,kind],{stdio:['ignore','pipe','pipe']}));
    }
  } finally {fs.rmSync(temporary,{recursive:true,force:true});}
}
