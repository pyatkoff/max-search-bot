#!/usr/bin/env node
'use strict';
// Isolated, synthetic causal diagnostic. Never a production performance gate.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict'),Module=require('node:module');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),out=path.resolve('diagnostic-media'),assets=path.resolve('installed');
const product='d0e3a8d25708b0a29ab48351a14e778a42cc6b6b';
const hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
fs.mkdirSync(out,{recursive:true});
process.argv.push('--product-sha',product,'--assets',assets,'--output',out);
const meterPath=path.join(__dirname,'measure_manager2_browser.js'),meter=fs.readFileSync(meterPath,'utf8'),tail=meter.lastIndexOf('main().catch(');
assert.ok(tail>0);
const maintained=new Module(meterPath,module);maintained.filename=meterPath;maintained.paths=Module._nodeModulePaths(__dirname);
maintained._compile(meter.slice(0,tail)+'\nmodule.exports={cycle,startup,verifyInputs};\n',meterPath);
const {cycle,startup,verifyInputs}=maintained.exports,fixture=require('./fixtures/manager2_browser_measurement');
const input=verifyInputs();
const original=fs.readFileSync(path.join(assets,'manager/assets/workspace-v2-conversation.js'),'utf8');
const old='if(!retained.has(node))box.removeChild(node)';
assert.equal(original.split(old).length,2,'Removal patch must match exactly once');
const retire="if(!retained.has(node)){for(const media of node.querySelectorAll('audio,video')){window.__m2RemovedMedia=(window.__m2RemovedMedia||0)+1;media.onerror=null;media.pause();media.removeAttribute('src');media.querySelectorAll('source').forEach(source=>source.removeAttribute('src'));media.load()}box.removeChild(node)}";
const control=original.replace(old,retire);
fs.writeFileSync(path.join(out,'control-conversation.js'),control);
const bin=path.join(out,'bin');fs.mkdirSync(bin,{recursive:true});
fs.writeFileSync(path.join(bin,'php'),'#!/bin/sh\nset -eu\n[ "$#" = 1 ] && [ "$1" = manager2/index.php ]\nexec /usr/bin/cat "$M2_MEASUREMENT_HTML"\n',{mode:0o700});
process.env.PATH=bin+path.delimiter+process.env.PATH;process.env.M2_MEASUREMENT_HTML=path.join(assets,'shell.html');
const json=(name,value)=>fs.writeFileSync(path.join(out,name),JSON.stringify(value,null,2)+'\n');
const median=list=>{const a=list.slice().sort((a,b)=>a-b),n=a.length;return n%2?a[n>>1]:(a[n/2-1]+a[n/2])/2};
const raw=path.join(out,'samples.jsonl');fs.writeFileSync(raw,'');
let browser,lastOperation='startup',watchdog;
async function close(task){let timer;try{return await Promise.race([task(),new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('Bounded browser cleanup exceeded15s')),15000)})])}finally{clearTimeout(timer)}}
async function main(){
 const {webkit}=require('@playwright/test');browser=await webkit.launch({headless:true});
 json('manifest.json',{purpose:'causal_diagnostic_only',product,tooling:execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim(),datasetSha:fixture.datasetSha,input,browser:browser.version(),node:process.version,playwright:require('@playwright/test/package.json').version,viewport:{width:768,height:844},method:{pages:2,discardedCyclesPerMode:1,recordedCyclesPerMode:12,order:'alternating normal/control and control/normal by cycle',control:'Explicitly retire only audio/video belonging to rows already removed by canonical rendering',unchanged:'installed source,14-phase cycle,meter,transport,data,role,cache policy and timeouts',productionAPI_SQL_P95_SLA:'not_measured'},hashes:{diagnostic:hash(fs.readFileSync(__filename)),meter:hash(meter),fixture:hash(fs.readFileSync(path.join(__dirname,'fixtures/manager2_browser_measurement.js'))),originalConversation:hash(original),controlConversation:hash(control)}});
 watchdog=setTimeout(()=>{json('watchdog-failure.json',{lastOperation,time:new Date().toISOString()});process.exit(1)},420000);
 const pages={};
 try{
  for(const mode of ['normal','retire_detached_media']){
   const context=await browser.newContext({viewport:{width:768,height:844},deviceScaleFactor:1,timezoneId:'Europe/Moscow'}),page=await context.newPage();
   page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(15000);await page.addInitScript(fixture.installMeter);
   const state=await fixture.setup(page,{sourceRoot:assets,beforeNavigate:async p=>{
    if(mode==='retire_detached_media')await p.route('**/manager/assets/workspace-v2-conversation.js*',route=>route.fulfill({contentType:'application/javascript',body:control}));
   }});
   await startup(page);pages[mode]={context,page,state};
  }
  const records=[];
  for(let index=-1;index<12;index++){
   const order=(index+1)%2?['retire_detached_media','normal']:['normal','retire_detached_media'];
   for(const mode of order){
    lastOperation=mode+'/cycle'+index;const {page,state}=pages[mode];await page.bringToFront();
    const result=await cycle(page,state,index);
    const counts=await page.evaluate(()=>({connectedMedia:document.querySelectorAll('#messages audio,#messages video').length,removedMedia:window.__m2RemovedMedia||0}));
    if(index>=0)for(const record of result){const row={...record,mode,mediaCounts:counts,viewport:{width:768,height:844},engine:'webkit'};records.push(row);fs.appendFileSync(raw,JSON.stringify(row)+'\n')}
    const open=result.find(r=>r.name==='open_authorized_reply');
    process.stdout.write(JSON.stringify({index,mode,open_ms:open.duration,transcript_ms:open.milestones.transcript-open.start,...counts})+'\n');
   }
  }
  assert.equal(records.length,336);
  const summary={ok:true,purpose:'causal_diagnostic_only',raw_records:records.length,raw_sha256:hash(fs.readFileSync(raw)),modes:{}};
  for(const mode of ['normal','retire_detached_media']){
   const list=records.filter(r=>r.mode===mode&&r.name==='open_authorized_reply');
   summary.modes[mode]={n:list.length,median_open_ms:median(list.map(r=>r.duration)),first4_median_ms:median(list.slice(0,4).map(r=>r.duration)),last4_median_ms:median(list.slice(-4).map(r=>r.duration)),observations:list.map(r=>({cycle:r.sample,open:r.duration,transcript:r.milestones.transcript-r.start,counts:r.mediaCounts}))};
   await pages[mode].page.screenshot({path:path.join(out,mode+'.png'),animations:'disabled'});
  }
  json('summary.json',summary);process.stdout.write(JSON.stringify(summary)+'\n');
 }catch(error){json('failure.json',{lastOperation,error:error.message,stack:error.stack,time:new Date().toISOString()});throw error}
 finally{clearTimeout(watchdog);await close(()=>browser.close())}
}
main().catch(error=>{json('terminal-error.json',{lastOperation,error:error.message,stack:error.stack});process.stderr.write(error.stack+'\n');process.exitCode=1});
