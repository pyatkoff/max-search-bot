const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function storage() {
  const values = new Map();
  return {
    values,
    getItem: key => values.get(key) || null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: key => values.delete(key),
  };
}

function element(value = '') {
  const listeners = new Map();
  return {
    value,
    textContent: '',
    className: '',
    disabled: false,
    isConnected: true,
    classList: { add() {}, remove() {} },
    addEventListener: (name, handler) => listeners.set(name, handler),
    emit: (name, event = {}) => listeners.get(name)?.(event),
    focus() {},
  };
}

function root() {
  const state = { elements: new Map() };
  const reset = () => state.elements = new Map([
    ['#leadTaskAdd', element()],
    ['#leadTaskTitle', element()],
    ['#leadTaskDue', element()],
    ['#leadTaskCreateStatus', element()],
  ]);
  reset();
  return {
    state,
    set innerHTML(value) { this.markup = value; reset(); },
    get innerHTML() { return this.markup || ''; },
    querySelector: selector => state.elements.get(selector) || null,
    querySelectorAll: () => [],
  };
}

function harness({ createOk = true, createResult = null, createError = false, refreshRenders = true, refreshThrows = false, savedStorage = null, managerId = 5, authExpired = false } = {}) {
  const saved = savedStorage || storage();
  const taskRoot = root();
  let createCalls = 0;
  const document = {
    querySelectorAll: () => [],
    createElement: () => ({
      textContent: '',
      get innerHTML() { return String(this.textContent); },
    }),
  };
  const W = {
    S: { current: 101, manager: managerId ? { id: managerId } : null, authExpired, authGeneration: 0, detail: { lead: { pipeline: { outcome: { outcome: 'open' } } } } },
    pipe: async action => {
      assert.equal(action, 'create_task');
      createCalls++;
      if (createError) throw new Error('connection lost');
      return createResult || { ok: createOk };
    },
  };
  const window = { WorkspaceV2: W };
  const context = vm.createContext({ window, document, sessionStorage: saved, console, Date, setTimeout });
  for (const file of ['manager-time.js','workspace-v2-tasks.js', 'workspace-v2-task-draft.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../manager/assets', file), 'utf8'), context, { filename: file });
  }
  W.WorkspaceV2Tasks = window.WorkspaceV2Tasks;
  W.WorkspaceV2TaskDraft = window.WorkspaceV2TaskDraft;
  window.WorkspaceV2Conversation = {
    refreshLeadData: async ({ conversationId }) => {
      assert.equal(conversationId, 101);
      if (refreshThrows) throw new Error('read failed after confirmed create');
      if (refreshRenders) window.WorkspaceV2Tasks.render(taskRoot, { tasks: [], canEdit: true, conversationId });
      return refreshRenders;
    },
  };
  window.WorkspaceV2Inbox = { load: async () => true };
  window.WorkspaceV2Tasks.render(taskRoot, { tasks: [], canEdit: true, conversationId: 101 });
  return { saved, taskRoot, window, W, createCalls: () => createCalls, render: conversationId => window.WorkspaceV2Tasks.render(taskRoot, { tasks: [], canEdit: true, conversationId: conversationId ?? W.S.current }) };
}

async function enterAndSubmit(h, title = 'Позвонить туристу') {
  const input = h.taskRoot.querySelector('#leadTaskTitle');
  input.value = title;
  input.emit('input');
  assert.equal(JSON.parse(h.saved.getItem(h.window.WorkspaceV2TaskDraft.keyFor(101))).title, title);
  await h.taskRoot.querySelector('#leadTaskAdd').onclick();
}

(async () => {
  const success = harness();
  await enterAndSubmit(success);
  assert.equal(success.createCalls(), 1);
  assert.equal(success.saved.getItem('workspaceV2.taskDraft.5.101'), null, 'successful create must clear persisted draft');
  assert.equal(success.taskRoot.querySelector('#leadTaskTitle').value, '', 'refresh must not restore the submitted draft');
  console.log('PASS  successful task creation clears the persisted draft before refresh restoration');

  const staleView = harness({ refreshRenders: false });
  await enterAndSubmit(staleView, 'Не создавать повторно');
  assert.equal(staleView.saved.getItem('workspaceV2.taskDraft.5.101'), null);
  assert.equal(staleView.taskRoot.querySelector('#leadTaskTitle').value, '', 'successful create clears visible fields even if refresh fails');
  console.log('PASS  successful task creation clears visible fields when the follow-up refresh fails');

  const failure = harness({ createOk: false });
  await enterAndSubmit(failure, 'Сохранить после ошибки');
  assert.equal(failure.createCalls(), 1);
  assert.equal(JSON.parse(failure.saved.getItem('workspaceV2.taskDraft.5.101')).title, 'Сохранить после ошибки');
  assert.equal(failure.taskRoot.querySelector('#leadTaskTitle').value, 'Сохранить после ошибки');
  console.log('PASS  failed task creation retains the exact retryable draft');

  for (const options of [{ createError: true }, { createResult: { ok: false, error: 'invalid_response' } }, { createResult: { ok: false, error: 'stale_session' } }, { createResult: { ok: false, error: 'save_failed', http_status: 500 } }]) {
    const unknown = harness(options);
    await enterAndSubmit(unknown, 'Проверить перед повтором');
    assert.equal(JSON.parse(unknown.saved.getItem('workspaceV2.taskDraft.5.101')).title, 'Проверить перед повтором');
    assert.equal(unknown.taskRoot.querySelector('#leadTaskTitle').value, 'Проверить перед повтором');
    assert.match(unknown.taskRoot.querySelector('#leadTaskCreateStatus').textContent, /Не удалось подтвердить/);
    assert.match(unknown.taskRoot.querySelector('#leadTaskCreateStatus').className, /unknown/);
    assert.equal(unknown.createCalls(), 1, 'unknown create must not automatically retry');
  }
  console.log('PASS  uncertain create preserves the draft and asks for reconciliation without retrying');

  const rejectedRead = harness({ refreshThrows: true });
  await enterAndSubmit(rejectedRead, 'Подтверждённая задача');
  assert.equal(rejectedRead.saved.getItem('workspaceV2.taskDraft.5.101'), null);
  assert.equal(rejectedRead.taskRoot.querySelector('#leadTaskTitle').value, '');
  assert.equal(rejectedRead.taskRoot.querySelector('#leadTaskCreateStatus').textContent, 'Задача добавлена');
  console.log('PASS  a rejected follow-up read does not turn confirmed creation into an uncertain mutation');

  const incomplete = harness();
  incomplete.saved.setItem('workspaceV2.taskDraft.5.101', JSON.stringify({ title: 'Не стирать без подтверждения' }));
  const draftKey = incomplete.window.WorkspaceV2TaskDraft.beginCreate(101);
  incomplete.window.WorkspaceV2TaskDraft.finishCreate(draftKey, undefined);
  assert.equal(JSON.parse(incomplete.saved.getItem(draftKey)).title, 'Не стирать без подтверждения');
  console.log('PASS  undefined completion cannot clear an unconfirmed task draft');

  const ownerA = harness();
  const ownerATitle = ownerA.taskRoot.querySelector('#leadTaskTitle');
  ownerATitle.value = 'Черновик Светланы'; ownerATitle.emit('input');
  const ownerADue = ownerA.taskRoot.querySelector('#leadTaskDue');
  ownerADue.value = '2026-10-14T18:30'; ownerADue.emit('change');
  const keyA = ownerA.window.WorkspaceV2TaskDraft.keyFor(101);
  assert.equal(keyA, 'workspaceV2.taskDraft.5.101');
  const reloadedA = harness({ savedStorage: ownerA.saved });
  assert.equal(reloadedA.taskRoot.querySelector('#leadTaskTitle').value, 'Черновик Светланы');
  assert.equal(reloadedA.taskRoot.querySelector('#leadTaskDue').value, '2026-10-14T18:30');
  const unknownOwner = harness({ savedStorage: ownerA.saved, managerId: 0 });
  assert.equal(unknownOwner.taskRoot.querySelector('#leadTaskTitle').value, '');
  assert.equal(unknownOwner.window.WorkspaceV2TaskDraft.read(keyA), null);
  const ownerB = harness({ savedStorage: ownerA.saved, managerId: 6 });
  assert.equal(ownerB.taskRoot.querySelector('#leadTaskTitle').value, '', 'same conversation never restores another manager draft');
  assert.equal(ownerB.window.WorkspaceV2TaskDraft.read(keyA), null, 'public draft read remains account gated');
  ownerB.window.WorkspaceV2TaskDraft.clear(keyA);
  const titleB = ownerB.taskRoot.querySelector('#leadTaskTitle');
  titleB.value = 'Черновик Анны'; titleB.emit('input');
  assert.equal(JSON.parse(ownerA.saved.getItem(keyA)).title, 'Черновик Светланы');
  assert.equal(JSON.parse(ownerA.saved.getItem('workspaceV2.taskDraft.6.101')).title, 'Черновик Анны');
  const restoredA = harness({ savedStorage: ownerA.saved });
  assert.equal(restoredA.taskRoot.querySelector('#leadTaskTitle').value, 'Черновик Светланы');
  console.log('PASS  reload restores the owner draft while two accounts sharing a conversation retain separate drafts');

  const expiring = harness({ savedStorage: ownerA.saved });
  const staleInput = expiring.taskRoot.querySelector('#leadTaskTitle');
  expiring.W.S.authExpired = true;
  staleInput.value = 'Не записывать из истёкшей сессии'; staleInput.emit('input');
  assert.equal(expiring.window.WorkspaceV2TaskDraft.keyFor(101), '');
  assert.equal(expiring.window.WorkspaceV2TaskDraft.read(keyA), null);
  expiring.window.WorkspaceV2TaskDraft.clear(keyA);
  assert.equal(JSON.parse(ownerA.saved.getItem(keyA)).title, 'Черновик Светланы');
  expiring.W.S.authExpired = false; expiring.W.S.authGeneration++;
  expiring.render();
  assert.equal(expiring.taskRoot.querySelector('#leadTaskTitle').value, 'Черновик Светланы');
  const lateKey = expiring.window.WorkspaceV2TaskDraft.beginCreate(101);
  expiring.W.S.manager = { id: 6 };
  expiring.window.WorkspaceV2TaskDraft.finishCreate(lateKey, true);
  assert.equal(JSON.parse(ownerA.saved.getItem(keyA)).title, 'Черновик Светланы', 'late callback cannot clear a draft under another account');
  assert.equal(JSON.parse(ownerA.saved.getItem('workspaceV2.taskDraft.6.101')).title, 'Черновик Анны');
  console.log('PASS  expired-session access is blocked and same-manager recovery preserves the owned draft');

  const legacyStorage = storage();
  legacyStorage.setItem('workspaceV2.taskDraft.101', JSON.stringify({ title: 'Неподтверждённый старый владелец' }));
  for (const managerId of [0, 5, 6]) {
    const legacy = harness({ savedStorage: legacyStorage, managerId });
    assert.equal(legacy.taskRoot.querySelector('#leadTaskTitle').value, '');
    assert.equal(legacy.window.WorkspaceV2TaskDraft.read('workspaceV2.taskDraft.101'), null);
  }
  assert.equal(JSON.parse(legacyStorage.getItem('workspaceV2.taskDraft.101')).title, 'Неподтверждённый старый владелец', 'unattributed legacy data is not silently deleted or reassigned');
  console.log('PASS  legacy unattributed drafts remain untouched and cannot leak into a guessed account');

  const explicitLead = harness();
  explicitLead.render(202);
  const explicitTitle = explicitLead.taskRoot.querySelector('#leadTaskTitle');
  explicitTitle.value = 'Другой исходный лид'; explicitTitle.emit('input');
  assert.equal(JSON.parse(explicitLead.saved.getItem('workspaceV2.taskDraft.5.202')).title, 'Другой исходный лид');
  assert.equal(explicitLead.saved.getItem('workspaceV2.taskDraft.5.101'), null);
  console.log('PASS  draft binding uses the explicit rendered conversation rather than a stale global selection');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
