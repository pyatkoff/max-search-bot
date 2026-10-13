const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const origin='http://127.0.0.1:4173';

// Use the canonical composer asset. All detail/send results remain in memory;
// no manager endpoint, external service or real customer can be contacted.
async function setup(page){
  const unexpected=[];
  await page.route('**/*',route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.origin!==origin||request.method()!=='GET'||url.pathname.endsWith('.php')){
      unexpected.push(request.method()+' '+url.pathname);return route.abort();
    }
    return route.continue();
  });
  await page.goto(origin+'/tests/visual/workspace-v2-fixture.html?view=conversation');
  await page.evaluate(()=>{
    sessionStorage.clear();
    const ids=[['.conversationZone','conversationZone'],['.messages','messages'],['.composer','composer'],['.composer textarea','replyText'],['.replyStatus','replyStatus'],['.sendBtn','sendReply'],['.conversationAvatar','conversationAvatar'],['.conversationTitleRow .brand','conversationTitle'],['.conversationState','conversationState'],['.conversationIdentityText .muted','conversationMeta'],['.conversationHead .actions','conversationActions']];
    for(const [selector,id] of ids)document.querySelector(selector).id=id;
    for(const id of ['deliveryFailure','composerLocked']){
      const el=document.createElement('div');el.id=id;el.className=id+' hidden';document.getElementById('composer').before(el);
    }
    const file=document.createElement('input');file.id='replyFile';file.type='file';file.className='hidden';document.getElementById('composer').append(file);
    document.getElementById('sendReply').type='submit';
    window.fixtureCalls=[];window.fixtureOwner=7;window.fixtureDeliveryFailure=null;window.fixtureDeferSend=false;
    window.fixtureMessages=[{id:1,sender_type:'customer',text:'Здравствуйте! Жду варианты отпуска.'}];
    window.WorkspaceV2={S:{manager:{id:7,role:'manager'},current:11,authGeneration:1,authExpired:false},$:id=>document.getElementById(id),statusText:()=> 'У менеджера',esc:v=>String(v),
      api:async(action,payload)=>{
        fixtureCalls.push({action,payload});
        if(action==='detail')return {ok:true,conversation:{id:payload.conversation_id,manager_id:fixtureOwner,status:'manager',channel:'max',display_name:'Пример диалога'},messages:fixtureMessages,delivery_failure:fixtureDeliveryFailure};
        if(action==='send'){
          const result=fixtureDeferSend?await new Promise(resolve=>window.fixtureFinishSend=resolve):{ok:true};
          if(result.ok)fixtureMessages.push({id:2,sender_type:'manager',text:payload.text});
          return result;
        }
        throw new Error('Unexpected fixture action');
      },pipe:async(action,payload)=>{fixtureCalls.push({action:'pipe:'+action,payload});return {ok:false};}};
  });
  await page.addScriptTag({url:origin+'/manager/assets/workspace-v2-conversation.js'});
  await page.evaluate(async()=>{
    WorkspaceV2Conversation.activateReplySession(7);WorkspaceV2Conversation.bind();
    await WorkspaceV2Conversation.open(11);fixtureCalls.length=0;
  });
  await expect(page.locator('#emojiToggle')).toBeEnabled();
  return unexpected;
}

async function noRequests(page){expect(await page.evaluate(()=>fixtureCalls)).toEqual([]);}
async function fits(page,width,height){
  const root=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth}));
  expect(root.scroll).toBeLessThanOrEqual(root.width+1);
  for(const selector of ['#emojiPicker','#emojiToggle','#replyText','#sendReply']){
    const box=await page.locator(selector).boundingBox();expect(box).not.toBeNull();
    expect(box.x).toBeGreaterThanOrEqual(0);expect(box.x+box.width).toBeLessThanOrEqual(width+1);
    expect(box.y).toBeGreaterThanOrEqual(0);expect(box.y+box.height).toBeLessThanOrEqual(height+1);
  }
}

for(const width of [390,430,768,1440])test.describe('emoji composer '+width,()=>{
  test.use({viewport:{width,height:844},hasTouch:true,isMobile:width<768});
  test('replaces selected text, persists the draft and sends the exact Unicode only on Send',async({page,browserName})=>{
    const unexpected=await setup(page);
    await page.locator('#replyText').fill('Хорошего старого отдыха');
    await page.locator('#replyText').evaluate(reply=>reply.setSelectionRange(9,16));
    await page.locator('#emojiToggle').tap();
    await expect(page.locator('#emojiToggle')).toHaveAttribute('aria-expanded','true');
    await expect(page.locator('#emojiPicker')).toBeVisible();
    await expect(page.locator('#emojiPicker [data-emoji="😊"]')).toHaveAccessibleName('Улыбка');
    await expect(page.locator('#emojiPicker [data-emoji="👍"]')).toHaveAccessibleName('Палец вверх');
    await fits(page,width,844);
    const replyBox=await page.locator('#replyText').boundingBox();expect(replyBox.width).toBeGreaterThanOrEqual(220);
    fs.mkdirSync('emoji-picker-artifacts',{recursive:true});
    await page.screenshot({path:`emoji-picker-artifacts/${browserName}-${width}-palette.png`});
    await page.locator('#emojiPicker [data-emoji="😊"]').tap();
    const draft='Хорошего 😊 отдыха';
    await expect(page.locator('#replyText')).toHaveValue(draft);
    await expect(page.locator('#emojiPicker')).toBeHidden();
    await expect(page.locator('#replyText')).toBeFocused();
    expect(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('workspaceV2.replySession.v1')).drafts.find(d=>d.id===11)?.text)).toBe(draft);
    await noRequests(page);
    await page.locator('#replyText').evaluate(reply=>reply.setSelectionRange(reply.value.length,reply.value.length));
    await page.locator('#emojiToggle').tap();await page.locator('#emojiPicker [data-emoji="🏖️"]').tap();
    const text=draft+'🏖️';await expect(page.locator('#replyText')).toHaveValue(text);await noRequests(page);
    // The open palette must not move/cancel the normal mobile Send tap.
    await page.locator('#emojiToggle').tap();await page.locator('#sendReply').tap();
    await expect(page.locator('#replyStatus')).toHaveText('Отправлено');
    await expect(page.locator('#emojiPicker')).toBeHidden();await expect(page.locator('#replyText')).toHaveValue('');
    await expect(page.locator('#messages .msg.manager .msgBody')).toHaveText(text);
    expect(await page.evaluate(()=>fixtureCalls)).toEqual([
      {action:'send',payload:{conversation_id:11,text}},
      {action:'detail',payload:{conversation_id:11}},
      {action:'pipe:detail',payload:{conversation_id:11}},
    ]);
    expect(await page.evaluate(()=>JSON.parse(sessionStorage.getItem('workspaceV2.replySession.v1')).drafts)).toEqual([]);
    expect(unexpected).toEqual([]);
  });
  test('palette scrolls and the composer stays reachable at 320px height',async({page,browserName})=>{
    await page.setViewportSize({width,height:320});const unexpected=await setup(page);
    await page.locator('#replyText').fill('Спасибо ');await page.locator('#emojiToggle').tap();
    await fits(page,width,320);
    const dimensions=await page.locator('#emojiPicker').evaluate(el=>({client:el.clientHeight,scroll:el.scrollHeight,overflow:getComputedStyle(el).overflowY}));
    expect(dimensions.overflow).toBe('auto');expect(dimensions.scroll).toBeGreaterThan(dimensions.client);
    fs.mkdirSync('emoji-picker-artifacts',{recursive:true});
    await page.screenshot({path:`emoji-picker-artifacts/${browserName}-${width}-short-palette.png`});
    const last=page.locator('#emojiPicker [data-emoji]').last(),emoji=await last.getAttribute('data-emoji');
    await last.tap();await expect(page.locator('#replyText')).toHaveValue('Спасибо '+emoji);
    await expect(page.locator('#replyText')).toBeFocused();await expect(page.locator('#emojiPicker')).toBeHidden();
    await noRequests(page);expect(unexpected).toEqual([]);
  });
});

test('keyboard insertion, Escape and outside click preserve the draft without submitting',async({page})=>{
  const unexpected=await setup(page);await page.locator('#replyText').fill('Готово ');
  await page.locator('#emojiToggle').focus();await page.keyboard.press('Enter');
  await expect(page.locator('#emojiPicker [data-emoji="😊"]')).toBeFocused();
  await page.locator('#emojiPicker [data-emoji="👍"]').focus();await page.keyboard.press('Enter');
  await expect(page.locator('#replyText')).toHaveValue('Готово 👍');await expect(page.locator('#replyText')).toBeFocused();
  await page.locator('#emojiToggle').click();await page.keyboard.press('Escape');
  await expect(page.locator('#emojiPicker')).toBeHidden();await expect(page.locator('#emojiToggle')).toBeFocused();
  await page.locator('#emojiToggle').click();await page.locator('#messages .msgBody').click();
  await expect(page.locator('#emojiPicker')).toBeHidden();await expect(page.locator('#replyText')).toHaveValue('Готово 👍');
  await noRequests(page);expect(unexpected).toEqual([]);
});

test('an emoji-only reply uses the existing Send action exactly once',async({page})=>{
  const unexpected=await setup(page);await page.locator('#replyText').fill('');
  await page.locator('#emojiToggle').click();await page.locator('#emojiPicker [data-emoji="😊"]').click();
  await expect(page.locator('#replyText')).toHaveValue('😊');await noRequests(page);
  await page.locator('#sendReply').click();await expect(page.locator('#replyStatus')).toHaveText('Отправлено');
  expect(await page.evaluate(()=>fixtureCalls.filter(c=>c.action==='send'))).toEqual([{action:'send',payload:{conversation_id:11,text:'😊'}}]);
  await expect(page.locator('#messages .msg.manager .msgBody')).toHaveText('😊');expect(unexpected).toEqual([]);
});

test('an in-flight send closes the palette and rejects stale choice clicks',async({page})=>{
  const unexpected=await setup(page);await page.locator('#replyText').fill('Сохраняемый ответ 😊');await page.locator('#emojiToggle').click();
  await page.evaluate(()=>{fixtureDeferSend=true;WorkspaceV2Conversation.sendReply();});
  await expect(page.locator('#replyText')).toBeDisabled();await expect(page.locator('#emojiToggle')).toBeDisabled();await expect(page.locator('#emojiPicker')).toBeHidden();
  await page.evaluate(()=>document.querySelector('#emojiPicker [data-emoji="👍"]').click());
  await expect(page.locator('#replyText')).toHaveValue('Сохраняемый ответ 😊');
  await page.evaluate(()=>fixtureFinishSend({ok:false,failure:{category:'unknown'}}));
  await expect(page.locator('#replyText')).toBeEnabled();await expect(page.locator('#replyStatus')).toContainText('Отправка не подтверждена');
  expect(await page.evaluate(()=>fixtureCalls)).toEqual([{action:'send',payload:{conversation_id:11,text:'Сохраняемый ответ 😊'}}]);expect(unexpected).toEqual([]);
});

test('suspension and auth recovery close the palette without changing the draft or sending',async({page})=>{
  const unexpected=await setup(page);await page.locator('#replyText').fill('Черновик 😊');await page.locator('#emojiToggle').click();
  await page.evaluate(async()=>{fixtureDeliveryFailure={category:'suspended',notice:'Диалог MAX приостановлен.'};await WorkspaceV2Conversation.refreshVisible();});
  await expect(page.locator('#emojiPicker')).toBeHidden();await expect(page.locator('#emojiToggle')).toBeDisabled();
  await page.evaluate(()=>document.querySelector('#emojiPicker [data-emoji="👍"]').click());
  await expect(page.locator('#replyText')).toHaveValue('Черновик 😊');
  await page.evaluate(async()=>{fixtureDeliveryFailure=null;await WorkspaceV2Conversation.refreshVisible();fixtureCalls.length=0;});
  await page.locator('#emojiToggle').click();
  await page.evaluate(()=>{WorkspaceV2.S.authExpired=true;WorkspaceV2Conversation.suspendForAuthRecovery();document.querySelector('#emojiPicker [data-emoji="👍"]').click();});
  await expect(page.locator('#emojiPicker')).toBeHidden();await expect(page.locator('#composer')).toBeHidden();
  await expect(page.locator('#replyText')).toHaveValue('Черновик 😊');await noRequests(page);expect(unexpected).toEqual([]);
});

test('ownership loss closes the palette and stale choices cannot recreate the cleared reply',async({page})=>{
  const unexpected=await setup(page);await page.locator('#replyText').fill('Черновик 😊');await page.locator('#emojiToggle').click();
  await page.evaluate(async()=>{fixtureOwner=8;await WorkspaceV2Conversation.refreshVisible();document.querySelector('#emojiPicker [data-emoji="👍"]').click();});
  await expect(page.locator('#emojiPicker')).toBeHidden();await expect(page.locator('#composer')).toBeHidden();await expect(page.locator('#replyText')).toHaveValue('');
  expect(await page.evaluate(()=>fixtureCalls.filter(c=>c.action==='send'))).toEqual([]);expect(unexpected).toEqual([]);
});

test('switching dialogs closes the palette and preserves each conversation draft',async({page})=>{
  const unexpected=await setup(page);await page.locator('#replyText').fill('Первый 😊');await page.locator('#emojiToggle').click();
  await page.evaluate(()=>WorkspaceV2Conversation.open(12));
  await expect(page.locator('#emojiPicker')).toBeHidden();await expect(page.locator('#replyText')).toHaveValue('');
  await page.locator('#replyText').fill('Второй ');await page.locator('#emojiToggle').click();await page.locator('#emojiPicker [data-emoji="👍"]').click();
  await page.evaluate(()=>WorkspaceV2Conversation.open(11));await expect(page.locator('#replyText')).toHaveValue('Первый 😊');
  await page.evaluate(()=>WorkspaceV2Conversation.open(12));await expect(page.locator('#replyText')).toHaveValue('Второй 👍');
  expect(await page.evaluate(()=>fixtureCalls.filter(c=>c.action==='send'))).toEqual([]);expect(unexpected).toEqual([]);
});
