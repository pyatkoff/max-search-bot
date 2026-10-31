const {test,expect}=require('@playwright/test');
const {setupRequestProfile,root}=require('./fixtures/manager-request-profile');
const fs=require('node:fs'),path=require('node:path');
let app;
const field=(page,k)=>page.locator(`[data-field="${k}"]`);
async function editField(page,k){const f=field(page,k);if(!await f.evaluate(e=>e.open))await f.locator('summary').click();return f}
async function ready(page){await expect(page.locator('#profileApp')).toBeVisible();await expect(page.locator('[data-field]')).toHaveCount(12)}
async function correction(page){await page.getByRole('button',{name:'Применить состав к черновику'}).click();await expect(field(page,'party').locator('[data-value=ages]')).toHaveValue('12')}
async function save(page){await page.locator('#profileSave').click();await expect(page.locator('#profileStatus')).toContainText('Профиль сохранён.');await expect(page.locator('#profileSave')).toBeEnabled()}
test.afterEach(async()=>{if(app){try{expect(app.errors).toEqual([]);expect(app.unexpected).toEqual([]);for(const response of app.actualResponses){expect(response.status).toBe(200);expect(response.body.ok).toBe(true)}}finally{await app.cleanup();app=null}}});
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
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  const directory=path.join(root,'manager2-artifacts');fs.mkdirSync(directory,{recursive:true});
  await page.screenshot({path:path.join(directory,`request-profile-${browserName}-${width}.png`),animations:'disabled',fullPage:true});
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
  await expect.poll(()=>page.evaluate(()=>!WorkspaceV2.S.booting)).toBe(true);await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#replyText')).toBeVisible();await page.locator('#replyText').fill('Несохранённый ответ туристу');await page.locator('#mobileLeadBtn').click();
  const link=page.locator('[data-request-profile] a');if(projectKey==='anytour'){await expect(link).toHaveAttribute('href','/manager2/request-profile.php?conversation=11');await expect(link).toHaveAttribute('target','_blank');await expect(link).toHaveAttribute('rel','noopener')}else await expect(link).toHaveCount(0);
  await expect(page.locator('#replyText')).toHaveValue('Несохранённый ответ туристу');expect(s.calls.filter(c=>c.action==='send')).toEqual([]);expect(s.errors).toEqual([]);expect(s.unexpected).toEqual([]);
});
