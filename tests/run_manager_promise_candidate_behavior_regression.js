const assert=require('node:assert/strict');
const {setup,clone,tick,field,response,deferred}=require('./run_manager_promise_task_behavior_regression.js');

// Execute the real AI-panel and promise-dialog assets in one existing workspace.
// The DOM adapter tests behavior, guards and draft identity, not browser layout.
const cases=[],test=(name,run)=>cases.push({name,run});
async function harness({candidate={},envelope={},outside=true,storage,server}={}){
  const h=await setup({storage,server}),form=h.$('composer'),reply=h.$('replyText'),proto=Object.getPrototypeOf(reply);
  proto.setSelectionRange=function(start,end,direction='none'){this.selectionStart=Math.min(start,this.value.length);this.selectionEnd=Math.min(end,this.value.length);this.selectionDirection=direction};
  proto.prepend=function(...nodes){for(const node of nodes.reverse())this.insertBefore(node,this.firstChild)};
  proto.getBoundingClientRect=function(){return{top:0,left:0,width:600,height:this.clientHeight,right:600,bottom:this.clientHeight,x:0,y:0}};
  const style=()=>({setProperty(name,value){this[name]=String(value)},getPropertyValue(name){return this[name]??''},removeProperty(name){delete this[name]}});
  for(const node of [h.document.body,...h.ids.values()])Object.assign(node.style,style());
  const create=h.document.createElement;h.document.createElement=tag=>{const node=create(tag);Object.assign(node.style,style());return node};
  const getComputedStyle=node=>({display:node.hidden||node.classList.contains('hidden')?'none':'block',position:'static',paddingTop:'0px',paddingBottom:'0px',marginTop:'0px',marginBottom:'0px',rowGap:'0px'});
  const make=(id,tag='div')=>{const node=h.document.createElement(tag);node.id=id;return node};
  const row=make('quickReplies'),selected=make('quickRepliesSelected'),quick=make('quickRepliesOpen','button'),main=make('composerMain');
  main.className='composerMain';main.appendChild(reply);row.append(selected,quick);form.append(row,main,h.$('replyFile'));
  h.$('conversationZone').append(h.$('composerLocked'),form);h.$('conversationZone').clientHeight=900;form.clientHeight=180;
  const head=make('promiseCandidateConversationHead');head.className='conversationHead';h.$('conversationZone').prepend(head);
  reply.placeholder='Напишите туристу…';reply.selectionStart=reply.selectionEnd=0;reply.selectionDirection='none';reply.scrollLeft=0;
  h.window.innerHeight=900;h.window.getComputedStyle=getComputedStyle;h.document.readyState='complete';
  const timers=new Map();let timerId=0,now=Date.now();
  h.window.setTimeout=(fn,ms)=>{const id=++timerId;timers.set(id,{fn,ms});return id};h.window.clearTimeout=id=>timers.delete(id);
  h.window.WorkspaceV2PersonalWorkspace={preparationPermission:()=>({allowed:false,token:'synthetic-manual-only',until:null})};
  // The selected source is deliberately outside the current transcript window.
  h.server.sources[12].text='Пришлю согласованный расчёт завтра.';
  h.W.S.detail.messages=h.sourceMessages().filter(message=>!outside||message.id!==12);
  if(outside)for(const node of h.document.querySelectorAll('[data-message-id="12"]'))node.remove();
  const promise={conversation_id:101,as_of_message_id:13,kind:'send_calculation',source_message_id:12,source_message_revision:h.server.sources[12].revision,
    trip_id:h.server.profile.trip_id,profile_version:h.server.profile.version,profile_sha256:h.server.profile.sha256,profile_status:'fresh',
    quote:h.server.sources[12].text,deadline_text:'завтра',deadline:{kind:'date',value:'2026-11-14',zone:'Europe/Moscow'},deadline_state:'suggested',...candidate};
  const result={ok:true,prepared:true,prepared_at:new Date(now).toISOString(),expires_at:new Date(now+3600000).toISOString(),source:'chatgpt',
    should_draft:false,draft:'',next_action:'no_contact',reason:'Менеджер уже обещал прислать расчёт.',checks:[],evidence_message_ids:[12],history_incomplete:false,
    revision:'synthetic-promise-revision',latest_message_id:13,promise_candidates:[promise],...envelope};
  let intercept=null;
  h.setIntercept(async(url,request)=>{
    const custom=intercept?await intercept(url,request):null;if(custom!==null&&custom!==undefined)return custom;
    if(url==='ai-draft.php')return response(result);
    return null;
  });
  h.loadAsset('workspace-v2-ai-draft.js',{innerHeight:900,getComputedStyle,MutationObserver:class{observe(){}},AbortController,Date:class extends Date{static now(){return now}}});
  const ai=h.window.WorkspaceV2AiDraft;
  return Object.assign(h,{ai,promise,result,advance:ms=>{now+=ms},intercept:fn=>intercept=fn,
    cards:()=>h.document.querySelectorAll('.aiPromiseCandidate'),actions:()=>h.document.querySelectorAll('.aiPromiseOpen'),
    aiCalls:()=>h.calls.filter(call=>call.url==='ai-draft.php'),contexts:()=>h.calls.filter(call=>call.action==='promise_context'),
    async show(){assert.equal(await ai.openPrepared(),true)},async open(){await h.actions()[0].click();await tick();assert.equal(h.$('promiseTaskDialog')?.open,true)}});
}
async function checked(h){await h.open();await field(h,'promiseTaskTask','77');assert.equal(await h.P.preview(),true);}

test('no-contact candidate outside the transcript opens the same dialog, verifies pins and requires one confirmation',async()=>{
  const h=await harness(),before=h.snapshot();await h.show();
  assert.equal(h.aiCalls().length,1);assert.equal(h.aiCalls()[0].action,'prepared_preview');assert.equal(h.contexts().length,0);assert.equal(h.writes().length,0);
  assert.equal(h.cards().length,1);assert.match(h.$('aiDraftPanel').textContent,/Предложение по обещанию менеджера/);
  assert.equal(h.document.querySelector('.aiPromiseQuote').textContent,h.promise.quote);assert.match(h.cards()[0].textContent,/Отправить расчёт/);assert.match(h.cards()[0].textContent,/14\.11\.2026/);
  assert.equal(h.W.S.detail.messages.some(message=>message.id===12),false);assert.equal(h.$('aiDraftInsert').hidden,true);
  await h.open();assert.equal(h.$('promiseTaskKind').value,'');assert.equal(h.$('promiseTaskDeadlineMode').value,'');assert.equal(h.$('promiseTaskKind').disabled,true);
  assert.equal(await h.P.confirm(),false);assert.equal(h.contexts().length,0);
  await field(h,'promiseTaskTask','77');assert.equal(await h.P.preview(),true);
  assert.equal(h.contexts()[0].source_message_id,12);assert.equal(h.$('promiseTaskKind').value,'send_calculation');assert.equal(h.$('promiseTaskDeadlineMode').value,'date');assert.equal(h.$('promiseTaskDate').value,'2026-11-14');
  assert.equal(h.$('promiseTaskConfirm').hidden,false);assert.equal(h.writes().length,0);h.preserved(before);
  assert.equal(await h.P.confirm(),true);assert.equal(await h.P.confirm(),false);assert.equal(h.writes().length,1);
  assert.deepEqual(h.writes()[0].deadline,h.promise.deadline);assert.equal(h.writes()[0].source_message_revision,h.promise.source_message_revision);
  assert.equal(h.writes()[0].trip_id,h.promise.trip_id);assert.equal(h.writes()[0].profile_version,h.promise.profile_version);assert.equal(h.writes()[0].profile_sha256,h.promise.profile_sha256);
  h.P.close();h.preserved(before);
});

test('unknown or absent deadline text never silently means the manager selected no deadline',async()=>{
  for(const deadline_text of [null,'завтра']){
    const h=await harness({candidate:{deadline_text,deadline:null,deadline_state:'manual_required'}}),before=h.snapshot();await h.show();await checked(h);
    assert.equal(h.$('promiseTaskKind').value,'send_calculation');assert.equal(h.$('promiseTaskDeadlineMode').value,'');assert.equal(h.$('promiseTaskDeadlineMode').disabled,false);
    assert.match(h.$('promiseTaskStatus').textContent,/Выберите срок явно/);assert.equal(h.$('promiseTaskConfirm').hidden,true);
    assert.equal(await h.P.confirm(),false);assert.equal(await h.P.preview(),false);assert.equal(h.writes().length,0);
    await field(h,'promiseTaskDeadlineMode','none');assert.equal(await h.P.preview(),true);assert.equal(await h.P.confirm(),true);
    assert.equal(h.writes()[0].deadline,null);assert.equal(h.contexts().length,2);h.preserved(before);
  }
});

test('changed source, trip or profile pins reject seeding without rebasing the original proposal',async()=>{
  for(const change of [h=>{h.server.sources[12].revision='9'.repeat(64)},h=>{h.server.profile.trip_id='9'.repeat(32)},
    h=>{h.server.profile.version++},h=>{h.server.profile.sha256='9'.repeat(64)},h=>{h.server.sources[12].text='Другой текст без обещания'}]){
    const h=await harness(),before=h.snapshot();await h.show();await h.open();const original=clone(h.promise);change(h);await field(h,'promiseTaskTask','77');
    assert.equal(await h.P.preview(),false);assert.equal(h.$('promiseTaskKind').value,'');assert.equal(h.$('promiseTaskDeadlineMode').value,'');
    assert.match(h.$('promiseTaskStatus').textContent,/свежий AI-разбор/);assert.equal(await h.P.confirm(),false);assert.equal(h.writes().length,0);assert.deepEqual(h.promise,original);h.preserved(before);
  }
});

test('fresh canonical needs-review status is shown without replacing immutable candidate pins',async()=>{
  const h=await harness();await h.show();h.server.profile.status='needs_review';await checked(h);
  assert.match(h.$('promiseTaskProfile').textContent,/Сверьте текущие условия/);assert.equal(h.$('promiseTaskConfirm').hidden,false);assert.equal(h.promise.profile_status,'fresh');
  assert.equal(await h.P.confirm(),true);assert.equal(h.writes()[0].profile_sha256,h.promise.profile_sha256);
});

test('a suggested instant keeps its seconds through a second task preview and remains Moscow time',async()=>{
  const h=await harness(),instant='2030-11-20T15:45:42+03:00';h.server.sources[12].text='Пришлю расчёт '+instant+'.';
  Object.assign(h.promise,{quote:h.server.sources[12].text,deadline_text:instant,deadline:{kind:'instant',value:instant}});
  await h.show();assert.match(h.cards()[0].textContent,/20\.11\.2030 15:45:42 МСК/);await checked(h);
  assert.equal(h.$('promiseTaskInstant').value,'2030-11-20T15:45:42');assert.match(h.$('promiseTaskDeclaredDeadline').textContent,/15:45:42 МСК/);
  await field(h,'promiseTaskTask','78');assert.equal(await h.P.preview(),true);assert.equal(await h.P.confirm(),true);
  assert.deepEqual(h.writes()[0].deadline,{kind:'instant',value:'2030-11-20T12:45:42Z'});assert.equal(h.writes()[0].task_id,78);
});

test('legacy AI results and invalid, cross-scope, duplicate or expired candidate envelopes render no action',async()=>{
  const invalid=[h=>{delete h.result.promise_candidates},h=>{h.result.promise_candidates=[]},h=>{h.promise.conversation_id=102},h=>{h.promise.as_of_message_id=12},
    h=>{h.promise.source_message_revision='untrusted'},h=>{h.promise.deadline=null},h=>{h.result.promise_candidates.push(clone(h.promise))},
    h=>{h.result.expires_at='not-a-date'},h=>{h.result.expires_at='2030-02-30 09:00:00'},h=>{h.result.expires_at='2000-01-01 00:00:00'}];
  for(const change of invalid){const h=await harness(),before=h.snapshot();change(h);await h.show();assert.equal(h.cards().length,0);assert.equal(h.actions().length,0);assert.equal(h.contexts().length,0);assert.equal(h.writes().length,0);h.preserved(before);}
});

test('candidate text is literal and both supported result kinds use their own source',async()=>{
  const h=await harness();h.promise.quote='<img src=x onerror=alert(1)> Пришлю расчёт завтра.';h.server.sources[12].text=h.promise.quote;
  const selection={...clone(h.promise),kind:'send_selection',source_message_id:13,source_message_revision:h.server.sources[13].revision,quote:h.server.sources[13].text,deadline_text:null,deadline:null,deadline_state:'manual_required',profile_status:'needs_review'};
  h.result.promise_candidates.push(selection);await h.show();assert.equal(h.cards().length,2);assert.equal(h.cards()[0].querySelector('blockquote').textContent,h.promise.quote);assert.equal(h.cards()[0].querySelector('img'),null);
  assert.match(h.cards()[1].textContent,/Отправить подборку/);assert.match(h.cards()[1].textContent,/Условия поездки требуют проверки/);
  await h.actions()[1].click();await field(h,'promiseTaskTask','78');assert.equal(await h.P.preview(),true);
  assert.equal(h.contexts()[0].source_message_id,13);assert.equal(h.contexts()[0].kind,'send_selection');assert.equal(h.$('promiseTaskKind').value,'send_selection');assert.equal(h.writes().length,0);
});

test('snapshot, identity and expiry changes fence captured candidate actions before any dialog read',async()=>{
  for(const change of [h=>{h.W.S.detail.messages.push({id:14,sender_type:'customer',text:'Новое условие'})},h=>{h.W.S.authGeneration++},
    h=>{h.W.S.manager={...h.W.S.manager,id:8}},h=>{h.W.S.detail.conversation.manager_id=9},h=>{h.advance(3600001)},h=>{h.ai.resetSession()}]){
    const h=await harness(),before=h.snapshot(),draftKey=h.window.WorkspaceV2TaskDraft.keyFor(101),oldDraft=before.draft;await h.show();const action=h.actions()[0],reads=h.calls.length;change(h);
    before.draft=h.snapshot().draft;await action.dispatch('click');
    assert.equal(h.$('promiseTaskDialog')?.open===true,false);assert.equal(h.calls.length,reads);assert.equal(h.writes().length,0);assert.equal(h.storage.getItem(draftKey),oldDraft);h.preserved(before);
  }
});

test('closing or invalidating an in-flight canonical preview cannot seed a new workspace or reopen the modal',async()=>{
  for(const change of [h=>h.P.close(),h=>{h.ai.resetSession()},h=>{h.W.S.authGeneration++;h.P.reset(true)},h=>{h.W.S.manager={...h.W.S.manager,id:8};h.P.reset(true)}]){
    const h=await harness(),wait=deferred();await h.show();await h.open();await field(h,'promiseTaskTask','77');
    h.intercept((url,request)=>request.action==='promise_context'?wait.promise:null);const reading=h.P.preview();await tick();const request=h.contexts()[0];change(h);
    await h.draft('Новый ручной ответ во время проверки');const file={name:'new-current.pdf'};h.setFile(file);const before=h.snapshot(),focused=h.document.activeElement;
    wait.resolve(response(h.contextResponse(request)));assert.equal(await reading,false);assert.equal(h.$('promiseTaskKind').value,'');assert.equal(h.$('promiseTaskDeadlineMode').value,'');assert.equal(await h.P.confirm(),false);
    assert.equal(h.document.activeElement,focused);assert.equal(h.writes().length,0);h.preserved(before);
  }
});

test('a reviewed candidate cannot be confirmed after its captured AI snapshot becomes stale',async()=>{
  for(const change of [h=>{h.W.S.detail.messages.push({id:14,sender_type:'customer',text:'Другая поездка'})},h=>{h.advance(3600001)},h=>{h.ai.resetSession()}]){
    const h=await harness(),before=h.snapshot();await h.show();await checked(h);change(h);assert.equal(await h.P.confirm(),false);assert.equal(h.writes().length,0);
    h.P.decorate();assert.equal(h.$('promiseTaskDialog').open,false);h.preserved(before);
  }
});

test('identical status refresh preserves the dialog choice while a shorter current expiry still fences confirmation',async()=>{
  for(const shortened of [false,true]){
    const h=await harness(),before=h.snapshot();await h.show();await checked(h);
    const refreshed=clone(h.result);if(shortened)refreshed.expires_at=new Date(Date.now()+1000).toISOString();
    h.intercept((url,request)=>url==='ai-draft.php'&&request.action==='prepared_status'?response(refreshed):null);
    h.window.WorkspaceV2PersonalWorkspace.preparationPermission=()=>({allowed:true,token:'synthetic-status-refresh',until:null});h.ai.sync();await tick();
    assert.equal(h.aiCalls().at(-1).action,'prepared_status');assert.equal(h.$('promiseTaskTask').value,'77');assert.equal(h.$('promiseTaskKind').value,'send_calculation');
    if(shortened){h.advance(2000);assert.equal(await h.P.confirm(),false);assert.equal(h.writes().length,0);}
    else{assert.equal(await h.P.confirm(),true);assert.equal(h.writes().length,1);}
    h.preserved(before);
  }
});

test('typing out of a generated composer preview cannot extend the candidate original ten-minute TTL',async()=>{
  const h=await harness({envelope:{prepared:false,should_draft:true,draft:'Синтетический ответ для проверки менеджером.',next_action:'answer_question'}});
  delete h.result.prepared_at;delete h.result.expires_at;
  h.window.WorkspaceV2PersonalWorkspace.preparationPermission=()=>({allowed:true,token:'synthetic-generated-preview',until:null});
  await h.draft('');await h.$('aiDraftOpen').click();await tick();
  assert.equal(h.aiCalls().filter(call=>call.action==='generate').length,1);assert.equal(h.C.aiDraftPreview()?.text,h.result.draft);
  const originalAction=h.actions()[0];assert.ok(originalAction);
  h.advance(9*60000);await h.draft('Ручная правка к предложенному ответу');
  assert.equal(h.C.aiDraftPreview(),null);assert.notEqual(h.actions()[0],originalAction,'typing triggered a real panel rerender');
  const before=h.snapshot();await checked(h);assert.equal(h.writes().length,0);
  h.advance(60001);assert.equal(await h.P.confirm(),false);assert.equal(h.writes().length,0);
  h.P.decorate();assert.equal(h.$('promiseTaskDialog').open,false);h.preserved(before);
});

test('UNKNOWN from a candidate keeps the original receipt flow after the AI view expires',async()=>{
  const h=await harness(),before=h.snapshot();await h.show();await checked(h);
  h.intercept((url,request)=>{if(request.action==='bind_promise'){h.success(request);throw Error('synthetic response lost')}return null});
  assert.equal(await h.P.confirm(),false);const original=clone(h.writes()[0]);assert.equal(await h.P.confirm(),false);assert.equal(h.writes().length,1);
  h.ai.resetSession();h.advance(3600001);h.intercept(null);h.server.tasks[0].status='done';
  assert.equal(await h.P.check(),true);assert.equal(h.writes().length,1);assert.deepEqual(h.writes()[0],original);
  assert.doesNotMatch(h.$('promiseTaskStatus').textContent,/Обещание выполнено|Задача выполнена/);h.preserved(before);
});

test('a durable UNKNOWN operation takes precedence over a different new candidate',async()=>{
  const h=await harness();await h.show();await checked(h);h.intercept((url,request)=>{if(request.action==='bind_promise')throw Error('synthetic response lost');return null});
  assert.equal(await h.P.confirm(),false);const original=clone(h.writes()[0]);h.P.close();
  const next=await harness({storage:h.storage,server:h.server,candidate:{kind:'send_selection',source_message_id:13,source_message_revision:h.server.sources[13].revision,quote:h.server.sources[13].text,deadline_text:null,deadline:null,deadline_state:'manual_required'}});
  await next.show();await next.open();assert.equal(next.$('promiseTaskKind').value,'send_calculation');assert.equal(next.$('promiseTaskCheck').hidden,false);assert.equal(next.contexts().length,0);
  assert.equal(await next.P.check(),false);assert.equal(next.writes().length,0);assert.equal(await next.P.retry(),true);assert.deepEqual(next.writes()[0],original);
});

if(require.main===module)(async()=>{const match=process.argv.find(arg=>arg.startsWith('--match='))?.slice(8),selected=cases.filter(item=>!match||new RegExp(match).test(item.name));assert.ok(selected.length,'at least one requested case');let failed=0;for(const item of selected){try{await item.run();console.log('PASS '+item.name)}catch(error){failed++;console.error('FAIL '+item.name+'\n'+error.stack)}}console.log(`TOTAL ${selected.length} | PASS ${selected.length-failed} | FAIL ${failed}`);process.exitCode=failed?1:0})();
