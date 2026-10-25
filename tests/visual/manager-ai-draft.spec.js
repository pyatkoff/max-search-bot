const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),sourceRoot=process.env.M2_QA_SOURCE_ROOT||root,origin='http://127.0.0.1:4173';
const shell=execFileSync('php',['manager2/index.php'],{cwd:sourceRoot,encoding:'utf8'});
const {startOAuthFixture}=require('./fixtures/oauth-consent');
const proposal=(changes={})=>({ok:true,should_draft:true,draft:'Какие даты поездки вам удобны?',next_action:'clarify',reason:'В запросе не указаны даты вылета.',checks:[],evidence_message_ids:[35],history_incomplete:false,revision:'1'.repeat(64),latest_message_id:35,draft_id:'d'.repeat(32),...changes});
const prepared=(changes={})=>proposal({prepared:true,prepared_at:'2026-10-19T10:30:00Z',source:'chatgpt',draft_id:null,...changes});
// Render the actual PHP shell and canonical assets. Every identity, message,
// proposal and HTTP response in this fixture is synthetic and stays in loopback.
async function setup(page,{readiness=false,hideNotice=false,supervisor=false,owner=false,ownerState='manager'}={}){
  const calls=[],unexpected=[],errors=[],messages=new Map();
  let ai=async data=>data.action==='prepared'?{body:prepared()}:data.action==='generate'?{body:proposal()}:{body:{ok:true,revision:'1'.repeat(64),latest_message_id:35,draft_id:data.draft_id||null}};
  const manager={id:7,role:owner?'admin':'manager',display_name:'Мария',is_working:true,supervision_available:supervisor||owner};
  const conversation=id=>({id,status:owner&&id===11?ownerState:'manager',manager_id:owner&&id===11&&ownerState!=='manager'?null:(supervisor||owner)&&id===11?9:7,manager_name:(supervisor||owner)&&id===11?'Другой менеджер':'Мария',channel:'telegram',display_name:id===11?'Анна Морозова':'Алексей Иванов'});
  for(const id of [11,12])messages.set(id,Array.from({length:35},(_,i)=>({id:i+1,sender_type:i%3?'customer':'manager',manager_id:i%3?null:7,text:'Синтетическое сообщение '+(i+1)+'. Обсуждаем пожелания к поездке.',created_at:'2026-10-17 12:00:00'})));
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url()),method=request.method();
    const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    const block=()=>{unexpected.push(method+' '+url.pathname);return route.abort()};
    if(url.origin!==origin)return block();
    if(method==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
    if(method==='GET'&&/^\/manager2?\/assets\/[\w.-]+$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(sourceRoot,url.pathname.slice(1)))});
    if(method==='GET'&&url.pathname==='/manager/customer-avatar.php')return route.fulfill({status:404,body:''});
    if(method==='GET'&&url.pathname==='/manager/sw.js')return route.fulfill({contentType:'application/javascript',body:''});
    if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
    if(method==='GET'&&url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
    if(method!=='POST'||!url.pathname.startsWith('/manager/'))return block();
    let data;try{data=request.postDataJSON()}catch{return block()}
    calls.push({path:url.pathname,...data});
    if(url.pathname==='/manager/api.php'){
      if(['me','login'].includes(data.action))return reply({ok:true,manager,csrf:'fixture-csrf',projects:[]});
      if(data.action==='detail')return reply({ok:true,conversation:conversation(data.conversation_id),messages:messages.get(data.conversation_id),delivery_failure:null,supervisor_read:(supervisor||owner)&&data.conversation_id===11,owner_read:owner&&data.conversation_id===11});
      if(data.action==='counts')return reply({ok:true,counts:{waiting:{count:2,unread:0},mine:{count:2}}});
      if(data.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
    }
    if(url.pathname==='/manager/pipeline-api.php'){
      if(data.action==='catalog')return reply({ok:true,stages:[],tags:[],outcomes:{open:'В работе'},close_reasons:{}});
      if(data.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager]}});
      if(data.action==='list')return reply({ok:true,conversations:[11,12].map(id=>({...conversation(id),last_text:'Пожелания к поездке',last_sender_type:'customer',awaiting_manager_reply:1,lead_outcome:'open'}))});
      if(data.action==='detail')return reply({ok:true,can_edit_pipeline:true,pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},tasks:[],trip:{},contact:{},source:{},handoff:{}});
    }
    if(url.pathname==='/manager/quick-replies-api.php'&&data.action==='toolbar')return reply({ok:true,replies:[],version:'1'.repeat(64),max_replies:100});
    if(url.pathname==='/manager/personal-workspace-api.php'&&data.action==='snapshot'){const snapshot=require('./fixtures/personal-workspace-default').emptyPersonalWorkspaceSnapshot();snapshot.state.settings.ai_ready_notice=hideNotice?'hide':'show';return reply(snapshot);}
    if(url.pathname==='/manager/post-context-api.php'&&data.action==='detail')return reply({ok:true,conversation_id:data.conversation_id,context:{current:null,history:[]}});
    if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
    if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
    if(url.pathname==='/manager/ai-draft.php'){
      expect(data.csrf).toBe('fixture-csrf');expect([11,12]).toContain(data.conversation_id);
      expect(['prepared','generate','validate','prepared_status']).toContain(data.action);if(data.action==='prepared_status')return reply(readiness?{ok:true,prepared:true,should_draft:readiness!=='recommendations',revision:'1'.repeat(64),latest_message_id:data.latest_message_id,prepared_at:new Date().toISOString(),expires_at:new Date(Date.now()+3600000).toISOString(),preparation_source:'chatgpt_assistant'}:{ok:true,prepared:false});const result=await ai(data);return reply(result.body,result.status||200);
    }
    unexpected.push(method+' '+url.pathname+' '+data.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
  });
  await page.goto(origin+'/manager2/index.php?qa=1');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
  await page.locator('#inboxList [data-conversation-id="11"]').click();if(supervisor||owner)await expect(page.getByRole('button',{name:'AI · Просмотр',exact:true})).toBeVisible();else{await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#aiDraftOpen')).toBeEnabled()}
  return {calls,unexpected,errors,setAI:handler=>{ai=handler},messages,aiCalls:()=>calls.filter(call=>call.path==='/manager/ai-draft.php'&&call.action!=='prepared_status'),statusCalls:()=>calls.filter(call=>call.action==='prepared_status')};
}
function clean(state){expect(state.errors).toEqual([]);expect(state.unexpected).toEqual([]);expect(state.calls.filter(call=>['send','take','release','close','reopen'].includes(call.action))).toEqual([])}
async function bounded(page){
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  const bounds=await page.locator('#aiDraftPanel').evaluate(node=>{const rect=node.getBoundingClientRect();return {left:rect.left,right:rect.right,top:rect.top,bottom:rect.bottom}});
  const viewport=page.viewportSize();expect(bounds.left).toBeGreaterThanOrEqual(-1);expect(bounds.right).toBeLessThanOrEqual(viewport.width+1);expect(bounds.top).toBeGreaterThanOrEqual(-1);expect(bounds.bottom).toBeLessThanOrEqual(viewport.height+1);
  await page.locator('#sendReply').scrollIntoViewIfNeeded();expect(await page.locator('#sendReply').evaluate(node=>node.getBoundingClientRect().bottom)).toBeLessThanOrEqual(viewport.height+1);
}
async function capture(page,browserName,viewport){const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,`ai-draft-${browserName}-${viewport.width}-${viewport.height}-preview.png`)})}
for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])test('AI expired readiness hides before a delayed timer and preserves input '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
  await page.setViewportSize(viewport);const state=await setup(page,{readiness:true});
  const reply=page.locator('#replyText'),notice=page.locator('.aiReadyNotice');
  await expect(page.locator('#aiDraftOpen')).toHaveText('AI — Ответ готов');await expect(notice).toBeVisible();
  await reply.fill('Мой незаконченный ответ');await page.locator('#replyFile').setInputFiles({name:'keep.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment')});
  await reply.evaluate(node=>{node.focus();node.setSelectionRange(1,5,'backward');window.aiExpiryFile=document.querySelector('#replyFile').files[0]});
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});
  const prefix=`ai-expiry-${browserName}-${viewport.width}-${viewport.height}`;
  await page.screenshot({animations:'disabled',path:path.join(directory,prefix+'-before.png')});
  const calls=state.statusCalls().length;
  await page.evaluate(()=>{const now=Date.now()+3600001;Date.now=()=>now;WorkspaceV2AiDraft.syncPreferences();WorkspaceV2AiDraft.sync()});
  await expect(page.locator('#aiDraftOpen')).toHaveText('AI');await expect(notice).not.toBeVisible();
  await expect(reply).toBeFocused();await expect(reply).toHaveValue('Мой незаконченный ответ');await expect(page.locator('#mediaSelection')).toContainText('keep.txt');
  expect(await reply.evaluate(node=>[node.selectionStart,node.selectionEnd,node.selectionDirection,document.querySelector('#replyFile').files[0]===window.aiExpiryFile])).toEqual([1,5,'backward',true]);
  expect(state.statusCalls().length).toBe(calls);expect(state.aiCalls()).toEqual([]);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  await page.screenshot({animations:'disabled',path:path.join(directory,prefix+'-after.png')});clean(state);
});
for(const width of [390,430,768,1440])test('AI automatic readiness stays quiet and fits the composer '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const state=await setup(page,{readiness:true});
  const button=page.locator('#aiDraftOpen'),notice=page.locator('.aiReadyNotice');
  await expect(button).toHaveText('AI — Ответ готов');await expect(button).toHaveAttribute('data-ai-state','prepared');await expect(button).toHaveAttribute('title',/Подготовлен автоматически.*МСК/);
  await expect(notice).toBeVisible();await expect(notice).toContainText('AI — Ответ готов');await expect(page.locator('#aiDraftPanel')).not.toBeVisible();
  await page.locator('#replyText').fill('Мой ответ');await page.locator('#replyFile').setInputFiles({name:'keep.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment')});
  await page.locator('#replyText').focus();await page.evaluate(()=>{for(let i=0;i<5;i++)WorkspaceV2AiDraft.sync()});
  await expect(page.locator('#replyText')).toBeFocused();await expect(page.locator('#replyText')).toHaveValue('Мой ответ');await expect(page.locator('#mediaSelection')).toContainText('keep.txt');
  expect(state.aiCalls()).toEqual([]);expect(state.statusCalls().length).toBeGreaterThan(0);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  // A readable title does not imply the native mobile slide has finished.
  // Measure the final conversation position, preserving every geometry assertion.
  if(width<=740)await expect.poll(()=>page.locator('#conversationZone').evaluate(el=>Math.round(el.getBoundingClientRect().left))).toBe(0);
  // Bounds describe the settled responsive pane, not an intermediate transform.
  // Keep the same viewport limits; wait for the actual native transition.
  await expect.poll(()=>page.locator('#conversationZone').evaluate(node=>node.getAnimations({subtree:true}).filter(animation=>animation.playState==='running').length)).toBe(0);
  for(const locator of [button,notice,page.locator('#sendReply')]){const box=await locator.boundingBox();expect(box.x).toBeGreaterThanOrEqual(-1);expect(box.x+box.width).toBeLessThanOrEqual(width+1);expect(box.y+box.height).toBeLessThanOrEqual(901)}
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,`ai-ready-${browserName}-${width}.png`)});
  await notice.getByRole('button',{name:'Посмотреть'}).click();await expect(page.locator('#aiDraftInsert')).toBeEnabled();await expect(notice).not.toBeVisible();expect(state.aiCalls().map(call=>call.action)).toEqual(['prepared']);clean(state);
});
for(const mode of ['hidden','recommendations'])test('AI '+mode+' keeps the ready button without a conversation notice',async({page})=>{
  const state=await setup(page,{readiness:mode==='recommendations'?'recommendations':true,hideNotice:mode==='hidden'});
  await expect(page.locator('#aiDraftOpen')).toHaveText(mode==='recommendations'?'AI — Рекомендации':'AI — Ответ готов');await expect(page.locator('.aiReadyNotice')).not.toBeVisible();expect(state.aiCalls()).toEqual([]);
  if(mode==='hidden'){await page.evaluate(()=>WorkspaceV2PersonalWorkspace.open());await expect(page.locator('#pwHideAiReadyNotice')).toBeChecked();await page.locator('#pwClose').click();await page.locator('#aiDraftOpen').click();await expect(page.locator('#aiDraftInsert')).toBeEnabled()}
  clean(state);
});
for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])test('AI preview and explicit insert retain the native draft, File and controls '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
  await page.setViewportSize(viewport);const state=await setup(page);expect(state.aiCalls()).toEqual([]);
  const reply=page.locator('#replyText'),text='  Уже набранный ответ\nс сохранёнными пробелами  ';
  // WebKit fill changes the first leading U+0020 before any AI action on this
  // surface. Seed exact text through the canonical native input handler.
  await reply.evaluate((node,value)=>{node.value=value;node.dispatchEvent(new Event('input',{bubbles:true}))},text);
  await page.locator('#replyFile').setInputFiles({name:'synthetic-plan.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment')});
  await reply.evaluate(node=>{node.focus();node.setSelectionRange(2,12,'backward');window.aiNativeProof={reply:node,form:document.querySelector('#composer'),file:document.querySelector('#replyFile'),preview:document.querySelector('#mediaSelection .mediaSelectedItem')}});
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  // Viewport coordinates also include the mobile pane's own transform. A settled
  // composer alone can still move with that ancestor before the first snapshot.
  await expect.poll(()=>page.locator('#conversationZone').evaluate(node=>node.getAnimations({subtree:true}).filter(animation=>animation.playState==='running').length)).toBe(0);
  await expect(page.locator('#composer')).toHaveClass(/m2ReplyMultiline/);
  await expect.poll(()=>page.locator('#composer .composerActions').evaluate(node=>{
    const surface=node.closest('.composerSurface'),css=getComputedStyle(surface);
    return Math.abs(node.getBoundingClientRect().left-surface.getBoundingClientRect().left-parseFloat(css.borderLeftWidth)-parseFloat(css.paddingLeft));
  })).toBeLessThanOrEqual(1);
  const actionBoxes=await page.locator('#composer .composerActions').boundingBox();
  await expect(reply).toHaveValue(text);
  await page.locator('#aiDraftOpen').click();await expect(page.locator('#aiDraftInsert')).toBeEnabled();await expect(page.locator('#aiDraftPanel')).toBeFocused();
  await expect(page.locator('#aiDraftStatus')).toContainText('Ответ подготовлен заранее');await expect(page.locator('.aiDraftAction')).toHaveText('Уточнить запрос');await expect(reply).toHaveValue(text);await expect(page.locator('#mediaSelection')).toContainText('synthetic-plan.txt');
  expect(state.aiCalls()).toHaveLength(1);expect(state.aiCalls()[0]).toMatchObject({action:'prepared',conversation_id:11,latest_message_id:35});
  const previewBoxes=await page.locator('#composer .composerActions').boundingBox();expect(Math.abs(previewBoxes.y-actionBoxes.y)).toBeLessThanOrEqual(1);expect(Math.abs(previewBoxes.x-actionBoxes.x)).toBeLessThanOrEqual(1);
  await bounded(page);await capture(page,browserName,viewport);
  await page.locator('#aiDraftInsert').click();await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(reply).toHaveValue(text+'\nКакие даты поездки вам удобны?');await expect(reply).toBeFocused();
  expect(await reply.evaluate(node=>[node.selectionStart,node.selectionEnd,node.selectionDirection])).toEqual([2,12,'backward']);
  expect(await page.evaluate(()=>{const proof=aiNativeProof;return document.querySelector('#replyText')===proof.reply&&document.querySelector('#composer')===proof.form&&document.querySelector('#replyFile')===proof.file&&document.querySelector('#mediaSelection .mediaSelectedItem')===proof.preview&&WorkspaceV2Media.hasFile()})).toBe(true);
  expect(state.aiCalls().map(call=>call.action)).toEqual(['prepared','validate']);
  expect(state.aiCalls()[1].draft_id).toBeUndefined();
  expect(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('workspaceV2.replySession.v1')).drafts.find(row=>row.id===11).aiDraftId)).toBeUndefined();
  clean(state);
});
for(const unavailable of [false,true])test('AI cache '+(unavailable?'unavailable':'miss')+' generates on the first click and preserves text and File',async({page})=>{
  const state=await setup(page),typed='Свой ответ  ';
  state.setAI(async data=>data.action==='prepared'?{body:unavailable?{ok:false,error:'prepared_unavailable'}:{ok:true,prepared:false},status:unavailable?503:200}:data.action==='generate'?{body:proposal()}:{body:{ok:true,revision:'1'.repeat(64),latest_message_id:35,draft_id:data.draft_id}});
  await page.locator('#replyText').fill(typed);await page.locator('#replyFile').setInputFiles({name:'keep.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment')});
  // WebKit may turn the final filled space into NBSP before any AI action.
  // Compare the native value byte-for-byte across cache read and insertion.
  const text=await page.locator('#replyText').inputValue();expect(text.replace(/\u00a0/g,' ')).toBe(typed);
  await page.locator('#aiDraftOpen').click();await expect(page.locator('#aiDraftInsert')).toBeEnabled();
  await expect(page.locator('#replyText')).toHaveValue(text);await expect(page.locator('#mediaSelection')).toContainText('keep.txt');expect(state.aiCalls().map(call=>call.action)).toEqual(['prepared','generate']);
  await expect(page.locator('#aiDraftStatus')).not.toContainText('подготовлен заранее');
  await page.locator('#aiDraftInsert').click();await expect(page.locator('#replyText')).toHaveValue(text+'\n'+proposal().draft);expect(state.aiCalls()[2].draft_id).toBe('d'.repeat(32));
  expect(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('workspaceV2.replySession.v1')).drafts.find(row=>row.id===11).aiDraftId)).toBe('d'.repeat(32));await expect(page.locator('#mediaSelection')).toContainText('keep.txt');clean(state);
});
test('AI stale cached cutoff never renders the text or generates a replacement',async({page})=>{
  const state=await setup(page);state.setAI(async()=>({body:prepared({latest_message_id:34})}));await page.locator('#replyText').fill('Свой текст');await page.locator('#aiDraftOpen').click();
  await expect(page.locator('#aiDraftStatus')).toContainText('Переписка изменилась');await expect(page.locator('.aiDraftText')).toHaveCount(0);await expect(page.locator('#replyText')).toHaveValue('Свой текст');expect(state.aiCalls().map(call=>call.action)).toEqual(['prepared']);clean(state);
});
test('AI reassignment discards a late successful cached reply',async({page})=>{
  const state=await setup(page);let release;state.setAI(async()=>{await new Promise(resolve=>{release=resolve});return {body:prepared()}});
  await page.locator('#replyText').fill('Свой текст');await page.locator('#aiDraftOpen').click();await expect.poll(()=>!!release).toBe(true);
  await page.evaluate(()=>{WorkspaceV2.S.detail.conversation.manager_id=99;WorkspaceV2AiDraft.sync()});release();await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(page.locator('#aiDraftOpen')).toBeDisabled();await expect(page.locator('#replyText')).toHaveValue('Свой текст');expect(state.aiCalls().map(call=>call.action)).toEqual(['prepared']);clean(state);
});
test('AI no_contact and unavailable provider remain human readable without inserting',async({page})=>{
  const state=await setup(page);await page.locator('#replyText').fill('Свой ответ');
  state.setAI(async()=>({body:prepared({should_draft:false,draft:null,next_action:'no_contact',reason:'Турист попросил не писать.'})}));
  await page.locator('#aiDraftOpen').click();await expect(page.locator('.aiDraftAction')).toHaveText('Сейчас не писать');await expect(page.locator('#aiDraftInsert')).not.toBeVisible();
  await expect(page.locator('#replyText')).toHaveValue('Свой ответ');expect(state.aiCalls()).toHaveLength(1);
  state.setAI(async()=>({body:{ok:false,error:'provider_not_configured'},status:503}));await page.locator('#aiDraftRetry').click();await expect(page.locator('#aiDraftStatus')).toContainText('AI сейчас недоступен');
  await expect(page.locator('#aiDraftInsert')).not.toBeVisible();clean(state);
});
test('AI server context_stale409 blocks explicit insertion',async({page})=>{
  const state=await setup(page);state.setAI(async data=>data.action==='prepared'?{body:prepared()}:{body:{ok:false,error:'context_stale'},status:409});
  await page.locator('#replyText').fill('Свой текст');await page.locator('#aiDraftOpen').click();await expect(page.locator('#aiDraftInsert')).toBeEnabled();await page.locator('#aiDraftInsert').click();
  await expect(page.locator('#aiDraftStatus')).toContainText('Переписка изменилась');await expect(page.locator('#aiDraftInsert')).not.toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Свой текст');expect(state.aiCalls()).toHaveLength(2);clean(state);
});
for(const change of ['arrival','same-ID edit'])test('AI rejects a '+change+' while the cached reply is pending',async({page})=>{
  const state=await setup(page);let release;state.setAI(async()=>{await new Promise(resolve=>{release=resolve});return {body:prepared()}});
  await page.locator('#replyText').fill('Свой текст');await page.locator('#aiDraftOpen').click();await expect.poll(()=>!!release).toBe(true);
  if(change==='arrival')state.messages.get(11).push({id:36,sender_type:'customer',text:'Синтетическое новое требование',created_at:'2026-10-17 12:01:00'});else state.messages.get(11)[34].text='Синтетическое исправленное требование';
  await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible());await expect(page.locator('#aiDraftStatus')).toContainText('Переписка изменилась');release();
  await expect(page.locator('#aiDraftInsert')).not.toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Свой текст');expect(state.aiCalls()).toHaveLength(1);clean(state);
});
test('AI validation preserves text edited while waiting and does not execute proposal HTML',async({page})=>{
  const state=await setup(page);let release;const draft='<img src=x onerror="throw 1"> Литерал';
  state.setAI(async data=>{if(data.action==='prepared')return {body:prepared({draft})};await new Promise(resolve=>{release=resolve});return {body:{ok:true,revision:'1'.repeat(64),latest_message_id:35}}});
  await page.locator('#replyText').fill('Первый текст');await page.locator('#aiDraftOpen').click();await expect(page.locator('.aiDraftText')).toHaveText(draft);await expect(page.locator('.aiDraftText img')).toHaveCount(0);
  await page.locator('#aiDraftInsert').click();await expect.poll(()=>!!release).toBe(true);await page.locator('#replyText').fill('Новый текст  ');release();
  await expect(page.locator('#replyText')).toHaveValue('Новый текст  \n'+draft);await expect(page.locator('#aiDraftPanel')).not.toBeVisible();clean(state);
});
test('AI obsolete401 cannot expire the newly selected conversation; current401 still recovers',async({page})=>{
  const state=await setup(page);let release;
  // Retain the response despite cancellation, so the canonical read-owner fence
  // rather than a successful network abort must reject the late401.
  await page.evaluate(()=>{AbortController.prototype.abort=function(){}});
  state.setAI(async()=>{await new Promise(resolve=>{release=resolve});return {body:{ok:false,error:'unauthorized'},status:401}});
  await page.locator('#aiDraftOpen').click();await expect.poll(()=>!!release).toBe(true);await page.evaluate(()=>WorkspaceV2Conversation.open(12));await expect(page.locator('#replyText')).toBeEnabled();
  await page.locator('#replyText').fill('Новый диалог');release();await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(page.locator('#managerAuthRecovery')).not.toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Новый диалог');
  state.setAI(async()=>({body:{ok:false,error:'unauthorized'},status:401}));await page.locator('#aiDraftOpen').click();await expect(page.locator('#managerAuthRecovery')).toBeVisible();await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Новый диалог');clean(state);
});
test('AI panel travels with the canonical expanded editor and Escape returns the existing selection',async({page})=>{
  await page.setViewportSize({width:390,height:420});const state=await setup(page);await page.locator('#replyText').fill('Свой ответ');await page.locator('#m2ReplyExpand').click();
  await page.locator('#replyText').evaluate(node=>node.setSelectionRange(1,5,'backward'));await page.locator('#aiDraftOpen').click();await expect(page.locator('#aiDraftInsert')).toBeEnabled();await bounded(page);
  await page.keyboard.press('Escape');await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(page.locator('#m2ReplyEditor')).toBeVisible();await expect(page.locator('#replyText')).toBeFocused();expect(await page.locator('#replyText').evaluate(node=>[node.selectionStart,node.selectionEnd,node.selectionDirection])).toEqual([1,5,'backward']);clean(state);
});

for(const choice of [{decision:'allow',label:'Разрешить',width:1440},{decision:'deny',label:'Отмена',width:390},{decision:'allow',label:'Разрешить',width:430,legacy:true}])test('OAuth native '+(choice.legacy?'legacy policy control':choice.decision)+' posts the actual secure consent form and verifies callback navigation',async({browser,browserName},testInfo)=>{
  const fixture=await startOAuthFixture(sourceRoot,{legacyFormPolicy:!!choice.legacy});
  const context=await browser.newContext({ignoreHTTPSErrors:true,proxy:fixture.proxy,serviceWorkers:'block',viewport:{width:choice.width,height:900}});
  const {callbacks,unexpected}=fixture;
  let failed=false,page,phase='startup',postStatus=null;
  try{
    page=await context.newPage();
    await page.goto(fixture.origin+'/manager/fixture-session.php?actor=1');
    const cookies=await context.cookies(fixture.origin+'/manager/');
    expect(cookies.some(cookie=>cookie.name==='anytour_manager_panel'&&cookie.secure&&cookie.httpOnly&&cookie.path==='/manager/')).toBe(true);
    const authorizationPage=await page.goto(fixture.origin+fixture.authorization_path);
    const policy=(await authorizationPage.allHeaders())['content-security-policy'];
    expect(policy.includes("form-action 'self'"+(choice.legacy?'':(' '+fixture.redirect_uri))+';')).toBe(true);
    await expect(page.getByRole('heading',{name:'Разрешить подготовку ответов в ChatGPT?'})).toBeVisible();
    const form=page.locator('form');await expect(form).toHaveAttribute('method','post');
    await expect(form.locator('input[name="pending_id"]')).toHaveValue(/^[a-f0-9]{48}$/);
    await expect(form.locator('input[name="csrf"]')).not.toHaveValue('');
    await page.evaluate(()=>{window.oauthCspDirectives=[];document.addEventListener('securitypolicyviolation',event=>window.oauthCspDirectives.push(event.effectiveDirective));});
    const image=testInfo.outputPath('oauth-consent-'+choice.width+'-'+browserName+'.png');
    await page.screenshot({path:image,fullPage:true});await testInfo.attach('oauth-consent',{body:fs.readFileSync(image),contentType:'image/png'});
    phase='native_post';
    const isPost=request=>request.method()==='POST'&&new URL(request.url()).origin===fixture.origin&&new URL(request.url()).pathname==='/manager/ai-oauth.php';
    const sent=page.waitForRequest(isPost,{timeout:10000});
    const posted=!choice.legacy&&browserName==='chromium'?page.waitForResponse(response=>isPost(response.request()),{timeout:10000}):null;
    await page.getByRole('button',{name:choice.label,exact:true}).click({noWaitAfter:true});
    const body=new URLSearchParams((await sent).postData());
    expect([...body.keys()]).toEqual(['pending_id','csrf','decision']);expect(body.get('decision')).toBe(choice.decision);
    phase='callback_navigation';
    // WebKit omits this redirected POST response event even after reaching the
    // callback. Require the real PHP 303 at the TLS boundary in every engine;
    // Chromium additionally exposes it on page.response for positive flows.
    await expect.poll(()=>fixture.responses.filter(result=>result.method==='POST'&&result.path==='/manager/ai-oauth.php').length).toBe(1);
    postStatus=fixture.responses.find(result=>result.method==='POST'&&result.path==='/manager/ai-oauth.php').status;
    expect(postStatus).toBe(303);
    if(posted)expect((await posted).status()).toBe(303);
    if(choice.legacy){
      await expect.poll(async()=>callbacks.length>0||(await page.evaluate(()=>window.oauthCspDirectives??[])).includes('form-action')).toBe(true);
      const directives=await page.evaluate(()=>window.oauthCspDirectives??[]);
      // Chromium checks the redirected form destination. Preserve the observed
      // engine difference if WebKit permits the old policy instead.
      if(browserName==='chromium'||directives.includes('form-action')){
        expect(directives).toContain('form-action');expect(callbacks).toHaveLength(0);
        phase='blocked_callback_retry';
        const retried=page.waitForResponse(result=>result.request().method()==='POST'&&new URL(result.url()).origin===fixture.origin,{timeout:10000});
        await page.getByRole('button',{name:choice.label,exact:true}).click({noWaitAfter:true});
        const rejected=await retried;expect(rejected.status()).toBe(400);
        expect(await rejected.json()).toEqual({error:'invalid_request',error_description:'consent_request_missing'});
        expect(callbacks).toHaveLength(0);
      }else{
        expect(callbacks).toHaveLength(1);expect(callbacks[0].searchParams.get('iss')).toBe(fixture.issuer);
      }
      expect(unexpected).toEqual([]);return;
    }
    await expect.poll(()=>callbacks.length).toBe(1);
    expect(callbacks[0].searchParams.get('iss')).toBe(fixture.issuer);
    expect(callbacks[0].searchParams.get('state')).toBe('synthetic-state/a?b&c');
    if(choice.decision==='allow'){
      const code=callbacks[0].searchParams.get('code');expect(code).toMatch(/^[A-Za-z0-9_-]{43}$/);
      const token=await context.request.post(fixture.origin+'/manager/ai-oauth.php?action=token',{maxRedirects:0,form:{grant_type:'authorization_code',
        client_id:fixture.client_id,code,code_verifier:fixture.verifier,resource:fixture.resource,redirect_uri:fixture.redirect_uri}});
      expect(token.status()).toBe(200);const grant=await token.json();expect(grant.token_type).toBe('Bearer');expect(grant.expires_in).toBe(3600);
    }else{
      expect(callbacks[0].searchParams.get('error')).toBe('access_denied');expect(callbacks[0].searchParams.has('code')).toBe(false);
    }
    expect(unexpected).toEqual([]);
  }catch(error){
    failed=true;
    const csp=page?await page.evaluate(()=>window.oauthCspDirectives??[]).catch(()=>['unavailable']):[];
    error.message+=' [OAuth fixture phase='+phase+' post_status='+postStatus+' csp='+csp.join(',')+' callback_count='+callbacks.length+']';
    throw error;
  }finally{
    try{await context.close();}catch(error){if(!failed)throw error;}finally{await fixture.close();}
  }
});

for(const viewport of [{width:390,height:844},{width:430,height:844},{width:768,height:844},{width:1440,height:844},{width:390,height:420}])test('Supervisor foreign AI is readonly and preserves own draft/File '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
  await page.setViewportSize(viewport);const state=await setup(page,{supervisor:true});
  await page.evaluate(()=>WorkspaceV2Conversation.open(12));await expect(page.locator('#replyText')).toBeVisible();await page.locator('#replyText').fill('Мой незаконченный ответ');await page.locator('#replyFile').setInputFiles({name:'keep.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic own attachment')});await page.evaluate(()=>window.supervisorOwnFile=document.querySelector('#replyFile').files[0]);
  await page.evaluate(()=>WorkspaceV2Conversation.open(11));await page.getByRole('button',{name:'AI · Просмотр',exact:true}).click();await expect(page.locator('#aiDraftPanel')).toBeVisible();await expect(page.locator('#aiDraftStatus')).toContainText('Просмотр руководителя');await expect(page.locator('#aiDraftInsert')).toBeHidden();await expect(page.locator('#composer')).toBeHidden();await expect(page.locator('#conversationTitle')).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);const b=await page.locator('#aiDraftPanel').boundingBox();expect(b.x).toBeGreaterThanOrEqual(0);expect(b.x+b.width).toBeLessThanOrEqual(viewport.width+1);expect(b.y+b.height).toBeLessThanOrEqual(viewport.height+1);const host=await page.locator('#conversationZone').boundingBox();expect(b.x).toBeGreaterThanOrEqual(host.x);expect(b.x+b.width).toBeLessThanOrEqual(host.x+host.width+1);
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,'supervisor-ai-'+browserName+'-'+viewport.width+'-'+viewport.height+'.png')});
  await page.locator('#aiDraftRetry').click();await expect.poll(()=>state.aiCalls().filter(c=>c.action==='generate').length).toBe(1);await page.locator('#aiDraftPanel .aiDraftClose').click();await page.evaluate(()=>WorkspaceV2Conversation.open(12));await expect(page.locator('#replyText')).toHaveValue('Мой незаконченный ответ');await expect(page.locator('#mediaSelection')).toContainText('keep.txt');expect(state.aiCalls().filter(c=>c.action==='validate')).toEqual([]);clean(state);
});

for(const viewport of [{width:390,height:844},{width:430,height:844},{width:768,height:844},{width:1440,height:844},{width:390,height:420}])test('Owner AI opens a waiting client before take and preserves draft '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
  // Follow the real Inbox/Back path and await the selected client's visible state.
  const openClient=async(id,name)=>{if(await page.locator('#mobileBack').isVisible()){await page.locator('#mobileBack').click();await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-mobile-screen','inbox')}await page.locator(`#inboxList [data-conversation-id="${id}"] .m2RowOpen`).click();await expect(page.locator('#conversationTitle')).toHaveText(name)};
  await page.setViewportSize(viewport);const state=await setup(page,{owner:true,ownerState:'waiting_manager'});await openClient(12,'Алексей Иванов');await expect(page.locator('#replyText')).toBeEnabled();await page.locator('#replyText').fill('Мой ответ другому клиенту');await page.locator('#replyFile').setInputFiles({name:'owner-file.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic owner File')});
  await openClient(11,'Анна Морозова');await page.getByRole('button',{name:'AI · Просмотр',exact:true}).click();await expect(page.locator('#aiDraftPanel')).toBeVisible();await expect(page.locator('#aiDraftStatus')).toContainText('Режим владельца');await expect(page.locator('#aiDraftInsert')).toBeHidden();await expect(page.locator('#composer')).toBeHidden();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);const box=await page.locator('#aiDraftPanel').boundingBox();expect(box.x).toBeGreaterThanOrEqual(0);expect(box.x+box.width).toBeLessThanOrEqual(viewport.width+1);expect(box.y+box.height).toBeLessThanOrEqual(viewport.height+1);const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,'owner-ai-'+browserName+'-'+viewport.width+'-'+viewport.height+'.png')});
  await page.locator('#aiDraftRetry').click();await expect.poll(()=>state.aiCalls().filter(c=>c.action==='generate').length).toBe(1);await page.locator('#aiDraftPanel .aiDraftClose').click();await openClient(12,'Алексей Иванов');await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyText')).toHaveValue('Мой ответ другому клиенту');await expect(page.locator('#mediaSelection')).toContainText('owner-file.txt');expect(state.aiCalls().filter(c=>c.action==='validate')).toEqual([]);clean(state);
});
// Keep the report's case locations in the audited, existing spec inventory.
(function supervisorAnalyticsCases(){
  const shell=execFileSync('php',['manager2/analytics.php'],{cwd:sourceRoot,encoding:'utf8'});
  const scope={project_id:1,project_key:'anytour',display_name:'AnyTour',version:1,include_unsourced:false,sources:[{id:11,name:'Основной источник',channel:'telegram'}]};
  const allResult=(offset=0)=>({ok:true,access_revision:'1'.repeat(64),as_of:'2026-10-22T10:00:00Z',mode:'started',metric:'all',totals:{conversations:206,assigned:205,waiting:155,requested:205,replied:103,continued:52,ready_candidates:7,won:1,lost:0,recorded_stage:1},first_reply:{n:103,p50_seconds:60,p95_seconds:120},by_manager:[{manager_id:9,manager_name:'Ответственный менеджер',conversations:206,waiting:155,replied:103,reply_n:103}],period_events:{requests:206,assignments:205,reply_messages:104,reply_conversations:104,delivery_failures:1},rows:Array.from({length:offset?50:50},(_,i)=>({id:1205-offset-i,display_name:'Синтетический клиент '+(offset+i+1),channel:'telegram',manager_id:9,manager_name:'Ответственный менеджер',first_reply_at:i%2?'2026-10-22T10:00:00Z':null,first_reply_seconds:i%2?60:null,first_reply_manager_id:i%2?7:null})),has_more:offset<150,next_cursor:1156-offset});
  const result=(offset=0,manager=0)=>{const r=allResult(offset);if(!manager)return r;return {...r,totals:{conversations:55,assigned:55,waiting:15,requested:55,replied:23,continued:12,ready_candidates:2,won:0,lost:0,recorded_stage:0},first_reply:{n:23,p50_seconds:60,p95_seconds:120},by_manager:[{manager_id:9,manager_name:'Ответственный менеджер',conversations:55,waiting:15,replied:23,reply_n:23}],rows:Array.from({length:offset?5:50},(_,i)=>({...r.rows[i],id:2205-offset-i,display_name:'Клиент команды B '+(offset+i+1)})),has_more:offset===0,next_cursor:offset?null:2156}};
  async function setup(page,{admin=false}={}){
    const calls=[],errors=[],unexpected=[],held=[];let mode='success',grant=null;
    page.on('pageerror',e=>errors.push(e.message));await page.route('**/*',async route=>{
      const req=route.request(),url=new URL(req.url());const json=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify({viewer_id:4,...body})});
      if(url.origin!==origin){unexpected.push(req.url());return route.abort()}
      if(req.method()==='GET'&&url.pathname==='/manager2/analytics.php')return route.fulfill({contentType:'text/html',body:shell});
      if(req.method()==='GET'&&url.pathname.startsWith('/manager/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(sourceRoot,url.pathname.slice(1)))});
      if(req.method()==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
      if(req.method()!=='POST'){unexpected.push(req.method()+' '+url.pathname);return route.abort()}
      const d=req.postDataJSON();calls.push({path:url.pathname,...d});
      if(url.pathname==='/manager/supervision-api.php'&&d.action==='identity')return json({ok:true,manager:{id:4,role:admin?'admin':'manager',display_name:'Руководитель'},csrf:'synthetic-csrf'});
      if(url.pathname==='/manager/api.php'&&d.action==='me')return json({ok:true,manager:{id:4,role:admin?'admin':'manager',display_name:'Руководитель'},csrf:'synthetic-csrf'});
      if(url.pathname!=='/manager/supervision-api.php'){unexpected.push(url.pathname);return json({ok:false,error:'unexpected'},400)}
      expect(d.csrf).toBe('synthetic-csrf');
      if(d.action==='scope')return json({ok:true,scopes:[scope],managers:[{id:9,name:'Ответственный менеджер'}]});
      if(d.action==='analytics'){
        if(mode==='hold'){mode='success';held.push(()=>json(result(d.cursor?50:0,Number(d.manager_id))));return}
        if(mode==='account')return json({...result(),viewer_id:9});
        if(mode==='scope')return json({...result(d.cursor?50:0,Number(d.manager_id)),access_revision:'2'.repeat(64)});
        if(mode==='denied')return json({ok:false,error:'forbidden'},403);
        if(mode==='error')return json({ok:false,error:'analytics_unavailable'},503);
        if(mode==='empty')return json({...result(),totals:Object.fromEntries(Object.keys(result().totals).map(k=>[k,0])),rows:[],by_manager:[],has_more:false,next_cursor:null});
        return json(result(d.cursor?50:0,Number(d.manager_id)));
      }
      if(d.action==='directory')return json({ok:true,projects:[{id:1,display_name:'AnyTour'}],managers:[{id:4,project_id:1,login:'synthetic-manager',display_name:'Руководитель'}],sources:[{id:11,project_id:1,display_name:'Основной источник'}],grants:grant?[grant]:[]});
      if(d.action==='set'){grant={manager_id:d.manager_id,project_id:d.project_id,enabled:Number(d.enabled),include_unsourced:Number(d.include_unsourced),source_ids:d.source_ids,version:1};return json({ok:true,...grant})}
      unexpected.push(d.action);return json({ok:false,error:'unexpected'},400);
    });
    await page.goto(origin+'/manager2/analytics.php');await expect(page.locator('#analyticsStatus')).toHaveText('Готово.');return {calls,errors,unexpected,setMode:v=>mode=v,releaseHeld:async()=>{for(const send of held.splice(0))await send()}};
  }
  for(const v of [{width:390,height:844},{width:430,height:844},{width:768,height:844},{width:1440,height:844},{width:390,height:420}])test('Supervisor analytics complete totals, readonly pages and responsive states '+v.width+'x'+v.height,async({page,browserName})=>{
    await page.setViewportSize(v);const s=await setup(page);await expect(page.locator('#analyticsTotals')).toContainText('206');await expect(page.locator('#analyticsRows .dialogue')).toHaveCount(50);await expect(page.locator('#analyticsLatency')).toContainText('N = 103');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(dir,'supervisor-analytics-'+browserName+'-'+v.width+'-'+v.height+'.png')});
    await page.locator('#analyticsMore').click();await expect(page.locator('#analyticsRows .dialogue')).toHaveCount(100);expect(s.calls.filter(c=>c.action==='analytics').at(-1).cursor).toBe(1156);await expect(page.locator('#analyticsRows a').first()).toHaveAttribute('href','/manager2/#conversation=1205');await expect(page.locator('#analyticsRows a').first()).toHaveAttribute('target','_blank');
    s.setMode('empty');await page.getByRole('button',{name:'Показать',exact:true}).click();await expect(page.locator('#analyticsStatus')).toContainText('данных нет');await expect(page.locator('#analyticsMore')).toBeHidden();
    s.setMode('error');await page.getByRole('button',{name:'Показать',exact:true}).click();await expect(page.locator('#analyticsStatus')).toContainText('Не удалось');await expect(page.locator('#analyticsReport')).toBeHidden();
    s.setMode('denied');await page.getByRole('button',{name:'Показать',exact:true}).click();await expect(page.locator('#analyticsStatus')).toContainText('отозван');await expect(page.locator('#analyticsApp')).toBeHidden();expect(s.calls.some(c=>['send','take','generate','validate','decision','set'].includes(c.action))).toBe(false);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
  });
  test('Supervisor analytics keeps edited filters and late pages out of the applied cohort',async({page})=>{
    const s=await setup(page);const rows=page.locator('#analyticsRows .dialogue'),more=page.locator('#analyticsMore'),manager=page.locator('[name=manager_id]'),show=page.getByRole('button',{name:'Показать',exact:true});
    s.setMode('scope');await more.click();await expect(page.locator('#analyticsReport')).toBeHidden();await expect(rows).toHaveCount(0);await expect(page.locator('#analyticsStatus')).toContainText('Область доступа изменилась');await show.click();await expect(rows).toHaveCount(50);await more.click();await expect(rows).toHaveCount(100);s.setMode('success');
    await manager.selectOption('9');await expect(page.locator('#analyticsReport')).toBeHidden();await expect(more).toBeHidden();await expect(page.locator('#analyticsStatus')).toContainText('Фильтры изменены');const beforeApply=s.calls.filter(c=>c.action==='analytics').length;expect(s.calls.filter(c=>c.action==='analytics').at(-1).manager_id).toBe(0);
    await show.click();await expect(rows).toHaveCount(50);expect(s.calls.filter(c=>c.action==='analytics').length).toBe(beforeApply+1);await expect(page.locator('#analyticsTotals .metric strong').first()).toHaveText('55');await more.click();await expect(rows).toHaveCount(55);await expect(rows.first()).toContainText('команды B');expect(s.calls.filter(c=>c.action==='analytics').at(-1)).toMatchObject({manager_id:9,cursor:2156});
    await manager.selectOption('0');await show.click();await expect(rows).toHaveCount(50);s.setMode('hold');await more.click();await expect.poll(()=>s.calls.filter(c=>c.action==='analytics').at(-1)?.cursor).toBe(1156);
    await manager.selectOption('9');await show.click();await expect(rows).toHaveCount(50);await more.click();await expect(rows).toHaveCount(55);const late=page.waitForResponse(r=>r.request().method()==='POST'&&r.request().postDataJSON()?.action==='analytics'&&r.request().postDataJSON()?.cursor===1156);await s.releaseHeld();await (await late).finished();await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(resolve)));await expect(rows).toHaveCount(55);await expect(page.locator('#analyticsTotals .metric strong').first()).toHaveText('55');await expect(rows.last()).toContainText('команды B');await expect(more).toBeHidden();expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
  });
  test('Supervisor analytics never renders a different logged-in account response',async({page})=>{
    const s=await setup(page);s.setMode('account');await page.getByRole('button',{name:'Показать',exact:true}).click();await expect(page.locator('#analyticsApp')).toBeHidden();await expect(page.locator('#analyticsRows .dialogue')).toHaveCount(0);await expect(page.locator('#analyticsLogin')).toBeVisible();expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
  });
  test('Supervisor access command is explicit, exact account/source and readback before another write',async({page})=>{
    const s=await setup(page,{admin:true});await page.locator('#supervisorAdmin summary').click();await page.locator('#grantRead').click();await expect(page.locator('#grantIdentity')).toContainText('synthetic-manager · №4');await page.locator('#grantSources input').check();await page.locator('#grantEnabled').check();await page.locator('#grantSave').click();await expect(page.locator('#grantSave')).toBeDisabled();const sent=s.calls.filter(c=>c.action==='set');expect(sent).toHaveLength(1);expect(sent[0]).toMatchObject({manager_id:4,project_id:1,source_ids:[11],enabled:true,expected_version:0});await page.locator('#grantRead').click();await expect(page.locator('#grantIdentity')).toContainText('версия прав 1');await expect(page.locator('#grantSources input')).toBeChecked();await expect(page.locator('#grantEnabled')).toBeChecked();expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
  });
})();
