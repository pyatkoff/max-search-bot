const assert=require('node:assert/strict');
const {createHarness,response,deferred,assertNoMutations}=require('./run_manager_reauth_composer_behavior_regression.js');

// Real Workspace, Conversation, Tasks, TaskDraft and promise UI assets. The DOM
// adapter models only controls/events; browser layout and File use the visual test.
const clone=value=>JSON.parse(JSON.stringify(value));
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const cases=[],test=(name,run)=>cases.push({name,run});
function storage(){const values=new Map();return{values,get length(){return values.size},key:index=>[...values.keys()][index]??null,getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,String(value)),removeItem:key=>values.delete(key)}}
function adaptDom(h){
  const proto=Object.getPrototypeOf(h.document.createElement('div')),baseMatches=proto.matches,baseQuery=proto.querySelectorAll;
  const textContent=Object.getOwnPropertyDescriptor(proto,'textContent');
  Object.defineProperty(proto,'textContent',{...textContent,get(){return this._text+this.children.map(child=>child.textContent).join('')}});
  const innerHTML=Object.getOwnPropertyDescriptor(proto,'innerHTML');
  Object.defineProperty(proto,'innerHTML',{...innerHTML,set(value){
    innerHTML.set.call(this,value);
    // The base ID parser only accepts alphabetic tags; headings are standard DOM.
    for(const match of String(value).matchAll(/<(h[1-6])\b[^>]*\bid="([^"]+)"[^>]*>/g)){
      const child=h.document.createElement(match[1]);child.id=match[2];this.appendChild(child);
    }
    if(String(value).includes('<div class="taskCreate">')){
      const form=h.document.createElement('div');form.className='taskCreate';
      for(const id of ['leadTaskTitle','leadTaskDue','leadTaskAdd','leadTaskCreateStatus']){const control=this.querySelector('#'+id);if(control)form.appendChild(control);}
      this.appendChild(form);
    }
    if(String(value).includes('data-task-action-status')){const status=h.document.createElement('div');status.dataset.taskActionStatus='';this.appendChild(status);}
  }});
  proto.append=function(...nodes){for(const node of nodes)this.appendChild(typeof node==='string'?h.document.createTextNode(node):node)};
  proto.before=function(node){this.parentNode?.insertBefore(node,this)};
  proto.after=function(node){this.parentNode?.insertBefore(node,this.nextSibling)};
  proto.getAttribute=function(name){if(name.startsWith('data-'))return this.dataset[name.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]??null;return this[name]??null};
  proto.setAttribute=function(name,value){if(name.startsWith('data-'))this.dataset[name.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(value);else this[name]=String(value)};
  proto.matches=function(selector){const excluded=[...selector.matchAll(/:not\(([^)]+)\)/g)].map(match=>match[1]);return !excluded.some(part=>baseMatches.call(this,part))&&baseMatches.call(this,selector.replace(/:not\([^)]+\)/g,''))};
  proto.querySelectorAll=function(selector){
    if(selector.includes(','))return [...new Set(selector.split(',').flatMap(part=>this.querySelectorAll(part.trim())))];
    if(selector.startsWith(':scope > '))return this.children.filter(child=>child.matches(selector.slice(9)));
    if(selector.startsWith('> '))return this.children.filter(child=>child.matches(selector.slice(2)));
    return baseQuery.call(this,selector);
  };
  proto.closest=function(selector){for(let node=this;node;node=node.parentNode)if(node.matches(selector))return node;return null};
  proto.focus=function(){h.document.activeElement=this};
  proto.showModal=function(){this.open=true};
  proto.close=function(){this.open=false;for(const handler of this.handlers.close||[])handler({target:this,preventDefault(){}})};
  proto.dispatch=async function(name,extra={}){const event={type:name,target:this,currentTarget:this,preventDefault(){},stopPropagation(){},...extra};await this['on'+name]?.(event);for(const handler of this.handlers[name]||[])await handler(event)};
  proto.click=async function(){if(!this.disabled)return this.dispatch('click')};
  h.document.querySelector=selector=>h.document.querySelectorAll(selector)[0]||null;
  h.document.body.classList.add('manager2');h.window.localStorage=h.storage;h.window.sessionStorage=h.storage;
}
function serverState(){return{
  tasks:[{id:77,conversation_id:101,title:'Подготовить согласованный расчёт',due_at_utc:'2030-11-20 12:00:00',status:'open',is_pinned:true,assigned_manager_id:7},
    {id:78,conversation_id:101,title:'Подготовить подборку',due_at_utc:null,status:'open',is_pinned:false,assigned_manager_id:7}],
  profile:{trip_id:'a'.repeat(32),version:3,sha256:'b'.repeat(64),context_sha256:'c'.repeat(64),boundary_message_id:10,status:'fresh',label:'Зимняя поездка',
    fields:{dates:{value:'20 ноября, 7 ночей',kind:'manager_interpretation',importance:'hard',note:'',citation:{message_id:10,sender_type:'manager',quote:'20 ноября, 7 ночей',sha256:'f'.repeat(64)}}},field_labels:{dates:'Даты поездки'}},
  sources:{12:{id:12,revision:'d'.repeat(64),author_manager_id:7,text:'Пришлю согласованный расчёт.',recorded_at_utc:'2026-11-13T09:00:00Z'},
    13:{id:13,revision:'e'.repeat(64),author_manager_id:7,text:'Подготовлю подборку отелей.',recorded_at_utc:null}},
  records:new Map(),canEdit:true,degraded:[],canBind:true,
}}
async function setup(options={}){
  const h=createHarness({storage:options.storage||storage(),mobile:true});adaptDom(h);const server=options.server||serverState();let intercept=null;
  const sourceMessages=()=>Object.values(server.sources).map(source=>({id:source.id,direction:'outbound',sender_type:'manager',sender_id:'7',manager_id:7,channel:'max',external_message_id:'synthetic-'+source.id,text:source.text,created_at:'2026-11-13 09:00:00'}));
  const lead=cid=>({ok:true,can_edit_pipeline:server.canEdit,degraded_components:[...server.degraded],tasks:clone(server.tasks).map(task=>({...task,conversation_id:cid})),pipeline:{stage:null,tags:[],outcome:{outcome:'open'}},trip:{},contact:{},source:{},handoff:{}});
  const context=request=>({ok:true,viewer_id:7,conversation_id:request.conversation_id,can_bind:server.canBind&&server.tasks.find(task=>task.id===request.task_id)?.status==='open',
    task:{...clone(server.tasks.find(task=>task.id===request.task_id)),conversation_id:request.conversation_id},profile:clone(server.profile),source:clone(server.sources[request.source_message_id]),binding:null});
  const success=request=>{
    const task={...clone(server.tasks.find(task=>task.id===request.task_id)),conversation_id:request.conversation_id};
    const binding={promise_id:'f'.repeat(64),conversation_id:request.conversation_id,trip_id:request.trip_id,kind:request.kind,task_id:task.id,
      source_message_id:request.source_message_id,source_message_revision:request.source_message_revision,source_author_manager_id:7,
      profile_version:request.profile_version,profile_sha256:request.profile_sha256,deadline:clone(request.deadline),created_by_manager_id:7,created_at:'2026-11-13 10:00:00',task};
    const result={ok:true,viewer_id:7,id:task.id,promise_id:binding.promise_id,replayed:false,reused:false,binding,task};server.records.set(request.operation_id,clone(result));return result;
  };
  h.setHook(async(url,request)=>{
    if(intercept){const result=await intercept(url,request);if(result!==undefined&&result!==null)return result;}
    if(url==='api.php'&&request.action==='detail')return response({ok:true,conversation:{...h.conversation,id:request.conversation_id,project_key:'anytour'},messages:sourceMessages()});
    if(url!=='pipeline-api.php')return null;
    if(request.action==='detail')return response(lead(request.conversation_id));
    if(request.action==='promise_context')return response(context(request));
    if(request.action==='bind_promise')return response(success(request));
    if(request.action==='promise_operation'){
      const saved=server.records.get(request.operation_id),task=saved?{...clone(server.tasks.find(item=>item.id===saved.id)),conversation_id:request.conversation_id}:null;
      return response({ok:true,viewer_id:7,found:!!saved,promise_id:saved?.promise_id??null,task_id:saved?.id??null,task});
    }
    return null;
  });
  await h.start();await tick();assert.equal(h.W.S.detail.lead.ok,true,'initial canonical card read has settled before the feature scenario');
  h.loadAsset('workspace-v2-tasks.js',{AbortController,clearTimeout});h.loadAsset('workspace-v2-task-draft.js');h.loadAsset('workspace-v2-promise-tasks.js',{AbortController,clearTimeout});
  const P=h.window.WorkspaceV2PromiseTasks;assert.ok(P,'production promise module is loaded');
  let cardRenders=0,refreshes=0,opens=0;const realOpen=h.C.open;
  h.C.open=async(...args)=>{opens++;return realOpen(...args)};h.C.refreshLeadData=async()=>{refreshes++;throw Error('promise UI must not refresh the whole lead card')};
  h.window.WorkspaceV2LeadCard.render=()=>{cardRenders++};
  const $=id=>h.ids.get(id);
  const add=(id,tag='input')=>{const node=h.document.createElement(tag);node.id=id;h.document.body.appendChild(node);return node};
  const taskRoot=add('promiseTestTasks','section');h.window.WorkspaceV2Tasks.render(taskRoot,{tasks:[],canEdit:true,conversationId:101});
  $('leadTaskTitle').value='Незавершённая ручная задача';await $('leadTaskTitle').dispatch('input');$('leadTaskDue').value='2031-02-03T12:30';await $('leadTaskDue').dispatch('change');
  const edit=add('promiseTestDirtyEdit','div');edit.className='taskEditForm';edit._taskEditIsDirty=()=>true;
  const editTitle=add('promiseTestEditTitle');editTitle.dataset.taskEditTitle='';editTitle.value='Ручная правка существующей задачи';edit.appendChild(editTitle);
  const editDue=add('promiseTestEditDue');editDue.dataset.taskEditDue='';editDue.value='2031-02-04T14:00';edit.appendChild(editDue);
  await h.draft('Незавершённый ответ туристу');const file={name:'private-synthetic.pdf'};h.setFile(file);
  const snapshot=()=>({reply:$('replyText').value,file:h.getFile(),title:$('leadTaskTitle').value,due:$('leadTaskDue').value,editTitle:editTitle.value,editDue:editDue.value,
    draft:h.storage.getItem(h.window.WorkspaceV2TaskDraft.keyFor(101)),detail:h.W.S.detail,lead:h.W.S.detail.lead});
  const preserved=before=>{
    const now=snapshot();for(const key of ['reply','file','title','due','editTitle','editDue','draft','detail','lead'])assert.equal(now[key],before[key],key+' stays owned by the current workspace');
    assert.equal($('leadTaskTitle').isConnected,true);assert.equal(editTitle.isConnected,true);assert.equal(cardRenders,0);assert.equal(refreshes,0);assert.equal(opens,0);
    assertNoMutations(h);assert.equal(h.calls.some(call=>['create_task','update_task','set_task_completed','set_task_pinned','ai_draft','decision'].includes(call.action)),false);
  };
  return Object.assign(h,{P,$,server,taskRoot,lead,contextResponse:context,success,setIntercept:fn=>intercept=fn,snapshot,preserved,
    navigate:id=>realOpen(id),writes:()=>h.calls.filter(call=>call.action==='bind_promise'),sourceMessages,
    async clearTaskDraftsForNavigation(){
      $('leadTaskTitle').value='';await $('leadTaskTitle').dispatch('input');$('leadTaskDue').value='';await $('leadTaskDue').dispatch('change');
      h.window.WorkspaceV2TaskDraft.clear(h.window.WorkspaceV2TaskDraft.keyFor(101));edit.remove();
    }});
}
async function field(h,id,value,type='change'){h.$(id).value=value;await h.$(id).dispatch(type);}
async function choose(h,{task=77,kind='send_calculation',mode='none',date='2030-11-20',instant='2030-11-20T15:45'}={}){
  await field(h,'promiseTaskTask',String(task));await field(h,'promiseTaskKind',kind);await field(h,'promiseTaskDeadlineMode',mode);
  if(mode==='date')await field(h,'promiseTaskDate',date);if(mode==='instant')await field(h,'promiseTaskInstant',instant);
}
async function ready(h,options){assert.equal(await h.P.open(12),true);await choose(h,options);assert.equal(await h.P.preview(),true);}

test('opening and preview stay read-only; one explicit confirmation preserves reply, File and task drafts',async()=>{
  const h=await setup(),before=h.snapshot();assert.equal(h.calls.some(call=>call.action.startsWith('promise_')||call.action==='bind_promise'),false);
  h.P.decorate();const buttons=h.document.querySelectorAll('.promiseTaskSourceButton');assert.equal(buttons.length,2);assert.equal(buttons[0].dataset.promiseSourceId,'12');
  await ready(h,{mode:'date'});assert.equal(h.writes().length,0);h.preserved(before);
  const pending=deferred();h.setIntercept((url,request)=>request.action==='bind_promise'?pending.promise:undefined);
  const writing=h.P.confirm();await tick();assert.equal(h.writes().length,1);await h.P.confirm();assert.equal(h.writes().length,1);
  const sent=h.writes()[0];assert.deepEqual(Object.keys(sent).sort(),['url','action','csrf','conversation_id','operation_id','task_id','kind','trip_id','profile_version','profile_sha256','profile_context_sha256','source_message_id','source_message_revision','deadline'].sort());
  assert.equal(sent.task_id,77);assert.equal(sent.source_message_id,12);assert.equal(sent.profile_version,3);assert.equal(sent.source_message_revision,'d'.repeat(64));assert.match(sent.operation_id,/^[a-f0-9]{32}$/);
  assert.deepEqual(sent.deadline,{kind:'date',value:'2030-11-20',zone:'Europe/Moscow'});
  pending.resolve(response(h.success(sent)));assert.equal(await writing,true);h.P.close();h.preserved(before);
});

test('late preview does not erase new manual input or replace a newer source selection',async()=>{
  const h=await setup(),pending=deferred();await h.P.open(12);await choose(h);h.setIntercept((url,request)=>request.action==='promise_context'&&request.source_message_id===12?pending.promise:undefined);
  const preview=h.P.preview();await tick();h.$('leadTaskTitle').value='Новая ручная правка во время чтения';await h.$('leadTaskTitle').dispatch('input');const before=h.snapshot();
  const old=h.calls.findLast(call=>call.action==='promise_context');h.setIntercept(null);assert.equal(await h.P.open(13),true);
  // Native dialog.close queues its event: it may arrive after an immediate reopen.
  await h.$('promiseTaskDialog').dispatch('close');assert.equal(h.$('promiseTaskDialog').open,true);
  await choose(h,{kind:'send_selection'});await h.P.preview();
  pending.resolve(response(h.contextResponse(old)));assert.equal(await preview,false);assert.match(h.$('promiseTaskSource').textContent+h.$('promiseTaskSource').innerHTML,/Подготовлю подборку/);
  h.preserved(before);assert.equal(h.writes().length,0);
});

test('editing a reviewed choice revokes confirmation and a context conflict never auto-submits',async()=>{
  const h=await setup();await ready(h);await field(h,'promiseTaskTask','78');assert.equal(await h.P.confirm(),false);assert.equal(h.writes().length,0);
  await h.P.preview();h.setIntercept((url,request)=>request.action==='bind_promise'?response({ok:false,viewer_id:7,error:'context_changed'},409):undefined);
  assert.equal(await h.P.confirm(),false);assert.equal(h.writes().length,1);await h.P.confirm();assert.equal(h.writes().length,1);
  assert.match(h.$('promiseTaskStatus').textContent,/изменились/);
  assert.equal(await h.P.save(),false);assert.equal(h.writes().length,1,'new Save first displays the fresh changed context without another write');
  assert.match(h.$('promiseTaskStatus').textContent,/Пока ничего не сохранено/);
});

for(const change of ['A to B','A to B to A','account'])test('late context is fenced after '+change,async()=>{
  const h=await setup(),pending=deferred();await h.P.open(12);await choose(h);h.setIntercept((url,request)=>request.action==='promise_context'?pending.promise:undefined);
  const reading=h.P.preview();await tick();const request=h.calls.findLast(call=>call.action==='promise_context');h.setIntercept(null);
  if(change==='account'){h.W.S.manager={...h.W.S.manager,id:8};h.W.S.authGeneration++;h.P.reset(true);}
  else{await h.clearTaskDraftsForNavigation();assert.equal(await h.navigate(102),true);assert.equal(h.W.S.current,102);if(change==='A to B to A'){assert.equal(await h.navigate(101),true);assert.equal(h.W.S.current,101);}}
  await h.draft('Новый текущий ответ');const file={name:'new-current.pdf'};h.setFile(file);const active=h.document.activeElement;
  pending.resolve(response(h.contextResponse(request)));assert.equal(await reading,false);assert.equal(h.writes().length,0);
  assert.equal(h.$('replyText').value,'Новый текущий ответ');assert.equal(h.getFile(),file);assert.equal(h.document.activeElement,active);
});

test('UNKNOWN check confirms the original operation without another bind or task completion claim',async()=>{
  const h=await setup(),before=h.snapshot();await ready(h);h.setIntercept((url,request)=>{if(request.action==='bind_promise'){h.success(request);throw Error('synthetic response lost')}return undefined});
  assert.equal(await h.P.confirm(),false);await h.P.confirm();assert.equal(h.writes().length,1);assert.ok(h.$('promiseTaskConfirm').hidden||h.$('promiseTaskConfirm').disabled);
  h.server.tasks[0].status='done';h.server.tasks[0].title='Текущая ручная правка';h.setIntercept(null);
  assert.equal(await h.P.check(),true);assert.equal(h.writes().length,1);assert.doesNotMatch(h.$('promiseTaskStatus').textContent,/Обещание выполнено|Задача выполнена/);h.preserved(before);
});

test('UNKNOWN survives reopen; missing receipt only enables explicit identical retry',async()=>{
  const h=await setup();await ready(h);h.setIntercept((url,request)=>{if(request.action==='bind_promise')throw Error('synthetic request lost');return undefined});
  await h.P.confirm();const original=clone(h.writes()[0]);h.P.close();
  const next=await setup({storage:h.storage,server:h.server});await next.P.open(12);assert.equal(next.writes().length,0);
  assert.equal(await next.P.check(),false);assert.equal(next.writes().length,0);assert.equal(next.$('promiseTaskRetry').hidden,false);
  assert.equal(await next.P.retry(),true);assert.equal(next.writes().length,1);assert.deepEqual(next.writes()[0],original);
});

test('deadline choices preserve date precision and produce a whole-second Moscow instant',async()=>{
  for(const [options,expected] of [[{mode:'none'},null],[{mode:'date'},{kind:'date',value:'2030-11-20',zone:'Europe/Moscow'}],
    [{mode:'instant'},{kind:'instant',value:'2030-11-20T12:45:00Z'}]]){
    const h=await setup();await ready(h,options);assert.equal(await h.P.confirm(),true);assert.deepEqual(h.writes()[0].deadline,expected);
  }
  const h=await setup();await h.P.open(12);await choose(h,{mode:''});assert.equal(await h.P.preview(),false);assert.equal(h.writes().length,0);
});

test('readonly or degraded tasks and rejected context never become an authorized confirmation',async()=>{
  for(const detail of [{canEdit:false},{degraded:['tasks']}]){
    const h=await setup();Object.assign(h.server,detail);await h.P.open(12);assert.equal(await h.P.confirm(),false);assert.equal(h.writes().length,0);
  }
  for(const patch of [{can_bind:false},{viewer_id:8},{conversation_id:102},{source:{id:13,revision:'e'.repeat(64),author_manager_id:7,text:'Чужой источник',recorded_at_utc:null}}]){
    const h=await setup(),before=h.snapshot();await h.P.open(12);await choose(h);
    h.setIntercept((url,request)=>request.action==='promise_context'?response({...h.contextResponse(request),...patch}):undefined);
    await h.P.preview();assert.equal(await h.P.confirm(),false);assert.equal(h.writes().length,0);h.preserved(before);
  }
  const h=await setup();h.server.tasks[0].status='done';await h.P.open(12);await choose(h);
  h.setIntercept((url,request)=>{
    if(request.action!=='promise_context')return undefined;
    const result=h.contextResponse(request);result.binding={promise_id:'f'.repeat(64),conversation_id:101,trip_id:h.server.profile.trip_id,kind:'send_calculation',
      task_id:77,source_message_id:12,source_message_revision:h.server.sources[12].revision,source_author_manager_id:7,
      profile_version:2,profile_sha256:'b'.repeat(64),deadline:null,created_by_manager_id:7,created_at:'2026-11-12 10:00:00',task:clone(result.task)};
    return response(result);
  });
  await h.P.preview();assert.equal(await h.P.confirm(),false);assert.equal(h.writes().length,0);
  assert.match(h.$('promiseTaskTaskSummary').textContent+h.$('promiseTaskTaskSummary').innerHTML,/Подготовить согласованный расчёт/);
  const empty=await setup();empty.server.profile.fields=[];await ready(empty);assert.equal(await empty.P.confirm(),true);
});

test('message action stays inside a collapsed native menu, returns focus and clears on access change',async()=>{
  const h=await setup(),before=h.snapshot();h.P.decorate();
  const menus=h.document.querySelectorAll('.promiseTaskMessageMenu');assert.equal(menus.length,2);
  const menu=menus[0],toggle=menu.querySelector('summary'),action=menu.querySelector('.promiseTaskSourceButton');
  assert.equal(menu.tagName,'DETAILS');assert.ok(!menu.open);assert.equal(action.parentNode,menu);assert.equal(toggle.textContent,'⋯');
  assert.equal(toggle.getAttribute('aria-label'),'Действия с сообщением');
  assert.equal(h.document.querySelectorAll('.promiseTaskSourceButton').every(button=>button.parentNode.tagName==='DETAILS'),true);
  menu.open=true;await action.click();assert.equal(menu.open,false);assert.equal(h.$('promiseTaskDialog').open,true);assert.equal(h.writes().length,0);
  h.P.close();assert.equal(h.document.activeElement,toggle);h.preserved(before);
  menu.open=true;await menu.dispatch('keydown',{key:'Escape'});assert.equal(menu.open,false);assert.equal(h.document.activeElement,toggle);
  await action.click();h.W.S.logoutPending=true;h.P.decorate();
  assert.equal(h.document.querySelectorAll('.promiseTaskMessageMenu').length,0);assert.equal(h.$('promiseTaskDialog').open,false);assert.equal(h.writes().length,0);
});

test('one Save reads fresh context then binds once while preserving all unrelated drafts and File',async()=>{
  const h=await setup(),before=h.snapshot();await h.P.open(12);await choose(h,{mode:'date'});assert.equal(h.writes().length,0);
  const pending=deferred();h.setIntercept((url,request)=>request.action==='bind_promise'?pending.promise:undefined);
  const saving=h.P.save();await tick();assert.equal(h.calls.filter(call=>call.action==='promise_context').length,1);assert.equal(h.writes().length,1);
  assert.equal(await h.P.save(),false);assert.equal(h.writes().length,1);
  assert.deepEqual(h.writes()[0].deadline,{kind:'date',value:'2030-11-20',zone:'Europe/Moscow'});
  pending.resolve(response(h.success(h.writes()[0])));assert.equal(await saving,true);h.preserved(before);
});

test('failed context read cannot bind and needs-review requires an unchanged explicitly reviewed summary',async()=>{
  const h=await setup(),before=h.snapshot();await h.P.open(12);await choose(h);
  h.setIntercept((url,request)=>request.action==='promise_context'?response({ok:false,error:'network_error'},503):undefined);
  assert.equal(await h.P.save(),false);assert.equal(h.writes().length,0);assert.match(h.$('promiseTaskStatus').textContent,/Ничего не сохранено/);
  h.setIntercept(null);h.server.profile.status='needs_review';assert.equal(await h.P.save(),false);assert.equal(h.writes().length,0);
  assert.equal(h.$('promiseTaskReview').hidden,false);assert.match(h.$('promiseTaskStatus').textContent,/ещё раз/);
  h.server.profile.version++;h.server.profile.sha256='9'.repeat(64);
  assert.equal(await h.P.save(),false);assert.equal(h.writes().length,0,'a changed version is not the summary the user acknowledged');
  assert.equal(await h.P.save(),true);assert.equal(h.writes().length,1);assert.equal(h.writes()[0].profile_version,4);h.preserved(before);
});

test('changed source text is reviewed explicitly and UNKNOWN keeps the original operation',async()=>{
  const h=await setup(),before=h.snapshot();await h.P.open(12);await choose(h);
  h.server.sources[12].text='Расчёт пришлю после подтверждения дат.';h.server.sources[12].revision='8'.repeat(64);
  assert.equal(await h.P.save(),false);assert.equal(h.writes().length,0);assert.match(h.$('promiseTaskSource').textContent,/после подтверждения/);
  h.setIntercept((url,request)=>{if(request.action==='bind_promise'){h.success(request);throw Error('lost response')}return undefined;});
  assert.equal(await h.P.save(),false);assert.equal(h.writes().length,1);assert.equal(h.writes()[0].source_message_revision,'8'.repeat(64));
  const reads=h.calls.filter(call=>call.action==='promise_context').length;
  assert.equal(await h.P.save(),false);assert.equal(h.calls.filter(call=>call.action==='promise_context').length,reads);assert.equal(h.writes().length,1);
  h.setIntercept(null);assert.equal(await h.P.check(),true);assert.equal(h.writes().length,1);h.preserved(before);
});

test('changed displayed task title or due requires explicit review and a newly completed task cannot bind',async()=>{
  for(const patch of [{title:'Новая цель существующей задачи'},{due_at_utc:'2033-01-01 10:00:00'}]){
    const h=await setup(),before=h.snapshot();await h.P.open(12);await choose(h,{mode:'date'});
    Object.assign(h.server.tasks[0],patch);assert.equal(await h.P.save(),false);assert.equal(h.writes().length,0);
    assert.match(h.$('promiseTaskStatus').textContent,/изменились/);assert.equal(h.$('promiseTaskReview').hidden,false);
    assert.equal(await h.P.save(),true);assert.equal(h.writes().length,1);assert.equal(h.writes()[0].task_id,77);
    assert.deepEqual(h.writes()[0].deadline,{kind:'date',value:'2030-11-20',zone:'Europe/Moscow'});h.preserved(before);
  }
  const h=await setup();await h.P.open(12);await choose(h);h.server.tasks[0].status='done';
  assert.equal(await h.P.save(),false);assert.equal(h.writes().length,0);assert.match(h.$('promiseTaskStatus').textContent,/нужна открытая задача/);
});

function plannerPresentation(h){
  const calls=[];
  h.window.Manager2Presentation={
    enhanceTasks(){},
    enhanceConversationActions(){},
    revealTaskDraft(form){form.querySelector('[data-task-edit-title]')?.focus();form.querySelector('#leadTaskTitle')?.focus();},
    revealTaskPlanner(root,options){calls.push({...options});if(options.focus)(options.create?root.querySelector('#leadTaskTitle'):root.querySelector('[data-task-action-status]'))?.focus();return true;},
  };
  return calls;
}
test('empty binding offers the canonical planner without an empty required selector or automatic task creation',async()=>{
  const h=await setup();await h.clearTaskDraftsForNavigation();h.server.tasks=[];const calls=plannerPresentation(h),reply=h.$('replyText').value,file=h.getFile();
  await h.P.open(12);assert.equal(h.$('promiseTaskFields').hidden,true);assert.equal(h.$('promiseTaskEmpty').hidden,false);assert.equal(h.$('promiseTaskPlan').hidden,false);
  const reads=h.calls.filter(call=>call.action==='detail'&&call.url==='pipeline-api.php').length;
  assert.equal(Object.hasOwn(h.lead(101),'viewer_id'),false);assert.equal(Object.hasOwn(h.lead(101),'conversation_id'),false,'detail uses the production DTO; identity belongs to its captured request scope');
  await h.$('promiseTaskPlan').click();await tick();
  assert.equal(h.$('promiseTaskDialog').open,false);assert.equal(h.calls.filter(call=>call.action==='detail'&&call.url==='pipeline-api.php').length,reads+1);
  assert.equal(h.document.querySelectorAll('#leadTaskTitle').length,1);assert.equal(h.$('leadTaskTitle').value,'');assert.equal(h.$('leadTaskDue').value,'');
  assert.equal(calls.at(-1).create,true);assert.equal(h.document.activeElement,h.$('leadTaskTitle'));
  assert.equal(h.$('replyText').value,reply);assert.equal(h.getFile(),file);assertNoMutations(h);assert.equal(h.writes().length,0);
});

test('planner retains dirty edit/create before reading and a draft entered during a delayed read',async()=>{
  const h=await setup(),before=h.snapshot();plannerPresentation(h);const Tasks=h.window.WorkspaceV2Tasks;
  let reads=h.calls.filter(call=>call.action==='detail').length;
  assert.equal(await Tasks.openPlanner(),true);assert.equal(h.calls.filter(call=>call.action==='detail').length,reads);h.preserved(before);
  h.$('promiseTestDirtyEdit').remove();assert.equal(await Tasks.openPlanner(),true);assert.equal(h.calls.filter(call=>call.action==='detail').length,reads);assert.equal(h.document.activeElement,h.$('leadTaskTitle'));
  await h.clearTaskDraftsForNavigation();const title=h.$('leadTaskTitle'),due=h.$('leadTaskDue'),pending=deferred();
  h.setIntercept((url,request)=>url==='pipeline-api.php'&&request.action==='detail'?pending.promise:undefined);
  const opening=Tasks.openPlanner();await tick();title.focus();title.value='Начал задачу во время чтения';await title.dispatch('input');due.value='2032-02-03T13:00';await due.dispatch('change');h.$('replyText').focus();
  pending.resolve(response(h.lead(101)));assert.equal(await opening,true);assert.equal(h.$('leadTaskTitle'),title);assert.equal(h.$('leadTaskDue'),due);
  assert.equal(title.value,'Начал задачу во время чтения');assert.equal(due.value,'2032-02-03T13:00');assert.equal(h.document.activeElement,h.$('replyText'),'late completion keeps the focus the user moved to the reply');assert.equal(h.getFile(),before.file);assertNoMutations(h);
});

test('planner respects fresh ACL, foreign tasks, failed reads, scope changes, and focus moved during the read',async()=>{
  for(const failure of ['acl','foreign task','degraded','failed read']){
    const h=await setup();await h.clearTaskDraftsForNavigation();plannerPresentation(h);
    const patch=failure==='acl'?{can_edit_pipeline:false}:failure==='foreign task'?{tasks:[{...h.server.tasks[0],conversation_id:102}]}:failure==='degraded'?{degraded_components:['tasks']}:{ok:false,error:'unavailable'};
    h.setIntercept((url,request)=>url==='pipeline-api.php'&&request.action==='detail'?response({...h.lead(101),...patch}):undefined);
    assert.equal(await h.window.WorkspaceV2Tasks.openPlanner(),false);assertNoMutations(h);assert.equal(h.writes().length,0);
    if(patch.can_edit_pipeline===false)assert.equal(h.document.querySelectorAll('#leadTaskTitle').length,0);
  }
  for(const change of ['focus','navigation','account','owner','access']){
    const h=await setup();await h.clearTaskDraftsForNavigation();const calls=plannerPresentation(h),pending=deferred();
    h.setIntercept((url,request)=>url==='pipeline-api.php'&&request.action==='detail'?pending.promise:undefined);
    const opening=h.window.WorkspaceV2Tasks.openPlanner();await tick();
    h.setIntercept(null);
    if(change==='navigation')await h.navigate(102);
    if(change==='account'){h.W.S.manager={...h.W.S.manager,id:8};h.W.S.authGeneration++;}
    if(change==='owner')h.W.S.detail.conversation.manager_id=8;
    if(change==='access')h.C.hasReadAccess=()=>false;
    h.$('replyText').focus();const active=h.document.activeElement;
    pending.resolve(response(h.lead(101)));assert.equal(await opening,change==='focus');assert.equal(h.document.activeElement,active);
    if(change==='focus')assert.equal(calls.at(-1).focus,false);else assert.equal(calls.length,1);
    assertNoMutations(h);
  }
});

test('two-field labels are limited to Manager2 and legacy task inputs keep their original markup',async()=>{
  const h=await setup();assert.match(h.taskRoot.innerHTML,/class="taskCreateField"/);
  h.document.body.classList.remove('manager2');h.window.WorkspaceV2Tasks.render(h.taskRoot,{tasks:[],canEdit:true,conversationId:101});
  assert.doesNotMatch(h.taskRoot.innerHTML,/taskCreateField/);
  assert.match(h.taskRoot.innerHTML,/<div class="taskCreate"><input id="leadTaskTitle" maxlength="255" placeholder="Например: перезвонить туристу"><input id="leadTaskDue" type="datetime-local">/);
});

test('planner accepts the production detail DTO with a PDO string task ID and retains focus across rendering',async()=>{
  const h=await setup();await h.clearTaskDraftsForNavigation();plannerPresentation(h);h.server.tasks[0].id='77';
  const Tasks=h.window.WorkspaceV2Tasks,render=Tasks.render;let detachedFocus=0;
  Tasks.render=function(root,options){
    const active=h.document.activeElement,result=render.call(this,root,options);
    // Browsers move focus to body when innerHTML removes the focused status.
    if(active&&!active.isConnected){h.document.activeElement=h.document.body;detachedFocus++;}
    return result;
  };
  assert.equal(await h.window.WorkspaceV2Tasks.openPlanner(),true);
  assert.equal(detachedFocus,1);assert.equal(h.document.activeElement,h.$('leadTaskTitle'));
  assert.equal(h.W.S.detail.lead.tasks[0].id,'77');assert.equal(Object.hasOwn(h.W.S.detail.lead,'viewer_id'),false);assert.equal(Object.hasOwn(h.W.S.detail.lead,'conversation_id'),false);
  assert.match(h.taskRoot.innerHTML,/data-task-edit="77"/);assertNoMutations(h);assert.equal(h.writes().length,0);
});

module.exports={setup,clone,tick,field,choose,response,deferred};
if(require.main===module)(async()=>{const match=process.argv.find(arg=>arg.startsWith('--match='))?.slice(8),selected=cases.filter(item=>!match||new RegExp(match).test(item.name));assert.ok(selected.length,'at least one requested case');let failed=0;for(const item of selected){try{await item.run();console.log('PASS '+item.name)}catch(error){failed++;console.error('FAIL '+item.name+'\n'+error.stack)}}console.log(`TOTAL ${selected.length} | PASS ${selected.length-failed} | FAIL ${failed}`);process.exitCode=failed?1:0})();
