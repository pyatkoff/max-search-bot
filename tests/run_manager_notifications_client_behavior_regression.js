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
function activationHarness({ failAt = '', failure = new Error(secretMarker), httpStatus = 0, permission = 'granted', permissionResult = 'granted', working = false, healthReason = 'manager_inactive' } = {}) {
  const localRoot = { className: '', innerHTML: '' };
  const calls = { permission: 0, register: 0, ready: 0, key: 0, read: 0, repair: 0, subscribe: 0, save: 0, confirmation: 0, status: 0, auth: 0 };
  let userGesture = false, permissionDuringGesture = false;
  const step = name => { calls[name]++; if (failAt === name) throw failure; };
  const healthy = { is_working: working, notification_path_usable: true, notification_path_reason: 'healthy_subscription', healthy_subscription_count: 1 };
  const subscription = { toJSON() { return { endpoint: `https://push.example.test/${secretMarker}`, keys: { p256dh: secretMarker, auth: secretMarker } }; } };
  const staleSubscription = { ...subscription, options: { applicationServerKey: new Uint8Array([2]) }, async unsubscribe() { step('repair'); return true; } };
  const registration = {
    pushManager: {
      async getSubscription() { step('read'); return failAt === 'repair' ? staleSubscription : null; },
      async subscribe() { step('subscribe'); return subscription; },
    },
    async showNotification() { step('confirmation'); },
  };
  const serviceWorker = {
    async register() { step('register'); return registration; },
    get ready() { step('ready'); return Promise.resolve(registration); },
    addEventListener() {},
  };
  const notification = { permission, async requestPermission() { permissionDuringGesture = userGesture; step('permission'); return permissionResult; } };
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
      return { ok: status === 200, status, async json() { return status === 200 ? name === 'key' ? { ok: true, public_key: 'AQ' } : { ok: true, push_status: healthy } : { ok: false, error: secretMarker }; } };
    },
    atob: value => Buffer.from(value, 'base64').toString('binary'),
    console: { warn() {} },
    setTimeout(callback, delay) { return delay === 500 ? 0 : setTimeout(callback, delay); },
    clearTimeout,
    Uint8Array,
  };
  vm.runInNewContext(source, localContext, { filename: 'workspace-v2-notifications.js' });
  return {
    root: localRoot, calls,
    api: localContext.window.WorkspaceV2Notifications,
    async enable() { userGesture = true; const result = this.api.enable(localRoot); userGesture = false; return result; },
    clearFailure() { failAt = ''; },
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
    if (!harness.root.innerHTML.includes('notificationDiagnostic')) throw new Error(`${failAt} diagnosis cannot remain visible on a small off-shift screen`);
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

async function run() {
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
  if (!savedRoot.innerHTML.includes('Не удалось сохранить подписку') || !savedRoot.innerHTML.includes('Повторить')) {
    throw new Error('unusable canonical server status is silently treated as successful setup');
  }
  if (confirmationCount !== 0 || savedRoot.innerHTML.includes('Уведомления включены')) {
    throw new Error('unusable canonical server status shows a false success confirmation');
  }
  console.log('PASS  unusable canonical server status remains an actionable setup failure');
  await activationDiagnosticCases();
}

run().catch(error => {
  console.error(`FAIL  ${error.message}`);
  process.exit(1);
});
