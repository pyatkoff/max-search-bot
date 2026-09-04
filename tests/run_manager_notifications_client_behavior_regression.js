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
}

run().catch(error => {
  console.error(`FAIL  ${error.message}`);
  process.exit(1);
});
