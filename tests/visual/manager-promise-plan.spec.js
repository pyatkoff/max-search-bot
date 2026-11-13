const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root}=require('./fixtures/internal-notes');
const {settledMediaSelectionText}=require('./fixtures/media-selection');
const {fixture}=require('../run_manager_promise_plan_behavior_regression');
const clone=value=>JSON.parse(JSON.stringify(value));

// Real Manager2 shell and assets; only synthetic read responses. No model,
// customer send, task mutation or selection write is accepted by this fixture.
async function setupPlan(page){
 const base=await setup(page,{sourceRoot:root,projectKey:'anytour'}),data=fixture({conversationId:12}),calls=[],held=[];let hold=null;
 data.binding.source_message_id=205;
 const items=new Map([[12,data.item],[11,{...clone(data.item),conversation_id:11,display_name:'Текущий диалог'}]]);
 await page.route('**/manager/pipeline-api.php',route=>{
  const d=route.request().postDataJSON();if(d.action!=='detail')return route.fallback();expect(d.csrf).toBe('fixture-csrf');
  return route.fulfill({contentType:'application/json',json:{ok:true,viewer_id:7,conversation_id:d.conversation_id,can_edit_pipeline:true,degraded_components:[],
   tasks:[{id:77,conversation_id:d.conversation_id,title:'Ручная текущая задача',due_at_utc:'2030-11-20 12:00:00',status:'open',is_pinned:true,assigned_manager_id:7,assigned_manager_name:'Мария'}],
   pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},trip:{},contact:{},source:{},handoff:{}}});
 });
 await page.route('**/manager/assistant-api.php',route=>{
  const d=route.request().postDataJSON();calls.push(clone(d));expect(d.csrf).toBe('fixture-csrf');expect(['list','detail']).toContain(d.action);
  return route.fulfill({contentType:'application/json',json:d.action==='detail'?{ok:true,item:clone(items.get(d.conversation_id))}:{ok:true,items:[...items.values()].map(item=>({...clone(item),promise_coverage:'not_loaded',tasks:item.tasks.map(({promise,...task})=>task)})),has_more:false,next_cursor:null}});
 });
 await page.route('**/manager/api.php',async route=>{
  const d=route.request().postDataJSON();if(d.action!=='message_context')return route.fallback();calls.push(clone(d));expect(d.csrf).toBe('fixture-csrf');expect(d.conversation_id).toBe(12);expect(d.target_message_id).toBe(205);
  const result=data.message();if(hold==='source')await new Promise(resolve=>held.push(resolve));return route.fulfill({contentType:'application/json',json:result});
 });
 await page.route('**/manager/promise-selections-api.php',async route=>{
  const d=route.request().postDataJSON();calls.push(clone(d));expect(d.action).toBe('view');expect(d.csrf).toBe('fixture-csrf');expect({...d,csrf:undefined,action:undefined}).toEqual({...data.target(),csrf:undefined,action:undefined});
  const result=data.view();if(hold==='selection')await new Promise(resolve=>held.push(resolve));return route.fulfill({contentType:'application/json',json:result});
 });
 await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
 return{base,data,items,calls,hold:kind=>hold=kind,held:()=>held.length,release:()=>{hold=null;held.shift()?.()}};
}
async function prepare(page){
 await page.locator('#inboxList [data-conversation-id="11"] .m2RowOpen').click();await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');
 await page.locator('#replyText').fill('Несохранённый ответ в другом диалоге');
 await page.locator('#replyFile').setInputFiles({name:'unsent-plan.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic unsent attachment')});await settledMediaSelectionText(page);
 if(!await page.locator('#leadZone').isVisible()||await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='lead'))await page.locator('#conversationTitle').click();
 await page.locator('.m2TaskCreate>summary').click();await page.locator('#leadTaskTitle').fill('Незавершённая ручная задача');await page.locator('#leadTaskDue').fill('2031-02-03T12:30');
 await page.locator('[data-task-edit="77"]').click();await page.locator('[data-task-edit-title]').fill('Несохранённое изменение задачи');await page.locator('[data-task-edit-due]').fill('2031-02-04T14:00');await page.locator('#mobileLeadClose').click();
 if(await page.locator('#mobileBack').isVisible())await page.locator('#mobileBack').click();
 await page.locator('[data-m2-nav="tasks"]').click();await page.locator('[data-assistant-client="12"]').click();await expect(page.locator('.assistantPromiseFacts')).toContainText('Подтверждённое обещание');
 await page.evaluate(()=>{window.promisePlanSaved={current:WorkspaceV2.S.current,detail:WorkspaceV2.S.detail,composer:document.getElementById('replyText'),file:WorkspaceV2Media.captureSelection(),
  title:document.getElementById('leadTaskTitle'),due:document.getElementById('leadTaskDue'),editTitle:document.querySelector('[data-task-edit-title]'),editDue:document.querySelector('[data-task-edit-due]'),
  draftKey:WorkspaceV2TaskDraft.keyFor(11),draft:sessionStorage.getItem(WorkspaceV2TaskDraft.keyFor(11)),messages:[...document.getElementById('messages').children],scroll:document.getElementById('messages').scrollTop}});
}
async function preserved(page){
 await expect(page.locator('#replyText')).toHaveValue('Несохранённый ответ в другом диалоге');await expect(page.locator('#leadTaskTitle')).toHaveValue('Незавершённая ручная задача');await expect(page.locator('#leadTaskDue')).toHaveValue('2031-02-03T12:30');
 await expect(page.locator('[data-task-edit-title]')).toHaveValue('Несохранённое изменение задачи');await expect(page.locator('[data-task-edit-due]')).toHaveValue('2031-02-04T14:00');
 expect(await page.evaluate(()=>{const p=window.promisePlanSaved,m=document.getElementById('messages');return WorkspaceV2.S.current===p.current&&WorkspaceV2.S.detail===p.detail&&p.current===11&&WorkspaceV2Media.matchesSelection(p.file)
  &&p.composer===document.getElementById('replyText')&&p.title===document.getElementById('leadTaskTitle')&&p.due===document.getElementById('leadTaskDue')&&p.editTitle===document.querySelector('[data-task-edit-title]')&&p.editDue===document.querySelector('[data-task-edit-due]')
  &&p.draft===sessionStorage.getItem(p.draftKey)&&m.scrollTop===p.scroll&&m.children.length===p.messages.length&&p.messages.every((node,index)=>m.children[index]===node)})).toBe(true);
}
function clean(s,baseline){
 expect(s.base.errors).toEqual([]);expect(s.base.unexpected).toEqual([]);expect(s.calls.every(c=>['list','detail','message_context','view'].includes(c.action))).toBe(true);
 expect(s.base.calls.slice(baseline).filter(c=>['detail','ack_read','send','take','release','close','reopen','create_task','update_task','set_task_completed','set_task_pinned','publish','prepare','ai_draft'].includes(c.action))).toEqual([]);
}
async function bounded(page,id,width){const box=await page.locator('#'+id).boundingBox();expect(box.x).toBeGreaterThanOrEqual(-1);expect(box.x+box.width).toBeLessThanOrEqual(width+1);expect(box.y).toBeGreaterThanOrEqual(-1);expect(box.y+box.height).toBeLessThanOrEqual(901);expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);}
async function capture(page,browser,width,label){const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,`promise-plan-${label}-${browser}-${width}.png`)});}

for(const width of [390,1440])test(`promise facts and exact read-only source/publication preserve all manual work at ${width}px`,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await setupPlan(page);await prepare(page);const baseline=s.base.calls.length;
 await expect(page.locator('.assistantTask')).toHaveCount(1);await expect(page.locator('.assistantTask')).toContainText('Отправить согласованную подборку');await expect(page.locator('.assistantPromiseFacts')).toContainText('календарный день, Москва');await expect(page.locator('.assistantPromiseFacts')).toContainText('1 из 2');
 await page.locator('[data-assistant-snooze="77"]').click();await page.locator('#assistantDue').fill('2031-02-05T17:12');await page.evaluate(()=>{window.promisePlanDue=document.getElementById('assistantDue')});
 await page.locator('[data-assistant-promise-source="77"]').click();await expect(page.locator('#messageContextMessages')).toContainText('Пришлю согласованную подборку');await bounded(page,'messageContextDialog',width);await capture(page,browserName,width,'source');await preserved(page);
 await page.locator('#messageContextBack').click();await expect(page.locator('[data-assistant-promise-source="77"]')).toBeFocused();await expect(page.locator('#assistantDue')).toHaveValue('2031-02-05T17:12');
 await page.locator('[data-assistant-promise-selection="77"]').click();await expect(page.locator('#promiseSelectionCards>li')).toHaveCount(2);for(const [index,card] of s.data.publication.cards.entries())await expect(page.locator('#promiseSelectionCards>li').nth(index)).toHaveText(card.text);
 await expect(page.locator('#promiseSelectionIntent')).toContainText('1 из 2');for(const id of ['promiseSelectionFields','promiseSelectionPreview','promiseSelectionConfirm','promiseSelectionCancel','promiseSelectionCheck','promiseSelectionRetry'])await expect(page.locator('#'+id)).toBeHidden();
 await bounded(page,'promiseSelectionDialog',width);await capture(page,browserName,width,'composition');await preserved(page);await page.evaluate(()=>history.back());await expect(page.locator('#promiseSelectionDialog')).toBeHidden();await expect(page.locator('[data-assistant-promise-selection="77"]')).toBeFocused();
 await expect(page.locator('#assistantDue')).toHaveValue('2031-02-05T17:12');expect(await page.evaluate(()=>document.getElementById('assistantDue')===window.promisePlanDue)).toBe(true);await preserved(page);clean(s,baseline);
});
for(const width of [390,1440])for(const [kind,change] of [['source','actor'],['selection','client']])test(`late ${kind} read is discarded after ${change} change at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:900});const s=await setupPlan(page);await prepare(page);const baseline=s.base.calls.length;s.hold(kind);await page.locator('[data-assistant-promise-'+kind+'="77"]').click();await expect.poll(s.held).toBe(1);
 if(change==='actor')await page.evaluate(()=>{WorkspaceV2.S.manager={...WorkspaceV2.S.manager,id:8};WorkspaceV2.S.authGeneration++;WorkspaceV2Assistant.suspend();WorkspaceV2MessageNavigation.sync()});
 else await page.evaluate(()=>WorkspaceV2Assistant.loadDetail(11));
 const dialog=kind==='source'?'messageContextDialog':'promiseSelectionDialog',content=kind==='source'?'messageContextMessages':'promiseSelectionCards';await expect(page.locator('#'+dialog)).toBeHidden();await page.evaluate(()=>{window.promisePlanFocus=document.activeElement});
 s.release();await expect.poll(()=>s.base.pending.size).toBe(0);await expect(page.locator('#'+content)).toBeEmpty();expect(await page.evaluate(()=>document.activeElement===window.promisePlanFocus)).toBe(true);await preserved(page);clean(s,baseline);
});
