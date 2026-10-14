const assert=require('node:assert/strict');
const {createHarness,response,deferred,hidden,assertNoMutations}=require('./run_manager_reauth_composer_behavior_regression.js');
const cases=[];const test=(name,run)=>cases.push({name,run});const flush=async()=>{for(let i=0;i<40;i++)await Promise.resolve()};
const message={id:1,sender_type:'customer',text:'Synthetic original'};
async function setup(){const h=createHarness();await h.start();return h}
function leadRecorder(h){const rendered=[];h.window.WorkspaceV2LeadCard.render=(p,c)=>rendered.push({p,id:c.id});h.window.WorkspaceV2LeadCard.renderUnavailable=c=>rendered.push({unavailable:true,id:c.id});return rendered}
function detail(h,id,messages=[message]){return response({ok:true,conversation:{...h.conversation,id},messages})}

test('authorized transcript and composer are ready while the secondary card is unresolved, with one read each',async()=>{
 const h=await setup(),card=deferred(),rendered=leadRecorder(h);h.setHook((url,r)=>url==='pipeline-api.php'&&r.action==='detail'?card.promise:null);
 const before=h.calls.length;assert.equal(await h.C.open(102),true);assert.equal(h.W.S.current,102);assert.equal(hidden(h),false);assert.equal(h.W.S.detail.lead,null);assert.equal(rendered.length,0);
 assert.deepEqual(h.calls.slice(before).map(r=>r.url+':'+r.action).sort(),['api.php:detail','pipeline-api.php:detail']);
 card.resolve(response({ok:true,trip:{country:'Synthetic new'}}));await flush();assert.equal(rendered.length,1);assert.equal(rendered[0].id,102);assert.equal(h.W.S.detail.lead.trip.country,'Synthetic new');assertNoMutations(h);
});

test('slow and failed cards are isolated from successful transcript, and late card A cannot replace B',async()=>{
 const h=await setup(),card=deferred(),rendered=leadRecorder(h);h.setHook((url,r)=>url==='pipeline-api.php'&&r.action==='detail'?(r.conversation_id===102?card.promise:response({ok:false},500)):null);
 await h.C.open(102);await h.C.open(103);await flush();assert.equal(rendered.at(-1).unavailable,true);assert.equal(rendered.at(-1).id,103);
 card.resolve(response({ok:true,trip:{country:'Private A'}}));await flush();assert.equal(h.W.S.current,103);assert.equal(h.W.S.detail.lead,null);assert.equal(rendered.some(x=>x.id===102),false);assert.equal(hidden(h),false);
});

test('late same-client secondary response from an old authentication generation is discarded',async()=>{
 const h=await setup(),card=deferred(),rendered=leadRecorder(h);h.setHook((url,r)=>url==='pipeline-api.php'&&r.action==='detail'?card.promise:null);await h.C.open(102);
 h.C.suspendForAuthRecovery();h.W.S.authGeneration++;card.resolve(response({ok:true,trip:{country:'Old authentication'}}));await flush();assert.equal(rendered.length,0);assert.equal(hidden(h),true);
});

test('late lead retry from account A never attaches to account B on the same conversation id',async()=>{
 const h=await setup(),card=deferred(),rendered=leadRecorder(h);h.setHook((url,r)=>url==='pipeline-api.php'&&r.action==='detail'?card.promise:null);const retry=h.C.refreshLeadData();
 h.W.S.manager={...h.W.S.manager,id:8};h.W.S.authGeneration++;card.resolve(response({ok:true,trip:{country:'Account A data'}}));assert.equal(await retry,false);assert.equal(rendered.length,0);
});

test('successful source-pinned lead refresh after navigation still updates Inbox without repainting the new client',async()=>{
 const h=await setup(),card=deferred(),rendered=leadRecorder(h);let inbox=0;h.window.WorkspaceV2Inbox.load=async()=>{inbox++;return true};
 h.setHook((url,r)=>url==='pipeline-api.php'&&r.action==='detail'&&r.conversation_id===101?card.promise:null);const retry=h.C.refreshLeadData({refreshInbox:true});await h.C.open(102);await flush();rendered.length=0;
 card.resolve(response({ok:true,trip:{country:'Changed source client'}}));assert.equal(await retry,false);assert.equal(inbox,1);assert.equal(rendered.length,0);assert.equal(h.W.S.current,102);assertNoMutations(h);
});

test('composer is disabled during authorization of the next conversation; sends and quick replies cannot target the old one',async()=>{
 const h=await setup(),pending=deferred();await h.draft('Unsent A');const ctx=h.C.captureReplyContext();h.setHook((url,r)=>url==='api.php'&&r.action==='detail'?pending.promise:null);const opening=h.C.open(102);await flush();
 assert.equal(h.ids.get('replyText').disabled,true);assert.equal(h.C.insertQuickReply('Do not insert',ctx),false);await h.C.sendReply();assertNoMutations(h);
 pending.resolve(detail(h,102));await opening;assert.equal(h.ids.get('replyText').disabled,false);assert.equal(h.ids.get('replyText').value,'');h.C.restoreDraft(101);assert.equal(h.ids.get('replyText').value,'Unsent A');
});

test('mismatched detail cannot select or mark-read an unrelated client and failed switch retains the old draft',async()=>{
 const h=await setup(),reads=[];h.window.WorkspaceV2Inbox.markRead=id=>reads.push(id);await h.draft('Current private draft');h.setHook((url,r)=>url==='api.php'&&r.action==='detail'?detail(h,999):null);
 assert.equal(await h.C.open(102),false);assert.equal(h.W.S.current,101);assert.equal(h.ids.get('replyText').value,'Current private draft');assert.equal(h.ids.get('replyText').disabled,false);assert.deepEqual(reads,[]);assertNoMutations(h);
});

test('same-client explicit access loss closes the composer and removes its unsent private state',async()=>{
 const h=await setup();await h.draft('Private draft');h.setFile({name:'private.png'});h.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false},403):null);
 assert.equal(await h.C.open(101),false);assert.equal(hidden(h),true);assert.equal(h.ids.get('replyText').value,'');assert.equal(h.getFile(),null);assert.equal(h.C.openSearch(),false);assertNoMutations(h);
});

test('quick reply is editable appended text, preserves attachments and refuses stale or unresolved context',async()=>{
 const h=await setup();await h.draft('Existing draft');const file={name:'synthetic.png'};h.setFile(file);const ctx=h.C.captureReplyContext();
 assert.equal(h.C.insertQuickReply('Prepared answer',ctx),true);assert.equal(h.ids.get('replyText').value,'Existing draft\nPrepared answer');assert.equal(h.getFile(),file);
 for(const text of ['Hello {{client_name}}','Hello {unknown}'])assert.equal(h.C.insertQuickReply(text,ctx),false);
 await h.C.open(102);assert.equal(h.C.insertQuickReply('Wrong client',ctx),false);assertNoMutations(h);
});

test('next waiting follows only current loaded selection, keeps filters and makes no assignment or pre-read',async()=>{
 const h=await setup(),reads=[],rows=[{id:101,awaiting_manager_reply:1},{id:104,status:'ai',unread_count:2},{id:102,awaiting_manager_reply:1},{id:103,awaiting_first_reply:1}];h.W.S.queue='mine';h.W.S.search='same selection';h.window.WorkspaceV2Inbox.getLoadedSelection=()=>rows;h.window.WorkspaceV2Inbox.markRead=id=>reads.push(id);await h.draft('Saved A');
 assert.equal(await h.C.nextWaiting(),true);assert.equal(h.W.S.current,102);assert.equal(h.W.S.queue,'mine');assert.equal(h.W.S.search,'same selection');assert.deepEqual(reads,[102]);h.C.restoreDraft(101);assert.equal(h.ids.get('replyText').value,'Saved A');assertNoMutations(h);
});

test('dirty task and revoked access stop next transition without marking the next client read',async()=>{
 const h=await setup(),reads=[];h.window.WorkspaceV2Inbox.getLoadedSelection=()=>[{id:102,awaiting_manager_reply:1}];h.window.WorkspaceV2Inbox.markRead=id=>reads.push(id);h.window.WorkspaceV2Tasks={blockNavigationForDirtyDraft:()=>true};const before=h.calls.length;
 assert.equal(await h.C.nextWaiting(),false);assert.equal(h.calls.length,before);assert.equal(h.W.S.current,101);assert.deepEqual(reads,[]);
 h.window.WorkspaceV2Tasks.blockNavigationForDirtyDraft=()=>false;h.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false},403):null);assert.equal(await h.C.nextWaiting(),false);assert.equal(h.W.S.current,101);assert.deepEqual(reads,[]);assertNoMutations(h);
});

test('empty waiting selection reports the bounded scope and does not trigger background reads',async()=>{
 const h=await setup();h.window.WorkspaceV2Inbox.getLoadedSelection=()=>[{id:101,awaiting_manager_reply:1},{id:105,status:'ai',unread_count:5}];const before=h.calls.length;assert.equal(await h.C.nextWaiting(),false);assert.equal(h.calls.length,before);assert.match(h.ids.get('conversationLoadStatus').textContent,/В загруженной подборке больше нет/);assertNoMutations(h);
});
for(const state of ['loading','unavailable'])test('next waiting does not report an empty list while selection is '+state,async()=>{
 const h=await setup();h.window.WorkspaceV2Inbox.getLoadedSelectionStatus=()=>state;h.window.WorkspaceV2Inbox.getLoadedSelection=()=>[];const before=h.calls.length;
 assert.equal(await h.C.nextWaiting(),false);assert.equal(h.calls.length,before);assert.doesNotMatch(h.ids.get('conversationLoadStatus').textContent,/больше нет/);assert.match(h.ids.get('conversationLoadStatus').textContent,state==='loading'?/Список обновляется/:/Обновите список/);assertNoMutations(h);
});
(async()=>{let failed=0;for(const item of cases){try{await item.run();console.log('PASS '+item.name)}catch(e){failed++;console.error('FAIL '+item.name+'\n'+e.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
