'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const root=path.resolve(__dirname,'..'),source=fs.readFileSync(path.join(root,'manager/assets/workspace-v2-tour-selection-host.js'),'utf8');
let checks=0;function check(name,fn){fn();checks++;console.log('PASS '+name)}
function fixture(hint=''){
  const c={id:11,manager_id:7,status:'manager'},state={authExpired:false,manager:{id:7,role:'manager'},detail:{conversation:c},current:11,authGeneration:3};
  let host,open=5,mobile=false;const requests=[],navigations=[],listeners={},replacements=[],roots=[];
  const editor={sync:c=>roots.push(c)},window={WorkspaceV2:{S:state,request:(...args)=>{requests.push(args);return Promise.resolve({ok:true})}},WorkspaceV2Conversation:{getOpenGeneration:()=>open},AnyTourSelectionEditor:{mount:h=>{host=h;return editor}},ManagerTime:{format:(...args)=>args.join('|')},matchMedia:()=>({matches:mobile}),open:(...args)=>navigations.push(args),addEventListener:(name,fn)=>listeners[name]=fn};
  const location={href:'https://app.anytoour.ru/manager2/'+hint,origin:'https://app.anytoour.ru'};
  const document={querySelector:selector=>{roots.push(selector);return {selector}}},history={state:null,replaceState:(...args)=>replacements.push(args)};
  vm.runInNewContext(source,{window,document,location,history,URL});
  return {host,state,c,window,requests,navigations,listeners,replacements,roots,editor,setOpen:value=>open=value,setMobile:value=>mobile=value};
}
check('current context includes the manager, conversation, authentication and open generations',()=>{const f=fixture(),t=f.host.capture();assert.deepEqual(JSON.parse(JSON.stringify(t)),{actor:7,conversation:11,auth:3,open:5});assert.equal(f.host.isCurrent(t),true)});
for(const [name,change] of [
 ['account change',f=>f.state.manager.id=8],['expired auth',f=>f.state.authExpired=true],
 ['same-account reauthentication',f=>f.state.authGeneration++],['conversation navigation',f=>f.state.current=12],
 ['detail replaced',f=>f.state.detail.conversation={id:12}],['same-conversation reopening',f=>f.setOpen(6)]
])check(name+' invalidates the old editor operation',()=>{const f=fixture(),t=f.host.capture();change(f);assert.equal(f.host.isCurrent(t),false);assert.equal(f.requests.length,0);assert.equal(f.navigations.length,0)});
check('only the authorized current conversation can launch',()=>{const f=fixture();assert.equal(f.host.canOpen(f.c),true);assert.equal(f.host.canOpen({id:12}),false);f.state.authExpired=true;assert.equal(f.host.canOpen(f.c),false)});
check('reassignment and closure remove edit capability without granting another owner',()=>{const f=fixture();assert.equal(f.host.canEdit(f.c),true);f.c.manager_id=8;assert.equal(f.host.canEdit(f.c),false);f.state.manager.role='admin';assert.equal(f.host.canEdit(f.c),true);f.c.status='closed';assert.equal(f.host.canEdit(f.c),false)});
check('valid collector hint is consumed once after matching canonical navigation',()=>{const f=fixture('?selection=11&collector_actor=7#conversation=11');assert.equal(f.host.takeCollectorIntent(f.host.capture()),true);assert.equal(f.host.takeCollectorIntent(f.host.capture()),false);assert.equal(f.replacements.length,1);assert.equal(f.requests.length,0)});
for(const hint of ['?selection=11&collector_actor=8#conversation=11','?selection=11&collector_actor=7#conversation=12','?selection=11&selection=12&collector_actor=7#conversation=11','?selection=011&collector_actor=7#conversation=11','?selection=11&collector_actor=7'])check('mismatched collector navigation stays closed: '+hint,()=>{const f=fixture(hint);assert.equal(f.host.takeCollectorIntent(f.host.capture()),false);assert.equal(f.requests.length,0)});
check('account recovery discards an old collector actor hint',()=>{const f=fixture('?selection=11&collector_actor=7#conversation=11');f.state.manager.id=8;f.host.activate();f.state.manager.id=7;assert.equal(f.host.takeCollectorIntent(f.host.capture()),false)});
check('request passes the original signal and currentness fence to canonical transport',()=>{const f=fixture(),data={conversation_id:11},options={signal:{fixture:true},isCurrent:()=>false};f.host.request('tour-selections-api.php','get',data,options);assert.equal(f.requests[0][2],data);assert.equal(f.requests[0][3],options);assert.equal(f.requests[0][0],'tour-selections-api.php')});
check('responsive launcher stays with its existing Manager2 owner',()=>{const f=fixture();assert.equal(f.host.launcher().selector,'.conversationTitleRow');f.setMobile(true);assert.equal(f.host.launcher().selector,'.m2ConversationMenu>div');f.listeners.resize();assert.equal(f.roots.at(-1),f.c);assert.equal(f.window.WorkspaceV2TourSelection,f.editor)});
check('collector opens only the existing same-origin bridge as an explicit host action',()=>{const f=fixture();assert.equal(f.navigations.length,0);f.host.openCollector(11);assert.equal(f.navigations.length,1);assert.deepEqual(f.navigations[0],['tour-selection-collector.php?conversation_id=11','_blank','noopener'])});
const core=fs.readFileSync(path.join(root,'manager/assets/workspace-v2-tour-selection.js'),'utf8');
check('canonical core loads without WorkspaceV2 or a workspace DOM and rejects an invalid host',()=>{const window={};vm.runInNewContext(core,{window});assert.equal(window.AnyTourSelectionEditor.contract,'tour-selection-editor-host-v1');assert.throws(()=>window.AnyTourSelectionEditor.mount({}),/Invalid selection editor host/)});
console.log('PASS '+checks+' editor host controls');
