(function(){
'use strict';
const W=window.WorkspaceV2,{S,$}=W,prefix='workspaceV2.mediaResults.v1.',pendingStates=['processing','unknown','not_found'],terminalStates=['confirmed','rejected','cancelled'];
let owner=0,generation=0,target=0,notice='';const checking=new Map();
const id=()=>{const b=new Uint8Array(16);window.crypto.getRandomValues(b);return [...b].map(x=>x.toString(16).padStart(2,'0')).join('')};
const key=(actor,operation)=>prefix+actor+'.'+operation;
const authenticated=()=>owner>0&&!S.authExpired&&Number(S.manager?.id)===owner&&Number(S.authGeneration||0)===generation;
const context=()=>window.WorkspaceV2Conversation?.captureReplyContext?.()||{conversationId:target,managerId:owner,authGeneration:generation};
function current(c){const n=context();return authenticated()&&Number(S.current)===target&&c&&Object.keys(n).every(k=>n[k]===c[k])}
function meta(file){return {name:String(file.name||'Вложение').slice(0,200),size:Number(file.size||0),type:String(file.type||'').slice(0,150),lastModified:Number(file.lastModified||0)}}
function valid(r,actor=owner){return r?.schema===1&&r.actor===actor&&Number.isSafeInteger(r.conversation_id)&&r.conversation_id>0&&/^[a-f0-9]{32}$/.test(r.operation_id||'')&&/^[a-f0-9]{32}$/.test(r.batch_id||'')&&[...pendingStates,...terminalStates].includes(r.state)&&r.file&&typeof r.file.name==='string'&&r.file.name.length<=200&&Number.isFinite(r.file.size)&&r.file.size>=0&&typeof r.caption==='string'&&r.caption.length<=20000&&Number.isFinite(r.created_at)&&Number.isSafeInteger(r.order)&&r.order>0}
function readRecord(actor,operation){
 const storage=window.localStorage,base=key(actor,operation),raw=storage.getItem(base);if(!raw)return null;
 if(raw.length>100000)throw Error('invalid');const intent=JSON.parse(raw);if(!valid(intent,actor))throw Error('invalid');
 // Primary intent is immutable. Tiny terminal proofs always win over pending
 // progress, including interleaved reads/writes in separate windows.
 for(const suffix of ['.terminal','.pending']){
  const value=storage.getItem(base+suffix);if(!value)continue;
  if(value.length>2000)throw Error('invalid');const r=JSON.parse(value),states=suffix==='.terminal'?terminalStates:pendingStates;
  if(r.schema!==1||r.actor!==intent.actor||r.operation_id!==intent.operation_id||r.conversation_id!==intent.conversation_id||r.batch_id!==intent.batch_id||!states.includes(r.state)||typeof r.caption_included!=='boolean')throw Error('invalid');
  if(r.state==='confirmed'&&(!['image','video','audio','file'].includes(r.media_type)||r.caption_included!==(intent.caption.trim()!=='')))throw Error('invalid');
  return {...intent,state:r.state,caption_included:r.caption_included,...(r.state==='confirmed'?{media_type:r.media_type}:{})};
 }
 return intent;
}
function entries(){
 const out=[];try{const storage=window.localStorage;
  for(let i=0;i<storage.length;i++){const k=storage.key(i);if(!k?.startsWith(prefix+owner+'.'))continue;const operation=k.slice((prefix+owner+'.').length);
   if(/^[a-f0-9]{32}\.(pending|terminal)$/.test(operation))continue;
   if(!/^[a-f0-9]{32}$/.test(operation))throw Error('invalid');const r=readRecord(owner,operation);if(r)out.push(r);
  }
  return out.sort((a,b)=>a.order-b.order||a.created_at-b.created_at||a.operation_id.localeCompare(b.operation_id));
 }catch(e){notice='Не удалось прочитать сохранённые результаты. Новая отправка файлов не начата.';return null}
}
function write(r,suffix=''){try{const value=suffix?{schema:1,actor:r.actor,conversation_id:r.conversation_id,operation_id:r.operation_id,batch_id:r.batch_id,state:r.state,caption_included:r.caption_included,...(r.state==='confirmed'?{media_type:r.media_type}:{})}:r;window.localStorage.setItem(key(r.actor,r.operation_id)+suffix,JSON.stringify(value));return true}catch(e){if(r.actor===owner)notice='Результат не удалось сохранить. Проверьте отправку перед повтором; проверьте свободное место в браузере.';return false}}
function activate(managerId){owner=Number(managerId||0);generation=Number(S.authGeneration||0);target=0;notice='';checking.clear();render()}
function suspend(){target=0;checking.clear();const box=$('mediaRecovery');if(box)box.hidden=true}
function configure(conversationId){if(owner!==Number(S.manager?.id)||generation!==Number(S.authGeneration||0))activate(S.manager?.id);target=Number(conversationId||0);render()}
function blocking(file,conversationId=target){const all=entries();if(!all)return null;const f=meta(file);return all.find(r=>r.conversation_id===conversationId&&pendingStates.includes(r.state)&&r.file.name===f.name&&r.file.size===f.size&&r.file.type===f.type)||null}
function blocked(file,conversationId=target){return entries()===null||!!blocking(file,conversationId)}
function begin(file,caption,batch){
 if(!authenticated()||!target)return null;notice='';const all=entries();if(!all||blocked(file))return null;
 // Retain every unresolved fence. Only old terminal display entries are bounded.
 const completed=all.filter(r=>terminalStates.includes(r.state));
 try{for(const r of completed.slice(0,Math.max(0,completed.length-99))){const base=key(owner,r.operation_id);window.localStorage.removeItem(base);window.localStorage.removeItem(base+'.pending');window.localStorage.removeItem(base+'.terminal')}
  const storage=window.localStorage,keys=Array.from({length:storage.length},(_,i)=>storage.key(i));for(const k of keys)if(k?.startsWith(prefix+owner+'.')&&/^[a-f0-9]{32}\.(pending|terminal)$/.test(k.slice((prefix+owner+'.').length))&&!storage.getItem(k.replace(/\.(pending|terminal)$/,'')))storage.removeItem(k);
 }catch(e){return null}
 if(all.length-completed.length>=100){notice='Сначала проверьте незавершённые отправки файлов.';render();return null}
 let r;try{r={schema:1,actor:owner,conversation_id:target,operation_id:id(),batch_id:batch||id(),file:meta(file),caption:String(caption||''),state:'processing',order:Math.max(0,...all.map(r=>r.order))+1,created_at:Date.now(),caption_included:false}}catch(e){notice='Безопасная отправка файлов недоступна в этом браузере.';render();return null}
 if(r.caption.length>20000||!write(r)){render();return null}render();return r;
}
function result(record,response){
 let old;try{old=readRecord(record.actor,record.operation_id)}catch(e){return null}
 if(!valid(old,record.actor)||old.conversation_id!==record.conversation_id)return null;
 const a=response?.attempt,confirmedValid=a?.state!=='confirmed'||(['image','video','audio','file'].includes(a.media_type)&&typeof a.caption_included==='boolean'&&a.caption_included===(old.caption.trim()!=='')),typed=a?.kind==='media'&&[...pendingStates,...terminalStates].includes(a.state)&&confirmedValid,state=typed?a.state:'unknown';
 // A late PROCESSING/UNKNOWN response cannot undo a terminal receipt.
 if(terminalStates.includes(old.state))return old;
 const next={...old,state,caption_included:state==='confirmed'&&a?.caption_included===true,...(state==='confirmed'&&['image','video','audio','file'].includes(a?.media_type)?{media_type:a.media_type}:{})};
 if(!write(next,terminalStates.includes(state)?'.terminal':'.pending'))return old;let saved=next;try{saved=readRecord(record.actor,record.operation_id)||next}catch(e){}if(authenticated()&&record.actor===owner)render();return saved;
}
async function check(operationId,cancel=false){
 if(!authenticated()||checking.has(operationId))return false;
 const record=entries()?.find(r=>r.operation_id===operationId&&r.conversation_id===target);if(!record||!pendingStates.includes(record.state)||(cancel&&record.state!=='not_found'))return false;
 const c=context(),flight={};checking.set(operationId,flight);notice='';render();
 try{
  const response=await W.api(cancel?'media_cancel':'media_receipt',{conversation_id:record.conversation_id,operation_id:operationId,expected_manager_id:record.actor},{isCurrent:()=>current(c)});
  if(!current(c))return false;
  if((!cancel&&response?.ok!==true)||response?.attempt?.kind!=='media'){notice='Не удалось проверить отправку. Файл не отправлялся повторно.';return false}
  const saved=result(record,response);
  if(saved&&terminalStates.includes(saved.state))window.WorkspaceV2Media?.settleRecovery?.(saved);
  return !!saved;
 }catch(e){if(current(c))notice='Не удалось проверить отправку. Файл не отправлялся повторно.';return false}
 finally{if(checking.get(operationId)===flight)checking.delete(operationId);if(current(c))render()}
}
function render(){
 if(!document.createElement)return;let box=$('mediaRecovery');const host=$('mediaSelection');if(!box&&host?.parentNode){box=document.createElement('details');box.id='mediaRecovery';box.className='mediaRecovery';host.insertAdjacentElement('afterend',box)}if(!box)return;
 const all=authenticated()&&target?entries():[],rows=all?.filter(r=>r.conversation_id===target)||[];
 box.hidden=!authenticated()||!target||(!rows.length&&!notice);if(box.hidden)return;
 const opened=box.open;box.replaceChildren();const title=document.createElement('summary'),confirmed=rows.filter(r=>r.state==='confirmed').length,waiting=rows.filter(r=>pendingStates.includes(r.state)).length;title.textContent=`Отправка файлов: подтверждено ${confirmed}`+(waiting?`, проверить ${waiting}`:'');box.appendChild(title);
 const list=document.createElement('div');list.className='mediaRecoveryList';
 for(const r of rows){const row=document.createElement('div'),text=document.createElement('span');row.className='mediaRecoveryRow';
  const label={confirmed:'Отправлено',rejected:'Не отправлено',cancelled:'Отправка не начиналась',processing:'Результат пока не подтверждён',unknown:'Результат неизвестен',not_found:'Подтверждение пока не найдено'}[r.state];
  text.textContent=r.file.name+' — '+label+(r.caption_included?' · подпись отправлена':'');row.appendChild(text);
  if(pendingStates.includes(r.state)){const button=document.createElement('button');button.type='button';button.className='actionBtn';button.textContent=checking.has(r.operation_id)?'Проверяем…':'Проверить отправку';button.disabled=checking.has(r.operation_id);button.onclick=()=>check(r.operation_id);row.appendChild(button);
   if(r.state==='not_found'){const cancel=document.createElement('button');cancel.type='button';cancel.className='actionBtn';cancel.textContent='Отменить неначатую отправку';cancel.disabled=checking.has(r.operation_id);cancel.onclick=()=>check(r.operation_id,true);row.appendChild(cancel)}}
  list.appendChild(row);
 }
 const hint=document.createElement('div');hint.className='mediaRecoveryHint';hint.setAttribute('role','status');hint.textContent=notice||'Проверка не отправляет файлы повторно. Черновик с вложениями восстанавливается отдельно в этой вкладке.';list.appendChild(hint);box.appendChild(list);box.open=opened||!!notice;
}
window.addEventListener?.('storage',event=>{if(!event.key?.startsWith(prefix+owner+'.')||!authenticated())return;try{const r=JSON.parse(event.newValue||'null');if(r?.actor===owner&&terminalStates.includes(r.state)){const saved=readRecord(owner,r.operation_id);if(saved&&terminalStates.includes(saved.state))window.WorkspaceV2Media?.settleRecovery?.(saved)}}catch(e){}render()});
window.WorkspaceV2MediaRecovery={activate,suspend,configure,begin,result,blocked,blocking,check,entries,render,newBatch:id};
})();
