'use strict';
const bridgeOrigin='https://app.anytoour.ru',bridgePath='/manager/tour-selection-collector.php';
const id=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
const bridges=new Map(),requests=new Map(),bridgeReady=new Set();let serial=Promise.resolve(),processing=false,inFlight='';
const exclusive=fn=>{const p=serial.then(fn,fn);serial=p.catch(()=>{});return p};
function kind(sender){try{const u=new URL(sender.url||sender.tab?.url||'');if(u.origin===bridgeOrigin&&u.pathname===bridgePath)return'bridge';if(u.origin==='https://b2b.fstravel.com'&&u.pathname==='/search_tour')return'source';if(u.protocol==='chrome-extension:'&&u.host===chrome.runtime.id)return'popup'}catch{}return''}
async function state(){const s=await chrome.storage.local.get(['device','target','items']);if(!s.device){s.device=id();await chrome.storage.local.set({device:s.device})}return {...s,items:Array.isArray(s.items)?s.items:[]}}
function same(a,b){return a&&b&&a.actor===b.actor&&a.conversation_id===b.conversation_id&&a.binding===b.binding&&a.connection?.id===b.connection?.id}
async function announce(){for(const tab of await chrome.tabs.query({url:'https://b2b.fstravel.com/search_tour*'}))void chrome.tabs.sendMessage(tab.id,{type:'target_changed'}).catch(()=>{});await chrome.action.setBadgeText({text:String((await state()).items.filter(x=>x.state!=='saved').length||'')})}
async function relay(item,action,reconnect=true){
 const s=await state();if(!same(s.target,item.target))return{ok:false,error:'connection_changed'};
 const entry=Array.from(bridges.entries()).find(([,b])=>same(b.target,item.target));
 if(!entry){
  if(!reconnect)return{ok:false,error:'bridge_unavailable'};
  let notify;const ready=new Promise(resolve=>{const timer=setTimeout(()=>{bridgeReady.delete(notify);resolve(false)},8000);notify=target=>{if(!same(target,item.target))return;clearTimeout(timer);bridgeReady.delete(notify);resolve(true)};bridgeReady.add(notify)});
  const tabs=await chrome.tabs.query({url:bridgeOrigin+bridgePath+'*'}),u=bridgeOrigin+bridgePath+'?conversation_id='+item.target.conversation_id,tab=tabs.find(t=>t.url===u);
  if(tab)void chrome.tabs.sendMessage(tab.id,{type:'bridge_reconnect'}).catch(()=>{});else await chrome.tabs.create({url:u,active:false});
  return await ready?relay(item,action,false):{ok:false,error:'bridge_unavailable'};
 }
 const [tab,b]=entry,request_id=id();return new Promise(resolve=>{const timer=setTimeout(()=>{requests.delete(request_id);resolve({ok:false,error:'network_error'})},20000);requests.set(request_id,{tab,nonce:b.nonce,timer,resolve});chrome.tabs.sendMessage(tab,{type:'bridge_command',nonce:b.nonce,command:{action,request_id,actor:item.target.actor,conversation_id:item.target.conversation_id,connection_id:item.target.connection.id,operation_id:item.operation_id,observation:item.observation,as_variant:item.as_variant===true}}).catch(()=>{clearTimeout(timer);requests.delete(request_id);bridges.delete(tab);resolve({ok:false,error:'bridge_unavailable'})})})
}
async function updateItem(operation,patch){await exclusive(async()=>{const s=await state();const at=s.items.findIndex(i=>i.operation_id===operation);if(at<0)return;s.items[at]={...s.items[at],...patch};await chrome.storage.local.set({items:s.items})});await announce()}
async function process(){if(processing)return;processing=true;try{
 for(;;){const s=await state(),item=s.items.find(i=>i.state==='queued'&&same(i.target,s.target));if(!item)break;inFlight=item.operation_id;
 let r=await relay(item,'operation');if(r.ok&&r.found){await updateItem(item.operation_id,{state:'saved',error:null});continue}
 if(r.ok&&r.found===false)r=await relay(item,'import');
 if(r.ok)await updateItem(item.operation_id,{state:'saved',error:null});
 else{const current=await state(),changed=!same(item.target,current.target),review=['source_price_changed','ambiguous_offer'].includes(r.error),wait=['network_error','storage_unavailable','bridge_unavailable'].includes(r.error);await updateItem(item.operation_id,{state:changed?'blocked':review?'review':wait?'waiting':'blocked',error:changed?'connection_changed':r.error});if(wait)break}
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
 const target={...e.target,connection:e.connection};b.target=target;
 await exclusive(async()=>{const s=await state();if(e.selected===true||same(s.target,target)){for(const item of s.items)if(item.state!=='saved'&&item.operation_id!==inFlight&&!same(item.target,target)){item.state='blocked';item.error='connection_changed'}await chrome.storage.local.set({target,items:s.items})}});
 for(const notify of bridgeReady)notify(target);
 await announce();void process();return{ok:true}}
 if(e.type==='disconnected'){const was=b.target;b.target=null;await exclusive(async()=>{const s=await state();if(same(s.target,was)){for(const item of s.items)if(item.state!=='saved'&&item.operation_id!==inFlight){item.state='blocked';item.error='connection_changed'}await chrome.storage.local.set({target:null,items:s.items})}});await announce();return{ok:true}}
 return{ok:false};
 }
 if(role==='source'&&m.type==='enqueue'){
 if(!m.observation||m.observation.adapter!=='funsun-b2b-v1'||JSON.stringify(m.observation).length>16000)return{ok:false,error:'Данные предложения не поддерживаются.'};
 const r=await exclusive(async()=>{const s=await state();if(!s.target)return{ok:false,error:'Подключите клиента: Manager2 → Подборка → Сборщик FUN&SUN.'};
 if(s.items.filter(x=>x.state!=='saved').length>=50)return{ok:false,error:'Проверьте очередь расширения: в ней уже 50 предложений.'};
 s.items=s.items.filter(x=>x.state!=='saved').concat(s.items.filter(x=>x.state==='saved').slice(-20));
 const operation_id=id();s.items.push({operation_id,observation:m.observation,target:s.target,state:'destination',as_variant:false,created_at:new Date().toISOString()});await chrome.storage.local.set({items:s.items});return{ok:true}});
 if(r.ok){await announce();try{if(typeof chrome.action.openPopup==='function')void chrome.action.openPopup().catch(()=>{})}catch{}}return r;
 }
 if(role!=='popup')return{ok:false};
 if(m.type==='status'){const s=await state();return{ok:true,target:s.target||null,items:s.items}}
 if(m.type==='open_manager'){await chrome.tabs.create({url:'https://app.anytoour.ru/manager2/'});return{ok:true}}
 if(['confirm','retry','variant','remove','reconnect'].includes(m.type)){
 const result=await exclusive(async()=>{const s=await state(),at=s.items.findIndex(x=>x.operation_id===m.operation_id);if(at<0)return{ok:false};const item=s.items[at];
 if(m.type==='remove'){if(item.state==='queued')return{ok:false};s.items.splice(at,1)}
 else{if(item.state==='saved')return{ok:false};
 const expected={confirm:'destination',retry:'waiting',variant:'review',reconnect:'blocked'};if(item.state!==expected[m.type])return{ok:false};
 if(m.type==='reconnect'){const t=s.target;if(!t||item.target.actor!==t.actor||item.target.conversation_id!==t.conversation_id||item.target.binding!==t.binding)return{ok:false};item.target=t;item.operation_id=id()}
 if(!same(item.target,s.target))return{ok:false};
 if(m.type==='variant'){if(item.state!=='review')return{ok:false};item.as_variant=true;item.operation_id=id()}
 item.state='queued';item.error=null}
 await chrome.storage.local.set({items:s.items});return{ok:true}});await announce();if(result.ok)void process();return result;
 }
 return{ok:false};
}
chrome.runtime.onMessage.addListener((m,sender,reply)=>{handle(m,sender).then(reply,()=>reply({ok:false,error:'storage_unavailable'}));return true});
chrome.alarms.create('collector-recovery',{periodInMinutes:1});chrome.alarms.onAlarm.addListener(()=>{void exclusive(async()=>{const s=await state();for(const item of s.items)if(item.state==='waiting')item.state='queued';await chrome.storage.local.set({items:s.items})}).then(process)});
chrome.runtime.onStartup.addListener(()=>{void process()});
