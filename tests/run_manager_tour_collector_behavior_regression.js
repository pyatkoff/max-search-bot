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
function worker(db,server,{popupSupported=true,now=()=>Date.now(),priceEditorSupported=true,allowManagerOpen=false}={}){
 let listener,alarm,nonce='',current=db.target?clone(db.target):null;const opened=[];
 const sender=target=>({id:'fixture-extension',url:'https://app.anytoour.ru/manager/tour-selection-collector.php?conversation_id='+target.conversation_id,tab:{id:10,url:'https://app.anytoour.ru/manager/tour-selection-collector.php?conversation_id='+target.conversation_id}});
 const popup={url:'chrome-extension://fixture-extension/popup.html'},to={url:'https://b2b.fstravel.com/search_tour?FILTER=1',tab:{id:20}};
 const send=(message,from=popup)=>new Promise(resolve=>listener(message,from,resolve));
 async function connect(target,selected=true){current=clone(target);const hello=await send({type:'bridge_hello'},sender(target));nonce=hello.nonce;return send({type:'bridge_event',event:{nonce,type:'connected',target,connection:target.connection,selected,features:priceEditorSupported?{manager_price:1}:{} }},sender(target))}
 const chrome={runtime:{id:'fixture-extension',onMessage:{addListener(fn){listener=fn}},onStartup:{addListener(){}}},
  storage:{local:{async get(keys){return Object.fromEntries(keys.filter(k=>k in db).map(k=>[k,clone(db[k])]))},async set(value){Object.assign(db,clone(value))}}},
  action:{async setBadgeText(){},...(popupSupported?{async openPopup(){}}:{})},
  alarms:{create(){},onAlarm:{addListener(fn){alarm=fn}}},
  tabs:{async query({url}){return (Array.isArray(url)?url.some(x=>x.includes('fstravel')):url.includes('fstravel'))?[{id:20,url:to.url}]:current?[sender(current).tab]:[]},async create(options){if(!allowManagerOpen)throw new Error('unexpected new tab');opened.push(clone(options));return{id:99,...options}},async sendMessage(tab,message){
   if(tab===20)return;
   if(message.type==='bridge_reconnect'){await connect(current,false);return}
   if(message.type!=='bridge_command')throw new Error('unknown bridge message');
   const command=message.command,result=await server.receive(command);
   await send({type:'bridge_event',event:{type:'result',nonce:message.nonce,request_id:command.request_id,result}},sender(current));
  }}
 };
 vm.runInNewContext(source,{chrome,crypto:webcrypto,URL,Uint8Array,Map,Set,Promise,setTimeout,clearTimeout,Date:class extends Date{static now(){return now()}}},{filename:'collector-background.js'});
 return {send,connect,opened,enqueue:input=>send({type:'enqueue',observation:input},to),status:()=>send({type:'status'}),alarm:()=>alarm(),disconnect:()=>send({type:'bridge_event',event:{nonce,type:'disconnected'}},sender(current))};
}
function provider(){return {receipts:new Map(),calls:[],creates:0,mode:'',async receive(c){
 this.calls.push(clone(c));const hash=JSON.stringify([c.connection_id,c.observation,c.as_variant,c.manager_amount??null]),old=this.receipts.get(c.operation_id),ack=Object.hasOwn(c,'manager_amount')&&this.mode!=='legacy_amount'?{manager_amount:c.manager_amount}:{};
 if(old&&old.hash!==hash)return{ok:false,error:'operation_mismatch'};
 if(c.action==='operation'){if(this.mode==='offline'){this.mode='';return{ok:false,error:'network_error'}}if(this.mode==='malformed_status'){this.mode='';return{ok:true}}return{ok:true,found:!!old,count:this.creates,saved_version:old?.version??null,...ack}}
 if(this.mode==='price'){this.mode='';return{ok:false,error:'source_price_changed'}}
 if(this.mode==='manual_conflict'){this.mode='';return{ok:false,error:'manual_price_conflict'}}
 if(!old){this.creates++;this.receipts.set(c.operation_id,{hash,version:this.creates})}
 if(this.mode==='lost'){this.mode='';return{ok:false,error:'network_error'}}
 if(this.mode==='malformed_import'){this.mode='';return{ok:true,state:'added'}}
 return{ok:true,state:'added',count:this.creates,saved_version:this.creates,...ack};
}}}
async function stage(w,hotel){assert.equal((await w.enqueue(observation(hotel))).ok,true);const s=await w.status();return s.items.at(-1).operation_id}
async function itemState(w,operation,state){await until(async()=>(await w.status()).items.find(x=>x.operation_id===operation)?.state===state)}

(async()=>{
 require('node:child_process').execFileSync('python3',[__dirname+'/../extensions/selection-collector/build_package.py','--check'],{stdio:'pipe'});
 check('installable package matches actual extension sources',true);
 const manifest=JSON.parse(fs.readFileSync(__dirname+'/../extensions/selection-collector/manifest.json','utf8'));

 {
 const photoFixture=(file)=>{const context={window:{},URL,Set};vm.runInNewContext(fs.readFileSync(__dirname+'/../extensions/selection-collector/'+file,'utf8'),context);return context.window};
 const fun=photoFixture('funsun.js').AnyTourFunsun,anex=photoFixture('anex.js').AnyTourAnex,tv=photoFixture('tourvisor.js').AnyTourTourvisor;
 const base='https://apigate-tui.fstravel.com/api/geocontent/static/Hotel/00180000-ac11-0242-6c05-08d9934f9e4b/';
 const funPhotos=['MainPhoto','Gallery','InsideOfTheRoom'].map((x,i)=>base+x+'-Source-photo'+i+'.jpg');
 const doc=(pathname,urls)=>({location:new URL('https://b2b.fstravel.com'+pathname),querySelectorAll:()=>urls.map(src=>({getAttribute:()=>src}))});
 check('FUNSUN automatically selects three real gallery slots from its exact hotel page',JSON.stringify(fun.photos(doc('/hotel/10',funPhotos),'10'))===JSON.stringify(funPhotos));
 check('FUNSUN gallery never follows a similarly named different hotel',fun.photos(doc('/hotel/11',funPhotos),'10').length===0);
 check('FUNSUN gallery excludes logos and tracking source URLs',fun.photos(doc('/hotel/10',['https://b2b.fstravel.com/logo.svg',funPhotos[0]+'?token=secret']),'10').length===0);
 check('FUNSUN refuses mixed gallery UUIDs',fun.photos(doc('/hotel/10',[funPhotos[0],funPhotos[1].replace('00180000','00280000')]),'10').length===0);
 check('one available hotel image stays one image instead of duplicates',fun.photos(doc('/hotel/10',[funPhotos[0],funPhotos[0]]),'10').length===1);
 const partnerKeys={hotel:'-10',partner:'HBK'},partnerPhotos=[101,102,103].map(i=>'https://hbpro.expert/resource/hotel/photo_1200/11/22/'+i+'.jpg');
 check('partner gallery retains its signed hotel and exact partner URL',JSON.stringify(fun.photos(doc('/hotel/-10?partner=HBK',partnerPhotos),partnerKeys))===JSON.stringify(partnerPhotos));
 check('partner gallery rejects lost or changed partner identity',fun.photos(doc('/hotel/-10',partnerPhotos),partnerKeys).length===0&&fun.photos(doc('/hotel/-11?partner=HBK',partnerPhotos),partnerKeys).length===0&&fun.photos(doc('/hotel/-10?partner=EVIL',partnerPhotos),partnerKeys).length===0);
 check('partner and native photo grammars cannot borrow each other',fun.photos(doc('/hotel/-10?partner=HBK',funPhotos),partnerKeys).length===0&&fun.photos(doc('/hotel/10',partnerPhotos),'10').length===0);
 check('partner gallery refuses mixed hotel prefixes and secret query values',fun.photos(doc('/hotel/-10?partner=HBK',[partnerPhotos[0],partnerPhotos[1].replace('/11/22/','/11/23/')]),partnerKeys).length===0&&fun.photos(doc('/hotel/-10?partner=HBK',[partnerPhotos[0]+'?token=secret']),partnerKeys).length===0);
 const canonicalDoc=(name,href='https://b2b.fstravel.com/hotel/europe/turkey/synthetic-hotel')=>({location:new URL(href),querySelectorAll:s=>s==='h2'?[{innerText:name,getClientRects:()=>[{}]}]:funPhotos.map(src=>({getAttribute:()=>src}))});
 check('numeric native hotel navigation may use its matching canonical page',fun.photos(canonicalDoc('Синтетический отель'),'10','Синтетический отель').length===3);
 check('canonical gallery refuses wrong header origin and another numeric ID',fun.photos(canonicalDoc('Чужой отель'),'10','Синтетический отель').length===0&&fun.photos(canonicalDoc('Синтетический отель','https://evil.example/hotel/europe/turkey/synthetic-hotel'),'10','Синтетический отель').length===0&&fun.photos(canonicalDoc('Синтетический отель','https://b2b.fstravel.com/hotel/11'),'10','Синтетический отель').length===0);
 const anexUrl='https://agent.anextour.ru/search/tour?HOTEL_INC=100&TAB_ACTIVE=description';
 const anexPhotos=[1,2,3].map(i=>'https://files.anextour.ru/hotel/hotels/turkey/synthetic/o'+i+'?&hotelCode=100');
 const adoc=(href,urls)=>({location:new URL(href),querySelectorAll:()=>urls.map(src=>({getAttribute:()=>src}))});
 check('ANEX gallery requires its exact native hotel code',anex.photos(adoc(anexUrl,anexPhotos),'100').length===3&&anex.photos(adoc(anexUrl,anexPhotos),'101').length===0);
 check('ANEX refuses a mixed hotel image and credentials',anex.photos(adoc(anexUrl,[anexPhotos[0].replace('hotelCode=100','hotelCode=101'),anexPhotos[0]+'&token=secret']),'100').length===0);
 check('ANEX does not copy placeholders or duplicate photos',anex.photos(adoc(anexUrl,[anexPhotos[0],anexPhotos[0],'data:image/gif;base64,AA']),'100').length===1);
 const tvPhotos=[1,2,3].map(i=>'https://static.tourvisor.ru/hotel_pics/verybig/1/502-'+i+'.jpg');
 const tcard=urls=>({querySelectorAll:()=>urls.map(src=>({style:{backgroundImage:'url("'+src+'")'}}))});
 check('Tourvisor gallery is bound to source country and hotel image prefix',tv.photos(tcard(tvPhotos),'502','1').length===3&&tv.photos(tcard(tvPhotos),'503','1').length===0&&tv.photos(tcard(tvPhotos),'502','2').length===0);
 check('Tourvisor excludes operator logos unrelated hotels and query tokens',tv.photos(tcard(['https://tourvisor.ru/pics/operators/mobilelogo/13.svg',tvPhotos[0]+'?token=secret',tvPhotos[1].replace('/502-','/503-')]),'502','1').length===0);
 }
 check('MV3 collector has no cookie credential or arbitrary-origin permissions',manifest.manifest_version===3&&manifest.permissions.join(',')==='storage,alarms'&&manifest.host_permissions.length===4&&manifest.host_permissions.every(x=>/^https:\/\/(?:b2b\.fstravel\.com\/search_tour|agent\.anextour\.ru\/search\/tour|pro\.tourvisor\.ru\/search|app\.anytoour\.ru\/manager\/tour-selection-collector\.php)\*$/.test(x)));
 const navDb={target:makeTarget(),items:[{operation_id:'1'.repeat(32),state:'saved',target:makeTarget(12,'b'.repeat(32)),observation:observation('Previous client')}]},navServer=provider(),nav=worker(navDb,navServer,{allowManagerOpen:true});
 await nav.send({type:'open_manager',target:clone(navDb.target)});
 check('collector opens the exact active selection instead of the generic inbox',nav.opened.at(-1)?.url==='https://app.anytoour.ru/manager2/?selection=11&collector_actor=7#conversation=11');
 const oldTarget=clone(navDb.target);navDb.target=makeTarget(13,'c'.repeat(32));const beforeNav=nav.opened.length;
 check('stale active-selection click cannot navigate to a replacement client',(await nav.send({type:'open_manager',target:oldTarget})).ok===false&&nav.opened.length===beforeNav);
 await nav.send({type:'open_manager',operation_id:'1'.repeat(32),target:makeTarget(12,'b'.repeat(32))});
 check('saved-row selection remains bound to its original client',nav.opened.at(-1)?.url==='https://app.anytoour.ru/manager2/?selection=12&collector_actor=7#conversation=12');
 check('removed queue row cannot become a different selection',(await nav.send({type:'open_manager',operation_id:'2'.repeat(32),target:oldTarget})).ok===false);
 check('TO source cannot open a client selection',(await nav.send({type:'open_manager',target:navDb.target},{url:'https://b2b.fstravel.com/search_tour',tab:{id:20}})).ok===false);
 await nav.send({type:'open_manager'});check('unconnected legacy popup keeps the ordinary Manager2 entry',nav.opened.at(-1)?.url==='https://app.anytoour.ru/manager2/'&&navServer.creates===0&&navServer.calls.length===0);
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

 const directDb={},directServer=provider(),funsunSender={url:'https://b2b.fstravel.com/search_tour?FILTER=1',tab:{id:20},documentId:'source-document',frameId:0};let clock=1000;
 let direct=worker(directDb,directServer,{popupSupported:false,now:()=>clock});
 check('one click cannot begin before explicit client trip connection',(await direct.send({type:'begin_add'},funsunSender)).ok===false);
 await direct.connect(makeTarget());
 const begin=()=>direct.send({type:'begin_add'},funsunSender);
 const enqueue=(ticket,hotel='Direct')=>direct.send({type:'enqueue',ticket,observation:observation(hotel)},funsunSender);
 const read=operation=>direct.send({type:'add_status',operation_id:operation},funsunSender);
 check('foreign page and subframe cannot obtain an add ticket',(await direct.send({type:'begin_add'},{url:'https://evil.example/',tab:{id:20}})).ok===false&&(await direct.send({type:'begin_add'},{...funsunSender,frameId:2})).ok===false);
 const ticket=await begin();
 check('click ticket reveals no client trip or connection',ticket.ok&&Object.keys(ticket).sort().join(',')==='ok,ticket'&&/^[a-f0-9]{32}$/.test(ticket.ticket));
 const receive=directServer.receive.bind(directServer);directServer.receive=async command=>{assert.ok(directDb.items.some(x=>x.operation_id===command.operation_id&&x.state==='queued'),'intent durable before canonical bridge call');return receive(command)};
 const added=await enqueue(ticket.ticket);await itemState(direct,added.operation_id,'saved');
 check('one source click saves without a popup confirmation or popup API',added.ok&&added.state==='queued'&&directServer.creates===1&&directServer.calls.map(x=>x.action).join(',')==='operation,import');
 const own=await read(added.operation_id);
 check('source receives only its operation state',own.ok&&own.state==='saved'&&Object.keys(own).sort().join(',')==='error,ok,state');
 check('another tab document or source cannot read that operation',(await direct.send({type:'add_status',operation_id:added.operation_id},{...funsunSender,tab:{id:99}})).ok===false&&(await direct.send({type:'add_status',operation_id:added.operation_id},{...funsunSender,documentId:'other-document'})).ok===false&&(await direct.send({type:'add_status',operation_id:added.operation_id},anexSender)).ok===false);
 check('a consumed click ticket cannot append another intent',(await enqueue(ticket.ticket)).ok===false&&directDb.items.length===1);
 let stale=await begin();await direct.connect(makeTarget(12,'b'.repeat(32)));
 check('client change during extraction refuses rather than retargets',(await enqueue(stale.ticket)).ok===false&&directDb.items.length===1&&directServer.creates===1);
 check('old saved operation is not presented as saved for the new target',(await read(added.operation_id)).state==='blocked');
 await direct.connect(makeTarget());stale=await begin();await direct.connect({...makeTarget(),binding:'f'.repeat(64)});
 check('changed trip binding rejects the captured click',(await enqueue(stale.ticket)).ok===false&&directDb.items.length===1);
 await direct.connect(makeTarget());stale=await begin();await direct.connect(makeTarget(11,'c'.repeat(32)));
 check('same client with replacement connection rejects old click',(await enqueue(stale.ticket)).ok===false&&directDb.items.length===1);
 stale=await begin();await direct.disconnect();check('revocation before enqueue cannot import',(await enqueue(stale.ticket)).ok===false&&directServer.creates===1);
 await direct.connect(makeTarget());stale=await begin();clock+=60001;
 check('expired capture cannot authorize a later append',(await enqueue(stale.ticket)).ok===false&&directDb.items.length===1);
 stale=await begin();check('another source document cannot consume a ticket',(await direct.send({type:'enqueue',ticket:stale.ticket,observation:observation('Wrong document')},{...funsunSender,documentId:'other-document'})).ok===false);
 stale=await begin();direct=worker(directDb,directServer,{now:()=>clock});
 check('worker restart does not revive a non-durable click ticket',(await enqueue(stale.ticket)).ok===false&&directDb.items.length===1);
 await direct.connect(makeTarget());directServer.mode='lost';let command=await enqueue((await begin()).ticket,'Lost direct');await itemState(direct,command.operation_id,'waiting');
 check('lost direct save does not report success',(await read(command.operation_id)).state==='waiting'&&directServer.creates===2);
 direct=worker(directDb,directServer,{now:()=>clock});direct.alarm();await itemState(direct,command.operation_id,'saved');
 check('durable one click recovery reads receipt without a second append',directServer.creates===2&&directServer.calls.filter(x=>x.operation_id===command.operation_id&&x.action==='import').length===1);
 directServer.mode='malformed_status';command=await enqueue((await begin()).ticket,'Bad receipt');await itemState(direct,command.operation_id,'waiting');
 check('malformed status cannot become saved or authorize append',(await read(command.operation_id)).state==='waiting'&&directServer.calls.filter(x=>x.operation_id===command.operation_id&&x.action==='import').length===0);
 directServer.mode='malformed_import';direct.alarm();await until(()=>directServer.creates===3);await itemState(direct,command.operation_id,'waiting');
 check('positive import without saved revision remains unconfirmed',(await read(command.operation_id)).state==='waiting'&&directServer.creates===3);
 direct.alarm();await itemState(direct,command.operation_id,'saved');check('malformed import recovery uses known saved receipt',directServer.creates===3);
 directServer.mode='price';command=await enqueue((await begin()).ticket,'Changed price');await itemState(direct,command.operation_id,'review');
 check('direct add preserves mandatory changed price review',(await read(command.operation_id)).state==='review'&&directServer.creates===3);
 const anexTicket=await direct.send({type:'begin_add'},anexSender),anexDirect=await direct.send({type:'enqueue',ticket:anexTicket.ticket,observation:anex},anexSender);await itemState(direct,anexDirect.operation_id,'saved');
 check('ANEX direct add uses the same canonical queue',directServer.creates===4);
 const tvTicket=await direct.send({type:'begin_add'},tvSender),tvDirect=await direct.send({type:'enqueue',ticket:tvTicket.ticket,observation:tv},tvSender);
 check('click capture cannot bypass unresolved TV price basis',tvDirect.ok&&tvDirect.state==='destination'&&directDb.items.at(-1).observation.raw.party_price_confirmation==='pending'&&directServer.creates===4);
 const money=require('../extensions/selection-collector/price-adjustment.js');
 check('price calculator retains cents and locale input without float drift',money.total('195\u00a0432,10')==='195432.10'&&money.adjust('237017','discount','2','percent')==='232276.66'&&money.adjust('237017','markup','2','percent')==='241757.34');
 check('fixed amount half-cent rounding and rounding to thousands are explicit',money.adjust('237017','discount','12.50','amount')==='237004.50'&&money.adjust('1.25','discount','2','percent')==='1.22'&&money.adjust('200999.99','markup','0','amount','thousand')==='201000');
 for(const bad of ['0','-1','1e3','1+2','12.345','1000000000','NaN']){let rejected=false;try{money.total(bad)}catch{rejected=true}check('invalid manual amount '+bad+' is not a calculation',rejected)}
 for(const args of [['200000','discount','100','percent'],['999999999.99','markup','0.01','amount'],['200000','discount','1','percent','unexpected']]){let rejected=false;try{money.adjust(...args)}catch{rejected=true}check('invalid or overflowing adjustment cannot become an amount',rejected)}

 const editDb={},editServer=provider();let editor=worker(editDb,editServer,{popupSupported:false});await editor.connect(makeTarget());
 const editReceive=editServer.receive.bind(editServer);editServer.receive=async c=>{assert.ok(editDb.items.some(x=>x.operation_id===c.operation_id&&x.state==='queued'&&x.manager_amount===c.manager_amount),'adjusted intent durable before bridge lookup/create');return editReceive(c)};
 const capture=async(input=observation('Before add'),sender=funsunSender)=>{const t=await editor.send({type:'begin_add'},sender);return editor.send({type:'enqueue_edit',ticket:t.ticket,observation:input,manager_amount:'1'},sender)};
 check('source cannot open an editor without a captured destination ticket',(await editor.send({type:'enqueue_edit',observation:observation('No ticket')},funsunSender)).ok===false);
 let editing=await capture();let editId=editing.operation_id;
 check('pencil durably stages the source without import or source-supplied manual price',editing.ok&&editing.state==='editing'&&editServer.calls.length===0&&!Object.hasOwn(editDb.items[0],'manager_amount')&&editDb.items[0].observation.offer.amount==='200000');
 const draft={amount:'195432.10',action:'discount',value:'2.5',basis:'percent',round:'none',price_basis:false};
 check('third party source cannot write the private editor or confirm an import',(await editor.send({type:'edit_draft',operation_id:editId,draft},funsunSender)).ok===false&&(await editor.send({type:'save_edit',operation_id:editId,manager_amount:'195432.10'},funsunSender)).ok===false);
 await editor.send({type:'edit_draft',operation_id:editId,draft});editor=worker(editDb,editServer,{popupSupported:false});editor.alarm();await sleep(20);
 check('popup close and worker restart preserve editable input without provider calls',editDb.items[0].edit_draft.amount==='195432.10'&&editDb.items[0].state==='editing'&&editServer.calls.length===0);
 await editor.send({type:'remove',operation_id:editId});await editor.connect(makeTarget(12,'b'.repeat(32)));editor.alarm();await sleep(20);
 check('cancelled pre-add edit stays cancelled across destination changes and cannot import',editDb.items[0].state==='cancelled'&&editServer.creates===0&&(await editor.send({type:'add_status',operation_id:editId},funsunSender)).state==='cancelled'&&(await editor.send({type:'save_edit',operation_id:editId,manager_amount:'195432.10'})).ok===false);
 await editor.connect(makeTarget());editing=await capture();editId=editing.operation_id;await editor.send({type:'edit_draft',operation_id:editId,draft});await editor.connect(makeTarget(12,'b'.repeat(32)));
 check('client switch blocks editing instead of moving its adjusted price',(await editor.send({type:'save_edit',operation_id:editId,manager_amount:'195432.10'})).ok===false&&editServer.creates===0);
 await editor.connect(makeTarget(11,'c'.repeat(32)));await editor.send({type:'reconnect',operation_id:editId});const restored=editDb.items.find(x=>x.state==='editing');editId=restored.operation_id;
 check('reconnect preserves pre-add fields and still needs explicit Save',restored.edit_draft.amount==='195432.10'&&restored.needs_edit_confirmation&&editServer.creates===0);
 for(const amount of ['',null,0,'0','-1','1e3','12.345','1000000000'])check('worker rejects invalid final amount '+String(amount),(await editor.send({type:'save_edit',operation_id:editId,manager_amount:amount})).ok===false&&editServer.creates===0);
 editServer.mode='lost';await editor.send({type:'save_edit',operation_id:editId,manager_amount:'195432.10'});await itemState(editor,editId,'waiting');
 check('adjusted intent is durable before create and sends original and manual amounts separately',editDb.items.find(x=>x.operation_id===editId).manager_amount==='195432.10'&&editServer.calls.filter(x=>x.operation_id===editId).every(x=>x.manager_amount==='195432.10'&&x.observation.offer.amount==='200000')&&editServer.creates===1);
 check('UNKNOWN adjusted import cannot be edited or resubmitted as another price',(await editor.send({type:'edit_draft',operation_id:editId,draft:{...draft,amount:'180000'}})).ok===false&&(await editor.send({type:'save_edit',operation_id:editId,manager_amount:'180000'})).ok===false);
 editor=worker(editDb,editServer);editor.alarm();await itemState(editor,editId,'saved');
 check('lost adjusted reply recovers the same hash and does not append again',editServer.creates===1&&editServer.calls.filter(x=>x.operation_id===editId&&x.action==='import').length===1);
 let editTV=await capture(tv,tvSender);const tvEditId=editTV.operation_id;
 check('pencil cannot bypass TV room price basis',(await editor.send({type:'save_edit',operation_id:tvEditId,manager_amount:'150000',confirm_price_basis:false})).ok===false&&editDb.items.at(-1).state==='editing'&&editServer.creates===1);
 await editor.send({type:'save_edit',operation_id:tvEditId,manager_amount:'150000',confirm_price_basis:true});await itemState(editor,tvEditId,'saved');
 check('TV adjusted price keeps explicit basis and original source amount',editServer.calls.filter(x=>x.operation_id===tvEditId).every(x=>x.manager_amount==='150000'&&x.observation.offer.amount==='200000'&&x.observation.raw.party_price_confirmation==='one_room_two_adults')&&editServer.creates===2);
 editServer.mode='manual_conflict';const conflict=await capture();await editor.send({type:'save_edit',operation_id:conflict.operation_id,manager_amount:'190000'});await itemState(editor,conflict.operation_id,'review');
 check('existing manual-price conflict cannot be converted into a duplicate variant',(await editor.send({type:'variant',operation_id:conflict.operation_id})).ok===false&&editServer.creates===2);
 const legacyDb={},legacyServer=provider();let legacy=worker(legacyDb,legacyServer,{priceEditorSupported:false});await legacy.connect(makeTarget());let t=await legacy.send({type:'begin_add'},funsunSender),stagedEdit=await legacy.send({type:'enqueue_edit',ticket:t.ticket,observation:observation('Old bridge')},funsunSender);await legacy.send({type:'save_edit',operation_id:stagedEdit.operation_id,manager_amount:'190000'});await itemState(legacy,stagedEdit.operation_id,'waiting');
 check('already open old bridge cannot silently discard an adjusted amount',legacyServer.calls.length===0&&legacyDb.items[0].error==='bridge_update_required');
 legacy=worker(legacyDb,legacyServer);legacy.alarm();await itemState(legacy,stagedEdit.operation_id,'saved');check('updated bridge resumes the same adjusted intent without retargeting',legacyServer.creates===1&&legacyServer.calls.every(c=>c.operation_id===stagedEdit.operation_id&&c.manager_amount==='190000'));
 legacyServer.mode='legacy_amount';t=await legacy.send({type:'begin_add'},funsunSender);stagedEdit=await legacy.send({type:'enqueue_edit',ticket:t.ticket,observation:observation('Old backend')},funsunSender);await legacy.send({type:'save_edit',operation_id:stagedEdit.operation_id,manager_amount:'180000'});await itemState(legacy,stagedEdit.operation_id,'waiting');
 check('canonical lookup must acknowledge the final amount before any append',legacyServer.calls.filter(c=>c.operation_id===stagedEdit.operation_id).every(c=>c.action==='operation')&&legacyServer.creates===1);
 legacyServer.mode='';legacy.alarm();await itemState(legacy,stagedEdit.operation_id,'saved');check('canonical amount acknowledgement permits exact recovery once',legacyServer.creates===2&&legacyServer.calls.filter(c=>c.operation_id===stagedEdit.operation_id&&c.action==='import').length===1);
 console.log('COLLECTOR WORKER TOTAL '+checks+' PASS');
})().catch(error=>{console.error(error);process.exitCode=1});
