const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),crypto=require('node:crypto');
const source=['workspace-v2-media-recovery.js','workspace-v2-media.js'].map(f=>fs.readFileSync(__dirname+'/../manager/assets/'+f,'utf8')).join('\n');
function harness(store=new Map()){
 const events={},requests=[],pending=[],reads=[],controls={},input={files:[],value:'',addEventListener(){}},box={innerHTML:'',textContent:'',classList:{add(){},remove(){}},querySelector:k=>controls[k]||(controls[k]={})};let quota=false,readResult={ok:true,attempt:{kind:'media',state:'unknown'}};
 const S={manager:{id:7},authGeneration:1,current:11,detail:{conversation:{id:11,channel:'telegram'}}},W={S,api:async(action,data)=>{reads.push({action,...data});return readResult}};
 const window={addEventListener:(name,fn)=>{events[name]=fn},WorkspaceV2:W,crypto:crypto.webcrypto,localStorage:{get length(){return store.size},key:i=>[...store.keys()][i],getItem:k=>store.get(k)||null,setItem(k,v){if(quota)throw Error('quota');store.set(k,v)},removeItem:k=>store.delete(k)}};
 class FormData{constructor(){this.values=new Map()}append(k,v){this.values.set(k,v)}}
 vm.runInNewContext(source,{window,document:{getElementById:id=>id==='replyFile'?input:box},FormData,URL,fetch:(url,options)=>{requests.push(options.body.values);return new Promise((resolve,reject)=>pending.push({resolve,reject}))}});
 const media=window.WorkspaceV2Media,recovery=window.WorkspaceV2MediaRecovery;media.configure('csrf',11);
 return {window,S,media,recovery,requests,reads,store,storageEvent:r=>events.storage?.({key:'workspaceV2.mediaResults.v1.'+r.actor+'.'+r.operation_id,newValue:JSON.stringify(r)}),quota:()=>{quota=true},read:value=>{readResult=value},async ack(state='confirmed',extra={}){const req=requests.at(-1),body={ok:state==='confirmed',attempt:{kind:'media',state,...(state==='confirmed'?{media_type:'file',caption_included:!!req.get('caption')}:{})},...extra};pending.shift().resolve({status:200,json:async()=>body});await new Promise(r=>setImmediate(r))},async fail(){pending.shift().reject(Error('lost'));await new Promise(r=>setImmediate(r))}};
}
const file=name=>({name,size:100,type:'text/plain',lastModified:17});
const cases=[];const test=(name,run)=>cases.push({name,run});
test('each multipart request has an immutable durable fence before dispatch',async()=>{
 const h=harness();h.media.addFiles([file('one')]);const flight=h.media.send('caption'),r=h.recovery.entries()[0];assert.equal(r.state,'processing');assert.equal(h.requests[0].get('expected_manager_id'),'7');assert.equal(h.requests[0].get('operation_id'),r.operation_id);assert.equal(r.caption,'caption');assert.equal(r.file.name,'one');assert.equal(JSON.stringify(r).includes('filebytes'),false);await h.ack();assert.equal((await flight).ok,true);assert.equal(h.recovery.entries()[0].state,'confirmed');
});
test('two confirmed files and one unknown survive reload; checking never uploads or restores File objects',async()=>{
 const h=harness();h.media.addFiles([file('one'),file('two'),file('three')]);const flight=h.media.send('caption');await h.ack();await h.ack();await h.fail();assert.equal((await flight).confirmed_count,2);const h2=harness(h.store);assert.equal(h2.media.hasFile(),false);assert.deepEqual(Array.from(h2.recovery.entries(),r=>r.state),['confirmed','confirmed','unknown']);const r=h2.recovery.entries().find(r=>r.state==='unknown');h2.read({ok:true,attempt:{kind:'media',state:'confirmed',media_type:'file',caption_included:false}});await h2.recovery.check(r.operation_id);assert.equal(h2.requests.length,0);assert.equal(h2.reads[0].action,'media_receipt');assert.equal(h2.reads[0].expected_manager_id,7);assert.equal(h2.recovery.entries().find(x=>x.operation_id===r.operation_id).state,'confirmed');
});
test('not found remains fenced until an explicit cancel tombstone; a fresh send gets a new ID',async()=>{
 const h=harness();h.media.addFiles([file('one')]);const flight=h.media.send('caption');await h.fail();await flight;const old=h.recovery.entries()[0];h.read({ok:true,attempt:{kind:'media',state:'not_found'}});await h.recovery.check(old.operation_id);assert.equal((await h.media.send('caption')).error,'media_unknown');assert.equal(h.requests.length,1);h.read({ok:false,attempt:{kind:'media',state:'cancelled'}});await h.recovery.check(old.operation_id,true);const retry=h.media.send('caption');assert.notEqual(h.requests[1].get('operation_id'),old.operation_id);await h.ack();assert.equal((await retry).ok,true);
});
test('storage failure prevents every outbound byte',async()=>{const h=harness();h.media.addFiles([file('one')]);h.quota();assert.equal((await h.media.send('caption')).error,'media_recovery_unavailable');assert.equal(h.requests.length,0)});
test('quota failure after provider acknowledgement retains the unresolved fence for read-only recovery',async()=>{
 const h=harness();h.media.addFiles([file('confirmed-but-not-saved')]);const flight=h.media.send('caption');h.quota();await h.ack();assert.equal((await flight).unknown,true);assert.equal(h.recovery.entries()[0].state,'processing');assert.equal((await h.media.send('caption')).error,'media_unknown');assert.equal(h.requests.length,1);
});
test('removal, logout and a new page retain the unknown barrier without binding a new row to the old ID',async()=>{
 const h=harness();h.media.addFiles([file('one')]);const flight=h.media.send();await h.fail();await flight;h.media.resetDrafts();const h2=harness(h.store);h2.media.addFiles([file('one')]);assert.equal((await h2.media.send()).error,'media_unknown');assert.equal(h2.requests.length,0);assert.equal(h2.recovery.entries().length,1);
});
test('two windows retain separate operations and late unknown cannot downgrade confirmed',async()=>{
 const a=harness(),b=harness(a.store);a.media.addFiles([file('a')]);b.media.addFiles([file('b')]);const pa=a.media.send(),pb=b.media.send();await a.ack();await b.ack();await pa;await pb;assert.equal(a.recovery.entries().length,2);const r=a.recovery.entries()[0];a.recovery.result(r,{ok:true,attempt:{kind:'media',state:'unknown'}});assert.equal(a.recovery.entries().find(x=>x.operation_id===r.operation_id).state,'confirmed');
});
test('a terminal proof survives an actual pending-read / terminal-write / pending-write interleave',async()=>{
 const shared=new Map(),a=harness(shared),b=harness(shared),record=a.recovery.begin(file('raced'),'');const primary=[...shared.keys()][0],immutable=shared.get(primary),get=shared.get.bind(shared);let once=true;
 shared.get=function(k){const captured=get(k);if(once&&k===primary+'.terminal'){once=false;b.recovery.result(record,{ok:true,attempt:{kind:'media',state:'confirmed',media_type:'file',caption_included:false}})}return captured};
 a.recovery.result(record,{ok:true,attempt:{kind:'media',state:'unknown'}});assert.equal(a.recovery.entries()[0].state,'confirmed');assert.equal(shared.get(primary),immutable);const proof=JSON.parse(shared.get(primary+'.terminal'));assert.equal(proof.caption,undefined);assert.equal(proof.file,undefined);assert.equal(a.requests.length+b.requests.length,0);
});
test('a stale pending writer cannot resurrect a primary pruned after terminal confirmation',async()=>{
 const shared=new Map(),a=harness(shared),b=harness(shared),record=a.recovery.begin(file('old-race'),'');const primary=[...shared.keys()][0];
 for(let i=0;i<100;i++){const r=b.recovery.begin(file('completed-'+i),'');b.recovery.result(r,{ok:true,attempt:{kind:'media',state:'confirmed',media_type:'file',caption_included:false}})}
 const get=shared.get.bind(shared);let once=true;
 shared.get=function(k){const captured=get(k);if(once&&k===primary+'.terminal'){once=false;b.recovery.result(record,{ok:true,attempt:{kind:'media',state:'confirmed',media_type:'file',caption_included:false}});b.recovery.begin(file('pruner'),'')}return captured};
 a.recovery.result(record,{ok:true,attempt:{kind:'media',state:'unknown'}});assert.equal(shared.has(primary),false);assert.equal(a.recovery.entries().some(r=>r.operation_id===record.operation_id),false);b.recovery.begin(file('cleaner'),'');assert.equal(shared.has(primary+'.pending'),false);assert.equal(shared.has(primary+'.terminal'),false);assert.equal(a.requests.length+b.requests.length,0);
});
test('a terminal result from another window settles only the exact original row',async()=>{
 const a=harness();a.media.addFiles([file('original')]);const flight=a.media.send();await a.fail();await flight;
 const b=harness(a.store),record=b.recovery.entries()[0],saved=b.recovery.result(record,{ok:true,attempt:{kind:'media',state:'confirmed',media_type:'file',caption_included:false}});
 a.storageEvent(saved);assert.equal(a.media.hasFile(),false);a.media.addFiles([file('new')]);a.storageEvent(saved);assert.equal(a.media.hasFile(),true);
});
test('old account acknowledgement updates only its original journal and cannot remove new files',async()=>{
 const h=harness();h.media.addFiles([file('old')]);const flight=h.media.send('caption');h.S.manager.id=8;h.S.authGeneration++;h.media.resetDrafts();h.media.configure('new',11);h.media.addFiles([file('new')]);await h.ack();assert.equal((await flight).error,'stale_session');assert.equal(h.media.hasFile(),true);assert.equal(h.recovery.entries().length,0);h.S.manager.id=7;h.S.authGeneration++;h.media.configure('back',11);assert.equal(h.recovery.entries()[0].state,'confirmed');
});
test('a new selection in the same conversation survives the original success',async()=>{
 const h=harness();h.media.addFiles([file('old')]);const flight=h.media.send();h.media.clear();h.media.addFiles([file('new')]);await h.ack();await flight;assert.equal(h.media.hasFile(),true);
});
test('one new caption is sent once for a mixed remaining and newly added selection',async()=>{
 const h=harness();h.media.addFiles([file('one'),file('two')]);const first=h.media.send('old caption');await h.ack();await h.ack('rejected');await first;h.media.addFiles([file('three')]);const second=h.media.send('new caption');assert.equal(h.requests[2].get('caption'),'new caption');await h.ack();assert.equal(h.requests[3].get('caption'),'');await h.ack();assert.equal((await second).ok,true);
});
for(const attempt of [{kind:'media',state:'confirmed'},{kind:'media',state:'confirmed',media_type:'other',caption_included:true},{kind:'media',state:'confirmed',media_type:'file',caption_included:false},{kind:'text',state:'confirmed',media_type:'file',caption_included:true}])test('malformed confirmed descriptor remains unknown: '+JSON.stringify(attempt),async()=>{
 const h=harness();h.media.addFiles([file('one'),file('two')]);const flight=h.media.send('caption');await h.ack('confirmed',{attempt});assert.equal((await flight).unknown,true);assert.equal(h.requests.length,1);assert.equal(h.recovery.entries()[0].state,'unknown');
});
test('a malformed acknowledgement without typed receipt cannot authorize another upload',async()=>{const h=harness();h.media.addFiles([file('one'),file('two')]);const f=h.media.send();await h.ack('confirmed',{attempt:undefined});assert.equal((await f).unknown,true);assert.equal(h.requests.length,1)});
test('a confirmed media result replaces only its correlated old warning without consuming the draft',async()=>{
 const {createHarness}=require('./run_manager_reauth_composer_behavior_regression'),h=createHarness();await h.start();await h.draft('Keep this newer draft');const selected=file('still-selected');h.setFile(selected);const operation='a'.repeat(32);
 h.window.WorkspaceV2Media.send=async()=>({ok:false,error:'media_unknown',error_message:'Результат неизвестен; повтор заблокирован.',media_operation_id:operation});await h.C.sendReply();assert.match(h.ids.get('replyStatus').textContent,/заблокирован/);
 assert.equal(h.C.settleMediaFeedback({actor:7,conversation_id:101,operation_id:operation,state:'confirmed'}),true);assert.match(h.ids.get('replyStatus').textContent,/Отправка файла подтверждена/);assert.equal(h.ids.get('replyText').value,'Keep this newer draft');assert.equal(h.getFile(),selected);
});
test('an older media result cannot replace a newer media warning or a newer text-send result',async()=>{
 const {createHarness}=require('./run_manager_reauth_composer_behavior_regression'),h=createHarness();await h.start();await h.draft('Caption');h.setFile(file('selected'));let operation='a'.repeat(32);
 h.window.WorkspaceV2Media.send=async()=>({ok:false,error:'media_unknown',error_message:operation,media_operation_id:operation});await h.C.sendReply();operation='b'.repeat(32);await h.C.sendReply();const newer=h.ids.get('replyStatus').textContent;
 assert.equal(h.C.settleMediaFeedback({actor:7,conversation_id:101,operation_id:'a'.repeat(32),state:'confirmed'}),false);assert.equal(h.ids.get('replyStatus').textContent,newer);
 h.setFile(null);h.setHook((url,r)=>r.action==='send'?{ok:true,status:200,json:async()=>({ok:true,attempt:{state:'confirmed'}})}:null);await h.draft('New text');await h.C.sendReply();assert.match(h.ids.get('replyStatus').textContent,/Отправлено/);const sent=h.ids.get('replyStatus').textContent;
 assert.equal(h.C.settleMediaFeedback({actor:7,conversation_id:101,operation_id:operation,state:'confirmed'}),false);assert.equal(h.ids.get('replyStatus').textContent,sent);
});
test('an old media result cannot paint another open conversation',async()=>{
 const {createHarness}=require('./run_manager_reauth_composer_behavior_regression'),h=createHarness();await h.start();await h.draft('A');h.setFile(file('selected'));const operation='c'.repeat(32);h.window.WorkspaceV2Media.send=async()=>({ok:false,error:'media_unknown',media_operation_id:operation});await h.C.sendReply();await h.C.open(102);await h.draft('B');const before=h.ids.get('replyStatus').textContent;assert.equal(h.C.settleMediaFeedback({actor:7,conversation_id:101,operation_id:operation,state:'confirmed'}),false);assert.equal(h.ids.get('replyText').value,'B');assert.equal(h.ids.get('replyStatus').textContent,before);
});
test('same-account authorized recovery settles a cached unknown row confirmed while authentication was expired',async()=>{
 const h=harness();Object.assign(h.S.detail.conversation,{manager_id:7,status:'manager'});h.media.addFiles([file('original')]);const flight=h.media.send('caption');await h.fail();await flight;
 const record=h.recovery.entries()[0];h.S.authExpired=true;
 const other=harness(h.store),confirmed=other.recovery.result(record,{ok:true,attempt:{kind:'media',state:'confirmed',media_type:'file',caption_included:true}});h.storageEvent(confirmed);assert.equal(h.media.hasFile(),true);
 h.S.authExpired=false;h.S.authGeneration++;h.media.configure('recovered',11);assert.equal(h.media.reconcileDraft(11),true);assert.equal(h.media.hasFile(),false);assert.equal(h.requests.length,1);
 h.media.addFiles([file('new')]);h.media.reconcileDraft(11);assert.equal(h.media.hasFile(),true);assert.equal(h.requests.length,1);
});
(async()=>{let failed=0;for(const c of cases)try{await c.run();console.log('PASS '+c.name)}catch(e){failed++;console.error('FAIL '+c.name,e)}console.log('TOTAL '+cases.length+' | FAIL '+failed);if(failed)process.exitCode=1})()
