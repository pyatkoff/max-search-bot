const assert=require('node:assert/strict'),crypto=require('node:crypto'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {setup,clone,tick,field,response,deferred}=require('./run_manager_promise_task_behavior_regression.js');
const endpoint='promise-selections-api.php',pendingPrefix='workspaceV2.promiseSelection.pending.v1.';
const cases=[],test=(name,run)=>cases.push({name,run});
const hash=value=>crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
function fixtureService({conversationId=101,actor=7}={}){
 const profile={trip_id:'a'.repeat(32),version:3,sha256:'b'.repeat(64),context_sha256:'c'.repeat(64),boundary_message_id:10,status:'fresh',label:'Поездка в Турцию',fields:{dates:{value:'20 ноября, 7 ночей'}},field_labels:{dates:'Даты поездки'}};
 const tasks=[{id:77,conversation_id:conversationId,title:'Отправить согласованную подборку',due_at_utc:'2030-11-20 12:00:00',status:'open',business_revision:5},{id:78,conversation_id:conversationId,title:'Подготовить второй вариант',due_at_utc:null,status:'open',business_revision:2}];
 const promises=tasks.map((task,index)=>({promise_id:String(index+1).repeat(64),kind:'send_selection',source_message_id:13,task}));
 const cards=[{index:0,offer_id:901,state:'pending',text:'Вариант «Море»\n20 ноября · 7 ночей · 2 взрослых\nДвухместный номер · Всё включено · 240 000 ₽',snapshot:{hotel:'Море',date_from:'2030-11-20',adults:2}},
  {index:1,offer_id:902,state:'rejected',text:'Вариант «Сад»\n20 ноября · 7 ночей · 2 взрослых\nДвухместный номер · Завтраки · 210 000 ₽',snapshot:{hotel:'Сад',date_from:'2030-11-20',adults:2}}];
 const publications=[{publication_id:'3'.repeat(32),title:'Два варианта у моря',card_count:2,created_at:'2026-11-13 09:00:00',cards},
  {publication_id:'4'.repeat(32),title:'Другой сохранённый вариант',card_count:1,created_at:'2026-11-13 09:01:00',cards:[{...clone(cards[0]),offer_id:903,text:'Другой отель · 200 000 ₽'}]}];
 const source={id:13,revision:'e'.repeat(64),author_manager_id:7,text:'Подготовлю подборку отелей к 20 ноября.',recorded_at_utc:'2026-11-13T09:00:00Z'};
 const intents=new Map(),receipts=new Map(),state={can_edit:true,reason:null,profile,tasks,promises,publications,source,intents,receipts};
 const envelope=value=>({ok:true,viewer_id:actor,conversation_id:conversationId,...value});
 const requestHash=d=>hash(d.action==='attach'?['attach',d.conversation_id,d.operation_id,d.promise_id,d.publication_id,d.preview_sha256]:['cancel',d.conversation_id,d.operation_id,d.intent_id]);
 const catalog=()=>envelope({can_edit:state.can_edit,profile:clone(profile),promises:clone(promises),publications:publications.map(p=>({publication_id:p.publication_id,title:p.title,card_count:p.cards.length,created_at:p.created_at,states:p.cards.map(c=>c.state)})),intents:clone([...intents.values()])});
 const preview=d=>{
  const p=promises.find(item=>item.promise_id===d.promise_id),publication=publications.find(item=>item.publication_id===d.publication_id),intent=[...intents.values()].find(item=>item.state==='armed'&&(item.promise_id===d.promise_id||item.publication_id===d.publication_id))||null;
  if(!p||!publication)return{ok:false,error:'not_found'};
  const reason=state.reason||(!state.can_edit?'forbidden':intent?intent.promise_id===p.promise_id&&intent.publication_id===publication.publication_id?'already_attached':'intent_conflict':null);
  return envelope({preview_sha256:hash([p,publication,profile,source,intent]),can_attach:reason===null,reason,promise:{promise_id:p.promise_id,kind:p.kind,deadline:{kind:'date',value:'2030-11-20',zone:'Europe/Moscow'}},task:clone(p.task),source:clone(source),profile:clone(profile),publication:clone(publication),intent:clone(intent)});
 };
 function write(d){
  const digest=requestHash(d),existing=receipts.get(d.operation_id);
  if(existing){assert.equal(existing.request_sha256,digest);return envelope({replayed:true,intent:clone(intents.get(existing.intent_id))});}
  let intent;
  if(d.action==='attach'){
   const publication=publications.find(item=>item.publication_id===d.publication_id),promise=promises.find(item=>item.promise_id===d.promise_id);
   intent={intent_id:crypto.createHash('md5').update(d.operation_id).digest('hex'),promise_id:d.promise_id,publication_id:d.publication_id,task_id:promise.task.id,state:'armed',reason:null,created_at:'2026-11-13 09:02:00',completed_at:null,cancelled_at:null,card_count:publication.cards.length,delivered_count:0};intents.set(intent.intent_id,intent);
  }else{intent=intents.get(d.intent_id);assert.ok(intent);if(intent.state==='armed'){intent.state='cancelled';intent.cancelled_at='2026-11-13 09:03:00';}}
  receipts.set(d.operation_id,{action:d.action,request_sha256:digest,intent_id:intent.intent_id});return envelope({replayed:false,intent:clone(intent)});
 }
 const operation=d=>{const receipt=receipts.get(d.operation_id);return envelope(receipt?{found:true,action:receipt.action,request_sha256:receipt.request_sha256,intent:clone(intents.get(receipt.intent_id))}:{found:false,action:null,request_sha256:null,intent:null});};
 const status=d=>intents.has(d.intent_id)?envelope({intent:clone(intents.get(d.intent_id))}):{ok:false,error:'not_found'};
 return{state,catalog,preview,write,operation,status,requestHash,envelope};
}
async function harness({storage,service=fixtureService(),lazy=false}={}){
 const h=await setup({storage});let intercept=null,now=Date.now();
 h.setIntercept(async(url,data)=>{
  if(url!==endpoint)return null;const custom=intercept?await intercept(data):null;if(custom!==null&&custom!==undefined)return custom;
  if(data.action==='catalog')return response(service.catalog());if(data.action==='preview')return response(service.preview(data));if(['attach','cancel'].includes(data.action))return response(service.write(data));
  if(data.action==='operation')return response(service.operation(data));if(data.action==='status')return response(service.status(data));throw Error('Unexpected promise-selection action '+data.action);
 });
 const observers=[],Observer=class{constructor(callback){observers.push(callback)}observe(){}};
 const load=()=>{h.loadAsset('workspace-v2-promise-selection.js',{AbortController,TextEncoder,clearTimeout,MutationObserver:Observer,Date:class extends Date{static now(){return now}}});h.Q=h.window.WorkspaceV2PromiseSelection;};
 if(!lazy)load();
 const opener=h.document.createElement('button');opener.id='selectionFixtureOpener';h.document.body.appendChild(opener);
 const pending=()=>[...h.storage.values.entries()].filter(([key])=>key.startsWith(pendingPrefix)).map(([key,value])=>({key,record:JSON.parse(value),value}));
 return Object.assign(h,{service,loadFeature:load,opener,advance:ms=>{now+=ms},observe:()=>observers.forEach(callback=>callback()),intercept:fn=>intercept=fn,pending,
  featureCalls:()=>h.calls.filter(call=>call.url===endpoint),writes:()=>h.calls.filter(call=>call.url===endpoint&&['attach','cancel'].includes(call.action)),
  async open(){assert.equal(await h.Q.open(opener),true)},async choose({promise=0,publication=0}={}){await field(h,'promiseSelectionPromise',service.state.promises[promise].promise_id);await field(h,'promiseSelectionPublication',service.state.publications[publication].publication_id)},
  async ready(options){await h.open();await h.choose(options);assert.equal(await h.Q.preview(),true)}});
}
function attachPresentation(h){
 h.document.head=h.document.createElement('head');h.document.head._harnessRoot=true;h.document.body.appendChild(h.$('conversationActions'));
 const source=fs.readFileSync(path.join(__dirname,'../manager2/assets/manager2.js'),'utf8');
 // Match the existing Manager2 navigation harness: run the actual owners without
 // unrelated shell boot. The feature assets and all operation logic stay intact.
 vm.runInNewContext(source.replace('  init();',''),{window:h.window,document:h.document,URL,location:{href:'https://example.invalid/manager2/'},history:h.history,MutationObserver:class{observe(){}},HTMLDetailsElement:class{},setTimeout:()=>0,clearTimeout(){},console});
 h.window.Manager2Presentation.enhanceConversationActions(h.$('conversationActions'));
 return{button:h.$('m2PromiseSelectionOpen'),assets:()=>h.document.head.children};
}

test('catalog and full preview are read-only; one explicit attach preserves all manual work',async()=>{
 const h=await harness(),before=h.snapshot();h.service.state.profile.status='needs_review';h.service.state.source.text='<img src=x> Подготовлю подборку отелей к 20 ноября.';
 await h.ready();assert.deepEqual(h.featureCalls().map(call=>call.action),['catalog','preview']);assert.equal(h.writes().length,0);
 assert.equal(h.$('promiseSelectionQuote').textContent,h.service.state.source.text);assert.equal(h.$('promiseSelectionQuote').querySelector('img'),null);
 assert.match(h.$('promiseSelectionProfile').textContent,/Сверьте текущие условия/);assert.equal(h.$('promiseSelectionCards').children.length,2);assert.match(h.$('promiseSelectionTask').textContent,/15:00 МСК/);assert.equal(h.$('promiseSelectionConfirm').hidden,false);
 const wait=deferred(),entered=deferred();h.intercept(d=>{if(d.action==='attach'){entered.resolve();return wait.promise}return null});
 const saving=h.Q.confirm();await entered.promise;assert.equal(await h.Q.confirm(),false);assert.equal(h.writes().length,1);
 const sent=h.writes()[0];assert.deepEqual(Object.keys(sent).sort(),['url','action','csrf','conversation_id','operation_id','promise_id','publication_id','preview_sha256'].sort());
 assert.equal(h.pending().length,1);assert.equal(h.pending()[0].record.request_sha256,h.service.requestHash(sent));assert.doesNotMatch(h.pending()[0].value,/Подготовлю|Турцию|Море|240 000|private-synthetic/);
 assert.deepEqual(Object.keys(h.pending()[0].record).sort(),['schema','actor','conversation_id','action','operation_id','request_sha256','promise_id','publication_id','preview_sha256'].sort());h.preserved(before);
 wait.resolve(response(h.service.write(sent)));assert.equal(await saving,true);assert.equal(h.pending().length,0);assert.match(h.$('promiseSelectionIntent').textContent,/Ожидает отправки/);assert.equal(h.service.state.tasks[0].status,'open');h.Q.close();h.preserved(before);
});

test('changing the pair revokes confirmation and a fresh server refusal never becomes an automatic retry',async()=>{
 const h=await harness();await h.ready();await field(h,'promiseSelectionPublication',h.service.state.publications[1].publication_id);assert.equal(await h.Q.confirm(),false);assert.equal(h.writes().length,0);
 assert.equal(await h.Q.preview(),true);h.intercept(d=>d.action==='attach'?response({ok:false,error:'preview_changed'},409):null);
 assert.equal(await h.Q.confirm(),false);assert.equal(h.pending().length,0);assert.equal(await h.Q.confirm(),false);assert.equal(h.writes().length,1);assert.match(h.$('promiseSelectionStatus').textContent,/изменились/);
});

test('a preview has one fixed five-minute lifetime and requires a new explicit check afterwards',async()=>{
 const h=await harness(),before=h.snapshot();await h.ready();h.advance(299000);h.Q.sync();h.advance(1001);
 assert.equal(await h.Q.confirm(),false);assert.equal(h.writes().length,0);assert.match(h.$('promiseSelectionStatus').textContent,/Срок проверки истёк/);
 assert.equal(await h.Q.preview(),true);assert.equal(await h.Q.confirm(),true);h.preserved(before);
});

test('an instant promise deadline keeps its exact seconds in Moscow without changing the server hash',async()=>{
 const h=await harness();let previewHash;await h.open();await h.choose();h.intercept(d=>{if(d.action!=='preview')return null;const result=h.service.preview(d);previewHash=result.preview_sha256;result.promise.deadline={kind:'instant',value:'2030-11-20T12:45:27Z'};return response(result)});
 assert.equal(await h.Q.preview(),true);assert.match(h.$('promiseSelectionSourceMeta').textContent,/20\.11\.2030 15:45:27 МСК/);assert.equal(await h.Q.confirm(),true);
 assert.equal(h.writes()[0].preview_sha256,previewHash);
});

test('malformed, foreign and non-attachable previews cannot authorize a link',async()=>{
 for(const patch of [{viewer_id:8},{conversation_id:102},{preview_sha256:'invalid'},{can_attach:false,reason:'delivery_started'},{can_attach:false,reason:'duplicate_cards'}]){
  const h=await harness(),before=h.snapshot();await h.open();await h.choose();h.intercept(d=>d.action==='preview'?response({...h.service.preview(d),...patch}):null);
  await h.Q.preview();assert.equal(await h.Q.confirm(),false);assert.equal(h.writes().length,0);h.preserved(before);
 }
 const h=await harness();h.service.state.can_edit=false;await h.ready();assert.equal(await h.Q.confirm(),false);assert.equal(h.writes().length,0);
});

test('conflicting intent never exposes cancellation of a different promise behind the selected preview',async()=>{
 const h=await harness();await h.ready();assert.equal(await h.Q.confirm(),true);h.Q.close();await h.open();await h.choose({promise:1});assert.equal(await h.Q.preview(),true);
 assert.match(h.$('promiseSelectionStatus').textContent,/другая связь/);assert.equal(h.$('promiseSelectionCancel').hidden,true);assert.equal(h.Q.beginCancel(),false);assert.equal(h.writes().length,1);
});

test('late preview is fenced after close, actor change and A-to-B-to-A navigation without clearing drafts',async()=>{
 for(const change of ['close','actor','A-B-A']){
  const h=await harness(),wait=deferred();await h.open();await h.choose();h.intercept(d=>d.action==='preview'?wait.promise:null);
  const reading=h.Q.preview();await tick();const request=h.featureCalls().findLast(call=>call.action==='preview');
  if(change==='close')h.Q.close();
  if(change==='actor'){h.W.S.manager={...h.W.S.manager,id:8};h.W.S.authGeneration++;h.Q.sync();}
  if(change==='A-B-A'){await h.clearTaskDraftsForNavigation();assert.equal(await h.navigate(102),true);assert.equal(await h.navigate(101),true);h.Q.sync();}
  await h.draft('Новый ручной ответ');h.setFile({name:'current-file.pdf'});const focused=h.document.activeElement,reply=h.$('replyText').value,file=h.getFile();
  wait.resolve(response(h.service.preview(request)));assert.equal(await reading,false);assert.equal(h.$('promiseSelectionDialog').open,false);assert.equal(h.writes().length,0);
  assert.equal(h.$('replyText').value,reply);assert.equal(h.getFile(),file);assert.equal(h.document.activeElement,focused);
 }
});

test('UNKNOWN survives close and preview expiry, resolves by exact receipt and keeps completed history distinct from task status',async()=>{
 const h=await harness(),before=h.snapshot();await h.ready();h.intercept(d=>{if(d.action==='attach'){h.service.write(d);throw Error('synthetic response lost')}return null});
 assert.equal(await h.Q.confirm(),false);const original=clone(h.writes()[0]);assert.equal(await h.Q.confirm(),false);h.Q.close();h.advance(600000);h.intercept(null);await h.open();
 assert.equal(h.$('promiseSelectionCheck').hidden,false);assert.equal(h.featureCalls().filter(call=>call.action==='catalog').length,1);
 const intent=[...h.service.state.intents.values()][0];intent.state='completed';intent.completed_at='2026-11-13 10:00:00';intent.delivered_count=2;h.service.state.tasks[0].status='open';
 assert.equal(await h.Q.check(),true);assert.equal(h.writes().length,1);assert.deepEqual(h.writes()[0],original);assert.match(h.$('promiseSelectionIntent').textContent,/Автоматическое выполнение было применено/);assert.doesNotMatch(h.$('promiseSelectionIntent').textContent,/Задача выполнена|Обещание выполнено/);assert.equal(h.service.state.tasks[0].status,'open');h.preserved(before);
});

test('a missing receipt only enables explicit byte-equivalent retry of the original command after reopen',async()=>{
 const h=await harness();await h.ready();h.intercept(d=>{if(d.action==='attach')throw Error('synthetic request lost');return null});assert.equal(await h.Q.confirm(),false);const original=clone(h.writes()[0]);h.Q.close();
 const next=await harness({storage:h.storage,service:h.service});await next.open();assert.equal(next.writes().length,0);assert.equal(await next.Q.retry(),false);assert.equal(await next.Q.check(),false);assert.equal(next.writes().length,0);
 assert.equal(await next.Q.retry(),true);assert.deepEqual(next.writes()[0],original);assert.equal(next.pending().length,0);
});

test('a receipt with the wrong action, request hash or intent stays UNKNOWN without another write',async()=>{
 for(const alter of [r=>{r.action='cancel'},r=>{r.request_sha256='0'.repeat(64)},r=>{r.intent.publication_id='9'.repeat(32)}]){
  const h=await harness();await h.ready();h.intercept(d=>{if(d.action==='attach'){h.service.write(d);throw Error('synthetic response lost')}return null});await h.Q.confirm();
  h.intercept(d=>{if(d.action==='operation'){const r=h.service.operation(d);alter(r);return response(r)}return null});assert.equal(await h.Q.check(),false);assert.equal(await h.Q.retry(),false);assert.equal(h.writes().length,1);assert.equal(h.pending().length,1);
 }
});

test('a late operation lookup cannot paint or forget the receipt after close, actor change or conversation change',async()=>{
 for(const change of ['close','actor','conversation']){
  const h=await harness();await h.ready();h.intercept(d=>{if(d.action==='attach'){h.service.write(d);throw Error('synthetic response lost')}return null});await h.Q.confirm();
  const saved=h.pending()[0],wait=deferred(),manager=h.W.S.manager;h.intercept(d=>d.action==='operation'?wait.promise:null);const lookup=h.Q.check();await tick();
  if(change==='close'){h.Q.close();await h.open();}
  if(change==='actor'){h.W.S.manager={...manager,id:8};h.W.S.authGeneration++;h.Q.sync();}
  if(change==='conversation'){await h.clearTaskDraftsForNavigation();assert.equal(await h.navigate(102),true);h.Q.sync();}
  await h.draft('Текущий ручной ответ');h.setFile({name:'current-manual.pdf'});const reply=h.$('replyText').value,file=h.getFile(),focus=h.document.activeElement,status=h.$('promiseSelectionStatus').textContent;
  wait.resolve(response(h.service.operation({operation_id:saved.record.operation_id})));assert.equal(await lookup,false);
  assert.equal(h.storage.getItem(saved.key),saved.value);assert.equal(h.$('promiseSelectionStatus').textContent,status);assert.equal(h.$('promiseSelectionIntent').hidden,true);
  assert.equal(h.$('replyText').value,reply);assert.equal(h.getFile(),file);assert.equal(h.document.activeElement,focus);assert.equal(h.writes().length,1);
  h.Q.close();if(change==='actor'){h.W.S.manager=manager;h.W.S.authGeneration++;}if(change==='conversation')assert.equal(await h.navigate(101),true);
  h.intercept(null);await h.open();assert.equal(await h.Q.check(),true);assert.equal(h.pending().length,0);assert.equal(h.writes().length,1);
 }
});

test('a server-stopped intent explains new client context without claiming that the trip conditions changed',async()=>{
 const h=await harness(),before=h.snapshot();await h.ready();await h.Q.confirm();const intent=[...h.service.state.intents.values()][0];intent.state='blocked';intent.reason='context_changed';
 assert.equal(await h.Q.readStatus(),true);assert.match(h.$('promiseSelectionIntent').textContent,/появились новые сообщения или запрос клиента/);assert.doesNotMatch(h.$('promiseSelectionIntent').textContent,/Условия поездки изменились|context_changed/);
 assert.equal(h.Q.beginCancel(),false);assert.equal(h.writes().length,1);h.preserved(before);
});

test('cancellation has a separate confirmation and cannot undo a completion that won the race',async()=>{
 for(const completed of [false,true]){
  const h=await harness(),before=h.snapshot();await h.ready();assert.equal(await h.Q.confirm(),true);assert.equal(h.Q.beginCancel(),true);assert.equal(h.writes().length,1);
  if(completed){const intent=[...h.service.state.intents.values()][0];intent.state='completed';intent.completed_at='2026-11-13 10:00:00';intent.delivered_count=2;}
  assert.equal(await h.Q.confirmCancel(),true);assert.equal(await h.Q.confirmCancel(),false);assert.equal(h.writes().length,2);
  assert.deepEqual(Object.keys(h.writes()[1]).sort(),['url','action','csrf','conversation_id','operation_id','intent_id'].sort());assert.match(h.$('promiseSelectionIntent').textContent,completed?/было применено/:/отменено/);
  assert.equal(h.service.state.tasks[0].status,'open');h.preserved(before);
 }
});

test('UNKNOWN cancellation uses its own receipt and never claims an armed response confirmed cancellation',async()=>{
 const h=await harness();await h.ready();await h.Q.confirm();h.Q.beginCancel();h.intercept(d=>{if(d.action==='cancel'){const result=h.service.write(d);result.intent.state='armed';return response(result)}return null});
 assert.equal(await h.Q.confirmCancel(),false);assert.equal(h.pending().length,1);assert.equal(h.pending()[0].record.action,'cancel');h.Q.close();h.intercept(null);await h.open();assert.equal(await h.Q.check(),true);assert.equal(h.writes().length,2);assert.match(h.$('promiseSelectionIntent').textContent,/отменено/);
});

test('storage failure prevents dispatch and a different actor never replays the previous actor pending command',async()=>{
 const h=await harness();await h.ready();const set=h.storage.setItem;h.storage.setItem=(key,value)=>{if(key.startsWith(pendingPrefix))throw Error('synthetic quota');return set(key,value)};assert.equal(await h.Q.confirm(),false);assert.equal(h.writes().length,0);
 const other=await harness();await other.ready();other.intercept(d=>{if(d.action==='attach')throw Error('synthetic lost');return null});await other.Q.confirm();const pending=other.pending()[0];other.Q.close();
 other.W.S.manager={...other.W.S.manager,id:8};other.W.S.authGeneration++;await other.Q.open(other.opener);assert.equal(await other.Q.check(),false);assert.equal(other.featureCalls().some(call=>call.action==='operation'),false);assert.equal(other.storage.getItem(pending.key),pending.value);
});

test('native Back closes only this dialog and a delayed close event cannot close its new opening',async()=>{
 const h=await harness(),before=h.snapshot(),entries=h.history.entries.length;await h.open();assert.equal(h.history.entries.length,entries+1);await h.history.back();assert.equal(h.$('promiseSelectionDialog').open,false);assert.equal(h.document.activeElement,h.opener);h.preserved(before);
 await h.open();h.Q.close();await h.open();await h.$('promiseSelectionDialog').dispatch('close');assert.equal(h.$('promiseSelectionDialog').open,true);h.Q.close();h.preserved(before);
});

test('the actual Manager2 launcher lazily loads only the new owner once and delegates without editor access',async()=>{
 const h=await harness({lazy:true}),before=h.snapshot(),ui=attachPresentation(h);assert.ok(ui.button);assert.equal(ui.assets().length,0);assert.equal(h.featureCalls().length,0);
 const opening=ui.button.click();await tick();assert.equal(ui.assets().length,2);assert.ok(ui.assets().some(element=>String(element.src).includes('/manager/assets/workspace-v2-promise-selection.js')));assert.ok(ui.assets().some(element=>String(element.href).includes('/manager/assets/workspace-v2-promise-selection.css')));
 h.loadFeature();for(const asset of ui.assets())asset.onload();await opening;assert.equal(h.$('promiseSelectionDialog').open,true);assert.deepEqual(h.featureCalls().map(call=>call.action),['catalog']);h.Q.close();h.preserved(before);
 await ui.button.click();assert.equal(ui.assets().length,2);assert.equal(h.featureCalls().filter(call=>call.action==='catalog').length,2);assert.equal(h.calls.some(call=>call.url==='tour-selections-api.php'),false);h.Q.close();h.preserved(before);
});

test('a late lazy load cannot open the dialog for a switched actor or for mobile Back to inbox',async()=>{
 for(const change of ['actor','inbox']){
  const h=await harness({lazy:true}),ui=attachPresentation(h),opening=ui.button.click();await tick();
  if(change==='actor'){h.W.S.manager={...h.W.S.manager,id:8};h.W.S.authGeneration++;}else h.window.WorkspaceV2Mobile.showInbox();
  h.loadFeature();for(const asset of ui.assets())asset.onload();await opening;assert.equal(h.$('promiseSelectionDialog')?.open===true,false);assert.equal(h.featureCalls().length,0);assert.equal(h.getFile().name,'private-synthetic.pdf');
 }
});

module.exports={fixtureService};
if(require.main===module)(async()=>{const match=process.argv.find(arg=>arg.startsWith('--match='))?.slice(8),selected=cases.filter(item=>!match||new RegExp(match).test(item.name));assert.ok(selected.length,'at least one requested case');let failed=0;for(const item of selected){let timer;try{await Promise.race([item.run(),new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('Scenario did not settle')),10000)})]);console.log('PASS '+item.name)}catch(error){failed++;console.error('FAIL '+item.name+'\n'+error.stack)}finally{clearTimeout(timer)}}console.log(`TOTAL ${selected.length} | PASS ${selected.length-failed} | FAIL ${failed}`);process.exitCode=failed?1:0})();
