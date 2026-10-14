const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),sourceRoot=process.env.M2_QA_SOURCE_ROOT||root;
const origin='http://127.0.0.1:4173';
// Render the real protected workspace shell. Every API and media response below
// is synthetic; even an unexpected provider request is blocked, never forwarded.
const shell=execFileSync('php',['manager2/index.php'],{cwd:sourceRoot,encoding:'utf8'});
const photo='<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160"><rect width="160" height="160" fill="#9cddd5"/><path d="M0 110L55 75 110 97 160 60V160H0" fill="#52755a"/><circle cx="118" cy="35" r="18" fill="#f5dfa0"/></svg>';
function post(id=11,entryId=101,overrides={}){
 return {entry_id:entryId,title:id===11?'Турция — семейный отдых в октябре':'Египет — отдых у моря',channel_name:'AnyTour Москва',platform:'max',post_url:'https://max.ru/c/-80095447388179/AaE53iyDMmw',entered_at:'2026-10-14 09:20:00',version_number:2,media_count:1,media_url:`/manager/post-context-media.php?conversation_id=${id}&entry_id=${entryId}&attachment=0`,text:'Турция, Анталья. Вылет из Москвы 24 октября, 7 ночей.\nОтель 5★, всё включено. 2 взрослых.\nЦена из публикации: 150 000 ₽ за всех.\n'+Array.from({length:12},(_,i)=>`Условие ${i+1}: уточнить доступность отеля и цену на новые даты.`).join('\n'),...overrides};
}
async function setup(page,{holdPostIds=[],postStatus=200,postFailure=false,photoFailure=false,managerRole='manager'}={}){
 const calls=[],unexpected=[],errors=[],held=[],holdIds=new Set(holdPostIds);let detailStatus=200;
 const manager={id:7,role:managerRole,display_name:'Мария',is_working:true};
 const contexts=new Map([[11,{current:post(),history:[post(11,100,{title:'Предыдущий пост — Кемер',text:'Предыдущие условия: 130 000 ₽ за двоих.',version_number:1})]}],[12,{current:post(12,202,{text:'Египет, 8 ночей. Цена из публикации: 180 000 ₽ за всех.'}),history:[]}]]);
 const revisions=new Map([[11,0],[12,0]]);
 const conv=id=>({id,manager_id:7,manager_name:'Мария',status:'manager',channel:'max',source_name:'AnyTour Москва',display_name:id===11?'Анна Морозова':'Алексей Иванов',last_message_at:`2026-10-14 09:20:0${revisions.get(id)||0}`});
 const messages=id=>Array.from({length:35+(revisions.get(id)||0)},(_,i)=>({id:i+1,sender_type:i%3===0?'manager':'customer',manager_id:i%3===0?7:null,text:i>=34?'А сколько будет на других датах, на двоих с ребёнком?':'Сообщение '+(i+1)+'. Обсуждаем семейный отдых, отели и даты.',created_at:'2026-10-14 09:20:00'}));
 await page.addInitScript(()=>{
  sessionStorage.clear();
  Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{register:async()=>({scope:location.origin+'/manager/',active:{postMessage(){}},pushManager:{getSubscription:async()=>null}}),addEventListener(){}}});
  function FakeNotification(){}FakeNotification.permission='denied';Object.defineProperty(window,'Notification',{configurable:true,value:FakeNotification});
 });
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url()),method=req.method();
  const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
  const block=()=>{unexpected.push(method+' '+url.href);return route.abort()};
  if(url.origin!==origin)return block();
  if(method==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
  if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
  if(method==='GET'&&url.pathname==='/manager/sw.js')return route.fulfill({contentType:'application/javascript',body:''});
  if(method==='GET'&&url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
  if(method==='GET'&&/^\/manager2?\/assets\/[\w.-]+$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(sourceRoot,url.pathname.slice(1)))});
  if(method==='GET'&&url.pathname==='/manager/post-context-media.php'){
   calls.push({path:url.pathname,query:Object.fromEntries(url.searchParams)});
   if(!['11','12'].includes(url.searchParams.get('conversation_id'))||!['100','101','102','202'].includes(url.searchParams.get('entry_id'))||url.searchParams.get('attachment')!=='0')return block();
   return route.fulfill({status:photoFailure?404:200,contentType:photoFailure?'text/plain':'image/svg+xml',body:photoFailure?'unavailable':photo});
  }
  if(!url.pathname.startsWith('/manager/')||method!=='POST')return block();
  let d;try{d=req.postDataJSON()||{}}catch{return block()}
  calls.push({path:url.pathname,...d});
  if(d.action!=='me'&&d.csrf!=='fixture-csrf')throw new Error('Protected requests must retain canonical CSRF');
  if(url.pathname==='/manager/api.php'){
   if(d.action==='me')return reply({ok:true,manager,csrf:'fixture-csrf',projects:[]});
   if(d.action==='detail')return reply(detailStatus===200?{ok:true,conversation:conv(d.conversation_id),messages:messages(d.conversation_id),delivery_failure:null}:{ok:false,error:'not_found'},detailStatus);
   if(d.action==='counts')return reply({ok:true,counts:{waiting:{count:2},mine:{count:2}}});
   if(d.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
  }
  if(url.pathname==='/manager/pipeline-api.php'){
   if(d.action==='catalog')return reply({ok:true,stages:[],tags:[],outcomes:{open:'В работе'},close_reasons:{}});
   if(d.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager]}});
   if(d.action==='list')return reply({ok:true,conversations:[11,12].map(id=>({...conv(id),project_label:'AnyTour',origin_label:'MAX · канал',can_edit_pipeline:true,last_text:'А сколько будет на других датах?',unread_count:1,last_sender_type:'customer',lead_outcome:'open'}))});
   if(d.action==='detail')return reply({ok:true,can_edit_pipeline:true,pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},tasks:[],trip:{city:'Москва',country:'Турция',adults:2},contact:{},source:{},handoff:{}});
  }
  if(url.pathname==='/manager/quick-replies-api.php'&&d.action==='toolbar')return reply({ok:true,replies:[{id:901,title:'Уточнить даты',body:'На какие даты рассчитать тур?',scope:'personal',category:'Уточнение',version:1,is_favorite:true,can_edit:true}],version:'1'.padStart(64,'0'),max_replies:100});
  if(url.pathname==='/manager/post-context-api.php'){
   if(d.action!=='detail'||![11,12].includes(d.conversation_id))return block();
   // Capture before waiting, so an old deferred response really is stale.
   const body={ok:true,conversation_id:d.conversation_id,context:structuredClone(contexts.get(d.conversation_id))},status=postStatus,failure=postFailure;
   if(holdIds.has(d.conversation_id))await new Promise(resolve=>held.push({id:d.conversation_id,resolve}));
   if(failure)return route.abort('failed');
   return reply(status===200?body:{ok:false,error:status===401?'unauthorized':'post_context_unavailable'},status);
  }
  if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
  if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
  return block();
 });
 await page.goto(origin+'/manager2/index.php?qa=1');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
 return {calls,unexpected,errors,contexts,holdIds,setStatus:value=>{postStatus=value},setDetailStatus:value=>{detailStatus=value},setFailure:value=>{postFailure=value},revise:id=>revisions.set(id,(revisions.get(id)||0)+1),release:id=>{const i=held.findIndex(x=>x.id===id);if(i>=0)held.splice(i,1)[0].resolve()}};
}
async function openLead(page,id=11){if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox'))await page.locator('#mobileBack').click();await page.locator(`#inboxList [data-conversation-id="${id}"]`).click();await expect(page.locator('#conversationTitle')).toHaveText(id===11?'Анна Морозова':'Алексей Иванов');await expect(page.locator('#composer')).toBeVisible()}
async function refresh(page){await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible())}
async function bounded(page){const sizes=await page.evaluate(()=>({w:document.documentElement.scrollWidth,h:document.documentElement.scrollHeight,vw:innerWidth,vh:innerHeight}));expect(sizes.w).toBeLessThanOrEqual(sizes.vw+1);expect(sizes.h).toBeLessThanOrEqual(sizes.vh+1);await expect(page.locator('#sendReply')).toBeInViewport();expect((await page.locator('#messages').boundingBox()).height).toBeGreaterThan(35);const host=page.locator('#conversationPostContext');if(await host.isVisible())expect(await host.evaluate(el=>el.scrollWidth-el.clientWidth),'Publication links must remain inside the source panel').toBeLessThanOrEqual(1)}
async function capture(page,browser,width,state){const dir=process.env.M2_ARTIFACT_ROOT||path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});await page.screenshot({animations:'disabled',fullPage:state.startsWith('channel-'),path:path.join(dir,`post-context-${browser}-${width}-${state}.png`)})}
function clean(s){expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(c=>c.action==='send')).toEqual([])}

for(const width of [390,430,768,1440])test.describe('Source publication at '+width,()=>{
 test.use({viewport:{width,height:900}});
 test('current publication, protected photo and history stay usable without displacing the reply',async({page,browserName})=>{
  const s=await setup(page);await openLead(page);const host=page.locator('#conversationPostContext');await expect(host).toHaveAttribute('data-state','ready');
  await expect(host.locator('.postContextCard .postContextTitle')).toHaveText('Турция — семейный отдых в октябре');await expect(host.locator('.postContextMeta').first()).toContainText('12:20 МСК');
  await expect(host.locator('.postContextThumb').first()).toBeVisible();await expect.poll(()=>host.locator('.postContextThumb').first().evaluate(el=>el.complete&&el.naturalWidth>0)).toBe(true);
  await expect(host.locator('.postContextOriginal').first()).toHaveAttribute('href','https://max.ru/c/-80095447388179/AaE53iyDMmw');
  await page.locator('#replyText').fill('Уточню цену на ваши даты. Черновик сохраняется.');await page.locator('#replyFile').setInputFiles({name:'synthetic.txt',mimeType:'text/plain',buffer:Buffer.from('Isolated attachment, never sent')});const file=await page.locator('#mediaSelection').textContent();
  await capture(page,browserName,width,'compact');await host.locator('.postContextCard summary').click();await expect(host.locator('.postContextCard .postContextText')).toContainText('Цена из публикации: 150 000 ₽ за всех.');
  await host.locator('.postContextHistory>summary').click();await expect(host.locator('.postContextPrevious .postContextTitle')).toHaveText('Предыдущий пост — Кемер');await bounded(page);await capture(page,browserName,width,'history');
  const disclosure=host.locator('.postContextCard details');await host.locator('.postContextCard .postContextText').evaluate(el=>el.scrollTop=80);const oldScroll=await host.locator('.postContextCard .postContextText').evaluate(el=>el.scrollTop);
  s.revise(11);await refresh(page);await expect(host).toHaveAttribute('data-state','ready');await expect(disclosure).toHaveAttribute('open');await expect.poll(()=>host.locator('.postContextCard .postContextText').evaluate(el=>el.scrollTop)).toBe(oldScroll);
  await expect(page.locator('#replyText')).toHaveValue('Уточню цену на ваши даты. Черновик сохраняется.');await expect(page.locator('#mediaSelection')).toHaveText(file);
  await page.setViewportSize({width,height:420});await capture(page,browserName,width,'short');await bounded(page);clean(s);
 });
 test('a new question from another post replaces the current conditions and preserves the unsent answer',async({page,browserName})=>{
  const s=await setup(page);await openLead(page);const host=page.locator('#conversationPostContext');await expect(host).toHaveAttribute('data-state','ready');await page.locator('#replyText').fill('Черновик для Анны');
  const previous=s.contexts.get(11).current;s.contexts.set(11,{current:post(11,102,{title:'Турция — другой отель и новые даты',text:'Условия нового поста: 210 000 ₽ за всех.',version_number:1}),history:[previous]});s.revise(11);await refresh(page);
  await expect(host.locator('.postContextCard .postContextTitle')).toHaveText('Турция — другой отель и новые даты');await host.locator('.postContextCard summary').click();await expect(host.locator('.postContextCard .postContextText')).toHaveText('Условия нового поста: 210 000 ₽ за всех.');await expect(host.locator('.postContextCard')).not.toContainText('150 000');await expect(page.locator('#replyText')).toHaveValue('Черновик для Анны');
  await host.locator('.postContextHistory>summary').click();await expect(host.locator('.postContextPrevious .postContextTitle')).toHaveText(previous.title);await bounded(page);await capture(page,browserName,width,'new-question');clean(s);
 });
});

test('slow optional context and network failure do not hold the transcript or composer',async({page})=>{
 await page.setViewportSize({width:390,height:900});const s=await setup(page,{holdPostIds:[11],postFailure:true});await openLead(page);await expect(page.locator('#conversationPostContext')).toHaveAttribute('data-state','loading');await expect(page.locator('#messages')).toContainText('А сколько будет на других датах');await expect(page.locator('#replyText')).toBeEnabled();await page.locator('#replyText').fill('Ответ доступен без публикации');await expect(page.locator('#sendReply')).toBeEnabled();s.release(11);await expect(page.locator('#conversationPostContext')).toHaveAttribute('data-state','error');await expect(page.locator('#replyText')).toHaveValue('Ответ доступен без публикации');await bounded(page);clean(s);
});

test('a multiline answer and attachment remain reachable with a publication at keyboard height',async({page,browserName})=>{
 await page.setViewportSize({width:390,height:900});const s=await setup(page);await openLead(page);await expect(page.locator('#conversationPostContext')).toHaveAttribute('data-state','ready');const text=Array.from({length:8},(_,i)=>'Строка '+(i+1)+' — уточним даты, туристов и отель.').join('\n');await page.locator('#replyText').fill(text);await page.locator('#replyFile').setInputFiles({name:'synthetic.txt',mimeType:'text/plain',buffer:Buffer.from('Isolated attachment')});await page.setViewportSize({width:390,height:420});await capture(page,browserName,390,'multiline-short');await bounded(page);await expect(page.locator('#replyText')).toHaveValue(text);clean(s);
});

test('a delayed first client cannot overwrite the second client publication',async({page})=>{
 const s=await setup(page,{holdPostIds:[11]});await openLead(page);await expect(page.locator('#conversationPostContext')).toHaveAttribute('data-state','loading');await openLead(page,12);await expect(page.locator('.postContextCard .postContextTitle')).toHaveText('Египет — отдых у моря');s.release(11);await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await expect(page.locator('.postContextCard .postContextTitle')).toHaveText('Египет — отдых у моря');await expect(page.locator('#conversationPostContext')).not.toContainText('Турция — семейный');clean(s);
});

test('authentication loss removes visible context immediately and rejects a delayed private response',async({page})=>{
 const s=await setup(page);await openLead(page);await expect(page.locator('#conversationPostContext')).toHaveAttribute('data-state','ready');s.holdIds.add(11);s.revise(11);await refresh(page);await expect(page.locator('#conversationPostContext')).toHaveAttribute('data-state','loading');await page.evaluate(()=>WorkspaceV2.showAuthRecovery());await expect(page.locator('#managerAuthRecovery')).toBeVisible();await expect(page.locator('#conversationPostContext')).not.toBeVisible();s.release(11);await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await expect(page.locator('#conversationPostContext')).not.toContainText('Турция — семейный');await expect(page.locator('#composer')).not.toBeVisible();clean(s);
});

test('current optional 401 uses canonical sign-in recovery rather than preserving a private card',async({page})=>{
 const s=await setup(page);await openLead(page);await expect(page.locator('#conversationPostContext')).toHaveAttribute('data-state','ready');s.setStatus(401);s.revise(11);await refresh(page);await expect(page.locator('#managerAuthRecovery')).toBeVisible();await expect(page.locator('#conversationPostContext')).not.toBeVisible();await expect(page.locator('#conversationPostContext')).not.toContainText('150 000');clean(s);
});

test('fresh loss of conversation access removes its previous publication',async({page})=>{
 const s=await setup(page);await openLead(page);await expect(page.locator('#conversationPostContext')).toHaveAttribute('data-state','ready');s.setDetailStatus(404);await refresh(page);await expect(page.locator('#conversationRefreshStatus')).toContainText('Доступ к диалогу изменился');await expect(page.locator('#conversationPostContext')).not.toBeVisible();await expect(page.locator('#conversationPostContext')).not.toContainText('150 000');clean(s);
});

for(const kind of ['legacy','unavailable','forbidden','photo-failure'])test('publication fallback is truthful: '+kind,async({page,browserName})=>{
 await page.setViewportSize({width:390,height:900});const s=await setup(page,{photoFailure:kind==='photo-failure',postStatus:kind==='forbidden'?404:200});
 if(kind==='legacy')s.contexts.set(11,{current:null,history:[]});
 if(kind==='unavailable')s.contexts.set(11,{current:{unavailable:true},history:[post(11,100,{title:'Предыдущий пост — Кемер'})]});
 await openLead(page);const host=page.locator('#conversationPostContext');
 if(kind==='legacy'){await expect(host).toHaveAttribute('data-state','empty');await expect(host).toContainText('без ссылки на публикацию');await expect(host.locator('.postContextCard')).toHaveCount(0)}
 if(kind==='unavailable'){await expect(host).toContainText('Исходный пост этого обращения недоступен');await expect(host.locator('.postContextCard')).toHaveCount(0);await host.locator('.postContextHistory>summary').click();await expect(host.locator('.postContextPrevious .postContextTitle')).toHaveText('Предыдущий пост — Кемер')}
 if(kind==='forbidden'){await expect(host).toHaveAttribute('data-state','error');await expect(host).toContainText('Пост недоступен для этого диалога');await expect(host.locator('.postContextCard')).toHaveCount(0)}
 if(kind==='photo-failure'){await expect(host.locator('.postContextCard')).toContainText('Фото недоступно');await expect(host.locator('.postContextCard .postContextThumb')).toHaveCount(0);await expect(host.locator('.postContextOriginal').first()).toBeVisible()}
 await expect(page.locator('#replyText')).toBeEnabled();await bounded(page);await capture(page,browserName,390,kind);clean(s);
});

async function setupChannels(page,{unknownSave=false,saveDenied=false}={}){
 const adminShell=execFileSync('php',[path.join(__dirname,'fixtures/post-channel-shell.php')],{cwd:sourceRoot,encoding:'utf8'});
 expect(adminShell).toContain('<!doctype html>');
 const calls=[],unexpected=[],errors=[];
 const channels=[{id:1,project_key:'post-fixture',platform:'max',channel_id:'-80095447388179',display_name:'AnyTour — синтетический тест кнопок',source_key:'max_anytour_msk1',button_text:'Рассчитать на мои даты',is_enabled:true,config_version:2,publication_count:3,attention_count:1,status:'attention'}];
 const catalog=()=>({ok:true,projects:[{project_key:'post-fixture',display_name:'AnyTour',bots:{max:{bot_url:'https://max.ru/id9704048781_1_bot'},telegram:{bot_url:'https://t.me/Any_tour_bot'}}}],sources:[{id:1,project_key:'post-fixture',platform:'max',source_key:'max_anytour_msk1',display_name:'Москва из MAX',handling_mode:'manager'},{id:2,project_key:'post-fixture',platform:'telegram',source_key:'tg_anytour_msk1',display_name:'Москва из Telegram',handling_mode:'manager'}],channels});
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url());const block=()=>{unexpected.push(req.method()+' '+url.href);return route.abort()};
  if(url.origin!==origin)return block();
  if(req.method()==='GET'&&url.pathname==='/manager/publications.php')return route.fulfill({contentType:'text/html',body:adminShell});
  if(req.method()==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
  if(req.method()==='GET'&&/^\/manager\/assets\/(?:post-channels\.(?:js|css)|manager-http-client\.js)$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(sourceRoot,url.pathname.slice(1)))});
  if(req.method()!=='POST'||url.pathname!=='/manager/post-channels-api.php')return block();
  const d=req.postDataJSON();calls.push(d);expect(d.csrf).toBe('fixture-csrf');
  const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
  if(d.action==='list')return reply(catalog());
  if(d.action==='save'){
   if(saveDenied)return reply({ok:false,error:'channel_verification_failed',verification:{reason:'channel_edit_permission_required'}},422);
   const channel={...d,id:2,channel_id:d.platform==='max'?'-'+d.channel_id.replace(/^-/,''):d.channel_id,config_version:d.expected_version+1,publication_count:0,attention_count:0,status:d.is_enabled?'waiting':'disabled'};channels.push(channel);
   if(unknownSave)return route.abort('failed');return reply({ok:true,channel});
  }
  return block();
 });
 await page.goto(origin+'/manager/publications.php');await expect(page.locator('.pc-channel')).toHaveCount(1);
 return {calls,unexpected,errors,channels};
}
async function fillChannel(page){await page.locator('#pcName').fill('Новый синтетический канал');await page.locator('#pcChannelId').fill('80090000000001');await page.locator('#pcSource').selectOption('max_anytour_msk1')}
async function adminBounded(page){expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1)}

for(const width of [390,430,768,1440])test('one-time channel configuration is usable at '+width,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await setupChannels(page,{saveDenied:true});await expect(page.locator('.pc-badge')).toHaveText('Требует внимания');await expect(page.locator('#pcEnabled')).not.toBeChecked();await expect(page.locator('#pcButton')).toHaveValue('Рассчитать на мои даты');
 await page.locator('.pc-edit').click();await expect(page.locator('#pcChannelId')).toBeDisabled();await expect(page.locator('#pcSource')).toBeDisabled();await expect(page.locator('#pcName')).toHaveValue('AnyTour — синтетический тест кнопок');
 await page.locator('#pcNew').click();await page.locator('#pcPlatform').selectOption('telegram');await expect(page.locator('#pcSource option')).toHaveCount(2);await expect(page.locator('#pcBot')).toHaveAttribute('href','https://t.me/Any_tour_bot');await page.locator('#pcPlatform').selectOption('max');await fillChannel(page);await page.locator('#pcEnabled').check();await page.locator('#pcSave').click();
 await expect(page.locator('#pcSaveStatus')).toContainText('Предоставьте боту право редактирования постов');await expect(page.locator('#pcName')).toHaveValue('Новый синтетический канал');await expect(page.locator('#pcEnabled')).toBeChecked();await expect(page.locator('#pcSave')).toBeEnabled();expect(s.calls.filter(c=>c.action==='save')).toHaveLength(1);await adminBounded(page);await page.locator('#pcSaveStatus').scrollIntoViewIfNeeded();await capture(page,browserName,width,'channel-config');clean(s);
});

test('unknown channel-save result blocks a duplicate write until explicit readback confirms it',async({page,browserName})=>{
 await page.setViewportSize({width:390,height:900});const s=await setupChannels(page,{unknownSave:true});await fillChannel(page);await page.locator('#pcEnabled').check();await page.locator('#pcSave').click();await expect(page.locator('#pcSaveStatus')).toContainText('Ответ на сохранение не получен');await expect(page.locator('#pcSave')).toBeDisabled();await expect(page.locator('#pcNew')).toBeDisabled();await expect(page.locator('#pcName')).toHaveValue('Новый синтетический канал');expect(s.calls.filter(c=>c.action==='save')).toHaveLength(1);await page.locator('#pcRefresh').click();await expect(page.locator('#pcSaveStatus')).toContainText('Сохранение подтверждено после обновления списка');await expect(page.locator('#pcSave')).toBeEnabled();await expect(page.locator('.pc-channel')).toHaveCount(2);expect(s.calls.filter(c=>c.action==='save')).toHaveLength(1);await adminBounded(page);await capture(page,browserName,390,'channel-readback');clean(s);
});

test('publication settings entry follows administrator access on desktop and mobile',async({page,browserName})=>{
 await page.setViewportSize({width:1440,height:900});const s=await setup(page,{managerRole:'admin'});const link=page.locator('#m2PublicationsLink');await expect(link).toBeVisible();await expect(link).toHaveAttribute('href','/manager/publications.php');const rail=await page.locator('.m2Rail').boundingBox();for(const control of [link,page.locator('#adminLink')]){const box=await control.boundingBox();expect(box.x).toBeGreaterThanOrEqual(rail.x-1);expect(box.x+box.width).toBeLessThanOrEqual(rail.x+rail.width+1)}await capture(page,browserName,1440,'admin-entry');
 await page.setViewportSize({width:390,height:900});await page.locator('#m2NotificationButton').click();await expect(page.locator('#m2Notifications')).toBeVisible();await expect(link).toBeVisible();await expect(link).toBeInViewport();expect((await link.boundingBox()).height).toBeGreaterThanOrEqual(44);expect(await link.evaluate(el=>getComputedStyle(el).color)).toBe(await page.locator('#adminLink').evaluate(el=>getComputedStyle(el).color));await capture(page,browserName,390,'admin-entry');
 // These changes use the canonical role and admin-link state; the optional
 // shortcut must never keep administrator controls after access or auth loss.
 await page.evaluate(()=>{WorkspaceV2.S.manager.role='manager';document.getElementById('adminLink').classList.add('hidden')});await expect(link).not.toBeVisible();
 await page.evaluate(()=>{WorkspaceV2.S.manager.role='admin';document.getElementById('adminLink').classList.remove('hidden')});await expect(link).toBeVisible();await page.evaluate(()=>WorkspaceV2.showAuthRecovery());await expect(link).not.toBeVisible();clean(s);
});
