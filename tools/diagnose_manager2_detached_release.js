#!/usr/bin/env node
'use strict';
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {setup,installMeter,dataset,datasetSha,origin}=require('./fixtures/manager2_browser_measurement');
const root=path.resolve(__dirname,'..'),base='edb007c9d3ce7e741db9bd5da6e0a5bd34172509';
const arg=(name,fallback)=>{const i=process.argv.indexOf('--'+name);return i<0?fallback:process.argv[i+1]};
const engine=arg('engine','webkit'),width=Number(arg('width',engine==='webkit'?'390':'1440')),pairs=Number(arg('pairs','12'));
const variants=arg('variants','canonical').split(','),assets=path.resolve(arg('assets','installed')),out=path.resolve(arg('output','detached-release-'+engine));
assert.ok(['chromium','webkit'].includes(engine)&&[390,1440].includes(width)&&pairs>=1&&pairs<=12&&Number.isInteger(pairs));
assert.ok(variants.every(v=>v==='canonical'));
assert.ok(!fs.existsSync(out),'Output namespace must be new');fs.mkdirSync(out,{recursive:true});
const hash=b=>crypto.createHash('sha256').update(b).digest('hex'),json=(name,value)=>fs.writeFileSync(path.join(out,name),JSON.stringify(value,null,2)+'\n');
const source=p=>execFileSync('git',['show',base+':'+p],{cwd:root,maxBuffer:4*1024*1024});
const control=source('manager/assets/workspace-v2-conversation.js').toString();
const edits=[["function retireMessageMedia(row){\n  for(const media of [...row.querySelectorAll('audio'),...row.querySelectorAll('video')]){\n    media.onerror=null;media.pause?.();media.removeAttribute('src');media.load?.();\n  }\n}\n", "function retireMessageMedia(row,remove){\n  const items=[...row.querySelectorAll('audio'),...row.querySelectorAll('video')];\n  for(const media of items){media.onerror=null;media.pause?.()}\n  remove();\n  for(const media of items){media.removeAttribute('src');media.load?.()}\n}\n"], ["    const retained=new Set(desired);[...box.children].forEach(node=>{if(!retained.has(node)){retireMessageMedia(node);box.removeChild(node)}});", "    const retained=new Set(desired);[...box.children].forEach(node=>{if(!retained.has(node))retireMessageMedia(node,()=>box.removeChild(node))});"], ["    const frag=document.createDocumentFragment();rows.forEach(m=>frag.appendChild(createMessageRow(m)));if(!frag.childNodes.length)frag.appendChild(emptyMessageRow());[...box.children].forEach(retireMessageMedia);box.replaceChildren(frag);", "    const frag=document.createDocumentFragment();rows.forEach(m=>frag.appendChild(createMessageRow(m)));if(!frag.childNodes.length)frag.appendChild(emptyMessageRow());retireMessageMedia(box,()=>box.replaceChildren(frag));"]];
let candidate=control;for(const [before,after] of edits){assert.equal(candidate.split(before).length,2);candidate=candidate.replace(before,after)}
fs.writeFileSync(path.join(out,'control-conversation.js'),control);fs.writeFileSync(path.join(out,'candidate-conversation.js'),candidate);
const receipt=JSON.parse(fs.readFileSync(path.join(assets,'receipt.json')));
assert.equal(receipt.assets.length,61);assert.equal(hash(fs.readFileSync(path.join(assets,'shell.html'))),receipt.shell_sha256);
for(const item of [...receipt.assets,receipt.worker]){assert.match(item.path,/^(?:(manager|manager2)\/assets\/[\w.-]+\.(js|css)|manager\/sw\.js)$/);const bytes=fs.readFileSync(path.join(assets,item.path));assert.equal(bytes.length,item.bytes);assert.equal(hash(bytes),item.sha256);assert.ok(bytes.equals(source(item.path)),item.path)}
const bin=path.join(out,'bin');fs.mkdirSync(bin);fs.writeFileSync(path.join(bin,'php'),'#!/bin/sh\nset -eu\n[ "$#" = 1 ] && [ "$1" = manager2/index.php ]\nexec /usr/bin/cat "$M2_MEASUREMENT_HTML"\n',{mode:0o700});
process.env.PATH=bin+path.delimiter+process.env.PATH;process.env.M2_MEASUREMENT_HTML=path.join(assets,'shell.html');
const viewport={width,height:width===1440?900:844},records=[],attributions=[],originalDates=dataset.messages.map(m=>m.created_at);
let browser,primary,lastOperation='launch';
async function bounded(action,name){lastOperation=name;let timer;try{return await Promise.race([Promise.resolve().then(action),new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('Timeout: '+name)),15000)})])}finally{clearTimeout(timer)}}
async function ready(page){await page.waitForFunction(()=>window.__managerMeasurement.done());}
async function prepareB(page,state){state.reset();await bounded(()=>page.evaluate(async()=>{if(!await WorkspaceV2Conversation.open(12,{mobileHistory:'none'}))throw Error('B failed')}),'prepare_B');await page.waitForFunction(()=>WorkspaceV2.S.current===12&&WorkspaceV2.S.detail?.lead&&WorkspaceV2History.getState().loaded===200);await page.evaluate(()=>{if(WorkspaceV2Mobile.isMobile())WorkspaceV2Mobile.showInbox({historyMode:'replace'})});}
async function openA(page){const selector='#inboxList [data-conversation-id="11"] .m2RowOpen';assert.equal(await page.evaluate(()=>WorkspaceV2.S.current),12);await page.evaluate(selector=>window.__managerMeasurement.begin({name:'open_authorized_reply',kind:'open',event:'click',selector,from:12,id:11,loaded:200}),selector);await bounded(()=>page.locator(selector).click(),'native_A_click');await ready(page);await page.waitForFunction(()=>window.__managerMeasurement.cardDone());return page.evaluate(()=>window.__managerMeasurement.record());}
async function modeSample(variant,pair,mode,order){
 const context=await browser.newContext({viewport,deviceScaleFactor:1,timezoneId:'Europe/Moscow'}),page=await context.newPage();page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(15000);
 try{await page.addInitScript(installMeter);const state=await setup(page,{sourceRoot:assets,beforeNavigate:async p=>{if(mode==='detached')await p.route('**/manager/assets/workspace-v2-conversation.js*',route=>route.fulfill({contentType:'text/javascript',body:candidate}))}});await ready(page);
  const personal='[data-pw-selection="tab:10"]';await page.evaluate(selector=>window.__managerMeasurement.begin({name:'personal_selection',kind:'list',event:'click',selector,path:'/manager/personal-workspace-api.php'}),personal);await bounded(()=>page.locator(personal).click(),'personal_selection');await ready(page);
  for(let warm=0;warm<2;warm++){
   await prepareB(page,state);
   if(pair===0&&warm===0)await page.evaluate(()=>{const proto=HTMLMediaElement.prototype,originals={pause:proto.pause,load:proto.load},calls=[];window.__retirementAttribution={originals,calls};for(const name of ['pause','load'])proto[name]=function(...args){const item={method:name,connected:this.isConnected,src:this.getAttribute('src'),errorCleared:this.onerror===null},start=performance.now();try{return originals[name].apply(this,args)}finally{item.inclusive_ms=performance.now()-start;calls.push(item)}}});
   await openA(page);
   if(pair===0&&warm===0){const calls=await page.evaluate(()=>{const a=window.__retirementAttribution;Object.assign(HTMLMediaElement.prototype,a.originals);delete window.__retirementAttribution;return a.calls});const pauses=calls.filter(c=>c.method==='pause'),loads=calls.filter(c=>c.method==='load');assert.equal(pauses.length,10);assert.equal(loads.length,10);assert.ok(pauses.every(c=>c.connected&&c.errorCleared));assert.ok(loads.every(c=>c.connected===(mode==='control')&&c.src===null&&c.errorCleared));attributions.push({mode,discardedWarmup:true,calls});}
  }
  await prepareB(page,state);const record=await openA(page);
  const layout=await page.evaluate(()=>{const box=document.getElementById('messages'),base=box.getBoundingClientRect();return{scrollHeight:box.scrollHeight,clientHeight:box.clientHeight,scrollWidth:box.scrollWidth,clientWidth:box.clientWidth,scrollTop:box.scrollTop,rows:[...box.querySelectorAll('.msg')].map(n=>{const r=n.getBoundingClientRect();return{width:r.width,height:r.height,top:r.top-base.top+box.scrollTop}})}});
  const native=await page.evaluate(()=>[...document.querySelectorAll('#messages audio')].map(n=>({controls:n.controls,preload:n.preload,src:n.getAttribute('src'),width:n.getBoundingClientRect().width,height:n.getBoundingClientRect().height,contentVisibility:getComputedStyle(n).contentVisibility})));
  assert.equal(layout.rows.length,200);assert.ok(layout.scrollWidth<=layout.clientWidth+1);
  const transcriptSha=hash(await page.locator('#messages').textContent());

  assert.equal(native.length,10);for(const item of native){assert.equal(item.controls,true);assert.equal(item.preload,'none');assert.ok(item.src.startsWith(locationOrigin())&&item.width>0&&item.height>0)}
  assert.equal(record.trusted,true);assert.equal(record.state.current,11);assert.equal(record.state.loaded,200);assert.equal(record.state.replyDisabled,false);assert.equal(record.counts.mediaResourceEntries,0);
  assert.deepEqual(state.errors,[]);assert.deepEqual(state.unexpected,[]);assert.deepEqual(state.rejected,[]);
  const result={...record,variant,pair,mode,order,engine,viewport,native,layout,transcriptSha,synthetic:true};records.push(result);fs.appendFileSync(path.join(out,'raw.jsonl'),JSON.stringify(result)+'\n');
  if(pair===0)await bounded(()=>page.screenshot({path:path.join(out,variant+'-'+mode+'-'+width+'.png'),animations:'disabled'}),'screenshot');
 }catch(error){json('primary-error.json',{operation:lastOperation,error:error.message,stack:error.stack,variant,pair,mode});throw error}finally{await bounded(()=>context.close(),'close_context')}
}
function locationOrigin(){return origin;}
function verifyPairGeometry([a,b]){
 assert.equal(a.transcriptSha,b.transcriptSha,'Transcript text changed');
 for(const key of ['scrollHeight','clientHeight','scrollWidth','clientWidth','scrollTop'])assert.ok(Math.abs(a.layout[key]-b.layout[key])<=.01,'Scroll geometry changed: '+key);
 for(let i=0;i<200;i++)for(const key of ['width','height','top'])assert.ok(Math.abs(a.layout.rows[i][key]-b.layout.rows[i][key])<=.01,'Row geometry changed: '+i+'/'+key);
 for(let i=0;i<10;i++){for(const key of ['controls','preload','src'])assert.equal(a.native[i][key],b.native[i][key]);for(const key of ['width','height'])assert.ok(Math.abs(a.native[i][key]-b.native[i][key])<=.01,'Native geometry changed');}
}
const median=values=>{const v=[...values].sort((a,b)=>a-b),i=Math.floor(v.length/2);return v.length%2?v[i]:(v[i-1]+v[i])/2};
async function main(){
 const watchdog=setTimeout(()=>{json('watchdog-failure.json',{operation:lastOperation});process.exit(1)},8*60*1000);
 try{const pw=require('@playwright/test');browser=await pw[engine].launch({headless:true});
  json('manifest.json',{purpose:pairs===12?'paired_candidate_comparison':'protocol_check',base,baseTree:execFileSync('git',['rev-parse',base+'^{tree}'],{cwd:root,encoding:'utf8'}).trim(),tooling:execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim(),engine,browser:browser.version(),playwright:require('@playwright/test/package.json').version,viewport,pairs,discardedWarmups:2,order:'balanced AB/BA, fresh context per mode; one shared browser process',variants,donorInput:receipt,controlHash:hash(control),candidateHash:hash(candidate),runnerHash:hash(fs.readFileSync(__filename)),datasetSha,fixtureHashes:Object.fromEntries(['tools/fixtures/manager2_browser_measurement.js','tests/visual/fixtures/personal-workspace.js'].map(p=>[p,hash(fs.readFileSync(path.join(root,p)))])),cpu:os.cpus()[0]?.model,node:process.version,metric:'native click capture to authorized reply plus two RAF; locator preparation excluded; synthetic routing and observation overhead included; startup/cold/production/API/SQL/P95/physical devices not measured',productionWrites:0,realSends:0});
  for(const variant of variants){dataset.messages.forEach((m,i)=>m.created_at=variant==='canonical'?originalDates[i]:new Date(Date.UTC(2026,9,14,10,0,i)).toISOString().slice(0,19).replace('T',' '));json('dataset-'+variant+'.json',{sha256:hash(JSON.stringify(dataset)),messageTimes:dataset.messages.map(m=>m.created_at),allOtherFieldsSame:true});
   for(let pair=0;pair<pairs;pair++){const order=pair%2?['detached','control']:['control','detached'];for(const mode of order)await modeSample(variant,pair,mode,order.join(','));verifyPairGeometry(records.slice(-2));process.stdout.write(JSON.stringify({engine,variant,pair,records:records.length})+'\n');}
  }
 }catch(error){primary=error;if(!fs.existsSync(path.join(out,'primary-error.json')))json('primary-error.json',{operation:lastOperation,error:error.message,stack:error.stack})}finally{try{if(browser)await bounded(()=>browser.close(),'close_browser')}catch(error){json('cleanup-error.json',{error:error.message});primary??=error}clearTimeout(watchdog)}
 if(primary)throw primary;
 const groups=variants.map(variant=>{const rows=records.filter(r=>r.variant===variant),a=rows.filter(r=>r.mode==='control'),b=rows.filter(r=>r.mode==='detached'),deltas=Array.from({length:pairs},(_,pair)=>a.find(r=>r.pair===pair).duration-b.find(r=>r.pair===pair).duration);return{variant,n:pairs,controlMedian:median(a.map(r=>r.duration)),candidateMedian:median(b.map(r=>r.duration)),pairedSavedMedian:median(deltas),positivePairs:deltas.filter(d=>d>0).length,pairedDeltas:deltas}});
 json('attribution.json',attributions);json('summary.json',{ok:true,groups,rawSha256:hash(fs.readFileSync(path.join(out,'raw.jsonl'))),records:records.length,notMeasured:['production','SQL','P95','physicalSafari','coldPage']});process.stdout.write('DETACHED_RELEASE_RESULT '+JSON.stringify({engine,groups,records:records.length})+'\n');
}
main().catch(error=>{process.stderr.write(error.stack+'\n');process.exit(1)});
