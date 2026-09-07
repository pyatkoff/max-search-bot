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

function harness({ createOk = true, refreshRenders = true } = {}) {
  const saved = storage();
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
    S: { current: 101, detail: { lead: { pipeline: { outcome: { outcome: 'open' } } } } },
    pipe: async action => {
      assert.equal(action, 'create_task');
      createCalls++;
      return { ok: createOk };
    },
  };
  const window = { WorkspaceV2: W };
  const context = vm.createContext({ window, document, sessionStorage: saved, console, Date, setTimeout });
  for (const file of ['workspace-v2-tasks.js', 'workspace-v2-task-draft.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../manager/assets', file), 'utf8'), context, { filename: file });
  }
  W.WorkspaceV2Tasks = window.WorkspaceV2Tasks;
  W.WorkspaceV2TaskDraft = window.WorkspaceV2TaskDraft;
  window.WorkspaceV2Conversation = {
    refreshLeadData: async ({ conversationId }) => {
      assert.equal(conversationId, 101);
      if (refreshRenders) window.WorkspaceV2Tasks.render(taskRoot, { tasks: [], canEdit: true, conversationId });
      return refreshRenders;
    },
  };
  window.WorkspaceV2Inbox = { load: async () => true };
  window.WorkspaceV2Tasks.render(taskRoot, { tasks: [], canEdit: true, conversationId: 101 });
  return { saved, taskRoot, window, createCalls: () => createCalls };
}

async function enterAndSubmit(h, title = 'Позвонить туристу') {
  const input = h.taskRoot.querySelector('#leadTaskTitle');
  input.value = title;
  input.emit('input');
  assert.equal(JSON.parse(h.saved.getItem('workspaceV2.taskDraft.101')).title, title);
  await h.taskRoot.querySelector('#leadTaskAdd').onclick();
}

(async () => {
  const success = harness();
  await enterAndSubmit(success);
  assert.equal(success.createCalls(), 1);
  assert.equal(success.saved.getItem('workspaceV2.taskDraft.101'), null, 'successful create must clear persisted draft');
  assert.equal(success.taskRoot.querySelector('#leadTaskTitle').value, '', 'refresh must not restore the submitted draft');
  console.log('PASS  successful task creation clears the persisted draft before refresh restoration');

  const staleView = harness({ refreshRenders: false });
  await enterAndSubmit(staleView, 'Не создавать повторно');
  assert.equal(staleView.saved.getItem('workspaceV2.taskDraft.101'), null);
  assert.equal(staleView.taskRoot.querySelector('#leadTaskTitle').value, '', 'successful create clears visible fields even if refresh fails');
  console.log('PASS  successful task creation clears visible fields when the follow-up refresh fails');

  const failure = harness({ createOk: false });
  await enterAndSubmit(failure, 'Сохранить после ошибки');
  assert.equal(failure.createCalls(), 1);
  assert.equal(JSON.parse(failure.saved.getItem('workspaceV2.taskDraft.101')).title, 'Сохранить после ошибки');
  assert.equal(failure.taskRoot.querySelector('#leadTaskTitle').value, 'Сохранить после ошибки');
  console.log('PASS  failed task creation retains the exact retryable draft');
})().catch(error => {
  console.error(error);
  process.exit(1);
});
