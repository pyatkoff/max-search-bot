const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

// Execute the production upload owner with synthetic files and deferred HTTP only.
// No uploads or manager actions are sent to a real server.
function harness() {
  let file = null, resolve, recoveries = 0;
  const requests = [], response = new Promise(done => { resolve = done; });
  const input = {get files(){return file ? [file] : []}, set value(value){if(value==='')file=null}, addEventListener(){}};
  const box = {textContent:'', classList:{add(){},remove(){}}};
  const W = {S:{authGeneration:1}, showAuthRecovery(){recoveries++; W.S.authGeneration++;}};
  const window = {WorkspaceV2:W};
  class FormData {constructor(){this.values=new Map()} append(key,value){this.values.set(key,value)}}
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../manager/assets/workspace-v2-media.js'),'utf8'), {
    window, document:{getElementById:id=>id==='replyFile'?input:box}, FormData,
    fetch:(url,options)=>{requests.push({url,...options}); return response;}
  });
  const media=window.WorkspaceV2Media; media.configure('synthetic-csrf',101);
  return {media,W,requests,select(value){file=value},file:()=>file,recoveries:()=>recoveries,
    finish(status=200,body={ok:true}){resolve({status,ok:status>=200&&status<300,json:async()=>body})}};
}
const cases=[];function test(name,run){cases.push({name,run})}

test('a completed upload cannot clear a new attachment selected in another conversation',async()=>{
  const h=harness(),first={name:'first.png'},next={name:'next.png'};h.select(first);
  const pending=h.media.send('Synthetic caption');h.media.configure('synthetic-csrf',102);h.media.clear();h.select(next);h.finish();
  const result=await pending;assert.equal(result.conversation_id,101);assert.equal(h.file(),next);
  assert.equal(h.requests[0].body.values.get('file'),first);assert.equal(h.requests[0].body.values.get('conversation_id'),'101');
});
test('replacing a pending attachment in the same conversation preserves the replacement',async()=>{
  const h=harness();h.select({name:'first.png'});const pending=h.media.send();h.media.clear();const next={name:'next.png'};h.select(next);h.finish();await pending;assert.equal(h.file(),next);
});
test('the current successful upload clears exactly its own selection',async()=>{
  const h=harness();h.select({name:'current.png'});const pending=h.media.send('Exact caption');h.finish();assert.equal((await pending).ok,true);assert.equal(h.file(),null);
  assert.equal(h.requests[0].body.values.get('caption'),'Exact caption');assert.equal(h.requests[0].body.values.get('csrf'),'synthetic-csrf');assert.equal(h.requests[0].credentials,'same-origin');
});
test('a failed current upload retains the file for an explicit retry',async()=>{
  const h=harness(),file={name:'current.png'};h.select(file);const pending=h.media.send();h.finish(500,{ok:false,error:'synthetic_failure'});assert.equal((await pending).ok,false);assert.equal(h.file(),file);
});
test('current-session unauthorized upload invokes the existing sign-in recovery once',async()=>{
  const h=harness(),file={name:'current.png'};h.select(file);const pending=h.media.send();h.finish(401,{ok:false});const result=await pending;assert.equal(result.error,'unauthorized');assert.equal(h.recoveries(),1);assert.equal(h.file(),file);
});
for(const status of [200,401])test('an old-session '+status+' upload response cannot affect a recovered session',async()=>{
  const h=harness();h.select({name:'old.png'});const pending=h.media.send();h.W.S.authGeneration+=2;h.media.configure('new-synthetic-csrf',101);h.media.clear();const next={name:'new.png'};h.select(next);h.finish(status,{ok:status===200});
  const result=await pending;assert.equal(result.ok,false);assert.equal(result.error,'stale_session');assert.equal(h.recoveries(),0);assert.equal(h.file(),next);
});
test('duplicate submit while upload is pending still makes only one request',async()=>{
  const h=harness();h.select({name:'current.png'});const pending=h.media.send();assert.equal((await h.media.send()).error,'media_not_ready');assert.equal(h.requests.length,1);h.finish();await pending;
});
test('conversation refresh after send preserves a replacement attachment too',async()=>{
  const {createHarness,deferred}=require('./run_manager_reauth_composer_behavior_regression');
  const h=createHarness(),sent=deferred();await h.start();h.setFile({name:'first.png'});
  h.window.WorkspaceV2Media.send=()=>sent.promise;
  const pending=h.C.sendReply(),next={name:'replacement.png'};h.setFile(next);sent.resolve({ok:true});await pending;
  assert.equal(h.getFile(),next);assert.equal(h.W.S.current,101);
});

(async()=>{let failed=0;for(const item of cases){try{await item.run();console.log('PASS '+item.name)}catch(e){failed++;console.error('FAIL '+item.name+'\n'+e.stack)}}console.log(`TOTAL ${cases.length} | FAIL ${failed}`);process.exitCode=failed?1:0})();
