'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{webcrypto}=require('node:crypto');
const source=fs.readFileSync(__dirname+'/../extensions/selection-collector/background.js','utf8');
const clone=x=>JSON.parse(JSON.stringify(x));
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
let checks=0;
function check(name,condition){assert.ok(condition,name);checks++;console.log('PASS '+name)}
async function until(fn){for(let i=0;i<200;i++){if(await fn())return;await sleep(5)}throw new Error('queue did not settle')}
const makeTarget=(conversation=11,connection='a'.repeat(32))=>({actor:7,conversation_id:conversation,binding:String(conversation).padStart(64,'0'),client:'Synthetic client '+conversation,trip:'Synthetic current trip',count:0,connection:{id:connection}});
const observation=hotel=>({adapter:'funsun-b2b-v1',offer:{hotel,amount:'200000',currency:'RUB'},raw:{price:'200000 RUB'}});

// Execute the actual MV3 worker against persistent storage and a synthetic
// canonical bridge. This verifies queue protocol, not a physical browser install.
function worker(db,server,{popupSupported=true}={}){
 let listener,alarm,nonce='',current=db.target?clone(db.target):null;
 const sender=target=>({id:'fixture-extension',url:'https://app.anytoour.ru/manager/tour-selection-collector.php?conversation_id='+target.conversation_id,tab:{id:10,url:'https://app.anytoour.ru/manager/tour-selection-collector.php?conversation_id='+target.conversation_id}});
 const popup={url:'chrome-extension://fixture-extension/popup.html'},to={url:'https://b2b.fstravel.com/search_tour?FILTER=1',tab:{id:20}};
 const send=(message,from=popup)=>new Promise(resolve=>listener(message,from,resolve));
 async function connect(target,selected=true){current=clone(target);const hello=await send({type:'bridge_hello'},sender(target));nonce=hello.nonce;return send({type:'bridge_event',event:{nonce,type:'connected',target,connection:target.connection,selected}},sender(target))}
 const chrome={runtime:{id:'fixture-extension',onMessage:{addListener(fn){listener=fn}},onStartup:{addListener(){}}},
  storage:{local:{async get(keys){return Object.fromEntries(keys.filter(k=>k in db).map(k=>[k,clone(db[k])]))},async set(value){Object.assign(db,clone(value))}}},
  action:{async setBadgeText(){},...(popupSupported?{async openPopup(){}}:{})},
  alarms:{create(){},onAlarm:{addListener(fn){alarm=fn}}},
  tabs:{async query({url}){return (Array.isArray(url)?url.some(x=>x.includes('fstravel')):url.includes('fstravel'))?[{id:20,url:to.url}]:current?[sender(current).tab]:[]},async create(){throw new Error('unexpected new tab')},async sendMessage(tab,message){
   if(tab===20)return;
   if(message.type==='bridge_reconnect'){await connect(current,false);return}
   if(message.type!=='bridge_command')throw new Error('unknown bridge message');
   const command=message.command,result=await server.receive(command);
   await send({type:'bridge_event',event:{type:'result',nonce:message.nonce,request_id:command.request_id,result}},sender(current));
  }}
 };
 vm.runInNewContext(source,{chrome,crypto:webcrypto,URL,Uint8Array,Map,Set,Promise,setTimeout,clearTimeout},{filename:'collector-background.js'});
 return {send,connect,enqueue:input=>send({type:'enqueue',observation:input},to),status:()=>send({type:'status'}),alarm:()=>alarm(),disconnect:()=>send({type:'bridge_event',event:{nonce,type:'disconnected'}},sender(current))};
}
function provider(){return {receipts:new Map(),calls:[],creates:0,mode:'',async receive(c){
 this.calls.push(clone(c));const hash=JSON.stringify([c.connection_id,c.observation,c.as_variant]),old=this.receipts.get(c.operation_id);
 if(old&&old!==hash)return{ok:false,error:'operation_mismatch'};
 if(c.action==='operation'){if(this.mode==='offline'){this.mode='';return{ok:false,error:'network_error'}}return{ok:true,found:!!old,count:this.creates}}
 if(this.mode==='price'){this.mode='';return{ok:false,error:'source_price_changed'}}
 if(!old){this.creates++;this.receipts.set(c.operation_id,hash)}
 if(this.mode==='lost'){this.mode='';return{ok:false,error:'network_error'}}
 return{ok:true,state:'added',count:this.creates,saved_version:this.creates};
}}}
async function stage(w,hotel){assert.equal((await w.enqueue(observation(hotel))).ok,true);const s=await w.status();return s.items.at(-1).operation_id}
async function itemState(w,operation,state){await until(async()=>(await w.status()).items.find(x=>x.operation_id===operation)?.state===state)}

(async()=>{
 require('node:child_process').execFileSync('python3',[__dirname+'/../extensions/selection-collector/build_package.py','--check'],{stdio:'pipe'});
 check('installable package matches actual extension sources',true);
 const manifest=JSON.parse(fs.readFileSync(__dirname+'/../extensions/selection-collector/manifest.json','utf8'));
 check('MV3 collector has no cookie credential or arbitrary-origin permissions',manifest.manifest_version===3&&manifest.permissions.join(',')==='storage,alarms'&&manifest.host_permissions.length===4&&manifest.host_permissions.every(x=>/^https:\/\/(?:b2b\.fstravel\.com\/search_tour|agent\.anextour\.ru\/search\/tour|pro\.tourvisor\.ru\/search|app\.anytoour\.ru\/manager\/tour-selection-collector\.php)\*$/.test(x)));
 const db={},server=provider();let w=worker(db,server,{popupSupported:false});
 check('foreign page cannot read client identity or enqueue',(await w.send({type:'status'},{url:'https://evil.example/'})).ok===false&&(await w.send({type:'enqueue',observation:observation('x')},{url:'https://evil.example/'})).ok===false);
 check('TO content cannot read extension target',(await w.send({type:'status'},{url:'https://b2b.fstravel.com/search_tour'})).ok===false);
 const target=makeTarget();await w.connect(target);
 const staged=await w.enqueue(observation('Synthetic A'));
 check('old Chromium without openPopup still stages without leaking client into TO response',staged.ok&&Object.keys(staged).join(',')==='ok'&&server.creates===0);
 const intent=(await w.status()).items[0].operation_id;
 check('durable intent retains explicit original target before any server create',db.items[0].target.conversation_id===11&&db.items[0].state==='destination'&&/^[a-f0-9]{32}$/.test(intent));
 w=worker(db,server);check('worker restart preserves unconfirmed queue without automatic import',(await w.status()).items[0].operation_id===intent&&server.creates===0);
 await w.send({type:'confirm',operation_id:intent});await itemState(w,intent,'saved');
 check('cold worker reconnects existing Manager bridge and reads receipt before import',server.calls.map(x=>x.action).join(',')==='operation,import'&&server.creates===1&&server.calls.every(x=>x.operation_id===intent));
 server.mode='lost';const lost=await stage(w,'Synthetic B');await w.send({type:'confirm',operation_id:lost});await itemState(w,lost,'waiting');
 check('lost response is waiting rather than false saved',server.creates===2&&db.items.find(x=>x.operation_id===lost).state==='waiting');
 w=worker(db,server);w.alarm();await itemState(w,lost,'saved');
 check('restart after committed lost response reads known receipt without second append',server.creates===2&&server.calls.filter(x=>x.operation_id===lost&&x.action==='import').length===1);
 server.mode='offline';const offline=await stage(w,'Synthetic C');await w.send({type:'confirm',operation_id:offline});await itemState(w,offline,'waiting');
 check('offline status never assumes create success',server.creates===2&&server.calls.filter(x=>x.operation_id===offline&&x.action==='import').length===0);
 await w.send({type:'retry',operation_id:offline});await itemState(w,offline,'saved');
 check('explicit retry uses same durable intent after proven missing receipt',server.creates===3&&server.calls.filter(x=>x.operation_id===offline).every(x=>x.operation_id===offline));
 server.mode='price';const price=await stage(w,'Synthetic D');await w.send({type:'confirm',operation_id:price});await itemState(w,price,'review');
 check('price review cannot be bypassed by ordinary confirm or retry',(await w.send({type:'retry',operation_id:price})).ok===false&&(await w.send({type:'confirm',operation_id:price})).ok===false);
 await w.send({type:'variant',operation_id:price});await until(async()=>(await w.status()).items.some(x=>x.observation.offer.hotel==='Synthetic D'&&x.state==='saved'));
 const variant=db.items.find(x=>x.observation.offer.hotel==='Synthetic D');
 check('explicit separate variant gets new intent and retains original observed terms',variant.operation_id!==price&&variant.as_variant===true&&variant.observation.offer.amount==='200000'&&server.creates===4);
 const pending=await stage(w,'Synthetic E');await w.connect(makeTarget(12,'b'.repeat(32)));
 check('changing destination blocks old staged rows instead of retargeting',db.items.find(x=>x.operation_id===pending).state==='blocked'&&db.items.find(x=>x.operation_id===pending).target.conversation_id===11);
 check('wrong client reconnect cannot transfer an old intent',(await w.send({type:'reconnect',operation_id:pending})).ok===false&&server.creates===4);
 await w.connect(makeTarget(11,'c'.repeat(32)));await w.send({type:'reconnect',operation_id:pending});
 await until(async()=>(await w.status()).items.some(x=>x.observation.offer.hotel==='Synthetic E'&&x.state==='saved'));
 check('explicit reconnection restores only original client and trip',db.items.find(x=>x.observation.offer.hotel==='Synthetic E').target.conversation_id===11&&server.creates===5);
 const revoke=await stage(w,'Synthetic F');await w.disconnect();
 check('revoke blocks local pending rows and clears chosen target',db.target===null&&db.items.find(x=>x.operation_id===revoke).state==='blocked');
 check('disconnected collector refuses new observations',(await w.enqueue(observation('Synthetic G'))).ok===false);
 const anexSender={url:'https://agent.anextour.ru/search/tour?ADULT=2',tab:{id:21}},anex={...observation('Synthetic ANEX'),adapter:'anex-agent-v1'};
 check('ANEX cannot read client identity or borrow FUNSUN adapter',(await w.send({type:'status'},anexSender)).ok===false&&(await w.send({type:'enqueue',observation:observation('Spoof')},anexSender)).ok===false);
 await w.connect(makeTarget());
 check('FUNSUN cannot borrow ANEX adapter',(await w.enqueue(anex)).ok===false);
 check('ANEX stages through the same durable destination queue',(await w.send({type:'enqueue',observation:anex},anexSender)).ok&&db.items.at(-1).state==='destination'&&server.creates===5);
 const anexId=db.items.at(-1).operation_id;w=worker(db,server);await w.send({type:'confirm',operation_id:anexId});await itemState(w,anexId,'saved');
 check('ANEX worker restart retains destination and uses canonical receipt then import',server.creates===6&&server.calls.filter(x=>x.operation_id===anexId).map(x=>x.action).join(',')==='operation,import');
 const tvSender={url:'https://pro.tourvisor.ru/search#tvtourid=1234567890',tab:{id:22}},tv={...observation('Synthetic TV'),adapter:'tourvisor-snapshot-v1',raw:{price:'160000 RUB',party_price_confirmation:'one_room_two_adults'}};
 check('TV content cannot read target or borrow another adapter',(await w.send({type:'status'},tvSender)).ok===false&&(await w.send({type:'enqueue',observation:anex},tvSender)).ok===false);
 check('other sources cannot enqueue TV snapshot',(await w.enqueue(tv)).ok===false);
 await w.send({type:'enqueue',observation:tv},tvSender);let tvId=db.items.at(-1).operation_id;
 check('TV content cannot self-confirm a whole-party price',db.items.at(-1).observation.raw.party_price_confirmation==='pending'&&(await w.send({type:'confirm',operation_id:tvId})).ok===false&&server.creates===6);
 await w.disconnect();await w.connect(makeTarget(11,'c'.repeat(32)));await w.send({type:'reconnect',operation_id:tvId});tvId=db.items.at(-1).operation_id;
 check('TV reconnect returns unconfirmed intent to destination review',db.items.at(-1).state==='destination'&&server.creates===6);
 w=worker(db,server);check('TV restart retains unconfirmed price basis',(await w.send({type:'confirm',operation_id:tvId,confirm_price_basis:false})).ok===false);
 await w.send({type:'confirm',operation_id:tvId,confirm_price_basis:true});await itemState(w,tvId,'saved');
 check('TV explicit price plus destination confirmation uses canonical receipt and import',server.creates===7&&server.calls.filter(x=>x.operation_id===tvId).every(x=>x.observation.raw.party_price_confirmation==='one_room_two_adults'));
 console.log('COLLECTOR WORKER TOTAL '+checks+' PASS');
})().catch(error=>{console.error(error);process.exitCode=1});
