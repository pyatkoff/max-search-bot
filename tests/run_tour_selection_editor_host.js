'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const root=path.resolve(__dirname,'..'),source=fs.readFileSync(path.join(root,'manager/assets/workspace-v2-tour-selection-host.js'),'utf8');
let checks=0;function check(name,fn){fn();checks++;console.log('PASS '+name)}
function fixture(hint='',entry=''){
  const c={id:11,manager_id:7,status:'manager',project_key:'anytour',request_kind:'travel'},state={authExpired:false,manager:{id:7,role:'manager'},detail:{conversation:c},current:11,authGeneration:3};
  let host,open=5,mobile=false,ready=true,readAccess=true;const requests=[],navigations=[],listeners={},replacements=[],roots=[];
  const editor={sync:c=>roots.push(c)},window={WorkspaceV2:{S:state,request:(...args)=>{requests.push(args);return Promise.resolve({ok:true})}},WorkspaceV2Conversation:{getOpenGeneration:()=>open,hasReadAccess:()=>readAccess,canNavigateMessages:()=>ready&&readAccess},AnyTourSelectionEditor:{mount:h=>{host=h;return editor}},ManagerTime:{format:(...args)=>args.join('|')},matchMedia:()=>({matches:mobile}),open:(...args)=>navigations.push(args),addEventListener:(name,fn)=>listeners[name]=fn};
  const location={href:'https://app.anytoour.ru/manager2/'+hint,origin:'https://app.anytoour.ru'};
  const document={querySelector:selector=>{roots.push(selector);return selector==='meta[name="selection-editor-entry"]'?{content:entry}:{selector}}},history={state:null,replaceState:(...args)=>replacements.push(args)};
  vm.runInNewContext(source,{window,document,location,history,URL});
  return {host,state,c,window,requests,navigations,listeners,replacements,roots,editor,setOpen:value=>open=value,setMobile:value=>mobile=value,setReady:value=>ready=value,setReadAccess:value=>readAccess=value};
}
check('current context includes the manager, conversation, authentication and open generations',()=>{const f=fixture(),t=f.host.capture();assert.deepEqual(JSON.parse(JSON.stringify(t)),{actor:7,conversation:11,auth:3,open:5});assert.equal(f.host.isCurrent(t),true)});
for(const [name,change] of [
 ['account change',f=>f.state.manager.id=8],['expired auth',f=>f.state.authExpired=true],
 ['same-account reauthentication',f=>f.state.authGeneration++],['conversation navigation',f=>f.state.current=12],
 ['detail replaced',f=>f.state.detail.conversation={id:12}],['same-conversation reopening',f=>f.setOpen(6)]
])check(name+' invalidates the old editor operation',()=>{const f=fixture(),t=f.host.capture();change(f);assert.equal(f.host.isCurrent(t),false);assert.equal(f.requests.length,0);assert.equal(f.navigations.length,0)});
check('only the authorized current conversation can launch',()=>{const f=fixture();assert.equal(f.host.canOpen(f.c),true);assert.equal(f.host.canOpen({id:12}),false);f.state.authExpired=true;assert.equal(f.host.canOpen(f.c),false)});
for(const kind of ['device_repair','general','unexpected',undefined,null,[],{}])check('canonical nontravel or missing profile denies every entry: '+JSON.stringify(kind),()=>{
  const f=fixture('','https://tours.anytoour.ru/editor.php'),target=f.host.capture();
  f.state.detail.conversation={...f.c,request_kind:kind};
  assert.equal(f.host.canOpen(f.c),false);assert.equal(f.host.canStart(f.c),false);assert.equal(f.host.isCurrent(target),false);
  f.host.openStandalone(11);f.host.openCollector(11);assert.equal(f.requests.length,0);assert.equal(f.navigations.length,0);
});
check('read access loss invalidates the editor while transient readiness only blocks a new start',()=>{
  const f=fixture('','https://tours.anytoour.ru/editor.php'),target=f.host.capture();f.setReady(false);
  assert.equal(f.host.canOpen(f.c),true);assert.equal(f.host.isCurrent(target),true);assert.equal(f.host.canStart(f.c),false);
  f.host.openStandalone(11);f.host.openCollector(11);assert.equal(f.navigations.length,0);
  f.setReady(true);assert.equal(f.host.canStart(f.c),true);f.setReadAccess(false);
  assert.equal(f.host.canOpen(f.c),false);assert.equal(f.host.canStart(f.c),false);assert.equal(f.host.isCurrent(target),false);
});
check('missing workspace capability owners fail closed without a second readiness source',()=>{
  const f=fixture();delete f.window.WorkspaceV2Conversation.hasReadAccess;assert.equal(f.host.canOpen(f.c),false);
  f.window.WorkspaceV2Conversation.hasReadAccess=()=>true;delete f.window.WorkspaceV2Conversation.canNavigateMessages;
  assert.equal(f.host.canOpen(f.c),true);assert.equal(f.host.canStart(f.c),false);
});
check('reassignment and closure remove edit capability without granting another owner',()=>{const f=fixture();assert.equal(f.host.canEdit(f.c),true);f.c.manager_id=8;assert.equal(f.host.canEdit(f.c),false);f.state.manager.role='admin';assert.equal(f.host.canEdit(f.c),true);f.c.status='closed';assert.equal(f.host.canEdit(f.c),false)});
check('authorized read-only travel keeps its launcher and start without gaining edit rights',()=>{const f=fixture();f.c.manager_id=8;f.c.status='closed';assert.equal(f.host.canOpen(f.c),true);assert.equal(f.host.canStart(f.c),true);assert.equal(f.host.canEdit(f.c),false)});
check('valid collector hint is consumed once after matching canonical navigation',()=>{const f=fixture('?selection=11&collector_actor=7#conversation=11');assert.equal(f.host.takeCollectorIntent(f.host.capture()),true);assert.equal(f.host.takeCollectorIntent(f.host.capture()),false);assert.equal(f.replacements.length,1);assert.equal(f.requests.length,0)});
for(const hint of ['?selection=11&collector_actor=8#conversation=11','?selection=11&collector_actor=7#conversation=12','?selection=11&selection=12&collector_actor=7#conversation=11','?selection=011&collector_actor=7#conversation=11','?selection=11&collector_actor=7'])check('mismatched collector navigation stays closed: '+hint,()=>{const f=fixture(hint);assert.equal(f.host.takeCollectorIntent(f.host.capture()),false);assert.equal(f.requests.length,0)});
check('account recovery discards an old collector actor hint',()=>{const f=fixture('?selection=11&collector_actor=7#conversation=11');f.state.manager.id=8;f.host.activate();f.state.manager.id=7;assert.equal(f.host.takeCollectorIntent(f.host.capture()),false)});
check('unsupported collector return is discarded at canonical display without opening later as travel',()=>{
  const f=fixture('?selection=11&collector_actor=7#conversation=11');f.state.detail.conversation={...f.c,request_kind:'general'};
  assert.equal(f.host.canOpen(f.state.detail.conversation),false);assert.equal(f.replacements.length,1);
  f.state.detail.conversation=f.c;assert.equal(f.host.takeCollectorIntent(f.host.capture()),false);assert.equal(f.requests.length,0);assert.equal(f.navigations.length,0);
});
check('request passes the original signal and currentness fence to canonical transport',()=>{const f=fixture(),data={conversation_id:11},options={signal:{fixture:true},isCurrent:()=>false};f.host.request('tour-selections-api.php','get',data,options);assert.equal(f.requests[0][2],data);assert.equal(f.requests[0][3],options);assert.equal(f.requests[0][0],'tour-selections-api.php')});
check('responsive launcher stays with its existing Manager2 owner',()=>{const f=fixture();assert.equal(f.host.launcher().selector,'.conversationTitleRow');f.setMobile(true);assert.equal(f.host.launcher().selector,'.m2ConversationMenu>div');f.listeners.resize();assert.equal(f.roots.at(-1),f.c);assert.equal(f.window.WorkspaceV2TourSelection,f.editor)});
check('collector opens only the existing same-origin bridge as an explicit host action',()=>{const f=fixture();assert.equal(f.navigations.length,0);f.host.openCollector(11);assert.equal(f.navigations.length,1);assert.deepEqual(f.navigations[0],['tour-selection-collector.php?conversation_id=11','_blank','noopener'])});
check('standalone entry needs installed exact origin and explicit current actor action',()=>{assert.equal(fixture().host.openStandalone,undefined);assert.equal(fixture('','https://foreign.invalid/editor.php').host.openStandalone,undefined);const f=fixture('','https://tours.anytoour.ru/editor.php');assert.equal(f.navigations.length,0);f.host.openStandalone(12);assert.equal(f.navigations.length,0);f.host.openStandalone(11);assert.deepEqual(f.navigations[0],['https://tours.anytoour.ru/editor.php?conversation_id=11&editor_actor=7','_blank','noopener']);f.state.authGeneration++;f.state.authExpired=true;f.host.openStandalone(11);assert.equal(f.navigations.length,1)});
const core=fs.readFileSync(path.join(root,'manager/assets/workspace-v2-tour-selection.js'),'utf8');
check('canonical core loads without WorkspaceV2 or a workspace DOM and rejects an invalid host',()=>{const window={};vm.runInNewContext(core,{window});assert.equal(window.AnyTourSelectionEditor.contract,'tour-selection-editor-host-v1');assert.throws(()=>window.AnyTourSelectionEditor.mount({}),/Invalid selection editor host/)});
check('the optional start hook must be callable when supplied',()=>{const window={},f=fixture();vm.runInNewContext(core,{window});assert.throws(()=>window.AnyTourSelectionEditor.mount({...f.host,canStart:true}),/Invalid selection editor host/)});

// Minimal DOM for the actual editor's empty-draft lifecycle, not a layout fixture.
// No source rewriting or exported test-only state: open, sync and Save run normally.
function coreFixture({start=true,dirty=false,holdSave=false}={}){
  const f=fixture(),storage=new Map(),calls=[];let releaseSave,created=0;
  class Element {
    constructor(tag){this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.attributes={};this.dataset={};this.listeners={};this.open=false;this.hidden=false;this.style={setProperty(){},removeProperty(){}};
      this.classList={add:(...names)=>{this.className=[...new Set([...this.className.split(/\s+/),...names])].filter(Boolean).join(' ')},remove:(...names)=>{this.className=this.className.split(/\s+/).filter(x=>!names.includes(x)).join(' ')},contains:name=>this.className.split(/\s+/).includes(name),toggle:(name,force)=>{const on=force??!this.classList.contains(name);this.classList[on?'add':'remove'](name);return on}};
    }
    get id(){return this.attributes.id||''}set id(value){this.attributes.id=value}
    get className(){return this.attributes.class||''}set className(value){this.attributes.class=value}
    get isConnected(){return this===document.body||!!this.parentElement?.isConnected}
    get firstChild(){return this.children[0]||null}
    set textContent(value){this._text=String(value);this.replaceChildren()}get textContent(){return this._text||this.children.map(x=>x.textContent).join('')}
    set innerHTML(value){this._html=value;this.replaceChildren();const stack=[this];for(const token of value.match(/<[^>]+>/g)||[]){
      if(token.startsWith('</')){if(stack.length>1)stack.pop();continue}const match=token.match(/^<([\w-]+)([^>]*)>/);if(!match)continue;
      const el=document.createElement(match[1]);for(const attr of match[2].matchAll(/([\w-]+)(?:="([^"]*)")?/g))el.setAttribute(attr[1],attr[2]||'');stack.at(-1).append(el);
      if(!['input','img','br','hr','meta','link'].includes(match[1])&&!token.endsWith('/>'))stack.push(el);
    }}get innerHTML(){return this._html||this._text||''}
    setAttribute(name,value){this.attributes[name]=String(value);if(name.startsWith('data-'))this.dataset[name.slice(5).replace(/-([a-z])/g,(_,x)=>x.toUpperCase())]=String(value)}
    removeAttribute(name){delete this.attributes[name]}getAttribute(name){return this.attributes[name]??null}
    append(...nodes){for(const node of nodes){node.remove();node.parentElement=this;this.children.push(node)}}appendChild(node){this.append(node);return node}
    prepend(node){this.insertBefore(node,this.firstChild)}insertBefore(node,reference){node.remove();const index=reference?this.children.indexOf(reference):this.children.length;assert(index>=0);node.parentElement=this;this.children.splice(index,0,node);return node}
    before(node){this.parentElement?.insertBefore(node,this)}after(node){const parent=this.parentElement;if(parent)parent.insertBefore(node,parent.children[parent.children.indexOf(this)+1]||null)}
    remove(){if(this.parentElement){const parent=this.parentElement;parent.children.splice(parent.children.indexOf(this),1);this.parentElement=null}}
    replaceChildren(...nodes){for(const child of this.children)child.parentElement=null;this.children=[];this.append(...nodes)}
    matches(selector){if(selector.startsWith('#'))return this.id===selector.slice(1);if(selector.startsWith('.'))return this.classList.contains(selector.slice(1));if(selector.startsWith('[')){const m=selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);return !!m&&Object.hasOwn(this.attributes,m[1])&&(m[2]===undefined||this.attributes[m[1]]===m[2])}return this.tagName.toLowerCase()===selector}
    querySelectorAll(selector){const selectors=selector.split(',').map(x=>x.trim()),result=[];for(const child of this.children){if(selectors.some(x=>child.matches(x)))result.push(child);result.push(...child.querySelectorAll(selector))}return result}
    querySelector(selector){return this.querySelectorAll(selector)[0]||null}closest(selector){return this.matches(selector)?this:this.parentElement?.closest(selector)||null}
    addEventListener(type,fn){(this.listeners[type]??=[]).push(fn)}focus(){document.activeElement=this}
    showModal(){this.open=true}close(){this.open=false;for(const fn of this.listeners.close||[])fn()}
  }
  const document={createElement:tag=>{created++;return new Element(tag)},getElementById:id=>document.body.querySelector('#'+id),querySelector:selector=>document.body.querySelector(selector),querySelectorAll:selector=>document.body.querySelectorAll(selector)};
  document.body=new Element('body');const launcher=document.createElement('div');document.body.append(launcher);
  const draft={title:'Retained local title',intro:'',offers:[]},key='workspaceV2.tourSelection.7.11';
  if(dirty)storage.set(key,JSON.stringify({draft,version:1,selected:'',dirty:true,rawAges:{},pending:null,conflict:null,at:Date.now()}));
  const snapshot=(version=1,value=draft)=>({conversation_id:11,version,can_edit:true,draft:value});
  const host={...f.host,launcher:()=>launcher,request:(_resource,action,data,options)=>{calls.push({action,data,options});if(action==='get')return Promise.resolve({ok:true,selection:snapshot()});assert.equal(action,'save');return holdSave?new Promise(resolve=>{releaseSave=()=>resolve({ok:true,saved_version:2,selection:snapshot(2,data.draft)})}):Promise.resolve({ok:true,saved_version:2,selection:snapshot(2,data.draft)})}};
  if(start===null)delete host.canStart;else if(start===false)host.canStart=()=>false;
  const window={addEventListener(){}},sessionStorage={getItem:key=>storage.get(key)??null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)};
  vm.runInNewContext(core,{window,document,sessionStorage,URL,AbortController,setTimeout,clearTimeout,queueMicrotask,crypto:require('node:crypto').webcrypto});
  const editor=window.AnyTourSelectionEditor.mount(host);
  return {...f,editor,host,document,storage,calls,key,launcher,created:()=>created,releaseSave:()=>releaseSave(),snapshot};
}
async function coreChecks(){
  const blocked=coreFixture({start:false}),before=blocked.created();await blocked.editor.open(blocked.c);
  check('optional start rejection runs before DOM creation or transport',()=>{assert.equal(blocked.created(),before);assert.equal(blocked.document.getElementById('tourSelectionDialog'),null);assert.equal(blocked.calls.length,0)});
  const standalone=coreFixture({start:null});await standalone.editor.open(standalone.c);
  check('a private host without the optional start hook retains the original open contract',()=>{assert.equal(standalone.document.getElementById('tourSelectionDialog').open,true);assert.deepEqual(standalone.calls.map(x=>x.action),['get'])});
  const f=coreFixture({dirty:true,holdSave:true});f.editor.sync(f.c);await f.editor.open(f.c);
  const button=f.document.getElementById('tourSelectionOpen'),dialog=f.document.getElementById('tourSelectionDialog');
  assert.equal(f.document.getElementById('tsSave').disabled,false);const saving=f.document.getElementById('tsSave').onclick();
  const pending=JSON.parse(f.storage.get(f.key));assert.match(pending.pending.operation_id,/^[a-f0-9]{32}$/);assert.equal(pending.pending.draft.title,'Retained local title');
  f.setReady(false);f.editor.sync(f.c);await f.editor.open(f.c);
  check('reply busy preserves the real editor and pending save without starting another read',()=>{assert.equal(dialog.open,true);assert.equal(f.document.getElementById('tourSelectionOpen'),button);assert.equal(f.calls.at(-1).options.isCurrent(),true);assert.equal(f.storage.get(f.key),JSON.stringify(pending));assert.deepEqual(f.calls.map(x=>x.action),['get','save'])});
  f.releaseSave();await saving;
  check('the original pending receipt is accepted while reply readiness is false',()=>{assert.equal(dialog.open,true);assert.equal(f.document.getElementById('tsStatus').textContent,'Черновик сохранён.');assert.equal(f.document.getElementById('tsSaved').textContent,'Сохранено на сервере');assert.equal(f.storage.has(f.key),false);assert.deepEqual(f.calls.map(x=>x.action),['get','save'])});
  f.setReady(true);f.editor.sync(f.c);
  check('reply completion retains the same travel launcher without rebuilding the editor',()=>{assert.equal(f.document.getElementById('tourSelectionOpen'),button);assert.equal(f.document.getElementById('tourSelectionDialog'),dialog)});
  const switched=coreFixture({dirty:true,holdSave:true});switched.editor.sync(switched.c);await switched.editor.open(switched.c);
  const oldSave=switched.document.getElementById('tsSave').onclick(),oldPending=JSON.parse(switched.storage.get(switched.key)).pending;
  switched.state.detail.conversation={...switched.c,request_kind:'device_repair'};switched.editor.sync(switched.state.detail.conversation);
  switched.releaseSave();await oldSave;
  check('a canonical business change closes the editor but retains the uncertain saved operation',()=>{assert.equal(switched.document.getElementById('tourSelectionDialog').open,false);assert.equal(switched.document.getElementById('tourSelectionOpen'),null);assert.deepEqual(JSON.parse(switched.storage.get(switched.key)).pending,oldPending);assert.deepEqual(switched.calls.map(x=>x.action),['get','save'])});
  switched.state.detail.conversation=switched.c;switched.editor.sync(switched.c);await switched.editor.open(switched.c);
  check('returning to travel restores pending recovery without replaying the save',()=>{assert.equal(switched.document.getElementById('tourSelectionDialog').open,true);assert.equal(switched.document.getElementById('tsSaved').textContent,'Результат сохранения уточняется');assert.deepEqual(JSON.parse(switched.storage.get(switched.key)).pending,oldPending);assert.deepEqual(switched.calls.map(x=>x.action),['get','save','get'])});
  console.log('PASS '+checks+' editor host controls');
}
coreChecks().catch(error=>{console.error(error);process.exitCode=1});
