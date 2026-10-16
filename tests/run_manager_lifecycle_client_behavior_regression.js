const assert=require('node:assert/strict');
const {createHarness,response,deferred,hidden}=require('./run_manager_reauth_composer_behavior_regression');
// Execute the canonical assets with isolated DOM/API fixtures; never a real action.
const cases=[],test=(name,run)=>cases.push({name,run}),flush=()=>new Promise(resolve=>setImmediate(resolve));
const mutations=h=>h.calls.filter(r=>['take','release','close','reopen','send'].includes(r.action));
const action=(h,name='Закрыть обращение')=>h.ids.get('conversationActions').children.find(b=>b.textContent===name).onclick();
const notice=h=>h.ids.get('conversationLifecycleStatus')?.children[0]?.textContent||'';
const check=h=>h.ids.get('conversationLifecycleStatus').querySelector('button').onclick();
async function owned(){const h=createHarness();await h.start();await h.draft('Unsent lifecycle draft');const file={name:'never-sent.txt'};h.setFile(file);return{h,file}}
for(const [error,copy] of [['unavailable','недоступен'],['closed','уже закрыт'],['owned_elsewhere','другому менеджеру'],['not_owned','не назначен вам'],['state_changed','Состояние диалога изменилось'],['not_working','не на смене'],['csrf','проверкой безопасности']])test('confirmed '+error+' explains refusal without guessing state or sending',async()=>{
  const{h,file}=await owned(),previous=h.W.S.detail,reads=h.calls.filter(r=>r.action==='detail').length;
  h.setHook((url,r)=>r.action==='close'?response({ok:false,error,error_message:'Not a reason parser'},error==='csrf'?403:error==='not_working'?409:200):null);
  await action(h);assert.match(notice(h),new RegExp(copy));assert.equal(h.W.S.detail,previous);assert.equal(h.W.S.detail.conversation.status,'manager');
  assert.equal(h.ids.get('replyText').value,'Unsent lifecycle draft');assert.equal(h.getFile(),file);assert.equal(h.ids.get('replyText').disabled,false);assert.equal(h.ids.get('sendReply').disabled,true);
  await action(h);await h.C.sendReply();assert.equal(mutations(h).length,1);assert.equal(h.calls.filter(r=>r.action==='detail').length,reads);assert.equal(h.calls.some(r=>r.action==='lifecycle_state'),false);
});
for(const kind of ['network','legacy','malformed','server','bad-success'])test('uncertain '+kind+' stays UNKNOWN and never auto-replays',async()=>{
  const{h,file}=await owned();h.setHook((url,r)=>{if(r.action!=='close')return null;if(kind==='network')throw new Error('synthetic connection lost');return response(kind==='legacy'?{ok:false}:kind==='malformed'?null:kind==='server'?{ok:false,error:'closed'}:{ok:'true'},kind==='server'?500:200)});
  await action(h);assert.match(notice(h),/Результат действия не подтверждён/);assert.equal(h.getFile(),file);assert.equal(h.ids.get('replyText').value,'Unsent lifecycle draft');assert.equal(await h.C.refreshVisible(),false);
  await action(h);await h.C.sendReply();assert.equal(mutations(h).length,1);assert.equal(h.calls.some(r=>r.action==='lifecycle_state'),false);
});
test('explicit state check is one visibility-only request; own state retains text/file/history',async()=>{
  const{h,file}=await owned(),messages=h.W.S.detail.messages;let readMarks=0;h.window.WorkspaceV2Inbox.markRead=()=>readMarks++;
  h.setHook((url,r)=>r.action==='close'?response({ok:false}):r.action==='lifecycle_state'?response({ok:true,conversation_state:{id:101,status:'manager',manager_id:7}}):null);
  await action(h);const calls=h.calls.length;await check(h);
  assert.equal(h.calls.length,calls+1);assert.equal(h.calls.at(-1).action,'lifecycle_state');assert.equal(readMarks,0);assert.equal(h.W.S.detail.messages,messages);assert.equal(h.getFile(),file);assert.equal(h.ids.get('replyText').value,'Unsent lifecycle draft');assert.equal(h.ids.get('sendReply').disabled,false);assert.match(h.ids.get('conversationLoadStatus').textContent,/не подтверждение предыдущего действия/);assert.equal(mutations(h).length,1);
});
test('failed/double state check keeps UNKNOWN, text/file and safe retry for the READ only',async()=>{
  const{h,file}=await owned(),held=deferred();h.setHook((url,r)=>r.action==='close'?response({ok:false}):r.action==='lifecycle_state'?held.promise:null);
  await action(h);const reading=check(h);await flush();await check(h);assert.equal(h.calls.filter(r=>r.action==='lifecycle_state').length,1);
  held.resolve(response({ok:false,error:'synthetic_unavailable'},500));await reading;assert.match(notice(h),/Результат действия не подтверждён/);assert.match(notice(h),/Не удалось проверить состояние/);assert.equal(h.getFile(),file);assert.equal(h.ids.get('sendReply').disabled,true);
  h.setHook((url,r)=>r.action==='lifecycle_state'?response({ok:true,conversation_state:{id:101,status:'manager',manager_id:7}}):null);await check(h);assert.equal(h.ids.get('sendReply').disabled,false);assert.equal(mutations(h).length,1);
});
for(const state of [{id:101,status:'closed',manager_id:7},{id:101,status:'manager',manager_id:8},{id:101,status:'ai',manager_id:null}])test('only fresh authoritative state applies existing ownership clear policy '+JSON.stringify(state),async()=>{
  const{h}=await owned();h.setHook((url,r)=>r.action==='close'?response({ok:false,error:'state_changed'}):r.action==='lifecycle_state'?response({ok:true,conversation_state:state}):null);
  await action(h);assert.equal(h.ids.get('replyText').value,'Unsent lifecycle draft');await check(h);
  assert.equal(h.W.S.detail.conversation.status,state.status);assert.equal(h.W.S.detail.conversation.manager_id,state.manager_id);assert.equal(h.ids.get('replyText').value,'');assert.equal(h.getFile(),null);assert.equal(hidden(h),true);assert.equal(mutations(h).length,1);
});
test('unavailable state check follows existing privacy/access-loss policy without transcript read',async()=>{
  const{h}=await owned();h.setHook((url,r)=>r.action==='close'?response({ok:false}):r.action==='lifecycle_state'?response({ok:false,error:'unavailable'},404):null);await action(h);const count=h.calls.length;await check(h);assert.equal(h.calls.length,count+1);assert.equal(h.ids.get('replyText').value,'');assert.equal(h.getFile(),null);assert.equal(hidden(h),true);assert.match(h.ids.get('conversationRefreshStatus').textContent,/Доступ к диалогу изменился/);
});
test('double mutation shares busy state; successful same-owned refresh retains attachment',async()=>{
  const{h,file}=await owned(),held=deferred();h.setHook((url,r)=>r.action==='close'?held.promise:null);const first=action(h);await flush();await action(h);assert.equal(mutations(h).length,1);assert.equal(h.ids.get('sendReply').disabled,true);held.resolve(response({ok:true}));await first;assert.equal(h.ids.get('sendReply').disabled,false);assert.equal(h.getFile(),file);assert.equal(h.ids.get('replyText').value,'Unsent lifecycle draft');assert.match(h.ids.get('conversationLoadStatus').textContent,/Диалог закрыт/);
});
test('confirmed mutation remains confirmed when later transcript refresh fails',async()=>{
  const{h,file}=await owned();h.setHook((url,r)=>r.action==='close'?response({ok:true}):url==='api.php'&&r.action==='detail'?response({ok:false},500):null);await action(h);assert.match(notice(h),/^Диалог закрыт\./);assert.doesNotMatch(notice(h),/Результат действия не подтверждён/);assert.equal(h.getFile(),file);assert.equal(h.ids.get('replyText').value,'Unsent lifecycle draft');assert.equal(h.ids.get('sendReply').disabled,true);
});
for(const outcome of ['success','refusal','network'])test('late '+outcome+' cannot paint or clear another conversation',async()=>{
  const{h}=await owned(),held=deferred();h.setHook((url,r)=>r.action==='close'?held.promise:null);const changing=action(h);await flush();await h.C.open(102);await h.draft('Draft for B');const bFile={name:'B.txt'};h.setFile(bFile);h.ids.get('conversationLoadStatus').textContent='B feedback';
  if(outcome==='network')held.resolve(Promise.reject(new Error('old network')));else held.resolve(response(outcome==='success'?{ok:true}:{ok:false,error:'closed'}));await changing;
  assert.equal(h.W.S.current,102);assert.equal(h.ids.get('replyText').value,'Draft for B');assert.equal(h.getFile(),bFile);assert.equal(h.ids.get('conversationLoadStatus').textContent,'B feedback');assert.equal(notice(h),'');assert.equal(mutations(h).length,1);
});
test('old-account finally cannot release a newer account mutation busy lock',async()=>{
  const{h}=await owned(),old=deferred(),newer=deferred();h.setHook((url,r)=>r.action==='close'?old.promise:null);const first=action(h);await flush();await h.expire();
  h.setIdentity({ok:true,csrf:'new-csrf',manager:{id:8,role:'manager',display_name:'New account',is_working:true},projects:[]});await h.login();h.conversation.manager_id=8;await h.C.open(201);await h.draft('New account draft');h.setHook((url,r)=>r.action==='close'?newer.promise:null);const second=action(h);await flush();
  old.resolve(response({ok:true}));await first;assert.equal(h.W.S.manager.id,8);assert.equal(h.ids.get('sendReply').disabled,true);assert.equal(h.ids.get('replyText').value,'New account draft');assert.equal(h.W.S.current,201);
  newer.resolve(response({ok:false,error:'state_changed'}));await second;assert.equal(h.ids.get('replyText').disabled,false);assert.match(notice(h),/Состояние диалога изменилось/);
});
test('obsolete state-read cannot replace B or expire auth after navigation',async()=>{
  const{h}=await owned(),held=deferred();h.setHook((url,r)=>r.action==='close'?response({ok:false}):r.action==='lifecycle_state'?held.promise:null);await action(h);const reading=check(h);await flush();await h.C.open(102);await h.draft('B stays');held.resolve(response({ok:false,error:'unauthorized'},401));await reading;assert.equal(h.W.S.authExpired,false);assert.equal(h.W.S.current,102);assert.equal(h.ids.get('replyText').value,'B stays');assert.equal(notice(h),'');
});
test('confirmed mutation survives a render exception after the open generation advances',async()=>{
  const{h,file}=await owned();h.window.Manager2Presentation={enhanceConversationActions(){throw new Error('synthetic render failure')}};h.setHook((url,r)=>r.action==='close'?response({ok:true}):null);await action(h);assert.match(notice(h),/^Диалог закрыт\./);assert.equal(h.ids.get('sendReply').disabled,true);assert.equal(h.getFile(),file);assert.equal(h.ids.get('replyText').value,'Unsent lifecycle draft');
});
test('optional Inbox refresh cannot hold the confirmed write lock',async()=>{
  const{h}=await owned(),held=deferred();h.window.WorkspaceV2Inbox.load=()=>held.promise;h.setHook((url,r)=>r.action==='close'?response({ok:true}):null);await action(h);assert.equal(h.ids.get('sendReply').disabled,false);assert.match(h.ids.get('conversationLoadStatus').textContent,/Диалог закрыт/);held.resolve(true);
});
test('obsolete post-success read cannot release B new mutation lock or overwrite its draft',async()=>{
  const{h}=await owned(),read=deferred(),second=deferred();let closes=0;h.setHook((url,r)=>r.action==='close'?(++closes===1?response({ok:true}):second.promise):url==='api.php'&&r.action==='detail'&&r.conversation_id===101?read.promise:null);
  const first=action(h);await flush();await h.C.open(102);await h.draft('B pending');const bFile={name:'B-retained.txt'};h.setFile(bFile);const next=action(h);await flush();read.resolve(response({ok:true,conversation:{...h.conversation,id:101},messages:[]}));await first;assert.equal(h.ids.get('sendReply').disabled,true);assert.equal(h.getFile(),bFile);assert.equal(h.ids.get('replyText').value,'B pending');second.resolve(response({ok:false,error:'closed'}));await next;assert.match(notice(h),/уже закрыт/);
});
test('fresh foreign ownership clears unusable drafts even when a presentation hook throws',async()=>{
  const{h}=await owned();h.setHook((url,r)=>r.action==='close'?response({ok:false}):r.action==='lifecycle_state'?response({ok:true,conversation_state:{id:101,status:'manager',manager_id:8}}):null);await action(h);h.window.Manager2Presentation={enhanceConversationActions(){throw new Error('synthetic state-render failure')}};await check(h);assert.equal(h.W.S.detail.conversation.manager_id,8);assert.equal(h.ids.get('replyText').value,'');assert.equal(h.getFile(),null);assert.equal(h.ids.get('sendReply').disabled,true);assert.match(notice(h),/Результат действия не подтверждён/);assert.match(notice(h),/Не удалось проверить состояние/);
});
test('failed navigation never leaves the original UNKNOWN read button permanently loading',async()=>{
  const{h,file}=await owned(),held=deferred();h.setHook((url,r)=>r.action==='close'?response({ok:false}):r.action==='lifecycle_state'?held.promise:url==='api.php'&&r.action==='detail'&&r.conversation_id===102?response({ok:false},500):null);await action(h);const reading=check(h);await flush();assert.equal(await h.C.open(102),false);held.resolve(response({ok:false,error:'unauthorized'},401));await reading;assert.equal(h.W.S.authExpired,false);assert.equal(h.W.S.current,101);assert.equal(h.ids.get('conversationLifecycleStatus').querySelector('button').disabled,false);assert.equal(h.ids.get('conversationLifecycleStatus').querySelector('button').textContent,'Проверить состояние');assert.equal(h.ids.get('sendReply').disabled,true);assert.equal(h.getFile(),file);assert.equal(h.ids.get('replyText').value,'Unsent lifecycle draft');
});
(async()=>{let failed=0;for(const{name,run}of cases){try{await run();console.log('PASS '+name)}catch(e){failed++;console.error('FAIL '+name+'\n'+e.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
