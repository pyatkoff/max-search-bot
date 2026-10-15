const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../..'),origin='http://127.0.0.1:4173';

// Render the real entry point and serve real assets. Only account data is a
// fixture; neither the browser nor this suite can reach a production service.
const shell=execFileSync('php',['manager2/index.php'],{cwd:root,encoding:'utf8'});
async function setup(page,{role='manager',savedTabs=false,longPreview=false}={}){
  const calls=[],unexpected=[],errors=[],pending=new Set();
  const manager={id:7,role,display_name:'Мария',is_working:true};
  const stages=[{id:1,stage_key:'working',display_name:'В работе',sort_order:1}];
  const state={tabs:savedTabs?[{id:5,name:'MAX — ждут ответа',queue:'mine',channel:'max',source_id:0,reply_needed:true,label_id:0,pinned_only:false}]:[],labels:[],members:{},pins:[11],settings:{start_tab:'waiting',density:'comfortable',font:'normal'}};
  const snapshot=()=>({ok:true,version:1,state});
  const conversation=id=>({id,customer_id:100+id,manager_id:id===11?7:null,manager_name:id===11?'Мария':null,status:id===11?'manager':'waiting_manager',channel:id===11?'max':'telegram',source_id:1,source_name:'AnyTour',display_name:id===11?'Анна Морозова'+(longPreview?' · очень длинное имя клиента для проверки ширины':''):'Алексей Иванов'});
  const rows=()=>[11,12].map((id,i)=>({...conversation(id),project_label:'AnyTour',origin_label:'Реклама · тестовый источник',can_edit_pipeline:true,trip_summary:'Москва → Турция · 2 взрослых · 7 ночей',lead_stage:stages[0],lead_outcome:'open',unread_count:i?0:2,last_text:longPreview?'Здравствуйте! Хотим подобрать семейный отдых у моря. Нужен удобный пляж, хороший отель и перелёт без пересадок. Подскажите, пожалуйста, какие варианты есть на наши даты и какой получится бюджет за всех путешественников.':'Ждём варианты у моря',last_sender_type:'customer',last_message_at:'2026-10-14 12:00:00',awaiting_manager_reply:id===11?1:0,awaiting_first_reply:id===12?1:0,wait_age_seconds:id===12?360:0,next_task_title:'Уточнить бюджет',next_task_due_state:'today',contact_phone:'+7 999 123-45-67'}));
  const messages=Array.from({length:24},(_,i)=>({id:i+1,sender_type:i%3?'customer':'manager',manager_id:i%3?null:7,text:'Сообщение '+(i+1)+'. Подбираем отдых у моря.',created_at:'2026-10-14 12:00:00'}));
  page.on('request',request=>pending.add(request));
  page.on('requestfinished',request=>pending.delete(request));
  page.on('requestfailed',request=>pending.delete(request));
  page.on('pageerror',error=>errors.push(error.message));
  await page.addInitScript(()=>{
    const registration={scope:location.origin+'/manager/',active:{postMessage(){}},pushManager:{getSubscription:async()=>null},showNotification:async()=>{}};
    Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{register:async()=>registration,ready:Promise.resolve(registration),addEventListener(){}}});
    function FixtureNotification(){}FixtureNotification.permission='default';
    Object.defineProperty(window,'Notification',{configurable:true,value:FixtureNotification});
    Object.defineProperty(window,'PushManager',{configurable:true,value:function(){}});
  });
  await page.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url()),method=request.method();
    const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    const block=()=>{unexpected.push(method+' '+url.pathname);return route.abort()};
    if(url.origin!==origin)return block();
    if(method==='GET'&&['/manager2/','/manager2/index.php'].includes(url.pathname))return route.fulfill({contentType:'text/html',body:shell});
    if(method==='GET'&&url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
    if(method==='GET'&&/^\/manager2?\/assets\/[\w.-]+$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(root,url.pathname.slice(1)))});
    if(method==='GET'&&url.pathname==='/manager/customer-avatar.php'){
      expect([...url.searchParams.keys()]).toEqual(['conversation_id']);
      expect(['11','12']).toContain(url.searchParams.get('conversation_id'));
      return route.fulfill({status:404,body:''});
    }
    if(method==='GET'&&url.pathname==='/manager/push-status.php'){
      calls.push({path:url.pathname});
      return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
    }
    if(method!=='POST'||!url.pathname.startsWith('/manager/'))return block();
    const data=request.postDataJSON()||{};
    calls.push({path:url.pathname,...data});
    if(data.action!=='me'&&data.csrf!=='fixture-csrf')throw new Error('Canonical CSRF missing: '+url.pathname+' '+data.action);
    if(url.pathname==='/manager/api.php'){
      if(data.action==='me')return reply({ok:true,manager,csrf:'fixture-csrf',projects:[{project_key:'anytour',display_name:'AnyTour'}]});
      if(data.action==='counts')return reply({ok:true,counts:{waiting:{count:1},mine:{count:1,unread:2}}});
      if(data.action==='detail'&&[11,12].includes(data.conversation_id))return reply({ok:true,conversation:conversation(data.conversation_id),messages,delivery_failure:null,has_manager_reply:true,history:{has_more:false,before_id:1,page_size:200,mode:'latest'}});
      if(data.action==='edit_candidates')return reply({ok:true,messages:[],candidates:[]});
    }
    if(url.pathname==='/manager/pipeline-api.php'){
      if(data.action==='catalog')return reply({ok:true,stages,tags:[],outcomes:{open:'В работе'},close_reasons:{}});
      if(data.action==='filter_options')return reply({ok:true,filters:{sources:[],managers:[manager]}});
      if(data.action==='list')return reply({ok:true,conversations:rows()});
      if(data.action==='detail')return reply({ok:true,can_edit_pipeline:true,pipeline:{stage:stages[0],stage_history:[],tags:[],outcome:{outcome:'open'}},tasks:[],trip:{city:'Москва',country:'Турция',adults:2},contact:{phone:'+7 999 123-45-67'},source:{project_label:'AnyTour',origin_label:'Реклама · тестовый источник'},handoff:{technical_status:'manager',manager_name:'Мария'}});
    }
    if(url.pathname==='/manager/quick-replies-api.php'&&data.action==='toolbar')return reply({ok:true,replies:[],version:'0'.repeat(64),max_replies:100});
    if(url.pathname==='/manager/post-context-api.php'&&data.action==='detail')return reply({ok:true,conversation_id:data.conversation_id,context:{current:null,history:[]}});
    if(url.pathname==='/manager/personal-workspace-api.php'){
      if(data.action==='snapshot')return reply(snapshot());
      if(data.action==='list'){
        const conversations=data.selection==='pinned'?rows().filter(row=>state.pins.includes(row.id)):rows().filter(row=>row.channel==='max');
        return reply({...snapshot(),conversations,bounded_count:conversations.length,has_more:false,limited:false,count_scope:'loaded_visible',limit:200});
      }
    }
    if(url.pathname==='/manager/push-status.php')return reply({ok:true,push_status:{is_working:true,notification_path_usable:false,healthy_subscription_count:0,notification_path_reason:'no_subscription'}});
    if(url.pathname==='/manager/notification-events.php')return reply({ok:true,cursor:0,events:[]});
    unexpected.push(method+' '+url.pathname+' '+data.action);
    return reply({ok:false,error:'unexpected_fixture_action'},400);
  });
  await page.goto(origin+'/manager2/index.php?qa=1');
  await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  await expect(page.locator('#inboxList')).toHaveAttribute('aria-busy','false');
  await expect(page.locator('#m2WorkspaceSettings')).toBeEnabled();
  await expect.poll(()=>pending.size).toBe(0);
  return {calls,unexpected,errors,pending};
}
async function settle(fixture){await expect.poll(()=>fixture.pending.size).toBe(0)}
async function openConversation(page){
  await page.locator('#inboxList [data-conversation-id="11"]').click();
  await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');
  await expect(page.locator('#replyText')).toBeEnabled();
}
async function fitsViewport(page){
  const size=await page.evaluate(()=>({width:document.documentElement.scrollWidth,height:document.documentElement.scrollHeight,vw:innerWidth,vh:innerHeight}));
  expect(size.width).toBeLessThanOrEqual(size.vw+1);
  expect(size.height).toBeLessThanOrEqual(size.vh+1);
}
async function clientCardHeaderFits(page){
  for(const id of ['mobileLeadClose','pwClientOpen','inOpen'])await expect(page.locator('#'+id)).toBeVisible();
  const close=page.getByRole('button',{name:'Закрыть карточку клиента',exact:true});
  await expect(close).toHaveText('×');await expect(close).toHaveAttribute('title','Закрыть карточку клиента');
  await expect.poll(()=>page.locator('#leadZone>.zoneHead').evaluate(header=>{
    const bounds=header.getBoundingClientRect(),text=header.querySelector('.leadHeaderText'),identity=text.getBoundingClientRect();
    const close=header.querySelector('#mobileLeadClose'),closeBox=close.getBoundingClientRect(),closeHit=document.elementFromPoint(closeBox.x+closeBox.width/2,closeBox.y+closeBox.height/2);
    const buttons=[...header.querySelectorAll('.leadHeaderActions button')].filter(button=>!button.hidden);
    return {
      textFits:text.scrollWidth<=text.clientWidth+1,
      clearClose:closeBox.width>=44&&closeBox.height>=44&&closeBox.left>=identity.right+4&&closeBox.right<=bounds.right&&closeBox.top>=bounds.top&&closeBox.bottom<=bounds.bottom&&(closeHit===close||close.contains(closeHit)),
      separateRows:buttons.every(button=>button.getBoundingClientRect().top>=identity.bottom+4),
      contained:buttons.every(button=>{const box=button.getBoundingClientRect();return box.left>=bounds.left-1&&box.right<=bounds.right+1&&box.top>=bounds.top-1&&box.bottom<=bounds.bottom+1}),
      readableTargets:buttons.every(button=>{const box=button.getBoundingClientRect();return box.width>=36&&box.height>=40&&parseFloat(getComputedStyle(button).fontSize)>=12}),
      unobstructed:buttons.every(button=>{const box=button.getBoundingClientRect(),hit=document.elementFromPoint(box.x+box.width/2,box.y+box.height/2);return hit===button||button.contains(hit)})
    };
  })).toEqual({textFits:true,clearClose:true,separateRows:true,contained:true,readableTargets:true,unobstructed:true});
  expect((await page.locator('#leadZone>.zoneHead').boundingBox()).height).toBeLessThanOrEqual(160);
}
function clean(fixture){expect(fixture.unexpected).toEqual([]);expect(fixture.errors).toEqual([]);expect(fixture.calls.filter(call=>['send','send_media','set_working','tab_save','tab_delete','close'].includes(call.action))).toEqual([])}

for(const width of [390,430,768,1440])test.describe('Messenger layout '+width,()=>{
  test.use({viewport:{width,height:900}});
  test('inbox gives height to chats and keeps native search, filters and account tools reachable',async({page})=>{
    const fixture=await setup(page);
    await expect(page.locator('.m2Topbar')).toHaveCount(0);
    await expect(page.locator('#leadSearch')).not.toBeVisible();
    await expect(page.locator('#pwOpen')).not.toBeVisible();
    for(const id of ['m2InboxSearchToggle','filtersToggle','m2WorkspaceSettings','m2PinnedToggle'])await expect(page.locator('#m2InboxTools #'+id)).toBeVisible();
    const filterHitbox=await page.locator('#filtersToggle').evaluate(element=>{
      const box=element.getBoundingClientRect(),hit=document.elementFromPoint(box.x+box.width/2,box.y+box.height/2);
      return {textFont:getComputedStyle(element).fontSize,iconFont:getComputedStyle(element,'::before').fontSize,width:box.width,hit:hit===element||element.contains(hit)};
    });
    expect(filterHitbox.textFont).toBe('0px');expect(filterHitbox.iconFont).toBe('21px');
    expect(filterHitbox.width).toBeGreaterThanOrEqual(32);expect(filterHitbox.width).toBeLessThanOrEqual(42);expect(filterHitbox.hit).toBe(true);
    const header=await page.locator('.inboxHead').boundingBox();
    expect(header.height).toBeLessThanOrEqual(170);
    expect((await page.locator('#inboxList').boundingBox()).height).toBeGreaterThanOrEqual(650);
    const initial=fixture.calls.length;
    await page.locator('#m2InboxSearchToggle').focus();await page.keyboard.press('/');
    await expect(page.locator('#leadSearch')).toBeVisible();await expect(page.locator('#leadSearch')).toBeFocused();
    await page.locator('#leadSearch').press('Escape');await expect(page.locator('#m2InboxSearchToggle')).toBeFocused();
    await page.locator('#m2InboxSearchToggle').press('Enter');
    await expect(page.locator('#leadSearch')).toBeVisible();
    await expect(page.locator('#leadSearch')).toBeFocused();
    await expect(page.locator('#m2InboxSearchToggle')).toHaveAttribute('aria-expanded','true');
    await page.locator('#leadSearch').press('Escape');
    await expect(page.locator('#leadSearch')).not.toBeVisible();
    await expect(page.locator('#m2InboxSearchToggle')).toBeFocused();
    await page.locator('#filtersToggle').press('Enter');
    await expect(page.locator('#filtersPanel')).toBeVisible();
    await page.locator('#filtersToggle').press('Enter');
    await expect(page.locator('#filtersPanel')).not.toBeVisible();
    await page.locator('#m2WorkspaceSettings').press('Enter');
    await expect(page.locator('#pwDialog')).toBeVisible();
    await expect(page.locator('#pwTabs')).toBeVisible();
    await page.locator('#pwClose').click();
    await expect(page.locator('#m2WorkspaceSettings')).toBeFocused();
    await page.locator('#m2NotificationButton').press('Enter');
    await expect(page.locator('#m2Notifications')).toBeVisible();
    await expect(page.locator('#m2Notifications #managerName')).toHaveText('Мария');
    await expect(page.locator('#m2Notifications #managerShiftBtn')).toBeVisible();
    await expect(page.locator('#notificationStatus')).toBeVisible();
    await page.locator('#m2NotificationsClose').click();
    await expect(page.locator('#m2NotificationButton')).toBeFocused();
    await settle(fixture);
    expect(fixture.calls.length).toBe(initial);
    await page.setViewportSize({width,height:420});
    expect((await page.locator('.inboxHead').boundingBox()).height).toBeLessThanOrEqual(170);
    expect((await page.locator('#inboxList').boundingBox()).height).toBeGreaterThanOrEqual(180);
    await fitsViewport(page);clean(fixture);
  });
  test('compact activity stays beside the name and leaves two lines for a message preview',async({page})=>{
    const fixture=await setup(page,{longPreview:true}),item=page.locator('#inboxList [data-conversation-id="11"]'),activity=item.locator('.leadActivityTime');
    const formatted=await page.evaluate(()=>Manager2Presentation.formatInboxActivity('2026-10-14 12:00:00'));
    await expect(activity).toHaveText(formatted.text);await expect(activity).toHaveAttribute('title','Последняя активность: '+formatted.exact);await expect(activity).toHaveAttribute('aria-label','Последняя активность: '+formatted.exact);
    await expect(item.locator('.leadTitleLine .leadActivityTime')).toHaveCount(1);await expect(item.locator('.leadSignals')).toHaveCount(0);await expect(item.locator('.m2ChatMeta .unreadBadge')).toHaveText('2');
    for(const height of [900,420]){
      await page.setViewportSize({width,height});
      const geometry=await item.evaluate(element=>{
        const title=element.querySelector('.leadTitle').getBoundingClientRect(),time=element.querySelector('.leadActivityTime').getBoundingClientRect(),identity=element.querySelector('.leadIdentity').getBoundingClientRect(),preview=element.querySelector('.leadPreview'),box=preview.getBoundingClientRect(),lineHeight=parseFloat(getComputedStyle(preview).lineHeight);
        return {sameRow:Math.abs((title.top+title.height/2)-(time.top+time.height/2))<=2,noOverlap:title.right+4<=time.left,fullPreview:Math.abs(box.right-identity.right)<=1&&Math.abs(box.left-identity.left)<=1,twoLines:box.height>lineHeight*1.5&&box.height<=lineHeight*2+1,compact:element.getBoundingClientRect().height<=130};
      });
      console.log('ROW_GEOMETRY',width,height,JSON.stringify(await item.evaluate(el=>({height:el.getBoundingClientRect().height,padding:getComputedStyle(el).padding,children:[...el.querySelectorAll('.leadPrimary,.leadIdentity,.leadTitleLine,.leadTitle,.leadActivityTime,.leadPreview,.m2ChatMeta')].map(n=>({className:n.className,rect:{width:n.getBoundingClientRect().width,height:n.getBoundingClientRect().height},css:{display:getComputedStyle(n).display,margin:getComputedStyle(n).margin,lineHeight:getComputedStyle(n).lineHeight,whiteSpace:getComputedStyle(n).whiteSpace,gridColumns:getComputedStyle(n).gridTemplateColumns}}))}))));expect(geometry).toEqual({sameRow:true,noOverlap:true,fullPreview:true,twoLines:true,compact:true});await fitsViewport(page);
    }
    clean(fixture);
  });
  test('client name opens an optional card and returns focus without losing text or attachments',async({page})=>{
    const fixture=await setup(page);await openConversation(page);await settle(fixture);
    const title=page.locator('#conversationTitle'),card=page.locator('#leadZone'),workspace=page.locator('#workspaceRoot');
    await expect(title).toHaveJSProperty('tagName','BUTTON');
    await expect(card).not.toBeVisible();
    await expect(workspace).not.toHaveClass(/m2ClientCardOpen/);
    await page.locator('#replyText').fill('Черновик остаётся при открытии карточки');
    await page.locator('#replyFile').setInputFiles({name:'messenger-fixture.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic attachment, never sent')});
    const attachment=await page.locator('#mediaSelection').textContent();
    // Message editing performs one canonical debounced read after transcript rendering.
    // Drain that initial request before measuring the disclosure's zero extra reads.
    await expect.poll(()=>fixture.calls.filter(call=>call.action==='edit_candidates').length).toBe(1);await settle(fixture);
    const before=fixture.calls.length;
    for(const opener of ['#conversationTitle','#mobileLeadBtn']){
      await page.locator(opener).press('Enter');
      await expect(card).toBeVisible();
      await expect(page.locator('#mobileLeadClose')).toBeVisible();
      if(width>1100)await expect(workspace).toHaveClass(/m2ClientCardOpen/);
      else await expect(workspace).toHaveAttribute('data-mobile-screen','lead');
      await expect(page.locator('#m2ClientName')).toHaveText('Анна Морозова');
      await clientCardHeaderFits(page);await page.setViewportSize({width,height:420});await clientCardHeaderFits(page);await page.setViewportSize({width,height:900});
      await page.locator('#mobileLeadClose').click();
      await expect(card).not.toBeVisible();
      await expect(page.locator(opener)).toBeFocused();
      await expect(page.locator('#replyText')).toHaveValue('Черновик остаётся при открытии карточки');
      await expect(page.locator('#mediaSelection')).toHaveText(attachment);
    }
    await title.focus();await page.keyboard.press('Alt+l');await expect(card).toBeVisible();
    await page.locator('#mobileLeadClose').click();await expect(card).not.toBeVisible();await expect(page.locator('#mobileLeadBtn')).toBeFocused();
    const actions=page.getByLabel('Действия с диалогом',{exact:true});await actions.press('Enter');
    await expect(page.getByRole('button',{name:'Закрыть обращение',exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Закрыть',exact:true})).toHaveCount(0);
    await actions.press('Escape');await expect(actions).toBeFocused();await expect(card).not.toBeVisible();
    await settle(fixture);expect(fixture.calls.length).toBe(before);
    await page.setViewportSize({width,height:420});
    await page.locator('#replyText').focus();
    await fitsViewport(page);
    for(const id of ['replyText','sendReply']){
      const box=await page.locator('#'+id).boundingBox();
      expect(box.y).toBeGreaterThanOrEqual(0);expect(box.y+box.height).toBeLessThanOrEqual(421);
    }
    expect(await page.locator('#messages').evaluate(element=>element.clientHeight)).toBeGreaterThanOrEqual(40);
    await expect(page.locator('#mediaSelection')).toHaveText(attachment);clean(fixture);
  });
});

for(const role of ['manager','admin'])test('Messenger ownership is compact and role appropriate: '+role,async({page})=>{
  await page.setViewportSize({width:1440,height:900});const fixture=await setup(page,{role});
  const own=page.locator('#inboxList [data-conversation-id="11"]'),free=page.locator('#inboxList [data-conversation-id="12"]');
  for(const item of [own,free]){
    await expect(item.locator('.m2OwnershipMark')).toBeVisible();
    await expect(item.locator('.leadAvatar')).toBeVisible();
    await expect(item.locator('.leadPreview')).toHaveText('Ждём варианты у моря');
    for(const selector of ['.leadTrip','.leadActionRow','.leadContextRow','.leadSource:not(.leadActivityTime)'])for(const element of await item.locator(selector).all())await expect(element).not.toBeVisible();
  }
  const ownLabel=await own.locator('.m2OwnershipMark').evaluate(element=>element.getAttribute('aria-label')||element.title);
  const freeLabel=await free.locator('.m2OwnershipMark').evaluate(element=>element.getAttribute('aria-label')||element.title);
  expect(ownLabel).toMatch(/мой|ваш|свой|мария/i);expect(freeLabel).toMatch(/свобод|не назначен/i);
  const colors=await page.locator('#inboxList .m2OwnershipMark').evaluateAll(elements=>elements.map(element=>getComputedStyle(element).backgroundColor));
  expect(colors[0]).not.toBe(colors[1]);
  await expect(own.locator('.leadReplyStatus')).toHaveText('Ждёт ответа');
  await expect(own.locator('.m2ChannelBadge')).toHaveText('MAX');
  await expect(free.locator('.m2ChannelBadge')).toHaveText('TG');
  await expect(free.locator('.leadWaitCompact')).toBeVisible();
  if(role==='admin')await expect(own).toContainText('Мария');
  else await expect(own).not.toContainText('Мария');
  clean(fixture);
});

test('Messenger saved tabs remain usable and pinned proxy keeps canonical selection after rerender',async({page})=>{
  await page.setViewportSize({width:390,height:900});const fixture=await setup(page,{savedTabs:true});
  const tab=page.locator('#pwLane [data-pw-selection="tab:5"]'),pin=page.locator('#m2PinnedToggle');
  await expect(tab).toBeVisible();await expect(tab).toHaveText('MAX — ждут ответа');
  await tab.click();await expect(tab).toHaveAttribute('aria-pressed','true');
  await expect(page.locator('#inboxList .leadItem')).toHaveCount(1);
  expect(fixture.calls.filter(call=>call.path==='/manager/personal-workspace-api.php'&&call.action==='list').at(-1)).toMatchObject({selection:'tab:5'});
  await pin.press('Enter');await expect(pin).toHaveAttribute('aria-pressed','true');
  await expect(page.locator('#inboxList .leadItem')).toHaveCount(1);await settle(fixture);
  expect(fixture.calls.filter(call=>call.path==='/manager/personal-workspace-api.php'&&call.action==='list').at(-1)).toMatchObject({selection:'pinned'});
  await pin.press('Enter');await expect(pin).toHaveAttribute('aria-pressed','false');
  await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);
  expect(fixture.calls.filter(call=>call.path==='/manager/pipeline-api.php'&&call.action==='list').at(-1)).toMatchObject({queue:'all'});
  await pin.press('Enter');await expect(pin).toHaveAttribute('aria-pressed','true');
  await page.evaluate(()=>WorkspaceV2PersonalWorkspace.rendered());
  await expect(pin).toBeVisible();await expect(pin).toHaveAttribute('aria-pressed','true');
  await expect(page.locator('#m2InboxTools #m2PinnedToggle')).toHaveCount(1);
  await page.locator('#m2WorkspaceSettings').click();await expect(page.locator('#pwDialog')).toBeVisible();
  await expect(page.locator('[data-pw-edit-tab="5"]')).toBeVisible();await expect(page.locator('[data-pw-delete-tab="5"]')).toBeVisible();
  await page.locator('#pwClose').click();await expect(page.locator('#m2WorkspaceSettings')).toBeFocused();
  await fitsViewport(page);clean(fixture);
});

test('Messenger client card follows viewport transitions and keeps expanded state coherent',async({page})=>{
  await page.setViewportSize({width:1440,height:900});const fixture=await setup(page);await openConversation(page);
  await page.locator('#conversationTitle').click();await expect(page.locator('#workspaceRoot')).toHaveClass(/m2ClientCardOpen/);
  await page.setViewportSize({width:390,height:900});
  await expect(page.locator('#workspaceRoot')).not.toHaveClass(/m2ClientCardOpen/);
  await expect(page.locator('#conversationTitle')).toHaveAttribute('aria-expanded','false');
  await page.setViewportSize({width:1440,height:900});await expect(page.locator('#leadZone')).not.toBeVisible();
  await expect(page.locator('#conversationTitle')).toHaveAttribute('aria-expanded','false');
  await page.setViewportSize({width:390,height:900});
  await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#workspaceRoot')).toHaveAttribute('data-mobile-screen','conversation');
  await page.locator('#conversationTitle').click();await expect(page.locator('#conversationTitle')).toHaveAttribute('aria-expanded','true');
  await page.setViewportSize({width:1440,height:900});await expect(page.locator('#leadZone')).not.toBeVisible();
  await expect(page.locator('#conversationTitle')).toHaveAttribute('aria-expanded','false');clean(fixture);
});
