const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-customer-name.js'),'utf8');
const quickSource=fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-quick-replies.js'),'utf8');
const baseName={customer_id:101,display_name:'Анна',original_display_name:'Анна',working_name:null,version:0,updated_at:null,updated_by_manager_name:null,can_edit:true};
const renamed={...baseName,display_name:'Анна — семейная поездка',working_name:'Анна — семейная поездка',version:1,updated_at:'2026-10-14 10:00:00',updated_by_manager_name:'Мария'};
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve}}
const tick=()=>new Promise(resolve=>setTimeout(resolve,0));
function harness(handler,{storage=new Map(),role='manager'}={}){
  const nodes=new Map(),calls=[],projected=[];let active=null,openGeneration=1;
  const S={current:11,manager:{id:5,display_name:'Мария',role},authGeneration:1,authExpired:false,detail:{conversation:{id:11,customer_id:101,manager_id:5,status:'manager',display_name:'Анна',original_display_name:'Анна'}}};
  function element(id=''){
    const listeners=new Map(),attrs=new Map();
    const node={id,value:'',textContent:'',hidden:false,disabled:false,className:'',isConnected:true,open:false,markup:'',
      addEventListener(type,callback){listeners.set(type,callback)},emit(type,event={}){return listeners.get(type)?.(event)},
      setAttribute(key,value){attrs.set(key,value)},getAttribute:key=>attrs.get(key),querySelector:()=>null,querySelectorAll:()=>[],
      focus(){active=node},showModal(){node.open=true},close(){node.open=false;node.emit('close')},remove(){node.isConnected=false},
      get innerHTML(){return node.markup},set innerHTML(value){node.markup=value;for(const match of value.matchAll(/id="([^"]+)"/g))nodes.set(match[1],element(match[1]))},
    };
    return node;
  }
  for(const id of ['customerNameOpen','customerNameCardOpen','replyText','replyFile','leadTaskTitle'])nodes.set(id,element(id));
  nodes.get('replyText').value='Не потерять черновик ответа';nodes.get('replyFile').value='Сохранить attachment';nodes.get('leadTaskTitle').value='Не потерять задачу';
  const W={S,$:id=>nodes.get(id)||null,esc:value=>String(value??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'),
    request:async(endpoint,action,data)=>{calls.push({endpoint,action,data});return handler(action,data,calls)}
  };
  const document={createElement:()=>element(),body:{appendChild(node){nodes.set(node.id,node)}},querySelector:()=>null,get activeElement(){return active}};
  const window={WorkspaceV2:W,crypto:require('node:crypto').webcrypto,ManagerTime:{format:value=>value},WorkspaceV2Conversation:{getOpenGeneration:()=>openGeneration,captureReplyContext:()=>({conversationId:S.current,managerId:S.manager.id,authGeneration:S.authGeneration,openGeneration})}};
  const context=vm.createContext({window,document,sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)},Uint8Array,setTimeout,clearTimeout,console});
  vm.runInContext(source,context);const api=window.WorkspaceV2CustomerName;
  api.configure({onUpdated:(name,target)=>{projected.push({name,target})}});
  return {api,nodes,S,calls,storage,projected,window,context,element,active:()=>active,setOpenGeneration:value=>openGeneration=value};
}
function input(h,value){h.nodes.get('cnInput').value=value;h.nodes.get('cnInput').emit('input')}
function submit(h){return h.nodes.get('cnForm').emit('submit',{preventDefault(){}})}
function get(name=baseName,history=[]){return {ok:true,name,history,history_has_more:false}}
(async()=>{
  let state=baseName;
  const full=harness((action,data)=>{if(action==='get')return get(state);if(action==='save'){state={...renamed,working_name:data.working_name,display_name:data.working_name||'Анна',version:data.version+1};return {ok:true,name:state}}throw Error(action)});
  assert.equal(full.calls.length,0,'module does not preload names or block transcript');
  full.nodes.get('customerNameCardOpen').focus();await full.api.open();
  input(full,'Анна — семейная поездка');submit(full);await tick();
  assert.equal(full.projected.length,1);assert.equal(full.projected[0].name.working_name,'Анна — семейная поездка');
  assert.equal(full.nodes.get('cnOriginal').textContent,'Анна');
  assert.equal(full.nodes.get('replyText').value,'Не потерять черновик ответа');assert.equal(full.nodes.get('replyFile').value,'Сохранить attachment');assert.equal(full.nodes.get('leadTaskTitle').value,'Не потерять задачу');
  full.nodes.get('customerNameCardOpen').isConnected=false;const replacement=full.element('customerNameCardOpen');full.nodes.set('customerNameCardOpen',replacement);
  full.api.close();assert.equal(full.active(),replacement,'close restores the replacement opener after identity repaint');
  console.log('PASS lazy rename preserves original identity, composer, files, task and replacement-opener focus');

  const conflictName={...renamed,display_name:'Анна — другое имя',working_name:'Анна — другое имя',version:2};let conflictSeen=false;
  const racing=harness((action,data)=>{if(action==='get')return get(conflictSeen?conflictName:baseName);if(action==='save'){if(data.version===0){conflictSeen=true;return {ok:false,error:'conflict',current:conflictName}}return {ok:true,name:{...conflictName,working_name:data.working_name,display_name:data.working_name,version:3}}}throw Error(action)});
  await racing.api.open();input(racing,'Мой вариант имени');submit(racing);await tick();
  assert.equal(racing.nodes.get('cnInput').value,'Мой вариант имени');assert.equal(racing.nodes.get('cnSave').disabled,true);assert.equal(racing.projected.length,0);
  racing.nodes.get('cnConflictKeep').onclick();assert.equal(racing.nodes.get('cnInput').value,'Мой вариант имени');assert.equal(racing.calls.filter(c=>c.action==='save').length,1,'reviewing a conflict never writes automatically');
  submit(racing);await tick();assert.equal(racing.calls.filter(c=>c.action==='save')[1].data.version,2);
  console.log('PASS conflict preserves typed name and needs explicit version review plus separate save');

  let attempted=0;
  const receipt=harness(action=>{
    if(action==='get')return get(attempted?conflictName:baseName);
    if(action==='save'){attempted++;return {ok:false,error:'network_error'}}
    if(action==='operation')return {ok:true,found:true,result:{ok:true,name:renamed}};
    throw Error(action);
  });
  await receipt.api.open();input(receipt,'Анна — семейная поездка');submit(receipt);await tick();
  assert.equal(receipt.nodes.get('cnSave').disabled,true);assert.equal(JSON.parse(receipt.storage.get('workspaceV2.customerNameDraft.5.101')).pending.data.working_name,'Анна — семейная поездка');
  await receipt.nodes.get('cnResolve').onclick();await tick();
  assert.equal(attempted,1);assert.deepEqual(receipt.projected.map(row=>row.name.version),[2],'historical v1 receipt cannot paint over current v2');assert.equal(receipt.nodes.get('cnEffective').textContent,'Анна — другое имя');assert.equal(receipt.storage.has('workspaceV2.customerNameDraft.5.101'),false);
  console.log('PASS unknown receipt confirms old write while only fresh current name is projected');

  let saved=0,reads=0;
  const retry=harness((action,data)=>{
    if(action==='get'){reads++;return reads===1?get():get(renamed)}
    if(action==='save'){saved++;return saved===1?{ok:true}:{ok:true,name:renamed}}
    if(action==='operation')return {ok:true,found:false};throw Error(action);
  });
  await retry.api.open();input(retry,'Анна — семейная поездка');submit(retry);await tick();
  assert.equal(retry.projected.length,0,'missing name in success payload is unknown, not confirmed');
  await retry.nodes.get('cnResolve').onclick();await tick();
  const writes=retry.calls.filter(c=>c.action==='save');assert.equal(writes.length,2);assert.deepEqual(writes[0].data,writes[1].data,'retry uses original context, payload and id only after a checked missing receipt');
  console.log('PASS invalid success keeps UNKNOWN and missing receipt permits same-command retry');

  let getCount=0;
  const failedRead=harness(action=>action==='save'?{ok:true,name:renamed}:++getCount===1?get():{ok:false,error:'network_error'});
  await failedRead.api.open();input(failedRead,'Анна — семейная поездка');submit(failedRead);await tick();
  assert.equal(failedRead.nodes.get('cnStatus').textContent,'Рабочее имя сохранено.');assert.equal(failedRead.projected.length,1);assert.equal(failedRead.storage.size,0);assert.match(failedRead.nodes.get('cnHistory').innerHTML,/Историю пока не удалось обновить/);
  console.log('PASS confirmed write success survives failed follow-up history read');

  const pendingRead=deferred();
  const isolated=harness(action=>action==='get'?pendingRead.promise:{ok:false});
  const opening=isolated.api.open();isolated.S.authGeneration++;isolated.api.resetSession();isolated.S.manager={id:6,role:'manager'};pendingRead.resolve(get(renamed));await opening;
  assert.equal(isolated.projected.length,0);assert.equal(isolated.nodes.get('customerNameDialog').open,false);
  console.log('PASS late account response is discarded and cannot paint another account');

  const salutation=harness(()=>get());salutation.S.detail.conversation.display_name='Анна — семейная поездка';
  vm.runInContext(quickSource,salutation.context);
  const qr=salutation.window.WorkspaceV2QuickReplies;
  salutation.window.WorkspaceV2.request=async()=>({ok:true,replies:[{id:1,title:'Приветствие',body:'Здравствуйте, {client_name}!',scope:'personal',version:1,can_edit:true}],categories:[],has_more:false});
  await qr.open();salutation.nodes.get('qrList').emit('click',{target:{closest:selector=>selector==='[data-qr-select]'?{dataset:{qrSelect:'1'}}:null}});
  assert.match(salutation.nodes.get('qrDetail').innerHTML,/Здравствуйте, Анна!/);assert.doesNotMatch(salutation.nodes.get('qrDetail').innerHTML,/Здравствуйте, Анна — семейная поездка!/);
  qr.resetSession();salutation.S.detail.conversation.original_display_name='';await qr.open();salutation.nodes.get('qrList').emit('click',{target:{closest:selector=>selector==='[data-qr-select]'?{dataset:{qrSelect:'1'}}:null}});
  assert.equal(salutation.nodes.get('qrInsert').disabled,true,'unknown original name never falls back to internal alias');
  console.log('PASS quick reply salutation uses original name and requires manual entry if original is unknown');
})().catch(error=>{console.error(error);process.exitCode=1});
