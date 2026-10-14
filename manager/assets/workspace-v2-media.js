(()=>{
const state={csrf:'',conversationId:0,busy:false,restored:null,notice:''};
const attachmentDrafts=new Map(),captionSent=new Map(),uncertainFiles=new Set();
const MAX_FILES=10; // A bounded selection, sent as individual canonical messages, never an album.
const account=()=>Number(window.WorkspaceV2?.S?.manager?.id||0);
const draftKey=id=>account()+':'+Number(id||0);
const $=id=>document.getElementById(id);
const input=()=>$('replyFile'),selection=()=>$('mediaSelection');
const fileKey=(file,key=draftKey(state.conversationId))=>key+':'+file.name+':'+file.size+':'+(file.lastModified||0);
function files(){return state.restored||Array.from(input()?.files||[]).map(file=>({file,status:uncertainFiles.has(fileKey(file))?'unknown':'ready',url:''}))}
function revoke(rows){rows.forEach(row=>{if(row.url)URL.revokeObjectURL(row.url)})}
function save(rows,id=state.conversationId){state.restored=rows;if(Number(id)>0){if(rows.length)attachmentDrafts.set(draftKey(id),rows);else{attachmentDrafts.delete(draftKey(id));captionSent.delete(draftKey(id))}}}
function preserveDraft(id=state.conversationId){save(files(),id)}
function restoreDraft(id=state.conversationId){if(input())input().value='';state.restored=attachmentDrafts.get(draftKey(id))||[];state.notice='';render()}
function resetDrafts(){attachmentDrafts.forEach(revoke);attachmentDrafts.clear();captionSent.clear();state.restored=[];if(input())input().value='';state.notice='';render()}
function clear(){revoke(files());save([]);if(input())input().value='';state.notice='';render()}
function render(){
 const rows=files(),box=selection();if(!box)return;
 if(!rows.length&&!state.notice){box.textContent='';box.classList.add('hidden');return}
 box.innerHTML=rows.map((row,index)=>`<div class="mediaSelectedItem"><span class="mediaPreview"></span><span class="mediaFileInfo"><span class="mediaFileName"></span><span class="mediaFileSize"></span></span><button class="mediaRemove" type="button" data-index="${index}" aria-label="Убрать вложение ${index+1}">×</button></div>`).join('')+'<div class="mediaSelectionHint" role="status"></div>';
 // querySelector fallback keeps the small non-browser context harness supported.
 const items=box.querySelectorAll?Array.from(box.querySelectorAll('.mediaSelectedItem')):rows.map(()=>box);
 items.forEach((item,index)=>{const row=rows[index],f=row.file;item.querySelector('.mediaFileName').textContent=f.name||'Скриншот';item.querySelector('.mediaFileSize').textContent=row.status==='unknown'?'Исход неизвестен — повтор заблокирован':Number(f.size||0)<1024*1024?`${(Number(f.size||0)/1024).toFixed(1)} КБ`:`${(Number(f.size||0)/1024/1024).toFixed(2)} МБ`;
 const remove=item.querySelector('.mediaRemove');remove.disabled=state.busy||row.status==='unknown';remove.onclick=()=>{if(state.busy||row.status==='unknown')return;const current=files();revoke([current[index]]);current.splice(index,1);save(current);state.notice='';render()};
 if(f.type?.startsWith('image/')&&typeof URL?.createObjectURL==='function'&&item!==box){if(!row.url)row.url=URL.createObjectURL(f);const img=document.createElement('img');img.src=row.url;img.alt='';item.querySelector('.mediaPreview').appendChild(img)}
 });
 box.querySelector('.mediaSelectionHint').textContent=state.notice||`Файлов: ${rows.length} из ${MAX_FILES}. Отправятся отдельно; подпись — к первому. Выбранные файлы сохраняются до обновления страницы.`;box.classList.remove('hidden');
}
function addFiles(incoming){
 const rows=files(),added=Array.from(incoming||[]);let rejected=0;
 for(const file of added){if(!file||Number(file.size||0)===0){rejected++;continue}if(rows.length>=MAX_FILES){rejected++;continue}rows.push({file,status:uncertainFiles.has(fileKey(file))?'unknown':'ready',url:''})}
 save(rows);if(input())input().value='';state.notice=rejected?'Часть файлов не добавлена: пустой файл или больше 10 вложений. Проверьте список.':'';render();return added.length>rejected;
}
function init(){
 input()?.addEventListener('change',()=>{const incoming=Array.from(input().files||[]);if(input())input().value='';addFiles(incoming)});
 $('replyText')?.addEventListener('paste',event=>{
   if($('replyText')?.disabled||$('composer')?.classList.contains('hidden'))return;
   const pasted=Array.from(event.clipboardData?.items||[]).filter(item=>item.kind==='file').map(item=>item.getAsFile()).filter(Boolean);
   if(!pasted.length)return;event.preventDefault();addFiles(pasted);
 });
}
function configure(csrf,conversationId){state.csrf=csrf||'';state.conversationId=Number(conversationId||0)}
function hasFile(){return files().length>0}
async function send(caption=''){
 if(state.busy||!hasFile()||!state.conversationId)return{ok:false,error:'media_not_ready'};
 const generation=window.WorkspaceV2?.S?.authGeneration,manager=account(),target=state.conversationId,sourceKey=draftKey(target),rows=files(),csrf=state.csrf;
 const channel=window.WorkspaceV2?.S?.detail?.conversation?.channel;
 if(channel&&!['max','telegram'].includes(String(channel).toLowerCase()))return{ok:false,error:'unsupported',conversation_id:target,error_message:'Вложения для этого канала пока не поддерживаются. Файлы и текст сохранены.'};
 if(rows.some(row=>row.status==='unknown'))return{ok:false,error:'media_unknown',conversation_id:target,error_message:'Исход отправки файла неизвестен. Проверьте переписку перед повторной отправкой; автоматический повтор заблокирован.'};
 save(rows);state.busy=true;render();let sent=0,captionConfirmed=captionSent.has(sourceKey)&&captionSent.get(sourceKey)===(caption||'');
 const result=(j)=>({...j,conversation_id:target,sent_count:sent,confirmed_count:sent,caption_sent:captionConfirmed,unknown:rows.some(row=>row.status==='unknown'),remaining_count:rows.length});
 const sameSession=()=>generation===window.WorkspaceV2?.S?.authGeneration&&manager===account();
 try{
  for(const row of rows.slice()){
   if(!sameSession())return result({ok:false,error:'stale_session'});
   if(state.conversationId!==target||attachmentDrafts.get(sourceKey)!==rows)return result({ok:false,error:'context_changed',error_message:`Отправлено файлов: ${sent}. Остальные сохранены в исходном диалоге.`});
   state.notice=`Отправляем файл ${sent+1} из ${rows.length+sent}…`;render();
   const data=new FormData();data.append('csrf',csrf);data.append('conversation_id',String(target));data.append('file',row.file);data.append('caption',captionConfirmed?'':caption||'');
   let response,j;
   try{response=await fetch('media-upload.php',{method:'POST',body:data,credentials:'same-origin'});j=await response.json()}
   catch(e){row.status='unknown';uncertainFiles.add(fileKey(row.file,sourceKey));return result({ok:false,error:'media_unknown',error_message:`Отправлено файлов: ${sent}. Исход следующего неизвестен — проверьте переписку. Повтор заблокирован.`})}
   if(!sameSession()){
    // Retain the outcome in the captured source queue without touching the new account UI.
    if(j.ok===true){sent++;captionConfirmed=true;if(attachmentDrafts.get(sourceKey)===rows)captionSent.set(sourceKey,caption||'');const index=rows.indexOf(row);if(index>=0)rows.splice(index,1);revoke([row]);if(attachmentDrafts.get(sourceKey)===rows&&!rows.length){attachmentDrafts.delete(sourceKey);captionSent.delete(sourceKey)}}
    else if(response.status!==401){row.status='unknown';uncertainFiles.add(fileKey(row.file,sourceKey))}
    return result({ok:false,error:'stale_session'});
   }
   if(response.status===401){window.WorkspaceV2?.showAuthRecovery?.();return result({...j,ok:false,error:'unauthorized',http_status:401})}
   if(j.ok===true){sent++;captionConfirmed=true;if(attachmentDrafts.get(sourceKey)===rows)captionSent.set(sourceKey,caption||'');const index=rows.indexOf(row);if(index>=0)rows.splice(index,1);revoke([row]);if(attachmentDrafts.get(sourceKey)===rows&&!rows.length){attachmentDrafts.delete(sourceKey);captionSent.delete(sourceKey)}
    if(state.conversationId===target&&state.restored===rows){if(input())input().value='';render()}continue;}
   // Validation/auth and explicit provider rejection are safe failures. A transport/5xx response is not a receipt.
   const known=['file_required','file_too_large','upload_failed','invalid_upload','invalid_conversation','csrf_invalid','unsupported'].includes(j.error)||j.failure?.category==='unsupported'||response.status===403||Number(j.failure?.http_code)>=400&&Number(j.failure?.http_code)<500;
   if(!known){row.status='unknown';uncertainFiles.add(fileKey(row.file,sourceKey))}
   return result({...j,ok:false,error:known?j.error:'media_unknown',error_message:(sent?`Отправлено файлов: ${sent}. `:'')+(known?(j.error_message||'Файл не отправлен. Исправьте причину и повторите.'):'Исход следующего файла неизвестен. Проверьте переписку; повтор заблокирован.')});
  }
  if(rows.length)return result({ok:false,error:'additional_files_pending',error_message:`Отправлено файлов: ${sent}. Добавленные во время отправки вложения сохранены; отправьте их отдельно.`});
  return result({ok:true});
 }finally{state.busy=false;if(sameSession()&&state.conversationId===target){state.notice='';render()}}
}
window.WorkspaceV2Media={init,configure,hasFile,send,clear,preserveDraft,restoreDraft,resetDrafts,addFiles};
})();
