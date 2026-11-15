'use strict';
const assert=require('node:assert/strict'),{File}=require('node:buffer');
const {setup,tick,response,deferred}=require('./run_manager_promise_task_behavior_regression');
const {fixtureService,clone}=require('./support/manager-promise-calculation-ui-fixture');
const endpoint='promise-calculations-api.php',prefix='workspaceV2.promiseCalculation.v1.';
const cases=[],test=(name,run)=>cases.push({name,run});
const file=(name='calculation.txt',text='Synthetic calculation bytes')=>new File([text],name,{type:'text/plain',lastModified:123});
async function harness({service=fixtureService(),storage}={}){
 const h=await setup({storage}),$=h.$;let intercept=null;const uploads=[];
 class FormData{constructor(){this.values=new Map();uploads.push(this)}append(name,value){this.values.set(name,value)}toString(){const value=Object.fromEntries(this.values),f=value.file;return JSON.stringify({...value,file:{name:f.name,size:f.size,type:f.type},action:'fixture_upload'})}}
 const media=h.document.createElement('div');media.id='mediaSelection';$('composer').appendChild(media);
 Object.defineProperty(media,'innerHTML',{get(){return this._html||''},set(value){this._html=String(value);this.replaceChildren();for(const match of this._html.matchAll(/data-index="(\d+)"/g)){const item=h.document.createElement('div');item.className='mediaSelectedItem';for(const className of ['mediaPreview','mediaFileName','mediaFileSize','mediaRemove']){const child=h.document.createElement(className==='mediaRemove'?'button':'span');child.className=className;if(className==='mediaRemove')child.dataset.index=match[1];item.appendChild(child)}this.appendChild(item)}const hint=h.document.createElement('div');hint.className='mediaSelectionHint';this.appendChild(hint)}});
 h.loadAsset('workspace-v2-media-recovery.js');h.loadAsset('workspace-v2-media.js',{FormData,URL});
 h.window.WorkspaceV2Media.configure('synthetic-csrf',101);h.window.WorkspaceV2Media.init();h.window.WorkspaceV2Media.restoreDraft(101);
 const load=()=>{h.loadAsset('workspace-v2-promise-calculation.js',{TextEncoder,AbortController,clearTimeout});h.Q=h.window.WorkspaceV2PromiseCalculation};load();
 h.setIntercept(async(url,d)=>{
  if(intercept){const custom=await intercept(url,d);if(custom!==undefined&&custom!==null)return custom}
  if(url===endpoint){const result=service.request(d);return response(result,result.ok===true?200:409)}
  if(url==='media-upload.php'){const body=uploads.findLast(upload=>upload.values.get('operation_id')===d.operation_id),bytes=Buffer.from(await body.values.get('file').arrayBuffer());return response(service.native(d,bytes))}
  if(url==='api.php'&&d.action==='send')return response(service.native(d));
  if(url==='api.php'&&['send_receipt','media_receipt'].includes(d.action))return response(service.receipt(d));
  return undefined;
 });
 await h.draft('');
 return Object.assign(h,{service,uploads,loadFeature:load,intercept:fn=>intercept=fn,featureCalls:()=>h.calls.filter(d=>d.url===endpoint),nativeCalls:()=>h.calls.filter(d=>d.action==='send'||d.url==='media-upload.php'),
  entry:()=>service.catalog().entries[0],select:()=>h.Q.select(service.catalog().entries[0]),record:()=>JSON.parse(h.storage.getItem(prefix+'7.101')||'null'),
  async attach(files=[file()]){h.window.WorkspaceV2Media.addFiles(files);await tick()},async send(){await h.C.sendReply();await tick();await tick()},
  async ready(text='Стоимость поездки: 240 000 ₽'){await h.select();await h.draft(text);await h.send();assert.equal(h.nativeCalls().length,0);assert.equal(h.featureCalls().filter(d=>d.action==='preview').length,1);assert.match($('promiseCalculationStatus').textContent,/Состав проверен/)}
 });
}
test('empty selection and first Send only review; second explicit Send binds actual File and preserves manual task fields',async()=>{
 const h=await harness(),before=h.snapshot();assert.equal(await h.select(),true);assert.equal(h.featureCalls().length,0);assert.equal(h.nativeCalls().length,0);
 const original=file();await h.attach([original]);await h.draft('Точная подпись расчёта');const snapshot=h.window.WorkspaceV2Media.captureSelection();await h.send();
 assert.equal(h.window.WorkspaceV2Media.matchesSelection(snapshot),true);assert.equal(h.nativeCalls().length,0);assert.match(h.$('promiseCalculation').textContent,/Точная подпись/);assert.match(h.$('promiseCalculation').textContent,/calculation.txt/);
 await h.send();assert.equal(h.nativeCalls().length,1);assert.equal(h.uploads[0].values.get('file'),original);assert.equal(h.uploads[0].values.get('calculation_part_index'),'0');assert.match(h.uploads[0].values.get('calculation_intent_id'),/^[a-f0-9]{32}$/);assert.equal(h.service.state.completionCount,1);
 for(const name of ['title','due','editTitle','editDue','draft'])assert.equal(h.snapshot()[name],before[name]);assert.equal(h.$('promiseTestEditTitle').isConnected,true);assert.equal(h.service.state.task.status,'done');assert.equal(h.$('promiseCalculationStatus').textContent,'Расчёт отправлен. Задача выполнена.');
});
test('changed text and changed same-name File each require a fresh read and another explicit Send',async()=>{
 const h=await harness();await h.attach([file('same.txt','first')]);await h.ready();await h.draft('Изменённая подпись');await h.send();assert.equal(h.nativeCalls().length,0);assert.equal(h.featureCalls().filter(d=>d.action==='preview').length,2);
 h.window.WorkspaceV2Media.clear();const replacement=file('same.txt','second');await h.attach([replacement]);await h.send();assert.equal(h.nativeCalls().length,0);assert.equal(h.featureCalls().filter(d=>d.action==='preview').length,3);await h.send();assert.equal(h.uploads[0].values.get('file'),replacement);assert.equal(h.service.state.completionCount,1);
});
test('canonical filename and Unicode line separators survive exact operation readback without changing the native File',async()=>{
 const h=await harness(),original=file('  Расчёт_\u0007.txt  ','Unicode proof bytes');await h.attach([original]);await h.ready('Первая\u2028вторая\u2029третья строка');assert.equal(h.featureCalls().find(d=>d.action==='preview').composition.files[0].name,'Расчёт_.txt');
 h.intercept((url,d)=>{if(url===endpoint&&d.action==='arm'){h.service.write(d);throw Error('lost')}});await h.send();h.intercept(null);assert.equal(await h.Q.checkOperation(),true);await h.send();assert.equal(h.uploads[0].values.get('file'),original);assert.equal(h.uploads[0].values.get('file').name,'  Расчёт_\u0007.txt  ');assert.equal(h.uploads[0].values.get('caption'),'Первая\u2028вторая\u2029третья строка');assert.equal(h.service.state.completionCount,1);
});
test('preview mismatch and validation refusal never fall through to ordinary sends',async()=>{
 for(const mode of ['projection','refusal']){const h=await harness();await h.select();await h.draft('Точный текст');h.intercept((url,d)=>{if(url!==endpoint||d.action!=='preview')return;return mode==='projection'?response({...h.service.preview(d),composition:{text:'Другой текст',files:[]}}):response({ok:false,error:'source_changed'},409)});await h.send();await h.send();assert.equal(h.nativeCalls().length,0);assert.equal(h.record().task_id,77);assert.equal(h.featureCalls().some(d=>d.action==='arm'),false)}
});
test('lost arm response persists its exact operation; reload/readback never arms or dispatches again',async()=>{
 const h=await harness();await h.attach();await h.ready();h.intercept((url,d)=>{if(url===endpoint&&d.action==='arm'){h.service.write(d);throw Error('lost response')}});await h.send();assert.equal(h.nativeCalls().length,0);const op=h.record().pending.operation_id;
 h.loadFeature();h.intercept(null);await h.send();assert.equal(h.featureCalls().filter(d=>d.action==='arm').length,1);assert.equal(h.featureCalls().findLast(d=>d.action==='operation').operation_id,op);assert.equal(h.nativeCalls().length,0);await h.send();assert.equal(h.nativeCalls().length,1);assert.equal(h.service.state.completionCount,1);
});
test('not-found arm readback remains blocked and preserves the same unresolved operation',async()=>{
 const h=await harness();await h.ready();h.intercept((url,d)=>{if(url===endpoint&&d.action==='arm')throw Error('unknown')});await h.send();const op=h.record().pending.operation_id;h.intercept(null);await h.send();await h.send();assert.equal(h.record().pending.operation_id,op);assert.equal(h.featureCalls().filter(d=>d.action==='arm').length,1);assert.equal(h.nativeCalls().length,0);
});
test('explicit retry after absent read saves the identical frozen command once despite a delayed original and changed draft',async()=>{
 const h=await harness();await h.attach([file('original.txt','original')]);await h.ready('Исходная подпись');let original;
 h.intercept((url,d)=>{if(url===endpoint&&d.action==='arm'){original=clone(d);throw Error('request outcome unknown')}});await h.send();h.intercept(null);await h.Q.checkOperation();assert.ok(h.$('promiseCalculationRetry'));const pending=h.record().pending;
 await h.draft('Новый ручной текст');h.window.WorkspaceV2Media.clear();const replacement=file('replacement.txt','replacement');await h.attach([replacement]);assert.equal(await h.Q.retryOperation(),true);
 const retry=h.featureCalls().filter(d=>d.action==='arm').at(-1);assert.equal(retry.operation_id,pending.operation_id);assert.deepEqual(retry.composition,pending.input.composition);assert.equal(retry.preview_sha256,pending.input.preview_sha256);assert.equal(h.nativeCalls().length,0);h.service.write(original);assert.equal(h.service.state.intents.size,1);
 await h.send();assert.equal(h.nativeCalls().length,0);assert.equal(h.$('replyText').value,'Новый ручной текст');assert.equal(h.window.WorkspaceV2Media.captureSelection().rows[0].row.file,replacement);assert.equal(await h.Q.detach(true),true);await h.send();assert.equal(h.nativeCalls().length,0);await h.send();assert.equal(h.nativeCalls().length,1);
});
test('a write deadline clears busy but retains UNKNOWN and the exact operation for explicit recovery',async()=>{
 const h=await harness();await h.ready();const timers=[],controllers=[];
 class Controller extends AbortController{constructor(){super();controllers.push(this)}}
 h.loadAsset('workspace-v2-promise-calculation.js',{TextEncoder,AbortController:Controller,setTimeout:(run,delay)=>{timers.push({run,delay});return timers.length},clearTimeout(){}});h.Q=h.window.WorkspaceV2PromiseCalculation;
 // Reload deliberately revokes the old ephemeral preview, while preserving task.
 await h.send();const started=deferred();h.intercept((url,d)=>{if(url===endpoint&&d.action==='arm'){started.resolve();return new Promise((resolve,reject)=>controllers.at(-1).signal.addEventListener('abort',()=>reject(Error('AbortError'))))}});
 const sending=h.send();await started.promise;assert.equal(timers.at(-1).delay,20000);timers.at(-1).run();await sending;assert.ok(h.record().pending);assert.equal(h.$('promiseCalculationCheck').disabled,false);assert.equal(h.nativeCalls().length,0);
 h.intercept(null);await h.Q.checkOperation();assert.equal(h.$('promiseCalculationRetry').disabled,false);assert.equal(h.featureCalls().filter(d=>d.action==='arm').length,1);
});
test('definitive arm refusal retains the task and requires a fresh preview before another arm',async()=>{
 const h=await harness();await h.ready();h.service.state.reject='task_changed';await h.send();assert.equal(h.record().pending,null);assert.equal(h.record().task_id,77);assert.equal(h.nativeCalls().length,0);h.service.state.reject=null;await h.send();assert.equal(h.nativeCalls().length,0);assert.equal(h.featureCalls().filter(d=>d.action==='preview').length,2);await h.send();assert.equal(h.nativeCalls().length,1);
});
test('mismatched arm projection remains UNKNOWN without creating a native send',async()=>{
 const h=await harness();await h.ready();h.intercept((url,d)=>{if(url===endpoint&&d.action==='arm'){const result=h.service.write(d);result.intent.parts[0].text_sha256='f'.repeat(64);return response(result)}});await h.send();assert.ok(h.record().pending);assert.equal(h.nativeCalls().length,0);assert.match(h.$('promiseCalculationStatus').textContent,/не подтверждена/);
});
test('generic ok keeps a bound text attempt fenced and persists its calculation identity for exact readback',async()=>{
 const h=await harness();await h.ready();h.intercept((url,d)=>{if(url==='api.php'&&d.action==='send'){h.service.native(d);return response({ok:true})}});await h.send();const sent=h.nativeCalls()[0],saved=JSON.parse(h.storage.getItem('workspaceV2.replySession.v1')).drafts.find(d=>d.id===101).attempt;assert.equal(saved.calculationIntentId,sent.calculation_intent_id);assert.equal(saved.calculationPartIndex,0);assert.equal(h.$('replyText').value,'Стоимость поездки: 240 000 ₽');assert.equal(h.$('sendReply').dataset.sendPending,'true');
 h.loadAsset('workspace-v2-conversation.js');h.C=h.window.WorkspaceV2Conversation;h.C.activateReplySession(7);h.C.restoreDraft(101);h.intercept(null);await h.send();assert.equal(h.nativeCalls().length,1);assert.equal(h.calls.findLast(d=>d.action==='send_receipt').operation_id,sent.operation_id);assert.equal(h.$('sendReply').dataset.sendPending,'false');
});
test('partial media UNKNOWN retains original part indices, caption and exact receipt without a duplicate dispatch',async()=>{
 const h=await harness();await h.attach([file('one.txt','one'),file('two.txt','two')]);await h.ready('Одна подпись');h.intercept(async(url,d)=>{if(url==='media-upload.php'&&d.calculation_part_index==='1'){h.service.state.nativeState='unknown';const upload=h.uploads.findLast(u=>u.values.get('operation_id')===d.operation_id);return response(h.service.native(d,Buffer.from(await upload.values.get('file').arrayBuffer())))}});await h.send();assert.equal(h.nativeCalls().length,2);assert.equal(h.uploads[0].values.get('caption'),'Одна подпись');assert.equal(h.uploads[1].values.get('caption'),'');assert.equal(h.service.state.completionCount,0);assert.equal(h.$('replyText').value,'');const row=h.window.WorkspaceV2Media.captureSelection().rows[0].row;assert.equal(row.calculationPartIndex,1);assert.equal(row.status,'unknown');
 await h.send();assert.equal(h.nativeCalls().length,2);assert.equal(await h.Q.detach(),false);h.service.confirm(row.recoveryId);h.intercept(null);await h.window.WorkspaceV2MediaRecovery.check(row.recoveryId);await tick();await tick();assert.equal(h.window.WorkspaceV2Media.hasFile(),false);assert.equal(h.service.state.completionCount,1);assert.equal(h.nativeCalls().length,2);
});
test('a rejected remaining part resumes only its original index and has no repeated caption or arm',async()=>{
 const h=await harness();await h.attach([file('one.txt','one'),file('two.txt','two')]);await h.ready('Одна подпись');h.intercept(async(url,d)=>{if(url==='media-upload.php'&&d.calculation_part_index==='1'){h.service.state.nativeState='rejected';const upload=h.uploads.findLast(u=>u.values.get('operation_id')===d.operation_id);return response(h.service.native(d,Buffer.from(await upload.values.get('file').arrayBuffer())))}});await h.send();h.intercept(null);h.service.state.nativeState='confirmed';await h.Q.checkIntent();await h.send();assert.equal(h.nativeCalls().length,3);assert.equal(h.uploads[2].values.get('calculation_part_index'),'1');assert.equal(h.uploads[2].values.get('caption'),'');assert.equal(h.featureCalls().filter(d=>d.action==='arm').length,1);assert.equal(h.service.state.completionCount,1);
});
test('extra File added during native dispatch remains separate while only the frozen complete manifest closes the task',async()=>{
 const h=await harness();await h.attach([file('bound.txt')]);await h.ready('Точная подпись');const gate=deferred(),started=deferred();h.intercept(async(url,d)=>{if(url==='media-upload.php'){const upload=h.uploads.findLast(u=>u.values.get('operation_id')===d.operation_id);const result=h.service.native(d,Buffer.from(await upload.values.get('file').arrayBuffer()));started.resolve();await gate.promise;return response(result)}});const sending=h.send();await started.promise;const extra=file('new-unbound.txt','new work');await h.attach([extra]);gate.resolve();await sending;
 assert.equal(h.nativeCalls().length,1);assert.equal(h.service.state.completionCount,1);const row=h.window.WorkspaceV2Media.captureSelection().rows[0].row;assert.equal(row.file,extra);assert.equal(row.calculationIntentId,undefined);await h.send();assert.equal(h.nativeCalls().length,1);assert.equal(h.window.WorkspaceV2Media.captureSelection().rows[0].row.file,extra);
});
test('new work while arm is pending is retained and cannot be sent as the old calculation',async()=>{
 const h=await harness();await h.attach();await h.ready();const gate=deferred(),started=deferred();h.intercept((url,d)=>{if(url===endpoint&&d.action==='arm'){started.resolve(d);return gate.promise}});const sending=h.send(),d=await started.promise;await h.draft('Новый ручной ответ');const added=file('new.txt');await h.attach([added]);gate.resolve(response(h.service.write(d)));await sending;assert.equal(h.nativeCalls().length,0);assert.equal(h.$('replyText').value,'Новый ручной ответ');assert.equal(h.window.WorkspaceV2Media.captureSelection().rows[1].row.file,added);assert.ok(h.record().intent);h.intercept(null);await h.send();assert.equal(h.nativeCalls().length,0);
});
test('late arm after an account or conversation change cannot dispatch into the new context',async()=>{
 for(const mode of ['account','conversation']){const h=await harness();await h.ready();const gate=deferred(),started=deferred();h.intercept((url,d)=>{if(url===endpoint&&d.action==='arm'){started.resolve(d);return gate.promise}});const sending=h.send(),d=await started.promise;if(mode==='account'){h.W.S.manager={...h.W.S.manager,id:8};h.W.S.authGeneration++}else h.W.S.current=102;h.$('replyText').value='Другой текущий черновик';gate.resolve(response(h.service.write(d)));await sending;assert.equal(h.nativeCalls().length,0);assert.equal(h.$('replyText').value,'Другой текущий черновик')}
});
test('historical completion never sends or closes again; explicit new calculation on reopened task requires new review',async()=>{
 const h=await harness();await h.ready();await h.send();assert.equal(h.service.state.completionCount,1);h.service.reopen();await h.draft('Новый расчёт');await h.send();assert.equal(h.nativeCalls().length,1);await h.Q.checkIntent();assert.equal(h.$('promiseCalculationStatus').textContent,'Предыдущий расчёт отправлен. Задача сейчас открыта.');assert.equal(h.service.state.task.status,'open');assert.equal(h.service.state.completionCount,1);
 assert.equal(await h.Q.select(h.entry(),{newCalculation:true}),true);await h.send();assert.equal(h.nativeCalls().length,1);assert.match(h.$('promiseCalculation').textContent,/Предыдущий расчёт подтверждён/);await h.send();assert.equal(h.nativeCalls().length,2);assert.equal(h.service.state.completionCount,2);assert.equal(h.service.state.intents.size,2);
});
test('explicit cancellation keeps text and actual File then demands a new preview for the changed composition',async()=>{
 const h=await harness();await h.attach();await h.ready();h.intercept((url,d)=>{if(url===endpoint&&d.action==='arm'){h.service.write(d);throw Error('lost')}});await h.send();h.intercept(null);await h.Q.checkOperation();const snapshot=h.window.WorkspaceV2Media.captureSelection(),draft=h.$('replyText').value;assert.equal(await h.Q.detach(true),true);assert.equal(h.$('replyText').value,draft);assert.equal(h.window.WorkspaceV2Media.captureSelection().rows[0].row.file,snapshot.rows[0].row.file);await h.send();assert.equal(h.nativeCalls().length,0);await h.send();assert.equal(h.nativeCalls().length,1);
});
test('terminal ordinary send with an unverified calculation can disarm automation without closing the task or resending',async()=>{
 for(const media of [false,true]){const h=await harness();if(media)await h.attach();await h.ready();h.service.state.proofMissing=true;await h.send();assert.equal(h.service.state.completionCount,0);assert.equal(h.service.state.task.status,'open');assert.equal(h.record().intent.parts[0].state,'unknown');assert.equal(await h.Q.detach(),true);assert.equal(h.record(),null);assert.equal(h.nativeCalls().length,1);assert.equal(h.service.state.task.status,'open')}
});
test('other projects and website keep the ordinary composer without calculation catalog reads',async()=>{
 for(const change of [{project_key:'other'},{channel:'website'}]){const h=await harness();Object.assign(h.W.S.detail.conversation,change);await h.Q.renderTasks(h.taskRoot,{tasks:[h.service.state.task],canEdit:true,conversationId:101});assert.equal(await h.select(),false);await h.draft('Обычное сообщение');await h.send();assert.equal(h.featureCalls().length,0);assert.equal(h.nativeCalls().length,1);assert.equal(h.nativeCalls()[0].calculation_intent_id,undefined);assert.equal(h.service.state.completionCount,0)}
});
test('auth loss removes private review DOM; a late old read cannot expose it to another account',async()=>{
 const h=await harness();await h.ready();assert.match(h.$('promiseCalculation').textContent,/Пришлю расчёт стоимости/);h.W.S.authExpired=true;h.W.S.authGeneration++;h.C.syncComposer();assert.equal(h.$('promiseCalculation').textContent,'');assert.equal(h.$('promiseCalculation').hidden,true);
 h.W.S.authExpired=false;h.W.S.authGeneration++;h.C.syncComposer();assert.doesNotMatch(h.$('promiseCalculation').textContent,/Пришлю расчёт стоимости|20 ноября/);await h.send();assert.equal(h.nativeCalls().length,0);assert.match(h.$('promiseCalculation').textContent,/Пришлю расчёт стоимости/);
 await h.draft('Новый состав');const gate=deferred(),started=deferred();h.intercept((url,d)=>{if(url===endpoint&&d.action==='preview'){started.resolve(d);return gate.promise}});const reading=h.send(),d=await started.promise;h.W.S.manager={...h.W.S.manager,id:8};h.W.S.authGeneration++;h.C.syncComposer();gate.resolve(response(h.service.preview(d)));await reading;assert.equal(h.$('promiseCalculation').textContent,'');assert.equal(h.nativeCalls().length,0);
});
test('linked File rows cannot be dispatched directly without the checked composer gate',async()=>{
 const h=await harness();await h.attach();assert.equal(await h.window.WorkspaceV2Media.bindCalculation(h.window.WorkspaceV2Media.captureSelection(),'9'.repeat(32)),true);assert.equal((await h.window.WorkspaceV2Media.send('unreviewed')).error,'calculation_changed');assert.equal(h.nativeCalls().length,0);
});
test('storage refusal prevents arm and native send while preserving current manual work',async()=>{
 const h=await harness();await h.ready();const set=h.storage.setItem;h.storage.setItem=(key,value)=>{if(key.startsWith(prefix))throw Error('quota');set(key,value)};await h.send();assert.equal(h.featureCalls().some(d=>d.action==='arm'),false);assert.equal(h.nativeCalls().length,0);assert.equal(h.$('replyText').value,'Стоимость поездки: 240 000 ₽');
});
module.exports={harness};
if(require.main===module)(async()=>{let failed=0;for(const {name,run}of cases){try{await run();console.log('PASS '+name)}catch(e){failed++;console.error('FAIL '+name+'\n'+e.stack)}}console.log('TOTAL '+cases.length+' | PASS '+(cases.length-failed)+' | FAIL '+failed);process.exitCode=failed?1:0})();
