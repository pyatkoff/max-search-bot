(function(){
'use strict';
const DB_NAME='workspaceV2.mediaDrafts.v1',PREFIX=DB_NAME+'.',TTL=24*60*60*1000,MAX_BYTES=100*1024*1024,MAX_FILES=10,MAX_CHATS=20;
const revisions=new Map(),epochs=new Map(),known=new Map(),identities=new WeakMap();let opening=null;
const positive=n=>Number.isSafeInteger(n)&&n>0,idOK=s=>typeof s==='string'&&/^[a-f0-9]{32}$/.test(s),scope=(a,c)=>a+':'+c;
const epoch=a=>epochs.get(a)||0;
function next(a,c){const k=scope(a,c),n=(revisions.get(k)||0)+1;revisions.set(k,n);return n}
function token(a,c){const S=window.WorkspaceV2?.S;return{actor:a,cid:c,epoch:epoch(a),revision:next(a,c),session:S?{generation:S.authGeneration,actor:Number(S.manager?.id),expired:!!S.authExpired}:null}}
function current(t){const S=window.WorkspaceV2?.S;return epoch(t.actor)===t.epoch&&revisions.get(scope(t.actor,t.cid))===t.revision&&(!t.session||(S&&t.session.actor===t.actor&&!t.session.expired&&!S.authExpired&&Number(S.manager?.id)===t.actor&&S.authGeneration===t.session.generation))}
function randomId(){const bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);return Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('')}
function caption(value){if(value==null)return null;if(!idOK(value.batch)||typeof value.caption!=='string'||value.caption.length>20000)throw Error('invalid_caption');return{batch:value.batch,caption:value.caption}}
function metadata(file){if(!file||typeof file.slice!=='function'||typeof file.arrayBuffer!=='function'||!Number.isSafeInteger(file.size)||file.size<=0||file.size>MAX_BYTES||typeof file.name!=='string'||file.name.length>1000||typeof file.type!=='string'||file.type.length>200||!Number.isFinite(file.lastModified))throw Error('invalid_file');return{name:file.name,size:file.size,type:file.type,lastModified:file.lastModified}}
function rowDescriptor(row,assign=false){
 const meta=metadata(row.file);if(assign){const old=identities.get(row);if(!old||old.file!==row.file||old.id!==row.blobId){row.blobId=randomId();identities.set(row,{file:row.file,id:row.blobId})}}
 if(!idOK(row.blobId)||!['ready','unknown'].includes(row.status||'ready'))throw Error('invalid_row');
 if(row.batchId!=null&&!idOK(row.batchId)||row.recoveryId!=null&&(!idOK(row.recoveryId)||!idOK(row.batchId)))throw Error('invalid_operation');
 return{blobId:row.blobId,...meta,status:row.status||'ready',...(row.batchId?{batchId:row.batchId}:{}),...(row.recoveryId?{recoveryId:row.recoveryId}:{})};
}
function descriptors(rows,assign=false){if(!Array.isArray(rows)||rows.length>MAX_FILES)throw Error('invalid_selection');const out=rows.map(r=>rowDescriptor(r,assign));if(new Set(out.map(r=>r.blobId)).size!==out.length)throw Error('duplicate_row');if(out.reduce((sum,r)=>sum+r.size,0)>MAX_BYTES)throw Error('selection_capacity');return out}
function manifest(actor){
 const raw=window.sessionStorage.getItem(PREFIX+actor);if(raw===null)return{schema:1,actor,conversations:{}};
 if(raw.length>1000000)throw Error('invalid_manifest');const m=JSON.parse(raw);
 if(m?.schema!==1||m.actor!==actor||!m.conversations||Array.isArray(m.conversations)||typeof m.conversations!=='object'||Object.keys(m.conversations).length>MAX_CHATS)throw Error('invalid_manifest');
 for(const [cid,d]of Object.entries(m.conversations)){
  if(!/^[1-9][0-9]*$/.test(cid)||!positive(Number(cid))||!d||!Array.isArray(d.rows)||d.rows.length>MAX_FILES||!Number.isFinite(d.updatedAt)||!Number.isFinite(d.expiresAt)||(d.expiresAt<=d.updatedAt||d.expiresAt>d.updatedAt+TTL))throw Error('invalid_manifest');
  caption(d.captionSent);
  const ids=new Set();for(const r of d.rows){if(!idOK(r.blobId)||ids.has(r.blobId)||typeof r.name!=='string'||r.name.length>1000||!Number.isSafeInteger(r.size)||r.size<=0||r.size>MAX_BYTES||typeof r.type!=='string'||r.type.length>200||!Number.isFinite(r.lastModified)||!['ready','unknown'].includes(r.status)||r.batchId!=null&&!idOK(r.batchId)||r.recoveryId!=null&&(!idOK(r.recoveryId)||!idOK(r.batchId)))throw Error('invalid_manifest');ids.add(r.blobId)}
 }
 return m;
}
function writeManifest(actor,m){window.sessionStorage.setItem(PREFIX+actor,JSON.stringify(m));return true}
function commit(t,rows,cap,incomplete=false){
 if(!current(t))return false;const now=Date.now(),m=manifest(t.actor);
 for(const [cid,d]of Object.entries(m.conversations))if(d.expiresAt<=now)delete m.conversations[cid];
 if(rows.length||incomplete){if(!m.conversations[t.cid]&&Object.keys(m.conversations).length>=MAX_CHATS)return false;m.conversations[t.cid]={rows,captionSent:cap,updatedAt:now,expiresAt:Math.min(now+TTL,...rows.map(r=>known.get(scope(t.actor,t.cid)+':'+r.blobId))),...(incomplete?{incomplete:true}:{})}}
 else delete m.conversations[t.cid];
 if(!current(t))return false;return writeManifest(t.actor,m);
}
function safeCommit(t,rows,cap,incomplete=false){
 try{return commit(t,rows,cap,incomplete)}catch(e){if(current(t))try{window.sessionStorage.removeItem(PREFIX+t.actor)}catch(ignored){}return false}
}
function database(){
 if(opening)return opening;
 opening=new Promise(resolve=>{
  let done=false,request,timer;const finish=db=>{if(done){db?.close();return}done=true;clearTimeout(timer);resolve(db)};
  try{request=window.indexedDB.open(DB_NAME,1);timer=setTimeout(()=>finish(null),3000);
   request.onupgradeneeded=()=>{const db=request.result;if(!db.objectStoreNames.contains('blobs'))db.createObjectStore('blobs',{keyPath:'id'});if(!db.objectStoreNames.contains('usage'))db.createObjectStore('usage',{keyPath:'id'})};
   request.onerror=request.onblocked=()=>finish(null);request.onsuccess=()=>{const db=request.result;db.onversionchange=()=>{db.close();opening=null};finish(db)};
  }catch(e){finish(null)}
 }).then(db=>{if(!db)opening=null;return db});return opening;
}
function identityMatches(record,actor,cid,r){return record&&record.id===r.blobId&&record.actor===actor&&record.cid===cid&&record.name===r.name&&record.size===r.size&&record.type===r.type&&record.lastModified===r.lastModified&&Number.isFinite(record.createdAt)&&Number.isFinite(record.expiresAt)&&record.expiresAt===record.createdAt+TTL&&record.createdAt<=Date.now()&&record.expiresAt>Date.now()}
async function storeBlobs(actor,cid,rows,desc){
 // Read bounded immutable bytes BEFORE opening a transaction. WebKit may reject
 // persisted Blob/File handles even when ordinary binary structured cloning works.
 let buffers;try{buffers=await Promise.all(rows.map(file=>file.arrayBuffer()));if(buffers.some((bytes,i)=>Object.prototype.toString.call(bytes)!=='[object ArrayBuffer]'||bytes.byteLength!==desc[i].size))return false}catch(e){return false}
 const db=await database();if(!db)return false;
 return new Promise(resolve=>{
  let tx,finished=false,timer;const expiry=new Map();const finish=ok=>{if(finished)return;finished=true;clearTimeout(timer);resolve(ok)};
  try{
   tx=db.transaction(['blobs','usage'],'readwrite');const blobs=tx.objectStore('blobs'),usage=tx.objectStore('usage');
   timer=setTimeout(()=>{try{tx.abort()}catch(e){}finish(false)},5000);tx.onabort=tx.onerror=()=>finish(false);
   tx.oncomplete=()=>{for(const d of desc)known.set(scope(actor,cid)+':'+d.blobId,expiry.get(d.blobId));finish(true)};
   const request=usage.getAll();request.onsuccess=()=>{
    try{
     const now=Date.now(),live=new Map();let bytes=0;
     for(const r of request.result){if(!r||!idOK(r.id)||!Number.isSafeInteger(r.size)||r.size<=0||!Number.isFinite(r.expiresAt))throw Error('invalid_usage');if(r.expiresAt<=now){blobs.delete(r.id);usage.delete(r.id)}else{live.set(r.id,r);bytes+=r.size}}
     const add=[];for(let i=0;i<desc.length;i++){const d=desc[i],existing=live.get(d.blobId);if(existing){if(!identityMatches(existing,actor,cid,d))throw Error('immutable_blob_mismatch');expiry.set(d.blobId,existing.expiresAt)}else{bytes+=d.size;add.push(d)}}
     if(bytes>MAX_BYTES)throw Error('draft_capacity');
     for(const d of add){const record={id:d.blobId,actor,cid,name:d.name,size:d.size,type:d.type,lastModified:d.lastModified,createdAt:now,expiresAt:now+TTL};expiry.set(d.blobId,record.expiresAt);usage.add(record);blobs.add({...record,format:'arraybuffer-v1',bytes:buffers[desc.indexOf(d)]})}
    }catch(e){try{tx.abort()}catch(ignored){}}
   };
  }catch(e){try{tx?.abort()}catch(ignored){}finish(false)}
 });
}
function checkpoint(actor,cid,rows,captionSent=null){
 try{if(!positive(actor)||!positive(cid))return false;const t=token(actor,cid),desc=descriptors(rows),cap=caption(captionSent);if(desc.some(d=>!(known.get(scope(actor,cid)+':'+d.blobId)>Date.now())))return false;return safeCommit(t,desc,cap)}catch(e){return false}
}
function save(actor,cid,rows,captionSent=null){
 let t,desc,cap,signature,files;
 try{if(!positive(actor)||!positive(cid))return Promise.resolve(false);t=token(actor,cid);desc=descriptors(rows,true);cap=caption(captionSent);signature=JSON.stringify(desc);files=rows.map(r=>r.file);if(!current(t))return Promise.resolve(false);
  if(desc.every(d=>known.get(scope(actor,cid)+':'+d.blobId)>Date.now()))return Promise.resolve(safeCommit(t,desc,cap));
  // Remove obsolete membership synchronously, even while new bytes are pending.
  // The partial marker never claims that the new selection is durably saved.
  if(!safeCommit(t,desc.filter(d=>known.get(scope(actor,cid)+':'+d.blobId)>Date.now()),cap,true))return Promise.resolve(false)
 }catch(e){return Promise.resolve(false)}
 return storeBlobs(actor,cid,files,desc).then(ok=>{if(!ok||!current(t))return false;try{if(rows.some((r,i)=>r.file!==files[i])||JSON.stringify(descriptors(rows))!==signature)return false;return safeCommit(t,desc,cap)}catch(e){return false}}).catch(()=>false);
}
async function load(actor,cid){
 try{
  if(!positive(actor)||!positive(cid))return null;const m=manifest(actor),d=m.conversations[cid],now=Date.now();
  if(!d||d.expiresAt<=now)return{rows:[],captionSent:null,missing:!!d};
  const db=await database();if(!db)return null;const records=await new Promise(resolve=>{
   let tx,done=false,timer;const result=[];const finish=value=>{if(done)return;done=true;clearTimeout(timer);resolve(value)};
   try{tx=db.transaction('blobs','readonly');timer=setTimeout(()=>{try{tx.abort()}catch(e){}finish(null)},5000);tx.onabort=tx.onerror=()=>finish(null);tx.oncomplete=()=>finish(result);
    for(const r of d.rows){const req=tx.objectStore('blobs').get(r.blobId);req.onsuccess=()=>result.push([r,req.result])}
   }catch(e){finish(null)}
  });
  if(records===null)return null;const byId=new Map(records.map(([r,value])=>[r.blobId,value])),rows=[];let missing=!!d.incomplete;
  for(const r of d.rows){const value=byId.get(r.blobId);if(!identityMatches(value,actor,cid,r)||value.format!=='arraybuffer-v1'||Object.prototype.toString.call(value.bytes)!=='[object ArrayBuffer]'||value.bytes.byteLength!==r.size){missing=true;continue}
   const FileClass=window.File||File,file=new FileClass([value.bytes],r.name,{type:r.type,lastModified:r.lastModified});const row={file,status:r.status,blobId:r.blobId,...(r.batchId?{batchId:r.batchId}:{}),...(r.recoveryId?{recoveryId:r.recoveryId}:{})};identities.set(row,{file,id:r.blobId});known.set(scope(actor,cid)+':'+r.blobId,value.expiresAt);rows.push(row);
  }
  return{rows,captionSent:caption(d.captionSent),missing};
 }catch(e){return null}
}
function forget(actor,cid){if(!positive(actor)||!positive(cid))return false;next(actor,cid);try{const m=manifest(actor);delete m.conversations[cid];return writeManifest(actor,m)}catch(e){try{window.sessionStorage.removeItem(PREFIX+actor)}catch(ignored){}return false}}
function resetActor(actor){if(!positive(actor))return false;epochs.set(actor,epoch(actor)+1);for(const key of known.keys())if(key.startsWith(actor+':'))known.delete(key);try{window.sessionStorage.removeItem(PREFIX+actor);return true}catch(e){return false}}
function has(actor,cid){try{return positive(actor)&&positive(cid)&&!!manifest(actor).conversations[cid]}catch(e){return false}}
window.WorkspaceV2MediaDrafts={load,save,checkpoint,forget,resetActor,has};
})();
