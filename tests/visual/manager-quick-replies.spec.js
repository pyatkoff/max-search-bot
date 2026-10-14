const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
const sourceRoot=process.env.M2_QA_SOURCE_ROOT||root;
const readSource=relative=>fs.readFileSync(path.join(sourceRoot,relative));
const shell=execFileSync('php',['manager2/index.php'],{cwd:sourceRoot,encoding:'utf8'});
// Actual PHP and source assets. All data and mutations below are isolated fixture
// responses. No production traffic or customer messages are permitted.
async function setup(page,{role='manager',holdList=false,listError=false,holdCard=false,cardDelay=0,unknownCreate=false,conflictUpdate=false,unknownReorder=false,rejectedReorder=false,conflictReorder=false,holdToolbar=false,toolbarError=false,holdFavorite=false,unknownFavorite=false,conflictFavorite=false,unknownDelete=false,selectedIds=[2],messages=null}={}){
 const calls=[],unexpected=[],errors=[],heldLists=[],heldCards=[],heldToolbars=[],heldFavorites=[],receipts=new Map(),panels=new Map([[7,[...selectedIds]],[8,[]]]),panelVersions=new Map([[7,1],[8,1]]);
 const requestIds=new WeakMap(),requestTrace=[],errorDiagnostics=[];let requestSequence=0,documentGeneration=0;
 const trace=(kind,data={})=>{requestTrace.push({kind,at:Date.now(),documentGeneration,...data});if(requestTrace.length>120)requestTrace.shift()};
 const describeRequest=request=>{const path=new URL(request.url()).pathname;if(!path.startsWith('/manager/'))return null;let action='';try{action=String(request.postDataJSON()?.action||'')}catch{}if(!requestIds.has(request))requestIds.set(request,++requestSequence);return{id:requestIds.get(request),path,method:request.method(),type:request.resourceType(),action}};
 page.on('request',request=>{const data=describeRequest(request);if(data)trace('request',data)});
 page.on('response',response=>{const data=describeRequest(response.request());if(data)trace('response',{...data,status:response.status()})});
 page.on('requestfinished',request=>{const data=describeRequest(request);if(data)trace('finished',data)});
 page.on('requestfailed',request=>{const data=describeRequest(request);if(data)trace('failed',{...data,failure:request.failure()?.errorText||''})});
 page.on('framenavigated',frame=>{if(frame===page.mainFrame()){documentGeneration++;trace('document')}});
 let manager={id:7,role,display_name:'Мария',is_working:true};
 const replies=[
  {id:1,manager_id:7,title:'Личное приветствие',body:'Здравствуйте, {client_name}! Меня зовут {manager_name}.',category:'Приветствие',scope:'personal',version:1,is_favorite:false,can_edit:true},
  {id:2,title:'Общий ответ о подборе',body:'Проверяем отели у моря.',category:'Подбор',scope:'shared',version:1,is_favorite:true,can_edit:role==='admin'},
  {id:3,manager_id:7,title:'Уточнить пожелания',body:'Какие пожелания к {unknown}?',category:'Подбор',scope:'personal',version:1,is_favorite:false,can_edit:true}
 ];
 const available=(r,id=manager.id)=>r&&(r.scope==='shared'||r.manager_id===id);
 const selected=(id=manager.id)=>panels.get(id)||[];
 const view=(r,id=manager.id)=>({...r,is_favorite:selected(id).includes(r.id),can_edit:r.scope==='personal'||manager.role==='admin'});
 const toolbar=(id=manager.id)=>({ok:true,replies:selected(id).map(key=>replies.find(r=>r.id===key)).filter(r=>available(r,id)).map(r=>view(r,id)),version:String(panelVersions.get(id)||1).padStart(64,'0'),max_replies:100});
 const bump=id=>panelVersions.set(id,(panelVersions.get(id)||1)+1);
 const snapshot=result=>JSON.parse(JSON.stringify(result));
 const conv=id=>({id,manager_id:manager.id,manager_name:manager.display_name,status:'manager',channel:'telegram',display_name:id===11?'Анна Морозова':'Алексей Иванов'});
 const msgs=()=>messages||Array.from({length:35},(_,i)=>({id:i+1,sender_type:i%3===0?'manager':'customer',manager_id:i%3===0?manager.id:null,text:i===34?'Посмотрите варианты у моря. Бюджет до 250 тысяч за всех.':'Сообщение '+(i+1)+'. Обсуждаем отели у моря и даты.',created_at:'2026-10-13 12:00:00'}));
 page.on('pageerror',e=>{errors.push(e.message);errorDiagnostics.push({message:e.message,stack:e.stack||'',at:Date.now(),documentGeneration,recentRequests:requestTrace.slice(-40)});if(errorDiagnostics.length>8)errorDiagnostics.shift()});
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url()),method=req.method();
  const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
  const block=()=>{unexpected.push(method+' '+url.pathname);return route.abort()};
  if(url.origin!==origin)return block();
  if(method==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
  // Optional portraits are absent for these synthetic clients; the dedicated avatar suite covers images/ACL.
  if(req.method()==='GET'&&url.pathname==='/manager/customer-avatar.php'){expect([...url.searchParams.keys()]).toEqual(['conversation_id']);expect(['11','12']).toContain(url.searchParams.get('conversation_id'));return route.fulfill({status:404,body:''})}
  if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
  // WebKit may issue native registration despite the mocked push capability.
  // The response-library fixture uses an inert worker; dedicated worker suites
  // verify the canonical worker and push lifecycle separately.
  if(method==='GET'&&url.pathname==='/manager/sw.js')return route.fulfill({contentType:'application/javascript',body:''});
  if(method==='GET'&&/^\/manager2?\/assets\/[\w.-]+$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:readSource(url.pathname.slice(1))});
  if(!url.pathname.startsWith('/manager/'))return block();
  let d={};if(method==='POST'){try{d=req.postDataJSON()||{}}catch(e){return block()}}
  calls.push({path:url.pathname,...d});
  // Reply-library fixtures are legacy entries without a publication. Accept
  // only the canonical CSRF-protected read for their two scoped conversations.
  if(method==='POST'&&url.pathname==='/manager/post-context-api.php'&&d.action==='detail'&&d.csrf==='fixture-csrf'&&[11,12].includes(d.conversation_id))return reply({ok:true,conversation_id:d.conversation_id,context:{current:null,history:[]}});
  if(url.pathname==='/manager/api.php'){
   if(d.action==='me')return reply({ok:true,manager,csrf:'fixture-csrf',projects:[]});
   if(d.action==='detail')return reply({ok:true,conversation:conv(d.conversation_id),messages:msgs(),delivery_failure:null});
   if(d.action==='counts')return reply({ok:true,counts:{waiting:{count:2,unread:2},mine:{count:2}}});
   if(d.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
  }
  if(url.pathname==='/manager/personal-workspace-api.php'&&d.action==='snapshot')return reply(require('./fixtures/personal-workspace-default').emptyPersonalWorkspaceSnapshot());
  if(url.pathname==='/manager/pipeline-api.php'){
   if(d.action==='catalog')return reply({ok:true,stages:[],tags:[],outcomes:{open:'В работе'},close_reasons:{}});
   if(d.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager]}});
   if(d.action==='list')return reply({ok:true,conversations:[11,12].map(id=>({...conv(id),project_label:'AnyTour',origin_label:'TG · реклама',can_edit_pipeline:true,last_text:'Ждём варианты у моря',unread_count:id===11?2:1,awaiting_manager_reply:1,last_sender_type:'customer',lead_outcome:'open'}))});
   if(d.action==='detail'){
    if(holdCard)await new Promise(resolve=>heldCards.push(resolve));
    if(cardDelay)await new Promise(resolve=>setTimeout(resolve,cardDelay));
    return reply({ok:true,can_edit_pipeline:true,pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},tasks:[],trip:{city:'Москва',country:'Турция',adults:2,budget:{max:250000,currency:'RUB',basis:'total'}},contact:{},source:{},handoff:{}});
   }
  }
  if(url.pathname==='/manager/quick-replies-api.php'){
   if(d.csrf!=='fixture-csrf')throw new Error('Quick replies must use canonical CSRF');
   const requestOwner=manager.id,requestRole=manager.role;
   if(d.action==='toolbar'){
    const captured=snapshot(toolbar());
    if(holdToolbar)await new Promise(resolve=>heldToolbars.push(resolve));
    if(toolbarError)return reply({ok:false,error:'snapshot_failed'},500);
    return reply(captured);
   }
   if(d.action==='list'){
    if(holdList)await new Promise(resolve=>heldLists.push(resolve));
    if(listError)return reply({ok:false,error:'snapshot_failed'},500);
    const items=replies.filter(r=>available(r,requestOwner)&&(d.scope!=='personal'||r.scope==='personal')&&(d.scope!=='shared'||r.scope==='shared')&&(!d.favorites_only||selected(requestOwner).includes(r.id))&&(!d.category||r.category===d.category)&&(!d.search||(r.title+' '+r.body).toLowerCase().includes(d.search.toLowerCase()))).map(r=>view(r,requestOwner));
    return reply({ok:true,replies:items,total:items.length,has_more:false,categories:['Приветствие','Подбор'],can_manage_shared:manager.role==='admin'});
   }
   if(d.action==='operation')return reply({ok:true,found:receipts.has(d.operation_id),result:receipts.get(d.operation_id)||null});
   if(d.action==='reorder'){
    if(rejectedReorder)return reply({ok:false,error:'invalid_ids'},400);
    if(conflictReorder||d.toolbar_version!==toolbar().version){conflictReorder=false;return reply({ok:false,error:'conflict',toolbar:toolbar()},409)}
    if(!Array.isArray(d.ids)||new Set(d.ids).size!==d.ids.length||[...d.ids].sort().join(',')!==[...selected()].sort().join(','))return reply({ok:false,error:'invalid_ids'},400);
    panels.set(requestOwner,[...d.ids]);bump(requestOwner);const result={ok:true,toolbar:toolbar()};receipts.set(d.operation_id,snapshot(result));if(unknownReorder)return route.abort('failed');return reply(result);
   }
   if(['create','update','copy'].includes(d.action)){
    if(d.action==='update'&&conflictUpdate)return reply({ok:false,error:'conflict',current:{...replies.find(r=>r.id===Number(d.id)),version:2,body:'Актуальная версия коллеги'}},409);
    let r=d.action==='create'?null:replies.find(r=>r.id===Number(d.id));if(d.action==='copy'){r={...r,id:100+replies.length,manager_id:requestOwner,scope:'personal',is_favorite:false,can_edit:true};replies.push(r);const result={ok:true,reply:view(r),toolbar:toolbar()};receipts.set(d.operation_id,snapshot(result));return reply(result)}if(r&&d.version!==r.version)return reply({ok:false,error:'conflict',current:r},409);
    if(!r){r={id:100+replies.length,manager_id:requestOwner,is_favorite:false,version:0};replies.push(r)}Object.assign(r,{title:d.title,body:d.body,category:d.category,scope:d.scope||r.scope,can_edit:true,version:r.version+1});const result={ok:true,reply:view(r),toolbar:toolbar()};receipts.set(d.operation_id,snapshot(result));if(d.action==='create'&&unknownCreate)return route.abort('failed');return reply(result);
   }
   if(d.action==='favorite'){
    if(conflictFavorite||(d.toolbar_version&&d.toolbar_version!==toolbar(requestOwner).version)){conflictFavorite=false;return reply({ok:false,error:'conflict',toolbar:toolbar(requestOwner)},409)}
    const r=replies.find(r=>r.id===Number(d.id));if(!available(r,requestOwner))return reply({ok:false,error:'not_found'},404);
    r.is_favorite=!!d.is_favorite;const ids=selected(requestOwner);panels.set(requestOwner,d.is_favorite?(ids.includes(r.id)?ids:[...ids,r.id]):ids.filter(id=>id!==r.id));bump(requestOwner);
    const result={ok:true,reply:view(r,requestOwner),toolbar:toolbar(requestOwner)};receipts.set(d.operation_id,snapshot(result));
    if(holdFavorite)await new Promise(resolve=>heldFavorites.push(resolve));if(unknownFavorite)return route.abort('failed');return reply(result);
   }
   if(d.action==='delete'){
    const i=replies.findIndex(r=>r.id===Number(d.id)),r=replies[i];if(!available(r,requestOwner))return reply({ok:false,error:'not_found'},404);
    if(r.scope==='shared'&&requestRole!=='admin')return reply({ok:false,error:'forbidden'},403);if(d.version!==r.version)return reply({ok:false,error:'conflict',current:view(r,requestOwner)},409);
    const id=r.id;replies.splice(i,1);for(const [owner,ids]of panels){if(ids.includes(id)){panels.set(owner,ids.filter(key=>key!==id));bump(owner)}}const result={ok:true,toolbar:toolbar(requestOwner)};receipts.set(d.operation_id,snapshot(result));if(unknownDelete)return route.abort('failed');return reply(result);
   }
  }
  if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
  if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
  unexpected.push(method+' '+url.pathname+' '+d.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
 });
 await page.goto(origin+'/manager2/index.php?qa=1');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
 return {calls,unexpected,errors,errorDiagnostics,requestTrace,trace,replies,toolbar,setPanel:(ids,id=manager.id)=>{panels.set(id,[...ids]);bump(id)},releaseList:()=>heldLists.shift()?.(),releaseCard:()=>heldCards.shift()?.(),releaseToolbar:()=>heldToolbars.shift()?.(),releaseFavorite:()=>heldFavorites.shift()?.(),switchManager:m=>{manager=m},setListError:v=>{listError=v},setHoldList:v=>{holdList=v},setHoldToolbar:v=>{holdToolbar=v},setToolbarError:v=>{toolbarError=v}};
}
async function reloadFixture(page,s){s.trace('before_reload');await page.reload();s.trace('after_reload')}
async function openLead(page,id=11){
 // Reload may populate Inbox before the saved conversation finishes opening.
 // Choose visible navigation only after boot has settled that restoration.
 await expect.poll(()=>page.evaluate(()=>!!window.WorkspaceV2?.S.workspaceBound&&!window.WorkspaceV2.S.booting)).toBe(true);
 if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox')){
  await page.locator('#mobileBack').click();
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2Mobile.getScreen())).toBe('inbox');
 }
 await page.locator('#inboxList [data-conversation-id="'+id+'"]').click();await expect(page.locator('#composer')).toBeVisible();
}
async function useConversationTool(page,id,interaction='click'){
 const tool=page.locator('#'+id);await expect(tool).toHaveCount(1);
 const menu=tool.locator('xpath=ancestor::details[1]');
 if(await menu.count()&&!await menu.evaluate(el=>el.open))await menu.locator('summary').click();
 await tool[interaction]();
}
async function openLibrary(page){await page.locator('#quickRepliesOpen').click();if(await page.locator('#qrTitle').count()===0)await page.locator('[data-qr-scope="all"]').click()}
async function capture(page,browser,width,state){const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(dir,`reply-${browser}-${width}-${state}.png`)})}
async function bounded(page){expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1)}
function clean(s){if(s.errors.length)console.log('QUICK_REPLY_PAGEERROR_DIAGNOSTICS '+JSON.stringify({errors:s.errorDiagnostics,recentRequests:s.requestTrace}));expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(c=>c.action==='send')).toEqual([])}
async function panelIds(page){return page.locator('[data-qr-toolbar-id]').evaluateAll(nodes=>nodes.map(node=>Number(node.dataset.qrToolbarId)))}
async function expectPanel(page,ids){await expect.poll(()=>panelIds(page)).toEqual(ids)}
async function chooseForPanel(page,id){await page.locator('[data-qr-scope="all"]').click();await page.locator('[data-qr-select="'+id+'"]').click();await page.locator('#qrFavorite').click()}
async function assertToolbarLayout(page){
 await bounded(page);
 await expect(page.locator('.quickReplies [data-reply]')).toHaveCount(0);
 await expect(page.locator('#quickRepliesOpen')).toHaveText('Настроить ответы');
 const positions=await page.locator('.quickReplies').evaluate(row=>({buttons:[...row.querySelectorAll('button')].map(button=>button.id||button.dataset.qrToolbarId),scrollable:row.scrollWidth>row.clientWidth,style:getComputedStyle(row).overflowX}));
 expect(positions.buttons.at(-1)).toBe('quickRepliesOpen');
 if(positions.scrollable)expect(['auto','scroll']).toContain(positions.style);
 const send=await page.locator('#sendReply').boundingBox();expect(send.y+send.height).toBeLessThanOrEqual((await page.viewportSize()).height+1);
}

for(const width of [390,430,768,1440])test.describe('Manager-selected reply panel '+width,()=>{
 test.use({viewport:{width,height:900}});
 test('selected replies replace defaults, direct insert keeps draft/file, ordered choices persist after reload',async({page,browserName})=>{
  const s=await setup(page);await openLead(page);await expectPanel(page,[2]);await assertToolbarLayout(page);
  await page.locator('#replyText').fill('Существующий ответ');await page.locator('#replyFile').setInputFiles({name:'panel-file.txt',mimeType:'text/plain',buffer:Buffer.from('Keep this file')});const attachment=await page.locator('#mediaSelection').textContent();
  // Composer focus hides this optional strip on mobile; leave the text field
  // through an ordinary visible click before using an explicitly selected chip.
  await page.locator('#conversationTitle').click();await page.locator('[data-qr-toolbar-id="2"]').click();await expect(page.locator('#replyText')).toHaveValue('Существующий ответ\nПроверяем отели у моря.');await expect(page.locator('#quickRepliesDialog')).toHaveCount(0);await expect(page.locator('#mediaSelection')).toHaveText(attachment);
  await page.locator('#conversationTitle').click();await page.locator('#quickRepliesOpen').click();await expect(page.locator('[data-qr-scope="toolbar"]')).toHaveAttribute('aria-pressed','true');await expect(page.locator('[data-qr-panel-id]')).toHaveCount(1);
  await chooseForPanel(page,1);await expectPanel(page,[2,1]);await chooseForPanel(page,3);await expectPanel(page,[2,1,3]);
  await page.locator('[data-qr-scope="toolbar"]').click();await expect(page.locator('[data-qr-panel-id]')).toHaveCount(3);await expect(page.locator('[data-qr-move="up"][data-qr-move-id="2"]')).toBeDisabled();await expect(page.locator('[data-qr-move="down"][data-qr-move-id="3"]')).toBeDisabled();
  const move=page.locator('[data-qr-move="up"][data-qr-move-id="1"]');await expect(move).toHaveAccessibleName(/Личное приветствие/);await move.focus();await page.keyboard.press('Enter');await expectPanel(page,[1,2,3]);await capture(page,browserName,width,'ordered-panel');await page.setViewportSize({width,height:420});await bounded(page);await page.locator('[data-qr-move="up"][data-qr-move-id="3"]').scrollIntoViewIfNeeded();await expect(page.locator('[data-qr-move="up"][data-qr-move-id="3"]')).toBeInViewport();await page.locator('[data-qr-move="up"][data-qr-move-id="3"]').click();await expectPanel(page,[1,3,2]);await page.locator('[data-qr-move="down"][data-qr-move-id="3"]').click();await expectPanel(page,[1,2,3]);await capture(page,browserName,width,'ordered-panel-short');await page.setViewportSize({width,height:900});await page.locator('#qrClose').click();
  await page.locator('[data-qr-toolbar-id="1"]').click();await expect(page.locator('#replyText')).toHaveValue('Существующий ответ\nПроверяем отели у моря.\nЗдравствуйте, Анна Морозова! Меня зовут Мария.');await expect(page.locator('#mediaSelection')).toHaveText(attachment);
  await page.locator('#conversationTitle').click();await page.locator('[data-qr-toolbar-id="3"]').click();await expect(page.locator('#quickRepliesDialog')).toBeVisible();await expect(page.locator('#qrPreview')).toHaveValue('Какие пожелания к {unknown}?');await expect(page.locator('#qrInsert')).toBeDisabled();await expect(page.locator('#replyText')).not.toHaveValue(/unknown/);await page.locator('#qrClose').click();
  await page.locator('#conversationTitle').click();await page.setViewportSize({width,height:420});await assertToolbarLayout(page);await page.locator('[data-qr-toolbar-id="1"]').scrollIntoViewIfNeeded();await expect(page.locator('[data-qr-toolbar-id="1"]')).toBeInViewport();await capture(page,browserName,width,'selected-composer-short');await page.setViewportSize({width,height:900});
  // Let the newly opened transcript's deferred read finish before the intentional
  // persistence reload; WebKit can reject a fetch started during page teardown.
  const editRead=page.waitForResponse(response=>{if(new URL(response.url()).pathname!=='/manager/api.php')return false;const data=response.request().postDataJSON();return data?.action==='edit_candidates'&&Number(data.conversation_id)===12});
  const before=s.calls.filter(c=>c.action==='toolbar').length;await openLead(page,12);await expectPanel(page,[1,2,3]);await page.locator('[data-qr-toolbar-id="1"]').click();await expect(page.locator('#replyText')).toHaveValue('Здравствуйте, Алексей Иванов! Меня зовут Мария.');expect(s.calls.filter(c=>c.action==='toolbar')).toHaveLength(before);
  expect(await (await editRead).finished()).toBeNull();
  await reloadFixture(page,s);await openLead(page);await expectPanel(page,[1,2,3]);await assertToolbarLayout(page);await capture(page,browserName,width,'selected-reload');
  await page.locator('#quickRepliesOpen').click();await chooseForPanel(page,2);await expectPanel(page,[1,3]);await page.locator('#qrClose').click();await reloadFixture(page,s);await openLead(page);await expectPanel(page,[1,3]);clean(s);
 });
});

test('created answer selection is explicit and panel reflects edited title/body, shared copy and deletion',async({page})=>{
 const s=await setup(page);await openLead(page);await openLibrary(page);await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Моё приветствие');await page.locator('#qrBody').fill('Я {manager_name}, здравствуйте, {client_name}!');await page.locator('#qrSave').click();await expect.poll(()=>s.replies.length).toBe(4);const created=s.replies.find(r=>r.title==='Моё приветствие');
 await expectPanel(page,[2]);await page.locator('#qrFavorite').click();await expectPanel(page,[2,created.id]);await page.locator('#qrEdit').click();await page.locator('#qrTitle').fill('Моё короткое приветствие');await page.locator('#qrBody').fill('Здравствуйте, {client_name}! На связи {manager_name}.');await page.locator('#qrSave').click();await page.locator('#qrClose').click();
 await expect(page.locator('[data-qr-toolbar-id="'+created.id+'"]')).toHaveText('Моё короткое приветствие');await page.locator('[data-qr-toolbar-id="'+created.id+'"]').click();await expect(page.locator('#replyText')).toHaveValue('Здравствуйте, Анна Морозова! На связи Мария.');
 await page.locator('#conversationTitle').click();await openLibrary(page);await page.locator('[data-qr-select="2"]').click();await expect(page.locator('#qrEdit')).toHaveCount(0);await page.locator('#qrCopy').click();await expect.poll(()=>s.replies.length).toBe(5);const copied=s.replies.at(-1);expect(copied.scope).toBe('personal');await expectPanel(page,[2,created.id]);
 await page.locator('#qrFavorite').click();await expectPanel(page,[2,created.id,copied.id]);await page.locator('#qrEdit').click();await page.locator('#qrTitle').fill('Моя подборка');await page.locator('#qrBody').fill('Проверю мой вариант.');await page.locator('#qrSave').click();await expect(page.locator('[data-qr-toolbar-id="'+copied.id+'"]')).toHaveText('Моя подборка');
 page.once('dialog',dialog=>dialog.accept());await page.locator('#qrDelete').click();await expectPanel(page,[2,created.id]);expect(s.replies.find(r=>r.id===2).body).toBe('Проверяем отели у моря.');clean(s);
});

test('empty and unavailable selected panel cannot delay a transcript or replace it with fixed defaults',async({page})=>{
 const s=await setup(page,{holdToolbar:true,selectedIds:[]});await openLead(page);await expect(page.locator('#messages .msg')).toHaveCount(35);await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('.quickReplies [data-reply]')).toHaveCount(0);s.releaseToolbar();await expectPanel(page,[]);await expect(page.locator('#quickRepliesOpen')).toBeVisible();await page.locator('#quickRepliesOpen').click();await expect(page.locator('[data-qr-scope="toolbar"]')).toHaveAttribute('aria-pressed','true');await expect(page.locator('#qrList')).toContainText(/панел/);await page.locator('#qrClose').click();
 s.setHoldToolbar(false);s.setToolbarError(true);await reloadFixture(page,s);await openLead(page);await expect(page.locator('#messages .msg')).toHaveCount(35);await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('.quickReplies [data-reply]')).toHaveCount(0);await expect(page.locator('[data-qr-toolbar-id]')).toHaveCount(0);await expect(page.locator('.qrToolbarError')).toContainText('Не удалось загрузить');await page.locator('#replyText').fill('Черновик при недоступной панели');s.setToolbarError(false);await page.locator('[data-qr-toolbar-retry]').click();await expect(page.locator('.qrToolbarError')).toHaveCount(0);await expect(page.locator('#replyText')).toHaveValue('Черновик при недоступной панели');clean(s);
});

test('late old-account toolbar cannot disclose personal chips and account choice remains isolated',async({page})=>{
 const s=await setup(page,{holdToolbar:true,selectedIds:[1,2]});await openLead(page);s.switchManager({id:8,role:'manager',display_name:'Ольга',is_working:true});s.setHoldToolbar(false);
 await page.evaluate(()=>{WorkspaceV2.S.authGeneration++;WorkspaceV2QuickReplies.resetSession();WorkspaceV2Conversation.resetForIdentityChange();WorkspaceV2.S.manager={id:8,role:'manager',display_name:'Ольга',is_working:true};WorkspaceV2Conversation.activateReplySession(8);WorkspaceV2QuickReplies.configure()});await expectPanel(page,[]);s.releaseToolbar();await expectPanel(page,[]);
 await page.evaluate(()=>WorkspaceV2QuickReplies.open());await expect(page.locator('[data-qr-select="1"]')).toHaveCount(0);await expect(page.locator('[data-qr-select="3"]')).toHaveCount(0);await page.locator('[data-qr-select="2"]').click();await page.locator('#qrFavorite').click();await expectPanel(page,[2]);expect(s.toolbar(7).replies.map(r=>r.id)).toEqual([1,2]);expect(s.toolbar(8).replies.map(r=>r.id)).toEqual([2]);clean(s);
});

for(const result of ['unknown','rejected','conflict'])test('panel reorder '+result+' is truthful and cannot automatically repeat a write',async({page})=>{
 const s=await setup(page,{selectedIds:[1,2],unknownReorder:result==='unknown',rejectedReorder:result==='rejected',conflictReorder:result==='conflict'});await openLead(page);await expectPanel(page,[1,2]);await page.locator('#quickRepliesOpen').click();await page.locator('[data-qr-move="up"][data-qr-move-id="2"]').click();
 if(result==='unknown'){
  await expect(page.locator('#qrPending')).toBeVisible();await expect(page.locator('#qrStatus')).toContainText(/подтверд|уточн/);await expectPanel(page,[1,2]);await expect(page.locator('[data-qr-move="up"][data-qr-move-id="2"]')).toBeDisabled();await page.locator('#qrResolve').click();await expect(page.locator('#qrPending')).toBeHidden();await expectPanel(page,[2,1]);expect(s.calls.filter(c=>c.action==='operation')).toHaveLength(1);
 }else{
  await expect(page.locator('#qrStatus')).toContainText(result==='conflict'?/другом|поряд|измен/ : /Не удалось|поряд|доступ/);await expect(page.locator('#qrStatus')).toHaveClass(/\berror\b/);await expectPanel(page,[1,2]);await expect(page.locator('#qrPending')).toBeHidden();
 }
 expect(s.calls.filter(c=>c.action==='reorder')).toHaveLength(1);clean(s);
});

test('recovered reorder receipt cannot overwrite a newer canonical panel from another device',async({page})=>{
 const s=await setup(page,{selectedIds:[1,2],unknownReorder:true});await openLead(page);await expectPanel(page,[1,2]);await page.locator('#quickRepliesOpen').click();await page.locator('[data-qr-move="up"][data-qr-move-id="2"]').click();await expect(page.locator('#qrPending')).toBeVisible();
 // The original operation committed [2,1]. A newer explicit selection on a
 // second device removed reply 1 before this tab requested the old receipt.
 s.setPanel([2]);const reads=s.calls.filter(c=>c.action==='toolbar').length;await page.locator('#qrResolve').click();await expect(page.locator('#qrPending')).toBeHidden();await expectPanel(page,[2]);await expect.poll(()=>s.calls.filter(c=>c.action==='toolbar').length).toBeGreaterThan(reads);await expect(page.locator('[data-qr-panel-id="1"]')).toHaveCount(0);expect(s.calls.filter(c=>c.action==='reorder')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='operation')).toHaveLength(1);clean(s);
});

test('reopening settings observes another device order, edited text and a deleted shared reply',async({page})=>{
 const s=await setup(page,{selectedIds:[1,2,3]});await openLead(page);await expectPanel(page,[1,2,3]);await page.locator('#quickRepliesOpen').click();await expect(page.locator('[data-qr-panel-id]')).toHaveCount(3);await page.locator('#qrClose').click();
 Object.assign(s.replies.find(r=>r.id===1),{title:'Новое имя приветствия',body:'Анна, ваш менеджер {manager_name} снова на связи.',version:2});s.replies.splice(s.replies.findIndex(r=>r.id===2),1);s.setPanel([3,1]);const reads=s.calls.filter(c=>c.action==='toolbar').length;
 await page.locator('#quickRepliesOpen').click();await expectPanel(page,[3,1]);await expect.poll(()=>s.calls.filter(c=>c.action==='toolbar').length).toBeGreaterThan(reads);await expect(page.locator('[data-qr-panel-id="2"]')).toHaveCount(0);await expect(page.locator('[data-qr-toolbar-id="1"]')).toHaveText('Новое имя приветствия');await page.locator('#qrClose').click();await page.locator('[data-qr-toolbar-id="1"]').click();await expect(page.locator('#replyText')).toHaveValue('Анна, ваш менеджер Мария снова на связи.');clean(s);
});

test('held toolbar refresh preserves keyboard move focus and Enter reorders exactly once',async({page})=>{
 await page.setViewportSize({width:768,height:900});
 const s=await setup(page,{selectedIds:[1,2]});await openLead(page);await expectPanel(page,[1,2]);
 s.setHoldToolbar(true);await page.locator('#quickRepliesOpen').click();await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','true');
 const move=page.locator('[data-qr-move="up"][data-qr-move-id="2"]');await expect(move).toBeEnabled();await move.focus();await expect(move).toBeFocused();
 s.releaseToolbar();await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','false');await expect(move).toBeFocused();
 await page.keyboard.press('Enter');await expectPanel(page,[2,1]);expect(s.calls.filter(c=>c.action==='reorder')).toHaveLength(1);clean(s);
});

test('held toolbar refresh preserves editable preview focus, selection and text',async({page})=>{
 await page.setViewportSize({width:768,height:900});
 const s=await setup(page,{selectedIds:[1,2]});await openLead(page);await expectPanel(page,[1,2]);
 s.setHoldToolbar(true);await page.locator('#quickRepliesOpen').click();await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','true');
 await page.locator('[data-qr-select="1"]').click();const preview=page.locator('#qrPreview');await preview.fill('Личный текст перед вставкой');await preview.evaluate(node=>node.setSelectionRange(7,12));await expect(preview).toBeFocused();
 s.releaseToolbar();await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','false');await expect(preview).toBeFocused();await expect(preview).toHaveValue('Личный текст перед вставкой');
 expect(await preview.evaluate(node=>[node.selectionStart,node.selectionEnd])).toEqual([7,12]);expect(s.calls.filter(c=>c.action==='reorder')).toHaveLength(0);clean(s);
});

for(const target of ['preview','editor'])test('held toolbar refresh preserves '+target+' range while emoji choices have focus',async({page})=>{
 await page.setViewportSize({width:768,height:900});const s=await setup(page,{selectedIds:[1,2]});await openLead(page);await expectPanel(page,[1,2]);await page.locator('#replyText').fill('Клиентский черновик');
 s.setHoldToolbar(true);await page.locator('#quickRepliesOpen').click();await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','true');if(target==='editor'){await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Черновик со смайликом')}else await page.locator('[data-qr-select="2"]').click();const input=page.locator(target==='editor'?'#qrBody':'#qrPreview');await input.fill('До заменить после');await input.evaluate(node=>node.setSelectionRange(3,11));await page.locator('#qrEmojiToggle').click();await expect(page.locator('#qrEmojiPicker')).toBeVisible();await expect(page.locator('#qrEmojiPicker [data-qr-emoji="😊"]')).toBeFocused();
 s.releaseToolbar();await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','false');await expect(page.locator('#qrEmojiPicker')).toBeHidden();await expect(page.locator('#qrEmojiToggle')).toBeFocused();expect(await input.evaluate(node=>[node.selectionStart,node.selectionEnd])).toEqual([3,11]);
 await page.locator('#qrEmojiToggle').click();await page.locator('#qrEmojiPicker [data-qr-emoji="😊"]').click();await expect(input).toHaveValue('До 😊 после');expect(await input.evaluate(node=>[node.selectionStart,node.selectionEnd])).toEqual([5,5]);await expect(page.locator('#replyText')).toHaveValue('Клиентский черновик');if(target==='editor'){expect(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('workspaceV2.quickReplyDraft.7')).editor.body)).toBe('До 😊 после');s.setHoldToolbar(false);await page.locator('#qrClose').click();await openLibrary(page);await expect(input).toHaveValue('До 😊 после')}expect(s.calls.filter(c=>['create','update','copy','delete','favorite','reorder','send'].includes(c.action))).toHaveLength(0);clean(s);
});

for(const width of [390,430,768,1440])test.describe('Quick reply controls '+width,()=>{
 test.use({viewport:{width,height:900}});
 test('emoji replacement preserves Unicode, caret, saved text and composer draft/file',async({page,browserName})=>{
  const s=await setup(page);await openLead(page);await page.locator('#replyText').fill('Черновик клиента 🌍');await page.locator('#replyFile').setInputFiles({name:'emoji-draft.txt',mimeType:'text/plain',buffer:Buffer.from('Preserve draft attachment')});const attachment=await page.locator('#mediaSelection').textContent();
  await openLibrary(page);await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Приветствие со смайликом');const body=page.locator('#qrBody');await body.fill('Начало убрать конец');await body.evaluate(node=>node.setSelectionRange(7,13));
  await page.locator('#qrEmojiToggle').click();await expect(page.locator('#qrEmojiPicker')).toBeVisible();await expect(page.locator('#qrEmojiPicker [data-qr-emoji="😊"]')).toHaveAccessibleName('Улыбка');
  const canonical=await page.locator('#emojiPicker [data-emoji]').evaluateAll(nodes=>nodes.map(node=>node.dataset.emoji));expect(await page.locator('#qrEmojiPicker [data-qr-emoji]').evaluateAll(nodes=>nodes.map(node=>node.dataset.qrEmoji))).toEqual(canonical);
  await page.locator('#qrEmojiPicker [data-qr-emoji="😊"]').click();await expect(body).toHaveValue('Начало 😊 конец');await expect(body).toBeFocused();expect(await body.evaluate(node=>[node.selectionStart,node.selectionEnd])).toEqual([9,9]);
  expect(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('workspaceV2.quickReplyDraft.7')).editor.body)).toBe('Начало 😊 конец');await page.locator('#qrClose').click();await openLibrary(page);await expect(body).toHaveValue('Начало 😊 конец');
  await page.setViewportSize({width,height:420});await page.locator('#qrEmojiToggle').scrollIntoViewIfNeeded();await page.locator('#qrEmojiToggle').click();const last=page.locator('#qrEmojiPicker [data-qr-emoji]').last();await last.scrollIntoViewIfNeeded();await expect(last).toBeInViewport();await bounded(page);expect(await page.locator('#qrEmojiPicker').evaluate(node=>node.scrollWidth-node.clientWidth)).toBeLessThanOrEqual(1);await capture(page,browserName,width,'emoji-editor-short');
  await page.keyboard.press('Escape');await expect(page.locator('#qrEmojiPicker')).toBeHidden();await expect(page.locator('#quickRepliesDialog')).toBeVisible();await expect(page.locator('#qrEmojiToggle')).toBeFocused();await page.setViewportSize({width,height:900});await page.locator('#qrSave').click();await expect.poll(()=>s.replies.find(r=>r.title==='Приветствие со смайликом')?.body).toBe('Начало 😊 конец');const created=s.replies.find(r=>r.title==='Приветствие со смайликом');
  const preview=page.locator('#qrPreview');await preview.fill('До заменить после');await preview.evaluate(node=>node.setSelectionRange(3,11));await page.locator('#qrEmojiToggle').click();await page.locator('#qrEmojiPicker [data-qr-emoji="😎"]').focus();await page.keyboard.press('Enter');await expect(preview).toHaveValue('До 😎 после');await expect(preview).toBeFocused();expect(await preview.evaluate(node=>[node.selectionStart,node.selectionEnd])).toEqual([5,5]);
  await expect(page.locator('#replyText')).toHaveValue('Черновик клиента 🌍');await expect(page.locator('#mediaSelection')).toHaveText(attachment);await capture(page,browserName,width,'emoji-preview');await page.locator('#qrInsert').click();await expect(page.locator('#replyText')).toHaveValue('Черновик клиента 🌍\nДо 😎 после');await expect(page.locator('#mediaSelection')).toHaveText(attachment);
  await reloadFixture(page,s);await openLead(page);await openLibrary(page);await page.locator('[data-qr-select="'+created.id+'"]').click();await page.locator('#qrEdit').click();await expect(body).toHaveValue('Начало 😊 конец');expect(s.calls.filter(c=>c.action==='create')).toHaveLength(1);clean(s);
 });
 test('row removal changes only personal panel membership, keeps library and reload order',async({page,browserName})=>{
  const s=await setup(page,{selectedIds:[1,2,3]});await openLead(page);await page.locator('#replyText').fill('Текст до настройки панели');await page.locator('#replyFile').setInputFiles({name:'remove-draft.txt',mimeType:'text/plain',buffer:Buffer.from('Keep selected file')});const attachment=await page.locator('#mediaSelection').textContent();await page.locator('#quickRepliesOpen').click();
  // Opening settings renders cached rows while refreshing the account panel.
  // Wait for that read before the short-viewport scroll targets its final row.
  await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','false');
  const remove=page.locator('[data-qr-remove-id="1"]');await expect(remove).toHaveAccessibleName('Убрать с панели: Личное приветствие');await page.setViewportSize({width,height:420});await remove.scrollIntoViewIfNeeded();await expect(remove).toBeInViewport();await bounded(page);await capture(page,browserName,width,'panel-remove-short');await remove.click();await expectPanel(page,[2,3]);
  expect(s.replies.map(r=>r.id)).toEqual([1,2,3]);expect(s.calls.filter(c=>c.action==='delete')).toHaveLength(0);expect(s.calls.filter(c=>c.action==='favorite')).toHaveLength(1);expect(s.calls.find(c=>c.action==='favorite')).toMatchObject({id:1,is_favorite:false});
  await page.setViewportSize({width,height:900});await page.locator('[data-qr-scope="all"]').click();await page.locator('[data-qr-select="1"]').click();await expect(page.locator('#qrFavorite')).toHaveText('Добавить на панель');await expect(page.locator('#qrDelete')).toHaveText('Удалить ответ');await page.locator('#qrClose').click();await expect(page.locator('#replyText')).toHaveValue('Текст до настройки панели');await expect(page.locator('#mediaSelection')).toHaveText(attachment);
  await reloadFixture(page,s);await openLead(page);await expectPanel(page,[2,3]);await openLibrary(page);await page.locator('[data-qr-select="1"]').click();await page.locator('#qrFavorite').click();await expectPanel(page,[2,3,1]);await page.locator('#qrClose').click();await reloadFixture(page,s);await openLead(page);await expectPanel(page,[2,3,1]);clean(s);
 });
});

test('full delete is explicit, cancellable and respects personal/shared role rights',async({page})=>{
 const s=await setup(page,{selectedIds:[1,2]});await openLead(page);await openLibrary(page);await page.locator('[data-qr-select="1"]').click();await expect(page.locator('#qrDelete')).toHaveText('Удалить ответ');let confirmation='';page.once('dialog',dialog=>{confirmation=dialog.message();return dialog.dismiss()});await page.locator('#qrDelete').click();expect(confirmation).toMatch(/Удалить.*ответ/i);expect(s.calls.filter(c=>c.action==='delete')).toHaveLength(0);await expectPanel(page,[1,2]);
 page.once('dialog',dialog=>dialog.accept());await page.locator('#qrDelete').click();await expectPanel(page,[2]);await expect(page.locator('[data-qr-select="1"]')).toHaveCount(0);expect(s.replies.find(r=>r.id===1)).toBeUndefined();await page.locator('[data-qr-select="2"]').click();await expect(page.locator('#qrDelete')).toHaveCount(0);await expect(page.locator('#qrFavorite')).toHaveText('Убрать с панели');await page.locator('#qrFavorite').click();await expectPanel(page,[]);expect(s.replies.find(r=>r.id===2)).toBeDefined();await page.locator('#qrClose').click();
 s.switchManager({id:7,role:'admin',display_name:'Мария',is_working:true});await page.evaluate(()=>{WorkspaceV2.S.manager.role='admin';WorkspaceV2QuickReplies.resetSession();WorkspaceV2QuickReplies.configure()});s.setPanel([2],7);s.setPanel([2],8);await openLibrary(page);await page.locator('[data-qr-select="2"]').click();page.once('dialog',dialog=>{confirmation=dialog.message();return dialog.dismiss()});await page.locator('#qrDelete').click();expect(confirmation).toMatch(/общий.*всех|всех.*менеджер/i);expect(s.replies.find(r=>r.id===2)).toBeDefined();page.once('dialog',dialog=>dialog.accept());await page.locator('#qrDelete').click();await expectPanel(page,[]);expect(s.toolbar(8).replies).toEqual([]);await expect(page.locator('[data-qr-select="2"]')).toHaveCount(0);expect(s.calls.filter(c=>c.action==='delete')).toHaveLength(2);clean(s);
});

test('unknown row removal remains pending and reconciles one favorite command',async({page})=>{
 const s=await setup(page,{selectedIds:[1,2],unknownFavorite:true});await openLead(page);await page.locator('#quickRepliesOpen').click();await page.locator('[data-qr-remove-id="1"]').click();await expect(page.locator('#qrPending')).toBeVisible();await expectPanel(page,[1,2]);await expect(page.locator('[data-qr-remove-id="2"]')).toBeDisabled();await page.locator('#qrResolve').click();await expect(page.locator('#qrPending')).toBeHidden();await expectPanel(page,[2]);expect(s.replies.map(r=>r.id)).toEqual([1,2,3]);expect(s.calls.filter(c=>c.action==='favorite')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='operation')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='delete')).toHaveLength(0);clean(s);
});

test('concurrent panel change rejects stale row removal and preserves new order',async({page})=>{
 const s=await setup(page,{selectedIds:[1,2]});await openLead(page);await page.locator('#quickRepliesOpen').click();await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','false');s.setPanel([2,1]);await page.locator('[data-qr-remove-id="1"]').click();await expect(page.locator('#qrStatus')).toContainText('другом устройстве');await expectPanel(page,[2,1]);expect(s.toolbar().replies.map(r=>r.id)).toEqual([2,1]);await page.locator('[data-qr-remove-id="1"]').click();await expectPanel(page,[2]);expect(s.calls.filter(c=>c.action==='favorite')).toHaveLength(2);expect(s.calls.filter(c=>c.action==='delete')).toHaveLength(0);clean(s);
});

test('late row-removal result cannot restore private buttons after account change',async({page})=>{
 const s=await setup(page,{selectedIds:[1,2],holdFavorite:true});await openLead(page);await page.locator('#quickRepliesOpen').click();await page.locator('[data-qr-remove-id="1"]').click();await expect(page.locator('[data-qr-remove-id="2"]')).toBeDisabled();s.switchManager({id:8,role:'manager',display_name:'Ольга',is_working:true});
 await page.evaluate(()=>{WorkspaceV2.S.authGeneration++;WorkspaceV2QuickReplies.resetSession();WorkspaceV2Conversation.resetForIdentityChange();WorkspaceV2.S.manager={id:8,role:'manager',display_name:'Ольга',is_working:true};WorkspaceV2Conversation.activateReplySession(8);WorkspaceV2QuickReplies.configure()});await expectPanel(page,[]);const late=page.waitForResponse(response=>response.url().endsWith('/quick-replies-api.php')&&response.request().postDataJSON()?.action==='favorite');s.releaseFavorite();await late;await expectPanel(page,[]);expect(s.toolbar(7).replies.map(r=>r.id)).toEqual([2]);expect(s.toolbar(8).replies).toEqual([]);await openLead(page);await openLibrary(page);await expect(page.locator('[data-qr-select="1"]')).toHaveCount(0);await expect(page.locator('[data-qr-select="3"]')).toHaveCount(0);clean(s);
});

test('unknown emoji template save disables palette and preserves exact draft for receipt recovery',async({page})=>{
 const s=await setup(page,{unknownCreate:true});await openLead(page);await openLibrary(page);await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Сохранение 😊');await page.locator('#qrBody').fill('Спасибо ');await page.locator('#qrEmojiToggle').click();await page.locator('#qrEmojiPicker [data-qr-emoji="🙏"]').click();await page.locator('#qrSave').click();await expect(page.locator('#qrPending')).toBeVisible();await expect(page.locator('#qrEmojiToggle')).toBeDisabled();await expect(page.locator('#qrBody')).toHaveValue('Спасибо 🙏');expect(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('workspaceV2.quickReplyDraft.7')).editor.body)).toBe('Спасибо 🙏');await page.locator('#qrResolve').click();await expect(page.locator('#qrPending')).toBeHidden();expect(s.replies.find(r=>r.title==='Сохранение 😊')?.body).toBe('Спасибо 🙏');expect(s.calls.filter(c=>c.action==='create')).toHaveLength(1);clean(s);
});

test('unknown full delete disables emoji editing and reconciles without another delete',async({page})=>{
 const s=await setup(page,{selectedIds:[1,2],unknownDelete:true});await openLead(page);await openLibrary(page);await page.locator('[data-qr-select="1"]').click();page.once('dialog',dialog=>dialog.accept());await page.locator('#qrDelete').click();await expect(page.locator('#qrPending')).toBeVisible();await expect(page.locator('#qrEmojiToggle')).toBeDisabled();await expect(page.locator('#qrDelete')).toBeDisabled();await page.locator('#qrResolve').click();await expect(page.locator('#qrPending')).toBeHidden();await expectPanel(page,[2]);await expect(page.locator('[data-qr-select="1"]')).toHaveCount(0);expect(s.calls.filter(c=>c.action==='delete')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='operation')).toHaveLength(1);clean(s);
});

for(const width of [390,430,768,1440])test.describe('Quick reply full cycle '+width,()=>{
 test.use({viewport:{width,height:900}});
 test('library filtering, CRUD and explicit insertion preserve draft and attachment',async({page,browserName})=>{
  const s=await setup(page);await openLead(page);await page.locator('#replyText').fill('Существующий черновик ответа');await page.locator('#conversationTitle').click();await capture(page,browserName,width,'after-shell');
  await page.locator('#replyFile').setInputFiles({name:'synthetic.txt',mimeType:'text/plain',buffer:Buffer.from('Isolated file')});
  const attachment=await page.locator('#mediaSelection').textContent();
  await openLibrary(page);await expect(page.locator('#quickRepliesDialog')).toBeVisible();
  await expect(page.locator('[data-qr-select]')).toHaveCount(3);await bounded(page);await capture(page,browserName,width,'library');
  await page.locator('[data-qr-scope="shared"]').click();await expect(page.locator('[data-qr-select]')).toHaveCount(1);
  await page.locator('[data-qr-select="2"]').click();await expect(page.locator('#qrEdit')).toHaveCount(0);await expect(page.locator('#qrDelete')).toHaveCount(0);
  await page.locator('[data-qr-scope="toolbar"]').click();await expect(page.locator('[data-qr-select]')).toHaveCount(1);
  await page.locator('[data-qr-scope="all"]').click();await page.locator('#qrCategory').selectOption('Приветствие');await expect(page.locator('[data-qr-select]')).toHaveCount(1);
  await page.locator('#qrCategory').selectOption('');await page.locator('#qrSearch').fill('пожелания');await expect(page.locator('[data-qr-select]')).toHaveCount(1);
  await page.locator('[data-qr-select="3"]').click();await expect(page.locator('#qrInsert')).toBeDisabled();await page.locator('#qrPreview').fill('Какие пожелания к отелю?');await expect(page.locator('#qrInsert')).toBeEnabled();
  await page.locator('#qrSearch').fill('');await expect(page.locator('[data-qr-select]')).toHaveCount(3);await page.locator('[data-qr-select="1"]').click();
  await expect(page.locator('#qrPreview')).toHaveValue('Здравствуйте, Анна Морозова! Меня зовут Мария.');await page.locator('#qrFavorite').click();await expect.poll(()=>s.replies[0].is_favorite).toBe(true);
  await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Проверить даты');await page.locator('#qrBody').fill('Какие даты вы рассматриваете?');await page.locator('#qrEditCategory').fill('Уточнение');await page.locator('#qrSave').click();
  await expect.poll(()=>s.replies.some(r=>r.title==='Проверить даты')).toBe(true);const created=s.replies.find(r=>r.title==='Проверить даты');
  await page.locator('[data-qr-select="'+created.id+'"]').click();await page.locator('#qrEdit').click();await page.locator('#qrTitle').fill('Уточнить даты');await page.locator('#qrSave').click();await expect.poll(()=>created.title).toBe('Уточнить даты');
  await page.locator('#qrCopy').click();await expect.poll(()=>s.replies.length).toBe(5);
  page.once('dialog',d=>d.accept());await page.locator('#qrDelete').click();await expect.poll(()=>s.replies.length).toBe(4);
  await page.locator('[data-qr-select="1"]').click();await page.locator('#qrPreview').fill('Здравствуйте, Анна! Меня зовут Мария.');await capture(page,browserName,width,'preview');
  await page.locator('#qrInsert').click();await expect(page.locator('#quickRepliesDialog')).not.toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Существующий черновик ответа\nЗдравствуйте, Анна! Меня зовут Мария.');await expect(page.locator('#replyText')).toBeFocused();await expect(page.locator('#mediaSelection')).toHaveText(attachment);
  await capture(page,browserName,width,'after');await page.setViewportSize({width,height:420});await bounded(page);expect((await page.locator('#sendReply').boundingBox()).y+(await page.locator('#sendReply').boundingBox()).height).toBeLessThanOrEqual(421);await capture(page,browserName,width,'after-short');await page.locator('#conversationTitle').click();await openLibrary(page);await expect(page.locator('#quickRepliesDialog')).toBeVisible();await page.locator('[data-qr-select=\"1\"]').click();await page.locator('#qrInsert').scrollIntoViewIfNeeded();await expect(page.locator('#qrInsert')).toBeInViewport();await capture(page,browserName,width,'library-short');await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Черновик на коротком экране');await page.locator('#qrBody').fill('Текст на коротком экране');await page.locator('#qrSave').scrollIntoViewIfNeeded();await expect(page.locator('#qrSave')).toBeInViewport();await capture(page,browserName,width,'editor-short');await page.keyboard.press('Escape');await expect(page.locator('#quickRepliesOpen')).toBeFocused();await openLibrary(page);await expect(page.locator('#qrTitle')).toHaveValue('Черновик на коротком экране');await page.locator('#qrCancelEdit').click();await page.locator('#qrClose').click();
  expect(s.calls.filter(c=>c.path==='/manager/quick-replies-api.php'&&c.action==='create')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='list'&&c.path==='/manager/pipeline-api.php')).toHaveLength(1);clean(s);
 });
});

for(const width of [390,430,768,1440])test.describe('Response cycle '+width,()=>{
 test.use({viewport:{width,height:900}});
 test('transcript opens ahead of held card, search and next retain correct draft/file',async({page,browserName})=>{
  const s=await setup(page,{holdCard:true});const before=Date.now();await openLead(page);const elapsed=Date.now()-before;
  await expect(page.locator('#messages .msg')).toHaveCount(35);await expect(page.locator('#leadCard')).toContainText('Загружаем');
  await page.locator('#replyText').fill('Черновик Анны');await page.locator('#replyFile').setInputFiles({name:'Anna.txt',mimeType:'text/plain',buffer:Buffer.from('File for Anna')});
  const attachment=await page.locator('#mediaSelection').textContent();
  await useConversationTool(page,'conversationSearchOpen');await expect(page.locator('#conversationSearchInput')).toBeFocused();await page.locator('#conversationSearchInput').fill('у моря');await expect(page.locator('#conversationSearchCount')).toHaveText('1 из 35');await expect(page.locator('#conversationSearchScope')).toContainText('Только загруженные');
  const original=await page.locator('#messages .msgBody').allTextContents();await page.locator('#conversationSearchInput').press('Enter');await expect(page.locator('#conversationSearchCount')).toHaveText('2 из 35');await page.locator('#conversationSearchInput').press('Shift+Enter');await expect(page.locator('#conversationSearchCount')).toHaveText('1 из 35');
  const position=await page.locator('#messages').evaluate(el=>el.scrollTop);s.releaseCard();await expect(page.locator('#leadCard')).not.toContainText('Загружаем');expect(await page.locator('#messages .msgBody').allTextContents()).toEqual(original);expect(Math.abs(await page.locator('#messages').evaluate(el=>el.scrollTop)-position)).toBeLessThanOrEqual(3);
  await capture(page,browserName,width,'search');await page.setViewportSize({width,height:420});await bounded(page);expect(await page.locator('#messages').evaluate(el=>el.clientHeight)).toBeGreaterThanOrEqual(40);const composer=await page.locator('#sendReply').boundingBox();expect(composer.y+composer.height).toBeLessThanOrEqual(421);await capture(page,browserName,width,'search-short');await page.setViewportSize({width,height:900});
  await page.locator('#conversationSearchInput').fill('не существующий текст');await expect(page.locator('#conversationSearchCount')).toHaveText('Совпадений нет');await expect(page.locator('#conversationSearchNext')).toBeDisabled();await page.locator('#conversationSearchInput').press('Escape');await expect(page.locator('#conversationSearchOpen')).toBeFocused();await expect(page.locator('.conversationSearchHit')).toHaveCount(0);
  await useConversationTool(page,'nextWaitingConversation');await expect(page.locator('#conversationTitle')).toHaveText('Алексей Иванов');await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('#mediaSelection')).toBeHidden();await page.locator('#replyText').fill('Черновик Алексея');
  await useConversationTool(page,'nextWaitingConversation');await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');await expect(page.locator('#replyText')).toHaveValue('Черновик Анны');await expect(page.locator('#mediaSelection')).toHaveText(attachment);
  expect(s.calls.filter(c=>c.action==='detail'&&c.path==='/manager/api.php')).toHaveLength(3);expect(s.calls.filter(c=>c.action==='list'&&c.path==='/manager/pipeline-api.php')).toHaveLength(1);console.log('Fixture transcript visible while card held: '+width+'px '+elapsed+'ms; no production SLA claim.');clean(s);
 });
});

test('quick replies failure/loading/empty states, persistent editor and account isolation',async({page})=>{
 const s=await setup(page,{holdList:true});await openLead(page);await openLibrary(page);await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','true');await expect(page.locator('#qrList')).toContainText('Загружаем ответы');s.setListError(true);s.releaseList();await expect(page.locator('#qrList')).toContainText('Не удалось загрузить');
 s.setHoldList(false);s.setListError(false);await page.locator('[data-qr-retry]').click();await expect(page.locator('[data-qr-select]')).toHaveCount(3);
 await page.locator('#qrSearch').fill('отсутствующий ответ');await expect(page.locator('#qrList')).toContainText('Подходящих ответов нет');await page.locator('#qrSearch').fill('');await expect(page.locator('[data-qr-select]')).toHaveCount(3);
 await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Мой незавершённый ответ');await page.locator('#qrBody').fill('Личный черновик шаблона');await page.locator('#qrClose').click();await expect(page.locator('#quickRepliesOpen')).toBeFocused();await openLibrary(page);await expect(page.locator('#qrTitle')).toHaveValue('Мой незавершённый ответ');await page.locator('#qrClose').click();
 await page.evaluate(()=>{WorkspaceV2.S.authGeneration++;WorkspaceV2QuickReplies.resetSession();WorkspaceV2Conversation.resetForIdentityChange();WorkspaceV2.S.manager={id:8,role:'manager',display_name:'Ольга',is_working:true};WorkspaceV2Conversation.activateReplySession(8)});
 await page.evaluate(()=>WorkspaceV2QuickReplies.open());await expect(page.locator('#qrTitle')).toHaveCount(0);await expect(page.locator('#qrDetail')).not.toContainText('Личный черновик');expect(await page.evaluate(()=>sessionStorage.getItem('workspaceV2.quickReplyDraft.7'))).toContain('Личный черновик шаблона');clean(s);
});

test('shared templates admin CRUD scope and stale insertion cannot target another client',async({page})=>{
 const s=await setup(page,{role:'admin'});await openLead(page);await openLibrary(page);await page.locator('[data-qr-select="2"]').click();await expect(page.locator('#qrEdit')).toBeVisible();await page.locator('#qrEdit').click();await expect(page.locator('#qrEditScope')).toHaveValue('shared');await expect(page.locator('#qrEditScope')).toBeDisabled();await page.locator('#qrCancelEdit').click();
 await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Общий ответ команды');await page.locator('#qrBody').fill('Проверим перелёт');await page.locator('#qrEditScope').selectOption('shared');await page.locator('#qrSave').click();await expect.poll(()=>s.replies.some(r=>r.title==='Общий ответ команды'&&r.scope==='shared')).toBe(true);
 await page.locator('[data-qr-select="1"]').click();await page.evaluate(()=>WorkspaceV2.S.current=12);await page.locator('#qrInsert').click();await expect(page.locator('#quickRepliesDialog')).toBeVisible();await expect(page.locator('#replyText')).toHaveValue('');clean(s);
});

test('fixture performance measured click to visible transcript uses one list and one detail per open',async({page})=>{
 const s=await setup(page,{cardDelay:250});const samples=[];
 for(let i=0;i<10;i++){
  const id=i%2?12:11;
  const started=Date.now();await openLead(page,id);await expect(page.locator('#conversationTitle')).toHaveText(id===11?'Анна Морозова':'Алексей Иванов');await expect(page.locator('#messages .msg')).toHaveCount(35);samples.push(Date.now()-started);
 }
 const sorted=[...samples].sort((a,b)=>a-b),p50=sorted[4],p95=sorted[9];
 console.log(JSON.stringify({fixture:'35messages/2clients/250ms-synthetic-card',metric:'browser-click-to-visible-transcript',n:10,p50,p95,samples,http:{list:s.calls.filter(c=>c.path==='/manager/pipeline-api.php'&&c.action==='list').length,transcript:s.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail').length,card:s.calls.filter(c=>c.path==='/manager/pipeline-api.php'&&c.action==='detail').length},production:false}));
 expect(s.calls.filter(c=>c.path==='/manager/pipeline-api.php'&&c.action==='list')).toHaveLength(1);expect(s.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail')).toHaveLength(10);clean(s);
});

test('unknown committed template creation reconciles receipt without duplicate create',async({page})=>{
 const s=await setup(page,{unknownCreate:true});await openLead(page);await openLibrary(page);await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Подтверждение сохранения');await page.locator('#qrBody').fill('Текст для проверки сохранения');await page.locator('#qrSave').click();
 await expect(page.locator('#qrPending')).toBeVisible();await expect(page.locator('#qrStatus')).toContainText('Не удалось подтвердить');await expect(page.locator('#qrSave')).toBeDisabled();await page.locator('#qrResolve').click();await expect(page.locator('#qrPending')).toBeHidden();await expect(page.locator('#qrStatus')).toContainText('Ответ создан');expect(s.replies.filter(r=>r.title==='Подтверждение сохранения')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='create')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='operation')).toHaveLength(1);clean(s);
});

test('edit conflict keeps manager text and stale list cannot cross identity',async({page})=>{
 const s=await setup(page,{conflictUpdate:true});await openLead(page);await openLibrary(page);await page.locator('[data-qr-select="1"]').click();await page.locator('#qrEdit').click();await page.locator('#qrBody').fill('Мой текст при конфликте');await page.locator('#qrSave').click();await expect(page.locator('#qrStatus')).toContainText('изменён в другом окне');await expect(page.locator('#qrBody')).toHaveValue('Мой текст при конфликте');await expect(page.locator('#qrReloadConflict')).toBeVisible();
 await page.locator('#qrCancelEdit').click();await page.locator('#qrClose').click();s.setHoldList(true);await openLibrary(page);await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','true');await page.evaluate(()=>{WorkspaceV2.S.authGeneration++;WorkspaceV2QuickReplies.resetSession();WorkspaceV2.S.manager={id:8,role:'manager',display_name:'Ольга'}});s.releaseList();await expect(page.locator('#quickRepliesDialog')).toHaveCount(0);s.setHoldList(false);await page.evaluate(()=>WorkspaceV2QuickReplies.open());await expect(page.locator('#qrDetail')).not.toContainText('Мой текст при конфликте');clean(s);
});

test('quoted title/category stay inert text when template editor is reopened',async({page})=>{
 const s=await setup(page);await openLead(page);await openLibrary(page);await page.locator('#qrCreate').click();const title='Приветствие " onclick="alert(1)',category='Семья "<Trip>';
 await page.locator('#qrTitle').fill(title);await page.locator('#qrEditCategory').fill(category);await page.locator('#qrBody').fill('Здравствуйте!');await page.locator('#qrSave').click();await expect(page.locator('#qrDetail h3')).toHaveText(title);await page.locator('#qrEdit').click();await expect(page.locator('#qrTitle')).toHaveValue(title);await expect(page.locator('#qrEditCategory')).toHaveValue(category);expect(await page.locator('#qrTitle').getAttribute('onclick')).toBeNull();expect(await page.locator('#qrEditCategory').getAttribute('onclick')).toBeNull();clean(s);
});

test('workspace shortcuts respect typing, IME and modal focus',async({page})=>{
 const s=await setup(page);await openLead(page);await useConversationTool(page,'conversationSearchOpen','focus');await page.keyboard.press('Control+Alt+r');await expect(page.locator('#replyText')).toBeFocused();await page.locator('#replyText').fill('Нельзя прерывать ввод');await page.keyboard.press('Control+Alt+j');await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');
 await useConversationTool(page,'conversationSearchOpen','focus');await page.evaluate(()=>document.getElementById('conversationSearchOpen').dispatchEvent(new KeyboardEvent('keydown',{key:'j',ctrlKey:true,altKey:true,isComposing:true,bubbles:true})));await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');
 await page.keyboard.press('Control+Alt+f');await expect(page.locator('#conversationSearchInput')).toBeFocused();await page.locator('#conversationSearchInput').press('Escape');await page.keyboard.press('Control+Alt+q');await expect(page.locator('#quickRepliesDialog')).toBeVisible();await page.locator('[data-qr-select="1"]').click();await page.locator('#qrCopy').focus();await page.keyboard.press('Control+Alt+j');await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');await page.keyboard.press('Escape');await expect(page.locator('#quickRepliesDialog')).not.toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Нельзя прерывать ввод');clean(s);
});

async function activeSearchTextVisible(page){
 await expect.poll(()=>page.locator('#messages').evaluate(box=>{
  const mark=box.querySelector('mark.isCurrentSearchHit');if(!mark)return false;
  const viewport=box.getBoundingClientRect(),top=viewport.top+box.clientTop,bottom=top+box.clientHeight;
  const rects=[...mark.getClientRects()];
  return rects.length>0&&rects.every(rect=>rect.top>=top-1&&rect.bottom<=bottom+1&&rect.height>0);
 })).toBe(true);
}
for(const width of [390,430,768,1440])test.describe('Search actual text visibility '+width,()=>{
 test.use({viewport:{width,height:420}});
 test('late second match in a multiline message is visible with a selected file and draft',async({page,browserName})=>{
  const lines=Array.from({length:60},(_,i)=>i===2?'Первая договорённость: ориентир':i===53?'Поздняя договорённость: ориентир':'Строка '+i+' с подробностями поездки.');
  const text=lines.join('\n'),messages=[{id:401,sender_type:'customer',text,created_at:'2026-10-13 12:00:00'},...Array.from({length:8},(_,i)=>({id:402+i,sender_type:'manager',text:'Последующий контекст '+i,created_at:'2026-10-13 12:01:00'}))];
  const s=await setup(page,{messages});await openLead(page);await page.locator('#replyText').fill('Черновик без отправки');
  await page.locator('#replyFile').setInputFiles({name:'search-context.txt',mimeType:'text/plain',buffer:Buffer.from('Isolated attachment')});
  const attachment=await page.locator('#mediaSelection').textContent();
  await useConversationTool(page,'conversationSearchOpen');await page.locator('#conversationSearchInput').fill('ориентир');
  await expect(page.locator('#conversationSearchCount')).toHaveText('1 из 2');await activeSearchTextVisible(page);
  const firstTop=await page.locator('#messages').evaluate(el=>el.scrollTop);
  await page.locator('#conversationSearchNext').click();await expect(page.locator('#conversationSearchCount')).toHaveText('2 из 2');await activeSearchTextVisible(page);
  expect(await page.locator('#messages').evaluate(el=>el.scrollTop)).toBeGreaterThan(firstTop+400);
  await expect(page.locator('#messages .msgBody').first()).toHaveText(text);
  await expect(page.locator('#replyText')).toHaveValue('Черновик без отправки');await expect(page.locator('#mediaSelection')).toHaveText(attachment);
  const send=await page.locator('#sendReply').boundingBox();expect(send.y+send.height).toBeLessThanOrEqual(421);
  await capture(page,browserName,width,'search-late-text-short');
  await page.locator('#conversationSearchPrevious').click();await expect(page.locator('#conversationSearchCount')).toHaveText('1 из 2');await activeSearchTextVisible(page);
  clean(s);
 });
});
