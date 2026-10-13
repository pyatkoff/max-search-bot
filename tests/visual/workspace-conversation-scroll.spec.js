const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const origin='http://127.0.0.1:4173';

// Real Manager assets and browser layout; all conversations/sends are synthetic.
async function setup(page){
  const unexpected=[];
  await page.route('**/*',route=>{
    const r=route.request(),url=new URL(r.url());
    if(url.origin!==origin||r.method()!=='GET'||url.pathname.endsWith('.php')){
      unexpected.push(r.method()+' '+url.pathname);return route.abort();
    }
    return route.continue();
  });
  await page.goto(origin+'/tests/visual/workspace-v2-fixture.html?view=conversation');
  await page.addStyleTag({url:origin+'/manager/assets/workspace-v2-jump.css'});
  await page.evaluate(()=>{
    sessionStorage.clear();
    for(const [selector,id] of [['.conversationZone','conversationZone'],['.messages','messages'],['.composer','composer'],['.composer textarea','replyText'],['.replyStatus','replyStatus'],['.sendBtn','sendReply'],['.conversationAvatar','conversationAvatar'],['.conversationTitleRow .brand','conversationTitle'],['.conversationState','conversationState'],['.conversationIdentityText .muted','conversationMeta'],['.conversationHead .actions','conversationActions']])document.querySelector(selector).id=id;
    document.getElementById('composer').classList.add('hidden');
    document.getElementById('replyText').value='';
    document.getElementById('sendReply').type='submit';
    for(const id of ['deliveryFailure','composerLocked']){const el=document.createElement('div');el.id=id;el.className='hidden';document.getElementById('composer').before(el);}
    window.fixtureMessages=Array.from({length:45},(_,i)=>({id:i+1,sender_type:i%2?'manager':'customer',text:'Синтетическое сообщение '+(i+1)+'\nПроверяем длинную историю переписки.',created_at:'2026-10-13 12:00:00'}));
    window.fixtureSends=[];
    window.WorkspaceV2={S:{manager:{id:7,role:'manager'},current:0,authGeneration:1,authExpired:false},$:id=>document.getElementById(id),statusText:()=> 'У менеджера',esc:v=>String(v),
      api:async(action,payload)=>{
        if(action==='detail')return {ok:true,conversation:{id:payload.conversation_id,manager_id:7,status:'manager',channel:'max',display_name:'Тест прокрутки'},messages:fixtureMessages.map(m=>({...m}))};
        if(action==='send'){fixtureSends.push(payload);fixtureMessages.push({id:100+fixtureSends.length,sender_type:'manager',text:payload.text});return {ok:true};}
        throw new Error('Unexpected synthetic action '+action);
      },pipe:async()=>({ok:false})};
  });
  await page.addScriptTag({url:origin+'/manager/assets/workspace-v2-conversation.js'});
  await page.addScriptTag({url:origin+'/manager/assets/workspace-v2-jump.js'});
  await page.evaluate(async()=>{WorkspaceV2Conversation.activateReplySession(7);WorkspaceV2Conversation.bind();await WorkspaceV2Conversation.open(11);});
  return unexpected;
}
async function gap(page){return page.locator('#messages').evaluate(el=>el.scrollHeight-el.clientHeight-el.scrollTop);}
async function pinned(page){await expect.poll(()=>gap(page),{timeout:3000}).toBeLessThanOrEqual(2);}
async function anchor(page){return page.locator('#messages').evaluate(box=>{const top=box.getBoundingClientRect().top,row=[...box.children].find(n=>n.getBoundingClientRect().bottom>top+1);return {id:row?.dataset.scrollMessageKey||row?.querySelector('.msgBody')?.textContent,offset:row?.getBoundingClientRect().top-top};});}
async function layoutSettled(page){await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));}
async function sameAnchor(page,before){await layoutSettled(page);const after=await anchor(page);expect(after.id).toBe(before.id);expect(Math.abs(after.offset-before.offset)).toBeLessThanOrEqual(2);}

for(const width of [390,430,768,1440])test.describe('conversation scrolling '+width,()=>{
  test.use({viewport:{width,height:844}});
  test('opening, composer growth, keyboard-height viewport and sending keep the latest message visible',async({page,browserName})=>{
    const unexpected=await setup(page);
    await pinned(page);
    await page.locator('#replyText').fill('Длинный ответ\nВторая строка\nТретья строка\nЧетвёртая строка\nПятая строка');
    await pinned(page);
    await page.getByRole('button',{name:'Добавить смайлик',exact:true}).click();await pinned(page);
    await page.getByRole('button',{name:'Улыбка',exact:true}).click();await pinned(page);
    await page.setViewportSize({width,height:420});await pinned(page);
    const send=await page.locator('#sendReply').boundingBox();expect(send.y+send.height).toBeLessThanOrEqual(421);
    const documentSize=await page.evaluate(()=>({height:document.documentElement.scrollHeight,width:document.documentElement.scrollWidth}));
    expect(documentSize.height).toBeLessThanOrEqual(421);expect(documentSize.width).toBeLessThanOrEqual(width+1);
    await page.setViewportSize({width,height:844});await pinned(page);
    await page.locator('#replyText').press('Control+Enter');
    await expect(page.locator('#replyStatus')).toHaveText('Отправлено');await pinned(page);
    expect(await page.evaluate(()=>fixtureSends.length)).toBe(1);
    expect(unexpected).toEqual([]);
    fs.mkdirSync('conversation-scroll-artifacts',{recursive:true});
    await page.screenshot({path:`conversation-scroll-artifacts/${browserName}-${width}-latest.png`});
  });
  test('incoming messages and layout changes preserve earlier-history reading; one click returns to latest',async({page})=>{
    const unexpected=await setup(page);await pinned(page);
    await page.locator('#messages').hover();await page.mouse.wheel(0,-900);await layoutSettled(page);
    await expect(page.getByRole('button',{name:'Перейти к последним сообщениям',exact:true})).toBeVisible();
    const before=await anchor(page);
    await page.evaluate(async()=>{fixtureMessages.push({id:90,sender_type:'customer',text:'Новое синтетическое сообщение'});await WorkspaceV2Conversation.refreshVisible();});
    await sameAnchor(page,before);
    await page.locator('#replyText').fill('Черновик\nНесколько строк\nБез отправки');await sameAnchor(page,before);
    await page.setViewportSize({width,height:480});await sameAnchor(page,before);
    await page.setViewportSize({width,height:844});await sameAnchor(page,before);
    await page.getByRole('button',{name:'Перейти к последним сообщениям',exact:true}).click();await pinned(page);
    await expect(page.locator('#replyText')).toHaveValue('Черновик\nНесколько строк\nБез отправки');
    expect(await page.evaluate(()=>fixtureSends.length)).toBe(0);expect(unexpected).toEqual([]);
  });
});

test('a scroll just before composer reflow keeps the newly chosen history position',async({page})=>{
  await page.setViewportSize({width:1440,height:844});const unexpected=await setup(page);await pinned(page);
  await page.evaluate(()=>{
    const messages=document.getElementById('messages');messages.dispatchEvent(new WheelEvent('wheel',{deltaY:-700,bubbles:true}));messages.scrollTop=700;
    const reply=document.getElementById('replyText');reply.value='Черновик\nВторая строка\nТретья строка\nЧетвёртая строка';reply.dispatchEvent(new Event('input',{bubbles:true}));
  });
  const before=await anchor(page);await sameAnchor(page,before);
  expect(await gap(page)).toBeGreaterThan(200);expect(unexpected).toEqual([]);
});

for(const reading of [false,true])test(`late photo layout ${reading?'preserves the history anchor':'follows the latest message'}`,async({page})=>{
  await page.setViewportSize({width:1440,height:844});const unexpected=await setup(page);await pinned(page);
  let release,requested;const hold=new Promise(resolve=>release=resolve),started=new Promise(resolve=>requested=resolve);
  await page.route('**/scroll-late-photo.svg',async route=>{requested();await hold;await route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="600" height="420"><rect width="600" height="420" fill="#dde8ef"/></svg>'});});
  await page.evaluate(async reading=>{const index=reading?10:44;fixtureMessages[index].attachments=[{type:'image',name:'Синтетическое фото',url:location.origin+'/scroll-late-photo.svg'}];await WorkspaceV2Conversation.refreshVisible();},reading);
  if(reading)await page.locator('#messages').evaluate(box=>{box.dispatchEvent(new WheelEvent('wheel',{deltaY:-900,bubbles:true}));box.scrollTop=box.children[10].offsetTop-box.offsetTop;});
  await started;
  if(reading)await page.locator('#messages').evaluate(box=>box.scrollTop=box.children[15].offsetTop-box.offsetTop);
  const before=reading?await anchor(page):null;release();
  await expect.poll(()=>page.locator('#messages img').evaluate(img=>img.complete&&img.naturalHeight>0)).toBe(true);
  if(reading){await sameAnchor(page,before);expect(await gap(page)).toBeGreaterThan(200);}else await pinned(page);
  expect(unexpected).toEqual([]);
});
