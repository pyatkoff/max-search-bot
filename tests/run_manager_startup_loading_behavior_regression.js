const assert=require('node:assert/strict');
const {createHarness,response,deferred,hidden,assertNoMutations}=require('./run_manager_reauth_composer_behavior_regression');

// Execute the real Workspace core, Inbox and Conversation assets. Only DOM and
// transport/native notification dependencies are synthetic; no production data.
const cases=[];
const test=(name,run)=>cases.push({name,run});
async function within(promise){
  let timer;
  try{return await Promise.race([promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Startup is waiting for a secondary operation')),300);})]);}
  finally{clearTimeout(timer);}
}
function liveRows(count=200){
  return Array.from({length:count},(_,i)=>({id:i+1,status:'manager',manager_id:7,display_name:'Synthetic live lead '+(i+1),last_text:'Synthetic customer message',last_message_at:'2026-10-13 12:00:00',next_task_due_at_utc:'2026-10-14 07:00:00'}));
}
function withRows(h){
  h.setHook((url,r)=>r.action==='list'?response({ok:true,conversations:liveRows()}):null);
}
function renderedIds(h){
  return h.ids.get('inboxList').children.flatMap(node=>node.children||[]).filter(node=>node.dataset.conversationId).map(node=>Number(node.dataset.conversationId));
}

for(const action of ['catalog','filter_options'])for(const mobile of [false,true])test(action+' never settling does not block Inbox or an authorized reply, mobile='+mobile,async()=>{
  const h=createHarness({inbox:true,mobile}),held=deferred();
  h.setHook((url,r)=>r.action===action?held.promise:r.action==='list'?response({ok:true,conversations:liveRows()}):null);
  await within(h.start({open:false}));
  assert.equal(h.W.S.booting,false);assert.equal(h.W.S.workspaceBound,true);assert.equal(renderedIds(h).length,200);
  await within(h.C.open(101));await h.draft('Exact unsent draft\n  with whitespace');const file={name:'synthetic.png'};h.setFile(file);
  assert.equal(hidden(h),false);const lists=h.calls.filter(r=>r.action==='list').length,details=h.calls.filter(r=>r.action==='detail').length;
  held.resolve(response(action==='catalog'?{ok:true}:{ok:true,filters:{projects:[],sources:[],managers:[]}}));
  await new Promise(setImmediate);
  assert.equal(h.calls.filter(r=>r.action==='list').length,lists);assert.equal(h.calls.filter(r=>r.action==='detail').length,details);
  assert.equal(h.W.S.current,101);assert.equal(h.ids.get('replyText').value,'Exact unsent draft\n  with whitespace');assert.equal(h.getFile(),file);assert.equal(hidden(h),false);assertNoMutations(h);
});

for(const action of ['catalog','filter_options'])test('same-manager recovery restores reply and attachment while '+action+' never settles',async()=>{
  const h=createHarness({inbox:true}),held=deferred();await h.start();await h.draft('Recovery unsent draft');const file={name:'synthetic.txt'};h.setFile(file);await h.expire();
  h.setHook((url,r)=>r.action===action?held.promise:null);await within(h.login());
  assert.equal(h.W.S.current,101);assert.equal(hidden(h),false);assert.equal(h.ids.get('replyText').value,'Recovery unsent draft');assert.equal(h.getFile(),file);assertNoMutations(h);
});

test('secondary card waits for catalog options without delaying the transcript, and only the current selection renders',async()=>{
  const h=createHarness({inbox:true}),held=deferred(),cards=[];
  h.window.WorkspaceV2LeadCard.render=(p,c)=>cards.push({id:c.id,stages:h.W.S.pipeline.stages});
  h.setHook((url,r)=>r.action==='catalog'?held.promise:null);await within(h.start());
  assert.equal(hidden(h),false);assert.equal(cards.length,0);await h.C.open(102);await h.draft('Current draft');
  held.resolve(response({ok:true,stages:[{stage_key:'working',display_name:'В работе'}]}));await new Promise(setImmediate);
  assert.equal(cards.length,1);assert.equal(cards[0].id,102);assert.equal(cards[0].stages[0].stage_key,'working');
  assert.equal(h.ids.get('replyText').value,'Current draft');assertNoMutations(h);
});

test('a previously initialized core without the optional catalog signal still opens an authorized transcript',async()=>{
  const h=createHarness({inbox:true});await h.start({open:false});delete h.W.whenCatalogReady;
  await within(h.C.open(101));assert.equal(h.W.S.current,101);assert.equal(hidden(h),false);assertNoMutations(h);
});

test('new account starts its Inbox without previous-account directory labels while metadata is held',async()=>{
  const h=createHarness({inbox:true}),held=deferred();await h.start({open:false});
  h.W.S.filterSources=[{id:12,display_name:'Previous account source'}];h.W.S.filterManagers=[{id:9,display_name:'Previous account manager'}];h.W.S.filterOptionsReady=true;
  h.W.S.leadSourceFilter=12;h.W.S.leadManagerFilter='9';h.W.showAuthRecovery();
  h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8,role:'admin'}});
  h.setHook((url,r)=>r.action==='catalog'?held.promise:null);await within(h.login());
  assert.equal(h.W.S.manager.id,8);assert.equal(h.W.S.filterSources.length,0);assert.equal(h.W.S.filterManagers.length,0);assert.equal(h.W.S.filterOptionsReady,false);
  assert.equal(h.W.S.leadSourceFilter,12);assert.equal(h.W.S.leadManagerFilter,'9');
  assert.equal(h.calls.filter(r=>r.action==='list').length,2);assertNoMutations(h);
});

for(const action of ['catalog','filter_options'])for(const status of [401,500])test('late '+action+' '+status+' keeps canonical auth recovery and loaded data',async()=>{
  const h=createHarness({inbox:true}),held=deferred();h.setHook((url,r)=>r.action===action?held.promise:r.action==='list'?response({ok:true,conversations:liveRows(2)}):null);
  await within(h.start());await h.draft('Preserved on metadata failure');const file={name:'synthetic.txt'};h.setFile(file);
  held.resolve(response({ok:false},status));await new Promise(setImmediate);
  assert.equal(h.W.S.authExpired,status===401);assert.equal(hidden(h),status===401);assert.equal(renderedIds(h).length,2);
  assert.equal(h.ids.get('replyText').value,'Preserved on metadata failure');assert.equal(h.getFile(),file);assert.equal(h.ids.get('inboxList').innerHTML.includes('startupFailure'),false);assertNoMutations(h);
});

for(const heldStage of ['init','refresh'])test('200 live-shaped leads render while notification '+heldStage+' never settles',async()=>{
  const h=createHarness({inbox:true}),pending=deferred(),events=[];
  h.window.WorkspaceV2Notifications={
    init:async()=>{events.push('init');if(heldStage==='init')await pending.promise;},
    refresh:async()=>{events.push('refresh');if(heldStage==='refresh')await pending.promise;},
  };
  withRows(h);await within(h.start({open:false}));
  assert.equal(h.W.S.booting,false);
  assert.equal(h.W.S.authExpired,false);
  assert.equal(renderedIds(h).length,200);
  assert.equal(h.calls.filter(r=>r.action==='list').length,1);
  assert.equal(events.includes(heldStage),true);
  assert.equal(h.calls.some(r=>['send','take','release','close','reopen'].includes(r.action)),false);
});

test('notification initialization rejection does not replace loaded leads with a startup failure',async()=>{
  const h=createHarness({inbox:true});
  h.window.WorkspaceV2Notifications={init:async()=>{throw new Error('Synthetic native notification outage');},refresh:async()=>{throw new Error('Synthetic push health outage');}};
  withRows(h);await within(h.start({open:false}));
  assert.equal(renderedIds(h).length,200);
  assert.equal(h.ids.get('inboxList').innerHTML.includes('startupFailure'),false);
});

test('same-manager recovery restores the selected reply while notification health never settles',async()=>{
  const h=createHarness({inbox:true}),health=deferred();
  await h.start();await h.draft('Synthetic unsent reply');await h.expire();
  h.window.WorkspaceV2Notifications={init:async()=>{},refresh:async()=>health.promise};
  await within(h.login());
  assert.equal(h.W.S.current,101);
  assert.equal(h.ids.get('composer').classList.contains('hidden'),false);
  assert.equal(h.ids.get('replyText').value,'Synthetic unsent reply');
  assert.equal(h.calls.some(r=>['send','take','release','close','reopen'].includes(r.action)),false);
});

test('late notification init from an expired login does not refresh a new account',async()=>{
  const h=createHarness({inbox:true}),oldInit=deferred();let initCount=0;const refreshes=[];
  h.window.WorkspaceV2Notifications={
    init:async()=>{if(++initCount===1)await oldInit.promise;},
    refresh:async()=>{refreshes.push({owner:h.W.S.manager.id,generation:h.W.S.authGeneration});},
  };
  withRows(h);await within(h.start({open:false}));
  h.W.showAuthRecovery();h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8},csrf:'synthetic-new-account'});
  await within(h.login());
  assert.equal(refreshes.length,1);assert.equal(refreshes[0].owner,8);
  oldInit.resolve();await Promise.resolve();await Promise.resolve();
  assert.equal(refreshes.length,1);
  assert.equal(h.W.S.manager.id,8);
});

test('late Inbox from expired startup cannot populate a new account',async()=>{
  const h=createHarness({inbox:true}),oldList=deferred(),entered=deferred();
  h.setHook((url,r)=>r.action==='list'?(entered.resolve(),oldList.promise):null);
  const oldBoot=h.start({open:false});await entered.promise;
  h.W.showAuthRecovery();h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8},csrf:'synthetic-new-account'});
  h.setHook((url,r)=>r.action==='list'?response({ok:true,conversations:liveRows(1)}):null);
  await within(h.login());assert.deepEqual(renderedIds(h),[1]);
  oldList.resolve(response({ok:true,conversations:[{...liveRows(1)[0],id:999,display_name:'Expired account private lead'}]}));
  await within(oldBoot);
  assert.deepEqual(renderedIds(h),[1]);assert.equal(h.W.S.manager.id,8);
});

for(const action of ['catalog','filter_options'])for(const status of [200,401])test('late '+action+' '+status+' from expired startup cannot clear a new session or reload its Inbox',async()=>{
  const h=createHarness({inbox:true}),oldMetadata=deferred(),entered=deferred();let filterRenders=0;
  h.window.WorkspaceV2Filters={bind(){},render(){filterRenders++;}};
  h.setHook((url,r)=>r.action===action?(entered.resolve(),oldMetadata.promise):null);
  const oldBoot=h.start({open:false});await entered.promise;
  h.W.showAuthRecovery();
  h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8},csrf:'synthetic-new-account'});
  h.setHook((url,r)=>{
    if(r.action==='catalog')return response({ok:true,stages:[{stage_key:'working',display_name:'New session catalog'}]});
    if(r.action==='filter_options')return response({ok:true,filters:{projects:[],sources:[{id:88,display_name:'New session source'}],managers:[]}});
    if(r.action==='list')return response({ok:true,conversations:liveRows(1)});
    return null;
  });
  await within(h.login());
  const lists=h.calls.filter(r=>r.action==='list').length,renders=filterRenders;
  oldMetadata.resolve(response({ok:true,stages:[{stage_key:'old',display_name:'Expired account catalog'}],filters:{projects:[],sources:[{id:999,display_name:'Expired source'}],managers:[]}},status));
  await within(oldBoot);await new Promise(setImmediate);
  assert.equal(h.W.S.pipeline.stages[0].display_name,'New session catalog');
  assert.equal(h.W.S.filterSources[0].id,88);
  assert.equal(h.W.S.filterOptionsReady,true);
  assert.equal(h.calls.filter(r=>r.action==='list').length,lists);
  assert.equal(filterRenders,renders);
  assert.equal(h.W.S.manager.id,8);assert.equal(h.W.S.authExpired,false);assertNoMutations(h);
});

(async()=>{let failed=0;for(const {name,run} of cases){try{await run();console.log('PASS '+name);}catch(error){failed++;console.error('FAIL '+name+'\n'+error.stack);}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0;})();
