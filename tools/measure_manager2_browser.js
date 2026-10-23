#!/usr/bin/env node
'use strict';
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..');
const {setup,installMeter,dataset,datasetSha,origin,quickText,previewText,draftText,fileName,previewPNG,aiText,editedAiText}=require('./fixtures/manager2_browser_measurement');
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
const journey=arg('journey')||'workspace';assert.ok(['workspace','reply','open'].includes(journey));
const protocol=journey==='open'?'manager2-open-v1':journey==='reply'?'manager2-reply-v1':'manager2-workspace-v1';
const recordsPerCycle=journey==='open'?2:journey==='reply'?13:14;
const productSha=arg('product-sha')||git('rev-parse','HEAD');assert.match(productSha,/^[a-f0-9]{40}$/);
const output=path.resolve(arg('output')||path.join(root,'manager2-artifacts','measurement-'+engine));fs.mkdirSync(output,{recursive:true});
const assets=path.resolve(arg('assets')||path.join(output,'installed'));
if(['manifest.json','samples.jsonl','summary.json','failure.json'].some(file=>fs.existsSync(path.join(output,file)))){process.stderr.write('Output already contains measurement evidence; use a new output directory\n');process.exit(2)}
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
async function bounded(action,name){lastOperation=name;let timer;try{return await Promise.race([Promise.resolve().then(action),new Promise((_,reject)=>{timer=setTimeout(()=>{json(path.join(output,'action-timeout.json'),{ok:false,time:new Date().toISOString(),operation:name});reject(Error('Operation timeout: '+name))},15000)})])}finally{clearTimeout(timer)}}
async function failureScreenshot(page){try{await page.screenshot({path:path.join(output,'failure.png')})}catch(error){json(path.join(output,'failure-screenshot-error.json'),{error:error.message})}}
async function startup(page){lastOperation='startup';try{await page.waitForFunction(()=>window.__managerMeasurement.done())}catch(error){json(path.join(output,'startup-failure.json'),{error:error.message,diagnostic:await page.evaluate(()=>window.__managerMeasurement?.diagnostic()).catch(e=>({error:e.message}))});await failureScreenshot(page);throw error}}
async function phase(page,options,action){
 lastOperation=options.name;
 await page.evaluate(options=>window.__managerMeasurement.begin(options),options);
 try{if(action)await bounded(action,options.name);
  await page.waitForFunction(()=>window.__managerMeasurement.done(),{},{timeout:15000});
  if(['open','back'].includes(options.kind))await page.waitForFunction(()=>window.__managerMeasurement.cardDone(),{},{timeout:15000});
  return page.evaluate(()=>window.__managerMeasurement.record());
 }catch(error){json(path.join(output,'phase-failure.json'),{phase:options,url:page.url(),error:error.message,diagnostic:await page.evaluate(()=>window.__managerMeasurement?.diagnostic()).catch(e=>({error:e.message}))});await failureScreenshot(page);throw error}
}
const command=(page,name)=>page.evaluate(async name=>{
 const meter=window.__managerMeasurement;
 for(let attempt=0;attempt<2;attempt++){
  const start=performance.now(),result=await WorkspaceV2Conversation[name]();meter.commandAttempt({start,end:performance.now(),result});
  if(result===true){meter.commandDone();return}
  if(name!=='refreshVisible'||attempt||!meter.pendingDetail())throw Error('Canonical command did not complete: '+name);
  // A concurrent automatic refresh suppresses this manual call. Preserve it as
  // an attempt, wait for that read to settle, then require our own successful
  // command and response. Its wait stays inside the measured duration.
  await new Promise(resolve=>{const tick=()=>meter.pendingDetail()?requestAnimationFrame(tick):requestAnimationFrame(resolve);tick()});
 }
},name);
async function inbox(page){await page.evaluate(()=>{if(WorkspaceV2Mobile.isMobile())WorkspaceV2Mobile.showInbox({historyMode:'replace'})});}
async function cycle(page,state,index){
 const records=[];try{state.reset();
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
 }catch(error){json(path.join(output,'partial-cycle-'+index+'.json'),{ok:false,acceptedBaseline:false,saved_at:new Date().toISOString(),engine,viewport:page.viewportSize(),sample:index,error:error.message,records:records.map(record=>({...record,sample:index,synthetic:true,cacheMode:index<0?'discarded_warmup':'same_page_after_warmup'}))});throw error}
}
async function replyCycle(page,state,index){
 const records=[];try{
  for(const aiMode of ['prepared','generated']){
   if(await page.evaluate(()=>WorkspaceV2.S.current>0)){await page.evaluate(()=>WorkspaceV2Media.clear());await page.locator('#replyText').fill('')}
   state.replyMode(aiMode);
   await bounded(()=>page.evaluate(async()=>{if(!await WorkspaceV2Conversation.open(12,{mobileHistory:'none'}))throw Error('Precondition B did not open')}),'precondition_B');
   await page.waitForFunction(()=>WorkspaceV2.S.current===12&&WorkspaceV2.S.detail?.lead&&WorkspaceV2History.getState().loaded===200);
   await inbox(page);const selector='#inboxList [data-conversation-id="11"] .m2RowOpen',startIndex=records.length,transportIndex=state.transport.length;
   const measure=(name,options,action)=>phase(page,{name:aiMode+'_'+name,aiMode,id:11,...options},action);
   records.push(await measure('open',{kind:'open',event:'click',selector,from:12,loaded:200},()=>page.locator(selector).click()));
   records.push(await measure('ai_preview',{kind:'ai_preview',event:'click',selector:'#aiDraftOpen',draft:aiText},()=>page.locator('#aiDraftOpen').click()));
   records.push(await measure('ai_insert',{kind:'ai_insert',event:'click',selector:'#aiDraftInsert',draft:aiText},()=>page.locator('#aiDraftInsert').click()));
   records.push(await measure('manager_edit',{kind:'input',event:'input',selector:'#replyText',draft:editedAiText},()=>page.locator('#replyText').fill(editedAiText)));
   const send={event:'click',selector:'#sendReply',draft:editedAiText};
   if(aiMode==='generated'){
    records.push(await measure('send_unknown',{kind:'send_unknown',...send},()=>page.locator('#sendReply').click()));
    records.push(await measure('receipt_confirmed',{kind:'send_confirmed',action:'send_receipt',...send},()=>page.locator('#sendReply').click()));
   }else records.push(await measure('send_confirmed',{kind:'send_confirmed',action:'send',...send},()=>page.locator('#sendReply').click()));
   const requests=state.transport.slice(transportIndex),sends=requests.filter(r=>r.action==='send'),receipts=requests.filter(r=>r.action==='send_receipt');
   assert.equal(sends.length,1,'Exactly one synthetic send per journey');assert.equal(receipts.length,aiMode==='generated'?1:0);
   if(receipts.length)assert.equal(receipts[0].operation_id,sends[0].operation_id,'Receipt must reconcile the original operation');
   assert.equal(state.attempts.size,1);assert.equal(await page.locator('#replyText').inputValue(),'');
   assert.equal(await page.evaluate(text=>WorkspaceV2.S.detail.messages.filter(m=>m.sender_type==='manager'&&m.text===text).length,editedAiText),1,'Exactly one confirmed transcript message');
   const first=records[startIndex],last=records.at(-1);
   const phaseDuration=records.slice(startIndex).reduce((sum,r)=>sum+r.duration,0);
   records.push(await page.evaluate(span=>window.__managerMeasurement.record(span),{name:aiMode+'_full_cycle',kind:'reply_cycle',aiMode,id:11,start:first.start,ready:last.ready,end:last.end,fetchIndex:first.fetchIndex,milestones:{},phaseCount:records.length-startIndex,componentDuration:phaseDuration,betweenPhaseDuration:last.end-first.start-phaseDuration,syntheticSendCount:sends.length,receiptCount:receipts.length}));
  }
  assert.deepEqual(state.unexpected,[]);assert.deepEqual(state.rejected,[]);assert.deepEqual(state.errors,[]);assert.equal(records.length,recordsPerCycle);
  return records.map(record=>({...record,sample:index,synthetic:true}));
 }catch(error){json(path.join(output,'partial-cycle-'+index+'.json'),{ok:false,acceptedBaseline:false,protocol,saved_at:new Date().toISOString(),engine,viewport:page.viewportSize(),sample:index,error:error.message,records});throw error}
}
function summarise(records){
 const groups={};for(const r of records){const key=[r.engine,r.viewport.width+'x'+r.viewport.height,r.cacheMode,r.name].join('/');(groups[key]??=[]).push(r.duration)}
 return Object.entries(groups).map(([key,values])=>{const sorted=[...values].sort((a,b)=>a-b),n=sorted.length;return {key,n,median_ms:n%2?sorted[(n-1)/2]:(sorted[n/2-1]+sorted[n/2])/2,min_ms:sorted[0],max_ms:sorted[n-1],p95:'not_measured',tail_reason:'Bounded synthetic descriptive series; no stable production-tail or SLA claim'}});
}
function openingComparisons(records){
 const median=values=>{values.sort((a,b)=>a-b);const n=values.length;return n%2?values[(n-1)/2]:(values[n/2-1]+values[n/2])/2};
 return viewports.map(viewport=>{
  const selected=records.filter(r=>r.cacheMode==='same_page_after_warmup'&&r.viewport.width===viewport.width&&r.viewport.height===viewport.height),pairs=[];
  for(let i=0;i<samples;i++){const pair=selected.filter(r=>r.sample===i),baseline=pair.find(r=>r.cohort==='baseline'),candidate=pair.find(r=>r.cohort==='candidate');assert.equal(pair.length,2);assert.ok(baseline&&candidate);pairs.push({sample:i,baseline_ms:baseline.duration,candidate_ms:candidate.duration,delta_ms:baseline.duration-candidate.duration})}
  return {engine,viewport,n:pairs.length,baseline_median_ms:median(pairs.map(p=>p.baseline_ms)),candidate_median_ms:median(pairs.map(p=>p.candidate_ms)),paired_delta_median_ms:median(pairs.map(p=>p.delta_ms)),pairs};
 });
}
async function openSample(page,state,cohort,index){
 state.reset();
 await bounded(()=>page.evaluate(async()=>{if(!await WorkspaceV2Conversation.open(12,{mobileHistory:'none'}))throw Error('Precondition B did not open')}),'precondition_B');
 await page.waitForFunction(()=>WorkspaceV2.S.current===12&&WorkspaceV2.S.detail?.lead&&WorkspaceV2History.getState().loaded===200);
 await inbox(page);const selector='#inboxList [data-conversation-id="11"] .m2RowOpen';
 const record=await phase(page,{name:cohort+'_open',kind:'open',event:'click',selector,from:12,id:11,loaded:200},()=>page.locator(selector).click());
 assert.equal(await page.locator('#messages .msg').count(),200);
 assert.equal(await page.locator('#messages audio').count(),10);
 assert.equal(await page.locator('#messages audio, #messages video').evaluateAll(players=>players.every(player=>player.controls&&player.preload==='none')),true);
 assert.deepEqual(state.rejected,[]);assert.deepEqual(state.unexpected,[]);assert.deepEqual(state.errors,[]);
 return {...record,cohort,sample:index,synthetic:true};
}
async function main(){
 if(has('capture'))await capture();const input=verifyInputs();
 if(has('capture-only')){assert.ok(has('capture'));process.stdout.write('Verified '+input.assets.length+' installed assets and worker\n');return}
 const bin=path.join(output,'bin');fs.mkdirSync(bin,{recursive:true});fs.writeFileSync(path.join(bin,'php'),'#!/bin/sh\nset -eu\n[ "$#" = 1 ] && [ "$1" = manager2/index.php ]\nexec /usr/bin/cat "$M2_MEASUREMENT_HTML"\n',{mode:0o700});process.env.PATH=bin+path.delimiter+process.env.PATH;process.env.M2_MEASUREMENT_HTML=path.join(assets,'shell.html');
 const playwright=require('@playwright/test'),toolingTrackedClean=git('status','--porcelain','--untracked-files=no')==='';
 assert.equal(require('@playwright/test/package.json').version,'1.55.0','Accepted protocol requires pinned Playwright 1.55.0');
 if(samples===30&&warmups===3&&cold===3)assert.ok(toolingTrackedClean,'Baseline tooling must match its clean tracked HEAD');
 const browser=await playwright[engine].launch({headless:true});
 const rawFile=path.join(output,'samples.jsonl');fs.writeFileSync(rawFile,'');const records=[],environments=[];
 const manifest={schema:2,protocol,started_at:new Date().toISOString(),productSha,productTree:input.tree,toolingSha:git('rev-parse','HEAD'),datasetSha:journey==='reply'?digest(JSON.stringify({dataset,datasetSha,aiText,editedAiText})):datasetSha,dataset:{inbox:200,initialMessages:200,availableHistory:600,refreshAppend:journey==='workspace'?1:0,role:'manager',manager_id:7,synthetic:true},method:{purpose:samples===30&&warmups===3&&cold===3?'baseline':'protocol_check',journey,recordsPerCycle,expectedRawRecords:viewports.length*(cold+samples*recordsPerCycle),samples,warmups,cold,engine,viewports,httpCache:'disabled by Playwright routing',warm:'same page/context after discarded whole-path warmups; no HTTP-cache warming claim',coldDescription:'fresh browser context/page; shared browser process/OS cache uncontrolled',cpuThrottle:'none',network:'loopback synthetic interception; bridge overhead included',timestamps:'browser performance.now; captured event or armed canonical command to first matching state plus two animation frames; command completion required for history/refresh',milestones:'first RAF observing transcript/authorized reply/card state; main duration additionally waits two animation frames',instrumentation:'fetch JSON timestamps and reserialization byte count; resource/longtask observers and state polling; observer overhead included',order:journey==='reply'?'prepared then generated/lost-ack cohorts: open, AI preview, validated insert, native edit, explicit send, optional receipt, full-cycle span; no randomized crossover':'fixed 14-phase whole-path cycles; viewports in supplied order; no randomized crossover',fullCycle:journey==='reply'?'open event through confirmed transcript; includes driver gaps and phase verification; aggregate overlaps its phases':'not_measured',modelAndChannelLatency:'not_measured: synthetic immediate responses and one deliberate lost acknowledgement',humanThinkTime:'not_measured: automated immediate edits and clicks',p95:'not_measured',serverSQL:'not_measured',productionBrowser:'not_measured',realCustomerOrProviderSends:0,productionDataWrites:0},node:process.version,playwright:require('@playwright/test/package.json').version,browserVersion:browser.version(),platform:{os:os.platform(),release:os.release(),arch:os.arch(),cpuCount:os.cpus().length,cpuModel:os.cpus()[0]?.model,totalMemory:os.totalmem(),loadAverage:os.loadavg()},input:{shell_sha256:input.shell_sha256,assets:input.assets,worker:input.worker},scriptHashes:Object.fromEntries(['tools/measure_manager2_browser.js','tools/fixtures/manager2_browser_measurement.js','tests/visual/fixtures/personal-workspace.js'].map(p=>[p,digest(fs.readFileSync(path.join(root,p)))]))};
 manifest.toolingTrackedClean=toolingTrackedClean;
 const candidatePath='manager/assets/workspace-v2-conversation.js',candidateSource=journey==='open'?fs.readFileSync(path.join(root,candidatePath)):null;
 if(journey==='open'){
  const baseline=input.assets.find(asset=>asset.path===candidatePath);assert.ok(baseline);
  manifest.candidate={path:candidatePath,sha256:digest(candidateSource),baseline_sha256:baseline.sha256,sourceSha:manifest.toolingSha,override:'Only this canonical asset; verified installed shell, worker and all other assets unchanged'};
  manifest.method.expectedRawRecords=viewports.length*(2*cold+samples*recordsPerCycle);
  manifest.method.order='Paired baseline/candidate contexts on the same runner; order alternates per sample, starting baseline; B→A native row click in each cohort; warmups discarded per cohort';
  manifest.method.modelAndChannelLatency='not_measured: no AI, send or receipt action in opening comparison';
 }
 json(path.join(output,'manifest.json'),manifest);
 experimentWatchdog=setTimeout(()=>{json(path.join(output,'watchdog-failure.json'),{ok:false,time:new Date().toISOString(),lastOperation,error:'Experiment watchdog timeout; partial artifacts are not a baseline'});process.stderr.write('Experiment watchdog timeout at '+lastOperation+'\n');process.exit(1)},manifest.method.purpose==='protocol_check'?180000:960000);
 const save=record=>{records.push(record);fs.appendFileSync(rawFile,JSON.stringify(record)+'\n')};
 try{for(const viewport of viewports){
  if(journey==='open'){
   const prepare=async cohort=>{
    const context=await browser.newContext({viewport,deviceScaleFactor:1,timezoneId:'Europe/Moscow'}),page=await context.newPage();page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(15000);await page.addInitScript(installMeter);
    const state=await setup(page,{sourceRoot:assets,beforeNavigate:cohort==='candidate'?async p=>{
     await p.route('**/manager/assets/workspace-v2-conversation.js*',route=>route.fulfill({contentType:'application/javascript',body:candidateSource}));
    }:undefined});await startup(page);return {context,page,state,cohort};
   };
   for(const cohort of ['baseline','candidate'])for(let i=0;i<cold;i++){
    const {context,page,state}=await prepare(cohort);save({...await page.evaluate(()=>window.__managerMeasurement.record()),name:cohort+'_startup',cohort,protocol,engine,viewport,sample:i,cacheMode:'fresh_context',synthetic:true});
    assert.deepEqual(state.rejected,[]);assert.deepEqual(state.unexpected,[]);assert.deepEqual(state.errors,[]);environments.push({cohort,...await page.evaluate(()=>window.__managerMeasurement.environment())});await bounded(()=>context.close(),'close_context');
   }
   const cohorts=[await prepare('baseline'),await prepare('candidate')];
   for(let i=-warmups;i<samples;i++){
    const ordered=(i>=0?i:i+warmups)%2?[...cohorts].reverse():cohorts;
    for(const {page,state,cohort} of ordered){const record=await openSample(page,state,cohort,i);if(i>=0)save({...record,protocol,engine,viewport,cacheMode:'same_page_after_warmup'});process.stdout.write(JSON.stringify({protocol,engine,viewport,cohort,sample:i,duration:record.duration})+'\n')}
   }
   for(const {context,page,cohort} of cohorts){await page.screenshot({path:path.join(output,'final-'+cohort+'-'+viewport.width+'x'+viewport.height+'.png'),animations:'disabled'});await bounded(()=>context.close(),'close_context')}
   continue;
  }
  for(let i=0;i<cold;i++){const context=await browser.newContext({viewport,deviceScaleFactor:1,timezoneId:'Europe/Moscow'}),page=await context.newPage();page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(15000);await page.addInitScript(installMeter);const state=await setup(page,{sourceRoot:assets,journey});await startup(page);save({...await page.evaluate(()=>window.__managerMeasurement.record()),protocol,engine,viewport,sample:i,cacheMode:'fresh_context',synthetic:true});assert.deepEqual(state.rejected,[]);assert.deepEqual(state.unexpected,[]);assert.deepEqual(state.errors,[]);environments.push(await page.evaluate(()=>window.__managerMeasurement.environment()));await bounded(()=>context.close(),'close_context')}
  const context=await browser.newContext({viewport,deviceScaleFactor:1,timezoneId:'Europe/Moscow'}),page=await context.newPage();page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(15000);await page.addInitScript(installMeter);const state=await setup(page,{sourceRoot:assets,journey});await startup(page);
  for(let i=-warmups;i<samples;i++){const result=await (journey==='reply'?replyCycle:cycle)(page,state,i);assert.equal(result.length,recordsPerCycle);if(i>=0)for(const record of result)save({...record,protocol,engine,viewport,cacheMode:'same_page_after_warmup'});process.stdout.write(JSON.stringify({protocol,engine,viewport,sample:i,operations:result.length})+'\n')}
  await page.screenshot({path:path.join(output,'final-'+viewport.width+'x'+viewport.height+'.png'),animations:'disabled'});await bounded(()=>context.close(),'close_context');
 }}catch(error){json(path.join(output,'primary-error.json'),{ok:false,time:new Date().toISOString(),operation:lastOperation,error:error.message,stack:error.stack});throw error}finally{try{await bounded(()=>browser.close(),'close_browser')}catch(error){json(path.join(output,'cleanup-failure.json'),{ok:false,time:new Date().toISOString(),error:error.message});process.stderr.write(error.stack+'\n');process.exit(1)}}
 clearTimeout(experimentWatchdog);
 assert.equal(records.length,manifest.method.expectedRawRecords);
 if(journey==='open')json(path.join(output,'opening-comparison.json'),{protocol,comparisons:openingComparisons(records),sign:'Positive delta means candidate faster; bounded descriptive observations, no stable tail or SLA'});
 const summary={ok:true,protocol,purpose:manifest.method.purpose,completed_at:new Date().toISOString(),raw_samples_sha256:digest(fs.readFileSync(rawFile)),raw_records:records.length,groups:summarise(records),environments,scope:'Synthetic installed-assets browser baseline only; production browser/API/SQL/model/channel/human time and stable P95 not measured'};json(path.join(output,'summary.json'),summary);process.stdout.write('M2_BROWSER_MEASUREMENT '+JSON.stringify({ok:true,protocol,engine,records:records.length,raw_sha256:summary.raw_samples_sha256})+'\n');
}
main().catch(error=>{clearTimeout(experimentWatchdog);json(path.join(output,'failure.json'),{ok:false,time:new Date().toISOString(),name:error.name,message:error.message,stack:error.stack});process.stderr.write(error.stack+'\n');process.exitCode=1});
