'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../manager/assets/workspace-v2-personal-workspace.js'),'utf8');
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return {resolve,promise}};
function harness(handler){
  const nodes=new Map(),storage=new Map(),calls=[],loads=[],queues=[],classes=new Set();let active=null;
  function element(id=''){
    const handlers=new Map(),attrs=new Map(),node={id,disabled:false,hidden:false,isConnected:true,open:false,value:'',checked:false,textContent:'',dataset:{},innerMarkup:'',children:[],parentNode:null,
      classList:{toggle(name,on){if(on)classes.add(name);else classes.delete(name)},remove(){},add(){}},
      addEventListener(type,cb){handlers.set(type,cb)},emit(type,event={}){return handlers.get(type)?.(event)},
      setAttribute(k,v){attrs.set(k,String(v))},getAttribute:k=>attrs.get(k),focus(){active=node},blur(){active=null},setSelectionRange(start,end){node.selectionStart=start;node.selectionEnd=end},
      showModal(){node.open=true},close(){node.open=false;node.emit('close')},appendChild(child){node.children.push(child);nodes.set(child.id,child)},
      insertBefore(child){node.children.push(child);nodes.set(child.id,child)},querySelector:()=>null,querySelectorAll:()=>[],
      get innerHTML(){return node.innerMarkup},set innerHTML(html){node.innerMarkup=html;for(const m of html.matchAll(/id="([^"]+)"/g))nodes.set(m[1],element(m[1]));for(const m of html.matchAll(/<(input|select)[^>]*id="([^"]+)"[^>]*>/g)){const n=nodes.get(m[2]);n.value=m[0].match(/value="([^"]*)"/)?.[1]||'';n.checked=/ checked/.test(m[0]);if(m[1]==='select'){const rest=html.slice(m.index+m[0].length).split('</select>')[0];n.value=rest.match(/value="([^"]+)" selected/)?.[1]||rest.match(/value="([^"]*)"/)?.[1]||''}}for(const m of html.matchAll(/<(button|input)[^>]*data-pw-([\w-]+)="([^"]*)"[^>]*>/g)){const n=element(),attr='data-pw-'+m[2];n.dataset[('pw-'+m[2]).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=m[3];nodes.set('['+attr+'="'+m[3]+'"]',n)}}
    };return node;
  }
  const body=element('body'),anchor=element('queueTabs'),parent=element();anchor.parentNode=parent;
  const S={manager:{id:5},authGeneration:1,authExpired:false,current:101,queue:'waiting',detail:{conversation:{id:101,display_name:'Павел'}},filterSources:[],leadStageFilter:'old',leadTagFilter:7,leadTaskFilter:'old',leadOutcomeFilter:'old'};
  const W={S,$:id=>nodes.get(id)||null,esc:v=>String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'),request:async(endpoint,action,data)=>{calls.push({endpoint,action,data});return handler(action,data)}};
  const document={body,createElement:()=>element(),querySelector:selector=>selector==='.queueTabs'?anchor:selector==='.leadHeaderActions'?parent:nodes.get(selector)||null,querySelectorAll:()=>[],get activeElement(){return active}};
  const window={WorkspaceV2:W,crypto:require('node:crypto').webcrypto,confirm:()=>true,WorkspaceV2Inbox:{load:async options=>{loads.push(options);return true},setQueue:(q,options)=>{queues.push({q,options});S.queue=q;return true}},WorkspaceV2Filters:{syncFilterUi(){},saveFilterState(){}}};
  vm.runInContext(source,vm.createContext({window,document,sessionStorage:{setItem:(k,v)=>storage.set(k,v),getItem:k=>storage.get(k),removeItem:k=>storage.delete(k)},Uint8Array,console}));
  return {api:window.WorkspaceV2PersonalWorkspace,S,nodes,storage,calls,loads,queues,classes,active:()=>active};
}
function snapshot(version=0,start='waiting'){return {ok:true,version,state:{tabs:[{id:7,name:'Telegram',queue:'mine',channel:'telegram',source_id:0,label_id:9,reply_needed:true,pinned_only:false}],labels:[{id:9,name:'Даты'},{id:10,name:'Семья'}],members:{9:[101],10:[101]},pins:[101],settings:{start_tab:start,density:'comfortable',font:'normal'}}}}
const tick=()=>new Promise(r=>setTimeout(r,0));
(async()=>{
  const h=harness(()=>snapshot());assert.equal(h.calls.length,0);h.api.bind();await h.api.activate(5);assert.equal(h.calls.length,1);assert.equal(h.loads.length,0,'background prefs never request Inbox');assert.equal(h.api.prepareInitialSelection(),'waiting');
  await h.api.selectSelection('tab:7');assert.equal(h.api.selectionScope(),'tab:7');assert.equal(h.api.prepareInitialSelection(),'tab:7','saved startup never overrides explicit selection');assert.equal(h.loads.length,1);assert.equal(h.S.leadStageFilter,'');assert.equal(h.S.leadTagFilter,0);assert.equal(h.S.leadTaskFilter,'');
  assert.equal(h.api.enhanceRows([{id:101}])[0].personal_labels.length,2,'one client can carry multiple private labels');assert.equal(h.api.enhanceRows([{id:101}])[0].personal_pinned,true);
  h.api.clearSelection();assert.equal(await h.api.fetchRows({}),null);assert.equal(h.api.prepareInitialSelection(),'');
  console.log('PASS lazy snapshot, one selected list, no startup replay, multiple labels and no CRM dependency');

  const delayed=deferred(),late=harness(()=>delayed.promise);const loading=late.api.activate(5);assert.equal(late.api.prepareInitialSelection(),'');late.api.clearSelection();late.S.queue='closed';delayed.resolve(snapshot(0,'tab:7'));await loading;assert.equal(late.S.queue,'closed');assert.equal(late.loads.length,0);assert.match(late.nodes.get('pwLane').innerHTML,/pwApplyStart/,'late startup offered explicitly, never jumps');
  console.log('PASS slow personal preferences do not delay or replace a user queue');

  const ready=harness(()=>snapshot(0,'tab:7'));await ready.api.activate(5);assert.equal(ready.api.prepareInitialSelection(),'tab:7');assert.equal(ready.S.queue,'mine');assert.equal(ready.loads.length,0);
  const bad=harness(action=>action==='list'?{ok:false,error:'forbidden',http_status:403}:snapshot());await bad.api.activate(5);await bad.api.selectSelection('pinned');await assert.rejects(()=>bad.api.fetchRows({search:'x'}),/personal_list_failed/);assert.equal(bad.calls.filter(c=>c.action==='list').length,1,'no all/mine fallback after selected failure');
  console.log('PASS saved startup applied only when ready and selected failures never broaden access');

  const read=deferred(),switching=harness((action,data)=>action==='snapshot'?read.promise:snapshot());const stale=switching.api.activate(5);switching.S.manager={id:6};switching.S.authGeneration++;const fresh=switching.api.activate(6);read.resolve(snapshot());await stale;await fresh;assert.equal(switching.api.enhanceRows([{id:101}])[0].personal_labels.length,2);
  const lateWrite=deferred();let saved=0;const switched=harness(action=>action==='snapshot'?snapshot():action==='pin'?(saved++,lateWrite.promise):snapshot());await switched.api.activate(5);const write=switched.api.mutate('pin',{conversation_id:101,enabled:false});assert.equal(await switched.api.mutate('pin',{conversation_id:101,enabled:false}),false);switched.S.manager={id:6};switched.S.authGeneration++;await switched.api.activate(6);lateWrite.resolve({...snapshot(1),state:{...snapshot(1).state,pins:[]}});await write;assert.equal(switched.api.enhanceRows([{id:101}])[0].personal_pinned,true,'late previous account write cannot leak into current prefs');assert.equal(saved,1);
  console.log('PASS one mutation flight and account-safe late write');

  let mutations=0;const uncertain=harness(action=>action==='snapshot'?snapshot():action==='operation'?{ok:true,found:true,result:snapshot(1)}:(mutations++,{ok:false,error:'network_error'}));await uncertain.api.activate(5);await uncertain.api.open();await uncertain.api.mutate('pin',{conversation_id:101,enabled:false});assert.match(uncertain.nodes.get('pwStatus').textContent,/неизвестен/);assert.equal(await uncertain.api.mutate('pin',{}),false);assert.equal(mutations,1);await uncertain.api.reconcile();assert.equal(uncertain.storage.size,0,'verified receipt clears pending');assert.equal(mutations,1,'receipt read never resends');
  console.log('PASS UNKNOWN disables mutation until positive receipt, never retries Send');

  const missing=harness(action=>action==='snapshot'?snapshot():action==='operation'?{ok:true,found:false}:{ok:false,error:'network_error'});await missing.api.activate(5);await missing.api.mutate('pin',{conversation_id:101,enabled:false});await missing.api.reconcile();assert.equal(await missing.api.mutate('pin',{}),false,'not-found readback requires explicit fresh version reconciliation');
  const conflict=harness(action=>action==='snapshot'?snapshot():{ok:false,error:'conflict'});await conflict.api.activate(5);await conflict.api.open();conflict.nodes.get('pwNewLabel').onclick();conflict.nodes.get('pwLabelName').value='Сохранённый черновик';conflict.nodes.get('pwLabelName').emit('input');await conflict.api.mutate('label_save',{id:0,name:'Сохранённый черновик'},'label');assert.ok([...conflict.storage.values()].some(value=>value.includes('Сохранённый черновик')));assert.equal(await conflict.api.mutate('label_save',{}),false);await conflict.nodes.get('pwRebaseConflict').onclick();assert.match(conflict.nodes.get('pwLabels').innerHTML,/Сохранённый черновик/);
  console.log('PASS missing receipt and conflict preserve draft, require explicit version reconciliation');

  const held=deferred();const create=harness(action=>action==='snapshot'?snapshot():held.promise);await create.api.activate(5);await create.api.open();create.nodes.get('pwNewLabel').onclick();create.nodes.get('pwLabelName').value='Семейные';create.nodes.get('pwLabelName').emit('input');const saving=create.api.mutate('label_save',{id:0,name:'Семейные'},'label');create.nodes.get('pwLabelName').value='Семейные туры';create.nodes.get('pwLabelName').emit('input');held.resolve({...snapshot(1),state:{...snapshot(1).state,labels:[...snapshot().state.labels,{id:11,name:'Семейные'}]}});await saving;const draft=JSON.parse([...create.storage.values()][0]);assert.equal(draft.labelDraft.name,'Семейные туры');assert.equal(draft.labelDraft.id,11,'newer input binds to created ID to prevent duplicate create');assert.match(create.nodes.get('pwLabels').innerHTML,/Семейные туры/);
  console.log('PASS typing during create retains newer draft and created ID');

  const settings=harness(action=>({...snapshot(1),state:{...snapshot().state,settings:{start_tab:'pinned',density:'compact',font:'large'}}}));await settings.api.activate(5);assert.ok(settings.classes.has('pwCompact'));assert.ok(settings.classes.has('pwLargeFont'));await settings.api.open();assert.match(settings.nodes.get('pwDialog').innerHTML,/Видно только вам/);settings.api.suspend();assert.equal(settings.classes.has('pwCompact'),false);assert.equal(settings.nodes.get('pwDialog').open,false);
  console.log('PASS account-scoped appearance, explicit private copy and auth suspension');

  const deleted=snapshot();deleted.state.labels=[];const dangling=harness(()=>deleted);await dangling.api.activate(5);await dangling.api.open();assert.match(dangling.nodes.get('pwTabs').innerHTML,/Метка удалена/,'dependent tab remains a bounded empty selection rather than silently broadening');
  console.log('PASS deleted label warning preserves personal selection semantics');

  let remote=snapshot(3),failList=false;
  remote.state.labels=[{id:9,name:'Даты с другого устройства'}];remote.state.members={9:[101,102]};remote.state.pins=[102];remote.state.settings.font='large';
  const savedRemote=snapshot(4);savedRemote.state.labels[0].name='После сохранения';
  const device=harness(action=>action==='snapshot'?snapshot(1):action==='list'?failList?{ok:false,error:'storage_unavailable',http_status:503}:{...remote,conversations:[{id:101},{id:102}],bounded_count:2,limited:true,count_scope:'loaded_visible',limit:200}:savedRemote);
  await device.api.activate(5);await device.api.selectSelection('tab:7');
  let deviceRows=await device.api.fetchRows({});assert.equal(deviceRows[0].personal_labels[0].name,'Даты с другого устройства');assert.equal(deviceRows[0].personal_pinned,false);assert.equal(deviceRows[1].personal_pinned,true);assert.ok(device.classes.has('pwLargeFont'));
  assert.match(device.nodes.get('pwLane').innerHTML,/Загружено 2 диалогов · до 200 за загрузку/);assert.match(device.nodes.get('pwLane').innerHTML,/не общее количество/);assert.equal(device.calls.filter(c=>c.action==='snapshot').length,1,'list adopts fresh state without an extra preferences read');
  await device.api.mutate('pin',{conversation_id:101,enabled:true});assert.equal(device.calls.at(-1).data.expected_version,3,'next mutation uses the other device revision');
  remote=snapshot(2);deviceRows=await device.api.fetchRows({});assert.equal(deviceRows[0].personal_labels[0].name,'После сохранения','old response cannot regress newly adopted revision4 state');
  failList=true;await assert.rejects(()=>device.api.fetchRows({}),/personal_list_failed/);assert.doesNotMatch(device.nodes.get('pwLane').innerHTML,/pwListScope/,'failed read removes stale bounded-count claim');device.api.clearSelection();assert.doesNotMatch(device.nodes.get('pwLane').innerHTML,/Загружено/);
  console.log('PASS refreshed personal list adopts newer device state/version, rejects regression and shows loaded-only bounds');

  const large=snapshot(1);large.state.labels=Array.from({length:30},(_,i)=>({id:i+1,name:'Метка '+(i+1)}));large.state.members=Object.fromEntries(large.state.labels.map(label=>[label.id,Array.from({length:2000},(_,i)=>i+1)]));large.state.pins=Array.from({length:2000},(_,i)=>i+1);
  const indexed=harness(()=>large);await indexed.api.activate(5);const batch=Array.from({length:200},(_,i)=>({id:i+1}));for(let i=0;i<4;i++){const decorated=indexed.api.enhanceRows(batch);assert.equal(decorated.length,200);assert.equal(decorated[199].personal_labels.length,30);assert.equal(decorated[0].personal_pinned,true);decorated[0].personal_labels[0].name='Локальный потребитель';assert.equal(indexed.api.enhanceRows([{id:1}])[0].personal_labels[0].name,'Метка 1','consumers cannot mutate indexed account markers')}
  assert.equal(indexed.calls.length,1,'large marker batches add no HTTP reads');
  console.log('PASS bounded large marker batches preserve all memberships without extra HTTP or consumer mutation');

  const focus=harness(()=>snapshot());await focus.api.activate(5);const oldTab=focus.nodes.get('[data-pw-selection="tab:7"]');oldTab.focus();await focus.api.snapshot();assert.notEqual(focus.active(),oldTab,'refresh replaces the lane node');assert.equal(focus.active(),focus.nodes.get('[data-pw-selection="tab:7"]'),'keyboard focus follows the fresh same tab');
  const opener=focus.nodes.get('pwOpen');opener.focus();await focus.api.open();await focus.api.mutate('settings',{settings:snapshot().state.settings},'settings');assert.notEqual(focus.nodes.get('pwOpen'),opener,'successful save replaced opener node');focus.nodes.get('pwClose').onclick();assert.equal(focus.active(),focus.nodes.get('pwOpen'),'close after save resolves fresh opener rather than detached node');
  focus.nodes.get('pwOpen').focus();await focus.api.open();const membership=focus.nodes.get('[data-pw-membership="9"]');membership.focus();await focus.api.snapshot();assert.equal(focus.active(),focus.nodes.get('[data-pw-membership="9"]'),'snapshot render preserves membership focus');
  const beforeSave=focus.nodes.get('[data-pw-membership="9"]');beforeSave.focus();await focus.api.mutate('label_membership',{conversation_id:101,label_id:9,enabled:true});assert.notEqual(focus.active(),beforeSave);assert.equal(focus.active(),focus.nodes.get('[data-pw-membership="9"]'),'membership save retains same checkbox focus');
  console.log('PASS lane, successful dialog close and membership refresh/save preserve stable keyboard focus');

  const staleList=deferred();let listCalls=0;
  const latest=harness(action=>action==='snapshot'?snapshot(1):action==='list'?(++listCalls===1?staleList.promise:{ok:false,error:'storage_unavailable',http_status:503}):snapshot());await latest.api.activate(5);await latest.api.selectSelection('tab:7');const oldList=latest.api.fetchRows({search:'старое'});const observed=assert.rejects(oldList,/stale_personal_selection/);latest.S.leadSearch='новое';await assert.rejects(()=>latest.api.fetchRows({search:'новое'}),/personal_list_failed/);const staleState=snapshot(8);staleState.state.labels[0].name='Нельзя принять старый запрос';staleList.resolve({...staleState,conversations:[{id:101}],bounded_count:1,limit:200,limited:false,count_scope:'loaded_visible'});await observed;
  assert.equal(latest.api.enhanceRows([{id:101}])[0].personal_labels[0].name,'Даты','obsolete search result cannot adopt private state');assert.doesNotMatch(latest.nodes.get('pwLane').innerHTML,/pwListScope/,'obsolete success cannot restore count after latest failed search');assert.equal(latest.calls.filter(c=>c.action==='list').length,2);
  console.log('PASS stale same-tab list cannot adopt state/count after a newer failed filter request');

  for(const outcome of ['success','conflict','rejected']){
    const waiting=deferred(),editing=harness(action=>action==='snapshot'?snapshot():waiting.promise);await editing.api.activate(5);await editing.api.open();editing.nodes.get('pwNewLabel').onclick();editing.nodes.get('pwLabelName').value='До сохранения';editing.nodes.get('pwLabelName').emit('input');editing.nodes.get('pwLabelSave').focus();const savingEdit=editing.api.mutate('label_save',{id:0,name:'До сохранения'},'label');editing.nodes.get('pwLabelName').focus();editing.nodes.get('pwLabelName').value='Новый ввод во время запроса';editing.nodes.get('pwLabelName').emit('input');editing.nodes.get('pwLabelName').setSelectionRange(3,9);
    waiting.resolve(outcome==='success'?{...snapshot(2),id:11,state:{...snapshot().state,labels:[...snapshot().state.labels,{id:11,name:'До сохранения'}]}}:{ok:false,error:outcome==='conflict'?'conflict':'forbidden'});await savingEdit;assert.equal(editing.active(),editing.nodes.get('pwLabelName'),outcome+' result cannot steal focus from newer input');assert.equal(editing.active().selectionStart,3);assert.equal(editing.active().selectionEnd,9);assert.equal(editing.active().value,'Новый ввод во время запроса');
  }
  console.log('PASS success, conflict and rejection retain newer input focus and caret during held save');
})().catch(error=>{console.error(error);process.exitCode=1});
