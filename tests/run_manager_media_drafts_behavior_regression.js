'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),crypto=require('node:crypto'),{File,Blob}=require('node:buffer');
const source=fs.readFileSync(__dirname+'/../manager/assets/workspace-v2-media-drafts.js','utf8'),PREFIX='workspaceV2.mediaDrafts.v1.',TTL=86400000,MAX=100*1024*1024;
const tick=()=>new Promise(resolve=>setImmediate(resolve));
// Native browser transactions are covered separately. This fixture serializes
// transactions and deliberately separates request success from atomic commit.
function engine(){
 const tables=new Map(),queue=[],control={hold:false,abortNext:false,denyOpen:false,abortBeforeAdd:false,eventErrors:[],held:[],transactions:0};
 const clone=map=>new Map(Array.from(map,([k,v])=>[k,{...v}]));
 function transaction(names,mode){
  names=Array.isArray(names)?names:[names];const tx={pending:0,started:false,done:false,operations:[],working:null,oncomplete:null,onabort:null,onerror:null};control.transactions++;
  tx.abort=()=>{if(tx.done)throw Error('InvalidStateError: transaction already finished');finish(false)};
  function finish(ok){if(tx.done)return;tx.done=true;if(ok&&mode==='readwrite')for(const n of names)tables.set(n,tx.working.get(n));(ok?tx.oncomplete:tx.onabort)?.();queue.shift();queue[0]?.start()}
  function drain(){setImmediate(()=>{if(tx.done||tx.pending)return;if(control.abortNext){control.abortNext=false;finish(false)}else if(control.hold&&mode==='readwrite')control.held.push(()=>finish(true));else finish(true)})}
  function run(operation){setImmediate(()=>{if(tx.done)return;try{operation.fn()}catch(e){operation.request.error=e;operation.request.onerror?.();finish(false);return}try{operation.request.onsuccess?.({target:operation.request})}catch(e){control.eventErrors.push(e);finish(false);return}tx.pending--;drain()})}
  function request(fn){const r={onsuccess:null,onerror:null,result:undefined};const op={request:r,fn:()=>{r.result=fn()}};tx.pending++;if(tx.started)run(op);else tx.operations.push(op);return r}
  tx.objectStore=name=>{
   if(!names.includes(name))throw Error('store not scoped');
   const table=()=>tx.working.get(name);
   return{get:key=>request(()=>table().get(key)),getAll:()=>request(()=>Array.from(table().values())),add:value=>{if(control.abortBeforeAdd){control.abortBeforeAdd=false;tx.abort();throw Error('QuotaExceededError')}return request(()=>{if(table().has(value.id))throw Error('duplicate immutable key');table().set(value.id,{...value});return value.id})},delete:key=>request(()=>{table().delete(key)})};
  };
  tx.start=()=>{if(tx.started||tx.done)return;tx.started=true;tx.working=new Map(names.map(n=>[n,clone(tables.get(n)||new Map())]));for(const op of tx.operations)run(op);drain()};
  queue.push(tx);if(queue.length===1)tx.start();return tx;
 }
 const db={objectStoreNames:{contains:n=>tables.has(n)},createObjectStore:n=>tables.set(n,new Map()),transaction,close(){}};
 const indexedDB={open(){const r={};setImmediate(()=>{if(control.denyOpen){r.onerror?.();return}r.result=db;if(!tables.has('blobs'))r.onupgradeneeded?.();r.onsuccess?.()});return r}};
 return{tables,indexedDB,control,release(){control.hold=false;for(const done of control.held.splice(0))done()}};
}
function harness({db=engine(),session=new Map(),actor=7,time=Date.now()}={}){
 const clock={now:time},S={manager:{id:actor},authGeneration:1,authExpired:false,current:11};let quota=false;
 class ClockDate extends Date{static now(){return clock.now}}
 const storage={getItem:k=>session.get(k)??null,setItem(k,v){if(quota)throw Error('quota');session.set(k,v)},removeItem:k=>session.delete(k)};
 const window={WorkspaceV2:{S},File,crypto:crypto.webcrypto,indexedDB:db.indexedDB,sessionStorage:storage};
 vm.runInNewContext(source,{window,File,Blob,Uint8Array,Date:ClockDate,setTimeout,clearTimeout});
 return{api:window.WorkspaceV2MediaDrafts,db,session,S,clock,quota:()=>{quota=true},manifest:()=>JSON.parse(session.get(PREFIX+actor)||'null')};
}
const row=(name,text='Bytes for '+name)=>({file:new File([text],name,{type:'text/plain',lastModified:123}),status:'ready'}),id=n=>n.toString(16).padStart(32,'0');
const cases=[];const test=(name,run)=>cases.push({name,run});
test('actual File bytes metadata order and caption survive a new module context',async()=>{
 const h=harness(),rows=[row('first.txt','first bytes'),row('second.txt','second bytes')],cap={batch:id(10),caption:'Caption stays once'};rows[1].status='unknown';rows[1].batchId=id(10);rows[1].recoveryId=id(11);
 assert.equal(await h.api.save(7,11,rows,cap),true);assert.match(rows[0].blobId,/^[a-f0-9]{32}$/);for(const record of h.db.tables.get('blobs').values()){assert.equal(record.format,'arraybuffer-v1');assert.equal(Object.prototype.toString.call(record.bytes),'[object ArrayBuffer]');assert.equal(record.blob,undefined)}
 const next=harness({db:h.db,session:h.session}),loaded=await next.api.load(7,11);assert.equal(loaded.missing,false);assert.deepEqual(Array.from(loaded.rows,r=>r.file.name),['first.txt','second.txt']);assert.equal(await loaded.rows[0].file.text(),'first bytes');assert.equal(await loaded.rows[1].file.text(),'second bytes');assert.equal(loaded.rows[1].recoveryId,id(11));assert.equal(loaded.rows[1].status,'unknown');assert.equal(loaded.rows[1].file.lastModified,123);assert.equal(loaded.rows[0].file.type,'text/plain');assert.equal(loaded.captionSent.caption,cap.caption);
});
test('request success never claims persistence before transaction complete',async()=>{
 const h=harness();h.db.control.hold=true;let settled=false;const rows=[row('pending')],pending=h.api.save(7,11,rows).then(v=>{settled=true;return v});for(let i=0;i<8;i++)await tick();assert.equal(settled,false);assert.equal(h.manifest().conversations[11].incomplete,true);assert.equal(h.manifest().conversations[11].rows.length,0);h.db.release();assert.equal(await pending,true);assert.equal(h.manifest().conversations[11].incomplete,undefined);
});
test('an aborted byte transaction preserves RAM and never claims saved bytes',async()=>{
 const h=harness(),r=row('abort');h.db.control.abortNext=true;assert.equal(await h.api.save(7,11,[r]),false);assert.equal(await r.file.text(),'Bytes for abort');assert.equal(h.db.tables.get('blobs').size,0);assert.equal(h.manifest().conversations[11].incomplete,true);
});
test('an already aborted quota transaction is handled without a second abort exception',async()=>{
 const h=harness(),r=row('quota abort');h.db.control.abortBeforeAdd=true;assert.equal(await h.api.save(7,11,[r]),false);assert.equal(h.db.control.eventErrors.length,0);assert.equal(h.db.tables.get('blobs').size,0);assert.equal(await r.file.text(),'Bytes for quota abort');
});
test('forget fences a late add from resurrecting a cleared selection',async()=>{
 const h=harness();h.db.control.hold=true;const pending=h.api.save(7,11,[row('old')]);for(let i=0;i<8;i++)await tick();assert.equal(h.api.forget(7,11),true);h.db.release();assert.equal(await pending,false);assert.equal(h.api.has(7,11),false);
});
test('a newer selection wins when an older byte write finishes later',async()=>{
 const h=harness();h.db.control.hold=true;const a=h.api.save(7,11,[row('old')]);for(let i=0;i<8;i++)await tick();const b=h.api.save(7,11,[row('new')]);h.db.release();assert.equal(await a,false);assert.equal(await b,true);assert.equal((await h.api.load(7,11)).rows[0].file.name,'new');
});
test('removing stored A while new B is pending invalidates old membership synchronously',async()=>{
 const h=harness(),a=row('A'),b=row('B');assert.equal(await h.api.save(7,11,[a]),true);h.db.control.hold=true;
 const add=h.api.save(7,11,[a,b]);for(let i=0;i<8;i++)await tick();const remove=h.api.save(7,11,[b]);assert.equal(h.manifest().conversations[11].rows.length,0);assert.equal(h.manifest().conversations[11].incomplete,true);
 const restored=harness({db:h.db,session:h.session});h.db.release();assert.equal(await add,false);assert.equal(await remove,true);assert.deepEqual(Array.from((await restored.api.load(7,11)).rows,r=>r.file.name),['B']);
});
test('actor reset and auth generation fence every late manifest commit',async()=>{
 for(const reset of [h=>h.api.resetActor(7),h=>{h.S.authGeneration++}]){const h=harness();h.db.control.hold=true;const p=h.api.save(7,11,[row('old actor')]);for(let i=0;i<8;i++)await tick();reset(h);h.db.release();assert.equal(await p,false);assert.equal(h.manifest()?.conversations?.[11]?.rows.length||0,0)}
});
test('same-metadata File replacement cannot substitute bytes during an async save',async()=>{
 const h=harness();h.db.control.hold=true;const r=row('same','AAAA'),p=h.api.save(7,11,[r]);for(let i=0;i<8;i++)await tick();r.file=new File(['BBBB'],'same',{type:'text/plain',lastModified:123});h.db.release();assert.equal(await p,false);assert.equal(h.manifest().conversations[11].rows.length,0);
});
test('operation and batch checkpoint commits synchronously after bytes are durable',async()=>{
 const h=harness(),r=row('operation');assert.equal(await h.api.save(7,11,[r]),true);r.batchId=id(20);r.recoveryId=id(21);r.status='unknown';assert.equal(h.api.checkpoint(7,11,[r]),true);const d=h.manifest().conversations[11].rows[0];assert.equal(d.recoveryId,id(21));assert.equal(d.batchId,id(20));assert.equal(d.status,'unknown');
});
test('session quota failure invalidates stale membership and leaves bytes in RAM',async()=>{
 const h=harness(),a=row('A'),b=row('B');assert.equal(await h.api.save(7,11,[a]),true);h.quota();assert.equal(await h.api.save(7,11,[b]),false);assert.equal(h.session.has(PREFIX+7),false);assert.equal(await b.file.text(),'Bytes for B');
});
test('known-only quota failure cannot resurrect explicitly removed membership',async()=>{
 const h=harness(),a=row('removed-A'),b=row('kept-B');assert.equal(await h.api.save(7,11,[a,b]),true);h.quota();assert.equal(await h.api.save(7,11,[b]),false);const fresh=harness({db:h.db,session:h.session});assert.equal((await fresh.api.load(7,11)).rows.length,0);assert.equal(await b.file.text(),'Bytes for kept-B');
});
test('forget under a storage exception invalidates the stale actor pointer',async()=>{
 const h=harness();assert.equal(await h.api.save(7,11,[row('remove')]),true);h.quota();assert.equal(h.api.forget(7,11),false);assert.equal(h.session.has(PREFIX+7),false);assert.equal((await harness({db:h.db,session:h.session}).api.load(7,11)).rows.length,0);
});
test('same-name new rows have distinct immutable identities and selection order',async()=>{
 const h=harness(),rows=[row('same','1111'),row('same','2222')];assert.equal(await h.api.save(7,11,rows),true);assert.notEqual(rows[0].blobId,rows[1].blobId);const loaded=await h.api.load(7,11);assert.equal(await loaded.rows[0].file.text(),'1111');assert.equal(await loaded.rows[1].file.text(),'2222');
});
test('missing blob and forged cross-actor reference never return another actor bytes',async()=>{
 const h=harness(),rows=[row('first'),row('second')];assert.equal(await h.api.save(7,11,rows),true);h.db.tables.get('blobs').delete(rows[0].blobId);const loaded=await h.api.load(7,11);assert.equal(loaded.missing,true);assert.equal(loaded.rows.length,1);
 const m=h.manifest();m.actor=8;h.session.set(PREFIX+8,JSON.stringify(m));const other=harness({db:h.db,session:h.session,actor:8});const secret=await other.api.load(8,11);assert.equal(secret.missing,true);assert.equal(secret.rows.length,0);
});
test('metadata saves never extend the descriptor beyond its immutable byte expiry',async()=>{
 const h=harness(),rows=[row('expires')];assert.equal(await h.api.save(7,11,rows),true);const expiry=h.manifest().conversations[11].expiresAt;h.clock.now+=TTL-1000;assert.equal(h.api.checkpoint(7,11,rows),true);assert.equal(h.manifest().conversations[11].expiresAt,expiry);h.clock.now+=1001;assert.equal(h.api.checkpoint(7,11,rows),false);assert.equal(await h.api.save(7,11,rows),true);assert.ok(h.manifest().conversations[11].expiresAt>expiry);
});
test('global byte quota and selection bounds reject without evicting live references',async()=>{
 const h=harness();await h.api.save(7,11,[row('seed')]);const seed=h.db.tables.get('usage').values().next().value;seed.size=MAX-2;const r=row('too much');assert.equal(await h.api.save(7,12,[r]),false);assert.ok(h.db.tables.get('usage').has(seed.id));assert.equal(await h.api.save(7,11,Array.from({length:11},(_,i)=>row('x'+i))),false);
 const many=harness();for(let cid=1;cid<=20;cid++)assert.equal(await many.api.save(7,cid,[row('chat'+cid)]),true);assert.equal(await many.api.save(7,21,[row('extra')]),false);assert.equal(Object.keys(many.manifest().conversations).length,20);
});
test('combined selection capacity is rejected before reading any file bytes',async()=>{
 const h=harness();let reads=0;const big=name=>({file:{name,size:MAX/2+1,type:'application/octet-stream',lastModified:1,slice(){},arrayBuffer(){reads++;throw Error('oversize must not be read')}},status:'ready'});assert.equal(await h.api.save(7,11,[big('one'),big('two')]),false);assert.equal(reads,0);assert.equal(h.db.control.transactions,0);
});
test('two windows cannot exceed the global byte budget through concurrent writes',async()=>{
 const a=harness();assert.equal(await a.api.save(7,11,[row('budget-seed','x')]),true);a.db.tables.get('usage').values().next().value.size=MAX-10;const b=harness({db:a.db,actor:8});const results=await Promise.all([a.api.save(7,12,[row('a','123456')]),b.api.save(8,12,[row('b','654321')])]);assert.equal(results.filter(Boolean).length,1);assert.equal(a.db.tables.get('usage').size,2);
});
test('cloned tab descriptors do not share a mutable selection owner',async()=>{
 const a=harness(),rows=[row('shared')];assert.equal(await a.api.save(7,11,rows),true);const b=harness({db:a.db,session:new Map(a.session)});assert.equal(a.api.forget(7,11),true);assert.equal((await b.api.load(7,11)).rows[0].file.name,'shared');assert.equal(a.api.has(7,11),false);
});
test('unavailable storage and invalid manifests resolve honestly without throwing',async()=>{
 const h=harness();h.db.control.denyOpen=true;assert.equal(await h.api.save(7,11,[row('denied')]),false);assert.equal(await h.api.load(7,11),null);h.session.set(PREFIX+7,'invalid JSON');assert.equal(await h.api.load(7,11),null);assert.equal(await h.api.save(7,11,[row('keep')]),false);
});
(async()=>{let passed=0;for(const {name,run}of cases){await run();passed++;console.log('PASS '+name)}console.log('TOTAL '+passed+' | FAIL 0')})().catch(e=>{console.error(e);process.exitCode=1});
