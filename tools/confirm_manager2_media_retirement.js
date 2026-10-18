#!/usr/bin/env node
'use strict';
// Run the unchanged whole-path protocol on the exact reviewed candidate asset.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict'),Module=require('node:module');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),out=path.resolve('media-confirmation');fs.mkdirSync(out,{recursive:true});
const product='d0e3a8d25708b0a29ab48351a14e778a42cc6b6b',candidate='ea282a30bf13a39ab1e0f10dd2d7f598c5e33580';
const hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const git=(...args)=>execFileSync('git',args,{cwd:root,encoding:'utf8'});
const relative='manager/assets/workspace-v2-conversation.js',original=git('show',product+':'+relative),asset=git('show',candidate+':'+relative);
assert.deepEqual(git('diff','--name-only',product,candidate).trim().split('\n').sort(),[relative,'tests/run_manager_visible_conversation_behavior_regression.js'].sort());
assert.ok(asset.includes('function retireMessageMedia(row)'));
fs.writeFileSync(path.join(out,'executed-conversation.js'),asset);
process.argv.push('--engine','webkit','--widths','768','--samples','30','--warmups','3','--cold','3','--product-sha',product,'--assets',path.resolve('installed'),'--output',out);
const fixturePath=path.join(__dirname,'fixtures/manager2_browser_measurement.js'),fixture=require(fixturePath),setup=fixture.setup;
fixture.setup=(page,options={})=>setup(page,{...options,beforeNavigate:async p=>{
 await p.route('**/manager/assets/workspace-v2-conversation.js*',route=>route.fulfill({contentType:'application/javascript',body:asset}));
 if(options.beforeNavigate)await options.beforeNavigate(p);
}});
const file=path.join(__dirname,'measure_manager2_browser.js'),source=fs.readFileSync(file,'utf8'),tail=source.lastIndexOf('main().catch(');assert.ok(tail>0);
const purpose="purpose:samples===30&&warmups===3&&cold===3?'baseline':'protocol_check'",closing="finally{lastOperation='close_browser';await browser.close()}";
assert.equal(source.split(purpose).length,2);assert.equal(source.split(closing).length,2);
// Native actions, meter, sample counts and time limits stay unchanged. The
// label records the candidate interception; browser shutdown gets the same
// existing15s bound as other driver actions, without turning a failure green.
const evaluated=source.slice(0,tail).replace(purpose,"purpose:'candidate_confirmation'").replace(closing,"finally{lastOperation='close_browser';await bounded(()=>browser.close(),'close_browser')}")+'\nmodule.exports={main};\n';
const metadata={purpose:'candidate_confirmation',installedProduction:product,executedCandidate:candidate,scope:'Only the reviewed conversation asset is intercepted; unchanged installed HTML/other assets, synthetic APIs and original14-phase protocol. Not installed-production/SLA/P95 proof.',hashes:{originalConversation:hash(original),executedConversation:hash(asset),originalDriver:hash(source),evaluatedDriver:hash(evaluated),originalFixture:hash(fs.readFileSync(fixturePath)),confirmationScript:hash(fs.readFileSync(__filename))},method:{engine:'webkit',viewport:'768x844',freshContexts:3,discardedWarmupCycles:3,measuredCycles:30,expectedRecords:423,cleanupTimeoutMs:15000,experimentTimeoutMs:420000}};
fs.writeFileSync(path.join(out,'candidate-control.json'),JSON.stringify(metadata,null,2)+'\n');
const driver=new Module(file,module);driver.filename=file;driver.paths=Module._nodeModulePaths(__dirname);driver._compile(evaluated,file);
const watchdog=setTimeout(()=>{fs.writeFileSync(path.join(out,'confirmation-watchdog.json'),JSON.stringify({error:'7-minute candidate confirmation deadline'}));process.exit(1)},420000);
(async()=>{try{
 await driver.exports.main();
 const summary=JSON.parse(fs.readFileSync(path.join(out,'summary.json')));
 assert.equal(summary.ok,true);assert.equal(summary.purpose,'candidate_confirmation');assert.equal(summary.raw_records,423);
 process.stdout.write('EXACT_CANDIDATE_CONFIRMATION '+candidate+' PASS423 including clean browser shutdown\n');
}finally{clearTimeout(watchdog)}})().catch(error=>{fs.writeFileSync(path.join(out,'confirmation-error.json'),JSON.stringify({error:error.message,stack:error.stack},null,2));process.stderr.write(error.stack+'\n');process.exit(1)});
