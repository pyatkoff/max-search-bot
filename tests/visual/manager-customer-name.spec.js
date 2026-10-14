const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
const sourceRoot=process.env.M2_QA_SOURCE_ROOT||root;
const readSource=relative=>fs.readFileSync(path.join(sourceRoot,relative));
const shell=execFileSync('php',['manager2/index.php'],{cwd:sourceRoot,encoding:'utf8'});
// Actual PHP and source assets. All data and mutations below are isolated fixture
// responses. No production traffic or customer messages are permitted.
async function setup(page,{role='manager',holdList=false,listError=false,holdCard=false,cardDelay=0,unknownSave=false,conflictSave=false,nameError=false,otherOwner=false,holdGet=false,denySave=false,historySize=0}={}){
 const calls=[],unexpected=[],errors=[],heldLists=[],heldCards=[],receipts=new Map();
 let manager={id:7,role,display_name:'Мария',is_working:true};
 let version=0,workingName=null;
 const names=()=>({customer_id:101,display_name:workingName||'Анна Морозова',original_display_name:'Анна Морозова',working_name:workingName,version,updated_at:version?'2026-10-14 02:00:00':null,updated_by_manager_name:version?'Мария':null,can_edit:role==='admin'||!otherOwner});
 const history=[];for(let i=historySize;i>0;i--)history.push({version:i,before_name:i>1?'Историческое имя '+(i-1):null,after_name:'Историческое имя '+i,actor_name:'Мария',created_at:'2026-10-14 02:00:00'});
 const conv=id=>({id,customer_id:id===11?101:102,manager_id:otherOwner?8:manager.id,manager_name:otherOwner?'Ольга':manager.display_name,status:'manager',channel:'telegram',display_name:id===11?names().display_name:'Алексей Иванов',original_display_name:id===11?'Анна Морозова':'Алексей Иванов',working_name:id===11?workingName:null,name_version:id===11?version:0,customer_name_version:id===11?version:0});
 const msgs=()=>Array.from({length:35},(_,i)=>({id:i+1,sender_type:i%3===0?'manager':'customer',manager_id:i%3===0?manager.id:null,text:i===34?'Посмотрите варианты у моря. Бюджет до 250 тысяч за всех.':'Сообщение '+(i+1)+'. Обсуждаем отели у моря и даты.',created_at:'2026-10-13 12:00:00'}));
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url()),method=req.method();
  const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
  const block=()=>{unexpected.push(method+' '+url.pathname);return route.abort()};
  if(url.origin!==origin)return block();
  if(method==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
  // WebKit may fetch the canonical worker script; this fixture exercises UI,
  // while notification/worker behavior remains in its dedicated suite.
  if(method==='GET'&&url.pathname==='/manager/sw.js')return route.fulfill({contentType:'application/javascript',body:''});
  // Optional portraits are absent for these synthetic clients; the dedicated avatar suite covers images/ACL.
  if(req.method()==='GET'&&url.pathname==='/manager/customer-avatar.php'){expect([...url.searchParams.keys()]).toEqual(['conversation_id']);expect(['11','12']).toContain(url.searchParams.get('conversation_id'));return route.fulfill({status:404,body:''})}
  if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
  if(method==='GET'&&/^\/manager2?\/assets\/[\w.-]+$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:readSource(url.pathname.slice(1))});
  if(!url.pathname.startsWith('/manager/'))return block();
  let d={};if(method==='POST'){try{d=req.postDataJSON()||{}}catch(e){return block()}}
  calls.push({path:url.pathname,...d});
  if(url.pathname==='/manager/api.php'){
   if(d.action==='me')return reply({ok:true,manager,csrf:'fixture-csrf',projects:[]});
   if(d.action==='detail')return reply({ok:true,conversation:conv(d.conversation_id),messages:msgs(),delivery_failure:null});
   if(d.action==='counts')return reply({ok:true,counts:{waiting:{count:2,unread:2},mine:{count:2}}});
   if(d.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
  }
  if(url.pathname==='/manager/pipeline-api.php'){
   if(d.action==='catalog')return reply({ok:true,stages:[{id:1,stage_key:'working',display_name:'В работе',sort_order:1}],tags:[],outcomes:{open:'В работе'},close_reasons:{}});
   if(d.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager]}});
   if(d.action==='list')return reply({ok:true,conversations:[11,12].filter(id=>!d.search||(conv(id).display_name+' '+conv(id).original_display_name).toLowerCase().includes(String(d.search).toLowerCase())).map(id=>({...conv(id),project_label:'AnyTour',origin_label:'TG · реклама',can_edit_pipeline:true,lead_stage:{id:1,stage_key:'working',display_name:'В работе'},last_text:'Ждём варианты у моря',unread_count:id===11?2:1,awaiting_manager_reply:1,last_sender_type:'customer',lead_outcome:'open'}))});
   if(d.action==='detail'){
    if(holdCard)await new Promise(resolve=>heldCards.push(resolve));
    if(cardDelay)await new Promise(resolve=>setTimeout(resolve,cardDelay));
    return reply({ok:true,can_edit_pipeline:true,pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},tasks:[],trip:{city:'Москва',country:'Турция',adults:2,budget:{max:250000,currency:'RUB',basis:'total'}},contact:{},source:{},handoff:{}});
   }
  }
  if(url.pathname==='/manager/quick-replies-api.php'&&d.action==='list')return reply({ok:true,replies:[{id:1,title:'Приветствие',body:'Здравствуйте, {client_name}!',category:'',scope:'personal',version:1,can_edit:true,is_favorite:false}],categories:[],has_more:false});
  if(url.pathname==='/manager/customer-name-api.php'){
   if(d.csrf!=='fixture-csrf')throw new Error('Working name must use canonical CSRF');
   if(d.action==='get'){
    if(holdGet)await new Promise(resolve=>heldLists.push(resolve));
    if(nameError)return reply({ok:false,error:'storage_unavailable'},503);
    const rows=history.filter(h=>!d.before_version||h.version<Number(d.before_version)),limit=Number(d.limit)||20;return reply({ok:true,name:names(),history:rows.slice(0,limit),history_has_more:rows.length>limit});
   }
   if(d.action==='save'){
    if(denySave)return reply({ok:false,error:'forbidden'},403);
    if(otherOwner&&role!=='admin')return reply({ok:false,error:'forbidden'},403);
    if(conflictSave){workingName='Изменено коллегой';version++;conflictSave=false;return reply({ok:false,error:'conflict',current:names()},409);}
    if(Number(d.version)!==version)return reply({ok:false,error:'conflict',current:names()},409);
    const before=workingName;workingName=d.working_name?.trim()||null;version++;history.unshift({version,before_name:before,after_name:workingName,actor_name:manager.display_name,created_at:'2026-10-14 02:00:00'});
    const result={ok:true,name:names()};receipts.set(d.operation_id,result);if(unknownSave)return route.abort('failed');return reply(result);
   }
   if(d.action==='operation')return reply({ok:true,found:receipts.has(d.operation_id),result:receipts.get(d.operation_id)||null});
  }
  if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
  if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
  unexpected.push(method+' '+url.pathname+' '+d.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
 });
 await page.goto(origin+'/manager2/index.php?qa=1');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
 return {calls,unexpected,errors,names,history,releaseList:()=>heldLists.shift()?.(),releaseCard:()=>heldCards.shift()?.(),switchManager:m=>{manager=m},setListError:v=>{listError=v},setHoldList:v=>{holdList=v},setNameError:v=>{nameError=v},setHoldGet:v=>{holdGet=v},setDenySave:v=>{denySave=v},advanceName:label=>{const before=workingName;workingName=label;version++;history.unshift({version,before_name:before,after_name:label,actor_name:'Ольга',created_at:'2026-10-14 02:00:00'})}};
}
async function openLead(page,id=11){if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox'))await page.locator('#mobileBack').click();await page.locator('#inboxList [data-conversation-id="'+id+'"]').click();await expect(page.locator('#composer')).toBeVisible()}
async function capture(page,browser,width,state){const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(dir,`customer-name-${browser}-${width}-${state}.png`)})}
async function bounded(page){expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1)}
function clean(s){expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(c=>c.action==='send')).toEqual([])}

for(const width of [390,430,768,1440])test.describe('Customer names '+width,()=>{
 test.use({viewport:{width,height:900}});
 test('rename projects consistently without losing reply/file/task drafts, reload and restore original',async({page,browserName})=>{
  const s=await setup(page);expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php')).toHaveLength(0);await openLead(page);await page.locator('#replyText').fill('Черновик для Анны');await page.locator('#replyFile').setInputFiles({name:'Anna.txt',mimeType:'text/plain',buffer:Buffer.from('Private test file')});const file=await page.locator('#mediaSelection').textContent();
  if(width<=1100)await page.locator('#mobileLeadBtn').click();await page.locator('.m2TaskCreate>summary').click();await page.locator('#leadTaskTitle').fill('Черновик следующего действия');await page.locator('#leadTaskDue').fill('2026-10-15T10:00');
  await page.locator('#customerNameCardOpen').click();await expect(page.locator('#customerNameDialog')).toBeVisible();await expect(page.locator('#cnInput')).toBeEnabled();await expect(page.locator('#cnOriginal')).toContainText('Анна Морозова');
  expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php'&&c.action==='get')).toHaveLength(1);await capture(page,browserName,width,'dialog');
  await page.locator('#cnInput').fill('Анна — семейная поездка');await page.locator('#cnSave').click();await expect.poll(()=>s.names().working_name).toBe('Анна — семейная поездка');
  await page.locator('#cnClose').click();await expect(page.locator('#customerNameCardOpen')).toBeFocused();await expect(page.locator('#conversationTitle')).toHaveText('Анна — семейная поездка');await expect(page.locator('#leadTaskTitle')).toHaveValue('Черновик следующего действия');await expect(page.locator('#leadTaskDue')).toHaveValue('2026-10-15T10:00');await expect(page.locator('#leadCard')).toContainText('Анна — семейная поездка');await expect(page.locator('#leadCard')).toContainText('Анна Морозова');await capture(page,browserName,width,'renamed-card');
  if(width<=1100)await page.locator('#mobileLeadClose').click();await expect(page.locator('#replyText')).toHaveValue('Черновик для Анны');await expect(page.locator('#mediaSelection')).toHaveText(file);if(width<=430){const title=await page.locator('#conversationTitle').boundingBox(),edit=await page.locator('#customerNameOpen').boundingBox();expect(Math.abs((title.y+title.height/2)-(edit.y+edit.height/2))).toBeLessThanOrEqual(8)}await capture(page,browserName,width,'renamed');await page.setViewportSize({width,height:420});await page.locator('#conversationSearchOpen').click();await page.locator('#conversationSearchInput').fill('у моря');await page.locator('#replyText').focus();await bounded(page);expect(await page.locator('#messages').evaluate(el=>el.clientHeight)).toBeGreaterThanOrEqual(40);const send=await page.locator('#sendReply').boundingBox();expect(send.y+send.height).toBeLessThanOrEqual(421);await capture(page,browserName,width,'renamed-search-short');await page.locator('#conversationSearchClose').click();await page.setViewportSize({width,height:900});
  if(width<=1100)await page.locator('#mobileBack').click();await expect(page.locator('#inboxList [data-conversation-id="11"]')).toContainText('Анна — семейная поездка');
  expect(s.calls.filter(c=>c.path==='/manager/pipeline-api.php'&&c.action==='list')).toHaveLength(2);expect(s.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail')).toHaveLength(1);
  // Search checks the current bounded server list and both identities, not global
  // historical search completeness. No customer IDs are merged by display text.
  await page.locator('#leadSearch').fill('семейная');await expect(page.locator('#inboxList .leadItem')).toHaveCount(1);await expect(page.locator('#inboxList .leadItem')).toHaveAttribute('data-conversation-id','11');await page.locator('#leadSearch').fill('Морозова');await expect(page.locator('#inboxList .leadItem')).toHaveCount(1);await page.locator('#leadSearch').fill('');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  await page.locator('[data-m2-nav=sales]').click();await expect(page.locator('.kanbanCard[data-id="11"]')).toContainText('Анна — семейная поездка');await capture(page,browserName,width,'renamed-board');await page.locator('[data-m2-nav=dialogues]').click();
  await page.reload();await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);await openLead(page);await expect(page.locator('#leadTaskTitle')).toHaveValue('Черновик следующего действия');await expect(page.locator('#conversationTitle')).toHaveText('Анна — семейная поездка');await expect(page.locator('#replyText')).toHaveValue('Черновик для Анны');
  if(width<=1100&&await page.evaluate(()=>WorkspaceV2Mobile.getScreen()==='lead'))await page.locator('#mobileLeadClose').click();
  await page.locator('#customerNameOpen').click();await expect(page.locator('#cnHistory')).toContainText('Анна — семейная поездка');await expect(page.locator('#cnHistory')).toContainText('Мария');await page.setViewportSize({width,height:420});await page.locator('#cnInput').fill('Анна — семейная поездка');await expect(page.locator('#cnInput')).toBeFocused();await page.locator('#cnRestore').scrollIntoViewIfNeeded();await expect(page.locator('#cnRestore')).toBeInViewport();await bounded(page);await capture(page,browserName,width,'dialog-short');
  await page.locator('#cnRestore').click();await expect.poll(()=>s.names().working_name).toBe(null);await page.locator('#cnClose').click();await expect(page.locator('#customerNameOpen')).toBeFocused();await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');await expect(page.locator('#replyText')).toHaveValue('Черновик для Анны');
  expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php'&&c.action==='save')).toHaveLength(2);expect(s.calls.filter(c=>c.action==='create_task')).toHaveLength(0);clean(s);
 });
});

test('unknown committed name update recovers receipt without duplicate write',async({page})=>{
 const s=await setup(page,{unknownSave:true});await openLead(page);await page.locator('#replyText').fill('Ответ туристу остаётся черновиком');await page.locator('#customerNameOpen').click();await expect(page.locator('#cnInput')).toBeEnabled();await page.locator('#cnInput').fill('Анна — повторный клиент');await page.locator('#cnSave').click();await expect(page.locator('#cnStatus')).toContainText('подтверд');await expect(page.locator('#cnResolve')).toBeVisible();await expect(page.locator('#cnSave')).toBeDisabled();await page.locator('#cnResolve').click();await expect(page.locator('#conversationTitle')).toHaveText('Анна — повторный клиент');expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php'&&c.action==='save')).toHaveLength(1);expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php'&&c.action==='operation')).toHaveLength(1);expect(s.history).toHaveLength(1);await expect(page.locator('#replyText')).toHaveValue('Ответ туристу остаётся черновиком');clean(s);
});

test('concurrent name conflict preserves draft and requires explicit current version',async({page})=>{
 const s=await setup(page,{conflictSave:true});await openLead(page);await page.locator('#customerNameOpen').click();await expect(page.locator('#cnInput')).toBeEnabled();await page.locator('#cnInput').fill('Мой текст при конфликте');await page.locator('#cnSave').click();await expect(page.locator('#cnStatus')).toContainText('измен');await expect(page.locator('#cnInput')).toHaveValue('Мой текст при конфликте');await expect(page.locator('#cnConflictReload')).toBeVisible();page.once('dialog',d=>d.accept());await page.locator('#cnConflictReload').click();await expect(page.locator('#cnInput')).toHaveValue('Изменено коллегой');await page.locator('#cnInput').fill('Анна — подтверждённое имя');await page.locator('#cnSave').click();await expect(page.locator('#conversationTitle')).toHaveText('Анна — подтверждённое имя');expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php'&&c.action==='save').map(c=>c.version)).toEqual([0,1]);clean(s);
});

for(const role of ['manager','admin'])test('visible nonassigned client '+role+' name permissions are explicit',async({page})=>{
 const s=await setup(page,{role,otherOwner:true});await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');await expect(page.locator('#composer')).toBeHidden();if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()))await page.locator('#mobileLeadBtn').click();await page.locator('#customerNameCardOpen').click();await expect(page.locator('#cnOriginal')).toContainText('Анна Морозова');
 if(role==='manager'){await expect(page.locator('#cnInput')).toBeDisabled();await expect(page.locator('#cnSave')).toBeDisabled();expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php'&&c.action==='save')).toHaveLength(0)}else{await expect(page.locator('#cnInput')).toBeEnabled();await page.locator('#cnInput').fill('Анна — рабочее имя администратора');await page.locator('#cnSave').click();await expect(page.locator('#conversationTitle')).toHaveText('Анна — рабочее имя администратора')}
 clean(s);
});

test('load/error/retry, Escape, retained name draft and identity invalidation',async({page})=>{
 const s=await setup(page,{holdGet:true,nameError:true});await openLead(page);await page.locator('#customerNameOpen').click();await expect(page.locator('#cnInput')).toBeDisabled();await expect(page.locator('#cnLoading')).toContainText('Загружа');s.releaseList();await expect(page.locator('#cnRetry')).toBeVisible();await expect(page.locator('#cnLoadError')).toContainText('Не удалось');s.setHoldGet(false);s.setNameError(false);await page.locator('#cnRetry').click();await expect(page.locator('#cnInput')).toBeEnabled();await page.locator('#cnInput').fill('Несохранённое личное имя');await page.keyboard.press('Escape');await expect(page.locator('#customerNameOpen')).toBeFocused();await page.locator('#customerNameOpen').click();await expect(page.locator('#cnInput')).toHaveValue('Несохранённое личное имя');await page.locator('#cnClose').click();
 s.setHoldGet(true);await page.locator('#customerNameOpen').click();await page.evaluate(()=>{WorkspaceV2.S.authGeneration++;WorkspaceV2CustomerName.resetSession();WorkspaceV2Conversation.resetForIdentityChange();WorkspaceV2.S.manager={id:8,role:'manager',display_name:'Ольга',is_working:true};WorkspaceV2Conversation.activateReplySession(8)});s.releaseList();await expect(page.locator('#customerNameDialog')).toHaveCount(0);s.setHoldGet(false);await page.evaluate(()=>WorkspaceV2Conversation.open(11));await page.locator('#customerNameCardOpen').click();await expect(page.locator('#cnInput')).not.toHaveValue('Несохранённое личное имя');clean(s);
});

test('dialog keyboard stays inside modal and captured context cannot rename another client',async({page})=>{
 const s=await setup(page);await openLead(page);await page.locator('#customerNameOpen').click();await expect(page.locator('#cnInput')).toBeEnabled();await page.locator('#cnInput').fill('Анна — текущий черновик');await page.locator('#cnSave').focus();await page.keyboard.press('Control+Alt+j');await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');await page.evaluate(()=>{WorkspaceV2.S.current=12});await page.locator('#cnSave').click();expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php'&&c.action==='save')).toHaveLength(0);clean(s);
});

test('fresh permission refusal keeps name text and does not change customer projection',async({page})=>{
 const s=await setup(page);await openLead(page);await page.locator('#replyText').fill('Ответ без отправки');await page.locator('#customerNameOpen').click();await expect(page.locator('#cnInput')).toBeEnabled();await page.locator('#cnInput').fill('Имя после потери назначения');s.setDenySave(true);await page.locator('#cnSave').click();await expect(page.locator('#cnStatus')).toContainText(/ответственный|прав/);await expect(page.locator('#cnInput')).toHaveValue('Имя после потери назначения');await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');await expect(page.locator('#replyText')).toHaveValue('Ответ без отправки');expect(s.history).toHaveLength(0);clean(s);
});

test('history loads a bounded page then older changes explicitly',async({page})=>{
 const s=await setup(page,{historySize:25});await openLead(page);await page.locator('#customerNameOpen').click();await expect(page.locator('#cnHistory')).toContainText('Историческое имя 25');await expect(page.locator('#cnHistory .cnHistoryRow')).toHaveCount(20);await expect(page.locator('#cnHistory .cnHistoryChange>strong').filter({hasText:/^Историческое имя 1$/})).toHaveCount(0);await page.locator('#cnHistoryDetails>summary').click();await expect(page.locator('#cnMoreHistory')).toBeVisible();await page.locator('#cnMoreHistory').click();await expect(page.locator('#cnHistory .cnHistoryRow')).toHaveCount(25);await expect(page.locator('#cnHistory .cnHistoryChange>strong').filter({hasText:/^Историческое имя 1$/})).toHaveCount(1);await expect(page.locator('#cnMoreHistory')).not.toBeVisible();expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php'&&c.action==='get').map(c=>c.before_version||0)).toEqual([0,6]);expect(s.calls.filter(c=>c.path==='/manager/pipeline-api.php'&&c.action==='list')).toHaveLength(1);clean(s);
});

test('working name is inert display text and quick reply uses original messenger name',async({page})=>{
 const s=await setup(page);await openLead(page);await page.locator('#customerNameOpen').click();await expect(page.locator('#cnInput')).toBeEnabled();const label='Анна " onclick="alert(1) <семья>';
 await page.locator('#cnInput').fill(label);await page.locator('#cnSave').click();await expect(page.locator('#conversationTitle')).toHaveText(label);await page.locator('#cnClose').click();expect(await page.locator('#conversationTitle').getAttribute('onclick')).toBeNull();await expect(page.locator('#conversationTitle img')).toHaveCount(0);await page.locator('#conversationTitle').click();await page.locator('#quickRepliesOpen').click();await page.locator('[data-qr-select="1"]').click();await expect(page.locator('#qrPreview')).toHaveValue('Здравствуйте, Анна Морозова!');await page.locator('#qrClose').click();await page.locator('#customerNameOpen').click();await expect(page.locator('#cnInput')).toHaveValue(label);expect(await page.locator('#cnInput').getAttribute('onclick')).toBeNull();clean(s);
});

test('historical receipt confirms command while fresh snapshot paints newer colleague name',async({page})=>{
 const s=await setup(page,{unknownSave:true});await openLead(page);await page.locator('#customerNameOpen').click();await expect(page.locator('#cnInput')).toBeEnabled();await page.locator('#cnInput').fill('Моё первоначальное имя');await page.locator('#cnSave').click();await expect(page.locator('#cnResolve')).toBeVisible();s.advanceName('Более новое имя коллеги');await page.locator('#cnResolve').click();await expect(page.locator('#conversationTitle')).toHaveText('Более новое имя коллеги');await expect(page.locator('#cnEffective')).toHaveText('Более новое имя коллеги');await expect(page.locator('#cnStatus')).toContainText('показан текущий вариант');expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php'&&c.action==='save')).toHaveLength(1);expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php'&&c.action==='get')).toHaveLength(2);clean(s);
});

test('fixture Inbox timing and request counts keep name data lazy',async({page})=>{
 await page.setViewportSize({width:1440,height:900});const samples=[],requests=[];
 for(let i=0;i<10;i++){
  await page.unroute('**/*');const started=Date.now(),s=await setup(page);samples.push(Date.now()-started);requests.push(s.calls.map(c=>c.path+' '+c.action));
  expect(s.calls.filter(c=>c.path==='/manager/customer-name-api.php')).toHaveLength(0);expect(s.calls.filter(c=>c.path==='/manager/pipeline-api.php'&&c.action==='list')).toHaveLength(1);clean(s);
 }
 const sorted=[...samples].sort((a,b)=>a-b);console.log(JSON.stringify({fixture:'2clients/35messages/1440x900',metric:'browser-pageGoto-to-visible-Inbox',n:10,p50:sorted[4],p95:sorted[9],samples,http:requests,production:false}));
});

test('account invalidation removes every injected client identity surface',async({page})=>{
 const s=await setup(page);await openLead(page);await page.locator('#customerNameOpen').click();await expect(page.locator('#cnInput')).toBeEnabled();await page.locator('#cnInput').fill('Анна — приватная рабочая пометка');await page.locator('#cnSave').click();await expect(page.locator('#conversationTitle')).toHaveText('Анна — приватная рабочая пометка');await page.locator('#cnCancel').click();await expect(page.locator('.cnOriginalInline')).toContainText('Анна Морозова');
 await page.evaluate(()=>{WorkspaceV2.S.authGeneration++;WorkspaceV2CustomerName.resetSession();WorkspaceV2Conversation.resetForIdentityChange();WorkspaceV2.S.manager={id:8,role:'manager',display_name:'Ольга',is_working:true};WorkspaceV2Conversation.activateReplySession(8)});
 await expect(page.locator('.cnOriginalInline')).toHaveCount(0);await expect(page.locator('#customerNameOpen')).toHaveCount(0);await expect(page.locator('.cnCardIdentity')).toHaveCount(0);await expect(page.locator('#customerNameDialog')).toHaveCount(0);clean(s);
});
