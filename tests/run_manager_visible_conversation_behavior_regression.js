const assert=require('node:assert/strict');
const {createHarness,response,deferred,hidden,assertNoMutations}=require('./run_manager_reauth_composer_behavior_regression.js');
const cases=[];
const test=(name,run)=>cases.push({name,run});
const first={id:1,sender_type:'customer',text:'Synthetic first message',created_at:'2026-09-06 21:00:00'};
const next={id:2,sender_type:'customer',text:'Synthetic arriving message',created_at:'2026-09-06 21:01:00'};
const flush=async()=>{for(let i=0;i<40;i++)await Promise.resolve()};
function storage(){const values=new Map();return{getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)}}
async function setup(options={}){
 const h=createHarness({inbox:true,storage:storage(),...options});let messages=[first],conversation=h.conversation,failure=null,hook=null;
 h.setHook((url,r)=>{
   const intercepted=hook?.(url,r);if(intercepted)return intercepted;
   if(url==='api.php'&&r.action==='detail')return response({ok:true,conversation:{...conversation,id:r.conversation_id},messages,delivery_failure:failure});
   if(url==='pipeline-api.php'&&r.action==='list')return response({ok:true,conversations:[{...conversation,last_text:messages.at(-1)?.text}]});
 });
 await h.start();
 return Object.assign(h,{arrive(value=[first,next]){messages=value},ownership(value){conversation={...conversation,...value}},failure(value){failure=value},intercept(value){hook=value}});
}
const detailCount=h=>h.calls.filter(r=>r.url==='api.php'&&r.action==='detail').length;

test('canonical 15-second Inbox timer displays an arriving message without reopening or touching edits',async()=>{
 const h=await setup();await h.draft('Synthetic unsent reply');const file={name:'synthetic.png'};h.setFile(file);
 const lead=h.W.S.detail.lead;let leadRenders=0;h.window.WorkspaceV2LeadCard.render=()=>leadRenders++;
 h.ids.get('replyText').selectionStart=3;h.ids.get('replyText').selectionEnd=8;
 h.ids.get('replyStatus').textContent='Existing send feedback';h.ids.get('conversationLoadStatus').textContent='Existing action feedback';
 const generation=h.C.getOpenGeneration(),count=detailCount(h),pipelineCount=h.calls.filter(r=>r.url==='pipeline-api.php'&&r.action==='detail').length;
 assert.equal(h.timers.length,1);assert.equal(h.timers[0].ms,15000);h.arrive();h.timers[0].fn();await flush();
 assert.equal(detailCount(h),count+1);assert.equal(h.W.S.detail.messages.at(-1).id,2);assert.equal(h.W.S.current,101);assert.equal(h.C.getOpenGeneration(),generation);
 assert.equal(h.ids.get('replyText').value,'Synthetic unsent reply');assert.equal(h.ids.get('replyText').selectionStart,3);assert.equal(h.ids.get('replyText').selectionEnd,8);assert.equal(h.getFile(),file);
 assert.equal(h.W.S.detail.lead,lead);assert.equal(leadRenders,0);assert.equal(h.calls.filter(r=>r.url==='pipeline-api.php'&&r.action==='detail').length,pipelineCount);
 assert.equal(h.ids.get('replyStatus').textContent,'Existing send feedback');assert.equal(h.ids.get('conversationLoadStatus').textContent,'Existing action feedback');assertNoMutations(h);
});

test('focus and visible-tab events use the existing refresh path; hidden tabs make no requests',async()=>{
 const h=await setup();h.arrive();h.events.get('focus')();await flush();assert.equal(h.W.S.detail.messages.length,2);
 h.document.visibilityState='hidden';const count=h.calls.length;h.events.get('focus')();h.documentEvents.get('visibilitychange')();assert.equal(await h.C.refreshVisible(),false);await flush();assert.equal(h.calls.length,count);
 h.arrive([first,next,{...next,id:3}]);h.document.visibilityState='visible';h.documentEvents.get('visibilitychange')();await flush();assert.equal(h.W.S.detail.messages.length,3);
});

test('mobile inbox and lead overlay never fetch read-marking detail; transcript does',async()=>{
 const h=await setup({mobile:true});h.arrive();h.window.WorkspaceV2Mobile.showInbox({historyMode:'none'});let count=detailCount(h);
 assert.equal(await h.C.refreshVisible(),false);assert.equal(detailCount(h),count);
 h.window.WorkspaceV2Mobile.showLead({historyMode:'none'});assert.equal(await h.C.refreshVisible(),false);assert.equal(detailCount(h),count);
 h.window.WorkspaceV2Mobile.conversationOpened(101,{historyMode:'none'});assert.equal(await h.C.refreshVisible(),true);assert.equal(h.W.S.detail.messages.length,2);
});

test('unchanged history retains its DOM and media nodes; same-id synthetic summary text can refresh',async()=>{
 const h=await setup();const box=h.ids.get('messages'),fragment=box.children[0];assert.equal(await h.C.refreshVisible(),true);assert.equal(box.children[0],fragment);
 h.arrive([{id:0,sender_type:'ai',text:'Synthetic summary A'}]);await h.C.refreshVisible();const summary=box.children[0];h.arrive([{id:0,sender_type:'ai',text:'Synthetic summary B'}]);await h.C.refreshVisible();assert.notEqual(box.children[0],summary);assert.equal(h.W.S.detail.messages[0].text,'Synthetic summary B');
});
for(const atBottom of [false,true])test(atBottom?'new messages follow an already-bottom transcript':'new messages preserve earlier-history reading position',async()=>{
 const h=await setup(),box=h.ids.get('messages');box.scrollHeight=1000;box.clientHeight=100;box.scrollTop=atBottom?900:150;
 const replace=box.replaceChildren.bind(box);box.replaceChildren=(...nodes)=>{replace(...nodes);box.scrollHeight=1200};h.arrive();await h.C.refreshVisible();assert.equal(box.scrollTop,atBottom?1200:150);
});

test('only one visible refresh runs while its protected detail is pending',async()=>{
 const h=await setup(),pending=deferred();h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?pending.promise:null);const count=detailCount(h),firstRefresh=h.C.refreshVisible();await flush();assert.equal(await h.C.refreshVisible(),false);assert.equal(detailCount(h),count+1);
 pending.resolve(response({ok:true,conversation:h.conversation,messages:[first,next]}));assert.equal(await firstRefresh,true);
});

test('navigation in progress blocks polling and late old refresh cannot overwrite the new conversation',async()=>{
 const h=await setup(),old=deferred(),navigation=deferred();h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?(r.conversation_id===101?old.promise:navigation.promise):null);
 const refresh=h.C.refreshVisible();await flush();const opening=h.C.open(102);await flush();assert.equal(await h.C.refreshVisible(),false);
 navigation.resolve(response({ok:true,conversation:{...h.conversation,id:102},messages:[next]}));assert.equal(await opening,true);await h.draft('New conversation draft');old.resolve(response({ok:false},404));assert.equal(await refresh,false);assert.equal(h.W.S.current,102);assert.equal(h.ids.get('replyText').value,'New conversation draft');assert.equal(hidden(h),false);
});

test('response arriving after mobile Back is discarded without changing screen or messages',async()=>{
 const h=await setup({mobile:true}),pending=deferred();h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?pending.promise:null);const refresh=h.C.refreshVisible();await flush();h.window.WorkspaceV2Mobile.showInbox({historyMode:'none'});
 pending.resolve(response({ok:true,conversation:h.conversation,messages:[first,next]}));assert.equal(await refresh,false);assert.equal(h.W.S.detail.messages.length,1);assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'inbox');
});
for(const status of [403,404])test('fresh '+status+' access loss locks replies and removes cached text without sending',async()=>{
 const h=await setup();await h.draft('Private unsent reply');h.setFile({name:'private.png'});h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false},status):null);
 assert.equal(await h.C.refreshVisible(),false);assert.equal(hidden(h),true);assert.equal(h.ids.get('sendReply').disabled,true);assert.equal(h.ids.get('replyText').value,'');assert.equal(h.getFile(),null);assert.equal(h.C.getSavedSelection(),0);assert.equal(h.W.S.detail.messages.length,1);
 h.C.restoreDraft(101);assert.equal(h.ids.get('replyText').value,'');await h.C.sendReply();assertNoMutations(h);
 h.intercept(null);await h.C.refreshVisible();assert.equal(hidden(h),false);assert.equal(h.ids.get('sendReply').disabled,false);
});

test('transient refresh failure preserves history, draft, attachment and unrelated feedback',async()=>{
 const h=await setup();await h.draft('Unsent');const file={name:'synthetic.png'};h.setFile(file);const messages=h.W.S.detail.messages;
 h.ids.get('replyStatus').textContent='Send feedback';h.ids.get('conversationLoadStatus').textContent='Lifecycle feedback';h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false},500):null);
 assert.equal(await h.C.refreshVisible(),false);assert.equal(h.W.S.detail.messages,messages);assert.equal(h.ids.get('replyText').value,'Unsent');assert.equal(h.getFile(),file);assert.match(h.ids.get('conversationRefreshStatus').textContent,/Не удалось обновить/);
 h.intercept(null);await h.C.refreshVisible();assert.equal(h.ids.get('conversationRefreshStatus').textContent,'');assert.equal(h.ids.get('replyStatus').textContent,'Send feedback');assert.equal(h.ids.get('conversationLoadStatus').textContent,'Lifecycle feedback');
});
for(const ownership of [{manager_id:8},{status:'closed'}])test('fresh ownership/status change removes unusable reply controls and draft: '+JSON.stringify(ownership),async()=>{
 const h=await setup();await h.draft('Private unsent');h.setFile({name:'synthetic.png'});h.ownership(ownership);await h.C.refreshVisible();assert.equal(hidden(h),true);assert.equal(h.ids.get('replyText').value,'');assert.equal(h.getFile(),null);h.C.restoreDraft(101);assert.equal(h.ids.get('replyText').value,'');assertNoMutations(h);
});

test('fresh delivery suspension disables the composer while preserving the unsent reply',async()=>{
 const h=await setup();await h.draft('Unsent');h.failure({category:'suspended',message:'Synthetic delivery suspension'});await h.C.refreshVisible();assert.equal(h.ids.get('sendReply').disabled,true);assert.equal(h.ids.get('replyText').value,'Unsent');await h.C.sendReply();assertNoMutations(h);
});

test('a pre-send refresh cannot erase the newer failed-send delivery state after busy clears',async()=>{
 const h=await setup(),pending=deferred();await h.draft('Unsent');h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?pending.promise:r.action==='send'?response({ok:false,failure:{category:'suspended',message:'New failed-send state'}}):null);
 const refresh=h.C.refreshVisible();await flush();await h.C.sendReply();assert.equal(h.W.S.detail.delivery_failure.category,'suspended');pending.resolve(response({ok:true,conversation:h.conversation,messages:[first],delivery_failure:null}));assert.equal(await refresh,false);assert.equal(h.W.S.detail.delivery_failure.category,'suspended');assert.equal(h.ids.get('sendReply').disabled,true);
});

test('a pre-lifecycle refresh is invalidated even when the lifecycle action fails',async()=>{
 const h=await setup(),pending=deferred();h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?pending.promise:r.action==='close'?response({ok:false}):null);
 const refresh=h.C.refreshVisible();await flush();await h.ids.get('conversationActions').children.find(b=>b.textContent==='Закрыть').onclick();pending.resolve(response({ok:true,conversation:{...h.conversation,status:'closed'},messages:[first]}));assert.equal(await refresh,false);assert.equal(h.W.S.detail.conversation.status,'manager');assert.equal(hidden(h),false);assert.match(h.ids.get('conversationLoadStatus').textContent,/Не удалось закрыть/);
});

test('sending pauses new refresh requests without replaying the message',async()=>{
 const h=await setup(),pending=deferred();await h.draft('Unsent');h.intercept((url,r)=>r.action==='send'?pending.promise:null);const sending=h.C.sendReply();await flush();const count=detailCount(h);assert.equal(await h.C.refreshVisible(),false);assert.equal(detailCount(h),count);pending.resolve(response({ok:false}));await sending;assert.equal(h.calls.filter(r=>r.action==='send').length,1);
});

test('old account refresh is discarded across authenticated account recovery',async()=>{
 const h=await setup(),pending=deferred();h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?pending.promise:null);const refresh=h.C.refreshVisible();await flush();h.W.showAuthRecovery();h.intercept(null);h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8}});await h.login();pending.resolve(response({ok:true,conversation:h.conversation,messages:[next]}));assert.equal(await refresh,false);assert.equal(h.W.S.current,0);assert.equal(h.W.S.detail,null);assert.equal(hidden(h),true);
});

test('401 uses existing recovery and malformed matching data never replaces history',async()=>{
 const h=await setup();h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:true,conversation:{...h.conversation,id:999},messages:[next]}):null);assert.equal(await h.C.refreshVisible(),false);assert.equal(h.W.S.current,101);assert.equal(h.W.S.detail.messages.length,1);
 h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false},401):null);assert.equal(await h.C.refreshVisible(),false);assert.equal(h.W.S.authExpired,true);assert.equal(hidden(h),true);assertNoMutations(h);
});
(async()=>{let failed=0;for(const item of cases){try{await item.run();console.log('PASS '+item.name)}catch(e){failed++;console.error('FAIL '+item.name+'\n'+e.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
