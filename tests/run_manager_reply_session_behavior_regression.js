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

(async()=>{let failed=0;for(const item of cases){try{await item.run();console.log('PASS '+item.name)}catch(e){failed++;console.error('FAIL '+item.name+'\n'+e.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
