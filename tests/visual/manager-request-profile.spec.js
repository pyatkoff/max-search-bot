const {test,expect}=require('@playwright/test');
const {setupRequestProfile,root}=require('./fixtures/manager-request-profile');
const fs=require('node:fs'),path=require('node:path');
let app;
const field=(page,k)=>page.locator(`[data-field="${k}"]`);
const historyEvent=(page,version)=>page.locator(`[data-history-version="${version}"]`);
const cloneHistory=()=>app.mutate("INSERT INTO manager_request_profiles SELECT conversation_id,version+1,trip_id,boundary_message_id,manager_id,lower(hex(randomblob(16))),request_sha256,context_sha256,profile_json,profile_sha256,changed_fields_json,created_at FROM manager_request_profiles ORDER BY version DESC LIMIT 1");
async function editField(page,k){const f=field(page,k);if(!await f.evaluate(e=>e.open))await f.locator('summary').click();return f}
async function ready(page){await expect(page.locator('#profileApp')).toBeVisible();await expect(page.locator('[data-field]')).toHaveCount(12)}
async function correction(page){await page.getByRole('button',{name:'Применить состав к черновику'}).click();await expect(field(page,'party').locator('[data-value=ages]')).toHaveValue('12')}
async function save(page){await page.locator('#profileSave').click();await expect(page.locator('#profileStatus')).toContainText('Профиль сохранён.');await expect(page.locator('#profileSave')).toBeEnabled()}
test.afterEach(async()=>{if(app){try{expect(app.errors).toEqual([]);expect(app.unexpected).toEqual([]);for(const response of app.actualResponses){expect(response.status).toBe(200);expect(response.body.ok).toBe(true)}}finally{await app.cleanup();app=null}}});
for(const [width,height] of [[390,900],[430,900],[768,900],[1440,900],[390,420]])test(`request history before after and exact source at ${width}x${height}`,async({page,browserName})=>{
  await page.setViewportSize({width,height});app=await setupRequestProfile(page);await ready(page);
  expect(app.calls.filter(c=>c.action==='history')).toHaveLength(0);
  await correction(page);await save(page);app.mutate("UPDATE manager_request_profiles SET created_at='2026-12-31 22:30:00' WHERE version=2");
  const draft=await editField(page,'questions');await draft.locator('[data-value=text]').fill('Несохранённый вопрос о багаже');
  await page.locator('#profileHistoryOpen').click();const event=historyEvent(page,2);await expect(event).toBeVisible();
  await expect(event.locator('.profileHistoryMeta')).toHaveText('Мария · 01.01.2027, 01:30:00 МСК');
  const change=event.locator('[data-history-field=party]');await expect(change.locator('[data-history-side=before]')).toContainText('Взрослых: 2; детей: 0');await expect(change.locator('[data-history-side=after]')).toContainText('Взрослых: 2; детей: 1; возраст: 12');
  await expect(event.locator('[data-history-field=dates]')).toHaveCount(0);
  const shots=path.join(root,'manager2-artifacts');fs.mkdirSync(shots,{recursive:true});
  await page.locator('#profileHistoryRefresh').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(shots,`request-history-${browserName}-${width}x${height}.png`),animations:'disabled',fullPage:true});
  const source=change.locator('[data-history-source="2:party:after"]');await source.click();const dialog=page.locator('#profileHistorySourceDialog');await expect(dialog).toBeVisible();await expect(dialog).toContainText('Сообщение №12 · Турист');await expect(dialog).toContainText('совпадает с сохранённым основанием');
  expect(app.calls.find(c=>c.action==='history_source')).toMatchObject({version:2,field:'party',side:'after'});
  await page.screenshot({path:path.join(shots,`request-history-source-${browserName}-${width}x${height}.png`),animations:'disabled'});
  const geometry=await page.evaluate(()=>{const d=document.querySelector('#profileHistorySourceDialog'),r=d.getBoundingClientRect(),b=document.querySelector('#profileHistorySourceClose').getBoundingClientRect();return {scroll:document.documentElement.scrollWidth,width:innerWidth,height:innerHeight,left:r.left,right:r.right,top:r.top,bottom:r.bottom,closeHeight:b.height,closeBottom:b.bottom}});
  expect(geometry.scroll).toBeLessThanOrEqual(width);expect(geometry.left).toBeGreaterThanOrEqual(0);expect(geometry.right).toBeLessThanOrEqual(width);expect(geometry.top).toBeGreaterThanOrEqual(0);expect(geometry.bottom).toBeLessThanOrEqual(height);expect(geometry.closeHeight).toBeGreaterThanOrEqual(44);expect(geometry.closeBottom).toBeLessThanOrEqual(height);
  await page.keyboard.press('Escape');await expect(dialog).not.toBeVisible();await expect(source).toBeFocused();await expect(field(page,'questions').locator('[data-value=text]')).toHaveValue('Несохранённый вопрос о багаже');expect(app.calls.filter(c=>c.action==='save')).toHaveLength(1);
});
test('history ceiling pagination refresh gaps and trip boundaries are explicit',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);for(let i=0;i<6;i++)cloneHistory();await page.locator('#profileHistoryOpen').click();await expect(page.locator('[data-history-version]')).toHaveCount(3);await expect(historyEvent(page,7)).toBeVisible();
  cloneHistory();await page.locator('#profileHistoryOlder').click();await expect(page.locator('[data-history-version]')).toHaveCount(6);await expect(historyEvent(page,8)).toHaveCount(0);await page.locator('#profileHistoryOlder').click();await expect(page.locator('[data-history-version]')).toHaveCount(7);await expect(page.locator('#profileHistoryOlder')).toBeHidden();
  const continuations=app.calls.filter(c=>c.action==='history'&&c.before_version);expect(continuations.map(c=>c.snapshot_version)).toEqual([7,7]);
  await page.locator('#profileHistoryRefresh').click();await expect(historyEvent(page,8)).toBeVisible();await expect(page.locator('[data-history-version]')).toHaveCount(3);
  app.mutate("DELETE FROM manager_request_profiles WHERE version=7");await page.locator('#profileHistoryRefresh').click();await expect(historyEvent(page,8)).toContainText('Предыдущая версия недоступна');
  app.mutate("UPDATE manager_request_profiles SET trip_id='ffffffffffffffffffffffffffffffff' WHERE version=8; INSERT INTO manager_request_profiles SELECT conversation_id,7,trip_id,boundary_message_id,manager_id,lower(hex(randomblob(16))),request_sha256,context_sha256,profile_json,profile_sha256,changed_fields_json,created_at FROM manager_request_profiles WHERE version=6");
  await page.locator('#profileHistoryRefresh').click();await expect(historyEvent(page,8)).toContainText('Начата другая поездка');await expect(historyEvent(page,8).locator('[data-history-side=before]')).toHaveText(['БылоНе зафиксировано','БылоНе зафиксировано','БылоНе зафиксировано']);
  expect(app.calls.filter(c=>c.action==='save')).toHaveLength(0);
});
test('historical source edits missing rows and injected markup keep saved quotation separate',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await correction(page);await save(page);await page.locator('#profileHistoryOpen').click();
  app.mutate("UPDATE messages SET text='<img src=x onerror=window.historyInjected=1> synthetic@example.org https://provider.invalid/?token=private-synthetic' WHERE id=12");
  await page.locator('[data-history-source="2:party:after"]').click();const dialog=page.locator('#profileHistorySourceDialog');await expect(dialog).toContainText('Основание изменилось');await expect(dialog.locator('blockquote')).toContainText('ребёнок 12 лет');await expect(dialog.locator('.profileHistoryOriginal')).toContainText('<img src=x');await expect(dialog.locator('.profileHistoryOriginal')).toContainText('[email скрыт]');await expect(dialog.locator('img')).toHaveCount(0);expect(await page.evaluate(()=>window.historyInjected)).toBeUndefined();await expect(dialog).not.toContainText('private-synthetic');
  await page.locator('#profileHistorySourceClose').click();app.mutate('DELETE FROM messages WHERE id=12');await page.locator('[data-history-source="2:party:after"]').click();await expect(dialog).toContainText('Это не подтверждает его удаление');await expect(dialog.locator('blockquote')).toContainText('ребёнок 12 лет');await expect(dialog.locator('.profileHistoryOriginal')).toHaveCount(0);
});
test('late history and closed-source refusals preserve newer form while current access loss clears private DOM',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await (await editField(page,'questions')).locator('[data-value=text]').fill('Мой текущий черновик');
  app.queue({action:'history',hold:true,status:403,body:{ok:false,error:'forbidden'}});await page.locator('#profileHistoryOpen').click();await expect.poll(()=>app.heldCount()).toBe(1);await page.locator('#profileRefresh').click();await expect(page.locator('#profileStatus')).toContainText('Профиль загружен');app.release();await expect(page.locator('#profileHistoryStatus')).toContainText('Повторите загрузку');await expect(page.locator('#profileApp')).toBeVisible();await expect(field(page,'questions').locator('[data-value=text]')).toHaveValue('Мой текущий черновик');
  await page.locator('#profileHistoryOpen').click();await expect(historyEvent(page,1)).toBeVisible();app.queue({action:'history_source',hold:true,status:401,body:{ok:false,error:'unauthorized'}});await page.locator('[data-history-source="1:dates:after"]').click();await expect.poll(()=>app.heldCount()).toBe(1);await page.locator('#profileHistorySourceClose').click();app.release();await expect(page.locator('#profileApp')).toBeVisible();await expect(field(page,'questions').locator('[data-value=text]')).toHaveValue('Мой текущий черновик');
  app.mutate('DELETE FROM manager_projects WHERE manager_id=1');await page.locator('#profileHistoryRefresh').click();await expect(page.locator('#profileApp')).toBeHidden();await expect(page.locator('#profileHistory')).toBeEmpty();await expect(page.locator('#profileHistorySourceDialog')).toHaveCount(0);expect(app.calls.filter(c=>c.action==='save')).toHaveLength(0);
});
test('history transient and malformed replies allow explicit retry without rewriting private draft',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await (await editField(page,'questions')).locator('[data-value=text]').fill('Сохранить вопрос');
  app.queue({action:'history',body:{ok:true,viewer_id:1,conversation_id:1,snapshot_version:1,before_version:1,has_more:false,events:[{version:1}]}});await page.locator('#profileHistoryOpen').click();await expect(page.locator('#profileHistoryStatus')).toContainText('Не удалось загрузить');await expect(page.locator('[data-history-version]')).toHaveCount(0);await page.locator('#profileHistoryOpen').click();await expect(historyEvent(page,1)).toBeVisible();
  app.queue({action:'history_source',body:{ok:true,viewer_id:1,conversation_id:1,version:1,field:'dates',side:'after',available:true,source_state:'unavailable'}});await page.locator('[data-history-source="1:dates:after"]').click();const dialog=page.locator('#profileHistorySourceDialog');await expect(dialog).toContainText('Не удалось открыть сообщение');await dialog.getByRole('button',{name:'Повторить',exact:true}).click();await expect(dialog).toContainText('Сообщение №10');await page.locator('#profileHistorySourceClose').click();await expect(field(page,'questions').locator('[data-value=text]')).toHaveValue('Сохранить вопрос');expect(app.calls.filter(c=>c.action==='save')).toHaveLength(0);
});
test('shipped embedded history retains actual parent reply File cursor and transcript position',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);
  await page.route(app.origin+'/history-parent',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:'<!doctype html><body class="manager2"><section id="conversationZone"><header class="conversationHead">Клиент</header><div id="handoffContext">Исходные условия</div><div id="messages" style="height:80px;overflow:auto"><div style="height:600px">История</div></div><textarea id="replyText"></textarea><input id="replyFile" type="file"></section><style>#m2RequestProfilePanel[open]{display:flex;flex-direction:column;width:90vw;height:90vh}#m2RequestProfilePanel iframe{flex:1;min-height:0;width:100%}</style></body>'}));
  await page.goto(app.origin+'/history-parent');await page.evaluate(()=>{
    const S={current:1,manager:{id:1,role:'manager'},authExpired:false,authGeneration:1,detail:{conversation:{id:1,project_key:'anytour',manager_id:1,status:'manager',channel:'telegram',customer_id:10,customer_channel_id:'fixture'},messages:[]}};
    window.WorkspaceV2={S,$:id=>document.getElementById(id),showAuthRecovery:()=>{throw Error('unexpected_auth_recovery')},request:async(endpoint,action,args)=>{const response=await fetch('/manager/'+endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,...args,csrf:'fixture-profile-csrf'})});return {...await response.json(),http_status:response.status}}};window.WorkspaceV2Conversation={hasReadAccess:()=>true,getOpenGeneration:()=>1};
    const reply=document.getElementById('replyText');reply.value='Настоящий несохранённый ответ';reply.setSelectionRange(2,9);const transfer=new DataTransfer();transfer.items.add(new File(['synthetic'],'draft.txt',{type:'text/plain'}));document.getElementById('replyFile').files=transfer.files;window.historyParentFile=document.getElementById('replyFile').files[0];document.getElementById('messages').scrollTop=75;
  });await page.addScriptTag({path:path.join(root,'manager2/assets/request-profile-panel.js')});await page.locator('#m2CurrentTrip summary').click();await page.locator('#m2RequestProfileOpen').click();
  const frame=page.frameLocator('#m2RequestProfilePanel iframe');await expect(frame.locator('#profileApp')).toBeVisible();await frame.locator('#profileHistoryOpen').click();await frame.locator('[data-history-source="1:dates:after"]').click();await expect(frame.locator('#profileHistorySourceDialog')).toContainText('Сообщение №10');await frame.locator('#profileHistorySourceClose').click();await page.locator('#m2RequestProfilePanel').getByRole('button',{name:'Готово',exact:true}).click();
  expect(await page.evaluate(()=>{const reply=document.getElementById('replyText');return {text:reply.value,start:reply.selectionStart,end:reply.selectionEnd,file:document.getElementById('replyFile').files[0]===window.historyParentFile,scroll:document.getElementById('messages').scrollTop}})).toEqual({text:'Настоящий несохранённый ответ',start:2,end:9,file:true,scroll:75});expect(app.calls.filter(c=>c.action==='save')).toHaveLength(0);
});
for(const width of [390,430,768,1440])test(`profile party correction and evidence at ${width}px`,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});app=await setupRequestProfile(page);await ready(page);
  await expect(page.locator('#profileState')).toContainText('новые сообщения');
  await expect(field(page,'dates').locator('[data-value=text]')).toHaveValue('19 декабря');
  await expect(field(page,'budget').locator('[data-value=text]')).toBeEmpty();
  await correction(page);expect(app.calls.filter(c=>c.action==='save')).toHaveLength(0);
  await expect(field(page,'dates').locator('[data-source]')).toHaveValue('10');
  await save(page);await expect(page.locator('#profileHistory')).toContainText('Версия 2');
  expect(app.calls.filter(c=>c.action==='save')).toHaveLength(1);
  const command=app.calls.find(c=>c.action==='save');expect(command.fields.party.value).toEqual({adults:2,children:1,child_ages:[12]});expect(command.fields.dates.source_id).toBe(10);expect(command.fields.budget).toBeUndefined();
  await expect(page.locator('#profileState')).toContainText('Цена, наличие и полнота условий этим не подтверждены');
  await page.locator('[data-field=party]').scrollIntoViewIfNeeded();
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});
  const geometry=await page.evaluate(()=>{
    const viewport=innerWidth,records=[];
    for(const e of document.querySelectorAll('main,#profileApp,.profileHeaderFields,.profileFieldGrid,.profileField,label,select')){
      const r=e.getBoundingClientRect(),s=getComputedStyle(e),visible=r.height>0;
      if((visible&&(e.tagName==='SELECT'||e.querySelector(':scope>select')))||e.scrollWidth>e.clientWidth+1||r.right>viewport+1)records.push({tag:e.tagName,id:e.id,field:e.closest('[data-field]')?.dataset.field||'',visible,left:r.left,right:r.right,width:r.width,client:e.clientWidth,scroll:e.scrollWidth,display:s.display,columns:s.gridTemplateColumns,minWidth:s.minWidth,overflow:s.overflow,whiteSpace:s.whiteSpace,appearance:s.appearance,textOverflow:s.textOverflow});
    }
    records.sort((a,b)=>Number(b.visible)-Number(a.visible));
    const control=document.getElementById('profileBoundary'),controlStyle=getComputedStyle(control);
    return {innerWidth:viewport,clientWidth:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth,boundary:{appearance:controlStyle.appearance,overflow:controlStyle.overflow,height:control.getBoundingClientRect().height},records:records.slice(0,32)};
  });
  await page.screenshot({path:path.join(directory,`request-profile-${browserName}-${width}.png`),animations:'disabled',fullPage:true});
  expect(geometry.scrollWidth,JSON.stringify(geometry)).toBeLessThanOrEqual(geometry.innerWidth);
  expect(geometry.boundary.appearance).toBe('none');expect(geometry.boundary.height).toBeGreaterThanOrEqual(44);
});
test('stale context preserves local criteria and requires explicit sync',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await (await editField(page,'tradeoffs')).locator('[data-value=text]').fill('Можно сдвинуть вылет на день');
  app.mutate("INSERT INTO messages VALUES(13,1,'inbound','customer','Ещё вопрос о перелёте','2026-10-31 10:00:00','telegram',NULL,'tourist','synthetic-13')");
  await page.locator('#profileSave').click();await expect(page.locator('#profileStatus')).toContainText('новым контекстом');await expect(page.locator('#profileSave')).toBeDisabled();
  await expect(field(page,'tradeoffs').locator('[data-value=text]')).toHaveValue('Можно сдвинуть вылет на день');
  await page.locator('#profileRefresh').click();await expect(page.locator('#profileSave')).toBeEnabled();await save(page);
  await expect(field(page,'tradeoffs').locator('[data-value=text]')).toHaveValue('Можно сдвинуть вылет на день');
});
test('new trip clears prior conditions and reload restores only explicit new trip',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await page.locator('#profileNewTrip').check();await expect(field(page,'dates').locator('[data-value=text]')).toBeEmpty();await page.locator('#profileNewTrip').uncheck();await expect(field(page,'dates').locator('[data-value=text]')).toHaveValue('19 декабря');
  await page.locator('#profileNewTrip').check();await page.locator('#profileLabel').fill('Новая поездка');await page.locator('#profileBoundary').selectOption('12');await correction(page);await save(page);await page.reload();await ready(page);
  await expect(field(page,'dates').locator('[data-value=text]')).toBeEmpty();await expect(field(page,'party').locator('[data-value=ages]')).toHaveValue('12');
});
test('unknown committed save is reconciled without duplicate or lost later edits',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await correction(page);app.queue({action:'save',actual:true,abort:true});await page.locator('#profileSave').click();await expect(page.locator('#profileStatus')).toContainText('Сохранение не подтверждено');
  await (await editField(page,'questions')).locator('[data-value=text]').fill('Проверить багаж');await page.locator('#profileReconcile').click();await expect(page.locator('#profileStatus')).toContainText('Запись подтверждена');
  expect(app.calls.filter(c=>c.action==='save')).toHaveLength(1);await expect(field(page,'questions').locator('[data-value=text]')).toHaveValue('Проверить багаж');await save(page);await expect(page.locator('#profileHistory')).toContainText('Версия 3');
});
test('reconciled new-trip intent is consumed once while later criteria survive',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await page.locator('#profileNewTrip').check();await page.locator('#profileLabel').fill('Новая поездка');await page.locator('#profileBoundary').selectOption('12');await correction(page);app.queue({action:'save',actual:true,abort:true});await page.locator('#profileSave').click();await expect(page.locator('#profileStatus')).toContainText('Сохранение не подтверждено');
  const read=()=>page.evaluate(async()=>await (await fetch('/manager/request-profile-api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get',conversation_id:1,csrf:'fixture-profile-csrf'})})).json());const saved=await read();
  await (await editField(page,'questions')).locator('[data-value=text]').fill('Проверить перелёт');await page.locator('#profileReconcile').click();await expect(page.locator('#profileStatus')).toContainText('Запись подтверждена');await expect(page.locator('#profileNewTrip')).not.toBeChecked();await expect(field(page,'questions').locator('[data-value=text]')).toHaveValue('Проверить перелёт');await save(page);const edited=await read();expect(edited.profile.trip_id).toBe(saved.profile.trip_id);expect(edited.profile.version).toBe(3);
});
test('unknown uncommitted write retries same operation only after explicit decision',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await correction(page);app.queue({action:'save',abort:true});await page.locator('#profileSave').click();await expect(page.locator('#profileStatus')).toContainText('Сохранение не подтверждено');await page.locator('#profileReconcile').click();await expect(page.locator('#profileReconcile')).toHaveText('Повторить ту же запись');await (await editField(page,'questions')).locator('[data-value=text]').fill('Багаж уточнить после проверки сохранения');
  expect(app.calls.filter(c=>c.action==='save')).toHaveLength(1);await page.locator('#profileReconcile').click();await expect(page.locator('#profileStatus')).toContainText('Новые правки');await expect(field(page,'questions').locator('[data-value=text]')).toHaveValue('Багаж уточнить после проверки сохранения');
  const writes=app.calls.filter(c=>c.action==='save');expect(writes).toHaveLength(2);expect(writes[0]).toEqual(writes[1]);
  app.queue({action:'save',actual:true,abort:true});await page.locator('#profileSave').click();await expect(page.locator('#profileStatus')).toContainText('Сохранение не подтверждено');await expect(page.locator('#profileReconcile')).toHaveText('Проверить сохранение');await page.locator('#profileReconcile').click();await expect(page.locator('#profileStatus')).toContainText('Запись подтверждена');
});
test('initial transient read failure offers a visible retry without private form',async({page})=>{
  app=await setupRequestProfile(page,{initialGetAbort:true});await expect(page.locator('#profileApp')).toBeHidden();await expect(page.locator('#profileRetryLoad')).toBeVisible();await page.locator('#profileRetryLoad').click();await ready(page);await expect(page.locator('#profileRetryLoad')).toBeHidden();expect(app.calls.filter(c=>c.action==='save')).toHaveLength(0);
});
test('failed post-save readback leaves explicit recovery available',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await correction(page);app.queue({action:'get',abort:true});await page.locator('#profileSave').click();await expect(page.locator('#profileStatus')).toContainText('Профиль сохранён, но прочитать актуальную версию не удалось');await expect(page.locator('#profileRefresh')).toBeEnabled();expect(app.calls.filter(c=>c.action==='save')).toHaveLength(1);await page.locator('#profileRefresh').click();await expect(page.locator('#profileHistory')).toContainText('Версия 2');await expect(page.locator('#profileSave')).toBeEnabled();await expect(field(page,'party').locator('[data-value=ages]')).toHaveValue('12');
});
test('access lost during saved-profile readback keeps the private form cleared',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await correction(page);app.queue({action:'get',status:403,body:{ok:false,error:'forbidden'}});await page.locator('#profileSave').click();await expect(page.locator('#profileApp')).toBeHidden();await expect(page.locator('[data-field]')).toHaveCount(0);await expect(page.locator('#profileStatus')).toContainText('Доступ или аккаунт изменился');
});
test('edits during a successful save remain a local unsaved revision',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await correction(page);const cookieIdentity=async()=>(await page.context().cookies()).map(({name,value,path,domain,secure})=>({name,value,path,domain,secure}));const beforeCookies=await cookieIdentity();app.queue({action:'save',actual:true,hold:true});await page.locator('#profileSave').click();await expect.poll(()=>app.heldCount()).toBe(1);
  await (await editField(page,'rejections')).locator('[data-value=text]').fill('Отказ от Египта из-за ветра');app.release();await expect(page.locator('#profileStatus')).toContainText('Новые правки');await expect(field(page,'rejections').locator('[data-value=text]')).toHaveValue('Отказ от Египта из-за ветра');expect(await cookieIdentity()).toEqual(beforeCookies);await save(page);
});
test('late refresh preserves current input; changed actor clears private DOM',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);app.queue({action:'get',actual:true,hold:true});await page.locator('#profileRefresh').click();await expect.poll(()=>app.heldCount()).toBe(1);await (await editField(page,'must_have')).locator('[data-value=text]').fill('Без ночного перелёта');app.release();await expect(page.locator('#profileStatus')).toContainText('Профиль загружен');await expect(field(page,'must_have').locator('[data-value=text]')).toHaveValue('Без ночного перелёта');
  app.queue({action:'get',body:{ok:true,viewer_id:2,conversation_id:1,profile:{},sources:[]}});await page.locator('#profileRefresh').click();await expect(page.locator('#profileApp')).toBeHidden();await expect(page.locator('[data-field]')).toHaveCount(0);await expect(page.locator('#profileHistory')).toBeEmpty();
});
test('unsafe text is inert and stale manager version cannot overwrite another save',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await (await editField(page,'questions')).locator('[data-value=text]').fill('<img src=x onerror="window.profileInjected=1">');
  app.mutate('INSERT INTO manager_request_profiles SELECT conversation_id,version+1,trip_id,boundary_message_id,manager_id,\'11111111111111111111111111111111\',request_sha256,context_sha256,profile_json,profile_sha256,changed_fields_json,created_at FROM manager_request_profiles');
  await page.locator('#profileSave').click();await expect(page.locator('#profileReplace')).toBeVisible();await expect(page.locator('#profileSave')).toBeDisabled();await expect(field(page,'questions').locator('[data-value=text]')).toHaveValue('<img src=x onerror="window.profileInjected=1">');expect(await page.evaluate(()=>window.profileInjected)).toBeUndefined();
  await page.locator('#profileReplace').click();await expect(field(page,'questions').locator('[data-value=text]')).toBeEmpty();await expect(page.locator('#profileSave')).toBeEnabled();
});
test('draft survives reload; malformed local draft safely falls back to server',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);await (await editField(page,'budget')).locator('[data-value=text]').fill('250 тысяч за всю семью');await page.reload();await ready(page);await expect(field(page,'budget').locator('[data-value=text]')).toHaveValue('250 тысяч за всю семью');
  await page.evaluate(()=>sessionStorage.setItem('anytour.requestProfile.1.1',JSON.stringify({at:Date.now(),draft:{version:1,fields:{party:{value:{child_ages:'broken'}}}}})));await page.reload();await ready(page);await expect(field(page,'budget').locator('[data-value=text]')).toBeEmpty();
});

test('closed profile is read-only and access revocation hides private criteria',async({page})=>{
  app=await setupRequestProfile(page);await ready(page);app.mutate("UPDATE conversations SET status='closed' WHERE id=1");await page.locator('#profileRefresh').click();await expect(page.locator('#profileSave')).toBeDisabled();await expect(field(page,'dates').locator('[data-value=text]')).toBeDisabled();await expect(page.locator('#profileSuggestion')).toBeHidden();
  app.mutate('DELETE FROM manager_projects WHERE manager_id=1');await page.locator('#profileRefresh').click();await expect(page.locator('#profileApp')).toBeHidden();await expect(page.locator('[data-field]')).toHaveCount(0);await expect(page.locator('#profileStatus')).toContainText('Доступ или аккаунт изменился');
});
for(const projectKey of ['anytour','other'])test(`actual lead-card profile entry preserves reply draft for ${projectKey}`,async({page})=>{
  const {setup}=require('./fixtures/internal-notes');const s=await setup(page,{projectKey});
  await expect.poll(()=>page.evaluate(()=>!WorkspaceV2.S.booting)).toBe(true);await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#replyText')).toBeVisible();await page.locator('#replyText').fill('Несохранённый ответ туристу');await page.locator('#conversationTitle.m2ClientCardButton, #mobileLeadBtn:visible').first().click();
  const link=page.locator('[data-request-profile] a');if(projectKey==='anytour'){await expect(link).toHaveAttribute('href','/manager2/request-profile.php?conversation=11');await expect(link).toHaveAttribute('target','_blank');await expect(link).toHaveAttribute('rel','noopener')}else await expect(link).toHaveCount(0);
  await expect(page.locator('#replyText')).toHaveValue('Несохранённый ответ туристу');expect(s.calls.filter(c=>c.action==='send')).toEqual([]);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});

async function hotelFamily(page){const party=await editField(page,'party');await party.locator('[data-value=adults]').fill('2');await party.locator('[data-value=children]').fill('1');await party.locator('[data-value=ages]').fill('12');await party.locator('[data-kind]').selectOption('customer_statement');await party.locator('[data-source]').selectOption('13');await party.locator('[data-quote]').fill('На те же даты только на 3-х, 2 взрослых и один ребёнок 12 лет.')}
async function hotelReady(page){await ready(page);await expect(page.locator('#profileHotelFocus')).toBeVisible();await expect(page.locator('#profileHotelChoice option')).toHaveCount(2)}
async function hotelChoose(page,id='12'){await page.locator('#profileHotelChoice').selectOption(id);await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();await page.locator('#profileHotelConfirm').check()}
async function hotelRead(page){return page.evaluate(async()=>await(await fetch('/manager/request-profile-api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get',conversation_id:1,csrf:'fixture-profile-csrf'})})).json())}
for(const width of [390,430,768,1440])test(`hotel focus explicit family clarification and exact offer at ${width}px`,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});app=await setupRequestProfile(page,{mode:'hotel_focus'});await hotelReady(page);
  await expect(page.locator('#profileHotelChoice')).toHaveValue('0');await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();
  expect(app.calls.filter(c=>c.action==='save')).toHaveLength(0);const before=await hotelRead(page);expect(before.profile.hotel_focus||null).toBeNull();
  await hotelFamily(page);await hotelChoose(page);await expect(page.locator('#profileHotelTerms')).toContainText('Состав в предложении: 2 взр., 0 дет.');
  await expect(page.locator('#profileHotelTerms')).toContainText('Family Suite');await expect(page.locator('#profileHotelTerms')).toContainText('RUB');
  await save(page);const after=await hotelRead(page);expect(after.profile.hotel_focus.status).toBe('confirmed');expect(after.profile.fields.party.value).toEqual({adults:2,children:1,child_ages:[12]});expect(after.profile.fields.dates.citation.message_id).toBe(10);
  const sent=app.calls.find(c=>c.action==='save');expect(sent.hotel_focus).toEqual({message_id:12,revision:before.hotel_focus_candidates[0].revision});
  await expect(page.locator('#profileHotelState')).toContainText('Связь сохранена');await expect(page.locator('#profileHotelFocus')).toContainText('Цена, наличие');
  const geometry=await page.evaluate(()=>{const terms=document.getElementById('profileHotelTerms');return {width:innerWidth,scroll:document.documentElement.scrollWidth,choice:document.getElementById('profileHotelChoice').getBoundingClientRect().height,terms:{client:terms.clientHeight,scroll:terms.scrollHeight}}});
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});await page.screenshot({path:path.join(directory,`request-profile-hotel-${browserName}-${width}.png`),animations:'disabled',fullPage:true});
  expect(geometry.scroll,JSON.stringify(geometry)).toBeLessThanOrEqual(geometry.width);expect(geometry.choice).toBeGreaterThanOrEqual(44);expect(geometry.terms.scroll,JSON.stringify(geometry)).toBeLessThanOrEqual(geometry.terms.client);
});
test('hotel focus followup requires a new acknowledgement without losing the family or date',async({page})=>{
  app=await setupRequestProfile(page,{mode:'hotel_focus'});await hotelReady(page);await hotelFamily(page);await hotelChoose(page);await save(page);
  app.mutate("INSERT INTO messages VALUES(14,1,'inbound','customer','А питание для ребёнка тоже нужно проверить.','2026-11-01 11:00:00','telegram',NULL,'tourist','synthetic-14')");
  await page.locator('#profileRefresh').click();await expect(page.locator('#profileHotelState')).toContainText('Есть уточнения');await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();
  await expect(field(page,'party').locator('[data-value=ages]')).toHaveValue('12');await expect(field(page,'dates').locator('[data-source]')).toHaveValue('10');
  await page.locator('#profileHotelChoice').selectOption('12');await page.locator('#profileHotelConfirm').check();await save(page);expect((await hotelRead(page)).profile.hotel_focus.status).toBe('confirmed');
});
test('hotel focus source revision drift clears an unsaved acknowledgement on same message context',async({page})=>{
  app=await setupRequestProfile(page,{mode:'hotel_focus'});await hotelReady(page);await hotelChoose(page);const before=await hotelRead(page);
  app.mutate("UPDATE manager_tour_selection_publications SET selection_json=json_set(selection_json,'$.offers[0].import_source.raw.tariff','Updated synthetic source tariff')");
  await page.locator('#profileRefresh').click();await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();const after=await hotelRead(page);
  expect(after.context_sha256).toBe(before.context_sha256);expect(after.hotel_focus_candidates[0].revision).not.toBe(before.hotel_focus_candidates[0].revision);
  expect(app.calls.filter(c=>c.action==='save')).toHaveLength(0);
});
test('hotel focus invalid party cannot resurrect explicitly unchecked or cleared confirmation',async({page})=>{
  app=await setupRequestProfile(page,{mode:'hotel_focus'});await hotelReady(page);await hotelFamily(page);await hotelChoose(page);await save(page);
  const party=await editField(page,'party');await party.locator('[data-value=children]').fill('');await page.locator('#profileHotelConfirm').uncheck();
  await page.locator('#profileBoundary').selectOption('10');await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();
  await hotelFamily(page);await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();await page.locator('#profileHotelChoice').selectOption('12');await page.locator('#profileHotelConfirm').check();
  await party.locator('[data-value=children]').fill('');await page.locator('#profileHotelChoice').selectOption('0');await expect(page.locator('#profileHotelChoice')).toHaveValue('0');await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();
  await hotelFamily(page);await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();await save(page);expect((await hotelRead(page)).profile.hotel_focus||null).toBeNull();
});
test('hotel focus explicit clear and separate trip never inherit an earlier offer',async({page})=>{
  app=await setupRequestProfile(page,{mode:'hotel_focus'});await hotelReady(page);await hotelChoose(page);await save(page);
  await page.locator('#profileHotelChoice').selectOption('0');await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();await save(page);expect((await hotelRead(page)).profile.hotel_focus||null).toBeNull();
  await hotelChoose(page);await save(page);const oldTrip=(await hotelRead(page)).profile.trip_id;
  await page.locator('#profileNewTrip').check();await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();await page.locator('#profileBoundary').selectOption('13');await page.locator('#profileLabel').fill('Отдельная поездка после нового запроса');
  await expect(page.locator('#profileHotelChoice option[value="12"]')).toHaveCount(0);await save(page);const next=await hotelRead(page);expect(next.profile.trip_id).not.toBe(oldTrip);expect(next.profile.hotel_focus||null).toBeNull();expect(next.hotel_focus_candidates).toEqual([]);
});
test('hotel focus delayed successful save preserves a newer explicit clear',async({page})=>{
  app=await setupRequestProfile(page,{mode:'hotel_focus'});await hotelReady(page);await hotelChoose(page);app.queue({action:'save',actual:true,hold:true});await page.locator('#profileSave').click();await expect.poll(()=>app.heldCount()).toBe(1);
  await page.locator('#profileHotelChoice').selectOption('0');await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();app.release();await expect(page.locator('#profileStatus')).toContainText('Новые правки');
  await expect(page.locator('#profileHotelChoice')).toHaveValue('0');await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();await save(page);expect((await hotelRead(page)).profile.hotel_focus||null).toBeNull();
});
test('hotel focus late read keeps same-source local acknowledgement and actor revocation clears terms',async({page})=>{
  app=await setupRequestProfile(page,{mode:'hotel_focus'});await hotelReady(page);app.queue({action:'get',actual:true,hold:true});await page.locator('#profileRefresh').click();await expect.poll(()=>app.heldCount()).toBe(1);
  await hotelChoose(page);app.release();await expect(page.locator('#profileStatus')).toContainText('Профиль загружен');await expect(page.locator('#profileHotelConfirm')).toBeChecked();
  app.mutate('DELETE FROM manager_projects WHERE manager_id=1');await page.locator('#profileRefresh').click();await expect(page.locator('#profileApp')).toBeHidden();await expect(page.locator('#profileHotelTerms')).toBeEmpty();await expect(page.locator('#profileHotelChoice option')).toHaveCount(0);await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();
});
test('hotel focus malformed stored confirmation never activates a property',async({page})=>{
  app=await setupRequestProfile(page,{mode:'hotel_focus'});await hotelReady(page);
  await page.evaluate(()=>{const key='anytour.requestProfile.1.1';sessionStorage.setItem(key,JSON.stringify({at:Date.now(),draft:{version:1,context_sha256:'0'.repeat(64),label:'stored',boundary_message_id:10,new_trip:false,fields:{},hotel_focus:{message_id:12,revision:'invalid'}}}))});
  await page.reload();await hotelReady(page);await expect(page.locator('#profileHotelChoice')).toHaveValue('0');await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();expect(app.calls.filter(c=>c.action==='save')).toHaveLength(0);
});
test('hotel focus same hotel and room never silently substitutes a second native tariff',async({page})=>{
  app=await setupRequestProfile(page,{mode:'hotel_focus'});await hotelReady(page);await hotelFamily(page);await hotelChoose(page);await save(page);const first=await hotelRead(page);
  app.secondHotelInquiry(15);await page.locator('#profileRefresh').click();await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();await expect(page.locator('#profileHotelChoice')).toHaveValue('0');
  const changed=await hotelRead(page);expect(changed.hotel_focus_candidates).toHaveLength(1);const next=changed.hotel_focus_candidates[0];expect(next.message_id).toBe(15);expect(next.hotel_ref).toEqual(first.hotel_focus_candidates[0].hotel_ref);expect(next.selected_room).toBe(first.hotel_focus_candidates[0].selected_room);expect(next.public_offer.amount).toBe('185000');expect(next.revision).not.toBe(first.hotel_focus_candidates[0].revision);
  await page.locator('#profileHotelChoice').selectOption('15');await expect(page.locator('#profileHotelConfirm')).not.toBeChecked();await expect(page.locator('#profileHotelTerms')).toContainText('185000');await expect(page.locator('#profileHotelTerms')).toContainText('Другой перелёт');await expect(page.locator('#profileHotelTerms')).toContainText('Актуальность не подтверждена');
  await page.locator('#profileHotelConfirm').check();await save(page);const final=await hotelRead(page);expect(final.profile.hotel_focus.status).toBe('confirmed');expect(final.profile.hotel_focus.message_id).toBe(15);expect(final.profile.fields.party.value.child_ages).toEqual([12]);
});

async function emptyPreparedRequest(page){
  app=await setupRequestProfile(page);await ready(page);
  app.mutate("DELETE FROM manager_request_profiles; DELETE FROM messages WHERE id>11; INSERT INTO messages(id,conversation_id,direction,sender_type,text,created_at,channel) VALUES(20,1,'inbound','customer','Куда можно улететь на Новый год?','2026-10-30 11:00:00','telegram')");
  await page.locator('#profileRefresh').click();await expect(page.locator('#profilePreparation')).toBeVisible();
  await expect(page.locator('#profileLabel')).toBeEmpty();
}
for(const width of [390,1440])test(`literal request preparation stays unsaved and source-bound at ${width}px`,async({page,browserName})=>{
  await page.setViewportSize({width,height:420});await emptyPreparedRequest(page);
  await expect(page.locator('#profileState')).toContainText('Профиль пока не создан');
  await expect(page.locator('#profilePreparation')).toContainText('Новый год');
  await expect(field(page,'dates').locator('[data-value=text]')).toBeEmpty();
  await expect(field(page,'budget').locator('[data-value=text]')).toBeEmpty();
  const preparationShots=path.join(root,'manager2-artifacts');fs.mkdirSync(preparationShots,{recursive:true});
  await page.locator('#profilePreparation').screenshot({path:path.join(preparationShots,`request-profile-preparation-${browserName}-${width}.png`)});
  await page.locator('#profileApplyPreparation').click();
  await expect(field(page,'dates').locator('[data-value=text]')).toHaveValue('Новый год');
  await expect(field(page,'questions').locator('[data-value=text]')).toHaveValue('Куда можно улететь на Новый год?');
  await expect(field(page,'dates').locator('[data-source]')).toHaveValue('20');
  await expect(field(page,'destination').locator('[data-value=text]')).toBeEmpty();
  await expect(field(page,'party').locator('[data-value=adults]')).toBeEmpty();
  await expect(field(page,'budget').locator('[data-value=text]')).toBeEmpty();
  await expect(page.locator('#profileState')).toContainText('Профиль пока не создан');
  await expect(page.locator('#profileStatus')).toContainText('только в черновик');
  expect(app.calls.filter(c=>c.action==='save')).toHaveLength(0);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
});
test('preparation refresh preserves dirty fields pending commands and a new-trip draft',async({page})=>{
  await emptyPreparedRequest(page);
  await editField(page,'dates');await field(page,'dates').locator('[data-value=text]').fill('Ручной период');
  await page.locator('#profileLabel').fill('Мой несохранённый запрос');
  await page.locator('#profileRefresh').click();
  await expect(field(page,'dates').locator('[data-value=text]')).toHaveValue('Ручной период');
  await expect(page.locator('#profileLabel')).toHaveValue('Мой несохранённый запрос');
  await page.locator('#profileApplyPreparation').click();
  await expect(field(page,'dates').locator('[data-value=text]')).toHaveValue('Ручной период');
  await expect(field(page,'questions').locator('[data-value=text]')).toHaveValue('Куда можно улететь на Новый год?');
  app.queue({action:'save',abort:true});await page.locator('#profileSave').click();
  await expect(page.locator('#profileReconcile')).toBeVisible();
  await page.locator('#profileRefresh').click();
  await expect(page.locator('#profileReconcile')).toBeVisible();
  await expect(page.locator('#profileApplyPreparation')).toBeDisabled();
  await expect(field(page,'dates').locator('[data-value=text]')).toHaveValue('Ручной период');
  expect(app.calls.filter(c=>c.action==='save')).toHaveLength(1);
});
test('new-trip choice never imports a proposal from the previous trip',async({page})=>{
  await emptyPreparedRequest(page);await page.locator('#profileNewTrip').check();
  await page.locator('#profileLabel').fill('Другая поездка');
  await page.locator('#profileRefresh').click();
  await expect(page.locator('#profileNewTrip')).toBeChecked();
  await expect(page.locator('#profileLabel')).toHaveValue('Другая поездка');
  await expect(page.locator('#profilePreparation')).toBeHidden();
  await expect(field(page,'dates').locator('[data-value=text]')).toBeEmpty();
  expect(app.calls.filter(c=>c.action==='save')).toHaveLength(0);
});

// Exercise the shipped panel in a native DOM with synthetic authorized reads.
// Provider calls and all other network destinations are deliberately excluded.
for(const width of [390,1440])test(`current request stays independent of the optional AI host at ${width}px`,async({page,browserName})=>{
  const errors=[],unexpected=[];
  const onError=error=>errors.push(error.message);page.on('pageerror',onError);
  await page.setViewportSize({width,height:700});
  await page.route('**/*',route=>{
    const url=new URL(route.request().url());
    if(url.origin==='https://request-profile.invalid'&&['/','/manager2/request-profile.php'].includes(url.pathname))return route.fulfill({contentType:'text/html; charset=utf-8',body:url.pathname==='/'?
      '<!doctype html><body class="manager2"><section id="conversationZone"><header class="conversationHead">Клиент</header><div id="optionalAiHost" hidden></div><div id="handoffContext">Исходные условия</div><div id="messages" style="height:80px;overflow:auto"><div style="height:600px">История</div></div><textarea id="replyText"></textarea><input id="replyFile" type="file"><div id="profileTools"></div></section></body>':'<!doctype html><title>Protected profile fixture</title>'});
    unexpected.push(route.request().url());return route.abort();
  });
  try{
    await page.goto('https://request-profile.invalid/');
    await page.evaluate(({width})=>{
      const S={current:11,manager:{id:7,role:'manager'},authExpired:false,authGeneration:1,detail:{conversation:{id:11,project_key:'anytour',manager_id:7,status:'manager',channel:'telegram',customer_id:101,customer_channel_id:'fixture'},messages:[]}};
      const p=window.profileHostProbe={calls:[],hostReads:0,mayRead:true,profile:{label:'Текущая поездка',status:'fresh',fields:{dates:{value:'20 декабря'},party:{value:{adults:2,children:0}},budget:{value:'200 000 ₽ за всех'}}}};
      window.WorkspaceV2={S,$:id=>document.getElementById(id),showAuthRecovery:()=>{throw new Error('unexpected_auth_recovery')},request:async(endpoint,action,args)=>{
        p.calls.push({endpoint,action,conversation:args.conversation_id});
        return {ok:true,viewer_id:7,conversation_id:args.conversation_id,profile:p.profile};
      }};
      window.WorkspaceV2Conversation={hasReadAccess:()=>p.mayRead,getOpenGeneration:()=>1};
      window.Manager2Presentation={registerConversationTool:button=>{document.getElementById('profileTools').appendChild(button);return true},focusConversationTool:id=>document.getElementById(id).focus()};
      window.WorkspaceV2AiDraft={host(){p.hostReads++;if(width===1440)throw new Error('unavailable_optional_ai_host');return document.getElementById('optionalAiHost')},resetSession(){},sync(){}};
      const reply=document.getElementById('replyText');reply.value='Несохранённый ответ';reply.setSelectionRange(2,8);
      const transfer=new DataTransfer();p.file=new File(['synthetic'],'draft.txt',{type:'text/plain'});transfer.items.add(p.file);document.getElementById('replyFile').files=transfer.files;p.file=document.getElementById('replyFile').files[0];
      document.getElementById('messages').scrollTop=75;
    },{width});
    await page.addScriptTag({path:path.join(root,'manager2/assets/request-profile-panel.js')});
    const trip=page.locator('#m2CurrentTrip');await expect(trip).toBeVisible();await expect(trip.locator('small')).toContainText('20 декабря');
    expect(await trip.evaluate(node=>node.parentElement.id==='conversationZone'&&node.previousElementSibling.classList.contains('conversationHead'))).toBe(true);
    await expect(page.locator('#optionalAiHost #m2CurrentTrip')).toHaveCount(0);
    expect(await page.evaluate(()=>profileHostProbe.hostReads)).toBe(0);
    await page.evaluate(()=>{document.getElementById('optionalAiHost').remove();delete window.WorkspaceV2AiDraft;WorkspaceV2RequestProfile.sync()});
    await expect(trip).toBeVisible();await expect(trip.locator('small')).toContainText('200 000 ₽ за всех');
    expect(await page.evaluate(()=>profileHostProbe.calls.length)).toBe(1);
    await trip.locator('summary').click();const status=page.locator('#m2RequestStatus');
    await expect(status).toHaveText('Запрос сверен с перепиской');
    const savedCriteria='20 декабря · 2 взр. · 200 000 ₽ за всех';await expect(trip.locator('small')).toHaveText(savedCriteria);
    await page.evaluate(()=>{profileHostProbe.profile.status='needs_review';WorkspaceV2.S.detail.messages.push({id:501,direction:'in',sender_type:'customer',text:'Уточнение к поездке',created_at:'2026-11-07 10:00:00'});WorkspaceV2RequestProfile.sync()});
    await expect(status).toHaveText('Есть новые сообщения. Сверьте запрос.');await trip.locator('summary').click();await expect(status).toBeVisible();
    await expect(trip).toHaveAttribute('data-state','review');await expect(trip.locator('small')).toHaveText(savedCriteria);
    expect(await page.evaluate(()=>profileHostProbe.calls.length)).toBe(2);
    const screenshotWidth=width===390?430:1280;
    await page.setViewportSize({width:screenshotWidth,height:700});const style=await page.addStyleTag({path:path.join(root,'manager2/assets/manager2.css')});
    const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});
    await expect(status).toBeVisible();await trip.screenshot({path:path.join(directory,`request-profile-needs-review-${browserName}-${screenshotWidth}.png`),animations:'disabled'});
    await style.evaluate(node=>node.remove());await page.setViewportSize({width,height:700});
    await page.evaluate(()=>{profileHostProbe.profile.status='fresh';WorkspaceV2RequestProfile.refresh()});
    await expect(status).toHaveText('Запрос сверен с перепиской');await expect(trip).toHaveAttribute('data-state','fresh');
    await trip.locator('summary').click();await expect(status).toBeVisible();
    await expect(trip.locator('small')).toHaveText(savedCriteria);await expect(status).not.toContainText('Есть новые сообщения');
    await page.evaluate(()=>{profileHostProbe.profile={label:'Текущая поездка',status:'empty',fields:{}};WorkspaceV2RequestProfile.refresh()});
    await expect(status).toHaveText('Сохранённого запроса пока нет. Уточните его по переписке.');await expect(status).toBeVisible();
    await expect(trip).toBeHidden();await expect(trip.locator('small')).toBeEmpty();
    await page.locator('#m2RequestProfileOpen').click();await expect(page.locator('#m2RequestProfilePanel')).toBeVisible();
    await page.locator('#m2RequestProfilePanel button').click();await expect(page.locator('#m2RequestProfilePanel')).not.toBeVisible();
    expect(await page.evaluate(()=>({text:document.getElementById('replyText').value,file:document.getElementById('replyFile').files[0]===profileHostProbe.file,scroll:document.getElementById('messages').scrollTop}))).toEqual({text:'Несохранённый ответ',file:true,scroll:75});
    await page.evaluate(()=>{profileHostProbe.mayRead=false;WorkspaceV2RequestProfile.sync()});
    await expect(trip).toBeHidden();await expect(trip.locator('small')).toBeEmpty();await expect(page.locator('#m2RequestProfileOpen')).toBeHidden();
    expect(await page.evaluate(()=>profileHostProbe.calls.every(call=>call.endpoint==='request-profile-api.php'&&call.action==='get'&&call.conversation===11))).toBe(true);
    expect(errors).toEqual([]);expect(unexpected).toEqual([]);
  }finally{page.off('pageerror',onError)}
});
