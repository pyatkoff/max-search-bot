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
async function setup(page){
  const calls=[],unexpected=[],errors=[],messages=new Map();
  let ai=async data=>data.action==='prepared'?{body:prepared()}:data.action==='generate'?{body:proposal()}:{body:{ok:true,revision:'1'.repeat(64),latest_message_id:35,draft_id:data.draft_id||null}};
  const manager={id:7,role:'manager',display_name:'Мария',is_working:true};
  const conversation=id=>({id,status:'manager',manager_id:7,manager_name:'Мария',channel:'telegram',display_name:id===11?'Анна Морозова':'Алексей Иванов'});
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
      if(data.action==='detail')return reply({ok:true,conversation:conversation(data.conversation_id),messages:messages.get(data.conversation_id),delivery_failure:null});
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
    if(url.pathname==='/manager/personal-workspace-api.php'&&data.action==='snapshot')return reply(require('./fixtures/personal-workspace-default').emptyPersonalWorkspaceSnapshot());
    if(url.pathname==='/manager/post-context-api.php'&&data.action==='detail')return reply({ok:true,conversation_id:data.conversation_id,context:{current:null,history:[]}});
    if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
    if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
    if(url.pathname==='/manager/ai-draft.php'){
      expect(data.csrf).toBe('fixture-csrf');expect([11,12]).toContain(data.conversation_id);
      expect(['prepared','generate','validate']).toContain(data.action);const result=await ai(data);return reply(result.body,result.status||200);
    }
    unexpected.push(method+' '+url.pathname+' '+data.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
  });
  await page.goto(origin+'/manager2/index.php?qa=1');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
  await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#replyText')).toBeEnabled();
  await expect(page.locator('#aiDraftOpen')).toBeEnabled();
  return {calls,unexpected,errors,setAI:handler=>{ai=handler},messages,aiCalls:()=>calls.filter(call=>call.path==='/manager/ai-draft.php')};
}
function clean(state){expect(state.errors).toEqual([]);expect(state.unexpected).toEqual([]);expect(state.calls.filter(call=>['send','take','release','close','reopen'].includes(call.action))).toEqual([])}
async function bounded(page){
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  const bounds=await page.locator('#aiDraftPanel').evaluate(node=>{const rect=node.getBoundingClientRect();return {left:rect.left,right:rect.right,top:rect.top,bottom:rect.bottom}});
  const viewport=page.viewportSize();expect(bounds.left).toBeGreaterThanOrEqual(-1);expect(bounds.right).toBeLessThanOrEqual(viewport.width+1);expect(bounds.top).toBeGreaterThanOrEqual(-1);expect(bounds.bottom).toBeLessThanOrEqual(viewport.height+1);
  await page.locator('#sendReply').scrollIntoViewIfNeeded();expect(await page.locator('#sendReply').evaluate(node=>node.getBoundingClientRect().bottom)).toBeLessThanOrEqual(viewport.height+1);
}
async function capture(page,browserName,viewport){const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,`ai-draft-${browserName}-${viewport.width}-${viewport.height}-preview.png`)})}
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
for(const unavailable of [false,true])test('AI cache '+(unavailable?'unavailable':'miss')+' preserves text and File until explicit regeneration',async({page})=>{
  const state=await setup(page),typed='Свой ответ  ';
  state.setAI(async data=>data.action==='prepared'?{body:unavailable?{ok:false,error:'prepared_unavailable'}:{ok:true,prepared:false},status:unavailable?503:200}:data.action==='generate'?{body:proposal()}:{body:{ok:true,revision:'1'.repeat(64),latest_message_id:35,draft_id:data.draft_id}});
  await page.locator('#replyText').fill(typed);await page.locator('#replyFile').setInputFiles({name:'keep.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment')});
  // WebKit may turn the final filled space into NBSP before any AI action.
  // Compare the native value byte-for-byte across cache read and insertion.
  const text=await page.locator('#replyText').inputValue();expect(text.replace(/\u00a0/g,' ')).toBe(typed);
  await page.locator('#aiDraftOpen').click();await expect(page.locator('#aiDraftStatus')).toContainText(unavailable?'Не удалось проверить готовый ответ':'Готового ответа пока нет');
  await expect(page.locator('#aiDraftInsert')).not.toBeVisible();await expect(page.locator('#replyText')).toHaveValue(text);await expect(page.locator('#mediaSelection')).toContainText('keep.txt');expect(state.aiCalls().map(call=>call.action)).toEqual(['prepared']);
  await page.locator('#aiDraftRetry').click();await expect(page.locator('#aiDraftInsert')).toBeEnabled();await expect(page.locator('#aiDraftStatus')).not.toContainText('подготовлен заранее');expect(state.aiCalls().map(call=>call.action)).toEqual(['prepared','generate']);
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

for(const choice of [{decision:'allow',label:'Разрешить',width:1440},{decision:'deny',label:'Отмена',width:390}])test('OAuth native '+choice.decision+' posts the actual secure consent form and reaches the pinned callback',async({browser,browserName},testInfo)=>{
  const fixture=await startOAuthFixture(sourceRoot);
  const context=await browser.newContext({ignoreHTTPSErrors:true,proxy:fixture.proxy,serviceWorkers:'block',viewport:{width:choice.width,height:900}});
  const {callbacks,unexpected}=fixture;
  let failed=false,page,phase='startup',postStatus=null;
  try{
    page=await context.newPage();
    await page.goto(fixture.origin+'/manager/fixture-session.php?actor=1');
    const cookies=await context.cookies(fixture.origin+'/manager/');
    expect(cookies.some(cookie=>cookie.name==='anytour_manager_panel'&&cookie.secure&&cookie.httpOnly&&cookie.path==='/manager/')).toBe(true);
    await page.goto(fixture.origin+fixture.authorization_path);
    await expect(page.getByRole('heading',{name:'Разрешить подготовку ответов в ChatGPT?'})).toBeVisible();
    const form=page.locator('form');await expect(form).toHaveAttribute('method','post');
    await expect(form.locator('input[name="pending_id"]')).toHaveValue(/^[a-f0-9]{48}$/);
    await expect(form.locator('input[name="csrf"]')).not.toHaveValue('');
    await page.evaluate(()=>{window.oauthCspDirectives=[];document.addEventListener('securitypolicyviolation',event=>window.oauthCspDirectives.push(event.effectiveDirective));});
    const image=testInfo.outputPath('oauth-consent-'+choice.width+'-'+browserName+'.png');
    await page.screenshot({path:image,fullPage:true});await testInfo.attach('oauth-consent',{path:image,contentType:'image/png'});
    phase='native_post';
    const posted=page.waitForResponse(response=>response.request().method()==='POST'&&new URL(response.url()).origin===fixture.origin&&new URL(response.url()).pathname==='/manager/ai-oauth.php',{timeout:10000});
    await page.getByRole('button',{name:choice.label,exact:true}).click({noWaitAfter:true});
    const response=await posted,body=new URLSearchParams(response.request().postData());
    postStatus=response.status();
    expect([...body.keys()]).toEqual(['pending_id','csrf','decision']);expect(body.get('decision')).toBe(choice.decision);
    expect(response.status()).toBe(303);
    phase='callback_navigation';
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
