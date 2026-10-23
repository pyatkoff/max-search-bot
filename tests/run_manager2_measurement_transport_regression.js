'use strict';
// Execute the real measurement transport with only its browser shell registrar
// replaced. No browser, application request, model or provider is invoked.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=path.resolve(__dirname,'../tools/fixtures/manager2_browser_measurement.js');
let checks=0;
async function fixture(journey='reply'){
 let handler;
 const page={async route(pattern,callback){assert.equal(pattern,'**/manager/*.php');handler=callback}};
 const module={exports:{}};
 const context={module,exports:module.exports,Buffer,URL,structuredClone,require(name){
  if(name==='../../tests/visual/fixtures/personal-workspace')return {origin:'http://127.0.0.1:4173',async setup(p,options){await options.beforeNavigate(p);return {unexpected:[],errors:[]}}};
  return require(name);
 }};
 vm.runInNewContext(fs.readFileSync(source,'utf8'),context,{filename:source});
 const api=module.exports,state=await api.setup(page,{journey});
 async function request(endpoint,body,origin='http://127.0.0.1:4173'){
  let result;
  await handler({request:()=>({url:()=>origin+'/manager/'+endpoint,method:()=> 'POST',postDataJSON:()=>body}),
   async abort(reason){result={aborted:true,reason}},async fulfill(value){result=JSON.parse(value.body)},async fallback(){throw Error('Unexpected fallback')}});
  return result;
 }
 return {...api,state,request};
}
async function check(name,run){await run();checks++;console.log('PASS '+name)}
const operation='a'.repeat(32),csrf='fixture-csrf';
const send=f=>({action:'send',csrf,conversation_id:11,operation_id:operation,text:f.editedAiText});
const receipt=()=>({action:'send_receipt',csrf,conversation_id:11,operation_id:operation});
async function main(){
 await check('workspace still refuses send and model generation',async()=>{const f=await fixture('workspace');assert.equal((await f.request('api.php',send(f))).aborted,true);assert.equal((await f.request('ai-draft.php',{action:'generate',csrf,conversation_id:11,latest_message_id:600})).aborted,true);assert.equal(f.state.attempts.size,0)});
 await check('external send aborts before synthetic state changes',async()=>{const f=await fixture();assert.equal((await f.request('api.php',send(f),'https://app.anytoour.ru')).aborted,true);assert.equal(f.state.documents.size,0);assert.equal(f.state.attempts.size,0)});
 await check('unplanned mutations abort',async()=>{const f=await fixture();assert.equal((await f.request('api.php',{action:'assign',csrf,conversation_id:11})).aborted,true);assert.equal(f.state.attempts.size,0)});
 for(const [name,change] of [['CSRF',{csrf:'wrong'}],['client',{conversation_id:12}],['text',{text:'unexpected'}],['operation',{operation_id:'bad'}],['prepared provenance',{ai_draft_id:'d'.repeat(32)}]]){
  await check('invalid '+name+' cannot dispatch',async()=>{const f=await fixture();await assert.rejects(f.request('api.php',{...send(f),...change}));assert.equal(f.state.attempts.size,0);assert.equal(f.state.documents.size,0)});
 }
 await check('prepared send confirms one message and receipt does not append',async()=>{const f=await fixture();assert.equal((await f.request('api.php',send(f))).attempt.state,'confirmed');assert.equal(f.state.documents.get(11).length,601);assert.equal((await f.request('api.php',receipt())).attempt.state,'confirmed');assert.equal(f.state.documents.get(11).length,601);await assert.rejects(f.request('api.php',send(f)));assert.equal(f.state.documents.get(11).length,601)});
 await check('missing receipt never confirms',async()=>{const f=await fixture();await assert.rejects(f.request('api.php',receipt()));assert.equal(f.state.attempts.size,0);assert.equal(f.state.documents.size,0)});
 await check('lost acknowledgement reconciles original operation exactly once',async()=>{const f=await fixture();f.state.replyMode('generated');const body={...send(f),ai_draft_id:'d'.repeat(32)};assert.equal((await f.request('api.php',body)).aborted,true);assert.equal(f.state.attempts.size,1);assert.equal(f.state.documents.get(11).length,601);assert.equal((await f.request('api.php',receipt())).attempt.state,'confirmed');assert.equal(f.state.documents.get(11).length,601);assert.equal(f.state.transport[0].operation_id,f.state.transport[1].operation_id);await assert.rejects(f.request('api.php',body))});
 await check('generated send requires validated provenance',async()=>{const f=await fixture();f.state.replyMode('generated');await assert.rejects(f.request('api.php',send(f)));assert.equal(f.state.attempts.size,0)});
 await check('AI validates latest message, revision and generated draft identity',async()=>{const f=await fixture();f.state.replyMode('generated');const body={action:'validate',csrf,conversation_id:11,latest_message_id:600,revision:'1'.repeat(64),draft_id:'d'.repeat(32)};assert.equal((await f.request('ai-draft.php',body)).draft_id,body.draft_id);for(const change of [{latest_message_id:599},{revision:'wrong'},{draft_id:'e'.repeat(32)},{conversation_id:9999}])await assert.rejects(f.request('ai-draft.php',{...body,...change}));assert.equal(f.state.attempts.size,0)});
 console.log('MANAGER2_MEASUREMENT_TRANSPORT '+checks+'/'+checks+' PASS; real requests/sends/model calls=0');
}
main().catch(error=>{console.error(error);process.exitCode=1});
