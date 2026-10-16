const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const {setup:shell,origin}=require('./fixtures/personal-workspace');
const endpoint='https://push.example.test/PRIVATE_BROWSER_B_ENDPOINT';
const viewports=[{width:390,height:900},{width:430,height:900},{width:768,height:900},{width:1440,height:900},{width:390,height:420}];

// The actual PHP shell and source assets run unchanged. Browser capabilities,
// native subscription work and every HTTP response below are synthetic/local.
async function setup(page,options={}){
 const config={permission:'granted',subscription:false,native:'ready',registration:'ready',supported:true,allowActivation:false,...options};
 const reads=[],proofs=[],saves=[],unexpected=[],pendingProofs=[];let proofReason=options.proof||'healthy_subscription';
 const aggregate=()=>({is_working:true,notification_path_usable:options.accountHealthy!==0,healthy_subscription_count:options.accountHealthy===0?0:1,notification_path_reason:options.accountHealthy===0?'no_subscription':'healthy_subscription'});
 const current=()=>({notification_path_usable:proofReason==='healthy_subscription',notification_path_reason:proofReason});
 const state=await shell(page,{skipPushStubs:true,beforeNavigate:async page=>{
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
   const body=request.postDataJSON();expect(Object.keys(body).sort()).toEqual(['csrf','endpoint']);expect(body).toEqual({csrf:'fixture-csrf',endpoint});proofs.push(body);
   if(options.holdProof)await new Promise(resolve=>pendingProofs.push(resolve));
   if(options.proofHttp)return reply({ok:false,error:'synthetic_proof_failure'},options.proofHttp);
   return reply({ok:true,push_status:aggregate(),...(options.omitProof?{}:{current_subscription:current()})});
  });
  await page.route('**/manager/push.php*',async route=>{
   const request=route.request(),url=new URL(request.url());
   if(url.origin!==origin){unexpected.push('external push setup');return route.abort()}
   if(request.method()==='GET'&&url.search==='?action=key')return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,public_key:Buffer.from([4,...Array(64).fill(1)]).toString('base64url')})});
   const body=request.method()==='POST'?request.postDataJSON():null;
   if(!config.allowActivation||body?.action!=='subscribe'||body.csrf!=='fixture-csrf'||body.subscription?.endpoint!==endpoint){unexpected.push('blocked synthetic push save');return route.abort()}
   saves.push(body);proofReason='healthy_subscription';return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,push_status:{...aggregate(),notification_path_usable:true,notification_path_reason:'healthy_subscription',healthy_subscription_count:1},current_subscription:current()})});
  });
 }});
 return {...state,reads,proofs,saves,pushUnexpected:unexpected,native:()=>page.evaluate(()=>({...pushBrowserFixture.calls})),releaseNative:()=>page.evaluate(()=>pushBrowserFixture.releaseNative()),releaseProof:()=>pendingProofs.splice(0).forEach(resolve=>resolve())};
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
