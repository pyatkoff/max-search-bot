#!/usr/bin/env node
'use strict';
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..');
const {setup,installMeter,dataset,datasetSha,origin,quickText,previewText,draftText,fileName,previewPNG}=require('./fixtures/manager2_browser_measurement');
const digest=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const arg=name=>{const index=process.argv.indexOf('--'+name);return index<0?undefined:process.argv[index+1]};
const has=name=>process.argv.includes('--'+name);
const git=(...args)=>execFileSync('git',args,{cwd:root,encoding:'utf8'}).trim();
const json=(file,value)=>fs.writeFileSync(file,JSON.stringify(value,null,2)+'\n');
const positive=(value,fallback,max)=>{const n=Number(value??fallback);assert.ok(Number.isInteger(n)&&n>0&&n<=max);return n};
const selectedWidths=(arg('widths')||'390,430,768,1440,390-short').split(',');
const viewports=selectedWidths.map(value=>value==='390-short'?{width:390,height:420}:({width:positive(value,390,1440),height:value==='1440'?900:844}));
const samples=positive(arg('samples'),30,30),warmups=positive(arg('warmups'),3,3),cold=positive(arg('cold'),3,5);
let experimentWatchdog,lastOperation='input_verification';
const engine=arg('engine')||'chromium';assert.ok(['chromium','webkit'].includes(engine));
const productSha=arg('product-sha')||git('rev-parse','HEAD');assert.match(productSha,/^[a-f0-9]{40}$/);
const output=path.resolve(arg('output')||path.join(root,'manager2-artifacts','measurement-'+engine));fs.mkdirSync(output,{recursive:true});
const assets=path.resolve(arg('assets')||path.join(output,'installed'));
const readSource=relative=>execFileSync('git',['show',productSha+':'+relative],{cwd:root,maxBuffer:4*1024*1024});

async function publicGet(url){
 const u=new URL(url);assert.equal(u.origin,'https://app.anytoour.ru');
 const response=await fetch(url,{redirect:'error',credentials:'omit',signal:AbortSignal.timeout(30000),headers:{'User-Agent':'AnyTour-Manager2-static-measurement','Accept-Encoding':'identity'}});assert.equal(response.status,200);
 const bytes=Buffer.from(await response.arrayBuffer());assert.ok(bytes.length>0&&bytes.length<=2*1024*1024);return bytes;
}
async function capture(){
 fs.mkdirSync(assets,{recursive:true});const base='https://app.anytoour.ru/manager2/',html=await publicGet(base),text=html.toString('utf8');
 const baseTag=text.match(/<base\b[^>]*\bhref=["']([^"']+)["']/i),documentBase=new URL(baseTag?.[1]||base,base);
 const urls=new Set();for(const match of text.matchAll(/<(script|link)\b[^>]*>/gi)){
  const tag=match[0];if(match[1].toLowerCase()==='link'&&!/\brel=["']stylesheet["']/i.test(tag))continue;
  const attribute=tag.match(/\b(?:src|href)=["']([^"']+)["']/i);if(attribute)urls.add(new URL(attribute[1].replace(/&amp;/g,'&'),documentBase).href);
 }
 assert.ok(urls.size>=40&&urls.size<=100);const records=[];
 for(const url of [...urls].sort()){
  const u=new URL(url),relative=decodeURIComponent(u.pathname).slice(1);assert.equal(u.origin,'https://app.anytoour.ru');assert.match(relative,/^(manager|manager2)\/assets\/[\w.-]+\.(js|css)$/);
  const bytes=await publicGet(url);assert.ok(bytes.equals(readSource(relative)),'Installed asset differs from source: '+relative);const target=path.join(assets,relative);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,bytes);records.push({path:relative,bytes:bytes.length,sha256:digest(bytes)});
 }
 const worker=await publicGet('https://app.anytoour.ru/manager/sw.js');assert.ok(worker.equals(readSource('manager/sw.js')));fs.writeFileSync(path.join(assets,'manager/sw.js'),worker);fs.writeFileSync(path.join(assets,'shell.html'),html);
 json(path.join(assets,'receipt.json'),{production_sha:productSha,tree:git('rev-parse',productSha+'^{tree}'),shell_sha256:digest(html),assets:records,worker:{path:'manager/sw.js',bytes:worker.length,sha256:digest(worker)},public_static_gets:urls.size+2,captured_at:new Date().toISOString(),production_api_or_business_writes:0});
}
function verifyInputs(){
 const receipt=JSON.parse(fs.readFileSync(path.join(assets,'receipt.json')));assert.equal(receipt.production_sha,productSha);assert.equal(receipt.tree,git('rev-parse',productSha+'^{tree}'));
 assert.equal(digest(fs.readFileSync(path.join(assets,'shell.html'))),receipt.shell_sha256);
 for(const record of [...receipt.assets,receipt.worker]){assert.match(record.path,/^(?:(manager|manager2)\/assets\/[\w.-]+\.(js|css)|manager\/sw\.js)$/);const actual=fs.readFileSync(path.join(assets,record.path));assert.equal(actual.length,record.bytes);assert.equal(digest(actual),record.sha256);assert.ok(actual.equals(readSource(record.path)));}
 return receipt;
}
function progress(name){lastOperation=name;const value={time:new Date().toISOString(),operation:name};json(path.join(output,'progress.json'),value);process.stdout.write('M2_DIAGNOSTIC '+JSON.stringify(value)+'\n')}
let instrumentedSource;
function instrumentedConversation(){
 if(instrumentedSource)return instrumentedSource;
 let source=fs.readFileSync(path.join(assets,'manager/assets/workspace-v2-conversation.js'),'utf8');
 const old="    const retained=new Set(desired);[...box.children].forEach(node=>{if(!retained.has(node))box.removeChild(node)});\n    let cursor=box.firstChild;for(const node of desired){if(node===cursor)cursor=cursor.nextSibling;else box.insertBefore(node,cursor)}";
 if(has('batch-reset')){assert.equal(source.split(old).length,2);source=source.replace(old,"    if(resetRows){const fragment=document.createDocumentFragment();desired.forEach(node=>fragment.appendChild(node));box.replaceChildren(fragment)}else{\n"+old+"\n    }")}
 const box=arg('audio-box')?JSON.parse(fs.readFileSync(arg('audio-box'),'utf8')):null;
 if(box){const audio="else if(a.type==='audio'&&url){n=document.createElement('audio');n.preload='none';n.src=url;n.controls=true;mediaFallback(n,a,url,'Аудио')}";assert.equal(source.split(audio).length,2);source=source.replace(audio,"else if(a.type==='audio'&&url){n=document.createElement('audio');n.preload='none';"+(has('audio-no-src')?'':'n.src=url;')+'n.controls='+(!has('audio-no-controls'))+";mediaFallback(n,a,url,'Аудио');const shell=document.createElement('span');shell.className='diagnosticAudioBox';Object.assign(shell.style,"+JSON.stringify({...box.style,display:box.style.display==='inline'?'inline-block':box.style.display})+");Object.assign(n.style,{display:'block',width:'100%',height:'100%',margin:'0',maxWidth:'100%'});shell.appendChild(n);n=shell}")}
 const operations="  if(typeof box.insertBefore==='function'&&typeof box.removeChild==='function'){";assert.equal(source.split(operations).length,2);source=source.replace(operations,"  const __diagDOMStart=performance.now();\n"+operations);
 const scroll="  if(stickToBottom)box.scrollTop=box.scrollHeight;else if(preserveScroll)";assert.equal(source.split(scroll).length,2);source=source.replace(scroll,"  window.__m2DiagnosticCost?.add('DOM.operations',performance.now()-__diagDOMStart);const __diagScrollStart=performance.now();\n"+scroll);
 const restore="  window.WorkspaceV2Jump?.restore(position,{stickToBottom,preserveScroll});";assert.equal(source.split(restore).length,2);source=source.replace(restore,"  window.__m2DiagnosticCost?.add('DOM.scrollPosition',performance.now()-__diagScrollStart);\n"+restore);
 const bundle=path.resolve(path.dirname(require.resolve('@playwright/test/package.json')),'../../playwright/lib/transform/babelBundle.js');
 const babel=require(bundle),edits=[],targets=new Set(['renderMessages','createMessageRow','renderAttachments','renderHeader','renderActions']);
 babel.traverse(babel.babelParse(source,'workspace-v2-conversation.js',false),{FunctionDeclaration({node}){if(!targets.has(node.id?.name))return;edits.push({at:node.body.start+1,text:'const __m2DiagnosticCostStart=performance.now();try{'});edits.push({at:node.body.end-1,text:'}finally{window.__m2DiagnosticCost?.add('+JSON.stringify(node.id.name)+',performance.now()-__m2DiagnosticCostStart)}'});}});
 assert.equal(edits.length,10);let text=source;for(const edit of edits.sort((a,b)=>b.at-a.at))text=text.slice(0,edit.at)+edit.text+text.slice(edit.at);
 new Function(text);fs.writeFileSync(path.join(output,'conversation-instrumented.js'),text);instrumentedSource=text;return text;
}
async function instrument(page){await page.route('**/manager/assets/workspace-v2-conversation.js*',route=>{assert.equal(new URL(route.request().url()).origin,origin);return route.fulfill({contentType:'application/javascript',body:instrumentedConversation()})})}
function installCostMeter(){let values={};window.__m2DiagnosticCost={add(name,ms){const value=values[name]??={calls:0,total:0,max:0};value.calls++;value.total+=ms;value.max=Math.max(value.max,ms)},reset(){values={}},values(){return values}}}
async function attachCostHooks(page){await page.evaluate(()=>{
 const costs=window.__m2DiagnosticCost;
 for(const [object,key] of [[window.ManagerTime,'format'],[window.WorkspaceV2Jump,'capture'],[window.WorkspaceV2Jump,'restore'],[window.WorkspaceV2Inbox,'markRead'],[window.WorkspaceV2Inbox,'markActive'],[window.WorkspaceV2LeadCard,'render']]){
  if(!object||typeof object[key]!=='function')continue;const original=object[key],name=(object===window.ManagerTime?'ManagerTime':object===window.WorkspaceV2Jump?'Jump':object===window.WorkspaceV2Inbox?'Inbox':'LeadCard')+'.'+key;
  object[key]=function(...args){const start=performance.now();try{return original.apply(this,args)}finally{costs.add(name,performance.now()-start)}};
 }
 const meter=window.__managerMeasurement,begin=meter.begin,record=meter.record;
 meter.begin=function(...args){costs.reset();return begin.apply(this,args)};
 meter.record=function(){return {...record.call(this),diagnosticInclusiveFunctionCosts:costs.values()}};
})}
async function bounded(action,name){progress(name);let timer;try{return await Promise.race([Promise.resolve().then(action),new Promise((_,reject)=>{timer=setTimeout(()=>{json(path.join(output,'action-timeout.json'),{ok:false,time:new Date().toISOString(),operation:name});reject(Error('Operation timeout: '+name))},15000)})])}finally{clearTimeout(timer)}}
async function startup(page){progress('startup');try{await page.waitForFunction(()=>window.__managerMeasurement.done())}catch(error){json(path.join(output,'startup-failure.json'),{diagnostic:await page.evaluate(()=>window.__managerMeasurement?.diagnostic()).catch(e=>({error:e.message}))});await page.screenshot({path:path.join(output,'failure.png')});throw error}}
async function phase(page,options,action){
 lastOperation=options.name;
 await page.evaluate(options=>window.__managerMeasurement.begin(options),options);
 try{if(action)await bounded(action,options.name);
  await page.waitForFunction(()=>window.__managerMeasurement.done(),{},{timeout:15000});
  if(['open','back'].includes(options.kind))await page.waitForFunction(()=>window.__managerMeasurement.cardDone(),{},{timeout:15000});
  return page.evaluate(()=>window.__managerMeasurement.record());
 }catch(error){json(path.join(output,'phase-failure.json'),{phase:options,url:page.url(),error:error.message,diagnostic:await page.evaluate(()=>window.__managerMeasurement?.diagnostic()).catch(e=>({error:e.message}))});await page.screenshot({path:path.join(output,'failure.png')});throw error}
}
const command=(page,name)=>page.evaluate(async name=>{await WorkspaceV2Conversation[name]();window.__managerMeasurement.commandDone()},name);
async function inbox(page){await page.evaluate(()=>{if(WorkspaceV2Mobile.isMobile())WorkspaceV2Mobile.showInbox({historyMode:'replace'})});}
async function cycle(page,state,index){
 progress('cycle_'+index);const records=[];state.reset();
 if(await page.evaluate(()=>WorkspaceV2.S.current>0)){await page.evaluate(()=>WorkspaceV2Media.clear());await page.locator('#replyText').fill('')}
 // Every timed A-open starts from B, including repeated same-page samples.
 // Otherwise the preceding Back leaves A selected and measures same-client
 // reconciliation rather than a real conversation switch.
 await bounded(()=>page.evaluate(async()=>{if(!await WorkspaceV2Conversation.open(12,{mobileHistory:'none'}))throw Error('Precondition B did not open')}),'precondition_B');
 await page.waitForFunction(()=>WorkspaceV2.S.current===12&&WorkspaceV2.S.detail?.lead&&WorkspaceV2History.getState().loaded===200);
 await inbox(page);
 records.push(await phase(page,{name:'system_selection',kind:'list',event:'click',selector:'[data-q="mine"]',path:'/manager/pipeline-api.php'},()=>page.locator('[data-q="mine"]').click()));
 records.push(await phase(page,{name:'personal_selection',kind:'list',event:'click',selector:'[data-pw-selection="tab:10"]',path:'/manager/personal-workspace-api.php'},()=>page.locator('[data-pw-selection="tab:10"]').click()));
 const selector='#inboxList [data-conversation-id="11"] .m2RowOpen';
 assert.equal(await page.evaluate(()=>WorkspaceV2.S.current),12);
 records.push(await phase(page,{name:'open_authorized_reply',kind:'open',event:'click',selector,from:12,id:11,loaded:200},()=>page.locator(selector).click()));
 await page.locator('#replyText').focus();
 records.push(await phase(page,{name:'input_adoption',kind:'input',event:'input',selector:'#replyText',id:11,draft:draftText},()=>page.locator('#replyText').fill(draftText)));
 records.push(await phase(page,{name:'older_200_to_400',kind:'history',id:11,loaded:400},()=>command(page,'loadOlder')));
 records.push(await phase(page,{name:'older_400_to_600',kind:'history',id:11,loaded:600},()=>command(page,'loadOlder')));
 records.push(await phase(page,{name:'refresh_unchanged_600',kind:'refresh',id:11,loaded:600},()=>command(page,'refreshVisible')));
 state.append();records.push(await phase(page,{name:'refresh_append_601',kind:'refresh',id:11,loaded:601},()=>command(page,'refreshVisible')));
 const quick='[data-qr-toolbar-id="1"]',inserted=draftText+'\n'+quickText;
 await page.locator('#replyText').evaluate(input=>input.setSelectionRange(input.value.length,input.value.length));
 records.push(await phase(page,{name:'quick_reply_insert',kind:'insert',event:'click',selector:quick,id:11,draft:inserted},()=>page.locator(quick).click()));
 records.push(await phase(page,{name:'quick_reply_preview',kind:'preview',event:'click',selector:'[data-qr-toolbar-id="2"]'},()=>page.locator('[data-qr-toolbar-id="2"]').click()));
 await page.locator('#qrPreview').fill(previewText);
 const finalDraft=inserted+'\n'+previewText;
 records.push(await phase(page,{name:'quick_reply_preview_insert',kind:'insert',event:'click',selector:'#qrInsert',id:11,draft:finalDraft},()=>page.locator('#qrInsert').click()));
 records.push(await phase(page,{name:'file_preview',kind:'file',event:'change',selector:'#replyFile',id:11,file:fileName},()=>page.locator('#replyFile').setInputFiles({name:fileName,mimeType:'image/png',buffer:previewPNG})));
 await page.locator('#conversationTitle').focus();
 records.push(await phase(page,{name:'next_waiting',kind:'open',event:'keydown',id:12,loaded:200},()=>page.keyboard.press('Control+Alt+j')));
 if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()))records.push(await phase(page,{name:'native_back_reply',kind:'back',event:'popstate',id:11,loaded:200,draft:finalDraft,file:fileName},()=>page.goBack()));
 else records.push(await phase(page,{name:'desktop_return_reply',kind:'back',event:'click',selector,id:11,loaded:200,draft:finalDraft,file:fileName},()=>page.locator(selector).click()));
 assert.equal(await page.evaluate(()=>window.__managerMeasurement.fileRetained()),true,'Native File identity lost');
 assert.deepEqual(state.unexpected,[]);assert.deepEqual(state.rejected,[]);assert.deepEqual(state.errors,[]);
 assert.equal(await page.locator('#replyText').inputValue(),finalDraft);
 return records.map(record=>({...record,sample:index,synthetic:true}));
}
function summarise(records){
 const groups={};for(const r of records){const key=[r.engine,r.viewport.width+'x'+r.viewport.height,r.cacheMode,r.name].join('/');(groups[key]??=[]).push(r.duration)}
 return Object.entries(groups).map(([key,values])=>{const sorted=[...values].sort((a,b)=>a-b),n=sorted.length;return {key,n,median_ms:n%2?sorted[(n-1)/2]:(sorted[n/2-1]+sorted[n/2])/2,min_ms:sorted[0],max_ms:sorted[n-1],p95:'not_measured',tail_reason:'Bounded synthetic descriptive series; no stable production-tail or SLA claim'}});
}
async function main(){
 if(has('capture'))await capture();const input=verifyInputs();
 if(has('capture-only')){assert.ok(has('capture'));process.stdout.write('Verified '+input.assets.length+' installed assets and worker\n');return}
 const bin=path.join(output,'bin');fs.mkdirSync(bin,{recursive:true});fs.writeFileSync(path.join(bin,'php'),'#!/bin/sh\nset -eu\n[ "$#" = 1 ] && [ "$1" = manager2/index.php ]\nexec /usr/bin/cat "$M2_MEASUREMENT_HTML"\n',{mode:0o700});process.env.PATH=bin+path.delimiter+process.env.PATH;process.env.M2_MEASUREMENT_HTML=path.join(assets,'shell.html');
 const playwright=require('@playwright/test'),browser=await playwright[engine].launch({headless:true});
 const rawFile=path.join(output,'samples.jsonl');fs.writeFileSync(rawFile,'');const records=[],environments=[];
 const manifest={schema:1,started_at:new Date().toISOString(),productSha,productTree:input.tree,toolingSha:git('rev-parse','HEAD'),datasetSha,dataset:{inbox:200,initialMessages:200,availableHistory:600,refreshAppend:1,role:'manager',manager_id:7,synthetic:true},method:{audioNoControls:has('audio-no-controls'),audioNoSrc:has('audio-no-src'),audioFixedBox:!!arg('audio-box'),batchReset:has('batch-reset'),diagnostic:'Synchronous inclusive function costs; instrumented conversation response; excluded from primary baseline',instrumentedConversationSha:digest(Buffer.from(instrumentedConversation())),purpose:samples===30&&warmups===3&&cold===3?'baseline':'protocol_check',samples,warmups,cold,engine,viewports,httpCache:'disabled by Playwright routing',warm:'same page/context after discarded whole-path warmups; no HTTP-cache warming claim',coldDescription:'fresh browser context/page; shared browser process/OS cache uncontrolled',cpuThrottle:'none',network:'loopback synthetic interception; bridge overhead included',timestamps:'browser performance.now; captured event or armed canonical command to first matching state plus two animation frames; command completion required for history/refresh',milestones:'first RAF observing transcript/authorized reply/card state; main duration additionally waits two animation frames',instrumentation:'fetch JSON timestamps and reserialization byte count; resource/longtask observers and state polling; observer overhead included',order:'fixed 14-phase whole-path cycles; viewports in supplied order; no randomized crossover',p95:'not_measured',serverSQL:'not_measured',productionBrowser:'not_measured',realCustomerOrProviderSends:0,productionDataWrites:0},node:process.version,playwright:require('@playwright/test/package.json').version,browserVersion:browser.version(),platform:{os:os.platform(),release:os.release(),arch:os.arch(),cpuCount:os.cpus().length,cpuModel:os.cpus()[0]?.model,totalMemory:os.totalmem(),loadAverage:os.loadavg()},input:{shell_sha256:input.shell_sha256,assets:input.assets,worker:input.worker},scriptHashes:Object.fromEntries(['tools/measure_manager2_browser.js','tools/fixtures/manager2_browser_measurement.js','tests/visual/fixtures/personal-workspace.js'].map(p=>[p,digest(fs.readFileSync(path.join(root,p)))]))};
 json(path.join(output,'manifest.json'),manifest);
 experimentWatchdog=setTimeout(()=>{json(path.join(output,'watchdog-failure.json'),{ok:false,time:new Date().toISOString(),lastOperation,error:'Experiment watchdog timeout; partial artifacts are not a baseline'});process.stderr.write('Experiment watchdog timeout at '+lastOperation+'\n');process.exit(1)},manifest.method.purpose==='protocol_check'?180000:960000);
 const save=record=>{records.push(record);fs.appendFileSync(rawFile,JSON.stringify(record)+'\n')};
 try{for(const viewport of viewports){
  for(let i=0;i<cold;i++){const context=await browser.newContext({viewport,deviceScaleFactor:1,timezoneId:'Europe/Moscow'}),page=await context.newPage();page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(15000);await page.addInitScript(installMeter);await page.addInitScript(installCostMeter);progress('fixture_setup');const state=await setup(page,{sourceRoot:assets,beforeNavigate:instrument});await startup(page);await attachCostHooks(page);save({...await page.evaluate(()=>window.__managerMeasurement.record()),engine,viewport,sample:i,cacheMode:'fresh_context',synthetic:true});assert.deepEqual(state.rejected,[]);assert.deepEqual(state.unexpected,[]);assert.deepEqual(state.errors,[]);environments.push(await page.evaluate(()=>window.__managerMeasurement.environment()));await bounded(()=>context.close(),'close_context')}
  const context=await browser.newContext({viewport,deviceScaleFactor:1,timezoneId:'Europe/Moscow'}),page=await context.newPage();page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(15000);await page.addInitScript(installMeter);await page.addInitScript(installCostMeter);progress('fixture_setup');const state=await setup(page,{sourceRoot:assets,beforeNavigate:instrument});await startup(page);await attachCostHooks(page);
  for(let i=-warmups;i<samples;i++){const result=await cycle(page,state,i);if(i>=0)for(const record of result)save({...record,engine,viewport,cacheMode:'same_page_after_warmup'});process.stdout.write(JSON.stringify({engine,viewport,sample:i,operations:result.length})+'\n')}
  json(path.join(output,'audio-box.json'),await page.evaluate(()=>{const n=document.querySelector('#messages .diagnosticAudioBox')||document.querySelector('#messages audio');if(!n)return null;const r=n.getBoundingClientRect(),s=getComputedStyle(n);return {width:r.width,height:r.height,style:{display:s.display,width:r.width+'px',height:r.height+'px',boxSizing:'border-box',maxWidth:'100%',marginTop:s.marginTop,marginBottom:s.marginBottom,marginLeft:s.marginLeft,marginRight:s.marginRight,verticalAlign:s.verticalAlign}}}));
  await page.screenshot({path:path.join(output,'final-'+viewport.width+'x'+viewport.height+'.png'),animations:'disabled'});await bounded(()=>context.close(),'close_context');
 }}catch(error){json(path.join(output,'primary-error.json'),{time:new Date().toISOString(),operation:lastOperation,error:error.message,stack:error.stack});throw error}finally{progress('close_browser');try{await bounded(()=>browser.close(),'close_browser')}catch(error){json(path.join(output,'cleanup-failure.json'),{error:error.message});process.stderr.write(error.stack+'\n');process.exit(1)}}
 clearTimeout(experimentWatchdog);
 const summary={ok:true,purpose:manifest.method.purpose,completed_at:new Date().toISOString(),raw_samples_sha256:digest(fs.readFileSync(rawFile)),raw_records:records.length,groups:summarise(records),environments,scope:'Synthetic installed-assets browser baseline only; production browser/API/SQL and stable P95 not measured'};json(path.join(output,'summary.json'),summary);process.stdout.write('M2_BROWSER_MEASUREMENT '+JSON.stringify({ok:true,engine,records:records.length,raw_sha256:summary.raw_samples_sha256})+'\n');
}
main().catch(error=>{clearTimeout(experimentWatchdog);json(path.join(output,'failure.json'),{ok:false,time:new Date().toISOString(),name:error.name,message:error.message,stack:error.stack});process.stderr.write(error.stack+'\n');process.exitCode=1});
