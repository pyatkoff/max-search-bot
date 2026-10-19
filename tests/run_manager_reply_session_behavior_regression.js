const assert = require('node:assert/strict');
const {createHarness,response,deferred,hidden,assertNoMutations}=require('./run_manager_reauth_composer_behavior_regression');

// Independent VM instances share only synthetic sessionStorage/history, exactly as a
// reload does. All persistence, authentication, navigation and send logic is real JS.
function storage(){
  const values=new Map();
  return{values,denyRead:false,denyWrite:false,
    getItem(key){if(this.denyRead)throw new Error('Synthetic denied storage');return values.get(key)||null},
    setItem(key,value){if(this.denyWrite)throw new Error('Synthetic quota');values.set(key,String(value))},
    removeItem:key=>values.delete(key)};
}
async function seeded(options={}){const saved=storage(),h=createHarness({storage:saved,...options});await h.start();await h.draft('Unsent synthetic draft');return{saved,h}}
const snapshot=saved=>JSON.parse([...saved.values.values()][0]);
const tick=()=>new Promise(setImmediate);
const cases=[];function test(name,run){cases.push({name,run})}

function quickReplies(h){
  const buttons=[{dataset:{reply:'Synthetic greeting'}},{dataset:{reply:'Synthetic budget question'}}];
  const query=h.document.querySelectorAll;
  h.document.querySelectorAll=selector=>selector==='.quickReplies [data-reply]'?buttons:query(selector);
  return buttons;
}

test('quick reply keeps the exact existing draft and attachment through reload without sending',async()=>{
  const saved=storage(),h=createHarness({storage:saved}),buttons=quickReplies(h);await h.start();
  const draft='Synthetic drafted offer\n  Keep these details <b>literal</b>  ',file={name:'synthetic.png'};
  await h.draft(draft);h.setFile(file);const callCount=h.calls.length;buttons[0].onclick();
  const expected=draft+'\nSynthetic greeting';
  assert.equal(h.ids.get('replyText').value,expected);assert.equal(h.getFile(),file);assert.equal(h.calls.length,callCount);assertNoMutations(h);
  const next=createHarness({storage:saved});await next.start({open:false});assert.equal(next.ids.get('replyText').value,expected);assertNoMutations(next);
});

test('quick replies fill an empty editor and retain existing whitespace when adding another paragraph',async()=>{
  for(const draft of ['', 'First line', 'First line\n', '  ']){
    const h=createHarness(),buttons=quickReplies(h);await h.start();await h.draft(draft);buttons[0].onclick();
    const expected=draft+(draft&&!draft.endsWith('\n')?'\n':'')+'Synthetic greeting';
    assert.equal(h.ids.get('replyText').value,expected);buttons[1].onclick();
    assert.equal(h.ids.get('replyText').value,expected+'\nSynthetic budget question');assertNoMutations(h);
  }
});

test('quick replies cannot change drafts while the composer is hidden for authentication',async()=>{
  const h=createHarness(),buttons=quickReplies(h);await h.start();await h.draft();await h.expire();
  const count=h.calls.length;buttons[0].onclick();assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft');assert.equal(h.calls.length,count);
  await h.login();assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft');assertNoMutations(h);
});

test('quick replies share the suspended composer lock and recover with the editor',async()=>{
  const h=createHarness(),buttons=quickReplies(h);await h.start();await h.draft();
  h.W.S.detail.delivery_failure={category:'suspended'};h.C.renderDeliveryFailure(h.W.S.detail.delivery_failure);
  assert.equal(buttons[0].disabled,true);buttons[0].onclick();assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft');
  h.W.S.detail.delivery_failure=null;h.C.renderDeliveryFailure(null);assert.equal(buttons[0].disabled,false);
  buttons[0].onclick();assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft\nSynthetic greeting');assertNoMutations(h);
});

test('in-flight send locks quick replies and a failed send restores them without changing text',async()=>{
  const h=createHarness(),buttons=quickReplies(h),sent=deferred(),requested=deferred();await h.start();await h.draft();
  h.setHook((url,r)=>r.action==='send'?(requested.resolve(),sent.promise):null);
  const pending=h.C.sendReply();await requested.promise;assert.equal(buttons[0].disabled,true);
  buttons[0].onclick();assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft');
  sent.resolve(response({ok:false},500));await pending;assert.equal(buttons[0].disabled,false);
  assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft');assert.equal(h.calls.filter(r=>r.action==='send').length,1);
});

test('reload authenticates and authorizes the selected detail before restoring exact unsent text',async()=>{
  const {saved,h}=await seeded();h.setFile({name:'not-persisted.png'});
  const next=createHarness({storage:saved}),detail=deferred(),requested=deferred();
  next.setHook((url,r)=>url==='api.php'&&r.action==='detail'?(requested.resolve(),detail.promise):null);
  const pending=next.start({open:false});await requested.promise;
  assert.equal(next.calls[0].action,'me');assert.equal(next.W.S.current,0);assert.equal(next.ids.get('replyText').value,'');assert.equal(hidden(next),true);
  detail.resolve(response({ok:true,conversation:next.conversation,messages:[]}));await pending;
  assert.equal(next.W.S.current,101);assert.equal(next.ids.get('replyText').value,'Unsent synthetic draft');assert.equal(next.getFile(),null);
  assert.equal(hidden(next),false);assertNoMutations(h);assertNoMutations(next);
});

test('session drafts stay isolated between conversations, browser tabs and freshly authenticated accounts',async()=>{
  const {saved,h}=await seeded();await h.C.open(102);assert.equal(h.ids.get('replyText').value,'');await h.draft('Second synthetic draft');
  const next=createHarness({storage:saved});await next.start({open:false});assert.equal(next.W.S.current,102);assert.equal(next.ids.get('replyText').value,'Second synthetic draft');
  await next.C.open(101);assert.equal(next.ids.get('replyText').value,'Unsent synthetic draft');
  const otherTab=createHarness({storage:storage()});await otherTab.start({open:false});assert.equal(otherTab.W.S.current,0);
  const otherAccount=createHarness({storage:saved,managerId:8});await otherAccount.start({open:false});assert.equal(otherAccount.W.S.current,0);assert.equal(otherAccount.calls.some(r=>r.action==='detail'),false);assert.equal(saved.values.size,0);
  const original=createHarness({storage:saved});await original.start({open:false});assert.equal(original.W.S.current,0);await original.C.open(101);assert.equal(original.ids.get('replyText').value,'');
});

test('a failed initial authentication never loads private drafts',async()=>{
  const {saved}=await seeded(),next=createHarness({storage:saved});
  next.setHook((url,r)=>r.action==='me'?response({ok:false},401):null);await next.start({open:false});
  assert.equal(next.W.S.authExpired,true);assert.equal(next.W.S.current,0);assert.equal(next.ids.get('replyText').value,'');assert.equal(next.calls.some(r=>r.action==='detail'),false);
  next.setHook(null);await next.login();assert.equal(next.W.S.current,101);assert.equal(next.ids.get('replyText').value,'Unsent synthetic draft');assertNoMutations(next);
});

for(const status of [403,404])test('restored detail '+status+' discards inaccessible cached selection and draft',async()=>{
  const {saved}=await seeded(),next=createHarness({storage:saved});next.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false},status):null);await next.start({open:false});
  assert.equal(next.W.S.current,0);assert.equal(next.ids.get('replyText').value,'');assert.equal(hidden(next),true);assert.equal(saved.values.size,0);assertNoMutations(next);
});

test('transient detail failure preserves a retryable draft without exposing it',async()=>{
  const {saved}=await seeded(),next=createHarness({storage:saved});next.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false},500):null);await next.start({open:false});
  assert.equal(next.W.S.current,0);assert.equal(next.ids.get('replyText').value,'');assert.equal(hidden(next),true);
  const retry=createHarness({storage:saved});await retry.start({open:false});assert.equal(retry.ids.get('replyText').value,'Unsent synthetic draft');
});

test('reauthentication during automatic detail restoration resumes only after fresh authorization',async()=>{
  const {saved}=await seeded(),next=createHarness({storage:saved});next.setHook((url,r)=>url==='pipeline-api.php'&&r.action==='detail'?response({ok:false},401):null);await next.start({open:false});
  assert.equal(next.W.S.authExpired,true);assert.equal(next.W.S.current,0);assert.equal(hidden(next),true);
  next.setHook(null);await next.login();assert.equal(next.W.S.current,101);assert.equal(next.ids.get('replyText').value,'Unsent synthetic draft');assertNoMutations(next);
});

for(const state of [{manager_id:8,status:'manager'},{manager_id:7,status:'closed'}])test('fresh '+state.status+'/'+state.manager_id+' ownership prevents a saved reply from reappearing',async()=>{
  const {saved}=await seeded(),next=createHarness({storage:saved});next.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:true,conversation:{...next.conversation,...state},messages:[]}):null);await next.start({open:false});
  assert.equal(next.W.S.current,101);assert.equal(hidden(next),true);assert.equal(next.ids.get('replyText').value,'');assert.equal(snapshot(saved).drafts.length,0);assertNoMutations(next);
});

test('mismatched returned conversation identity cannot receive the cached draft',async()=>{
  const {saved}=await seeded(),next=createHarness({storage:saved});next.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:true,conversation:{...next.conversation,id:999},messages:[]}):null);await next.start({open:false});
  assert.equal(next.W.S.current,0);assert.equal(next.ids.get('replyText').value,'');assert.equal(saved.values.size,0);
});

test('malformed, expired, future and wrong-owner envelopes fail closed',async()=>{
  for(const alter of [()=>'{broken',value=>JSON.stringify({...value,version:99}),value=>JSON.stringify({...value,managerId:8}),value=>JSON.stringify({...value,updatedAt:0}),value=>JSON.stringify({...value,updatedAt:Date.now()+60000}),value=>JSON.stringify({...value,selected:-1,drafts:[{id:Infinity,text:'invalid',updatedAt:Date.now()}]})]){
    const {saved}=await seeded(),key=[...saved.values.keys()][0];saved.values.set(key,alter(snapshot(saved)));
    const next=createHarness({storage:saved});await next.start({open:false});assert.equal(next.W.S.current,0);assert.equal(next.ids.get('replyText').value,'');assert.equal(saved.values.size,0);
  }
});

test('unavailable storage keeps existing in-tab switching functional',async()=>{
  const saved=storage();saved.denyRead=true;saved.denyWrite=true;
  const h=createHarness({storage:saved});await h.start();await h.draft();await h.C.open(102);await h.C.open(101);
  assert.equal(h.ids.get('replyText').value,'Unsent synthetic draft');assert.equal(saved.values.size,0);assertNoMutations(h);
});

test('quota failure while clearing text cannot resurrect an older saved copy',async()=>{
  const {saved,h}=await seeded();saved.denyWrite=true;await h.draft('');assert.equal(saved.values.size,0);
  saved.denyWrite=false;const next=createHarness({storage:saved});await next.start();assert.equal(next.ids.get('replyText').value,'');
});

test('oversized text remains exact in memory and is never silently truncated or replaced with an older copy',async()=>{
  const {saved,h}=await seeded(),long='x'.repeat(20001);await h.draft(long);await h.C.open(102);await h.C.open(101);assert.equal(h.ids.get('replyText').value,long);
  assert.equal(snapshot(saved).drafts.some(d=>d.id===101),false);const next=createHarness({storage:saved});await next.start({open:false});assert.equal(next.ids.get('replyText').value,'');
});

test('persisted text count and age stay bounded without storing messages or identity credentials',async()=>{
  const {saved,h}=await seeded();for(let id=102;id<=125;id++){await h.C.open(id);await h.draft('Synthetic '+id)}
  const value=snapshot(saved);assert.equal(value.drafts.length,20);assert.equal(value.selected,125);assert.equal(value.drafts.some(d=>d.id===101),false);
  assert.deepEqual(Object.keys(value).sort(),['drafts','managerId','selected','updatedAt','version']);
  value.drafts[0].updatedAt=0;saved.values.set([...saved.values.keys()][0],JSON.stringify(value));const next=createHarness({storage:saved});await next.start({open:false});assert.equal(next.ids.get('replyText').value,'');
});

for(const success of [true,false])test('explicit '+(success?'successful':'failed')+' send '+(success?'clears':'retains')+' persisted text without replay',async()=>{
  const {saved,h}=await seeded();h.setHook((url,r)=>r.action==='send'?response({ok:success},success?200:500):null);await h.C.sendReply();
  const next=createHarness({storage:saved});await next.start({open:false});assert.equal(next.ids.get('replyText').value,success?'':'Unsent synthetic draft');assert.equal(h.calls.filter(r=>r.action==='send').length,1);assertNoMutations(next);
});

test('successful send followed by quota failure removes the old persisted text',async()=>{
  const {saved,h}=await seeded();saved.denyWrite=true;h.setHook((url,r)=>r.action==='send'?response({ok:true}):null);await h.C.sendReply();assert.equal(saved.values.size,0);
  saved.denyWrite=false;const next=createHarness({storage:saved});await next.start();assert.equal(next.ids.get('replyText').value,'');
});

test('late send completion cannot erase a newer draft for the same conversation',async()=>{
  const {saved,h}=await seeded(),sent=deferred(),requested=deferred();h.setHook((url,r)=>r.action==='send'?(requested.resolve(),sent.promise):null);
  const pending=h.C.sendReply();await requested.promise;await h.draft('New synthetic text after send began');sent.resolve(response({ok:true}));await pending;
  assert.equal(h.ids.get('replyText').value,'New synthetic text after send began');const next=createHarness({storage:saved});await next.start({open:false});assert.equal(next.ids.get('replyText').value,'New synthetic text after send began');
});

test('inserted identity survives edits, quick replies, switching and authorized reload with its exact text',async()=>{
  const saved=storage(),h=createHarness({storage:saved}),id='a'.repeat(32);await h.start();await h.draft('Manual introduction');
  assert.equal(h.C.insertAiDraft('Synthetic AI reply',h.C.captureReplyContext(),id),true);assertNoMutations(h);
  await h.draft(h.ids.get('replyText').value+' with manual changes');h.C.insertQuickReply('Manual extra paragraph');
  const text=h.ids.get('replyText').value;assert.equal(snapshot(saved).drafts[0].aiDraftId,id);
  await h.C.open(102);await h.draft('Unrelated reply');await h.C.open(101);assert.equal(h.ids.get('replyText').value,text);
  const next=createHarness({storage:saved});await next.start({open:false});assert.equal(next.ids.get('replyText').value,text);
  next.setHook((url,r)=>r.action==='send'?response({ok:false},409):null);await next.C.sendReply();
  const sent=next.calls.find(r=>r.action==='send');assert.equal(sent.conversation_id,101);assert.equal(sent.text,text);assert.equal(sent.ai_draft_id,id);assert.equal(snapshot(saved).drafts.find(d=>d.id===101).aiDraftId,id);
});
test('clearing a tracked draft removes its identity before a new manual reply',async()=>{
  const saved=storage(),h=createHarness({storage:saved});await h.start();h.C.insertAiDraft('Synthetic AI reply',h.C.captureReplyContext(),'a'.repeat(32));await h.draft('');
  assert.equal(snapshot(saved).drafts.length,0);await h.draft('New manual reply');
  h.setHook((url,r)=>r.action==='send'?response({ok:true}):null);await h.C.sendReply();
  assert.equal(h.calls.find(r=>r.action==='send').ai_draft_id,undefined);assert.equal(snapshot(saved).drafts.length,0);
});
test('a new untracked AI insertion cannot inherit the previous proposal identity',async()=>{
  const saved=storage(),h=createHarness({storage:saved});await h.start();h.C.insertAiDraft('Tracked text',h.C.captureReplyContext(),'a'.repeat(32));
  h.C.insertAiDraft('Untracked text',h.C.captureReplyContext(),null);assert.equal(snapshot(saved).drafts[0].aiDraftId,undefined);
  h.setHook((url,r)=>r.action==='send'?response({ok:false},409):null);await h.C.sendReply();assert.equal(h.calls.find(r=>r.action==='send').ai_draft_id,undefined);
});
test('late same-text success cannot consume a newer proposal identity',async()=>{
  const saved=storage(),h=createHarness({storage:saved}),oldId='a'.repeat(32),newId='b'.repeat(32);await h.start();h.C.insertAiDraft('Same synthetic reply',h.C.captureReplyContext(),oldId);
  const sent=deferred(),requested=deferred();h.setHook((url,r)=>r.action==='send'?(requested.resolve(),sent.promise):null);
  const pending=h.C.sendReply();await requested.promise;
  // Replace the local draft identity without changing its text while the old
  // completion is pending; the canonical save owner assigns a new version.
  h.C.saveDraft(101,newId);sent.resolve(response({ok:true}));await pending;
  assert.equal(h.calls.filter(r=>r.action==='send').length,1);assert.equal(h.calls.find(r=>r.action==='send').ai_draft_id,oldId);
  assert.equal(h.ids.get('replyText').value,'Same synthetic reply');assert.equal(snapshot(saved).drafts[0].aiDraftId,newId);
  const next=createHarness({storage:saved});await next.start({open:false});next.setHook((url,r)=>r.action==='send'?response({ok:false},409):null);await next.C.sendReply();assert.equal(next.calls.find(r=>r.action==='send').ai_draft_id,newId);
});
for(const outcome of ['network','failure'])test('unconfirmed tracked '+outcome+' keeps identity and text without replay',async()=>{
  const saved=storage(),h=createHarness({storage:saved}),id='a'.repeat(32);await h.start();h.C.insertAiDraft('Synthetic AI reply',h.C.captureReplyContext(),id);
  h.setHook((url,r)=>{if(r.action!=='send')return null;if(outcome==='network')throw new Error('Synthetic loss');return response({ok:false},409)});await h.C.sendReply();
  assert.equal(h.calls.filter(r=>r.action==='send').length,1);assert.equal(snapshot(saved).drafts[0].aiDraftId,id);assert.equal(h.ids.get('replyText').value,'Synthetic AI reply');
});
test('media send receives only its caption and retains untracked coverage on failure',async()=>{
  const saved=storage(),h=createHarness({storage:saved}),id='a'.repeat(32);await h.start();h.C.insertAiDraft('Synthetic AI caption',h.C.captureReplyContext(),id);h.setFile({name:'synthetic.png'});
  const media=[];h.window.WorkspaceV2Media.send=async(...args)=>{media.push(args);return{ok:false,error:'media_unknown'}};await h.C.sendReply();
  assert.equal(media.length,1);assert.deepEqual(media[0],['Synthetic AI caption']);assert.equal(h.calls.some(r=>r.action==='send'),false);assert.equal(snapshot(saved).drafts[0].aiDraftId,id);
});
test('partial media caption completion cannot consume a newer same-text proposal',async()=>{
  const saved=storage(),h=createHarness({storage:saved});await h.start();h.C.insertAiDraft('Same synthetic caption',h.C.captureReplyContext(),'a'.repeat(32));h.setFile({name:'synthetic.png'});
  const sent=deferred();h.window.WorkspaceV2Media.send=()=>sent.promise;const pending=h.C.sendReply();await tick();h.C.saveDraft(101,'b'.repeat(32));
  sent.resolve({ok:false,confirmed_count:1,caption_sent:true,error_message:'Synthetic partial delivery'});await pending;
  assert.equal(h.ids.get('replyText').value,'Same synthetic caption');assert.equal(snapshot(saved).drafts[0].aiDraftId,'b'.repeat(32));
});
test('tracked drafts cannot cross accounts or inaccessible restored conversations',async()=>{
  for(const mode of ['account','access']){
    const saved=storage(),h=createHarness({storage:saved});await h.start();h.C.insertAiDraft('Private synthetic draft',h.C.captureReplyContext(),'a'.repeat(32));
    const next=createHarness({storage:saved,...(mode==='account'?{managerId:8}:{})});
    if(mode==='access')next.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false},403):null);
    await next.start({open:false});assert.equal(next.ids.get('replyText').value,'');assert.equal(saved.values.size,0);assertNoMutations(next);
  }
});
for(const invalid of ['A'.repeat(32),'short',null,3])test('malformed stored identity is ignored without losing manual text: '+String(invalid),async()=>{
  const {saved}=await seeded(),value=snapshot(saved);value.drafts[0].aiDraftId=invalid;saved.values.set([...saved.values.keys()][0],JSON.stringify(value));
  const next=createHarness({storage:saved});await next.start({open:false});assert.equal(next.ids.get('replyText').value,'Unsent synthetic draft');
  next.setHook((url,r)=>r.action==='send'?response({ok:false},409):null);await next.C.sendReply();assert.equal(next.calls.find(r=>r.action==='send').ai_draft_id,undefined);
});

test('late previous-account attachment success cannot delete a new account draft',async()=>{
  const {saved,h}=await seeded(),sent=deferred();h.setFile({name:'synthetic.png'});h.window.WorkspaceV2Media.send=()=>sent.promise;
  const pending=h.C.sendReply();await tick();h.W.showAuthRecovery();h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8}});await h.login();
  h.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:true,conversation:{...h.conversation,manager_id:8},messages:[]}):null);await h.C.open(101);await h.draft('Other account synthetic draft');sent.resolve({ok:true});await pending;
  assert.equal(snapshot(saved).managerId,8);assert.equal(snapshot(saved).drafts[0].text,'Other account synthetic draft');assert.equal(h.ids.get('replyText').value,'Other account synthetic draft');
});

test('new navigation during startup wins over the stored selection',async()=>{
  const {saved}=await seeded(),next=createHarness({storage:saved}),catalog=deferred(),requested=deferred();next.setHook((url,r)=>r.action==='catalog'?(requested.resolve(),catalog.promise):null);
  const startup=next.start({open:false});await requested.promise;await next.C.open(102);await next.draft('New navigation draft');catalog.resolve(response({ok:true}));await startup;
  assert.equal(next.W.S.current,102);assert.equal(next.ids.get('replyText').value,'New navigation draft');assert.equal(next.calls.some(r=>r.action==='detail'&&r.conversation_id===101),false);
});

for(const phase of ['startup','detail'])test('mobile inbox intent during '+phase+' prevents automatic reopening',async()=>{
  const {saved}=await seeded(),next=createHarness({storage:saved,mobile:true}),delayed=deferred(),requested=deferred();next.setHook((url,r)=>(phase==='startup'?r.action==='catalog':url==='api.php'&&r.action==='detail')?(requested.resolve(),delayed.promise):null);
  const startup=next.start({open:false});await requested.promise;next.window.WorkspaceV2Mobile.showInbox({historyMode:'replace'});
  delayed.resolve(response(phase==='detail'?{ok:true,conversation:next.conversation,messages:[]}:{ok:true}));await startup;
  assert.equal(next.W.S.current,0);assert.equal(next.window.WorkspaceV2Mobile.getScreen(),'inbox');assert.equal(next.ids.get('replyText').value,'');assert.equal(next.C.getSavedSelection(),0);
});

test('actual mobile history survives repeated reload, Back to inbox and Forward without extra entries',async()=>{
  const {saved,h}=await seeded({mobile:true});assert.equal(h.history.entries.length,2);assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'conversation');
  let next=h;for(let n=0;n<3;n++){next=createHarness({storage:saved,mobile:true,sessionHistory:next.history});await next.start({open:false});assert.equal(next.history.entries.length,2);assert.equal(next.window.WorkspaceV2Mobile.getScreen(),'conversation');assert.equal(next.ids.get('replyText').value,'Unsent synthetic draft')}
  await next.history.back();await tick();assert.equal(next.window.WorkspaceV2Mobile.getScreen(),'inbox');assert.equal(next.C.getSavedSelection(),0);
  const inboxReload=createHarness({storage:saved,mobile:true,sessionHistory:next.history});await inboxReload.start({open:false});assert.equal(inboxReload.W.S.current,0);assert.equal(inboxReload.window.WorkspaceV2Mobile.getScreen(),'inbox');
  await inboxReload.history.forward();await tick();assert.equal(inboxReload.window.WorkspaceV2Mobile.getScreen(),'conversation');assert.equal(inboxReload.C.getSavedSelection(),101);assert.equal(inboxReload.ids.get('replyText').value,'Unsent synthetic draft');
  const final=createHarness({storage:saved,mobile:true,sessionHistory:inboxReload.history});await final.start({open:false});assert.equal(final.W.S.current,101);assert.equal(final.history.entries.length,2);
});

test('browser Back while reloaded detail is pending keeps the inbox after the late response',async()=>{
  const {saved,h}=await seeded({mobile:true}),next=createHarness({storage:saved,mobile:true,sessionHistory:h.history}),detail=deferred(),requested=deferred();
  next.setHook((url,r)=>url==='api.php'&&r.action==='detail'?(requested.resolve(),detail.promise):null);
  const startup=next.start({open:false});await requested.promise;await next.history.back();await tick();
  detail.resolve(response({ok:true,conversation:next.conversation,messages:[]}));await startup;
  assert.equal(next.W.S.current,0);assert.equal(next.window.WorkspaceV2Mobile.getScreen(),'inbox');assert.equal(next.C.getSavedSelection(),0);assert.equal(next.ids.get('replyText').value,'');
});

test('mobile same-current Forward remembers selection again and other-account history stays in inbox',async()=>{
  const {saved,h}=await seeded({mobile:true});await h.history.back();await tick();await h.history.forward();await tick();assert.equal(h.C.getSavedSelection(),101);
  const other=createHarness({storage:saved,mobile:true,managerId:8,sessionHistory:h.history});await other.start({open:false});assert.equal(other.W.S.current,0);assert.equal(other.window.WorkspaceV2Mobile.getScreen(),'inbox');assert.equal(other.calls.some(r=>r.action==='detail'),false);
});

async function pendingMobileBack(){
  const h=createHarness({storage:storage(),mobile:true});await h.start();await h.draft('Synthetic A draft');
  await h.C.open(102);await h.draft('Synthetic B draft');
  const file={name:'synthetic-B.txt'};h.setFile(file);
  const delayed=deferred(),requested=deferred();
  h.setHook((url,r)=>url==='api.php'&&r.action==='detail'&&r.conversation_id===101?(requested.resolve(),delayed.promise):null);
  const back=h.history.back();await requested.promise;
  return {h,file,delayed,back};
}
function settleMobileBack(delayed,outcome,h){
  delayed.resolve(outcome==='network'?Promise.reject(new Error('Synthetic navigation outage')):
    response(outcome===200?{ok:true,conversation:h.conversation,messages:[]}:{ok:false,error:'synthetic'},outcome));
}
for(const outcome of [200,401,403,404,500,'network'])test('superseded mobile Back '+outcome+' cannot reopen a client or authentication after Back to inbox',async()=>{
  const {h,file,delayed,back}=await pendingMobileBack();
  await h.history.back();await tick();
  assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'inbox');assert.equal(h.C.getSavedSelection(),0);
  const calls=h.calls.length,generation=h.W.S.authGeneration;
  settleMobileBack(delayed,outcome,h);await back;await tick();
  assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'inbox');assert.equal(h.W.S.current,102);
  assert.equal(h.C.getSavedSelection(),0);assert.equal(h.history.index,0);assert.equal(h.history.entries.length,3);
  assert.equal(h.W.S.authExpired,false);assert.equal(h.W.S.authGeneration,generation);
  assert.equal(h.ids.get('replyText').value,'Synthetic B draft');assert.equal(h.getFile(),file);
  assert.equal(h.calls.length,calls);assertNoMutations(h);
});
for(const outcome of [200,401,403,404,500,'network'])test('superseded mobile Back '+outcome+' leaves a subsequently opened client and its saved selection intact',async()=>{
  const {h,delayed,back}=await pendingMobileBack();await h.history.back();
  await h.C.open(103);await h.draft('Synthetic current C draft');
  const file={name:'synthetic-C.txt'};h.setFile(file);const calls=h.calls.length,entries=JSON.stringify(h.history.entries);
  settleMobileBack(delayed,outcome,h);await back;await tick();
  assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'conversation');assert.equal(h.W.S.current,103);
  assert.equal(h.C.getSavedSelection(),103);assert.equal(JSON.stringify(h.history.entries),entries);
  assert.equal(h.W.S.authExpired,false);assert.equal(h.ids.get('replyText').disabled,false);
  assert.equal(h.ids.get('replyText').value,'Synthetic current C draft');assert.equal(h.getFile(),file);
  assert.equal(h.calls.length,calls);assertNoMutations(h);
});
test('Forward to the retained current client releases controls before an obsolete Back read settles',async()=>{
  const {h,file,delayed,back}=await pendingMobileBack();await h.history.back();
  const forward=h.history.forward();await tick();await h.history.forward();await tick();
  assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'conversation');assert.equal(h.W.S.current,102);
  assert.equal(h.C.getSavedSelection(),102);assert.equal(h.ids.get('replyText').disabled,false);
  assert.equal(h.ids.get('replyText').value,'Synthetic B draft');assert.equal(h.getFile(),file);
  const calls=h.calls.length;
  settleMobileBack(delayed,401,h);await Promise.all([back,forward]);await tick();
  assert.equal(h.W.S.authExpired,false);assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'conversation');
  assert.equal(h.W.S.current,102);assert.equal(h.C.getSavedSelection(),102);assert.equal(h.history.index,2);
  assert.equal(h.ids.get('replyText').disabled,false);assert.equal(h.calls.length,calls);assertNoMutations(h);
});
test('an obsolete Back finally cannot finish a newer pending history transition',async()=>{
  const {h,delayed,back}=await pendingMobileBack(),next=deferred(),requested=deferred();
  h.setHook((url,r)=>url==='api.php'&&r.action==='detail'&&r.conversation_id===103?(requested.resolve(),next.promise):null);
  const newer=h.window.WorkspaceV2Mobile.restore({workspaceV2:true,manager_id:7,screen:'conversation',conversation_id:103});
  await requested.promise;settleMobileBack(delayed,200,h);await back;await tick();
  assert.equal(h.W.S.current,102);assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'conversation');
  assert.equal(h.ids.get('replyText').disabled,true);
  next.resolve(response({ok:true,conversation:{...h.conversation,id:103},messages:[]}));await newer;
  assert.equal(h.W.S.current,103);assert.equal(h.C.getSavedSelection(),103);
  assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'conversation');assert.equal(h.ids.get('replyText').disabled,false);assertNoMutations(h);
});
test('an ordinary pending open also revokes an older mobile restore continuation before commit',async()=>{
  const {h,delayed,back}=await pendingMobileBack(),next=deferred(),requested=deferred();
  h.setHook((url,r)=>url==='api.php'&&r.action==='detail'&&r.conversation_id===103?(requested.resolve(),next.promise):null);
  const newer=h.C.open(103);await requested.promise;settleMobileBack(delayed,500,h);await back;await tick();
  assert.equal(h.W.S.current,102);assert.equal(h.C.getSavedSelection(),102);
  assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'conversation');assert.equal(h.ids.get('replyText').disabled,true);
  next.resolve(response({ok:true,conversation:{...h.conversation,id:103},messages:[]}));assert.equal(await newer,true);
  assert.equal(h.C.getSavedSelection(),103);assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'conversation');
  assert.equal(h.history.state.conversation_id,103);assertNoMutations(h);
});
for(const outcome of [200,401])test('old-account mobile Back '+outcome+' cannot hide a recovered account client',async()=>{
  const {h,delayed,back}=await pendingMobileBack();h.W.showAuthRecovery();
  h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8}});await h.login();
  h.setHook((url,r)=>url==='api.php'&&r.action==='detail'&&r.conversation_id===103?
    response({ok:true,conversation:{...h.conversation,id:103,manager_id:8},messages:[]}):null);
  await h.C.open(103);await h.draft('Synthetic account 8 draft');const file={name:'synthetic-account8.txt'};h.setFile(file);
  settleMobileBack(delayed,outcome,h);await back;await tick();
  assert.equal(h.W.S.manager.id,8);assert.equal(h.W.S.authExpired,false);assert.equal(h.W.S.current,103);
  assert.equal(h.C.getSavedSelection(),103);assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'conversation');
  assert.equal(h.ids.get('replyText').value,'Synthetic account 8 draft');assert.equal(h.getFile(),file);assertNoMutations(h);
});
test('a dirty task keeps its revealed client card when it refuses a history transition',async()=>{
  const {h}=await seeded({mobile:true});await h.C.open(102);const calls=h.calls.length;
  h.window.WorkspaceV2Tasks={blockNavigationForDirtyDraft(){h.window.WorkspaceV2Mobile.showLead();return true}};
  await h.history.back();
  assert.equal(h.W.S.current,102);assert.equal(h.C.getSavedSelection(),102);
  assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'lead');assert.equal(h.calls.length,calls);assertNoMutations(h);
});
test('a current mobile history read still owns real 401 recovery',async()=>{
  const {h,delayed,back}=await pendingMobileBack();settleMobileBack(delayed,401,h);await back;await tick();
  assert.equal(h.W.S.authExpired,true);assert.equal(hidden(h),true);
  assert.equal(h.ids.get('replyText').value,'Synthetic B draft');assertNoMutations(h);
});
test('cancelling a mobile open leaves a real in-flight send locked and never repeats it',async()=>{
  const h=createHarness({mobile:true});await h.start();await h.draft('Synthetic pending send');
  const sent=deferred(),read=deferred(),sendingStarted=deferred(),readingStarted=deferred();
  h.setHook((url,r)=>r.action==='send'?(sendingStarted.resolve(),sent.promise):
    url==='api.php'&&r.action==='detail'&&r.conversation_id===102?(readingStarted.resolve(),read.promise):null);
  const sending=h.C.sendReply();await sendingStarted.promise;
  const opening=h.C.open(102);await readingStarted.promise;h.window.WorkspaceV2Mobile.showInbox({historyMode:'none'});
  assert.equal(h.ids.get('replyText').disabled,true);assert.equal(h.ids.get('sendReply').disabled,true);
  read.resolve(response({ok:true,conversation:{...h.conversation,id:102},messages:[]}));assert.equal(await opening,false);
  assert.equal(h.ids.get('replyText').disabled,true);assert.equal(h.W.S.current,101);
  sent.resolve(response({ok:false},500));await sending;
  assert.equal(h.ids.get('replyText').disabled,false);assert.equal(h.ids.get('replyText').value,'Synthetic pending send');
  assert.equal(h.calls.filter(r=>r.action==='send').length,1);assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'inbox');
});
test('cancelled mobile restoration retains loaded history and permits explicit paging before the old 401 settles',async()=>{
  const {createHistoryHarness}=require('./run_manager_history_transport_behavior_regression');
  const h=createHistoryHarness({mobile:true}),rows=(first,last)=>Array.from({length:last-first+1},(_,i)=>({id:first+i,sender_type:'customer',text:'Synthetic '+(first+i)}));
  h.installFetch(r=>response(h.older(102,rows(201,400))),id=>h.latest(id,rows(401,600)));
  await h.start();await h.C.open(102);await h.H.loadOlder();assert.equal(h.H.getState().loaded,400);
  await h.draft('Synthetic loaded-history draft');const file={name:'synthetic-loaded.txt'};h.setFile(file);
  const old=deferred(),requested=deferred();
  h.setHook((url,r)=>url==='api.php'&&r.action==='detail'&&r.conversation_id===101?(requested.resolve(),old.promise):
    url==='api.php'&&r.action==='detail'&&r.before_id?response(h.older(102,rows(1,200),false)):null);
  const back=h.history.back();await requested.promise;await h.history.back();
  await h.window.WorkspaceV2Mobile.restore({workspaceV2:true,manager_id:7,screen:'conversation',conversation_id:102});
  assert.equal(h.ids.get('replyText').disabled,false);assert.equal(h.H.getState().loaded,400);
  assert.equal(h.H.getState().before_id,201);assert.equal(await h.H.loadOlder(),true);assert.equal(h.H.getState().loaded,600);
  const current=h.W.S.detail,marks=h.readMarks.length,calls=h.calls.length;
  old.resolve(response({ok:false},401));await back;await tick();
  assert.equal(h.W.S.authExpired,false);assert.equal(h.W.S.detail,current);assert.equal(h.C.getSavedSelection(),102);
  assert.equal(h.H.getState().has_more,false);assert.equal(h.H.getState().loaded,600);
  assert.equal(h.readMarks.length,marks);assert.equal(h.calls.length,calls);
  assert.equal(h.ids.get('replyText').value,'Synthetic loaded-history draft');assert.equal(h.getFile(),file);assertNoMutations(h);
});

(async()=>{let failed=0;for(const item of cases){try{await item.run();console.log('PASS '+item.name)}catch(e){failed++;console.error('FAIL '+item.name+'\n'+e.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
