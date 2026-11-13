const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root}=require('./fixtures/internal-notes');
const {settledMediaSelectionText}=require('./fixtures/media-selection');
const clone=value=>JSON.parse(JSON.stringify(value));

// The shared fixture renders the actual Manager2 PHP shell and serves current
// production assets. Only synthetic API responses are added here; no server,
// account, customer send, task mutation or model invocation is used.
async function setupPromise(page){
  const base=await setup(page,{sourceRoot:root,projectKey:'anytour'}),calls=[],receipts=new Map();
  const tasks=[{id:77,conversation_id:11,title:'Подготовить согласованный расчёт',due_at_utc:'2030-11-20 12:00:00',status:'open',is_pinned:true,assigned_manager_id:7,assigned_manager_name:'Мария',due_state:'upcoming'}];
  const profile={trip_id:'a'.repeat(32),version:3,sha256:'b'.repeat(64),context_sha256:'c'.repeat(64),boundary_message_id:10,status:'fresh',label:'Зимняя поездка',
    fields:{dates:{value:'20 ноября, 7 ночей',kind:'manager_interpretation',importance:'hard',note:'',citation:{message_id:10,sender_type:'manager',quote:'20 ноября, 7 ночей',sha256:'f'.repeat(64)}}},field_labels:{dates:'Даты поездки'}};
  const source={id:12,revision:'d'.repeat(64),author_manager_id:7,text:'Пришлю согласованный расчёт на двоих.',recorded_at_utc:'2026-11-13T09:00:00Z'};
  const conversation=id=>({id,project_key:'anytour',customer_id:id+90,manager_id:7,manager_name:'Мария',status:'manager',channel:'telegram',source_id:1,
    display_name:id===11?'Анна Морозова':'Алексей Иванов',original_display_name:id===11?'Анна Морозова':'Алексей Иванов',working_name:null,customer_name_version:0});
  await page.route('**/manager/api.php',route=>{
    const d=route.request().postDataJSON();if(d.action!=='detail')return route.fallback();
    expect(d.csrf).toBe('fixture-csrf');
    return route.fulfill({contentType:'application/json',json:{ok:true,conversation:conversation(d.conversation_id),delivery_failure:null,has_manager_reply:true,
      messages:[{id:10,direction:'inbound',sender_type:'customer',text:'Нужен расчёт на двоих.',created_at:'2026-11-13 08:59:00'},
        {id:12,direction:'outbound',sender_type:'manager',manager_id:7,sender_id:'7',channel:'telegram',external_message_id:'synthetic-outgoing-12',text:source.text,created_at:'2026-11-13 09:00:00'}],
      history:{has_more:false,before_id:10,page_size:200,mode:'latest'}}});
  });
  await page.route('**/manager/pipeline-api.php',route=>{
    const d=route.request().postDataJSON(),reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',json:body});
    if(!['detail','promise_context','bind_promise','promise_operation'].includes(d.action))return route.fallback();
    calls.push(clone(d));expect(d.csrf).toBe('fixture-csrf');expect(d.conversation_id).toBe(11);
    if(d.action==='detail')return reply({ok:true,viewer_id:7,conversation_id:11,can_edit_pipeline:true,degraded_components:[],tasks:clone(tasks),pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},trip:{},contact:{},source:{},handoff:{}});
    if(d.action==='promise_context'){
      expect(d.task_id).toBe(77);expect(d.source_message_id).toBe(12);expect(d.kind).toBe('send_calculation');
      return reply({ok:true,viewer_id:7,conversation_id:11,can_bind:true,task:clone(tasks[0]),profile:clone(profile),source:clone(source),binding:null});
    }
    if(d.action==='bind_promise'){
      expect(d.task_id).toBe(77);expect(d.source_message_id).toBe(12);expect(d.source_message_revision).toBe(source.revision);expect(d.profile_version).toBe(profile.version);
      expect(d.profile_sha256).toBe(profile.sha256);expect(d.profile_context_sha256).toBe(profile.context_sha256);expect(d.operation_id).toMatch(/^[a-f0-9]{32}$/);
      expect(d.deadline).toEqual({kind:'date',value:'2030-11-20',zone:'Europe/Moscow'});
      receipts.set(d.operation_id,{promise_id:'f'.repeat(64),task_id:77});return route.abort('failed');
    }
    const receipt=receipts.get(d.operation_id);
    return reply({ok:true,viewer_id:7,found:!!receipt,promise_id:receipt?.promise_id??null,task_id:receipt?.task_id??null,task:receipt?clone(tasks[0]):null});
  });
  return{base,calls,tasks,receipts,writes:()=>calls.filter(call=>call.action==='bind_promise')};
}
async function showCard(page){
  if(!await page.locator('#leadZone').isVisible()||await page.evaluate(()=>WorkspaceV2Mobile.isMobile()&&WorkspaceV2Mobile.getScreen()!=='lead'))await page.locator('#conversationTitle').click();
  await expect(page.locator('#leadTaskTitle')).toBeAttached();
}
async function showChat(page){
  await page.locator('#mobileLeadClose').click();
  await expect(page.locator('#messages')).toBeVisible();
}
async function preserved(page,fileText){
  await expect(page.locator('#replyText')).toHaveValue('Ручной ответ остаётся черновиком');
  await expect(page.locator('#leadTaskTitle')).toHaveValue('Новая ручная задача без сохранения');
  await expect(page.locator('#leadTaskDue')).toHaveValue('2031-02-03T12:30');
  await expect(page.locator('[data-task-edit-title]')).toHaveValue('Незавершённая правка существующей задачи');
  await expect(page.locator('[data-task-edit-due]')).toHaveValue('2031-02-04T14:00');
  expect(await settledMediaSelectionText(page)).toBe(fileText);
  expect(await page.evaluate(()=>WorkspaceV2Media.matchesSelection(window.promiseUiFileSelection))).toBe(true);
  expect(await page.evaluate(()=>{
    const p=window.promiseUiManualNodes;
    return p.title===document.getElementById('leadTaskTitle')&&p.due===document.getElementById('leadTaskDue')
      &&p.editTitle===document.querySelector('[data-task-edit-title]')&&p.editDue===document.querySelector('[data-task-edit-due]')
      &&p.draft===sessionStorage.getItem(WorkspaceV2TaskDraft.keyFor(11));
  })).toBe(true);
}

for(const [width,height] of [[390,900],[430,900],[768,900],[1440,900],[390,420]])test(`explicit promise binding preserves manual work and reconciles once at ${width}x${height}`,async({page,browserName})=>{
  await page.setViewportSize({width,height});const s=await setupPromise(page);
  await page.locator('#inboxList [data-conversation-id="11"] .m2RowOpen').click();await expect(page.locator('#conversationTitle')).toHaveText('Анна Морозова');
  await page.locator('#replyText').fill('Ручной ответ остаётся черновиком');
  await page.locator('#replyFile').setInputFiles({name:'private-synthetic.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic unsent attachment')});
  const fileText=await settledMediaSelectionText(page);await page.evaluate(()=>{window.promiseUiFileSelection=WorkspaceV2Media.captureSelection()});
  await showCard(page);await page.locator('.m2TaskCreate>summary').click();
  await page.locator('#leadTaskTitle').fill('Новая ручная задача без сохранения');await page.locator('#leadTaskDue').fill('2031-02-03T12:30');
  await page.locator('[data-task-edit="77"]').click();await page.locator('[data-task-edit-title]').fill('Незавершённая правка существующей задачи');await page.locator('[data-task-edit-due]').fill('2031-02-04T14:00');
  await page.evaluate(()=>{window.promiseUiManualNodes={title:document.getElementById('leadTaskTitle'),due:document.getElementById('leadTaskDue'),
    editTitle:document.querySelector('[data-task-edit-title]'),editDue:document.querySelector('[data-task-edit-due]'),draft:sessionStorage.getItem(WorkspaceV2TaskDraft.keyFor(11))}});
  await showChat(page);const opener=page.locator('.promiseTaskSourceButton[data-promise-source-id="12"]');await expect(opener).toBeVisible();
  expect(s.calls.filter(call=>call.action.startsWith('promise_')||call.action==='bind_promise')).toEqual([]);
  await opener.click();await expect(page.locator('#promiseTaskDialog')).toBeVisible();await expect(page.locator('#promiseTaskTask')).toBeEnabled();
  await page.locator('#promiseTaskTask').selectOption('77');await page.locator('#promiseTaskKind').selectOption('send_calculation');
  await page.locator('#promiseTaskDeadlineMode').selectOption('date');await page.locator('#promiseTaskDate').fill('2030-11-20');await page.locator('#promiseTaskPreview').click();
  await expect(page.locator('#promiseTaskSource')).toContainText('Пришлю согласованный расчёт на двоих.');
  await expect(page.locator('#promiseTaskProfile')).toContainText('Зимняя поездка');await expect(page.locator('#promiseTaskProfile')).toContainText('20 ноября, 7 ночей');
  await expect(page.locator('#promiseTaskTaskSummary')).toContainText('Подготовить согласованный расчёт');await expect(page.locator('#promiseTaskConfirm')).toBeEnabled();
  expect(s.writes()).toHaveLength(0);await preserved(page,fileText);
  const box=await page.locator('#promiseTaskDialog').boundingBox();expect(box.x).toBeGreaterThanOrEqual(-1);expect(box.x+box.width).toBeLessThanOrEqual(width+1);expect(box.y).toBeGreaterThanOrEqual(-1);expect(box.y+box.height).toBeLessThanOrEqual(height+1);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});
  await page.screenshot({animations:'disabled',path:path.join(directory,`promise-tasks-preview-${browserName}-${width}x${height}.png`)});
  for(const id of ['promiseTaskKind','promiseTaskTask','promiseTaskDeadlineMode'])expect((await page.locator('#'+id).boundingBox()).height).toBeGreaterThanOrEqual(44);
  await expect(page.locator('#promiseTaskClose')).toBeInViewport();
  await page.locator('#promiseTaskProfile').scrollIntoViewIfNeeded();await expect(page.locator('#promiseTaskProfile')).toBeInViewport();
  await page.screenshot({animations:'disabled',path:path.join(directory,`promise-tasks-conditions-${browserName}-${width}x${height}.png`)});
  await page.locator('#promiseTaskConfirm').click();await expect(page.locator('#promiseTaskCheck')).toBeVisible();await expect(page.locator('#promiseTaskConfirm')).not.toBeVisible();expect(s.writes()).toHaveLength(1);
  await page.locator('#promiseTaskCheck').click();await expect(page.locator('#promiseTaskStatus')).toContainText('Связь подтверждена');
  await expect(page.locator('#promiseTaskStatus')).not.toContainText(/Обещание выполнено|Задача выполнена/);expect(s.writes()).toHaveLength(1);
  expect(s.calls.filter(call=>call.action==='promise_operation')).toHaveLength(1);await preserved(page,fileText);
  await page.screenshot({animations:'disabled',path:path.join(directory,`promise-tasks-confirmed-${browserName}-${width}x${height}.png`)});
  await page.locator('#promiseTaskClose').click();await expect(page.locator('#promiseTaskDialog')).not.toBeVisible();await expect(opener).toBeFocused();await preserved(page,fileText);
  expect(s.base.errors).toEqual([]);expect(s.base.unexpected).toEqual([]);
  expect(s.base.calls.filter(call=>['send','take','release','close','reopen','create_task','update_task','set_task_completed','set_task_pinned'].includes(call.action))).toEqual([]);
});
