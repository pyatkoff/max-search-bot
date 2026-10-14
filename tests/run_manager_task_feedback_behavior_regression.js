const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function classes() {
  const values = new Set();
  return { values, add: (...names) => names.forEach(name => values.add(name)), remove: (...names) => names.forEach(name => values.delete(name)) };
}

function element(options = {}) {
  return {
    value: '', textContent: '', className: '', disabled: false, checked: false, indeterminate: false,
    isConnected: true, dataset: {}, type: 'button', classList: classes(), attributes: {},
    setAttribute(name, value) { this.attributes[name] = value; },
    addEventListener() {}, focus() {}, ...options,
  };
}

const fixtureTask = { id: 77, title: 'Позвонить туристу', status: 'open', due_state: 'today', due_at_utc: '2026-10-14T07:00:00Z', assigned_manager_name: 'Светлана' };

function root() {
  const r = { elements: new Map(), controls: {}, rows: [] };
  Object.defineProperty(r, 'innerHTML', {
    set(markup) {
      for (const old of r.elements.values()) old.isConnected = false;
      for (const old of Object.values(r.controls)) old.isConnected = false;
      r.markup = markup;
      const feedback = markup.match(/class="taskActionStatus([^"]*)"[^>]*>([^<]*)<\/div>/);
      const status = element({ className: 'taskActionStatus' + (feedback?.[1] || ''), textContent: feedback?.[2] || '' });
      const row = element();
      row.querySelector = () => null;
      const toggle = element({ type: 'checkbox', dataset: { taskToggle: '77' }, closest: () => row });
      const pin = element({ dataset: { taskPin: '77', pinned: '0' }, closest: () => row });
      const snooze = element({ dataset: { taskId: '77', taskSnooze: 'quarter' }, closest: () => row });
      r.controls = { toggle, pin, snooze };
      r.rows = [row];
      r.elements = new Map([
        ['[data-task-action-status]', status], ['#leadTaskTitle', element()], ['#leadTaskDue', element()],
        ['#leadTaskAdd', element()], ['#leadTaskCreateStatus', element()],
      ]);
      const create = element();
      create.querySelector = selector => r.elements.get(selector) || null;
      r.elements.set('.taskCreate', create);
    },
    get() { return r.markup || ''; },
  });
  r.querySelector = selector => r.elements.get(selector) || null;
  r.querySelectorAll = selector => ({
    '[data-task-toggle]': [r.controls.toggle], '[data-task-pin]': [r.controls.pin],
    '[data-task-snooze]': [r.controls.snooze],
  }[selector] || []);
  return r;
}

function harness({ response = { ok: true }, networkError = false, refresh = 'render', pending = false } = {}) {
  const taskRoot = root(), calls = [];
  let resolveRequest;
  const deferred = pending ? new Promise(resolve => { resolveRequest = resolve; }) : null;
  const W = {
    S: { current: 101, manager: { id: 5 }, authGeneration: 0, authExpired: false, detail: { lead: { pipeline: { outcome: { outcome: 'open' } } } } },
    pipe: async (action, payload) => {
      calls.push({ action, payload });
      if (networkError) throw new Error('connection lost after send');
      return deferred || response;
    },
  };
  const document = {
    querySelector: selector => taskRoot.querySelector(selector),
    querySelectorAll: selector => taskRoot.querySelectorAll(selector),
    createElement: () => ({ textContent: '', get innerHTML() { return String(this.textContent).replaceAll('&', '&amp;').replaceAll('<', '&lt;'); } }),
  };
  const window = {
    WorkspaceV2: W,
    WorkspaceV2TaskPresets: { dateForPreset: () => new Date('2026-10-14T12:15:00Z') },
  };
  const context = vm.createContext({ window, document, console, Date, setTimeout });
  for (const file of ['manager-time.js', 'workspace-v2-tasks.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../manager/assets', file), 'utf8'), context, { filename: file });
  }
  const render = (conversationId = W.S.current) => window.WorkspaceV2Tasks.render(taskRoot, { tasks: [fixtureTask], canEdit: true, conversationId });
  window.WorkspaceV2Conversation = {
    refreshLeadData: async ({ conversationId }) => {
      assert.equal(conversationId, 101, 'mutation must refresh its source conversation');
      if (refresh === 'throw') throw new Error('read failed after mutation was confirmed');
      if (refresh === 'render' && W.S.current === conversationId) render(conversationId);
      return refresh === 'render';
    },
  };
  render();
  return { taskRoot, W, window, calls, render, resolveRequest, status: () => taskRoot.querySelector('[data-task-action-status]') };
}

async function trigger(h, kind) {
  const control = h.taskRoot.controls[kind];
  if (kind === 'toggle') { control.checked = true; return control.onchange(); }
  return control.onclick();
}

(async () => {
  for (const [kind, phrase, action] of [
    ['toggle', 'выполнена', 'set_task_completed'], ['pin', 'добавлена в приоритет', 'set_task_pinned'], ['snooze', 'перенесён', 'update_task'],
  ]) {
    const h = harness();
    await trigger(h, kind);
    assert.equal(h.calls.length, 1);
    assert.equal(h.calls[0].action, action);
    assert.equal(h.calls[0].payload.conversation_id, 101);
    assert.match(h.status().textContent, new RegExp(phrase));
    assert.match(h.status().className, /success/);
    h.render();
    assert.match(h.status().textContent, new RegExp(phrase), 'confirmation must survive task/lead rerender');
  }
  console.log('PASS  completion, pin and snooze show persistent confirmed feedback across rerender');

  const rejected = harness({ response: { ok: false, error: 'task_not_found', http_status: 400 } });
  const rejectedControl = rejected.taskRoot.controls.toggle;
  await trigger(rejected, 'toggle');
  assert.equal(rejectedControl.checked, false, 'a known rejection restores the last confirmed completion display');
  assert.equal(rejectedControl.indeterminate, false);
  assert.equal(rejectedControl.disabled, false);
  assert.match(rejected.status().className, /error/);
  rejected.render();
  assert.match(rejected.status().className, /error/, 'failure does not vanish after a brief border flash');
  console.log('PASS  known rejection restores completion display and retains readable failure');

  for (const options of [{ networkError: true }, { response: { ok: false, error: 'invalid_response' } }, { response: { ok: false, error: 'stale_session' } }, { response: { ok: false, error: 'save_failed', http_status: 500 } }, { response: null }]) {
    const h = harness(options);
    const control = h.taskRoot.controls.toggle;
    await trigger(h, 'toggle');
    assert.equal(control.indeterminate, true, 'unknown completion cannot imply confirmed success or rollback');
    assert.equal(control.disabled, false);
    assert.match(h.status().textContent, /Не удалось подтвердить/);
    assert.match(h.status().textContent, /Обновите список/);
    assert.match(h.status().className, /unknown/);
    assert.equal(h.calls.length, 1, 'uncertain mutation must not automatically retry');
  }
  console.log('PASS  lost and malformed responses show an unknown outcome without claiming rollback or retrying');

  const forbidden = harness({ response: { ok: false, error: 'forbidden', http_status: 403 } });
  const forbiddenControl = forbidden.taskRoot.controls.toggle;
  await trigger(forbidden, 'toggle');
  assert.equal(forbiddenControl.checked, false);
  assert.equal(forbiddenControl.indeterminate, false);
  assert.match(forbidden.status().className, /error/);
  console.log('PASS  valid 400 and 403 rejections remain distinct from uncertain server failures');

  const refreshFailed = harness({ refresh: 'throw' });
  await trigger(refreshFailed, 'pin');
  assert.match(refreshFailed.status().className, /success/);
  assert.match(refreshFailed.status().textContent, /добавлена в приоритет/);
  console.log('PASS  failed follow-up read preserves a server-confirmed mutation outcome');

  const pending = harness({ pending: true, refresh: 'none' });
  const control = pending.taskRoot.controls.pin;
  const first = trigger(pending, 'pin');
  assert.equal(control.disabled, true);
  assert.match(pending.status().className, /pending/);
  await control.onclick();
  assert.equal(pending.calls.length, 1, 'in-flight duplicate control click is guarded');
  pending.W.S.current = 202;
  pending.render(202);
  pending.resolveRequest({ ok: true });
  await first;
  assert.equal(pending.status().textContent, '', 'late completion does not change another conversation status');
  pending.W.S.current = 101;
  pending.render(101);
  assert.match(pending.status().textContent, /добавлена в приоритет/);
  console.log('PASS  in-flight guards and source-conversation feedback survive navigation');

  const changedIdentity = harness({ pending: true, refresh: 'none' });
  const oldAction = trigger(changedIdentity, 'pin');
  changedIdentity.W.S.manager = { id: 6 };
  changedIdentity.render(101);
  changedIdentity.resolveRequest({ ok: true });
  await oldAction;
  assert.equal(changedIdentity.status().textContent, '', 'previous account feedback cannot appear after identity recovery');
  console.log('PASS  feedback and late results are isolated by manager account');

  const recoveredSession = harness({ pending: true, refresh: 'none' });
  const oldSessionAction = trigger(recoveredSession, 'toggle');
  recoveredSession.W.S.authGeneration++;
  recoveredSession.render(101);
  recoveredSession.resolveRequest({ ok: false, error: 'stale_session' });
  await oldSessionAction;
  assert.equal(recoveredSession.status().textContent, '', 'late result cannot paint a fresh session of the same account');
  recoveredSession.render(101);
  assert.equal(recoveredSession.status().textContent, '', 'discarded session feedback cannot return on rerender');
  console.log('PASS  same-account authentication recovery discards old-generation notices');

  const visits = harness({ refresh: 'none' });
  await trigger(visits, 'pin');
  for (let id = 1000; id < 1205; id++) { visits.W.S.current = id; visits.render(id); }
  visits.W.S.current = 101; visits.render(101);
  assert.equal(visits.status().textContent, '', 'feedback outside the last 200 visited leads is discarded');
  console.log('PASS  task feedback and DOM references are bounded to recent lead visits');

  for (const manager2 of [false, true]) {
    const h = harness();
    const title = h.taskRoot.querySelector('#leadTaskTitle');
    title.value = 'Не потерять черновик';
    if (manager2) h.taskRoot.elements.set('.m2TaskCancel', element());
    assert.equal(h.window.WorkspaceV2Tasks.blockNavigationForDirtyDraft(), true);
    const message = h.taskRoot.querySelector('#leadTaskCreateStatus').textContent;
    assert.match(message, manager2 ? /нажмите «Отмена»/ : /очистите черновик/);
    assert.equal(h.calls.length, 0);
  }
  console.log('PASS  dirty-draft recovery names the actual Manager2 cancel action and preserves legacy copy');
})().catch(error => { console.error(error); process.exit(1); });
