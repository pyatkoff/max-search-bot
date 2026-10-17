const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// DOM behavior only: no layout, production session, transport, or credentials.
// App logic comes directly from the canonical production asset files.
function createHarness({storage, managerId=7, mobile=false, sessionHistory, inbox=false} = {}) {
const ids = new Map();
class Element {
  constructor(tag = 'div') {
    this.tagName = tag.toUpperCase(); this.children = []; this.handlers = {};
    this.dataset = {}; this.style = {}; this.value = ''; this.disabled = false;
    this._className = ''; this._text = ''; this.scrollHeight = 100;
    this.scrollTop = 0; this.clientHeight = 100;
    this.classList = {
      contains: name => this._className.split(/\s+/).includes(name),
      add: (...names) => { this._className = [...new Set([...this._className.split(/\s+/).filter(Boolean), ...names])].join(' '); },
      remove: (...names) => { this._className = this._className.split(/\s+/).filter(n => !names.includes(n)).join(' '); },
      toggle: (name, force) => {
        const enabled = force === undefined ? !this.classList.contains(name) : !!force;
        if (enabled) this.classList.add(name); else this.classList.remove(name);
        return enabled;
      }
    };
  }
  set id(value) { this._id = value; ids.set(value, this); }
  get id() { return this._id || ''; }
  set className(value) { this._className = value; }
  get className() { return this._className; }
  set textContent(value) { this.replaceChildren(); this._text = String(value); }
  get textContent() { return this._text; }
  set innerHTML(value) {
    this._html = String(value); this.replaceChildren();
    // Expose dynamically inserted controls by their real id, and preserve classes.
    // HTML semantics and any app state are not implemented by this harness.
    for (const match of this._html.matchAll(/<([a-z]+)\b([^>]*\bid="([^"]+)"[^>]*)>/g)) {
      const child = new Element(match[1]); child.id = match[3];
      child.className = match[2].match(/\bclass="([^"]*)"/)?.[1] || '';
      this.appendChild(child);
    }
  }
  get innerHTML() { return this._html || this._text; }
  get childNodes() { return this.children; }
  get parentNode() { return this.parentElement || null; }
  get firstChild() { return this.children[0] || null; }
  get nextSibling() { return this.parentNode?.children[this.parentNode.children.indexOf(this)+1] || null; }
  get isConnected() { return this===body || !!this._harnessRoot || !!this.parentNode?.isConnected; }
  appendChild(child) { return this.insertBefore(child,null); }
  insertBefore(child,before) {
    if(child===before)return child;
    if(child.tagName==='FRAGMENT'){for(const node of [...child.children])this.insertBefore(node,before);return child;}
    if(before && before.parentNode!==this)throw new Error('Reference node is not a child');
    child.remove();const index=before?this.children.indexOf(before):this.children.length;
    this.children.splice(index,0,child);child.parentElement=this;return child;
  }
  remove() { this._harnessRoot=false;if(this.parentNode){const siblings=this.parentNode.children;siblings.splice(siblings.indexOf(this),1);this.parentElement=null;} }
  replaceChildren(...children) { for(const node of [...this.children])node.remove();this._text='';for(const node of children)this.appendChild(node); }
  matches(selector) {
    const tag=selector.match(/^[a-z]+/i)?.[0],id=selector.match(/#([\w-]+)/)?.[1];
    if(tag && this.tagName!==tag.toUpperCase() || id && this.id!==id)return false;
    if([...selector.matchAll(/\.([\w-]+)/g)].some(m=>!this.classList.contains(m[1])))return false;
    return [...selector.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)].every(([,name,value])=>{
      const actual=name.startsWith('data-')?this.dataset[name.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]:this[name];
      return actual!==undefined&&(value===undefined||String(actual)===value);
    });
  }
  querySelectorAll(selector) {
    const [first,...rest]=selector.trim().split(/\s+/),found=[];
    for(const child of this.children){if(child.matches(first))found.push(...(rest.length?child.querySelectorAll(rest.join(' ')):[child]));found.push(...child.querySelectorAll(selector));}
    return [...new Set(found)];
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  contains(node) { return node===this||this.children.some(child=>child.contains(node)); }
  setAttribute(name, value) { this[name] = value; }
  removeAttribute(name) { delete this[name]; }
  addEventListener(name, handler) { (this.handlers[name] ||= []).push(handler); }
  async dispatch(name) { for (const fn of this.handlers[name] || []) await fn({preventDefault(){}}); }
  focus() {}
}
function add(id, tag = 'div', className = '') { const el = new Element(tag); el.id = id; el.className = className; el._harnessRoot=true;return el; }
const body = new Element('body'),documentEvents=new Map(),timers=[];
const document = {
  body,visibilityState:'visible',addEventListener:(name,fn)=>documentEvents.set(name,fn),querySelector:()=>null,
  getElementById: id => ids.get(id) || null,
  createElement: tag => new Element(tag),
  createDocumentFragment: () => new Element('fragment'),
  createTextNode: text => { const el = new Element('text'); el.textContent = text; return el; },
  querySelectorAll: selector => {
    if(selector==='#conversationActions button')return ids.get('conversationActions').children;
    const match=selector.match(/^#([\w-]+) (.+)$/);if(match)return ids.get(match[1])?.querySelectorAll(match[2])||[];
    return [...new Set([...ids.values(),body].filter(node=>node.isConnected).flatMap(node=>[...(node.matches(selector)?[node]:[]),...node.querySelectorAll(selector)]))];
  },
};
['inboxList','adminLink','managerName','conversationLoadStatus','conversationRefreshStatus','conversationActions',
 'conversationTitle','conversationAvatar','conversationState','conversationMeta',
 'deliveryFailure','composerLocked','replyStatus','conversationZone','messages','workspaceRoot','leadZone','mobileBack','mobileLeadBtn','mobileLeadClose'].forEach(id => add(id));
add('composer', 'form', 'composer hidden'); add('replyText', 'textarea'); add('replyFile', 'input'); add('sendReply', 'button');

const calls = [];
let hook = null;
let nextIdentity = null;
const manager = {id:managerId, role:'manager', login:'synthetic', display_name:'Synthetic manager', is_working:true};
const identity = {ok:true, csrf:'synthetic-csrf', manager, projects:[]};
const conversation = {id:101, manager_id:7, status:'manager', channel:'max', display_name:'Synthetic conversation'};
async function fetch(url, options) {
  const request = JSON.parse(options.body); calls.push({url,...request});
  if(hook){const result=await hook(url,request);if(result)return result;}
  let value;
  if (request.action === 'me' || request.action === 'login') value = nextIdentity || identity;
  else if (url === 'api.php' && request.action === 'detail') value = {ok:true, conversation:{...conversation,id:request.conversation_id}, messages:[]};
  else if (url === 'pipeline-api.php' && request.action === 'detail') value = {ok:true};
  else if (request.action === 'catalog') value = {ok:true};
  else if (request.action === 'filter_options') value = {ok:true, filters:{projects:[],sources:[],managers:[]}};
  else if (request.action === 'list') value = {ok:true,conversations:[]};
  else if (request.action === 'counts') value = {ok:true,counts:{}};
  else throw new Error('Unexpected synthetic request: '+url+' '+request.action);
  return {ok:true,status:200,json:async()=>value};
}
let selectedFile = null;
const events=new Map();
const window = {
  matchMedia:()=>({matches:mobile}),addEventListener:(name,fn)=>{events.set(name,fn)},
  WorkspaceV2Inbox: {bind(){},load:async()=>true,markRead(){},markActive(){}},
  WorkspaceV2LeadCard: {render(){},renderUnavailable(){}},
  WorkspaceV2Media: {init(){},configure(){},clear(){selectedFile=null;},hasFile:()=>!!selectedFile},
  WorkspaceV2Mobile: {bind(){},isMobile:()=>false,showInbox(){}},
};
const history=sessionHistory||{entries:[null],index:0,get state(){return this.entries[this.index]},replaceState(state){this.entries[this.index]=state},pushState(state){this.entries.splice(++this.index);this.entries.push(state)},async back(){if(this.index>0){this.index--;await this.listener(this.state)}},async forward(){if(this.index<this.entries.length-1){this.index++;await this.listener(this.state)}}};
history.listener=async state=>{const handler=events.get('popstate');if(handler)await handler({state})};
const context = vm.createContext({window,document,fetch,location:{pathname:'/manager/',search:'',hash:'',href:'https://example.invalid/manager/'},history,setTimeout:()=>0,setInterval:(fn,ms)=>{timers.push({fn,ms});return timers.length},console,sessionStorage:storage});
for (const file of ['manager-time.js','workspace-v2.js','workspace-v2-conversation.js',...(inbox?['workspace-v2-inbox.js']:[]),...(mobile?['workspace-v2-mobile.js']:[])]) vm.runInContext(fs.readFileSync(path.join(__dirname,'../manager/assets',file),'utf8'),context,{filename:file});

const W=window.WorkspaceV2,C=window.WorkspaceV2Conversation;
async function start({open=true}={}){await W.boot();if(open)await C.open(101);}
async function expire(){hook=(url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false,error:'unauthorized'},401):null;await C.open(101);hook=null;assert.equal(W.S.authExpired,true);}
async function login(){ids.get('managerAuthLogin').value='synthetic';ids.get('managerAuthPassword').value='synthetic-only';await ids.get('managerAuthForm').dispatch('submit');}
async function draft(text='Unsent synthetic draft'){ids.get('replyText').value=text;await ids.get('replyText').dispatch('input');}
return {W,C,ids,calls,start,expire,login,draft,window,history,document,documentEvents,events,timers,setHook(fn){hook=fn},setIdentity(value){nextIdentity=value},identity,conversation,
  setFile(file){selectedFile=file},getFile(){return selectedFile}};
}
function response(value,status=200){return{ok:status>=200&&status<300,status,json:async()=>value};}
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return{promise,resolve};}
const cases=[];
function test(name,run){cases.push({name,run});}
function hidden(h){return h.ids.get('composer').classList.contains('hidden');}
function assertNoMutations(h){assert.equal(h.calls.some(r=>['send','take','release','close','reopen'].includes(r.action)),false);}

test('first anonymous entry offers login without claiming an expired session',async()=>{
  const h=createHarness();
  h.setHook((url,r)=>r.action==='me'?response({ok:false,error:'unauthorized'},401):null);
  await h.W.boot();
  assert.equal(h.W.S.authExpired,true);assert.equal(h.W.S.manager,null);
  assert.equal(h.ids.get('managerAuthMessage').textContent,'Войдите в кабинет, чтобы открыть диалоги и задачи.');
  assert.equal(h.ids.get('managerAuthSubmit').textContent,'Войти');
  h.setHook((url,r)=>r.action==='login'?response({ok:false,error:'unauthorized'},401):null);
  await h.login();
  assert.equal(h.ids.get('managerAuthError').textContent,'Неверный логин или пароль.');
  assert.equal(h.ids.get('managerAuthSubmit').textContent,'Войти');
  h.setHook(null);await h.login();
  assert.equal(h.W.S.authExpired,false);assert.equal(h.W.S.manager.id,7);assertNoMutations(h);
});
test('authenticated session expiry asks to sign in again and keeps the recovery label after failure',async()=>{
  const h=createHarness();await h.start();await h.draft('Unsent synthetic draft');
  await h.expire();
  assert.equal(h.ids.get('managerAuthMessage').textContent,'Сессия менеджера истекла. Войдите снова, чтобы продолжить.');
  assert.equal(h.ids.get('managerAuthSubmit').textContent,'Войти и продолжить');
  h.setHook((url,r)=>r.action==='login'?response({ok:false,error:'unauthorized'},401):null);
  await h.login();
  assert.equal(h.ids.get('managerAuthSubmit').textContent,'Войти и продолжить');
  assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft');
  assert.equal(hidden(h),true);assertNoMutations(h);
});

test('same manager recovery refreshes ownership and restores draft/file without mobile history or scroll loss',async()=>{
  const h=createHarness();await h.start();assert.equal(hidden(h),false);await h.draft();const file={name:'synthetic.png'};h.setFile(file);
  h.ids.get('messages').scrollTop=25;h.ids.get('messages').scrollHeight=400;h.ids.get('messages').clientHeight=100;
  await h.expire();assert.equal(hidden(h),true);
  h.setIdentity({...h.identity,csrf:'renewed-csrf'});
  const originalOpen=h.C.open;let options;
  h.C.open=async(id,value)=>{options=value;return originalOpen(id,value)};
  await h.login();
  assert.equal(h.W.S.authExpired,false);assert.equal(hidden(h),false);assert.equal(h.W.S.current,101);
  assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft');assert.equal(h.getFile(),file);
  assert.equal(h.ids.get('messages').scrollTop,25);assert.equal(options.mobileHistory,'none');
  assert.equal(h.calls.filter(r=>r.action==='detail').at(-1).csrf,'renewed-csrf');
  assertNoMutations(h);
});
for(const [name,conversation,failure,disabled] of [
  ['reassigned',{manager_id:8,status:'manager'},null,false],
  ['closed',{manager_id:7,status:'closed'},null,false],
  ['suspended',{manager_id:7,status:'manager'},{category:'suspended',message:'Synthetic suspended'},true],
])test(name+' conversation does not regain usable reply controls',async()=>{
  const h=createHarness();await h.start();await h.expire();
  h.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:true,conversation:{...h.conversation,...conversation},messages:[],delivery_failure:failure}):null);
  await h.login();assert.equal(disabled?h.ids.get('sendReply').disabled:hidden(h),true);assertNoMutations(h);
});
for(const [name,status] of [['detail forbidden',403],['second unauthorized',401],['detail server error',500]])test(name+' stays closed',async()=>{
  const h=createHarness();await h.start();await h.expire();h.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false},status):null);
  await h.login();assert.equal(hidden(h),true);if(status===401)assert.equal(h.W.S.authExpired,true);assertNoMutations(h);
});
test('paired pipeline 401 prevents successful API detail from reopening composer',async()=>{
  const h=createHarness();await h.start();await h.expire();
  h.setHook((url,r)=>url==='pipeline-api.php'&&r.action==='detail'?response({ok:false},401):null);
  await h.login();assert.equal(h.W.S.authExpired,true);assert.equal(hidden(h),true);
});
test('account switch clears previous transcript, draft, attachment and selection without reopening it',async()=>{
  const h=createHarness();await h.start();await h.draft('Previous account private draft');h.setFile({name:'private.png'});
  h.ids.get('conversationTitle').textContent='Previous private title';await h.expire();
  h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8},csrf:'other-account-csrf'});
  const count=h.calls.filter(r=>r.action==='detail').length;await h.login();
  assert.equal(h.calls.filter(r=>r.action==='detail').length,count);assert.equal(h.W.S.current,0);assert.equal(h.W.S.detail,null);
  assert.equal(h.ids.get('replyText').value,'');assert.equal(h.getFile(),null);assert.equal(h.ids.get('conversationTitle').textContent,'');assert.equal(hidden(h),true);
  h.C.restoreDraft(101);assert.equal(h.ids.get('replyText').value,'');assertNoMutations(h);
});
test('login with no selected conversation does not fetch a detail',async()=>{
  const h=createHarness();await h.start({open:false});h.W.showAuthRecovery();await h.login();
  assert.equal(h.calls.some(r=>r.action==='detail'),false);assert.equal(h.W.S.current,0);assert.equal(hidden(h),true);
});
test('pending old-session detail cannot replace selected conversation after recovery',async()=>{
  const h=createHarness();await h.start();const old=deferred();
  h.setHook((url,r)=>url==='api.php'&&r.action==='detail'&&r.conversation_id===102?old.promise:null);
  const pending=h.C.open(102);await Promise.resolve();h.W.showAuthRecovery();h.setHook(null);await h.login();
  old.resolve(response({ok:true,conversation:{...h.conversation,id:102,display_name:'Old session private history'},messages:[]}));
  assert.equal(await pending,false);assert.equal(h.W.S.current,101);assert.equal(h.ids.get('conversationTitle').textContent,'Synthetic conversation');assert.equal(hidden(h),false);
});
test('new navigation started during login initialization wins over recovery refresh',async()=>{
  const h=createHarness();await h.start();await h.expire();const catalog=deferred(),catalogRequested=deferred(),next=deferred(),nextRequested=deferred();
  h.setHook((url,r)=>{
    if(r.action==='catalog'){catalogRequested.resolve();return catalog.promise;}
    if(url==='api.php'&&r.action==='detail'&&r.conversation_id===102){nextRequested.resolve();return next.promise;}
    return null;
  });
  const recovering=h.login();await catalogRequested.promise;const navigation=h.C.open(102);await nextRequested.promise;
  const priorDetails=h.calls.filter(r=>r.action==='detail'&&r.conversation_id===101).length;
  catalog.resolve(response({ok:true}));await recovering;
  assert.equal(h.calls.filter(r=>r.action==='detail'&&r.conversation_id===101).length,priorDetails);
  next.resolve(response({ok:true,conversation:{...h.conversation,id:102,display_name:'New selected conversation'},messages:[]}));
  assert.equal(await navigation,true);assert.equal(h.W.S.current,102);assert.equal(hidden(h),false);
});
test('pending protected request is discarded across account switch',async()=>{
  const h=createHarness();await h.start();const old=deferred();
  h.setHook((url,r)=>r.action==='counts'?old.promise:null);const pending=h.W.api('counts');await Promise.resolve();
  h.W.showAuthRecovery();h.setHook(null);h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8}});await h.login();
  old.resolve(response({ok:true,counts:{mine:{count:99}}}));const result=await pending;
  assert.equal(result.ok,false);assert.equal(result.error,'stale_session');
});

// A read response belongs to its navigation, including secondary card reads.
// Exercise the real request/Conversation modules, not a copy of their guards.
for(const owner of ['detail','card','refresh'])for(const status of [200,401,403,404,500])for(const signal of owner==='refresh'?[false]:[false,true])test('obsolete '+owner+' '+status+' with signal='+signal+' cannot expire the current reply',async()=>{
  const h=createHarness();await h.start();
  if(owner==='refresh')await h.C.open(102);
  const old=deferred(),entered=deferred();
  h.setHook((url,r)=>r.action==='detail'&&r.conversation_id===102&&url===(owner==='detail'?'api.php':'pipeline-api.php')?(entered.resolve(),old.promise):null);
  const pending=owner==='refresh'?h.C.refreshLeadData():h.C.open(102,signal?{signal:new AbortController().signal}:{});
  await entered.promise;if(owner==='card')assert.equal(await pending,true);
  assert.equal(await h.C.open(103),true);await h.draft('Current reply stays editable');
  const file={name:'current-only.txt'};h.setFile(file);const detail=h.W.S.detail,reads=h.calls.length;
  old.resolve(response({ok:status===200,conversation:{...h.conversation,id:102},messages:[]},status));
  if(owner!=='card')assert.equal(await pending,false);
  await new Promise(setImmediate);
  assert.equal(h.W.S.authExpired,false);assert.equal(h.W.S.current,103);assert.equal(h.W.S.detail,detail);
  assert.equal(hidden(h),false);assert.equal(h.ids.get('replyText').disabled,false);
  assert.equal(h.ids.get('replyText').value,'Current reply stays editable');assert.equal(h.getFile(),file);
  assert.equal(h.calls.length,reads);assertNoMutations(h);
});
for(const owner of ['detail','card','refresh'])test('obsolete '+owner+' 401 stays obsolete after A to B to A',async()=>{
  const h=createHarness();await h.start();if(owner==='refresh')await h.C.open(102);
  const old=deferred(),entered=deferred();let held=false;
  h.setHook((url,r)=>{if(!held&&r.action==='detail'&&r.conversation_id===102&&url===(owner==='detail'?'api.php':'pipeline-api.php')){held=true;entered.resolve();return old.promise;}return null;});
  const pending=owner==='refresh'?h.C.refreshLeadData():h.C.open(102);
  await entered.promise;if(owner==='card')await pending;
  await h.C.open(103);await h.C.open(102);await h.draft('New visit to the same client');
  old.resolve(response({ok:false},401));if(owner!=='card')await pending;await new Promise(setImmediate);
  assert.equal(h.W.S.authExpired,false);assert.equal(h.W.S.current,102);assert.equal(hidden(h),false);
  assert.equal(h.ids.get('replyText').value,'New visit to the same client');assertNoMutations(h);
});
test('obsolete initial detail settlement cannot unlock a newer pending navigation',async()=>{
  const h=createHarness();await h.start();const old=deferred(),next=deferred(),oldEntered=deferred(),nextEntered=deferred();
  h.setHook((url,r)=>{if(url!=='api.php'||r.action!=='detail')return null;if(r.conversation_id===102){oldEntered.resolve();return old.promise;}if(r.conversation_id===103){nextEntered.resolve();return next.promise;}return null;});
  const first=h.C.open(102);await oldEntered.promise;const second=h.C.open(103);await nextEntered.promise;
  old.resolve(response({ok:false},401));assert.equal(await first,false);
  assert.equal(h.W.S.authExpired,false);assert.equal(h.ids.get('replyText').disabled,true);
  next.resolve(response({ok:true,conversation:{...h.conversation,id:103},messages:[]}));
  assert.equal(await second,true);assert.equal(hidden(h),false);assert.equal(h.ids.get('replyText').disabled,false);assertNoMutations(h);
});
test('current secondary refresh 401 still requires canonical authentication',async()=>{
  const h=createHarness();await h.start();await h.draft('Keep same-account recovery draft');
  h.setHook((url,r)=>url==='pipeline-api.php'&&r.action==='detail'?response({ok:false},401):null);
  assert.equal(await h.C.refreshLeadData(),false);assert.equal(h.W.S.authExpired,true);assert.equal(hidden(h),true);
  assert.equal(h.ids.get('managerAuthRecovery').classList.contains('hidden'),false);assertNoMutations(h);
});
for(const status of [200,401,403,404,500])test('source-pinned Inbox refresh '+status+' retains successful completion without obsolete auth ownership',async()=>{
  const h=createHarness();await h.start();const old=deferred(),entered=deferred();let inbox=0;
  h.window.WorkspaceV2Inbox.load=async()=>{inbox++;return true};
  h.setHook((url,r)=>url==='pipeline-api.php'&&r.action==='detail'&&r.conversation_id===101?(entered.resolve(),old.promise):null);
  const pending=h.C.refreshLeadData({refreshInbox:true});await entered.promise;await h.C.open(102);await h.draft('Selected client reply');
  old.resolve(response({ok:status===200,trip:{country:'Old source client'}},status));assert.equal(await pending,false);
  assert.equal(inbox,status===200?1:0);assert.equal(h.W.S.authExpired,false);assert.equal(h.W.S.current,102);
  assert.equal(hidden(h),false);assert.equal(h.ids.get('replyText').value,'Selected client reply');assertNoMutations(h);
});
for(const [method,action] of [['api','send'],['pipe','set_stage']])test('unowned '+action+' response retains global 401 handling after navigation',async()=>{
  const h=createHarness();await h.start();const old=deferred(),entered=deferred();
  h.setHook((url,r)=>r.action===action?(entered.resolve(),old.promise):null);
  // Synthetic transport only: this tests the unchanged default request policy.
  const pending=h.W[method](action,{conversation_id:101});const rejected=assert.rejects(pending,/unauthorized/);
  await entered.promise;await h.C.open(102);old.resolve(response({ok:false},401));await rejected;
  assert.equal(h.W.S.authExpired,true);assert.equal(hidden(h),true);
});

// Send requests below are handled by the in-memory fetch above; no network I/O.
for(const result of ['network','invalid','unknown','temporary'])test('unconfirmed '+result+' preserves draft/file and does not resend',async()=>{
  const h=createHarness();await h.start();await h.draft('Synthetic reply');const file={name:'fixture.png'};h.setFile(file);
  h.window.WorkspaceV2Media.send=async()=>{if(result==='network')throw new Error('Synthetic connection loss');return result==='invalid'?{ok:false,error:'invalid_response'}:{ok:false,failure:{category:result,message:'Сообщение не доставлено'}};};
  let sends=0;const send=h.window.WorkspaceV2Media.send;h.window.WorkspaceV2Media.send=async()=>{sends++;return send();};
  await h.C.sendReply();
  assert.match(h.ids.get('replyStatus').textContent,/Отправка не подтверждена/);
  if(['unknown','temporary'].includes(result))assert.match(h.ids.get('deliveryFailure').textContent,/Отправка не подтверждена/);
  assert.equal(h.ids.get('replyText').value,'Synthetic reply');assert.equal(h.getFile(),file);assert.equal(sends,1);assert.equal(h.ids.get('sendReply').disabled,false);
});
for(const category of ['suspended','blocked','unavailable','unsupported'])test('known '+category+' reason remains visible without retries',async()=>{
  const h=createHarness();await h.start();await h.draft();
  h.setHook((url,r)=>r.action==='send'?response({ok:false,error_message:'Synthetic known reason',failure:{category,message:'Synthetic raw provider reason'}},409):null);
  await h.C.sendReply();assert.equal(h.ids.get('replyStatus').textContent,'Synthetic known reason');assert.equal(h.ids.get('deliveryFailure').textContent,'Synthetic known reason');
  assert.equal(h.calls.filter(r=>r.action==='send').length,1);assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft');assert.equal(h.ids.get('sendReply').disabled,category==='suspended');
});
test('persisted suspension uses the server notice after detail load and reload',async()=>{
  const raw='Key: error.dialog.suspended, args: [990000001,].';
  const notice='Пользователь остановил или заблокировал бота MAX. Отправка станет доступна после новой активности клиента — когда он снова запустит или разблокирует бота.';
  for(let reload=0;reload<2;reload++){
    const h=createHarness();await h.start();await h.draft();const file={name:'synthetic.png'};h.setFile(file);
    h.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:true,conversation:h.conversation,messages:[],delivery_failure:{category:'suspended',message:raw,notice,retry_allowed:false}}):null);
    await h.C.open(101,{preserveAttachment:true});
    assert.equal(h.ids.get('deliveryFailure').textContent,notice);
    assert.equal(h.W.S.detail.delivery_failure.message,raw);
    assert.equal(h.ids.get('sendReply').disabled,true);assert.equal(h.ids.get('replyFile').disabled,true);
    assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft');assert.equal(h.getFile(),file);
    await h.C.sendReply();assertNoMutations(h);
  }
});
test('raw-only suspended media failure uses neutral copy without a retry or lost draft',async()=>{
  const h=createHarness();await h.start();await h.draft();const file={name:'synthetic.png'};h.setFile(file);
  const raw='Key: error.dialog.suspended, args: [990000001,].';let sends=0;
  h.window.WorkspaceV2Media.send=async()=>{sends++;return{ok:false,failure:{category:'suspended',message:raw}};};
  await h.C.sendReply();
  for(const id of ['deliveryFailure','replyStatus']){
    const copy=h.ids.get(id).textContent;
    assert.match(copy,/Диалог MAX приостановлен/);assert.match(copy,/нового сообщения или запуска бота/);
    assert.doesNotMatch(copy,/error\.dialog|990000001|остановил|заблокировал/);
  }
  assert.equal(h.W.S.detail.delivery_failure.message,raw);
  assert.equal(h.ids.get('sendReply').disabled,true);assert.equal(h.ids.get('replyFile').disabled,true);
  assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft');assert.equal(h.getFile(),file);
  await h.C.sendReply();assert.equal(sends,1);assertNoMutations(h);
});
for(const mode of ['detail-error','detail-throw','inbox-throw','render-throw'])test('accepted send remains confirmed after '+mode,async()=>{
  const h=createHarness();await h.start();await h.draft('Confirmed synthetic reply');
  h.setHook((url,r)=>{
    if(r.action==='send')return response({ok:true});
    if(url==='api.php'&&r.action==='detail'){
      if(mode==='detail-error')return response({ok:false},500);
      if(mode==='detail-throw')throw new Error('Synthetic history outage');
    }
    return null;
  });
  if(mode==='inbox-throw')h.window.WorkspaceV2Inbox.load=async()=>{throw new Error('Synthetic Inbox outage');};
  if(mode==='render-throw')h.window.WorkspaceV2LeadCard.render=()=>{throw new Error('Synthetic render failure');};
  await h.C.sendReply();
  assert.match(h.ids.get('replyStatus').textContent,/^Отправлено/);
  assert.doesNotMatch(h.ids.get('replyStatus').textContent,/не подтверждена|Не удалось отправить/);
  assert.equal(h.ids.get('replyText').value,'');assert.equal(h.calls.filter(r=>r.action==='send').length,1);
});
test('a new accepted attempt does not display an older uncertain warning',async()=>{
  const h=createHarness();await h.start();await h.draft('Synthetic reply');
  h.setHook((url,r)=>r.action==='send'?response({ok:false,failure:{category:'unknown',message:'Old uncertainty'}},409):null);
  await h.C.sendReply();assert.match(h.ids.get('deliveryFailure').textContent,/Отправка не подтверждена/);
  h.setHook((url,r)=>r.action==='send'?response({ok:true}):url==='api.php'&&r.action==='detail'?response({ok:false},500):null);
  await h.C.sendReply();assert.match(h.ids.get('replyStatus').textContent,/^Отправлено/);
  assert.equal(h.ids.get('deliveryFailure').classList.contains('hidden'),true);
  assert.equal(h.calls.filter(r=>r.action==='send').length,2);
});
test('successful send and refresh reports sent without inferring client reading',async()=>{
  const h=createHarness();await h.start();await h.draft('Synthetic text');h.setHook((url,r)=>r.action==='send'?response({ok:true}):null);
  await h.C.sendReply();assert.equal(h.ids.get('replyStatus').textContent,'Отправлено');
  const sends=h.calls.filter(r=>r.action==='send');assert.equal(sends.length,1);assert.equal(sends[0].conversation_id,101);assert.equal(sends[0].text,'Synthetic text');
  assert.equal(h.W.S.detail.messages.length,0); // no invented message/receipt in history
});
test('late confirmed send after navigation cannot clear another draft or set its status',async()=>{
  const h=createHarness();await h.start();await h.draft('Old draft');const pending=deferred(),entered=deferred();
  h.setHook((url,r)=>r.action==='send'?(entered.resolve(),pending.promise):null);
  const sending=h.C.sendReply();await entered.promise;await h.C.open(102);await h.draft('New conversation draft');
  h.ids.get('replyStatus').textContent='New conversation feedback';pending.resolve(response({ok:true}));await sending;
  assert.equal(h.W.S.current,102);assert.equal(h.ids.get('replyText').value,'New conversation draft');assert.equal(h.ids.get('replyStatus').textContent,'New conversation feedback');
});
test('late send after reauthentication cannot display old-account feedback',async()=>{
  const h=createHarness();await h.start();await h.draft('Old draft');const pending=deferred(),entered=deferred();
  h.setHook((url,r)=>r.action==='send'?(entered.resolve(),pending.promise):null);
  const sending=h.C.sendReply();await entered.promise;h.W.showAuthRecovery();h.setHook(null);await h.login();await h.draft('Recovered draft');
  h.ids.get('replyStatus').textContent='Recovered feedback';pending.resolve(response({ok:true}));await sending;
  assert.equal(h.ids.get('replyText').value,'Recovered draft');assert.equal(h.ids.get('replyStatus').textContent,'Recovered feedback');
});
test('newer navigation during post-send refresh owns the new screen and draft',async()=>{
  const h=createHarness();await h.start();await h.draft('Old draft');const pending=deferred(),entered=deferred();
  h.setHook((url,r)=>r.action==='send'?response({ok:true}):url==='api.php'&&r.action==='detail'&&r.conversation_id===101?(entered.resolve(),pending.promise):null);
  const sending=h.C.sendReply();await entered.promise;await h.C.open(102);await h.draft('New draft');h.ids.get('replyStatus').textContent='New feedback';
  pending.resolve(response({ok:false},500));await sending;
  assert.equal(h.W.S.current,102);assert.equal(h.ids.get('replyText').value,'New draft');assert.equal(h.ids.get('replyStatus').textContent,'New feedback');
});

for(const mode of ['navigation','new-session'])test('late rejected send cannot paint '+mode+' feedback',async()=>{
  const h=createHarness();await h.start();await h.draft('Old draft');const pending=deferred(),entered=deferred();
  h.setHook((url,r)=>r.action==='send'?(entered.resolve(),pending.promise):null);
  const sending=h.C.sendReply();await entered.promise;
  if(mode==='navigation')await h.C.open(102);
  else{h.W.showAuthRecovery();h.setHook(null);await h.login();}
  await h.draft('Current draft');h.ids.get('replyStatus').textContent='Current feedback';
  pending.resolve(response({ok:false,error_message:'Stale refusal',failure:{category:'blocked',message:'Stale refusal'}},409));await sending;
  assert.equal(h.ids.get('replyText').value,'Current draft');assert.equal(h.ids.get('replyStatus').textContent,'Current feedback');
  assert.notEqual(h.W.S.detail.delivery_failure?.message,'Stale refusal');
});

module.exports={createHarness,response,deferred,hidden,assertNoMutations};
if(require.main===module)(async()=>{let failed=0;for(const item of cases){try{await item.run();console.log('PASS '+item.name)}catch(e){failed++;console.error('FAIL '+item.name+'\n'+e.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})()
