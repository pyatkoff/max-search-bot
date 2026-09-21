const {test, expect}=require('@playwright/test');
const fs=require('node:fs');
const base='http://127.0.0.1:4173';
const event=(id=101,conversation=11)=>({message_id:id,conversation_id:conversation});
const freshFeed=()=>({upper:100,rows:[],owner:7,status:200,requests:[]});

async function setup(page,feed=freshFeed()){
  await page.addInitScript(()=>{
    window.toneStarts=0;
    const original=OscillatorNode.prototype.start;
    OscillatorNode.prototype.start=function(...args){window.toneStarts++;return original.apply(this,args);};
  });
  await page.route('**/notification-events.php',route=>{
    const data=route.request().postDataJSON();feed.requests.push(data);
    return route.fulfill({status:feed.status,contentType:'application/json',body:JSON.stringify({ok:feed.status===200,manager_id:feed.owner,cursor:feed.upper,events:feed.rows,has_more:false})});
  });
  await page.goto(base+'/tests/visual/workspace-v2-fixture.html?view=inbox');
  await page.addStyleTag({url:base+'/manager/assets/workspace-v2-sound.css'});
  await page.evaluate(()=>{
    document.querySelector('.notificationHealth').id='notificationStatus';
    document.querySelector('.messages').id='messages';
    document.querySelector('.composer').id='composer';
    document.querySelector('.composer textarea').id='replyText';
    document.getElementById('replyText').value='Несохранённый ответ туристу';
  });
  await page.addScriptTag({url:base+'/manager/assets/workspace-v2.js'});
  await page.evaluate(()=>Object.assign(WorkspaceV2.S,{manager:{id:7},authGeneration:1,authExpired:false,csrf:'synthetic-csrf',current:11}));
  await page.addScriptTag({url:base+'/manager/assets/workspace-v2-sound-ledger.js'});
  await page.addScriptTag({url:base+'/manager/assets/workspace-v2-sound.js'});
  await page.evaluate(()=>WorkspaceV2Sound.activate(7));
  return feed;
}
const tones=page=>page.evaluate(()=>window.toneStarts);
async function enable(page){await page.getByRole('button',{name:'Включить звук',exact:true}).click();await expect(page.getByRole('button',{name:'Выключить звук',exact:true})).toHaveAttribute('aria-pressed','true');}
async function poll(page){return page.evaluate(()=>WorkspaceV2Sound.poll(true));}
async function push(page,payload){
  return page.evaluate(async payload=>{
    const reg=await navigator.serviceWorker.register('/tests/notification-sound/worker.js');
    if(!reg.active)await new Promise(resolve=>{const worker=reg.installing||reg.waiting;worker.addEventListener('statechange',()=>{if(worker.state==='activated')resolve();});});
    const channel=new MessageChannel();
    return new Promise((resolve,reject)=>{
      const timer=setTimeout(()=>{channel.port1.close();reject(new Error('worker_timeout'));},15000);
      channel.port1.onmessage=event=>{clearTimeout(timer);channel.port1.close();resolve(event.data);};
      reg.active.postMessage({type:'TEST_PUSH',payload},[channel.port2]);
    });
  },payload);
}

for(const width of [390,430,768,1440])test.describe(width+'px',()=>{
  test.use({viewport:{width,height:844},hasTouch:true,isMobile:width<768});
  test('enable, test, new incoming and disable are reachable',async({page,browserName})=>{
    const feed=await setup(page);
    await expect(page.getByRole('button',{name:'Проверить звук'})).toBeVisible();
    expect(await tones(page)).toBe(0);
    await enable(page);expect(await tones(page)).toBe(1);
    expect(feed.requests[0]).toEqual({action:'poll',csrf:'synthetic-csrf',cursor:null});
    await page.evaluate(()=>Object.assign(WorkspaceV2.S,{queue:'closed',leadSearch:'nonmatching filter',leadProjectFilter:'other-project'}));
    feed.upper=101;feed.rows=[event()];await poll(page);expect(await tones(page)).toBe(2);
    await poll(page);expect(await tones(page)).toBe(2);
    const box=await page.locator('#soundStatus').boundingBox();expect(box.x).toBeGreaterThanOrEqual(0);expect(box.x+box.width).toBeLessThanOrEqual(width);
    for(const button of await page.locator('#soundStatus button').all())expect((await button.boundingBox()).height).toBeGreaterThanOrEqual(44);
    fs.mkdirSync('sound-evidence',{recursive:true});await page.screenshot({path:`sound-evidence/${browserName}-${width}.png`});
    await page.getByRole('button',{name:'Выключить звук'}).tap();feed.upper=102;feed.rows.push(event(102));await poll(page);expect(await tones(page)).toBe(2);
    await page.getByRole('button',{name:'Проверить звук'}).tap();expect(await tones(page)).toBe(3);
    await expect(page.getByRole('button',{name:'Включить звук',exact:true})).toHaveAttribute('aria-pressed','false');
    await expect(page.locator('#replyText')).toHaveValue('Несохранённый ответ туристу');
  });
});

test('new message in the open conversation signals without changing draft or transcript',async({page})=>{
  const feed=await setup(page);await enable(page);
  await page.evaluate(()=>{document.querySelector('.conversationZone').classList.add('open');document.getElementById('messages').scrollTop=25;});
  const before=await page.locator('#messages').innerHTML();
  feed.upper=101;feed.rows=[event()];await poll(page);
  expect(await tones(page)).toBe(2);expect(await page.locator('#messages').innerHTML()).toBe(before);
  await expect(page.locator('#replyText')).toHaveValue('Несохранённый ответ туристу');
  expect(feed.requests.every(request=>request.action==='poll'&&Object.keys(request).length===3)).toBe(true);
});

test('two enabled tabs share one real lock and ledger for a new arrival',async({page,context})=>{
  const feed=await setup(page);await enable(page);
  const other=await context.newPage();await setup(other,feed);await enable(other);
  for(const p of [page,other])await p.evaluate(()=>Object.defineProperty(document,'visibilityState',{configurable:true,get:()=> 'visible'}));
  const before=(await tones(page))+(await tones(other));feed.upper=101;feed.rows=[event()];
  await Promise.all([poll(page),poll(other)]);
  expect((await tones(page))+(await tones(other))).toBe(before+1);
});

test('push and page poll share one arrival cue; other notifications are unchanged',async({page})=>{
  const feed=await setup(page);await enable(page);feed.upper=101;feed.rows=[event()];
  const payload={title:'Synthetic incoming',body:'Synthetic',conversationId:11,incomingMessageId:101,notificationManagerId:7,notificationKind:'customer_message'};
  const first=await push(page,payload);expect(first.error).toBeUndefined();
  expect(await tones(page)).toBe(2);expect(first.shown.at(-1).options.silent).toBe(true);
  const repeat=await push(page,payload);expect(await tones(page)).toBe(2);expect(repeat.shown.at(-1).options.silent).toBe(true);
  const reminder=await push(page,{title:'Synthetic reminder',conversationId:11});expect(reminder.shown.at(-1).options.silent).toBeUndefined();
});

test('system notification first prevents a later page duplicate after a hidden tab',async({page})=>{
  const feed=await setup(page);await enable(page);
  await page.evaluate(()=>Object.defineProperty(document,'visibilityState',{configurable:true,get:()=> 'hidden'}));
  feed.upper=101;feed.rows=[event()];await poll(page);expect(await tones(page)).toBe(1);
  const result=await push(page,{conversationId:11,incomingMessageId:101,notificationManagerId:7,notificationKind:'customer_message'});
  expect(result.shown.at(-1).options.silent).toBeUndefined();
  await page.evaluate(()=>Object.defineProperty(document,'visibilityState',{configurable:true,get:()=> 'visible'}));
  await poll(page);expect(await tones(page)).toBe(1);
});

test('auth expiry stops polling and leaves the draft untouched',async({page})=>{
  const feed=await setup(page);await enable(page);feed.status=401;await poll(page);
  const count=feed.requests.length;await poll(page);expect(feed.requests).toHaveLength(count);
  expect(await tones(page)).toBe(1);await expect(page.locator('#replyText')).toHaveValue('Несохранённый ответ туристу');
  await expect(page.locator('#soundToggle')).toBeDisabled();
});

test('another authenticated manager response cannot produce a cue',async({page})=>{
  const feed=await setup(page);await enable(page);feed.owner=8;feed.upper=101;feed.rows=[event()];await poll(page);
  expect(await tones(page)).toBe(1);await expect(page.locator('#soundMessage')).toContainText('временно недоступен');
});

test('long suspension rebaselines instead of sounding old history',async({page})=>{
  const feed=await setup(page);await enable(page);feed.upper=101;feed.rows=[event()];
  await page.evaluate(()=>WorkspaceSoundLedger.run(7,ledger=>{ledger.pollAt=Date.now()-60000;}));
  await poll(page);expect(await tones(page)).toBe(1);expect(feed.requests.at(-1).cursor).toBeNull();
});

test('browser audio rejection is visible and cannot claim sound enabled',async({page})=>{
  await setup(page);await page.evaluate(()=>{AudioContext.prototype.resume=()=>Promise.reject(new Error('NotAllowedError'));});
  await page.getByRole('button',{name:'Включить звук',exact:true}).click();
  await expect(page.locator('#soundMessage')).toContainText('не разрешил звук');
  await expect(page.locator('#soundToggle')).toHaveAttribute('aria-pressed','false');expect(await tones(page)).toBe(0);
});

test('missing cross-tab coordination does not silently enable duplicate-prone sound',async({page})=>{
  await setup(page);await page.evaluate(()=>{WorkspaceSoundLedger.supported=()=>false;});
  await page.getByRole('button',{name:'Включить звук',exact:true}).click();
  await expect(page.locator('#soundMessage')).toContainText('недоступен');expect(await tones(page)).toBe(0);
});

test('recent baseline is silent and a later lower ID sounds once',async({page})=>{
  const feed=freshFeed();feed.rows=[event(110)];feed.upper=110;
  await setup(page,feed);await enable(page);expect(await tones(page)).toBe(1);
  feed.rows.unshift(event(109));await poll(page);expect(await tones(page)).toBe(2);
  await poll(page);expect(await tones(page)).toBe(2);
});
