const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const origin='http://127.0.0.1:4173';

async function setup(page){
  const unexpected=[];
  // Only the local static fixture is reachable. Send/detail are in-memory stubs.
  await page.route('**/*',route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.origin!==origin||request.method()!=='GET'){
      unexpected.push(request.method()+' '+url.pathname);return route.abort();
    }
    return route.continue();
  });
  await page.goto(origin+'/tests/visual/workspace-v2-fixture.html?view=conversation');
  await page.evaluate(()=>{
    const ids=[['.conversationZone','conversationZone'],['.messages','messages'],['.composer','composer'],['.composer textarea','replyText'],['.replyStatus','replyStatus'],['.sendBtn','sendReply'],['.conversationAvatar','conversationAvatar'],['.conversationTitleRow .brand','conversationTitle'],['.conversationState','conversationState'],['.conversationIdentityText .muted','conversationMeta'],['.conversationHead .actions','conversationActions']];
    for(const [selector,id] of ids)document.querySelector(selector).id=id;
    for(const id of ['deliveryFailure','composerLocked']){
      const el=document.createElement('div');el.id=id;el.className=id+' hidden';document.getElementById('composer').before(el);
    }
    const file=document.createElement('input');file.id='replyFile';file.type='file';file.className='hidden';document.getElementById('composer').append(file);
    document.getElementById('sendReply').type='submit';
    window.fixtureTouches=[];
    for(const type of ['touchstart','touchend','mousedown','mouseup','click','focusin','focusout','submit'])document.addEventListener(type,e=>{const b=document.getElementById('sendReply').getBoundingClientRect();window.fixtureTouches.push({type,target:e.target.id||e.target.tagName,top:b.top,focused:document.activeElement?.id});},true);
    window.fixtureCalls=[];window.fixtureResult={ok:false,failure:{category:'unknown',message:'Сообщение не доставлено'}};
    window.fixtureHistoryFails=true;
    const conversation={id:11,manager_id:7,status:'manager',channel:'max',display_name:'Пример диалога'};
    window.WorkspaceV2={S:{manager:{id:7},current:11,authGeneration:1,authExpired:false,detail:{conversation}},$:id=>document.getElementById(id),statusText:()=> 'У менеджера',esc:v=>String(v),
      api:async(action,payload)=>{
        window.fixtureCalls.push({action,payload});
        if(action==='send')return window.fixtureResult;
        if(action==='detail')return window.fixtureHistoryFails?{ok:false,http_status:500}:{ok:true,conversation:{...conversation,id:payload.conversation_id},messages:[],delivery_failure:window.fixtureDeliveryFailure||null};
        throw new Error('Unexpected fixture action');
      },pipe:async()=>({ok:false})};
  });
  await page.addScriptTag({url:origin+'/manager/assets/workspace-v2-conversation.js'});
  await page.evaluate(()=>{
    WorkspaceV2Conversation.activateReplySession(7);
    WorkspaceV2Conversation.renderHeader(WorkspaceV2.S.detail.conversation);
    WorkspaceV2Conversation.renderMessages([{id:1,sender_type:'customer',text:'Здравствуйте, жду варианты.'},{id:2,sender_type:'manager',text:'Пример ранее записанного ответа.'}]);
    WorkspaceV2Conversation.bind();
  });
  await page.locator('#replyText').fill('Синтетический ответ для проверки');
  return unexpected;
}
async function assertFits(page,width){
  const data=await page.evaluate(()=>({root:document.documentElement.scrollWidth,width:innerWidth}));
  expect(data.root).toBeLessThanOrEqual(data.width+1);
  for(const selector of ['#replyStatus','#sendReply']){
    const box=await page.locator(selector).boundingBox();expect(box).not.toBeNull();
    expect(box.x).toBeGreaterThanOrEqual(0);expect(box.x+box.width).toBeLessThanOrEqual(width+1);
    expect(box.y).toBeGreaterThanOrEqual(0);expect(box.y+box.height).toBeLessThanOrEqual(845);
  }
}
for(const width of [390,430,768,1440])test.describe('send feedback '+width,()=>{
  test.use({viewport:{width,height:844},hasTouch:true,isMobile:width<768});
  test('uncertain send and confirmed send with failed refresh remain readable',async({page,browserName})=>{
    const unexpected=await setup(page),before=await page.locator('#messages').innerHTML();
    await page.locator('#sendReply').tap();
    await expect(page.locator('#replyStatus')).toContainText('Отправка не подтверждена');
    await expect(page.locator('#deliveryFailure')).toContainText('Отправка не подтверждена');
    await expect(page.locator('#replyText')).toHaveValue('Синтетический ответ для проверки');
    expect(await page.evaluate(()=>fixtureCalls.filter(c=>c.action==='send').length)).toBe(1);
    expect(await page.locator('#messages').innerHTML()).toBe(before);await assertFits(page,width);
    fs.mkdirSync('send-feedback-artifacts',{recursive:true});
    await page.screenshot({path:`send-feedback-artifacts/${browserName}-${width}-unconfirmed.png`});
    await page.evaluate(()=>{window.fixtureResult={ok:true};});
    await page.locator('#sendReply').tap();
    const clicks=await page.evaluate(()=>fixtureTouches.filter(e=>e.type==='click'));
    expect(clicks.filter(e=>e.target==='sendReply')).toHaveLength(2);
    await expect(page.locator('#replyStatus')).toHaveText('Отправлено. Переписку пока не удалось обновить.');
    await expect(page.locator('#replyText')).toHaveValue('');
    await expect(page.locator('#deliveryFailure')).toBeHidden();
    expect(await page.evaluate(()=>fixtureCalls.filter(c=>c.action==='send').length)).toBe(2);
    expect(await page.locator('#messages').innerHTML()).toBe(before);await assertFits(page,width);
    await page.screenshot({path:`send-feedback-artifacts/${browserName}-${width}-confirmed.png`});
    expect(unexpected).toEqual([]);
  });
  test('persisted suspended detail shows the notice without unlocking or losing the draft',async({page,browserName})=>{
    const unexpected=await setup(page);
    const notice='Пользователь остановил или заблокировал бота MAX. Отправка станет доступна после новой активности клиента — когда он снова запустит или разблокирует бота.';
    await page.locator('#replyFile').setInputFiles({name:'synthetic.png',mimeType:'image/png',buffer:Buffer.from('Synthetic file, never uploaded')});
    await page.evaluate(async notice=>{
      window.fixtureHistoryFails=false;
      window.fixtureDeliveryFailure={category:'suspended',message:'Key: error.dialog.suspended, args: [990000001,].',notice,retry_allowed:false};
      await WorkspaceV2Conversation.open(11,{preserveAttachment:true});
    },notice);
    await expect(page.locator('#deliveryFailure')).toHaveText(notice);
    await expect(page.locator('#deliveryFailure')).toBeVisible();
    await expect(page.locator('#deliveryFailure')).not.toContainText('error.dialog');
    await expect(page.locator('#sendReply')).toBeDisabled();await expect(page.locator('#replyFile')).toBeDisabled();
    await expect(page.locator('#replyText')).toHaveValue('Синтетический ответ для проверки');
    expect(await page.locator('#replyFile').evaluate(input=>input.files[0]?.name)).toBe('synthetic.png');
    await page.evaluate(()=>WorkspaceV2Conversation.sendReply());
    expect(await page.evaluate(()=>fixtureCalls.filter(c=>c.action==='send').length)).toBe(0);
    await assertFits(page,width);
    fs.mkdirSync('send-feedback-artifacts',{recursive:true});
    await page.screenshot({path:`send-feedback-artifacts/${browserName}-${width}-suspended-notice.png`});
    expect(unexpected).toEqual([]);
  });
});
test('known suspension preserves the reason and blocks a repeat send',async({page})=>{
  const unexpected=await setup(page);
  await page.evaluate(()=>{window.fixtureResult={ok:false,error_message:'Диалог MAX приостановлен. Клиенту нужно снова запустить бота.',failure:{category:'suspended',message:'Key: error.dialog.suspended, args: [990000001,].'}};});
  await page.locator('#sendReply').click();
  await expect(page.locator('#replyStatus')).toHaveText('Диалог MAX приостановлен. Клиенту нужно снова запустить бота.');
  await expect(page.locator('#deliveryFailure')).toHaveText('Диалог MAX приостановлен. Клиенту нужно снова запустить бота.');
  await expect(page.locator('#deliveryFailure')).not.toContainText('error.dialog');
  await expect(page.locator('#sendReply')).toBeDisabled();await expect(page.locator('#replyFile')).toBeDisabled();
  await expect(page.locator('#replyText')).toHaveValue('Синтетический ответ для проверки');
  await page.evaluate(()=>WorkspaceV2Conversation.sendReply());
  expect(await page.evaluate(()=>fixtureCalls.filter(c=>c.action==='send').length)).toBe(1);expect(unexpected).toEqual([]);
});
test('successful send is not relabelled when only the Inbox refresh throws',async({page})=>{
  const unexpected=await setup(page);
  await page.evaluate(()=>{window.fixtureResult={ok:true};window.fixtureHistoryFails=false;window.WorkspaceV2Inbox={markRead(){},markActive(){},load:async()=>{throw new Error('Synthetic Inbox refresh failure');}};});
  await page.locator('#sendReply').click();
  await expect(page.locator('#replyStatus')).toHaveText('Отправлено');
  await expect(page.locator('#replyText')).toHaveValue('');
  expect(await page.evaluate(()=>fixtureCalls.filter(c=>c.action==='send').length)).toBe(1);expect(unexpected).toEqual([]);
});

for(const key of ['Enter','Space'])test('keyboard '+key+' submits once without pointer events',async({page})=>{
  const unexpected=await setup(page);
  await page.evaluate(()=>{window.fixtureResult={ok:true};window.fixtureHistoryFails=false;});
  await page.locator('#sendReply').focus();
  await page.keyboard.press(key);
  await expect(page.locator('#replyStatus')).toHaveText('Отправлено');
  expect(await page.evaluate(()=>fixtureCalls.filter(c=>c.action==='send').length)).toBe(1);
  expect(await page.evaluate(()=>fixtureTouches.some(e=>['touchstart','mousedown'].includes(e.type)))).toBe(false);
  expect(unexpected).toEqual([]);
});
