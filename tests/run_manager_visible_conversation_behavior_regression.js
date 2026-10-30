const assert=require('node:assert/strict');
const {createHarness,response,deferred,hidden,assertNoMutations}=require('./run_manager_reauth_composer_behavior_regression.js');
const cases=[];
const test=(name,run)=>cases.push({name,run});
const first={id:1,sender_type:'customer',text:'Synthetic first message',created_at:'2026-09-06 21:00:00'};
const next={id:2,sender_type:'customer',text:'Synthetic arriving message',created_at:'2026-09-06 21:01:00'};
const flush=async()=>{for(let i=0;i<40;i++)await Promise.resolve()};
function storage(){const values=new Map();return{getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)}}
async function setup(options={}){
 const h=createHarness({inbox:true,storage:storage(),...options});let messages=[first],conversation=h.conversation,failure=null,hook=null,handoff=null;
 const contextRoot=h.document.createElement('div');contextRoot.id='handoffContext';contextRoot.hidden=true;h.ids.get('conversationZone').appendChild(contextRoot);
 if(options.reconcileMessages){const box=h.ids.get('messages');box.removeChild=node=>{assert.equal(node.parentNode,box);node.remove();return node};}
 h.setHook((url,r)=>{
   const intercepted=hook?.(url,r);if(intercepted)return intercepted;
   if(url==='api.php'&&r.action==='detail')return response({ok:true,conversation:{...conversation,id:r.conversation_id},messages,delivery_failure:failure,handoff_context:handoff});
   if(url==='pipeline-api.php'&&r.action==='list')return response({ok:true,conversations:[{...conversation,last_text:messages.at(-1)?.text}]});
 });
 await h.start();
 return Object.assign(h,{arrive(value=[first,next]){messages=value},handoff(value){handoff=value},ownership(value){conversation={...conversation,...value}},failure(value){failure=value},intercept(value){hook=value}});
}
const detailCount=h=>h.calls.filter(r=>r.url==='api.php'&&r.action==='detail').length;

test('opening a long owned chat positions the latest message after the composer becomes visible',async()=>{
 const h=createHarness(),box=h.ids.get('messages');let top=0;
 Object.defineProperties(box,{
   scrollHeight:{get:()=>2000},clientHeight:{get:()=>hidden(h)?700:550},
   scrollTop:{get:()=>top,set:value=>{top=Math.max(0,Math.min(value,2000-box.clientHeight))}}
 });
 await h.start();assert.equal(hidden(h),false);
 assert.equal(box.scrollHeight-box.clientHeight-box.scrollTop,0);
 assertNoMutations(h);
});

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

test('handoff changes without message changes preserve transcript, draft, disclosure and focusable DOM',async()=>{
 const h=await setup(),root=h.ids.get('handoffContext'),row=h.ids.get('messages').firstChild;
 await h.draft('Unsent reply');h.handoff({summary:'Customer request A',guidance:'Internal guidance',attachments:[]});
 assert.equal(await h.C.refreshVisible(),true);assert.equal(root.hidden,false);assert.equal(h.ids.get('messages').firstChild,row);
 const details=root.firstChild,title=details.firstChild;details.open=true;
 h.handoff({summary:'Customer request B <script>literal</script>',guidance:'Updated guidance',attachments:[]});
 await h.C.refreshVisible();assert.equal(root.firstChild,details);assert.equal(details.firstChild,title);assert.equal(details.open,true);
 assert.equal(root.querySelector('.handoffContextSummary').textContent,'Customer request B <script>literal</script>');
 assert.equal(root.querySelector('.handoffContextGuidance').textContent,'Updated guidance');assert.equal(root.querySelector('script'),null);
 assert.equal(h.ids.get('messages').firstChild,row);assert.equal(h.W.S.detail.messages.length,1);assert.equal(h.ids.get('replyText').value,'Unsent reply');
 h.C.renderMessages(h.W.S.detail.messages,{preserveScroll:true,prepend:true});assert.equal(root.firstChild,details,'Rendering older history must not replace the latest context');
 h.handoff(null);await h.C.refreshVisible();assert.equal(root.hidden,true);assert.equal(root.children.length,0);assertNoMutations(h);
});

test('pending navigation hides old handoff and late refresh cannot restore it in a new conversation',async()=>{
 const h=await setup(),root=h.ids.get('handoffContext');h.handoff({summary:'Old private request',attachments:[]});await h.C.refreshVisible();
 const old=deferred(),navigation=deferred();h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?(r.conversation_id===101?old.promise:navigation.promise):null);
 const refreshing=h.C.refreshVisible();await flush();const opening=h.C.open(102);assert.equal(root.hidden,true);assert.equal(root.children.length,0);
 navigation.resolve(response({ok:true,conversation:{...h.conversation,id:102},messages:[next],handoff_context:{summary:'New request',attachments:[]}}));await opening;
 const details=root.firstChild;old.resolve(response({ok:true,conversation:h.conversation,messages:[first],handoff_context:{summary:'Stale private request',attachments:[]}}));await refreshing;
 assert.equal(root.firstChild,details);assert.equal(root.querySelector('.handoffContextSummary').textContent,'New request');assertNoMutations(h);
});

for(const boundary of ['auth','identity','access','denied-refresh'])test('handoff is removed at '+boundary+' boundary',async()=>{
 const h=await setup(),root=h.ids.get('handoffContext');h.handoff({summary:'Private request',attachments:[]});await h.C.refreshVisible();assert.equal(root.hidden,false);
 if(boundary==='auth')h.W.showAuthRecovery();
 else if(boundary==='identity')h.C.resetForIdentityChange();
 else if(boundary==='access')h.C.invalidateMessageAccess();
 else{h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false},403):null);await h.C.refreshVisible();}
 assert.equal(root.hidden,true);assert.equal(root.children.length,0);assertNoMutations(h);
});

test('new media controls initialize against the complete reconciled transcript',async()=>{
 const h=await setup({reconcileMessages:true}),box=h.ids.get('messages'),create=h.document.createElement.bind(h.document),activated=[];
 h.document.createElement=tag=>{
  const node=create(tag);if(['audio','video'].includes(tag)){let controls=false;Object.defineProperty(node,'controls',{get:()=>controls,set:value=>{controls=value;if(value)activated.push({node,connected:node.isConnected,rows:box.children.length})}})}return node;
 };
 h.arrive([{...first,attachments:[{type:'audio',url:'synthetic-audio'}]},{...next,attachments:[{type:'video',url:'synthetic-video'}]}]);await h.C.refreshVisible();
 assert.equal(activated.length,2);for(const item of activated){assert.equal(item.connected,true,'Native controls need the connected final transcript');assert.equal(item.rows,2);assert.equal(item.node.controls,true)}
 const players=activated.map(item=>item.node);await h.C.refreshVisible();assert.equal(activated.length,2,'Unchanged players must not reinitialize');assert.ok(players.every(node=>node.isConnected));assertNoMutations(h);
});

function trackedMedia(h,row,tag){
 const media=h.document.createElement(tag);media.setAttribute('src','synthetic-'+tag);media.currentTime=42;media.playing=true;media.onerror=()=>{};
 media.pauses=0;media.loads=0;media.pause=()=>{assert.equal(media.isConnected,true);media.pauses++;media.playing=false};media.load=()=>{assert.equal(media.src,undefined);media.loads++};row.appendChild(media);return media;
}
function assertRetired(media){assert.equal(media.pauses,1);assert.equal(media.loads,1);assert.equal(media.playing,false);assert.equal(media.src,undefined);assert.equal(media.onerror,null);assert.equal(media.isConnected,false)}
test('unchanged handoff media survives summary refresh and is retired when context disappears',async()=>{
 const h=await setup(),root=h.ids.get('handoffContext');h.handoff({summary:'First request',attachments:[]});await h.C.refreshVisible();
 const mediaRoot=root.querySelector('.handoffContextContent').children[2],audio=trackedMedia(h,mediaRoot,'audio');
 h.handoff({summary:'Updated request',attachments:[]});await h.C.refreshVisible();
 assert.equal(audio.isConnected,true);assert.equal(audio.playing,true);assert.equal(audio.pauses,0);
 h.handoff(null);await h.C.refreshVisible();assertRetired(audio);assertNoMutations(h);
});
test('switching conversations retires detached audio and video without touching retained players',async()=>{
 const h=await setup({reconcileMessages:true}),row=h.ids.get('messages').firstChild,audio=trackedMedia(h,row,'audio'),video=trackedMedia(h,row,'video');
 await h.C.open(102);assertRetired(audio);assertRetired(video);assert.equal(h.W.S.current,102);assertNoMutations(h);
});
test('refresh and failed navigation preserve the same loaded native media',async()=>{
 const h=await setup({reconcileMessages:true}),row=h.ids.get('messages').firstChild,audio=trackedMedia(h,row,'audio');h.arrive();await h.C.refreshVisible();
 h.intercept((url,r)=>url==='api.php'&&r.action==='detail'&&r.conversation_id===102?response({ok:false},500):null);assert.equal(await h.C.open(102),false);
 assert.equal(h.ids.get('messages').firstChild,row);assert.equal(audio.isConnected,true);assert.equal(audio.playing,true);assert.equal(audio.currentTime,42);assert.equal(audio.src,'synthetic-audio');assert.equal(audio.pauses,0);assert.equal(audio.loads,0);assertNoMutations(h);
});
test('replaced message media is retired while its unchanged neighbour keeps playing',async()=>{
 const h=await setup({reconcileMessages:true});h.arrive();await h.C.refreshVisible();const box=h.ids.get('messages'),old=trackedMedia(h,box.children[0],'audio'),retained=trackedMedia(h,box.children[1],'video');
 h.arrive([{...first,text:'Corrected synthetic first message'},next]);await h.C.refreshVisible();assertRetired(old);assert.equal(retained.isConnected,true);assert.equal(retained.playing,true);assert.equal(retained.pauses,0);assert.equal(retained.loads,0);assertNoMutations(h);
});
test('identity reset releases old transcript media',async()=>{
 const h=await setup(),audio=trackedMedia(h,h.ids.get('messages').firstChild,'audio');h.C.resetForIdentityChange();assertRetired(audio);assertNoMutations(h);
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
 const h=await setup(),pending=deferred();await h.draft('Unsent');h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?pending.promise:r.action==='send'?response({ok:false,attempt:{state:'rejected'},failure:{category:'suspended',message:'New failed-send state'}}):null);
 const refresh=h.C.refreshVisible();await flush();await h.C.sendReply();assert.equal(h.W.S.detail.delivery_failure.category,'suspended');pending.resolve(response({ok:true,conversation:h.conversation,messages:[first],delivery_failure:null}));assert.equal(await refresh,false);assert.equal(h.W.S.detail.delivery_failure.category,'suspended');assert.equal(h.ids.get('sendReply').disabled,true);
});

test('a pre-lifecycle refresh is invalidated even when the lifecycle action fails',async()=>{
 const h=await setup(),pending=deferred();h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?pending.promise:r.action==='close'?response({ok:false}):null);
 const refresh=h.C.refreshVisible();await flush();await h.ids.get('conversationActions').children.find(b=>b.textContent==='Закрыть обращение').onclick();pending.resolve(response({ok:true,conversation:{...h.conversation,status:'closed'},messages:[first]}));assert.equal(await refresh,false);assert.equal(h.W.S.detail.conversation.status,'manager');assert.equal(hidden(h),false);assert.match(h.ids.get('conversationLifecycleStatus').children[0].textContent,/Результат действия не подтверждён/);assert.equal(h.ids.get('sendReply').disabled,true);
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

// Keep the obsolete read pending until the replacement context has progressed.
// These execute the canonical Inbox/core/Conversation together, not a refresh stub.
async function replaceRefreshContext(h,mode){
 if(mode==='navigation')await h.C.open(202,{preserveAttachment:true});
 else{
  const owner=mode==='different-account'?8:7;
  h.W.showAuthRecovery();h.setIdentity({...h.identity,manager:{...h.identity.manager,id:owner},csrf:'synthetic-recovered'});h.ownership({manager_id:owner});
  await h.login();if(owner===8)await h.C.open(101,{preserveAttachment:true});
 }
 assert.equal(h.W.S.authExpired,false);assert.equal(hidden(h),false);
}
const listCount=h=>h.calls.filter(r=>r.action==='list').length;
function rejectableRead(){let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no});return{promise,resolve,reject}}
for(const mobile of [false,true])for(const mode of ['navigation','same-account','different-account']){
 const label=`${mobile?'mobile branch':'desktop'} ${mode}`;
 test('pending obsolete detail does not block direct refresh in '+label,async()=>{
  const h=await setup({mobile}),old=deferred();await h.draft('Private previous reply');const file={name:'previous.txt'};h.setFile(file);
  h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?old.promise:null);const obsolete=h.C.refreshVisible();await flush();h.intercept(null);
  try{
   await replaceRefreshContext(h,mode);
   if(mode==='same-account'){assert.equal(h.ids.get('replyText').value,'Private previous reply');assert.equal(h.getFile(),file)}
   if(mode==='different-account'){assert.equal(h.ids.get('replyText').value,'');assert.equal(h.getFile(),null)}
   await h.draft('Current exact reply');const currentFile={name:'current.txt'};h.setFile(currentFile);h.arrive();const before=detailCount(h);
   assert.equal(await h.C.refreshVisible(),true);assert.equal(detailCount(h),before+1);assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),[1,2]);assert.equal(h.ids.get('replyText').value,'Current exact reply');assert.equal(h.getFile(),currentFile);assertNoMutations(h);
  }finally{old.resolve(response({ok:true,conversation:h.conversation,messages:[first]}));await obsolete}
 });
 test('pending obsolete automatic cycle does not block focus events in '+label,async()=>{
  const h=await setup({mobile}),old=deferred();h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?old.promise:null);
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();h.intercept(null);
  try{
   await replaceRefreshContext(h,mode);await h.draft('Focus reply');const file={name:'focus.txt'};h.setFile(file);h.arrive();const details=detailCount(h),lists=listCount(h);
   h.events.get('focus')();await flush();assert.equal(detailCount(h),details+1);assert.equal(listCount(h),lists+1);assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),[1,2]);assert.equal(h.ids.get('replyText').value,'Focus reply');assert.equal(h.getFile(),file);assertNoMutations(h);
  }finally{old.resolve(response({ok:true,conversation:h.conversation,messages:[first]}));await flush()}
 });
 for(const outcome of ['success','forbidden','unauthorized','network'])test('old '+outcome+' cannot render, recover auth or release a held current cycle in '+label,async()=>{
  const h=await setup({mobile}),old=rejectableRead(),current=deferred();h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?old.promise:null);
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();h.intercept(null);
  try{
   await replaceRefreshContext(h,mode);await h.draft('Current private reply');const file={name:'current.txt'};h.setFile(file);h.arrive();
   h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?current.promise:null);
   const before=detailCount(h);h.events.get('focus')();await flush();assert.equal(detailCount(h),before+1);
   const details=detailCount(h),lists=listCount(h),context=h.C.captureReplyContext();
   if(outcome==='network')old.reject(new Error('Synthetic obsolete connection failure'));
   else old.resolve(response(outcome==='success'?{ok:true,conversation:h.conversation,messages:[{...first,id:999}]}:{ok:false},outcome==='unauthorized'?401:outcome==='forbidden'?403:200));
   await flush();assert.equal(h.W.S.authExpired,false);assert.deepEqual(h.C.captureReplyContext(),context);assert.equal(hidden(h),false);assert.equal(h.ids.get('replyText').value,'Current private reply');assert.equal(h.getFile(),file);assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),[1]);
   for(let i=0;i<5;i++){h.events.get('focus')();h.timers[0].fn();}await flush();assert.equal(listCount(h),lists);assert.equal(detailCount(h),details);assert.equal(await h.C.refreshVisible(),false);
   current.resolve(response({ok:true,conversation:{...h.W.S.detail.conversation},messages:[first,next]}));await flush();assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),[1,2]);
   h.intercept(null);h.events.get('focus')();await flush();assert.equal(detailCount(h),details+1);assertNoMutations(h);
  }finally{old.resolve(response({ok:false},500));current.resolve(response({ok:false},500));await flush()}
 });
}
for(const mobile of [false,true])test('A to B to A admits fresh automatic detail before obsolete A settles: '+mobile,async()=>{
 const h=await setup({mobile}),old=deferred();h.intercept((url,r)=>url==='api.php'&&r.action==='detail'?old.promise:null);h.window.WorkspaceV2Inbox.autoRefresh(true);await flush();h.intercept(null);
 try{await h.C.open(202,{preserveAttachment:true});await h.C.open(101,{preserveAttachment:true});h.arrive();const before=detailCount(h);h.events.get('focus')();await flush();assert.equal(detailCount(h),before+1);assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),[1,2]);assertNoMutations(h)}finally{old.resolve(response({ok:true,conversation:h.conversation,messages:[first]}));await flush()}
});
(async()=>{let failed=0;for(const item of cases){try{await item.run();console.log('PASS '+item.name)}catch(e){failed++;console.error('FAIL '+item.name+'\n'+e.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
