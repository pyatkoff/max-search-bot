const assert=require('node:assert/strict');
const {createHarness,response,deferred}=require('./run_manager_reauth_composer_behavior_regression');

// Run the canonical Inbox/core against deferred synthetic HTTP responses. Native
// timer/focus/visibility hooks come from Inbox.bind; no production traffic.
const cases=[],test=(name,run)=>cases.push({name,run});
const flush=async()=>{for(let i=0;i<50;i++)await Promise.resolve();};
const lists=h=>h.calls.filter(r=>r.action==='list');
const rows=id=>[{id,status:'manager',manager_id:7,display_name:'Synthetic lead '+id,last_text:'Synthetic message'}];
const renderedIds=h=>h.ids.get('inboxList').children.flatMap(n=>n.children||[]).filter(n=>n.dataset.conversationId).map(n=>Number(n.dataset.conversationId));
async function setup(){const h=createHarness({inbox:true});await h.start({open:false});return h;}

test('ordinary Inbox click, Enter and Space preserve attachment on conversation re-entry',async()=>{
  const h=await setup(),opened=[];
  h.C.open=(id,options)=>opened.push({id,options});
  h.setHook((url,r)=>r.action==='list'?response({ok:true,conversations:rows(11)}):null);
  await h.window.WorkspaceV2Inbox.load();
  const row=h.ids.get('inboxList').children.flatMap(n=>n.children||[]).find(n=>n.dataset.conversationId==='11');
  assert.ok(row);row.onclick();
  for(const key of ['Enter',' ']){let prevented=false;row.onkeydown({key,preventDefault(){prevented=true;}});assert.equal(prevented,true);}
  row.onkeydown({key:'ArrowDown',preventDefault(){throw new Error('Unexpected key handling');}});
  assert.equal(opened.length,3);for(const call of opened){assert.equal(call.id,11);assert.deepEqual(Object.keys(call.options),['preserveAttachment']);assert.equal(call.options.preserveAttachment,true);}
});

test('five interval/focus/visible events start one list while live data is still loading',async()=>{
  const h=await setup(),pending=deferred();let transcriptRefreshes=0;
  h.C.refreshVisible=async()=>{transcriptRefreshes++;return true;};
  h.setHook((url,r)=>r.action==='list'?pending.promise:null);
  const before=lists(h).length;
  h.timers[0].fn();h.events.get('focus')();h.documentEvents.get('visibilitychange')();h.events.get('focus')();h.timers[0].fn();
  assert.equal(lists(h).length,before+1);
  assert.equal(transcriptRefreshes,0);
  pending.resolve(response({ok:true,conversations:rows(11)}));await flush();
  assert.deepEqual(renderedIds(h),[11]);assert.equal(transcriptRefreshes,1);
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();
  assert.equal(lists(h).length,before+2);
});

test('automatic refresh waits for any manual Inbox request without blocking it',async()=>{
  const h=await setup(),pending=deferred();h.setHook((url,r)=>r.action==='list'?pending.promise:null);
  const before=lists(h).length,manual=h.window.WorkspaceV2Inbox.load({preserveScroll:false});
  for(let i=0;i<5;i++)assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),false);
  assert.equal(lists(h).length,before+1);
  pending.resolve(response({ok:true,conversations:rows(12)}));assert.equal(await manual,true);await flush();
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();
  assert.equal(lists(h).length,before+2);
});

test('automatic cycle remains coalesced until visible-conversation refresh completes',async()=>{
  const h=await setup(),transcript=deferred();let transcriptRefreshes=0;
  h.C.refreshVisible=async()=>{transcriptRefreshes++;await transcript.promise;return true;};
  const before=lists(h).length;assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();
  assert.equal(transcriptRefreshes,1);
  for(let i=0;i<5;i++)assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),false);
  assert.equal(lists(h).length,before+1);
  transcript.resolve();await flush();
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();
  assert.equal(lists(h).length,before+2);
});

for(const failure of ['http','network','conversation'])test('automatic guard clears after '+failure+' failure',async()=>{
  const h=await setup();let transcriptRefreshes=0;
  h.C.refreshVisible=async()=>{transcriptRefreshes++;if(failure==='conversation')throw new Error('Synthetic transcript outage');return true;};
  h.setHook((url,r)=>{
    if(r.action!=='list')return null;
    if(failure==='http')return response({ok:false},500);
    if(failure==='network')throw new Error('Synthetic connection outage');
    return response({ok:true,conversations:rows(13)});
  });
  const before=lists(h).length;assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();
  assert.equal(transcriptRefreshes,failure==='conversation'?1:0);
  h.setHook((url,r)=>r.action==='list'?response({ok:true,conversations:rows(14)}):null);
  h.C.refreshVisible=async()=>true;
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();
  assert.equal(lists(h).length,before+2);assert.deepEqual(renderedIds(h),[14]);
});

test('manual newer filter/queue request wins and superseded automatic load never refreshes transcript',async()=>{
  const h=await setup(),old=deferred(),manual=deferred();let transcriptRefreshes=0;
  h.C.refreshVisible=async()=>{transcriptRefreshes++;return true;};
  h.setHook((url,r)=>r.action==='list'?(r.search==='new filter'?manual.promise:old.promise):null);
  const before=lists(h).length;
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);
  h.W.S.leadSearch='new filter';h.W.S.queue='mine';
  const newer=h.window.WorkspaceV2Inbox.load({preserveScroll:false});
  assert.equal(lists(h).length,before+2);assert.equal(lists(h).at(-1).search,'new filter');assert.equal(lists(h).at(-1).queue,'mine');
  manual.resolve(response({ok:true,conversations:rows(15)}));assert.equal(await newer,true);
  assert.deepEqual(renderedIds(h),[15]);assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),false);
  old.resolve(response({ok:true,conversations:rows(999)}));await flush();
  assert.deepEqual(renderedIds(h),[15]);assert.equal(transcriptRefreshes,0);
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);
  await flush();assert.equal(transcriptRefreshes,1);
});

test('expired automatic list cannot refresh the new account after manual login recovery',async()=>{
  const h=await setup(),old=deferred();let transcriptRefreshes=0;
  h.C.refreshVisible=async()=>{transcriptRefreshes++;return true;};
  h.setHook((url,r)=>r.action==='list'?old.promise:null);
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);h.W.showAuthRecovery();
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),false);
  h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8},csrf:'synthetic-new-account'});
  h.setHook((url,r)=>r.action==='list'?response({ok:true,conversations:rows(16)}):null);
  await h.login();assert.deepEqual(renderedIds(h),[16]);
  old.resolve(response({ok:true,conversations:rows(999)}));await flush();
  assert.deepEqual(renderedIds(h),[16]);assert.equal(transcriptRefreshes,0);assert.equal(h.W.S.manager.id,8);
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();assert.equal(transcriptRefreshes,1);
});

test('hidden page starts no automatic load and pending visible list does not mark transcript read after hide',async()=>{
  const h=await setup(),pending=deferred();let transcriptRefreshes=0;
  h.C.refreshVisible=async()=>{transcriptRefreshes++;return true;};
  h.setHook((url,r)=>r.action==='list'?pending.promise:null);
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);h.document.visibilityState='hidden';
  const before=lists(h).length;h.events.get('focus')();h.documentEvents.get('visibilitychange')();h.timers[0].fn();
  assert.equal(lists(h).length,before);
  pending.resolve(response({ok:true,conversations:rows(17)}));await flush();assert.equal(transcriptRefreshes,0);
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),false);
  h.document.visibilityState='visible';h.documentEvents.get('visibilitychange')();await flush();
  assert.equal(lists(h).length,before+1);assert.equal(transcriptRefreshes,1);
});

for(const owner of [7,8])test('never-settling previous-session list does not block recovered manager '+owner+' automatic refresh',async()=>{
  const h=await setup(),old=deferred(),current=deferred();let transcriptRefreshes=0;
  h.C.refreshVisible=async()=>{transcriptRefreshes++;return true;};
  h.setHook((url,r)=>r.action==='list'?old.promise:null);
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);
  h.W.showAuthRecovery();h.setIdentity({...h.identity,manager:{...h.identity.manager,id:owner},csrf:'synthetic-recovered-session'});
  h.setHook((url,r)=>r.action==='list'?response({ok:true,conversations:rows(18)}):null);
  await h.login();assert.deepEqual(renderedIds(h),[18]);
  h.setHook((url,r)=>r.action==='list'?current.promise:null);
  const before=lists(h).length;assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);
  for(let i=0;i<5;i++)assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),false);
  assert.equal(lists(h).length,before+1);
  current.resolve(response({ok:true,conversations:rows(19)}));await flush();
  assert.deepEqual(renderedIds(h),[19]);assert.equal(transcriptRefreshes,1);
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();
  assert.equal(transcriptRefreshes,2);
  // old is intentionally never resolved: recovery cannot rely on its finally.
});

for(const owner of [7,8])test('never-settling previous-session transcript does not block recovered manager '+owner+' cycle',async()=>{
  const h=await setup(),old=deferred(),current=deferred();let transcriptRefreshes=0;
  h.C.refreshVisible=async()=>{const number=++transcriptRefreshes;if(number===1)await old.promise;else if(number===2)await current.promise;return true;};
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();assert.equal(transcriptRefreshes,1);
  h.W.showAuthRecovery();h.setIdentity({...h.identity,manager:{...h.identity.manager,id:owner},csrf:'synthetic-recovered-session'});
  await h.login();
  const before=lists(h).length;assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();
  assert.equal(transcriptRefreshes,2);assert.equal(lists(h).length,before+1);
  for(let i=0;i<5;i++)assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),false);
  assert.equal(lists(h).length,before+1);
  current.resolve();await flush();
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();assert.equal(transcriptRefreshes,3);
  // old remains pending while the recovered session continues normally.
});

test('late previous-session finally cannot release a current transcript cycle',async()=>{
  const h=await setup(),old=deferred(),current=deferred();let transcriptRefreshes=0;
  h.C.refreshVisible=async()=>{if(++transcriptRefreshes===1)await old.promise;else await current.promise;return true;};
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();
  h.W.showAuthRecovery();h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8},csrf:'synthetic-new-account'});
  await h.login();assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();
  assert.equal(transcriptRefreshes,2);const before=lists(h).length;
  old.resolve();await flush();
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),false);assert.equal(lists(h).length,before);
  current.resolve();await flush();
  assert.equal(h.window.WorkspaceV2Inbox.autoRefresh(true),true);await flush();
});

test('next selection is scoped to loaded filters, current account and auth generation',async()=>{
  const h=await setup(),inbox=h.window.WorkspaceV2Inbox;
  h.setHook((url,r)=>r.action==='list'?response({ok:true,conversations:rows(80)}):null);await inbox.load();
  assert.ok(inbox.getLoadedSelection().length>0);
  h.W.S.leadSearch='different filter';assert.equal(inbox.getLoadedSelection().length,0);
  h.setHook((url,r)=>r.action==='list'?response({ok:true,conversations:rows(81)}):null);
  await inbox.load();assert.equal(inbox.getLoadedSelection()[0].id,81);
  const detached=inbox.getLoadedSelection();detached[0].id=999;assert.equal(inbox.getLoadedSelection()[0].id,81);
  h.W.S.authGeneration++;assert.equal(inbox.getLoadedSelection().length,0);
});

(async()=>{let failed=0;for(const {name,run} of cases){try{await run();console.log('PASS '+name);}catch(error){failed++;console.error('FAIL '+name+'\n'+error.stack);}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0;})();
