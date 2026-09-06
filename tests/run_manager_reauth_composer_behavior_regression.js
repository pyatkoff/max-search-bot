const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// DOM behavior only: no layout, production session, transport, or credentials.
// App logic comes directly from the canonical production asset files.
function createHarness() {
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
  set textContent(value) { this._text = String(value); this.children = []; }
  get textContent() { return this._text; }
  set innerHTML(value) {
    this._html = String(value); this.children = [];
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
  appendChild(child) { this.children.push(child); child.parentElement = this; return child; }
  replaceChildren(...children) { this.children = children; this._text = ''; }
  setAttribute(name, value) { this[name] = value; }
  addEventListener(name, handler) { (this.handlers[name] ||= []).push(handler); }
  async dispatch(name) { for (const fn of this.handlers[name] || []) await fn({preventDefault(){}}); }
  focus() {}
}
function add(id, tag = 'div', className = '') { const el = new Element(tag); el.id = id; el.className = className; return el; }
const body = new Element('body');
const document = {
  body,
  getElementById: id => ids.get(id) || null,
  createElement: tag => new Element(tag),
  createDocumentFragment: () => new Element('fragment'),
  createTextNode: text => { const el = new Element('text'); el.textContent = text; return el; },
  querySelectorAll: selector => selector === '#conversationActions button' ? ids.get('conversationActions').children : [],
};
['inboxList','adminLink','managerName','conversationLoadStatus','conversationActions',
 'conversationTitle','conversationAvatar','conversationState','conversationMeta',
 'deliveryFailure','composerLocked','replyStatus','conversationZone','messages'].forEach(id => add(id));
add('composer', 'form', 'composer hidden'); add('replyText', 'textarea'); add('replyFile', 'input'); add('sendReply', 'button');

const calls = [];
let hook = null;
let nextIdentity = null;
const manager = {id:7, role:'manager', login:'synthetic', display_name:'Synthetic manager', is_working:true};
const identity = {ok:true, csrf:'synthetic-csrf', manager, projects:[]};
const conversation = {id:101, manager_id:7, status:'manager', channel:'max', display_name:'Synthetic conversation'};
async function fetch(url, options) {
  const request = JSON.parse(options.body); calls.push({url,...request});
  if(hook){const result=await hook(url,request);if(result)return result;}
  let value;
  if (request.action === 'me' || request.action === 'login') value = nextIdentity || identity;
  else if (url === 'api.php' && request.action === 'detail') value = {ok:true, conversation, messages:[]};
  else if (url === 'pipeline-api.php' && request.action === 'detail') value = {ok:true};
  else if (request.action === 'catalog') value = {ok:true};
  else if (request.action === 'filter_options') value = {ok:true, filters:{projects:[],sources:[],managers:[]}};
  else throw new Error('Unexpected synthetic request: '+url+' '+request.action);
  return {ok:true,status:200,json:async()=>value};
}
let selectedFile = null;
const window = {
  WorkspaceV2Inbox: {bind(){},load:async()=>true,markRead(){},markActive(){}},
  WorkspaceV2LeadCard: {render(){},renderUnavailable(){}},
  WorkspaceV2Media: {init(){},configure(){},clear(){selectedFile=null;},hasFile:()=>!!selectedFile},
  WorkspaceV2Mobile: {bind(){},isMobile:()=>false,showInbox(){}},
};
const context = vm.createContext({window,document,fetch,location:{pathname:'/manager/',search:'',hash:''},history:{},setTimeout:()=>0,console});
for (const file of ['workspace-v2.js','workspace-v2-conversation.js']) vm.runInContext(fs.readFileSync(path.join(__dirname,'../manager/assets',file),'utf8'),context,{filename:file});

const W=window.WorkspaceV2,C=window.WorkspaceV2Conversation;
async function start({open=true}={}){await W.boot();if(open)await C.open(101);}
async function expire(){hook=(url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false,error:'unauthorized'},401):null;await C.open(101);hook=null;assert.equal(W.S.authExpired,true);}
async function login(){ids.get('managerAuthLogin').value='synthetic';ids.get('managerAuthPassword').value='synthetic-only';await ids.get('managerAuthForm').dispatch('submit');}
async function draft(text='Unsent synthetic draft'){ids.get('replyText').value=text;await ids.get('replyText').dispatch('input');}
return {W,C,ids,calls,start,expire,login,draft,window,setHook(fn){hook=fn},setIdentity(value){nextIdentity=value},identity,conversation,
  setFile(file){selectedFile=file},getFile(){return selectedFile}};
}
function response(value,status=200){return{ok:status>=200&&status<300,status,json:async()=>value};}
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return{promise,resolve};}
const cases=[];
function test(name,run){cases.push({name,run});}
function hidden(h){return h.ids.get('composer').classList.contains('hidden');}
function assertNoMutations(h){assert.equal(h.calls.some(r=>['send','take','release','close','reopen'].includes(r.action)),false);}

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
(async()=>{let failed=0;for(const item of cases){try{await item.run();console.log('PASS '+item.name)}catch(e){failed++;console.error('FAIL '+item.name+'\n'+e.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})()
