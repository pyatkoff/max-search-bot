const assert=require('node:assert/strict');
const {createHarness,response,deferred,assertNoMutations}=require('./run_manager_reauth_composer_behavior_regression.js');
const cases=[],test=(name,run)=>cases.push({name,run});
const initial={schema:1,context:'a'.repeat(64),version:0,needed:true,customer_message_id:35,request_event_id:12,manager_message_id:30,queue_event_id:12,waiting_since_unix:1794312000,wait_age_seconds:610,wait_time_known:true,can_resolve:true,can_undo:false,resolution:null};
function resolved(input,{version=90,needed=false,customer_message_id=35,...rest}={}){return {...initial,version,needed,customer_message_id,can_resolve:needed,can_undo:true,wait_age_seconds:needed?5:null,resolution:{operation_id:input.operation_id,actor_id:7,actor_name:'Тестовый менеджер',recorded_at:1794312600,through_message_id:input.through_message_id,through_request_event_id:input.through_request_event_id},...rest};}
async function setup({queue=initial,...options}={}){const h=createHarness({...options,replyQueue:true});h.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:true,conversation:{...h.conversation,id:r.conversation_id},messages:[],reply_queue:{...queue,context:(r.conversation_id===101?'a':'b').repeat(64)}}):null);await h.start();h.Q=h.window.WorkspaceV2ReplyQueue;return h;}
function writes(h){return h.calls.filter(c=>['reply_queue_resolve','reply_queue_undo'].includes(c.action));}
function text(h){return h.ids.get('replyQueueText').textContent;}
function state(h){return h.W.S.detail.reply_queue;}
function saved(h){return h.storage.getItem('workspaceV2.replyQueue.pending.v1.7');}
test('mark has an exact immutable displayed frontier; new inbound remains waiting and the composer survives',async()=>{
 const h=await setup();await h.draft('Незавершённый ответ');const file={name:'draft.pdf'};h.setFile(file);h.ids.get('messages').scrollTop=29;
 h.setHook((url,r)=>r.action==='reply_queue_resolve'?response({ok:true,saved_version:90,reply_queue:resolved(r,{needed:true,customer_message_id:36})}):null);
 assert.equal(await h.Q.mutate('resolve'),true);const sent=writes(h)[0];assert.equal(sent.through_message_id,35);assert.equal(sent.through_request_event_id,12);assert.equal(sent.expected_version,0);assert.equal(sent.context,initial.context);assert.match(sent.operation_id,/^[a-f0-9]{32}$/);
 assert.equal(state(h).needed,true);assert.match(text(h),/Ожидает ответа/);assert.match(text(h),/Тестовый менеджер/);assert.equal(h.ids.get('replyText').value,'Незавершённый ответ');assert.equal(h.getFile(),file);assert.equal(h.ids.get('messages').scrollTop,29);assert.equal(h.W.S.detail.conversation.status,'manager');assert.equal(saved(h),null);assertNoMutations(h);
});
test('undo targets exactly the displayed resolution and restores the original wait age',async()=>{
 const h=await setup();let mark;
 h.setHook((url,r)=>{if(r.action==='reply_queue_resolve'){mark=r;return response({ok:true,saved_version:90,reply_queue:resolved(r)})}if(r.action==='reply_queue_undo')return response({ok:true,saved_version:91,reply_queue:{...initial,version:91,wait_age_seconds:640}});return null});
 await h.Q.mutate('resolve');assert.equal(h.ids.get('replyQueueUndo').hidden,false);assert.equal(await h.Q.mutate('undo'),true);const undo=writes(h)[1];assert.equal(undo.resolution_operation_id,mark.operation_id);assert.equal(undo.resolution_actor_id,7);assert.equal(undo.expected_version,90);assert.notEqual(undo.operation_id,mark.operation_id);assert.equal(state(h).wait_age_seconds,640);assert.equal(state(h).waiting_since_unix,initial.waiting_since_unix);assertNoMutations(h);
});
test('lost response fences another mark; receipt confirms the original operation without replay',async()=>{
 const h=await setup();let original;h.setHook((url,r)=>{if(r.action==='reply_queue_resolve'){original=r;throw new Error('response lost')}if(r.action==='reply_queue_receipt')return response({ok:true,found:true,result:{ok:true,saved_version:90},reply_queue:resolved(original)});return null});
 assert.equal(await h.Q.mutate('resolve'),false);assert.ok(saved(h));assert.match(text(h),/Результат пока не подтверждён/);assert.equal(await h.Q.mutate('resolve'),false);assert.equal(writes(h).length,1);assert.equal(await h.Q.check(),true);assert.equal(writes(h).length,1);assert.equal(saved(h),null);assert.equal(state(h).needed,false);assertNoMutations(h);
});
test('not-found receipt permits only explicit retry with the same operation and original payload',async()=>{
 const h=await setup();let original,attempts=0;h.setHook((url,r)=>{if(r.action==='reply_queue_resolve'){attempts++;if(attempts===1){original=r;throw new Error('request lost')}assert.deepEqual(r,original);return response({ok:true,saved_version:90,reply_queue:resolved(r,{needed:true,customer_message_id:99})})}if(r.action==='reply_queue_receipt')return response({ok:true,found:false,result:null,reply_queue:{...initial,customer_message_id:99}});return null});
 await h.Q.mutate('resolve');assert.equal(writes(h).length,1);await h.Q.check();assert.equal(writes(h).length,1);assert.equal(h.ids.get('replyQueueRetry').hidden,false);assert.equal(await h.ids.get('replyQueueRetry').onclick(),true);assert.equal(writes(h).length,2);assert.equal(state(h).needed,true);assert.equal(state(h).customer_message_id,99);assert.equal(state(h).resolution.through_message_id,35);assertNoMutations(h);
});
test('reload recovers the original receipt fence with no automatic mutation',async()=>{
 const h=await setup();let original;h.setHook((url,r)=>{if(r.action==='reply_queue_resolve'){original=r;throw new Error('lost')}return null});await h.Q.mutate('resolve');
 const reloaded=await setup({storage:h.storage});assert.equal(writes(reloaded).length,0);assert.match(text(reloaded),/Результат пока не подтверждён/);reloaded.setHook((url,r)=>r.action==='reply_queue_receipt'?response({ok:true,found:true,result:{ok:true,saved_version:90},reply_queue:resolved(original)}):null);await reloaded.Q.check();assert.equal(state(reloaded).needed,false);assert.equal(writes(reloaded).length,0);assert.equal(saved(reloaded),null);
});
test('late mark A cannot repaint B or touch B draft and files',async()=>{
 const h=await setup(),late=deferred();let original;h.setHook((url,r)=>{if(r.action==='reply_queue_resolve'){original=r;return late.promise}if(r.action==='detail'&&url==='api.php')return response({ok:true,conversation:{...h.conversation,id:r.conversation_id},messages:[],reply_queue:{...initial,context:'b'.repeat(64)}});return null});const marking=h.Q.mutate('resolve');await h.C.open(102);await h.draft('Ответ Б');const file={name:'b.pdf'};h.setFile(file);late.resolve(response({ok:true,saved_version:90,reply_queue:resolved(original)}));await marking;
 assert.equal(h.W.S.current,102);assert.equal(state(h).context,'b'.repeat(64));assert.equal(state(h).version,0);assert.equal(h.ids.get('replyText').value,'Ответ Б');assert.equal(h.getFile(),file);assert.doesNotMatch(text(h),/Последняя отметка/);assertNoMutations(h);
});
test('old account result cannot repaint the same conversation under a new account',async()=>{
 const h=await setup(),late=deferred();let original;h.setHook((url,r)=>{if(r.action==='reply_queue_resolve'){original=r;return late.promise}return null});const marking=h.Q.mutate('resolve');h.W.S.manager={...h.W.S.manager,id:8};h.W.S.authGeneration++;h.Q.activate(8);h.W.S.detail.reply_queue={...initial,context:'c'.repeat(64)};h.Q.sync(h.W.S.detail.conversation);late.resolve(response({ok:true,saved_version:90,reply_queue:resolved(original)}));await marking;assert.equal(state(h).context,'c'.repeat(64));assert.equal(state(h).version,0);assert.ok(saved(h));assertNoMutations(h);
});
test('fresh conflict keeps the canonical newer decision and does not change unread or lifecycle',async()=>{
 const h=await setup();await h.draft('Сохранить текст');h.W.S.detail.conversation.unread_count=3;h.setHook((url,r)=>r.action==='reply_queue_resolve'?response({ok:false,error:'conflict',reply_queue:resolved({...r,operation_id:'d'.repeat(32)},{version:91})},409):null);
 assert.equal(await h.Q.mutate('resolve'),false);assert.equal(state(h).version,91);assert.match(text(h),/Другой менеджер/);assert.equal(h.W.S.detail.conversation.unread_count,3);assert.equal(h.ids.get('replyText').value,'Сохранить текст');assert.equal(saved(h),null);assertNoMutations(h);
});
test('unknown legacy wait is explicit and an older detail cannot overwrite a confirmed decision',async()=>{
 const h=await setup();h.W.S.detail.reply_queue={...initial,wait_age_seconds:null,waiting_since_unix:null};h.Q.sync(h.W.S.detail.conversation);assert.match(text(h),/время ожидания неизвестно/);assert.doesNotMatch(text(h),/<1 мин/);
 h.setHook((url,r)=>r.action==='reply_queue_resolve'?response({ok:true,saved_version:90,reply_queue:resolved(r)}):null);await h.Q.mutate('resolve');h.W.S.detail.reply_queue={...initial};h.Q.sync(h.W.S.detail.conversation);assert.equal(state(h).version,90);assert.equal(state(h).needed,false);
});
test('unavailable/readonly actions and a storage failure make no mutation request',async()=>{
 const h=await setup();h.W.S.detail.reply_queue={...initial,can_resolve:false};h.Q.sync(h.W.S.detail.conversation);assert.equal(await h.Q.mutate('resolve'),false);h.W.S.detail.reply_queue={...initial};h.storage.setItem=()=>{throw new Error('quota')};assert.equal(await h.Q.mutate('resolve'),false);assert.equal(writes(h).length,0);assert.match(text(h),/Не удалось сохранить данные/);assertNoMutations(h);
});
test('legacy waiting without a message or request anchor can be explicitly settled with unknown age',async()=>{
 const h=await setup({queue:{...initial,customer_message_id:0,request_event_id:0,waiting_since_unix:null,wait_age_seconds:null,wait_time_known:false}});
 h.setHook((url,r)=>r.action==='reply_queue_resolve'?response({ok:true,saved_version:90,reply_queue:resolved(r,{customer_message_id:0,request_event_id:0})}):null);
 assert.match(text(h),/время ожидания неизвестно/);assert.equal(await h.Q.mutate('resolve'),true);assert.equal(writes(h)[0].through_message_id,0);assert.equal(writes(h)[0].through_request_event_id,0);assert.equal(state(h).needed,false);assert.equal(h.ids.get('replyQueueUndo').hidden,false);assertNoMutations(h);
});
for(const key of ['customer_message_id','request_event_id','manager_message_id','queue_event_id'])test('equal decision version cannot roll back a newer '+key+' snapshot',async()=>{
 const h=await setup(),late=deferred();let input;h.setHook((url,r)=>{if(r.action==='reply_queue_resolve'){input=r;return late.promise}return null});const marking=h.Q.mutate('resolve');
 const olderState=resolved(input),fresh={...olderState,[key]:olderState[key]+1,needed:key==='manager_message_id'?false:true,can_resolve:key!=='manager_message_id'};h.W.S.detail.reply_queue=fresh;h.Q.sync(h.W.S.detail.conversation);
 late.resolve(response({ok:true,saved_version:90,reply_queue:{...olderState,needed:!fresh.needed}}));await marking;assert.equal(state(h)[key],fresh[key]);assert.equal(state(h).needed,fresh.needed);
 h.W.S.detail.reply_queue={...olderState,needed:!fresh.needed};h.Q.sync(h.W.S.detail.conversation);assert.equal(state(h)[key],fresh[key]);assert.equal(state(h).needed,fresh.needed);assertNoMutations(h);
});
for(const readonly of [false,true])test('late decision cannot restore an old '+(readonly?'readonly':'assignment')+' context',async()=>{
 const h=await setup(),late=deferred();let input,fresh=null;if(!readonly)h.W.S.manager.role='admin';
 h.setHook((url,r)=>{if(r.action==='reply_queue_resolve'){input=r;return late.promise}if(url==='api.php'&&r.action==='detail')return response({ok:true,conversation:{...h.conversation,manager_id:8},messages:[],reply_queue:fresh,supervisor_read:readonly});return null});
 const before=h.C.captureReplyContext(),marking=h.Q.mutate('resolve');if(!readonly)fresh={...resolved(input,{needed:true,customer_message_id:36}),context:'b'.repeat(64),queue_event_id:91};assert.equal(await h.C.refreshVisible(),true);assert.deepEqual(h.C.captureReplyContext(),before);
 late.resolve(response({ok:true,saved_version:90,reply_queue:resolved(input)}));assert.equal(await marking,true);
 if(readonly){assert.equal(state(h),null);assert.equal(h.ids.get('replyQueueStatus').hidden,true)}else{assert.equal(state(h).context,'b'.repeat(64));assert.equal(state(h).customer_message_id,36);assert.equal(state(h).needed,true);assert.doesNotMatch(text(h),/Проверяем результат/)}
 if(readonly)assert.ok(!h.ids.get('replyQueueUndo')||h.ids.get('replyQueueUndo').hidden);assert.equal(saved(h),null);assertNoMutations(h);
});
(async()=>{let failed=0;for(const t of cases){try{await t.run();console.log('PASS '+t.name)}catch(e){failed++;console.error('FAIL '+t.name+'\n'+e.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
