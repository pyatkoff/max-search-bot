const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root}=require('./fixtures/internal-notes');
const {fixtureService,clone}=require('../support/manager-promise-calculation-ui-fixture');

async function setupCalculation(page,{loseArm=false,unknownSecond=false}={}){
 // Observe the real native File/FormData, including runtimes whose intercepted
 // multipart body omits binary bytes. This wrapper never substitutes dispatch.
 await page.addInitScript(()=>{window.calculationUploads=[];window.calculationObserved=0;const nativeFetch=window.fetch;window.fetch=function(url,options){if(String(url).endsWith('media-upload.php')&&options?.body instanceof FormData){const fields={};for(const [name,value]of options.body)fields[name]=value instanceof File?{name:value.name,size:value.size,type:value.type,lastModified:value.lastModified}:value;const file=options.body.get('file');fields.bytes=file.arrayBuffer().then(bytes=>Array.from(new Uint8Array(bytes)));calculationUploads.push(fields)}return nativeFetch.apply(this,arguments)}});
 const base=await setup(page,{sourceRoot:root,projectKey:'anytour'}),service=fixtureService({conversationId:11}),calls=[],uploads=[],texts=[],reads=[];
 await page.route('**/manager/pipeline-api.php',route=>{
  const d=route.request().postDataJSON();if(d.action!=='detail')return route.fallback();expect(d.csrf).toBe('fixture-csrf');
  return route.fulfill({contentType:'application/json',json:{ok:true,viewer_id:7,conversation_id:d.conversation_id,can_edit_pipeline:true,degraded_components:[],tasks:[{...clone(service.state.task),conversation_id:d.conversation_id}],pipeline:{stage:null,tags:[],stage_history:[],outcome:{outcome:'open'}},trip:{},contact:{},source:{},handoff:{}}});
 });
 await page.route('**/manager/promise-calculations-api.php',async route=>{
  const d=route.request().postDataJSON();calls.push(clone(d));expect(d.csrf).toBe('fixture-csrf');expect(d.expected_manager_id).toBe(7);expect(d.conversation_id).toBe(11);
  const result=service.request(d);if(d.action==='arm'&&loseArm){loseArm=false;return route.abort('failed')}
  return route.fulfill({status:result.ok?200:409,contentType:'application/json',json:result});
 });
 await page.route('**/manager/api.php',route=>{
  const d=route.request().postDataJSON();if(!['send','send_receipt','media_receipt'].includes(d.action))return route.fallback();expect(d.csrf).toBe('fixture-csrf');expect(d.expected_manager_id).toBe(7);
  if(d.action==='send'){texts.push(d);return route.fulfill({contentType:'application/json',json:service.native(d)})}
  reads.push(d);return route.fulfill({contentType:'application/json',json:service.receipt(d)});
 });
 await page.route('**/manager/media-upload.php',async route=>{
  const d=await page.evaluate(async()=>{const observed=calculationUploads[calculationObserved++];return {...observed,bytes:await observed.bytes}});uploads.push(d);
  expect(d.csrf).toBe('fixture-csrf');expect(d.expected_manager_id).toBe('7');expect(d.conversation_id).toBe('11');expect(d.calculation_intent_id).toMatch(/^[a-f0-9]{32}$/);expect(d.operation_id).toMatch(/^[a-f0-9]{32}$/);
  const raw=route.request().postDataBuffer()?.toString();if(raw)for(const name of ['operation_id','calculation_intent_id','calculation_part_index','caption']){const value=raw.match(new RegExp('name="'+name+'"\\r\\n\\r\\n([\\s\\S]*?)\\r\\n--'))?.[1];expect((value||'').replace(/\r\n/g,'\n')).toBe(d[name])}
  service.state.nativeState=unknownSecond&&uploads.length===2?'unknown':'confirmed';const result=service.native(d,Buffer.from(d.bytes));if(service.state.nativeState==='unknown')return route.abort('failed');
  return route.fulfill({contentType:'application/json',json:result});
 });
 return{base,service,calls,uploads,texts,reads};
}
async function openConversation(page){await page.locator('#inboxList [data-conversation-id="11"] .m2RowOpen').click();await expect(page.locator('#composer')).toBeVisible()}
async function selectTask(page){await page.locator('#conversationTitle').click();await page.locator('[data-calculation-task="77"]').click();await expect(page.locator('#promiseCalculation')).toBeVisible();await expect(page.locator('#messages')).toBeVisible()}
async function savedFiles(page,names){await expect.poll(()=>page.evaluate(async()=>{const saved=await WorkspaceV2MediaDrafts.load(7,11);return saved?.rows.map(row=>row.file.name)})).toEqual(names)}
async function screenshot(page,name,browserName,width){const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({animations:'disabled',path:path.join(directory,`promise-calculation-${name}-${browserName}-${width}x900.png`)})}
async function noOverflow(page,width){expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);const box=await page.locator('#promiseCalculation').boundingBox();expect(box.x).toBeGreaterThanOrEqual(-1);expect(box.x+box.width).toBeLessThanOrEqual(width+1);await expect(page.locator('#sendReply')).toBeInViewport()}
const selectedFiles=[{name:'Расчёт_поездки_20_ноября.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic calculation 240000 rubles')},{name:'Подробности_расчёта.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic second file; exact ordered bytes')}];

for(const width of [390,430,768,1440])test(`calculation uses ordinary File and two explicit Send clicks while keeping task drafts at ${width}`,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await setupCalculation(page);await openConversation(page);
 await page.locator('#replyText').fill('Расчёт стоимости поездки: 240 000 ₽.');await page.locator('#replyFile').setInputFiles(selectedFiles.slice(0,1));await savedFiles(page,[selectedFiles[0].name]);
 await page.locator('#conversationTitle').click();await page.locator('.m2TaskCreate>summary').click();await page.locator('#leadTaskTitle').fill('Несохранённая новая задача');await page.locator('#leadTaskDue').fill('2031-02-03T12:30');
 await page.locator('[data-task-edit="77"]').click();await page.locator('[data-task-edit-title]').first().fill('Несохранённая ручная правка');await page.locator('[data-task-edit-due]').first().fill('2031-02-04T14:00');
 await page.evaluate(()=>{window.calculationManual={create:document.getElementById('leadTaskTitle'),edit:document.querySelector('[data-task-edit-title]'),file:WorkspaceV2Media.captureSelection().rows[0].row.file};window.calculationSelection=WorkspaceV2Media.captureSelection()});
 await page.locator('[data-calculation-task="77"]').click();await expect(page.locator('#promiseCalculation')).toBeVisible();expect(s.uploads).toHaveLength(0);expect(s.calls.filter(d=>d.action==='preview')).toHaveLength(0);
 await page.locator('#sendReply').click();await expect(page.locator('#promiseCalculationStatus')).toContainText('Состав проверен');expect(s.uploads).toHaveLength(0);expect(s.calls.filter(d=>d.action==='arm')).toHaveLength(0);
 await expect(page.locator('.promiseCalculationText')).toHaveText('Расчёт стоимости поездки: 240 000 ₽.');await expect(page.locator('.promiseCalculationFiles')).toContainText(selectedFiles[0].name);
 expect(await page.evaluate(()=>WorkspaceV2Media.matchesSelection(calculationSelection))).toBe(true);await noOverflow(page,width);await screenshot(page,'preview',browserName,width);
 await page.locator('#replyText').fill('Проверенный расчёт: 245 000 ₽.');await page.locator('#sendReply').click();await expect(page.locator('#promiseCalculationStatus')).toContainText('Состав проверен');expect(s.uploads).toHaveLength(0);
 await page.locator('#sendReply').click();await expect.poll(()=>s.uploads.length).toBe(1);await expect(page.locator('#promiseCalculationStatus')).toHaveText('Расчёт отправлен. Задача выполнена.');
 expect(s.uploads[0].bytes).toEqual(Array.from(selectedFiles[0].buffer));expect(s.uploads[0].caption).toBe('Проверенный расчёт: 245 000 ₽.');expect(s.uploads[0].calculation_part_index).toBe('0');expect(s.service.state.completionCount).toBe(1);expect(s.texts).toHaveLength(0);
 await expect(page.locator('#leadTaskTitle')).toHaveValue('Несохранённая новая задача');await expect(page.locator('#leadTaskDue')).toHaveValue('2031-02-03T12:30');await expect(page.locator('[data-task-edit-title]').first()).toHaveValue('Несохранённая ручная правка');
 expect(await page.evaluate(()=>calculationManual.create===document.getElementById('leadTaskTitle')&&calculationManual.edit===document.querySelector('[data-task-edit-title]'))).toBe(true);
 await noOverflow(page,width);await screenshot(page,'confirmed',browserName,width);expect(s.base.errors).toEqual([]);expect(s.base.unexpected).toEqual([]);expect(s.base.calls.some(d=>['set_task_completed','create_task','update_task'].includes(d.action))).toBe(false);
});

for(const width of [390,1440])test(`calculation arm and native UNKNOWN survive reload with exact File parts and read-only recovery at ${width}`,async({page,browserName})=>{
 await page.setViewportSize({width,height:900});const s=await setupCalculation(page,{loseArm:true,unknownSecond:true});await openConversation(page);await selectTask(page);await expect(page.locator('#promiseCalculationStatus')).toContainText('Подготовьте текст');expect(s.uploads).toHaveLength(0);
 await page.locator('#replyText').fill('Подпись только к первому файлу');await page.locator('#replyFile').setInputFiles(selectedFiles);await savedFiles(page,selectedFiles.map(f=>f.name));await page.locator('#sendReply').click();await expect(page.locator('#promiseCalculationStatus')).toContainText('Состав проверен');await page.locator('#sendReply').click();await expect(page.locator('#promiseCalculationCheck')).toBeEnabled();await expect(page.locator('#promiseCalculationStatus')).toContainText('не подтверждена');
 expect(s.uploads).toHaveLength(0);expect(s.calls.filter(d=>d.action==='arm')).toHaveLength(1);const operation=s.calls.find(d=>d.action==='arm').operation_id;await screenshot(page,'arm-unknown',browserName,width);
 await page.reload();await expect(page.locator('#composer')).toBeVisible();await savedFiles(page,selectedFiles.map(f=>f.name));await expect(page.locator('#replyText')).toHaveValue('Подпись только к первому файлу');
 expect(await page.evaluate(async()=>Promise.all(WorkspaceV2Media.captureSelection().rows.map(async({row})=>Array.from(new Uint8Array(await row.file.arrayBuffer())))))).toEqual(selectedFiles.map(f=>Array.from(f.buffer)));
 await page.locator('#promiseCalculationCheck').click();await expect(page.locator('#promiseCalculationStatus')).toContainText('Подтверждена отправка 0 из 2');expect(s.calls.findLast(d=>d.action==='operation').operation_id).toBe(operation);expect(s.calls.filter(d=>d.action==='arm')).toHaveLength(1);expect(s.uploads).toHaveLength(0);
 await page.locator('#sendReply').click();await expect.poll(()=>s.uploads.length).toBe(2);await expect(page.locator('#mediaSelection')).toContainText('Исход неизвестен');expect(s.uploads.map(d=>d.calculation_part_index)).toEqual(['0','1']);expect(s.uploads.map(d=>d.caption)).toEqual(['Подпись только к первому файлу','']);expect(s.service.state.completionCount).toBe(0);expect(s.service.state.task.status).toBe('open');
 await expect(page.locator('#replyText')).toHaveValue('');await page.locator('#mediaRecovery>summary').click();await noOverflow(page,width);await screenshot(page,'native-unknown',browserName,width);
 const stored=await page.evaluate(async()=>{const value=await WorkspaceV2MediaDrafts.load(7,11);return Promise.all(value.rows.map(async row=>({intent:row.calculationIntentId,index:row.calculationPartIndex,operation:row.recoveryId,bytes:Array.from(new Uint8Array(await row.file.arrayBuffer()))})))});expect(stored[0].intent).toBe(s.uploads[1].calculation_intent_id);expect(stored[0].index).toBe(1);expect(stored[0].operation).toBe(s.uploads[1].operation_id);expect(stored[0].bytes).toEqual(Array.from(selectedFiles[1].buffer));
 await page.locator('#replyText').fill('Новый ответ остаётся черновиком');s.service.confirm(s.uploads[1].operation_id);await page.locator('#mediaRecovery button').first().click();await expect(page.locator('#promiseCalculationStatus')).toHaveText('Расчёт отправлен. Задача выполнена.');await expect(page.locator('#replyText')).toHaveValue('Новый ответ остаётся черновиком');expect(s.uploads).toHaveLength(2);expect(s.service.state.completionCount).toBe(1);expect(s.reads.at(-1).operation_id).toBe(s.uploads[1].operation_id);
 await screenshot(page,'recovered',browserName,width);expect(s.base.errors).toEqual([]);expect(s.base.unexpected).toEqual([]);expect(s.base.calls.some(d=>['set_task_completed','create_task','update_task'].includes(d.action))).toBe(false);
});
