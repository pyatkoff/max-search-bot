const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root}=require('./fixtures/internal-notes');

// The fixture renders the real Manager2 PHP shell and production assets. These
// extra routes are local synthetic assistant reads/decisions, never live data.
async function setupAssistant(page){
  const base=await setup(page);
  const calls=[],detailResponses=[],heldDetails=[];
  const recommendation=(changes={})=>({revision:'a'.repeat(64),next_action:'check_offer',reason:'Клиент выбрал два отеля. Нужно сравнить питание и вылет из СПб.',checks:['Сравнить выбранные отели на двоих.','Проверить тарифы с завтраками и полную стоимость.'],prepared_at:new Date().toISOString(),expires_at:new Date(Date.now()+3600000).toISOString(),latest_message_id:35,evidence_message_ids:[35],history_incomplete:false,decision:null,...changes});
  const items=new Map([
    [11,{conversation_id:11,display_name:'Свадебное путешествие',channel:'telegram',last_message_at:new Date().toISOString(),tasks:[],recommendation:recommendation()}],
    [12,{conversation_id:12,display_name:'Клиент выбирает отель',channel:'telegram',last_message_at:new Date().toISOString(),tasks:[],recommendation:recommendation({next_action:'no_contact',reason:'Клиент обсуждает варианты. Сейчас отвечать не нужно.',checks:['Дождаться обратной связи по подборке.']})}],
    [901,{conversation_id:901,display_name:'Ранний клиент с задачей',channel:'max',last_message_at:'2026-09-01T10:00:00Z',tasks:[{id:31,title:'Проверить ответ поставщика',status:'open',due_state:'overdue',due_at_utc:'2026-10-19T09:00:00Z'}],recommendation:null}]
  ]);
  await page.route('**/manager/ai-draft.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,prepared:false})}));
  await page.route('**/manager/assistant-api.php',async route=>{
    const d=route.request().postDataJSON();calls.push(d);expect(d.csrf).toBe('fixture-csrf');
    let result,status=200,raw;
    if(d.action==='list')result=d.cursor?{ok:true,items:[items.get(901)],has_more:false,next_cursor:null}:{ok:true,items:[items.get(11),items.get(12)],has_more:true,next_cursor:'v1.'+'a'.repeat(64)+'.12'};
    else if(d.action==='detail'){
      const next=detailResponses.shift();
      result=next&&Object.prototype.hasOwnProperty.call(next,'result')?next.result:{ok:true,item:items.get(Number(d.conversation_id))};
      status=next?.status||200;raw=next?.raw;
      // Capture the original response before holding it: a later client/account
      // switch must not accidentally turn a stale response into fresh fixture data.
      result=JSON.parse(JSON.stringify(result));
      if(next?.hold)await new Promise(resolve=>heldDetails.push(resolve));
    }
    else if(d.action==='decision'){const item=items.get(Number(d.conversation_id));expect(d.revision).toBe(item.recommendation.revision);expect(d.expected_decision).toBe(item.recommendation.decision);item.recommendation={...item.recommendation,decision:d.decision};result={ok:true};}
    else throw Error('Unexpected assistant fixture action '+d.action);
    return route.fulfill({status,contentType:'application/json',body:raw===undefined?JSON.stringify(result):raw});
  });
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
  return{base,calls,items,queueDetail:(...responses)=>detailResponses.push(...responses),heldDetailCount:()=>heldDetails.length,releaseDetail:()=>heldDetails.shift()?.()};
}
function clean(s){expect(s.base.errors).toEqual([]);expect(s.base.unexpected).toEqual([]);expect(s.base.calls.filter(c=>['send','take','release','close','reopen','create_task','update_task','set_task_completed'].includes(c.action))).toEqual([]);expect(s.calls.filter(c=>!['list','detail','decision'].includes(c.action))).toEqual([])}
async function bounded(page){
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  const box=await page.locator('#assistantDialog').boundingBox(),v=page.viewportSize();expect(box.x).toBeGreaterThanOrEqual(-1);expect(box.x+box.width).toBeLessThanOrEqual(v.width+1);expect(box.y).toBeGreaterThanOrEqual(-1);expect(box.y+box.height).toBeLessThanOrEqual(v.height+1);await expect(page.locator('#assistantClose')).toBeInViewport();
}
async function capture(page,browser,width,label){const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,`assistant-${browser}-${width}-${label}.png`)})}

for(const width of [390,430,768,1440])test('Manager assistant unifies list and current-client advice without touching reply '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await setupAssistant(page);expect(s.calls).toEqual([]);
  await page.locator('#assistantOpen').click();await expect(page.locator('[data-assistant-client]')).toHaveCount(2);expect(s.calls.map(c=>c.action)).toEqual(['list']);await bounded(page);await capture(page,browserName,width,'list');
  await page.locator('#assistantMore').click();await expect(page.locator('[data-assistant-client]')).toHaveCount(3);await expect(page.locator('#assistantMore')).toBeHidden();expect(s.calls.at(-1).cursor).toBe('v1.'+'a'.repeat(64)+'.12');
await page.locator('[data-assistant-client="11"]').click();await expect(page.locator('#assistantDetail')).toContainText('Сравнить выбранные отели на двоих.');await expect(page.locator('#assistantPlan')).toBeVisible();if(width<768)await expect(page.locator('.assistantToolbar')).toBeHidden();await page.locator('.assistantSource summary').click();await expect(page.locator('.assistantSource')).toContainText('Сообщений в основании: 1');await bounded(page);await capture(page,browserName,width,'client');
  await page.locator('[data-assistant-decision="done"]').click();await expect(page.locator('#assistantDetail')).toContainText('Отмечено выполненным');expect(s.calls.filter(c=>c.action==='decision')).toHaveLength(1);
  if(width<768)await page.locator('#assistantBack').click();await page.locator('[data-assistant-client="12"]').click();await expect(page.locator('#assistantDetail')).toContainText('Дождаться клиента');await expect(page.locator('#assistantPlan')).toHaveCount(0);await expect(page.locator('[data-assistant-decision]')).toHaveCount(0);
await page.setViewportSize({width,height:420});await bounded(page);await capture(page,browserName,width,'short');await page.locator('#assistantClose').click();if(width>=768)expect(await page.evaluate(()=>document.documentElement.scrollHeight)).toBeLessThanOrEqual(421);
  await page.setViewportSize({width,height:900});await page.evaluate(()=>WorkspaceV2Conversation.open(11));await expect(page.locator('#replyText')).toBeEnabled();
  await page.locator('#replyText').fill('Мой незаконченный ответ');await page.locator('#replyFile').setInputFiles({name:'assistant-keep.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained attachment')});
  const draft=await page.locator('#replyText').inputValue(),selection=await page.locator('#mediaSelection').textContent();
await expect(page.locator('.conversationIdentityText #assistantClientOpen')).toHaveCount(0);await page.locator('#m2ConversationMenuToggle').click();await page.locator('#assistantClientOpen').click();await expect(page.locator('#assistantDetail')).toContainText('Отмечено выполненным');expect(s.calls.filter(c=>c.action==='decision')).toHaveLength(1);await page.locator('#assistantClose').click();
  await expect(page.locator('#replyText')).toHaveValue(draft);await expect(page.locator('#mediaSelection')).toHaveText(selection);
  clean(s);
});

test('assistant does not present expired reasoning or a missing analysis as completed work',async({page})=>{
  const s=await setupAssistant(page);s.items.get(11).recommendation.expires_at=new Date(Date.now()-1000).toISOString();await page.locator('#assistantOpen').click();await page.locator('[data-assistant-client="11"]').click();await expect(page.locator('#assistantDetail')).toContainText('Нет свежего разбора');await expect(page.locator('#assistantDetail')).toContainText('Это не означает');await expect(page.locator('#assistantDetail')).not.toContainText('Клиент выбрал два отеля');await expect(page.locator('[data-assistant-decision]')).toHaveCount(0);expect(s.calls.every(c=>['list','detail'].includes(c.action))).toBe(true);clean(s);
});

async function selectAssistantClient(page,id=11){
  await page.locator('#assistantOpen').click();
  await page.locator(`[data-assistant-client="${id}"]`).click();
  await expect(page.locator('#assistantDetail')).toContainText(id===11?'Сравнить выбранные отели на двоих.':'Дождаться клиента');
}
function detailCalls(s){return s.calls.filter(call=>call.action==='detail')}
async function periodicDetail(page,s){
  const before=detailCalls(s).length;
  const response=page.waitForResponse(response=>response.url().endsWith('/manager/assistant-api.php')&&response.request().postDataJSON().action==='detail');
  await page.clock.runFor(20001);
  await expect.poll(()=>detailCalls(s).length).toBe(before+1);
  await (await response).finished();
}
async function rememberReadingState(page){
  await page.locator('.assistantSource summary').click();
  await expect(page.locator('.assistantSource')).toHaveAttribute('open','');
  return page.evaluate(()=>{
    const source=document.querySelector('.assistantSource'),control=source.querySelector('summary'),body=document.querySelector('.assistantBody'),text=source.querySelector('p').firstChild;
    // Native details, keyboard focus, a real Range and the actual scroll host.
    // Keep node references so restoring a lookalike DOM cannot satisfy this check.
    control.focus({preventScroll:true});
    const range=document.createRange();range.setStart(text,0);range.setEnd(text,Math.min(18,text.length));
    const selection=getSelection();selection.removeAllRanges();selection.addRange(range);
    body.scrollTop=Math.max(1,Math.min(body.scrollHeight-body.clientHeight,130));
    window.assistantReadingProof={source,control,article:source.closest('article'),client:document.querySelector('[data-assistant-client="11"]'),body,top:body.scrollTop,text:selection.toString(),anchor:selection.anchorNode,offset:selection.anchorOffset};
    return {top:body.scrollTop,text:selection.toString()};
  });
}
async function expectReadingState(page){
  await expect.poll(()=>page.evaluate(()=>{
    const p=window.assistantReadingProof,s=getSelection();
    return {source:p.source===document.querySelector('.assistantSource')&&p.source.isConnected,article:p.article===document.querySelector('.assistantRecommendation'),client:p.client===document.querySelector('[data-assistant-client="11"]'),expanded:p.source.open,focus:document.activeElement===p.control&&p.control.isConnected,scroll:Math.abs(p.body.scrollTop-p.top)<=1,selection:s.toString()===p.text&&s.anchorNode===p.anchor&&s.anchorOffset===p.offset};
  })).toEqual({source:true,article:true,client:true,expanded:true,focus:true,scroll:true,selection:true});
}

for(const viewport of [{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}])test(`unchanged periodic assistant refresh preserves native reading state ${viewport.width}x${viewport.height}`,async({page})=>{
  await page.setViewportSize(viewport);const s=await setupAssistant(page);await page.clock.install();
  s.items.get(11).recommendation.checks.push(...Array.from({length:16},(_,i)=>`Пункт проверки ${i+1}: сопоставить условия выбранного варианта по полному запросу клиента.`));
  await selectAssistantClient(page);const reading=await rememberReadingState(page);expect(reading.top).toBeGreaterThan(0);expect(reading.text).not.toBe('');
  await periodicDetail(page,s);await expectReadingState(page);
  // A slow identical response must preserve the same state while pending too.
  s.queueDetail({hold:true});const before=detailCalls(s).length;await page.clock.runFor(20001);await expect.poll(()=>s.heldDetailCount()).toBe(1);expect(detailCalls(s)).toHaveLength(before+1);await expectReadingState(page);
  const response=page.waitForResponse(response=>response.url().endsWith('/manager/assistant-api.php')&&response.request().postDataJSON().action==='detail');s.releaseDetail();await (await response).finished();await expectReadingState(page);await bounded(page);clean(s);
});

test('periodic assistant refresh invalidates changed expired denied and failed reasoning',async({page})=>{
  await page.setViewportSize({width:1440,height:900});const s=await setupAssistant(page);await page.clock.install();
  const original=JSON.parse(JSON.stringify(s.items.get(11).recommendation));
  for(const outcome of ['changed','expired','revoked','server-error','empty-response','unauthorized'])await test.step(outcome,async()=>{
  if(await page.locator('#assistantDialog').isVisible())await page.locator('#assistantClose').click();
  s.items.get(11).recommendation=JSON.parse(JSON.stringify(original));
  await selectAssistantClient(page);await page.locator('.assistantSource summary').click();
  const oldReason=s.items.get(11).recommendation.reason;
  if(outcome==='changed')s.items.get(11).recommendation={...s.items.get(11).recommendation,revision:'b'.repeat(64),reason:'Новый запрос: проверить только выбранный тариф с завтраками.',latest_message_id:36,evidence_message_ids:[36]};
  else if(outcome==='expired')s.items.get(11).recommendation.expires_at=new Date(Date.now()-1000).toISOString();
  else if(outcome==='revoked')s.queueDetail({status:403,result:{ok:false,error:'forbidden'}});
  else if(outcome==='server-error')s.queueDetail({status:500,result:{ok:false,error:'assistant_unavailable'}});
  else if(outcome==='empty-response')s.queueDetail({raw:''});
  else s.queueDetail({status:401,result:{ok:false,error:'unauthorized'}});
  await periodicDetail(page,s);await expect(page.locator('#assistantDetail')).not.toContainText(oldReason);
  if(outcome==='changed')await expect(page.locator('#assistantDetail')).toContainText('Новый запрос: проверить только выбранный тариф с завтраками.');
  else if(outcome==='expired'){await expect(page.locator('#assistantDetail')).toContainText('Нет свежего разбора');await expect(page.locator('[data-assistant-decision]')).toHaveCount(0)}
  else if(outcome==='unauthorized'){await expect(page.locator('#assistantDialog')).not.toBeVisible();await expect(page.locator('#managerAuthRecovery')).toBeVisible()}
  else{await expect(page.locator('#assistantStatus')).toHaveClass(/isError/);await expect(page.locator('[data-assistant-decision]')).toHaveCount(0)}
  });clean(s);
});

test('late periodic assistant reads cannot steal client navigation focus or account context',async({page})=>{
  const s=await setupAssistant(page);await page.clock.install();
  for(const destination of ['other-client','back','close','other-account'])await test.step(destination,async()=>{
  if(await page.locator('#assistantDialog').isVisible())await page.locator('#assistantClose').click();
  const mobile=destination==='back';await page.setViewportSize({width:mobile?390:1440,height:mobile?420:900});await selectAssistantClient(page);
  s.queueDetail({hold:true,result:{ok:true,item:{...s.items.get(11),recommendation:{...s.items.get(11).recommendation,reason:'Устаревший ответ от предыдущего запроса.'}}}});
  await page.clock.runFor(20001);await expect.poll(()=>s.heldDetailCount()).toBe(1);
  if(destination==='other-client'){await page.locator('[data-assistant-client="12"]').click();await expect(page.locator('#assistantDetail')).toContainText('Дождаться клиента')}
  else if(destination==='back')await page.locator('#assistantBack').click();
  else if(destination==='close')await page.locator('#assistantClose').click();
  else{
    await page.evaluate(()=>WorkspaceV2.showAuthRecovery());s.base.switchManager({id:8,role:'manager',display_name:'Другой менеджер',is_working:true});
    await page.locator('#managerAuthLogin').fill('isolated-account');await page.locator('#managerAuthPassword').fill('isolated-password');await page.locator('#managerAuthSubmit').click();await expect(page.locator('#managerAuthRecovery')).toBeHidden();
    await page.locator('#assistantOpen').click();await page.locator('[data-assistant-client="12"]').click();await expect(page.locator('#assistantDetail')).toContainText('Дождаться клиента');
  }
  // Cached client text renders before its protected detail read completes. Wait
  // for that navigation's intentional focus handoff before releasing older work.
  if(destination==='other-client'||destination==='other-account')await expect(page.locator('#assistantConversation')).toBeFocused();
  const focused=await page.evaluate(()=>{window.assistantNavigationFocus=document.activeElement;return{activeId:document.activeElement.id,selectedClient:document.querySelector('.assistantClient.isSelected')?.dataset.assistantClient||null}});
  const response=page.waitForResponse(response=>response.url().endsWith('/manager/assistant-api.php')&&response.request().postDataJSON().action==='detail'&&Number(response.request().postDataJSON().conversation_id)===11);s.releaseDetail();await (await response).finished();
  await expect(page.locator('#assistantDetail')).not.toContainText('Устаревший ответ от предыдущего запроса.');
  await expect.poll(()=>page.evaluate(()=>({sameControl:document.activeElement===window.assistantNavigationFocus,connected:window.assistantNavigationFocus.isConnected,activeId:document.activeElement.id,selectedClient:document.querySelector('.assistantClient.isSelected')?.dataset.assistantClient||null}))).toEqual({sameControl:true,connected:true,...focused});
  if(destination==='back'){await expect(page.locator('#assistantDialog')).not.toHaveClass(/hasSelection/);await expect(page.locator('[data-assistant-client="11"]')).toBeFocused()}
  else if(destination==='close'){await expect(page.locator('#assistantDialog')).not.toBeVisible();await expect(page.locator('#assistantOpen')).toBeFocused()}
  else{await expect(page.locator('#assistantDetail')).toContainText('Дождаться клиента');await expect(page.locator('#assistantDetail h3')).toHaveText('Клиент выбирает отель')}
  });clean(s);
});

for(const viewport of [{width:390,height:844},{width:430,height:844},{width:768,height:844},{width:1440,height:844},{width:390,height:420}])test(`assistant operational priority without AI and changed-order recovery ${viewport.width}x${viewport.height}`,async({page,browserName})=>{
  await page.setViewportSize(viewport);const s=await setupAssistant(page);
  const own={...s.items.get(11),recommendation:null,operational:{awaiting_manager_reply:true,due_task:false}};
  const due={...s.items.get(12),recommendation:null,operational:{awaiting_manager_reply:false,due_task:true},tasks:[{id:31,title:'Проверить ответ поставщика',status:'open',due_state:'overdue',due_at_utc:'2026-01-01 10:00:00'}]};
  s.items.clear();s.items.set(11,own);s.items.set(12,due);for(let id=100;id<120;id++)s.items.set(id,{...own,conversation_id:id,display_name:'Клиент '+id,operational:{awaiting_manager_reply:false,due_task:false}});
  let changed=false,restarted=false;
  await page.route('**/manager/assistant-api.php',async route=>{
    const d=route.request().postDataJSON();s.calls.push(d);expect(d.csrf).toBe('fixture-csrf');let result,status=200;
    if(d.action==='detail')result={ok:true,item:s.items.get(Number(d.conversation_id))};
    else if(d.action==='list'){
      if(d.cursor&&!changed){changed=true;s.items.get(11).operational.awaiting_manager_reply=false;result={ok:false,error:'list_changed'};status=409}
      else{
        if(changed&&!d.cursor)restarted=true;
        const all=[...s.items.values()].sort((a,b)=>Number(b.operational.awaiting_manager_reply)-Number(a.operational.awaiting_manager_reply)||Number(b.operational.due_task)-Number(a.operational.due_task)||b.conversation_id-a.conversation_id);
        const offset=d.cursor?all.findIndex(i=>i.conversation_id===Number(d.cursor.split('.').at(-1)))+1:0,items=all.slice(offset,offset+20),more=offset+items.length<all.length;
        result={ok:true,items,has_more:more,next_cursor:more?'v1.'+(changed?'b':'a').repeat(64)+'.'+items.at(-1).conversation_id:null};
      }
    }else throw Error('Unexpected write '+d.action);
    return route.fulfill({status,contentType:'application/json',body:JSON.stringify(result)});
  });
  await page.evaluate(()=>WorkspaceV2Conversation.open(11));await page.locator('#replyText').fill('Мой ответ остаётся в черновике');await page.locator('#replyFile').setInputFiles({name:'retained.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic retained file')});
  const selection=await page.locator('#mediaSelection').textContent();
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  await page.locator('#messages').hover();const beforeWheel=await page.locator('#messages').evaluate(box=>box.scrollTop);
  await page.mouse.wheel(0,-250);
  // WebKit marks reading intent before its native wheel animation finishes.
  // Capture the baseline only after the requested real scroll has completed.
  await expect.poll(()=>page.locator('#messages').evaluate(box=>box.scrollTop)).toBe(Math.max(0,beforeWheel-250));
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2Jump.isFollowingLatest())).toBe(false);
  await page.evaluate(()=>{const host=document.querySelector('#messages');window.priorityReading={node:host.querySelector('[data-scroll-message-key]'),host,top:host.scrollTop}});
  if(viewport.width<=740)await page.locator('#mobileBack').click();
  await page.locator('#assistantOpen').click();await expect(page.locator('[data-assistant-client]')).toHaveCount(20);await expect(page.locator('[data-assistant-client="11"]')).toBeVisible();await expect(page.locator('[data-assistant-client="12"]')).toBeVisible();await page.locator('[data-assistant-filter="actions"]').click();await expect(page.locator('[data-assistant-client]')).toHaveCount(2);
  await bounded(page);await capture(page,browserName,viewport.width,`operational-list-${viewport.height}`);
  await page.locator('[data-assistant-client="11"]').click();await expect(page.locator('.assistantOperational')).toContainText('Обращение без записанного ответа');await expect(page.locator('#assistantDetail')).toContainText('Нет свежего разбора');await expect(page.locator('#assistantPlan')).toHaveCount(0);await bounded(page);await capture(page,browserName,viewport.width,`operational-detail-${viewport.height}`);
  await page.locator('#assistantConversation').click();await expect(page.locator('#replyText')).toHaveValue('Мой ответ остаётся в черновике');await expect(page.locator('#mediaSelection')).toHaveText(selection);expect(await page.evaluate(()=>({host:priorityReading.host===document.querySelector('#messages'),scroll:Math.round(priorityReading.host.scrollTop-priorityReading.top),node:!!priorityReading.node?.isConnected}))).toEqual({host:true,scroll:0,node:true});
  if(viewport.width<=740)await page.locator('#mobileBack').click();
  await page.locator('#assistantOpen').click();await page.locator('[data-assistant-filter="all"]').click();await page.locator('#assistantMore').click();await expect.poll(()=>restarted).toBe(true);await expect(page.locator('[data-assistant-client]')).toHaveCount(20);await expect(page.locator('[data-assistant-client="11"]')).toHaveCount(0);await expect(page.locator('[data-assistant-client="12"]')).toBeVisible();
  expect(s.calls.filter(c=>c.action==='list'&&c.cursor)).toHaveLength(1);await page.locator('#assistantMore').click();await expect(page.locator('[data-assistant-client]')).toHaveCount(22);await expect(page.locator('#assistantMore')).toBeHidden();await page.locator('[data-assistant-filter="actions"]').click();await expect(page.locator('[data-assistant-client]')).toHaveCount(1);await expect(page.locator('[data-assistant-client="12"]')).toBeVisible();
  await page.locator('#assistantClose').click();await expect(page.locator('#replyText')).toHaveValue('Мой ответ остаётся в черновике');await expect(page.locator('#mediaSelection')).toHaveText(selection);clean(s);
});
