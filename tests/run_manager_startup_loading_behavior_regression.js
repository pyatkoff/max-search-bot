const assert=require('node:assert/strict');
const {createHarness,response,deferred}=require('./run_manager_reauth_composer_behavior_regression');

// Execute the real Workspace core, Inbox and Conversation assets. Only DOM and
// transport/native notification dependencies are synthetic; no production data.
const cases=[];
const test=(name,run)=>cases.push({name,run});
async function within(promise){
  let timer;
  try{return await Promise.race([promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Startup is waiting for a secondary notification operation')),300);})]);}
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

test('late catalog and filter responses from expired startup cannot clear a new session or reload its Inbox',async()=>{
  const h=createHarness({inbox:true}),oldCatalog=deferred(),entered=deferred();let filterRenders=0;
  h.window.WorkspaceV2Filters={bind(){},render(){filterRenders++;}};
  h.setHook((url,r)=>r.action==='catalog'?(entered.resolve(),oldCatalog.promise):null);
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
  oldCatalog.resolve(response({ok:true,stages:[{stage_key:'old',display_name:'Expired account catalog'}]}));
  await within(oldBoot);
  assert.equal(h.W.S.pipeline.stages[0].display_name,'New session catalog');
  assert.equal(h.W.S.filterSources[0].id,88);
  assert.equal(h.W.S.filterOptionsReady,true);
  assert.equal(h.calls.filter(r=>r.action==='list').length,lists);
  assert.equal(filterRenders,renders);
  assert.equal(h.W.S.manager.id,8);
});

(async()=>{let failed=0;for(const {name,run} of cases){try{await run();console.log('PASS '+name);}catch(error){failed++;console.error('FAIL '+name+'\n'+error.stack);}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0;})();
