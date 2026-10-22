const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const {createHarness,response,deferred,assertNoMutations}=require('./run_manager_reauth_composer_behavior_regression');
const source=fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-ai-draft.js'),'utf8');
const tick=()=>new Promise(setImmediate);
const cases=[];function test(name,run){cases.push({name,run})}
const generated=(changes={})=>({ok:true,should_draft:true,draft:'Уточните удобные даты поездки.',next_action:'Уточнить даты',reason:'В запросе нет даты вылета.',checks:['Проверить состав туристов'],evidence_message_ids:[8],history_incomplete:false,revision:'synthetic-revision-1',latest_message_id:8,...changes});
const prepared=(changes={})=>({...generated(),prepared:true,prepared_at:'2026-10-19T10:30:00Z',source:'chatgpt',...changes});
async function harness(handler=()=>response(prepared()),{manager2=false}={}){
  const h=createHarness();await h.start();
  const form=h.ids.get('composer'),reply=h.ids.get('replyText'),prototype=Object.getPrototypeOf(reply);
  prototype.setSelectionRange=function(start,end,direction='none'){this.selectionStart=Math.min(start,this.value.length);this.selectionEnd=Math.min(end,this.value.length);this.selectionDirection=direction};
  prototype.focus=function(){h.document.activeElement=this};
  const make=(id,tag='div')=>{const node=h.document.createElement(tag);node.id=id;return node};
  const row=make('quickReplies'),selected=make('quickRepliesSelected'),quick=make('quickRepliesOpen','button');
  row.className='quickReplies qrToolbar';row.appendChild(selected);row.appendChild(quick);form.appendChild(row);form.appendChild(reply);form.appendChild(h.ids.get('replyFile'));
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
  vm.runInNewContext(source,{window:h.window,document:h.document,MutationObserver:Observer,AbortController,Date:class extends Date{static now(){return now}},console});
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
const readiness=(changes={})=>({ok:true,prepared:true,should_draft:true,revision:'synthetic-revision-1',latest_message_id:8,prepared_at:new Date().toISOString(),expires_at:new Date(Date.now()+3600000).toISOString(),...changes});
test('Manager2 status observes metadata without preview, focus, generation or composer changes',async()=>{
  const pending=deferred(),h=await harness(()=>pending.promise,{manager2:true});
  const file={name:'kept.txt'};await h.draft('Набранный текст');h.setFile(file);const reply=h.ids.get('replyText');reply.focus();reply.setSelectionRange(1,5,'backward');
  pending.resolve(response(readiness()));await tick();
  assert.deepEqual(h.aiCalls().map(c=>c.action),['prepared_status']);assert.equal(h.ids.get('aiDraftOpen').textContent,'AI — Ответ готов');assert.equal(h.ids.get('aiDraftOpen').dataset.aiState,'prepared');
  assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiReadyNotice').hidden,false);assert.equal(h.document.activeElement,reply);assert.equal(reply.value,'Набранный текст');assert.equal(h.getFile(),file);assert.equal(reply.selectionStart,1);assertNoMutations(h);
});
test('personal hide setting changes only the readiness notice, not the ready button or preview',async()=>{
  let show=false;
  const h=await harness(data=>response(data.action==='prepared_status'?readiness():prepared()),{manager2:true});await tick();
  h.window.WorkspaceV2PersonalWorkspace={showAiReadyNotice:()=>show};h.ai.syncPreferences();assert.equal(h.ids.get('aiReadyNotice').hidden,true);assert.equal(h.ids.get('aiDraftOpen').textContent,'AI — Ответ готов');
  show=true;h.ai.syncPreferences();assert.equal(h.ids.get('aiReadyNotice').hidden,false);
  await h.click('aiDraftOpen');assert.equal(h.ids.get('aiDraftPanel').hidden,false);assert.equal(h.ids.get('aiReadyNotice').hidden,true);assertNoMutations(h);
});
test('readiness without a customer draft is described as recommendations and stays quiet',async()=>{
  const h=await harness(()=>response(readiness({should_draft:false})),{manager2:true});await tick();
  assert.equal(h.ids.get('aiDraftOpen').textContent,'AI — Рекомендации');assert.equal(h.ids.get('aiReadyNotice').hidden,true);assert.equal(h.ids.get('aiDraftPanel').hidden,true);assertNoMutations(h);
});
test('late status401 after account change cannot expire the next session or announce readiness',async()=>{
  const pending=deferred(),h=await harness(()=>pending.promise,{manager2:true});h.W.S.authGeneration++;h.W.S.manager={...h.W.S.manager,id:9};h.W.S.detail.conversation.manager_id=9;
  pending.resolve(response({ok:false,error:'unauthorized'},401));await tick();
  assert.equal(h.W.S.authExpired,false);assert.equal(h.ids.get('aiReadyNotice').hidden,true);assert.equal(h.ids.get('aiDraftOpen').textContent,'AI');assertNoMutations(h);
});
test('expiry sync hides the notice before a throttled timer without touching the composer',async()=>{
  const h=await harness(()=>response(readiness()),{manager2:true});await tick();
  const file={name:'keep.txt'};await h.draft('Мой ответ');h.setFile(file);
  const reply=h.ids.get('replyText');reply.focus();reply.setSelectionRange(1,5,'backward');reply.scrollTop=17;
  assert.equal(h.ids.get('aiReadyNotice').hidden,false);const requests=h.aiCalls().length;
  h.advanceAiClock(3601000); // Simulate a sleeping tab without firing any timer.
  h.ai.syncPreferences();assert.equal(h.ids.get('aiReadyNotice').hidden,true);
  h.ai.sync();assert.equal(h.ids.get('aiDraftOpen').textContent,'AI');
  assert.equal(h.aiCalls().length,requests);assert.equal(reply.value,'Мой ответ');assert.equal(h.getFile(),file);
  assert.equal(h.document.activeElement,reply);assert.deepEqual([reply.selectionStart,reply.selectionEnd,reply.selectionDirection,reply.scrollTop],[1,5,'backward',17]);assertNoMutations(h);
});
test('expiry clears a ready button even after the bounded observer has stopped',async()=>{
  const h=await harness(()=>response(readiness()),{manager2:true});await tick();h.advanceAiClock(600001);
  const poll=[...h.aiTimers.values()].find(t=>t.ms===15000);await poll.fn();await tick();assert.equal(h.aiCalls().length,1);
  const expiry=[...h.aiTimers.values()].find(t=>t.ms>600000);expiry.fn();assert.equal(h.ids.get('aiDraftOpen').textContent,'AI');assert.equal(h.ids.get('aiReadyNotice').hidden,true);assertNoMutations(h);
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
test('Escape and pagehide clear private generated content without sending or persisting it',async()=>{
  const h=await harness();await h.click('aiDraftOpen');let stopped=false;
  for(const handler of h.ids.get('aiDraftPanel').handlers.keydown)handler({key:'Escape',preventDefault(){},stopPropagation(){stopped=true}});
  assert.equal(stopped,true);assert.equal(h.ids.get('aiDraftPanel').hidden,true);await h.click('aiDraftOpen');h.events.get('pagehide')();
  assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiDraftPanel').children[2].children.length,0);assertNoMutations(h);
});
(async()=>{let failed=0;for(const item of cases){try{await item.run();console.log('PASS '+item.name)}catch(error){failed++;console.error('FAIL '+item.name+'\n'+error.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
