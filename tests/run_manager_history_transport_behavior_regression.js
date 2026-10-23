const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {createHarness, response, deferred, hidden} = require('./run_manager_reauth_composer_behavior_regression');

// Real core request(), Conversation and History; only fetch and DOM are synthetic.
// In particular, a mock History.api would miss core's global HTTP 401 handling.
const canonicalHistory = () => fs.readFileSync(path.join(__dirname, '../manager/assets/workspace-v2-history.js'), 'utf8');
const message = id => ({id, sender_type:'customer', text:'Synthetic history '+id});
const olderCalls = h => h.calls.filter(r => r.action === 'detail' && r.before_id);
const stableHistory = h => { const {paging, recovering, ...state} = h.H.getState(); return state; };

function createHistoryHarness({mobile=false, historySource=canonicalHistory()}={}) {
  const h = createHarness({mobile});
  // Expose the shell's existing history controls. Layout is covered by browser QA.
  const controls = h.document.createElement('div'); controls.id='conversationHistoryControls';
  for (const [id, tag] of [['conversationLoadOlder','button'], ['conversationHistoryStatus','span']]) {
    const node=h.document.createElement(tag); node.id=id; controls.appendChild(node);
  }
  vm.runInNewContext(historySource, {window:h.window, document:h.document}, {filename:'workspace-v2-history.js'});
  h.H=h.window.WorkspaceV2History;
  h.readMarks=[];
  h.window.WorkspaceV2Inbox.markRead=id=>h.readMarks.push(id);
  h.latest=(id, rows=[message(500+id)], hasMore=true) => ({
    ok:true, conversation:{...h.conversation, id, manager_id:h.W.S.manager?.id || 7},
    messages:rows, history:{has_more:hasMore, before_id:rows[0]?.id || 0, page_size:200, mode:'latest'}
  });
  h.older=(id=101, rows=[message(400)], hasMore=true) => ({
    ...h.latest(id, rows, hasMore), history:{has_more:hasMore, before_id:rows[0]?.id || 0, page_size:200, mode:'older'}
  });
  h.installFetch=(older, latest=(id)=>h.latest(id)) => h.setHook((url,r)=> {
    if(url==='api.php' && r.action==='detail') return r.before_id ? older(r) : response(latest(r.conversation_id));
    // This is an in-memory successful send control, never network or provider I/O.
    if(url==='api.php' && r.action==='send') return response({ok:true});
    return null;
  });
  h.installFetch(()=>{throw new Error('Unexpected older read');});
  return h;
}

async function protectCurrent(h) {
  await h.draft('Exact current synthetic draft');
  const file={name:'synthetic-current.txt'}; h.setFile(file);
  const box=h.ids.get('messages'); box.scrollTop=25; box.scrollHeight=400; box.clientHeight=100;
  const audio=h.document.createElement('audio'); audio.currentTime=42; audio.playing=true;
  box.children[0]?.appendChild(audio);
  h.document.activeElement=h.ids.get('replyText');
  h.ids.get('replyStatus').textContent='Current synthetic feedback';
  return {
    current:h.W.S.current, manager:h.W.S.manager.id, generation:h.W.S.authGeneration,
    detail:h.W.S.detail, history:stableHistory(h), file, audio,
    rows:[...box.children], historyEntries:JSON.stringify(h.history.entries), historyIndex:h.history.index,
    marks:h.readMarks.length, requests:h.calls.length,
    historyStatus:h.ids.get('conversationHistoryStatus').textContent, historyPending:h.H.getState().paging,
    refresh:h.ids.get('conversationRefreshStatus').textContent,
    load:h.ids.get('conversationLoadStatus').textContent
  };
}

function assertCurrentProtected(h, saved) {
  assert.equal(h.W.S.authExpired,false, 'obsolete read must not expire current authentication');
  assert.equal(hidden(h),false, 'current composer remains available');
  assert.equal(h.ids.get('sendReply').disabled,false);
  assert.equal(h.W.S.current,saved.current); assert.equal(h.W.S.manager.id,saved.manager);
  assert.equal(h.W.S.authGeneration,saved.generation);
  assert.equal(h.W.S.detail,saved.detail); assert.deepEqual(stableHistory(h),saved.history);
  assert.equal(h.H.getState().paging,false);
  assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft'); assert.equal(h.getFile(),saved.file);
  assert.deepEqual(h.ids.get('messages').children,saved.rows); assert.equal(saved.audio.currentTime,42); assert.equal(saved.audio.playing,true);
  assert.equal(h.ids.get('messages').scrollTop,25); assert.equal(h.document.activeElement,h.ids.get('replyText'));
  assert.equal(h.ids.get('replyStatus').textContent,'Current synthetic feedback');
  assert.equal(h.ids.get('conversationRefreshStatus').textContent,saved.refresh);
  assert.equal(h.ids.get('conversationLoadStatus').textContent,saved.load);
  if(saved.historyPending && saved.history.has_gap) assert.match(h.ids.get('conversationHistoryStatus').textContent,/Между загруженными частями/);
  else assert.equal(h.ids.get('conversationHistoryStatus').textContent,saved.historyStatus);
  assert.equal(h.readMarks.length,saved.marks, 'older pages never add read-marker writes');
  assert.equal(h.calls.length,saved.requests, 'late completion never rereads or sends');
  assert.equal(JSON.stringify(h.history.entries),saved.historyEntries); assert.equal(h.history.index,saved.historyIndex);
}

async function runSuite({historySource=canonicalHistory()}={}) {
  const cases=[];
  const test=(name,run)=>cases.push({name,run});
  const make=options=>createHistoryHarness({historySource,...options});
  const outcomes=['200','401','403','404','500','network'];
  const settle=(pending,outcome,h)=>pending.resolve(outcome==='network' ? Promise.reject(new Error('Synthetic outage')) : response(outcome==='200'?h.older():{ok:false,error:'synthetic'},Number(outcome)));

  const rows=(first,last)=>Array.from({length:last-first+1},(_,i)=>message(first+i));
  const settleRecovery=()=>new Promise(resolve=>setImmediate(resolve));
  function recoveryFixture(h,{tail=800,older}={}) {
    // Enable Conversation's real retained-row renderer in this DOM fixture.
    const box=h.ids.get('messages');box.removeChild=node=>{node.remove();return node};
    const data={tail};
    h.installFetch(older||((r)=>response(h.older(101,rows(Math.max(1,r.before_id-200),r.before_id-1),r.before_id>201))),id=>h.latest(id,rows(Math.max(1,data.tail-199),data.tail),data.tail>200));
    return data;
  }
  test('reconnect automatically bridges a multi-page gap without draining older history or replacing current input',async()=>{
    const h=make(),data=recoveryFixture(h);await h.start();const saved=await protectCurrent(h),row=h.ids.get('messages').children[0];
    data.tail=1400;await h.C.refreshVisible();await settleRecovery();
    assert.equal(h.H.getState().has_gap,false);assert.equal(h.H.getState().before_id,601);assert.equal(h.H.getState().has_more,true);
    assert.deepEqual(olderCalls(h).map(r=>r.before_id),[1201,1001,801]);
    assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),Array.from({length:800},(_,i)=>601+i));
    assert.equal(h.ids.get('messages').children[0]===row,true);assert.equal(saved.audio.parentElement===row,true);assert.equal(saved.audio.currentTime,42);
    assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft');assert.equal(h.getFile(),saved.file);assert.equal(h.ids.get('messages').scrollTop,25);
    assert.equal(h.calls.some(r=>['send','take','release','close','reopen'].includes(r.action)),false);
  });
  test('automatic gap failure stops until explicit retry and keeps the draft and file',async()=>{
    const h=make(),data=recoveryFixture(h,{older:()=>response({ok:false},500)});await h.start();const saved=await protectCurrent(h);
    data.tail=1400;await h.C.refreshVisible();await settleRecovery();assert.equal(olderCalls(h).length,1);assert.equal(h.H.getState().has_gap,true);assert.match(h.H.getState().error,/черновик сохранены/);
    await h.C.refreshVisible();await settleRecovery();assert.equal(olderCalls(h).length,1,'a successful latest read does not blindly replay failed gap pages');
    recoveryFixture(h,{tail:1400});assert.equal(await h.H.loadOlder(),true);assert.equal(h.H.getState().has_gap,false);
    assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft');assert.equal(h.getFile(),saved.file);
  });
  test('automatic recovery pauses in a hidden tab and resumes the same gap on return',async()=>{
    const h=make(),hold=deferred(),data=recoveryFixture(h,{older:()=>hold.promise});await h.start();const saved=await protectCurrent(h);
    data.tail=1400;await h.C.refreshVisible();await settleRecovery();assert.equal(olderCalls(h).length,1);
    h.document.visibilityState='hidden';hold.resolve(response({ok:false},401));await settleRecovery();
    assert.equal(h.W.S.authExpired,false);assert.equal(h.H.getState().has_gap,true);assert.equal(olderCalls(h).length,1);
    h.document.visibilityState='visible';recoveryFixture(h,{tail:1400});await h.C.refreshVisible();await settleRecovery();
    assert.equal(h.H.getState().has_gap,false);assert.equal(h.getFile(),saved.file);assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft');
  });
  test('new arrivals during gap recovery move its frontier without letting the obsolete page erase it',async()=>{
    const h=make(),hold=deferred();let first=true;const data=recoveryFixture(h,{older:r=>first?(first=false,hold.promise):response(h.older(101,rows(r.before_id-200,r.before_id-1)))});await h.start();
    data.tail=1400;await h.C.refreshVisible();await settleRecovery();assert.equal(olderCalls(h)[0].before_id,1201);
    data.tail=2000;await h.C.refreshVisible();hold.resolve(response(h.older(101,rows(1001,1200))));await settleRecovery();
    assert.equal(h.H.getState().has_gap,false);assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),Array.from({length:1400},(_,i)=>601+i));
    assert.equal(olderCalls(h)[1].before_id,1801);assert.equal(new Set(h.W.S.detail.messages.map(m=>m.id)).size,1400);
  });
  test('a stalled hidden recovery cannot block return and its late 401 cannot expire the recovered view',async()=>{
    const h=make(),hold=deferred(),data=recoveryFixture(h,{older:()=>hold.promise});await h.start();const saved=await protectCurrent(h);
    data.tail=1400;await h.C.refreshVisible();await settleRecovery();assert.equal(olderCalls(h).length,1);
    h.document.visibilityState='hidden';h.documentEvents.get('visibilitychange')();assert.equal(h.H.getState().recovering,false);
    h.document.visibilityState='visible';recoveryFixture(h,{tail:1400});await h.C.refreshVisible();await settleRecovery();assert.equal(h.H.getState().has_gap,false);
    const count=h.calls.length;hold.resolve(response({ok:false},401));await settleRecovery();assert.equal(h.W.S.authExpired,false);assert.equal(h.calls.length,count);
    assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft');assert.equal(h.getFile(),saved.file);
  });
  test('network return starts canonical visible recovery and late A cannot expire B',async()=>{
    const h=make(),data=recoveryFixture(h);await h.start();data.tail=1400;h.events.get('online')();await settleRecovery();
    assert.equal(h.H.getState().has_gap,false);assert.equal(olderCalls(h).length,3);
    const hold=deferred();data.tail=2000;h.installFetch(()=>hold.promise,id=>h.latest(id,rows(id===101?1801:1,id===101?2000:3),id===101));
    await h.C.refreshVisible();await settleRecovery();await h.C.open(102);await h.draft('Current B draft');const file={name:'current-b.txt'};h.setFile(file);
    const count=h.calls.length;hold.resolve(response({ok:false},401));await settleRecovery();assert.equal(h.W.S.authExpired,false);assert.equal(h.W.S.current,102);assert.equal(h.calls.length,count);
    assert.equal(h.ids.get('replyText').value,'Current B draft');assert.equal(h.getFile(),file);
  });
  test('very large reconnect gaps stop after ten pages and continue only on explicit intent',async()=>{
    const h=make(),data=recoveryFixture(h,{tail:200});await h.start();data.tail=3000;await h.C.refreshVisible();await settleRecovery();
    assert.equal(olderCalls(h).length,10);assert.equal(h.H.getState().has_gap,true);assert.equal(h.H.getState().recovery_limited,true);
    await h.C.refreshVisible();await settleRecovery();assert.equal(olderCalls(h).length,10);
    assert.equal(await h.H.loadOlder(),true);assert.equal(olderCalls(h).length,14);assert.equal(h.H.getState().has_gap,false);assert.equal(h.W.S.detail.messages.length,3000);
  });
  async function loadedHistory(options={}) {
    const h=make(options);
    h.installFetch(()=>response(h.older(101,rows(401,600))),id=>h.latest(id,rows(601,800)));
    await h.start(); assert.equal(await h.H.loadOlder(),true);
    assert.equal(h.H.getState().loaded,400); assert.equal(h.H.getState().before_id,401);
    return h;
  }
  function failedTarget(h,outcome,{target=102}={}) {
    h.setHook((url,r)=>{
      if(url!=='api.php'||r.action!=='detail')return null;
      if(Number(r.conversation_id)!==target)return response(r.before_id?h.older(101,rows(201,400)):h.latest(101,rows(601,800)));
      if(outcome==='network')return Promise.reject(new Error('Synthetic target outage'));
      if(outcome==='malformed')return {...response(null),json:async()=>{throw new SyntaxError('Synthetic malformed JSON')}};
      if(outcome==='mismatched')return response(h.latest(999,rows(1,3)));
      if(outcome==='application')return response({ok:false,error:'synthetic-target'});
      return response({ok:false,error:'synthetic-target'},Number(outcome));
    });
  }
  for(const mobile of [false,true]) for(const outcome of ['500','network','403','404','application','malformed','mismatched']) {
    test(`${mobile?'mobile':'desktop'} failed target ${outcome} retains loaded history and resumes explicit paging`,async()=>{
      const h=await loadedHistory({mobile}),saved=await protectCurrent(h),generation=h.C.getOpenGeneration();
      failedTarget(h,outcome);
      assert.equal(await h.C.open(102),false);
      assert.equal(h.C.getOpenGeneration(),generation+1,'failed navigation still revokes old read ownership');
      assert.equal(h.W.S.current,101); assert.equal(h.W.S.detail,saved.detail);
      assert.deepEqual(stableHistory(h),saved.history); assert.equal(h.H.getState().paging,false);
      assert.equal(h.ids.get('conversationLoadOlder').disabled,false);
      assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft'); assert.equal(h.getFile(),saved.file);
      assert.deepEqual(h.ids.get('messages').children,saved.rows);
      assert.equal(h.readMarks.length,saved.marks,'failed target does not mark retained history read');
      assert.equal(await h.C.refreshVisible(),true);
      assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),Array.from({length:400},(_,i)=>401+i));
      assert.equal(h.H.getState().before_id,401); assert.equal(h.H.getState().has_more,true);
      const count=olderCalls(h).length,marks=h.readMarks.length;
      assert.equal(await h.H.loadOlder(),true); assert.equal(olderCalls(h).length,count+1);
      assert.equal(olderCalls(h).at(-1).before_id,401);
      assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),Array.from({length:600},(_,i)=>201+i));
      assert.equal(h.readMarks.length,marks); assert.equal(h.W.S.authExpired,false); assert.equal(hidden(h),false);
      assert.equal(h.calls.some(r=>['send','take','release','close','reopen'].includes(r.action)),false);
    });
  }

  for(const owner of ['older','refresh','card','detail']) for(const outcome of outcomes) {
    test(`failed navigation never revives the previous ${owner} read returning ${outcome}`,async()=>{
      const h=await loadedHistory(),pending=deferred(),entered=deferred();
      h.setHook((url,r)=>{
        if(r.action==='detail'&&Number(r.conversation_id)===101&&
          (owner==='card'?url==='pipeline-api.php':url==='api.php'&&(owner==='older'?!!r.before_id:!r.before_id))) {
          entered.resolve(); return pending.promise;
        }
        return null;
      });
      const old=owner==='older'?h.H.loadOlder():owner==='refresh'?h.C.refreshVisible():owner==='card'?h.C.refreshLeadData():h.C.open(101,{preserveHistory:true,preserveAttachment:true,mobileHistory:'none'});
      await entered.promise; failedTarget(h,'500'); assert.equal(await h.C.open(102),false);
      const saved=await protectCurrent(h);
      const body=owner==='older'?h.older(101,rows(201,400)):owner==='card'?{ok:true,synthetic:'obsolete card'}:h.latest(101,rows(801,1000));
      pending.resolve(outcome==='network'?Promise.reject(new Error('Synthetic obsolete outage')):response(outcome==='200'?body:{ok:false,error:'obsolete'},Number(outcome)));
      assert.equal(await old,false); assertCurrentProtected(h,saved);
      assert.equal(await h.C.refreshVisible(),true); assert.equal(h.H.getState().loaded,400);
    });
  }

  test('late older finally cannot unlock the replacement read after a failed switch',async()=>{
    const h=await loadedHistory(),oldPage=deferred(),newPage=deferred();
    h.installFetch(()=>oldPage.promise); const old=h.H.loadOlder();
    failedTarget(h,'500'); assert.equal(await h.C.open(102),false);
    h.installFetch(()=>newPage.promise,id=>h.latest(id,rows(601,800)));
    const current=h.H.loadOlder(),count=h.calls.length;
    oldPage.resolve(response({ok:false},401)); assert.equal(await old,false);
    assert.equal(h.W.S.authExpired,false); assert.equal(h.H.getState().paging,true);
    assert.equal(h.ids.get('conversationLoadOlder').disabled,true); assert.equal(h.calls.length,count);
    newPage.resolve(response(h.older(101,rows(201,400)))); assert.equal(await current,true);
    assert.equal(h.H.getState().loaded,600); assert.equal(h.H.getState().paging,false);
  });

  test('failed navigation keeps an older-page error and its explicit retry',async()=>{
    const h=await loadedHistory(); h.installFetch(()=>response({ok:false},500));
    assert.equal(await h.H.loadOlder(),false); const saved=stableHistory(h);
    assert.match(saved.error,/черновик сохранены/); failedTarget(h,'network');
    assert.equal(await h.C.open(102),false); assert.deepEqual(stableHistory(h),saved);
    assert.match(h.ids.get('conversationLoadOlder').textContent,/Повторить/);
    assert.equal(h.ids.get('conversationLoadOlder').disabled,false);
    assert.equal(await h.H.loadOlder(),true); assert.equal(h.H.getState().loaded,600);
    assert.equal(h.H.getState().error,'');
  });

  for(const empty of [false,true]) test(`failed navigation preserves ${empty?'empty':'complete'} history without offering an older read`,async()=>{
    const h=make();
    h.installFetch(()=>response(h.older(101,rows(1,200),false)),id=>h.latest(id,empty?[]:rows(201,400),!empty));
    await h.start(); if(!empty)assert.equal(await h.H.loadOlder(),true);
    const saved=stableHistory(h),count=olderCalls(h).length;
    failedTarget(h,'404'); assert.equal(await h.C.open(102),false);
    assert.deepEqual(stableHistory(h),saved); assert.equal(await h.H.loadOlder(),false);
    assert.equal(olderCalls(h).length,count); assert.equal(h.H.getState().has_more,false);
  });

  test('failed automatic gap repair survives failed navigation and resumes only on explicit retry',async()=>{
    const h=await loadedHistory();
    h.installFetch(()=>response({ok:false},500),id=>h.latest(id,rows(1001,1200)));
    assert.equal(await h.C.refreshVisible(),true);await settleRecovery();
    const saved=stableHistory(h); assert.equal(saved.has_gap,true); assert.equal(saved.before_id,1001);
    failedTarget(h,'500'); assert.equal(await h.C.open(102),false); assert.deepEqual(stableHistory(h),saved);
    h.installFetch(r=>response(h.older(101,r.before_id===1001?rows(801,1000):rows(601,800))),id=>h.latest(id,rows(1001,1200)));
    assert.equal(await h.C.refreshVisible(),true); assert.deepEqual(stableHistory(h),saved);
    const marks=h.readMarks.length;
    assert.equal(await h.H.loadOlder(),true);assert.equal(olderCalls(h).length,4);
    assert.equal(h.H.getState().has_gap,false); assert.equal(h.readMarks.length,marks);
    assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),Array.from({length:800},(_,i)=>401+i));
  });

  for(const status of [401,403,404]) test(`retained history still obeys current conversation ${status} after a failed switch`,async()=>{
    const h=await loadedHistory(); await protectCurrent(h); failedTarget(h,'500');
    assert.equal(await h.C.open(102),false);
    h.installFetch(()=>response({ok:false},status)); assert.equal(await h.H.loadOlder(),false);
    assert.equal(h.H.getState().loaded,0);
    if(status===401)assert.equal(hidden(h),true);else assert.equal(h.ids.get('replyText').disabled,true);
    assert.equal(h.W.S.authExpired,status===401);
    if(status!==401){assert.equal(h.ids.get('replyText').value,''); assert.equal(h.getFile(),null);}
  });

  test('a current target 401 clears retained history through canonical auth recovery',async()=>{
    const h=await loadedHistory(),saved=await protectCurrent(h); failedTarget(h,'401');
    assert.equal(await h.C.open(102),false); assert.equal(h.W.S.authExpired,true);
    assert.equal(h.H.getState().loaded,0); assert.equal(hidden(h),true);
    assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft'); assert.equal(h.getFile(),saved.file);
  });

  test('an account change after failed navigation cannot retain previous history or drafts',async()=>{
    const h=await loadedHistory(); await protectCurrent(h); failedTarget(h,'500');
    assert.equal(await h.C.open(102),false); h.W.showAuthRecovery();
    h.setIdentity({...h.identity,manager:{...h.identity.manager,id:8}}); h.setHook(null); await h.login();
    assert.equal(h.W.S.current,0); assert.equal(h.H.getState().loaded,0);
    assert.equal(h.ids.get('replyText').value,''); assert.equal(h.getFile(),null);
    assert.equal(h.ids.get('messages').textContent.includes('Synthetic history'),false);
  });

  test('failed first opening leaves the empty workspace without an older cursor',async()=>{
    const h=make(); await h.start({open:false}); failedTarget(h,'500');
    assert.equal(await h.C.open(102),false); assert.equal(h.W.S.current,0);
    assert.equal(h.H.getState().loaded,0); assert.equal(h.H.getState().has_more,false);
    assert.equal(await h.H.loadOlder(),false); assert.equal(hidden(h),true);
  });

  for(const status of [500,403,404]) test(`same-conversation opening ${status} keeps its existing retention or access-loss policy`,async()=>{
    const h=await loadedHistory(); await protectCurrent(h); const saved=stableHistory(h);
    failedTarget(h,String(status),{target:101}); assert.equal(await h.C.open(101),false);
    if(status===500){assert.deepEqual(stableHistory(h),saved); assert.equal(hidden(h),false);}
    else{assert.equal(h.H.getState().loaded,0); assert.equal(hidden(h),true); assert.equal(h.ids.get('replyText').value,''); assert.equal(h.getFile(),null);}
  });

  for(const status of [500,403,404]) for(const oldStatus of [200,401]) test(`failed target ${status} ends a pending client card and rejects its obsolete ${oldStatus}`,async()=>{
    const h=make(),pending=deferred(),terminal=[];
    const root=h.document.createElement('div'); root.id='leadCard'; h.ids.set('leadCard',root); h.document.body.appendChild(root);
    h.window.WorkspaceV2LeadCard={render(p,c){terminal.push(['ready',c.id]);root.textContent='Synthetic ready card';},renderUnavailable(c){terminal.push(['unavailable',c.id]);root.textContent='Synthetic explicit card retry';}};
    let cardReads=0;
    h.setHook((url,r)=>{
      if(url==='pipeline-api.php'&&r.action==='detail'&&r.conversation_id===101)return ++cardReads===1?pending.promise:response({ok:true,synthetic:'current card'});
      if(url==='api.php'&&r.action==='detail')return r.conversation_id===102?response({ok:false},status):response(r.before_id?h.older(101,rows(401,600)):h.latest(101,rows(601,800)));
      return null;
    });
    await h.start(); assert.equal(await h.H.loadOlder(),true); await h.draft('Synthetic pending card draft');
    const file={name:'synthetic-pending-card.txt'}; h.setFile(file);
    assert.match(root.querySelector('.leadPanelHint')?.textContent,/Загружаем карточку/); assert.equal(h.W.S.detail.lead,null);
    assert.equal(await h.C.open(102),false); assert.deepEqual(terminal,[['unavailable',101]]);
    const count=h.calls.length;
    pending.resolve(response(oldStatus===200?{ok:true,synthetic:'obsolete original card'}:{ok:false},oldStatus));
    for(let i=0;i<10;i++)await Promise.resolve();
    assert.equal(h.W.S.authExpired,false); assert.deepEqual(terminal,[['unavailable',101]]);
    assert.equal(h.calls.length,count,'ending loading adds no automatic read');
    assert.equal(await h.C.refreshVisible(),true); assert.equal(h.W.S.detail.lead,null);
    assert.match(root.textContent,/explicit card retry/); assert.equal(h.H.getState().loaded,400);
    assert.equal(await h.C.refreshLeadData(),true); assert.equal(h.W.S.detail.lead.synthetic,'current card');
    assert.deepEqual(terminal,[['unavailable',101],['ready',101]]); assert.equal(cardReads,2);
    assert.equal(h.ids.get('replyText').value,'Synthetic pending card draft'); assert.equal(h.getFile(),file);
    assert.equal(h.W.S.current,101); assert.equal(h.W.S.authExpired,false); assert.equal(hidden(h),false);
  });

  for(const empty of [false,true]) test(`successful target ${empty?'empty':'populated'} starts its own history after failed navigation`,async()=>{
    const h=await loadedHistory(); failedTarget(h,'500'); assert.equal(await h.C.open(102),false);
    h.installFetch(()=>{throw new Error('Unexpected older read')},id=>h.latest(id,empty?[]:rows(1,3),false));
    assert.equal(await h.C.open(102),true); assert.equal(h.W.S.current,102);
    assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),empty?[]:[1,2,3]);
    assert.equal(h.H.getState().loaded,empty?0:3); assert.equal(h.H.getState().has_more,false);
    assert.equal(await h.H.loadOlder(),false);
  });

  for(const mobile of [false,true]) for(const outcome of outcomes) test(`${mobile?'mobile Back → B':'desktop A → B'} ignores late older ${outcome}`,async()=>{
    const h=make({mobile}); await h.start();
    const pending=deferred(); h.installFetch(()=>pending.promise);
    const load=h.H.loadOlder(); assert.equal(olderCalls(h).length,1);
    if(mobile){await h.history.back(); assert.equal(h.window.WorkspaceV2Mobile.getScreen(),'inbox');}
    assert.equal(await h.C.open(102),true);
    const saved=await protectCurrent(h); settle(pending,outcome,h);
    assert.equal(await load,false); assertCurrentProtected(h,saved);
    assert.equal(olderCalls(h).length,1);
  });

  // These invalidate different canonical guards: auth generation, account,
  // open generation/refresh epoch, owner version, and a same-record gap cursor.
  for(const context of ['same-account recovery','different account','own send and reopen','same conversation reopen','history reset','new gap cursor']) {
    for(const outcome of outcomes) test(`${context} ignores late older ${outcome}`,async()=>{
      const h=make(); await h.start();
      const pending=deferred(); h.installFetch(()=>pending.promise);
      const load=h.H.loadOlder(); assert.equal(olderCalls(h).length,1);
      if(context==='same-account recovery' || context==='different account') {
        h.W.showAuthRecovery();
        h.setIdentity({...h.identity, csrf:'renewed-synthetic-csrf', manager:{...h.identity.manager,id:context==='different account'?8:7}});
        await h.login();
        if(context==='different account'){assert.equal(h.W.S.current,0); assert.equal(await h.C.open(102),true);}
      } else if(context==='own send and reopen') {
        await h.draft('Synthetic successful send'); await h.C.sendReply();
        assert.equal(h.calls.filter(r=>r.action==='send').length,1);
      } else if(context==='same conversation reopen') {
        assert.equal(await h.C.open(101,{preserveHistory:true,preserveAttachment:true,preserveMessageScroll:true,mobileHistory:'none'}),true);
      } else if(context==='history reset') {
        h.H.reset(); h.H.applyLatest(h.W.S.detail);
      } else {
        h.installFetch(()=>pending.promise,id=>h.latest(id,[message(801),message(901)]));
        assert.equal(await h.C.refreshVisible(),true);
        assert.equal(h.H.getState().has_gap,true); assert.equal(h.H.getState().before_id,801);
        // This obsolete transport must stay harmless while the newly detected
        // automatic repair is paused in the background. Active repair is covered above.
        h.document.visibilityState='hidden';
      }
      const saved=await protectCurrent(h); settle(pending,outcome,h);
      assert.equal(await load,false);if(context==='new gap cursor')await settleRecovery();assertCurrentProtected(h,saved); assert.equal(olderCalls(h).length,1);
    });
  }

  test('current older 401 still invokes canonical auth recovery and retains draft/file',async()=>{
    const h=make(); await h.start(); const saved=await protectCurrent(h);
    h.installFetch(()=>response({ok:false,error:'unauthorized'},401));
    assert.equal(await h.H.loadOlder(),false); assert.equal(h.W.S.authExpired,true); assert.equal(hidden(h),true);
    assert.equal(h.W.S.authGeneration,saved.generation+1);
    assert.equal(h.ids.get('managerAuthRecovery').classList.contains('hidden'),false);
    assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft'); assert.equal(h.getFile(),saved.file);
    assert.equal(h.H.getState().loaded,0); assert.equal(olderCalls(h).length,1);
    assert.equal(await h.H.loadOlder(),false); assert.equal(olderCalls(h).length,1);
  });

  test('stale A 401 cannot end a protected older read already pending in B',async()=>{
    const h=make(); await h.start(); const a=deferred(),b=deferred();
    h.installFetch(r=>r.conversation_id===101?a.promise:b.promise);
    const old=h.H.loadOlder(); await h.C.open(102); await h.draft('Exact current synthetic draft');
    const file={name:'synthetic-b.txt'}; h.setFile(file); const current=h.H.loadOlder();
    const count=h.calls.length,marks=h.readMarks.length;
    assert.equal(olderCalls(h).length,2); assert.equal(h.H.getState().paging,true);
    a.resolve(response({ok:false,error:'unauthorized'},401)); assert.equal(await old,false);
    assert.equal(h.W.S.authExpired,false); assert.equal(hidden(h),false); assert.equal(h.H.getState().paging,true);
    assert.equal(h.ids.get('conversationLoadOlder').disabled,true); assert.equal(h.calls.length,count);
    b.resolve(response(h.older(102,[message(401)],false))); assert.equal(await current,true);
    assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),[401,602]);
    assert.equal(h.H.getState().paging,false); assert.equal(h.readMarks.length,marks);
    assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft'); assert.equal(h.getFile(),file);
    assert.equal(h.calls.length,count);
  });

  for(const status of [403,404]) test(`current older ${status} keeps canonical access-loss lock and draft clearing`,async()=>{
    const h=make(); await h.start(); await protectCurrent(h);
    h.installFetch(()=>response({ok:false,error:'unavailable'},status));
    assert.equal(await h.H.loadOlder(),false); assert.equal(h.W.S.authExpired,false);
    assert.equal(h.ids.get('replyText').disabled,true); assert.equal(h.ids.get('sendReply').disabled,true);
    assert.equal(h.ids.get('replyText').value,''); assert.equal(h.getFile(),null);
    assert.match(h.ids.get('conversationRefreshStatus').textContent,/Доступ к диалогу изменился/);
    assert.equal(h.H.getState().loaded,0); assert.equal(await h.H.loadOlder(),false); assert.equal(olderCalls(h).length,1);
  });

  for(const failure of ['500','network']) test(`current older ${failure} preserves loaded history and offers one explicit retry`,async()=>{
    const h=make(); await h.start(); const saved=await protectCurrent(h);
    h.installFetch(()=>failure==='network'?Promise.reject(new Error('Synthetic outage')):response({ok:false},500));
    assert.equal(await h.H.loadOlder(),false); assert.equal(h.W.S.authExpired,false); assert.equal(hidden(h),false);
    assert.equal(h.W.S.detail,saved.detail); assert.equal(h.H.getState().loaded,1); assert.equal(h.H.getState().has_more,true);
    assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft'); assert.equal(h.getFile(),saved.file);
    assert.match(h.ids.get('conversationHistoryStatus').textContent,/черновик сохранены/);
    assert.match(h.ids.get('conversationLoadOlder').textContent,/Повторить/); assert.equal(h.ids.get('conversationLoadOlder').disabled,false);
    assert.equal(olderCalls(h).length,1); assert.equal(h.readMarks.length,saved.marks);
    h.installFetch(()=>response(h.older(101,[message(400)],false)));
    assert.equal(await h.H.loadOlder(),true); assert.equal(olderCalls(h).length,2); assert.equal(h.H.getState().has_more,false);
    assert.deepEqual(Array.from(h.W.S.detail.messages,m=>m.id),[400,601]);
    assert.equal(h.H.getState().error,''); assert.equal(h.readMarks.length,saved.marks);
    assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft'); assert.equal(h.getFile(),saved.file);
  });

  test('duplicate current clicks share one protected read; no-more and empty pages never auto-drain',async()=>{
    const h=make(); await h.start(); const saved=await protectCurrent(h);
    const pending=deferred(); h.installFetch(()=>pending.promise);
    const one=h.H.loadOlder(),two=h.H.loadOlder(); assert.equal(olderCalls(h).length,1);
    assert.equal(h.ids.get('conversationLoadOlder').disabled,true);
    assert.equal(olderCalls(h)[0].csrf,'synthetic-csrf'); assert.equal(olderCalls(h)[0].before_id,601);
    pending.resolve(response(h.older(101,[],false)));
    assert.equal(await one,true); assert.equal(await two,true); assert.equal(h.H.getState().has_more,false);
    assert.equal(await h.H.loadOlder(),false); assert.equal(olderCalls(h).length,1);
    assert.equal(h.W.S.authExpired,false); assert.equal(hidden(h),false); assert.equal(h.readMarks.length,saved.marks);
    assert.equal(h.ids.get('replyText').value,'Exact current synthetic draft'); assert.equal(h.getFile(),saved.file);
    assert.equal(h.calls.some(r=>['send','take','release','close','reopen'].includes(r.action)),false);
  });

  test('first-unread intent and anchor apply only to a current fresh opening',async()=>{
    const h=make(),anchors=[];h.window.WorkspaceV2Jump={capture:()=>null,restore(){},update(){},isFollowingLatest:()=>false,scrollToMessage:id=>anchors.push(id)};
    const initial=id=>{const d=h.latest(id,[message(10),message(20),message(30)]);d.history.initial_unread_id=20;return d;};
    h.installFetch(()=>{throw Error('Unexpected older request');},initial);await h.start();
    const first=h.calls.find(r=>r.action==='detail'&&!r.before_id&&r.conversation_id===101);assert.equal(first.open_unread,true);assert.deepEqual(anchors,[20]);
    await h.C.refreshVisible();assert.equal(h.calls.filter(r=>r.action==='detail'&&!r.before_id).at(-1).open_unread,undefined);assert.deepEqual(anchors,[20]);
    await h.C.open(102,{stickToBottom:true});assert.equal(h.calls.filter(r=>r.action==='detail'&&!r.before_id).at(-1).open_unread,undefined);assert.deepEqual(anchors,[20]);
  });
  test('obsolete first-unread opening cannot move the current conversation',async()=>{
    const h=make(),anchors=[],old=deferred();h.window.WorkspaceV2Jump={capture:()=>null,restore(){},update(){},isFollowingLatest:()=>false,scrollToMessage:id=>anchors.push(id)};await h.start();
    h.setHook((url,r)=>url==='api.php'&&r.action==='detail'?(r.conversation_id===102?old.promise:response(h.latest(r.conversation_id))):null);
    const pending=h.C.open(102);await h.C.open(103);const detail=h.W.S.detail;old.resolve(response({...h.latest(102,[message(10),message(20)]),history:{mode:'latest',has_more:false,before_id:10,page_size:200,initial_unread_id:20}}));await pending;
    assert.equal(h.W.S.current,103);assert.equal(h.W.S.detail,detail);assert.deepEqual(anchors,[]);assert.equal(h.W.S.authExpired,false);
  });
  let failed=0;
  for(const {name,run} of cases) {
    try {await run(); console.log('PASS '+name);} catch(error) {failed++; console.error('FAIL '+name+'\n'+error.stack);}
  }
  console.log(`TOTAL ${cases.length} | FAIL ${failed}`);
  return {total:cases.length,failed};
}

module.exports={createHistoryHarness,runSuite};
if(require.main===module) runSuite().then(({failed})=>{process.exitCode=failed?1:0;}).catch(error=>{console.error(error);process.exitCode=1;});
