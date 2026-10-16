const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const pageSource=fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-notifications.js'),'utf8');
const workerSource=fs.readFileSync(path.join(__dirname,'../manager/sw.js'),'utf8');
const secret='PRIVATE_PUSH_ENDPOINT_OR_ERROR';
const key=new Uint8Array([1]);
const turn=()=>new Promise(resolve=>setImmediate(resolve));
async function boundedFixture(promise){let deadline;try{return await Promise.race([promise,new Promise((_,reject)=>{deadline=setTimeout(()=>reject(new Error('expected native/membership fixture operation never started')),1000);})]);}finally{clearTimeout(deadline);}}
function deferred(){let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no});return{promise,resolve,reject};}
function abort(message=secret){return new DOMException(message,'AbortError');}
function subscription(bytes=key){return{options:{applicationServerKey:bytes},toJSON(){return{endpoint:'https://push.example.test/'+secret,keys:{p256dh:secret,auth:secret}}}};}
function locks(){let queue=Promise.resolve();return{request(name,callback){assert.equal(name,'anytour-manager-push-subscription');const next=queue.then(callback);queue=next.catch(()=>{});return next;}};}
function environment({initial=null,subscribe=null,lockManager=null,deadlineMs=100,deadlines=null,unhealthy=false,unsubscribe=null,saveRequest=null,outsideScope=false,unrelatedReady=false,keyRequest=null,statusRequest=null,readSubscription=null}={}){
  const state={sub:initial,reads:0,subscribes:0,unsubscribes:0,active:0,maxActive:0,saves:0,confirmations:0,shown:0,auth:0,permissions:0,registered:0,lookup:0,readyReads:0,currentChecks:0,currentHealthy:false,serverKey:key,expectedCsrf:'synthetic',otherDeviceHealthy:false,saveAttempts:0,aborts:0};
  const attach=sub=>{if(sub)sub.unsubscribe=async()=>{state.unsubscribes++;if(unsubscribe)await unsubscribe();state.sub=null;return true;};return sub;};
  attach(initial);
  const workerJobs=[];
  const registration={scope:'https://app.anytoour.ru/manager/',active:{state:'activated',postMessage(data){workerJobs.push(worker('message',data));}},pushManager:{
    async getSubscription(){state.reads++;return readSubscription?await readSubscription(state.reads,state):state.sub;},
    async subscribe(options){assert.equal(options.userVisibleOnly,true);assert.deepEqual(Array.from(options.applicationServerKey),Array.from(state.serverKey));state.subscribes++;state.active++;state.maxActive=Math.max(state.maxActive,state.active);try{if(state.active>1)throw abort('synthetic overlapping registration');const sub=subscribe?await subscribe(state.subscribes,state):subscription(state.serverKey);state.sub=attach(sub);return sub;}finally{state.active--;}}
  },async showNotification(title){if(title==='AnyTour')state.confirmations++;else state.shown++;}};
  const healthy=()=>({is_working:true,notification_path_usable:true,notification_path_reason:'healthy_subscription',healthy_subscription_count:1});
  const status=()=>state.saves||state.otherDeviceHealthy?healthy():{is_working:true,notification_path_usable:false,notification_path_reason:unhealthy?'subscription_unhealthy':'no_subscription'};
  const current=()=>({notification_path_usable:state.saves>0||state.currentHealthy,notification_path_reason:state.saves||state.currentHealthy?'healthy_subscription':unhealthy?'subscription_unhealthy':'no_subscription'});
  const fetch=async(url,options)=>{
    const isCurrent=url==='push-status.php'&&options?.method==='POST';
    if(isCurrent){state.currentChecks++;const body=JSON.parse(options.body);assert.equal(body.csrf,state.expectedCsrf);assert.equal(body.endpoint,state.sub?.toJSON().endpoint);}
    const override=url==='push-status.php'&&statusRequest?await statusRequest(options):url==='push.php?action=key'&&keyRequest?await keyRequest():null;
    if(override)return override;
    if(url!=='push-status.php'&&options?.method==='POST'){state.saveAttempts++;if(saveRequest)await saveRequest(options,state);}
    return{ok:true,status:200,async json(){
      if(url==='push-status.php')return{ok:true,push_status:status(),...(isCurrent?{current_subscription:current()}:{})};
      if(options?.method==='POST'){state.saves++;assert.equal(JSON.parse(options.body).csrf,state.expectedCsrf);return{ok:true,push_status:healthy(),current_subscription:current()};}
      return{ok:true,public_key:Buffer.from(state.serverKey).toString('base64'),csrf:'synthetic'};
    }};
  };
  const timer=(callback,delay)=>{if(deadlines&&delay>=10000){const timer={callback,delay,cancelled:false};deadlines.push(timer);return timer;}return delay===500?0:setTimeout(callback,delay>=10000?deadlineMs:delay===250?0:delay);};
  const cancelTimer=timer=>{if(deadlines&&deadlines.includes(timer))timer.cancelled=true;else clearTimeout(timer);};
  const atob=value=>Buffer.from(value,'base64').toString('binary');
  function page(){
    const root={innerHTML:'',className:''};
    const document={readyState:'loading',baseURI:'https://app.anytoour.ru/manager/',addEventListener(){},getElementById(){return root},createElement(){return{set textContent(value){this.value=String(value??'');},get innerHTML(){return this.value.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}};}};
    const Notification={permission:'granted',async requestPermission(){state.permissions++;return'granted';}};
    const navigator={serviceWorker:{async getRegistration(url){state.lookup++;assert.equal(url,'https://app.anytoour.ru/manager/');return registration;},async register(){state.registered++;return registration;},get ready(){state.readyReads++;return outsideScope?new Promise(()=>{}):unrelatedReady?Promise.resolve({scope:'https://app.anytoour.ru/',active:{state:'activated'},pushManager:{getSubscription(){throw new Error('Used unrelated global registration');}}}):Promise.resolve(registration);},addEventListener(){}},...(lockManager?{locks:lockManager}:{})};
    const auth={csrf:state.expectedCsrf,manager:{id:1},authGeneration:0};
    const context={document,navigator,window:{WorkspaceV2:{S:auth,showAuthRecovery(){state.auth++;}},Notification,PushManager:function(){}},Notification,fetch,atob,Uint8Array,URL,AbortController,setTimeout:timer,clearTimeout:cancelTimer,console:{warn(){}}};
    vm.runInNewContext(pageSource,context,{filename:'workspace-v2-notifications.js'});
    return{api:context.window.WorkspaceV2Notifications,root,auth};
  }
  const events={};
  vm.runInNewContext(workerSource,{navigator:lockManager?{locks:lockManager}:{},self:{registration,clients:{async claim(){},async matchAll(){return[];}},addEventListener(type,callback){events[type]=callback;}},fetch,atob,Uint8Array,AbortController,importScripts(){},WorkspaceSoundLedger:{supported(){return false;}},setTimeout:timer,clearTimeout:cancelTimer},{filename:'sw.js'});
  async function worker(type,data){let promise;events[type]({data,waitUntil(value){promise=value;}});await promise;}
  return{state,page,worker,async settleWorker(){await Promise.all(workerJobs);}};
}
async function lateNativeOutcomeCases(){
  const failures=[];
  for(const outcome of ['success','failure']){
    const native=deferred(),env=environment({subscribe:count=>count===1?native.promise:Promise.reject(abort('Registration failed - could not connect to push server')),deadlineMs:5}),page=env.page();
    assert.equal(await page.api.enable(page.root),false);assert.match(page.root.innerHTML,/TIMEOUT/);
    assert.match(page.root.innerHTML,/Браузер ещё подключает уведомления/);assert.match(page.root.innerHTML,/>Проверить<\/button>/);assert.ok(!page.root.innerHTML.includes('Если окно разрешения'));
    env.state.otherDeviceHealthy=true;await page.api.refresh();assert.ok(!page.root.innerHTML.includes('Уведомления включены'));
    if(outcome==='success')native.resolve(subscription());else native.reject(abort('Registration failed - could not connect to push server'));
    await turn();await new Promise(resolve=>setTimeout(resolve,5));await turn();
    const expected=outcome==='success'?'Уведомления включены':'AbortError/PUSH_NETWORK';
    if(!page.root.innerHTML.includes(expected)||page.root.innerHTML.includes('TIMEOUT'))failures.push(`${outcome}: native terminal result remains hidden behind the UI timeout`);
    assert.equal(env.state.subscribes,outcome==='success'?1:2);assert.equal(env.state.saves,outcome==='success'?1:0);assert.equal(env.state.confirmations,0);assert.equal(env.state.permissions,0);
    assert.ok(!page.root.innerHTML.includes(secret));
  }
  if(failures.length)throw new Error(failures.join('; '));
  console.log('PASS  late native success and failure replace only their own UI timeout without another setup or confirmation');

  const native=deferred(),env=environment({subscribe:()=>native.promise,deadlineMs:5}),page=env.page();
  assert.equal(await page.api.enable(page.root),false);page.auth.manager.id=2;page.auth.authGeneration++;page.auth.csrf='new-account';page.root.innerHTML='Current account';
  native.resolve(subscription());await turn();await turn();
  assert.equal(env.state.saves,0);assert.equal(env.state.confirmations,0);assert.equal(page.root.innerHTML,'Current account');
  const denied=deferred(),expired=environment({subscribe:()=>denied.promise,deadlineMs:5,saveRequest(){throw new Error('push_session_expired');}}),expiredPage=expired.page();
  assert.equal(await expiredPage.api.enable(expiredPage.root),false);denied.resolve(subscription());await turn();await turn();
  assert.equal(expired.state.auth,1);assert.equal(expired.state.saves,0);assert.equal(expired.state.confirmations,0);assert.match(expiredPage.root.innerHTML,/Войдите в кабинет/);assert.ok(!expiredPage.root.innerHTML.includes('TIMEOUT'));
  console.log('PASS  native settlement after UI timeout respects account changes and canonical expired-session recovery');
  let requests=0;const oldNative=deferred(),newer=environment({subscribe:()=>oldNative.promise,deadlineMs:5,keyRequest(){if(++requests>1)throw new TypeError(secret);}}),newerPage=newer.page();
  assert.equal(await newerPage.api.enable(newerPage.root),false);assert.equal(await newerPage.api.enable(newerPage.root),false);assert.match(newerPage.root.innerHTML,/TypeError/);
  oldNative.resolve(subscription());await turn();await turn();
  assert.match(newerPage.root.innerHTML,/TypeError/);assert.ok(!newerPage.root.innerHTML.includes('Уведомления включены'));assert.equal(newer.state.subscribes,1);assert.equal(newer.state.saves,1);assert.equal(newer.state.confirmations,0);
  console.log('PASS  late results from an older UI attempt cannot replace a newer explicit diagnosis');
}
async function browserInspectionRaceCases(){
  const older=deferred(),env=environment({initial:subscription(),readSubscription:async(count,state)=>count===1?older.promise:state.sub}),page=env.page();env.state.otherDeviceHealthy=true;env.state.currentHealthy=true;
  const first=page.api.refresh();await turn();await page.api.refresh();assert.match(page.root.innerHTML,/Уведомления включены/);const currentHtml=page.root.innerHTML;
  older.resolve(null);await first;await turn();assert.equal(page.root.innerHTML,currentHtml);assert.equal(env.state.subscribes,0);assert.equal(env.state.unsubscribes,0);assert.equal(env.state.saves,0);assert.equal(env.state.permissions,0);

  const oldNative=deferred(),activation=environment({readSubscription:async(count,state)=>count===1?oldNative.promise:state.sub}),activePage=activation.page();activation.state.otherDeviceHealthy=true;
  const checking=activePage.api.refresh();await turn();assert.equal(await activePage.api.enable(activePage.root),true);assert.match(activePage.root.innerHTML,/Уведомления включены/);const activatedHtml=activePage.root.innerHTML;
  oldNative.resolve(null);await checking;await turn();assert.equal(activePage.root.innerHTML,activatedHtml);assert.equal(activation.state.subscribes,1);assert.equal(activation.state.saves,1);assert.equal(activation.state.confirmations,1);assert.equal(activation.state.permissions,0);

  const membership=deferred(),entered=deferred(),authEnv=environment({initial:subscription(),statusRequest:options=>{if(options?.method==='POST'){entered.resolve();return membership.promise;}}}),authPage=authEnv.page();authEnv.state.otherDeviceHealthy=true;authEnv.state.currentHealthy=true;
  const protectedRead=authPage.api.refresh();await boundedFixture(entered.promise);authPage.auth.manager.id=2;authPage.auth.authGeneration++;authPage.auth.csrf='new-current-account';authPage.root.innerHTML='Current account';
  membership.resolve({ok:false,status:401,json:async()=>({ok:false})});await protectedRead;await turn();assert.equal(authPage.root.innerHTML,'Current account');assert.equal(authEnv.state.auth,0);assert.equal(authEnv.state.subscribes,0);assert.equal(authEnv.state.saves,0);
  console.log('PASS  stale native and membership reads cannot replace newer refresh, activation or account results');
}
async function run(){
  await browserInspectionRaceCases();
  await lateNativeOutcomeCases();
  // Reload can start granted-permission recovery before the visible Enable click.
  // That click must observe this attempt, with feedback now rather than a queued retry.
  const backgroundNative=deferred(),promoted=environment({subscribe:()=>backgroundNative.promise}),promotedPage=promoted.page();
  await promotedPage.api.refresh();await turn();assert.equal(promoted.state.subscribes,1);
  const promotedEnable=promotedPage.api.enable(promotedPage.root);
  assert.match(promotedPage.root.innerHTML,/Подключаем…/,'Enable stays invisible while granted-permission recovery is pending');
  assert.match(promotedPage.root.innerHTML,/data-enable-push disabled/);
  const repeatedEnable=promotedPage.api.enable(promotedPage.root);assert.equal(repeatedEnable,promotedEnable);await turn();
  assert.equal(promoted.state.subscribes,1);assert.equal(promoted.state.permissions,0);
  for(const otherDeviceHealthy of [false,true]){promoted.state.otherDeviceHealthy=otherDeviceHealthy;await promotedPage.api.refresh();assert.match(promotedPage.root.innerHTML,/Подключаем…/);assert.match(promotedPage.root.innerHTML,/data-enable-push disabled/);assert.ok(!promotedPage.root.innerHTML.includes('Уведомления включены'));}
  backgroundNative.resolve(subscription());assert.equal(await promotedEnable,true);assert.equal(await repeatedEnable,true);
  await turn();assert.equal(promoted.state.registered,1);assert.equal(promoted.state.saves,1);assert.equal(promoted.state.confirmations,1);
  assert.ok(promotedPage.root.innerHTML.includes('Уведомления включены'));
  console.log('PASS  Enable promotes pending granted-permission recovery with immediate feedback and one result');

  const failedNative=deferred(),promotedFailure=environment({subscribe:async count=>count===1?failedNative.promise:Promise.reject(abort('Registration failed - push service error'))}),promotedFailurePage=promotedFailure.page();
  await promotedFailurePage.api.refresh();await turn();const failureEnable=promotedFailurePage.api.enable(promotedFailurePage.root);
  failedNative.reject(abort('Registration failed - push service error'));assert.equal(await failureEnable,false);await turn();
  assert.equal(promotedFailure.state.subscribes,2,'manual click queues another full recovery after the failed background attempt');
  assert.equal(promotedFailure.state.registered,1);assert.equal(promotedFailure.state.saves,0);assert.equal(promotedFailure.state.confirmations,0);assert.equal(promotedFailure.state.permissions,0);
  assert.match(promotedFailurePage.root.innerHTML,/AbortError\/PUSH_SERVICE/);assert.ok(!promotedFailurePage.root.innerHTML.includes(secret));
  promotedFailure.state.otherDeviceHealthy=true;await promotedFailurePage.api.refresh();await turn();
  assert.match(promotedFailurePage.root.innerHTML,/AbortError\/PUSH_SERVICE/);assert.ok(!promotedFailurePage.root.innerHTML.includes('Уведомления включены'));assert.equal(promotedFailure.state.subscribes,2);
  console.log('PASS  clicked background AbortError is diagnostic and status refresh cannot erase or retry it');

  const uiDeadlines=[],timeoutNative=deferred(),promotedTimeout=environment({subscribe:()=>timeoutNative.promise,lockManager:locks(),deadlines:uiDeadlines}),promotedTimeoutPage=promotedTimeout.page();
  await promotedTimeoutPage.api.refresh();await turn();
  const originalDeadline=uiDeadlines.find(timer=>!timer.cancelled);assert.ok(originalDeadline);const beforeClickTimers=uiDeadlines.length;
  const timeoutEnable=promotedTimeoutPage.api.enable(promotedTimeoutPage.root),timeoutRepeat=promotedTimeoutPage.api.enable(promotedTimeoutPage.root);
  assert.equal(uiDeadlines.length,beforeClickTimers,'manual click restarts the whole UI wait');assert.equal(originalDeadline.cancelled,false);
  originalDeadline.callback();assert.equal(await timeoutEnable,false);assert.equal(await timeoutRepeat,false);assert.match(promotedTimeoutPage.root.innerHTML,/TIMEOUT/);
  assert.equal(promotedTimeout.state.subscribes,1);assert.equal(promotedTimeout.state.maxActive,1);assert.equal(promotedTimeout.state.registered,1);
  promotedTimeout.state.otherDeviceHealthy=true;await promotedTimeoutPage.api.refresh();assert.match(promotedTimeoutPage.root.innerHTML,/TIMEOUT/);
  timeoutNative.resolve(subscription());await turn();await turn();assert.equal(promotedTimeout.state.saves,1);assert.equal(promotedTimeout.state.confirmations,0);
  assert.match(promotedTimeoutPage.root.innerHTML,/Уведомления включены/);await promotedTimeoutPage.api.refresh();assert.match(promotedTimeoutPage.root.innerHTML,/Уведомления включены/);assert.equal(promotedTimeout.state.subscribes,1);
  console.log('PASS  promotion keeps the original UI deadline and only same-job saved success can clear its timeout');

  for(const accountChanged of [false,true]){
    const oldNative=deferred(),stalePromotion=environment({subscribe:()=>oldNative.promise}),stalePage=stalePromotion.page();
    await stalePage.api.refresh();await turn();stalePage.auth.manager.id=accountChanged?2:1;stalePage.auth.authGeneration++;stalePage.auth.csrf='new-session';stalePromotion.state.expectedCsrf=stalePage.auth.csrf;stalePage.root.innerHTML='Current session';
    assert.equal(await stalePage.api.enable(stalePage.root),false);assert.equal(stalePage.root.innerHTML,'Current session');
    oldNative.resolve(subscription());await turn();await turn();assert.equal(stalePromotion.state.saves,0);assert.equal(stalePromotion.state.confirmations,0);assert.equal(stalePage.root.innerHTML,'Current session');
    assert.equal(await stalePage.api.enable(stalePage.root),true);assert.equal(stalePromotion.state.subscribes,1);assert.equal(stalePromotion.state.saves,1);assert.equal(stalePromotion.state.confirmations,1);
  }
  const untouchedNative=deferred(),untouched=environment({subscribe:()=>untouchedNative.promise}),untouchedPage=untouched.page();
  await untouchedPage.api.refresh();await turn();assert.ok(!untouchedPage.root.innerHTML.includes('Подключаем…'));untouchedNative.resolve(subscription());await turn();await turn();assert.equal(untouched.state.saves,1);assert.equal(untouched.state.confirmations,0);assert.equal(untouched.state.permissions,0);assert.ok(!untouchedPage.root.innerHTML.includes('notificationDiagnostic'));
  const unclickedFailure=environment({subscribe:async()=>{throw abort();}}),unclickedPage=unclickedFailure.page();await unclickedPage.api.refresh();await turn();await new Promise(resolve=>setTimeout(resolve,5));await turn();assert.equal(unclickedFailure.state.subscribes,2);assert.equal(unclickedFailure.state.confirmations,0);assert.equal(unclickedFailure.state.permissions,0);assert.ok(!unclickedPage.root.innerHTML.includes('notificationDiagnostic'));assert.ok(!unclickedPage.root.innerHTML.includes('Подключаем…'));
  console.log('PASS  stale sessions cannot promote recovery and unclicked recovery retains silent behavior');

  // Both actual programs share one native PushManager. A second pending native
  // subscribe throws the exact AbortError previously shown by this race.
  const hold=deferred(),racing=environment({subscribe:()=>hold.promise});
  const page=racing.page(),activation=racing.worker('activate');
  const enable=page.api.enable(page.root);await turn();
  assert.equal(racing.state.subscribes,1,'worker activation competes with page subscription');
  assert.equal(racing.state.maxActive,1,'overlapping native subscription recreates AbortError');
  hold.resolve(subscription());assert.equal(await enable,true);await activation;
  assert.equal(racing.state.saves,1);assert.equal(racing.state.confirmations,1);
  console.log('PASS  actual page and worker activation cannot create competing subscriptions');

  for(const initial of [null,subscription(new Uint8Array([2]))]){
    const passive=environment({initial});
    await passive.worker('activate');await passive.worker('message',{type:'SYNC_PUSH'});await passive.worker('message',{type:'SHOW_NOTIFICATION',title:'Synthetic push'});
    assert.equal(passive.state.subscribes,0);assert.equal(passive.state.unsubscribes,0);assert.equal(passive.state.saves,0);assert.equal(passive.state.shown,1);
  }
  const synced=environment({initial:subscription()});await synced.worker('activate');assert.equal(synced.state.saves,0);await synced.worker('message',{type:'SYNC_PUSH'});assert.equal(synced.state.saves,1);assert.equal(synced.state.subscribes,0);assert.equal(synced.state.unsubscribes,0);
  const reused=environment({initial:subscription()}),healthyPage=reused.page();assert.equal(await healthyPage.api.enable(healthyPage.root),true);assert.equal(reused.state.subscribes,0);assert.equal(reused.state.unsubscribes,0);
  const stale=environment({initial:subscription(new Uint8Array([2]))}),stalePage=stale.page();assert.equal(await stalePage.api.enable(stalePage.root),true);assert.equal(stale.state.unsubscribes,1);assert.equal(stale.state.subscribes,1);
  console.log('PASS  automatic worker paths stay passive; compatible sync and page VAPID repair survive');
  const startup=environment({initial:subscription(),lockManager:locks()}),startupPage=startup.page();await startupPage.api.init();await startup.settleWorker();assert.equal(startup.state.saves,1);assert.equal(startup.state.subscribes,0);assert.equal(startup.state.unsubscribes,0);
  console.log('PASS  page initialization passively rebinds an existing subscription after activation');

  const late=deferred(),timed=environment({subscribe:()=>late.promise,deadlineMs:5}),timedPage=timed.page();
  assert.equal(await timedPage.api.enable(timedPage.root),false);assert.match(timedPage.root.innerHTML,/TIMEOUT/);
  const retry=timedPage.api.enable(timedPage.root);await turn();assert.equal(timed.state.subscribes,1,'retry starts a second operation after UI timeout');
  late.resolve(subscription());assert.equal(await retry,true);assert.equal(timed.state.maxActive,1);assert.equal(timed.state.saves,1);assert.equal(timed.state.confirmations,1);
  console.log('PASS  UI timeout preserves pending native ownership and retry observes late completion');

  const nativeLate=deferred(),cross=environment({subscribe:()=>nativeLate.promise,lockManager:locks(),deadlineMs:5}),tab1=cross.page(),tab2=cross.page();
  assert.equal(await tab1.api.enable(tab1.root),false);const tab2Enable=tab2.api.enable(tab2.root);await turn();assert.equal(cross.state.subscribes,1);
  nativeLate.resolve(subscription());assert.equal(await tab2Enable,true);assert.equal(cross.state.subscribes,1);assert.equal(cross.state.maxActive,1);
  console.log('PASS  origin lock remains held after timeout and a second tab reuses the actual subscription');

  const lateRepair=deferred(),repairing=environment({initial:subscription(new Uint8Array([2])),unsubscribe:()=>lateRepair.promise,deadlineMs:5}),repairPage=repairing.page();
  assert.equal(await repairPage.api.enable(repairPage.root),false);const repairRetry=repairPage.api.enable(repairPage.root);await turn();assert.equal(repairing.state.unsubscribes,1);assert.equal(repairing.state.subscribes,0);
  lateRepair.resolve();assert.equal(await repairRetry,true);assert.equal(repairing.state.unsubscribes,1);assert.equal(repairing.state.subscribes,1);
  const changedNative=deferred(),changed=environment({subscribe:async count=>count===1?changedNative.promise:subscription(changed.state.serverKey),deadlineMs:5}),changedPage=changed.page();
  assert.equal(await changedPage.api.enable(changedPage.root),false);changed.state.serverKey=new Uint8Array([2]);assert.equal(await changedPage.api.enable(changedPage.root),false);changedNative.resolve(subscription());await turn();assert.equal(changed.state.saves,0);assert.equal(changed.state.maxActive,1);
  assert.equal(await changedPage.api.enable(changedPage.root),true);assert.equal(changed.state.saves,1);assert.equal(changed.state.subscribes,2);assert.equal(changed.state.unsubscribes,1);
  const pendingRead=deferred(),alreadyCurrent=subscription(new Uint8Array([2])),rotating=environment({initial:alreadyCurrent,readSubscription:async count=>count===1?pendingRead.promise:alreadyCurrent,deadlineMs:5}),rotatingPage=rotating.page();
  assert.equal(await rotatingPage.api.enable(rotatingPage.root),false);rotating.state.serverKey=new Uint8Array([2]);assert.equal(await rotatingPage.api.enable(rotatingPage.root),false);pendingRead.resolve(alreadyCurrent);await turn();
  assert.equal(rotating.state.unsubscribes,0,'superseded key job deletes an already-current subscription');assert.equal(rotating.state.subscribes,0);assert.equal(rotating.state.saves,0);assert.equal(rotating.state.sub,alreadyCurrent);
  assert.equal(await rotatingPage.api.enable(rotatingPage.root),true);assert.equal(rotating.state.unsubscribes,0);assert.equal(rotating.state.subscribes,0);assert.equal(rotating.state.saves,1);
  console.log('PASS  late unsubscribe remains protected and changed VAPID key cannot inherit old-key success');

  const cancelled=environment({lockManager:locks(),deadlineMs:5,saveRequest:async(options,state)=>{if(state.saveAttempts===1)await new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>{state.aborts++;reject(abort());},{once:true}));}}),cancelPage=cancelled.page();
  assert.equal(await cancelPage.api.enable(cancelPage.root),false);await new Promise(resolve=>setTimeout(resolve,10));assert.equal(cancelled.state.aborts,1);assert.equal(await cancelPage.api.enable(cancelPage.root),true);assert.equal(cancelled.state.subscribes,1);assert.equal(cancelled.state.saveAttempts,2);assert.equal(cancelled.state.saves,1);
  const ready=environment({unrelatedReady:true}),readyPage=ready.page();assert.equal(await readyPage.api.enable(readyPage.root),true);assert.equal(ready.state.readyReads,0);assert.equal(ready.state.saves,1);
  const outside=environment({outsideScope:true}),outsidePage=outside.page();assert.equal(await outsidePage.api.enable(outsidePage.root),true);assert.equal(outside.state.readyReads,0);assert.equal(outside.state.subscribes,1);assert.equal(outside.state.saves,1);
  console.log('PASS  bounded HTTP abort releases origin lock and canonical registered worker is used without global ready');

  for(const accountChanged of [false,true]){
    const lateAuth=deferred(),authEnv=environment({subscribe:()=>lateAuth.promise}),authPage=authEnv.page();const authEnable=authPage.api.enable(authPage.root);await turn();
    authPage.auth.manager.id=accountChanged?2:1;authPage.auth.authGeneration++;authPage.auth.csrf='new-session-csrf';authEnv.state.expectedCsrf=authPage.auth.csrf;
    lateAuth.resolve(subscription());assert.equal(await authEnable,false);assert.equal(authEnv.state.saves,0);assert.equal(authEnv.state.confirmations,0);assert.equal(authEnv.state.auth,0);assert.ok(!authPage.root.innerHTML.includes('Уведомления включены'));
    assert.equal(await authPage.api.enable(authPage.root),true);assert.equal(authEnv.state.subscribes,1);assert.equal(authEnv.state.saves,1);
  }
  const blocked=deferred(),display=environment({subscribe:()=>blocked.promise,lockManager:locks()}),displayPage=display.page();const displayEnable=displayPage.api.enable(displayPage.root);await turn();const displayed=display.worker('message',{type:'SHOW_NOTIFICATION',title:'Synthetic push'});await turn();assert.equal(display.state.shown,1);await display.worker('activate');blocked.resolve(subscription());assert.equal(await displayEnable,true);await displayed;
  const heldKey=deferred(),keyAuth=environment({keyRequest:()=>heldKey.promise}),keyPage=keyAuth.page();const keyEnable=keyPage.api.enable(keyPage.root);await turn();keyPage.auth.manager.id=2;keyPage.auth.authGeneration++;keyPage.auth.csrf='new-session';keyPage.root.innerHTML='New account';heldKey.resolve();assert.equal(await keyEnable,false);assert.equal(keyAuth.state.subscribes,0);assert.equal(keyAuth.state.saves,0);assert.equal(keyAuth.state.auth,0);assert.equal(keyPage.root.innerHTML,'New account');
  const heldStatus=deferred(),statusAuth=environment({statusRequest:()=>heldStatus.promise}),statusPage=statusAuth.page();const refresh=statusPage.api.refresh();await turn();statusPage.auth.manager.id=2;statusPage.auth.authGeneration++;statusPage.auth.csrf='new-session';statusPage.root.innerHTML='New account';heldStatus.resolve({ok:false,status:401,json:async()=>({ok:false})});await refresh;assert.equal(statusAuth.state.auth,0);assert.equal(statusPage.root.innerHTML,'New account');
  console.log('PASS  stale auth jobs cannot save or render success; worker notifications stay independent of pending subscription');


  const recovered=environment({subscribe:async count=>{if(count===1)throw abort();return subscription();}}),recoverPage=recovered.page();assert.equal(await recoverPage.api.enable(recoverPage.root),true);assert.equal(recovered.state.subscribes,2);assert.equal(recovered.state.maxActive,1);assert.equal(recovered.state.saves,1);
  const found=environment({subscribe:async(count,state)=>{state.sub=subscription();throw abort();}}),foundPage=found.page();assert.equal(await foundPage.api.enable(foundPage.root),true);assert.equal(found.state.subscribes,1);assert.equal(found.state.saves,1);
  const incompatible=environment({subscribe:async(count,state)=>{state.sub=subscription(new Uint8Array([2]));throw abort();}}),incompatiblePage=incompatible.page();assert.equal(await incompatiblePage.api.enable(incompatiblePage.root),false);assert.equal(incompatible.state.subscribes,1);assert.equal(incompatible.state.unsubscribes,0);assert.equal(incompatible.state.saves,0);
  const persistent=environment({subscribe:async()=>{throw abort('Registration failed - push service error');}}),failurePage=persistent.page();assert.equal(await failurePage.api.enable(failurePage.root),false);assert.equal(persistent.state.subscribes,2);assert.equal(persistent.state.maxActive,1);assert.equal(persistent.state.saves,0);assert.equal(persistent.state.confirmations,0);assert.match(failurePage.root.innerHTML,/AbortError/);assert.ok(!failurePage.root.innerHTML.includes(secret));
  const denied=environment({subscribe:async()=>{throw new DOMException(secret,'NotAllowedError');}}),deniedPage=denied.page();assert.equal(await deniedPage.api.enable(deniedPage.root),false);assert.equal(denied.state.subscribes,1);assert.equal(denied.state.saves,0);
  const before=persistent.state.subscribes;persistent.state.otherDeviceHealthy=true;await failurePage.api.refresh();assert.equal(persistent.state.subscribes,before);assert.match(failurePage.root.innerHTML,/AbortError\/PUSH_SERVICE/);assert.ok(!failurePage.root.innerHTML.includes('Уведомления включены'));
  const beforeIdentity=denied.state.subscribes;deniedPage.auth.manager.id=2;deniedPage.auth.authGeneration++;deniedPage.auth.csrf='new-account';denied.state.expectedCsrf=deniedPage.auth.csrf;await deniedPage.api.refresh();await turn();assert.ok(denied.state.subscribes>beforeIdentity);await turn();
  console.log('PASS  only native AbortError gets bounded sequential recovery; persistent failures stay truthful');
}
run().catch(error=>{console.error('FAIL  '+error.stack);process.exitCode=1;});
