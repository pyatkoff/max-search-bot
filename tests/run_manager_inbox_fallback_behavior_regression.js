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
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../manager/assets/manager-time.js'),'utf8'),context);
  vm.runInNewContext(source, context);
  return {S, calls, list, status, inbox: context.window.WorkspaceV2Inbox};
}

// Synthetic layout exposes actual scroll assignments separately from the final
// position: assigning the same value can interrupt a native browser gesture.
function renderHarness(overrides={}) {
  let top = 0;
  const scrollWrites = [];
  class Element {
    constructor() { this.children=[];this.dataset={};this.className='';this.textContent='';this.parentNode=null;this.classList={toggle(){}}; }
    setAttribute() {}
    removeAttribute() {}
    get firstChild() { return this.children[0] || null; }
    get nextSibling() { return this.parentNode?.children[this.parentNode.children.indexOf(this)+1] || null; }
    get isConnected() { return this===list || !!this.parentNode?.isConnected; }
    get scrollHeight() { return this.children.length*40; }
    get clientHeight() { return 80; }
    get scrollTop() { return top=Math.max(0,Math.min(top,this.scrollHeight-this.clientHeight)); }
    set scrollTop(value) { scrollWrites.push(value);top=Math.max(0,Math.min(value,this.scrollHeight-this.clientHeight)); }
    getBoundingClientRect() { const y=this===list?20:20+this.parentNode.children.indexOf(this)*40-list.scrollTop;return {top:y,bottom:y+(this===list?80:40)}; }
    querySelectorAll() { return this.children.filter(node=>node.className.startsWith('leadItem')&&node.dataset.conversationId); }
    querySelector(selector) { return selector==='.empty'?this.children.find(node=>node.className==='empty')||null:null; }
    contains(node) { return node===this||this.children.includes(node); }
    insertBefore(node,before) { node.remove();const index=before?this.children.indexOf(before):this.children.length;this.children.splice(index,0,node);node.parentNode=this; }
    remove() { if(this.parentNode){const siblings=this.parentNode.children;siblings.splice(siblings.indexOf(this),1);this.parentNode=null;} }
  }
  const list=new Element(),S={queue:'all',manager:{id:7,role:'manager'},authGeneration:1,...overrides};
  const document={activeElement:null,getElementById:id=>id==='inboxList'?list:null,querySelector:()=>null,createElement:()=>new Element()};
  const window={ManagerTime:{format:()=>''},WorkspaceV2:{S,$:document.getElementById,esc:String,statusText:String,formatWait:seconds=>seconds+' seconds'}};
  const context={window,document,Intl,Number,Map,Set,JSON};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../manager/assets/manager-time.js'),'utf8'),context);
  vm.runInNewContext(source,context);
  return {list,scrollWrites,S,inbox:window.WorkspaceV2Inbox,render:window.WorkspaceV2Inbox.renderList};
}

(async () => {
  const ai=renderHarness(),future=new Date(Date.now()+60000).toISOString().replace('T',' ').slice(0,19),baseAi={id:91,status:'manager',manager_id:7,ai_preparation_expires_at:future};
  for(const [state,label]of[['pending','AI готовится'],['ready','AI подготовлен'],['recommendation','AI: рекомендации']]){
    const metadata=ai.inbox.aiStatusMeta({...baseAi,ai_preparation_status:state});assert.equal(metadata.label,label);assert.ok(metadata.expires>Date.now());
    assert.match(ai.inbox.aiStatusChip({...baseAi,ai_preparation_status:state}),/актуальность проверим при открытии/);
    assert.doesNotMatch(ai.inbox.aiStatusChip({...baseAi,ai_preparation_status:state}),/<button|onclick|proposal|job_id/,'a status is inert metadata without a preview or action');
  }
  for(const changes of [{ai_preparation_status:'unknown'},{ai_preparation_status:'__proto__'},{ai_preparation_status:'constructor'},{ai_preparation_status:'<img src=x onerror=1>'},{ai_preparation_expires_at:'invalid'},{ai_preparation_expires_at:'2000-01-01 00:00:00'},{id:0},{delivery_failure_category:'suspended'},{manager_id:8},{status:'closed'}])assert.equal(ai.inbox.aiStatusMeta({...baseAi,ai_preparation_status:'ready',...changes}),null);
  ai.S.authExpired=true;assert.equal(ai.inbox.aiStatusMeta({...baseAi,ai_preparation_status:'ready'}),null);ai.S.authExpired=false;
  ai.S.manager={id:7,role:'admin'};for(const status of ['manager','waiting_manager','closed'])assert.equal(ai.inbox.aiStatusMeta({...baseAi,status,manager_id:8,ai_preparation_status:'ready'}).state,'ready','the owner can display authorized foreign cache metadata');
  ai.render([{...baseAi,ai_preparation_status:'pending'}]);const aiRow=ai.list.children[0];assert.match(aiRow.innerHTML,/AI готовится/);
  ai.render([{...baseAi,ai_preparation_status:'pending'}]);assert.equal(ai.list.children[0],aiRow,'an unchanged badge keeps the row DOM');
  ai.render([{...baseAi,ai_preparation_status:'ready'}]);assert.notEqual(ai.list.children[0],aiRow);assert.match(ai.list.children[0].innerHTML,/AI подготовлен/);
  ai.render([{...baseAi,ai_preparation_status:'ready',ai_preparation_expires_at:'2000-01-01 00:00:00'}]);assert.doesNotMatch(ai.list.children[0].innerHTML,/leadAiStatus/);
  const blocked=renderHarness({queue:'mine'}),row={id:71,display_name:'Synthetic blocked client',status:'manager',awaiting_first_reply:1,awaiting_manager_reply:1,wait_age_seconds:7200,delivery_failure_category:'suspended',last_text:'Клиент недоступен в MAX',next_task_title:'Позвонить по договорённости',next_task_overdue:true};
  blocked.render([row]);const card=blocked.list.children.find(node=>node.dataset.conversationId==='71');
  assert.doesNotMatch(card.className,/waitUrgent|waitWarn|needsReply/,'suspended recipients have no automatic reply/SLA visual urgency even with old contradictory flags');
  assert.doesNotMatch(card.innerHTML,/leadWaitCompact|Ждёт ответа|Срочно/);assert.match(card.innerHTML,/Клиент недоступен в MAX/);assert.match(card.innerHTML,/Позвонить по договорённости/);assert.match(card.innerHTML,/leadTaskCompact overdue/,'human task deadline stays visible');
  blocked.render([{...row,delivery_failure_category:null}]);const recoveredCard=blocked.list.children.find(node=>node.dataset.conversationId==='71');assert.match(recoveredCard.className,/waitUrgent/);assert.match(recoveredCard.innerHTML,/Ждёт ответа|Срочно/,'recovered recipients regain normal automatic urgency');
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

  const h=renderHarness(),rows=Array.from({length:10},(_,index)=>({id:index+1,display_name:'Synthetic client '+index,status:'manager'}));
  h.render(rows,{preserveScroll:false});h.list.scrollTop=80;h.scrollWrites.length=0;
  const retained=[...h.list.children],anchor=retained[2],anchorTop=anchor.getBoundingClientRect().top;
  h.render(rows,{preserveScroll:true});
  assert.deepEqual(h.list.children,retained,'unchanged rows keep their DOM identity');
  assert.equal(h.list.scrollTop,80);
  assert.deepEqual(h.scrollWrites,[],'an unchanged refresh must not assign even an equal scrollTop');
  const arrived={id:99,display_name:'New synthetic client',status:'manager'};
  h.render([arrived,...rows],{preserveScroll:true});
  assert.equal(h.list.children[3],anchor,'a new arrival retains the reading anchor');
  assert.equal(anchor.getBoundingClientRect().top,anchorTop,'a new arrival preserves the reading position');
  assert.deepEqual(h.scrollWrites,[120],'a real anchor displacement still writes its correction');
  h.scrollWrites.length=0;h.render(rows.slice(0,2),{preserveScroll:true});
  assert.equal(h.list.scrollTop,0,'a shorter list clamps the old position to its actual scroll range');
  assert.deepEqual(h.scrollWrites,[],'a clamped position does not need a redundant out-of-range write');
  h.render(rows,{preserveScroll:false});h.list.scrollTop=120;h.scrollWrites.length=0;
  h.render(rows,{preserveScroll:false});
  assert.equal(h.list.scrollTop,0,'explicit navigation still resets the list to the start');
  assert.deepEqual(h.scrollWrites,[0]);
  h.scrollWrites.length=0;h.render(rows,{preserveScroll:false});
  assert.deepEqual(h.scrollWrites,[],'explicit start is also a no-op when already at the start');
  console.log('Inbox fallback behavior passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
