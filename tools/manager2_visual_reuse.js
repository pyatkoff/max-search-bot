#!/usr/bin/env node
'use strict';

// This is deliberately a spec-only optimization, not a general dependency
// guesser. A product/shared/unknown change always takes the original full path.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const cp = require('node:child_process');
const inventory = require('./manager2_visual_inventory.js');
const OUTPUT_DIRS = ['visual-artifacts','send-feedback-artifacts',
  'notification-diagnostic-artifacts','conversation-scroll-artifacts',
  'emoji-picker-artifacts','timezone-artifacts','manager2-artifacts','manager-media-artifacts',
  'report-attachment-artifacts'];
const WORKFLOW = '.github/workflows/workspace-v2-visual-qa.yml';
const SHA = /^[a-f0-9]{40}$/;
const ID = /^[1-9][0-9]*$/;
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const json = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const save = (file, value) => {
  fs.mkdirSync(path.dirname(file), {recursive:true});
  fs.writeFileSync(file, JSON.stringify(value, null, 2)+'\n');
};
function check(condition, message) { if (!condition) throw new Error(message); }
function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
function compactReports(root, names=['cache','main']) {
  // Preserve PNG attachments as hashed files rather than base64-duplicating
  // tens of megabytes inside JSON. Test identities/results/errors stay intact.
  const known=new Map(files(root).map(file=>[file.sha256,file.path]));
  function visit(suite) {
    for (const spec of suite.specs||[]) for (const test of spec.tests||[]) {
      for (const result of test.results||[]) for (const attachment of result.attachments||[]) {
        if (typeof attachment.body!=='string') continue;
        if (attachment.contentType!=='image/png') continue;
        const bytes=Buffer.from(attachment.body,'base64'), digest=hash(bytes);
        if (attachment.contentType==='image/png') {
          check(bytes.subarray(0,8).equals(Buffer.from('89504e470d0a1a0a','hex')), 'Invalid attached PNG');
          if (!known.has(digest)) {
            const name='report-attachment-artifacts/'+digest+'.png';
            fs.mkdirSync(path.join(root,'report-attachment-artifacts'),{recursive:true});
            fs.writeFileSync(path.join(root,name),bytes,{flag:'wx'});known.set(digest,name);
          }
          attachment.evidencePath=known.get(digest);
        }
        attachment.sha256=digest;
        delete attachment.body;
      }
    }
    for (const child of suite.suites||[]) visit(child);
  }
  for (const name of names) {
    const file=path.join(root,'visual-reports',name+'.json'), report=json(file);
    for (const suite of report.suites||[]) visit(suite);
    save(file,report);
  }
}
function files(root) {
  const result=[];
  function visit(directory) {
    if (!fs.existsSync(directory)) return;
    for (const item of fs.readdirSync(directory, {withFileTypes:true})) {
      const file=path.join(directory,item.name);
      check(!item.isSymbolicLink(), 'Evidence may not contain symlinks');
      if (item.isDirectory()) visit(file);
      else if (item.isFile() && item.name.endsWith('.png')) {
        const bytes=fs.readFileSync(file);
        check(bytes.subarray(0,8).equals(Buffer.from('89504e470d0a1a0a','hex')), 'Invalid PNG: '+file);
        result.push({path:path.relative(root,file).split(path.sep).join('/'),size:bytes.length,sha256:hash(bytes)});
      }
    }
  }
  for (const directory of OUTPUT_DIRS) visit(path.join(root,directory));
  return result.sort((a,b)=>a.path.localeCompare(b.path));
}
function provenance(env) {
  inventory.assertSafeEnvironment(env);
  const p={testedSha:env.QA_TESTED_SHA,headSha:env.QA_HEAD_SHA,
    runId:String(env.QA_RUN_ID||''),runAttempt:String(env.QA_RUN_ATTEMPT||''),
    repository:env.GITHUB_REPOSITORY,prNumber:String(env.QA_PR_NUMBER||''),
    workflow:WORKFLOW,execution:{node:process.version,platform:process.platform,arch:process.arch,
      imageOS:env.ImageOS||'',imageVersion:env.ImageVersion||''}};
  check(SHA.test(p.testedSha||'')&&SHA.test(p.headSha||'')&&ID.test(p.runId)&&ID.test(p.runAttempt), 'Exact current SHA/run provenance is required');
  check(p.repository==='pyatkoff/max-search-bot', 'Unexpected repository');
  check(p.prNumber===''||ID.test(p.prNumber), 'Invalid pull request identity');
  return p;
}
function validateDonor(meta, p) {
  check(meta && meta.repository===p.repository && meta.prNumber===p.prNumber && ID.test(p.prNumber), 'Donor must belong to this same repository and PR');
  check(meta.workflow===WORKFLOW && meta.status==='completed' && meta.conclusion==='success' && meta.event==='pull_request', 'Only successful completed full PR workflows are donors');
  check(ID.test(String(meta.runId))&&ID.test(String(meta.runAttempt))&&BigInt(meta.runId)<BigInt(p.runId), 'Invalid donor run provenance');
  check(SHA.test(meta.headSha||'')&&SHA.test(meta.testedSha||''), 'Invalid donor SHA');
  check(ID.test(String(meta.artifactId))&&/^sha256:[a-f0-9]{64}$/.test(meta.artifactDigest||''), 'Immutable donor artifact identity and digest required');
  check(meta.artifactName==='workspace-v2-visual-'+meta.testedSha, 'Donor artifact name does not match its tested SHA');
}
function selectedSpecs(browser, plan) {
  return plan.mode==='affected'
    ? inventory.mainSpecs(browser).filter(spec=>inventory.alwaysSpecs(browser).includes(spec)||plan.changedSpecs.includes(spec))
    : inventory.mainSpecs(browser);
}
function reports(root, browser, listing=false) {
  if (listing) return inventory.collectCases(json(path.join(root,'visual-reports','expected.json')), {browser,listing:true,expectedFiles:inventory.allSpecs(browser)});
  return ['cache','main'].flatMap(name=>inventory.collectCases(json(path.join(root,'visual-reports',name+'.json')), {browser}));
}
function exactCases(actual, expected, label) {
  const keys=actual.map(c=>c.key).sort(), wanted=expected.map(c=>c.key).sort();
  check(new Set(keys).size===keys.length && same(keys,wanted), label+' has missing, duplicate or unexpected cases');
}
function validatePNGAttachments(report,artifacts) {
  const available=new Map(artifacts.map(file=>[file.path,file.sha256]));
  function visit(suite) {
    for(const spec of suite.specs||[])for(const test of spec.tests||[])for(const result of test.results||[])for(const attachment of result.attachments||[]) {
      if(attachment.contentType!=='image/png')continue;
      check(attachment.body===undefined&&typeof attachment.evidencePath==='string'&&/^[a-f0-9]{64}$/.test(attachment.sha256||'')&&available.get(attachment.evidencePath)===attachment.sha256,'PNG attachment lacks matching owned screenshot evidence');
    }
    for(const child of suite.suites||[])visit(child);
  }
  for(const suite of report.suites||[])visit(suite);
}
function basicReceipt(root, browser, expectedProvenance) {
  const receipt=json(path.join(root,'visual-receipt.json'));
  check(receipt.schema===inventory.SCHEMA && receipt.completed===true && receipt.browser===browser, 'Missing complete schema-2 browser evidence');
  if (receipt.inputs?.entries?.some(entry=>entry.path==='tools/manager2_visual_shards.js')) {
    check(receipt.partitionProtocol==='manager2-partition-v1', 'Partition-enabled source requires its original partition provenance');
  }
  if (!receipt.partitionProtocol) check(!receipt.executionRole&&!receipt.partitionCount&&!receipt.partitionDigest&&!fs.existsSync(path.join(root,'partition-proof')), 'Partition evidence cannot downgrade to an unsplit receipt');
  for (const [key,value] of Object.entries(expectedProvenance)) {
    if (key!=='execution') check(same(receipt[key],value), 'Browser receipt provenance mismatch: '+key);
  }
  check(receipt.execution&&typeof receipt.execution.node==='string'&&
    typeof receipt.execution.imageVersion==='string', 'Missing actual browser runner identity');
  check(same(receipt.toolchain,inventory.TOOLCHAIN), 'Browser toolchain mismatch');
  const expected=reports(root,browser,true), fresh=reports(root,browser);
  const actualFiles=files(root);
  for(const name of ['cache','main'])validatePNGAttachments(json(path.join(root,'visual-reports',name+'.json')),actualFiles);
  check(actualFiles.length>0&&same(actualFiles,receipt.files), 'Missing, changed or unrecorded screenshot evidence');
  const captureFiles=actualFiles.filter(file=>file.path.startsWith('visual-artifacts/'));
  check(captureFiles.length===(browser==='chromium'?34:0), 'Current capture inventory is incomplete');
  check(receipt.expectedDigest===hash(JSON.stringify(expected)), 'Case inventory digest mismatch');
  check(receipt.freshDigest===hash(JSON.stringify(fresh)), 'Actual execution digest mismatch');
  check(same(receipt.expectedCases,expected), 'Expected case inventory differs from the collected source');
  return {receipt,expected,fresh};
}
function verifiedFullDonor(root, browser, meta, p) {
  validateDonor(meta,p);
  const origin={testedSha:meta.testedSha,headSha:meta.headSha,runId:String(meta.runId),runAttempt:String(meta.runAttempt),
    repository:meta.repository,prNumber:meta.prNumber,workflow:WORKFLOW};
  const result=basicReceipt(root,browser,origin);
  check(result.receipt.mode==='full'&&!result.receipt.donor, 'Composite or partial donors are not supported');
  if (result.receipt.partitionProtocol) {
    const validated=require('./manager2_visual_shards.js').verifyPartitionedEvidence({root,browser,p:origin});
    check(validated.parts.every(part=>same(part.execution,p.execution)), 'Donor runner/Node environment differs');
  } else check(same(result.receipt.execution,p.execution), 'Donor runner/Node environment differs');
  exactCases(result.fresh,result.expected,'Donor full execution');
  check(same(result.receipt.cases,result.fresh.map(c=>({...c,origin:'fresh'})).sort((a,b)=>a.key.localeCompare(b.key))), 'Donor case provenance is incomplete');
  return result;
}
function planReuse({root,baseline,metadata,p}) {
  const current=inventory.sourceManifest(root,p.testedSha);
  const full=reason=>({schema:inventory.SCHEMA,...p,mode:'full',reason,changedSpecs:[],inputs:current,donor:null});
  try {
    if (!baseline || !metadata || !p.prNumber) return full('No eligible same-PR full donor');
    validateDonor(metadata,p);
    cp.execFileSync('git',['merge-base','--is-ancestor',metadata.headSha,p.headSha],{cwd:root,stdio:'pipe'});
    const donors=['chromium','webkit'].map(browser=>verifiedFullDonor(path.join(baseline,browser),browser,metadata,p));
    check(same(donors[0].receipt.inputs,donors[1].receipt.inputs), 'Donor engines tested different inputs');
    const selection=inventory.classifyChanges(donors[0].receipt.inputs,current);
    if (selection.mode!=='affected') return full(selection.reason);
    return {schema:inventory.SCHEMA,...p,...selection,inputs:current,donor:metadata};
  } catch (error) {
    // A missing/expired/unprovable donor is not a failed test. Run every test.
    return full('Unusable donor: '+error.message.slice(0,200));
  }
}
function validatePlan(plan,p,root) {
  inventory.assertCleanSource(root,p.testedSha);
  check(plan.schema===inventory.SCHEMA && ['full','affected'].includes(plan.mode), 'Invalid selection plan');
  // Selection and execution run on separate runners. Their environment is not
  // proof of browser execution; a browser's actual environment is recorded below.
  for (const [key,value] of Object.entries(p)) {
    if (key!=='execution') check(same(plan[key],value), 'Plan belongs to another version/run: '+key);
  }
  check(same(plan.inputs,inventory.sourceManifest(root,p.testedSha)), 'Current tracked source differs from planned inputs');
  if (plan.mode==='affected') validateDonor(plan.donor,p);
  return plan;
}
function buildReceipt({root,browser,plan,p,donorRoot,donorExecution=p.execution}) {
  check(['chromium','webkit'].includes(browser), 'Unknown browser');
  const expected=reports(root,browser,true), fresh=reports(root,browser);
  const selected=[...selectedSpecs(browser,plan),'tests/visual/manager-avatar-cache.spec.js'];
  const selectedExpected=expected.filter(c=>selected.includes(c.spec));
  exactCases(fresh,selectedExpected,'Fresh selected execution');
  let cases=fresh.map(c=>({...c,origin:'fresh'}));
  if (plan.mode==='affected') {
    // A partition assembler is not a browser executor. Its caller supplies the
    // validated common current browser-part environment for donor compatibility.
    const prior=verifiedFullDonor(donorRoot,browser,plan.donor,{...p,execution:donorExecution});
    const selection=inventory.classifyChanges(prior.receipt.inputs,plan.inputs);
    check(selection.mode==='affected'&&same(selection.changedSpecs,plan.changedSpecs), 'Donor dependency closure differs from the selection plan');
    const reusable=prior.fresh.filter(c=>!selected.includes(c.spec));
    exactCases(reusable,expected.filter(c=>!selected.includes(c.spec)),'Unchanged donor coverage');
    cases.push(...reusable.map(c=>({...c,origin:'donor'})));
  }
  exactCases(cases,expected,'Complete fresh plus unchanged execution');
  cases.sort((a,b)=>a.key.localeCompare(b.key));
  const artifacts=files(root);
  check(artifacts.length>0&&artifacts.filter(f=>f.path.startsWith('visual-artifacts/')).length===(browser==='chromium'?34:0), 'Missing current responsive screenshots');
  return {schema:inventory.SCHEMA,...p,browser,mode:plan.mode,toolchain:inventory.TOOLCHAIN,
    inputs:plan.inputs,changedSpecs:plan.changedSpecs,donor:plan.donor,
    mainSpecCount:inventory.mainSpecs(browser).length,specCount:inventory.allSpecs(browser).length,captureCount:browser==='chromium'?34:0,
    completed:true,expectedCases:expected,expectedDigest:hash(JSON.stringify(expected)),
    freshDigest:hash(JSON.stringify(fresh)),cases,files:artifacts};
}
function verifyBrowserEvidence({root,browser,p,sourceRoot}) {
  if (json(path.join(root,'visual-receipt.json')).partitionProtocol) {
    return require('./manager2_visual_shards.js').verifyPartitionedEvidence({root,browser,p,sourceRoot}).receipt;
  }
  const current=basicReceipt(root,browser,p);
  const receipt=current.receipt;
  check(sourceRoot&&same(receipt.inputs,inventory.sourceManifest(sourceRoot,p.testedSha)), 'Receipt inputs do not match the current checked-out source');
  inventory.assertCleanSource(sourceRoot,p.testedSha);
  const actualP={...p,execution:receipt.execution};
  const plan={schema:inventory.SCHEMA,...actualP,mode:receipt.mode,inputs:receipt.inputs,
    changedSpecs:receipt.changedSpecs,donor:receipt.donor};
  const rebuilt=buildReceipt({root,browser,plan,p:actualP,donorRoot:path.join(root,'donor')});
  check(same(receipt,rebuilt), 'Browser execution, coverage or provenance envelope is invalid');
  return receipt;
}
function runPlaywright(root,browser,specs,{listing=false,report}) {
  const args=['playwright','test',...specs,'--browser='+browser,'--workers=1','--reporter='+ (listing?'json':'line,json')];
  if (listing) args.push('--list');
  const destination=path.join(root,'visual-reports',report+'.json');
  fs.mkdirSync(path.dirname(destination),{recursive:true});
  cp.execFileSync('npx',args,{cwd:root,env:{...process.env,PLAYWRIGHT_JSON_OUTPUT_NAME:destination},stdio:listing?['ignore','pipe','inherit']:'inherit'});
}
if (require.main===module) {
  try {
    const [command,browserArg]=process.argv.slice(2), root=process.cwd(), p=provenance(process.env);
    const planFile=path.join(root,'selection-plan.json');
    if (command==='plan') {
      const metadata=fs.existsSync('donor-meta.json')?json('donor-meta.json'):null;
      const plan=planReuse({root,baseline:path.join(root,'baseline'),metadata,p});
      save(planFile,plan);
      process.stdout.write(plan.mode+': '+plan.reason+'\n');
      if (process.env.GITHUB_OUTPUT) fs.appendFileSync(process.env.GITHUB_OUTPUT,'mode='+plan.mode+'\n');
    } else {
      const browser=browserArg||process.env.QA_BROWSER;
      const plan=validatePlan(json(planFile),p,root);
      if (command==='prepare') {
        if (plan.mode==='affected') {
          try { verifiedFullDonor(path.join(root,'baseline',browser),browser,plan.donor,p); }
          catch(error) {
            if (error.message!=='Donor runner/Node environment differs') throw error;
            plan.mode='full';plan.donor=null;plan.changedSpecs=[];plan.reason=error.message;
          }
        }
        save(planFile,plan);
        if (process.env.GITHUB_OUTPUT) fs.appendFileSync(process.env.GITHUB_OUTPUT,'mode='+plan.mode+'\n');
      }
      else if (command==='inventory') runPlaywright(root,browser,inventory.allSpecs(browser),{listing:true,report:'expected'});
      else if (command==='run-selected') runPlaywright(root,browser,selectedSpecs(browser,plan),{report:'main'});
      else if (command==='record') {
        compactReports(root);
        const baseline=path.join(root,'baseline',browser), destination=path.join(root,'donor');
        if (plan.mode==='affected') {
          check(!fs.existsSync(destination), 'Donor namespace must be fresh');
          verifiedFullDonor(baseline,browser,plan.donor,p);
          fs.cpSync(baseline,destination,{recursive:true,dereference:false,errorOnExist:true});
        }
        const receipt=buildReceipt({root,browser,plan,p,donorRoot:destination});
        save(path.join(root,'visual-receipt.json'),receipt);
        process.stdout.write(browser+': '+receipt.cases.length+' exact cases; '+receipt.cases.filter(c=>c.origin==='fresh').length+' executed now; '+receipt.cases.filter(c=>c.origin==='donor').length+' retained with original provenance\n');
      } else throw new Error('Unknown command');
    }
  } catch (error) { process.stderr.write(error.stack+'\n'); process.exitCode=1; }
}
module.exports={OUTPUT_DIRS,hash,files,compactReports,provenance,validateDonor,selectedSpecs,exactCases,validatePNGAttachments,reports,basicReceipt,runPlaywright,planReuse,validatePlan,buildReceipt,verifyBrowserEvidence,verifiedFullDonor};
