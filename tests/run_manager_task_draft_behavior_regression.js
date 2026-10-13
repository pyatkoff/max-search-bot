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
    userInput(value, name = 'input') { if (this.disabled) return false; this.value = value; this.emit(name); return true; },
    click() { if (this.disabled) return false; return this.onclick?.(); },
    focus() {},
  };
}

function root() {
  const state = { elements: new Map() };
  const reset = () => {
    for (const old of state.elements.values()) old.isConnected = false;
    state.elements = new Map([
    ['#leadTaskAdd', element()],
    ['#leadTaskTitle', element()],
    ['#leadTaskDue', element()],
    ['#leadTaskCreateStatus', element()],
    ['.m2TaskCancel', element()],
  ]);
  };
  reset();
  return {
    state,
    set innerHTML(value) { this.markup = value; reset(); },
    get innerHTML() { return this.markup || ''; },
    querySelector: selector => state.elements.get(selector) || null,
    querySelectorAll: () => [],
  };
}

function harness({ createOk = true, createResult = null, createError = false, refreshRenders = true, refreshThrows = false, savedStorage = null, managerId = 5, authExpired = false, pendingCreate = false, pendingRefresh = false } = {}) {
  const saved = savedStorage || storage();
  const taskRoot = root();
  let createCalls = 0;
  let resolveCreate, resolveRefresh, enterRefresh;
  const createPromise = pendingCreate ? new Promise(resolve => { resolveCreate = resolve; }) : null;
  const refreshPromise = pendingRefresh ? new Promise(resolve => { resolveRefresh = resolve; }) : null;
  const refreshEntered = new Promise(resolve => { enterRefresh = resolve; });
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
      if (createPromise) return createPromise;
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
      enterRefresh();
      if (refreshPromise) await refreshPromise;
      return refreshRenders;
    },
  };
  window.WorkspaceV2Inbox = { load: async () => true };
  window.WorkspaceV2Tasks.render(taskRoot, { tasks: [], canEdit: true, conversationId: 101 });
  return { saved, taskRoot, window, W, resolveCreate, resolveRefresh, refreshEntered, createCalls: () => createCalls, render: conversationId => window.WorkspaceV2Tasks.render(taskRoot, { tasks: [], canEdit: true, conversationId: conversationId ?? W.S.current }) };
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
  assert.equal(JSON.parse(incomplete.saved.getItem(draftKey.key)).title, 'Не стирать без подтверждения');
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

  for (const refreshRenders of [false, true]) {
    const locked = harness({ pendingCreate: true, pendingRefresh: true, refreshRenders });
    const input = locked.taskRoot.querySelector('#leadTaskTitle');
    input.userInput('Задача A');
    locked.taskRoot.querySelector('#leadTaskDue').userInput('2026-10-14T18:30', 'change');
    const submission = locked.taskRoot.querySelector('#leadTaskAdd').onclick();
    for (const selector of ['#leadTaskTitle', '#leadTaskDue', '#leadTaskAdd', '.m2TaskCancel']) assert.equal(locked.taskRoot.querySelector(selector).disabled, true);
    assert.equal(input.userInput('Черновик B'), false, 'ordinary input cannot replace a draft while its create is pending');
    assert.equal(locked.taskRoot.querySelector('.m2TaskCancel').click(), false, 'cancel cannot clear a pending create draft');
    await locked.taskRoot.querySelector('#leadTaskAdd').onclick();
    assert.equal(locked.createCalls(), 1);
    assert.equal(JSON.parse(locked.saved.getItem('workspaceV2.taskDraft.5.101')).title, 'Задача A');
    locked.resolveCreate({ ok: true });
    await locked.refreshEntered;
    for (const selector of ['#leadTaskTitle', '#leadTaskDue', '#leadTaskAdd', '.m2TaskCancel']) assert.equal(locked.taskRoot.querySelector(selector).disabled, true, 'a rerender stays locked until the original mutation settles');
    assert.equal(locked.taskRoot.querySelector('#leadTaskTitle').userInput('Черновик B'), false);
    await locked.taskRoot.querySelector('#leadTaskAdd').onclick();
    assert.equal(locked.createCalls(), 1);
    locked.resolveRefresh();
    await submission;
    for (const selector of ['#leadTaskTitle', '#leadTaskDue', '#leadTaskAdd', '.m2TaskCancel']) assert.equal(locked.taskRoot.querySelector(selector).disabled, false);
    assert.equal(locked.taskRoot.querySelector('#leadTaskTitle').value, '');
    assert.equal(locked.saved.getItem('workspaceV2.taskDraft.5.101'), null);
    assert.equal(locked.taskRoot.querySelector('#leadTaskTitle').userInput('Черновик B'), true);
    assert.equal(JSON.parse(locked.saved.getItem('workspaceV2.taskDraft.5.101')).title, 'Черновик B');
  }
  console.log('PASS  pending create locks title, due, cancel and duplicate submit across both refresh outcomes');

  const uncertainPending = harness({ pendingCreate: true, refreshRenders: false });
  uncertainPending.taskRoot.querySelector('#leadTaskTitle').userInput('Проверить задачу A');
  const uncertainSubmission = uncertainPending.taskRoot.querySelector('#leadTaskAdd').onclick();
  uncertainPending.resolveCreate({ ok: false, error: 'invalid_response' });
  await uncertainSubmission;
  assert.equal(uncertainPending.taskRoot.querySelector('#leadTaskTitle').disabled, false);
  assert.equal(uncertainPending.taskRoot.querySelector('.m2TaskCancel').disabled, false);
  assert.equal(uncertainPending.taskRoot.querySelector('#leadTaskTitle').value, 'Проверить задачу A');
  assert.equal(JSON.parse(uncertainPending.saved.getItem('workspaceV2.taskDraft.5.101')).title, 'Проверить задачу A');
  assert.equal(uncertainPending.createCalls(), 1);
  console.log('PASS  unknown pending create restores controls while keeping A without automatic retry');

  const recoveredCreate = harness({ pendingRefresh: true, refreshRenders: false });
  recoveredCreate.taskRoot.querySelector('#leadTaskTitle').userInput('Подтверждённая задача A');
  const oldSubmission = recoveredCreate.taskRoot.querySelector('#leadTaskAdd').onclick();
  await recoveredCreate.refreshEntered;
  recoveredCreate.W.S.authGeneration++;
  recoveredCreate.render();
  assert.equal(recoveredCreate.taskRoot.querySelector('#leadTaskTitle').disabled, false);
  recoveredCreate.taskRoot.querySelector('#leadTaskTitle').userInput('Новый черновик B');
  recoveredCreate.render();
  assert.equal(recoveredCreate.taskRoot.querySelector('#leadTaskTitle').value, 'Новый черновик B', 'older-generation pending create cannot suppress fresh draft restoration');
  recoveredCreate.resolveRefresh();
  await oldSubmission;
  assert.equal(JSON.parse(recoveredCreate.saved.getItem('workspaceV2.taskDraft.5.101')).title, 'Новый черновик B', 'acknowledged old create cannot clear a newer-generation draft');
  assert.equal(recoveredCreate.taskRoot.querySelector('#leadTaskTitle').value, 'Новый черновик B');
  console.log('PASS  acknowledged create with a late read cannot erase a same-account recovered-session draft');

  for (const oldFinishesFirst of [true, false]) {
    const overlap = harness({ pendingRefresh: true, refreshRenders: false });
    overlap.taskRoot.querySelector('#leadTaskTitle').userInput('Задача A прежней сессии');
    const a = overlap.taskRoot.querySelector('#leadTaskAdd').onclick();
    await overlap.refreshEntered;
    overlap.W.S.authGeneration++;
    overlap.render();
    overlap.taskRoot.querySelector('#leadTaskTitle').userInput('Задача B новой сессии');
    let resolveB;
    const bResponse = new Promise(resolve => { resolveB = resolve; });
    overlap.W.pipe = async action => { assert.equal(action, 'create_task'); return bResponse; };
    overlap.window.WorkspaceV2Conversation.refreshLeadData = async () => false;
    const b = overlap.taskRoot.querySelector('#leadTaskAdd').onclick();
    if (oldFinishesFirst) {
      overlap.resolveRefresh(); await a;
      overlap.render();
      assert.equal(overlap.taskRoot.querySelector('#leadTaskTitle').value, '', 'old completion must leave the newer create pending suppression intact');
      assert.equal(overlap.taskRoot.querySelector('#leadTaskTitle').disabled, true);
      assert.equal(JSON.parse(overlap.saved.getItem('workspaceV2.taskDraft.5.101')).title, 'Задача B новой сессии');
      resolveB({ ok: true }); await b;
      assert.equal(overlap.saved.getItem('workspaceV2.taskDraft.5.101'), null, 'only the matching fresh create may clear B');
      assert.equal(overlap.taskRoot.querySelector('#leadTaskTitle').disabled, false);
    } else {
      resolveB({ ok: true }); await b;
      overlap.taskRoot.querySelector('#leadTaskTitle').userInput('Следующий черновик C');
      overlap.resolveRefresh(); await a;
      assert.equal(JSON.parse(overlap.saved.getItem('workspaceV2.taskDraft.5.101')).title, 'Следующий черновик C', 'old completion after new completion cannot erase the next draft');
      assert.equal(overlap.taskRoot.querySelector('#leadTaskTitle').value, 'Следующий черновик C');
    }
  }
  console.log('PASS  overlapping session creates settle safely in either order without clearing newer drafts or pending guards');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
