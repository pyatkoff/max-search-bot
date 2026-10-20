const {test,expect}=require('@playwright/test');
const fs=require('node:fs'),path=require('node:path');
const {setup,root}=require('./fixtures/internal-notes');

// The fixture renders the real Manager2 PHP shell and production assets. These
// extra routes are local synthetic assistant reads/decisions, never live data.
async function setupAssistant(page){
  const base=await setup(page);
  const calls=[];
  const recommendation=(changes={})=>({revision:'a'.repeat(64),next_action:'check_offer',reason:'Клиент выбрал два отеля. Нужно сравнить питание и вылет из СПб.',checks:['Сравнить выбранные отели на двоих.','Проверить тарифы с завтраками и полную стоимость.'],prepared_at:new Date().toISOString(),expires_at:new Date(Date.now()+3600000).toISOString(),latest_message_id:35,evidence_message_ids:[35],history_incomplete:false,decision:null,...changes});
  const items=new Map([
    [11,{conversation_id:11,display_name:'Свадебное путешествие',channel:'telegram',last_message_at:new Date().toISOString(),tasks:[],recommendation:recommendation()}],
    [12,{conversation_id:12,display_name:'Клиент выбирает отель',channel:'telegram',last_message_at:new Date().toISOString(),tasks:[],recommendation:recommendation({next_action:'no_contact',reason:'Клиент обсуждает варианты. Сейчас отвечать не нужно.',checks:['Дождаться обратной связи по подборке.']})}],
    [901,{conversation_id:901,display_name:'Ранний клиент с задачей',channel:'max',last_message_at:'2026-09-01T10:00:00Z',tasks:[{id:31,title:'Проверить ответ поставщика',status:'open',due_state:'overdue',due_at_utc:'2026-10-19T09:00:00Z'}],recommendation:null}]
  ]);
  await page.route('**/manager/ai-draft.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,prepared:false})}));
  await page.route('**/manager/assistant-api.php',async route=>{
    const d=route.request().postDataJSON();calls.push(d);expect(d.csrf).toBe('fixture-csrf');
    let result;
    if(d.action==='list')result=d.cursor?{ok:true,items:[items.get(901)],has_more:false,next_cursor:null}:{ok:true,items:[items.get(11),items.get(12)],has_more:true,next_cursor:12};
    else if(d.action==='detail')result={ok:true,item:items.get(Number(d.conversation_id))};
    else if(d.action==='decision'){const item=items.get(Number(d.conversation_id));expect(d.revision).toBe(item.recommendation.revision);expect(d.expected_decision).toBe(item.recommendation.decision);item.recommendation={...item.recommendation,decision:d.decision};result={ok:true};}
    else throw Error('Unexpected assistant fixture action '+d.action);
    return route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
  });
  await expect.poll(()=>page.evaluate(()=>WorkspaceV2.S.workspaceBound&&!WorkspaceV2.S.booting)).toBe(true);
  return{base,calls,items};
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
  await page.locator('#assistantMore').click();await expect(page.locator('[data-assistant-client]')).toHaveCount(3);await expect(page.locator('#assistantMore')).toBeHidden();expect(s.calls.at(-1).cursor).toBe(12);
await page.locator('[data-assistant-client="11"]').click();await expect(page.locator('#assistantDetail')).toContainText('Сравнить выбранные отели на двоих.');await expect(page.locator('#assistantPlan')).toBeVisible();if(width<768)await expect(page.locator('.assistantToolbar')).toBeHidden();await page.locator('.assistantSource summary').click();await expect(page.locator('.assistantSource')).toContainText('Сообщений в основании: 1');await bounded(page);await capture(page,browserName,width,'client');
  await page.locator('[data-assistant-decision="done"]').click();await expect(page.locator('#assistantDetail')).toContainText('Отмечено выполненным');expect(s.calls.filter(c=>c.action==='decision')).toHaveLength(1);
  if(width<768)await page.locator('#assistantBack').click();await page.locator('[data-assistant-client="12"]').click();await expect(page.locator('#assistantDetail')).toContainText('Дождаться клиента');await expect(page.locator('#assistantPlan')).toHaveCount(0);await expect(page.locator('[data-assistant-decision]')).toHaveCount(0);
  await page.setViewportSize({width,height:420});await bounded(page);await capture(page,browserName,width,'short');await page.locator('#assistantClose').click();
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
