const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root}=require('./fixtures/internal-notes');
const copy=x=>JSON.parse(JSON.stringify(x));
async function fixture(page,options={}){
  const base=await setup(page),calls=[],drafts=new Map(),receipts=new Map();let fail=options.fail||'',held=null;
  const snapshot=id=>({conversation_id:id,version:drafts.get(id)?.version||0,draft:copy(drafts.get(id)?.draft||{title:'Подборка туров',intro:'',offers:[]}),can_edit:!options.readOnly,updated_at:null,updated_by_manager_id:7});
  await page.route('**/manager/tour-selections-api.php',async route=>{
    const d=route.request().postDataJSON();calls.push(copy(d));expect(d.csrf).toBe('fixture-csrf');expect([11,12]).toContain(d.conversation_id);
    const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    if(d.action==='get')return reply({ok:true,selection:snapshot(d.conversation_id)});
    if(d.action==='operation')return reply({ok:true,found:receipts.has(d.operation_id),saved_version:receipts.get(d.operation_id)?.version??null,selection:snapshot(d.conversation_id)});
    expect(d.action).toBe('save');
    if(options.readOnly)return reply({ok:false,error:'forbidden'},403);
    if(fail==='before'){fail='';return route.abort('failed')}
    if(!receipts.has(d.operation_id)){
      if(d.version!==snapshot(d.conversation_id).version)return reply({ok:false,error:'conflict',selection:snapshot(d.conversation_id)},409);
      const value={draft:copy(d.draft),version:d.version+1};drafts.set(d.conversation_id,value);receipts.set(d.operation_id,copy(value));
    }
    if(fail==='after'){fail='';return route.abort('failed')}
    if(fail==='hold'){fail='';await new Promise(resolve=>held=resolve)}
    return reply({ok:true,saved_version:receipts.get(d.operation_id).version,selection:snapshot(d.conversation_id)});
  });
  await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
  await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#tourSelectionOpen')).toBeAttached();
  return {...base,calls,drafts,receipts,snapshot,release:()=>held?.(),setFail:x=>fail=x};
}
async function open(page){if(!await page.locator('#tourSelectionOpen').isVisible())await page.locator('#m2ConversationMenuToggle').click();await page.locator('#tourSelectionOpen').click();await expect(page.locator('#tourSelectionDialog')).toBeVisible();await expect(page.locator('#tsStatus')).toContainText(/загружен|Просмотр|Восстановлены/)}
async function save(page){await page.locator('#tsSave').click();await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере')}
function clean(s){expect(s.unexpected).toEqual([]);expect(s.errors).toEqual([]);expect(s.calls.filter(x=>!['get','save','operation'].includes(x.action))).toEqual([]);expect(s.pending.size).toBeLessThanOrEqual(2)}

for(const width of [390,430,768,1440])test('selection editor preserves order conditions and reply at '+width,async({page,browserName})=>{
  await page.setViewportSize({width,height:900});const s=await fixture(page);expect(s.calls).toEqual([]);
  await expect(page.locator('#conversationTitle')).toBeVisible();
  await page.locator('#replyText').fill('Ответ клиенту остаётся в переписке');
  await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Тихий отель у моря');await page.locator('#ts-amount').fill('240000');await page.locator('#ts-adults').fill('2');await page.locator('#ts-child_ages').fill('0, 4, 4');await save(page);
  await page.locator('#tsAdd').click();await page.locator('#ts-hotel').fill('Семейный вариант');await page.locator('[data-ts-action="up"]').click();await save(page);
  expect(s.drafts.get(11).draft.offers.map(x=>x.hotel)).toEqual(['Семейный вариант','Тихий отель у моря']);
  await page.locator('[data-ts-field="selected"]').uncheck();await save(page);expect(s.drafts.get(11).draft.offers[0].selected).toBe(false);
  await page.locator('[data-ts-select]').nth(1).click();await expect(page.locator('#ts-child_ages')).toHaveValue('0, 4, 4');
  expect(await page.evaluate(()=>document.querySelector('#tourSelectionDialog').scrollWidth-document.querySelector('#tourSelectionDialog').clientWidth)).toBeLessThanOrEqual(1);
  await page.setViewportSize({width,height:420});await expect(page.locator('#tsSave')).toBeInViewport();await expect(page.locator('#tsClose')).toBeInViewport();await page.setViewportSize({width,height:900});
  fs.mkdirSync(path.join(root,'manager2-artifacts'),{recursive:true});await page.screenshot({animations:'disabled',path:path.join(root,'manager2-artifacts',`tour-selection-${browserName}-${width}.png`)});
  await page.locator('#tsClose').click();await expect(page.locator('#replyText')).toHaveValue('Ответ клиенту остаётся в переписке');
  await page.reload();await expect.poll(()=>page.evaluate(()=>!!WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);if(!await page.locator('#tourSelectionOpen').count())await page.locator('#inboxList [data-conversation-id="11"]').click();await open(page);await expect(page.locator('[data-ts-select]')).toHaveCount(2);clean(s);
});

test('selection conflict keeps local conditions until explicit choice',async({page})=>{
  const s=await fixture(page);await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Мой вариант');await save(page);
  const other=copy(s.drafts.get(11));other.version++;other.draft.offers[0].hotel='Вариант коллеги';s.drafts.set(11,other);
  await page.locator('#ts-hotel').fill('Моя новая версия');await page.locator('#tsSave').click();await expect(page.locator('#tsRecovery')).toContainText('другая версия');await expect(page.locator('#ts-hotel')).toHaveValue('Моя новая версия');
  await page.getByRole('button',{name:'Продолжить с моими правками'}).click();await save(page);expect(s.drafts.get(11).draft.offers[0].hotel).toBe('Моя новая версия');clean(s);
});

test('lost save response reconciles without another mutation',async({page})=>{
  const s=await fixture(page,{fail:'after'});await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Сохранённый вариант');await page.locator('#tsSave').click();await expect(page.getByRole('button',{name:'Проверить сохранение'})).toBeVisible();
  await page.getByRole('button',{name:'Проверить сохранение'}).click();await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере');expect(s.calls.filter(x=>x.action==='save')).toHaveLength(1);clean(s);
});

test('known missing receipt retries only the identical draft command',async({page})=>{
  const s=await fixture(page,{fail:'before'});await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-hotel').fill('Непринятый запрос');await page.locator('#tsSave').click();await page.getByRole('button',{name:'Проверить сохранение'}).click();await page.getByRole('button',{name:'Повторить это сохранение'}).click();await expect(page.locator('#tsSaved')).toHaveText('Сохранено на сервере');
  const writes=s.calls.filter(x=>x.action==='save');expect(writes).toHaveLength(2);expect(writes[1]).toEqual(writes[0]);clean(s);
});

test('invalid children input survives close and blocks stale autosave',async({page})=>{
  const s=await fixture(page);await open(page);await page.locator('#tsEmptyAdd').click();await page.locator('#ts-child_ages').fill('4, возраст');await expect(page.locator('#tsSave')).toBeDisabled();await page.waitForTimeout(1100);expect(s.calls.filter(x=>x.action==='save')).toHaveLength(0);
  await page.locator('#tsClose').click();await open(page);await expect(page.locator('#ts-child_ages')).toHaveValue('4, возраст');await page.locator('#ts-child_ages').fill('4, 10');await save(page);expect(s.drafts.get(11).draft.offers[0].child_ages).toEqual([4,10]);clean(s);
});

test('readonly selection cannot add or save offers',async({page})=>{
  const s=await fixture(page,{readOnly:true});await open(page);await expect(page.locator('#tsAdd')).toBeDisabled();await expect(page.locator('#tsEmptyAdd')).toBeDisabled();await expect(page.locator('#tsSave')).toBeDisabled();expect(s.calls.map(x=>x.action)).toEqual(['get']);clean(s);
});
