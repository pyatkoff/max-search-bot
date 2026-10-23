'use strict';
const assert=require('node:assert/strict');
const {createHarness,response,deferred}=require('./run_manager_reauth_composer_behavior_regression');
function storage(){const values=new Map();return{values,deny:false,getItem:k=>values.get(k)||null,setItem(k,v){if(this.deny)throw Error('synthetic quota');values.set(k,String(v));},removeItem:k=>values.delete(k)}}
const cases=[];function test(name,run){cases.push({name,run})}
async function unknown(options={}){const h=createHarness(options);await h.start();await h.draft('Synthetic original');h.setHook((url,r)=>{if(r.action==='send')throw Error('synthetic lost acknowledgement');return null});await h.C.sendReply();assert.match(h.ids.get('sendReply').textContent,/Проверить/);return h}

test('lost acknowledgement reconciles without a repeat send',async()=>{
 const h=await unknown();const id=h.calls.find(r=>r.action==='send').operation_id;assert.match(id,/^[a-f0-9]{32}$/);
 h.setHook((url,r)=>{if(r.action==='send_receipt'){assert.equal(r.operation_id,id);return response({ok:true,attempt:{state:'confirmed'}})}return null});
 await h.C.sendReply();assert.equal(h.calls.filter(r=>r.action==='send').length,1);assert.equal(h.ids.get('replyText').value,'');assert.equal(h.ids.get('sendReply').textContent,'Отправить');
});
test('readback preserves newer text and selected file and never sends media',async()=>{
 const h=await unknown();await h.draft('Newer essential text');const file={name:'synthetic.png'};h.setFile(file);h.window.WorkspaceV2Media.send=()=>{throw Error('Media must not run')};
 h.setHook((u,r)=>r.action==='send_receipt'?response({ok:true,attempt:{state:'confirmed'}}):null);await h.C.sendReply();
 assert.equal(h.ids.get('replyText').value,'Newer essential text');assert.equal(h.getFile(),file);assert.equal(h.calls.filter(r=>r.action==='send').length,1);
});
test('same-account reload retains the original pending intent even with an empty edited draft',async()=>{
 const saved=storage(),h=await unknown({storage:saved});await h.draft('');const id=h.calls.find(r=>r.action==='send').operation_id;
 const next=createHarness({storage:saved});await next.start({open:false});assert.match(next.ids.get('sendReply').textContent,/Проверить/);
 next.setHook((u,r)=>r.action==='send_receipt'?(assert.equal(r.operation_id,id),response({ok:true,attempt:{state:'confirmed'}})):null);await next.C.sendReply();assert.equal(next.calls.some(r=>r.action==='send'),false);assert.equal(next.ids.get('replyText').value,'');
});
test('unknown processing invalid and failed lookup never permits resend',async()=>{
 for(const result of [{ok:true,attempt:{state:'processing'}},{ok:true,attempt:{state:'unknown'}},{ok:false,error:'invalid_response'},{ok:false,http_status:503}]){
  const h=await unknown();h.setHook((u,r)=>r.action==='send_receipt'?response(result):null);await h.C.sendReply();await h.C.sendReply();assert.equal(h.calls.filter(r=>r.action==='send').length,1);assert.equal(h.ids.get('replyText').value,'Synthetic original');assert.match(h.ids.get('sendReply').textContent,/Проверить/);
 }
});
test('missing receipt requires explicit cancellation and a new explicit send',async()=>{
 const h=await unknown();let state='not_found';h.setHook((u,r)=>r.action==='send_receipt'?response({ok:true,attempt:{state}}):r.action==='cancel_send_attempt'?response({ok:false,attempt:{state:'cancelled'}}):r.action==='send'?response({ok:true,attempt:{state:'confirmed'}}):null);
 await h.C.sendReply();assert.equal(h.calls.filter(r=>r.action==='send').length,1);const cancel=h.ids.get('replyStatus').querySelector('button');assert.ok(cancel);await cancel.onclick();assert.equal(h.calls.filter(r=>r.action==='send').length,1);assert.equal(h.ids.get('replyText').value,'Synthetic original');assert.equal(h.ids.get('sendReply').textContent,'Отправить');
 await h.C.sendReply();const sends=h.calls.filter(r=>r.action==='send');assert.equal(sends.length,2);assert.notEqual(sends[0].operation_id,sends[1].operation_id);
});
test('cancellation racing accepted original cannot unlock another send',async()=>{
 const h=await unknown();h.setHook((u,r)=>r.action==='send_receipt'?response({ok:true,attempt:{state:'not_found'}}):r.action==='cancel_send_attempt'?response({ok:false,attempt:{state:'processing'}}):null);
 await h.C.sendReply();await h.ids.get('replyStatus').querySelector('button').onclick();assert.match(h.ids.get('sendReply').textContent,/Проверить/);assert.equal(h.calls.filter(r=>r.action==='send').length,1);
});
test('late receipt cannot clear another conversation draft or expire its session',async()=>{
 const h=await unknown(),gate=deferred(),entered=deferred();h.setHook((u,r)=>r.action==='send_receipt'?(entered.resolve(),gate.promise):null);const checking=h.C.sendReply();await entered.promise;await h.C.open(102);await h.draft('Other conversation');gate.resolve(response({ok:false},401));await checking;assert.equal(h.W.S.authExpired,false);assert.equal(h.ids.get('replyText').value,'Other conversation');
});
test('late confirmation retains newer local input during pending readback',async()=>{
 const h=await unknown(),gate=deferred(),entered=deferred();h.setHook((u,r)=>r.action==='send_receipt'?(entered.resolve(),gate.promise):null);const checking=h.C.sendReply();await entered.promise;await h.draft('Programmatically recovered newer text');gate.resolve(response({ok:true,attempt:{state:'confirmed'}}));await checking;assert.equal(h.ids.get('replyText').value,'Programmatically recovered newer text');
});
test('another account cannot inherit receipt or draft',async()=>{
 const saved=storage();await unknown({storage:saved});const next=createHarness({storage:saved,managerId:8});next.conversation.manager_id=8;await next.start();assert.equal(next.ids.get('replyText').value,'');assert.equal(next.ids.get('sendReply').textContent,'Отправить');assert.equal(next.calls.some(r=>['send','send_receipt'].includes(r.action)),false);
});
test('recipient suspension blocks sending but permits read-only reconciliation',async()=>{
 const h=await unknown();h.W.S.detail.delivery_failure={category:'suspended'};h.C.renderDeliveryFailure(h.W.S.detail.delivery_failure);assert.equal(h.ids.get('replyText').disabled,true);assert.equal(h.ids.get('sendReply').disabled,false);
 h.setHook((u,r)=>r.action==='send_receipt'?response({ok:true,attempt:{state:'confirmed'}}):null);await h.C.sendReply();assert.equal(h.calls.filter(r=>r.action==='send').length,1);assert.equal(h.calls.filter(r=>r.action==='send_receipt').length,1);
});
test('context rejection refreshes authorized detail before unlocking and preserves newer input and file',async()=>{
 const h=createHarness(),gate=deferred(),entered=deferred();await h.start();await h.draft('Original');h.setHook((u,r)=>r.action==='send'?(entered.resolve(),gate.promise):null);const sending=h.C.sendReply();await entered.promise;await h.draft('Newer input');const file={name:'synthetic.png'};h.setFile(file);gate.resolve(response({ok:false,attempt:{state:'rejected'},failure:{category:'attempt_context_changed'}}));await sending;assert.equal(h.calls.filter(r=>r.action==='send').length,1);assert.equal(h.ids.get('replyText').value,'Newer input');assert.equal(h.getFile(),file);assert.equal(h.ids.get('sendReply').textContent,'Отправить');assert.match(h.ids.get('replyStatus').textContent,/Отправка не начата/);assert.ok(h.calls.filter(r=>r.action==='detail'&&r.url==='api.php').length>=2);
});
test('failed fresh detail after context rejection retains the fence until read-only recovery succeeds',async()=>{
 const h=createHarness();await h.start();await h.draft('Essential draft');let failed=true;h.setHook((u,r)=>r.action==='send'||r.action==='send_receipt'?response({ok:false,attempt:{state:'rejected'},failure:{category:'attempt_context_changed'}}):u==='api.php'&&r.action==='detail'&&failed?response({ok:false},500):null);await h.C.sendReply();assert.match(h.ids.get('sendReply').textContent,/Проверить/);assert.match(h.ids.get('replyStatus').textContent,/повтор заблокирован/);failed=false;await h.C.sendReply();assert.equal(h.ids.get('sendReply').textContent,'Отправить');assert.equal(h.ids.get('replyText').value,'Essential draft');assert.equal(h.calls.filter(r=>r.action==='send').length,1);assert.equal(h.calls.filter(r=>r.action==='send_receipt').length,1);
});
test('storage denial refuses transport instead of losing the execution fence',async()=>{
 const saved=storage(),h=createHarness({storage:saved});await h.start();await h.draft('Keep essential input');saved.deny=true;await h.C.sendReply();assert.equal(h.calls.some(r=>r.action==='send'),false);assert.equal(h.ids.get('replyText').value,'Keep essential input');assert.match(h.ids.get('replyStatus').textContent,/отправка не начата/);
});
test('later storage quota failure retains the last durable pending intent',async()=>{
 const saved=storage(),h=await unknown({storage:saved});saved.deny=true;await h.draft('Newer text over unavailable storage');const next=createHarness({storage:saved});saved.deny=false;await next.start({open:false});assert.match(next.ids.get('sendReply').textContent,/Проверить/);assert.equal(next.calls.some(r=>r.action==='send'),false);
});
test('unresolved intent does not expire into a sendable restored draft',async()=>{
 const saved=storage();await unknown({storage:saved});for(const[k,v]of saved.values){const e=JSON.parse(v);e.updatedAt-=48*3600*1000;e.drafts.forEach(d=>d.updatedAt-=48*3600*1000);saved.values.set(k,JSON.stringify(e));}const next=createHarness({storage:saved});await next.start({open:false});assert.match(next.ids.get('sendReply').textContent,/Проверить/);assert.equal(next.ids.get('replyText').value,'Synthetic original');
});
(async()=>{let failed=0;for(const c of cases)try{await c.run();console.log('PASS '+c.name)}catch(e){failed++;console.error('FAIL '+c.name,e)}console.log('TOTAL '+cases.length+' | FAIL '+failed);process.exitCode=failed?1:0})().catch(e=>{console.error(e);process.exitCode=1});
