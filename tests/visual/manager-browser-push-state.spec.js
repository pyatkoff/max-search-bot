const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const {lifecycle}=require('../fixtures/manager_push_browser_fixture');
const {setup:shell,origin}=require('./fixtures/personal-workspace');
const endpoint='https://push.example.test/PRIVATE_BROWSER_B_ENDPOINT';
const viewports=[{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}];

// The actual PHP shell and source assets run unchanged. Browser capabilities,
// native subscription work and every HTTP response below are synthetic/local.
async function setup(page,options={}){
 const protocol=lifecycle(7);
 const config={permission:'granted',subscription:false,native:'ready',registration:'ready',supported:true,allowActivation:false,...options};
 const reads=[],proofs=[],saves=[],unexpected=[],pendingProofs=[];let proofReason=options.proof||'healthy_subscription';
 const connectionCalls=[],connectionReceipts=new Map(),heldConnectionReads=[];let connectionMode='success';
 const connectionRows=options.connections?[{id:1,revision:1,enabled:true,current:true,browser:'Chrome · Windows',status:'saved',last_activity_at:'2026-10-16T12:00:00Z'},{id:2,revision:1,enabled:true,current:false,browser:'Firefox · macOS',status:'saved',last_activity_at:'2026-10-15T10:30:00Z'},{id:3,revision:1,enabled:true,current:false,browser:'Safari · iOS',status:'attention',last_activity_at:null}]:[];
 const aggregate=()=>({is_working:true,notification_path_usable:options.accountHealthy!==0,healthy_subscription_count:options.accountHealthy===0?0:1,notification_path_reason:options.accountHealthy===0?'no_subscription':'healthy_subscription'});
 const current=()=>({notification_path_usable:proofReason==='healthy_subscription',notification_path_reason:proofReason});
 const state=await shell(page,{skipPushStubs:true,...(options.messages?{messages:options.messages}:{}),beforeNavigate:async page=>{
  await page.addInitScript(({config,endpoint})=>{
   sessionStorage.clear();
   const f=window.pushBrowserFixture={calls:{permission:0,registration:[],register:0,read:0,subscribe:0,unsubscribe:0,confirmation:0},pendingNative:[],subscription:config.subscription,endpoint};
   const sub={endpoint,options:{applicationServerKey:new Uint8Array([4,...Array(64).fill(1)])},toJSON:()=>({endpoint,keys:{p256dh:'PRIVATE_BROWSER_KEY',auth:'PRIVATE_BROWSER_AUTH'}}),async unsubscribe(){f.calls.unsubscribe++;if(!config.allowActivation)throw new Error('SYNTHETIC_NATIVE_MUTATION_BLOCKED');f.subscription=false;return true}};
   const reg={scope:location.origin+'/manager/',active:{state:'activated',postMessage(){}},pushManager:{
    async getSubscription(){f.calls.read++;if(config.native==='error')throw new Error('PRIVATE_NATIVE_READ_ERROR');if(config.native==='pending')await new Promise(resolve=>f.pendingNative.push(resolve));return f.subscription?{...sub,endpoint:f.endpoint}:null},
    async subscribe(){f.calls.subscribe++;if(!config.allowActivation)throw new Error('SYNTHETIC_NATIVE_MUTATION_BLOCKED');f.subscription=true;return sub},
   },async showNotification(){f.calls.confirmation++;if(!config.allowActivation)throw new Error('SYNTHETIC_OS_MUTATION_BLOCKED')}};
   Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{
    async getRegistration(url){f.calls.registration.push(url);if(config.registration==='error')throw new Error('PRIVATE_REGISTRATION_ERROR');return config.registration==='missing'?undefined:config.registration==='ancestor'?{...reg,scope:location.origin+'/'}:reg},
    async register(){f.calls.register++;return reg},ready:Promise.resolve(reg),addEventListener(){},
   }});
   function N(){}N.permission=config.permission;N.requestPermission=async()=>{f.calls.permission++;if(!config.allowActivation)throw new Error('SYNTHETIC_PERMISSION_MUTATION_BLOCKED');N.permission='granted';return 'granted'};
   Object.defineProperty(window,'Notification',{configurable:true,value:N});
   Object.defineProperty(window,'PushManager',{configurable:true,value:config.supported?function(){}:undefined});
   if(!config.supported)delete window.PushManager;
   f.releaseNative=()=>{config.native='ready';f.pendingNative.splice(0).forEach(resolve=>resolve())};
  },{config,endpoint});
  await page.route('**/manager/push-status.php',async route=>{
   const request=route.request(),url=new URL(request.url());
   if(url.origin!==origin){unexpected.push('external push status');return route.abort()}
   const reply=(body,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
   if(request.method()==='GET'){reads.push('aggregate');return reply({ok:true,push_status:aggregate()})}
   if(request.method()!=='POST'){unexpected.push('unexpected status method');return route.abort()}
   const body=request.postDataJSON();expect(body.csrf).toBe('fixture-csrf');if(body.endpoint)expect(body.endpoint).toBe(endpoint);if(body.installation_token)expect(body.installation_token).toMatch(/^[a-f0-9]{64}$/);proofs.push(body);
   if(options.holdProof)await new Promise(resolve=>pendingProofs.push(resolve));
   if(options.proofHttp)return reply({ok:false,error:'synthetic_proof_failure'},options.proofHttp);
   return reply({ok:true,push_status:aggregate(),...(options.omitProof?{}:{current_subscription:current(),current_connection:protocol.current()})});
  });
  await page.route('**/manager/push.php*',async route=>{
   const request=route.request(),url=new URL(request.url());
   if(url.origin!==origin){unexpected.push('external push setup');return route.abort()}
   if(request.method()==='GET'&&url.search==='?action=key')return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,public_key:Buffer.from([4,...Array(64).fill(1)]).toString('base64url')})});
   const body=request.method()==='POST'?request.postDataJSON():null;
   const reply=(data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
   if(body?.action==='connections'){
    connectionCalls.push(body);const rows=body.expected_manager_id===8?[{id:81,revision:1,enabled:true,current:false,browser:'Edge · Windows',status:'saved',last_activity_at:null}]:JSON.parse(JSON.stringify(connectionRows));
    if(connectionMode==='hold-list')await new Promise(resolve=>heldConnectionReads.push(resolve));
    if(['list500','list401','list403'].includes(connectionMode))return reply({ok:false,error:'synthetic_list_error'},Number(connectionMode.slice(4)));
    return reply({ok:true,connections:rows,truncated:false});
   }
   if(body?.action==='operation'){
    connectionCalls.push(body);const operation=connectionReceipts.get(body.operation_id);return reply(operation?{...operation.result,found:true,operation,current_connection:connectionRows.find(row=>row.id===operation.result.connection_id)||null}:{ok:true,found:false});
   }
   if(body?.action==='revoke'&&options.connections){
    connectionCalls.push(body);if(connectionMode==='revoke403')return reply({ok:false,error:'csrf'},403);
    if(connectionMode==='revoke500')return reply({ok:false,error:'synthetic_server_error'},500);
    const row=connectionRows.find(row=>row.id===body.connection_id);expect(row).toBeTruthy();expect(body.expected_revision).toBe(row.revision);
    row.enabled=false;row.status='revoked';row.revision++;if(row.id===1){proofReason='subscription_revoked';protocol.reply(body);}
    const result={ok:true,connection_id:row.id,revision:row.revision,enabled:false},operation={id:body.operation_id,action:'revoke',result};connectionReceipts.set(body.operation_id,operation);
    if(connectionMode==='lost')return route.abort('failed');return reply({...result,found:true,operation,current_connection:row});
   }
   if(config.allowActivation&&body?.action==='enable_intent'){
    const result=protocol.reply(body);if(options.connections)Object.assign(connectionRows[0],{enabled:true,revision:result.current_connection.revision,status:'not_connected'});return reply(result);
   }
   if(!config.allowActivation||body?.action!=='subscribe'||body.csrf!=='fixture-csrf'||body.subscription?.endpoint!==endpoint){unexpected.push('blocked synthetic push save');return route.abort()}
   saves.push(body);proofReason='healthy_subscription';if(options.connections)Object.assign(connectionRows[0],{enabled:true,revision:protocol.current().revision,status:'saved'});return route.fulfill({contentType:'application/json',body:JSON.stringify({...protocol.reply(body),push_status:{...aggregate(),notification_path_usable:true,notification_path_reason:'healthy_subscription',healthy_subscription_count:1},current_subscription:current()})});
  });
 }});
 return {...state,reads,proofs,saves,connectionCalls,connectionRows,setConnectionMode:mode=>connectionMode=mode,releaseConnectionReads:()=>heldConnectionReads.splice(0).forEach(resolve=>resolve()),remoteRevokeCurrent(){const row=connectionRows[0];row.enabled=false;row.status='revoked';row.revision++;proofReason='subscription_revoked';protocol.reply({action:'revoke',operation_id:'e'.repeat(64)});},pushUnexpected:unexpected,native:()=>page.evaluate(()=>({...pushBrowserFixture.calls})),releaseNative:()=>page.evaluate(()=>pushBrowserFixture.releaseNative()),releaseProof:()=>pendingProofs.splice(0).forEach(resolve=>resolve())};
}
async function openStatus(page){await page.locator('#m2NotificationButton').click();await expect(page.locator('#m2Notifications')).toBeVisible();return page.locator('#notificationStatus')}
async function unchangedNative(page,state){const calls=await state.native();expect(calls.permission).toBe(0);expect(calls.subscribe).toBe(0);expect(calls.unsubscribe).toBe(0);expect(calls.confirmation).toBe(0);expect(state.saves).toEqual([]);expect(state.unexpected).toEqual([]);expect(state.pushUnexpected).toEqual([]);expect(state.errors).toEqual([])}
async function fits(page){const box=await page.locator('#m2Notifications').boundingBox(),size=page.viewportSize();expect(box.x).toBeGreaterThanOrEqual(0);expect(box.x+box.width).toBeLessThanOrEqual(size.width+1);expect(box.y).toBeGreaterThanOrEqual(0);expect(box.y+box.height).toBeLessThanOrEqual(size.height+1);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true)}
async function notGreen(root){await expect(root).not.toHaveClass(/\bok\b/);await expect(root.locator('.notificationText strong')).not.toContainText(/Уведомления включены/i)}
async function capture(page,browserName,state){fs.mkdirSync('notification-diagnostic-artifacts',{recursive:true});await page.screenshot({animations:'disabled',path:`notification-diagnostic-artifacts/browser-state-${browserName}-${state}.png`})}

for(const viewport of viewports)for(const permission of ['default','denied','granted'])test(`Browser push account health cannot enable another browser: ${permission} ${viewport.width}x${viewport.height}`,async({page,browserName})=>{
 await page.setViewportSize(viewport);const state=await setup(page,{permission});const root=await openStatus(page);
 await notGreen(root);await expect(root).toContainText('Этот браузер');await expect(root).toContainText(/В аккаунте:\s*1/);
 const enable=root.locator('[data-enable-push]');await expect(enable).toBeVisible();await expect(enable).toBeEnabled();
 if(permission==='default')await expect(enable).toHaveText('Включить на этом устройстве');
 if(permission==='denied')await expect(root).toContainText(/настройк.*браузер/i);
 if(permission==='granted'){await expect.poll(async()=>(await state.native()).registration.length).toBeGreaterThan(0);expect((await state.native()).registration.every(url=>url===origin+'/manager/')).toBe(true)}
 expect(state.proofs).toEqual([]);await unchangedNative(page,state);await fits(page);
 if(permission==='default'&&viewport.width===390&&viewport.height===900)await capture(page,browserName,'default-390');
 if(permission==='denied'&&viewport.width===390&&viewport.height===420)await capture(page,browserName,'denied-390x420');
 await page.locator('#m2NotificationsClose').click();await expect(page.locator('#m2NotificationButton')).toBeFocused();
});

test('Browser push requires native presence and exact server proof for a green current browser',async({page,browserName})=>{
 await page.setViewportSize({width:1440,height:900});const state=await setup(page,{subscription:true});const root=await openStatus(page);
 await expect(root.locator('.notificationText strong')).toContainText(/Этот браузер.*Уведомления включены/i);await expect(root).toHaveClass(/\bok\b/);
 await expect(root).toContainText(/В аккаунте:\s*1/);await expect(root.locator('[data-enable-push]')).toHaveCount(0);
 await expect.poll(()=>state.proofs.length).toBeGreaterThan(0);expect((await state.native()).registration.every(url=>url===origin+'/manager/')).toBe(true);
 await expect(root).not.toContainText('PRIVATE_');await unchangedNative(page,state);await fits(page);await capture(page,browserName,'healthy-1440');
});

for(const proof of ['no_subscription','subscription_unhealthy'])test(`Browser push native presence cannot hide ${proof} server proof`,async({page})=>{
 const state=await setup(page,{subscription:true,proof});const root=await openStatus(page);
 await page.evaluate(()=>WorkspaceV2Notifications.refresh());await expect.poll(()=>state.proofs.length).toBeGreaterThan(0);await notGreen(root);await expect(root.locator('[data-enable-push]')).toBeVisible();await unchangedNative(page,state);
});

for(const options of [{supported:false},{native:'error'},{registration:'error'},{registration:'missing'},{registration:'ancestor',subscription:true},{subscription:true,omitProof:true},{subscription:true,proofHttp:500},{subscription:true,proof:'health_check_failed',accountHealthy:0}])test(`Browser push unknown state cannot inherit account green: ${JSON.stringify(options)}`,async({page})=>{
 const state=await setup(page,options);const root=await openStatus(page);await page.evaluate(()=>WorkspaceV2Notifications.refresh());await notGreen(root);await expect(root.locator('[data-enable-push]')).toBeVisible();
 if(options.registration==='ancestor'){expect((await state.native()).read).toBe(0);expect(state.proofs).toEqual([])}
 await expect(root).not.toContainText('PRIVATE_');await unchangedNative(page,state);
});

test('Browser push a timed out native read stays unknown and cannot start account-zero recovery',async({page})=>{
 await page.clock.install();const state=await setup(page,{native:'pending',accountHealthy:0,subscription:true});
 await expect.poll(()=>page.evaluate(()=>pushBrowserFixture.pendingNative.length)).toBeGreaterThan(0);await page.clock.runFor(5001);
 const root=await openStatus(page);await expect(root).toContainText('Не удалось проверить');await notGreen(root);await unchangedNative(page,state);
 await state.releaseNative();await page.clock.runFor(1);await notGreen(root);await unchangedNative(page,state);expect(state.proofs).toEqual([]);
});

test('Browser push server proof for E1 cannot confirm a changed native endpoint E2',async({page})=>{
 const state=await setup(page,{subscription:true,holdProof:true});await expect.poll(()=>state.proofs.length).toBeGreaterThan(0);
 await page.evaluate(()=>{pushBrowserFixture.endpoint='https://push.example.test/PRIVATE_REPLACEMENT_E2'});state.releaseProof();
 await expect.poll(async()=>(await state.native()).read).toBeGreaterThanOrEqual(2);
 await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));const root=await openStatus(page);
 await notGreen(root);await expect(root).toContainText('Не удалось проверить');await expect(root.locator('[data-enable-push]')).toBeVisible();await unchangedNative(page,state);await expect(root).not.toContainText('PRIVATE_');
});

for(const viewport of [{width:390,height:900},{width:1440,height:900}])test(`Browser push held native read cannot stall Inbox or reply ${viewport.width}`,async({page})=>{
 await page.setViewportSize(viewport);const state=await setup(page,{subscription:true,native:'pending'});
 await expect.poll(()=>page.evaluate(()=>pushBrowserFixture.pendingNative.length)).toBeGreaterThan(0);expect(state.reads.length).toBeGreaterThan(0);
 await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#replyText')).toBeEnabled();
 const draft='Черновик, пока проверяется этот браузер';await page.locator('#replyText').fill(draft);await page.locator('#replyFile').setInputFiles({name:'browser-probe.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic unsent file')});await page.locator('#replyText').focus();
 const lists=state.calls.filter(call=>call.action==='list').length;await expect(page.locator('#notificationStatus')).not.toHaveClass(/\bok\b/);await unchangedNative(page,state);
 await state.releaseNative();await expect(page.locator('#notificationStatus')).toHaveClass(/\bok\b/);
 await expect(page.locator('#replyText')).toHaveValue(draft);await expect(page.locator('#replyText')).toBeFocused();await expect(page.locator('#mediaSelection')).toContainText('browser-probe.txt');
 expect(await page.evaluate(()=>WorkspaceV2.S.current)).toBe(11);expect(state.calls.filter(call=>call.action==='list')).toHaveLength(lists);await unchangedNative(page,state);
});

test('Browser push a successful explicit activation confirms this endpoint even when another browser is healthy',async({page})=>{
 const state=await setup(page,{permission:'default',allowActivation:true});const root=await openStatus(page);await notGreen(root);
 await root.locator('[data-enable-push]').click();await expect(root.locator('.notificationText strong')).toContainText(/Этот браузер.*Уведомления включены/i);await expect(root).toHaveClass(/\bok\b/);
 const calls=await state.native();expect(calls.permission).toBe(1);expect(calls.subscribe).toBe(1);expect(calls.unsubscribe).toBe(0);expect(state.saves).toHaveLength(1);await expect(root.locator('[data-enable-push]')).toHaveCount(0);
 expect(state.unexpected).toEqual([]);expect(state.pushUnexpected).toEqual([]);expect(state.errors).toEqual([]);await expect(root).not.toContainText('PRIVATE_');
});

function silentConnectionWav(){const samples=240000,b=Buffer.alloc(44+samples*2);b.write('RIFF');b.writeUInt32LE(b.length-8,4);b.write('WAVEfmt ',8);b.writeUInt32LE(16,16);b.writeUInt16LE(1,20);b.writeUInt16LE(1,22);b.writeUInt32LE(8000,24);b.writeUInt32LE(16000,28);b.writeUInt16LE(2,32);b.writeUInt16LE(16,34);b.write('data',36);b.writeUInt32LE(samples*2,40);return b}
const connectionRow=(page,id)=>page.locator(`#notificationConnections [data-connection-id="${id}"]`);
const connectionWrites=state=>state.connectionCalls.filter(call=>call.action==='revoke');
function cleanConnections(state){expect(state.unexpected).toEqual([]);expect(state.pushUnexpected).toEqual([]);expect(state.errors).toEqual([])}

for(const viewport of viewports)test(`Browser connections lazy list and remote revoke preserve working conversation ${viewport.width}x${viewport.height}`,async({page,browserName})=>{
 await page.setViewportSize(viewport);const wav=silentConnectionWav(),messages=[{id:1,sender_type:'customer',text:'Синтетическое аудио',created_at:'2026-10-16 12:00:00',attachments:[{type:'audio',name:'synthetic.wav',url:'data:audio/wav;base64,'+wav.toString('base64')}]}];
 const state=await setup(page,{connections:true,subscription:true,messages});expect(state.connectionCalls).toEqual([]);
 await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#replyText')).toBeEnabled();
 await page.locator('#replyText').fill('Черновик при отключении старого браузера');await page.locator('#replyFile').setInputFiles({name:'unsent-browser.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic unsent attachment')});
 const audio=page.locator('#messages audio');await expect(audio).toHaveCount(1);await audio.evaluate(async el=>{el.muted=true;await el.play();window.connectionAudio=el});await expect.poll(()=>audio.evaluate(el=>el.currentTime)).toBeGreaterThan(.1);
 const mobileBack=await page.locator('#mobileBack').isVisible();if(mobileBack)await page.locator('#mobileBack').click();
 await page.locator('#replyText').evaluate(el=>{if(el.getClientRects().length)el.focus();el.setSelectionRange(2,8)});
 const reads=state.calls.filter(call=>['list','detail'].includes(call.action)).length,shift=await page.locator('#managerShiftBtn').getAttribute('aria-pressed');
 await openStatus(page);await expect(connectionRow(page,2)).toContainText('Firefox · macOS');await expect(connectionRow(page,1)).toContainText('Этот браузер');await expect(connectionRow(page,3)).toContainText('Последняя активность неизвестна');
 await expect(page.locator('#notificationConnections')).not.toContainText('PRIVATE_');await fits(page);
 await connectionRow(page,2).scrollIntoViewIfNeeded();await capture(page,browserName,`connections-${viewport.width}x${viewport.height}-list`);
 const revoke=connectionRow(page,2).getByRole('button',{name:'Отключить уведомления'});await revoke.focus();await revoke.press('Enter');
 await expect(connectionRow(page,2)).toContainText('Уведомления отключены');await expect(connectionRow(page,2).locator('button')).toHaveCount(0);
 await expect(connectionRow(page,1).getByRole('button',{name:'Отключить уведомления'})).toBeEnabled();await expect(connectionRow(page,3).getByRole('button',{name:'Отключить уведомления'})).toBeEnabled();
 expect(connectionWrites(state)).toHaveLength(1);expect(await page.locator('#managerShiftBtn').getAttribute('aria-pressed')).toBe(shift);expect(state.calls.filter(call=>['list','detail'].includes(call.action))).toHaveLength(reads);
 const native=await state.native();expect([native.permission,native.subscribe,native.unsubscribe,native.confirmation]).toEqual([0,0,0,0]);
 await capture(page,browserName,`connections-${viewport.width}x${viewport.height}-revoked`);await page.keyboard.press('Escape');await expect(page.locator('#m2NotificationButton')).toBeFocused();
 await expect(page.locator('#replyText')).toHaveValue('Черновик при отключении старого браузера');await expect(page.locator('#mediaSelection')).toContainText('unsent-browser.txt');expect(await page.locator('#replyText').evaluate(el=>[el.selectionStart,el.selectionEnd])).toEqual([2,8]);
 if(mobileBack){await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#replyText')).toHaveValue('Черновик при отключении старого браузера');await expect(page.locator('#mediaSelection')).toContainText('unsent-browser.txt');}
 expect(await audio.evaluate(el=>el===window.connectionAudio&&!el.paused&&el.currentTime>.1)).toBe(true);await audio.evaluate(el=>el.pause());cleanConnections(state);
});

for(const viewport of [viewports[0],viewports[3]])test(`Browser connections lost response survives reload and reconciles without repeat ${viewport.width}`,async({page,browserName})=>{
 await page.setViewportSize(viewport);const state=await setup(page,{connections:true,subscription:true});await openStatus(page);await expect(connectionRow(page,2)).toBeVisible();state.setConnectionMode('lost');
 await connectionRow(page,2).getByRole('button',{name:'Отключить уведомления'}).click();await expect(connectionRow(page,2)).toContainText('Ответ не получен');await expect(connectionRow(page,2).getByRole('button',{name:'Проверить результат'})).toBeEnabled();
 expect(connectionWrites(state)).toHaveLength(1);await capture(page,browserName,`connections-${viewport.width}-unknown`);
 await page.reload();await expect(page.locator('#inboxList .leadItem')).toHaveCount(2);await openStatus(page);await expect(connectionRow(page,2).getByRole('button',{name:'Проверить результат'})).toBeVisible();
 await connectionRow(page,2).getByRole('button',{name:'Проверить результат'}).click();await expect(connectionRow(page,2)).toContainText('Уведомления отключены');await expect(connectionRow(page,2).locator('button')).toHaveCount(0);
 expect(connectionWrites(state)).toHaveLength(1);expect(state.connectionCalls.filter(call=>call.action==='operation')).toHaveLength(1);const native=await state.native();expect([native.subscribe,native.unsubscribe,native.permission,native.confirmation]).toEqual([0,0,0,0]);cleanConnections(state);
});

test('Browser connections HTTP 403 is visible and 500 stays UNKNOWN without a blind retry',async({page})=>{
 const state=await setup(page,{connections:true,subscription:true});await openStatus(page);await expect(connectionRow(page,2)).toBeVisible();state.setConnectionMode('revoke403');
 await connectionRow(page,2).getByRole('button',{name:'Отключить уведомления'}).click();await expect(connectionRow(page,2)).toContainText('Не удалось подтвердить');expect(state.connectionRows[1].enabled).toBe(true);
 state.setConnectionMode('revoke500');await connectionRow(page,2).getByRole('button',{name:'Отключить уведомления'}).click();await expect(connectionRow(page,2)).toContainText('Ответ не получен');
 await connectionRow(page,2).getByRole('button',{name:'Проверить результат'}).click();await expect(connectionRow(page,2)).toContainText('Ответ не получен');expect(connectionWrites(state)).toHaveLength(2);expect(state.connectionRows[1].enabled).toBe(true);expect((await state.native()).unsubscribe).toBe(0);cleanConnections(state);
});

test('Browser connections a pre-revoke list response cannot restore the enabled row',async({page})=>{
 const state=await setup(page,{connections:true,subscription:true});await openStatus(page);await expect(connectionRow(page,2)).toBeVisible();state.setConnectionMode('hold-list');
 await page.locator('[data-connections-refresh]').click();await expect.poll(()=>state.connectionCalls.filter(call=>call.action==='connections').length).toBe(2);
 await connectionRow(page,2).getByRole('button',{name:'Отключить уведомления'}).click();await expect.poll(()=>state.connectionCalls.filter(call=>call.action==='connections').length).toBe(3);
 state.setConnectionMode('success');state.releaseConnectionReads();await expect(connectionRow(page,2)).toContainText('Уведомления отключены');await expect(connectionRow(page,2).locator('button')).toHaveCount(0);expect(connectionWrites(state)).toHaveLength(1);cleanConnections(state);
});

test('Browser connections lazy loading and stale account responses cannot hold or repaint the Inbox',async({page})=>{
 const state=await setup(page,{connections:true,subscription:true});state.setConnectionMode('hold-list');await openStatus(page);await expect.poll(()=>state.connectionCalls.length).toBe(1);
 await page.keyboard.press('Escape');await page.locator('#inboxList [data-conversation-id="11"]').click();await expect(page.locator('#replyText')).toBeEnabled();await page.locator('#replyText').fill('Пишу, пока список браузеров загружается');
 state.switchManager({id:8,role:'manager',display_name:'Другой сотрудник',is_working:true});state.setConnectionMode('success');
 await page.evaluate(async()=>{const S=WorkspaceV2.S;S.manager={id:8,role:'manager',is_working:true};S.authGeneration++;await WorkspaceV2Notifications.refresh();WorkspaceV2Notifications.openConnections(document.getElementById('m2NotificationSlot'))});
 state.releaseConnectionReads();await openStatus(page);await expect(connectionRow(page,81)).toContainText('Edge · Windows');await expect(connectionRow(page,2)).toHaveCount(0);expect(connectionWrites(state)).toHaveLength(0);cleanConnections(state);
});

test('Browser connections current remote off prevents recovery until a new explicit originating-browser intent',async({page})=>{
 const state=await setup(page,{connections:true,subscription:true,allowActivation:true});await openStatus(page);await expect(connectionRow(page,1)).toBeVisible();state.remoteRevokeCurrent();await page.evaluate(()=>WorkspaceV2Notifications.refresh());
 await expect(page.locator('#notificationStatus')).toContainText('Уведомления отключены');await page.evaluate(()=>WorkspaceV2Notifications.refresh());expect(state.saves).toHaveLength(0);expect((await state.native()).subscribe).toBe(0);
 await page.locator('#notificationStatus [data-enable-push]').click();await expect(page.locator('#notificationStatus')).toHaveClass(/\bok\b/);await expect(connectionRow(page,1)).toContainText('Подписка сохранена');expect(state.saves).toHaveLength(1);expect(state.connectionRows[0].revision).toBe(3);expect((await state.native()).unsubscribe).toBe(0);cleanConnections(state);
});

for(const status of [401,403,500])test(`Browser connections list ${status} remains truthful and read-only`,async({page})=>{
 const state=await setup(page,{connections:true,subscription:true});state.setConnectionMode('list'+status);await openStatus(page);await expect(page.locator('#notificationConnections')).toContainText(status===401?'Войдите в кабинет':'Не удалось обновить');
 expect(connectionWrites(state)).toHaveLength(0);expect(state.saves).toHaveLength(0);expect((await state.native()).unsubscribe).toBe(0);cleanConnections(state);
});
