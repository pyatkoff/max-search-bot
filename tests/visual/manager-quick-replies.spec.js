const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
const sourceRoot=process.env.M2_QA_SOURCE_ROOT||root;
const readSource=relative=>fs.readFileSync(path.join(sourceRoot,relative));
const shell=execFileSync('php',['manager2/index.php'],{cwd:sourceRoot,encoding:'utf8'});
// Actual PHP and source assets. All data and mutations below are isolated fixture
// responses. No production traffic or customer messages are permitted.
async function setup(page,{role='manager',holdList=false,listError=false,holdCard=false,cardDelay=0,unknownCreate=false,conflictUpdate=false,messages=null}={}){
 const calls=[],unexpected=[],errors=[],heldLists=[],heldCards=[],receipts=new Map();
 let manager={id:7,role,display_name:'Мария',is_working:true};
 const replies=[
  {id:1,title:'Личное приветствие',body:'Здравствуйте, {client_name}! Меня зовут {manager_name}.',category:'Приветствие',scope:'personal',version:1,is_favorite:false,can_edit:true},
  {id:2,title:'Общий ответ о подборе',body:'Проверяем отели у моря.',category:'Подбор',scope:'shared',version:1,is_favorite:true,can_edit:role==='admin'},
  {id:3,title:'Уточнить пожелания',body:'Какие пожелания к {unknown}?',category:'Подбор',scope:'personal',version:1,is_favorite:false,can_edit:true}
 ];
 const conv=id=>({id,manager_id:manager.id,manager_name:manager.display_name,status:'manager',channel:'telegram',display_name:id===11?'Анна Морозова':'Алексей Иванов'});
 const msgs=()=>messages||Array.from({length:35},(_,i)=>({id:i+1,sender_type:i%3===0?'manager':'customer',manager_id:i%3===0?manager.id:null,text:i===34?'Посмотрите варианты у моря. Бюджет до 250 тысяч за всех.':'Сообщение '+(i+1)+'. Обсуждаем отели у моря и даты.',created_at:'2026-10-13 12:00:00'}));
 page.on('pageerror',e=>errors.push(e.message));
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
  if(url.pathname==='/manager/api.php'){
   if(d.action==='me')return reply({ok:true,manager,csrf:'fixture-csrf',projects:[]});
   if(d.action==='detail')return reply({ok:true,conversation:conv(d.conversation_id),messages:msgs(),delivery_failure:null});
   if(d.action==='counts')return reply({ok:true,counts:{waiting:{count:2,unread:2},mine:{count:2}}});
   if(d.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
  }
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
   if(d.action==='list'){
    if(holdList)await new Promise(resolve=>heldLists.push(resolve));
    if(listError)return reply({ok:false,error:'snapshot_failed'},500);
    const items=replies.filter(r=>(d.scope!=='personal'||r.scope==='personal')&&(d.scope!=='shared'||r.scope==='shared')&&(!d.favorites_only||r.is_favorite)&&(!d.category||r.category===d.category)&&(!d.search||(r.title+' '+r.body).toLowerCase().includes(d.search.toLowerCase())));
    return reply({ok:true,replies:items,total:items.length,has_more:false,categories:['Приветствие','Подбор'],can_manage_shared:manager.role==='admin'});
   }
   if(d.action==='operation')return reply({ok:true,found:receipts.has(d.operation_id),result:receipts.get(d.operation_id)||null});
   if(['create','update','copy'].includes(d.action)){
    if(d.action==='update'&&conflictUpdate)return reply({ok:false,error:'conflict',current:{...replies.find(r=>r.id===Number(d.id)),version:2,body:'Актуальная версия коллеги'}},409);
    let r=d.action==='create'?null:replies.find(r=>r.id===Number(d.id));if(d.action==='copy'){r={...r,id:100+replies.length,scope:'personal',can_edit:true};replies.push(r);return reply({ok:true,reply:r})}if(r&&d.version!==r.version)return reply({ok:false,error:'conflict',current:r},409);
    if(!r){r={id:100+replies.length,is_favorite:false,version:0};replies.push(r)}Object.assign(r,{title:d.title,body:d.body,category:d.category,scope:d.scope||r.scope,can_edit:true,version:r.version+1});const result={ok:true,reply:r};receipts.set(d.operation_id,result);if(d.action==='create'&&unknownCreate)return route.abort('failed');return reply(result);
   }
   if(d.action==='favorite'){const r=replies.find(r=>r.id===Number(d.id));r.is_favorite=!!d.is_favorite;return reply({ok:true,reply:r})}
   if(d.action==='delete'){const i=replies.findIndex(r=>r.id===Number(d.id));replies.splice(i,1);return reply({ok:true})}
  }
  if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
  if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
  unexpected.push(method+' '+url.pathname+' '+d.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
 });
 await page.goto(origin+'/manager2/index.php?qa=1');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
 return {calls,unexpected,errors,replies,releaseList:()=>heldLists.shift()?.(),releaseCard:()=>heldCards.shift()?.(),switchManager:m=>{manager=m},setListError:v=>{listError=v},setHoldList:v=>{holdList=v}};
}
async function openLead(page,id=11){if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox'))await page.locator('#mobileBack').click();await page.locator('#inboxList [data-conversation-id="'+id+'"]').click();await expect(page.locator('#composer')).toBeVisible()}
async function capture(page,browser,width,state){const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(dir,`reply-${browser}-${width}-${state}.png`)})}
async function bounded(page){expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1)}
function clean(s){expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(c=>c.action==='send')).toEqual([])}

for(const width of [390,430,768,1440])test.describe('Quick reply full cycle '+width,()=>{
 test.use({viewport:{width,height:900}});
 test('library filtering, CRUD and explicit insertion preserve draft and attachment',async({page,browserName})=>{
  const s=await setup(page);await openLead(page);await page.locator('#replyText').fill('Существующий черновик ответа');await page.locator('#conversationTitle').click();await capture(page,browserName,width,'after-shell');
  await page.locator('#replyFile').setInputFiles({name:'synthetic.txt',mimeType:'text/plain',buffer:Buffer.from('Isolated file')});
  const attachment=await page.locator('#mediaSelection').textContent();
  await page.locator('#quickRepliesOpen').click();await expect(page.locator('#quickRepliesDialog')).toBeVisible();
  await expect(page.locator('[data-qr-select]')).toHaveCount(3);await bounded(page);await capture(page,browserName,width,'library');
  await page.locator('[data-qr-scope="shared"]').click();await expect(page.locator('[data-qr-select]')).toHaveCount(1);
  await page.locator('[data-qr-select="2"]').click();await expect(page.locator('#qrEdit')).toHaveCount(0);await expect(page.locator('#qrDelete')).toHaveCount(0);
  await page.locator('[data-qr-scope="favorites"]').click();await expect(page.locator('[data-qr-select]')).toHaveCount(1);
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
  await capture(page,browserName,width,'after');await page.setViewportSize({width,height:420});await bounded(page);expect((await page.locator('#sendReply').boundingBox()).y+(await page.locator('#sendReply').boundingBox()).height).toBeLessThanOrEqual(421);await capture(page,browserName,width,'after-short');await page.locator('#conversationTitle').click();await page.locator('#quickRepliesOpen').click();await expect(page.locator('#quickRepliesDialog')).toBeVisible();await page.locator('[data-qr-select=\"1\"]').click();await page.locator('#qrInsert').scrollIntoViewIfNeeded();await expect(page.locator('#qrInsert')).toBeInViewport();await capture(page,browserName,width,'library-short');await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Черновик на коротком экране');await page.locator('#qrBody').fill('Текст на коротком экране');await page.locator('#qrSave').scrollIntoViewIfNeeded();await expect(page.locator('#qrSave')).toBeInViewport();await capture(page,browserName,width,'editor-short');await page.keyboard.press('Escape');await expect(page.locator('#quickRepliesOpen')).toBeFocused();await page.locator('#quickRepliesOpen').click();await expect(page.locator('#qrTitle')).toHaveValue('Черновик на коротком экране');await page.locator('#qrCancelEdit').click();await page.locator('#qrClose').click();
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
  await page.locator('#conversationSearchOpen').click();await expect(page.locator('#conversationSearchInput')).toBeFocused();await page.locator('#conversationSearchInput').fill('у моря');await expect(page.locator('#conversationSearchCount')).toHaveText('1 из 35');await expect(page.locator('#conversationSearchScope')).toContainText('Только загруженные');
  const original=await page.locator('#messages .msgBody').allTextContents();await page.locator('#conversationSearchInput').press('Enter');await expect(page.locator('#conversationSearchCount')).toHaveText('2 из 35');await page.locator('#conversationSearchInput').press('Shift+Enter');await expect(page.locator('#conversationSearchCount')).toHaveText('1 из 35');
  const position=await page.locator('#messages').evaluate(el=>el.scrollTop);s.releaseCard();await expect(page.locator('#leadCard')).not.toContainText('Загружаем');expect(await page.locator('#messages .msgBody').allTextContents()).toEqual(original);expect(Math.abs(await page.locator('#messages').evaluate(el=>el.scrollTop)-position)).toBeLessThanOrEqual(3);
  await capture(page,browserName,width,'search');await page.setViewportSize({width,height:420});await bounded(page);expect(await page.locator('#messages').evaluate(el=>el.clientHeight)).toBeGreaterThanOrEqual(40);const composer=await page.locator('#sendReply').boundingBox();expect(composer.y+composer.height).toBeLessThanOrEqual(421);await capture(page,browserName,width,'search-short');await page.setViewportSize({width,height:900});
  await page.locator('#conversationSearchInput').fill('не существующий текст');await expect(page.locator('#conversationSearchCount')).toHaveText('Совпадений нет');await expect(page.locator('#conversationSearchNext')).toBeDisabled();await page.locator('#conversationSearchInput').press('Escape');await expect(page.locator('#conversationSearchOpen')).toBeFocused();await expect(page.locator('.conversationSearchHit')).toHaveCount(0);
  await page.locator('#nextWaitingConversation').click();await expect(page.locator('#conversationTitle')).toHaveText('Алексей Иванов');await expect(page.locator('#replyText')).toHaveValue('');await expect(page.locator('#mediaSelection')).toBeHidden();await page.locator('#replyText').fill('Черновик Алексея');
  await page.locator('#nextWaitingConversation').click();await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');await expect(page.locator('#replyText')).toHaveValue('Черновик Анны');await expect(page.locator('#mediaSelection')).toHaveText(attachment);
  expect(s.calls.filter(c=>c.action==='detail'&&c.path==='/manager/api.php')).toHaveLength(3);expect(s.calls.filter(c=>c.action==='list'&&c.path==='/manager/pipeline-api.php')).toHaveLength(1);console.log('Fixture transcript visible while card held: '+width+'px '+elapsed+'ms; no production SLA claim.');clean(s);
 });
});

test('quick replies failure/loading/empty states, persistent editor and account isolation',async({page})=>{
 const s=await setup(page,{holdList:true});await openLead(page);await page.locator('#quickRepliesOpen').click();await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','true');await expect(page.locator('#qrList')).toContainText('Загружаем ответы');s.setListError(true);s.releaseList();await expect(page.locator('#qrList')).toContainText('Не удалось загрузить');
 s.setHoldList(false);s.setListError(false);await page.locator('[data-qr-retry]').click();await expect(page.locator('[data-qr-select]')).toHaveCount(3);
 await page.locator('#qrSearch').fill('отсутствующий ответ');await expect(page.locator('#qrList')).toContainText('Подходящих ответов нет');await page.locator('#qrSearch').fill('');await expect(page.locator('[data-qr-select]')).toHaveCount(3);
 await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Мой незавершённый ответ');await page.locator('#qrBody').fill('Личный черновик шаблона');await page.locator('#qrClose').click();await expect(page.locator('#quickRepliesOpen')).toBeFocused();await page.locator('#quickRepliesOpen').click();await expect(page.locator('#qrTitle')).toHaveValue('Мой незавершённый ответ');await page.locator('#qrClose').click();
 await page.evaluate(()=>{WorkspaceV2.S.authGeneration++;WorkspaceV2QuickReplies.resetSession();WorkspaceV2Conversation.resetForIdentityChange();WorkspaceV2.S.manager={id:8,role:'manager',display_name:'Ольга',is_working:true};WorkspaceV2Conversation.activateReplySession(8)});
 await page.evaluate(()=>WorkspaceV2QuickReplies.open());await expect(page.locator('#qrTitle')).toHaveCount(0);await expect(page.locator('#qrDetail')).not.toContainText('Личный черновик');expect(await page.evaluate(()=>sessionStorage.getItem('workspaceV2.quickReplyDraft.7'))).toContain('Личный черновик шаблона');clean(s);
});

test('shared templates admin CRUD scope and stale insertion cannot target another client',async({page})=>{
 const s=await setup(page,{role:'admin'});await openLead(page);await page.locator('#quickRepliesOpen').click();await page.locator('[data-qr-select="2"]').click();await expect(page.locator('#qrEdit')).toBeVisible();await page.locator('#qrEdit').click();await expect(page.locator('#qrEditScope')).toHaveValue('shared');await expect(page.locator('#qrEditScope')).toBeDisabled();await page.locator('#qrCancelEdit').click();
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
 const s=await setup(page,{unknownCreate:true});await openLead(page);await page.locator('#quickRepliesOpen').click();await page.locator('#qrCreate').click();await page.locator('#qrTitle').fill('Подтверждение сохранения');await page.locator('#qrBody').fill('Текст для проверки сохранения');await page.locator('#qrSave').click();
 await expect(page.locator('#qrPending')).toBeVisible();await expect(page.locator('#qrStatus')).toContainText('Не удалось подтвердить');await expect(page.locator('#qrSave')).toBeDisabled();await page.locator('#qrResolve').click();await expect(page.locator('#qrPending')).toBeHidden();await expect(page.locator('#qrStatus')).toContainText('Ответ создан');expect(s.replies.filter(r=>r.title==='Подтверждение сохранения')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='create')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='operation')).toHaveLength(1);clean(s);
});

test('edit conflict keeps manager text and stale list cannot cross identity',async({page})=>{
 const s=await setup(page,{conflictUpdate:true});await openLead(page);await page.locator('#quickRepliesOpen').click();await page.locator('[data-qr-select="1"]').click();await page.locator('#qrEdit').click();await page.locator('#qrBody').fill('Мой текст при конфликте');await page.locator('#qrSave').click();await expect(page.locator('#qrStatus')).toContainText('изменён в другом окне');await expect(page.locator('#qrBody')).toHaveValue('Мой текст при конфликте');await expect(page.locator('#qrReloadConflict')).toBeVisible();
 await page.locator('#qrCancelEdit').click();await page.locator('#qrClose').click();s.setHoldList(true);await page.locator('#quickRepliesOpen').click();await expect(page.locator('#qrList')).toHaveAttribute('aria-busy','true');await page.evaluate(()=>{WorkspaceV2.S.authGeneration++;WorkspaceV2QuickReplies.resetSession();WorkspaceV2.S.manager={id:8,role:'manager',display_name:'Ольга'}});s.releaseList();await expect(page.locator('#quickRepliesDialog')).toHaveCount(0);s.setHoldList(false);await page.evaluate(()=>WorkspaceV2QuickReplies.open());await expect(page.locator('#qrDetail')).not.toContainText('Мой текст при конфликте');clean(s);
});

test('quoted title/category stay inert text when template editor is reopened',async({page})=>{
 const s=await setup(page);await openLead(page);await page.locator('#quickRepliesOpen').click();await page.locator('#qrCreate').click();const title='Приветствие " onclick="alert(1)',category='Семья "<Trip>';
 await page.locator('#qrTitle').fill(title);await page.locator('#qrEditCategory').fill(category);await page.locator('#qrBody').fill('Здравствуйте!');await page.locator('#qrSave').click();await expect(page.locator('#qrDetail h3')).toHaveText(title);await page.locator('#qrEdit').click();await expect(page.locator('#qrTitle')).toHaveValue(title);await expect(page.locator('#qrEditCategory')).toHaveValue(category);expect(await page.locator('#qrTitle').getAttribute('onclick')).toBeNull();expect(await page.locator('#qrEditCategory').getAttribute('onclick')).toBeNull();clean(s);
});

test('workspace shortcuts respect typing, IME and modal focus',async({page})=>{
 const s=await setup(page);await openLead(page);await page.locator('#conversationSearchOpen').focus();await page.keyboard.press('Control+Alt+r');await expect(page.locator('#replyText')).toBeFocused();await page.locator('#replyText').fill('Нельзя прерывать ввод');await page.keyboard.press('Control+Alt+j');await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');
 await page.locator('#conversationSearchOpen').focus();await page.evaluate(()=>document.getElementById('conversationSearchOpen').dispatchEvent(new KeyboardEvent('keydown',{key:'j',ctrlKey:true,altKey:true,isComposing:true,bubbles:true})));await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');
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
  await page.locator('#conversationSearchOpen').click();await page.locator('#conversationSearchInput').fill('ориентир');
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
