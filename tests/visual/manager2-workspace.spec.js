const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const {lifecycle}=require('../fixtures/manager_push_browser_fixture');
const path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
// Render the actual PHP shell; no copied HTML or production traffic.
const shell=execFileSync('php',['manager2/index.php'],{cwd:root,encoding:'utf8'});
const stages=[['new','Новый лид'],['working','В работе'],['selection','Подбираем'],['proposal','Предложение отправлено'],['sold','Продано']].map(([stage_key,display_name],i)=>({id:i+1,stage_key,display_name,sort_order:i}));
async function setup(page,{anonymous=false,otherOwner=false,role='manager',serviceEvents=false,taskResult='success',holdCreate=false,holdMetadata='',waitForInbox=true,savedFilters=null,replyAudio=false,tourChoices=null}={}){
  const pushProtocol=lifecycle(7);
  const calls=[],unexpected=[],errors=[],sends=[],pendingCreates=[],pendingMetadata=[];
  let authenticated=!anonymous,subscribed=false,currentTourChoices=tourChoices;
  let lifecycleHandler=null,stateHandler=null,readHandler=null;const conversationOverrides=new Map();
  const manager={id:7,role,display_name:'Мария',is_working:true};
  const tasks=[{id:21,title:'Проверить отели у моря в бюджете',status:'open',due_state:'today',due_at_utc:'2026-10-14 07:00:00',is_pinned:true,assigned_manager_name:'Мария'},{id:22,title:'Отправить подборку клиенту',status:'open',due_state:'overdue',due_at_utc:'2026-10-13 09:00:00',assigned_manager_name:'Мария'},{id:23,title:'Уточнить состав туристов',status:'done',due_state:'done',assigned_manager_name:'Мария'}];
  const conversation=id=>({id,manager_id:otherOwner?8:7,manager_name:otherOwner?'Людмила':'Мария',status:'manager',channel:'telegram',source_id:1,source_name:'AnyTour',display_name:id===11?'Анна Морозова':'Алексей Иванов',...conversationOverrides.get(id)});
  const messages=Array.from({length:35},(_,i)=>({id:i+1,sender_type:i%3===0?'manager':'customer',manager_id:i%3===0?7:null,text:i===34?'Посмотрите, пожалуйста, варианты у моря. Бюджет до 250 тысяч за всех.':'Сообщение '+(i+1)+'. Подбираем семейный отдых в Турции, обсуждаем отели и даты.',created_at:'2026-10-13 12:00:00'}));
  if(replyAudio)messages[0].attachments=[{type:'audio',name:'Тестовое аудио',url:replyEditorAudio()}];
  if(serviceEvents)messages.push({id:36,direction:'inbound',sender_type:'customer',text:'manager_after_tours',service_action:{type:'callback',label:'Клиент запросил менеджера после подбора туров',raw_payload:'manager_after_tours'},created_at:'2026-10-13 12:01:00'},{id:37,direction:'inbound',sender_type:'customer',text:'manager_after_tours',created_at:'2026-10-13 12:02:00'});
  const lead=id=>({ok:true,can_edit_pipeline:!otherOwner,pipeline:{stage:stages[1],stage_history:[],tags:[],outcome:{outcome:'open'}},tasks:tasks.map(t=>({...t})),trip:{city:'Москва',country:'Турция',date:'24 октября',nights:'7–9',adults:2,children:1,child_ages:'8',meal:'all_inclusive',stars:'4,5',budget:{max:250000,currency:'RUB',basis:'total'}},contact:{phone:'+7 999 123-45-67'},source:{project_label:'AnyTour',origin_label:'TG · реклама',channel:'telegram'},handoff:{technical_status:'manager',manager_name:conversation(id).manager_name}});
  const rows=()=>[11,12].map((id,i)=>({...conversation(id),can_edit_pipeline:!otherOwner,project_label:'AnyTour',origin_label:'TG · реклама',trip_summary:'Москва → Турция · 2 взрослых и ребёнок · 7–9 ночей',lead_stage:stages[1],lead_outcome:'open',unread_count:i?0:2,last_text:'Ждём варианты у моря',last_sender_type:'customer',last_message_at:'2026-10-13 12:00:00',next_task_id:21,next_task_title:tasks.find(t=>t.status==='open')?.title||'',next_task_due_state:'today',operational_task_due_state:'overdue',next_task_due_at_utc:'2026-10-14 07:00:00',next_task_pinned:1,contact_phone:'+7 999 123-45-67'}));
  await page.addInitScript(savedFilters=>{
    if(!sessionStorage.getItem('manager2-fixture-initialized')){sessionStorage.clear();sessionStorage.setItem('manager2-fixture-initialized','1')}
    if(savedFilters)sessionStorage.setItem('anytour.manager.workspace.filters.v1',JSON.stringify(savedFilters));
    window.fixtureWorkers=[];
    let subscription=null;
    const reg={scope:location.origin+'/manager/',active:{state:'activated',postMessage(){}},pushManager:{getSubscription:async()=>subscription,subscribe:async options=>{subscription={options,endpoint:'https://example.invalid/synthetic-push',toJSON:()=>({endpoint:'https://example.invalid/synthetic-push',keys:{p256dh:'synthetic',auth:'synthetic'}})};return subscription}},showNotification:async()=>{}};
    Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{getRegistration:async url=>{if(new URL(url,document.baseURI).href!==reg.scope)throw new Error('Noncanonical registration read');return reg},register:async(url,options)=>{fixtureWorkers.push({url:new URL(url,document.baseURI).pathname,scope:new URL(options.scope,document.baseURI).pathname});return reg},ready:Promise.resolve(reg),addEventListener(){}}});
    function FakeNotification(){}FakeNotification.permission='default';FakeNotification.requestPermission=async()=>{FakeNotification.permission='granted';return 'granted'};
    Object.defineProperty(window,'Notification',{configurable:true,value:FakeNotification});Object.defineProperty(window,'PushManager',{configurable:true,value:function(){}});
  },savedFilters);
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
    const req=route.request(),url=new URL(req.url());
    if(url.origin!==origin){unexpected.push(req.url());return route.abort()}
    if(req.method()==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
  // Optional portraits are absent for these synthetic clients; the dedicated avatar suite covers images/ACL.
  if(req.method()==='GET'&&url.pathname==='/manager/customer-avatar.php'){expect([...url.searchParams.keys()]).toEqual(['conversation_id','account','channel']);expect(url.searchParams.get('account')).toBe(String(manager.id));expect(url.searchParams.get('channel')).toBe(conversation(Number(url.searchParams.get('conversation_id'))).channel);expect(['11','12']).toContain(url.searchParams.get('conversation_id'));return route.fulfill({status:404,body:''})}
    if(url.pathname.startsWith('/manager/assets/')||url.pathname.startsWith('/manager2/assets/')){const asset=path.resolve(root,'.'+url.pathname);if(!asset.startsWith(root+path.sep))return route.abort();return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(asset)})}
    if(!url.pathname.endsWith('.php'))return route.continue();
    if(!url.pathname.startsWith('/manager/')){unexpected.push(req.method()+' '+url.pathname);return route.abort()}
    const data=req.method()==='POST'?req.postDataJSON()||{}:{};
    calls.push({path:url.pathname,...data});
    const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    if(readHandler&&data.action==='detail'&&['/manager/api.php','/manager/pipeline-api.php'].includes(url.pathname)){
      const result=await readHandler(url.pathname,data);if(result)return reply(result.body,result.status||200);
    }
    if(url.pathname==='/manager/quick-replies-api.php'&&data.action==='toolbar'){
      if(!authenticated)return reply({ok:false,error:'unauthorized'},401);
      return reply({ok:true,replies:[],version:'0'.repeat(64),max_replies:100});
    }
    // These synthetic conversations have no publication entry. Keep the real
    // optional protected read inside the fixture without allowing other actions.
    if(req.method()==='POST'&&url.pathname==='/manager/post-context-api.php'&&data.action==='detail'&&data.csrf==='synthetic-csrf'&&[11,12].includes(data.conversation_id)){
      if(!authenticated)return reply({ok:false,error:'unauthorized'},401);
      return reply({ok:true,conversation_id:data.conversation_id,context:{current:null,history:[]}});
    }
    if(url.pathname==='/manager/api.php'){
      if(data.action==='login'){authenticated=true;return reply({ok:true,manager,csrf:'synthetic-csrf',projects:[{project_key:'anytour',display_name:'AnyTour'}]})}
      if(!authenticated)return reply({ok:false,error:'unauthorized'},401);
      if(data.action==='me')return reply({ok:true,manager,csrf:'synthetic-csrf',projects:[{project_key:'anytour',display_name:'AnyTour'}]});
      if(data.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
      if(data.action==='counts')return reply({ok:true,counts:{waiting:{count:2,unread:2},mine:{count:2}}});
      if(data.action==='detail')return reply({ok:true,conversation:conversation(data.conversation_id),messages:messages.map(m=>({...m})),delivery_failure:null,...(currentTourChoices&&Number(data.conversation_id)===11?{tour_choices:currentTourChoices}:{})});
      if((['take','release','close','reopen'].includes(data.action)&&lifecycleHandler)||(data.action==='lifecycle_state'&&stateHandler)){
        expect(data.csrf).toBe('synthetic-csrf');const result=await (data.action==='lifecycle_state'?stateHandler:lifecycleHandler)(data);
        if(result.abort)return route.abort('failed').catch(()=>{});
        if(result.raw!==undefined)return route.fulfill({status:result.status||200,contentType:'application/json',body:result.raw});
        return reply(result.body,result.status||200);
      }
      if(data.action==='send'){sends.push(data);messages.push({id:100+sends.length,sender_type:'manager',manager_id:7,text:data.text,created_at:'2026-10-13 12:01:00'});return reply({ok:true})}
      if(data.action==='set_working'){manager.is_working=data.working;return reply({ok:true,manager})}
    }
  if(url.pathname==='/manager/personal-workspace-api.php'&&data.action==='snapshot')return reply(require('./fixtures/personal-workspace-default').emptyPersonalWorkspaceSnapshot());
    if(url.pathname==='/manager/pipeline-api.php'){
      if(data.csrf!=='synthetic-csrf')throw new Error('Canonical CSRF was not retained');
      if(['catalog','filter_options'].includes(data.action)&&data.action===holdMetadata){
        const status=await new Promise(resolve=>pendingMetadata.push({request:req,release:resolve}));
        if(status!==200){if(status===401)authenticated=false;return reply({ok:false,error:status===401?'unauthorized':'synthetic_metadata_unavailable'},status)}
      }
      if(data.action==='catalog')return reply({ok:true,stages,tags:[{id:1,display_name:'Семья'}],outcomes:{open:'В работе',won:'Продажа',lost:'Отказ'},close_reasons:{price:'Цена'}});
      if(data.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager]}});
      if(data.action==='list')return reply({ok:true,conversations:rows()});
      if(data.action==='detail')return reply(lead(data.conversation_id));
      if(data.action==='create_task'){if(holdCreate)await new Promise(resolve=>pendingCreates.push(resolve));tasks.push({id:30,title:data.title,due_at_utc:data.due_at,status:'open',due_state:'upcoming',assigned_manager_name:'Мария'});return reply({ok:true})}
      if(data.action==='set_task_completed'){if(taskResult==='unknown')return route.abort('failed');if(taskResult==='rejected')return reply({ok:false,error:'task_not_found'});tasks.find(t=>t.id===data.task_id).status=data.completed?'done':'open';return reply({ok:true})}
      if(data.action==='set_task_pinned'){tasks.find(t=>t.id===data.task_id).is_pinned=data.pinned;return reply({ok:true})}
      if(data.action==='update_task'){Object.assign(tasks.find(t=>t.id===data.task_id),{title:data.title,due_at_utc:data.due_at});return reply({ok:true})}
      if(['set_stage','set_tags','set_outcome'].includes(data.action))return reply({ok:true});
    }
    if(url.pathname==='/manager/push-status.php'){
      const result={ok:true,push_status:{is_working:manager.is_working,notification_path_usable:subscribed,healthy_subscription_count:subscribed?1:0,notification_path_reason:subscribed?'healthy_subscription':'no_subscription'}};
      if(req.method()==='POST'){
        expect(data.csrf).toBe('synthetic-csrf');if(data.endpoint)expect(data.endpoint).toBe('https://example.invalid/synthetic-push');result.current_connection=pushProtocol.current();
        result.current_subscription={notification_path_usable:subscribed,notification_path_reason:subscribed?'healthy_subscription':'no_subscription'};
      }
      return reply(result);
    }
    if(url.pathname==='/manager/push.php'){
      if(data.action==='connections')return reply({ok:true,connections:[],truncated:false});
      if(data.action==='enable_intent')return reply(pushProtocol.reply(data));
      if(url.searchParams.get('action')==='key')return reply({ok:true,public_key:Buffer.from([4,...Array(64).fill(1)]).toString('base64url')});
      if(data.action==='subscribe'){expect(data.csrf).toBe('synthetic-csrf');expect(data.subscription.endpoint).toBe('https://example.invalid/synthetic-push');subscribed=true;return reply({...pushProtocol.reply(data),push_status:{is_working:manager.is_working,notification_path_usable:true,healthy_subscription_count:1,notification_path_reason:'healthy_subscription'},current_subscription:{notification_path_usable:true,notification_path_reason:'healthy_subscription'}})}
    }
    if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
    unexpected.push(req.method()+' '+url.pathname+' '+data.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
  });
  await page.goto(origin+'/manager2/index.php?qa=1');
  if(!anonymous&&waitForInbox)await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  return {calls,unexpected,errors,sends,tasks,manager,setTourChoices:value=>{currentTourChoices=value},setConversation:(id,patch)=>conversationOverrides.set(id,{...conversationOverrides.get(id),...patch}),setReadHandler:fn=>{readHandler=fn},setLifecycleHandler:fn=>{lifecycleHandler=fn},setStateHandler:fn=>{stateHandler=fn},releaseCreate:()=>pendingCreates.shift()?.(),metadataPending:()=>pendingMetadata.length,
    async releaseMetadata(status=200){const pending=pendingMetadata.splice(0);pending.forEach(item=>item.release(status));await Promise.all(pending.map(async item=>{const response=await item.request.response();if(response)await response.finished()}))}};
}
async function openLead(page,id=11){
  if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox'))await page.locator('#mobileBack').click();
  await page.locator('#inboxList [data-conversation-id="'+id+'"] .m2RowOpen').click();
  await expect(page.locator('#conversationTitle')).toHaveText(id===11?'Анна Морозова':'Алексей Иванов');
}
async function showCard(page){if(!await page.locator('#leadZone').isVisible()||await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='lead')){await page.locator('#mobileLeadBtn').click();if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()))await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-mobile-screen','lead');else await expect(page.locator('#workspaceRoot')).toHaveClass(/m2ClientCardOpen/)}}
async function showChat(page){if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()==='lead')||await page.locator('#workspaceRoot').evaluate(el=>el.classList.contains('m2ClientCardOpen'))){await page.locator('#mobileLeadClose').click();if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()))await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-mobile-screen','conversation');else await expect(page.locator('#workspaceRoot')).not.toHaveClass(/m2ClientCardOpen/)}}
async function noOverflow(page){const size=await page.evaluate(()=>({w:document.documentElement.scrollWidth,h:document.documentElement.scrollHeight,vw:innerWidth,vh:innerHeight}));expect(size.w).toBeLessThanOrEqual(size.vw+1);expect(size.h).toBeLessThanOrEqual(size.vh+1)}
async function readable(page,selector){
  const ratio=await page.locator(selector).first().evaluate(el=>{
    const linear=v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4};
    const luminance=color=>{const c=color.match(/[\d.]+/g).slice(0,3).map(Number);return c.map(linear).reduce((s,x,i)=>s+x*[.2126,.7152,.0722][i],0)};
    const fg=luminance(getComputedStyle(el).color);let p=el,bg='rgb(255, 255, 255)';
    while(p){const color=getComputedStyle(p).backgroundColor;if(!color.startsWith('rgba')&&color!=='transparent'){bg=color;break}p=p.parentElement}
    const b=luminance(bg);return (Math.max(fg,b)+.05)/(Math.min(fg,b)+.05);
  });expect(ratio,selector+' text contrast').toBeGreaterThanOrEqual(4.5);
  if(/msgMeta|messageSender/.test(selector))expect(await page.locator(selector).first().evaluate(el=>Number(getComputedStyle(el).opacity))).toBe(1);
}
async function capture(page,browser,width,state){fs.mkdirSync('manager2-artifacts',{recursive:true});await page.screenshot({animations:'disabled',path:`manager2-artifacts/${browser}-${width}-${state}.png`})}

async function readyBeforeMetadata(page,state,action){
  await expect.poll(()=>state.metadataPending()).toBe(1);
  expect(state.calls.filter(call=>call.path==='/manager/pipeline-api.php'&&call.action===action)).toHaveLength(1);
  await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  await expect(page.locator('#inboxList')).toHaveAttribute('aria-busy','false');
  expect(state.metadataPending()).toBe(1);
  await openLead(page,11);
  await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('#replyText')).toBeEnabled();
  await expect(page.locator('#sendReply')).toBeEnabled();
  expect(state.metadataPending()).toBe(1);
  expect(state.calls.filter(call=>call.path==='/manager/api.php'&&call.action==='detail')).toHaveLength(1);
}

for(const width of [390,1440])test('Manager2 saved tour choice stays in the client card at '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});
  const tourChoices={offers:[{offer_id:501,delivered_at:'2026-10-21 12:00:00',liked_at:'2026-10-21 12:01:00',snapshot:{
    hotel:'Семейный отель у моря',departure:'Москва',date_from:'2026-11-03',date_to:'2026-11-10',nights:7,adults:2,child_ages:[5,5],
    room:'Семейный номер с видом на море',meal:'Всё включено',flights:['ZF 123 · 10:00','ZF 124 · 12:00'],amount:'185000.50',currency:'RUB',price_basis:'party',is_demo:true,price_verified:false,
    rationale:'Песчаный пляж',compromise:'До моря 400 м'}}],limited:false};
  const s=await setup(page);await openLead(page);await expect(page.locator('#leadCard .leadHero')).toHaveCount(1);
  await expect(page.locator('#leadCard [data-tour-choices]')).toHaveCount(0);
  s.setTourChoices(tourChoices);expect(await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible())).toBe(true);await showCard(page);
  const choices=page.locator('#leadCard [data-tour-choices]');await expect(choices).toBeVisible();
  await choices.locator('summary').click();
  await expect(choices.locator('summary .leadReadonly')).toHaveText('Демо · ');
  await expect(choices.locator('.leadDetailsBody > .leadReadonly')).toBeVisible();await expect(choices.locator('.leadDetailsBody > .leadReadonly')).toHaveText('Демонстрационный вариант');
  for(const value of ['185 000,50 ₽ за всех','03.11.2026 — 10.11.2026','Возраст детей: 5, 5','Семейный номер с видом на море','Всё включено','ZF 123 · 10:00','актуальность и наличие нужно проверить'])await expect(choices).toContainText(value);
  await expect(page.locator('#messages [data-tour-choices]')).toHaveCount(0);
  expect(await choices.evaluate(el=>el.scrollWidth<=el.clientWidth+1)).toBe(true);await noOverflow(page);
  await showChat(page);expect(await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible())).toBe(true);await showCard(page);
  await expect(choices.locator('details')).toHaveAttribute('open','');await expect(choices.locator('.leadDetailsBody > .leadReadonly')).toBeVisible();
  await choices.scrollIntoViewIfNeeded();await noOverflow(page);await capture(page,browserName,width,'tour-choice');
  await showChat(page);await openLead(page,12);await showCard(page);await expect(page.locator('#leadCard [data-tour-choices]')).toHaveCount(0);
  expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}]){
  for(const action of ['catalog','filter_options'])test(`Manager2 startup metadata ${action} does not hold reply readiness at ${viewport.width}x${viewport.height}`,async({page})=>{
    await page.setViewportSize(viewport);
    const s=await setup(page,{holdMetadata:action,waitForInbox:false});await readyBeforeMetadata(page,s,action);
    if(action==='catalog')await expect(page.locator('#leadStage')).toHaveCount(0);
    const draft='Черновик до загрузки справочника';await page.locator('#replyText').fill(draft);
    await page.locator('#replyFile').setInputFiles({name:'held-metadata.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment, never uploaded or sent')});
    await expect(page.locator('#mediaSelection')).toContainText('held-metadata.txt');
    const attachment=await page.locator('#mediaSelection').textContent();
    await page.locator('#replyText').focus();await expect(page.locator('#replyText')).toBeFocused();
    const before={lists:s.calls.filter(call=>call.action==='list').length,details:s.calls.filter(call=>call.path==='/manager/api.php'&&call.action==='detail').length};
    expect(before.lists).toBe(1);expect(s.metadataPending()).toBe(1);
    await s.releaseMetadata();
    await expect.poll(()=>page.evaluate(held=>held==='catalog'?WorkspaceV2.S.pipeline.stages.length>0:WorkspaceV2.S.filterOptionsReady,action)).toBe(true);
    if(action==='catalog'){
      await expect(page.locator('#leadStage option')).toHaveCount(stages.length);await expect(page.locator('#leadStage')).toHaveValue('working');
      await expect(page.locator('#leadTags input[value="1"]')).toHaveCount(1);
      await expect(page.locator('#leadOutcome option')).toHaveCount(3);await expect(page.locator('#leadOutcome')).toHaveValue('open');
    }
    await expect(page.locator('#replyText')).toHaveValue(draft);await expect(page.locator('#mediaSelection')).toHaveText(attachment);
    await expect(page.locator('#replyText')).toBeFocused();await expect(page.locator('#replyText')).toBeEnabled();
    expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(11);
    expect(s.calls.filter(call=>call.action==='list')).toHaveLength(before.lists);
    expect(s.calls.filter(call=>call.path==='/manager/api.php'&&call.action==='detail')).toHaveLength(before.details);
    expect(s.calls.filter(call=>call.path==='/manager/pipeline-api.php'&&call.action===action)).toHaveLength(1);
    expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);await noOverflow(page);
  });
}

for(const action of ['catalog','filter_options'])for(const status of [500,401])test(`Manager2 startup metadata ${action} late ${status} preserves canonical recovery`,async({page})=>{
  await page.setViewportSize({width:390,height:900});
  const s=await setup(page,{holdMetadata:action,waitForInbox:false});await readyBeforeMetadata(page,s,action);
  const draft='Точный черновик при ошибке справочника';await page.locator('#replyText').fill(draft);
  await page.locator('#replyFile').setInputFiles({name:'metadata-failure.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment, never uploaded or sent')});
  await expect(page.locator('#mediaSelection')).toContainText('metadata-failure.txt');
  const attachment=await page.locator('#mediaSelection').textContent();await page.locator('#replyText').focus();
  const lists=s.calls.filter(call=>call.action==='list').length;await s.releaseMetadata(status);
  if(status===401){
    await expect(page.locator('#managerAuthRecovery')).toBeVisible();await expect(page.locator('#composer')).not.toBeVisible();
    expect(await page.evaluate(()=>WorkspaceV2.S.authExpired)).toBe(true);
  }else{
    // Let the received JSON and the next rendered frames settle; no retry or
    // new metadata request is introduced by this failure control.
    await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
    await expect(page.locator('#managerAuthRecovery')).not.toBeVisible();await expect(page.locator('#composer')).toBeVisible();
    await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyText')).toBeFocused();
  }
  await expect(page.locator('#replyText')).toHaveValue(draft);await expect(page.locator('#mediaSelection')).toHaveText(attachment);
  expect(s.calls.filter(call=>call.action==='list')).toHaveLength(lists);
  expect(s.calls.filter(call=>call.path==='/manager/pipeline-api.php'&&call.action===action)).toHaveLength(1);
  expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

for(const stale of [false,true])test(`Manager2 startup metadata reconciles ${stale?'stale':'valid'} saved criteria once without reopening the reply`,async({page})=>{
  await page.setViewportSize({width:390,height:900});
  const savedFilters={project:'*',source:0,manager:'',stage:stale?'removed-stage':'working',tag:stale?999:1,outcome:stale?'obsolete':'open',task:'',search:''};
  const s=await setup(page,{holdMetadata:'catalog',waitForInbox:false,savedFilters});await readyBeforeMetadata(page,s,'catalog');
  const initial=s.calls.find(call=>call.action==='list');
  expect(initial).toMatchObject({lead_stage_key:savedFilters.stage,lead_tag_id:savedFilters.tag,lead_outcome:savedFilters.outcome});
  const draft='Ответ остаётся при сверке сохранённых условий';await page.locator('#replyText').fill(draft);
  await page.locator('#replyFile').setInputFiles({name:'saved-criteria.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic file, never uploaded or sent')});
  await expect(page.locator('#mediaSelection')).toContainText('saved-criteria.txt');
  const attachment=await page.locator('#mediaSelection').textContent();await page.locator('#replyText').focus();
  expect(s.calls.filter(call=>call.action==='list')).toHaveLength(1);expect(s.metadataPending()).toBe(1);
  await s.releaseMetadata();
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.pipeline.stages.length)).toBe(stages.length);
  await expect.poll(()=>s.calls.filter(call=>call.action==='list').length).toBe(stale?2:1);
  if(stale){
    expect(s.calls.filter(call=>call.action==='list').at(-1)).toMatchObject({lead_stage_key:'',lead_tag_id:0,lead_outcome:''});
    await expect(page.locator('#inboxList')).toHaveAttribute('aria-busy','false');
  }
  expect(await page.evaluate(()=>({stage:WorkspaceV2.S.leadStageFilter,tag:WorkspaceV2.S.leadTagFilter,outcome:WorkspaceV2.S.leadOutcomeFilter}))).toEqual(stale?{stage:'',tag:0,outcome:''}:{stage:'working',tag:1,outcome:'open'});
  await expect(page.locator('#replyText')).toHaveValue(draft);await expect(page.locator('#mediaSelection')).toHaveText(attachment);
  await expect(page.locator('#replyText')).toBeFocused();await expect(page.locator('#replyText')).toBeEnabled();
  expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(11);
  expect(s.calls.filter(call=>call.path==='/manager/api.php'&&call.action==='detail')).toHaveLength(1);
  expect(s.calls.filter(call=>call.path==='/manager/pipeline-api.php'&&call.action==='catalog')).toHaveLength(1);
  expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

test('Manager2 startup metadata preserves a blurred search draft until its debounce commits',async({page})=>{
  await page.setViewportSize({width:1440,height:900});
  await page.clock.install({time:new Date('2026-10-15T23:59:59Z')});
  await page.clock.pauseAt(new Date('2026-10-16T00:00:00Z'));
  const s=await setup(page,{holdMetadata:'catalog',waitForInbox:false});await readyBeforeMetadata(page,s,'catalog');
  const draft='Ответ текущему клиенту остаётся на месте';await page.locator('#replyText').fill(draft);
  await page.getByRole('button',{name:'Поиск диалогов',exact:true}).click();
  const search=page.locator('#leadSearch'),query='Новое условие до окончания загрузки';await search.fill(query);
  await page.locator('#replyText').focus();await expect(search).not.toBeFocused();
  expect(s.calls.filter(call=>call.action==='list')).toHaveLength(1);
  await s.releaseMetadata();await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.pipeline.stages.length)).toBe(stages.length);
  await expect(search).toHaveValue(query);expect(s.calls.filter(call=>call.action==='list')).toHaveLength(1);
  await page.clock.runFor(250);
  await expect.poll(()=>s.calls.filter(call=>call.action==='list').length).toBe(2);
  await expect(page.locator('#inboxList')).toHaveAttribute('aria-busy','false');
  expect(s.calls.filter(call=>call.action==='list').at(-1)).toMatchObject({search:query});
  await expect(search).toHaveValue(query);await expect(page.locator('#replyText')).toHaveValue(draft);
  await expect(page.locator('#replyText')).toBeFocused();expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(11);
  expect(s.calls.filter(call=>call.path==='/manager/api.php'&&call.action==='detail')).toHaveLength(1);
  expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

for(const width of [390,430,768,1440])test.describe('Manager2 '+width,()=>{
  test.use({viewport:{width,height:900}});
  test('navigation loads once, keeps drafts and filters coherent, and actions stay readable',async({page,browserName})=>{
    const s=await setup(page);await openLead(page);await page.locator('#replyText').fill('Черновик сохранён при навигации');
    const [chooser]=await Promise.all([page.waitForEvent('filechooser'),page.getByRole('button',{name:'Прикрепить файл',exact:true}).press('Enter')]);
    await chooser.setFiles({name:'synthetic.txt',mimeType:'text/plain',buffer:Buffer.from('Local fixture only')});
    if(width<=1100)await page.locator('#mobileBack').click();
    for(const target of ['sales','archive','sales','tasks','sales','dialogues']){
      const before=s.calls.filter(c=>c.action==='list').length;
      await page.locator('[data-m2-nav='+target+']').click();
      await expect.poll(()=>s.calls.filter(c=>c.action==='list').length).toBe(before+1);
      await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-m2-section',target);
      await expect(page.locator('#inboxList')).toHaveAttribute('aria-busy','false');
      expect(s.calls.filter(c=>c.action==='list').length).toBe(before+1);
      if(target!=='sales')expect(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('anytour.manager.workspace.filters.v1')).task)).toBe('');
      await noOverflow(page);
    }
    await page.locator('[data-m2-nav=archive]').click();if(!await page.locator('#filtersPanel').isVisible())await page.locator('#filtersToggle').click();await expect(page.locator('#filtersPanel')).toBeVisible();await page.locator('#leadTaskFilter').selectOption('today');
    await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-m2-section','dialogues');await expect(page.locator('.inboxHead .brand')).toHaveText('Диалоги');
    await page.locator('[data-m2-nav=tasks]').click();await expect(page.locator('.inboxHead .brand')).toHaveText('Мой план работы');await expect(page.locator('#m2SectionHint')).toContainText('диалоги с задачами и без них');
    await page.reload();await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);expect(await page.evaluate(()=>WorkspaceV2.S.leadTaskFilter)).toBe('');
    await openLead(page);await expect(page.locator('#replyText')).toHaveValue('Черновик сохранён при навигации');
    const quick=page.locator('.quickReplies button').first();await page.locator('#replyText').evaluate(el=>el.blur());await expect(quick).toBeVisible();const box=await quick.boundingBox(),parent=await page.locator('.quickReplies').boundingBox();expect(box.y+box.height).toBeLessThanOrEqual(parent.y+parent.height+1);
    expect(await page.locator('.leadTitle').first().evaluate(el=>parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(14);
    await capture(page,browserName,width,'readable-navigation');expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
  });
  test('compact header keeps native tools, visible return focus and transcript capacity',async({page,browserName})=>{
    const s=await setup(page);await openLead(page);
    const menu=page.locator('.m2ConversationMenu'),search=page.locator('#conversationSearchOpen'),next=page.locator('#nextWaitingConversation');
    await expect(page.locator('#conversationAvatar')).toBeVisible();
    await expect(page.locator('#conversationState')).not.toBeVisible();await expect(page.locator('#conversationMeta')).not.toBeVisible();
    expect((await page.locator('.conversationHead').boundingBox()).height).toBeLessThanOrEqual(63);
    await expect(menu).not.toHaveAttribute('open');await expect(search).not.toBeVisible();await expect(next).not.toBeVisible();
    expect(await page.locator('#messages').evaluate(el=>el.clientHeight)).toBeGreaterThanOrEqual(500);
    await page.evaluate(()=>{window.fixtureNativeConversationTools=['conversationSearchOpen','nextWaitingConversation'].map(id=>document.getElementById(id))});
    await page.locator('#replyText').fill('Черновик Анны для проверки компактного кабинета');
    await page.locator('#replyFile').setInputFiles({name:'compact-Anna.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic file, never sent')});
    const file=await page.locator('#mediaSelection').textContent();
    await menu.locator('summary').press('Enter');await expect(search).toBeVisible();await expect(next).toBeVisible();
    await search.click();await expect(menu).not.toHaveAttribute('open');await expect(page.locator('#conversationSearchInput')).toBeFocused();
    await page.locator('#conversationSearchInput').fill('у моря');await page.locator('#conversationSearchInput').press('Escape');
    await expect(search).toBeFocused();await expect(search).toBeVisible();await expect(menu).toHaveAttribute('open');
    await menu.locator('summary').press('Escape');await expect(menu.locator('summary')).toBeFocused();
    await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible());await openLead(page,12);await openLead(page,11);
    expect(await page.evaluate(()=>fixtureNativeConversationTools.every(el=>document.getElementById(el.id)===el&&el.isConnected))).toBe(true);
    await expect(search).toHaveCount(1);await expect(next).toHaveCount(1);
    await expect(page.locator('#replyText')).toHaveValue('Черновик Анны для проверки компактного кабинета');await expect(page.locator('#mediaSelection')).toHaveText(file);
    await page.evaluate(()=>WorkspaceV2Conversation.renderHeader({...WorkspaceV2.S.detail.conversation,display_name:'Анна · семейный отдых в Турции на октябрь · длинное рабочее имя клиента'}));
    const title=await page.locator('#conversationTitle').boundingBox(),head=await page.locator('.conversationHead').boundingBox(),toggle=await menu.locator('summary').boundingBox();
    expect(title.height).toBeGreaterThanOrEqual(36);expect(title.height).toBeLessThanOrEqual(40);expect(toggle.x+toggle.width).toBeLessThanOrEqual(head.x+head.width+1);await noOverflow(page);
    await page.setViewportSize({width,height:420});await page.locator('#replyText').fill(Array.from({length:8},(_,i)=>'Строка '+i+' — уточняем отдых').join('\n'));
    await noOverflow(page);expect(await page.locator('#messages').evaluate(el=>el.clientHeight)).toBeGreaterThanOrEqual(40);
    for(const id of ['replyText','sendReply']){const box=await page.locator('#'+id).boundingBox();expect(box.y).toBeGreaterThanOrEqual(0);expect(box.y+box.height).toBeLessThanOrEqual(421)}
    await menu.locator('summary').click();await search.click();await expect(page.locator('#conversationSearchInput')).toBeFocused();
    expect(await page.locator('#messages').evaluate(el=>el.clientHeight)).toBeGreaterThanOrEqual(40);
    await page.locator('#conversationSearchInput').press('Escape');await expect(search).toBeFocused();await expect(search).toBeVisible();
    await menu.locator('summary').press('Escape');await capture(page,browserName,width,'compact-header-short');
    await page.setViewportSize({width,height:900});await showCard(page);
    const service=page.locator('.leadDetails').filter({has:page.locator('summary',{hasText:'Источник и служебная информация'})});await expect(service).not.toHaveAttribute('open');await service.locator('summary').click();await expect(service).toContainText('Мария');await expect(service).toContainText('TG · реклама');
    expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
  });
  test('typed service events stay separate from literal customer messages',async({page,browserName})=>{
    const s=await setup(page,{serviceEvents:true});await openLead(page);
    const event=page.locator('[data-scroll-message-key="36"]'),literal=page.locator('[data-scroll-message-key="37"]');
    await expect(event).toHaveClass('msg serviceAction');await expect(event.locator('.msgBody')).toHaveText('Клиент запросил менеджера после подбора туров');await expect(literal).toHaveClass('msg customer');await expect(literal.locator('.msgBody')).toHaveText('manager_after_tours');
    await event.locator('summary').click();await expect(event.locator('code')).toHaveText('manager_after_tours');await readable(page,'.serviceAction .msgBody');await noOverflow(page);await capture(page,browserName,width,'service-events');expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
  });
  test('actual shell boots, preserves canonical paths, compact client card and responsive navigation',async({page,browserName})=>{
    const s=await setup(page);await expect(page).toHaveURL(origin+'/manager2/?qa=1');
    await noOverflow(page);await capture(page,browserName,width,'inbox');
    expect(await page.evaluate(()=>fixtureWorkers)).toEqual([{url:'/manager/sw.js',scope:'/manager/'}]);
    await openLead(page);if(width<=1100){const left=width<=740?0:64;await expect.poll(async()=>Math.abs((await page.locator('#conversationZone').boundingBox()).x-left)).toBeLessThanOrEqual(1);const box=await page.locator('#conversationZone').boundingBox();expect(box.x+box.width).toBeLessThanOrEqual(width+1);expect(box.width).toBeGreaterThanOrEqual(width-left-1)}await expect(page.locator('#composer')).toBeVisible();await readable(page,'.msg.manager .msgBody');await readable(page,'.msg.customer .msgBody');await readable(page,'.msg.manager .msgMeta');await readable(page,'.msg.customer .messageSender');expect(await page.locator('.m2NotificationDot').evaluate(el=>parseFloat(getComputedStyle(el).width))).toBeLessThanOrEqual(8);await noOverflow(page);await capture(page,browserName,width,'conversation');
    const gap=await page.locator('#messages').evaluate(el=>el.scrollHeight-el.clientHeight-el.scrollTop);expect(gap).toBeLessThanOrEqual(2);
    await showCard(page);await expect(page.locator('.m2Budget strong')).toHaveText('до 250 000 ₽');await readable(page,'.m2Budget strong');
    if(width<=1100){await expect(page.locator('#m2ClientName')).toHaveText('Анна Морозова');const box=await page.locator('#leadZone').boundingBox();expect(box.y).toBeGreaterThanOrEqual(0);expect(box.y+box.height).toBeLessThanOrEqual(901);}
    await expect(page.locator('.m2ExtraUrgency')).toHaveText('Ещё просроченных задач: 1');
    await expect(page.locator('.m2AllTasks')).not.toHaveAttribute('open');await expect(page.locator('[data-task-toggle="22"]')).not.toBeVisible();
    await page.locator('.m2AllTasks>summary').click();await expect(page.locator('[data-task-toggle="22"]')).toBeVisible();
    await page.locator('.m2AllTasks>summary').click();await capture(page,browserName,width,'client');await noOverflow(page);
    await showChat(page);await page.locator('#replyText').fill('Черновик для Анны');await openLead(page,12);await page.locator('#replyText').fill('Для Алексея');await openLead(page,11);await expect(page.locator('#replyText')).toHaveValue('Черновик для Анны');
    await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toHaveText('Отправлено');expect(s.sends).toHaveLength(1);expect(s.sends[0]).toMatchObject({conversation_id:11,text:'Черновик для Анны',csrf:'synthetic-csrf'});
    await page.getByLabel('Действия с диалогом',{exact:true}).press('Enter');await expect(page.getByRole('button',{name:'Закрыть обращение',exact:true})).toBeVisible();await page.getByLabel('Действия с диалогом',{exact:true}).press('Escape');await expect(page.getByRole('button',{name:'Закрыть обращение',exact:true})).not.toBeVisible();
    await noOverflow(page);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
  });
  test('task mutations, keyboard-height composer, sales controls and notification dialog remain usable',async({page,browserName})=>{
    const s=await setup(page);await openLead(page);await showCard(page);
    await page.locator('.m2TaskCreate>summary').click();await page.locator('#leadTaskTitle').fill('Черновик задачи');
    await page.locator('.m2TaskCreate>summary').click();await expect(page.locator('.m2TaskCreate')).toHaveAttribute('open');
    await showChat(page);if(width<=1100)await page.locator('#mobileBack').click();await page.locator('#inboxList [data-conversation-id="12"] .m2RowOpen').click();
    await expect(page.locator('#leadTaskTitle')).toBeVisible();await expect(page.locator('#leadTaskTitle')).toHaveValue('Черновик задачи');await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');
    await page.locator('.m2TaskCancel').click();await page.locator('.m2TaskCreate>summary').click();await page.locator('#leadTaskTitle').fill('Новая рабочая задача');await page.locator('#leadTaskDue').fill('2026-10-15T10:00');await page.locator('#leadTaskAdd').click();
    await expect.poll(()=>s.calls.filter(c=>c.action==='create_task').length).toBe(1);
    expect(s.calls.find(c=>c.action==='create_task')).toMatchObject({conversation_id:11,title:'Новая рабочая задача',due_at:'2026-10-15T07:00:00.000Z'});
    await expect(page.locator('.m2AllTasks>summary')).toHaveText('Все задачи · 4');
    await page.locator('[data-task-toggle="21"]').check();await expect.poll(()=>s.tasks[0].status).toBe('done');
    await showChat(page);await page.locator('#replyText').fill('Длинный ответ\nВторая строка\nТретья строка');await page.setViewportSize({width,height:420});
    await noOverflow(page);const send=await page.locator('#sendReply').boundingBox();expect(send.y+send.height).toBeLessThanOrEqual(421);await page.setViewportSize({width,height:900});
    if(width<=1100)await page.locator('#mobileBack').click();
    await page.locator('[data-m2-nav=sales]').click();await expect(page.locator('.kanbanCard')).toHaveCount(2);await expect(page.locator('.m2BoardActions')).toHaveCount(2);await noOverflow(page);await capture(page,browserName,width,'sales');
    await page.locator('.m2BoardActions>summary').first().click();await expect(page.locator('.kanbanStageSelect').first()).toBeVisible();
    await page.locator('[data-m2-nav=tasks]').click();await expect(page.locator('.m2TaskFilters')).toHaveAttribute('open');await expect(page.locator('#leadTaskFilter')).toHaveValue('');
    await page.locator('#m2NotificationButton').click();await expect(page.locator('#m2Notifications')).toBeVisible();await expect(page.locator('#notificationStatus')).toBeVisible();await page.locator('[data-enable-push]').click();
    await expect(page.locator('#notificationStatus')).toHaveClass(/\bok\b/);await expect(page.locator('#notificationStatus')).toContainText('Уведомления включены');expect(s.calls.filter(c=>c.action==='subscribe')).toHaveLength(1);await capture(page,browserName,width,'notifications');
    await page.locator('#m2NotificationsClose').click();await expect(page.locator('#m2NotificationButton')).toBeFocused();expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
  });
});
test('Manager2 anonymous entry uses the canonical login and does not expose another manager composer',async({page})=>{
  const s=await setup(page,{anonymous:true,otherOwner:true});await expect(page.locator('#managerAuthRecovery')).toBeVisible();
  await expect(page.locator('#managerAuthRecovery h2')).toHaveText('Вход для менеджера');await expect(page.locator('#managerAuthSubmit')).toHaveText('Войти');
  await page.locator('#managerAuthLogin').fill('synthetic-login');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  await openLead(page);await expect(page.locator('#composer')).not.toBeVisible();await expect(page.locator('#composerLocked')).toContainText('Людмила');expect(s.calls.find(c=>c.action==='login').path).toBe('/manager/api.php');expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

test('Manager2 pending task creation protects the draft and Cancel until settlement',async({page})=>{
  await page.setViewportSize({width:390,height:900});const s=await setup(page,{holdCreate:true});await openLead(page);await showCard(page);await page.locator('.m2TaskCreate>summary').click();await page.locator('#leadTaskTitle').fill('Подтверждаемая задача A');await page.locator('#leadTaskAdd').click();
  await expect.poll(()=>s.calls.filter(c=>c.action==='create_task').length).toBe(1);await expect(page.locator('#leadTaskTitle')).toBeDisabled();await expect(page.locator('#leadTaskDue')).toBeDisabled();await expect(page.locator('.m2TaskCancel')).toBeDisabled();await expect(page.locator('#leadTaskAdd')).toBeDisabled();
  s.releaseCreate();await expect(page.locator('#leadTaskTitle')).toBeEnabled();await expect(page.locator('.m2TaskCancel')).toBeEnabled();await expect(page.locator('#leadTaskTitle')).toHaveValue('');await page.locator('#leadTaskTitle').fill('Новый черновик B');
  expect(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('workspaceV2.taskDraft.7.11')).title)).toBe('Новый черновик B');expect(s.calls.filter(c=>c.action==='create_task')).toHaveLength(1);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

for(const taskResult of ['success','rejected','unknown'])test('Manager2 task feedback remains truthful: '+taskResult,async({page,browserName})=>{
  await page.setViewportSize({width:390,height:900});const s=await setup(page,{taskResult});await openLead(page);await showCard(page);await page.locator('[data-task-toggle="21"]').click();
  const status=page.locator('[data-task-action-status]');await expect(status).toContainText(taskResult==='success'?'выполнена':taskResult==='rejected'?'Не удалось изменить состояние':'Не удалось подтвердить изменение');
  if(taskResult==='rejected')await expect(page.locator('[data-task-toggle="21"]')).not.toBeChecked();
  if(taskResult==='unknown')expect(await page.locator('[data-task-toggle="21"]').evaluate(el=>el.indeterminate)).toBe(true);
  await page.evaluate(()=>WorkspaceV2Tasks.render(document.getElementById('leadTasksBody'),{tasks:WorkspaceV2.S.detail.lead.tasks,canEdit:true,conversationId:WorkspaceV2.S.current}));await expect(status).toContainText(taskResult==='success'?'выполнена':taskResult==='rejected'?'Не удалось изменить состояние':'Не удалось подтвердить изменение');
  expect(s.calls.filter(c=>c.action==='set_task_completed')).toHaveLength(1);await noOverflow(page);await capture(page,browserName,390,'task-'+taskResult);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

for(const width of [390,1440])test('Manager2 admin settings remain accessible at '+width,async({page})=>{
  await page.setViewportSize({width,height:900});const s=await setup(page,{role:'admin'});
  await page.locator('#m2NotificationButton').click();
  await expect(page.locator('#adminLink')).toBeVisible();
  expect(await page.locator('#adminLink').evaluate(el=>new URL(el.getAttribute('href'),document.baseURI).pathname)).toBe('/manager2/admin.php');
  expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

const lifecycleMutations=s=>s.calls.filter(c=>c.path==='/manager/api.php'&&['take','release','close','reopen','send'].includes(c.action));
const lifecycleNotice=page=>page.locator('#conversationLifecycleStatus');
async function lifecycleAction(page,name){const button=page.getByRole('button',{name,exact:true});if(!await button.isVisible())await page.locator('#m2ConversationMenuToggle').click();await button.click()}
async function lifecycleDraft(page){
  await page.locator('#replyText').fill('Неотправленный ответ после отказа');
  await page.locator('#replyFile').setInputFiles({name:'lifecycle-draft.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic fixture, never uploaded')});
  await page.evaluate(()=>{window.lifecycleHeldFile=document.getElementById('replyFile').files[0];window.lifecycleHeldMessage=document.querySelector('#messages .msg');const reply=document.getElementById('replyText');reply.setSelectionRange(4,12)});
  await page.locator('#messages').hover();await page.mouse.wheel(0,-1200);await expect.poll(()=>page.evaluate(()=>WorkspaceV2Jump.isFollowingLatest())).toBe(false);
}
async function retainedLifecycleDraft(page){
  await expect(page.locator('#replyText')).toHaveValue('Неотправленный ответ после отказа');await expect(page.locator('#mediaSelection')).toContainText('lifecycle-draft.txt');
  expect(await page.evaluate(()=>({file:document.getElementById('replyFile').files[0]===window.lifecycleHeldFile,message:document.querySelector('#messages .msg')===window.lifecycleHeldMessage,start:document.getElementById('replyText').selectionStart,end:document.getElementById('replyText').selectionEnd}))).toEqual({file:true,message:true,start:4,end:12});
}
for(const width of [390,430,768,1440])test('Manager2 lifecycle refusal and UNKNOWN preserve reply at '+width+'x420',async({page,browserName})=>{
  await page.setViewportSize({width,height:420});const s=await setup(page);await openLead(page);await lifecycleDraft(page);await capture(page,browserName,width,'lifecycle-before-action');
  s.setLifecycleHandler(async()=>({body:{ok:false,error:'not_owned',error_message:'Серверная причина'}}));s.setStateHandler(async()=>({body:{ok:false,error:'synthetic_read_failed'},status:500}));
  const reads=s.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail').length;
  await lifecycleAction(page,'Передать боту');
  await expect(page.locator('#conversationLoadStatus')).not.toContainText('Передаём диалог');await capture(page,browserName,width,'lifecycle-refused');
  await expect(lifecycleNotice(page)).toContainText('не назначен вам');await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#sendReply')).toBeDisabled();await retainedLifecycleDraft(page);
  expect(s.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail')).toHaveLength(reads);expect(s.calls.some(c=>c.action==='lifecycle_state')).toBe(false);
  await page.locator('#replyText').press('Control+Enter');expect(lifecycleMutations(s)).toHaveLength(1);
  await lifecycleNotice(page).getByRole('button',{name:'Проверить состояние',exact:true}).click();await expect(lifecycleNotice(page)).toContainText('Не удалось проверить состояние');await expect(lifecycleNotice(page)).toContainText('не назначен вам');await retainedLifecycleDraft(page);
  s.setStateHandler(async()=>({body:{ok:true,conversation_state:{id:11,status:'manager',manager_id:7}}}));await lifecycleNotice(page).getByRole('button',{name:'Проверить состояние',exact:true}).click();
  await expect(lifecycleNotice(page)).toBeHidden();await expect(page.locator('#sendReply')).toBeEnabled();await retainedLifecycleDraft(page);expect(s.calls.filter(c=>c.path==='/manager/api.php'&&c.action==='detail')).toHaveLength(reads);
  s.setLifecycleHandler(async()=>({body:{ok:false,error:'closed'},status:500}));await lifecycleAction(page,'Закрыть обращение');await expect(lifecycleNotice(page)).toContainText('Результат действия не подтверждён');await expect(lifecycleNotice(page)).not.toContainText('уже закрыт');await retainedLifecycleDraft(page);await capture(page,browserName,width,'lifecycle-unknown');await noOverflow(page);
  const messageTop=await page.locator('#messages').evaluate(el=>el.scrollTop);await page.locator('#replyText').focus();
  await lifecycleNotice(page).getByRole('button',{name:'Проверить состояние',exact:true}).click();await expect(lifecycleNotice(page)).toBeHidden();await retainedLifecycleDraft(page);expect(await page.locator('#messages').evaluate(el=>el.scrollTop)).toBe(messageTop);
  await expect(page.locator('#conversationLoadStatus')).toContainText('не подтверждение предыдущего действия');await capture(page,browserName,width,'lifecycle-checked');await noOverflow(page);
  expect(lifecycleMutations(s).map(c=>c.action)).toEqual(['release','close']);expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
test('Manager2 lifecycle take/reopen use canonical current state without optimistic ownership',async({page,browserName})=>{
  await page.setViewportSize({width:430,height:900});const s=await setup(page);s.setConversation(11,{status:'waiting_manager',manager_id:null,manager_name:null});await openLead(page);
  s.setLifecycleHandler(async()=>({body:{ok:false,error:'closed'}}));s.setStateHandler(async()=>({body:{ok:true,conversation_state:{id:11,status:'closed',manager_id:8}}}));
  await page.getByRole('button',{name:'Взять',exact:true}).click();await expect(lifecycleNotice(page)).toContainText('уже закрыт');await expect(page.locator('#conversationState')).toContainText('Ждёт менеджера');
  await lifecycleNotice(page).getByRole('button',{name:'Проверить состояние',exact:true}).click();await expect(page.locator('#conversationState')).toContainText('Диалог закрыт');await expect(page.locator('#composer')).toBeHidden();
  s.setLifecycleHandler(async()=>{s.setConversation(11,{status:'manager',manager_id:7,manager_name:'Мария'});return{body:{ok:true}}});await page.getByRole('button',{name:'Переоткрыть',exact:true}).click();await expect(page.locator('#composer')).toBeVisible();await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#conversationLoadStatus')).toContainText('Диалог снова в работе');
  await capture(page,browserName,430,'lifecycle-reopened');expect(lifecycleMutations(s).map(c=>c.action)).toEqual(['take','reopen']);expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
test('Manager2 lifecycle native abort and state-read timeout remain explicit without replay',async({page})=>{
  await page.clock.install();await page.setViewportSize({width:390,height:420});const s=await setup(page);await openLead(page);await lifecycleDraft(page);
  let release;const held=new Promise(resolve=>{release=resolve});s.setLifecycleHandler(()=>held);
  await lifecycleAction(page,'Закрыть обращение');await expect.poll(()=>lifecycleMutations(s).length).toBe(1);await expect(page.locator('#replyText')).toBeDisabled();
  await page.clock.fastForward(20001);await expect(lifecycleNotice(page)).toContainText('Результат действия не подтверждён');await expect(page.locator('#replyText')).toBeEnabled();await retainedLifecycleDraft(page);release({abort:true});
  let releaseRead;const heldRead=new Promise(resolve=>{releaseRead=resolve});s.setStateHandler(()=>heldRead);await lifecycleNotice(page).getByRole('button',{name:'Проверить состояние',exact:true}).click();await expect.poll(()=>s.calls.filter(c=>c.action==='lifecycle_state').length).toBe(1);
  await page.clock.fastForward(20001);await expect(lifecycleNotice(page)).toContainText('Не удалось проверить состояние');await expect(lifecycleNotice(page).getByRole('button',{name:'Проверить состояние',exact:true})).toBeEnabled();await retainedLifecycleDraft(page);releaseRead({abort:true});
  s.setStateHandler(async()=>({body:{ok:true,conversation_state:{id:11,status:'manager',manager_id:7}}}));await lifecycleNotice(page).getByRole('button',{name:'Проверить состояние',exact:true}).click();await expect(page.locator('#sendReply')).toBeEnabled();expect(lifecycleMutations(s)).toHaveLength(1);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
test('Manager2 lifecycle late completion respects desktop navigation and mobile Back',async({page})=>{
  await page.setViewportSize({width:430,height:900});const s=await setup(page);await openLead(page,12);await page.locator('#replyText').fill('Сохранённый ответ второму клиенту');await page.locator('#replyFile').setInputFiles({name:'B.txt',mimeType:'text/plain',buffer:Buffer.from('B fixture')});await openLead(page,11);
  let release;const held=new Promise(resolve=>{release=resolve});s.setLifecycleHandler(()=>held);await lifecycleAction(page,'Закрыть обращение');await expect.poll(()=>lifecycleMutations(s).length).toBe(1);
  await page.locator('#mobileBack').click();await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-mobile-screen','inbox');await openLead(page,12);release({body:{ok:true}});await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyText')).toHaveValue('Сохранённый ответ второму клиенту');await expect(page.locator('#mediaSelection')).toContainText('B.txt');await expect(page.locator('#conversationTitle')).toHaveText('Алексей Иванов');await expect(lifecycleNotice(page)).toHaveCount(0);expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});

const editorText=Array.from({length:12},(_,i)=>'Абзац '+(i+1)+'. Подобрали отдых у моря, уточните удобное время вылета.').join('\n');
function replyEditorAudio(){
  const size=8000*8,wav=Buffer.alloc(44+size,128);
  wav.write('RIFF');wav.writeUInt32LE(36+size,4);wav.write('WAVEfmt ',8);wav.writeUInt32LE(16,16);
  wav.writeUInt16LE(1,20);wav.writeUInt16LE(1,22);wav.writeUInt32LE(8000,24);wav.writeUInt32LE(8000,28);
  wav.writeUInt16LE(1,32);wav.writeUInt16LE(8,34);wav.write('data',36);wav.writeUInt32LE(size,40);
  return 'data:audio/wav;base64,'+wav.toString('base64');
}
async function editorReady(page){
  await expect(page.locator('#replyText')).toBeEnabled();
  await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.detail?.lead)).toBe(true);
  await expect(page.locator('.qrToolbarEmpty')).toHaveText('Добавьте свои быстрые ответы');
}
function holdConversationRead(state,owner,id){
  let release,entered=false;const pending=new Promise(resolve=>{release=resolve});
  const path=owner==='detail'?'/manager/api.php':'/manager/pipeline-api.php';
  state.setReadHandler((url,data)=>{if(!entered&&url===path&&data.conversation_id===id){entered=true;return pending}return null});
  return {entered:()=>entered,path,release:status=>release({status,body:{ok:false,error:status===401?'unauthorized':'synthetic_read_error'}})};
}
const conversationReads=state=>state.calls.filter(call=>call.action==='detail'&&['/manager/api.php','/manager/pipeline-api.php'].includes(call.path));
for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])for(const owner of ['detail','card','refresh']){
test(`Manager2 obsolete read owner ${owner} 401 preserves current reply ${viewport.width}x${viewport.height}`,async({page,browserName})=>{
  await page.setViewportSize(viewport);const state=await setup(page,{replyAudio:true});
  if(owner==='refresh')await openLead(page,11);
  const old=holdConversationRead(state,owner,11);
  if(owner==='refresh')await page.evaluate(()=>{void WorkspaceV2Conversation.refreshLeadData()});
  else await page.locator('#inboxList [data-conversation-id="11"] .m2RowOpen').click();
  await expect.poll(old.entered).toBe(true);
  if(owner==='card')await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');
  await openLead(page,12);await editorReady(page);
  await page.locator('#replyText').fill('Ответ новому клиенту');
  // Observe the real File handed to the native preview, after the canonical
  // picker has cleared its input. An empty input is not File-identity proof.
  await page.evaluate(()=>{const create=URL.createObjectURL;window.readOwnerFiles=[];URL.createObjectURL=function(file){const url=create.call(this,file);readOwnerFiles.push({file,url});return url}});
  await page.locator('#replyFile').setInputFiles({name:'current-reply.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGOwbvr2HwAFYgKzn+LXXwAAAABJRU5ErkJggg==','base64')});
  expect(await page.evaluate(()=>readOwnerFiles.length===1&&readOwnerFiles[0].file instanceof File)).toBe(true);
  await expect.poll(()=>page.locator('#mediaSelection img').evaluate(img=>img.complete&&img.naturalWidth>0)).toBe(true);
  await editorGeometry(page);
  // Use the maintained history fixture's reading gesture and exact settled
  // position; native wheel momentum is not part of the read-owner contract.
  await page.locator('#messages').evaluate(box=>{box.dispatchEvent(new WheelEvent('wheel',{deltaY:-900,bubbles:true}));box.scrollTop=0});
  await expect.poll(()=>page.locator('#messages').evaluate(el=>el.scrollTop)).toBe(0);
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2Jump.isFollowingLatest())).toBe(false);
  await page.locator('#messages audio').evaluate(async audio=>{audio.muted=true;audio.loop=true;await audio.play()});
  await page.locator('#replyText').evaluate(reply=>{
    reply.focus({preventScroll:true});reply.setSelectionRange(4,12,'backward');
    const box=document.querySelector('#messages'),anchor=[...box.children].find(row=>row.getBoundingClientRect().bottom>box.getBoundingClientRect().top+1);
    window.readOwnerProof={reply,form:document.querySelector('#composer'),input:document.querySelector('#replyFile'),file:readOwnerFiles[0].file,url:readOwnerFiles[0].url,preview:document.querySelector('#mediaSelection .mediaSelectedItem'),anchor,offset:anchor.getBoundingClientRect().top-box.getBoundingClientRect().top,audio:box.querySelector('audio'),audioTime:box.querySelector('audio').currentTime,generation:WorkspaceV2.S.authGeneration};
  });
  const reads=conversationReads(state).length;
  const response=page.waitForResponse(r=>new URL(r.url()).pathname===old.path&&r.status()===401);
  old.release(401);await (await response).finished();
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  // A direct assertion makes the retained baseline fail on the actual false
  // login; readiness/file/audio assertions below are never bypassed.
  expect(await page.evaluate(()=>({expired:WorkspaceV2.S.authExpired,current:WorkspaceV2.S.current,generation:WorkspaceV2.S.authGeneration===readOwnerProof.generation}))).toEqual({expired:false,current:12,generation:true});
  await expect(page.locator('#managerAuthRecovery')).not.toBeVisible();await expect(page.locator('#composer')).toBeVisible();
  await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#sendReply')).toBeEnabled();
  await expect(page.locator('#replyText')).toHaveValue('Ответ новому клиенту');await expect(page.locator('#mediaSelection')).toContainText('current-reply.png');
  const retained=await page.evaluate(()=>{const p=readOwnerProof,reply=document.querySelector('#replyText'),box=document.querySelector('#messages'),audio=box.querySelector('audio');return {reply:reply===p.reply,form:document.querySelector('#composer')===p.form,input:document.querySelector('#replyFile')===p.input,file:readOwnerFiles.length===1&&readOwnerFiles[0].file===p.file&&document.querySelector('#mediaSelection img')?.src===p.url,preview:document.querySelector('#mediaSelection .mediaSelectedItem')===p.preview,focus:document.activeElement===reply,selection:[reply.selectionStart,reply.selectionEnd,reply.selectionDirection],anchor:p.anchor.isConnected&&Math.abs(p.anchor.getBoundingClientRect().top-box.getBoundingClientRect().top-p.offset)<=2,audio:audio===p.audio,playing:!audio.paused,progress:audio.currentTime>=p.audioTime-.05}});
  expect(retained).toEqual({reply:true,form:true,input:true,file:true,preview:true,focus:true,selection:[4,12,'backward'],anchor:true,audio:true,playing:true,progress:true});
  expect(conversationReads(state)).toHaveLength(reads);
  await capture(page,browserName,viewport.width,`read-owner-${owner}-${viewport.height}`);await noOverflow(page);
  expect(await page.evaluate(()=>WorkspaceV2Conversation.refreshLeadData())).toBe(true);
  expect(conversationReads(state)).toHaveLength(reads+1);
  await openLead(page,11);await openLead(page,12);
  await expect(page.locator('#replyText')).toHaveValue('Ответ новому клиенту');await expect(page.locator('#mediaSelection')).toContainText('current-reply.png');
  expect(state.sends).toEqual([]);expect(lifecycleMutations(state)).toEqual([]);expect(state.unexpected).toEqual([]);expect(state.errors).toEqual([]);
});
}
for(const owner of ['detail','card','refresh'])test(`Manager2 current read owner ${owner} 401 still requires same-account recovery`,async({page})=>{
  await page.setViewportSize({width:390,height:420});const state=await setup(page);await openLead(page,11);await editorReady(page);
  await page.locator('#replyText').fill('Сохранить при повторном входе');
  await page.locator('#replyFile').setInputFiles({name:'recovery.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic recovery file')});
  const current=holdConversationRead(state,owner,11);
  await page.evaluate(owner=>{if(owner==='refresh')void WorkspaceV2Conversation.refreshLeadData();else void WorkspaceV2Conversation.open(11,{preserveAttachment:true})},owner);
  await expect.poll(current.entered).toBe(true);current.release(401);
  await expect(page.locator('#managerAuthRecovery')).toBeVisible();await expect(page.locator('#composer')).not.toBeVisible();
  expect(await page.evaluate(()=>WorkspaceV2.S.authExpired)).toBe(true);
  state.setReadHandler(null);
  await page.locator('#managerAuthLogin').fill('synthetic');await page.locator('#managerAuthPassword').fill('synthetic-only');await page.locator('#managerAuthSubmit').click();
  await expect(page.locator('#managerAuthRecovery')).not.toBeVisible();await expect(page.locator('#replyText')).toBeEnabled();
  await expect(page.locator('#replyText')).toHaveValue('Сохранить при повторном входе');await expect(page.locator('#mediaSelection')).toContainText('recovery.txt');
  expect(state.sends).toEqual([]);expect(state.unexpected).toEqual([]);expect(state.errors).toEqual([]);
});
async function editorGeometry(page){
  // Read the settled layout, including intentional compact/full-width motion.
  // Two rendering frames flush the canonical input/ResizeObserver scheduling;
  // wait for actual transitions instead of a fixed animation timeout.
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  await expect.poll(()=>page.locator('#composer').evaluate(el=>el.getAnimations({subtree:true}).filter(animation=>['height','width','padding-bottom'].includes(animation.transitionProperty)&&animation.playState==='running').length)).toBe(0);
  return page.locator('#replyText').evaluate(el=>{
    const css=getComputedStyle(el),rect=el.getBoundingClientRect();
    return {height:rect.height,width:rect.width,lines:(rect.height-parseFloat(css.paddingTop)-parseFloat(css.paddingBottom)-parseFloat(css.borderTopWidth)-parseFloat(css.borderBottomWidth))/parseFloat(css.lineHeight)};
  });
}
for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}]){
test('Manager2 reply editor grows and preserves its native draft '+viewport.width+'x'+viewport.height,async({page,browserName})=>{
  await page.setViewportSize(viewport);const s=await setup(page,{replyAudio:viewport.width===1440});await openLead(page);await editorReady(page);
  const reply=page.locator('#replyText'),dialog=page.locator('#m2ReplyEditor');
  const empty=await editorGeometry(page);await reply.focus();const focused=await editorGeometry(page);
  const compact=await page.evaluate(()=>{const text=document.querySelector('#replyText').getBoundingClientRect(),actions=document.querySelector('#composer .composerActions').getBoundingClientRect();return {textRight:text.right,actionsLeft:actions.left,overlapY:text.bottom>actions.top&&text.top<actions.bottom}});
  expect(compact.textRight).toBeLessThanOrEqual(compact.actionsLeft+1);expect(compact.overlapY).toBe(true);
  await reply.fill('Здравствуйте!');const short=await editorGeometry(page);
  console.log(JSON.stringify({replyMinimum:browserName,width:viewport.width,height:viewport.height,empty,focused,short}));
  expect(empty.lines).toBeLessThanOrEqual(1.1);expect(focused.height).toBe(empty.height);expect(short.lines).toBeLessThanOrEqual(1.1);
  await reply.fill('Первая строка\nВторая строка');expect((await editorGeometry(page)).height).toBeGreaterThan(short.height+10);
  await reply.fill('');await editorGeometry(page);
  // Cross the native compact text edge by one glyph, without a newline. This
  // catches inherited grid/width rules that make the live field narrower than
  // its text measure and would hide the newly wrapped line inside one row.
  const wrappingText=await reply.evaluate(el=>{
    const css=getComputedStyle(el),canvas=document.createElement('canvas'),ctx=canvas.getContext('2d');ctx.font=css.font;
    const available=el.clientWidth-parseFloat(css.paddingLeft)-parseFloat(css.paddingRight);
    return 'i'.repeat(Math.ceil(available/ctx.measureText('i').width)+1);
  });
  await reply.fill(wrappingText);const wrapped=await editorGeometry(page);
  await expect(page.locator('#composer')).toHaveClass(/m2ReplyMultiline/);expect(wrapped.width).toBeGreaterThan(empty.width+80);
  await reply.fill('');expect((await editorGeometry(page)).lines).toBeLessThanOrEqual(1.1);await capture(page,browserName,viewport.width,'reply-compact-empty-'+viewport.height);
  expect(await reply.evaluate(el=>el.scrollHeight-el.clientHeight)).toBeLessThanOrEqual(1);
  await expect(page.locator('#composer')).not.toHaveClass(/m2ReplyMultiline/);
  await reply.fill(editorText);
  await expect.poll(async()=>(await editorGeometry(page)).height).toBeGreaterThan(short.height+40);
  const grown=await editorGeometry(page);
  await expect(page.locator('#composer')).toHaveClass(/m2ReplyMultiline/);
  const multiline=await page.evaluate(()=>{const text=document.querySelector('#replyText').getBoundingClientRect(),actions=document.querySelector('#composer .composerActions').getBoundingClientRect(),surface=document.querySelector('#composer .composerSurface').getBoundingClientRect();return {textBottom:text.bottom,actionsTop:actions.top,textWidth:text.width,surfaceWidth:surface.width}});
  expect(multiline.actionsTop).toBeGreaterThanOrEqual(multiline.textBottom-1);expect(multiline.textWidth).toBeGreaterThan(multiline.surfaceWidth*.9);expect(grown.lines).toBeLessThanOrEqual(12.1);
  const latest=await page.evaluate(()=>{const box=document.querySelector('#messages'),gap=()=>box.scrollHeight-box.clientHeight-box.scrollTop;WorkspaceV2Jump.scrollToLatest();const before=gap();WorkspaceV2ReplyEditor.resize();return {before,after:gap()}});
  expect(latest.before).toBeLessThanOrEqual(2);expect(latest.after).toBeLessThanOrEqual(2);
  expect(grown.lines).toBeGreaterThanOrEqual(viewport.height>540?5.9:2);
  expect(grown.width).toBeGreaterThan(viewport.width<740?viewport.width-50:500);
  await page.locator('#replyFile').setInputFiles({name:'Длинное название выбранного файла для клиента.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment')});
  await page.locator('#mediaSelection .mediaSelectedItem').waitFor();
  if(viewport.width===1440){
    await page.locator('#messages audio').evaluate(async audio=>{audio.muted=true;audio.loop=true;await audio.play()});
    await page.locator('#messages').evaluate(el=>{el.dispatchEvent(new WheelEvent('wheel',{deltaY:-300}));el.scrollTop=100;el.dispatchEvent(new Event('scroll'))});
    await expect(page.locator('#jumpToLatest')).toBeVisible();
  }
  await reply.evaluate(el=>{
    el.focus({preventScroll:true});el.setSelectionRange(14,58,'backward');
    window.editorNativeProof={form:document.querySelector('#composer'),reply:el,file:document.querySelector('#replyFile'),preview:document.querySelector('#mediaSelection .mediaSelectedItem'),message:document.querySelector('#messages .msg'),start:el.selectionStart,end:el.selectionEnd,direction:el.selectionDirection};
    const proof=window.editorNativeProof,box=document.querySelector('#messages');
    proof.audio=box.querySelector('audio');proof.audioTime=proof.audio?.currentTime;
    proof.anchor=[...box.children].find(row=>row.getBoundingClientRect().bottom>box.getBoundingClientRect().top+1);
    proof.anchorOffset=proof.anchor.getBoundingClientRect().top-box.getBoundingClientRect().top;
  });
  await capture(page,browserName,viewport.width,'reply-inline-'+viewport.height);
  const requests=s.calls.length,historySize=await page.evaluate(()=>history.length);
  if(viewport.width===390&&viewport.height===900){
    // Reduced visual area without resizing the layout viewport, as with a soft
    // keyboard. This is geometry emulation, not physical iPhone evidence.
    await page.evaluate(()=>{Object.defineProperty(visualViewport,'height',{configurable:true,value:360});visualViewport.dispatchEvent(new Event('resize'))});
    await expect.poll(()=>page.locator('#conversationZone').evaluate(el=>el.getBoundingClientRect().bottom)).toBeLessThanOrEqual(360);
    await page.locator('#m2ReplyExpand').scrollIntoViewIfNeeded();
    expect(await page.locator('#m2ReplyExpand').evaluate(el=>el.getBoundingClientRect().bottom)).toBeLessThanOrEqual(360);
  }
  await page.locator('#m2ReplyExpand').click();await expect(dialog).toBeVisible();
  await expect(page.locator('#m2ReplyExpand')).toHaveAttribute('aria-expanded','true');
  if(viewport.width===390&&viewport.height===900){
    await page.locator('#sendReply').scrollIntoViewIfNeeded();
    expect(await page.locator('#sendReply').evaluate(el=>el.getBoundingClientRect().bottom)).toBeLessThanOrEqual(360);
    expect(await page.locator('#m2ReplyCollapse').evaluate(el=>el.getBoundingClientRect().bottom)).toBeLessThanOrEqual(360);
    await page.evaluate(()=>{delete visualViewport.height;visualViewport.dispatchEvent(new Event('resize'))});
    await expect.poll(()=>dialog.evaluate(el=>el.getBoundingClientRect().height)).toBeGreaterThan(700);
    await reply.focus();
  }
  await expect(page.locator('#m2ReplyEditorRecipient')).toHaveText('Анна Морозова · TELEGRAM');
  await expect(reply).toBeFocused();await expect(reply).toHaveValue(editorText);
  expect(await page.evaluate(()=>history.length)).toBe(historySize+1);
  const expanded=await editorGeometry(page);expect(expanded.height).toBeGreaterThan(grown.height);
  const intact=()=>page.evaluate(()=>{
    const p=window.editorNativeProof,r=document.querySelector('#replyText');
    return {same:r===p.reply&&document.querySelector('#composer')===p.form&&document.querySelector('#replyFile')===p.file&&document.querySelector('#mediaSelection .mediaSelectedItem')===p.preview&&document.querySelector('#messages .msg')===p.message,selection:[r.selectionStart,r.selectionEnd,r.selectionDirection],expected:[p.start,p.end,p.direction]};
  });
  const opened=await intact();expect(opened.same).toBe(true);expect(opened.selection).toEqual(opened.expected);
  const accessible=await page.evaluate(()=>['replyText','sendReply','m2ReplyCollapse','mediaSelection'].every(id=>{
    const r=document.getElementById(id).getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1&&r.top>=-1&&r.bottom<=innerHeight+1;
  }));expect(accessible).toBe(true);
  await capture(page,browserName,viewport.width,'reply-expanded-'+viewport.height);
  await page.locator('#m2ReplyCollapse').click();await expect(dialog).not.toBeVisible();await expect(reply).toBeFocused();
  await expect(page.locator('#m2ReplyExpand')).toHaveAttribute('aria-expanded','false');
  let returned=await intact();expect(returned.same).toBe(true);expect(returned.selection).toEqual(returned.expected);
  await expect.poll(()=>page.evaluate(()=>!!history.state?.workspaceV2Overlay)).toBe(false);
  await page.locator('#m2ReplyExpand').click();await expect(dialog).toBeVisible();await page.keyboard.press('Escape');await expect(dialog).not.toBeVisible();
  await expect.poll(()=>page.evaluate(()=>!!history.state?.workspaceV2Overlay)).toBe(false);
  await page.locator('#m2ReplyExpand').click();await expect(dialog).toBeVisible();await page.goBack();await expect(dialog).not.toBeVisible();
  await expect(reply).toHaveValue(editorText);returned=await intact();expect(returned.same).toBe(true);expect(returned.selection).toEqual(returned.expected);
  if(viewport.width<=1100)await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-mobile-screen','conversation');
  expect(await page.evaluate(()=>history.length)).toBe(historySize+1);
  expect(s.calls).toHaveLength(requests);expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
  if(viewport.width===1440){
    await expect.poll(()=>page.evaluate(()=>{const p=editorNativeProof,a=document.querySelector('#messages audio');return a===p.audio&&!a.paused&&a.currentTime>p.audioTime})).toBe(true);
    await expect.poll(()=>page.evaluate(()=>{const p=editorNativeProof,box=document.querySelector('#messages');return Math.abs(p.anchor.getBoundingClientRect().top-box.getBoundingClientRect().top-p.anchorOffset)})).toBeLessThanOrEqual(2);
  }
  await reply.fill('Короткий ответ');await reply.evaluate(el=>el.setSelectionRange(3,7,'backward'));
  await page.locator('#m2ReplyExpand').click();await expect(dialog).toBeVisible();await page.locator('#m2ReplyCollapse').click();await expect(dialog).not.toBeVisible();
  await expect(reply).toBeFocused();await expect(reply).toHaveValue('Короткий ответ');expect((await editorGeometry(page)).lines).toBeLessThanOrEqual(1.1);
  expect(await reply.evaluate(el=>[el.selectionStart,el.selectionEnd,el.selectionDirection])).toEqual([3,7,'backward']);
  expect(await page.evaluate(()=>document.querySelector('#mediaSelection .mediaSelectedItem')===editorNativeProof.preview)).toBe(true);
  await reply.fill('');expect((await editorGeometry(page)).lines).toBeLessThanOrEqual(1.1);await capture(page,browserName,viewport.width,'reply-compact-file-'+viewport.height);
  expect(s.calls).toHaveLength(requests);expect(s.sends).toEqual([]);
  await page.emulateMedia({reducedMotion:'reduce'});await reply.fill(editorText);await editorGeometry(page);
  expect(await reply.evaluate(el=>getComputedStyle(el).transitionDuration)).toBe('0s');
  expect(await page.evaluate(()=>document.querySelector('#mediaSelection .mediaSelectedItem')===editorNativeProof.preview)).toBe(true);
  expect(s.calls).toHaveLength(requests);expect(s.sends).toEqual([]);
  await noOverflow(page);
});
}
test('Manager2 reply editor follows canonical client and account recovery boundaries',async({page})=>{
  await page.setViewportSize({width:430,height:900});const s=await setup(page);await openLead(page);await editorReady(page);
  const reply=page.locator('#replyText'),dialog=page.locator('#m2ReplyEditor');
  await reply.fill('Черновик Анны');await page.locator('#replyFile').setInputFiles({name:'Anna.txt',mimeType:'text/plain',buffer:Buffer.from('Anna fixture')});
  await page.locator('#m2ReplyExpand').click();await expect(dialog).toBeVisible();
  await page.evaluate(()=>WorkspaceV2Conversation.open(12));await expect(dialog).not.toBeVisible();await expect(reply).toBeEnabled();
  await reply.fill('Черновик Алексея');await page.locator('#replyFile').setInputFiles({name:'Alex.txt',mimeType:'text/plain',buffer:Buffer.from('Alex fixture')});
  await page.locator('#m2ReplyExpand').click();await expect(page.locator('#m2ReplyEditorRecipient')).toContainText('Алексей Иванов');
  await page.evaluate(()=>WorkspaceV2Conversation.open(11));await expect(dialog).not.toBeVisible();await expect(reply).toHaveValue('Черновик Анны');await expect(page.locator('#mediaSelection')).toContainText('Anna.txt');
  await page.locator('#m2ReplyExpand').click();await page.evaluate(()=>WorkspaceV2.showAuthRecovery());await expect(dialog).not.toBeVisible();
  await expect(page.locator('#m2ReplyEditorRecipient')).toHaveText('');
  await expect(page.locator('#managerAuthRecovery')).toBeVisible();
  await page.locator('#managerAuthLogin').fill('synthetic-login');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();
  await expect(reply).toBeEnabled();await expect(reply).toHaveValue('Черновик Анны');await expect(page.locator('#mediaSelection')).toContainText('Anna.txt');
  expect((await editorGeometry(page)).lines).toBeLessThanOrEqual(1.1);
  await page.locator('#m2ReplyExpand').click();
  s.setConversation(11,{manager_id:8,manager_name:'Другой менеджер'});
  await page.evaluate(()=>WorkspaceV2Conversation.open(11,{preserveAttachment:true}));await expect(dialog).not.toBeVisible();
  await expect(page.locator('#composer')).not.toBeVisible();await expect(reply).toHaveValue('');
  expect(await page.evaluate(()=>WorkspaceV2Media.hasFile())).toBe(false);
  for(const expanded of [false,true]){
    const account=s.manager.id,sentinel='ACCOUNT_'+account+'_PRIVATE_REPLY_SENTINEL';
    s.setConversation(12,{manager_id:account,manager_name:'Текущий менеджер'});
    await page.evaluate(()=>WorkspaceV2Conversation.open(12));await expect(reply).toBeEnabled();
    await reply.fill(sentinel);await editorGeometry(page);
    await page.locator('#replyFile').setInputFiles({name:'account-'+account+'.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic account file')});
    await expect(page.locator('#mediaSelection')).toContainText('account-'+account+'.txt');
    if(expanded){await page.locator('#m2ReplyExpand').click();await expect(dialog).toBeVisible()}
    await page.evaluate(()=>WorkspaceV2.showAuthRecovery());await expect(dialog).not.toBeVisible();
    await expect(reply).toHaveValue(sentinel);
    s.manager.id=account+1;
    await page.locator('#managerAuthLogin').fill('synthetic-next-account');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();
    await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.manager.id)).toBe(account+1);
    await expect(page.locator('#managerAuthRecovery')).not.toBeVisible();await expect(reply).toHaveValue('');
    await expect(page.locator('.m2ReplyMeasure')).toHaveText('');
    const cleared=await page.evaluate(sentinel=>({dom:!document.body.textContent.includes(sentinel),storage:!Object.values(sessionStorage).some(value=>String(value).includes(sentinel))}),sentinel);
    expect(cleared).toEqual({dom:true,storage:true});expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(0);
    expect(await page.evaluate(()=>WorkspaceV2Media.hasFile())).toBe(false);
    console.log(JSON.stringify({replyAccountCleanup:{from:account,to:account+1,expanded,...cleared,mirrorEmpty:true}}));
  }
  expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
test('Manager2 reply editor keeps canonical IME, unknown and newer-draft send semantics',async({page})=>{
  await page.setViewportSize({width:1440,height:900});const s=await setup(page);await openLead(page);await editorReady(page);
  let mode='unknown',release;const sends=[];
  await page.route('**/manager/api.php',async route=>{
    const data=route.request().postDataJSON();if(data.action!=='send')return route.fallback();
    expect(data.csrf).toBe('synthetic-csrf');expect(data.conversation_id).toBe(11);sends.push(data);
    if(mode==='unknown')return route.abort('failed');
    if(mode==='held')await new Promise(resolve=>{release=resolve});
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true})});
  });
  const reply=page.locator('#replyText'),dialog=page.locator('#m2ReplyEditor');
  await reply.fill('Редактируемый ответ');await page.locator('#m2ReplyExpand').click();
  await page.locator('#emojiToggle').click();await page.locator('#emojiPicker [data-emoji="😊"]').click();
  const canned={id:4,title:'Дополнение',body:'Дополнение из быстрого ответа',category:'Подбор',scope:'shared',version:1,is_favorite:true,can_edit:false};
  await page.route('**/manager/quick-replies-api.php',route=>{
    const data=route.request().postDataJSON();
    if(!['toolbar','list'].includes(data.action))return route.fallback();
    expect(data.csrf).toBe('synthetic-csrf');
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,replies:[canned],categories:['Подбор'],version:'1'.repeat(64),max_replies:100,has_more:false})});
  });
  await page.locator('#quickRepliesOpen').click();await expect(page.locator('#quickRepliesDialog')).toBeVisible();
  await page.locator('#qrList [data-qr-select="4"]').click();await expect(page.locator('#qrPreview')).toHaveValue(canned.body);
  await page.locator('#qrInsert').click();await expect(page.locator('#quickRepliesDialog')).not.toBeVisible();await expect(dialog).toBeVisible();
  const text=await reply.inputValue();expect(text).toContain('😊');expect(text).toContain('Дополнение из быстрого ответа');
  await reply.evaluate(el=>el.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',ctrlKey:true,isComposing:true,bubbles:true})));
  expect(sends).toHaveLength(0);
  await reply.press('Control+Enter');await expect(page.locator('#replyStatus')).toContainText('не подтверждена');await expect(reply).toHaveValue(text);
  expect(sends).toHaveLength(1);await expect(dialog).toBeVisible();
  await page.setViewportSize({width:768,height:420});await page.locator('#m2ReplyCollapse').click();await page.locator('#m2ReplyExpand').click();
  expect(sends).toHaveLength(1);await expect(reply).toHaveValue(text);
  mode='held';await reply.press('Control+Enter');await expect.poll(()=>sends.length).toBe(2);await expect(reply).toBeDisabled();
  await reply.evaluate(el=>{el.value='Более новый сохранённый черновик';el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',ctrlKey:true,bubbles:true}))});
  expect(sends).toHaveLength(2);release();await expect(reply).toBeEnabled();await expect(reply).toHaveValue('Более новый сохранённый черновик');
  await expect(dialog).not.toBeVisible();mode='success';await page.locator('#m2ReplyExpand').click();await reply.press('Control+Enter');
  await expect(reply).toHaveValue('');await expect(reply).toBeEnabled();await expect(dialog).not.toBeVisible();
  expect(sends).toHaveLength(3);expect((await editorGeometry(page)).lines).toBeLessThanOrEqual(1.1);
  expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
test('Manager2 reply editor preserves native File identity and partial unknown delivery',async({page})=>{
  await page.setViewportSize({width:390,height:420});const s=await setup(page);await openLead(page);await editorReady(page);
  await page.evaluate(()=>{
    window.editorFiles=[];window.editorUploads=[];
    document.querySelector('#replyFile').addEventListener('change',event=>{window.editorFiles=[...event.target.files]},true);
    const append=FormData.prototype.append;FormData.prototype.append=function(name,value,...rest){if(name==='file')window.editorUploads.push(value);return append.call(this,name,value,...rest)};
  });
  let uploads=0;
  await page.route('**/manager/media-upload.php',route=>{
    uploads++;if(uploads===2)return route.abort('failed');
    expect(route.request().postData()).toContain('Подпись к первому файлу');
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true})});
  });
  const reply=page.locator('#replyText'),dialog=page.locator('#m2ReplyEditor');
  await reply.fill('Подпись к первому файлу');
  await page.locator('#replyFile').setInputFiles([{name:'first.txt',mimeType:'text/plain',buffer:Buffer.from('First synthetic file')},{name:'second.txt',mimeType:'text/plain',buffer:Buffer.from('Second synthetic file')}]);
  await page.locator('#m2ReplyExpand').click();await page.locator('#m2ReplyCollapse').click();expect(uploads).toBe(0);
  await page.locator('#m2ReplyExpand').click();await page.locator('#sendReply').click();
  await expect(page.locator('#mediaSelection')).toContainText('second.txt');await expect(page.locator('#mediaSelection')).not.toContainText('first.txt');
  await expect(page.locator('#mediaSelection')).toContainText('Исход неизвестен');await expect(reply).toHaveValue('');await expect(reply).toBeEnabled();
  expect(await page.evaluate(()=>editorUploads.length===2&&editorUploads.every((file,i)=>file===editorFiles[i]))).toBe(true);
  await expect(dialog).not.toBeVisible();await page.locator('#m2ReplyExpand').click();await page.locator('#sendReply').click();
  await expect(page.locator('#replyStatus')).toContainText('неизвестен');expect(uploads).toBe(2);
  await expect(page.locator('#mediaSelection')).toContainText('second.txt');
  expect(s.sends).toEqual([]);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
