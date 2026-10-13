const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const origin='http://127.0.0.1:4173';

// Exercise the real notification module with in-memory browser/HTTP failures.
// No real permission prompt, push subscription, save or notification is created.
async function setup(page,working,{recoverAfterAbort=false}={}){
  const unexpected=[];
  await page.route('**/*',route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.origin!==origin||request.method()!=='GET'||url.pathname.endsWith('.php')){
      unexpected.push(request.url());return route.abort();
    }
    return route.continue();
  });
  await page.goto(origin+'/tests/visual/workspace-v2-fixture.html');
  await page.evaluate(({working,recoverAfterAbort})=>{
    document.querySelector('.notificationHealth').id='notificationStatus';
    window.fixturePushCalls=[];
    window.fixturePushStatus={is_working:working,notification_path_usable:false,notification_path_reason:'no_subscription'};
    window.WorkspaceV2={S:{csrf:'synthetic'}};
    const notification={permission:'default',async requestPermission(){fixturePushCalls.push('permission');return 'granted';}};
    Object.defineProperty(window,'Notification',{configurable:true,value:notification});
    Object.defineProperty(window,'PushManager',{configurable:true,value:function(){}});
    let registered=false;
    const subscription={options:{applicationServerKey:new Uint8Array([1])},toJSON(){return {endpoint:'https://push.example.test/PRIVATE_ENDPOINT_OR_KEY_NOT_FOR_DISPLAY',keys:{auth:'synthetic',p256dh:'synthetic'}};}};
    const registration={pushManager:{
      async getSubscription(){fixturePushCalls.push('read');return registered?subscription:null;},
      async subscribe(){fixturePushCalls.push('subscribe');registered=recoverAfterAbort;throw new DOMException(recoverAfterAbort?'PRIVATE_ENDPOINT_OR_KEY_NOT_FOR_DISPLAY':'Registration failed - could not connect to push server','AbortError');},
    },async showNotification(){fixturePushCalls.push('confirmation');if(!recoverAfterAbort)throw new Error('Unexpected confirmation');}};
    Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{
      ready:Promise.resolve(registration),async register(){fixturePushCalls.push('register');return registration;},addEventListener(){},
    }});
    window.fetch=async(url,options)=>{
      fixturePushCalls.push(String(url));
      if(url==='push-status.php')return {ok:true,status:200,json:async()=>({ok:true,push_status:fixturePushStatus})};
      if(url==='push.php?action=key')return {ok:true,status:200,json:async()=>({ok:true,public_key:'AQ'})};
      if(url==='push.php'&&recoverAfterAbort){fixturePushStatus={is_working:working,notification_path_usable:true,notification_path_reason:'healthy_subscription',healthy_subscription_count:1};return {ok:true,status:200,json:async()=>({ok:true,push_status:fixturePushStatus})};}
      throw new Error('Unexpected request');
    };
  },{working,recoverAfterAbort});
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
