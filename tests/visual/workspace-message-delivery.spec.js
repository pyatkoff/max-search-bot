const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const path=require('node:path');
const fs=require('node:fs');
const origin='http://127.0.0.1:4173';
// Real service -> native SQL storage -> a new PDO connection -> authorized detail.
// Transport/authorization collaborators are synthetic, never production accounts.
const fixture=JSON.parse(execFileSync('php',[path.join(__dirname,'../run_manager_message_delivery_regression.php'),'--json'],{encoding:'utf8'}));
const draft='Неотправленный черновик';
async function mount(page,rows){
  await page.evaluate(()=>{
    document.querySelector('.messages').id='messages';
    document.querySelector('.composer').id='composer';
    document.querySelector('.composer textarea').id='replyText';
    document.getElementById('replyText').value='Неотправленный черновик';
    window.WorkspaceV2={S:{current:11,manager:{id:7},authGeneration:1,detail:{conversation:{id:11}}},$:id=>document.getElementById(id)};
  });
  await page.addScriptTag({url:origin+'/manager/assets/workspace-v2-conversation.js'});
  await page.evaluate(messages=>WorkspaceV2Conversation.renderMessages(messages),rows);
}
async function setup(page,rows){
  const unexpected=[];
  await page.route('**/*',route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.origin!==origin||request.method()!=='GET'||url.pathname.endsWith('.php')){unexpected.push(request.url());return route.abort();}
    return route.continue();
  });
  await page.goto(origin+'/tests/visual/workspace-v2-fixture.html?view=conversation');
  await mount(page,rows);return unexpected;
}
for(const width of [390,430,768,1440])test.describe('message receipt '+width,()=>{
  test.use({viewport:{width,height:844},hasTouch:true,isMobile:width<768});
  test('stored send state survives reload and exposes honest reading limits on tap',async({page,browserName})=>{
    const rows=[...fixture.max,...fixture.legacy],unexpected=await setup(page,rows);
    const badges=page.locator('.messageDelivery');
    await expect(badges).toHaveCount(2);
    await expect(badges.first().locator('summary')).toHaveText('Отправлено');
    await expect(badges.last().locator('summary')).toHaveText('Статус не сохранён');
    fs.mkdirSync('delivery-status-artifacts',{recursive:true});
    await page.screenshot({path:`delivery-status-artifacts/${browserName}-${width}-saved.png`});
    await badges.first().locator('summary').tap();
    await expect(badges.first()).toHaveAttribute('open','');
    await expect(badges.first().locator('.messageDeliveryInfo')).toContainText('не подтверждение');
    await expect(badges.first().locator('.messageReadUnavailable')).toBeVisible();
    await expect(badges.first().locator('.messageReadUnavailable')).toContainText('не передаёт');
    const box=await badges.first().boundingBox();expect(box.x).toBeGreaterThanOrEqual(0);expect(box.x+box.width).toBeLessThanOrEqual(width+1);
    const root=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth}));expect(root.scroll).toBeLessThanOrEqual(root.width+1);
    await page.screenshot({path:`delivery-status-artifacts/${browserName}-${width}-explanation.png`});
    await expect(page.locator('#replyText')).toHaveValue(draft);
    await page.evaluate(messages=>WorkspaceV2Conversation.renderMessages(messages),rows);
    await expect(badges.first()).toHaveAttribute('open','');
    await page.reload();await mount(page,rows);
    await expect(badges.first().locator('summary')).toHaveText('Отправлено');
    await badges.last().locator('summary').tap();
    await expect(badges.last().locator('.messageDeliveryInfo')).toContainText('не означает, что оно не доставлено');
    expect(unexpected).toEqual([]);
  });
});
for(const [key,label,copy] of [['telegram','Отправлено','Telegram'],['website','В чате сайта','сохранено для чата сайта']])test(key+' status uses its own channel semantics',async({page})=>{
  const unexpected=await setup(page,fixture[key]);const badge=page.locator('.messageDelivery');
  await expect(badge.locator('summary')).toHaveText(label);await badge.locator('summary').click();
  await expect(badge.locator('.messageDeliveryInfo')).toContainText(copy);expect(unexpected).toEqual([]);
});
test('keyboard opens and closes explanation, never sends or changes draft',async({page})=>{
  const unexpected=await setup(page,fixture.max);const summary=page.locator('.messageDelivery summary');
  await summary.focus();await page.keyboard.press('Enter');await expect(page.locator('.messageReadUnavailable')).toBeVisible();
  await page.keyboard.press('Space');await expect(page.locator('.messageReadUnavailable')).toBeHidden();
  await expect(page.locator('#replyText')).toHaveValue(draft);expect(unexpected).toEqual([]);
});
test('customer, AI summary and untrusted read state never acquire a read badge',async({page})=>{
  const sent=fixture.max[0],rows=[{...sent,id:90,direction:'inbound',sender_type:'customer'},{...sent,id:0,sender_type:'ai'},{...sent,id:91,delivery:{...sent.delivery,state:'read'}}];
  const unexpected=await setup(page,rows);
  await expect(page.locator('.msg.customer .messageDelivery')).toHaveCount(0);await expect(page.locator('.msg.ai .messageDelivery')).toHaveCount(0);
  await expect(page.locator('.messageDelivery summary')).toHaveText('Статус не сохранён');expect(unexpected).toEqual([]);
});
