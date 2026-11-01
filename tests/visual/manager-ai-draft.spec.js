const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..'),sourceRoot=process.env.M2_QA_SOURCE_ROOT||root,origin='http://127.0.0.1:4173';
const shell=execFileSync('php',['manager2/index.php'],{cwd:sourceRoot,encoding:'utf8'});
const profileShell=execFileSync('php',['manager2/request-profile.php'],{cwd:sourceRoot,encoding:'utf8'});
const {startOAuthFixture}=require('./fixtures/oauth-consent');
const {setupAiUsage}=require('./fixtures/manager-ai-usage');
const proposal=(changes={})=>({ok:true,should_draft:true,draft:'Какие даты поездки вам удобны?',next_action:'clarify',reason:'В запросе не указаны даты вылета.',checks:[],evidence_message_ids:[35],history_incomplete:false,revision:'1'.repeat(64),latest_message_id:35,draft_id:'d'.repeat(32),...changes});
const prepared=(changes={})=>proposal({prepared:true,prepared_at:'2026-10-19T10:30:00Z',source:'chatgpt',draft_id:null,...changes});
// Render the actual PHP shell and canonical assets. Every identity, message,
// proposal and HTTP response in this fixture is synthetic and stays in loopback.
async function setup(page,{readiness=false,automatic=false,ensureHandler=null,hideNotice=false,supervisor=false,owner=false,ownerState='manager',previewHandler=null,blocked=false,manualTask=false,profilePanel=false}={}){
  const calls=[],unexpected=[],errors=[],messages=new Map();
  let profileHandler=null,profileRevision='1'.repeat(64);const profiles=new Map();
  const profileFor=id=>{if(!profiles.has(id))profiles.set(id,{version:1,status:'fresh',label:id===11?'Семейная поездка в Турцию':'Другая поездка',boundary_message_id:1,fields:{party:{value:{adults:2,children:1,child_ages:[7]},kind:'manager_interpretation',importance:'hard',note:''}}});return profiles.get(id);};
  let deliveryBlocked=blocked;
  let automaticComplete=false;
  let ai=async data=>data.action==='prepared'?{body:prepared()}:data.action==='generate'?{body:proposal()}:{body:{ok:true,revision:'1'.repeat(64),latest_message_id:35,draft_id:data.draft_id||null}};
  let preview=previewHandler|| (async()=>({body:prepared({should_draft:readiness!=='recommendations',draft:readiness==='recommendations'?null:proposal().draft,next_action:readiness==='recommendations'?'no_contact':'clarify',reason:readiness==='recommendations'?'Менеджер уже ответил, нового вопроса нет.':proposal().reason,expires_at:new Date(Date.now()+3600000).toISOString()})}));
  const manager={id:7,role:owner?'admin':'manager',display_name:'Мария',is_working:true,supervision_available:supervisor||owner};
  const conversation=id=>({id,...(profilePanel?{project_key:'anytour'}:{}),status:owner&&id===11?ownerState:'manager',manager_id:owner&&id===11&&ownerState!=='manager'?null:(supervisor||owner)&&id===11?9:7,manager_name:owner&&id===11&&ownerState!=='manager'?null:(supervisor||owner)&&id===11?'Другой менеджер':'Мария',channel:blocked&&id===11?'max':'telegram',display_name:id===11?'Анна Морозова':'Алексей Иванов',...(blocked&&id===11?{delivery_failure_category:deliveryBlocked?'suspended':null}:{})});
  const assistantItem=()=>({conversation_id:11,display_name:'Анна Морозова',channel:'max',project_key:'anytour',project_name:'AnyTour',manager_name:'Другой менеджер',last_message_at:'2026-10-17T12:00:00Z',delivery_suspended:deliveryBlocked,delivery_notice:deliveryBlocked?'Ответ станет доступен после новой активности клиента.':null,operational:{awaiting_manager_reply:true,due_task:manualTask},tasks:manualTask?[{id:901,title:'Проверить вручную сохранённый документ',status:'open',due_state:'overdue',overdue:true,due_at_utc:'2026-10-17 13:00:00',is_pinned:false}]:[],recommendation:deliveryBlocked?{revision:'1'.repeat(64),next_action:'answer_question',reason:'Противоречивая рекомендация из старого снимка',checks:[],prepared_at:prepared().prepared_at,expires_at:new Date(Date.now()+3600000).toISOString(),latest_message_id:35,evidence_message_ids:[35],history_incomplete:false,decision:null}:null});
  for(const id of [11,12])messages.set(id,Array.from({length:35},(_,i)=>({id:i+1,sender_type:i%3?'customer':'manager',manager_id:i%3?null:7,text:'Синтетическое сообщение '+(i+1)+'. Обсуждаем пожелания к поездке.',created_at:'2026-10-17 12:00:00'})));
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url()),method=request.method();
    const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(profilePanel&&url.pathname==='/manager/ai-draft.php'&&body.revision?{...body,revision:profileRevision}:body)});
    const block=()=>{unexpected.push(method+' '+url.pathname);return route.abort()};
    if(url.origin!==origin)return block();
    if(method==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
    if(profilePanel&&method==='GET'&&url.pathname==='/manager2/request-profile.php')return route.fulfill({contentType:'text/html',body:profileShell});
    if(method==='GET'&&/^\/manager2?\/assets\/[\w.-]+$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(sourceRoot,url.pathname.slice(1)))});
    if(method==='GET'&&url.pathname==='/manager/customer-avatar.php')return route.fulfill({status:404,body:''});
    if(method==='GET'&&url.pathname==='/manager/sw.js')return route.fulfill({contentType:'application/javascript',body:''});
    if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
    if(method==='GET'&&url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
    if(method!=='POST'||!url.pathname.startsWith('/manager/'))return block();
    let data;try{data=request.postDataJSON()}catch{return block()}
    calls.push({path:url.pathname,...data});
    if(profilePanel&&url.pathname==='/manager/request-profile-api.php'){
      if(profileHandler){const held=await profileHandler(data);if(held)return reply(held.body,held.status||200);}
      const id=Number(data.conversation_id),profile=profileFor(id);
      if(data.action==='identity')return reply({ok:true,viewer_id:7,csrf:'fixture-csrf'});
      expect(data.csrf).toBe('fixture-csrf');
      if(data.action==='get')return reply({ok:true,viewer_id:7,conversation_id:id,can_edit:true,context_sha256:'a'.repeat(64),profile,sources:[{id:1,sender_type:'customer',text:'Два взрослых и ребёнок7лет, семейный отдых в Турции.'}],history:[],coverage_incomplete:false});
      if(data.action==='save'){profile.label=data.label;profile.fields=data.fields;profile.version++;profileRevision='2'.repeat(64);return reply({ok:true,viewer_id:7,conversation_id:id,saved_version:profile.version});}
      return reply({ok:false,error:'unexpected_profile_action'},400);
    }
    if(url.pathname==='/manager/api.php'){
      if(['me','login'].includes(data.action))return reply({ok:true,manager,csrf:'fixture-csrf',projects:[]});
      if(data.action==='detail')return reply({ok:true,conversation:conversation(data.conversation_id),messages:messages.get(data.conversation_id),delivery_failure:deliveryBlocked&&data.conversation_id===11?{category:'suspended',message:'Клиент недоступен в MAX'}:null,supervisor_read:(supervisor||owner)&&data.conversation_id===11,owner_read:owner&&data.conversation_id===11});
      if(data.action==='counts')return reply({ok:true,counts:{waiting:{count:2,unread:0},mine:{count:2}}});
      if(data.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
    }
    if(url.pathname==='/manager/pipeline-api.php'){
      if(data.action==='catalog')return reply({ok:true,stages:[],tags:[],outcomes:{open:'В работе'},close_reasons:{}});
      if(data.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager]}});
      if(data.action==='list')return reply({ok:true,conversations:[11,12].map(id=>({...conversation(id),last_text:'Пожелания к поездке',last_sender_type:'customer',awaiting_manager_reply:1,lead_outcome:'open',...(blocked&&id===11?{awaiting_first_reply:1,wait_age_seconds:7200,last_preview_text:deliveryBlocked?'Клиент недоступен в MAX':null,...(manualTask?{next_task_title:'Проверить вручную сохранённый документ',next_task_due_state:'overdue',next_task_overdue:1,next_task_due_at_utc:'2026-10-17 13:00:00'}:{})}:{})}))});
      if(data.action==='detail')return reply({ok:true,can_edit_pipeline:true,pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},tasks:[],trip:{},contact:{},source:{},handoff:{}});
    }
    if(url.pathname==='/manager/quick-replies-api.php'&&data.action==='toolbar')return reply({ok:true,replies:[],version:'1'.repeat(64),max_replies:100});
    if(url.pathname==='/manager/personal-workspace-api.php'&&data.action==='snapshot'){const snapshot=require('./fixtures/personal-workspace-default').emptyPersonalWorkspaceSnapshot();snapshot.state.settings.ai_ready_notice=hideNotice?'hide':'show';return reply(snapshot);}
    if(url.pathname==='/manager/post-context-api.php'&&data.action==='detail')return reply({ok:true,conversation_id:data.conversation_id,context:{current:null,history:[]}});
    if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
    if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
    if(blocked&&url.pathname==='/manager/assistant-api.php'){
      if(data.action==='list')return reply({ok:true,items:[assistantItem()],has_more:false,next_cursor:null});
      if(data.action==='detail'&&data.conversation_id===11)return reply({ok:true,item:assistantItem()});
    }
    if(url.pathname==='/manager/ai-draft.php'){
      expect(data.csrf).toBe('fixture-csrf');expect([11,12]).toContain(data.conversation_id);
      expect(['prepared','prepared_preview','generate','validate','prepared_status','ensure_prepared']).toContain(data.action);if(data.action==='prepared_preview'){const result=await preview(data);return reply(result.body,result.status||200)}if(data.action==='prepared_status')return reply(readiness||automaticComplete?{ok:true,prepared:true,should_draft:readiness!=='recommendations',revision:'1'.repeat(64),latest_message_id:data.latest_message_id,prepared_at:prepared().prepared_at,expires_at:new Date(Date.now()+3600000).toISOString(),preparation_source:'chatgpt_assistant',automatic_preparation:automatic}:{ok:true,prepared:false,automatic_preparation:automatic});if(data.action==='ensure_prepared'){const result=ensureHandler?await ensureHandler(data):{body:{ok:true,requested:true,queued:true,preparation_state:'pending'}};return reply(result.body,result.status||200)}const result=await ai(data);return reply(result.body,result.status||200);
    }
    unexpected.push(method+' '+url.pathname+' '+data.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
  });
  await page.goto(origin+'/manager2/index.php?qa=1');await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
  await page.locator('#inboxList [data-conversation-id="11"]').click();if(supervisor||owner)await expect(page.getByRole('button',{name:'AI · Просмотр',exact:true})).toBeVisible();else{await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#aiDraftOpen')).toBeEnabled()}
  return {calls,unexpected,errors,setProfileHandler:handler=>{profileHandler=handler},setAI:handler=>{ai=handler},setPreview:handler=>{preview=handler},setDeliveryBlocked:value=>{deliveryBlocked=value},completePreparation:()=>{automaticComplete=true},messages,aiCalls:()=>calls.filter(call=>call.path==='/manager/ai-draft.php'&&!['prepared_status','prepared_preview'].includes(call.action)),ensureCalls:()=>calls.filter(call=>call.action==='ensure_prepared'),previewCalls:()=>calls.filter(call=>call.action==='prepared_preview'),statusCalls:()=>calls.filter(call=>call.action==='prepared_status')};
}
function clean(state){expect(state.errors).toEqual([]);expect(state.unexpected).toEqual([]);expect(state.calls.filter(call=>['send','take','release','close','reopen'].includes(call.action))).toEqual([])}
async function openClient(page,id,name){
  if(await page.locator('#mobileBack').isVisible()){await page.locator('#mobileBack').click();await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-mobile-screen','inbox')}
  await page.locator(`#inboxList [data-conversation-id="${id}"] .m2RowOpen`).click();await expect(page.locator('#conversationTitle')).toHaveText(name);await expect(page.locator('#replyText')).toBeEnabled();
}
async function bounded(page){
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  const bounds=await page.locator('#aiDraftPanel').evaluate(node=>{const rect=node.getBoundingClientRect();return {left:rect.left,right:rect.right,top:rect.top,bottom:rect.bottom}});
  const viewport=page.viewportSize();expect(bounds.left).toBeGreaterThanOrEqual(-1);expect(bounds.right).toBeLessThanOrEqual(viewport.width+1);expect(bounds.top).toBeGreaterThanOrEqual(-1);expect(bounds.bottom).toBeLessThanOrEqual(viewport.height+1);
  await page.locator('#sendReply').scrollIntoViewIfNeeded();expect(await page.locator('#sendReply').evaluate(node=>node.getBoundingClientRect().bottom)).toBeLessThanOrEqual(viewport.height+1);
}
async function capture(page,browserName,viewport){const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,`ai-draft-${browserName}-${viewport.width}-${viewport.height}-preview.png`)})}
async function replyFitsAssistant(page,{allowClipping=false}={}){
  await expect(page.locator('#m2Assistant')).toHaveCount(1);
  await expect(page.locator('#m2Assistant #aiDraftSuggestion')).toBeVisible();
  await expect(page.locator('#aiDraftSuggestion')).not.toHaveClass(/aiSuggestionGhost/);
  await expect(page.locator('#aiDraftSuggestion .aiSuggestionHead')).toBeVisible();
  await expect(page.locator('#replyText')).toHaveValue('');
  const geometry=await page.locator('#aiDraftSuggestion').evaluate(node=>{
    const host=node.closest('#m2Assistant').getBoundingClientRect(),rect=node.getBoundingClientRect(),field=document.querySelector('#replyText').getBoundingClientRect(),text=node.querySelector('.aiSuggestionText');
    const top=Math.max(rect.top,host.top),bottom=Math.min(rect.bottom,host.bottom);
    return {left:rect.left,right:rect.right,hostLeft:host.left,hostRight:host.right,overlap:bottom>top&&top<field.bottom&&bottom>field.top,clipped:text.scrollHeight>text.clientHeight+1};
  });
  expect(geometry.left).toBeGreaterThanOrEqual(geometry.hostLeft-1);expect(geometry.right).toBeLessThanOrEqual(geometry.hostRight+1);expect(geometry.overlap).toBe(false);
  if(!allowClipping)expect(geometry.clipped).toBe(false);
}
async function assistantPaint(page,selector){
  return page.locator(selector).evaluate(node=>{
    const rect=node.getBoundingClientRect(),host=node.closest('#m2Assistant').getBoundingClientRect();
    const top=Math.max(rect.top,host.top,0),bottom=Math.min(rect.bottom,host.bottom,innerHeight),left=Math.max(rect.left,host.left),right=Math.min(rect.right,host.right);
    const hit=bottom>top&&right>left?document.elementFromPoint((left+right)/2,(top+bottom)/2):null;
    return {height:Math.max(0,bottom-top),targetHeight:rect.height,hostHeight:host.height,hit:!!hit&&(hit===node||node.contains(hit)),fontSize:parseFloat(getComputedStyle(node).fontSize)};
  });
}
async function readAssistantSection(page,selector){
  const host=await page.locator('#m2Assistant').boundingBox(),target=await page.locator(selector).boundingBox();
  await page.locator('#m2Assistant').hover();await page.mouse.wheel(0,target.y+target.height/2-host.y-host.height/2);
  await expect.poll(async()=>{const p=await assistantPaint(page,selector);return p.hit&&p.height>=Math.min(p.targetHeight,p.hostHeight-16)-1}).toBe(true);
}
// Synthetic UI replay only: no provider call, production context or customer send.
for(const viewport of [{width:390,height:420},{width:430,height:900},{width:1440,height:900}])test('Unified assistant preserves reading and separates reply from checks '+viewport.width+'x'+viewport.height,async({page},testInfo)=>{
  await page.setViewportSize(viewport);let release;
  const response=prepared({draft:'Из какого города вылетаете и какой бюджет рассматриваете?',reason:'Уточнить город и бюджет, сохраняя известные условия.',checks:['Проверить пляж без сильного ветра.','Сверить реальные условия проживания.'],expires_at:new Date(Date.now()+3600000).toISOString()});
  const state=await setup(page,{readiness:true,profilePanel:true,previewHandler:async()=>{await new Promise(resolve=>{release=resolve});return {body:response}}});
  state.setAI(async data=>data.action==='prepared'?{body:response}:{body:{ok:true,revision:'1'.repeat(64),latest_message_id:35}});
  await expect.poll(()=>!!release).toBe(true);await expect(page.locator('#m2Assistant #m2CurrentTrip')).toBeVisible();
  await page.locator('#replyFile').setInputFiles({name:'assistant-retained.txt',mimeType:'text/plain',buffer:Buffer.from('synthetic attachment')});
  await page.locator('#messages').hover();await page.mouse.wheel(0,-250);await page.waitForTimeout(150);
  const anchor=()=>page.locator('#messages').evaluate(box=>{const edge=box.getBoundingClientRect().top,row=[...box.children].find(node=>node.getBoundingClientRect().bottom>edge+1);return {key:row?.dataset.scrollMessageKey,offset:row?.getBoundingClientRect().top-edge}});
  const before=await anchor();release();await expect(page.locator('#aiDraftSuggestion')).toBeVisible();
  // The bounded helper scrolls independently on a short screen. Reach its
  // answer controls with a native wheel rather than measuring offscreen content.
  const initialControl=await page.locator('#aiSuggestionDetails').boundingBox(),initialHelper=await page.locator('#m2Assistant').boundingBox();
  const wheel=initialControl.y<initialHelper.y?initialControl.y-initialHelper.y-8:Math.max(0,initialControl.y+initialControl.height-initialHelper.y-initialHelper.height+8);
  if(wheel){await page.locator('#m2Assistant').hover();await page.mouse.wheel(0,wheel);await page.waitForTimeout(150)}
  const control=await page.locator('#aiSuggestionDetails').boundingBox(),helper=await page.locator('#m2Assistant').boundingBox();
  expect(control.y).toBeGreaterThanOrEqual(helper.y);expect(control.y+control.height).toBeLessThanOrEqual(helper.y+helper.height);
  await replyFitsAssistant(page);
  await expect.poll(async()=>Math.abs((await anchor()).offset-before.offset)).toBeLessThanOrEqual(2);expect((await anchor()).key).toBe(before.key);
  const geometry=await page.evaluate(()=>{const r=id=>{const x=document.getElementById(id).getBoundingClientRect();return {width:x.width,height:x.height,bottom:x.bottom}};return {title:r('conversationTitle'),messages:r('messages'),reply:r('replyText'),helper:r('m2Assistant'),overflow:document.documentElement.scrollWidth-innerWidth}});
  expect(geometry.title.width).toBeGreaterThan(60);expect(geometry.messages.height).toBeGreaterThan(60);expect(geometry.reply.height).toBeGreaterThanOrEqual(32);expect(geometry.reply.bottom).toBeLessThanOrEqual(viewport.height+1);expect(geometry.helper.height).toBeLessThanOrEqual(viewport.height*.39+1);expect(geometry.overflow).toBeLessThanOrEqual(1);
  await page.locator('#m2ConversationMenuToggle').click();await page.locator('#assistantClientOpen').click();
  await expect(page.locator('#aiDraftPanel')).toBeVisible();await expect(page.locator('#assistantDialog')).toHaveCount(0);await expect(page.locator('.aiDraftSectionTitle')).toHaveText(['Готовый ответ туристу','Проверки менеджеру']);
  await expect(page.locator('.aiDraftText')).toHaveText(response.draft);
  await expect(page.locator('#aiDraftSuggestion')).toBeHidden();
  await expect.poll(async()=>{const p=await assistantPaint(page,'.aiDraftText');return p.hit&&p.height>=Math.min(24,p.targetHeight)}).toBe(true);
  await page.screenshot({animations:'disabled',path:testInfo.outputPath('unified-assistant-open-'+viewport.width+'-'+viewport.height+'.png')});
  await readAssistantSection(page,'.aiDraftText');
  expect((await assistantPaint(page,'.aiDraftText')).fontSize).toBeGreaterThanOrEqual(13);
  await page.screenshot({animations:'disabled',path:testInfo.outputPath('unified-assistant-answer-'+viewport.width+'-'+viewport.height+'.png')});
  await readAssistantSection(page,'.aiDraftChecks li:last-child');
  await page.screenshot({animations:'disabled',path:testInfo.outputPath('unified-assistant-checks-'+viewport.width+'-'+viewport.height+'.png')});
  await readAssistantSection(page,'#m2CurrentTrip strong');
  await page.screenshot({animations:'disabled',path:testInfo.outputPath('unified-assistant-request-'+viewport.width+'-'+viewport.height+'.png')});
  await page.locator('#aiDraftOpen').click();await expect(page.locator('#aiDraftPanel')).toBeHidden();
  await page.locator('#aiDraftOpen').click();await expect(page.locator('.aiDraftText')).toHaveText(response.draft);
  await expect.poll(async()=>{const p=await assistantPaint(page,'.aiDraftText');return p.hit&&p.height>=Math.min(24,p.targetHeight)}).toBe(true);
  await expect(page.locator('#m2Assistant')).toHaveCount(1);
  await page.locator('#replyText').click();await expect(page.locator('#replyText')).toBeFocused();
  await page.locator('#replyText').fill('Мой неотправленный ответ');await expect(page.locator('#mediaSelection')).toContainText('assistant-retained.txt');
  await page.locator('#m2ReplyExpand').click();await expect(page.locator('#m2ReplyEditor')).toBeVisible();
  await page.locator('#m2ReplyCollapse').click();await expect(page.locator('#replyText')).toHaveValue('Мой неотправленный ответ');
  await expect(page.locator('#mediaSelection')).toContainText('assistant-retained.txt');
  await page.locator('#messages').hover();await page.mouse.wheel(0,-120);
  const image=testInfo.outputPath('unified-assistant-'+viewport.width+'-'+viewport.height+'.png');await page.screenshot({animations:'disabled',path:image});await testInfo.attach('unified-assistant',{body:fs.readFileSync(image),contentType:'image/png'});clean(state);
  const readonlyPage=await page.context().newPage();
  await readonlyPage.setViewportSize(viewport);
  const readonly=await setup(readonlyPage,{readiness:'recommendations',profilePanel:true,owner:true,previewHandler:async()=>({body:prepared({should_draft:false,draft:null,next_action:'check_offer',reason:'Нет актуального расчёта на новый срок.',checks:['Сверить состав и питание по словам туриста; рассчитать новый срок.'],expires_at:new Date(Date.now()+3600000).toISOString()})})});
  await expect(readonlyPage.locator('#aiRecommendationNotice')).toBeVisible();await readonlyPage.locator('#aiRecommendationDetails').click();
  await expect(readonlyPage.locator('#m2Assistant')).toHaveCount(1);await expect(readonlyPage.locator('.aiDraftText')).toHaveCount(0);await expect(readonlyPage.locator('#aiDraftInsert')).toBeHidden();await expect(readonlyPage.locator('#aiSuggestionAccept')).toBeHidden();
  await expect.poll(async()=>{const p=await assistantPaint(readonlyPage,'.aiDraftEmpty');return p.hit&&p.height>=Math.min(24,p.targetHeight)}).toBe(true);
  await readAssistantSection(readonlyPage,'.aiDraftChecks li:last-child');
  await readonlyPage.screenshot({animations:'disabled',path:testInfo.outputPath('unified-assistant-readonly-'+viewport.width+'-'+viewport.height+'.png')});
  await readAssistantSection(readonlyPage,'.aiDraftClose');await readonlyPage.locator('.aiDraftClose').click();
  await expect(readonlyPage.locator('#aiDraftPanel')).toBeHidden();
  clean(readonly);await readonlyPage.close();
});
for(const stage of ['prepared','validate'])for(const width of [430,1440])test('AI inline insertion recovery '+stage+' '+width,async({page,browserName})=>{
  const viewport={width,height:width===430?420:900};await page.setViewportSize(viewport);
  const state=await setup(page,{readiness:true});
  await expect(page.locator('#aiDraftSuggestion')).toBeVisible();
  await expect(page.locator('#aiSuggestionAccept')).toHaveText('Вставить в ответ');
  await page.locator('#replyFile').setInputFiles({name:'insertion-recovery.txt',mimeType:'text/plain',buffer:Buffer.from('synthetic retained attachment')});
  let failed=true;
  state.setAI(async data=>{
    if(data.action===stage&&failed)return {body:{ok:false,error:stage==='prepared'?'prepared_unavailable':'temporary_unavailable'},status:503};
    return data.action==='prepared'?{body:prepared({draft_id:'d'.repeat(32),expires_at:new Date(Date.now()+3600000).toISOString()})}:{body:{ok:true,revision:'1'.repeat(64),latest_message_id:35,draft_id:data.draft_id||null}};
  });
  await page.locator('#replyText').focus();
  if(width===1440)await page.locator('#replyText').press('Tab');else await page.locator('#aiSuggestionAccept').click();
  await expect(page.locator('#aiDraftPanel')).toBeVisible();await expect(page.locator('#aiDraftStatus')).toContainText('Не удалось проверить');
  await expect(page.locator('#aiDraftStatus')).not.toContainText('Переписка изменилась');await expect(page.locator('#replyText')).toHaveValue('');
  if(width===1440)await expect(page.locator('#replyText')).toBeFocused();
  await expect(page.locator('#mediaSelection')).toContainText('insertion-recovery.txt');expect(await page.evaluate(()=>WorkspaceV2Media.hasFile())).toBe(true);
  expect(state.aiCalls().filter(call=>call.action===stage)).toHaveLength(1);expect(state.aiCalls().some(call=>call.action==='generate')).toBe(false);
  await bounded(page);
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});
  await page.screenshot({animations:'disabled',path:path.join(directory,`ai-inline-recovery-${browserName}-${stage}-${width}-${viewport.height}.png`)});
  failed=false;
  if(stage==='prepared'){await expect(page.locator('#aiDraftRetry')).toHaveText('Повторить проверку');await page.locator('#aiDraftRetry').click();}
  await expect(page.locator('#aiDraftInsert')).toBeEnabled();
  await page.locator('#replyText').fill('Моя поправка');await page.locator('#aiDraftInsert').click();
  await expect(page.locator('#aiDraftPanel')).toBeHidden();
  await expect(page.locator('#replyText')).toHaveValue('Моя поправка\n'+proposal().draft);
  await expect(page.locator('#mediaSelection')).toContainText('insertion-recovery.txt');expect(await page.evaluate(()=>WorkspaceV2Media.hasFile())).toBe(true);
  expect(state.aiCalls().filter(call=>call.action===stage)).toHaveLength(2);expect(state.aiCalls().at(-1).draft_id).toBe('d'.repeat(32));
  expect(state.aiCalls().some(call=>call.action==='generate')).toBe(false);clean(state);
});
for(const change of ['stale409','new message'])for(const width of [430,1440])test('AI validation retry offers fresh recovery after '+change+' '+width,async({page,browserName},testInfo)=>{
  const viewport={width,height:width===430?420:900};await page.setViewportSize(viewport);
  const state=await setup(page,{readiness:true}),reply=page.locator('#replyText');
  await expect(page.locator('#aiDraftSuggestion')).toBeVisible();
  await page.locator('#replyFile').setInputFiles({name:'stale-recovery.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained File')});
  const attachment=page.locator('#mediaSelection .mediaFileName'),attachmentSize=page.locator('#mediaSelection .mediaFileSize');
  await expect(attachment).toHaveText('stale-recovery.txt');const originalSize=await attachmentSize.textContent();
  expect(await page.evaluate(()=>WorkspaceV2Media.hasFile())).toBe(true);
  let validations=0,release;
  state.setAI(async data=>{
    if(data.action==='prepared')return {body:prepared({draft_id:'d'.repeat(32),expires_at:new Date(Date.now()+3600000).toISOString()})};
    if(data.action==='generate')return {body:proposal({latest_message_id:data.latest_message_id})};
    if(++validations===1)return {body:{ok:false,error:'temporary_unavailable'},status:503};
    if(change==='new message'){await new Promise(resolve=>{release=resolve});return {body:{ok:true,revision:'1'.repeat(64),latest_message_id:35,draft_id:data.draft_id}}}
    return {body:{ok:false,error:'context_stale'},status:409};
  });
  await reply.focus();if(width===1440)await reply.press('Tab');else await page.locator('#aiSuggestionAccept').click();
  await expect(page.locator('#aiDraftStatus')).toContainText('Не удалось проверить контекст');
  await expect(page.locator('#aiDraftInsert')).toHaveText('Повторить вставку');
  await expect(page.locator('#aiDraftRetry')).not.toBeVisible();
  await reply.fill('Мой незаконченный ответ');
  // Keep a late successful response alive so the source guards, rather than a
  // successful network abort, must reject it after the real detail refresh.
  if(change==='new message')await page.evaluate(()=>{AbortController.prototype.abort=function(){}});
  await page.locator('#aiDraftInsert').click();
  if(change==='new message'){
    await expect.poll(()=>!!release).toBe(true);
    state.messages.get(11).push({id:36,sender_type:'customer',text:'Синтетическое новое условие',created_at:'2026-10-17 12:01:00'});
    await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible());
    await expect(page.locator('#aiDraftStatus')).toContainText('Переписка изменилась');
    const late=page.waitForResponse(response=>response.url().endsWith('/manager/ai-draft.php')&&response.request().postDataJSON()?.action==='validate'&&response.status()===200);
    release();expect(await (await late).finished()).toBeNull();
  }
  await expect(page.locator('#aiDraftPanel')).toBeVisible();await expect(page.locator('#aiDraftStatus')).toContainText('Переписка изменилась');
  await expect(page.locator('#aiDraftInsert')).not.toBeVisible();await expect(page.locator('.aiDraftText')).toHaveCount(0);
  const recovery=page.locator('#aiDraftRetry');await expect(recovery).toBeVisible();await expect(recovery).toBeEnabled();await expect(recovery).toHaveText('Подготовить новый ответ');
  await expect(reply).toHaveValue('Мой незаконченный ответ');await expect(page.locator('#mediaSelection')).toContainText('stale-recovery.txt');
  await expect(attachment).toHaveText('stale-recovery.txt');await expect(attachmentSize).toHaveText(originalSize);expect(await page.evaluate(()=>WorkspaceV2Media.hasFile())).toBe(true);
  expect(state.aiCalls().map(call=>call.action)).toEqual(['prepared','validate','validate']);
  await bounded(page);
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});
  const image=path.join(directory,`ai-stale-recovery-${browserName}-${change.replace(' ','-')}-${width}-${viewport.height}.png`);await page.screenshot({animations:'disabled',path:image});await testInfo.attach('ai-stale-recovery',{body:fs.readFileSync(image),contentType:'image/png'});
  await recovery.click();await expect(page.locator('.aiDraftText')).toHaveText(proposal().draft);await expect(page.locator('#aiDraftInsert')).toBeEnabled();
  expect(state.aiCalls().map(call=>call.action)).toEqual(['prepared','validate','validate','generate']);expect(state.aiCalls().at(-1).latest_message_id).toBe(change==='new message'?36:35);
  await expect(reply).toHaveValue('Мой незаконченный ответ');await expect(attachment).toHaveText('stale-recovery.txt');await expect(attachmentSize).toHaveText(originalSize);expect(await page.evaluate(()=>WorkspaceV2Media.hasFile())).toBe(true);clean(state);
});
for(const owner of [false,true])for(const width of [390,1440])test('AI opening queues and reveals '+(owner?'owner foreign':'own')+' automatic hint without an AI click '+width,async({page,browserName},testInfo)=>{
  await page.setViewportSize({width,height:900});await page.addInitScript(()=>{window.syntheticAiButtonClicks=0;document.addEventListener('click',event=>{const button=event.target.closest?.('button');if(button?.id==='aiDraftOpen'||button?.getAttribute('aria-label')==='AI · Просмотр')window.syntheticAiButtonClicks++},true)});
  const state=await setup(page,{automatic:true,owner}),notice=page.locator('#aiPreparationNotice'),reply=page.locator('#replyText');
  await expect(notice).toBeVisible();await expect(notice).toContainText('AI готовит подсказку автоматически');await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(page.locator('#aiDraftSuggestion')).not.toBeVisible();await expect(reply).toHaveValue('');await expect.poll(()=>state.ensureCalls().length).toBe(1);
  const managerRows=await page.locator('#messages .msg.manager').count();
  if(!owner){await page.locator('#replyFile').setInputFiles({name:'automatic-retained.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained File')});await reply.focus();await reply.evaluate(()=>{window.syntheticAutomaticFile=document.querySelector('#replyFile').files[0]})}
  else await expect(page.locator('#composer')).not.toBeVisible();
  const pending=testInfo.outputPath('ai-auto-'+(owner?'foreign':'own')+'-'+width+'-pending.png');await page.screenshot({animations:'disabled',path:pending});await testInfo.attach('automatic-pending',{body:fs.readFileSync(pending),contentType:'image/png'});
  state.completePreparation();await expect(page.locator('#aiDraftSuggestion')).toBeVisible({timeout:20000});await expect(notice).not.toBeVisible();await expect(page.locator('.aiSuggestionText')).toHaveText(proposal().draft);await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(reply).toHaveValue('');
  await expect.poll(()=>page.locator('#conversationZone').evaluate(node=>node.getAnimations({subtree:true}).filter(animation=>animation.playState==='running').length)).toBe(0);
  if(owner){await expect(page.locator('#aiDraftSuggestion')).toHaveClass(/aiSuggestionReadonly/);await expect(page.locator('#aiDraftSuggestion')).toContainText('Только просмотр');await expect(page.locator('#aiSuggestionAccept')).not.toBeVisible();await expect(page.locator('#composer')).not.toBeVisible()}
  else{await replyFitsAssistant(page);await expect(reply).toBeFocused();await expect(page.locator('#mediaSelection')).toContainText('automatic-retained.txt');expect(await reply.evaluate(()=>document.querySelector('#replyFile').files[0]===window.syntheticAutomaticFile)).toBe(true)}
  expect(await page.evaluate(()=>window.syntheticAiButtonClicks)).toBe(0);expect(state.ensureCalls()).toHaveLength(1);expect(state.aiCalls().map(call=>call.action)).toEqual(['ensure_prepared']);expect(await page.locator('#messages .msg.manager').count()).toBe(managerRows);expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  const ready=testInfo.outputPath('ai-auto-'+(owner?'foreign':'own')+'-'+width+'-ready.png');await page.screenshot({animations:'disabled',path:ready});await testInfo.attach('automatic-ready',{body:fs.readFileSync(ready),contentType:'image/png'});clean(state);
});
test('AI automatic queue outage is visible without invoking manual generation',async({page})=>{
  await page.setViewportSize({width:390,height:900});const state=await setup(page,{automatic:true,ensureHandler:async()=>({body:{ok:false,error:'preparation_unavailable'},status:503})});
  await expect(page.locator('#aiPreparationNotice')).toContainText('временно недоступна');await expect(page.locator('#aiPreparationRetry')).toBeVisible();await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(page.locator('#replyText')).toHaveValue('');expect(state.aiCalls().map(call=>call.action)).toEqual(['ensure_prepared']);clean(state);
});
for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])test('AI expired readiness hides before a delayed timer and preserves input '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
  await page.setViewportSize(viewport);const state=await setup(page,{readiness:true});
  const reply=page.locator('#replyText'),notice=page.locator('.aiReadyNotice');
  await expect(page.locator('#aiDraftOpen')).toHaveText('Помощник — Ответ готов');await expect(page.locator('#aiDraftSuggestion')).toBeVisible();await expect(notice).not.toBeVisible();
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
  await expect(button).toHaveText('Помощник — Ответ готов');await expect(button).toHaveAttribute('data-ai-state','prepared');await expect(button).toHaveAttribute('title',/Подготовлен автоматически.*МСК/);
  await expect(page.locator('#aiDraftSuggestion')).toBeVisible();await expect(notice).not.toBeVisible();await expect(page.locator('#aiDraftPanel')).not.toBeVisible();
  await page.locator('#replyText').fill('Мой ответ');await page.locator('#replyFile').setInputFiles({name:'keep.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment')});
  await page.locator('#replyText').focus();await page.evaluate(()=>{for(let i=0;i<5;i++)WorkspaceV2AiDraft.sync()});
  await expect(page.locator('#aiDraftSuggestion')).not.toBeVisible();await expect(notice).toBeVisible();await expect(page.locator('#replyText')).toBeFocused();await expect(page.locator('#replyText')).toHaveValue('Мой ответ');await expect(page.locator('#mediaSelection')).toContainText('keep.txt');
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
for(const mode of ['hidden','recommendations'])test('AI '+mode+' keeps the ready button and explains only available recommendations',async({page})=>{
  const state=await setup(page,{readiness:mode==='recommendations'?'recommendations':true,hideNotice:mode==='hidden'});
  await expect(page.locator('#aiDraftOpen')).toHaveText(mode==='recommendations'?'Помощник — Нужна проверка':'Помощник — Ответ готов');await expect(page.locator('.aiReadyNotice')).not.toBeVisible();await expect(page.locator('#aiDraftSuggestion')).not.toBeVisible();expect(state.aiCalls()).toEqual([]);
  if(mode==='recommendations'){await expect(page.locator('#aiRecommendationNotice')).toBeVisible();await expect(page.locator('#aiRecommendationNotice')).toContainText('Сейчас отвечать не нужно')}else await expect(page.locator('#aiRecommendationNotice')).not.toBeVisible();
  if(mode==='hidden'){await page.evaluate(()=>WorkspaceV2PersonalWorkspace.open());await expect(page.locator('#pwHideAiReadyNotice')).toBeChecked();await page.locator('#pwClose').click();await page.locator('#aiDraftOpen').click();await expect(page.locator('#aiDraftInsert')).toBeEnabled()}
  clean(state);
});
const explanationViewports=[{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900}];
for(const viewport of explanationViewports)for(const owner of [false,true])for(const action of ['no_contact','check_offer'])test('AI non-draft explanation '+action+' '+(owner?'owner foreign':'own')+' '+viewport.width,async({page,browserName})=>{
  await page.setViewportSize(viewport);await page.addInitScript(()=>{window.syntheticAiButtonClicks=0;document.addEventListener('click',event=>{const button=event.target.closest?.('button');if(button?.id==='aiDraftOpen'||button?.getAttribute('aria-label')==='AI · Просмотр')window.syntheticAiButtonClicks++},true)});
  const reason=action==='no_contact'?'Менеджер уже ответил. Нового вопроса нет, повторное сообщение сейчас не требуется.':'Цена и наличие ещё не подтверждены. Проверьте условия перед ответом. <img src=x onerror=alert(1)>',state=await setup(page,{readiness:'recommendations',owner,previewHandler:async()=>({body:prepared({should_draft:false,draft:null,next_action:action,reason,expires_at:new Date(Date.now()+3600000).toISOString()})})}),notice=page.locator('#aiRecommendationNotice'),reply=page.locator('#replyText');
  await expect(notice).toBeVisible();await expect(notice.locator('strong')).toHaveText(action==='no_contact'?'Сейчас отвечать не нужно':'Готового ответа пока нет · Проверки менеджеру');await expect(notice.locator('.aiRecommendationReason')).toHaveText(reason);expect(await notice.locator('img').count()).toBe(0);await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(page.locator('#aiDraftSuggestion')).not.toBeVisible();await expect(reply).toHaveValue('');
  if(owner){await expect(notice).toHaveClass(/aiRecommendationReadonly/);await expect(page.locator('#composer')).not.toBeVisible()}else{await page.locator('#replyFile').setInputFiles({name:'recommendation-retained.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained File')});await reply.fill('Мой незаконченный ответ');await reply.focus();await reply.evaluate(()=>window.syntheticRecommendationFile=document.querySelector('#replyFile').files[0]);await expect(notice).toBeVisible();await expect(reply).toBeFocused()}
  await expect.poll(()=>page.locator('#conversationZone').evaluate(node=>node.getAnimations({subtree:true}).filter(animation=>animation.playState==='running').length)).toBe(0);
  const geometry=await notice.evaluate(node=>{const r=node.getBoundingClientRect(),host=document.getElementById('conversationZone').getBoundingClientRect();return{left:r.left,right:r.right,top:r.top,bottom:r.bottom,hostLeft:host.left,hostRight:host.right}});expect(geometry.left).toBeGreaterThanOrEqual(geometry.hostLeft-1);expect(geometry.right).toBeLessThanOrEqual(geometry.hostRight+1);expect(geometry.bottom).toBeLessThanOrEqual(viewport.height+1);expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,`ai-explanation-${browserName}-${owner?'foreign':'own'}-${action}-${viewport.width}.png`)});
  const previews=state.previewCalls().length;await page.locator('#aiRecommendationDetails').click();await expect(page.locator('#aiDraftPanel')).toBeVisible();await expect(page.locator('#aiDraftPanel .aiDraftReason')).toHaveText(reason);await expect(page.locator('#aiDraftInsert')).not.toBeVisible();expect(state.previewCalls().length).toBe(previews);expect(state.aiCalls()).toEqual([]);await page.locator('#aiDraftPanel .aiDraftClose').click();await expect(notice).toBeVisible();
  if(!owner){await expect(reply).toHaveValue('Мой незаконченный ответ');await expect(page.locator('#mediaSelection')).toContainText('recommendation-retained.txt');expect(await reply.evaluate(()=>document.querySelector('#replyFile').files[0]===window.syntheticRecommendationFile)).toBe(true)}
  expect(await page.evaluate(()=>window.syntheticAiButtonClicks)).toBe(0);expect(state.aiCalls()).toEqual([]);clean(state);
});
test('AI no-contact explanation keeps the short mobile editor reachable',async({page})=>{
  await page.setViewportSize({width:390,height:420});const state=await setup(page,{readiness:'recommendations'});await expect(page.locator('#aiRecommendationNotice')).toBeVisible();await page.locator('#replyText').fill('Мой ответ');await page.locator('#replyFile').setInputFiles({name:'short-advice.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained File')});await expect.poll(()=>page.locator('#conversationZone').evaluate(node=>node.getAnimations({subtree:true}).filter(animation=>animation.playState==='running').length)).toBe(0);expect(await page.locator('#sendReply').evaluate(node=>node.getBoundingClientRect().bottom)).toBeLessThanOrEqual(421);expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);await expect(page.locator('#replyText')).toHaveValue('Мой ответ');expect(state.aiCalls()).toEqual([]);clean(state);
});
for(const width of [390,430,768,1440])test('AI inline suggestion is visible and Tab inserts with native provenance '+width,async({page,browserName},testInfo)=>{
  await page.setViewportSize({width,height:900});const state=await setup(page,{readiness:true});
  const suggestion=page.locator('#aiDraftSuggestion'),reply=page.locator('#replyText');
  await expect(suggestion).toBeVisible();await expect(suggestion.locator('.aiSuggestionText')).toHaveText(proposal().draft);await expect(page.locator('#aiSuggestionAccept')).toHaveText('Вставить в ответ');
  await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(reply).toHaveValue('');expect(state.aiCalls()).toEqual([]);expect(state.previewCalls()).toHaveLength(1);
  await page.locator('#replyFile').setInputFiles({name:'inline-keep.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment')});
  await reply.click();await reply.evaluate(()=>{window.aiInlineFile=document.querySelector('#replyFile').files[0]});
  await expect(suggestion).toBeVisible();await expect(reply).toBeFocused();
  await expect.poll(()=>page.locator('#conversationZone').evaluate(node=>node.getAnimations({subtree:true}).filter(animation=>animation.playState==='running').length)).toBe(0);
  await replyFitsAssistant(page);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  for(const locator of [suggestion,reply,page.locator('#sendReply')]){const box=await locator.boundingBox();expect(box.x).toBeGreaterThanOrEqual(-1);expect(box.x+box.width).toBeLessThanOrEqual(width+1);expect(box.y).toBeGreaterThanOrEqual(-1);expect(box.y+box.height).toBeLessThanOrEqual(901)}
  const image=testInfo.outputPath('ai-inline-'+browserName+'-'+width+'.png');await page.screenshot({animations:'disabled',path:image});await testInfo.attach('ai-inline-'+width,{body:fs.readFileSync(image),contentType:'image/png'});
  state.setAI(async data=>data.action==='prepared'?{body:prepared({draft_id:'a'.repeat(32),expires_at:new Date(Date.now()+3600000).toISOString()})}:{body:{ok:true,revision:'1'.repeat(64),latest_message_id:35,draft_id:data.draft_id}});
  await reply.press('Tab');await expect(reply).toHaveValue(proposal().draft);await expect(reply).toBeFocused();await expect(suggestion).not.toBeVisible();
  expect(state.aiCalls().map(call=>call.action)).toEqual(['prepared','validate']);expect(state.aiCalls().at(-1).draft_id).toBe('a'.repeat(32));
  expect(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('workspaceV2.replySession.v1')).drafts.find(row=>row.id===11).aiDraftId)).toBe('a'.repeat(32));
  expect(await page.locator('#replyFile').evaluate(node=>node.files[0]===window.aiInlineFile)).toBe(true);await expect(page.locator('#mediaSelection')).toContainText('inline-keep.txt');clean(state);
});
for(const viewport of [{width:390,height:420},{width:430,height:900},{width:768,height:900},{width:1440,height:900}])test('AI reply stays in one assistant beside the native field and expanded editor '+viewport.width+'x'+viewport.height,async({page,browserName},testInfo)=>{
  const draft='Предлагаю сравнить два варианта на ваши даты. Первый ближе к пляжу, второй просторнее.\nУточните, пожалуйста, возраст детей и город вылета — проверим полную стоимость для вашего состава.';
  await page.setViewportSize(viewport);const state=await setup(page,{readiness:true,previewHandler:async()=>({body:prepared({draft,expires_at:new Date(Date.now()+3600000).toISOString()})})}),reply=page.locator('#replyText');
  await expect(page.locator('.aiSuggestionText')).toHaveText(draft);await expect.poll(()=>page.locator('#conversationZone').evaluate(node=>node.getAnimations({subtree:true}).filter(animation=>animation.playState==='running').length)).toBe(0);await replyFitsAssistant(page,{allowClipping:true});
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  const compact=testInfo.outputPath('ai-ghost-compact-'+browserName+'-'+viewport.width+'-'+viewport.height+'.png');await page.screenshot({animations:'disabled',path:compact});await testInfo.attach('ai-ghost-compact',{body:fs.readFileSync(compact),contentType:'image/png'});
  await page.locator('#m2ReplyExpand').click();await expect(page.locator('#m2ReplyEditor')).toBeVisible();await replyFitsAssistant(page,{allowClipping:true});await expect(reply).toHaveValue('');
  const expanded=testInfo.outputPath('ai-ghost-expanded-'+browserName+'-'+viewport.width+'-'+viewport.height+'.png');await page.screenshot({animations:'disabled',path:expanded});await testInfo.attach('ai-ghost-expanded',{body:fs.readFileSync(expanded),contentType:'image/png'});
  await reply.fill('Мой собственный ответ');await expect(page.locator('#aiDraftSuggestion')).not.toBeVisible();await expect(reply).toHaveValue('Мой собственный ответ');await page.locator('#m2ReplyCollapse').click();await expect(reply).toHaveValue('Мой собственный ответ');expect(state.aiCalls()).toEqual([]);clean(state);
});
test('AI inline suggestion leaves typed text and modified Tab navigation alone',async({page})=>{
  const state=await setup(page,{readiness:true}),reply=page.locator('#replyText'),suggestion=page.locator('#aiDraftSuggestion');await expect(suggestion).toBeVisible();
  await reply.focus();await reply.press('Shift+Tab');await expect(reply).not.toBeFocused();await expect(reply).toHaveValue('');expect(state.aiCalls()).toEqual([]);
  await reply.fill('Мой ответ');await expect(suggestion).not.toBeVisible();await reply.press('Tab');await expect(reply).not.toBeFocused();await expect(reply).toHaveValue('Мой ответ');expect(state.aiCalls()).toEqual([]);
  await reply.fill('');await expect(suggestion).toBeVisible();
  await reply.evaluate(node=>node.dispatchEvent(new KeyboardEvent('keydown',{key:'Tab',code:'Tab',isComposing:true,bubbles:true,cancelable:true})));await expect(reply).toHaveValue('');expect(state.aiCalls()).toEqual([]);
  await page.locator('#aiSuggestionDetails').click();await expect(page.locator('#aiDraftPanel')).toBeVisible();await expect(suggestion).not.toBeVisible();clean(state);
});
test('AI ghost dismissal restores the native empty-field placeholder without taking focus',async({page})=>{
  const state=await setup(page,{readiness:true}),reply=page.locator('#replyText');await expect(page.locator('#aiDraftSuggestion')).toBeVisible();await reply.click();await reply.press('Escape');
  await expect(page.locator('#aiDraftSuggestion')).not.toBeVisible();await expect(reply).toBeFocused();await expect(reply).toHaveValue('');await expect(reply).toHaveAttribute('placeholder',/\S/);
  expect(await reply.evaluate(node=>{const style=getComputedStyle(node,'::placeholder');return style.color!=='rgba(0, 0, 0, 0)'&&style.opacity!=='0'})).toBe(true);expect(state.aiCalls()).toEqual([]);clean(state);
});
test('AI ghost follows the desktop large-font preference while already visible',async({page},testInfo)=>{
  await page.setViewportSize({width:1440,height:900});const state=await setup(page,{readiness:true});await expect(page.locator('#aiDraftSuggestion')).toBeVisible();
  await page.evaluate(()=>{document.body.classList.add('pwLargeFont');WorkspaceV2AiDraft.sync()});await expect.poll(()=>page.locator('#replyText').evaluate(node=>getComputedStyle(node).fontSize)).toBe('16px');await expect.poll(()=>page.locator('#conversationZone').evaluate(node=>node.getAnimations({subtree:true}).filter(animation=>animation.playState==='running').length)).toBe(0);await replyFitsAssistant(page);
  const image=testInfo.outputPath('ai-ghost-large-font.png');await page.screenshot({animations:'disabled',path:image});await testInfo.attach('ai-ghost-large-font',{body:fs.readFileSync(image),contentType:'image/png'});
  await page.evaluate(()=>{document.body.classList.remove('pwLargeFont');WorkspaceV2AiDraft.sync()});await expect.poll(()=>page.locator('#replyText').evaluate(node=>getComputedStyle(node).fontSize)).toBe('14px');await expect.poll(()=>page.locator('#conversationZone').evaluate(node=>node.getAnimations({subtree:true}).filter(animation=>animation.playState==='running').length)).toBe(0);await replyFitsAssistant(page);expect(state.aiCalls()).toEqual([]);clean(state);
});
for(const width of [390,1440])test('Blocked customer has no automatic reply urgency and keeps explicit tasks '+width,async({page},testInfo)=>{
  const manualTask=width===1440;await page.setViewportSize({width,height:900});const state=await setup(page,{owner:true,blocked:true,manualTask}),row=page.locator('#inboxList [data-conversation-id="11"]');
  await expect(row).toContainText('Клиент недоступен в MAX');await expect(row).not.toHaveClass(/waitUrgent|waitWarn|needsReply/);await expect(row.locator('.leadWaitCompact,.leadReplyStatus')).toHaveCount(0);await expect(page.locator('#aiDraftSuggestion')).not.toBeVisible();expect(state.statusCalls()).toEqual([]);
  if(await page.locator('#mobileBack').isVisible())await page.locator('#mobileBack').click();await expect.poll(()=>page.locator('#conversationZone').evaluate(node=>node.getAnimations({subtree:true}).filter(animation=>animation.playState==='running').length)).toBe(0);
  const inbox=testInfo.outputPath('blocked-inbox-'+width+'.png');await page.screenshot({animations:'disabled',path:inbox});await testInfo.attach('blocked-inbox',{body:fs.readFileSync(inbox),contentType:'image/png'});
  if(await page.locator('#mobileBack').isVisible())await row.locator('.m2RowOpen').click();await page.locator('#m2ConversationMenuToggle').click();await page.locator('#assistantClientOpen').click();
  await expect(page.locator('#assistantDetail .assistantUnavailable')).toBeVisible();await expect(page.locator('#assistantDetail .assistantUnavailable')).toContainText('Клиент недоступен в MAX');await expect(page.locator('#assistantDetail .assistantRecommendation')).toHaveCount(0);await expect(page.locator('#assistantDetail')).not.toContainText('Обращение без записанного ответа');await expect(page.locator('#assistantDetail')).not.toContainText('Противоречивая рекомендация из старого снимка');
  if(manualTask){await expect(page.locator('#assistantDetail .assistantTask')).toContainText('Проверить вручную сохранённый документ');await expect(page.locator('#assistantDetail .assistantTask')).toContainText('Просрочено')}else{await expect(page.locator('#assistantDetail')).toContainText('Открытых задач нет');await expect(page.locator('#assistantDetail .assistantOperational')).toHaveCount(0)}
  const assistant=testInfo.outputPath('blocked-assistant-'+width+'.png');await page.screenshot({animations:'disabled',path:assistant});await testInfo.attach('blocked-assistant',{body:fs.readFileSync(assistant),contentType:'image/png'});
  await page.locator('#assistantClose').click();if(await page.locator('#mobileBack').isVisible())await page.locator('#mobileBack').click();state.setDeliveryBlocked(false);await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await expect(row).toHaveClass(/waitUrgent/);await expect(row.locator('.leadWaitCompact')).toContainText('Срочно');
  expect(state.aiCalls()).toEqual([]);expect(state.calls.filter(call=>['decision','set_task_completed','task_snooze','create_task'].includes(call.action))).toEqual([]);clean(state);
});
test('AI inline acceptance rechecks the empty composer after asynchronous preparation',async({page})=>{
  const state=await setup(page,{readiness:true}),reply=page.locator('#replyText');await expect(page.locator('#aiDraftSuggestion')).toBeVisible();let release;
  state.setAI(async data=>{if(data.action==='prepared'){await new Promise(resolve=>{release=resolve});return {body:prepared({expires_at:new Date(Date.now()+3600000).toISOString()})}}return {body:{ok:true,revision:'1'.repeat(64),latest_message_id:35}}});
  await reply.press('Tab');await expect.poll(()=>!!release).toBe(true);await reply.fill('Начал писать сам');release();await expect(reply).toHaveValue('Начал писать сам');
  await expect(page.locator('#aiDraftSuggestion')).not.toBeVisible();await expect.poll(()=>state.aiCalls().map(call=>call.action)).toEqual(['prepared']);clean(state);
});
for(const ownerState of ['manager','waiting_manager','ai','closed'])test('AI owner inline preview covers foreign '+ownerState+' without insertion',async({page,browserName},testInfo)=>{
  const width={manager:1440,waiting_manager:390,ai:768,closed:430}[ownerState];await page.setViewportSize({width,height:900});const state=await setup(page,{readiness:true,owner:true,ownerState}),suggestion=page.locator('#aiDraftSuggestion');
  await expect(suggestion).toBeVisible();await expect(suggestion.locator('.aiSuggestionText')).toHaveText(proposal().draft);await expect(suggestion).toContainText(/Просмотр|просмотр/);
  if(ownerState==='manager')await expect(suggestion).toContainText('Другой менеджер');await expect(page.locator('#aiSuggestionAccept')).not.toBeVisible();await expect(page.locator('#composer')).not.toBeVisible();expect(state.previewCalls()).toHaveLength(1);expect(state.aiCalls()).toEqual([]);
  await expect.poll(()=>page.locator('#conversationZone').evaluate(node=>node.getAnimations({subtree:true}).filter(animation=>animation.playState==='running').length)).toBe(0);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);const box=await suggestion.boundingBox();expect(box.x).toBeGreaterThanOrEqual(-1);expect(box.x+box.width).toBeLessThanOrEqual(width+1);expect(box.y+box.height).toBeLessThanOrEqual(901);
  const image=testInfo.outputPath('ai-owner-inline-'+browserName+'-'+ownerState+'.png');await page.screenshot({animations:'disabled',path:image});await testInfo.attach('ai-owner-inline-'+ownerState,{body:fs.readFileSync(image),contentType:'image/png'});
  await suggestion.locator('.aiSuggestionText').click();await page.keyboard.press('Tab');expect(state.aiCalls()).toEqual([]);clean(state);
});
test('AI foreign supervisor does not gain automatic inline previews',async({page})=>{
  const state=await setup(page,{readiness:true,supervisor:true});await expect(page.locator('#aiDraftSuggestion')).not.toBeVisible();expect(state.statusCalls()).toEqual([]);expect(state.previewCalls()).toEqual([]);expect(state.aiCalls()).toEqual([]);clean(state);
});
for(const change of ['navigation','new message','role loss'])test('AI inline late preview cannot survive '+change,async({page})=>{
  let release;const state=await setup(page,{readiness:true,owner:change==='role loss',previewHandler:async data=>{if(change==='navigation'&&data.conversation_id!==11)return {body:{ok:true,prepared:false}};await new Promise(resolve=>{release=resolve});return {body:prepared({expires_at:new Date(Date.now()+3600000).toISOString()})}}});await expect.poll(()=>!!release).toBe(true);
  let lateResponse;
  if(change==='navigation'){
    // Use the real selection path and deliver the original response despite
    // cancellation; the old conversation's response must fail its owner fence.
    await page.evaluate(()=>{AbortController.prototype.abort=function(){}});
    await openClient(page,12,'Алексей Иванов');await page.locator('#replyText').fill('Ответ в другом чате');
    lateResponse=page.waitForResponse(response=>response.url().endsWith('/manager/ai-draft.php')&&response.request().postDataJSON()?.action==='prepared_preview'&&response.request().postDataJSON()?.conversation_id===11);
  }
  else if(change==='new message'){state.messages.get(11).push({id:36,sender_type:'customer',text:'Новое требование',created_at:'2026-10-17 12:01:00'});await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible())}
  else await page.evaluate(()=>{WorkspaceV2.S.manager.role='manager';WorkspaceV2.S.detail.owner_read=false;WorkspaceV2AiDraft.sync()});
  release();if(lateResponse)expect(await (await lateResponse).finished()).toBeNull();await expect(page.locator('#aiDraftSuggestion')).not.toBeVisible();expect(state.aiCalls()).toEqual([]);if(change==='navigation'){await expect(page.locator('#replyText')).toHaveValue('Ответ в другом чате');await page.locator('#replyText').fill('');await expect(page.locator('#aiDraftSuggestion')).not.toBeVisible()}clean(state);
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
  await page.locator('#aiDraftOpen').click();await expect.poll(()=>!!release).toBe(true);await openClient(page,12,'Алексей Иванов');
  await page.locator('#replyText').fill('Новый диалог');const lateResponse=page.waitForResponse(response=>response.url().endsWith('/manager/ai-draft.php')&&response.status()===401&&response.request().postDataJSON()?.conversation_id===11);release();expect(await (await lateResponse).finished()).toBeNull();await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(page.locator('#managerAuthRecovery')).not.toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Новый диалог');
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


for(const choice of [{decision:'allow',label:'Разрешить',width:1440},{decision:'deny',label:'Отмена',width:390}])test('OAuth native development '+choice.decision+' uses the separate secure consent and callback',async({browser,browserName},testInfo)=>{
  const fixture=await startOAuthFixture(sourceRoot,{profile:'development'});
  let context,page,failed=false,postStatus=null,phase='startup';
  try{
    context=await browser.newContext({ignoreHTTPSErrors:true,proxy:fixture.proxy,serviceWorkers:'block',viewport:{width:choice.width,height:900}});
    page=await context.newPage();
    await page.goto(fixture.origin+'/manager/fixture-session.php?actor=1');
    expect((await context.cookies(fixture.origin+'/manager/')).some(cookie=>cookie.name==='anytour_manager_panel'&&cookie.secure&&cookie.httpOnly&&cookie.path==='/manager/')).toBe(true);
    const response=await page.goto(fixture.origin+fixture.authorization_path);
    expect((await response.allHeaders())['content-security-policy']).toContain("form-action 'self' "+fixture.redirect_uri+';');
    await expect(page.getByRole('heading',{name:'Подключение очереди плана развития AnyTour',exact:true})).toBeVisible();
    await expect(page.getByText('Отдельное подключение: только очередь плана развития AnyTour.',{exact:true})).toBeVisible();
    await expect(page.locator('ul li')).toHaveText([
      'Управление метаданными плана развития: очередь задач, привязка существующего исполнителя, состояние запусков и квитанции независимой приёмки.',
      'Получение событий готовности следующей задачи. Это подключение само не меняет код, не выпускает сайт и не запускает внешние задания.'
    ]);
    const form=page.locator('form');
    await expect(form).toHaveAttribute('method','post');await expect(form).toHaveAttribute('action',fixture.authorization_endpoint);
    await expect(form.locator('[name="pending_id"]')).toHaveValue(/^[a-f0-9]{48}$/);await expect(form.locator('[name="csrf"]')).not.toHaveValue('');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
    const image=testInfo.outputPath('oauth-development-consent-'+choice.width+'-'+browserName+'.png');
    await page.screenshot({path:image,fullPage:true});await testInfo.attach('oauth-development-consent',{body:fs.readFileSync(image),contentType:'image/png'});
    const endpoint=new URL(fixture.authorization_endpoint,fixture.origin).pathname;
    const isPost=request=>request.method()==='POST'&&new URL(request.url()).origin===fixture.origin&&new URL(request.url()).pathname===endpoint;
    phase='native_post';const sent=page.waitForRequest(isPost,{timeout:10000});
    const posted=browserName==='chromium'?page.waitForResponse(response=>isPost(response.request()),{timeout:10000}):null;
    await page.getByRole('button',{name:choice.label,exact:true}).click({noWaitAfter:true});
    const body=new URLSearchParams((await sent).postData());expect([...body.keys()]).toEqual(['pending_id','csrf','decision']);expect(body.get('decision')).toBe(choice.decision);
    phase='callback_navigation';
    await expect.poll(()=>fixture.responses.filter(r=>r.method==='POST'&&r.path===endpoint).length).toBe(1);
    postStatus=fixture.responses.find(r=>r.method==='POST'&&r.path===endpoint).status;expect(postStatus).toBe(303);
    if(posted)expect((await posted).status()).toBe(303);
    await expect.poll(()=>fixture.callbacks.length).toBe(1);
    const callback=fixture.callbacks[0];expect(callback.searchParams.get('iss')).toBe(fixture.issuer);
    expect(callback.searchParams.get('state')).toBe(new URL(fixture.authorization_path,fixture.origin).searchParams.get('state'));
    if(choice.decision==='allow'){
      const code=callback.searchParams.get('code');expect(code).toMatch(/^[A-Za-z0-9_-]{43}$/);
      const token=await context.request.post(fixture.origin+endpoint+'?action=token',{maxRedirects:0,form:{grant_type:'authorization_code',
        client_id:fixture.client_id,code,code_verifier:fixture.verifier,resource:fixture.resource,redirect_uri:fixture.redirect_uri}});
      expect(token.status()).toBe(200);const grant=await token.json();
      expect(grant.token_type).toBe('Bearer');expect(grant.expires_in).toBe(3600);expect(grant.scope).toBe('development_work:write');
    }else{
      expect(callback.searchParams.get('error')).toBe('access_denied');expect(callback.searchParams.has('code')).toBe(false);
    }
    expect(fixture.unexpected).toEqual([]);
  }catch(error){failed=true;error.message+=' [DEV OAuth phase='+phase+' post_status='+postStatus+' callback_count='+fixture.callbacks.length+']';throw error;}
  finally{try{if(context)await context.close();}catch(error){if(!failed)throw error;}finally{await fixture.close();}}
});

test('OAuth native development keeps pending sessions clients and resources isolated from legacy',async({browser})=>{
  const fixture=await startOAuthFixture(sourceRoot,{profile:'development'});let context,failed=false;
  try{
    context=await browser.newContext({ignoreHTTPSErrors:true,proxy:fixture.proxy,serviceWorkers:'block',viewport:{width:430,height:900}});
    const page=await context.newPage();await page.goto(fixture.origin+'/manager/fixture-session.php?actor=1');
    const metadata=await context.request.get(fixture.origin+fixture.metadata_endpoint,{maxRedirects:0});
    const discovery=await context.request.get(fixture.origin+fixture.discovery_path,{maxRedirects:0});
    expect(metadata.status()).toBe(200);expect(discovery.status()).toBe(200);
    const definition=await metadata.json();expect(await discovery.json()).toEqual(definition);
    expect(definition.issuer).toBe(fixture.issuer);expect(definition.scopes_supported).toEqual(['development_work:write']);
    expect(fixture.peer_client_id).not.toBe(fixture.client_id);expect(fixture.peer_resource).not.toBe(fixture.resource);
    expect(new URL(fixture.peer_authorization_endpoint,fixture.origin).pathname).toBe('/manager/ai-oauth.php');
    await page.goto(fixture.origin+fixture.authorization_path);
    await expect(page.getByRole('heading',{name:'Подключение очереди плана развития AnyTour',exact:true})).toBeVisible();
    const devPending=await page.locator('form [name="pending_id"]').inputValue();const csrf=await page.locator('form [name="csrf"]').inputValue();
    await page.goto(fixture.origin+fixture.peer_authorization_path);
    const peerPending=await page.locator('form [name="pending_id"]').inputValue();
    expect(peerPending).toMatch(/^[a-f0-9]{48}$/);expect(peerPending).not.toBe(devPending);
    for(const attempt of [{endpoint:fixture.peer_authorization_endpoint,pending:devPending},{endpoint:fixture.authorization_endpoint,pending:peerPending}]){
      const rejected=await context.request.post(fixture.origin+attempt.endpoint,{maxRedirects:0,form:{pending_id:attempt.pending,csrf,decision:'allow'}});
      expect(rejected.status()).toBe(400);expect(await rejected.json()).toEqual({error:'invalid_request',error_description:'consent_request_missing'});
    }
    const wrongClient=new URL(fixture.authorization_path,fixture.origin);wrongClient.searchParams.set('client_id',fixture.peer_client_id);
    const badClient=await context.request.get(wrongClient.href,{maxRedirects:0});
    expect(badClient.status()).toBe(400);expect((await badClient.json()).error).toBe('invalid_client');
    const wrongResource=new URL(fixture.authorization_path,fixture.origin);wrongResource.searchParams.set('resource',fixture.peer_resource);
    const badResource=await context.request.get(wrongResource.href,{maxRedirects:0});
    expect(badResource.status()).toBe(400);expect((await badResource.json()).error).toBe('invalid_target');
    // Failed cross-profile attempts must not consume either original pending form.
    const accepted=await context.request.post(fixture.origin+fixture.authorization_endpoint,{maxRedirects:0,form:{pending_id:devPending,csrf,decision:'allow'}});
    expect(accepted.status()).toBe(303);const callback=new URL(accepted.headers()['location']);
    expect(callback.origin+callback.pathname).toBe(fixture.redirect_uri);expect(callback.searchParams.get('iss')).toBe(fixture.issuer);
    const code=callback.searchParams.get('code');expect(code).toMatch(/^[A-Za-z0-9_-]{43}$/);
    const devPath=new URL(fixture.authorization_endpoint,fixture.origin).pathname;
    const token=await context.request.post(fixture.origin+devPath+'?action=token',{maxRedirects:0,form:{grant_type:'authorization_code',client_id:fixture.client_id,
      code,code_verifier:fixture.verifier,resource:fixture.resource,redirect_uri:fixture.redirect_uri}});
    expect(token.status()).toBe(200);expect((await token.json()).scope).toBe('development_work:write');
    const peerDenied=await context.request.post(fixture.origin+fixture.peer_authorization_endpoint,{maxRedirects:0,form:{pending_id:peerPending,csrf,decision:'deny'}});
    expect(peerDenied.status()).toBe(303);const peerCallback=new URL(peerDenied.headers()['location']);
    expect(peerCallback.searchParams.get('iss')).toBe(fixture.peer_issuer);expect(peerCallback.searchParams.get('error')).toBe('access_denied');
    expect(fixture.callbacks).toEqual([]);expect(fixture.unexpected).toEqual([]);
  }catch(error){failed=true;throw error;}
  finally{try{if(context)await context.close();}catch(error){if(!failed)throw error;}finally{await fixture.close();}}
});

for(const viewport of [{width:390,height:844},{width:430,height:844},{width:768,height:844},{width:1440,height:844},{width:390,height:420}])test('Supervisor foreign AI is readonly and preserves own draft/File '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
  await page.setViewportSize(viewport);const state=await setup(page,{supervisor:true});
  await openClient(page,12,'Алексей Иванов');await expect(page.locator('#replyText')).toBeVisible();await page.locator('#replyText').fill('Мой незаконченный ответ');await page.locator('#replyFile').setInputFiles({name:'keep.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic own attachment')});await page.evaluate(()=>window.supervisorOwnFile=document.querySelector('#replyFile').files[0]);
  await openClient(page,11,'Анна Морозова');await page.getByRole('button',{name:'AI · Просмотр',exact:true}).click();await expect(page.locator('#aiDraftPanel')).toBeVisible();await expect(page.locator('#aiDraftStatus')).toContainText('Просмотр руководителя');await expect(page.locator('#aiDraftInsert')).toBeHidden();await expect(page.locator('#composer')).toBeHidden();await expect(page.locator('#conversationTitle')).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);const b=await page.locator('#aiDraftPanel').boundingBox();expect(b.x).toBeGreaterThanOrEqual(0);expect(b.x+b.width).toBeLessThanOrEqual(viewport.width+1);expect(b.y+b.height).toBeLessThanOrEqual(viewport.height+1);const host=await page.locator('#conversationZone').boundingBox();expect(b.x).toBeGreaterThanOrEqual(host.x);expect(b.x+b.width).toBeLessThanOrEqual(host.x+host.width+1);
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,'supervisor-ai-'+browserName+'-'+viewport.width+'-'+viewport.height+'.png')});
  await page.locator('#aiDraftRetry').click();await expect.poll(()=>state.aiCalls().filter(c=>c.action==='generate').length).toBe(1);await page.locator('#aiDraftPanel .aiDraftClose').click();await openClient(page,12,'Алексей Иванов');await expect(page.locator('#replyText')).toHaveValue('Мой незаконченный ответ');await expect(page.locator('#mediaSelection')).toContainText('keep.txt');expect(state.aiCalls().filter(c=>c.action==='validate')).toEqual([]);clean(state);
});

for(const viewport of [{width:390,height:844},{width:430,height:844},{width:768,height:844},{width:1440,height:844},{width:390,height:420}])test('Owner AI opens a waiting client before take and preserves draft '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
  // Follow the real Inbox/Back path and await the selected client's visible state.
  const openClient=async(id,name)=>{if(await page.locator('#mobileBack').isVisible()){await page.locator('#mobileBack').click();await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-mobile-screen','inbox')}await page.locator(`#inboxList [data-conversation-id="${id}"] .m2RowOpen`).click();await expect(page.locator('#conversationTitle')).toHaveText(name)};
  await page.setViewportSize(viewport);const state=await setup(page,{owner:true,ownerState:'waiting_manager'});await openClient(12,'Алексей Иванов');await expect(page.locator('#replyText')).toBeEnabled();await page.locator('#replyText').fill('Мой ответ другому клиенту');await page.locator('#replyFile').setInputFiles({name:'owner-file.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic owner File')});
  await openClient(11,'Анна Морозова');await page.getByRole('button',{name:'AI · Просмотр',exact:true}).click();await expect(page.locator('#aiDraftPanel')).toBeVisible();await expect(page.locator('#aiDraftStatus')).toContainText('Режим владельца');await expect(page.locator('#aiDraftInsert')).toBeHidden();await expect(page.locator('#composer')).toBeHidden();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);const box=await page.locator('#aiDraftPanel').boundingBox();expect(box.x).toBeGreaterThanOrEqual(0);expect(box.x+box.width).toBeLessThanOrEqual(viewport.width+1);expect(box.y+box.height).toBeLessThanOrEqual(viewport.height+1);const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,'owner-ai-'+browserName+'-'+viewport.width+'-'+viewport.height+'.png')});
  await page.locator('#aiDraftRetry').click();await expect.poll(()=>state.aiCalls().filter(c=>c.action==='generate').length).toBe(1);await page.locator('#aiDraftPanel .aiDraftClose').click();await openClient(12,'Алексей Иванов');await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyText')).toHaveValue('Мой ответ другому клиенту');await expect(page.locator('#mediaSelection')).toContainText('owner-file.txt');expect(state.aiCalls().filter(c=>c.action==='validate')).toEqual([]);clean(state);
});
for(const viewport of [{width:390,height:844},{width:1440,height:900}])test('AI usage distinguishes base-rule editions without replay or review '+viewport.width,async({page,browserName})=>{
  await page.setViewportSize(viewport);const state=await setupAiUsage(page);
  try{
    const cases=page.locator('#usageCases .usageCase');await expect(cases).toHaveCount(20);await expect(page.locator('#usageStatus')).toHaveText('Готово.');
    const current=cases.nth(0).locator('.usageProvenance'),previous=cases.nth(1).locator('.usageProvenance'),preparedPreview=cases.nth(2).locator('.usageProvenance');
    await expect(current).toContainText('Общие правила: текущая редакция');await expect(current).toContainText('Черновик сохранён:');
    await expect(previous).toContainText('Общие правила: прежняя редакция');
    await expect(preparedPreview).toContainText('Первый просмотр готового ответа:');await expect(preparedPreview).not.toContainText('Черновик сохранён:');
    await expect(preparedPreview).toHaveAttribute('title',/не время его подготовки/);await expect(current).toHaveAttribute('title',/не подтверждает модель/);
    await expect(cases.nth(0).locator('.usageCaseHeader')).toContainText('Отправлено');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
    const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,'ai-usage-base-rules-'+browserName+'-'+viewport.width+'.png')});
    expect(state.calls.map(c=>c.action)).toEqual(['identity','list']);expect(state.errors).toEqual([]);expect(state.unexpected).toEqual([]);
  }finally{await state.cleanup()}
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

// New current-dialogue surface only; existing profile/AI owners remain canonical.
for(const width of [390,1440])test(`current-trip panel keeps profile and ready reply in one dialogue ${width}`,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});
  const state=await setup(page,{readiness:true,profilePanel:true});
  await expect(page.locator('#m2CurrentTrip strong')).toHaveText('Семейная поездка в Турцию');
  await expect(page.locator('#aiDraftSuggestion')).toBeVisible();
  await page.locator('#aiSuggestionDetails').click();await expect(page.locator('#aiDraftPanel')).toBeVisible();
  await page.locator('#replyText').fill('Мой неотправленный ответ');
  const tabs=page.context().pages().length;
  await page.getByRole('button',{name:'Условия поездки',exact:true}).click();
  const embedded=page.frameLocator('#m2RequestProfilePanel iframe');
  await expect(embedded.locator('#profileLabel')).toHaveValue('Семейная поездка в Турцию');
  await embedded.locator('#profileLabel').fill('Декабрь: семья с ребёнком7лет');
  await embedded.locator('#profileSave').click();
  await expect(page.locator('#m2CurrentTrip strong')).toHaveText('Декабрь: семья с ребёнком7лет');
  await expect(page.locator('.m2RequestProfileState')).toContainText('Профиль сохранён');
  await expect(page.locator('#aiDraftPanel')).not.toBeVisible();
  await expect(page.locator('#m2CurrentTrip small')).toContainText('2 взр. + 1 дет. (7)');
  fs.mkdirSync('manager2-artifacts',{recursive:true});await page.screenshot({path:`manager2-artifacts/${browserName}-${width}-trip-profile.png`,animations:'disabled'});
  await page.getByRole('button',{name:'Готово',exact:true}).click();
  await expect(page.locator('#m2RequestProfilePanel')).not.toBeVisible();
  await expect(page.locator('#replyText')).toHaveValue('Мой неотправленный ответ');
  expect(page.context().pages()).toHaveLength(tabs);
  await page.locator('#replyText').fill('');
  await expect(page.locator('#aiSuggestionAccept')).toBeVisible();
  await page.locator('#aiSuggestionAccept').click();
  await expect(page.locator('#replyText')).toHaveValue(prepared().draft);
  expect(state.calls.some(c=>c.action==='generate')).toBe(false);
  expect(state.calls.filter(c=>c.action==='save'&&c.path==='/manager/request-profile-api.php')).toHaveLength(1);
  if(width<=740)await page.locator('#m2ConversationMenuToggle').click();
  await expect(page.locator('#tourSelectionOpen')).toBeVisible();
  expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(11);
  if(width<=740)await page.locator('#m2ConversationMenuToggle').click();
  const name=page.locator('#conversationTitle');await expect(name).toHaveText('Анна Морозова');
  expect(await name.evaluate(el=>el.scrollWidth<=el.clientWidth+1)).toBe(true);
  const size=await page.evaluate(()=>({doc:document.documentElement.scrollWidth,viewport:innerWidth}));expect(size.doc).toBeLessThanOrEqual(size.viewport+1);
  fs.mkdirSync('manager2-artifacts',{recursive:true});await page.screenshot({path:`manager2-artifacts/${browserName}-${width}-trip-dialogue.png`,animations:'disabled'});
  clean(state);
});

test('current-trip panel closes on conversation and account change, ignores late profile reads',async({page})=>{
  await page.setViewportSize({width:390,height:900});const state=await setup(page,{profilePanel:true});
  await expect(page.locator('#m2CurrentTrip strong')).toHaveText('Семейная поездка в Турцию');
  await page.getByRole('button',{name:'Условия поездки',exact:true}).click();
  await expect(page.frameLocator('#m2RequestProfilePanel iframe').locator('#profileLabel')).toHaveValue('Семейная поездка в Турцию');
  await page.locator('#m2RequestProfilePanel').evaluate(el=>el.close());
  let release,entered;const waiting=new Promise(resolve=>{entered=resolve});
  state.setProfileHandler(async data=>{if(data.action!=='get'||data.conversation_id!==11)return;entered();await new Promise(resolve=>{release=resolve});return {body:{ok:true,viewer_id:7,conversation_id:11,profile:{label:'OLD PRIVATE TRIP',status:'fresh'}}};});
  await page.evaluate(()=>{WorkspaceV2.S.detail.messages.push({id:999,text:'Уточнение'});WorkspaceV2RequestProfile.sync()});await waiting;
  await page.evaluate(()=>WorkspaceV2Conversation.open(12));release();
  await expect(page.locator('#conversationTitle')).toHaveText('Алексей Иванов');
  await expect(page.locator('#m2CurrentTrip strong')).toHaveText('Другая поездка');
  await expect(page.locator('#m2RequestProfilePanel iframe')).toHaveCount(0);
  await expect(page.locator('body')).not.toContainText('OLD PRIVATE TRIP');
  await page.getByRole('button',{name:'Условия поездки',exact:true}).click();
  await expect(page.locator('#m2RequestProfilePanel')).toBeVisible();
  await page.evaluate(()=>WorkspaceV2.showAuthRecovery('Сессия изменилась'));
  await expect(page.locator('#m2RequestProfilePanel iframe')).toHaveCount(0);await expect(page.locator('#m2CurrentTrip')).not.toBeVisible();
  clean(state);
});

test('current-trip panel retains unsaved profile and composer and consumes browser Back once',async({page})=>{
  await page.setViewportSize({width:390,height:900});const state=await setup(page,{profilePanel:true});
  await page.locator('#replyText').fill('Не потерять этот ответ');
  await page.evaluate(()=>{WorkspaceV2.S.detail.conversation.display_name='Александра Константиновна Дмитриевская';WorkspaceV2Conversation.renderHeader(WorkspaceV2.S.detail.conversation)});
  const name=page.locator('#conversationTitle');await expect(name).toContainText('Александра');
  expect(await name.evaluate(el=>el.scrollWidth<=el.clientWidth+1)).toBe(true);
  expect(await name.evaluate(el=>el.scrollHeight<=el.clientHeight+1)).toBe(true);
  fs.mkdirSync('manager2-artifacts',{recursive:true});await page.screenshot({path:'manager2-artifacts/long-client-name-mobile.png',animations:'disabled'});
  const head=await page.locator('.conversationHead').boundingBox();expect(head.height).toBeLessThan(100);
  await page.getByRole('button',{name:'Условия поездки',exact:true}).click();
  const profile=page.frameLocator('#m2RequestProfilePanel iframe');await expect(profile.locator('#profileLabel')).toBeVisible();
  await profile.locator('#profileLabel').fill('Ещё не сохранённая поездка');
  await expect(page.locator('.m2RequestProfileState')).toContainText('Есть изменения');
  await page.goBack();await expect(page.locator('#m2RequestProfilePanel')).not.toBeVisible();
  expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(11);
  expect(await page.evaluate(()=>WorkspaceV2Mobile.getScreen())).toBe('conversation');
  await expect(page.locator('#replyText')).toHaveValue('Не потерять этот ответ');
  await page.getByRole('button',{name:'Условия поездки',exact:true}).click();
  await expect(profile.locator('#profileLabel')).toHaveValue('Ещё не сохранённая поездка');
  await page.getByRole('button',{name:'Готово',exact:true}).click();
  expect(state.calls.filter(c=>c.action==='save'&&c.path==='/manager/request-profile-api.php')).toHaveLength(0);
  await page.locator('#conversationTitle').click();
  await expect(page.locator('[data-request-profile] a')).toBeVisible();await page.locator('[data-request-profile] a').click();
  await expect(profile.locator('#profileLabel')).toHaveValue('Ещё не сохранённая поездка');expect(page.context().pages()).toHaveLength(1);
  await page.getByRole('button',{name:'Готово',exact:true}).click();await page.locator('#mobileLeadClose').click();
  await expect(page.locator('#replyText')).toHaveValue('Не потерять этот ответ');
  await page.locator('#m2ConversationMenuToggle').click();await expect(page.locator('.m2ConversationMenu #pwGroupOpen')).toBeVisible();
  await page.locator('#pwGroupOpen').click();await expect(page.locator('#pwDialog')).toBeVisible();
  await page.keyboard.press('Escape');await expect(page.locator('#pwDialog')).not.toBeVisible();
  await expect(page.locator('#pwGroupOpen')).toBeFocused();clean(state);
});

test('current-trip panel rejects forged child notifications and unexpected account identity',async({page})=>{
  const state=await setup(page,{profilePanel:true,readiness:true});
  await expect(page.locator('#m2CurrentTrip strong')).toHaveText('Семейная поездка в Турцию');
  await page.getByRole('button',{name:'Условия поездки',exact:true}).click();
  await expect(page.frameLocator('#m2RequestProfilePanel iframe').locator('#profileLabel')).toBeVisible();
  await page.evaluate(()=>{const old=WorkspaceV2AiDraft.resetSession;window.profileResetCount=0;WorkspaceV2AiDraft.resetSession=(...args)=>{window.profileResetCount++;return old(...args)};const f=document.querySelector('#m2RequestProfilePanel iframe'),panel=new URL(f.src).searchParams.get('panel');window.postMessage({type:'anytour.request-profile',action:'saved',panel,conversation_id:11,viewer_id:7},location.origin)});
  await page.frameLocator('#m2RequestProfilePanel iframe').locator('body').evaluate(()=>parent.postMessage({type:'anytour.request-profile',action:'saved',panel:'f'.repeat(32),conversation_id:11,viewer_id:7},location.origin));
  await expect.poll(()=>page.evaluate(()=>window.profileResetCount)).toBe(0);
  await page.getByRole('button',{name:'Готово',exact:true}).click();
  state.setProfileHandler(async data=>data.action==='identity'?{body:{ok:true,viewer_id:8,csrf:'other-account'}}:{body:{ok:true,viewer_id:8,conversation_id:11,profile:{label:'PRIVATE OTHER ACCOUNT',status:'fresh'}}});
  await page.getByRole('button',{name:'Условия поездки',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.authExpired)).toBe(true);
  await expect(page.locator('#m2RequestProfilePanel iframe')).toHaveCount(0);await expect(page.locator('#m2CurrentTrip')).not.toBeVisible();
  await expect(page.locator('body')).not.toContainText('PRIVATE OTHER ACCOUNT');clean(state);
});

test('current-trip panel reconciles one uncertain profile write and invalidates the previous AI preview',async({page})=>{
  const state=await setup(page,{profilePanel:true,readiness:true});
  await expect(page.locator('#aiSuggestionDetails')).toBeVisible();await page.locator('#aiSuggestionDetails').click();await expect(page.locator('#aiDraftPanel')).toBeVisible();
  let committed=null;
  state.setProfileHandler(async data=>{
    if(data.action==='save'){committed=data;return {body:{ok:false,error:'network_error'}};}
    if(data.action==='operation')return {body:{ok:true,viewer_id:7,found:true,saved_version:2}};
    if(data.action==='get'&&committed)return {body:{ok:true,viewer_id:7,conversation_id:11,can_edit:true,context_sha256:'a'.repeat(64),profile:{version:2,status:'fresh',label:committed.label,boundary_message_id:1,fields:committed.fields},sources:[{id:1,sender_type:'customer',text:'Семейный отдых'}],history:[]}};
  });
  await page.getByRole('button',{name:'Условия поездки',exact:true}).click();const profile=page.frameLocator('#m2RequestProfilePanel iframe');
  await profile.locator('#profileLabel').fill('Подтверждённая запись');await profile.locator('#profileSave').click();
  await expect(profile.locator('#profileStatus')).toContainText('Сохранение не подтверждено');
  await profile.locator('#profileReconcile').click();await expect(profile.locator('#profileStatus')).toContainText('Запись подтверждена');
  await expect(page.locator('#aiDraftPanel')).not.toBeVisible();await expect(page.locator('#m2CurrentTrip strong')).toHaveText('Подтверждённая запись');
  expect(state.calls.filter(c=>c.path==='/manager/request-profile-api.php'&&c.action==='save')).toHaveLength(1);
  expect(state.calls.filter(c=>c.path==='/manager/request-profile-api.php'&&c.action==='operation')).toHaveLength(1);clean(state);
});

test('current-trip panel cannot open after a delayed permission read and mobile Back to inbox',async({page})=>{
  await page.setViewportSize({width:390,height:900});const state=await setup(page,{profilePanel:true});
  await expect(page.locator('#m2CurrentTrip strong')).toHaveText('Семейная поездка в Турцию');
  let release,entered;const waiting=new Promise(resolve=>{entered=resolve});
  state.setProfileHandler(async data=>{if(data.action!=='get')return;entered();await new Promise(resolve=>{release=resolve});return {body:{ok:true,viewer_id:7,conversation_id:11,profile:{status:'fresh',label:'Поздний разрешённый профиль'}}};});
  await page.getByRole('button',{name:'Условия поездки',exact:true}).click();await waiting;
  await page.goBack();await expect.poll(()=>page.evaluate(()=>WorkspaceV2Mobile.getScreen())).toBe('inbox');release();
  await expect(page.locator('#m2CurrentTrip strong')).toHaveText('Поздний разрешённый профиль');
  await expect(page.locator('#m2RequestProfilePanel')).not.toBeVisible();expect(await page.evaluate(()=>WorkspaceV2Mobile.getScreen())).toBe('inbox');clean(state);
});

test('current-trip panel clears private form on canonical conversation access denial',async({page})=>{
  const state=await setup(page,{profilePanel:true});await page.getByRole('button',{name:'Условия поездки',exact:true}).click();
  await expect(page.frameLocator('#m2RequestProfilePanel iframe').locator('#profileLabel')).toHaveValue('Семейная поездка в Турцию');
  await page.route('**/manager/api.php',async route=>{const data=route.request().postDataJSON();if(data?.action==='detail'&&data.conversation_id===11)return route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({ok:false,error:'forbidden'})});return route.fallback();});
  expect(await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible())).toBe(false);
  await expect(page.locator('#m2RequestProfilePanel iframe')).toHaveCount(0);await expect(page.locator('#m2RequestProfilePanel')).not.toBeVisible();await expect(page.locator('#m2CurrentTrip')).not.toBeVisible();
  expect(await page.locator('#m2CurrentTrip').textContent()).not.toContain('Семейная поездка');
  await page.evaluate(()=>WorkspaceV2RequestProfile.sync());await expect(page.locator('#m2CurrentTrip')).not.toBeVisible();clean(state);
});

test('current-trip panel rechecks profile freshness after editing an existing message ID',async({page})=>{
  const state=await setup(page,{profilePanel:true});await expect(page.locator('#m2CurrentTrip span')).toHaveText('Профиль сверен с перепиской');
  const before=state.calls.filter(c=>c.path==='/manager/request-profile-api.php'&&c.action==='get').length;
  state.setProfileHandler(async data=>data.action==='get'?{body:{ok:true,viewer_id:7,conversation_id:11,profile:{version:1,label:'Уточнённые основания',status:'source_changed'}}}:undefined);
  state.messages.get(11).at(-1).text='Тот же ID: питание изменилось, отель нужно пересмотреть';
  expect(await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible())).toBe(true);
  await expect(page.locator('#m2CurrentTrip span')).toHaveText('Основания изменились — условия нужно сверить');
  expect(state.calls.filter(c=>c.path==='/manager/request-profile-api.php'&&c.action==='get').length).toBeGreaterThan(before);clean(state);
});
