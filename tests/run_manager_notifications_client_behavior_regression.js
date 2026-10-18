const assert = require('node:assert/strict');
const fs = require('fs');
const vm = require('vm');
const {lifecycle}=require('./fixtures/manager_push_browser_fixture');

const source = fs.readFileSync(require('path').join(__dirname, '..', 'manager', 'assets', 'workspace-v2-notifications.js'), 'utf8');
const listeners = {};
const root = { className: '', innerHTML: '' };
const document = {
  readyState: 'loading',
  baseURI: 'https://app.anytoour.ru/manager/',
  addEventListener(type, callback) { listeners[type] = callback; },
  getElementById(id) { return id === 'notificationStatus' ? root : null; },
  createElement() {
    return {
      value: '',
      set textContent(value) { this.value = String(value ?? ''); },
      get innerHTML() {
        return this.value.replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]);
      },
    };
  },
};
const baseProtocol=lifecycle(7);
const context = {
  localStorage:baseProtocol.localStorage,crypto:baseProtocol.crypto,
  document,
  navigator: {},
  window: { WorkspaceV2: { S: { csrf: 'test', manager: { id: 7 }, authGeneration: 0 } } },
  console: { warn() {} },
  setTimeout,
  clearTimeout,
  Uint8Array,
  URL,
};

vm.runInNewContext(source, context, { filename: 'workspace-v2-notifications.js' });

const secretMarker = 'PRIVATE_ENDPOINT_TOKEN_DO_NOT_DISPLAY';
function activationHarness({ failAt = '', failure = new Error(secretMarker), httpStatus = 0, permission = 'granted', permissionResult = 'granted', working = false, healthReason = 'manager_inactive', outsideScope = false, permissionRequest = null, keyBody = null, confirmationRequest = null, clock = null, browser = null } = {}) {
  const localRoot = { className: '', innerHTML: '' };
  const protocol=lifecycle(7);if(browser)protocol.localStorage.clear();
  const calls = { permission: 0, register: 0, ready: 0, key: 0, read: 0, repair: 0, subscribe: 0, save: 0, confirmation: 0, status: 0, currentStatus: 0, lookup: 0, auth: 0, diagnostic: 0 };
  const keySignals = [];
  let userGesture = false, permissionDuringGesture = false, nativeSubscription=browser?.subscription??null;
  const step = name => { calls[name]++; if (failAt === name) throw failure; };
  const healthy = { is_working: working, notification_path_usable: true, notification_path_reason: 'healthy_subscription', healthy_subscription_count: 1 };
  const subscription = { toJSON() { return { endpoint: `https://push.example.test/${secretMarker}`, keys: { p256dh: secretMarker, auth: secretMarker } }; } };
  const staleSubscription = { ...subscription, options: { applicationServerKey: new Uint8Array([2]) }, async unsubscribe() { step('repair'); return true; } };
  if(nativeSubscription&&!nativeSubscription.unsubscribe)nativeSubscription.unsubscribe=async()=>{step('repair');nativeSubscription=null;return true;};
  const registration = {
    scope: 'https://app.anytoour.ru/manager/',
    get active() { if (failAt === 'ready') { calls.ready++; throw failure; } return { state: 'activated' }; },
    pushManager: {
      async getSubscription() { step('read'); if(browser?.read)return browser.read();if(failAt==='repair')nativeSubscription=staleSubscription;return nativeSubscription; },
      async subscribe() { step('subscribe'); nativeSubscription=browser?.subscribe?await browser.subscribe():subscription;return nativeSubscription; },
    },
    showNotification() { step('confirmation'); return confirmationRequest ? confirmationRequest() : Promise.resolve(); },
  };
  const serviceWorker = {
    async getRegistration(url) { calls.lookup++;assert.equal(url,'https://app.anytoour.ru/manager/');return browser?.lookup?browser.lookup(registration):registration; },
    async register() { step('register'); return registration; },
    get ready() { step('ready'); return outsideScope ? new Promise(() => {}) : Promise.resolve(registration); },
    addEventListener() {},
  };
  const notification = { permission, requestPermission() { permissionDuringGesture = userGesture; step('permission'); const request=permissionRequest?permissionRequest():Promise.resolve(permissionResult);return Promise.resolve(request).then(result=>{notification.permission=result;return result;}); } };
  const localDocument = { ...document, addEventListener() {}, getElementById(id) { return id === 'notificationStatus' ? localRoot : null; } };
  const localContext = {
    document: localDocument,
    localStorage:protocol.localStorage,crypto:protocol.crypto,
    navigator: { serviceWorker },
    window: { WorkspaceV2: { S: { csrf: secretMarker, manager: { id: 7 }, authGeneration: 0 }, showAuthRecovery() { calls.auth++; } }, PushManager: function(){}, Notification: notification },
    Notification: notification,
    async fetch(url, options) {
      if (url === 'push-status.php') {
        const current=options?.method==='POST';calls[current?'currentStatus':'status']++;
        if(current){const body=JSON.parse(options.body);assert.equal(body.csrf,localContext.window.WorkspaceV2.S.csrf);assert.equal(body.endpoint,nativeSubscription?.toJSON().endpoint);}
        if(browser?.statusRequest){const override=await browser.statusRequest(options);if(override)return override;}
        return { ok: true, status: 200, async json() { return { ok: true, push_status: browser?.accountHealthy||(current&&!browser&&nativeSubscription)?healthy:{ is_working: working, notification_path_usable: false, notification_path_reason: healthReason },...(current?{current_subscription:{notification_path_usable:browser?browser.currentHealthy===true:!!nativeSubscription,notification_path_reason:(!browser&&nativeSubscription)||browser?.currentHealthy?'healthy_subscription':browser?.currentReason||'no_subscription'},current_connection:protocol.current()}:{}) }; } };
      }
      const body=options?.method==='POST'?JSON.parse(options.body):null;
      if(url==='push.php?action=setup_diagnostic'){calls.diagnostic++;return{ok:true,status:200,json:async()=>({ok:true,accepted:true})};}
      if(body?.action==='enable_intent')return{ok:true,status:200,json:async()=>protocol.reply(body)};
      const name = options?.method === 'POST' ? 'save' : 'key';
      if (!(failAt === name && httpStatus)) step(name); else calls[name]++;
      const status = failAt === name && httpStatus ? httpStatus : 200;
      if (name === 'key') keySignals.push(options?.signal);
      if(name==='save'&&browser?.saveRequest)await browser.saveRequest(options);
      if(name==='save'&&status===200&&browser)browser.currentHealthy=true;
      return { ok: status === 200, status, async json() { if (name === 'key' && keyBody) return keyBody(calls.key); return status === 200 ? name === 'key' ? { ok: true, public_key: 'AQ' } : { ...protocol.reply(body), push_status: healthy,current_subscription:{notification_path_usable:true,notification_path_reason:'healthy_subscription'} } : { ok: false, error: secretMarker }; } };
    },
    atob: value => Buffer.from(value, 'base64').toString('binary'),
    console: { warn() {} },
    setTimeout(callback, delay) { if (clock) return clock.setTimeout(callback, delay); return delay === 500 ? 0 : setTimeout(callback, outsideScope && delay >= 10000 ? 10 : delay); },
    clearTimeout(id) { if (clock) clock.clearTimeout(id); else clearTimeout(id); },
    Uint8Array,
    URL,
    AbortController,
  };
  vm.runInNewContext(source, localContext, { filename: 'workspace-v2-notifications.js' });
  return {
    root: localRoot, calls, keySignals, subscription, registration, notification, context:localContext,
    api: localContext.window.WorkspaceV2Notifications,
    async enable() { userGesture = true; const result = this.api.enable(localRoot); userGesture = false; return result; },
    clearFailure() { failAt = ''; },
    changeSession() { localContext.window.WorkspaceV2.S.csrf = 'different-session'; },
    permissionDuringGesture() { return permissionDuringGesture; },
  };
}

async function currentBrowserCases(){
  const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return{promise,resolve,reject};};
  const drain=async()=>{for(let i=0;i<40;i++)await Promise.resolve();await new Promise(setImmediate);};
  const local=()=>({toJSON(){return{endpoint:`https://push.example.test/${secretMarker}`,keys:{p256dh:secretMarker,auth:secretMarker}};}});
  const notGreen=h=>{assert.ok(!h.root.className.split(/\s+/).includes('ok'));assert.ok(!h.root.innerHTML.includes('Уведомления включены'));assert.match(h.root.innerHTML,/data-enable-push/);assert.ok(!h.root.innerHTML.includes(secretMarker));};
  const noPrompt=h=>{assert.equal(h.calls.permission,0);assert.equal(h.calls.confirmation,0);};
  const failures=[];
  async function check(label,run){let deadline;try{await Promise.race([run(),new Promise((_,reject)=>{deadline=setTimeout(()=>reject(new Error('expected fixture operation never started or settled')),1000);})]);console.log('PASS  '+label);}catch(error){failures.push(label+': '+error.message);console.error('FAIL  '+label+': '+error.message);}finally{clearTimeout(deadline);}}
  await check('initial ownerless refresh performs no native or protected reads and authenticated refresh resumes normally',async()=>{
    const h=activationHarness({browser:{accountHealthy:true,subscription:local(),currentHealthy:true}}),auth=h.context.window.WorkspaceV2.S;
    for(const state of [{manager:null,csrf:'',authExpired:false},{manager:{id:7},csrf:'',authExpired:false},{manager:{id:7},csrf:secretMarker,authExpired:true}]){
      Object.assign(auth,state);await h.api.refresh();await drain();assert.equal(h.calls.status,0);assert.equal(h.calls.currentStatus,0);assert.equal(h.calls.lookup,0);assert.equal(h.calls.read,0);assert.equal(h.calls.auth,0);assert.ok(!h.root.className.split(/\s+/).includes('ok'));noPrompt(h);
    }
    Object.assign(auth,{manager:{id:7},csrf:secretMarker,authExpired:false,authGeneration:1});await h.api.refresh();await drain();assert.equal(h.calls.status,1);assert.equal(h.calls.currentStatus,1);assert.match(h.root.innerHTML,/Уведомления включены/);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.save,0);
  });
  for(const accountHealthy of [false,true])for(const permission of ['default','denied'])await check(`current browser ${permission} stays actionable with account healthy=${accountHealthy}`,async()=>{
    const h=activationHarness({permission,browser:{accountHealthy}});await h.api.refresh();await drain();notGreen(h);noPrompt(h);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.save,0);assert.equal(h.calls.currentStatus,0);assert.equal(h.calls.register,0);
  });
  await check('another healthy device cannot hide a missing granted-browser subscription or prompt in background',async()=>{
    const h=activationHarness({browser:{accountHealthy:true}});
    await h.api.refresh();await drain();notGreen(h);noPrompt(h);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.save,0);assert.equal(h.calls.currentStatus,0);
    await h.api.refresh();await drain();assert.equal(h.calls.subscribe,0);notGreen(h);
    assert.equal(await h.enable(),true);assert.equal(h.calls.subscribe,1);assert.equal(h.calls.save,1);assert.equal(h.calls.permission,0);assert.equal(h.calls.confirmation,1);assert.match(h.root.innerHTML,/Уведомления включены/);
  });
  await check('an existing active installation retains exactly one silent granted-permission recovery',async()=>{
    const native=deferred(),h=activationHarness({healthReason:'no_subscription',browser:{accountHealthy:false,subscribe:()=>native.promise}});
    h.context.localStorage.setItem('anytour-manager-push-installation:7',JSON.stringify({token:'a'.repeat(64),revision:1}));
    await h.api.refresh();await drain();notGreen(h);assert.equal(h.calls.subscribe,1);noPrompt(h);await h.api.refresh();await drain();assert.equal(h.calls.subscribe,1);
    native.resolve(h.subscription);await drain();assert.equal(h.calls.save,1);assert.match(h.root.innerHTML,/Уведомления включены/);noPrompt(h);
  });
  await check('a native subscription is green only after protected current-endpoint membership is healthy',async()=>{
    const h=activationHarness({browser:{accountHealthy:true,subscription:local(),currentHealthy:true}});await h.api.refresh();await drain();
    assert.match(h.root.innerHTML,/Уведомления включены/);assert.ok(h.root.className.split(/\s+/).includes('ok'));assert.ok(!h.root.innerHTML.includes('data-enable-push'));assert.equal(h.calls.lookup,1);assert.equal(h.calls.currentStatus,1);assert.equal(h.calls.read,2);assert.equal(h.calls.register,0);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.repair,0);assert.equal(h.calls.save,0);noPrompt(h);assert.ok(!h.root.innerHTML.includes(secretMarker));
  });
  await check('ancestor registration cannot stand in for the canonical manager worker',async()=>{
    const h=activationHarness({browser:{accountHealthy:true,subscription:local(),currentHealthy:true}});h.registration.scope='https://app.anytoour.ru/';await h.api.refresh();await drain();notGreen(h);assert.equal(h.calls.lookup,1);assert.equal(h.calls.read,0);assert.equal(h.calls.currentStatus,0);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.save,0);noPrompt(h);
  });
  await check('an endpoint changed while membership proof is pending cannot inherit the old endpoint healthy result',async()=>{
    const proof=deferred(),entered=deferred();let actual=local();
    const h=activationHarness({browser:{accountHealthy:true,subscription:actual,currentHealthy:true,read:()=>actual,statusRequest:async options=>{if(options?.method==='POST'){entered.resolve();await proof.promise;}}}}),refresh=h.api.refresh();
    await entered.promise;actual={toJSON(){return{endpoint:`https://push.example.test/${secretMarker}/new`,keys:{p256dh:'new',auth:'new'}};}};proof.resolve();await refresh;await drain();notGreen(h);assert.equal(h.calls.read,2);assert.equal(h.calls.currentStatus,1);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.repair,0);assert.equal(h.calls.save,0);noPrompt(h);
  });
  for(const currentReason of ['no_subscription','subscription_unhealthy'])await check(`aggregate health cannot mask current endpoint ${currentReason}`,async()=>{
    const browser={accountHealthy:true,subscription:local(),currentHealthy:false,currentReason};const h=activationHarness({browser});
    await h.api.refresh();await drain();notGreen(h);assert.equal(h.calls.currentStatus,1);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.repair,0);assert.equal(h.calls.save,0);noPrompt(h);
    assert.equal(await h.enable(),true);assert.equal(h.calls.save,1);assert.equal(h.calls.subscribe,currentReason==='subscription_unhealthy'?1:0);assert.equal(h.calls.repair,currentReason==='subscription_unhealthy'?1:0);assert.ok(h.calls.currentStatus>=2);assert.equal(h.calls.permission,0);
  });
  await check('click before any probe uses fresh endpoint proof and UNKNOWN cannot delete or replace native subscription',async()=>{
    const h=activationHarness({browser:{accountHealthy:true,subscription:local(),currentHealthy:false,currentReason:'health_check_failed'}});
    assert.equal(await h.enable(),false);assert.equal(h.calls.currentStatus,1);assert.equal(h.calls.read,1);assert.equal(h.calls.repair,0);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.save,0);assert.equal(h.calls.permission,0);assert.equal(h.calls.confirmation,0);notGreen(h);
  });
  await check('aggregate-only subscribe response cannot claim current browser setup success',async()=>{
    const h=activationHarness({browser:{accountHealthy:true}}),fetch=h.context.fetch;
    h.context.fetch=async(url,options)=>{const response=await fetch(url,options);if(url==='push.php'&&options?.method==='POST')return{...response,async json(){const body=await response.json();delete body.current_subscription;return body;}};return response;};
    assert.equal(await h.enable(),false);assert.equal(h.calls.subscribe,1);assert.equal(h.calls.save,1);assert.equal(h.calls.confirmation,0);notGreen(h);
  });
  for(const accountHealthy of [false,true])await check(`UNKNOWN local read is not absence or recovery with account healthy=${accountHealthy}`,async()=>{
    const h=activationHarness({browser:{accountHealthy,read:async()=>{throw new DOMException(secretMarker,'InvalidStateError');}}});await h.api.refresh();await drain();notGreen(h);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.repair,0);assert.equal(h.calls.save,0);assert.equal(h.calls.currentStatus,0);noPrompt(h);
  });
  await check('unavailable native support cannot inherit another device healthy status',async()=>{
    const h=activationHarness({browser:{accountHealthy:true}});delete h.context.window.PushManager;await h.api.refresh();await drain();notGreen(h);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.save,0);assert.equal(h.calls.currentStatus,0);noPrompt(h);
  });
  await check('a timed-out native read stays UNKNOWN and a late completion cannot repaint it',async()=>{
    const native=deferred(),timers=new Map();let timerId=0;
    const clock={setTimeout(fn,delay){const id=++timerId;timers.set(id,{fn,delay});return id;},clearTimeout(id){timers.delete(id);}};
    const h=activationHarness({clock,browser:{accountHealthy:true,read:()=>native.promise}}),refresh=h.api.refresh();await drain();
    assert.ok(timers.size>0,'native inspection has no bounded deadline');for(const [id,timer]of [...timers]){timers.delete(id);assert.ok(timer.delay<=15000);timer.fn();}
    await refresh;await drain();notGreen(h);const html=h.root.innerHTML;native.resolve(local());await drain();assert.equal(h.root.innerHTML,html);assert.equal(h.calls.currentStatus,0);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.save,0);noPrompt(h);
  });
  await check('old local inspection cannot render or bind an endpoint after account/session replacement',async()=>{
    const native=deferred();const h=activationHarness({browser:{accountHealthy:true,read:()=>native.promise}}),refresh=h.api.refresh();await drain();
    h.context.window.WorkspaceV2.S.manager.id=8;h.context.window.WorkspaceV2.S.authGeneration++;h.changeSession();h.root.innerHTML='Current account';native.resolve(local());await refresh;await drain();
    assert.equal(h.root.innerHTML,'Current account');assert.equal(h.calls.auth,0);assert.equal(h.calls.currentStatus,0);assert.equal(h.calls.subscribe,0);assert.equal(h.calls.save,0);noPrompt(h);
  });
  if(failures.length)throw new Error('Current-browser regressions: '+failures.join('; '));
}

async function activationDiagnosticCases() {
  const cases = [
    ['permission', 'Разрешение браузера', 'NotAllowedError', new DOMException(secretMarker, 'NotAllowedError')],
    ['register', 'Подготовка уведомлений', 'SecurityError', new DOMException(secretMarker, 'SecurityError')],
    ['ready', 'Запуск уведомлений', 'InvalidStateError', new DOMException(secretMarker, 'InvalidStateError')],
    ['key', 'Получение настроек', 'TypeError', new TypeError(secretMarker)],
    ['read', 'Проверка подписки', 'AbortError', new DOMException(secretMarker, 'AbortError')],
    ['repair', 'Обновление подписки', 'AbortError', new DOMException(secretMarker, 'AbortError')],
    ['subscribe', 'Подключение устройства', 'NotAllowedError', new DOMException(secretMarker, 'NotAllowedError')],
    ['save', 'Сохранение подписки', 'TypeError', new TypeError(secretMarker)],
  ];
  for (const [failAt, stage, code, failure] of cases) {
    const harness = activationHarness({ failAt, failure, permission: failAt === 'permission' ? 'default' : 'granted' });
    if (await harness.enable() !== false) throw new Error(`${failAt} failure is treated as successful activation`);
    if (!harness.root.innerHTML.includes(`Этап: ${stage}`) || !harness.root.innerHTML.includes(`Код: ${code}`)) throw new Error(`${failAt} native failure has no safe inline diagnostic`);
    if (!harness.root.innerHTML.includes('<details class="notificationDiagnostic">') || !harness.root.innerHTML.includes('<summary>Подробности для администратора</summary>') || /<details[^>]*\bopen\b/.test(harness.root.innerHTML)) throw new Error(`${failAt} safe diagnosis is not behind an initially collapsed disclosure`);
    if (harness.root.innerHTML.includes(secretMarker) || harness.root.innerHTML.includes('https://push.example.test')) throw new Error(`${failAt} diagnosis leaks raw error or subscription secrets`);
    if (!harness.root.innerHTML.includes(failAt==='save'?'Проверить':'Повторить') || harness.root.innerHTML.includes('disabled')) throw new Error(`${failAt} does not restore the retry button`);
    if (harness.calls.subscribe > 1 || harness.calls.save > 1 || harness.calls.confirmation !== 0 || harness.calls.status !== 0) throw new Error(`${failAt} diagnosis introduces extra activation effects`);
    const callsBeforeRefresh = { ...harness.calls };
    await harness.api.refresh();
    if (!harness.root.innerHTML.includes(`Этап: ${stage}`)) throw new Error(`${failAt} diagnosis is erased by ordinary health refresh`);
    if (harness.calls.subscribe !== callsBeforeRefresh.subscribe || harness.calls.save !== callsBeforeRefresh.save) throw new Error(`${failAt} health refresh retries subscription changes`);
  }
  console.log('PASS  native activation failures expose eight distinct stages and allowlisted codes without secrets or extra effects');

  for (const failAt of ['key', 'save']) {
    const harness = activationHarness({ failAt, httpStatus: 403 });
    await harness.enable();
    if (!harness.root.innerHTML.includes('Код: HTTP_403') || harness.root.innerHTML.includes(secretMarker)) throw new Error(`${failAt} HTTP failure does not keep only a safe status code`);
  }
  const unknownFailure = new Error(secretMarker); unknownFailure.name = secretMarker;
  const unknown = activationHarness({ failAt: 'subscribe', failure: unknownFailure });
  await unknown.enable();
  if (!unknown.root.innerHTML.includes('Код: FAILED') || unknown.root.innerHTML.includes(secretMarker)) throw new Error('unknown error fields are not reduced to a safe fallback code');
  const denied = activationHarness({ permission: 'default', permissionResult: 'denied' });
  await denied.enable();
  if (!denied.root.innerHTML.includes('Этап: Разрешение браузера') || !denied.root.innerHTML.includes('Код: PERMISSION_NOT_GRANTED') || denied.calls.register !== 0) throw new Error('denied permission has no safe diagnosis or continues setup');
  console.log('PASS  HTTP, unknown native errors and denied permission expose bounded diagnostics');

  const retried = activationHarness({ failAt: 'subscribe', failure: new Error('subscribe_timeout') });
  await retried.enable();
  if (!retried.root.innerHTML.includes('Код: TIMEOUT')) throw new Error('known stage timeout does not expose a safe timeout code');
  retried.clearFailure();
  await retried.enable();
  if (!retried.root.innerHTML.includes('Уведомления включены') || retried.root.innerHTML.includes('notificationDiagnostic')) throw new Error('successful retry retains the previous inline diagnosis');
  const successful = activationHarness({ permission: 'default' });
  if (await successful.enable() !== true || !successful.root.innerHTML.includes('Уведомления включены') || successful.root.innerHTML.includes('notificationDiagnostic')) throw new Error('successful activation keeps a failure diagnostic or loses canonical success');
  if (!successful.permissionDuringGesture() || successful.calls.permission !== 1 || successful.calls.subscribe !== 1 || successful.calls.save !== 1 || successful.calls.confirmation !== 1) throw new Error('diagnosis changes permission gesture, subscription or confirmation behavior');
  const auth = activationHarness({ failAt: 'save', httpStatus: 401 });
  await auth.enable();
  if (auth.calls.auth !== 1 || auth.root.innerHTML.includes('notificationDiagnostic') || auth.calls.confirmation !== 0) throw new Error('authorization failure becomes a setup diagnosis or shows false success');
  const background = activationHarness({ failAt: 'subscribe', failure: new DOMException(secretMarker, 'NotAllowedError') });
  await background.api.refresh();
  if (background.api.maybeRecoverGranted(background.root) !== false || background.calls.permission !== 0 || background.calls.subscribe !== 0) throw new Error('nonrecoverable server status starts background activation');
  const recovering = activationHarness({ failAt: 'subscribe', failure: new DOMException(secretMarker, 'NotAllowedError'), healthReason: 'no_subscription' });
  await recovering.api.refresh();
  await new Promise(resolve => setTimeout(resolve, 0));
  await recovering.api.refresh();
  if (recovering.calls.subscribe !== 1 || recovering.calls.save !== 0 || recovering.calls.permission !== 0 || recovering.calls.confirmation !== 0 || recovering.root.innerHTML.includes('notificationDiagnostic') || recovering.api.maybeRecoverGranted(recovering.root) !== false) throw new Error('background recovery repeats, prompts permission or exposes interactive diagnosis '+JSON.stringify(recovering.calls));
  console.log('PASS  canonical success, user permission gesture, authorization and recovery guards are preserved');
}


// Model registration and worker EventTargets instead of replacing global ready
// with a resolved promise. /manager2 is outside the canonical /manager scope.
async function registrationLifecycleCases() {
  class Target {
    constructor(extra = {}) { Object.assign(this, extra); this.listeners = new Map(); }
    addEventListener(type, callback) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(callback); }
    removeEventListener(type, callback) { this.listeners.get(type)?.delete(callback); }
    dispatch(type) { for (const callback of [...this.listeners.get(type) || []]) callback({ target: this }); }
    get listenerCount() { return [...this.listeners.values()].reduce((count, callbacks) => count + callbacks.size, 0); }
  }
  function fixture(registration) {
    const timers = new Map(); let timerId = 0, registrations = 0, readyReads = 0;
    const serviceWorker = {
      register: async () => { registrations++; return registration; },
      get ready() { readyReads++; throw new Error('unrelated global ready was read'); },
      addEventListener() {},
    };
    const localContext = {
      ...context, document: { ...document, addEventListener() {} }, navigator: { serviceWorker },
      window: { WorkspaceV2: { S: { csrf: 'synthetic', manager: { id: 7 }, authGeneration: 0 } } },
      setTimeout(callback) { timers.set(++timerId, callback); return timerId; }, clearTimeout(id) { timers.delete(id); },
    };
    vm.runInNewContext(source, localContext, { filename: 'workspace-v2-notifications.js' });
    return { api: localContext.window.WorkspaceV2Notifications, timers, get registrations() { return registrations; }, get readyReads() { return readyReads; } };
  }
  function clean(harness, registration, workers) {
    if (harness.timers.size || registration.listenerCount || workers.some(worker => worker.listenerCount)) throw new Error('worker readiness leaves a timer or lifecycle listener after settlement');
    if (harness.readyReads !== 0) throw new Error('worker readiness reads an unrelated global registration');
  }
  const active = new Target({ state: 'activated' });
  const immediate = new Target({ active, installing: null, waiting: null }); const immediateHarness = fixture(immediate);
  if (await immediateHarness.api.readyRegistration(immediate) !== immediate || immediateHarness.registrations) throw new Error('already active readiness loses the supplied canonical registration');
  clean(immediateHarness, immediate, [active]);
  if (await immediateHarness.api.readyRegistration() !== immediate || immediateHarness.registrations !== 1) throw new Error('default readiness does not register the canonical worker once');
  clean(immediateHarness, immediate, [active]);
  const sharedHarness = fixture(immediate);
  const shared = await Promise.all([sharedHarness.api.readyRegistration(), sharedHarness.api.readyRegistration()]);
  if (shared.some(value => value !== immediate) || sharedHarness.registrations !== 1) throw new Error('concurrent push and sound readiness creates duplicate canonical registrations');
  clean(sharedHarness, immediate, [active]);

  const worker = new Target({ state: 'installing' });
  const registration = new Target({ active: null, installing: worker, waiting: null }); const harness = fixture(registration);
  let settled = false; const pending = harness.api.readyRegistration(registration).then(value => { settled = true; return value; });
  await Promise.resolve(); if (settled) throw new Error('installing registration is treated as active');
  worker.state = 'installed'; registration.installing = null; registration.waiting = worker; worker.dispatch('statechange');
  await Promise.resolve(); if (settled) throw new Error('waiting registration is treated as active');
  worker.state = 'activating'; registration.waiting = null; registration.active = worker; worker.dispatch('statechange');
  await Promise.resolve(); if (settled) throw new Error('activating worker is treated as activated');
  worker.state = 'activated'; worker.dispatch('statechange');
  if (await pending !== registration) throw new Error('activation resolves to another registration');
  clean(harness, registration, [worker]);

  const empty = new Target({ active: null, installing: null, waiting: null }); const foundHarness = fixture(empty);
  const foundPending = foundHarness.api.readyRegistration(empty); await Promise.resolve();
  const foundWorker = new Target({ state: 'installing' }); empty.installing = foundWorker; empty.dispatch('updatefound');
  foundWorker.state = 'activated'; empty.installing = null; empty.active = foundWorker; foundWorker.dispatch('statechange');
  if (await foundPending !== empty) throw new Error('worker discovered by updatefound is not observed');
  clean(foundHarness, empty, [foundWorker]);

  for (const state of ['redundant', 'timeout']) {
    const failedWorker = new Target({ state: 'installing' }); const failedReg = new Target({ active: null, installing: failedWorker, waiting: null }); const failedHarness = fixture(failedReg);
    const failure = failedHarness.api.readyRegistration(failedReg); await Promise.resolve();
    if (state === 'redundant') { failedWorker.state = 'redundant'; failedWorker.dispatch('statechange'); }
    else { if (!failedHarness.timers.size) throw new Error('readiness has no bounded timeout'); for (const callback of [...failedHarness.timers.values()]) callback(); }
    let rejected = false; try { await failure; } catch (_) { rejected = true; }
    if (!rejected) throw new Error(`${state} registration is treated as activated`);
    clean(failedHarness, failedReg, [failedWorker]);
    failedWorker.state = 'activated'; failedReg.active = failedWorker; failedWorker.dispatch('statechange');
    clean(failedHarness, failedReg, [failedWorker]);
  }
  const failedReg = new Target(); Object.defineProperty(failedReg, 'active', { get() { throw new DOMException('synthetic', 'InvalidStateError'); } });
  const failedHarness = fixture(failedReg); let thrown = false;
  try { await failedHarness.api.readyRegistration(failedReg); } catch (_) { thrown = true; }
  if (!thrown) throw new Error('native registration inspection failure is swallowed');
  clean(failedHarness, failedReg, []);
  console.log('PASS  canonical worker readiness covers activation, updatefound, redundant, timeout and native failure with cleanup');
}


async function pendingPermissionCases() {
  const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
  const drain = async () => { for (let index = 0; index < 20; index++) await Promise.resolve(); };
  const manualClock = () => {
    let nextId = 0; const timers = new Map();
    return {
      setTimeout(callback, delay) { const id = ++nextId; timers.set(id, { callback, delay }); return id; },
      clearTimeout(id) { timers.delete(id); },
      expirePermission() { const pending = [...timers].filter(([, timer]) => timer.delay === 15000); if (pending.length !== 1) throw new Error('permission UI wait has no single bounded timeout'); const [id, timer] = pending[0]; timers.delete(id); timer.callback(); },
    };
  };
  for (const outcome of ['granted', 'denied', 'rejected']) {
    const clock = manualClock(); let native = deferred();
    const harness = activationHarness({ permission: 'default', permissionRequest: () => native.promise, clock });
    const first = harness.enable(); await drain();
    if (harness.calls.permission !== 1 || !harness.permissionDuringGesture()) throw new Error(`${outcome} permission request is not made once within the click`);
    clock.expirePermission();
    if (await first !== false || !harness.root.innerHTML.includes('Этап: Разрешение браузера') || !harness.root.innerHTML.includes('Код: TIMEOUT') || !harness.root.innerHTML.includes('Повторить') || harness.root.innerHTML.includes('disabled')) throw new Error(`${outcome} pending permission does not restore bounded actionable diagnosis`);
    const retry = harness.enable(); await drain();
    if (harness.calls.permission !== 1 || harness.calls.register || harness.calls.key || harness.calls.subscribe || harness.calls.save) throw new Error(`${outcome} retry starts another native permission operation or continues before permission settles`);
    if (outcome === 'rejected') native.reject(new DOMException(secretMarker, 'NotAllowedError')); else native.resolve(outcome);
    const result = await retry;
    if (outcome === 'granted') {
      if (result !== true || harness.calls.register !== 1 || harness.calls.key !== 1 || harness.calls.subscribe !== 1 || harness.calls.save !== 1 || harness.calls.confirmation !== 1 || !harness.root.innerHTML.includes('Уведомления включены')) throw new Error('shared granted permission does not finish exactly one setup');
    } else {
      if (result !== false || harness.calls.register || harness.calls.key || harness.calls.subscribe || harness.calls.save || harness.root.innerHTML.includes(secretMarker)) throw new Error(`${outcome} permission continues setup or exposes raw details`);
      native = deferred(); const fresh = harness.enable(); await drain();
      if (harness.calls.permission !== 2 || !harness.permissionDuringGesture()) throw new Error(`${outcome} settled native permission flight blocks a fresh direct-click request`);
      native.resolve('granted');
      if (await fresh !== true || harness.calls.subscribe !== 1 || harness.calls.save !== 1) throw new Error(`${outcome} permission rejection poisons later setup`);
    }
  }
  const changedClock = manualClock(), changedNative = deferred();
  const changed = activationHarness({ permission: 'default', permissionRequest: () => changedNative.promise, clock: changedClock });
  const obsolete = changed.enable(); await drain(); changed.changeSession(); changedNative.resolve('granted');
  if (await obsolete !== false || changed.calls.register || changed.calls.key || changed.calls.subscribe || changed.calls.save || changed.calls.confirmation) throw new Error('permission granted after account change starts work for an obsolete session');
  let throwOnce = true;
  const synchronous = activationHarness({ permission: 'default', permissionRequest() { if (throwOnce) { throwOnce = false; throw new DOMException(secretMarker, 'NotAllowedError'); } return Promise.resolve('granted'); } });
  if (await synchronous.enable() !== false || !synchronous.permissionDuringGesture() || !synchronous.root.innerHTML.includes('Код: NotAllowedError') || synchronous.calls.register) throw new Error('synchronous permission error escapes direct-click diagnosis');
  if (await synchronous.enable() !== true || synchronous.calls.permission !== 2 || synchronous.calls.subscribe !== 1 || synchronous.calls.save !== 1) throw new Error('synchronous permission error leaves a stuck permission flight');
  console.log('PASS  permission UI timeout reuses pending native work; settlement, session changes and synchronous failures retain safe retry');
}

async function pendingSetupCases() {
  const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
  const drain = async () => { for (let index = 0; index < 100; index++) await Promise.resolve(); };
  const manualClock = () => {
    let nextId = 0; const timers = new Map();
    return {
      setTimeout(callback, delay) { const id = ++nextId; timers.set(id, { callback, delay }); return id; },
      clearTimeout(id) { timers.delete(id); },
      expireSetup() { for (const [id, timer] of [...timers]) if (timer.delay >= 10000) { timers.delete(id); timer.callback(); } },
      get setupTimers() { return [...timers.values()].filter(timer => timer.delay >= 10000).length; },
    };
  };
  const baselineFailures = [];
  const body = deferred(), clock = manualClock();
  const key = activationHarness({ clock, keyBody: attempt => attempt === 1 ? body.promise : { ok: true, public_key: 'AQ' } });
  let keyResult = null;
  key.enable().then(value => { keyResult = value; }); await drain();
  clock.expireSetup(); await drain();
  if (keyResult !== false || !key.root.innerHTML.includes('Этап: Получение настроек') || !key.root.innerHTML.includes('Код: TIMEOUT') || key.root.innerHTML.includes('disabled')) baselineFailures.push('key body remains pending beyond its bounded setup wait');
  const confirmation = deferred(), confirmationClock = manualClock();
  const pending = activationHarness({ clock: confirmationClock, confirmationRequest: () => confirmation.promise });
  let confirmationResult = null;
  const enabled = pending.enable(); enabled.then(value => { confirmationResult = value; });
  const joined = pending.enable(); await drain();
  if (confirmationResult !== true || !pending.root.innerHTML.includes('Уведомления включены') || pending.root.innerHTML.includes('disabled')) baselineFailures.push('saved setup still waits for optional native confirmation');
  if (baselineFailures.length) throw new Error(baselineFailures.join('; '));
  if (key.calls.subscribe || key.calls.save || key.calls.confirmation || clock.setupTimers || !key.keySignals[0]?.aborted) throw new Error('key body timeout does not abort owned HTTP cleanly before any native mutation');
  if (await key.enable() !== true) throw new Error('key body timeout prevents a fresh explicit retry');
  body.resolve({ ok: true, public_key: 'AQ' }); await drain();
  if (key.calls.key !== 2 || key.calls.subscribe !== 1 || key.calls.save !== 1 || key.calls.confirmation !== 1 || !key.root.innerHTML.includes('Уведомления включены')) throw new Error('late key body repeats or overwrites the completed retry');
  if (await joined !== true || await enabled !== true || pending.calls.subscribe !== 1 || pending.calls.save !== 1 || pending.calls.confirmation !== 1 || confirmationClock.setupTimers) throw new Error('concurrent setup observers repeat subscription, save or optional confirmation');
  pending.changeSession(); const beforeLate = { ...pending.calls }, beforeHtml = pending.root.innerHTML;
  confirmation.reject(new DOMException(secretMarker, 'NotAllowedError')); await drain();
  if (JSON.stringify(pending.calls) !== JSON.stringify(beforeLate) || pending.root.innerHTML !== beforeHtml) throw new Error('late confirmation changes a new session or repeats setup');
  console.log('PASS  whole key body is bounded and abortable; saved setup completes without waiting for optional native confirmation');

  const changedBody = deferred(), changed = activationHarness({ keyBody: () => changedBody.promise, clock: manualClock() });
  const obsolete = changed.enable(); await drain(); changed.changeSession(); changedBody.resolve({ ok: true, public_key: 'AQ' });
  if (await obsolete !== false || changed.calls.subscribe || changed.calls.save || changed.calls.confirmation) throw new Error('late key body starts a subscription for a changed account');
  for (const status of [401, 403]) {
    const httpClock = manualClock(), http = activationHarness({ failAt: 'key', httpStatus: status, keyBody: () => new Promise(() => {}), clock: httpClock });
    let result = null; http.enable().then(value => { result = value; }); await drain(); httpClock.expireSetup(); await drain();
    if (result !== false || http.calls.subscribe || http.calls.save || http.root.innerHTML.includes(secretMarker)) throw new Error(`HTTP ${status} key body does not settle safely`);
    if (status === 401 ? http.calls.auth !== 1 || http.root.innerHTML.includes('notificationDiagnostic') : !http.root.innerHTML.includes('Код: HTTP_403')) throw new Error(`HTTP ${status} key body loses authorization or safe status diagnosis`);
  }
  for (const keyBody of [() => Promise.reject(new SyntaxError(secretMarker)), () => ({ ok: true }), () => null]) {
    const invalid = activationHarness({ keyBody });
    if (await invalid.enable() !== false || !invalid.root.innerHTML.includes('Код: KEY_RESPONSE') || invalid.calls.subscribe || invalid.calls.save || invalid.root.innerHTML.includes(secretMarker)) throw new Error('invalid key JSON changes setup or exposes raw response details');
  }
  for (const confirmationRequest of [() => Promise.reject(new Error(secretMarker)), () => { throw new Error(secretMarker); }]) {
    const failed = activationHarness({ confirmationRequest });
    if (await failed.enable() !== true || !failed.root.innerHTML.includes('Уведомления включены') || failed.calls.subscribe !== 1 || failed.calls.save !== 1 || failed.calls.confirmation !== 1 || failed.root.innerHTML.includes(secretMarker)) throw new Error('optional confirmation failure hides a saved healthy setup');
  }
  console.log('PASS  key auth, invalid JSON and late account changes remain safe; optional confirmation failures never undo saved setup');
}

async function notificationNavigationCases(){
  const workerSource=fs.readFileSync(require('path').join(__dirname,'../manager/sw.js'),'utf8');
  const origin='https://app.anytoour.ru',failures=[];let total=0;
  async function check(name,run){total++;try{await run();console.log('PASS  notification navigation '+name);}catch(error){failures.push(name+': '+error.message);console.error('FAIL  notification navigation '+name+': '+error.message);}}
  async function click(definitions,{url='./',id=711,matchFails=false}={}){
    const handlers={},calls=[],messages=[],fallbacks=[];
    const clients=definitions.map(def=>{
      const client={url:new URL(def.path,origin).href,postMessage(data){if(def.postFails)throw Error('synthetic closed client');messages.push({path:def.path,...data});calls.push('post:'+def.path);}};
      if(!def.noFocus)client.focus=async()=>{calls.push('focus:'+def.path);if(def.focusFails)throw Error('synthetic focus rejection');if(def.changedPath)client.url=new URL(def.changedPath,origin).href;return client;};
      return client;
    });
    vm.runInNewContext(workerSource,{URL,importScripts(){},self:{location:{href:origin+'/manager/sw.js'},clients:{
      async matchAll(options){assert.equal(options.type,'window');assert.equal(options.includeUncontrolled,true);if(matchFails)throw Error('synthetic client lookup error');return clients;},
      async openWindow(target){fallbacks.push(new URL(target,origin+'/manager/').href);}
    },addEventListener(type,fn){handlers[type]=fn;}}},{filename:'sw.js'});
    let wait,closed=0;handlers.notificationclick({notification:{data:{conversationId:id,url},close(){closed++;}},waitUntil(p){wait=p;}});
    await wait;assert.equal(closed,1);return{calls,messages,fallbacks};
  }
  for(const paths of [
    ['/manager2/','/manager/push-enable.php'],['/manager/push-enable.php','/manager2/'],['/manager2/admin.php','/manager2/'],
    ['/manager/index.php'],['/manager2/index.php?view=mine#old'],['/manager2/','/manager/'],['/manager/','/manager2/'],
    ['https://other.example.test/manager2/','/manager2/'],['/manager2-unsafe/','/manager/'],['/manager2/nested/','/manager/']
  ])await check('selects only a workspace from '+paths.join(' then '),async()=>{
    const r=await click(paths.map(path=>({path}))),expected=paths.find(path=>/^\/manager2?\/(?:index\.php(?:[?#].*)?)?$/.test(path));
    assert.equal(r.messages.length,1);assert.equal(r.messages[0].path,expected);assert.equal(r.messages[0].conversationId,711);assert.deepEqual(r.fallbacks,[]);assert.equal(r.calls.filter(c=>c.startsWith('focus:')).length,1);
  });
  for(const first of [{focusFails:true},{postFails:true},{noFocus:true},{changedPath:'/manager/push-enable.php'}])await check('unavailable candidate '+Object.keys(first)[0]+' falls through once',async()=>{
    const r=await click([{path:'/manager2/',...first},{path:'/manager/index.php'}]);assert.equal(r.messages.length,1);assert.equal(r.messages[0].path,'/manager/index.php');assert.deepEqual(r.fallbacks,[]);
  });
  for(const definitions of [[],[{path:'/manager/push-enable.php'}],[{path:'/manager2/admin.php'},{path:'/manager/routing.php'}],[{path:'/manager2/',focusFails:true}]])await check('one fallback for '+JSON.stringify(definitions),async()=>{
    const r=await click(definitions);assert.deepEqual(r.messages,[]);assert.deepEqual(r.fallbacks,[origin+'/manager/#conversation=711']);
  });
  for(const url of ['/manager2/index.php?selection=mine#old','https://other.example.test/manager/','/manager/push-enable.php','javascript:alert(1)'])await check('fallback URL boundary '+url,async()=>{
    const r=await click([],{url});assert.deepEqual(r.messages,[]);assert.deepEqual(r.fallbacks,[url.startsWith('/manager2/index.php')?origin+'/manager2/index.php?selection=mine#conversation=711':origin+'/manager/#conversation=711']);
  });
  await check('failed client lookup still has one canonical fallback',async()=>{const r=await click([],{matchFails:true});assert.deepEqual(r.fallbacks,[origin+'/manager/#conversation=711']);});
  for(const id of [0,-1,1.5,Infinity,'not-an-id'])await check('invalid conversation '+id+' only focuses the workspace',async()=>{const r=await click([{path:'/manager2/'}],{id});assert.equal(r.messages.length,0);assert.deepEqual(r.fallbacks,[]);assert.deepEqual(r.calls,['focus:/manager2/']);});

  for(const mode of ['held-register','rejected-register','held-ready','rejected-ready','ready'])await check('receiver is independent of '+mode,async()=>{
    let resolve,reject;const pending=new Promise((yes,no)=>{resolve=yes;reject=no;}),opens=[];let bound=0,registrations=0;
    const worker=new EventTarget();worker.state='installing';
    const registration=mode.includes('ready')&&mode!=='ready'?{active:null,installing:worker,addEventListener(){},removeEventListener(){}}:{active:{state:'activated'}};
    const serviceWorker=new EventTarget(),add=serviceWorker.addEventListener.bind(serviceWorker);
    serviceWorker.addEventListener=(type,fn,...rest)=>{if(type==='message')bound++;return add(type,fn,...rest);};
    serviceWorker.register=()=>{registrations++;return mode.includes('register')?pending:Promise.resolve(registration);};
    const doc=new EventTarget();doc.readyState='complete';doc.getElementById=()=>null;
    const local={window:{WorkspaceV2:{S:{},openNotificationConversation:id=>opens.push(id)},WorkspaceV2Conversation:{open:id=>opens.push(id)}},document:doc,navigator:{serviceWorker},URL,setTimeout,clearTimeout,console:{warn(){}}};
    vm.runInNewContext(source,local,{filename:'workspace-v2-notifications.js'});
    const init=local.window.WorkspaceV2Notifications.init();await Promise.resolve();if(mode==='ready')await init;
    const emit=(type,id)=>{const event=new Event('message');event.data={type,conversationId:id};serviceWorker.dispatchEvent(event);};
    try{emit('OPEN_CONVERSATION',711);assert.deepEqual(opens,[711]);assert.equal(bound,1);}
    finally{
      if(mode==='rejected-register')reject(Error('synthetic registration error'));else resolve(registration);
      if(mode==='held-ready'||mode==='rejected-ready'){worker.state=mode==='held-ready'?'activated':'redundant';if(mode==='held-ready')registration.active=worker;worker.dispatchEvent(new Event('statechange'));}
      await init;
    }
    await local.window.WorkspaceV2Notifications.init();assert.equal(bound,1);assert.equal(registrations,1);assert.deepEqual(opens,[711]);
    emit('OTHER',712);emit('OPEN_CONVERSATION',712);assert.deepEqual(opens,[711,712]);
  });
  for(const blocked of [false,true])await check('unavailable worker does not prevent module loading, restricted='+blocked,async()=>{
    const doc=new EventTarget();doc.readyState='complete';doc.getElementById=()=>null;const navigator={};
    if(blocked)Object.defineProperty(navigator,'serviceWorker',{get(){throw new DOMException('Synthetic restricted worker','SecurityError');}});
    const local={window:{WorkspaceV2:{S:{}}},document:doc,navigator,URL,setTimeout,clearTimeout,console:{warn(){}}};vm.runInNewContext(source,local);assert.equal(typeof local.window.WorkspaceV2Notifications.init,'function');await local.window.WorkspaceV2Notifications.init();
  });
  console.log('NAVIGATION TOTAL '+total+' | FAIL '+failures.length);if(failures.length)throw Error(failures.join('; '));
}

async function run() {
  const manager2 = activationHarness({ outsideScope: true });
  if (await manager2.enable() !== true || manager2.calls.ready !== 0 || manager2.calls.subscribe !== 1 || manager2.calls.save !== 1) throw new Error('/manager2 must enable through its canonical active registration while global serviceWorker.ready remains pending');
  console.log('PASS  outside-scope manager2 enables through its canonical registration without global ready');

  if (typeof listeners.click !== 'function') {
    throw new Error('push setup click handler depends on the late workspace init');
  }

  listeners.click({
    preventDefault() {},
    target: { closest(selector) { return selector === '[data-enable-push]' ? {} : null; } },
  });
  await new Promise(resolve => setTimeout(resolve, 0));

  if (!root.innerHTML.includes('Этот браузер не поддерживает уведомления')) {
    throw new Error('unsupported browser reason is not visible after the click');
  }
  if (!root.innerHTML.includes('Telegram или MAX') || !/(?:не внутри|вне) Telegram или MAX/.test(root.innerHTML)) {
    throw new Error('unsupported browser recovery guidance is not visible');
  }
  if (!root.innerHTML.includes('Повторить')) {
    throw new Error('failed setup does not restore an actionable button');
  }
  if (root.innerHTML.includes('Смена без уведомлений')) {
    throw new Error('generic shift warning masks the concrete setup failure');
  }

  console.log('PASS  unsupported clients keep an active setup button and show the concrete recovery path');

  const savedListeners = {};
  const savedRoot = { className: '', innerHTML: '' };
  const savedDocument = {
    ...document,
    addEventListener(type, callback) { savedListeners[type] = callback; },
    getElementById(id) { return id === 'notificationStatus' ? savedRoot : null; },
  };
  let confirmationCount = 0;
  const subscription = { toJSON() { return { endpoint: 'https://push.example.test/anna', keys: { p256dh: 'key', auth: 'auth' } }; } };
  const registration = {
    scope: 'https://app.anytoour.ru/manager/', active: { state: 'activated' },
    pushManager: {
      async getSubscription() { return null; },
      async subscribe() { return subscription; },
    },
    async showNotification() { confirmationCount++; },
  };
  const serviceWorker = {
    ready: Promise.resolve(registration),
    async register() { return registration; },
    addEventListener() {},
  };
  const responses = [
    { ok: true, status: 200, async json() { return { ok: true, public_key: 'AQ' }; } },
    { ok: true, status: 200, async json() { return { ok: true, push_status: { is_working: true, notification_path_usable: false, notification_path_reason: 'subscription_unhealthy' },current_subscription:{notification_path_usable:false,notification_path_reason:'subscription_unhealthy'} }; } },
  ];
  const Notification = { permission: 'granted', async requestPermission() { return 'granted'; } };
  const savedProtocol=lifecycle(7);
  const savedContext = {
    localStorage:savedProtocol.localStorage,crypto:savedProtocol.crypto,
    document: savedDocument,
    navigator: { serviceWorker },
    window: { WorkspaceV2: { S: { csrf: 'test', manager: { id: 7 }, authGeneration: 0 } }, PushManager: function(){}, Notification },
    Notification,
    fetch:async(url,options)=>{
      if(url==='push.php?action=key')return responses[0];
      if(url==='push-status.php')return{ok:true,status:200,json:async()=>({ok:true,current_subscription:{notification_path_usable:false,notification_path_reason:'no_subscription'},current_connection:savedProtocol.current()})};
      const body=JSON.parse(options.body);return{ok:true,status:200,json:async()=>({...savedProtocol.reply(body),...(body.action==='subscribe'?await responses[1].json():{})})};
    },
    atob: value => Buffer.from(value, 'base64').toString('binary'),
    console: { warn() {} },
    setTimeout,
    clearTimeout,
    Uint8Array,
    URL,
  };
  vm.runInNewContext(source, savedContext, { filename: 'workspace-v2-notifications.js' });
  savedListeners.click({ preventDefault() {}, target: { closest(selector) { return selector==='[data-enable-push]'?{}:null; } } });
  await new Promise(resolve => setTimeout(resolve, 10));
  if (!savedRoot.innerHTML.includes('Не удалось завершить подключение уведомлений') || !savedRoot.innerHTML.includes('Повторить')) {
    throw new Error('unusable canonical server status is silently treated as successful setup');
  }
  if (confirmationCount !== 0 || savedRoot.innerHTML.includes('Уведомления включены')) {
    throw new Error('unusable canonical server status shows a false success confirmation');
  }
  console.log('PASS  unusable canonical server status remains an actionable setup failure');
  await activationDiagnosticCases();
  await pendingPermissionCases();
  await pendingSetupCases();
  await registrationLifecycleCases();
  await currentBrowserCases();
  await notificationNavigationCases();
}

run().catch(error => {
  console.error(`FAIL  ${error.message}`);
  process.exit(1);
});
