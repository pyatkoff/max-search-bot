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
  await expect.poll(()=>page.evaluate(()=>!WorkspaceV2.S.booting)).toBe(true);await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#replyText')).toBeVisible();await page.locator('#replyText').fill('Несохранённый ответ туристу');await page.locator('#mobileLeadBtn').click();
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
