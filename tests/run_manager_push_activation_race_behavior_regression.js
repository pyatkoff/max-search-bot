const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const pageSource=fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-notifications.js'),'utf8');
const workerSource=fs.readFileSync(path.join(__dirname,'../manager/sw.js'),'utf8');
const secret='PRIVATE_PUSH_ENDPOINT_OR_ERROR';
const key=new Uint8Array([1]);
const turn=()=>new Promise(resolve=>setImmediate(resolve));
function deferred(){let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no});return{promise,resolve,reject};}
function abort(message=secret){return new DOMException(message,'AbortError');}
function subscription(bytes=key){return{options:{applicationServerKey:bytes},toJSON(){return{endpoint:'https://push.example.test/'+secret,keys:{p256dh:secret,auth:secret}}}};}
function locks(){let queue=Promise.resolve();return{request(name,callback){assert.equal(name,'anytour-manager-push-subscription');const next=queue.then(callback);queue=next.catch(()=>{});return next;}};}
function environment({initial=null,subscribe=null,lockManager=null,deadlineMs=100,unhealthy=false,unsubscribe=null,saveRequest=null,differentReady=false,keyRequest=null,statusRequest=null,readSubscription=null}={}){
  const state={sub:initial,reads:0,subscribes:0,unsubscribes:0,active:0,maxActive:0,saves:0,confirmations:0,shown:0,auth:0,permissions:0,registered:0,serverKey:key,expectedCsrf:'synthetic',otherDeviceHealthy:false,saveAttempts:0,aborts:0};
  const attach=sub=>{if(sub)sub.unsubscribe=async()=>{state.unsubscribes++;if(unsubscribe)await unsubscribe();state.sub=null;return true;};return sub;};
  attach(initial);
  const workerJobs=[];
  const registration={scope:'https://app.anytoour.ru/manager/',active:{postMessage(data){workerJobs.push(worker('message',data));}},pushManager:{
    async getSubscription(){state.reads++;return readSubscription?await readSubscription(state.reads,state):state.sub;},
    async subscribe(options){assert.equal(options.userVisibleOnly,true);assert.deepEqual(Array.from(options.applicationServerKey),Array.from(state.serverKey));state.subscribes++;state.active++;state.maxActive=Math.max(state.maxActive,state.active);try{if(state.active>1)throw abort('synthetic overlapping registration');const sub=subscribe?await subscribe(state.subscribes,state):subscription(state.serverKey);state.sub=attach(sub);return sub;}finally{state.active--;}}
  },async showNotification(title){if(title==='AnyTour')state.confirmations++;else state.shown++;}};
  const healthy=()=>({is_working:true,notification_path_usable:true,notification_path_reason:'healthy_subscription',healthy_subscription_count:1});
  const status=()=>state.saves||state.otherDeviceHealthy?healthy():{is_working:true,notification_path_usable:false,notification_path_reason:unhealthy?'subscription_unhealthy':'no_subscription'};
  const fetch=async(url,options)=>{const override=url==='push-status.php'&&statusRequest?await statusRequest():url==='push.php?action=key'&&keyRequest?await keyRequest():null;if(override)return override;if(options?.method==='POST'){state.saveAttempts++;if(saveRequest)await saveRequest(options,state);}return{ok:true,status:200,async json(){if(url==='push-status.php')return{ok:true,push_status:status()};if(options?.method==='POST'){state.saves++;assert.equal(JSON.parse(options.body).csrf,state.expectedCsrf);return{ok:true,push_status:healthy()};}return{ok:true,public_key:Buffer.from(state.serverKey).toString('base64'),csrf:'synthetic'};}};};
  const timer=(callback,delay)=>delay===500?0:setTimeout(callback,delay>=10000?deadlineMs:delay===250?0:delay);
  const atob=value=>Buffer.from(value,'base64').toString('binary');
  function page(){
    const root={innerHTML:'',className:''};
    const document={readyState:'loading',addEventListener(){},getElementById(){return root},createElement(){return{set textContent(value){this.value=String(value??'');},get innerHTML(){return this.value.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}};}};
    const Notification={permission:'granted',async requestPermission(){state.permissions++;return'granted';}};
    const navigator={serviceWorker:{async register(){state.registered++;return differentReady?{scope:registration.scope,pushManager:{getSubscription(){throw new Error('Used register object instead of ready registration');}}}:registration;},ready:Promise.resolve(registration),addEventListener(){}},...(lockManager?{locks:lockManager}:{})};
    const auth={csrf:state.expectedCsrf,manager:{id:1},authGeneration:0};
    const context={document,navigator,window:{WorkspaceV2:{S:auth,showAuthRecovery(){state.auth++;}},Notification,PushManager:function(){}},Notification,fetch,atob,Uint8Array,AbortController,setTimeout:timer,clearTimeout,console:{warn(){}}};
    vm.runInNewContext(pageSource,context,{filename:'workspace-v2-notifications.js'});
    return{api:context.window.WorkspaceV2Notifications,root,auth};
  }
  const events={};
  vm.runInNewContext(workerSource,{navigator:lockManager?{locks:lockManager}:{},self:{registration,clients:{async claim(){},async matchAll(){return[];}},addEventListener(type,callback){events[type]=callback;}},fetch,atob,Uint8Array,AbortController,importScripts(){},WorkspaceSoundLedger:{supported(){return false;}},setTimeout:timer,clearTimeout},{filename:'sw.js'});
  async function worker(type,data){let promise;events[type]({data,waitUntil(value){promise=value;}});await promise;}
  return{state,page,worker,async settleWorker(){await Promise.all(workerJobs);}};
}
async function run(){
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
  const ready=environment({differentReady:true}),readyPage=ready.page();assert.equal(await readyPage.api.enable(readyPage.root),true);
  console.log('PASS  bounded HTTP abort releases origin lock and canonical ready registration is used');

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
