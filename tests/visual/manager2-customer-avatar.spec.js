const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
const sourceRoot=process.env.M2_QA_SOURCE_ROOT||root;
const readSource=relative=>fs.readFileSync(path.join(sourceRoot,relative));
// A synthetic portrait; all requests below are intercepted locally. No real
// provider, customer messages or production business writes are exercised.
const portrait=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAABM0lEQVR4nO2YzQ3CMAxGU8S4nDpIT+zABszAWBy4IIHAP89xovg7VpX9vdhp42y3x73NrFO2Aa8KIFsFkK0CyFYBZOscFPc4rp8P9/2CJ9rwo8RX6+9iMeAW+ute+I5cJIDcGciAAWg9UQwMgM0NwjD9ZxQA8CykvwhVgWwVQLYKIFsAgOdw5j/YVQVaa9aFRM7VWAW0bqipgGwhuSdwpoH3gMQZO5HxI+VLE8/EnVWf0WyF3Av9GFPwbUDuAdV4RZEwAObJ0I/hBUBuFjwYrk1M3e144tgB2BtCczQjAOveE9MCEOHeHFkNEOfeFn/6P7EOIHr5DVkUAH3ca3Mt1kIDSgrQs39UGZepwLASAfTvH3neNSowsgogWwWQrQLIlggg4laZyrtGBUaWFKB/FwkzPgFzBmYhb17hEQAAAABJRU5ErkJggg==','base64');
async function setup(page,{rowCount=2,holdPhoto=false,photoDelay=0,statuses={},disabled=false,channels={}}={}){
 const shell=execFileSync('php',['manager2/index.php'],{cwd:sourceRoot,encoding:'utf8'});
 const calls=[],photos=[],unexpected=[],errors=[],held=[],blobRequests=[],deniedDetails=new Set();
 let manager={id:7,role:'manager',display_name:'Мария',is_working:true},bytes=0,active=0,maximumActive=0;
 const conv=id=>({id,customer_id:100+id,manager_id:manager.id,manager_name:manager.display_name,status:'manager',channel:channels[id]||'telegram',display_name:id===11?'Анна Морозова':id===12?'Алексей Иванов':'Турист '+id,original_display_name:id===11?'Анна Морозова':id===12?'Алексей Иванов':'Турист '+id});
 const rows=()=>Array.from({length:rowCount},(_,i)=>({...conv(i+11),project_label:'AnyTour',origin_label:'Реклама',can_edit_pipeline:true,lead_stage:{id:1,display_name:'В работе'},lead_outcome:'open',last_text:'Ждём варианты у моря',last_sender_type:'customer',awaiting_manager_reply:1,unread_count:i?0:2}));
 await page.addInitScript(()=>{window.avatarObjectUrls={created:[],revoked:[]};const create=URL.createObjectURL.bind(URL),revoke=URL.revokeObjectURL.bind(URL);URL.createObjectURL=blob=>{const url=create(blob);avatarObjectUrls.created.push(url);return url};URL.revokeObjectURL=url=>{avatarObjectUrls.revoked.push(url);revoke(url)}});
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url()),method=req.method();
  // WebKit exposes local object-URL image reads to routing, unlike Chromium.
  // Continue only our own same-origin blobs; external network stays blocked.
  if(url.protocol==='blob:'&&url.origin===origin){blobRequests.push(req.url());return route.continue()}
  const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
  const block=()=>{unexpected.push(method+' '+url.pathname);return route.abort()};
  if(url.origin!==origin)return block();
  if(method==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
  if(method==='GET'&&url.pathname==='/manager/sw.js')return route.fulfill({contentType:'application/javascript',body:''});
  if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
  if(method==='GET'&&/^\/manager2?\/assets\/[\w.-]+$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:disabled&&url.pathname.endsWith('workspace-v2-customer-avatar.js')?'':readSource(url.pathname.slice(1))});
  if(url.pathname==='/manager/customer-avatar.php'){
   expect(method).toBe('GET');expect([...url.searchParams.keys()]).toEqual(['conversation_id']);
   const id=Number(url.searchParams.get('conversation_id')),request={id,account:manager.id,started:Date.now(),finished:null,status:statuses[id]||200};photos.push(request);active++;maximumActive=Math.max(maximumActive,active);
   if(holdPhoto)await new Promise(resolve=>held.push({id,resolve}));if(photoDelay)await new Promise(resolve=>setTimeout(resolve,photoDelay));
   try{if(request.status!==200)return await route.fulfill({status:request.status,contentType:'application/json',body:'{}'});bytes+=portrait.length;return await route.fulfill({contentType:'image/png',body:portrait,headers:{'Cache-Control':'private, no-store','X-Content-Type-Options':'nosniff'}})}catch(e){/* Aborted by identity invalidation is expected. */}finally{request.finished=Date.now();active--}
   return;
  }
  if(!url.pathname.startsWith('/manager/'))return block();
  let d={};if(method==='POST'){try{d=req.postDataJSON()||{}}catch(e){return block()}}calls.push({path:url.pathname,...d});
  if(url.pathname==='/manager/api.php'){
   if(d.action==='me'||d.action==='login')return reply({ok:true,manager,csrf:'fixture-csrf',projects:[]});
   if(d.action==='detail'&&deniedDetails.has(Number(d.conversation_id)))return reply({ok:false,error:'forbidden'},403);
   if(d.action==='detail')return reply({ok:true,conversation:conv(d.conversation_id),messages:Array.from({length:35},(_,i)=>({id:i+1,sender_type:i%3?'customer':'manager',manager_id:i%3?null:manager.id,text:i===34?'Посмотрите варианты у моря. Бюджет до 250 тысяч за всех.':'Обсуждаем отели, даты и пожелания '+(i+1),created_at:'2026-10-14 01:00:00'})),delivery_failure:null});
   if(d.action==='counts')return reply({ok:true,counts:{waiting:{count:rowCount,unread:2},mine:{count:rowCount}}});
   if(d.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
  }
  if(url.pathname==='/manager/pipeline-api.php'){
   if(d.action==='catalog')return reply({ok:true,stages:[{id:1,stage_key:'working',display_name:'В работе',sort_order:1}],tags:[],outcomes:{open:'В работе'},close_reasons:{}});
   if(d.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager]}});
   if(d.action==='list')return reply({ok:true,conversations:rows()});
   if(d.action==='detail')return reply({ok:true,can_edit_pipeline:true,pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},tasks:[],trip:{city:'Москва',country:'Турция',adults:2,budget:{max:250000,currency:'RUB',basis:'total'}},contact:{},source:{},handoff:{}});
  }
  if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
  if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
  unexpected.push(method+' '+url.pathname+' '+d.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
 });
 const navigatedAt=Date.now();await page.goto(origin+'/manager2/index.php?qa=1');await expect(page.locator('#inboxList .leadItem')).toHaveCount(rowCount);
 return {calls,photos,unexpected,errors,blobRequests,navigatedAt,denyDetail(id){deniedDetails.add(Number(id))},get bytes(){return bytes},get maximumActive(){return maximumActive},releaseAll(){holdPhoto=false;held.splice(0).forEach(h=>h.resolve())},release(id){const index=held.findIndex(h=>h.id===id);if(index>=0)held.splice(index,1)[0].resolve()},switchManager(m){manager=m}};
}
async function openLead(page,id=11){if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox'))await page.locator('#mobileBack').click();await page.locator('#inboxList [data-conversation-id="'+id+'"]').click();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('#messages')).toContainText('Посмотрите варианты у моря')}
async function capture(page,browser,width,state){const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(dir,`avatar-${browser}-${width}-${state}.png`)})}
async function bounded(page){expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1)}
function clean(s){expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(c=>c.action==='send')).toEqual([])}
async function imageLoaded(locator){await expect(locator).toHaveCount(1);await expect.poll(()=>locator.evaluate(img=>img.complete&&img.naturalWidth)).toBe(64);expect(await locator.getAttribute('src')).toMatch(/^blob:/)}
async function dimensions(locator){return locator.evaluate(el=>({width:parseFloat(getComputedStyle(el).width),height:parseFloat(getComputedStyle(el).height)}))}

for(const width of [390,430,768,1440])test.describe('Customer avatars '+width,()=>{
 test.use({viewport:{width,height:900}});
 test('background photos preserve row/header/card geometry, drafts and short-height composer',async({page,browserName,browser})=>{
  const before=await browser.newPage({viewport:{width,height:900}}),baseline=await setup(before,{disabled:true});await capture(before,browserName,width,'feature-disabled-inbox');await openLead(before);await before.locator('#replyText').fill('Черновик Анны с выбранным файлом');await before.locator('#replyFile').setInputFiles({name:'Anna.txt',mimeType:'text/plain',buffer:Buffer.from('Fixture attachment')});await capture(before,browserName,width,'feature-disabled-conversation');if(width<=1100)await before.locator('#mobileLeadBtn').click();await capture(before,browserName,width,'feature-disabled-card');clean(baseline);await before.close();
  const s=await setup(page,{holdPhoto:true});const row=page.locator('[data-conversation-id="11"] .leadAvatar');const beforeRow=await dimensions(row);await capture(page,browserName,width,'before-inbox');
  await openLead(page);await page.locator('#replyText').fill('Черновик Анны с выбранным файлом');await page.locator('#replyFile').setInputFiles({name:'Anna.txt',mimeType:'text/plain',buffer:Buffer.from('Fixture attachment')});const file=await page.locator('#mediaSelection').textContent();
  const headerBefore=await dimensions(page.locator('#conversationAvatar'));await capture(page,browserName,width,'before-conversation');
  if(width<=1100)await page.locator('#mobileLeadBtn').click();await expect(page.locator('.customerAvatarCard')).toBeVisible();const cardBefore=await dimensions(page.locator('.customerAvatarCard'));await capture(page,browserName,width,'before-card');
  s.releaseAll();await imageLoaded(page.locator('#conversationAvatar .customerAvatarImage'));await imageLoaded(page.locator('.customerAvatarCard .customerAvatarImage'));await imageLoaded(row.locator('.customerAvatarImage'));
  expect(await dimensions(row)).toEqual(beforeRow);expect(await dimensions(page.locator('#conversationAvatar'))).toEqual(headerBefore);expect(await dimensions(page.locator('.customerAvatarCard'))).toEqual(cardBefore);
  await capture(page,browserName,width,'after-card');if(width<=1100)await page.locator('#mobileLeadClose').click();await expect(page.locator('#replyText')).toHaveValue('Черновик Анны с выбранным файлом');await expect(page.locator('#mediaSelection')).toHaveText(file);await capture(page,browserName,width,'after-conversation');
  await page.setViewportSize({width,height:420});await page.locator('#conversationSearchOpen').click();await page.locator('#conversationSearchInput').fill('у моря');await page.locator('#replyText').focus();await bounded(page);expect(await page.locator('#messages').evaluate(el=>el.clientHeight)).toBeGreaterThanOrEqual(40);const send=await page.locator('#sendReply').boundingBox();expect(send.y+send.height).toBeLessThanOrEqual(421);await capture(page,browserName,width,'after-short');await page.locator('#conversationSearchClose').click();await page.setViewportSize({width,height:900});
  await openLead(page,12);await page.locator('#replyText').fill('Черновик Алексея');await openLead(page,11);await expect(page.locator('#replyText')).toHaveValue('Черновик Анны с выбранным файлом');await expect(page.locator('#mediaSelection')).toHaveText(file);
  const count=s.photos.length;await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await imageLoaded(page.locator('#conversationAvatar .customerAvatarImage'));expect(s.photos.length).toBe(count);expect(s.photos.filter(p=>p.id===11)).toHaveLength(1);expect(s.photos.filter(p=>p.id===12)).toHaveLength(1);expect(s.maximumActive).toBeLessThanOrEqual(2);
  if(width<=1100)await page.locator('#mobileBack').click();await capture(page,browserName,width,'after-inbox');await bounded(page);clean(s);
 });
});

test('delayed photos never hold transcript/composer or duplicate refreshed requests',async({page})=>{
 await page.setViewportSize({width:1440,height:900});const s=await setup(page,{holdPhoto:true});await expect.poll(()=>s.photos.length).toBe(2);await openLead(page);await page.locator('#replyText').fill('Работаю, пока фотография загружается');await expect(page.locator('#conversationAvatar .customerAvatarImage')).toHaveCount(0);for(let i=0;i<3;i++)await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));expect(s.photos).toHaveLength(2);expect(s.calls.filter(c=>c.action==='detail'&&c.path==='/manager/api.php')).toHaveLength(1);s.releaseAll();await imageLoaded(page.locator('#conversationAvatar .customerAvatarImage'));await expect(page.locator('#replyText')).toHaveValue('Работаю, пока фотография загружается');clean(s);
});

test('200 client list fetches viewport photos only, at most two simultaneously, and reuses them after rerender',async({page})=>{
 await page.setViewportSize({width:390,height:900});const s=await setup(page,{rowCount:200,photoDelay:25});await expect.poll(()=>s.bytes).toBeGreaterThan(0);await page.waitForTimeout(400);const initial=s.photos.length;expect(initial).toBeGreaterThan(0);expect(initial).toBeLessThan(20);expect(s.maximumActive).toBeLessThanOrEqual(2);await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await page.waitForTimeout(100);expect(s.photos).toHaveLength(initial);await page.locator('#inboxList').evaluate(el=>el.scrollTop=el.scrollHeight);await expect.poll(()=>s.photos.length).toBeGreaterThan(initial);await page.waitForTimeout(400);expect(s.photos.length).toBeLessThan(40);expect(new Set(s.photos.map(p=>p.id)).size).toBe(s.photos.length);expect(s.calls.filter(c=>c.action==='list')).toHaveLength(2);console.log(JSON.stringify({fixture:'200 synthetic clients/390x900',metric:'lazy-photo-budget',httpInitial:initial,httpAfterBottom:s.photos.length,photoBytes:s.bytes,maxConcurrent:s.maximumActive,production:false}));clean(s);
});

for(const status of [404,403,503])test('ACL '+status+' retains initials without provider URLs, repeated retries or auth leakage',async({page})=>{
 const s=await setup(page,{statuses:{11:status,12:404}});await openLead(page);await expect.poll(()=>s.photos.filter(p=>p.finished).length).toBe(2);await expect(page.locator('#conversationAvatar')).toContainText('АМ');await expect(page.locator('.customerAvatarImage')).toHaveCount(0);await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('#managerAuthRecovery')).toHaveCount(0);await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await page.waitForTimeout(100);expect(s.photos).toHaveLength(2);expect(await page.evaluate(()=>avatarObjectUrls.created.length)).toBe(0);expect(await page.locator('#conversationAvatar').getAttribute('title')).toBeNull();clean(s);
});

test('canonical account recovery revokes loaded images and discards the late prior-account photo',async({page})=>{
 const s=await setup(page,{holdPhoto:true});await expect.poll(()=>s.photos.length).toBe(2);s.release(11);await imageLoaded(page.locator('[data-conversation-id="11"] .customerAvatarImage'));const oldUrl=await page.locator('[data-conversation-id="11"] .customerAvatarImage').getAttribute('src');await openLead(page);await page.locator('#replyText').fill('Приватный черновик Марии');await page.evaluate(()=>WorkspaceV2.showAuthRecovery());await expect(page.locator('.customerAvatarImage')).toHaveCount(0);expect(await page.evaluate(url=>avatarObjectUrls.revoked.includes(url),oldUrl)).toBe(true);
 s.switchManager({id:8,role:'manager',display_name:'Ольга',is_working:true});await page.locator('#managerAuthLogin').fill('fixture-olga');await page.locator('#managerAuthPassword').fill('fixture-password');await page.locator('#managerAuthSubmit').click();await expect(page.locator('#managerAuthRecovery')).toBeHidden();s.releaseAll();await expect.poll(()=>s.photos.filter(p=>p.account===8).length).toBe(2);await imageLoaded(page.locator('[data-conversation-id="12"] .customerAvatarImage'));await openLead(page);await expect(page.locator('#replyText')).toHaveValue('');expect(await page.locator('#conversationAvatar .customerAvatarImage').getAttribute('src')).not.toBe(oldUrl);expect(await page.evaluate(()=>avatarObjectUrls.created.length)).toBe(3);clean(s);
});

test('paired browser fixture timing reports startup/transcript and additional photo bytes separately',async({browser,browserName})=>{
 const results=[];for(const disabled of [true,false]){const samples=[];for(let i=0;i<5;i++){const page=await browser.newPage({viewport:{width:1440,height:900}});const s=await setup(page,{disabled,photoDelay:250}),started=s.navigatedAt;const inbox=Date.now()-started;await openLead(page);const transcript=Date.now()-started;await expect(page.locator('#composer')).toBeVisible();samples.push({inbox,transcript});if(!disabled)await imageLoaded(page.locator('#conversationAvatar .customerAvatarImage'));if(i===0)await capture(page,browserName,1440,disabled?'feature-disabled':'feature-enabled');expect(s.calls.filter(c=>c.action==='list')).toHaveLength(1);expect(s.calls.filter(c=>c.action==='detail'&&c.path==='/manager/api.php')).toHaveLength(1);clean(s);results.push({enabled:!disabled,sample:i+1,...samples[i],httpList:1,httpTranscript:1,httpPhoto:s.photos.length,photoBytes:s.bytes});await page.close()}const percentile=key=>{const values=samples.map(s=>s[key]).sort((a,b)=>a-b);return {p50:values[2],p95:values[4]}};console.log(JSON.stringify({fixture:'2 synthetic clients/35 messages/1440x900/photo endpoint delayed250ms',enabled:!disabled,n:5,metric:'browser pageGoto-to-visible Inbox/transcript; real PHP shell, intercepted APIs',inbox:percentile('inbox'),transcript:percentile('transcript'),production:false}))}console.log(JSON.stringify({fixture:'paired-avatar-http-and-bytes',results,production:false}));
});


test('expired-photo session clears portrait surfaces and requires canonical login',async({page})=>{
 const s=await setup(page,{statuses:{11:401},holdPhoto:true});await expect.poll(()=>s.photos.length).toBe(2);await openLead(page);await page.locator('#replyText').fill('Черновик после истечения сессии');s.release(11);await expect(page.locator('#managerAuthRecovery')).toBeVisible();await expect(page.locator('.customerAvatarImage')).toHaveCount(0);await expect(page.locator('#composer')).toBeHidden();expect(await page.evaluate(()=>WorkspaceV2.S.authExpired)).toBe(true);s.releaseAll();clean(s);
});


test('confirmed transcript access loss clears the cached photo from every surface',async({page})=>{
 await page.setViewportSize({width:1440,height:900});const s=await setup(page);await openLead(page);await imageLoaded(page.locator('#conversationAvatar .customerAvatarImage'));const oldUrl=await page.locator('#conversationAvatar .customerAvatarImage').getAttribute('src');s.denyDetail(11);await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible());await expect(page.locator('[data-customer-avatar="11"] .customerAvatarImage')).toHaveCount(0);await expect(page.locator('#composer')).toBeHidden();expect(await page.evaluate(url=>avatarObjectUrls.revoked.includes(url),oldUrl)).toBe(true);await expect(page.locator('#conversationAvatar')).toContainText('АМ');clean(s);
});

test('unsupported private MAX and website portraits keep initials with zero avatar HTTP',async({page})=>{
 const s=await setup(page,{channels:{11:'max',12:'website'}});await openLead(page);await page.waitForTimeout(100);expect(s.photos).toHaveLength(0);await expect(page.locator('#conversationAvatar')).toContainText('АМ');await expect(page.locator('.customerAvatarCard')).toContainText('АМ');await expect(page.locator('.customerAvatarImage')).toHaveCount(0);clean(s);
});

test('late Telegram photo cannot paint a reused header after navigation to unsupported MAX',async({page})=>{
 await page.setViewportSize({width:1440,height:900});const s=await setup(page,{holdPhoto:true,channels:{12:'max'}});await openLead(page,11);await expect.poll(()=>s.photos.length).toBe(1);await openLead(page,12);s.releaseAll();await expect.poll(()=>s.photos.filter(p=>p.finished).length).toBe(1);await imageLoaded(page.locator('[data-conversation-id="11"] .customerAvatarImage'));await expect(page.locator('#conversationAvatar')).toContainText('АИ');await expect(page.locator('#conversationAvatar .customerAvatarImage')).toHaveCount(0);await expect(page.locator('.customerAvatarCard .customerAvatarImage')).toHaveCount(0);await expect(page.locator('.customerAvatarCard')).toContainText('АИ');clean(s);
});

test('valid PNG object URL decodes through same-origin fixture routing',async({page,browserName})=>{
 const s=await setup(page);await openLead(page);await imageLoaded(page.locator('#conversationAvatar .customerAvatarImage'));console.log(JSON.stringify({metric:'local-portrait-object-url-decode',browser:browserName,blobRouteReads:s.blobRequests.length,pngBytes:portrait.length,naturalWidth:64,production:false}));clean(s);
});
