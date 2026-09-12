const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../manager/assets/workspace-v2-inbox.js'), 'utf8');

function harness(overrides = {}, result = {ok: false, http_status: 500}) {
  const S = {queue: 'mine', leadProjectFilter: 'anytour', leadSourceFilter: 0, leadStageFilter: '', leadTagFilter: 0, leadOutcomeFilter: '', leadTaskFilter: '', leadSearch: '', ...overrides};
  const calls = [];
  const element = () => ({children: [], dataset: {}, attrs: {}, className: '', innerHTML: '', classList: {toggle() {}}, replaceChildren(...children) {this.children = children;}, appendChild(child) {this.children.push(child);}, setAttribute(key, value) {this.attrs[key] = value;}});
  const list = element(); list.innerHTML = '<existing-filtered-lead>';
  const status = element();
  const elements = {inboxList: list, inboxLoadStatus: status};
  const context = {
    document: {createElement: element, querySelector: selector => selector === '#inboxList .leadItem' ? {} : null},
    window: {WorkspaceV2: {S, $: id => elements[id] || null, pipe: async () => typeof result === 'function' ? result() : result, api: async (action, payload) => {calls.push({action, payload}); return {ok: true, conversations: [{id: 999, display_name: 'Unrelated lead'}]};}}},
  };
  vm.runInNewContext(source, context);
  return {S, calls, list, status, inbox: context.window.WorkspaceV2Inbox};
}

(async () => {
  for (const filter of [
    {leadSearch: 'Анна'}, {leadTaskFilter: 'overdue'}, {leadOutcomeFilter: 'won'},
    {leadStageFilter: 'selection'}, {leadTagFilter: 7}, {leadSourceFilter: 5}, {leadSourceFilter: -1},
  ]) {
    const h = harness(filter);
    await assert.rejects(h.inbox.fetchRows(), {message: 'inbox_list_failed'}, 'failed filtered request must not return an unfiltered list: ' + JSON.stringify(filter));
    assert.equal(h.calls.length, 0, 'unsupported filtered request must not use legacy list');
  }
  const basic = harness();
  assert.equal((await basic.inbox.fetchRows())[0].id, 999, 'unfiltered My queue still has basic fallback');
  assert.equal(basic.calls[0].payload.project_key, 'anytour', 'fallback preserves the original project');
  for (const status of [401, 403, 422]) {
    const h = harness({}, {ok: false, http_status: status});
    await assert.rejects(h.inbox.fetchRows(), {message: 'inbox_list_failed'});
    assert.equal(h.calls.length, 0, 'auth/client errors must not trigger fallback');
  }
  const otherQueue = harness({queue: 'waiting'});
  await assert.rejects(otherQueue.inbox.fetchRows(), {message: 'inbox_list_failed'});
  assert.equal(otherQueue.calls.length, 0, 'other queues must not fall back to My');
  const primary = harness({leadSearch: 'Анна'}, {ok: true, conversations: []});
  assert.equal((await primary.inbox.fetchRows()).length, 0, 'successful empty filtered result remains empty');
  assert.equal(primary.calls.length, 0);
  const stale = harness({leadTaskFilter: 'overdue'});
  assert.equal(await stale.inbox.load(), false, 'filtered refresh failure is reported');
  assert.equal(stale.list.innerHTML, '<existing-filtered-lead>', 'existing filtered cards are retained');
  assert.equal(stale.list.dataset.stale, 'true', 'retained data is labelled stale');
  assert.match(stale.status.children[0].textContent, /Показаны последние загруженные данные/);
  assert.equal(stale.status.children[1].textContent, 'Повторить');
  assert.equal(stale.list.attrs['aria-busy'], 'false');
  let resolve;
  const delayed = harness({}, () => new Promise(r => {resolve = r;}));
  const request = delayed.inbox.fetchRows();
  delayed.S.leadProjectFilter = 'another-project';
  resolve({ok: false, http_status: 500});
  await request;
  assert.equal(delayed.calls[0].payload.project_key, 'anytour', 'late fallback uses the original request scope');
  const filtered = harness({leadSearch: 'Анна'}, () => new Promise(r => {resolve = r;}));
  const filteredRequest = filtered.inbox.fetchRows();
  filtered.S.leadSearch = '';
  resolve({ok: false, http_status: 500});
  await assert.rejects(filteredRequest, {message: 'inbox_list_failed'});
  assert.equal(filtered.calls.length, 0, 'changing controls does not loosen an in-flight filtered request');
  console.log('Inbox fallback behavior passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
