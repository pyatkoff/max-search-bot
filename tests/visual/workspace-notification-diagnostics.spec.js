const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const origin='http://127.0.0.1:4173';

// Exercise the real notification module with in-memory browser/HTTP failures.
// No real permission prompt, push subscription, save or notification is created.
async function setup(page,working,{recoverAfterAbort=false,backgroundPending=false,permissionPending=false,keyBodyPending=false,confirmationPending=false,nativeDeadline=false}={}){
  const unexpected=[];
  await page.route('**/*',route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.origin!==origin||request.method()!=='GET'||url.pathname.endsWith('.php')){
      unexpected.push(request.url());return route.abort();
    }
    return route.continue();
  });
  await page.goto(origin+'/tests/visual/workspace-v2-fixture.html');
  await page.evaluate(({working,recoverAfterAbort,backgroundPending,permissionPending,keyBodyPending,confirmationPending,nativeDeadline})=>{
    const base=document.createElement('base');base.href='/manager/';document.head.prepend(base);
    document.querySelector('.notificationHealth').id='notificationStatus';
    window.fixturePushCalls=[];
    window.fixtureSetupDiagnostics=[];
    window.fixturePushStatus={is_working:working,notification_path_usable:false,notification_path_reason:'no_subscription'};
    window.WorkspaceV2={S:{csrf:'synthetic',manager:{id:7},authGeneration:1}};
    const notification={permission:backgroundPending?'granted':'default',requestPermission(){fixturePushCalls.push('permission');if(permissionPending)return new Promise(resolve=>{window.fixtureFinishPermission=result=>{notification.permission=result;resolve(result);};});notification.permission='granted';return Promise.resolve('granted');}};
    if(permissionPending||keyBodyPending||nativeDeadline){
      const schedule=window.setTimeout.bind(window),cancel=window.clearTimeout.bind(window);
      window.setTimeout=(callback,delay,...args)=>{const id=schedule(callback,delay,...args);const expire=()=>{cancel(id);callback(...args);};if(nativeDeadline&&delay===15000)window.fixtureExpireSubscription=expire;if(permissionPending&&delay===15000)window.fixtureExpirePermission=expire;if(keyBodyPending&&delay===10000)window.fixtureExpireKey=expire;return id;};
    }
    Object.defineProperty(window,'Notification',{configurable:true,value:notification});
    Object.defineProperty(window,'PushManager',{configurable:true,value:function(){}});
    let registered=false,revision=1;
    if(backgroundPending)localStorage.setItem('anytour-manager-push-installation:7',JSON.stringify({token:'a'.repeat(64),revision}));
    const current=()=>({id:1,revision,enabled:true});
    const receipt=body=>({ok:true,found:true,operation:{id:body.operation_id,action:body.action,result:{ok:true,connection_id:1,revision,enabled:true}},current_connection:current()});
    const subscription={options:{applicationServerKey:new Uint8Array([1])},toJSON(){return {endpoint:'https://push.example.test/PRIVATE_ENDPOINT_OR_KEY_NOT_FOR_DISPLAY',keys:{auth:'synthetic',p256dh:'synthetic'}};}};
    const registration={scope:location.origin+'/manager/',active:{state:'activated'},pushManager:{
      async getSubscription(){fixturePushCalls.push('read');return registered?subscription:null;},
      async subscribe(){fixturePushCalls.push('subscribe');if(backgroundPending&&!window.fixtureFinishPush)return new Promise((resolve,reject)=>{window.fixtureFinishPush=outcome=>{if(outcome==='success'){registered=true;resolve(subscription);}else reject(new DOMException('Registration failed - could not connect to push server','AbortError'));};});registered=recoverAfterAbort;throw new DOMException(recoverAfterAbort?'PRIVATE_ENDPOINT_OR_KEY_NOT_FOR_DISPLAY':'Registration failed - could not connect to push server','AbortError');},
    },async showNotification(){fixturePushCalls.push('confirmation');if(!recoverAfterAbort&&!registered)throw new Error('Unexpected confirmation');if(confirmationPending)return new Promise((resolve,reject)=>{window.fixtureRejectConfirmation=()=>reject(new Error('PRIVATE_ENDPOINT_OR_KEY_NOT_FOR_DISPLAY'));});}};
    Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{
      ready:Promise.resolve(registration),async getRegistration(){fixturePushCalls.push('lookup');return registration;},async register(){fixturePushCalls.push('register');return registration;},addEventListener(){},
    }});
    window.fetch=async(url,options)=>{
      const body=options?.method==='POST'?JSON.parse(options.body):null;
      if(url==='push.php?action=setup_diagnostic'){fixturePushCalls.push('push.php#setup_diagnostic');fixtureSetupDiagnostics.push(body.diagnostic);return{ok:true,status:200,json:async()=>({ok:true,accepted:true})};}
      fixturePushCalls.push(body?.action==='enable_intent'?'push.php#enable_intent':String(url));
      if(body?.action==='enable_intent'){revision++;return{ok:true,status:200,json:async()=>receipt(body)};}
      if(url==='push-status.php')return {ok:true,status:200,json:async()=>({ok:true,push_status:fixturePushStatus,...(options?.method==='POST'?{current_subscription:{notification_path_usable:registered,notification_path_reason:registered?'healthy_subscription':'no_subscription'},current_connection:current()}:{})})};
      if(url==='push.php?action=key'){window.fixtureKeySignal=options?.signal;return {ok:true,status:200,json:async()=>{if(keyBodyPending&&!window.fixtureFinishKey)return new Promise(resolve=>{window.fixtureFinishKey=()=>resolve({ok:true,public_key:'AQ'});});return {ok:true,public_key:'AQ'};}};}
      if(url==='push.php'&&(recoverAfterAbort||registered)){fixturePushStatus={is_working:working,notification_path_usable:true,notification_path_reason:'healthy_subscription',healthy_subscription_count:1};return {ok:true,status:200,json:async()=>({...receipt(body),push_status:fixturePushStatus,current_subscription:{notification_path_usable:true,notification_path_reason:'healthy_subscription'}})};}
      throw new Error('Unexpected request');
    };
  },{working,recoverAfterAbort,backgroundPending,permissionPending,keyBodyPending,confirmationPending,nativeDeadline});
  await page.addScriptTag({url:origin+'/manager/assets/workspace-v2-notifications.js'});
  await expect(page.locator('#notificationStatus [data-enable-push]')).toBeVisible();
  return unexpected;
}

for(const width of [390,430,768,1440])for(const working of [false,true])test.describe(`push diagnostic ${width} ${working?'working':'off-shift'}`,()=>{
  test.use({viewport:{width,height:844}});
  test('the failed step is readable without Console and stays visible after status refresh',async({page,browserName})=>{
    const unexpected=await setup(page,working);
    await page.locator('#notificationStatus [data-enable-push]').click();
    const diagnostic=page.locator('#notificationStatus .notificationDiagnostic');
    await expect(diagnostic).toBeVisible();
    await expect(diagnostic).toContainText(/подключение устройства/i);
    await expect(diagnostic).toContainText('AbortError');
    await expect(diagnostic).toContainText('PUSH_NETWORK');
    await expect(page.locator('#notificationStatus')).not.toContainText('Registration failed');
    await expect(page.locator('#notificationStatus')).not.toContainText('PRIVATE_ENDPOINT');
    await expect(page.locator('#notificationStatus [data-enable-push]')).toBeEnabled();
    await page.evaluate(working=>{fixturePushStatus={is_working:working,notification_path_usable:true,notification_path_reason:'healthy_subscription',healthy_subscription_count:1};return WorkspaceV2Notifications.refresh();},working);
    await expect(diagnostic).toBeVisible();
    await expect(page.locator('#notificationStatus')).not.toContainText('Уведомления включены');
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='subscribe').length)).toBe(2);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php').length)).toBe(0);
    const reports=await page.evaluate(()=>fixtureSetupDiagnostics);
    expect(reports).toHaveLength(1);
    expect(reports[0].trace.map(row=>[row.call,row.outcome])).toEqual([['subscribe','AbortError/PUSH_NETWORK'],['recovery_read','none'],['retry_subscribe','AbortError/PUSH_NETWORK']]);
    expect(JSON.stringify(reports)).not.toMatch(/PRIVATE_ENDPOINT|Registration failed|https?:/);
    const box=await diagnostic.boundingBox();expect(box.x).toBeGreaterThanOrEqual(0);expect(box.x+box.width).toBeLessThanOrEqual(width+1);
    const size=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,width:innerWidth}));expect(size.scroll).toBeLessThanOrEqual(size.width+1);
    fs.mkdirSync('notification-diagnostic-artifacts',{recursive:true});
    await page.screenshot({path:`notification-diagnostic-artifacts/${browserName}-${width}-${working?'working':'off-shift'}.png`});
    expect(unexpected).toEqual([]);
  });
});

for(const width of [390,1440])test.describe(`push Abort recovery ${width}`,()=>{
  test.use({viewport:{width,height:844}});
  test('reuses a real subscription found after AbortError and clears the failure only after save',async({page})=>{
    const unexpected=await setup(page,true,{recoverAfterAbort:true});
    await page.locator('#notificationStatus [data-enable-push]').click();
    await expect(page.locator('#notificationStatus')).toContainText('Уведомления включены');
    await expect(page.locator('#notificationStatus .notificationDiagnostic')).toHaveCount(0);
    await expect(page.locator('#notificationStatus')).not.toContainText('PRIVATE_ENDPOINT');
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='subscribe').length)).toBe(1);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php').length)).toBe(1);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='confirmation').length)).toBe(1);
    expect(unexpected).toEqual([]);
  });
});

for(const width of [390,430,768,1440])for(const outcome of ['success','failure'])test.describe(`push click during recovery ${width} ${outcome}`,()=>{
  test.use({viewport:{width,height:844}});
  test('Enable immediately observes pending recovery and uses its existing result',async({page,browserName})=>{
    const unexpected=await setup(page,true,{backgroundPending:true});
    await expect.poll(()=>page.evaluate(()=>fixturePushCalls.filter(call=>call==='subscribe').length)).toBe(1);
    const button=page.locator('#notificationStatus [data-enable-push]');
    await expect(button).toBeEnabled();await button.click();
    await expect(button).toBeDisabled();await expect(button).toHaveText('Подключаем…');
    await page.evaluate(()=>{window.fixtureJoinedPush=WorkspaceV2Notifications.enable(document.getElementById('notificationStatus'));});
    await page.evaluate(()=>WorkspaceV2Notifications.refresh());await expect(button).toBeDisabled();await expect(button).toHaveText('Подключаем…');
    await page.evaluate(()=>{fixturePushStatus={is_working:true,notification_path_usable:true,notification_path_reason:'healthy_subscription',healthy_subscription_count:1};return WorkspaceV2Notifications.refresh();});
    await expect(button).toBeDisabled();await expect(button).toHaveText('Подключаем…');await expect(page.locator('#notificationStatus')).not.toContainText('Уведомления включены');
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='register').length)).toBe(1);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php?action=key').length)).toBe(1);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='subscribe').length)).toBe(1);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='permission').length)).toBe(0);
    fs.mkdirSync('notification-diagnostic-artifacts',{recursive:true});
    await page.screenshot({path:`notification-diagnostic-artifacts/${browserName}-${width}-recovery-${outcome}-busy.png`});
    await page.evaluate(outcome=>fixtureFinishPush(outcome),outcome);
    expect(await page.evaluate(()=>fixtureJoinedPush)).toBe(outcome==='success');
    if(outcome==='success'){
      await expect(page.locator('#notificationStatus')).toContainText('Уведомления включены');
      expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='subscribe').length)).toBe(1);
      expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php').length)).toBe(1);
      expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='confirmation').length)).toBe(1);
    }else{
      const diagnostic=page.locator('#notificationStatus .notificationDiagnostic');
      await expect(diagnostic).toContainText('AbortError');await expect(diagnostic).toContainText('PUSH_NETWORK');
      await page.evaluate(()=>{fixturePushStatus={is_working:true,notification_path_usable:true,notification_path_reason:'healthy_subscription',healthy_subscription_count:1};return WorkspaceV2Notifications.refresh();});
      await expect(diagnostic).toBeVisible();await expect(page.locator('#notificationStatus')).not.toContainText('Уведомления включены');
      await expect(button).toBeEnabled();expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='subscribe').length)).toBe(2);
      expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php').length)).toBe(0);
      expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='confirmation').length)).toBe(0);
    }
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='register').length)).toBe(1);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php?action=key').length)).toBe(1);
    await expect(page.locator('#notificationStatus')).not.toContainText('PRIVATE_ENDPOINT');
    const size=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,width:innerWidth}));expect(size.scroll).toBeLessThanOrEqual(size.width+1);
    await page.screenshot({path:`notification-diagnostic-artifacts/${browserName}-${width}-recovery-${outcome}-result.png`});
    expect(unexpected).toEqual([]);
  });
});

for(const [width,height] of [[390,844],[430,844],[768,844],[1440,844],[390,420]])test.describe(`push bounded setup ${width}x${height}`,()=>{
  test.use({viewport:{width,height}});
  test('key body timeout restores Retry and a late body cannot repeat the saved setup',async({page,browserName})=>{
    const unexpected=await setup(page,true,{keyBodyPending:true,recoverAfterAbort:true});
    const originalDraft=await page.locator('.composer textarea').inputValue();
    const button=page.locator('#notificationStatus [data-enable-push]');
    await button.focus();await button.press('Enter');await expect(button).toBeDisabled();
    await expect.poll(()=>page.evaluate(()=>typeof fixtureFinishKey)).toBe('function');
    await page.evaluate(()=>fixtureExpireKey());
    const diagnostic=page.locator('#notificationStatus .notificationDiagnostic');
    await expect(diagnostic).toContainText('Этап: Получение настроек');await expect(diagnostic).toContainText('Код: TIMEOUT');
    await expect(button).toBeEnabled();expect(await page.evaluate(()=>fixtureKeySignal.aborted)).toBe(true);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='subscribe'||call==='push.php').length)).toBe(0);
    await diagnostic.locator('summary').focus();await diagnostic.locator('summary').press('Enter');await expect(diagnostic).toHaveAttribute('open','');
    fs.mkdirSync('notification-diagnostic-artifacts',{recursive:true});
    await page.screenshot({path:`notification-diagnostic-artifacts/${browserName}-${width}x${height}-key-body-timeout.png`});
    await button.click();await expect(page.locator('#notificationStatus')).toContainText('Уведомления включены');
    await page.evaluate(()=>fixtureFinishKey());
    await expect(page.locator('#notificationStatus')).toContainText('Уведомления включены');
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php?action=key').length)).toBe(2);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='subscribe').length)).toBe(1);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php').length)).toBe(1);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='confirmation').length)).toBe(1);
    await expect(page.locator('.composer textarea')).toHaveValue(originalDraft);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);expect(unexpected).toEqual([]);
  });
  test('pending or rejected optional confirmation leaves saved success usable',async({page,browserName})=>{
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    const unexpected=await setup(page,true,{confirmationPending:true,recoverAfterAbort:true});
    const originalDraft=await page.locator('.composer textarea').inputValue();
    await page.locator('#notificationStatus [data-enable-push]').click();
    await expect(page.locator('#notificationStatus')).toContainText('Уведомления включены');
    await expect(page.locator('#notificationStatus [data-enable-push]')).toHaveCount(0);
    expect(await page.evaluate(()=>typeof fixtureRejectConfirmation)).toBe('function');
    fs.mkdirSync('notification-diagnostic-artifacts',{recursive:true});
    await page.screenshot({path:`notification-diagnostic-artifacts/${browserName}-${width}x${height}-confirmation-pending.png`});
    await page.evaluate(()=>fixtureRejectConfirmation());await page.evaluate(()=>WorkspaceV2Notifications.refresh());
    await expect(page.locator('#notificationStatus')).toContainText('Уведомления включены');
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='subscribe').length)).toBe(1);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php').length)).toBe(1);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='confirmation').length)).toBe(1);
    await expect(page.locator('.composer textarea')).toHaveValue(originalDraft);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    expect(errors).toEqual([]);expect(unexpected).toEqual([]);
  });
});

for(const [width,height] of [[390,844],[430,844],[768,844],[1440,844],[390,420]])for(const outcome of ['success','failure'])test.describe(`push native late outcome ${width}x${height} ${outcome}`,()=>{
  test.use({viewport:{width,height}});
  test('shows pending native work and follows its exact terminal result without another click',async({page,browserName})=>{
    const unexpected=await setup(page,true,{backgroundPending:true,nativeDeadline:true});
    const originalDraft=await page.locator('.composer textarea').inputValue();
    await expect.poll(()=>page.evaluate(()=>typeof fixtureFinishPush)).toBe('function');
    await page.locator('#notificationStatus [data-enable-push]').click();
    await page.evaluate(()=>fixtureExpireSubscription());
    const root=page.locator('#notificationStatus'),button=root.locator('[data-enable-push]');
    await expect(root).toContainText('Браузер ещё подключает уведомления');await expect(root).toContainText('Разрешение получено');
    await expect(root).not.toContainText('Если окно разрешения');await expect(button).toBeEnabled();await expect(button).toHaveText('Проверить');
    await page.evaluate(()=>{fixturePushStatus={is_working:true,notification_path_usable:true,notification_path_reason:'healthy_subscription',healthy_subscription_count:1};return WorkspaceV2Notifications.refresh();});
    await expect(root).toContainText('Браузер ещё подключает уведомления');await expect(root).not.toContainText('Уведомления включены');
    fs.mkdirSync('notification-diagnostic-artifacts',{recursive:true});
    await page.screenshot({path:`notification-diagnostic-artifacts/${browserName}-${width}x${height}-native-${outcome}-pending.png`});
    await page.evaluate(outcome=>fixtureFinishPush(outcome),outcome);
    if(outcome==='success'){
      await expect(root).toContainText('Уведомления включены');await expect(root.locator('.notificationDiagnostic')).toHaveCount(0);
    }else{
      await expect(root.locator('.notificationDiagnostic')).toContainText('AbortError/PUSH_NETWORK');
      await expect(root.locator('.notificationDiagnostic')).not.toContainText('TIMEOUT');await expect(button).toBeEnabled();
    }
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='subscribe').length)).toBe(outcome==='success'?1:2);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php').length)).toBe(outcome==='success'?1:0);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='confirmation'||call==='permission').length)).toBe(0);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php?action=key').length)).toBe(1);
    await expect(page.locator('.composer textarea')).toHaveValue(originalDraft);await expect(root).not.toContainText('PRIVATE_ENDPOINT');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    await page.screenshot({path:`notification-diagnostic-artifacts/${browserName}-${width}x${height}-native-${outcome}-result.png`});expect(unexpected).toEqual([]);
  });
});

for(const width of [390,1440])for(const outcome of ['granted','denied'])test.describe(`push permission timeout ${width} ${outcome}`,()=>{
  test.use({viewport:{width,height:844}});
  test('Retry observes the original permission request after its UI timeout',async({page,browserName})=>{
    const unexpected=await setup(page,true,{permissionPending:true,recoverAfterAbort:true});
    const button=page.locator('#notificationStatus [data-enable-push]');
    await button.click();
    await expect(button).toBeDisabled();
    await expect.poll(()=>page.evaluate(()=>fixturePushCalls.filter(call=>call==='permission').length)).toBe(1);
    await page.evaluate(()=>fixtureExpirePermission());
    const diagnostic=page.locator('#notificationStatus .notificationDiagnostic');
    await expect(diagnostic).toContainText('Этап: Разрешение браузера');
    await expect(diagnostic).toContainText('Код: TIMEOUT');
    await expect(button).toBeEnabled();
    await button.click();
    await expect(button).toBeDisabled();
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='permission').length)).toBe(1);
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='register').length)).toBe(0);
    await page.evaluate(outcome=>fixtureFinishPermission(outcome),outcome);
    if(outcome==='granted'){
      await expect(page.locator('#notificationStatus')).toContainText('Уведомления включены');
      expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php').length)).toBe(1);
    }else{
      await expect(diagnostic).toContainText('PERMISSION_NOT_GRANTED');
      await expect(button).toBeEnabled();
      expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='register').length)).toBe(0);
      expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='push.php').length)).toBe(0);
    }
    expect(await page.evaluate(()=>fixturePushCalls.filter(call=>call==='permission').length)).toBe(1);
    expect(unexpected).toEqual([]);
    const size=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,width:innerWidth}));
    expect(size.scroll).toBeLessThanOrEqual(size.width+1);
    fs.mkdirSync('notification-diagnostic-artifacts',{recursive:true});
    await page.screenshot({path:`notification-diagnostic-artifacts/${browserName}-${width}-permission-timeout-${outcome}.png`});
  });
});
