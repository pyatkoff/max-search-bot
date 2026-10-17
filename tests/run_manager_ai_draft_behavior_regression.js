const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const {createHarness,response,deferred,assertNoMutations}=require('./run_manager_reauth_composer_behavior_regression');
const source=fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-ai-draft.js'),'utf8');
const tick=()=>new Promise(setImmediate);
const cases=[];function test(name,run){cases.push({name,run})}
const generated=(changes={})=>({ok:true,should_draft:true,draft:'Уточните удобные даты поездки.',next_action:'Уточнить даты',reason:'В запросе нет даты вылета.',checks:['Проверить состав туристов'],evidence_message_ids:[8],history_incomplete:false,revision:'synthetic-revision-1',latest_message_id:8,...changes});
async function harness(handler=()=>response(generated())){
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
  h.setHook((url,data)=>url==='ai-draft.php'?handler(data,h):null);
  vm.runInNewContext(source,{window:h.window,document:h.document,MutationObserver:Observer,AbortController,console});
  h.ai=h.window.WorkspaceV2AiDraft;
  h.observe=()=>observers.forEach(callback=>callback());
  h.click=async id=>{await h.ids.get(id).dispatch('click');await tick()};
  h.aiCalls=()=>h.calls.filter(call=>call.url==='ai-draft.php');
  h.insert=()=>h.click('aiDraftInsert');
  h.close=()=>h.ids.get('aiDraftPanel').children[0].children[1].dispatch('click');
  return h;
}
test('binding is lazy and adds one stable button without another draft, file or submit owner',async()=>{
  const h=await harness();h.ai.bind();h.observe();
  assert.equal(h.aiCalls().length,0);assert.equal(h.ids.get('quickReplies').firstChild,h.ids.get('aiDraftOpen'));
  assert.equal(h.ids.get('aiDraftOpen').type,'button');assert.equal(h.ids.get('aiDraftPanel').hidden,true);
  assert.equal(h.ids.get('composer').querySelectorAll('textarea').length,1);assert.equal(h.ids.get('composer').querySelectorAll('input').length,1);
  assertNoMutations(h);
});
test('generation renders inert text and keeps exact draft, file, selection and scroll',async()=>{
  const literal='<img src=x onerror="throw 1"> & {unknown}';
  const h=await harness(()=>response(generated({draft:literal,reason:literal,checks:[literal],history_incomplete:true})));
  const text='  Набранный текст\nс пробелами  ',file={name:'synthetic-file.txt'};await h.draft(text);h.setFile(file);
  const reply=h.ids.get('replyText');reply.setSelectionRange(2,9,'backward');reply.scrollTop=27;reply.scrollLeft=4;
  await h.click('aiDraftOpen');
  assert.equal(reply.value,text);assert.equal(h.getFile(),file);assert.deepEqual([reply.selectionStart,reply.selectionEnd,reply.selectionDirection,reply.scrollTop,reply.scrollLeft],[2,9,'backward',27,4]);
  const body=h.ids.get('aiDraftPanel').children[2];
  assert.equal(body.querySelector('.aiDraftText').textContent,literal);assert.equal(body.querySelector('.aiDraftText').children.length,0);
  assert.equal(body.querySelector('.aiDraftChecks').firstChild.textContent,literal);
  assert.match(body.querySelector('.aiDraftWarning').textContent,/пропуски/);
  assert.equal(h.document.activeElement,h.ids.get('aiDraftPanel'));
  assert.deepEqual(h.aiCalls().map(call=>[call.action,call.conversation_id,call.latest_message_id,call.csrf]),[['generate',101,8,'synthetic-csrf']]);
  await h.close();assert.equal(h.document.activeElement,reply);assert.equal(h.ids.get('aiDraftPanel').hidden,true);assertNoMutations(h);
});
test('explicit validation appends through canonical Conversation, retains newer typing and File identity',async()=>{
  const pending=deferred();
  const h=await harness(data=>data.action==='generate'?response(generated()):pending.promise);
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
test('a recommendation without a draft has no insertion or validation request',async()=>{
  const h=await harness(()=>response(generated({should_draft:false,draft:null,next_action:'Проверить обещание менеджера'})));
  await h.draft('Свой ответ');await h.click('aiDraftOpen');assert.equal(h.ids.get('aiDraftInsert').hidden,true);
  await h.insert();assert.equal(h.aiCalls().length,1);assert.equal(h.ids.get('replyText').value,'Свой ответ');assertNoMutations(h);
});
test('server action enums and unavailable/stale errors have readable labels',async()=>{
  for(const [action,label]of Object.entries({clarify:'Уточнить запрос',answer_question:'Ответить на вопрос',check_offer:'Проверить данные',prepare_selection:'Подготовить подборку',no_contact:'Сейчас не писать'})){
    const h=await harness(()=>response(generated({next_action:action,should_draft:false,draft:null})));await h.click('aiDraftOpen');assert.equal(h.ids.get('aiDraftPanel').children[2].querySelector('.aiDraftAction').textContent,label);assertNoMutations(h);
  }
  for(const [error,label]of [['context_stale','Переписка изменилась'],['provider_not_configured','AI сейчас недоступен']]){
    const h=await harness(()=>response({ok:false,error},409));await h.draft('Свой текст');await h.click('aiDraftOpen');assert.ok(h.ids.get('aiDraftStatus').textContent.includes(label));assert.equal(h.ids.get('replyText').value,'Свой текст');assertNoMutations(h);
  }
});
for(const change of ['navigation','account','auth','reopen'])test('late generation401 is inert after '+change,async()=>{
  const pending=deferred(),h=await harness(()=>pending.promise);await h.draft('Исходный черновик');await h.click('aiDraftOpen');
  if(change==='navigation')await h.C.open(102);
  if(change==='account'){h.W.S.authGeneration++;h.W.S.manager={...h.W.S.manager,id:9};h.W.S.detail.conversation.manager_id=9}
  if(change==='auth'){h.W.showAuthRecovery();h.W.S.authExpired=false;h.ids.get('composer').classList.remove('hidden')}
  if(change==='reopen')await h.C.open(101,{preserveAttachment:true});
  h.observe();const before=h.W.S.authExpired;pending.resolve(response({ok:false,error:'unauthorized'},401));await tick();
  assert.equal(h.W.S.authExpired,before);assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiDraftPanel').children[2].children.length,0);assertNoMutations(h);
});
for(const phase of ['generation','preview','validation'])test('new messages invalidate '+phase+' without an automatic replacement request',async()=>{
  const pending=deferred();
  const h=await harness(data=>phase==='generation'||phase==='validation'&&data.action==='validate'?pending.promise:response(generated()));
  await h.draft('Сохранить текст');const file={name:'keep.txt'};h.setFile(file);await h.click('aiDraftOpen');
  if(phase==='validation')await h.insert();
  h.W.S.detail.messages.push({id:9,sender_type:'customer',text:'Synthetic newer requirement'});h.observe();
  pending.resolve(response(phase==='validation'?{ok:true,revision:'synthetic-revision-1',latest_message_id:8}:generated()));await tick();await h.insert();
  assert.equal(h.ids.get('aiDraftInsert').hidden,true);assert.match(h.ids.get('aiDraftStatus').textContent,/изменилась/);
  assert.equal(h.ids.get('replyText').value,'Сохранить текст');assert.equal(h.getFile(),file);assert.equal(h.aiCalls().length,phase==='validation'?2:1);assertNoMutations(h);
});
test('same-ID edits also invalidate the preview',async()=>{
  const h=await harness();await h.click('aiDraftOpen');h.W.S.detail.messages[1].text='Synthetic edited request';h.observe();await h.insert();
  assert.match(h.ids.get('aiDraftStatus').textContent,/изменилась/);assert.equal(h.aiCalls().length,1);assertNoMutations(h);
});
for(const validation of [response({ok:false,error:'context_changed'},409),response({ok:true,revision:'new-revision',latest_message_id:8}),response({ok:true,revision:'synthetic-revision-1',latest_message_id:9})])test('server validation rejects an unseen revision or message change',async()=>{
  const h=await harness(data=>data.action==='generate'?response(generated()):validation);await h.draft('Свой текст');await h.click('aiDraftOpen');await h.insert();
  assert.equal(h.ids.get('replyText').value,'Свой текст');assert.equal(h.ids.get('aiDraftInsert').hidden,true);assert.match(h.ids.get('aiDraftStatus').textContent,/изменилась/);assertNoMutations(h);
});
test('permission loss during validation hides the source preview and keeps insertion blocked',async()=>{
  const pending=deferred(),h=await harness(data=>data.action==='generate'?response(generated()):pending.promise);
  await h.draft('Свой текст');await h.click('aiDraftOpen');await h.insert();h.W.S.detail.conversation.manager_id=8;h.observe();
  pending.resolve(response({ok:true,revision:'synthetic-revision-1',latest_message_id:8}));await tick();
  assert.equal(h.ids.get('aiDraftPanel').hidden,true);assert.equal(h.ids.get('aiDraftOpen').disabled,true);assert.equal(h.ids.get('replyText').value,'Свой текст');assertNoMutations(h);
});
test('canonical unresolved-token refusal does not bypass the draft boundary',async()=>{
  const h=await harness(data=>response(data.action==='generate'?generated({draft:'Здравствуйте, {unknown}'}):{ok:true,revision:'synthetic-revision-1',latest_message_id:8}));
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
