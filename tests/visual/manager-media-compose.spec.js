const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
const shell=execFileSync('php',['manager2/index.php'],{cwd:root,encoding:'utf8'});
async function setup(page,{failSecond=false,unknown=false,unknownSecond=false,holdFirst=false,suspendedSecond=false,failedRefresh=false,allowTextSend=false,unknownAt=0,lostAckAt=0,notFoundAt=0,holdReceipt=false}={}){
 const uploads=[],uploadFiles=[],texts=[],errors=[],held=[],mediaReads=[],mediaCancels=[],attempts=new Map(),detailOverrides=new Map();let lists=0,details=0;
 const manager={id:7,display_name:'Мария',role:'manager',is_working:true};
 const conversation=id=>({id,customer_id:id,display_name:id===11?'Анна':'Алексей',manager_id:manager.id,status:'manager',channel:'telegram'});
 // Current host Chrome can omit binary multipart postData from interception.
 // Observe the actual FormData before native fetch, without replacing dispatch.
 await page.addInitScript(()=>{window.mediaUploadProof=[];window.mediaUploadObserved=0;const fetch=window.fetch;window.fetch=function(url,options){if(String(url).endsWith('media-upload.php')&&options?.body instanceof FormData){const fields={};for(const [key,value] of options.body)fields[key]=value instanceof File?{name:value.name,size:value.size,type:value.type,lastModified:value.lastModified}:value;const file=options.body.get('file');fields.fileBytes=file instanceof File?file.arrayBuffer().then(buffer=>Array.from(new Uint8Array(buffer))):Promise.resolve(null);mediaUploadProof.push(fields)}return fetch.apply(this,arguments)}});
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url());
  const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
  if(url.origin!==origin)throw new Error('Forbidden external request '+url);
  if(url.pathname.startsWith('/manager/assets/')||url.pathname.startsWith('/manager2/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(root,url.pathname.slice(1)))});
  if(url.pathname==='/manager2/')return route.fulfill({contentType:'text/html',body:shell});
  if(url.protocol==='blob:'&&url.origin===origin)return route.continue();
  if(request.method()==='GET'&&url.pathname==='/manager/customer-avatar.php'){expect([...url.searchParams.keys()]).toEqual(['conversation_id','account','channel']);expect(url.searchParams.get('account')).toBe(String(manager.id));expect(url.searchParams.get('channel')).toBe(conversation(Number(url.searchParams.get('conversation_id'))).channel);expect(['11','12']).toContain(url.searchParams.get('conversation_id'));return route.fulfill({status:404,body:''})}
  if(url.pathname==='/manager/media-upload.php'){
   const observed=await page.evaluate(async()=>{const proof=mediaUploadProof[mediaUploadObserved++];return{...proof,fileBytes:await proof.fileBytes}}),raw=request.postDataBuffer()?.toString();expect(observed.file.size).toBeGreaterThan(0);uploadFiles.push({file:observed.file,bytes:observed.fileBytes,caption:observed.caption});uploads.push(raw||JSON.stringify(observed));
   // Native multipart serialization uses CRLF for scalar field line breaks.
   const field=name=>raw?(raw.match(new RegExp('name="'+name+'"\\r\\n\\r\\n([\\s\\S]*?)\\r\\n--'))?.[1]||'').replace(/\r\n/g,'\n'):observed[name]||'';
   for(const name of ['operation_id','expected_manager_id','conversation_id','caption'])expect(field(name)).toBe(observed[name]);
   const operation=field('operation_id');expect(field('expected_manager_id')).toBe(String(manager.id));expect(operation).toMatch(/^[a-f0-9]{32}$/);
   const confirmed={kind:'media',state:'confirmed',media_type:'file',caption_included:!!field('caption')};
   const respond=(state,body={},status=200)=>{const attempt=state==='confirmed'?confirmed:{kind:'media',state};attempts.set(operation,attempt);return reply({ok:state==='confirmed',attempt,...body},status)};
   if(holdFirst&&uploads.length===1)await new Promise(resolve=>held.push(resolve));
   if(notFoundAt===uploads.length)return route.abort();
   if(lostAckAt===uploads.length){attempts.set(operation,confirmed);return route.abort()}
   if((unknown&&uploads.length===1)||(unknownSecond&&uploads.length===2)||unknownAt===uploads.length){attempts.set(operation,{kind:'media',state:'unknown'});return route.abort()}
   if(suspendedSecond&&uploads.length===2)return respond('rejected',{error:'delivery_failed',failure:{category:'suspended',http_code:403,notice:'Диалог приостановлен'},error_message:'Диалог приостановлен'});
   if(failSecond&&uploads.length===2)return reply({ok:false,error:'file_too_large'},400);
   return respond('confirmed');
  }
  const d=request.method()==='POST'?request.postDataJSON()||{}:{};
  if(request.method()==='POST'&&url.pathname==='/manager/personal-workspace-api.php'&&d.action==='snapshot'&&d.csrf==='fixture')return reply(require('./fixtures/personal-workspace-default').emptyPersonalWorkspaceSnapshot());
  if(request.method()==='POST'&&url.pathname==='/manager/post-context-api.php'&&d.action==='detail'&&d.csrf==='fixture'&&[11,12].includes(d.conversation_id))return reply({ok:true,conversation_id:d.conversation_id,context:{current:null,history:[]}});
  if(request.method()==='POST'&&url.pathname==='/manager/quick-replies-api.php'&&d.action==='toolbar'&&d.csrf==='fixture')return reply({ok:true,replies:[],version:'0'.repeat(64),max_replies:100});
  if(url.pathname==='/manager/api.php'){
   if(['media_receipt','media_cancel'].includes(d.action)){
    expect(d.csrf).toBe('fixture');expect(d.expected_manager_id).toBe(manager.id);expect(d.operation_id).toMatch(/^[a-f0-9]{32}$/);expect([11,12]).toContain(d.conversation_id);
    if(d.action==='media_receipt'){mediaReads.push(d);if(holdReceipt)await new Promise(resolve=>held.push(resolve));return reply({ok:true,attempt:attempts.get(d.operation_id)||{kind:'media',state:'not_found'}})}
    mediaCancels.push(d);if(!attempts.has(d.operation_id))attempts.set(d.operation_id,{kind:'media',state:'cancelled'});return reply({ok:true,attempt:attempts.get(d.operation_id)});
   }
   if(['me','login'].includes(d.action))return reply({ok:true,manager,csrf:'fixture',projects:[]});
   if(d.action==='logout')return reply({ok:true});
   if(d.action==='counts')return reply({ok:true,counts:{mine:{count:2}}});
   if(d.action==='detail'){details++;if(failedRefresh&&details>1)return reply({ok:false,error:'temporary_failure'},503);const override=detailOverrides.get(d.conversation_id);if(override?.httpStatus)return reply({ok:false,error:'forbidden'},override.httpStatus);return reply({ok:true,conversation:{...conversation(d.conversation_id),...override},messages:[{id:1,sender_type:'customer',text:'Здравствуйте!',created_at:'2026-10-14 08:00:00'}]})}
   if(d.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
   if(d.action==='send'&&allowTextSend){expect(d.csrf).toBe('fixture');expect(d.conversation_id).toBe(11);expect(d.operation_id).toMatch(/^[a-f0-9]{32}$/);texts.push(d);return reply({ok:true})}
   throw new Error('Unexpected API '+d.action);
  }
  if(url.pathname==='/manager/pipeline-api.php'){
   if(d.action==='catalog')return reply({ok:true,stages:[],tags:[],outcomes:{open:'В работе'},close_reasons:{}});
   if(d.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager]}});
   if(d.action==='list'){lists++;return reply({ok:true,conversations:[11,12].map(id=>({...conversation(id),last_text:'Здравствуйте!',can_edit_pipeline:true}))})}
   if(d.action==='detail')return reply({ok:true,can_edit_pipeline:true,pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},tasks:[],trip:{},contact:{},source:{},handoff:{}});
  }
  if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
  if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
  if(url.pathname==='/manager/push.php')return reply({ok:true,public_key:''});
  if(url.pathname==='/manager/sw.js')return route.fulfill({contentType:'application/javascript',body:''});
  if(url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
  throw new Error('Unexpected endpoint '+url.pathname);
 });
 await page.goto(origin+'/manager2/');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);await page.locator('[data-conversation-id="11"]').click();await expect(page.locator('#composer')).toBeVisible();
 return{uploads,uploadFiles,texts,errors,mediaReads,mediaCancels,attempts,setAttempt:(operation,attempt)=>attempts.set(operation,attempt),switchManager:next=>Object.assign(manager,next),setDetail:(id,value)=>detailOverrides.set(id,value),release:()=>held.shift()?.(),lists:()=>lists};
}
const files=[{name:'Маршрут.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic')},{name:'Билеты.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF synthetic')}];
async function observeDraftDatabase(page){
 await page.addInitScript(()=>{
  const proof=window.mediaDraftDatabaseProof={opens:0,commits:0,aborts:0,reads:0,failWrites:false,errors:[]},transaction=IDBDatabase.prototype.transaction,get=IDBObjectStore.prototype.get,open=IDBFactory.prototype.open;
  IDBFactory.prototype.open=function(...args){const request=open.apply(this,args);if(args[0]==='workspaceV2.mediaDrafts.v1'){proof.opens++;request.addEventListener('error',()=>proof.errors.push('open: '+request.error?.name+': '+request.error?.message));request.addEventListener('blocked',()=>proof.errors.push('open: blocked'))}return request};
  IDBDatabase.prototype.transaction=function(...args){const tx=transaction.apply(this,args);if(this.name==='workspaceV2.mediaDrafts.v1'&&tx.mode==='readwrite'){tx.addEventListener('complete',()=>proof.commits++);tx.addEventListener('abort',()=>{proof.aborts++;if(tx.error)proof.errors.push(tx.error.name+': '+tx.error.message)})}return tx};
  for(const method of ['put','add']){const native=IDBObjectStore.prototype[method];IDBObjectStore.prototype[method]=function(...args){const draft=this.transaction.db.name==='workspaceV2.mediaDrafts.v1';if(draft&&proof.failWrites){this.transaction.abort();throw new DOMException('Synthetic browser storage quota','QuotaExceededError')}try{return native.apply(this,args)}catch(error){if(draft)proof.errors.push(method+': '+error.name+': '+error.message);throw error}}}
  IDBObjectStore.prototype.get=function(...args){if(this.transaction.db.name==='workspaceV2.mediaDrafts.v1')proof.reads++;return get.apply(this,args)};
 });
}
async function savedFiles(page,names){
 try{await expect.poll(()=>page.evaluate(async()=>{const W=window.WorkspaceV2,stored=await window.WorkspaceV2MediaDrafts?.load(W.S.manager.id,W.S.current),names=stored?.rows?.map(row=>row.file?.name)||[];return names.length?names:{names,nativeErrors:window.mediaDraftDatabaseProof?.errors||[]}}),{message:'Native IndexedDB must commit every selected file before reload'}).toEqual(names)}catch(error){const proof=await page.evaluate(async()=>({database:window.mediaDraftDatabaseProof,chosen:await window.mediaChosenFiles,manifest:sessionStorage.getItem('workspaceV2.mediaDrafts.v1.'+WorkspaceV2.S.manager.id)}));throw new Error(error.message+'\nNative file storage evidence: '+JSON.stringify(proof))}
}
async function rememberChosenFiles(page){
 await page.evaluate(()=>document.addEventListener('change',event=>{if(event.target.id==='replyFile')window.mediaChosenFiles=Promise.all(Array.from(event.target.files,file=>file.arrayBuffer().then(buffer=>({file:{name:file.name,size:file.size,type:file.type,lastModified:file.lastModified},bytes:Array.from(new Uint8Array(buffer))}))))},{capture:true}));
}
async function holdDraftReload(page){
 await page.addInitScript(()=>{let api;Object.defineProperty(window,'WorkspaceV2MediaDrafts',{configurable:true,get:()=>api,set(value){api=value;const load=value.load;value.load=function(actor,cid){const pending=load.apply(this,arguments);if(cid!==11||sessionStorage.getItem('syntheticHoldDraftRead')!=='yes'||window.releaseMediaDraftLoad)return pending;return pending.then(saved=>new Promise(resolve=>{window.releaseMediaDraftLoad=()=>{sessionStorage.removeItem('syntheticHoldDraftRead');resolve(saved)}}))}}})});
 await page.evaluate(()=>sessionStorage.setItem('syntheticHoldDraftRead','yes'));await page.reload();await expect.poll(()=>page.evaluate(()=>typeof window.releaseMediaDraftLoad)).toBe('function');
}
async function blockDraftWrites(page){
 await page.evaluate(()=>new Promise((resolve,reject)=>{const opening=indexedDB.open('workspaceV2.mediaDrafts.v1',1);opening.onerror=()=>reject(opening.error);opening.onsuccess=()=>{const db=opening.result,tx=db.transaction(['blobs','usage'],'readwrite'),usage=tx.objectStore('usage'),deadline=performance.now()+2500;let released=false;window.releaseDraftWriteBlocker=()=>{released=true};tx.oncomplete=()=>db.close();const keepAlive=()=>{const request=usage.get('synthetic-write-blocker');request.onsuccess=()=>{if(!released&&performance.now()<deadline)keepAlive()}};keepAlive();resolve()}}));
}
for(const width of [390,430,768,1440])test(`attachment draft reload restores native bytes order and exact text ${width}`,async({page,browserName},testInfo)=>{
 await page.setViewportSize({width,height:width===390?420:900});await observeDraftDatabase(page);const s=await setup(page);await rememberChosenFiles(page);
 const draft='  Точный черновик 🌍\nПодпись с пробелами  ',chosen=[{name:'Одинаковое имя.bin',mimeType:'application/octet-stream',buffer:Buffer.from([0,255,17,128,0,34])},{name:'Одинаковое имя.bin',mimeType:'application/octet-stream',buffer:Buffer.from([34,0,128,17,255,0])},{name:'План.txt',mimeType:'text/plain',buffer:Buffer.from('Маршрут\nс точными байтами 🌍')}];
 // Seed literal whitespace through the canonical input handler. Host WebKit's
 // native insertion changes the first of two leading spaces before persistence.
 await page.locator('#replyText').evaluate((node,text)=>{node.value=text;node.dispatchEvent(new Event('input',{bubbles:true}))},draft);await page.locator('#replyFile').setInputFiles(chosen);const expected=await page.evaluate(()=>mediaChosenFiles);await savedFiles(page,chosen.map(file=>file.name));expect(await page.locator('#replyText').inputValue(),'Exact native input before reload').toBe(draft);
 expect(await page.evaluate(()=>mediaDraftDatabaseProof.commits)).toBeGreaterThan(0);expect(s.uploads).toHaveLength(0);
 await page.reload();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('.mediaFileName')).toHaveText(chosen.map(file=>file.name));await expect(page.locator('#replyText')).toHaveValue(draft);expect(s.uploads).toHaveLength(0);expect(s.mediaReads).toHaveLength(0);expect(s.texts).toHaveLength(0);
 expect(await page.evaluate(async()=>{const stored=await WorkspaceV2MediaDrafts.load(7,11);return Promise.all(stored.rows.map(async row=>({file:row.file instanceof File,blob:row.file instanceof Blob,bytes:Array.from(new Uint8Array(await row.file.arrayBuffer()))})))})).toEqual(expected.map(row=>({file:true,blob:true,bytes:row.bytes})));
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);await expect(page.locator('#sendReply')).toBeInViewport();await expect(page.locator('#replyText')).toBeInViewport();await expect(page.locator('#mediaDraftStatus')).toContainText(/восстановлен|сохранён/);await expect(page.locator('#mediaDraftStatus')).toBeInViewport({ratio:1});await expect(page.locator('#mediaSelection #mediaDraftStatus')).toHaveCount(0);
 fs.mkdirSync('manager-media-artifacts',{recursive:true});const image=await page.screenshot({path:`manager-media-artifacts/media-draft-${browserName}-${width}-restored.png`});await testInfo.attach('media-draft-restored',{body:image,contentType:'image/png'});
 await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено');expect(s.uploadFiles).toEqual(expected.map((row,index)=>({...row,caption:index===0?draft.trim():''})));expect(s.uploads).toHaveLength(3);
 await page.reload();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('.mediaSelectedItem')).toHaveCount(0);await expect(page.locator('#replyText')).toHaveValue('');expect(s.uploads).toHaveLength(3);expect(s.errors).toEqual([]);
});
test('attachment drafts stay separate between clients through real reload and authentication recovery',async({page})=>{
 const s=await setup(page);await page.locator('#replyText').fill('Точный текст Анне');await page.locator('#replyFile').setInputFiles([files[0]]);await savedFiles(page,['Маршрут.txt']);
 await page.locator('[data-conversation-id="12"]').click();await expect(page.locator('.mediaSelectedItem')).toHaveCount(0);await page.locator('#replyText').fill('Другой текст Алексею');await page.locator('#replyFile').setInputFiles([files[1]]);await savedFiles(page,['Билеты.pdf']);
 await page.reload();await expect(page.locator('#conversationTitle')).toHaveText('Алексей');await expect(page.locator('#replyText')).toHaveValue('Другой текст Алексею');await expect(page.locator('.mediaFileName')).toHaveText('Билеты.pdf');
 await page.evaluate(()=>WorkspaceV2.showAuthRecovery());await expect(page.locator('#composer')).toBeHidden();await page.locator('#managerAuthLogin').fill('synthetic-manager');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Другой текст Алексею');await expect(page.locator('.mediaFileName')).toHaveText('Билеты.pdf');
 await page.locator('[data-conversation-id="11"]').click();await expect(page.locator('#replyText')).toHaveValue('Точный текст Анне');await expect(page.locator('.mediaFileName')).toHaveText('Маршрут.txt');expect(s.uploads).toHaveLength(0);expect(s.texts).toHaveLength(0);expect(s.errors).toEqual([]);
});
for(const denied of ['another actor','403','other owner','closed'])test(`attachment restoration waits for current editable authorization: ${denied}`,async({page})=>{
 await observeDraftDatabase(page);const s=await setup(page);await page.locator('#replyText').fill('Приватный черновик');await page.locator('#replyFile').setInputFiles([files[0]]);await savedFiles(page,['Маршрут.txt']);
 if(denied==='another actor')s.switchManager({id:8,display_name:'Ольга'});else s.setDetail(11,denied==='403'?{httpStatus:403}:denied==='other owner'?{manager_id:8}:{status:'closed'});
 await page.reload();await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.booting)).toBe(false);await expect(page.locator('#composer')).toBeHidden();await expect(page.locator('.mediaSelectedItem')).toHaveCount(0);await expect(page.locator('#replyText')).toHaveValue('');expect(await page.evaluate(()=>mediaDraftDatabaseProof.reads)).toBe(0);expect(s.uploads).toHaveLength(0);expect(s.texts).toHaveLength(0);expect(s.errors).toEqual([]);
});
test('an untouched AI preview is not restored as human draft beside saved attachments',async({page})=>{
 const s=await setup(page);await page.locator('#replyFile').setInputFiles([files[0]]);await savedFiles(page,['Маршрут.txt']);
 expect(await page.evaluate(()=>{const shown=WorkspaceV2Conversation.previewAiDraft('Непроверенный текст AI',WorkspaceV2Conversation.captureReplyContext(),'synthetic-preview');return{shown,value:document.getElementById('replyText').value,humanText:JSON.parse(sessionStorage.getItem('workspaceV2.replySession.v1')).drafts.some(d=>d.text)}})).toEqual({shown:true,value:'Непроверенный текст AI',humanText:false});
 await page.reload();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('.mediaFileName')).toHaveText('Маршрут.txt');await expect(page.locator('#replyText')).toHaveValue('');expect(s.uploads).toHaveLength(0);expect(s.texts).toHaveLength(0);expect(s.errors).toEqual([]);
});
test('an aborted native file transaction retains RAM draft and never claims it was saved',async({page})=>{
 await page.setViewportSize({width:390,height:420});await observeDraftDatabase(page);const s=await setup(page);await page.locator('#replyText').fill('Текст остаётся в черновике');await page.evaluate(()=>mediaDraftDatabaseProof.failWrites=true);await page.locator('#replyFile').setInputFiles([files[0]]);
 await expect(page.locator('#mediaDraftStatus')).toContainText('Вложения не сохранены');await expect(page.locator('#mediaDraftStatus')).not.toContainText('Черновик с вложениями сохранён');await expect(page.locator('.mediaFileName')).toHaveText('Маршрут.txt');await expect(page.locator('#replyText')).toHaveValue('Текст остаётся в черновике');expect(s.uploads).toHaveLength(0);
 await expect.poll(()=>page.evaluate(()=>mediaDraftDatabaseProof.aborts)).toBeGreaterThan(0);await page.evaluate(()=>mediaDraftDatabaseProof.failWrites=false);await blockDraftWrites(page);await page.locator('#replyFile').setInputFiles([files[1]]);await expect(page.locator('#mediaDraftStatus')).toContainText('Сохраняем');
 await page.evaluate(()=>{const box=document.getElementById('mediaSelection'),button=box.querySelectorAll('.mediaRemove')[1];box.scrollTop=box.scrollHeight;button.focus({preventScroll:true});window.mediaCommitDom={box,button,top:box.scrollTop};releaseDraftWriteBlocker()});await savedFiles(page,['Маршрут.txt','Билеты.pdf']);await expect(page.locator('#mediaDraftStatus')).toContainText('Черновик с вложениями сохранён');
 expect(await page.evaluate(()=>{const p=mediaCommitDom;return{list:p.box===document.getElementById('mediaSelection'),button:p.button===p.box.querySelectorAll('.mediaRemove')[1],focus:document.activeElement===p.button,scroll:Math.abs(p.box.scrollTop-p.top)<1}})).toEqual({list:true,button:true,focus:true,scroll:true});
 await page.reload();await expect(page.locator('.mediaFileName')).toHaveText(['Маршрут.txt','Билеты.pdf']);await expect(page.locator('#replyText')).toHaveValue('Текст остаётся в черновике');expect(s.uploads).toHaveLength(0);expect(s.errors).toEqual([]);
});
test('a late native attachment load cannot replace the current client reply or new files',async({page})=>{
 const s=await setup(page);await page.locator('#replyText').fill('Исходный текст Анне');await page.locator('#replyFile').setInputFiles([files[0]]);await savedFiles(page,['Маршрут.txt']);
 await holdDraftReload(page);await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyFile')).toBeDisabled();
 await page.locator('[data-conversation-id="12"]').click();await expect(page.locator('#conversationTitle')).toHaveText('Алексей');await page.locator('#replyText').fill('Новый точный текст Алексею');await page.locator('#replyFile').setInputFiles([files[1]]);await savedFiles(page,['Билеты.pdf']);await page.evaluate(async()=>{releaseMediaDraftLoad();await Promise.resolve();await Promise.resolve()});
 await expect(page.locator('#replyText')).toHaveValue('Новый точный текст Алексею');await expect(page.locator('.mediaFileName')).toHaveText('Билеты.pdf');await expect(page.locator('#conversationTitle')).toHaveText('Алексей');expect(s.uploads).toHaveLength(0);expect(s.errors).toEqual([]);
});
test('pasting a new native File during restoration releases controls and defeats the stale load',async({page})=>{
 const s=await setup(page);await page.locator('#replyFile').setInputFiles([files[0]]);await savedFiles(page,['Маршрут.txt']);await holdDraftReload(page);await expect(page.locator('#replyFile')).toBeDisabled();await page.locator('#replyText').fill('Новый текст во время восстановления');
 await page.evaluate(()=>{const data=new DataTransfer();data.items.add(new File(['Pasted native file'],'Вставленный.txt',{type:'text/plain'}));document.getElementById('replyText').dispatchEvent(new ClipboardEvent('paste',{clipboardData:data,bubbles:true,cancelable:true}))});
 await expect(page.locator('.mediaFileName')).toHaveText('Вставленный.txt');await expect(page.locator('#sendReply')).toBeEnabled();await expect(page.locator('#replyFile')).toBeEnabled();await savedFiles(page,['Вставленный.txt']);await page.evaluate(async()=>{releaseMediaDraftLoad();await Promise.resolve();await Promise.resolve()});
 await expect(page.locator('.mediaFileName')).toHaveText('Вставленный.txt');await expect(page.locator('#replyText')).toHaveValue('Новый текст во время восстановления');await expect(page.locator('#sendReply')).toBeEnabled();await expect(page.locator('#replyFile')).toBeEnabled();expect(s.uploads).toHaveLength(0);
 await page.reload();await expect(page.locator('.mediaFileName')).toHaveText('Вставленный.txt');await expect(page.locator('#replyText')).toHaveValue('Новый текст во время восстановления');expect(s.uploads).toHaveLength(0);expect(s.errors).toEqual([]);
});
test('removing a saved file while a new blob transaction waits cannot resurrect it on reload',async({page})=>{
 const s=await setup(page);await page.locator('#replyText').fill('Текст до удаления');await page.locator('#replyFile').setInputFiles([files[0]]);await savedFiles(page,['Маршрут.txt']);
 await blockDraftWrites(page);
 await page.locator('#replyFile').setInputFiles([files[1]]);await expect(page.locator('#mediaDraftStatus')).toContainText('Сохраняем');await page.locator('.mediaRemove').first().click();await expect(page.locator('.mediaFileName')).toHaveText('Билеты.pdf');
 const manifest=await page.evaluate(()=>sessionStorage.getItem('workspaceV2.mediaDrafts.v1.7'));expect(manifest).not.toContain('Маршрут.txt');await page.reload();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('#mediaSelection')).not.toContainText('Маршрут.txt');await expect(page.locator('.mediaSelectionHint')).toContainText('Выберите отсутствующие файлы заново');await expect(page.locator('#replyText')).toHaveValue('Текст до удаления');expect(s.uploads).toHaveLength(0);expect(s.errors).toEqual([]);
});
test('a confirmed operation removes only its exact row from a stale persisted draft',async({page})=>{
 const s=await setup(page,{holdFirst:true});await page.locator('#replyText').fill('Подпись подтверждённого файла');await page.locator('#replyFile').setInputFiles([files[0]]);await savedFiles(page,['Маршрут.txt']);await page.locator('#sendReply').click();await expect.poll(()=>s.uploads.length).toBe(1);
 const linked=await page.evaluate(()=>({manifest:sessionStorage.getItem('workspaceV2.mediaDrafts.v1.7'),operation:mediaUploadProof[0].operation_id}));expect(linked.manifest).toContain(linked.operation);
 s.release();await expect(page.locator('#replyStatus')).toContainText('Отправлено');await page.evaluate(value=>sessionStorage.setItem('workspaceV2.mediaDrafts.v1.7',value),linked.manifest);
 await page.reload();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('.mediaSelectedItem')).toHaveCount(0);await expect(page.locator('#replyText')).toHaveValue('');expect(s.uploads).toHaveLength(1);expect(s.errors).toEqual([]);
});
for(const width of [390,430,768,1440])test(`durable media recovery keeps each result after reload ${width}`,async({page,browserName},testInfo)=>{
 await page.setViewportSize({width,height:900});const s=await setup(page,{unknownAt:3});
 const three=[...files,{name:'Третий.txt',mimeType:'text/plain',buffer:Buffer.from('Third synthetic file')}];
 await page.locator('#replyText').fill('Подпись только один раз');await page.locator('#replyFile').setInputFiles(three);await page.locator('#sendReply').click();
 await expect(page.locator('#replyStatus')).toContainText('Отправлено файлов: 2');await expect(page.locator('#replyText')).toHaveValue('');expect(s.uploads).toHaveLength(3);
 await page.reload();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);await expect(page.locator('.mediaFileName')).toHaveText('Третий.txt');await expect(page.locator('.mediaFileSize')).toContainText('Исход неизвестен');await expect(page.locator('#mediaRecovery summary')).toContainText('подтверждено 2, проверить 1');expect(s.uploads).toHaveLength(3);
 await page.locator('#replyText').fill('Новый черновик после обновления');await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('повтор заблокирован');expect(s.uploads).toHaveLength(3);
 // A new selection with identical metadata is not the original operation row.
 await page.locator('.mediaRemove').click();await page.locator('#replyFile').setInputFiles([three[2]]);
 await page.locator('#mediaRecovery summary').click();const row=page.locator('.mediaRecoveryRow').filter({hasText:'Третий.txt'});await expect(row).toContainText('Результат неизвестен');await row.getByRole('button',{name:'Проверить отправку',exact:true}).click();await expect(row).toContainText('Результат неизвестен');expect(s.uploads).toHaveLength(3);
 fs.mkdirSync('manager-media-artifacts',{recursive:true});const capture=async suffix=>{const image=await page.screenshot({path:`manager-media-artifacts/media-recovery-${browserName}-${width}-${suffix}.png`});await testInfo.attach('media-recovery-'+suffix,{body:image,contentType:'image/png'})};await capture('unknown');
 const operation=s.mediaReads.at(-1).operation_id;s.setAttempt(operation,{kind:'media',state:'confirmed',media_type:'file',caption_included:false});await row.getByRole('button',{name:'Проверить отправку',exact:true}).click();await expect(page.locator('#mediaRecovery summary')).toContainText('подтверждено 3');await expect(page.locator('#replyStatus')).toContainText('Отправка файла подтверждена');await expect(page.locator('#replyStatus')).not.toContainText('повтор заблокирован');await expect(page.locator('#replyText')).toHaveValue('Новый черновик после обновления');await expect(page.locator('.mediaFileName')).toHaveText('Третий.txt');expect(s.uploads).toHaveLength(3);
 await page.locator('.mediaRemove').click();await expect(page.locator('.mediaSelectedItem')).toHaveCount(0);expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);await capture('confirmed');expect(s.errors).toEqual([]);
});
test('lost media acknowledgement is checked after reload without consuming a newer caption',async({page})=>{
 const s=await setup(page,{lostAckAt:1});await page.locator('#replyText').fill('Исходная подпись');await page.locator('#replyFile').setInputFiles([files[0]]);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('неизвестен');await page.reload();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('.mediaFileName')).toHaveText('Маршрут.txt');await page.locator('#replyText').fill('Новый текст');await page.locator('#mediaRecovery summary').click();await page.getByRole('button',{name:'Проверить отправку',exact:true}).click();await expect(page.locator('#mediaRecovery')).toContainText('подпись отправлена');await expect(page.locator('#replyText')).toHaveValue('Новый текст');await expect(page.locator('.mediaSelectedItem')).toHaveCount(0);expect(s.uploads).toHaveLength(1);expect(s.mediaReads).toHaveLength(1);
});
test('missing media result requires explicit cancellation before a fresh send',async({page})=>{
 const s=await setup(page,{notFoundAt:1});await page.locator('#replyText').fill('Сохранить подпись');await page.locator('#replyFile').setInputFiles([files[0]]);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('неизвестен');await page.locator('#mediaRecovery summary').click();await page.getByRole('button',{name:'Проверить отправку',exact:true}).click();await expect(page.locator('#mediaRecovery')).toContainText('Подтверждение пока не найдено');await page.getByRole('button',{name:'Отменить неначатую отправку',exact:true}).click();await expect(page.locator('#mediaRecovery')).toContainText('Отправка не начиналась');await expect(page.locator('.mediaFileName')).toHaveText('Маршрут.txt');expect(s.uploads).toHaveLength(1);expect(s.mediaReads).toHaveLength(1);expect(s.mediaCancels).toHaveLength(1);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено');expect(s.uploads).toHaveLength(2);
});
test('media storage failure retains text and files without an upload',async({page})=>{
 await page.addInitScript(()=>{const set=Storage.prototype.setItem;Storage.prototype.setItem=function(k,v){if(String(k).startsWith('workspaceV2.mediaResults.'))throw new DOMException('Synthetic quota','QuotaExceededError');return set.call(this,k,v)}});
 const s=await setup(page);await page.locator('#replyText').fill('Не терять текст');await page.locator('#replyFile').setInputFiles([files[0]]);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('не начата');await expect(page.locator('#replyText')).toHaveValue('Не терять текст');await expect(page.locator('.mediaFileName')).toHaveText('Маршрут.txt');expect(s.uploads).toHaveLength(0);
});
test('a late media lookup for A cannot clear the new reply and files in B',async({page})=>{
 const s=await setup(page,{unknown:true,holdReceipt:true});await page.locator('#replyFile').setInputFiles([files[0]]);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('неизвестен');await page.locator('#mediaRecovery summary').click();await page.getByRole('button',{name:'Проверить отправку',exact:true}).click();await expect.poll(()=>s.mediaReads.length).toBe(1);
 await page.locator('[data-conversation-id="12"]').click();await expect(page.locator('#conversationTitle')).toHaveText('Алексей');await page.locator('#replyText').fill('Ответ Алексею');await page.locator('#replyFile').setInputFiles([{name:'Новый.txt',mimeType:'text/plain',buffer:Buffer.from('new reply file')}]);s.setAttempt(s.mediaReads[0].operation_id,{kind:'media',state:'confirmed',media_type:'file',caption_included:false});s.release();await expect(page.locator('#replyText')).toHaveValue('Ответ Алексею');await expect(page.locator('.mediaFileName')).toHaveText('Новый.txt');await expect(page.locator('#mediaRecovery')).toBeHidden();expect(s.uploads).toHaveLength(1);
});
for(const width of [390,430,768,1440])for(const height of [900,420])test(`selection ${width}x${height}`,async({page,browserName})=>{
 await page.setViewportSize({width,height});const s=await setup(page);await page.locator('#replyText').fill('Подпись к первому файлу');if(height===420){await page.locator('#m2ConversationMenuToggle').click();await page.locator('#conversationSearchOpen').click();await page.locator('#conversationSearchInput').fill('Здравствуйте');await page.locator('#replyText').focus()}
 fs.mkdirSync('manager-media-artifacts',{recursive:true});await page.screenshot({path:`manager-media-artifacts/${browserName}-${width}-${height}-before.png`});
 await page.locator('#replyFile').setInputFiles(files);await expect(page.locator('.mediaSelectedItem')).toHaveCount(2);expect(s.uploads).toHaveLength(0);
 await page.screenshot({path:`manager-media-artifacts/${browserName}-${width}-${height}-after.png`});
 const box=await page.locator('#replyText').boundingBox();expect(box.y+box.height).toBeLessThanOrEqual(height+1);const sendBox=await page.locator('#sendReply').boundingBox();expect(sendBox.y+sendBox.height).toBeLessThanOrEqual(height+1);expect((await page.locator('#messages').boundingBox()).height).toBeGreaterThanOrEqual(40);expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width+1);
 await page.locator('.mediaRemove').first().focus();await expect(page.locator('.mediaRemove').first()).toBeFocused();await page.locator('.mediaRemove').first().press('Enter');await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено');expect(s.uploads).toHaveLength(1);expect(s.uploads[0]).toContain('Билеты.pdf');expect(s.uploads[0]).toContain('Подпись к первому файлу');expect(s.errors).toEqual([]);
});
for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])test(`keyboard attachment removal preserves focus and original files ${viewport.width}x${viewport.height}`,async({page,browserName})=>{
 await page.setViewportSize(viewport);
 await page.addInitScript(()=>{
  const create=URL.createObjectURL.bind(URL),proof=window.mediaFocusProof={files:[],objects:new Map(),urls:new Map(),keys:[]};
  URL.createObjectURL=file=>{const url=create(file);proof.objects.set(url,file);return url};
  window.addEventListener('change',event=>{if(event.target.id==='replyFile')proof.files=Array.from(event.target.files)},{capture:true});
  window.addEventListener('keydown',event=>{if(['Tab','Enter'].includes(event.key))proof.keys.push({key:event.key,trusted:event.isTrusted})},{capture:true});
 });
 const s=await setup(page),reply=page.locator('#replyText'),draft='Точный черновик перед удалением вложений.\n  Сохраняем текст и выделение.';
 const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=','base64');
 await reply.fill(draft);await page.locator('#replyFile').setInputFiles(['A','B','C'].map(name=>({name:name+'.png',mimeType:'image/png',buffer:png})));
 await expect(page.locator('.mediaSelectedItem')).toHaveCount(3);
 await expect.poll(()=>page.locator('.mediaPreview img').evaluateAll(images=>images.every(img=>img.complete&&img.naturalWidth===1))).toBe(true);
 expect(await page.evaluate(()=>{const p=mediaFocusProof;for(const item of document.querySelectorAll('.mediaSelectedItem'))p.urls.set(item.querySelector('.mediaFileName').textContent,item.querySelector('img').src);return p.files.map(file=>file.name)})).toEqual(['A.png','B.png','C.png']);
 await reply.click();await reply.evaluate(el=>el.setSelectionRange(4,24,'backward'));
 const selection={start:4,end:24,direction:'backward'};
 const retained=async names=>{
  await expect(page.locator('.mediaFileName')).toHaveText(names);
  expect(await page.evaluate(()=>{const p=mediaFocusProof;return Array.from(document.querySelectorAll('.mediaSelectedItem'),item=>{const name=item.querySelector('.mediaFileName').textContent,url=item.querySelector('img').src;return{name,sameFile:p.objects.get(url)===p.files.find(file=>file.name===name),sameUrl:url===p.urls.get(name)}})})).toEqual(names.map(name=>({name,sameFile:true,sameUrl:true})));
  await expect(reply).toHaveValue(draft);expect(await reply.evaluate(el=>({start:el.selectionStart,end:el.selectionEnd,direction:el.selectionDirection}))).toEqual(selection);
  await expect(page.locator('#composer')).toBeVisible();await expect(reply).toBeVisible();await expect(reply).toBeEnabled();
  expect(await page.evaluate(()=>({current:WorkspaceV2.S.current,hasFile:WorkspaceV2Media.hasFile()}))).toEqual({current:11,hasFile:names.length>0});expect(s.uploads).toHaveLength(0);expect(s.errors).toEqual([]);
 };
 await page.keyboard.press('Tab');await expect(page.locator('.mediaRemove').nth(0)).toBeFocused();
 await page.keyboard.press('Tab');await expect(page.locator('.mediaRemove').nth(1)).toBeFocused();await expect(page.locator('.mediaRemove').nth(1)).toBeInViewport();
 fs.mkdirSync('manager-media-artifacts',{recursive:true});const capture=suffix=>page.screenshot({path:`manager-media-artifacts/${browserName}-${viewport.width}-${viewport.height}-keyboard-remove-${suffix}.png`});await capture('selected');
 await page.keyboard.press('Enter');await expect(page.locator('.mediaSelectedItem')).toHaveCount(2);await capture('middle');await expect(page.locator('.mediaRemove').nth(1)).toBeFocused();await retained(['A.png','C.png']);
 await page.keyboard.press('Enter');await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);await expect(page.locator('.mediaRemove').nth(0)).toBeFocused();await retained(['A.png']);
 await page.keyboard.press('Enter');await expect(page.locator('.mediaSelectedItem')).toHaveCount(0);await expect(reply).toBeFocused();await expect(reply).toBeInViewport();await retained([]);await capture('empty');
 expect(await page.evaluate(()=>mediaFocusProof.keys)).toEqual(['Tab','Tab','Enter','Enter','Enter'].map(key=>({key,trusted:true})));
});
test('partial receipt clears confirmed caption and retries remaining file without repeating it',async({page})=>{
 const s=await setup(page,{failSecond:true});await page.locator('#replyText').fill('Подпись');await page.locator('#replyFile').setInputFiles(files);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено файлов: 1');await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);await page.reload();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('.mediaFileName')).toHaveText('Билеты.pdf');await expect(page.locator('#replyText')).toHaveValue('');expect(s.uploads).toHaveLength(2);await page.locator('#mediaRecovery summary').click();await page.getByRole('button',{name:'Проверить отправку',exact:true}).click();await page.getByRole('button',{name:'Отменить неначатую отправку',exact:true}).click();await expect(page.locator('#mediaRecovery')).toContainText('Отправка не начиналась');await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено');expect(s.uploads).toHaveLength(3);expect(s.uploads[0]).toContain('Маршрут.txt');expect(s.uploads[2]).toContain('Билеты.pdf');expect(s.uploads[2]).not.toContain('Подпись');expect(s.errors).toEqual([]);
});
test('unknown transport outcome cannot be repeated and explicitly pasted screenshot stays local',async({page})=>{
 const s=await setup(page,{unknown:true});await page.evaluate(async()=>{const canvas=document.createElement('canvas');canvas.width=64;canvas.height=32;canvas.getContext('2d').fillRect(0,0,64,32);const blob=await new Promise(resolve=>canvas.toBlob(resolve));const dt=new DataTransfer();dt.items.add(new File([blob],'Screenshot.png',{type:'image/png'}));document.getElementById('replyText').dispatchEvent(new ClipboardEvent('paste',{clipboardData:dt,bubbles:true,cancelable:true}))});await expect.poll(()=>page.locator('.mediaPreview img').evaluate(img=>img.naturalWidth)).toBe(64);await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);expect(s.uploads).toHaveLength(0);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('неизвестен');await expect(page.locator('.mediaRemove')).toBeEnabled();await page.locator('#sendReply').click();expect(s.uploads).toHaveLength(1);expect(s.errors).toEqual([]);
});

test('unknown second receipt clears only the confirmed caption and blocks the remaining file',async({page})=>{
 const s=await setup(page,{unknownSecond:true});await page.locator('#replyText').fill('Подпись');await page.locator('#replyFile').setInputFiles(files);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено файлов: 1');await expect(page.locator('#replyStatus')).toContainText('неизвестен');await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);await expect(page.locator('.mediaFileName')).toHaveText('Билеты.pdf');await expect(page.locator('.mediaRemove')).toBeEnabled();await page.locator('#sendReply').click();expect(s.uploads).toHaveLength(2);expect(s.errors).toEqual([]);
});

for(const viewport of [{width:390,height:844},{width:430,height:844},{width:768,height:844},{width:1440,height:900},{width:390,height:420}])test(`uncertain attachment removal ${viewport.width}x${viewport.height}`,async({page,browserName})=>{
 await page.setViewportSize(viewport);const s=await setup(page,{unknown:true,allowTextSend:true});
 await page.locator('#replyText').fill('Первоначальная подпись');
 await page.evaluate(()=>document.getElementById('replyFile').addEventListener('change',event=>{window.uncertainFile=event.target.files[0]},{capture:true,once:true}));
 await page.locator('#replyFile').setInputFiles(files);await page.locator('#sendReply').click();
 await expect(page.locator('#replyStatus')).toContainText('неизвестен');
 await expect(page.locator('.mediaRemove').first()).toBeEnabled();
 fs.mkdirSync('manager-media-artifacts',{recursive:true});
 const capture=suffix=>page.screenshot({path:`manager-media-artifacts/${browserName}-${viewport.width}-${viewport.height}-uncertain-${suffix}.png`});
 await capture('retained');await page.locator('.mediaRemove').first().click();
 await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);await expect(page.locator('.mediaFileName')).toHaveText('Билеты.pdf');
 await expect(page.locator('.mediaSelectionHint')).toContainText('только из черновика');
 await expect(page.locator('.mediaSelectionHint')).toContainText('подпись');
 await expect(page.locator('.mediaSelectionHint')).toBeInViewport({ratio:1});
 await expect(page.locator('#replyText')).toHaveValue('Первоначальная подпись');expect(s.uploads).toHaveLength(1);expect(s.texts).toHaveLength(0);
 await capture('removed');
 // Re-selecting the exact File must retain the same UNKNOWN fence after removal.
 await page.evaluate(()=>WorkspaceV2Media.addFiles([window.uncertainFile]));await page.locator('#sendReply').click();
 await expect(page.locator('#replyStatus')).toContainText('неизвестен');expect(s.uploads).toHaveLength(1);
 await page.locator('.mediaRemove').last().click();await expect(page.locator('.mediaSelectionHint')).toBeInViewport({ratio:1});await page.locator('#replyText').fill('Новая подпись после проверки');await page.locator('#sendReply').click();
 await expect(page.locator('.mediaSelectedItem')).toHaveCount(0);await expect(page.locator('#replyText')).toHaveValue('');
 expect(s.uploads).toHaveLength(2);expect(s.uploads[1]).toContain('Билеты.pdf');expect(s.uploads[1]).toContain('Новая подпись после проверки');expect(s.uploads[1]).not.toContain('Маршрут.txt');
 await page.locator('#replyText').fill('Следующий ответ');await page.locator('#sendReply').click();
 await expect(page.locator('#replyText')).toHaveValue('');expect(s.texts).toHaveLength(1);expect(s.texts[0].text).toBe('Следующий ответ');expect(s.uploads).toHaveLength(2);expect(s.errors).toEqual([]);
 await capture('continued');
});

test('pending attachment stops after switching client and old-account response cannot alter new files',async({page})=>{
 const s=await setup(page,{holdFirst:true});await page.locator('#replyFile').setInputFiles(files);await page.locator('#sendReply').click();await expect.poll(()=>s.uploads.length).toBe(1);
 await page.evaluate(()=>{WorkspaceV2Media.preserveDraft(11);WorkspaceV2Media.configure('fixture',12);WorkspaceV2Media.restoreDraft(12);WorkspaceV2.S.current=12});s.release();await expect.poll(()=>page.evaluate(()=>document.getElementById('sendReply').disabled)).toBe(false);expect(s.uploads).toHaveLength(1);
 await page.evaluate(()=>{WorkspaceV2Media.configure('fixture',11);WorkspaceV2Media.restoreDraft(11);WorkspaceV2.S.current=11});await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);
});
test('pending account changes preserve new account file after old response',async({page})=>{
 const s=await setup(page,{holdFirst:true});await page.locator('#replyFile').setInputFiles(files);await page.locator('#sendReply').click();await expect.poll(()=>s.uploads.length).toBe(1);
 s.switchManager({id:8,role:'manager',display_name:'Ольга',is_working:true});await page.evaluate(()=>{WorkspaceV2.S.authGeneration++;WorkspaceV2.S.manager={id:8,role:'manager'};WorkspaceV2Media.resetDrafts();WorkspaceV2Media.configure('new-account',11);WorkspaceV2Media.addFiles([new File(['new-account'],'New-account.txt',{type:'text/plain'})])});s.release();await expect.poll(()=>page.evaluate(()=>document.getElementById('sendReply').disabled)).toBe(false);await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);await expect(page.locator('.mediaFileName')).toHaveText('New-account.txt');expect(s.uploads).toHaveLength(1);expect(s.errors).toEqual([]);
});

test('partial provider suspension remains visible and blocks send when detail refresh fails',async({page})=>{
 const s=await setup(page,{suspendedSecond:true,failedRefresh:true});await page.locator('#replyText').fill('Подпись');await page.locator('#replyFile').setInputFiles(files);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено файлов: 1');await expect(page.locator('#deliveryFailure')).toContainText('Диалог приостановлен');await expect(page.locator('#sendReply')).toBeDisabled();expect(s.uploads).toHaveLength(2);await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);expect(s.errors).toEqual([]);
});

test('repeated canonical identity setup binds chooser and paste once',async({page})=>{
 const s=await setup(page);await page.evaluate(()=>{WorkspaceV2Media.init();WorkspaceV2Media.init()});await page.locator('#replyFile').setInputFiles([files[0]]);await expect(page.locator('.mediaSelectedItem')).toHaveCount(1);
 await page.evaluate(()=>{const dt=new DataTransfer();dt.items.add(new File(['synthetic clipboard'],'Clipboard.txt',{type:'text/plain'}));document.getElementById('replyText').dispatchEvent(new ClipboardEvent('paste',{clipboardData:dt,bubbles:true,cancelable:true}))});await expect(page.locator('.mediaSelectedItem')).toHaveCount(2);await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toContainText('Отправлено');expect(s.uploads).toHaveLength(2);expect(s.errors).toEqual([]);
});

test('short screen with search retains file count, current progress and scrollable controls at selection limit',async({page})=>{
 await page.setViewportSize({width:390,height:420});const s=await setup(page,{holdFirst:true});await page.locator('#m2ConversationMenuToggle').click();await page.locator('#conversationSearchOpen').click();await page.locator('#conversationSearchInput').fill('Здравствуйте');await page.locator('#replyText').fill('Файлы');await page.locator('#replyFile').setInputFiles(Array.from({length:10},(_,i)=>({name:'Файл-'+(i+1)+'.txt',mimeType:'text/plain',buffer:Buffer.from('synthetic')})));await expect(page.locator('.mediaSelectedItem')).toHaveCount(10);await expect(page.locator('.mediaPreview').first()).toHaveText('1/10');await page.locator('.mediaRemove').last().focus();await expect(page.locator('.mediaRemove').last()).toBeFocused();await page.locator('.mediaRemove').first().focus();await page.locator('#replyFile').setInputFiles([{name:'Лишний.txt',mimeType:'text/plain',buffer:Buffer.from('synthetic')}]);await expect(page.locator('.mediaSelectedItem')).toHaveCount(10);await expect(page.locator('.mediaSelectionHint')).toContainText('не добавлена');await page.locator('#sendReply').click();await expect(page.locator('.mediaFileSize').first()).toHaveText('Отправляем 1/10');expect(await page.locator('#messages').evaluate(el=>el.clientHeight)).toBeGreaterThanOrEqual(40);const progress=await page.locator('.mediaFileSize').first().boundingBox();expect(progress.y+progress.height).toBeLessThanOrEqual(420);await page.screenshot({path:'manager-media-artifacts/short-search-progress.png'});s.release();await expect(page.locator('#replyStatus')).toContainText('Отправлено');expect(s.uploads).toHaveLength(10);expect(s.errors).toEqual([]);
});
