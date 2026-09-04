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
  await context.window.WorkspaceV2Notifications.init();
  if (typeof listeners.click !== 'function') {
    throw new Error('push setup click handler was not attached without service worker support');
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
}

run().catch(error => {
  console.error(`FAIL  ${error.message}`);
  process.exit(1);
});
