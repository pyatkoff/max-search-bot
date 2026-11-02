'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
const PRODUCT_SHA='adb00d866c6c816964c1cd0ba1a2c5b208d89e9a';
const PRODUCT_TREE='b9bba3116eed6d8b66796f344a8ea4472184dc81';
const IMAGE='ghcr.io/pyatkoff/max-search-bot-browser-qa@sha256:7b95d1abd4105d9fa3d2af76a7e3c832e5bfe6afe43d091bbe8cdc18814a9c5a';
const RECIPE='e77c1cde26eef84d94fbd64790fe49907f2c306ec792e9677271e8cc114b3c16';
const CASE_TITLE='current-trip panel closes on conversation and account change, ignores late profile reads';
const ORIGINAL_CONTEXT_SHA='a34e4b37a07533be182e136f43147970aa7d51748fb10d2ed41200c986480e3e';
const sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
function inspectSource(root){
 const issues=[];
 const git=(...args)=>{const r=cp.spawnSync('git',['-c','safe.directory='+root,'-C',root,...args],{encoding:'utf8'});if(r.status!==0){issues.push('git '+args[0]+' failed');return ''}return r.stdout.trim()};
 const commit=git('rev-parse','HEAD'),tree=git('rev-parse','HEAD^{tree}');
 const dirty=git('status','--porcelain=v1','--untracked-files=all','--ignored=matching');
 if(commit!==PRODUCT_SHA)issues.push('Product commit mismatch');
 if(tree!==PRODUCT_TREE)issues.push('Product tree mismatch');
 if(dirty)issues.push('Product tracked/untracked/ignored files changed');
 return {commit,tree,clean:!dirty,dirty,issues};
}
function manifest(output){
 const files=[];
 const walk=folder=>{for(const entry of fs.readdirSync(folder,{withFileTypes:true})){const file=path.join(folder,entry.name);if(entry.isDirectory())walk(file);else if(entry.isFile()&&entry.name!=='artifact-manifest.json'){const bytes=fs.readFileSync(file);files.push({path:path.relative(output,file).split(path.sep).join('/'),bytes:bytes.length,sha256:sha(bytes)})}}};
 walk(output);fs.writeFileSync(path.join(output,'artifact-manifest.json'),JSON.stringify({files},null,2)+'\n');
}
function inspectEvidence(output){
 const files=[];const walk=folder=>{for(const item of fs.readdirSync(folder,{withFileTypes:true})){const file=path.join(folder,item.name);if(item.isDirectory())walk(file);else files.push(file)}};walk(output);
 const issues=[],trace=files.find(file=>path.basename(file)==='trace.zip');
 if(!trace)issues.push('Trace ZIP missing');
 if(!files.some(file=>path.basename(file)==='overlay-causal-events.json'))issues.push('Causal events missing');
 if(!files.some(file=>file.endsWith('.png')))issues.push('Screenshot missing');
 if(!fs.existsSync(path.join(output,'playwright-result.json')))issues.push('Original test result missing');
 if(trace){
  const check=cp.spawnSync('python3',['-c','import json,sys,zipfile; z=zipfile.ZipFile(sys.argv[1]); names=z.namelist(); network=[n for n in names if n.endswith(".network")]; print(json.dumps({"network_entries":network,"network_bytes":sum(z.getinfo(n).file_size for n in network)})); sys.exit(0 if network and sum(z.getinfo(n).file_size for n in network)>0 else 1)',trace],{encoding:'utf8'});
  fs.writeFileSync(path.join(output,'trace-network-inventory.json'),check.stdout||'{}');
  if(check.status!==0)issues.push('Trace network proof missing or unreadable');
 }
 return {issues,trace:trace?path.relative(output,trace):null};
}
function readExecutionResult(output){
 const file=path.join(output,'playwright-result.json');
 if(!fs.existsSync(file))return {executedCases:null,executionCountReason:'Actual JSON result missing; execution unknown'};
 let report;
 try{report=JSON.parse(fs.readFileSync(file,'utf8'))}
 catch(error){return {executedCases:null,executionCountReason:'Actual JSON result unreadable ('+error.name+'); execution unknown'}}
 const matches=[];
 const visit=suites=>{for(const suite of suites||[]){
  for(const spec of suite.specs||[]){
   const source=String(spec.file||'').replaceAll('\\','/');
   if(spec.title===CASE_TITLE&&(source==='tests/visual/manager-ai-draft.spec.js'||source.endsWith('/tests/visual/manager-ai-draft.spec.js')))matches.push(...(spec.tests||[]));
  }
  visit(suite.suites);
 }};
 visit(report.suites);
 if(matches.length!==1)return {executedCases:null,executionCountReason:'Actual JSON has '+matches.length+' matching original test entries; execution unknown'};
 const results=Array.isArray(matches[0].results)?matches[0].results:[];
 const started=results.filter(result=>['passed','failed','timedOut','interrupted'].includes(result.status)&&Number.isInteger(result.workerIndex)&&result.workerIndex>=0);
 if(started.length>1)return {executedCases:null,executionCountReason:'Actual JSON has multiple worker results despite retries0; execution unknown'};
 return {executedCases:started.length,executionCountReason:started.length?'One worker-executed original test result in actual JSON':'Original test has no worker-executed result in actual JSON',
  actualCaseResults:results.map(result=>({status:result.status,workerIndex:result.workerIndex,retry:result.retry,startTime:result.startTime}))};
}
function execute(){
 if(process.argv.length!==3||process.argv[2]!=='--execute-once')throw new Error('Only the literal --execute-once mode is supported');
 const root=path.resolve(process.env.DIAG_PRODUCT_ROOT||''),output=path.resolve(process.env.DIAG_OUTPUT_DIR||'');
 if(!process.env.DIAG_PRODUCT_ROOT||!process.env.DIAG_OUTPUT_DIR||output===root||output.startsWith(root+path.sep))throw new Error('Evidence must be outside the fixed product checkout');
 fs.mkdirSync(output,{recursive:true});
 const provenance={diagnosticOnly:true,productPin:PRODUCT_SHA,treePin:PRODUCT_TREE,image:IMAGE,recipeSha:RECIPE,case:CASE_TITLE,retries:0,workers:1,
  runId:process.env.GITHUB_RUN_ID,runAttempt:process.env.GITHUB_RUN_ATTEMPT,diagnosticRef:process.env.GITHUB_REF,diagnosticSha:process.env.GITHUB_SHA,
  runtime:{node:process.version,platform:process.platform,arch:process.arch,php:cp.spawnSync('php',['-r','echo PHP_VERSION;'],{encoding:'utf8'}).stdout?.trim(),playwright:require('playwright/package.json').version},
  observerSha256:sha(fs.readFileSync(path.join(__dirname,'observer.cjs'))),configSha256:sha(fs.readFileSync(path.join(__dirname,'diagnostic.config.cjs'))),sourceBefore:inspectSource(root),attemptedCases:0,executedCases:0,executionCountReason:'Browser process not attempted'};
 let code=1;
 try{
  const context=fs.readFileSync(path.join(__dirname,'original-failure-context.md'));
  if(sha(context)!==ORIGINAL_CONTEXT_SHA)throw new Error('Original failure-context hash mismatch');
  fs.writeFileSync(path.join(output,'original-failure-context.md'),context);
  fs.copyFileSync(path.join(__dirname,'original-failure-metadata.json'),path.join(output,'original-failure-metadata.json'));
  const verified=cp.spawnSync(process.execPath,['/opt/manager2-qa/verify-runtime.js','--require-source'],{cwd:root,encoding:'utf8',env:process.env});
  fs.writeFileSync(path.join(output,'runtime-verifier.log'),(verified.stdout||'')+(verified.stderr||''));
  provenance.runtimeVerifier={status:verified.status,signal:verified.signal};
  if(verified.status!==0)throw new Error('Prepared runtime verification failed; no browser started');
  const pin=JSON.parse(fs.readFileSync(path.join(root,'tools/browser_qa_environment.json'),'utf8'));
  if(pin.image!==IMAGE||pin.recipeSha!==RECIPE||process.env.QA_BROWSER_IMAGE!==IMAGE)throw new Error('Prepared runtime pin mismatch');
  if(provenance.runtime.node!=='v22.18.0'||provenance.runtime.php!=='8.3.6'||provenance.runtime.playwright!=='1.55.0'||process.platform!=='linux'||process.arch!=='x64')throw new Error('Prepared runtime versions mismatch');
  if(provenance.sourceBefore.issues.length)throw new Error(provenance.sourceBefore.issues.join('; '));
  if(process.env.GITHUB_REPOSITORY!=='pyatkoff/max-search-bot'||process.env.GITHUB_REF!=='refs/heads/codex/manager2-overlay-ci-diagnostic-20261102'||process.env.GITHUB_RUN_ATTEMPT!=='1')throw new Error('Diagnostic trigger/ref/attempt mismatch');
  const cli=path.join(path.dirname(require.resolve('playwright/package.json')),'cli.js');
  const args=['test','--config',path.join(__dirname,'diagnostic.config.cjs')];
  const selection=cp.spawnSync(process.execPath,[cli,...args,'--list','--reporter=line'],{encoding:'utf8',env:process.env});
  fs.writeFileSync(path.join(output,'selection-list.log'),(selection.stdout||'')+(selection.stderr||''));
  const text=(selection.stdout||'').replace(/\x1b\[[0-9;]*m/g,'');
  if(selection.status!==0||!text.includes(CASE_TITLE)||!/^Total: 1 test in 1 file\s*$/m.test(text))throw new Error('Selection must contain exactly the one original case');
  provenance.attemptedCases=1;provenance.executedCases=null;provenance.executionCountReason='Awaiting actual JSON result';
  const run=cp.spawnSync(process.execPath,[cli,...args],{encoding:'utf8',env:process.env,maxBuffer:16*1024*1024});
  fs.writeFileSync(path.join(output,'test-console.log'),(run.stdout||'')+(run.stderr||''));
  process.stdout.write(run.stdout||'');process.stderr.write(run.stderr||'');
  provenance.process={status:run.status,signal:run.signal,error:run.error?.message};code=run.status===0?0:1;
  Object.assign(provenance,readExecutionResult(output));if(provenance.executedCases!==1)code=1;
  provenance.evidence=inspectEvidence(output);if(provenance.evidence.issues.length)code=1;
 }finally{
  provenance.sourceAfter=inspectSource(root);
  if(provenance.sourceAfter.issues.length)code=1;
  provenance.outcome=code===0?'diagnostic_pass_cause_not_automatically_confirmed':'diagnostic_failure_not_a_product_gate';
  fs.writeFileSync(path.join(output,'provenance.json'),JSON.stringify(provenance,null,2)+'\n');manifest(output);process.exitCode=code;
 }
}
module.exports={PRODUCT_SHA,PRODUCT_TREE,IMAGE,RECIPE,CASE_TITLE,inspectSource};
if(require.main===module)execute();
