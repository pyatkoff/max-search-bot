const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
// Render the actual PHP shell; no copied HTML or production traffic.
const shell=execFileSync('php',['manager2/index.php'],{cwd:root,encoding:'utf8'});
const stages=[['new','Новый лид'],['working','В работе'],['selection','Подбираем'],['proposal','Предложение отправлено'],['sold','Продано']].map(([stage_key,display_name],i)=>({id:i+1,stage_key,display_name,sort_order:i}));
async function setup(page,{anonymous=false,otherOwner=false,role='manager',serviceEvents=false,taskResult='success',holdCreate=false}={}){
  const calls=[],unexpected=[],errors=[],sends=[],pendingCreates=[];
  let authenticated=!anonymous,subscribed=false;
  const cancellationState={status:'manager',event:null,deny:false};
  const manager={id:7,role,display_name:'Мария',is_working:true};
  const tasks=[{id:21,title:'Проверить отели у моря в бюджете',status:'open',due_state:'today',due_at_utc:'2026-10-14 07:00:00',is_pinned:true,assigned_manager_name:'Мария'},{id:22,title:'Отправить подборку клиенту',status:'open',due_state:'overdue',due_at_utc:'2026-10-13 09:00:00',assigned_manager_name:'Мария'},{id:23,title:'Уточнить состав туристов',status:'done',due_state:'done',assigned_manager_name:'Мария'}];
  const conversation=id=>({id,manager_id:id===12&&cancellationState.status==='ai'?null:otherOwner?8:7,manager_name:otherOwner?'Людмила':'Мария',status:id===12?cancellationState.status:'manager',channel:'telegram',source_id:1,source_name:'AnyTour',display_name:id===11?'Анна Морозова':'Алексей Иванов'});
  const messages=Array.from({length:35},(_,i)=>({id:i+1,sender_type:i%3===0?'manager':'customer',manager_id:i%3===0?7:null,text:i===34?'Посмотрите, пожалуйста, варианты у моря. Бюджет до 250 тысяч за всех.':'Сообщение '+(i+1)+'. Подбираем семейный отдых в Турции, обсуждаем отели и даты.',created_at:'2026-10-13 12:00:00'}));
  if(serviceEvents)messages.push({id:36,direction:'inbound',sender_type:'customer',text:'manager_after_tours',service_action:{type:'callback',label:'Клиент запросил менеджера после подбора туров',raw_payload:'manager_after_tours'},created_at:'2026-10-13 12:01:00'},{id:37,direction:'inbound',sender_type:'customer',text:'manager_after_tours',created_at:'2026-10-13 12:02:00'});
  const lead=id=>({ok:true,can_edit_pipeline:!otherOwner,pipeline:{stage:stages[1],stage_history:[],tags:[],outcome:{outcome:'open'}},tasks:tasks.map(t=>({...t})),trip:{city:'Москва',country:'Турция',date:'24 октября',nights:'7–9',adults:2,children:1,child_ages:'8',meal:'all_inclusive',stars:'4,5',budget:{max:250000,currency:'RUB',basis:'total'}},contact:{phone:'+7 999 123-45-67'},source:{project_label:'AnyTour',origin_label:'TG · реклама',channel:'telegram'},handoff:{technical_status:'manager',manager_name:conversation(id).manager_name}});
  const rows=()=>[11,12].map((id,i)=>({...conversation(id),can_edit_pipeline:!otherOwner,project_label:'AnyTour',origin_label:'TG · реклама',trip_summary:'Москва → Турция · 2 взрослых и ребёнок · 7–9 ночей',lead_stage:stages[1],lead_outcome:'open',unread_count:i?0:2,last_text:'Ждём варианты у моря',last_sender_type:'customer',last_message_at:'2026-10-13 12:00:00',next_task_id:21,next_task_title:tasks.find(t=>t.status==='open')?.title||'',next_task_due_state:'today',operational_task_due_state:'overdue',next_task_due_at_utc:'2026-10-14 07:00:00',next_task_pinned:1,contact_phone:'+7 999 123-45-67'}));
  await page.addInitScript(()=>{
    if(!sessionStorage.getItem('manager2-fixture-initialized')){sessionStorage.clear();sessionStorage.setItem('manager2-fixture-initialized','1')}
    window.fixtureWorkers=[];
    let subscription=null;
    const reg={scope:location.origin+'/manager/',active:{postMessage(){}},pushManager:{getSubscription:async()=>subscription,subscribe:async options=>{subscription={options,endpoint:'https://example.invalid/synthetic-push',toJSON:()=>({endpoint:'https://example.invalid/synthetic-push',keys:{p256dh:'synthetic',auth:'synthetic'}})};return subscription}},showNotification:async()=>{}};
    Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{register:async(url,options)=>{fixtureWorkers.push({url:new URL(url,document.baseURI).pathname,scope:new URL(options.scope,document.baseURI).pathname});return reg},ready:Promise.resolve(reg),addEventListener(){}}});
    function FakeNotification(){}FakeNotification.permission='default';FakeNotification.requestPermission=async()=>{FakeNotification.permission='granted';return 'granted'};
    Object.defineProperty(window,'Notification',{configurable:true,value:FakeNotification});Object.defineProperty(window,'PushManager',{configurable:true,value:function(){}});
  });
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
    const req=route.request(),url=new URL(req.url());
    if(url.origin!==origin){unexpected.push(req.url());return route.abort()}
    if(req.method()==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
  // Optional portraits are absent for these synthetic clients; the dedicated avatar suite covers images/ACL.
  if(req.method()==='GET'&&url.pathname==='/manager/customer-avatar.php'){expect([...url.searchParams.keys()]).toEqual(['conversation_id']);expect(['11','12']).toContain(url.searchParams.get('conversation_id'));return route.fulfill({status:404,body:''})}
    if(url.pathname.startsWith('/manager/assets/')||url.pathname.startsWith('/manager2/assets/')){const asset=path.resolve(root,'.'+url.pathname);if(!asset.startsWith(root+path.sep))return route.abort();return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(asset)})}
    if(!url.pathname.endsWith('.php'))return route.continue();
    if(!url.pathname.startsWith('/manager/')){unexpected.push(req.method()+' '+url.pathname);return route.abort()}
    const data=req.method()==='POST'?req.postDataJSON()||{}:{};
    calls.push({path:url.pathname,...data});
    const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    if(url.pathname==='/manager/api.php'){
      if(data.action==='login'){authenticated=true;return reply({ok:true,manager,csrf:'synthetic-csrf',projects:[{project_key:'anytour',display_name:'AnyTour'}]})}
      if(!authenticated)return reply({ok:false,error:'unauthorized'},401);
      if(data.action==='me')return reply({ok:true,manager,csrf:'synthetic-csrf',projects:[{project_key:'anytour',display_name:'AnyTour'}]});
      if(data.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
      if(data.action==='counts')return reply({ok:true,counts:{waiting:{count:2,unread:2},mine:{count:2}}});
      if(data.action==='detail'){if(cancellationState.deny)return reply({ok:false,error:'forbidden'},403);return reply({ok:true,conversation:conversation(data.conversation_id),messages:messages.map(m=>({...m})),delivery_failure:null,handoff_cancellation:cancellationState.event});}
      if(data.action==='send'){sends.push(data);messages.push({id:100+sends.length,sender_type:'manager',manager_id:7,text:data.text,created_at:'2026-10-13 12:01:00'});return reply({ok:true})}
      if(data.action==='set_working'){manager.is_working=data.working;return reply({ok:true,manager})}
    }
    if(url.pathname==='/manager/pipeline-api.php'){
      if(data.csrf!=='synthetic-csrf')throw new Error('Canonical CSRF was not retained');
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
    if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:manager.is_working,notification_path_usable:subscribed,healthy_subscription_count:subscribed?1:0,notification_path_reason:subscribed?'healthy_subscription':'no_subscription'}});
    if(url.pathname==='/manager/push.php'){
      if(url.searchParams.get('action')==='key')return reply({ok:true,public_key:Buffer.from([4,...Array(64).fill(1)]).toString('base64url')});
      if(data.action==='subscribe'){subscribed=true;return reply({ok:true,push_status:{is_working:manager.is_working,notification_path_usable:true,healthy_subscription_count:1,notification_path_reason:'healthy_subscription'}})}
    }
    if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
    unexpected.push(req.method()+' '+url.pathname+' '+data.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
  });
  await page.goto(origin+'/manager2/index.php?qa=1');
  if(!anonymous)await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  return {cancellationState,calls,unexpected,errors,sends,tasks,releaseCreate:()=>pendingCreates.shift()?.()};
}
async function openLead(page,id=11){
  if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox'))await page.locator('#mobileBack').click();
  await page.locator('#inboxList [data-conversation-id="'+id+'"]').click();
  await expect(page.locator('#conversationTitle')).toHaveText(id===11?'Анна Морозова':'Алексей Иванов');
}

async function settled(page,width){
  if(width<=430)await expect.poll(async()=>Math.round((await page.locator('#conversationZone').boundingBox()).x)).toBe(0);
  const notice=page.locator('#handoffCancellation');
  if(await notice.isVisible()){
    await expect.poll(async()=>{const box=await notice.boundingBox();return box.x>=-1&&box.x+box.width<=width+1}).toBe(true);
    if(width<=430)expect((await notice.boundingBox()).width).toBeGreaterThan(280);
  }
}
for(const width of [390,430,768,1440])test('actual shell confirmed handoff cause '+width,async({page,browserName},testInfo)=>{
  await page.setViewportSize({width,height:420});const fixture=await setup(page);await openLead(page);
  await page.locator('#replyText').fill('Сохранённый черновик до перехода');
  await settled(page,width);fs.mkdirSync('manager2-artifacts',{recursive:true});
  await page.screenshot({animations:'disabled',path:`manager2-artifacts/${browserName}-${width}-handoff-before.png`});
  fixture.cancellationState.status='ai';fixture.cancellationState.event={event_id:71,event_type:'ai_resumed',reason:'handoff_cancelled',created_at:'2026-10-14 08:30:00'};
  await openLead(page,12);
  await expect(page.locator('#handoffCancellation')).toContainText('Клиент отменил ожидание менеджера');
  await expect(page.locator('#handoffCancellation time')).toHaveText('14.10.2026 11:30 МСК');
  await expect(page.locator('#messages')).toContainText('Посмотрите, пожалуйста, варианты у моря');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBeTruthy();
  await settled(page,width);
  await page.screenshot({animations:'disabled',path:`manager2-artifacts/${browserName}-${width}-handoff-after.png`});
  await openLead(page,11);await expect(page.locator('#handoffCancellation')).toBeHidden();await expect(page.locator('#replyText')).toHaveValue('Сохранённый черновик до перехода');
  fixture.cancellationState.status='ai';fixture.cancellationState.event={event_id:71,event_type:'ai_resumed',reason:'handoff_cancelled',created_at:'2026-10-14 08:30:00'};
  await openLead(page,12);await expect(page.locator('#handoffCancellation')).toBeVisible();
  const confirmed=fixture.cancellationState.event;fixture.cancellationState.event=null;
  await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible());await expect(page.locator('#handoffCancellation')).toBeHidden();
  fixture.cancellationState.event=confirmed;await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible());await expect(page.locator('#handoffCancellation')).toBeVisible();
  await page.evaluate(()=>WorkspaceV2Conversation.suspendForAuthRecovery());await expect(page.locator('#handoffCancellation')).toBeHidden();
  await page.evaluate(()=>WorkspaceV2Conversation.open(12));await expect(page.locator('#handoffCancellation')).toBeVisible();
  fixture.cancellationState.deny=true;await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible());await expect(page.locator('#handoffCancellation')).toBeHidden();
  await page.evaluate(()=>WorkspaceV2Conversation.resetForIdentityChange());await expect(page.locator('#handoffCancellation')).toBeHidden();
  expect(fixture.sends).toHaveLength(0);expect(fixture.errors).toEqual([]);expect(fixture.unexpected).toEqual([]);
});
