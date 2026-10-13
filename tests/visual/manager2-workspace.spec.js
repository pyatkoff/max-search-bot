const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';
// Render the actual PHP shell; no copied HTML or production traffic.
const shell=execFileSync('php',['manager2/index.php'],{cwd:root,encoding:'utf8'});
const stages=[['new','Новый лид'],['working','В работе'],['selection','Подбираем'],['proposal','Предложение отправлено'],['sold','Продано']].map(([stage_key,display_name],i)=>({id:i+1,stage_key,display_name,sort_order:i}));
async function setup(page,{anonymous=false,otherOwner=false}={}){
  const calls=[],unexpected=[],errors=[],sends=[];
  let authenticated=!anonymous,subscribed=false;
  const manager={id:7,role:'manager',display_name:'Мария',is_working:true};
  const tasks=[{id:21,title:'Проверить отели у моря в бюджете',status:'open',due_state:'today',due_at_utc:'2026-10-14 07:00:00',is_pinned:true,assigned_manager_name:'Мария'},{id:22,title:'Отправить подборку клиенту',status:'open',due_state:'overdue',due_at_utc:'2026-10-13 09:00:00',assigned_manager_name:'Мария'},{id:23,title:'Уточнить состав туристов',status:'done',due_state:'done',assigned_manager_name:'Мария'}];
  const conversation=id=>({id,manager_id:otherOwner?8:7,manager_name:otherOwner?'Людмила':'Мария',status:'manager',channel:'telegram',source_id:1,source_name:'AnyTour',display_name:id===11?'Анна Морозова':'Алексей Иванов'});
  const messages=Array.from({length:35},(_,i)=>({id:i+1,sender_type:i%3===0?'manager':'customer',manager_id:i%3===0?7:null,text:i===34?'Посмотрите, пожалуйста, варианты у моря. Бюджет до 250 тысяч за всех.':'Сообщение '+(i+1)+'. Подбираем семейный отдых в Турции, обсуждаем отели и даты.',created_at:'2026-10-13 12:00:00'}));
  const lead=id=>({ok:true,can_edit_pipeline:!otherOwner,pipeline:{stage:stages[1],stage_history:[],tags:[],outcome:{outcome:'open'}},tasks:tasks.map(t=>({...t})),trip:{city:'Москва',country:'Турция',date:'24 октября',nights:'7–9',adults:2,children:1,child_ages:'8',meal:'all_inclusive',stars:'4,5',budget:{max:250000,currency:'RUB',basis:'total'}},contact:{phone:'+7 999 123-45-67'},source:{project_label:'AnyTour',origin_label:'TG · реклама',channel:'telegram'},handoff:{technical_status:'manager',manager_name:conversation(id).manager_name}});
  const rows=()=>[11,12].map((id,i)=>({...conversation(id),can_edit_pipeline:!otherOwner,project_label:'AnyTour',origin_label:'TG · реклама',trip_summary:'Москва → Турция · 2 взрослых и ребёнок · 7–9 ночей',lead_stage:stages[1],lead_outcome:'open',unread_count:i?0:2,last_text:'Ждём варианты у моря',last_sender_type:'customer',last_message_at:'2026-10-13 12:00:00',next_task_id:21,next_task_title:tasks.find(t=>t.status==='open')?.title||'',next_task_due_state:'today',operational_task_due_state:'overdue',next_task_due_at_utc:'2026-10-14 07:00:00',next_task_pinned:1,contact_phone:'+7 999 123-45-67'}));
  await page.addInitScript(()=>{
    sessionStorage.clear();
    window.fixtureWorkers=[];
    const reg={scope:location.origin+'/manager/',active:{postMessage(){}},pushManager:{getSubscription:async()=>null,subscribe:async()=>({options:{},toJSON:()=>({endpoint:'https://example.invalid/synthetic-push',keys:{p256dh:'synthetic',auth:'synthetic'}})})},showNotification:async()=>{}};
    Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{register:async(url,options)=>{fixtureWorkers.push({url:new URL(url,document.baseURI).pathname,scope:new URL(options.scope,document.baseURI).pathname});return reg},ready:Promise.resolve(reg),addEventListener(){}}});
    function FakeNotification(){}FakeNotification.permission='default';FakeNotification.requestPermission=async()=>{FakeNotification.permission='granted';return 'granted'};
    Object.defineProperty(window,'Notification',{configurable:true,value:FakeNotification});Object.defineProperty(window,'PushManager',{configurable:true,value:function(){}});
  });
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
    const req=route.request(),url=new URL(req.url());
    if(url.origin!==origin){unexpected.push(req.url());return route.abort()}
    if(req.method()==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
    if(!url.pathname.endsWith('.php'))return route.continue();
    if(!url.pathname.startsWith('/manager/')){unexpected.push(req.method()+' '+url.pathname);return route.abort()}
    const data=req.method()==='POST'?req.postDataJSON()||{}:{};
    calls.push({path:url.pathname,...data});
    const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    if(url.pathname==='/manager/api.php'){
      if(data.action==='login'){authenticated=true;return reply({ok:true,manager,csrf:'synthetic-csrf',projects:[{project_key:'anytour',display_name:'AnyTour'}]})}
      if(!authenticated)return reply({ok:false,error:'unauthorized'},401);
      if(data.action==='me')return reply({ok:true,manager,csrf:'synthetic-csrf',projects:[{project_key:'anytour',display_name:'AnyTour'}]});
      if(data.action==='counts')return reply({ok:true,counts:{waiting:{count:2,unread:2},mine:{count:2}}});
      if(data.action==='detail')return reply({ok:true,conversation:conversation(data.conversation_id),messages:messages.map(m=>({...m})),delivery_failure:null});
      if(data.action==='send'){sends.push(data);messages.push({id:100+sends.length,sender_type:'manager',manager_id:7,text:data.text,created_at:'2026-10-13 12:01:00'});return reply({ok:true})}
      if(data.action==='set_working'){manager.is_working=data.working;return reply({ok:true,manager})}
    }
    if(url.pathname==='/manager/pipeline-api.php'){
      if(data.csrf!=='synthetic-csrf')throw new Error('Canonical CSRF was not retained');
      if(data.action==='catalog')return reply({ok:true,stages,tags:[{id:1,display_name:'Семья'}],outcomes:{open:'В работе',won:'Продажа',lost:'Отказ'},close_reasons:{price:'Цена'}});
      if(data.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager]}});
      if(data.action==='list')return reply({ok:true,conversations:rows()});
      if(data.action==='detail')return reply(lead(data.conversation_id));
      if(data.action==='create_task'){tasks.push({id:30,title:data.title,due_at_utc:data.due_at,status:'open',due_state:'upcoming',assigned_manager_name:'Мария'});return reply({ok:true})}
      if(data.action==='set_task_completed'){tasks.find(t=>t.id===data.task_id).status=data.completed?'done':'open';return reply({ok:true})}
      if(data.action==='set_task_pinned'){tasks.find(t=>t.id===data.task_id).is_pinned=data.pinned;return reply({ok:true})}
      if(data.action==='update_task'){Object.assign(tasks.find(t=>t.id===data.task_id),{title:data.title,due_at_utc:data.due_at});return reply({ok:true})}
      if(['set_stage','set_tags','set_outcome'].includes(data.action))return reply({ok:true});
    }
    if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:manager.is_working,notification_path_usable:subscribed,healthy_subscription_count:subscribed?1:0,notification_path_reason:subscribed?'healthy':'no_subscription'}});
    if(url.pathname==='/manager/push.php'){
      if(url.searchParams.get('action')==='key')return reply({ok:true,public_key:Buffer.from([4,...Array(64).fill(1)]).toString('base64url')});
      if(data.action==='subscribe'){subscribed=true;return reply({ok:true})}
    }
    if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
    unexpected.push(req.method()+' '+url.pathname+' '+data.action);return reply({ok:false,error:'unexpected_fixture_action'},400);
  });
  await page.goto(origin+'/manager2/index.php?qa=1');
  if(!anonymous)await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  return {calls,unexpected,errors,sends,tasks};
}
async function openLead(page,id=11){
  if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox'))await page.locator('#mobileBack').click();
  await page.locator('#inboxList [data-conversation-id="'+id+'"]').click();
  await expect(page.locator('#conversationTitle')).toHaveText(id===11?'Анна Морозова':'Алексей Иванов');
}
async function showCard(page){if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()))await page.locator('#mobileLeadBtn').click()}
async function showChat(page){if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()==='lead'))await page.locator('#mobileLeadClose').click()}
async function noOverflow(page){const size=await page.evaluate(()=>({w:document.documentElement.scrollWidth,h:document.documentElement.scrollHeight,vw:innerWidth,vh:innerHeight}));expect(size.w).toBeLessThanOrEqual(size.vw+1);expect(size.h).toBeLessThanOrEqual(size.vh+1)}
async function capture(page,browser,width,state){fs.mkdirSync('manager2-artifacts',{recursive:true});await page.screenshot({path:`manager2-artifacts/${browser}-${width}-${state}.png`})}

for(const width of [390,430,768,1440])test.describe('Manager2 '+width,()=>{
  test.use({viewport:{width,height:900}});
  test('actual shell boots, preserves canonical paths, compact client card and responsive navigation',async({page,browserName})=>{
    const s=await setup(page);await expect(page).toHaveURL(origin+'/manager2/?qa=1');
    await noOverflow(page);await capture(page,browserName,width,'inbox');
    expect(await page.evaluate(()=>fixtureWorkers)).toEqual([{url:'/manager/sw.js',scope:'/manager/'}]);
    await openLead(page);await expect(page.locator('#composer')).toBeVisible();await noOverflow(page);await capture(page,browserName,width,'conversation');
    const gap=await page.locator('#messages').evaluate(el=>el.scrollHeight-el.clientHeight-el.scrollTop);expect(gap).toBeLessThanOrEqual(2);
    await showCard(page);await expect(page.locator('.m2Budget strong')).toHaveText('до 250 000 ₽');
    await expect(page.locator('.m2ExtraUrgency')).toHaveText('Ещё просроченных задач: 1');
    await expect(page.locator('.m2AllTasks')).not.toHaveAttribute('open');await expect(page.locator('[data-task-toggle="22"]')).not.toBeVisible();
    await page.locator('.m2AllTasks>summary').click();await expect(page.locator('[data-task-toggle="22"]')).toBeVisible();
    await page.locator('.m2AllTasks>summary').click();await capture(page,browserName,width,'client');await noOverflow(page);
    await showChat(page);await page.locator('#replyText').fill('Черновик для Анны');await openLead(page,12);await page.locator('#replyText').fill('Для Алексея');await openLead(page,11);await expect(page.locator('#replyText')).toHaveValue('Черновик для Анны');
    await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toHaveText('Отправлено');expect(s.sends).toHaveLength(1);expect(s.sends[0]).toMatchObject({conversation_id:11,text:'Черновик для Анны',csrf:'synthetic-csrf'});
    await noOverflow(page);expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
  });
  test('task mutations, keyboard-height composer, sales controls and notification dialog remain usable',async({page,browserName})=>{
    const s=await setup(page);await openLead(page);await showCard(page);
    await page.locator('.m2TaskCreate>summary').click();await page.locator('#leadTaskTitle').fill('Новая рабочая задача');await page.locator('#leadTaskDue').fill('2026-10-15T10:00');await page.locator('#leadTaskAdd').click();
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
    await expect(page.locator('#notificationStatus')).toHaveClass(/\bok\b/);expect(s.calls.filter(c=>c.action==='subscribe')).toHaveLength(1);await capture(page,browserName,width,'notifications');
    await page.locator('#m2NotificationsClose').click();await expect(page.locator('#m2NotificationButton')).toBeFocused();expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
  });
});
test('Manager2 anonymous entry uses the canonical login and does not expose another manager composer',async({page})=>{
  const s=await setup(page,{anonymous:true,otherOwner:true});await expect(page.locator('#managerAuthRecovery')).toBeVisible();
  await page.locator('#managerAuthLogin').fill('synthetic-login');await page.locator('#managerAuthPassword').fill('synthetic-password');await page.locator('#managerAuthSubmit').click();await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  await openLead(page);await expect(page.locator('#composer')).not.toBeVisible();await expect(page.locator('#composerLocked')).toContainText('Людмила');expect(s.calls.find(c=>c.action==='login').path).toBe('/manager/api.php');expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);
});
