const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {setup,clone,tick,response,deferred}=require('./run_manager_promise_task_behavior_regression.js');

// Actual Assistant, MessageNavigation, Conversation, Mobile, selection-view and
// Manager2 assets. The adapter below supplies DOM events only, not app logic.
const cases=[],test=(name,run)=>cases.push({name,run});
const pendingPrefix='workspaceV2.promiseSelection.pending.v1.';
function fixture({conversationId=102,actor=7}={}){
 const binding={promise_id:'1'.repeat(64),kind:'send_selection',deadline:{kind:'date',value:'2030-11-20',zone:'Europe/Moscow'},source_message_id:5,source_state:'current',trip_id:'a'.repeat(32),profile_version:3};
 const intent={intent_id:'2'.repeat(32),publication_id:'3'.repeat(32),state:'armed',reason:null,created_at:'2026-11-13 09:02:00',completed_at:null,cancelled_at:null};
 const projection={availability:'available',reason:null,binding,stale_reasons:[],selection:{availability:'available',reason:null,intent,stale_reasons:[],progress:{availability:'available',reason:null,confirmed:1,total:2}}};
 const task={id:77,title:'Отправить согласованную подборку',status:'open',due_state:'upcoming',due_at_utc:'2030-11-20 12:00:00',promise:projection};
 const item={conversation_id:conversationId,manager_id:actor,status:'manager',can_decide:true,display_name:'Клиент плана '+conversationId,channel:'max',last_message_at:'2026-11-13T09:00:00Z',tasks:[task],tasks_truncated:false,promise_coverage:'checked',operational:{awaiting_manager_reply:false,due_task:false},recommendation:null};
 const publication={publication_id:intent.publication_id,title:'Два сохранённых варианта',card_count:2,cards:[
  {index:0,offer_id:901,text:'Вариант «Море»\n20 ноября · 7 ночей · 2 взрослых\nВсё включено · 240 000 ₽',snapshot:{hotel:'Море',date_from:'2030-11-20',adults:2}},
  {index:1,offer_id:902,text:'Вариант «Сад»\n20 ноября · 7 ночей · 2 взрослых\nЗавтраки · 210 000 ₽',snapshot:{hotel:'Сад',date_from:'2030-11-20',adults:2}}
 ]};
 const target=()=>({conversation_id:conversationId,task_id:task.id,promise_id:binding.promise_id,intent_id:intent.intent_id,publication_id:intent.publication_id});
 const view=()=>({ok:true,viewer_id:actor,...target(),projection:clone(projection),publication:clone(publication)});
 const message=()=>({ok:true,conversation_id:conversationId,source_message_id:null,target_message_id:binding.source_message_id,available:true,messages:[
  {id:4,text:'Нужны два варианта у моря.',sender_type:'customer',direction:'inbound',created_at:'2026-11-13T08:59:00Z'},
  {id:binding.source_message_id,text:'Пришлю согласованную подборку к 20 ноября.',sender_type:'manager',direction:'outbound',manager_id:actor,created_at:'2026-11-13T09:00:00Z'}
 ]});
 return{item,task,projection,binding,intent,publication,target,view,message};
}
function nestedDom(h){
 const proto=Object.getPrototypeOf(h.document.createElement('div'));
 const decode=value=>String(value).replace(/&(?:amp|lt|gt|quot|#39);/g,s=>({'&amp;':'&','&lt;':'<','&gt;':'>','&quot;':'"','&#39;':"'"}[s]));
 Object.defineProperty(proto,'innerHTML',{configurable:true,get(){return this._html||this._text},set(value){
  this.replaceChildren();this._html=String(value);const stack=[this];
  for(const token of this._html.matchAll(/<\/?[a-z][^>]*>|[^<]+/gi)){
   const raw=token[0];if(raw.startsWith('</')){if(stack.length>1)stack.pop();continue;}
   if(raw[0]!=='<'){stack.at(-1).appendChild(h.document.createTextNode(decode(raw)));continue;}
   const tag=raw.match(/^<([a-z0-9]+)/i)[1],node=h.document.createElement(tag);
   for(const match of raw.matchAll(/\s([\w-]+)(?:="([^"]*)")?/g)){const key=match[1],value=decode(match[2]||'');node.setAttribute(key,value);if(key==='class')node.className=value;if(['hidden','disabled','required','selected'].includes(key))node[key]=true;if(key==='value')node.value=value;}
   stack.at(-1).appendChild(node);if(!['input','br','hr','img','meta','link'].includes(tag)&&!raw.endsWith('/>'))stack.push(node);
  }
 }});
 proto.show=function(){this.open=true};proto.scrollIntoView=function(){};
 proto.getBoundingClientRect=function(){return{top:0,bottom:100,left:0,right:100,width:100,height:100}};
 proto.dispatchEvent=function(event){for(const handler of this.handlers[event.type]||[])handler({...event,target:this,currentTarget:this});return true};
 const originalQuery=proto.querySelectorAll;
 proto.querySelectorAll=function(selector){if(selector.includes('>')&&!selector.startsWith(':scope')){const [parent,child]=selector.split('>').map(v=>v.trim());return this.querySelectorAll(parent).flatMap(node=>node.children.filter(c=>c.matches(child)));}return originalQuery.call(this,selector)};
 h.document.getElementById=id=>{const node=h.ids.get(id);return node?.isConnected?node:null};h.$=id=>h.document.getElementById(id);
 h.document.head=h.document.createElement('head');h.document.head._harnessRoot=true;
 h.document.body.appendChild(h.$('conversationActions'));h.document.body.appendChild(h.$('messages'));
 const host=h.document.createElement('aside');host.className='inboxZone';h.document.body.appendChild(host);
 return host;
}
function attachPresentation(h){
 const source=fs.readFileSync(path.join(__dirname,'../manager2/assets/manager2.js'),'utf8');
 // Same bounded launcher setup as the established Manager2 navigation harness:
 // omit shell boot only; keep the real lazy loader, scope guards and public API.
 vm.runInNewContext(source.replace('  init();',''),{window:h.window,document:h.document,URL,location:{href:'https://example.invalid/manager2/'},history:h.history,MutationObserver:class{observe(){}},HTMLDetailsElement:class{},setTimeout:()=>0,clearTimeout(){},console});
}
async function harness({data=fixture(),lazy=false}={}){
 const h=await setup();nestedDom(h);let intercept=null;
 const rows=new Map([[data.item.conversation_id,data.item],[103,{...clone(data.item),conversation_id:103,display_name:'Другой клиент'}]]),observers=[];
 h.setIntercept(async(url,request)=>{
  const custom=intercept?await intercept(url,request):null;if(custom!==null&&custom!==undefined)return custom;
  if(url==='assistant-api.php'){
   if(request.action==='detail')return response({ok:true,item:clone(rows.get(request.conversation_id))});
   if(request.action==='list')return response({ok:true,items:[...rows.values()].map(row=>({...clone(row),promise_coverage:'not_loaded',tasks:row.tasks.map(({promise,...task})=>clone(task))})),has_more:false,next_cursor:null});
   throw Error('Unexpected assistant write '+request.action);
  }
  if(url==='api.php'&&request.action==='message_context')return response(data.message());
  if(url==='promise-selections-api.php'&&request.action==='view')return response(data.view());
  if(url==='promise-selections-api.php')throw Error('Readonly task view called '+request.action);
  return null;
 });
 const globals={AbortController,TextEncoder,clearTimeout,MutationObserver:class{constructor(callback){observers.push(callback)}observe(){}},Event:class{constructor(type,options={}){this.type=type;Object.assign(this,options)}}};
 h.loadAsset('workspace-v2-message-navigation.js',globals);
 const loadSelection=()=>h.loadAsset('workspace-v2-promise-selection.js',globals);if(!lazy)loadSelection();
 attachPresentation(h);
 if(!lazy){const append=h.document.head.appendChild.bind(h.document.head);h.document.head.appendChild=node=>{const result=append(node);Promise.resolve().then(()=>node.onload?.());return result;};}
 h.loadAsset('workspace-v2-assistant.js',globals);
 const A=h.window.WorkspaceV2Assistant,N=h.window.WorkspaceV2MessageNavigation;A.activate(7);await A.openPlan(data.item.conversation_id);
 const click=async selector=>{const button=h.document.querySelector(selector);assert.ok(button,'Control exists: '+selector);assert.equal(button.disabled,false,'Control enabled: '+selector);return button.onclick?.({preventDefault(){},stopPropagation(){}})};
 const source=()=>click('[data-assistant-promise-source="77"]'),selection=()=>click('[data-assistant-promise-selection="77"]');
 const noWrites=()=>{assert.equal(h.calls.some(c=>['ack_read','decision','attach','cancel','send','send_reply','send_file','create_task','update_task','set_task_completed','set_task_pinned','ai_draft','bind_promise'].includes(c.action)),false);assert.equal(h.calls.some(c=>c.url==='promise-selections-api.php'&&c.action!=='view'),false);};
 const saved=h.snapshot(),taskDraftKey=h.window.WorkspaceV2TaskDraft.keyFor(101),messages=h.$('messages'),children=[...messages.children],scroll=messages.scrollTop;
 const preserved=()=>{h.preserved({...saved,draft:h.storage.getItem(h.window.WorkspaceV2TaskDraft.keyFor(101))});assert.equal(h.storage.getItem(taskDraftKey),saved.draft,'the captured account task draft stays intact');assert.deepEqual([...messages.children],children);assert.equal(messages.scrollTop,scroll);noWrites();};
 return Object.assign(h,{A,N,data,rows,click,source,selection,preservedPlan:preserved,noWrites,loadSelection,intercept:fn=>intercept=fn,observe:()=>observers.forEach(fn=>fn()),get Q(){return h.window.WorkspaceV2PromiseSelection},text:()=>h.$('assistantDetail').textContent,featureCalls:()=>h.calls.filter(c=>c.url==='promise-selections-api.php')});
}

test('one canonical open task receives promise facts without AI or a second task row',async()=>{
 const h=await harness();assert.equal(h.document.querySelectorAll('.assistantTask').length,1);assert.match(h.text(),/Отправить согласованную подборку/);assert.match(h.text(),/20\.11\.2030.*15:00/);assert.match(h.text(),/Подтверждённое обещание: Отправить подборку/);assert.match(h.text(),/Подтверждена отправка 1 из 2 вариантов/);assert.equal(h.document.querySelectorAll('[data-assistant-task="77"]').length,1);assert.equal(h.calls.some(c=>c.action==='ai_draft'),false);h.preservedPlan();
});
test('no-contact keeps internal work and the existing Open tasks action',async()=>{
 const data=fixture();data.item.recommendation={revision:'b'.repeat(64),next_action:'no_contact',reason:'Проверить результат уже начатой работы.',checks:[],prepared_at:'2026-11-13T09:00:00Z',expires_at:'2099-01-01T00:00:00Z',latest_message_id:5,evidence_message_ids:[5],history_incomplete:false,decision:null};
 const h=await harness({data});assert.match(h.text(),/Сейчас не писать/);assert.equal(h.$('assistantPlan').textContent,'Открыть задачи');assert.equal(h.$('assistantReply'),null);assert.match(h.text(),/Подтверждена отправка 1 из 2/);assert.doesNotMatch(h.text(),/Ожидание клиента/);h.preservedPlan();
});
test('calendar-day promise stays distinct from same-day task time; only equal instants collapse',async()=>{
 const data=fixture();data.task.due_at_utc='2026-11-13 12:00:00';data.binding.deadline={kind:'date',value:'2026-11-13',zone:'Europe/Moscow'};
 const h=await harness({data});assert.match(h.text(),/15:00/);assert.match(h.text(),/Срок обещания: 13\.11\.2026 · календарный день, Москва/);
 data.binding.deadline={kind:'instant',value:'2026-11-13T15:00:00+03:00'};await h.A.loadDetail(102);assert.doesNotMatch(h.text(),/Срок обещания:/);
 data.binding.deadline={kind:'instant',value:'2026-11-13T15:00:27+03:00'};await h.A.loadDetail(102);assert.match(h.text(),/Срок обещания:.*15:00:27 МСК/);h.preservedPlan();
});
test('coverage, successful absence and unavailable facts never invent a binding or zero',async()=>{
 const h=await harness(),row=h.data.item;
 row.promise_coverage='not_loaded';await h.A.loadDetail(102);assert.match(h.text(),/Проверяем связь/);assert.equal(h.document.querySelector('[data-assistant-promise-source]'),null);
 delete row.promise_coverage;await h.A.loadDetail(102);assert.equal(h.document.querySelector('.assistantPromiseFacts'),null);
 row.promise_coverage='checked';row.tasks[0].promise={availability:'none',reason:null,binding:null,stale_reasons:[],selection:{availability:'not_applicable',reason:null,intent:null,stale_reasons:[],progress:null}};await h.A.loadDetail(102);assert.equal(h.document.querySelector('.assistantPromiseFacts'),null);
 row.tasks[0].promise.availability='unavailable';row.tasks[0].promise.reason='storage_unavailable';await h.A.loadDetail(102);assert.match(h.text(),/Связь с обещанием сейчас недоступна/);assert.doesNotMatch(h.text(),/0 из|Подтверждённое обещание/);
 delete row.tasks[0].promise;await h.A.loadDetail(102);assert.match(h.text(),/Связь с обещанием сейчас недоступна/);h.preservedPlan();
});
test('missing selection shows the next internal step without a second editor or write',async()=>{
 const data=fixture();data.projection.selection={availability:'none',reason:null,intent:null,stale_reasons:[],progress:null};
 const h=await harness({data});assert.match(h.text(),/Следующий шаг: закрепить состав подборки/);assert.ok(h.document.querySelector('[data-assistant-promise-source]'));assert.equal(h.document.querySelector('[data-assistant-promise-selection]'),null);assert.equal(h.document.querySelector('.assistantTaskEditor'),null);h.preservedPlan();
});
test('unknown, unverified and rejected delivery expose the appropriate next check',async()=>{
 const h=await harness();for(const [reason,label] of [['delivery_unknown','проверить результат отправки'],['delivery_unverified','проверить подтверждение отправки'],['delivery_rejected','Отправка отклонена']]){h.data.projection.selection.progress.reason=reason;await h.A.loadDetail(102);assert.ok(h.text().includes(label));assert.doesNotMatch(h.text(),/Повторить отправку|Отправить заново/);}h.preservedPlan();
});
test('historical completion does not finish a reopened canonical task',async()=>{
 const data=fixture();data.intent.state='completed';data.intent.completed_at='2026-11-13 09:05:00';data.projection.selection.stale_reasons=['task_changed'];data.projection.selection.progress.confirmed=2;
 const h=await harness({data});assert.equal(h.document.querySelectorAll('.assistantTask').length,1);assert.ok(h.document.querySelector('[data-assistant-task="77"]'));assert.match(h.text(),/Задача уже завершалась автоматически.*12:05 МСК\. Сейчас она открыта/ );assert.match(h.text(),/Задача изменилась/);assert.equal(h.data.task.status,'open');await h.selection();assert.match(h.$('promiseSelectionIntent').textContent,/Задача завершалась автоматически/);assert.doesNotMatch(h.$('promiseSelectionIntent').textContent,/Задача выполнена/);h.Q.close();h.preservedPlan();
});
test('source outside the current transcript opens an isolated exact-client context with no acknowledgement',async()=>{
 const h=await harness();assert.equal(h.W.S.current,101);assert.equal(h.W.S.detail.messages.some(m=>m.id===5),false);
 assert.equal(await h.source(),true);const call=h.calls.findLast(c=>c.action==='message_context');assert.equal(call.conversation_id,102);assert.equal(call.target_message_id,5);assert.match(h.$('messageContextMessages').textContent,/Пришлю согласованную подборку/);assert.equal(h.$('messageContextMessages').querySelectorAll('.msg').length,2);assert.equal(h.$('messageContextBack').textContent,'Вернуться к плану');h.preservedPlan();
 h.$('messageContextDialog').close();assert.equal(h.document.activeElement,h.document.querySelector('[data-assistant-promise-source="77"]'));h.preservedPlan();
});
test('external source refusal leaves the unrelated current composer editable',async()=>{
 for(const code of [403,404]){const h=await harness();h.intercept((url,r)=>r.action==='message_context'?response({ok:false,error:code===403?'forbidden':'not_found'},code):null);assert.equal(await h.source(),false);assert.match(h.$('messageContextStatus').textContent,/Не удалось открыть/);assert.equal(h.C.hasReadAccess(),true);assert.equal(h.$('replyText').disabled,false);h.$('messageContextDialog').close();h.preservedPlan();}
});
test('exact linked publication uses only view and exposes no mutation controls',async()=>{
 const h=await harness();assert.equal(await h.selection(),true);const calls=h.featureCalls();assert.equal(calls.length,1);assert.deepEqual({...calls[0],url:undefined,action:undefined,csrf:undefined},{...h.data.target(),url:undefined,action:undefined,csrf:undefined});
 assert.equal(h.$('promiseSelectionCards').children.length,2);assert.match(h.$('promiseSelectionCards').textContent,/240 000 ₽/);assert.match(h.$('promiseSelectionCards').textContent,/210 000 ₽/);assert.match(h.$('promiseSelectionIntent').textContent,/1 из 2/);
 for(const id of ['promiseSelectionFields','promiseSelectionPreview','promiseSelectionConfirm','promiseSelectionCancel','promiseSelectionCheck','promiseSelectionRetry'])assert.equal(h.$(id).hidden,true,id+' is hidden');
 for(const action of ['preview','confirm','beginCancel','confirmCancel','check','retry','readStatus'])assert.equal(await h.Q[action](),false,action+' is unavailable in readonly view');
 await h.click('#promiseSelectionStatusRead');assert.deepEqual(h.featureCalls().map(c=>c.action),['view','view']);h.Q.close();assert.equal(h.document.activeElement,h.document.querySelector('[data-assistant-promise-selection="77"]'));h.preservedPlan();
});
test('view rejects every mismatched identity in its full immutable tuple',async()=>{
 for(const [key,value] of [['viewer_id',8],['conversation_id',103],['task_id',78],['promise_id','9'.repeat(64)],['intent_id','9'.repeat(32)],['publication_id','9'.repeat(32)]]){const h=await harness();h.intercept((url,r)=>r.action==='view'?response({...h.data.view(),[key]:value}):null);assert.equal(await h.selection(),false,key);assert.equal(h.$('promiseSelectionReview').hidden,true);assert.match(h.$('promiseSelectionStatus').textContent,/Не удалось проверить/);h.Q.close();h.preservedPlan();}
});
test('unavailable exact evidence retains known total without displaying zero or stale cards',async()=>{
 const h=await harness();await h.selection();h.Q.close();h.data.projection.selection.progress={availability:'unavailable',reason:'delivery_evidence_changed',confirmed:null,total:2};h.data.projection.selection.stale_reasons=['scope_changed'];await h.A.loadDetail(102);assert.match(h.text(),/Подтверждение отправки сейчас недоступно/);assert.doesNotMatch(h.text(),/0 из/);
 h.intercept((url,r)=>r.action==='view'?response({...h.data.view(),publication:null}):null);assert.equal(await h.selection(),true);assert.equal(h.$('promiseSelectionCards').children.length,0);assert.equal(h.$('promiseSelectionReview').hidden,true);assert.match(h.$('promiseSelectionIntent').textContent,/Подтверждение отправки сейчас недоступно/);assert.doesNotMatch(h.$('promiseSelectionIntent').textContent,/0 из/);h.Q.close();h.preservedPlan();
});
test('context changes state the actual new-message reason without asserting changed trip conditions',async()=>{
 const data=fixture();data.intent.state='blocked';data.intent.reason='context_changed';data.projection.selection.stale_reasons=['context_changed'];const h=await harness({data});assert.match(h.text(),/Пришло новое сообщение; проверьте дальнейший шаг/);assert.doesNotMatch(h.text(),/Условия поездки изменились/);await h.selection();assert.match(h.$('promiseSelectionIntent').textContent,/Пришло новое сообщение; проверьте дальнейший шаг/);h.Q.close();h.preservedPlan();
});
test('readonly inspection never consumes or reconciles an UNKNOWN write receipt',async()=>{
 const h=await harness(),record={schema:1,actor:7,conversation_id:102,action:'attach',operation_id:'7'.repeat(32),request_sha256:'8'.repeat(64),promise_id:h.data.binding.promise_id,publication_id:h.data.intent.publication_id,preview_sha256:'9'.repeat(64)},key=pendingPrefix+'7.102.'+record.operation_id,raw=JSON.stringify(record);h.storage.setItem(key,raw);
 await h.selection();assert.match(h.$('promiseSelectionStatus').textContent,/неподтверждённая попытка/);await h.click('#promiseSelectionStatusRead');h.Q.close();assert.equal(h.storage.getItem(key),raw);assert.deepEqual(h.featureCalls().map(c=>c.action),['view','view']);h.preservedPlan();
});
for(const mode of ['source','selection'])test(mode+' remains scoped across identical and changed-progress refresh, then returns focus to the current row',async()=>{
 const h=await harness();await h[mode]();const dialog=h.$(mode==='source'?'messageContextDialog':'promiseSelectionDialog'),button=h.document.querySelector('[data-assistant-promise-'+mode+'="77"]');await h.A.loadDetail(102);assert.equal(dialog.open,true);assert.equal(h.document.querySelector('[data-assistant-promise-'+mode+'="77"]'),button);
 h.data.projection.selection.progress.confirmed=2;await h.A.loadDetail(102);assert.equal(dialog.open,true);const refreshed=h.document.querySelector('[data-assistant-promise-'+mode+'="77"]');assert.notEqual(refreshed,button);assert.equal(refreshed.isConnected,true);
 if(mode==='source')dialog.close();else h.Q.close();assert.equal(h.document.activeElement,refreshed);h.preservedPlan();
});
for(const mode of ['source','selection'])test(mode+' late reply is discarded after A-B-A plan navigation, canonical conversation change or account change',async()=>{
 for(const change of ['selection','workspace','actor']){const h=await harness(),wait=deferred();h.intercept((url,r)=>r.action===(mode==='source'?'message_context':'view')?wait.promise:null);const reading=h[mode]();await tick();
  let currentWorkspace=null;
  if(change==='selection'){await h.A.loadDetail(103);await h.A.loadDetail(102);}else if(change==='workspace'){await h.clearTaskDraftsForNavigation();await h.navigate(102);await tick();await h.draft('Новый ответ после перехода');h.setFile({name:'current-private.txt'});h.N.sync();h.Q.sync();currentWorkspace=h.snapshot();}else{h.W.S.manager={...h.W.S.manager,id:8};h.W.S.authGeneration++;h.A.suspend();h.observe();}
  const active=h.document.activeElement;wait.resolve(response(mode==='source'?h.data.message():h.data.view()));assert.equal(await reading,false);assert.equal(h.document.activeElement,active);assert.equal(h.$(mode==='source'?'messageContextDialog':'promiseSelectionDialog').open,false);assert.equal(h.$(mode==='source'?'messageContextMessages':'promiseSelectionCards').children.length,0);if(currentWorkspace){const now=h.snapshot();for(const key of Object.keys(currentWorkspace))assert.equal(now[key],currentWorkspace[key]);h.noWrites();}else h.preservedPlan();
 }
});
for(const mode of ['source','selection'])test(mode+' close and native Back revoke pending reads without leaving the plan',async()=>{
 for(const close of ['button','back']){const h=await harness(),wait=deferred();h.intercept((url,r)=>r.action===(mode==='source'?'message_context':'view')?wait.promise:null);const before=h.history.index,reading=h[mode]();await tick();assert.equal(h.history.index,before+1);
  if(close==='back')await h.history.back();else if(mode==='source')h.$('messageContextDialog').close();else h.Q.close();
  await tick();const active=h.document.activeElement;wait.resolve(response(mode==='source'?h.data.message():h.data.view()));assert.equal(await reading,false);assert.equal(h.document.activeElement,active);assert.equal(h.A.isPlanOpen(),true);assert.equal(h.history.index,before);h.preservedPlan();
 }
});
test('opening facts preserves the unfinished canonical task deadline editor',async()=>{
 const h=await harness();await h.click('[data-assistant-snooze="77"]');const form=h.$('assistantTaskEditor').querySelector('form'),input=h.$('assistantDue');input.value='2031-02-05T17:12';await h.source();h.$('messageContextDialog').close();await h.selection();h.Q.close();assert.equal(h.$('assistantTaskEditor').querySelector('form'),form);assert.equal(h.$('assistantDue'),input);assert.equal(input.value,'2031-02-05T17:12');h.preservedPlan();
});
test('thin lazy launcher shares its assets and rejects a late launch after client change',async()=>{
 const h=await harness({lazy:true}),opening=h.selection();await tick();const assets=h.document.head.children;assert.equal(assets.length,2);assert.equal(assets.filter(n=>n.tagName==='SCRIPT').length,1);assert.equal(assets.filter(n=>n.tagName==='LINK').length,1);await h.A.loadDetail(103);h.loadSelection();for(const asset of assets)await asset.dispatch('load');assert.equal(await opening,false);assert.equal(h.featureCalls().length,0);await h.A.loadDetail(102);assert.equal(await h.selection(),true);assert.equal(h.document.head.children.length,2);h.window.WorkspaceV2PromiseSelection.close();h.preservedPlan();
});
test('the existing quote-comparison preview still renders the saved quote and original',async()=>{
 const h=await harness();h.A.close();const row=h.$('messages').children.find(n=>n.dataset.scrollMessageKey==='12'),button=h.document.createElement('button');button.dataset.quoteSourceId='12';button.dataset.linkedMid='saved';button.textContent='Цитата';row.appendChild(button);
 h.intercept((url,r)=>r.action==='message_target'?response({...h.data.message(),conversation_id:101,source_message_id:12,quote_comparison:{state:'text_differs',snapshot:{type:'reply',text:'Сохранённая цитата',sender_name:'Менеджер',may_be_truncated:false}}}):null);
 assert.equal(await h.N.openQuote(12,button),true);assert.match(h.$('messageContextMessages').textContent,/Текст цитаты отличается/);assert.match(h.$('messageContextMessages').textContent,/Сохранённая цитата/);assert.match(h.$('messageContextMessages').textContent,/Пришлю согласованную подборку/);h.$('messageContextDialog').close();h.noWrites();
});

async function main(){let passed=0;for(const {name,run} of cases){try{await run();passed++;console.log('PASS '+name)}catch(error){console.error('FAIL '+name);console.error(error);}}console.log('manager promise plan behavior: '+passed+'/'+cases.length);if(passed!==cases.length)throw Error((cases.length-passed)+' promise-plan scenarios failed');}
if(require.main===module)main().catch(error=>{console.error(error);process.exitCode=1});
module.exports={fixture};
