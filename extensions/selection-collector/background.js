'use strict';
const bridgeOrigin='https://app.anytoour.ru',bridgePath='/manager/tour-selection-collector.php';
const id=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
const bridges=new Map(),requests=new Map(),bridgeReady=new Set(),addTickets=new Map();let serial=Promise.resolve(),processing=false,inFlight='';
const exclusive=fn=>{const p=serial.then(fn,fn);serial=p.catch(()=>{});return p};
function kind(sender){try{const u=new URL(sender.url||sender.tab?.url||'');if(u.origin===bridgeOrigin&&u.pathname===bridgePath)return'bridge';if(u.origin==='https://b2b.fstravel.com'&&u.pathname==='/search_tour')return'funsun';if(u.origin==='https://agent.anextour.ru'&&u.pathname==='/search/tour')return'anex';if(u.origin==='https://pro.tourvisor.ru'&&u.pathname==='/search')return'tourvisor';if(u.protocol==='chrome-extension:'&&u.host===chrome.runtime.id)return'popup'}catch{}return''}
async function state(){const s=await chrome.storage.local.get(['device','target','items']);if(!s.device){s.device=id();await chrome.storage.local.set({device:s.device})}return {...s,items:Array.isArray(s.items)?s.items:[]}}
function same(a,b){return a&&b&&a.actor===b.actor&&a.conversation_id===b.conversation_id&&a.binding===b.binding&&a.connection?.id===b.connection?.id}
const sourceRoles=['funsun','anex','tourvisor'];
function sourceOwner(role,sender){return Number.isInteger(sender.tab?.id)&&sender.tab.id>=0&&(!Number.isInteger(sender.frameId)||sender.frameId===0)?{role,tab:sender.tab.id,document:sender.documentId||''}:null}
function ownsSource(owner,role,sender){const current=sourceOwner(role,sender);return !!owner&&!!current&&owner.role===current.role&&owner.tab===current.tab&&owner.document===current.document}
function rotateOperation(item){if(item.source&&!item.source_operation_id)item.source_operation_id=item.operation_id;item.operation_id=id()}
function pruneTickets(){for(const [key,ticket]of addTickets)if(Date.now()-ticket.at>60000)addTickets.delete(key);while(addTickets.size>=64)addTickets.delete(addTickets.keys().next().value)}
async function announce(){for(const tab of await chrome.tabs.query({url:['https://b2b.fstravel.com/search_tour*','https://agent.anextour.ru/search/tour*','https://pro.tourvisor.ru/search*']}))void chrome.tabs.sendMessage(tab.id,{type:'target_changed'}).catch(()=>{});await chrome.action.setBadgeText({text:String((await state()).items.filter(x=>!['saved','cancelled'].includes(x.state)).length||'')})}
async function openEditor(){try{if(typeof chrome.action.openPopup==='function')await chrome.action.openPopup()}catch{}}
async function relay(item,action,reconnect=true){
 const s=await state();if(!same(s.target,item.target))return{ok:false,error:'connection_changed'};
 const entry=Array.from(bridges.entries()).find(([,b])=>same(b.target,item.target));
 if(!entry){
  if(!reconnect)return{ok:false,error:'bridge_unavailable'};
  const tabs=await chrome.tabs.query({url:bridgeOrigin+bridgePath+'*'}),u=bridgeOrigin+bridgePath+'?conversation_id='+item.target.conversation_id,tab=tabs.find(t=>t.url===u);
  if(Array.from(bridges.values()).some(b=>same(b.target,item.target)))return relay(item,action,false);
  // Recovery is passive: a closed or redirected bridge is not permission to
  // open another tab. Keep the original durable operation waiting instead.
  if(!tab)return{ok:false,error:'bridge_unavailable'};
  let notify;const ready=new Promise(resolve=>{const timer=setTimeout(()=>{bridgeReady.delete(notify);resolve(false)},8000);notify=target=>{if(!same(target,item.target))return;clearTimeout(timer);bridgeReady.delete(notify);resolve(true)};bridgeReady.add(notify)});
  void chrome.tabs.sendMessage(tab.id,{type:'bridge_reconnect'}).catch(()=>{});
  return await ready?relay(item,action,false):{ok:false,error:'bridge_unavailable'};
 }
 if(Object.hasOwn(item,'manager_amount')&&entry[1].priceEdit!==true)return{ok:false,error:'bridge_update_required'};
 const [tab,b]=entry,request_id=id();return new Promise(resolve=>{const timer=setTimeout(()=>{requests.delete(request_id);resolve({ok:false,error:'network_error'})},20000);requests.set(request_id,{tab,nonce:b.nonce,timer,resolve});chrome.tabs.sendMessage(tab,{type:'bridge_command',nonce:b.nonce,command:{action,request_id,actor:item.target.actor,conversation_id:item.target.conversation_id,connection_id:item.target.connection.id,operation_id:item.operation_id,observation:item.observation,as_variant:item.as_variant===true,...(Object.hasOwn(item,'manager_amount')?{manager_amount:item.manager_amount}:{})}}).catch(()=>{clearTimeout(timer);requests.delete(request_id);bridges.delete(tab);resolve({ok:false,error:'bridge_unavailable'})})})
}
async function updateItem(operation,patch){await exclusive(async()=>{const s=await state();const at=s.items.findIndex(i=>i.operation_id===operation);if(at<0)return;s.items[at]={...s.items[at],...patch};await chrome.storage.local.set({items:s.items})});await announce()}
async function process(){if(processing)return;processing=true;try{
 for(;;){const s=await state(),item=s.items.find(i=>i.state==='queued'&&same(i.target,s.target));if(!item)break;inFlight=item.operation_id;
 const priceAck=r=>!Object.hasOwn(item,'manager_amount')||r.manager_amount===item.manager_amount;
 let r=await relay(item,'operation');if(r.ok&&!priceAck(r))r={ok:false,error:'bridge_update_required'};
 if(r.ok&&r.found===true&&Number.isSafeInteger(r.saved_version)&&r.saved_version>0){await updateItem(item.operation_id,{state:'saved',error:null});continue}
 if(r.ok&&r.found===false){r=await relay(item,'import');if(r.ok&&(!priceAck(r)||!['added','already_added','saved'].includes(r.state)||!Number.isSafeInteger(r.saved_version)||r.saved_version<=0))r={ok:false,error:'storage_unavailable'}}
 else if(r.ok)r={ok:false,error:'storage_unavailable'};
 if(r.ok)await updateItem(item.operation_id,{state:'saved',error:null});
 else{const current=await state(),changed=!same(item.target,current.target),review=['source_price_changed','ambiguous_offer','manual_price_conflict'].includes(r.error),wait=['network_error','storage_unavailable','bridge_unavailable','bridge_update_required'].includes(r.error);await updateItem(item.operation_id,{state:changed?'blocked':review?'review':wait?'waiting':'blocked',error:changed?'connection_changed':r.error});if(wait)break}
 }
}catch{if(inFlight)await updateItem(inFlight,{state:'waiting',error:'bridge_unavailable'}).catch(()=>{})}finally{inFlight='';processing=false}}
async function handle(m,sender){
 const role=kind(sender);if(!role||!m||typeof m!=='object')return{ok:false};
 if(role==='bridge'&&m.type==='bridge_hello'){const s=await state(),nonce=id();bridges.set(sender.tab.id,{nonce,target:null});return{ok:true,device:s.device,nonce}}
 if(role==='bridge'&&m.type==='bridge_event'){
 const b=bridges.get(sender.tab.id),e=m.event;if(!b||e?.nonce!==b.nonce)return{ok:false};
 if(e.type==='result'){const p=requests.get(e.request_id);if(p&&p.tab===sender.tab.id&&p.nonce===b.nonce){clearTimeout(p.timer);requests.delete(e.request_id);p.resolve(e.result)}return{ok:true}}
 if(e.type==='connected'&&e.target?.actor>0&&e.target?.conversation_id>0&&/^[a-f0-9]{32}$/.test(e.connection?.id||'')){
 const url=new URL(sender.tab.url);if(String(e.target.conversation_id)!==url.searchParams.get('conversation_id'))return{ok:false};
 const target={...e.target,connection:e.connection};b.target=target;b.priceEdit=e.features?.manager_price===1;
 await exclusive(async()=>{const s=await state();if(e.selected===true||same(s.target,target)){for(const item of s.items)if(!['saved','cancelled'].includes(item.state)&&item.operation_id!==inFlight&&!same(item.target,target)){item.state='blocked';item.error='connection_changed'}await chrome.storage.local.set({target,items:s.items})}});
 for(const notify of bridgeReady)notify(target);
 await announce();void process();return{ok:true}}
 if(e.type==='disconnected'){const was=b.target;b.target=null;await exclusive(async()=>{const s=await state();if(same(s.target,was)){for(const item of s.items)if(!['saved','cancelled'].includes(item.state)&&item.operation_id!==inFlight){item.state='blocked';item.error='connection_changed'}await chrome.storage.local.set({target:null,items:s.items})}});await announce();return{ok:true}}
 return{ok:false};
 }
 if(sourceRoles.includes(role)&&m.type==='begin_add')return exclusive(async()=>{
 const owner=sourceOwner(role,sender);if(!owner)return{ok:false};const s=await state();
 if(!s.target)return{ok:false,error:'Подключите клиента: Manager2 → Подборка → Сборщик туров.'};
 pruneTickets();const ticket=id();addTickets.set(ticket,{owner,target:s.target,at:Date.now()});return{ok:true,ticket};
 });
 if(sourceRoles.includes(role)&&m.type==='add_status'){
 const s=await state(),item=s.items.find(x=>x.operation_id===m.operation_id||(x.source_operation_id&&x.source_operation_id===m.operation_id));
 if(!item||!ownsSource(item.source,role,sender))return{ok:false};
 if(item.state==='cancelled')return{ok:true,state:'cancelled',error:null};
 return same(item.target,s.target)?{ok:true,state:item.state,error:item.error||null}:{ok:true,state:'blocked',error:'connection_changed'};
 }
 if(sourceRoles.includes(role)&&m.type==='show_edit'){
 const s=await state(),item=s.items.find(x=>x.operation_id===m.operation_id||(x.source_operation_id&&x.source_operation_id===m.operation_id));if(!item||item.state==='cancelled'||!ownsSource(item.source,role,sender))return{ok:false};await openEditor();return{ok:true};
 }
 if(sourceRoles.includes(role)&&['enqueue','enqueue_edit'].includes(m.type)){
 const editing=m.type==='enqueue_edit';if(editing&&!Object.hasOwn(m,'ticket'))return{ok:false};
 if(!m.observation||m.observation.adapter!==({funsun:'funsun-b2b-v1',anex:'anex-agent-v1',tourvisor:'tourvisor-snapshot-v1'}[role])||JSON.stringify(m.observation).length>16000)return{ok:false,error:'Данные предложения не поддерживаются.'};
 if(role==='tourvisor'){if(!m.observation.raw||typeof m.observation.raw!=='object')return{ok:false};m.observation.raw.party_price_confirmation='pending'};
 const r=await exclusive(async()=>{const s=await state();if(!s.target)return{ok:false,error:'Подключите клиента: Manager2 → Подборка → Сборщик туров.'};
 let ticket=null;if(Object.hasOwn(m,'ticket')){
 ticket=addTickets.get(m.ticket);addTickets.delete(m.ticket);
 if(!ticket||Date.now()-ticket.at>60000||!ownsSource(ticket.owner,role,sender)||!same(ticket.target,s.target))return{ok:false,error:'Клиент или подключение изменились. Проверьте подборку в расширении и нажмите ещё раз.'};
 }
 if(s.items.filter(x=>!['saved','cancelled'].includes(x.state)).length>=50)return{ok:false,error:'Проверьте очередь расширения: в ней уже 50 предложений.'};
 s.items=s.items.filter(x=>!['saved','cancelled'].includes(x.state)).concat(s.items.filter(x=>['saved','cancelled'].includes(x.state)).slice(-20));
 const operation_id=ticket?m.ticket:id(),direct=!!ticket&&role!=='tourvisor',item={operation_id,observation:m.observation,target:ticket?ticket.target:s.target,state:editing?'editing':direct?'queued':'destination',as_variant:false,created_at:new Date().toISOString()};
 if(editing)item.needs_edit_confirmation=true;
 if(ticket)item.source=ticket.owner;s.items.push(item);await chrome.storage.local.set({items:s.items});return ticket?{ok:true,operation_id,state:item.state}:{ok:true}});
 if(r.ok){await announce();if(r.state==='queued')void process();else await openEditor()}return r;
 }
 if(role!=='popup')return{ok:false};
 if(m.type==='status'){const s=await state();return{ok:true,target:s.target||null,items:s.items}}
 if(m.type==='open_manager'){
 const s=await state();let target=null;
 if(Object.hasOwn(m,'operation_id')){const item=s.items.find(x=>x.operation_id===m.operation_id);if(!item||!same(m.target,item.target))return{ok:false,error:'connection_changed'};target=item.target}
 else if(Object.hasOwn(m,'target')){if(!same(m.target,s.target))return{ok:false,error:'connection_changed'};target=s.target}
 if(target&&(!Number.isSafeInteger(target.actor)||target.actor<=0||!Number.isSafeInteger(target.conversation_id)||target.conversation_id<=0))return{ok:false};
 const url=bridgeOrigin+'/manager2/'+(target?'?selection='+target.conversation_id+'&collector_actor='+target.actor+'#conversation='+target.conversation_id:'');
 await chrome.tabs.create({url});return{ok:true};
 }
 if(m.type==='edit_draft')return exclusive(async()=>{
 const s=await state(),item=s.items.find(x=>x.operation_id===m.operation_id),d=m.draft;
 if(!item||item.state!=='editing'||!same(item.target,s.target)||!d||typeof d!=='object'||Array.isArray(d)||Object.keys(d).sort().join(',')!=='action,amount,basis,price_basis,round,value'||typeof d.amount!=='string'||d.amount.length>32||typeof d.value!=='string'||d.value.length>32||!['discount','markup'].includes(d.action)||!['percent','amount'].includes(d.basis)||!['none','unit','ten','hundred','thousand'].includes(d.round)||typeof d.price_basis!=='boolean')return{ok:false};
 item.edit_draft=d;await chrome.storage.local.set({items:s.items});return{ok:true};
 });
 if(['confirm','retry','variant','remove','reconnect','save_edit'].includes(m.type)){
 const result=await exclusive(async()=>{const s=await state(),at=s.items.findIndex(x=>x.operation_id===m.operation_id);if(at<0)return{ok:false};const item=s.items[at];
 if(m.type==='remove'){if(item.state==='queued')return{ok:false};if(item.needs_edit_confirmation||item.state==='destination'||item.observation.adapter==='tourvisor-snapshot-v1'&&item.observation.raw?.party_price_confirmation==='pending'){item.state='cancelled';item.error=null;delete item.edit_draft}else s.items.splice(at,1)}
 else{if(item.state==='saved')return{ok:false};
 const expected={confirm:'destination',retry:'waiting',variant:'review',reconnect:'blocked',save_edit:'editing'};if(item.state!==expected[m.type])return{ok:false};
 if(m.type==='reconnect'){const t=s.target;if(!t||item.target.actor!==t.actor||item.target.conversation_id!==t.conversation_id||item.target.binding!==t.binding)return{ok:false};item.target=t;rotateOperation(item)}
 if(!same(item.target,s.target))return{ok:false};
 if(m.type==='save_edit'){
  if(typeof m.manager_amount!=='string'||!/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/.test(m.manager_amount)||!/[1-9]/.test(m.manager_amount))return{ok:false};
  item.manager_amount=m.manager_amount;item.needs_edit_confirmation=false;
 }
 if(item.observation.adapter==='tourvisor-snapshot-v1'&&item.observation.raw.party_price_confirmation!=='one_room_two_adults'&&m.type!=='reconnect'){if(!['confirm','save_edit'].includes(m.type)||m.confirm_price_basis!==true)return{ok:false};item.observation.raw.party_price_confirmation='one_room_two_adults'}
 if(m.type==='variant'){if(item.state!=='review'||item.error==='manual_price_conflict')return{ok:false};item.as_variant=true;rotateOperation(item)}
 item.state=item.needs_edit_confirmation?'editing':item.observation.adapter==='tourvisor-snapshot-v1'&&item.observation.raw.party_price_confirmation!=='one_room_two_adults'?'destination':'queued';item.error=null}
 await chrome.storage.local.set({items:s.items});return{ok:true}});await announce();if(result.ok)void process();return result;
 }
 return{ok:false};
}
chrome.runtime.onMessage.addListener((m,sender,reply)=>{handle(m,sender).then(reply,()=>reply({ok:false,error:'storage_unavailable'}));return true});
chrome.alarms.create('collector-recovery',{periodInMinutes:1});chrome.alarms.onAlarm.addListener(()=>{void exclusive(async()=>{const s=await state();for(const item of s.items)if(item.state==='waiting')item.state='queued';await chrome.storage.local.set({items:s.items})}).then(process)});
chrome.runtime.onStartup.addListener(()=>{void process()});
