(function(scope){
'use strict';
const DAY = 86400000;
const valid = id => Number.isSafeInteger(id) && id > 0;
function supported(){return !!(scope.navigator?.locks && scope.indexedDB);}
function database(){
  return new Promise((resolve,reject)=>{
    const request = indexedDB.open('anytour-workspace-sound-v1',1);
    let expired=false;
    const timer = setTimeout(()=>{expired=true;reject(new Error('sound_storage_timeout'));},2000);
    request.onupgradeneeded=()=>request.result.createObjectStore('accounts',{keyPath:'managerId'});
    request.onerror=()=>{clearTimeout(timer);reject(new Error('sound_storage_failed'));};
    request.onsuccess=()=>{clearTimeout(timer);const db=request.result;if(expired){db.close();return;}db.onversionchange=()=>db.close();resolve(db);};
  });
}
function read(db,managerId){
  return new Promise((resolve,reject)=>{
    const request=db.transaction('accounts').objectStore('accounts').get(managerId);
    request.onerror=()=>reject(new Error('sound_storage_failed'));
    request.onsuccess=()=>{
      const value=request.result;
      if (!value || value.version!==2 || Date.now()-value.updatedAt>DAY || value.updatedAt>Date.now()+1000) {
        resolve({version:2,managerId,cursor:null,pollAt:0,lastTone:0,seen:{}});return;
      }
      resolve(value);
    };
  });
}
function save(db,state){
  state.updatedAt=Date.now();
  const entries=Object.entries(state.seen||{}).filter(([k,v])=>/^\d+:\d+$/.test(k)&&Number.isFinite(v)&&v>Date.now()-DAY)
    .sort((a,b)=>b[1]-a[1]).slice(0,2048);
  state.seen=Object.fromEntries(entries);
  return new Promise((resolve,reject)=>{
    const tx=db.transaction('accounts','readwrite'),store=tx.objectStore('accounts');
    store.put(state);
    const all=store.getAll();
    all.onsuccess=()=>{
      const rows=all.result.sort((a,b)=>b.updatedAt-a.updatedAt);
      rows.forEach((row,index)=>{if(row.managerId!==state.managerId&&(index>=32||row.updatedAt<Date.now()-DAY))store.delete(row.managerId);});
    };
    tx.oncomplete=()=>resolve();tx.onerror=tx.onabort=()=>reject(new Error('sound_storage_failed'));
  });
}
async function run(managerId,action,{ifAvailable=false}={}){
  if(!valid(managerId)||!supported())throw new Error('sound_coordination_unavailable');
  return navigator.locks.request('anytour-sound-'+managerId,{ifAvailable},async lock=>{
    if(!lock)return false;
    const db=await database();
    try{const state=await read(db,managerId);const result=await action(state);await save(db,state);return result;}
    finally{db.close();}
  });
}
function validEvent(event){return valid(event?.conversation_id)&&valid(event?.message_id);}
function eventKey(event){return event.conversation_id+':'+event.message_id;}
function seen(state,event){return validEvent(event)&&Object.prototype.hasOwnProperty.call(state.seen||{},eventKey(event));}
function mark(state,events){
  if(!state.seen)state.seen={};
  for(const event of events)if(validEvent(event)&&!seen(state,event))state.seen[eventKey(event)]=Date.now();
}
scope.WorkspaceSoundLedger={run,seen,mark,validEvent,supported};
})(typeof self!=='undefined'?self:globalThis);
