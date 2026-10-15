const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(require('path').join(__dirname, '..', 'manager', 'assets', 'workspace-v2-notifications.js'), 'utf8');
const listeners = {};
const root = { className: '', innerHTML: '' };
const document = {
  readyState: 'loading',
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
const context = {
  document,
  navigator: {},
  window: { WorkspaceV2: { S: { csrf: 'test' } } },
  console: { warn() {} },
  setTimeout,
  clearTimeout,
  Uint8Array,
};

vm.runInNewContext(source, context, { filename: 'workspace-v2-notifications.js' });

const secretMarker = 'PRIVATE_ENDPOINT_TOKEN_DO_NOT_DISPLAY';
function activationHarness({ failAt = '', failure = new Error(secretMarker), httpStatus = 0, permission = 'granted', permissionResult = 'granted', working = false, healthReason = 'manager_inactive', outsideScope = false, permissionRequest = null, keyBody = null, confirmationRequest = null, clock = null } = {}) {
  const localRoot = { className: '', innerHTML: '' };
  const calls = { permission: 0, register: 0, ready: 0, key: 0, read: 0, repair: 0, subscribe: 0, save: 0, confirmation: 0, status: 0, auth: 0 };
  const keySignals = [];
  let userGesture = false, permissionDuringGesture = false;
  const step = name => { calls[name]++; if (failAt === name) throw failure; };
  const healthy = { is_working: working, notification_path_usable: true, notification_path_reason: 'healthy_subscription', healthy_subscription_count: 1 };
  const subscription = { toJSON() { return { endpoint: `https://push.example.test/${secretMarker}`, keys: { p256dh: secretMarker, auth: secretMarker } }; } };
  const staleSubscription = { ...subscription, options: { applicationServerKey: new Uint8Array([2]) }, async unsubscribe() { step('repair'); return true; } };
  const registration = {
    scope: 'https://app.anytoour.ru/manager/',
    get active() { if (failAt === 'ready') { calls.ready++; throw failure; } return { state: 'activated' }; },
    pushManager: {
      async getSubscription() { step('read'); return failAt === 'repair' ? staleSubscription : null; },
      async subscribe() { step('subscribe'); return subscription; },
    },
    showNotification() { step('confirmation'); return confirmationRequest ? confirmationRequest() : Promise.resolve(); },
  };
  const serviceWorker = {
    async register() { step('register'); return registration; },
    get ready() { step('ready'); return outsideScope ? new Promise(() => {}) : Promise.resolve(registration); },
    addEventListener() {},
  };
  const notification = { permission, requestPermission() { permissionDuringGesture = userGesture; step('permission'); return permissionRequest ? permissionRequest() : Promise.resolve(permissionResult); } };
  const localDocument = { ...document, addEventListener() {}, getElementById(id) { return id === 'notificationStatus' ? localRoot : null; } };
  const localContext = {
    document: localDocument,
    navigator: { serviceWorker },
    window: { WorkspaceV2: { S: { csrf: secretMarker }, showAuthRecovery() { calls.auth++; } }, PushManager: function(){}, Notification: notification },
    Notification: notification,
    async fetch(url, options) {
      if (url === 'push-status.php') {
        calls.status++;
        return { ok: true, status: 200, async json() { return { ok: true, push_status: { is_working: working, notification_path_usable: false, notification_path_reason: healthReason } }; } };
      }
      const name = options?.method === 'POST' ? 'save' : 'key';
      if (!(failAt === name && httpStatus)) step(name); else calls[name]++;
      const status = failAt === name && httpStatus ? httpStatus : 200;
      if (name === 'key') keySignals.push(options?.signal);
      return { ok: status === 200, status, async json() { if (name === 'key' && keyBody) return keyBody(calls.key); return status === 200 ? name === 'key' ? { ok: true, public_key: 'AQ' } : { ok: true, push_status: healthy } : { ok: false, error: secretMarker }; } };
    },
    atob: value => Buffer.from(value, 'base64').toString('binary'),
    console: { warn() {} },
    setTimeout(callback, delay) { if (clock) return clock.setTimeout(callback, delay); return delay === 500 ? 0 : setTimeout(callback, outsideScope && delay >= 10000 ? 10 : delay); },
    clearTimeout(id) { if (clock) clock.clearTimeout(id); else clearTimeout(id); },
    Uint8Array,
    AbortController,
  };
  vm.runInNewContext(source, localContext, { filename: 'workspace-v2-notifications.js' });
  return {
    root: localRoot, calls, keySignals,
    api: localContext.window.WorkspaceV2Notifications,
    async enable() { userGesture = true; const result = this.api.enable(localRoot); userGesture = false; return result; },
    clearFailure() { failAt = ''; },
    changeSession() { localContext.window.WorkspaceV2.S.csrf = 'different-session'; },
    permissionDuringGesture() { return permissionDuringGesture; },
  };
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
    if (!harness.root.innerHTML.includes('Повторить') || harness.root.innerHTML.includes('disabled')) throw new Error(`${failAt} does not restore the retry button`);
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
  if (recovering.calls.subscribe !== 1 || recovering.calls.save !== 0 || recovering.calls.permission !== 0 || recovering.calls.confirmation !== 0 || recovering.root.innerHTML.includes('notificationDiagnostic') || recovering.api.maybeRecoverGranted(recovering.root) !== false) throw new Error('background recovery repeats, prompts permission or exposes interactive diagnosis');
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
      window: { WorkspaceV2: { S: { csrf: 'synthetic' } } },
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
  if (!root.innerHTML.includes('не внутри Telegram или MAX')) {
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
    { ok: true, status: 200, async json() { return { ok: true, push_status: { is_working: true, notification_path_usable: false, notification_path_reason: 'subscription_unhealthy' } }; } },
  ];
  const Notification = { permission: 'granted', async requestPermission() { return 'granted'; } };
  const savedContext = {
    document: savedDocument,
    navigator: { serviceWorker },
    window: { WorkspaceV2: { S: { csrf: 'test' } }, PushManager: function(){}, Notification },
    Notification,
    fetch: async () => responses.shift(),
    atob: value => Buffer.from(value, 'base64').toString('binary'),
    console: { warn() {} },
    setTimeout,
    clearTimeout,
    Uint8Array,
  };
  vm.runInNewContext(source, savedContext, { filename: 'workspace-v2-notifications.js' });
  savedListeners.click({ preventDefault() {}, target: { closest() { return {}; } } });
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
}

run().catch(error => {
  console.error(`FAIL  ${error.message}`);
  process.exit(1);
});
