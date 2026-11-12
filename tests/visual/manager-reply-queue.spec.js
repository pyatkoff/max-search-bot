const {test,expect}=require('@playwright/test');
const {setup,root}=require('./fixtures/personal-workspace');
const fs=require('node:fs'),path=require('node:path');

const clone=value=>JSON.parse(JSON.stringify(value));
const instant=1792141200;
function queueState(id){return {schema:1,context:String(id).padStart(64,'0'),version:0,needed:true,customer_message_id:60,request_event_id:1,manager_message_id:58,queue_event_id:1,
 waiting_since_unix:instant-600,waiting_since:new Date((instant-600)*1000).toISOString(),wait_age_seconds:600,wait_time_known:true,
 can_resolve:true,can_undo:false,resolution:null};}
async function fixture(page,{resolveMode='success'}={}){
 const states=new Map([[11,queueState(11)],[12,queueState(12)]]),receipts=new Map(),queueCalls=[],held=[];
 const data=[11,12].map(id=>({id,customer_id:id+90,project_key:'anytour',manager_id:7,manager_name:'Мария',status:'manager',channel:'telegram',source_id:1,
  display_name:id===11?'Анна Морозова':'Алексей Иванов',original_display_name:id===11?'Анна Морозова':'Алексей Иванов',last_text:'Уточните варианты',last_sender_type:'customer',unread_count:3,
  awaiting_first_reply:0,awaiting_manager_reply:1,wait_age_seconds:7200,reply_wait_started_unix:instant-600,reply_wait_started_at:new Date((instant-600)*1000).toISOString(),reply_wait_age_seconds:600,reply_wait_time_known:true}));
 const messages=Array.from({length:60},(_,i)=>({id:i+1,direction:i%3?'inbound':'outbound',sender_type:i%3?'customer':'manager',manager_id:i%3?null:7,
  text:'Сообщение '+(i+1)+'. Синтетическая переписка для проверки сохранности места чтения и черновика.',created_at:'2026-10-16 11:00:00'}));
 const f=await setup(page,{rows:data,messages,beforeNavigate:async()=>{
  await page.route('**/manager/api.php',async route=>{
   const d=route.request().postDataJSON()||{},id=Number(d.conversation_id),q=states.get(id);
   const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
   if(d.action==='detail'&&q)return reply({ok:true,conversation:clone(data.find(row=>row.id===id)),messages:clone(messages),reply_queue:clone(q),delivery_failure:null,has_manager_reply:true,history:{has_more:false,before_id:1,page_size:200,mode:'latest'}});
   if(!['reply_queue_resolve','reply_queue_undo','reply_queue_receipt'].includes(d.action))return route.fallback();
   queueCalls.push(clone(d));expect(d.csrf).toBe('fixture-csrf');expect(q).toBeTruthy();expect(d.operation_id).toMatch(/^[a-f0-9]{32}$/);
   if(d.action==='reply_queue_receipt')return reply({ok:true,found:receipts.has(d.operation_id),result:receipts.get(d.operation_id)||null,reply_queue:clone(q)});
   expect(d.context).toBe(q.context);expect(d.expected_version).toBe(q.version);
   if(d.action==='reply_queue_resolve'){
    expect(d.through_message_id).toBe(q.customer_message_id);expect(d.through_request_event_id).toBe(q.request_event_id);
    q.version++;q.queue_event_id++;q.needed=false;q.can_resolve=false;q.can_undo=true;q.resolution={actor_id:7,actor_name:'Мария',operation_id:d.operation_id,recorded_at:instant,through_message_id:d.through_message_id,through_request_event_id:d.through_request_event_id};
   }else{
    expect(d.resolution_actor_id).toBe(q.resolution.actor_id);expect(d.resolution_operation_id).toBe(q.resolution.operation_id);
    q.version++;q.queue_event_id++;q.needed=true;q.can_resolve=true;q.can_undo=false;q.resolution=null;
   }
   const row=data.find(row=>row.id===id);row.awaiting_manager_reply=Number(q.needed);row.reply_wait_age_seconds=q.needed?q.wait_age_seconds:null;row.reply_wait_time_known=q.needed&&q.wait_time_known;
   const result={ok:true,saved_version:q.version,reply_queue:clone(q)};receipts.set(d.operation_id,{ok:true,saved_version:q.version});
   if(d.action==='reply_queue_resolve'&&resolveMode==='hold')await new Promise(resolve=>held.push(resolve));
   if(d.action==='reply_queue_resolve'&&resolveMode==='lost')return route.abort('failed');
   return reply(result);
  });
 }});
 return {...f,states,queueCalls,data,release:()=>held.shift()?.(),held:()=>held.length,
  incoming(id,age=0){const q=states.get(id),row=data.find(row=>row.id===id);q.customer_message_id++;q.needed=true;q.can_resolve=true;q.waiting_since_unix=instant;q.waiting_since=new Date(instant*1000).toISOString();q.wait_age_seconds=age;q.wait_time_known=true;
   Object.assign(row,{awaiting_manager_reply:1,reply_wait_started_unix:instant,reply_wait_started_at:q.waiting_since,reply_wait_age_seconds:age,reply_wait_time_known:true});}};
}
async function open(page,id=11){if(await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='inbox'))await page.locator('#mobileBack').click();await page.locator(`#inboxList [data-conversation-id="${id}"] .m2RowOpen`).click();await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyQueueStatus')).toBeVisible();}
async function resolve(page){await page.locator('#m2ConversationMenuToggle').click();await expect(page.locator('#replyQueueResolve')).toBeVisible();await page.locator('#replyQueueResolve').click();}
async function settleReadingPosition(page){
 // mouse.wheel resolves before scrolling finishes. Capture the preservation
 // baseline only after both the wheel and composer layout have settled.
 await page.locator('#messages').evaluate(el=>new Promise((resolve,reject)=>{
  const read=()=>({top:el.scrollTop,clientHeight:el.clientHeight});
  let previous=read(),stableSince=performance.now(),frame;
  const timer=setTimeout(()=>{cancelAnimationFrame(frame);reject(new Error('Reading position did not settle within 1000ms: '+JSON.stringify({previous,current:read()})))},1000);
  const sample=now=>{
   const current=read();
   if(current.top!==previous.top||current.clientHeight!==previous.clientHeight)stableSince=now;
   previous=current;
   if(now-stableSince>=100){clearTimeout(timer);resolve();return;}
   frame=requestAnimationFrame(sample);
  };
  frame=requestAnimationFrame(sample);
 }));
}
function clean(f){expect(f.unexpected).toEqual([]);expect(f.errors).toEqual([]);expect(f.calls.filter(c=>['send','send_media','set_working','set_stage','set_outcome','create_task','update_task','close','release','reopen'].includes(c.action))).toEqual([]);}
async function capture(page,testInfo,label){const dir=path.join(root,'manager2-artifacts');fs.mkdirSync(dir,{recursive:true});const image=await page.screenshot({animations:'disabled',path:path.join(dir,label+'.png')});await testInfo.attach(label,{body:image,contentType:'image/png'});}

for(const width of [390,430,768,1440])test(`reply queue decision and undo preserve the active reply ${width}`,async({page},testInfo)=>{
 await page.setViewportSize({width,height:740});const f=await fixture(page);await open(page);
 await page.locator('#replyText').fill('Неотправленный ответ не менять');await page.locator('#replyFile').setInputFiles([
  {name:'first.txt',mimeType:'text/plain',buffer:Buffer.from('First synthetic attachment')},{name:'second.txt',mimeType:'text/plain',buffer:Buffer.from('Second synthetic attachment')}]);
 await expect(page.locator('.mediaFileName')).toHaveText(['first.txt','second.txt']);await page.locator('#messages').hover();await page.mouse.wheel(0,-900);
 await expect.poll(()=>page.locator('#messages').evaluate(el=>el.scrollHeight-el.clientHeight-el.scrollTop)).toBeGreaterThan(400);
 await settleReadingPosition(page);
 await page.locator('#messages').evaluate(el=>{window.replyQueueProof={messages:el,top:el.scrollTop,geometry:{top:el.scrollTop,clientHeight:el.clientHeight,scrollHeight:el.scrollHeight,viewportTop:el.getBoundingClientRect().top,firstTop:el.querySelector('.msg')?.getBoundingClientRect().top},first:el.querySelector('.msg'),media:[...document.querySelectorAll('.mediaSelectedItem')]}});
 await resolve(page);await expect(page.locator('#replyQueueText')).toContainText('Ответ не требуется · Мария');await expect(page.locator('#replyQueueText')).toContainText('МСК');await expect(page.locator('#replyQueueUndo')).toBeVisible();await expect(page.locator('#replyQueueUndo')).toBeFocused();
 await expect(page.locator('#replyText')).toHaveValue('Неотправленный ответ не менять');await expect(page.locator('#sendReply')).toBeEnabled();
 const preserved=await page.evaluate(()=>{const p=replyQueueProof,el=p.messages,media=[...document.querySelectorAll('.mediaSelectedItem')];return {state:{messages:document.getElementById('messages')===el,first:el.querySelector('.msg')===p.first,scroll:Math.abs(el.scrollTop-p.top)<1,files:media.length,firstFile:media[0]===p.media[0],secondFile:media[1]===p.media[1],hasFile:WorkspaceV2Media.hasFile()},geometry:{baseline:p.geometry,current:{top:el.scrollTop,clientHeight:el.clientHeight,scrollHeight:el.scrollHeight,viewportTop:el.getBoundingClientRect().top,firstTop:el.querySelector('.msg')?.getBoundingClientRect().top},scrollDelta:el.scrollTop-p.top}}});
 expect(preserved.state,'Reading position geometry: '+JSON.stringify(preserved.geometry)).toEqual({messages:true,first:true,scroll:true,files:2,firstFile:true,secondFile:true,hasFile:true});
 await expect(page.locator('.mediaFileName')).toHaveText(['first.txt','second.txt']);
 expect(f.data[0].status).toBe('manager');expect(f.data[0].manager_id).toBe(7);expect(f.data[0].unread_count).toBe(3);expect(f.queueCalls.map(c=>c.action)).toEqual(['reply_queue_resolve']);
 await capture(page,testInfo,'reply-queue-resolved-'+width);
 await page.locator('#replyQueueUndo').click();await expect(page.locator('#replyQueueText')).toContainText('Ожидает ответа · 10 мин');await expect(page.locator('#replyText')).toHaveValue('Неотправленный ответ не менять');
 expect(f.queueCalls.map(c=>c.action)).toEqual(['reply_queue_resolve','reply_queue_undo']);expect(f.states.get(11).waiting_since_unix).toBe(instant-600);
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);await capture(page,testInfo,'reply-queue-'+width);clean(f);
});

test('a late reply queue decision for A does not repaint B or block its composer',async({page})=>{
 await page.setViewportSize({width:390,height:740});const f=await fixture(page,{resolveMode:'hold'});await open(page);await page.locator('#replyText').fill('Черновик Анны');await resolve(page);await expect.poll(f.held).toBe(1);await expect(page.locator('#sendReply')).toBeEnabled();
 await open(page,12);await page.locator('#replyText').fill('Черновик Алексея');f.release();await expect.poll(()=>f.pending.size).toBe(0);
 await expect(page.locator('#conversationTitle')).toHaveText('Алексей Иванов');await expect(page.locator('#replyText')).toHaveValue('Черновик Алексея');await expect(page.locator('#replyQueueText')).toContainText('Ожидает ответа');await expect(page.locator('#replyQueueText')).not.toContainText('Последняя отметка');await expect(page.locator('#replyQueueUndo')).toBeHidden();
 await open(page,11);await expect(page.locator('#replyText')).toHaveValue('Черновик Анны');await expect(page.locator('#replyQueueText')).toContainText('Ответ не требуется · Мария');clean(f);
});

test('lost reply queue acknowledgement is reconciled after reload without another mutation',async({page})=>{
 await page.setViewportSize({width:1440,height:740});const f=await fixture(page,{resolveMode:'lost'});await open(page);await page.locator('#replyText').fill('Черновик переживает проверку отметки');await resolve(page);
 await expect(page.locator('#replyQueueCheck')).toBeEnabled();await expect(page.locator('#replyQueueText')).toContainText('Результат пока не подтверждён');await page.reload();
 await expect(page.locator('#replyText')).toHaveValue('Черновик переживает проверку отметки');await expect(page.locator('#replyQueueCheck')).toBeEnabled();expect(f.queueCalls.filter(c=>c.action==='reply_queue_resolve')).toHaveLength(1);
 await page.locator('#replyQueueCheck').click();await expect(page.locator('#replyQueueUndo')).toBeVisible();await expect(page.locator('#replyQueueCheck')).toBeHidden();expect(f.queueCalls.map(c=>c.action)).toEqual(['reply_queue_resolve','reply_queue_receipt']);expect(f.queueCalls[1].operation_id).toBe(f.queueCalls[0].operation_id);clean(f);
});

test('a new inbound reopens the current series without restarting its timer on later messages',async({page})=>{
 await page.clock.install({time:new Date(instant*1000)});await page.setViewportSize({width:430,height:740});const f=await fixture(page);await open(page);await resolve(page);await expect(page.locator('#replyQueueUndo')).toBeVisible();
 f.incoming(11,0);await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible());await expect(page.locator('#replyQueueText')).toContainText('Ожидает ответа · <1 мин');await expect(page.locator('#replyQueueText')).toContainText('Последняя отметка: Ответ не требуется · Мария');
 const q=f.states.get(11);q.customer_message_id++;q.wait_age_seconds=125;f.data[0].reply_wait_age_seconds=125;
 await page.evaluate(()=>WorkspaceV2Conversation.refreshVisible());await expect(page.locator('#replyQueueText')).toContainText('Ожидает ответа · 2 мин');expect(q.waiting_since_unix).toBe(instant);expect(f.queueCalls.map(c=>c.action)).toEqual(['reply_queue_resolve']);clean(f);
});

for(const width of [390,430,768,1440])test(`list and board share current reply priority after reading and no-reply ${width}`,async({page},testInfo)=>{
 await page.setViewportSize({width,height:740});const f=await fixture(page);
 Object.assign(f.data[0],{unread_count:0,next_task_title:'Задача на завтра',operational_task_state:'soon',operational_task_rank:2});
 Object.assign(f.data[1],{awaiting_first_reply:1,awaiting_manager_reply:0,reply_wait_age_seconds:null,reply_wait_time_known:false,unread_count:20,next_task_title:'Задача на завтра',operational_task_state:'soon',operational_task_rank:2});
 const render=async()=>{await page.evaluate(()=>WorkspaceV2Inbox.load({preserveScroll:true}));await page.evaluate(()=>WorkspaceV2Kanban.render(WorkspaceV2Inbox.getLoadedSelection()));};
 await render();
 await expect(page.locator('.kanbanCard').first()).toHaveAttribute('data-id','11');
 await expect(page.locator('.kanbanCard[data-id="11"] .kanbanWait')).toContainText('10 мин');
 await expect(page.locator('.kanbanCard[data-id="12"] .kanbanWait')).toHaveCount(0);
 await expect(page.locator('.kanbanColumnTitle small').first()).toContainText('1 без ответа');
 f.data[1].unread_count=0;await render();await expect(page.locator('.kanbanCard').first()).toHaveAttribute('data-id','11');
 await capture(page,testInfo,'reply-queue-ranking-'+width);
 await page.locator('.kanbanCard[data-id="11"] .kanbanOpen').click();await expect(page.locator('#replyText')).toBeEnabled();
 await resolve(page);await expect(page.locator('#replyQueueText')).toContainText('Ответ не требуется');
 await render();await expect(page.locator('.kanbanWait')).toHaveCount(0);await expect(page.locator('.kanbanColumnTitle small').first()).not.toContainText('без ответа');
 f.incoming(11,7);await render();await expect(page.locator('.kanbanCard').first()).toHaveAttribute('data-id','11');
 await expect(page.locator('.kanbanCard[data-id="11"] .kanbanWait')).toContainText('<1 мин');
 await expect(page.locator('.kanbanColumnTitle small').first()).toContainText('1 без ответа');
 expect(f.data[0].unread_count).toBe(0);expect(f.data[0].awaiting_first_reply).toBe(0);clean(f);
});
