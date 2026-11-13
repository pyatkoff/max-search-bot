const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root}=require('./fixtures/internal-notes');
const {settledMediaSelectionText}=require('./fixtures/media-selection');
const {fixtureService}=require('../run_manager_promise_selection_behavior_regression');
const clone=value=>JSON.parse(JSON.stringify(value));
const pendingPrefix='workspaceV2.promiseSelection.pending.v1.';

// Actual Manager2 shell, lazy launcher and feature assets. The fixture records
// synthetic receipts only; it never sends a customer message or updates a task.
async function setupSelection(page){
 const base=await setup(page,{sourceRoot:root,projectKey:'anytour'}),service=fixtureService({conversationId:11,actor:7}),calls=[];
 let loseAttach=true,holdOperation=false,releaseOperation=null;
 await page.route('**/manager/pipeline-api.php',route=>{
  const d=route.request().postDataJSON();if(d.action!=='detail')return route.fallback();expect(d.csrf).toBe('fixture-csrf');
  return route.fulfill({contentType:'application/json',json:{ok:true,viewer_id:7,conversation_id:d.conversation_id,can_edit_pipeline:true,degraded_components:[],
   tasks:service.state.tasks.map(task=>({...clone(task),conversation_id:d.conversation_id,is_pinned:true,assigned_manager_id:7,assigned_manager_name:'Мария',due_state:'upcoming'})),
   pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},trip:{},contact:{},source:{},handoff:{}}});
 });
 await page.route('**/manager/promise-selections-api.php',async route=>{
  const d=route.request().postDataJSON();calls.push(clone(d));expect(d.csrf).toBe('fixture-csrf');expect(d.conversation_id).toBe(11);
  expect(['catalog','preview','attach','cancel','operation','status']).toContain(d.action);
  const reply=json=>route.fulfill({contentType:'application/json',json});
  if(d.action==='catalog')return reply(service.catalog());if(d.action==='preview')return reply(service.preview(d));if(d.action==='status')return reply(service.status(d));
  if(d.action==='operation'){const result=service.operation(d);if(holdOperation)await new Promise(resolve=>{releaseOperation=resolve});return reply(result);}
  const result=service.write(d);if(d.action==='attach'&&loseAttach)return route.abort('failed');return reply(result);
 });
 return{base,service,calls,writes:()=>calls.filter(call=>['attach','cancel'].includes(call.action)),setLoseAttach:value=>loseAttach=value,
  holdOperation:()=>{holdOperation=true},releaseOperation:()=>{holdOperation=false;releaseOperation?.();releaseOperation=null}};
}
async function openAction(page){
 const button=page.locator('#m2PromiseSelectionOpen');if(!await button.isVisible())await page.locator('#m2ConversationMenuToggle').click();
 await button.click();await expect(page.locator('#promiseSelectionDialog')).toBeVisible();
}
async function ready(page,s){
 await openAction(page);await expect(page.locator('#promiseSelectionPromise')).toBeEnabled();
 await page.locator('#promiseSelectionPromise').selectOption(s.service.state.promises[0].promise_id);
 await page.locator('#promiseSelectionPublication').selectOption(s.service.state.publications[0].publication_id);
 await page.locator('#promiseSelectionPreview').click();await expect(page.locator('#promiseSelectionConfirm')).toBeEnabled();
}
async function seedManualWork(page){
 await page.locator('#replyText').fill('Ручной ответ остаётся черновиком');
 await page.locator('#replyFile').setInputFiles({name:'private-synthetic.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic unsent attachment')});
 const fileText=await settledMediaSelectionText(page);await page.evaluate(()=>{window.promiseSelectionFile=WorkspaceV2Media.captureSelection()});
 if(!await page.locator('#leadZone').isVisible()||await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='lead'))await page.locator('#conversationTitle').click();
 await expect(page.locator('#leadTaskTitle')).toBeAttached();await page.locator('.m2TaskCreate>summary').click();
 await page.locator('#leadTaskTitle').fill('Новая ручная задача без сохранения');await page.locator('#leadTaskDue').fill('2031-02-03T12:30');
 await page.locator('[data-task-edit="77"]').click();await page.locator('[data-task-edit-title]').fill('Незавершённая правка существующей задачи');await page.locator('[data-task-edit-due]').fill('2031-02-04T14:00');
 await page.evaluate(()=>{window.promiseSelectionManual={title:document.getElementById('leadTaskTitle'),due:document.getElementById('leadTaskDue'),
  editTitle:document.querySelector('[data-task-edit-title]'),editDue:document.querySelector('[data-task-edit-due]'),draft:sessionStorage.getItem(WorkspaceV2TaskDraft.keyFor(11))}});
 await page.locator('#mobileLeadClose').click();await expect(page.locator('#messages')).toBeVisible();return fileText;
}
async function preserved(page,fileText){
 await expect(page.locator('#replyText')).toHaveValue('Ручной ответ остаётся черновиком');
 await expect(page.locator('#leadTaskTitle')).toHaveValue('Новая ручная задача без сохранения');await expect(page.locator('#leadTaskDue')).toHaveValue('2031-02-03T12:30');
 await expect(page.locator('[data-task-edit-title]')).toHaveValue('Незавершённая правка существующей задачи');await expect(page.locator('[data-task-edit-due]')).toHaveValue('2031-02-04T14:00');
 expect(await settledMediaSelectionText(page)).toBe(fileText);expect(await page.evaluate(()=>WorkspaceV2Media.matchesSelection(window.promiseSelectionFile))).toBe(true);
 expect(await page.evaluate(()=>{const p=window.promiseSelectionManual;return p.title===document.getElementById('leadTaskTitle')&&p.due===document.getElementById('leadTaskDue')
  &&p.editTitle===document.querySelector('[data-task-edit-title]')&&p.editDue===document.querySelector('[data-task-edit-due]')&&p.draft===sessionStorage.getItem(WorkspaceV2TaskDraft.keyFor(11))})).toBe(true);
}
function noOtherWrites(s){
 expect(s.base.errors).toEqual([]);expect(s.base.unexpected).toEqual([]);
 expect(s.base.calls.filter(call=>['send','take','release','close','reopen','create_task','update_task','set_task_completed','set_task_pinned','publish','prepare'].includes(call.action))).toEqual([]);
}

for(const width of [390,1440])test(`explicit promise and full publication preview preserve manual work and recover one receipt at ${width}px`,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await setupSelection(page);
 await page.locator('#inboxList [data-conversation-id="11"] .m2RowOpen').click();await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');
 const fileText=await seedManualWork(page);await ready(page,s);
 await expect(page.locator('#promiseSelectionQuote')).toHaveText(s.service.state.source.text);await expect(page.locator('#promiseSelectionTask')).toContainText('Отправить согласованную подборку');
 await expect(page.locator('#promiseSelectionProfile')).toContainText('20 ноября, 7 ночей');await expect(page.locator('#promiseSelectionReview')).toContainText('Сверьте все варианты с условиями этой поездки');
 await expect(page.locator('#promiseSelectionCards>li')).toHaveCount(2);for(const [index,card] of s.service.state.publications[0].cards.entries())await expect(page.locator('#promiseSelectionCards>li').nth(index)).toHaveText(card.text);
 expect(s.writes()).toHaveLength(0);await preserved(page,fileText);
 const box=await page.locator('#promiseSelectionDialog').boundingBox();expect(box.x).toBeGreaterThanOrEqual(-1);expect(box.x+box.width).toBeLessThanOrEqual(width+1);expect(box.y).toBeGreaterThanOrEqual(-1);expect(box.y+box.height).toBeLessThanOrEqual(901);
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
 const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,`promise-selection-preview-${browserName}-${width}.png`)});
 await page.locator('#promiseSelectionConfirm').click();await expect(page.locator('#promiseSelectionCheck')).toBeVisible();expect(s.writes()).toHaveLength(1);
 expect(Object.keys(s.writes()[0]).sort()).toEqual(['action','csrf','conversation_id','operation_id','promise_id','publication_id','preview_sha256'].sort());
 const pending=await page.evaluate(prefix=>Object.entries(localStorage).filter(([key])=>key.startsWith(prefix)),pendingPrefix);expect(pending).toHaveLength(1);
 expect(pending[0][1]).not.toMatch(/Подготовлю|Турцию|Море|240 000|private-synthetic/);
 await page.locator('#promiseSelectionClose').click();await expect(page.locator('#m2PromiseSelectionOpen')).toBeFocused();await preserved(page,fileText);
 await openAction(page);await expect(page.locator('#promiseSelectionCheck')).toBeVisible();await page.locator('#promiseSelectionCheck').click();
 await expect(page.locator('#promiseSelectionIntent')).toContainText('Ожидает отправки');await expect(page.locator('#promiseSelectionIntent')).toContainText('0 из 2');
 expect(s.writes()).toHaveLength(1);expect(s.calls.filter(call=>call.action==='operation')).toHaveLength(1);await preserved(page,fileText);
 await page.evaluate(()=>history.back());await expect(page.locator('#promiseSelectionDialog')).not.toBeVisible();await expect(page.locator('#m2PromiseSelectionOpen')).toBeFocused();
 await preserved(page,fileText);expect(s.service.state.tasks[0].status).toBe('open');noOtherWrites(s);
});

for(const width of [390,1440])for(const scopeChange of ['actor','conversation'])test(`late receipt read stays pending after ${scopeChange} change at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:900});const s=await setupSelection(page);await page.locator('#inboxList [data-conversation-id="11"] .m2RowOpen').click();await ready(page,s);
 await page.locator('#promiseSelectionConfirm').click();await expect(page.locator('#promiseSelectionCheck')).toBeVisible();s.holdOperation();await page.locator('#promiseSelectionCheck').click();
 await expect.poll(()=>s.calls.filter(call=>call.action==='operation').length).toBe(1);
 const pending=await page.evaluate(prefix=>Object.entries(localStorage).filter(([key])=>key.startsWith(prefix)),pendingPrefix);
 if(scopeChange==='actor')await page.evaluate(()=>{window.promiseSelectionActor=WorkspaceV2.S.manager;WorkspaceV2.S.manager={...WorkspaceV2.S.manager,id:8};WorkspaceV2.S.authGeneration++;WorkspaceV2PromiseSelection.sync()});
 else await page.evaluate(()=>WorkspaceV2Conversation.open(12));
 await expect(page.locator('#promiseSelectionDialog')).not.toBeVisible();await page.locator('#replyText').fill('Ответ в текущем контексте');
 await page.locator('#replyFile').setInputFiles({name:'current-context.txt',mimeType:'text/plain',buffer:Buffer.from('Current synthetic file')});await settledMediaSelectionText(page);
 await page.evaluate(()=>{window.promiseSelectionCurrentFile=WorkspaceV2Media.captureSelection();window.promiseSelectionCurrentFocus=document.activeElement});
 s.releaseOperation();await expect.poll(()=>s.base.pending.size).toBe(0);
 expect(await page.evaluate(prefix=>Object.entries(localStorage).filter(([key])=>key.startsWith(prefix)),pendingPrefix)).toEqual(pending);
 await expect(page.locator('#promiseSelectionIntent')).not.toBeVisible();await expect(page.locator('#replyText')).toHaveValue('Ответ в текущем контексте');
 expect(await page.evaluate(()=>WorkspaceV2Media.matchesSelection(window.promiseSelectionCurrentFile)&&document.activeElement===window.promiseSelectionCurrentFocus)).toBe(true);expect(s.writes()).toHaveLength(1);
 noOtherWrites(s);
});
