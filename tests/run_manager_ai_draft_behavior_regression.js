const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const {createHarness,response,deferred,assertNoMutations}=require('./run_manager_reauth_composer_behavior_regression');
const source=fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-ai-draft.js'),'utf8');
const tick=()=>new Promise(setImmediate);
const cases=[];function test(name,run){cases.push({name,run})}
const generated=(changes={})=>({ok:true,should_draft:true,draft:'Уточните удобные даты поездки.',next_action:'clarify',reason:'В запросе нет даты вылета.',checks:['Проверить состав туристов'],evidence_message_ids:[8],history_incomplete:false,revision:'synthetic-revision-1',latest_message_id:8,...changes});
const preparedAt=new Date().toISOString(),expiresAt=new Date(Date.now()+3600000).toISOString();
const prepared=(changes={})=>({...generated(),prepared:true,prepared_at:preparedAt,expires_at:expiresAt,source:'chatgpt',...changes});
async function harness(handler=()=>response(prepared()),{manager2=false,hosted=false}={}){
  const h=createHarness();await h.start();
  const form=h.ids.get('composer'),reply=h.ids.get('replyText'),prototype=Object.getPrototypeOf(reply);
  prototype.setSelectionRange=function(start,end,direction='none'){this.selectionStart=Math.min(start,this.value.length);this.selectionEnd=Math.min(end,this.value.length);this.selectionDirection=direction};
  prototype.focus=function(){h.document.activeElement=this};
  prototype.getAttribute=function(name){return this[name]??null};
  // Deterministic DOM APIs for behavior execution, not a layout/paint oracle.
  // Actual sizing, clipping and native input remain browser-fixture checks.
  const style=()=>({
    setProperty(name,value){this[name]=String(value)},
    getPropertyValue(name){return this[name]??''},
    removeProperty(name){const previous=this[name]??'';delete this[name];return previous}
  });
  for(const node of [h.document.body,...h.ids.values()])Object.assign(node.style,style());
  const createElement=h.document.createElement;
  h.document.createElement=tag=>{const node=createElement(tag);Object.assign(node.style,style());return node};
  prototype.getBoundingClientRect=function(){
    const {top=0,left=0,width=600,height=this.clientHeight}=this.fixtureRect||{};
    return {top,left,width,height,right:left+width,bottom:top+height,x:left,y:top};
  };
  const querySelectorAll=prototype.querySelectorAll;
  prototype.querySelectorAll=function(selector){
    return selector.includes(',')?[...new Set(selector.split(',').flatMap(part=>querySelectorAll.call(this,part.trim())))]:querySelectorAll.call(this,selector);
  };
  const getComputedStyle=node=>({display:node.hidden||node.classList.contains('hidden')?'none':node.style.display||'block',position:node.style.position||'static',
    paddingTop:node.style.paddingTop||'0px',paddingBottom:node.style.paddingBottom||'0px',marginTop:node.style.marginTop||'0px',marginBottom:node.style.marginBottom||'0px',rowGap:node.style.rowGap||'0px'});
  h.ids.get('conversationZone').clientHeight=900;form.clientHeight=180;
  h.window.innerHeight=900;h.window.getComputedStyle=getComputedStyle;
  const make=(id,tag='div')=>{const node=h.document.createElement(tag);node.id=id;return node};
  const row=make('quickReplies'),selected=make('quickRepliesSelected'),quick=make('quickRepliesOpen','button');
  const main=make('composerMain');main.className='composerMain';reply.placeholder='Напишите туристу…';main.appendChild(reply);
  row.className='quickReplies qrToolbar';row.appendChild(selected);row.appendChild(quick);form.appendChild(row);form.appendChild(main);form.appendChild(h.ids.get('replyFile'));
  h.ids.get('conversationZone').appendChild(h.ids.get('composerLocked'));h.ids.get('conversationZone').appendChild(form);
  if(hosted){
    const zone=h.ids.get('conversationZone'),head=make('syntheticConversationHead');
    head.clientHeight=64;
    head.after=node=>zone.insertBefore(node,h.ids.get('composerLocked'));zone.insertBefore(head,zone.firstChild);
    h.document.querySelector=selector=>selector==='#conversationZone .conversationHead'?head:null;
  }
  reply.selectionStart=reply.selectionEnd=0;reply.selectionDirection='none';reply.scrollLeft=0;
  h.W.S.detail.messages=[{id:5,sender_type:'manager',text:'Synthetic previous reply'},{id:8,sender_type:'customer',text:'Synthetic request'}];
  const observers=[];
  class Observer{constructor(callback){observers.push(callback)}observe(){}}
  h.document.readyState='complete';
  const aiTimers=new Map();let timerId=0,now=Date.now();
  if(manager2)h.document.body.classList.add('manager2');
  h.window.setTimeout=(fn,ms)=>{const id=++timerId;aiTimers.set(id,{fn,ms});return id};
  h.window.clearTimeout=id=>aiTimers.delete(id);
  h.aiTimers=aiTimers;h.advanceAiClock=ms=>{now+=ms};
  h.setHook((url,data)=>url==='ai-draft.php'?handler(data,h):null);
  vm.runInNewContext(source,{window:h.window,document:h.document,innerHeight:900,getComputedStyle,MutationObserver:Observer,AbortController,Date:class extends Date{static now(){return now}},console});
  h.ai=h.window.WorkspaceV2AiDraft;
  h.observe=()=>observers.forEach(callback=>callback());
  h.click=async id=>{await h.ids.get(id).dispatch('click');await tick()};
  h.aiCalls=()=>h.calls.filter(call=>call.url==='ai-draft.php');
  h.insert=()=>h.click('aiDraftInsert');
  h.generate=()=>h.click('aiDraftOpen');
  h.close=()=>h.ids.get('aiDraftPanel').children[0].children[1].dispatch('click');
  return h;
}
test('supervisor reads and explicitly prepares foreign AI without insertion or automatic polling',async()=>{
  const h=await harness(data=>response(data.action==='prepared'?prepared({supervisor_read:true,owner_id:9,draft_id:null}):generated({supervisor_read:true,owner_id:9,draft_id:null})),{manager2:false});
  h.W.S.detail.conversation.manager_id=9;h.W.S.detail.supervisor_read=true;h.ids.get('composer').classList.add('hidden');h.ai.sync();
  const file={name:'retained.txt'};h.setFile(file);await h.draft('Existing private draft');const initial=h.aiCalls().length;
  h.ai.openSupervisor();await tick();
  assert.equal(h.aiCalls().length,initial+1);assert.equal(h.aiCalls().at(-1).action,'prepared');assert.equal(h.ids.get('aiDraftInsert').hidden,true);assert.equal(h.ids.get('aiDraftInsert').disabled,true);assert.match(h.ids.get('aiDraftStatus').textContent,/Просмотр руководителя/);
  await h.insert();assert.equal(h.aiCalls().filter(c=>c.action==='validate').length,0);assert.equal(h.ids.get('replyText').value,'Existing private draft');assert.equal(h.getFile(),file);
  await h.click('aiDraftRetry');assert.equal(h.aiCalls().at(-1).action,'generate');assertNoMutations(h);
});
for(const state of ['waiting_manager','ai','closed'])test('workspace owner explicitly analyzes '+state+' without taking or inserting',async()=>{
  const h=await harness(data=>response(data.action==='prepared'?{ok:true,prepared:false}:generated()),{manager2:false});
  h.W.S.manager.role='admin';h.W.S.detail.conversation.manager_id=null;h.W.S.detail.conversation.status=state;h.W.S.detail.owner_read=true;h.ids.get('composer').classList.add('hidden');h.ai.sync();const start=h.aiCalls().length;
  h.ai.openSupervisor();await tick();assert.deepEqual(h.aiCalls().slice(start).map(c=>c.action),['prepared','generate']);assert.match(h.ids.get('aiDraftStatus').textContent,/Режим владельца/);assert.equal(h.ids.get('aiDraftInsert').hidden,true);assertNoMutations(h);
});
test('owner role loss invalidates a late generated preview',async()=>{
  const pending=deferred(),h=await harness(()=>pending.promise);h.W.S.manager.role='admin';h.W.S.detail.conversation.manager_id=null;h.W.S.detail.conversation.status='waiting_manager';h.W.S.detail.owner_read=true;h.ids.get('composer').classList.add('hidden');h.ai.sync();h.ai.openSupervisor();h.W.S.manager.role='manager';h.ai.sync();pending.resolve(response(prepared()));await tick();assert.equal(h.ids.get('aiDraftPanel').textContent.includes('Уточните удобные даты'),false);assertNoMutations(h);
});
test('supervisor late foreign response cannot survive a reassignment or scope loss',async()=>{
  for(const change of [h=>{h.W.S.detail.conversation.manager_id=10},h=>{h.W.S.detail.supervisor_read=false},h=>{h.W.S.authGeneration++}]){
    const pending=deferred(),h=await harness(()=>pending.promise);h.W.S.detail.conversation.manager_id=9;h.W.S.detail.supervisor_read=true;h.ids.get('composer').classList.add('hidden');h.ai.sync();h.ai.openSupervisor();change(h);h.ai.sync();pending.resolve(response(prepared({supervisor_read:true,owner_id:9})));await tick();assert.equal(h.ids.get('aiDraftInsert').hidden,true);assert.equal(h.ids.get('aiDraftPanel').textContent.includes('Уточните удобные даты'),false);assertNoMutations(h);
  }
});
test('binding is lazy and adds one stable button without another draft, file or submit owner',async()=>{
  const h=await harness();h.ai.bind();h.observe();
  assert.equal(h.aiCalls().length,0);assert.equal(h.ids.get('quickReplies').firstChild,h.ids.get('aiDraftOpen'));
  assert.equal(h.ids.get('aiDraftOpen').type,'button');assert.equal(h.ids.get('aiDraftPanel').hidden,true);
  assert.equal(h.ids.get('composer').querySelectorAll('textarea').length,1);assert.equal(h.ids.get('composer').querySelectorAll('input').length,1);
  assertNoMutations(h);
});
const readiness=(changes={})=>({ok:true,prepared:true,should_draft:true,revision:'synthetic-revision-1',latest_message_id:8,prepared_at:preparedAt,expires_at:expiresAt,...changes});
const automaticMiss={ok:true,prepared:false,automatic_preparation:true};
async function pollAi(h){const timer=[...h.aiTimers.entries()].filter(([,value])=>value.ms===15000).at(-1);assert.ok(timer);h.aiTimers.delete(timer[0]);await timer[1].fn();await tick()}
test('opening an empty own chat queues one automatic preparation and later shows a Tab-ready ghost without AI click',async()=>{
  let completed=false;const queued=deferred(),h=await harness(data=>data.action==='prepared_status'?response(completed?readiness():automaticMiss):data.action==='ensure_prepared'?queued.promise:inlineHandler(data),{manager2:true});await tick();
  const reply=h.ids.get('replyText'),file={name:'keep-auto.txt'};h.setFile(file);reply.focus();
  assert.deepEqual(h.aiCalls().map(call=>call.action),['prepared_status','ensure_prepared']);assert.match(h.ids.get('aiPreparationNotice').children[0].textContent,/готовит подсказку автоматически/);assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(reply.value,'');
  queued.resolve(response({ok:true,requested:true,preparation_state:'pending',queued:true}));await tick();
  await pollAi(h);h.ai.sync();await tick();assert.equal(h.aiCalls().filter(call=>call.action==='ensure_prepared').length,1);
  completed=true;await pollAi(h);assert.equal(h.ids.get('aiDraftSuggestion').hidden,false);assert.equal(h.ids.get('aiPreparationNotice').hidden,true);assert.equal(h.document.activeElement,reply);assert.equal(reply.value,'');assert.equal(h.getFile(),file);
  assert.equal(h.aiCalls().some(call=>call.action==='generate'),false);await pressInline(h);assert.equal(reply.value,generated().draft);assert.equal(h.getFile(),file);assertNoMutations(h);
});
test('opening a foreign chat queues automatic preparation only for the workspace owner and keeps the result readonly',async()=>{
  let completed=false;const first=deferred(),h=await harness(data=>data.action==='prepared_status'?completed?response(readiness()):first.promise:data.action==='ensure_prepared'?response({ok:true,requested:true,preparation_state:'pending',queued:true}):response(prepared()),{manager2:true});
  h.W.S.manager.role='admin';h.W.S.detail.owner_read=true;h.W.S.detail.supervisor_read=true;h.W.S.detail.conversation.manager_id=9;h.W.S.detail.conversation.manager_name='Другой менеджер';h.ids.get('composer').classList.add('hidden');h.ai.sync();first.resolve(response(automaticMiss));await tick();
  assert.equal(h.aiCalls().filter(call=>call.action==='ensure_prepared').length,1);assert.equal(h.ids.get('aiPreparationNotice').parentElement,h.ids.get('conversationZone'));assert.equal(h.ids.get('aiPreparationNotice').hidden,false);
  completed=true;await pollAi(h);assert.equal(h.ids.get('aiDraftSuggestion').hidden,false);assert.match(h.ids.get('aiDraftSuggestion').querySelector('strong').textContent,/Только просмотр.*Другой менеджер/);assert.equal(h.ids.get('aiSuggestionAccept').hidden,true);assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.aiCalls().some(call=>['generate','prepared','validate'].includes(call.action)),false);assertNoMutations(h);
});
for(const state of ['typed draft','hidden preference','hidden tab','ordinary supervisor'])test('automatic preparation does not start for '+state,async()=>{
  const first=deferred(),h=await harness(()=>first.promise,{manager2:true});
  if(state==='typed draft')await h.draft('Свой незаконченный ответ');else if(state==='hidden preference')h.window.WorkspaceV2PersonalWorkspace={showAiReadyNotice:()=>false};else if(state==='hidden tab'){h.document.visibilityState='hidden';h.documentEvents.get('visibilitychange')()}else{h.W.S.detail.supervisor_read=true;h.W.S.detail.conversation.manager_id=9;h.ids.get('composer').classList.add('hidden');h.ai.sync()}
  first.resolve(response(automaticMiss));await tick();assert.equal(h.aiCalls().some(call=>call.action==='ensure_prepared'||call.action==='generate'),false);assert.equal(h.ids.get('aiPreparationNotice').hidden,true);assertNoMutations(h);
});
test('automatic preparation is reused after hiding and returning, and no-contact results do not create a loop',async()=>{
  let complete=false;const h=await harness(data=>response(data.action==='prepared_status'?complete?readiness({should_draft:false}):automaticMiss:data.action==='prepared_preview'?prepared({should_draft:false,draft:null,next_action:'no_contact',reason:'Нового вопроса нет.'}):{ok:true,requested:true,preparation_state:'pending',queued:true}),{manager2:true});await tick();
  h.document.visibilityState='hidden';h.documentEvents.get('visibilitychange')();h.document.visibilityState='visible';h.documentEvents.get('visibilitychange')();await tick();await pollAi(h);
  assert.equal(h.aiCalls().filter(call=>call.action==='ensure_prepared').length,1);complete=true;await pollAi(h);await pollAi(h);assert.equal(h.ids.get('aiPreparationNotice').hidden,true);assert.equal(h.ids.get('aiDraftOpen').textContent,'Помощник — Нужна проверка');assert.equal(h.aiCalls().filter(call=>call.action==='ensure_prepared').length,1);assert.equal(h.aiCalls().some(call=>call.action==='generate'),false);assertNoMutations(h);
});
for(const error of ['preparation_unavailable','queue_unavailable','preparation_queue_full'])test('automatic '+error+' is visible and retries only after explicit retry',async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'?automaticMiss:{ok:false,error},data.action==='ensure_prepared'?503:200),{manager2:true});await tick();
  assert.equal(h.ids.get('aiPreparationNotice').hidden,false);assert.match(h.ids.get('aiPreparationNotice').children[0].textContent,/временно недоступна|Очередь AI-подсказок занята/);assert.equal(h.ids.get('aiPreparationRetry').hidden,false);
  await pollAi(h);assert.equal(h.aiCalls().filter(call=>call.action==='ensure_prepared').length,1);await h.click('aiPreparationRetry');assert.equal(h.aiCalls().filter(call=>call.action==='ensure_prepared').length,2);assert.equal(h.aiCalls().some(call=>call.action==='generate'),false);assertNoMutations(h);
});
test('pending automatic preparation becomes a truthful delay after the bounded observer and can be retried explicitly',async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'?automaticMiss:{ok:true,requested:true,preparation_state:'pending',queued:true}),{manager2:true});await tick();const calls=h.aiCalls().length;
  h.advanceAiClock(600001);await pollAi(h);assert.equal(h.aiCalls().length,calls);assert.match(h.ids.get('aiPreparationNotice').children[0].textContent,/задерживается/);assert.equal(h.ids.get('aiPreparationRetry').hidden,false);
  await h.click('aiPreparationRetry');assert.equal(h.aiCalls().filter(call=>call.action==='ensure_prepared').length,2);assert.match(h.ids.get('aiPreparationNotice').children[0].textContent,/готовит подсказку автоматически/);assert.equal(h.aiCalls().some(call=>call.action==='generate'),false);assertNoMutations(h);
});
test('a fresh visible opening after the pending attempt expires re-ensures once and keeps the current observer bounded',async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'?automaticMiss:{ok:true,requested:true,preparation_state:'pending',queued:true}),{manager2:true});await tick();h.advanceAiClock(600001);await pollAi(h);assert.equal(h.aiCalls().filter(call=>call.action==='ensure_prepared').length,1);
  h.document.visibilityState='hidden';h.documentEvents.get('visibilitychange')();h.document.visibilityState='visible';h.documentEvents.get('visibilitychange')();await tick();assert.equal(h.aiCalls().filter(call=>call.action==='ensure_prepared').length,2);await pollAi(h);assert.equal(h.aiCalls().filter(call=>call.action==='ensure_prepared').length,2);assert.match(h.ids.get('aiPreparationNotice').children[0].textContent,/готовит подсказку автоматически/);assertNoMutations(h);
});
test('an already-ready ensure receipt refreshes status immediately without waiting for a poll',async()=>{
  let complete=false;const h=await harness(data=>{if(data.action==='prepared_status')return response(complete?readiness():automaticMiss);if(data.action==='ensure_prepared'){complete=true;return response({ok:true,requested:true,preparation_state:'ready'})}return response(prepared())},{manager2:true});await tick();
  assert.deepEqual(h.aiCalls().map(call=>call.action),['prepared_status','ensure_prepared','prepared_status','prepared_preview']);assert.equal(h.ids.get('aiDraftSuggestion').hidden,false);assert.equal(h.ids.get('aiPreparationNotice').hidden,true);assertNoMutations(h);
});
for(const change of ['navigation','message','edit','role','account'])test('a late automatic enqueue receipt cannot survive '+change,async()=>{
  let statuses=0;const pending=deferred(),h=await harness(data=>data.action==='prepared_status'?response(++statuses===1?automaticMiss:{ok:true,prepared:false}):pending.promise,{manager2:true});await tick();
  if(change==='navigation')await h.C.open(102);else if(change==='message')h.W.S.detail.messages.push({id:9,sender_type:'customer',text:'Новое'});else if(change==='edit')h.W.S.detail.messages[1].text='Исправлено';else if(change==='role'){h.W.S.detail.conversation.manager_id=9;h.ids.get('composer').classList.add('hidden')}else h.W.S.authGeneration++;
  h.ai.sync();pending.resolve(response({ok:true,requested:true,preparation_state:'pending',queued:true}));await tick();assert.equal(h.ids.get('aiPreparationNotice').hidden,true);assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.aiCalls().some(call=>call.action==='generate'),false);assertNoMutations(h);
});
test('an obsolete automatic401 cannot expire a new account session',async()=>{
  let statuses=0;const pending=deferred(),h=await harness(data=>data.action==='prepared_status'?response(++statuses===1?automaticMiss:{ok:true,prepared:false}):pending.promise,{manager2:true});await tick();h.W.S.authGeneration++;h.W.S.manager={...h.W.S.manager,id:9};h.W.S.detail.conversation.manager_id=9;h.ai.sync();pending.resolve(response({ok:false,error:'unauthorized'},401));await tick();assert.equal(h.W.S.authExpired,false);assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);assertNoMutations(h);
});
test('Manager2 reads passive text without modal, focus, generation or composer changes',async()=>{
  const pending=deferred(),h=await harness(data=>data.action==='prepared_status'?pending.promise:response(prepared()),{manager2:true});
  const file={name:'kept.txt'};await h.draft('Набранный текст');h.setFile(file);const reply=h.ids.get('replyText');reply.focus();reply.setSelectionRange(1,5,'backward');
  pending.resolve(response(readiness()));await tick();
  assert.deepEqual(h.aiCalls().map(c=>c.action),['prepared_status','prepared_preview']);assert.equal(h.ids.get('aiDraftOpen').textContent,'Помощник — Ответ готов');assert.equal(h.ids.get('aiDraftOpen').dataset.aiState,'prepared');
  assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiReadyNotice').hidden,false);assert.equal(h.document.activeElement,reply);assert.equal(reply.value,'Набранный текст');assert.equal(h.getFile(),file);assert.equal(reply.selectionStart,1);assertNoMutations(h);
});
test('personal hide setting changes only the readiness notice, not the ready button or preview',async()=>{
  let show=false;
  const h=await harness(data=>response(data.action==='prepared_status'?readiness():prepared()),{manager2:true});await tick();
  h.window.WorkspaceV2PersonalWorkspace={showAiReadyNotice:()=>show};h.ai.syncPreferences();assert.equal(h.ids.get('aiReadyNotice').hidden,true);assert.equal(h.ids.get('aiDraftOpen').textContent,'Помощник — Ответ готов');
  show=true;h.ai.syncPreferences();assert.equal(h.ids.get('aiDraftSuggestion').hidden,false);assert.equal(h.ids.get('aiReadyNotice').hidden,true);
  await h.click('aiDraftOpen');assert.equal(h.ids.get('aiDraftPanel').hidden,false);assert.equal(h.ids.get('aiReadyNotice').hidden,true);assertNoMutations(h);
});
test('readiness without a customer draft passively explains no-contact without opening the preview',async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'?readiness({should_draft:false}):prepared({should_draft:false,draft:null,next_action:'no_contact',reason:'Турист поблагодарил, нового вопроса нет.'})),{manager2:true});await tick();
  assert.equal(h.ids.get('aiDraftOpen').textContent,'Помощник — Нужна проверка');assert.equal(h.ids.get('aiReadyNotice').hidden,true);assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiRecommendationNotice').hidden,false);assert.equal(h.ids.get('aiRecommendationNotice').querySelector('strong').textContent,'Сейчас отвечать не нужно');assert.equal(h.ids.get('aiRecommendationNotice').querySelector('p').textContent,'Турист поблагодарил, нового вопроса нет.');assert.deepEqual(h.aiCalls().map(c=>c.action),['prepared_status','prepared_preview']);assertNoMutations(h);
});
test('verification advice preserves typed text, File and focus; Details reuses the cached proposal without generation',async()=>{
  const pending=deferred(),reason='Сначала проверьте цену. <img src=x onerror=alert(1)>',h=await harness(data=>data.action==='prepared_status'?pending.promise:response(prepared({should_draft:false,draft:null,next_action:'check_offer',reason})),{manager2:true});
  const reply=h.ids.get('replyText'),file={name:'keep-advice.txt'};await h.draft('Мой начатый ответ');h.setFile(file);reply.focus();reply.setSelectionRange(2,5,'backward');
  pending.resolve(response(readiness({should_draft:false})));await tick();const notice=h.ids.get('aiRecommendationNotice');
  assert.equal(notice.hidden,false);assert.equal(notice.querySelector('strong').textContent,'Готового ответа пока нет · Проверки менеджеру');assert.equal(notice.querySelector('p').textContent,reason);assert.equal(notice.querySelector('p').children.length,0);assert.equal(reply.value,'Мой начатый ответ');assert.equal(h.getFile(),file);assert.equal(h.document.activeElement,reply);assert.equal(reply.selectionStart,2);assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);
  const calls=h.aiCalls().length;await h.click('aiRecommendationDetails');assert.equal(h.aiCalls().length,calls);assert.equal(h.ids.get('aiDraftPanel').hidden,false);assert.equal(notice.hidden,true);assert.equal(h.ids.get('aiDraftInsert').hidden,true);assert.equal(h.ids.get('aiDraftPanel').querySelector('.aiDraftReason').textContent,reason);await h.close();assert.equal(notice.hidden,false);assert.equal(reply.value,'Мой начатый ответ');assert.equal(h.getFile(),file);assertNoMutations(h);
});
test('workspace owner sees a private non-draft explanation in a foreign chat without insertion or feedback',async()=>{
  const first=deferred(),h=await harness(data=>data.action==='prepared_status'?first.promise:response(prepared({should_draft:false,draft:null,next_action:'no_contact',reason:'Менеджер уже ответил.'})),{manager2:true});
  h.W.S.manager.role='admin';h.W.S.detail.owner_read=true;h.W.S.detail.supervisor_read=true;h.W.S.detail.conversation.manager_id=9;h.W.S.detail.conversation.manager_name='Другой менеджер';h.ids.get('composer').classList.add('hidden');h.ai.sync();first.resolve(response(readiness({should_draft:false})));await tick();
  const notice=h.ids.get('aiRecommendationNotice');assert.equal(notice.hidden,false);assert.equal(notice.parentElement,h.ids.get('conversationZone'));assert.equal(notice.classList.contains('aiRecommendationReadonly'),true);assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);
  const calls=h.aiCalls().length;await h.click('aiRecommendationDetails');assert.match(h.ids.get('aiDraftStatus').textContent,/Режим владельца/);assert.equal(h.ids.get('aiDraftInsert').hidden,true);assert.equal(h.aiCalls().length,calls);assert.deepEqual(h.aiCalls().filter(c=>c.action!=='prepared_status').map(c=>c.action),['prepared_preview']);assertNoMutations(h);
});
for(const change of ['preference','hidden','expiry','message','edit','account','role'])test('non-draft explanation clears private reason after '+change,async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'?readiness({should_draft:false}):prepared({should_draft:false,draft:null,next_action:'no_contact',reason:'Приватное объяснение.'})),{manager2:true});await tick();const notice=h.ids.get('aiRecommendationNotice');assert.equal(notice.hidden,false);
  if(change==='preference')h.window.WorkspaceV2PersonalWorkspace={showAiReadyNotice:()=>false};else if(change==='hidden'){h.document.visibilityState='hidden';h.documentEvents.get('visibilitychange')()}else if(change==='expiry')h.advanceAiClock(3601000);else if(change==='message')h.W.S.detail.messages.push({id:9,sender_type:'customer',text:'Новое сообщение'});else if(change==='edit')h.W.S.detail.messages[1].text='Исправленный запрос';else if(change==='account'){h.W.S.authGeneration++;h.W.S.manager={...h.W.S.manager,id:9};h.W.S.detail.conversation.manager_id=9}else{h.W.S.detail.conversation.manager_id=9;h.ids.get('composer').classList.add('hidden')}
  h.ai.syncPreferences();assert.equal(notice.hidden,true);assert.equal(notice.querySelector('p').textContent,'');assert.equal(h.ids.get('replyText').value,'');assertNoMutations(h);
});
test('non-draft metadata polls retain one full explanation and a cache miss removes it',async()=>{
  let present=true;const h=await harness(data=>response(data.action==='prepared_status'?present?readiness({should_draft:false}):{ok:true,prepared:false}:prepared({should_draft:false,draft:null,next_action:'no_contact',reason:'Ответ уже получен.'})),{manager2:true});await tick();await pollAi(h);await pollAi(h);assert.equal(h.aiCalls().filter(c=>c.action==='prepared_preview').length,1);assert.equal(h.ids.get('aiRecommendationNotice').hidden,false);present=false;await pollAi(h);assert.equal(h.ids.get('aiRecommendationNotice').hidden,true);assert.equal(h.ids.get('aiRecommendationNotice').querySelector('p').textContent,'');assertNoMutations(h);
});
for(const change of ['edit','account'])test('a late private non-draft reason is discarded after '+change,async()=>{
  let statuses=0;const pending=deferred(),h=await harness(data=>data.action==='prepared_status'?response(++statuses===1?readiness({should_draft:false}):{ok:true,prepared:false}):pending.promise,{manager2:true});await tick();
  if(change==='edit')h.W.S.detail.messages[1].text='Исправленный запрос';else{h.W.S.authGeneration++;h.W.S.manager={...h.W.S.manager,id:9};h.W.S.detail.conversation.manager_id=9}
  h.ai.sync();pending.resolve(response(prepared({should_draft:false,draft:null,next_action:'no_contact',reason:'Приватный старый вывод.'})));await tick();assert.equal(h.ids.get('aiRecommendationNotice').hidden,true);assert.equal(h.ids.get('aiRecommendationNotice').querySelector('p').textContent,'');assert.equal(h.ids.get('aiDraftPanel').hidden,true);assertNoMutations(h);
});
test('a passive preview cannot replace non-draft metadata with a contradictory customer draft',async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'?readiness({should_draft:false}):prepared()),{manager2:true});await tick();assert.equal(h.ids.get('aiRecommendationNotice').hidden,true);assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);assert.equal(h.ids.get('replyText').value,'');assert.equal(h.ids.get('aiDraftOpen').textContent,'Помощник');assertNoMutations(h);
});
test('generated non-draft advice appears after closing and expires without replacing the native draft',async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'||data.action==='prepared'?{ok:true,prepared:false}:generated({should_draft:false,draft:null,next_action:'check_offer',reason:'Проверить наличие перед ответом.'})),{manager2:true});await tick();await h.click('aiDraftOpen');await h.close();const notice=h.ids.get('aiRecommendationNotice');assert.equal(notice.hidden,false);assert.equal(notice.querySelector('strong').textContent,'Готового ответа пока нет · Проверки менеджеру');h.advanceAiClock(600001);h.ai.syncPreferences();assert.equal(notice.hidden,true);assert.equal(notice.querySelector('p').textContent,'');assert.equal(h.ids.get('replyText').value,'');assertNoMutations(h);
});
test('late status401 after account change cannot expire the next session or announce readiness',async()=>{
  const pending=deferred(),h=await harness(()=>pending.promise,{manager2:true});h.W.S.authGeneration++;h.W.S.manager={...h.W.S.manager,id:9};h.W.S.detail.conversation.manager_id=9;
  pending.resolve(response({ok:false,error:'unauthorized'},401));await tick();
  assert.equal(h.W.S.authExpired,false);assert.equal(h.ids.get('aiReadyNotice').hidden,true);assert.equal(h.ids.get('aiDraftOpen').textContent,'Помощник');assertNoMutations(h);
});
test('expiry sync hides the notice before a throttled timer without touching the composer',async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'?readiness():prepared()),{manager2:true});await tick();
  const file={name:'keep.txt'};await h.draft('Мой ответ');h.setFile(file);
  const reply=h.ids.get('replyText');reply.focus();reply.setSelectionRange(1,5,'backward');reply.scrollTop=17;
  assert.equal(h.ids.get('aiReadyNotice').hidden,false);const requests=h.aiCalls().length;
  h.advanceAiClock(3601000); // Simulate a sleeping tab without firing any timer.
  h.ai.syncPreferences();assert.equal(h.ids.get('aiReadyNotice').hidden,true);
  h.ai.sync();assert.equal(h.ids.get('aiDraftOpen').textContent,'Помощник');
  assert.equal(h.aiCalls().length,requests);assert.equal(reply.value,'Мой ответ');assert.equal(h.getFile(),file);
  assert.equal(h.document.activeElement,reply);assert.deepEqual([reply.selectionStart,reply.selectionEnd,reply.selectionDirection,reply.scrollTop],[1,5,'backward',17]);assertNoMutations(h);
});
test('expiry clears a ready button even after the bounded observer has stopped',async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'?readiness():prepared()),{manager2:true});await tick();h.advanceAiClock(600001);
  const poll=[...h.aiTimers.values()].find(t=>t.ms===15000);await poll.fn();await tick();assert.equal(h.aiCalls().length,2);
  const expiry=[...h.aiTimers.values()].find(t=>t.ms>600000);expiry.fn();assert.equal(h.ids.get('aiDraftOpen').textContent,'Помощник');assert.equal(h.ids.get('aiReadyNotice').hidden,true);assertNoMutations(h);
});
const inlineHandler=data=>response(data.action==='prepared_status'?readiness():data.action==='validate'?{ok:true,revision:'synthetic-revision-1',latest_message_id:8,draft_id:'a'.repeat(32)}:prepared({draft_id:data.action==='prepared'?'a'.repeat(32):null}));
async function pressInline(h,changes={}){
  const event={key:'Tab',prevented:false,preventDefault(){this.prevented=true},stopPropagation(){},...changes};
  for(const fn of h.ids.get('replyText').handlers.keydown||[])fn(event);
  await tick();return event;
}
test('inline answer is inert visible text; Tab validates and inserts once with provenance and retained File',async()=>{
  const h=await harness(inlineHandler,{manager2:true});await tick();const reply=h.ids.get('replyText'),file={name:'keep.txt'};h.setFile(file);reply.focus();
  assert.equal(h.ids.get('aiDraftSuggestion').hidden,false);assert.equal(h.ids.get('aiDraftSuggestion').querySelector('.aiSuggestionText').textContent,generated().draft);
  assert.equal(h.ids.get('aiReadyNotice').hidden,true);assert.equal(h.ids.get('aiDraftPanel').hidden,true);
  assert.equal(h.ids.get('aiDraftSuggestion').parentElement,h.ids.get('composerMain'));assert.equal(h.ids.get('aiDraftSuggestion').classList.contains('aiSuggestionGhost'),true);assert.equal(reply.getAttribute('placeholder'),'');assert.equal(reply.value,'');
  assert.deepEqual(h.aiCalls().map(c=>c.action),['prepared_status','prepared_preview']);
  assert.equal((await pressInline(h)).prevented,true);assert.equal(reply.value,generated().draft);assert.equal(h.getFile(),file);assert.equal(h.document.activeElement,reply);
  assert.deepEqual([reply.selectionStart,reply.selectionEnd],[reply.value.length,reply.value.length]);assert.deepEqual(h.aiCalls().slice(-2).map(c=>c.action),['prepared','validate']);assert.equal(h.aiCalls().at(-1).draft_id,'a'.repeat(32));
  assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);assert.equal((await pressInline(h)).prevented,false);assertNoMutations(h);
  assert.equal(reply.getAttribute('placeholder'),'Напишите туристу…');assert.equal(h.ids.get('composer').classList.contains('aiGhostVisible'),false);
});
for(const stage of ['prepared','validate'])test('inline '+stage+' failure is visible and explicitly retries the same guarded stage',async()=>{
  let failed=true;
  const h=await harness(data=>data.action===stage&&failed?response({ok:false,error:stage==='prepared'?'prepared_unavailable':'temporary_unavailable'},503):inlineHandler(data),{manager2:true});await tick();
  const reply=h.ids.get('replyText'),file={name:'keep-after-insertion-error.txt'};h.setFile(file);reply.focus();
  await h.click('aiSuggestionAccept');
  assert.equal(h.ids.get('aiDraftPanel').hidden,false,'an explicit failed insertion must expose its explanation');
  assert.match(h.ids.get('aiDraftStatus').textContent,/Не удалось проверить/);
  assert.doesNotMatch(h.ids.get('aiDraftStatus').textContent,/Переписка изменилась/);
  assert.equal(reply.value,'');assert.equal(h.getFile(),file);assert.equal(h.document.activeElement,reply);
  assert.equal(h.aiCalls().filter(call=>call.action===stage).length,1);assert.equal(h.aiCalls().some(call=>call.action==='generate'),false);
  failed=false;
  if(stage==='prepared'){assert.equal(h.ids.get('aiDraftRetry').textContent,'Повторить проверку');await h.click('aiDraftRetry');}
  assert.equal(h.ids.get('aiDraftInsert').hidden,false);await h.insert();
  assert.equal(reply.value,generated().draft);assert.equal(h.getFile(),file);assert.equal(h.ids.get('aiDraftPanel').hidden,true);
  assert.equal(h.aiCalls().filter(call=>call.action===stage).length,2);assert.equal(h.aiCalls().at(-1).draft_id,'a'.repeat(32));
  assert.equal(h.aiCalls().some(call=>call.action==='generate'),false);assertNoMutations(h);
});
for(const change of ['server conflict','new message'])test('validation retry becomes stale with a usable explicit fresh-answer action: '+change,async()=>{
  let validations=0;const pending=deferred();
  const h=await harness(data=>data.action==='validate'?(++validations===1?response({ok:false,error:'temporary_unavailable'},503):change==='server conflict'?response({ok:false,error:'context_stale'},409):pending.promise):data.action==='generate'?response(generated({latest_message_id:data.latest_message_id,revision:'fresh-revision'})):inlineHandler(data),{manager2:true});await tick();
  const reply=h.ids.get('replyText'),file={name:'keep-through-stale-retry.txt'};h.setFile(file);reply.focus();await h.click('aiSuggestionAccept');
  assert.equal(h.ids.get('aiDraftInsert').hidden,false);assert.equal(h.ids.get('aiDraftRetry').hidden,true);
  await h.draft('Мой ответ');reply.setSelectionRange(1,5,'backward');await h.insert();
  if(change==='new message'){
    h.W.S.detail.messages.push({id:9,sender_type:'customer',text:'Новые даты поездки'});h.ai.sync();
    pending.resolve(response({ok:true,revision:'synthetic-revision-1',latest_message_id:8,draft_id:'a'.repeat(32)}));await tick();
  }
  assert.equal(h.ids.get('aiDraftPanel').hidden,false);assert.match(h.ids.get('aiDraftStatus').textContent,/Переписка изменилась/);
  assert.equal(h.ids.get('aiDraftInsert').hidden,true);assert.equal(h.ids.get('aiDraftPanel').children[2].children.length,0);
  const retry=h.ids.get('aiDraftRetry');assert.equal(retry.hidden,false,'stale validation recovery must offer a fresh-answer action');assert.equal(retry.disabled,false);assert.equal(retry.textContent,'Подготовить новый ответ');
  assert.equal(reply.value,'Мой ответ');assert.equal(h.getFile(),file);assert.equal(h.document.activeElement,reply);assert.deepEqual([reply.selectionStart,reply.selectionEnd,reply.selectionDirection],[1,5,'backward']);
  assert.equal(validations,2);assert.equal(h.aiCalls().some(call=>call.action==='generate'),false);assertNoMutations(h);
  await h.click('aiDraftRetry');
  assert.equal(h.aiCalls().filter(call=>call.action==='generate').length,1);assert.equal(h.aiCalls().at(-1).latest_message_id,change==='new message'?9:8);
  assert.equal(h.ids.get('aiDraftInsert').hidden,false);assert.equal(h.ids.get('aiDraftInsert').disabled,false);assert.equal(reply.value,'Мой ответ');assert.equal(h.getFile(),file);assertNoMutations(h);
});

test('repeated prepared recovery failure stays a read retry until an explicit new-generation choice',async()=>{
  let mode='failed';
  const h=await harness(data=>data.action==='prepared'?(mode==='failed'?response({ok:false,error:'prepared_unavailable'},503):response({ok:true,prepared:false})):inlineHandler(data),{manager2:true});await tick();
  await h.click('aiSuggestionAccept');await h.click('aiDraftRetry');
  assert.equal(h.ids.get('aiDraftPanel').hidden,false);assert.equal(h.ids.get('aiDraftRetry').textContent,'Повторить проверку');
  assert.equal(h.aiCalls().filter(call=>call.action==='prepared').length,2);assert.equal(h.aiCalls().some(call=>call.action==='generate'),false);
  mode='missing';await h.click('aiDraftRetry');assert.match(h.ids.get('aiDraftStatus').textContent,/больше недоступна/);
  assert.equal(h.ids.get('aiDraftRetry').textContent,'Подготовить новый ответ');assert.equal(h.aiCalls().some(call=>call.action==='generate'),false);assertNoMutations(h);
});
for(const stage of ['prepared','validate'])for(const status of [403,404])test('inline '+stage+' '+status+' clears private text without exposing a retry',async()=>{
  const h=await harness(data=>data.action===stage?response({ok:false,error:'reply_not_allowed'},status):inlineHandler(data),{manager2:true});await tick();
  const file={name:'keep-denied.txt'};h.setFile(file);await h.click('aiSuggestionAccept');
  assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiDraftPanel').children[2].children.length,0);
  assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);assert.equal(h.ids.get('replyText').value,'');assert.equal(h.getFile(),file);assertNoMutations(h);
});
for(const change of ['navigation','account','message','typing'])test('prepared insertion recovery rejects a late result after '+change,async()=>{
  let retrying=false;const pending=deferred();
  const h=await harness(data=>data.action==='prepared'?(retrying?pending.promise:response({ok:false,error:'prepared_unavailable'},503)):inlineHandler(data),{manager2:true});await tick();
  await h.click('aiSuggestionAccept');retrying=true;await h.click('aiDraftRetry');
  if(change==='navigation')await h.C.open(102);else if(change==='account')h.W.S.authGeneration++;else if(change==='message')h.W.S.detail.messages.push({id:9,sender_type:'customer',text:'Новое сообщение'});else await h.draft('Сохранить мой текст');
  h.ai.sync();pending.resolve(inlineHandler({action:'prepared'}));await tick();
  assert.equal(h.ids.get('replyText').value,change==='typing'?'Сохранить мой текст':'');
  if(change==='navigation'||change==='account')assert.equal(h.ids.get('aiDraftPanel').hidden,true);
  if(change==='message')assert.equal(h.ids.get('aiDraftInsert').hidden,true);
  assert.equal(h.aiCalls().some(call=>call.action==='generate'||call.action==='validate'),false);assertNoMutations(h);
});
test('a real revision conflict exposes an honest refresh instead of an insertion retry',async()=>{
  const h=await harness(data=>data.action==='validate'?response({ok:false,error:'context_stale'},409):inlineHandler(data),{manager2:true});await tick();
  await h.click('aiSuggestionAccept');assert.equal(h.ids.get('aiDraftPanel').hidden,false);assert.match(h.ids.get('aiDraftStatus').textContent,/Переписка изменилась/);
  assert.equal(h.ids.get('aiDraftInsert').hidden,true);assert.equal(h.ids.get('aiDraftPanel').children[2].children.length,0);
  assert.equal(h.ids.get('aiDraftRetry').textContent,'Повторить проверку');assert.equal(h.aiCalls().some(call=>call.action==='generate'),false);assertNoMutations(h);
});
test('inline suggestion preserves standard modified Tab, IME, typing, focus and dismissal',async()=>{
  const h=await harness(inlineHandler,{manager2:true});await tick();const reply=h.ids.get('replyText');reply.focus();
  for(const changes of [{shiftKey:true},{ctrlKey:true},{altKey:true},{metaKey:true},{isComposing:true},{repeat:true}])assert.equal((await pressInline(h,changes)).prevented,false);
  await h.draft('Свой текст');assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);assert.equal(reply.getAttribute('placeholder'),'Напишите туристу…');assert.equal((await pressInline(h)).prevented,false);assert.equal(reply.value,'Свой текст');
  await h.draft('');assert.equal(h.ids.get('aiDraftSuggestion').hidden,false);assert.equal((await pressInline(h,{key:'Escape'})).prevented,true);h.ai.sync();assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);assert.deepEqual(h.aiCalls().map(c=>c.action),['prepared_status','prepared_preview']);assertNoMutations(h);
});
test('metadata polling retains current inline text without rereading the full proposal',async()=>{
  const h=await harness(inlineHandler,{manager2:true});await tick();const poll=[...h.aiTimers.values()].find(t=>t.ms===15000);await poll.fn();await tick();
  assert.equal(h.aiCalls().filter(c=>c.action==='prepared_preview').length,1);assert.equal(h.ids.get('aiDraftSuggestion').hidden,false);assertNoMutations(h);
});
for(const state of ['manager','waiting_manager','ai','closed'])test('only workspace owner gets automatic foreign '+state+' inline preview',async()=>{
  const h=await harness(inlineHandler,{manager2:true});await tick();h.W.S.manager.role='admin';h.W.S.detail.owner_read=true;h.W.S.detail.supervisor_read=true;Object.assign(h.W.S.detail.conversation,{status:state,manager_id:state==='manager'?9:null,manager_name:'Другой менеджер'});h.ids.get('composer').classList.add('hidden');h.ai.sync();await tick();
  assert.equal(h.ids.get('aiDraftSuggestion').hidden,false);assert.match(h.ids.get('aiDraftSuggestion').querySelector('strong').textContent,/Только просмотр.*Другой менеджер/);assert.equal(h.ids.get('aiSuggestionAccept').hidden,true);assert.equal(h.ids.get('aiDraftSuggestion').parentElement,h.ids.get('conversationZone'));
  h.ids.get('replyText').focus();assert.equal((await pressInline(h)).prevented,false);assert.equal(h.aiCalls().some(c=>['generate','prepared','validate'].includes(c.action)),false);assertNoMutations(h);
});
test('ordinary supervisor foreign access stops automatic reads and clears private inline text',async()=>{
  const h=await harness(inlineHandler,{manager2:true});await tick();h.W.S.detail.supervisor_read=true;h.W.S.detail.conversation.manager_id=9;h.ids.get('composer').classList.add('hidden');const before=h.aiCalls().length;h.ai.sync();await tick();
  assert.equal(h.aiCalls().length,before);assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);assert.equal(h.ids.get('aiDraftSuggestion').querySelector('.aiSuggestionText').textContent,'');assertNoMutations(h);
});
for(const stage of ['prepared','validate'])for(const change of ['typing','expiry','hidden'])test('inline '+stage+' pending '+change+' cannot insert',async()=>{
  const pending=deferred(),h=await harness(data=>data.action===stage?pending.promise:inlineHandler(data),{manager2:true});await tick();h.ids.get('replyText').focus();await pressInline(h);
  if(change==='typing')await h.draft('Не заменять');else if(change==='expiry')h.advanceAiClock(3601000);else{h.document.visibilityState='hidden';h.documentEvents.get('visibilitychange')()}
  pending.resolve(stage==='validate'?response({ok:true,revision:'synthetic-revision-1',latest_message_id:8,draft_id:'a'.repeat(32)}):inlineHandler({action:'prepared'}));await tick();
  assert.equal(h.ids.get('replyText').value,change==='typing'?'Не заменять':'');assert.equal(h.ids.get('aiDraftPanel').hidden,true);assertNoMutations(h);
});
for(const change of ['navigation','message','edit','role','account'])test('late passive full text cannot survive '+change,async()=>{
  let statuses=0;const pending=deferred(),h=await harness(data=>data.action==='prepared_preview'?pending.promise:data.action==='prepared_status'&&++statuses>1?response({ok:true,prepared:false}):inlineHandler(data),{manager2:true});await tick();
  if(change==='navigation')await h.C.open(102);else if(change==='message')h.W.S.detail.messages.push({id:9,sender_type:'customer',text:'Новое'});else if(change==='edit')h.W.S.detail.messages[1].text='Исправлено';else if(change==='role'){h.W.S.detail.conversation.manager_id=9;h.W.S.detail.owner_read=true;h.ids.get('composer').classList.add('hidden')}else h.W.S.authGeneration++;
  h.ai.sync();pending.resolve(response(prepared()));await tick();assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);assert.equal(h.ids.get('aiDraftSuggestion').querySelector('.aiSuggestionText').textContent,'');assertNoMutations(h);
});
test('older history prepend preserves current inline text; cache replacement cannot silently change Tab text',async()=>{
  let replace=false;const h=await harness(data=>data.action==='prepared'&&replace?response(prepared({draft:'Другой вариант'})):inlineHandler(data),{manager2:true});await tick();h.W.S.detail.messages.unshift({id:1,sender_type:'customer',text:'Ранее'});h.ai.sync();
  assert.equal(h.ids.get('aiDraftSuggestion').hidden,false);replace=true;h.ids.get('replyText').focus();await pressInline(h);assert.equal(h.ids.get('replyText').value,'');assert.equal(h.aiCalls().some(c=>c.action==='validate'),false);assert.equal(h.ids.get('aiDraftSuggestion').hidden,true);assertNoMutations(h);
});
test('generated inline expiry during validation cannot insert after the UI lifetime',async()=>{
  const pending=deferred(),h=await harness(data=>data.action==='prepared_status'||data.action==='prepared'?response({ok:true,prepared:false}):data.action==='generate'?response(generated()):pending.promise,{manager2:true});await tick();await h.click('aiDraftOpen');await h.close();h.ids.get('replyText').focus();await pressInline(h);h.advanceAiClock(600001);
  pending.resolve(response({ok:true,revision:'synthetic-revision-1',latest_message_id:8}));await tick();assert.equal(h.ids.get('replyText').value,'');assertNoMutations(h);
});
test('old inline validation completion cannot cancel a newer hidden insertion',async()=>{
  const first=deferred(),second=deferred();let validations=0;
  const h=await harness(data=>data.action==='prepared_status'||data.action==='prepared'?response({ok:true,prepared:false}):data.action==='generate'?response(generated()):(++validations===1?first.promise:second.promise),{manager2:true});await tick();await h.click('aiDraftOpen');await h.close();h.ids.get('replyText').focus();await pressInline(h);
  h.ai.resetSession();await h.click('aiDraftOpen');await h.close();h.ids.get('replyText').focus();await pressInline(h);
  first.resolve(response({ok:true,revision:'synthetic-revision-1',latest_message_id:8}));await tick();assert.equal(h.ids.get('replyText').value,'');
  second.resolve(response({ok:true,revision:'synthetic-revision-1',latest_message_id:8}));await tick();assert.equal(h.ids.get('replyText').value,generated().draft);assert.equal(validations,2);assertNoMutations(h);
});
test('prepared reply renders inert text and keeps exact draft, file, selection and scroll',async()=>{
  const literal='<img src=x onerror="throw 1"> & {unknown}';
  const h=await harness(()=>response(prepared({draft:literal,reason:literal,checks:[literal],history_incomplete:true})));
  const text='  Набранный текст\nс пробелами  ',file={name:'synthetic-file.txt'};await h.draft(text);h.setFile(file);
  const reply=h.ids.get('replyText');reply.setSelectionRange(2,9,'backward');reply.scrollTop=27;reply.scrollLeft=4;
  await h.click('aiDraftOpen');
  assert.equal(reply.value,text);assert.equal(h.getFile(),file);assert.deepEqual([reply.selectionStart,reply.selectionEnd,reply.selectionDirection,reply.scrollTop,reply.scrollLeft],[2,9,'backward',27,4]);
  const body=h.ids.get('aiDraftPanel').children[2];
  assert.equal(body.querySelector('.aiDraftText').textContent,literal);assert.equal(body.querySelector('.aiDraftText').children.length,0);
  assert.equal(body.querySelector('.aiDraftChecks').firstChild.textContent,literal);
  assert.match(body.querySelector('.aiDraftWarning').textContent,/пропуски/);
  assert.equal(h.document.activeElement,h.ids.get('aiDraftPanel'));
  assert.match(h.ids.get('aiDraftStatus').textContent,/Ответ подготовлен заранее/);
  assert.deepEqual(h.aiCalls().map(call=>[call.action,call.conversation_id,call.latest_message_id,call.csrf]),[['prepared',101,8,'synthetic-csrf']]);
  await h.close();assert.equal(h.document.activeElement,reply);assert.equal(h.ids.get('aiDraftPanel').hidden,true);assertNoMutations(h);
});
test('manual ready button reopens the same private proposal without another generation or cache substitution',async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'?{ok:true,prepared:false}:data.action==='prepared'?{ok:true,prepared:false}:data.action==='generate'?generated():{ok:true,revision:'synthetic-revision-1',latest_message_id:8}),{manager2:true});await tick();
  await h.click('aiDraftOpen');await h.click('aiDraftRetry');assert.equal(h.ids.get('aiDraftOpen').dataset.aiState,'generated');await h.close();const before=h.aiCalls().length;await h.click('aiDraftOpen');assert.equal(h.aiCalls().length,before);assert.equal(h.ids.get('aiDraftPanel').children[2].querySelector('.aiDraftText').textContent,generated().draft);await h.insert();assert.equal(h.aiCalls().at(-1).action,'validate');assertNoMutations(h);
});
test('current status access denial clears an open private preview and stops repeated status reads',async()=>{
  let deny=false;const h=await harness(data=>data.action==='prepared_status'?response(deny?{ok:false,error:'reply_not_allowed'}:readiness(),deny?403:200):response(prepared()),{manager2:true});await tick();await h.click('aiDraftOpen');deny=true;
  const [timerId,poll]=[...h.aiTimers.entries()].find(([,t])=>t.ms===15000);h.aiTimers.delete(timerId);await poll.fn();await tick();assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiDraftPanel').children[2].children.length,0);const count=h.aiCalls().length;h.ai.sync();await tick();assert.equal(h.aiCalls().length,count);assert.equal([...h.aiTimers.values()].filter(t=>t.ms===15000).length,0);assertNoMutations(h);
});
for(const unavailable of [false,true])test('cache '+(unavailable?'unavailable':'miss')+' generates on the first click and preserves the composer',async()=>{
  const pending=deferred();
  const h=await harness(data=>data.action==='prepared'?response(unavailable?{ok:false,error:'prepared_unavailable'}:{ok:true,prepared:false},unavailable?503:200):pending.promise);
  const file={name:'keep.txt'};await h.draft('Свой текст  ');h.setFile(file);await h.click('aiDraftOpen');
  assert.equal(h.ids.get('replyText').value,'Свой текст  ');assert.equal(h.getFile(),file);assert.equal(h.ids.get('aiDraftInsert').hidden,true);
  assert.deepEqual(h.aiCalls().map(call=>call.action),['prepared','generate']);assert.equal(h.ids.get('aiDraftOpen').disabled,true);
  pending.resolve(response(generated()));await tick();assert.equal(h.ids.get('aiDraftInsert').hidden,false);
  assert.doesNotMatch(h.ids.get('aiDraftStatus').textContent,/Готового ответа пока нет|подготовлен заранее/);
  assert.equal(h.ids.get('replyText').value,'Свой текст  ');assert.equal(h.getFile(),file);assertNoMutations(h);
});
test('a prepared check without an answer starts one fresh generation including photos',async()=>{
  const h=await harness(data=>response(data.action==='prepared'?prepared({should_draft:false,draft:null,next_action:'check_offer'}):generated()));
  await h.click('aiDraftOpen');assert.deepEqual(h.aiCalls().map(c=>c.action),['prepared','generate']);assert.equal(h.ids.get('aiDraftInsert').hidden,false);assertNoMutations(h);
});
test('a no-contact decision explains itself without another model call',async()=>{
  const h=await harness(()=>response(prepared({should_draft:false,draft:null,next_action:'no_contact',reason:'Турист попросил не писать.'})));
  await h.click('aiDraftOpen');assert.deepEqual(h.aiCalls().map(c=>c.action),['prepared']);assert.match(h.ids.get('aiDraftStatus').textContent,/Сейчас отвечать не нужно/);assert.equal(h.ids.get('aiDraftInsert').hidden,true);assertNoMutations(h);
});
test('fallback generation is discarded after a conversation switch',async()=>{
  const pending=deferred(),h=await harness(data=>data.action==='prepared'?response({ok:true,prepared:false}):pending.promise);
  await h.draft('Свой текст');await h.click('aiDraftOpen');await h.C.open(102);pending.resolve(response(generated()));await tick();
  assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('replyText').value,'');assertNoMutations(h);
});
test('prepared insertion carries the server identity through validation and edited sending',async()=>{
  const h=await harness(data=>response(data.action==='prepared'?prepared({draft_id:'a'.repeat(32)}):{ok:true,revision:'synthetic-revision-1',latest_message_id:8,draft_id:'a'.repeat(32)}));
  await h.click('aiDraftOpen');await h.insert();assert.deepEqual(h.aiCalls().map(call=>call.action),['prepared','validate']);assert.equal(h.aiCalls()[1].draft_id,'a'.repeat(32));
  await h.draft('Исправленный менеджером ответ');
  h.setHook((url,data)=>data.action==='send'?response({ok:false},409):null);await h.C.sendReply();assert.equal(h.calls.find(call=>call.action==='send').ai_draft_id,'a'.repeat(32));
});
test('a stale cached cutoff is hidden without calling the provider',async()=>{
  const h=await harness(()=>response(prepared({latest_message_id:7})));await h.draft('Свой текст');await h.click('aiDraftOpen');await h.insert();
  assert.match(h.ids.get('aiDraftStatus').textContent,/Переписка изменилась/);assert.equal(h.ids.get('aiDraftInsert').hidden,true);assert.equal(h.ids.get('replyText').value,'Свой текст');assert.deepEqual(h.aiCalls().map(call=>call.action),['prepared']);assertNoMutations(h);
});
test('reassignment while the cached reply is pending discards even a successful late response',async()=>{
  const pending=deferred(),h=await harness(()=>pending.promise);await h.draft('Свой текст');await h.click('aiDraftOpen');
  h.W.S.detail.conversation.manager_id=8;h.observe();pending.resolve(response(prepared()));await tick();
  assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiDraftOpen').disabled,true);assert.equal(h.ids.get('replyText').value,'Свой текст');assertNoMutations(h);
});
test('current cache permission denial clears the private preview',async()=>{
  const h=await harness(()=>response({ok:false,error:'forbidden'},403));await h.draft('Свой текст');await h.click('aiDraftOpen');
  assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiDraftPanel').children[2].children.length,0);assert.equal(h.ids.get('replyText').value,'Свой текст');assertNoMutations(h);
});
test('explicit validation appends through canonical Conversation, retains newer typing and File identity',async()=>{
  const pending=deferred();
  const h=await harness(data=>data.action==='prepared'?response(prepared()):pending.promise);
  const file={name:'synthetic.png'};h.setFile(file);await h.draft('Исходный текст');await h.click('aiDraftOpen');
  await h.insert();assert.equal(h.aiCalls().length,2);assert.equal(h.ids.get('replyText').value,'Исходный текст');
  await h.draft('Более новый текст  ');const reply=h.ids.get('replyText');reply.setSelectionRange(2,7,'backward');reply.scrollTop=19;
  pending.resolve(response({ok:true,revision:'synthetic-revision-1',latest_message_id:8}));await tick();
  assert.equal(reply.value,'Более новый текст  \nУточните удобные даты поездки.');assert.equal(h.getFile(),file);
  assert.deepEqual([reply.selectionStart,reply.selectionEnd,reply.selectionDirection,reply.scrollTop],[2,7,'backward',19]);
  assert.equal(h.document.activeElement,reply);assert.equal(h.ids.get('aiDraftPanel').hidden,true);
  assert.equal(h.aiCalls()[1].action,'validate');assert.equal(h.aiCalls()[1].revision,'synthetic-revision-1');
  await h.C.open(102);await h.C.open(101);assert.equal(reply.value,'Более новый текст  \nУточните удобные даты поездки.');assertNoMutations(h);
});
for(const returnedId of ['a'.repeat(32),null,undefined,'b'.repeat(32),'A'.repeat(32)])test('insertion carries only the same validated optional identity: '+String(returnedId),async()=>{
  const id='a'.repeat(32),h=await harness(data=>response(data.action==='prepared'?{ok:true,prepared:false}:data.action==='generate'?generated({draft_id:id}):{ok:true,revision:'synthetic-revision-1',latest_message_id:8,...(returnedId===undefined?{}:{draft_id:returnedId})}));
  await h.generate();await h.insert();
  assert.equal(h.aiCalls()[2].draft_id,id);assert.equal(h.ids.get('replyText').value,generated().draft);assertNoMutations(h);
  h.setHook((url,data)=>data.action==='send'?response({ok:false},409):null);await h.C.sendReply();
  const sends=h.calls.filter(call=>call.action==='send');assert.equal(sends.length,1);
  assert.equal(sends[0].ai_draft_id,returnedId===id?id:undefined);
});
for(const generatedId of [undefined,null,'invalid','A'.repeat(32)])test('legacy or untracked proposal remains usable without accepting an unsolicited validation identity: '+String(generatedId),async()=>{
  const h=await harness(data=>response(data.action==='prepared'?{ok:true,prepared:false}:data.action==='generate'?generated(generatedId===undefined?{}:{draft_id:generatedId}):{ok:true,revision:'synthetic-revision-1',latest_message_id:8,draft_id:'b'.repeat(32)}));
  await h.generate();await h.insert();assert.equal(h.aiCalls()[2].draft_id,undefined);assert.equal(h.ids.get('replyText').value,generated().draft);
  h.setHook((url,data)=>data.action==='send'?response({ok:false},409):null);await h.C.sendReply();assert.equal(h.calls.find(call=>call.action==='send').ai_draft_id,undefined);
});
test('closing a tracked preview neither associates the composer nor submits feedback',async()=>{
  const h=await harness(data=>response(data.action==='prepared'?{ok:true,prepared:false}:generated({draft_id:'a'.repeat(32)})));await h.draft('Manual reply');await h.generate();await h.close();
  assert.equal(h.aiCalls().length,2);assertNoMutations(h);
  h.setHook((url,data)=>data.action==='send'?response({ok:false},409):null);await h.C.sendReply();assert.equal(h.calls.find(call=>call.action==='send').ai_draft_id,undefined);
});
test('late tracked validation cannot associate a different conversation',async()=>{
  const pending=deferred(),id='a'.repeat(32),h=await harness(data=>data.action==='prepared'?response({ok:true,prepared:false}):data.action==='generate'?response(generated({draft_id:id})):pending.promise);
  await h.generate();await h.insert();await h.C.open(102);await h.draft('Other conversation reply');
  pending.resolve(response({ok:true,revision:'synthetic-revision-1',latest_message_id:8,draft_id:id}));await tick();
  assert.equal(h.ids.get('replyText').value,'Other conversation reply');
  h.setHook((url,data)=>data.action==='send'?response({ok:false},409):null);await h.C.sendReply();
  const sent=h.calls.find(call=>call.action==='send');assert.equal(sent.conversation_id,102);assert.equal(sent.ai_draft_id,undefined);
});
test('a recommendation without a draft has no insertion or validation request',async()=>{
  const h=await harness(()=>response(prepared({should_draft:false,draft:null,next_action:'Проверить обещание менеджера'})));
  await h.draft('Свой ответ');await h.click('aiDraftOpen');assert.equal(h.ids.get('aiDraftInsert').hidden,true);
  await h.insert();assert.equal(h.aiCalls().length,2);assert.equal(h.ids.get('replyText').value,'Свой ответ');assertNoMutations(h);
});
test('server action enums and unavailable/stale errors have readable labels',async()=>{
  for(const [action,label]of Object.entries({clarify:'Уточнить запрос',answer_question:'Ответить на вопрос',check_offer:'Проверить данные',prepare_selection:'Подготовить подборку',no_contact:'Сейчас не писать'})){
    const h=await harness(()=>response(prepared({next_action:action,should_draft:false,draft:null})));await h.click('aiDraftOpen');assert.equal(h.ids.get('aiDraftPanel').children[2].querySelector('.aiDraftAction').textContent,label);assertNoMutations(h);
  }
  for(const [error,label]of [['context_stale','Переписка изменилась'],['provider_not_configured','AI сейчас недоступен']]){
    const h=await harness(data=>response(data.action==='prepared'&&error==='provider_not_configured'?{ok:true,prepared:false}:{ok:false,error},409));await h.draft('Свой текст');
    await h.click('aiDraftOpen');if(error==='provider_not_configured')await h.click('aiDraftRetry');
    assert.ok(h.ids.get('aiDraftStatus').textContent.includes(label));assert.equal(h.ids.get('replyText').value,'Свой текст');assertNoMutations(h);
  }
});
for(const mode of ['prepared','generate'])for(const change of ['navigation','account','auth','reopen'])test('late '+mode+' response401 is inert after '+change,async()=>{
  const pending=deferred(),h=await harness(data=>mode==='generate'&&data.action==='prepared'?response({ok:true,prepared:false}):pending.promise);await h.draft('Исходный черновик');await h.click('aiDraftOpen');if(mode==='generate')await h.click('aiDraftRetry');
  if(change==='navigation')await h.C.open(102);
  if(change==='account'){h.W.S.authGeneration++;h.W.S.manager={...h.W.S.manager,id:9};h.W.S.detail.conversation.manager_id=9}
  if(change==='auth'){h.W.showAuthRecovery();h.W.S.authExpired=false;h.ids.get('composer').classList.remove('hidden')}
  if(change==='reopen')await h.C.open(101,{preserveAttachment:true});
  h.observe();const before=h.W.S.authExpired;pending.resolve(response({ok:false,error:'unauthorized'},401));await tick();
  assert.equal(h.W.S.authExpired,before);assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiDraftPanel').children[2].children.length,0);assertNoMutations(h);
});
for(const phase of ['prepared','generation','preview','validation'])test('new messages invalidate '+phase+' without an automatic replacement request',async()=>{
  const pending=deferred();
  const h=await harness(data=>phase==='generation'?data.action==='prepared'?response({ok:true,prepared:false}):pending.promise:phase==='prepared'||phase==='validation'&&data.action==='validate'?pending.promise:response(prepared()));
  await h.draft('Сохранить текст');const file={name:'keep.txt'};h.setFile(file);await h.click('aiDraftOpen');
  if(phase==='generation')await h.click('aiDraftRetry');
  if(phase==='validation')await h.insert();
  h.W.S.detail.messages.push({id:9,sender_type:'customer',text:'Synthetic newer requirement'});h.observe();
  pending.resolve(response(phase==='validation'?{ok:true,revision:'synthetic-revision-1',latest_message_id:8}:phase==='generation'?generated():prepared()));await tick();await h.insert();
  assert.equal(h.ids.get('aiDraftInsert').hidden,true);assert.match(h.ids.get('aiDraftStatus').textContent,/изменилась/);
  assert.equal(h.ids.get('replyText').value,'Сохранить текст');assert.equal(h.getFile(),file);assert.equal(h.aiCalls().length,['generation','validation'].includes(phase)?2:1);assertNoMutations(h);
});
for(const phase of ['prepared','generation','validation'])test('loading older history preserves the current '+phase+' operation and still validates before insertion',async()=>{
  const pending=deferred();
  const h=await harness(data=>data.action==='validate'?(phase==='validation'?pending.promise:response({ok:true,revision:'synthetic-revision-1',latest_message_id:8})):phase==='generation'?(data.action==='prepared'?response({ok:true,prepared:false}):pending.promise):phase==='prepared'?pending.promise:response(prepared()));
  await h.draft('Сохранить текст');const file={name:'keep-history.txt'};h.setFile(file);await h.click('aiDraftOpen');
  if(phase==='generation')await h.click('aiDraftRetry');if(phase==='validation')await h.insert();
  const calls=h.aiCalls().length;h.W.S.detail.messages.unshift({id:1,sender_type:'customer',text:'Older existing message'});h.observe();assert.equal(h.aiCalls().length,calls);
  pending.resolve(response(phase==='validation'?{ok:true,revision:'synthetic-revision-1',latest_message_id:8}:phase==='generation'?generated():prepared()));await tick();
  if(phase!=='validation')await h.insert();
  assert.ok(h.ids.get('replyText').value.includes(generated().draft));assert.ok(h.ids.get('replyText').value.includes('Сохранить текст'));assert.equal(h.getFile(),file);
  assert.equal(h.aiCalls().filter(c=>c.action==='validate').length,1);assertNoMutations(h);
});
test('edits to captured messages still invalidate after older history was added',async()=>{
  const h=await harness();await h.click('aiDraftOpen');h.W.S.detail.messages.unshift({id:1,sender_type:'customer',text:'Older'});h.observe();h.W.S.detail.messages.find(m=>m.id===5).text='Edited captured history';h.observe();await h.insert();
  assert.match(h.ids.get('aiDraftStatus').textContent,/изменилась/);assert.equal(h.aiCalls().length,1);assertNoMutations(h);
});
test('older-page loading never bypasses a changed server context revision',async()=>{
  const h=await harness(data=>data.action==='prepared'?response(prepared()):response({ok:false,error:'context_stale'},409));await h.draft('Own reply');await h.click('aiDraftOpen');h.W.S.detail.messages.unshift({id:1,sender_type:'customer',text:'Older'});h.observe();await h.insert();
  assert.equal(h.ids.get('replyText').value,'Own reply');assert.equal(h.aiCalls().filter(c=>c.action==='validate').length,1);assertNoMutations(h);
});
test('same-ID edits also invalidate the preview',async()=>{
  const h=await harness();await h.click('aiDraftOpen');h.W.S.detail.messages[1].text='Synthetic edited request';h.observe();await h.insert();
  assert.match(h.ids.get('aiDraftStatus').textContent,/изменилась/);assert.equal(h.aiCalls().length,1);assertNoMutations(h);
});
for(const validation of [response({ok:false,error:'context_changed'},409),response({ok:true,revision:'new-revision',latest_message_id:8}),response({ok:true,revision:'synthetic-revision-1',latest_message_id:9})])test('server validation rejects an unseen revision or message change',async()=>{
  const h=await harness(data=>data.action==='prepared'?response(prepared()):validation);await h.draft('Свой текст');await h.click('aiDraftOpen');await h.insert();
  assert.equal(h.ids.get('replyText').value,'Свой текст');assert.equal(h.ids.get('aiDraftInsert').hidden,true);assert.match(h.ids.get('aiDraftStatus').textContent,/изменилась/);assertNoMutations(h);
});
test('permission loss during validation hides the source preview and keeps insertion blocked',async()=>{
  const pending=deferred(),h=await harness(data=>data.action==='prepared'?response(prepared()):pending.promise);
  await h.draft('Свой текст');await h.click('aiDraftOpen');await h.insert();h.W.S.detail.conversation.manager_id=8;h.observe();
  pending.resolve(response({ok:true,revision:'synthetic-revision-1',latest_message_id:8}));await tick();
  assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiDraftOpen').disabled,true);assert.equal(h.ids.get('replyText').value,'Свой текст');assertNoMutations(h);
});
test('canonical unresolved-token refusal does not bypass the draft boundary',async()=>{
  const h=await harness(data=>response(data.action==='prepared'?prepared({draft:'Здравствуйте, {unknown}'}):{ok:true,revision:'synthetic-revision-1',latest_message_id:8}));
  await h.draft('Свой текст');await h.click('aiDraftOpen');await h.insert();assert.equal(h.ids.get('replyText').value,'Свой текст');assert.match(h.ids.get('aiDraftStatus').textContent,/Не удалось добавить/);assertNoMutations(h);
});
test('current401 still uses canonical recovery and preserves the existing draft and File',async()=>{
  const h=await harness(()=>response({ok:false,error:'unauthorized'},401)),file={name:'keep.txt'};await h.draft('Свой текст');h.setFile(file);await h.click('aiDraftOpen');h.observe();
  assert.equal(h.W.S.authExpired,true);assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('replyText').value,'Свой текст');assert.equal(h.getFile(),file);assertNoMutations(h);
});
test('one hosted assistant separates a missing-information reply from internal checks',async()=>{
  const draft='Из какого города вылетаете и какой бюджет рассматриваете?',checks=['Проверить защищённость пляжа от ветра на выбранные даты.'];
  const h=await harness(data=>response(data.action==='prepared_status'?readiness():prepared({draft,checks})),{manager2:true,hosted:true});await tick();
  assert.equal(h.ids.get('aiDraftSuggestion').parentElement,h.ids.get('m2Assistant'));
  assert.equal(h.ids.get('composer').classList.contains('aiGhostVisible'),false);
  await h.click('aiSuggestionDetails');
  const body=h.ids.get('aiDraftPanel').children[2];
  assert.deepEqual(body.querySelectorAll('.aiDraftSectionTitle').map(n=>n.textContent),['Готовый ответ туристу','Проверки менеджеру']);
  assert.equal(body.querySelector('.aiDraftText').textContent,draft);
  assert.equal(body.querySelector('.aiDraftChecks').children[0].textContent,checks[0]);
  assert.equal(h.aiCalls().some(c=>c.action==='generate'),false);assertNoMutations(h);
});
test('metadata readiness stays inside the same assistant while private preview is pending',async()=>{
  const pending=deferred();const h=await harness(data=>data.action==='prepared_preview'?pending.promise:response(readiness()),{manager2:true,hosted:true});await tick();
  assert.equal(h.ids.get('aiReadyNotice').parentElement,h.ids.get('m2Assistant'));
  assert.equal(h.ids.get('aiPreparationNotice').parentElement,h.ids.get('m2Assistant'));
  assert.equal(h.ids.get('replyText').value,'');pending.resolve(response(prepared()));await tick();assertNoMutations(h);
});
test('price-only pending work has no insertable reply and a new message invalidates its internal note',async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'?readiness({should_draft:false}):prepared({should_draft:false,draft:null,next_action:'check_offer',reason:'Нет актуального расчёта на изменённую длительность.',checks:['Рассчитать Example Bay Resort на 18 дней из Самары 5 июня; состав и питание сверить с туристом, не брать из старого AI-текста.']})),{manager2:true,hosted:true});await tick();
  await h.click('aiRecommendationDetails');
  const body=h.ids.get('aiDraftPanel').children[2];assert.equal(body.querySelector('.aiDraftText'),null);
  assert.equal(h.ids.get('aiDraftInsert').hidden,true);await h.insert();assert.equal(h.ids.get('replyText').value,'');
  h.W.S.detail.messages.push({id:9,sender_type:'customer',text:'Изменились даты.'});h.ai.sync();
  assert.equal(body.children.length,0);assert.equal(h.aiCalls().some(c=>c.action==='generate'),false);assertNoMutations(h);
});
test('a useful partial answer remains insertable while price checking stays an independent action',async()=>{
  const draft='В опубликованном описании этого номера две отдельные спальни.';
  const h=await harness(data=>response(data.action==='prepared_status'?readiness():data.action==='validate'?{ok:true,revision:'synthetic-revision-1',latest_message_id:8,draft_id:'a'.repeat(32)}:prepared({draft,next_action:'check_offer',checks:['Проверить актуальную полную цену на новые даты.']})),{manager2:true,hosted:true});await tick();
  await h.click('aiSuggestionDetails');assert.equal(h.ids.get('aiDraftInsert').hidden,false);
  assert.equal(h.ids.get('aiDraftPanel').children[2].querySelector('.aiDraftAction').textContent,'Проверить данные');
  await h.insert();assert.equal(h.ids.get('replyText').value,draft);assertNoMutations(h);
});
test('hosted readonly content has one host and no insertion across dialog changes',async()=>{
  const h=await harness(data=>response(data.action==='prepared_status'?readiness():prepared()),{manager2:true,hosted:true});await tick();
  h.W.S.manager.role='admin';h.W.S.detail.owner_read=true;h.W.S.detail.conversation.manager_id=9;h.ai.sync();await tick();
  const host=h.ids.get('m2Assistant');assert.equal(h.ids.get('aiSuggestionAccept').hidden,true);
  h.W.S.current=12;h.W.S.detail.conversation.id=12;h.ai.sync();await tick();
  assert.equal(h.ids.get('m2Assistant'),host);assert.equal(h.ids.get('conversationZone').children.filter(n=>n.id==='m2Assistant').length,1);
  await h.click('aiSuggestionDetails');await h.insert();assert.equal(h.ids.get('replyText').value,'');assertNoMutations(h);
});
test('Escape and pagehide clear private generated content without sending or persisting it',async()=>{
  const h=await harness();await h.click('aiDraftOpen');let stopped=false;
  for(const handler of h.ids.get('aiDraftPanel').handlers.keydown)handler({key:'Escape',preventDefault(){},stopPropagation(){stopped=true}});
  assert.equal(stopped,true);assert.equal(h.ids.get('aiDraftPanel').hidden,true);await h.click('aiDraftOpen');h.events.get('pagehide')();
  assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiDraftPanel').children[2].children.length,0);assertNoMutations(h);
});
(async()=>{let failed=0;for(const item of cases){try{await item.run();console.log('PASS '+item.name)}catch(error){failed++;console.error('FAIL '+item.name+'\n'+error.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
