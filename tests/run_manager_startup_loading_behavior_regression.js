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
  return h.ids.get('inboxList').querySelectorAll('.leadItem').map(node=>Number(node.dataset.conversationId));
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

function notificationHarness(hash='#conversation=102'){
  const h=createHarness({inbox:true});h.location.hash=hash;
  const replace=h.history.replaceState.bind(h.history);
  h.history.replaceState=(state,unused,url)=>{replace(state);if(url!==undefined){const parsed=new URL(url,h.location.href);Object.assign(h.location,{pathname:parsed.pathname,search:parsed.search,hash:parsed.hash,href:parsed.href});}};
  return h;
}
test('notification fragment opens its exact conversation once after authenticated startup',async()=>{
  const h=notificationHarness();await h.start({open:false});assert.equal(h.W.S.current,102);assert.equal(h.location.hash,'');assert.equal(h.calls.filter(c=>c.url==='api.php'&&c.action==='detail').length,1);assertNoMutations(h);
});
test('notification fragment remains through anonymous login then uses protected detail once',async()=>{
  const h=notificationHarness();h.setHook((url,r)=>r.action==='me'?response({ok:false},401):null);await h.start({open:false});assert.equal(h.W.S.authExpired,true);assert.equal(h.location.hash,'#conversation=102');h.setHook(null);await h.login();assert.equal(h.W.S.current,102);assert.equal(h.location.hash,'');assertNoMutations(h);
});
test('an older startup fragment cannot override a newer conversation chosen during Inbox loading',async()=>{
  const h=notificationHarness(),held=deferred(),entered=deferred();h.setHook((url,r)=>r.action==='list'?(entered.resolve(),held.promise):null);const boot=h.start({open:false});await entered.promise;await h.C.open(103);await h.draft('Newer choice');held.resolve(response({ok:true,conversations:liveRows(1)}));await boot;assert.equal(h.W.S.current,103);assert.equal(h.ids.get('replyText').value,'Newer choice');assert.equal(h.calls.some(c=>c.action==='detail'&&c.conversation_id===102),false);assertNoMutations(h);
});
for(const same of [true,false])test('expired notification intent is scoped to recovered account, same='+same,async()=>{
  const h=notificationHarness('');await h.start();await h.draft('Account7 unsent');const file={name:'account7.txt'};h.setFile(file);await h.expire();assert.equal(h.W.openNotificationConversation(102),false);assert.equal(h.location.hash,'#conversation=102');if(!same)h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8}});await h.login();assert.equal(h.W.S.manager.id,same?7:8);assert.equal(h.W.S.current,same?102:0);assert.equal(h.location.hash,'');if(same){await h.C.open(101,{preserveAttachment:true});assert.equal(h.ids.get('replyText').value,'Account7 unsent');}else{assert.equal(h.ids.get('replyText').value,'');assert.equal(h.getFile(),null);}assertNoMutations(h);
});
test('notification for current conversation retains canonical history and selected file',async()=>{
  const h=notificationHarness('');await h.start();await h.draft('Current draft');const file={name:'current.txt'};h.setFile(file);await h.W.openNotificationConversation(101);assert.equal(h.W.S.current,101);assert.equal(h.ids.get('replyText').value,'Current draft');assert.equal(h.getFile(),file);assertNoMutations(h);
});
for(const status of [401,403,404,500])test('notification detail '+status+' retains canonical auth and failure handling',async()=>{
  const h=notificationHarness();h.setHook((url,r)=>url==='api.php'&&r.action==='detail'?response({ok:false},status):null);await h.start({open:false});assert.equal(h.W.S.current,0);assert.equal(h.W.S.authExpired,status===401);assert.equal(h.location.hash,'');assertNoMutations(h);
});
for(const hash of ['#conversation=0','#conversation=-1','#conversation=1.2','#conversation=9007199254740992','#conversation=102&other=1'])test('invalid initial fragment is not a conversation command '+hash,async()=>{const h=notificationHarness(hash);await h.start({open:false});assert.equal(h.W.S.current,0);assert.equal(h.calls.some(c=>c.action==='detail'),false);assertNoMutations(h);});

for(const action of ['list','catalog','filter_options'])test('notification reauth retains the cold target when startup '+action+' expires',async()=>{
  const h=notificationHarness(),held=deferred(),entered=deferred();
  h.setHook((url,r)=>r.action===action?(entered.resolve(),held.promise):null);
  const boot=h.start({open:false});await entered.promise;held.resolve(response({ok:false},401));await boot;await new Promise(setImmediate);
  assert.equal(h.W.S.authExpired,true);assert.equal(h.location.hash,'#conversation=102');
  h.setHook(null);await h.login();assert.equal(h.W.S.current,102);assert.equal(h.location.hash,'');
  assert.equal(h.calls.filter(c=>c.url==='api.php'&&c.action==='detail'&&c.conversation_id===102).length,1);assertNoMutations(h);
});
test('notification reauth retains one target through repeated same-account Inbox expiry',async()=>{
  const h=notificationHarness();let failures=2;h.setHook((url,r)=>r.action==='list'&&failures-->0?response({ok:false},401):null);
  await h.start({open:false});await h.login();assert.equal(h.W.S.authExpired,true);assert.equal(h.location.hash,'#conversation=102');
  await h.login();assert.equal(h.W.S.current,102);assert.equal(h.location.hash,'');
  assert.equal(h.calls.filter(c=>c.url==='api.php'&&c.action==='detail').length,1);assertNoMutations(h);
});
test('notification reauth discards the old-account target before the new Inbox can expire',async()=>{
  const h=notificationHarness();h.setHook((url,r)=>r.action==='list'?response({ok:false},401):null);await h.start({open:false});
  h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8}});await h.login();assert.equal(h.W.S.authExpired,true);assert.equal(h.location.hash,'');
  h.setHook(null);await h.login();assert.equal(h.W.S.manager.id,8);assert.equal(h.W.S.current,0);assert.equal(h.calls.some(c=>c.action==='detail'),false);assertNoMutations(h);
});
test('notification reauth preserves the retained reply and file through another failed Inbox',async()=>{
  const h=notificationHarness('');await h.start();await h.draft('Retained before notification reauth');const file={name:'retained-reauth.txt'};h.setFile(file);await h.expire();h.W.openNotificationConversation(102);
  h.setHook((url,r)=>r.action==='list'?response({ok:false},401):null);await h.login();assert.equal(h.W.S.authExpired,true);assert.equal(h.location.hash,'#conversation=102');assert.equal(h.getFile(),file);
  h.setHook(null);await h.login();assert.equal(h.W.S.current,102);await h.C.open(101,{preserveAttachment:true});assert.equal(h.ids.get('replyText').value,'Retained before notification reauth');assertNoMutations(h);
});
test('notification reauth keeps typed credentials and the latest click without another auth reset',async()=>{
  const h=notificationHarness('');await h.start({open:false});h.W.showAuthRecovery();const generation=h.W.S.authGeneration;
  h.ids.get('managerAuthLogin').value='typed-login';h.ids.get('managerAuthPassword').value='typed-synthetic-password';h.ids.get('managerAuthError').textContent='Existing login feedback';
  h.W.openNotificationConversation(102);h.W.openNotificationConversation(103);
  assert.equal(h.ids.get('managerAuthLogin').value,'typed-login');assert.equal(h.ids.get('managerAuthPassword').value,'typed-synthetic-password');assert.equal(h.ids.get('managerAuthError').textContent,'Existing login feedback');assert.equal(h.W.S.authGeneration,generation);assert.equal(h.location.hash,'#conversation=103');
  await h.login();assert.equal(h.W.S.current,103);assert.equal(h.calls.some(c=>c.action==='detail'&&c.conversation_id===102),false);assertNoMutations(h);
});
test('notification reauth keeps an in-flight login disabled and uses its latest explicit target once',async()=>{
  const h=notificationHarness(''),held=deferred(),entered=deferred();await h.start({open:false});h.W.showAuthRecovery();
  h.setHook((url,r)=>r.action==='login'?(entered.resolve(),held.promise):null);const login=h.login();await entered.promise;
  assert.equal(h.ids.get('managerAuthSubmit').disabled,true);const label=h.ids.get('managerAuthSubmit').textContent;h.W.openNotificationConversation(102);
  assert.equal(h.ids.get('managerAuthSubmit').disabled,true);assert.equal(h.ids.get('managerAuthSubmit').textContent,label);assert.equal(h.ids.get('managerAuthPassword').value,'synthetic-only');
  held.resolve(response(h.identity));await login;assert.equal(h.W.S.current,102);assert.equal(h.calls.filter(c=>c.url==='api.php'&&c.action==='detail').length,1);assertNoMutations(h);
});
test('notification reauth lets a newer chosen conversation win while the recovered Inbox is held',async()=>{
  const h=notificationHarness(),held=deferred(),entered=deferred();h.setHook((url,r)=>r.action==='list'?response({ok:false},401):null);await h.start({open:false});
  h.setHook((url,r)=>r.action==='list'?(entered.resolve(),held.promise):null);const login=h.login();await entered.promise;await h.C.open(103);await h.draft('Newer recovered choice');
  held.resolve(response({ok:true,conversations:liveRows(1)}));await login;assert.equal(h.W.S.current,103);assert.equal(h.ids.get('replyText').value,'Newer recovered choice');assert.equal(h.location.hash,'');assert.equal(h.calls.some(c=>c.action==='detail'&&c.conversation_id===102),false);assertNoMutations(h);
});
test('notification reauth ignores the old startup Inbox401 after a new account has selected a client',async()=>{
  const h=notificationHarness(),held=deferred(),entered=deferred();h.setHook((url,r)=>r.action==='list'?(entered.resolve(),held.promise):null);const boot=h.start({open:false});await entered.promise;
  h.W.showAuthRecovery();h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8}});h.setHook(null);await h.login();await h.C.open(103);await h.draft('Only account8');
  held.resolve(response({ok:false},401));await boot;assert.equal(h.W.S.authExpired,false);assert.equal(h.W.S.manager.id,8);assert.equal(h.W.S.current,103);assert.equal(h.ids.get('replyText').value,'Only account8');assert.equal(h.location.hash,'');assertNoMutations(h);
});

(async()=>{let failed=0;for(const {name,run} of cases){try{await run();console.log('PASS '+name);}catch(error){failed++;console.error('FAIL '+name+'\n'+error.stack);}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0;})();
