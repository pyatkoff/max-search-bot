(function(){
'use strict';
const W=window.WorkspaceV2,{S,$,esc}=W,files=new Map(),operations=new Map();
const labels={image:'Изображение',video:'Видео',audio:'Аудио',file:'Файл'};
const kind=mime=>/^(image|video|audio)\//.exec(String(mime||''))?.[1]||'file';
const bytes=size=>size<1000000?Math.ceil(size/1000)+' КБ':(size/1000000).toLocaleString('ru-RU',{maximumFractionDigits:1})+' МБ';
const fileKey=(scope,id)=>scope+':'+id;
const uuid=()=>Array.from(window.crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');
async function digest(value){return Array.from(new Uint8Array(await window.crypto.subtle.digest('SHA-256',value)),x=>x.toString(16).padStart(2,'0')).join('')}
function reset(){files.clear();operations.clear()}
function release(editor,scope){for(const row of editor?.materials||[])if(row.client_id)files.delete(fileKey(scope,row.client_id))}
function forgetOperation(id){operations.delete(id)}
function packet(editor,scope){
 const uploads=[],materials=[],hashes=[];
 if(editor.preparing_files)throw Error('Дождитесь проверки выбранных файлов.');
 for(const row of editor.materials||[]){
  if(row.id){materials.push({id:row.id});continue}
  const file=files.get(fileKey(scope,row.client_id));
  if(!file||file.size!==row.size||file.name!==row.name)throw Error('После перезагрузки выберите несохранённые файлы заново. Сохранённые материалы остаются на месте.');
  materials.push({upload_index:uploads.length});uploads.push(file);hashes.push(row.sha256);
 }
 return {materials,uploads,upload_hashes:hashes};
}
function remember(id,uploads){if(uploads.length)operations.set(id,uploads)}
function pendingFiles(pending){return pending.uploads?.length?operations.get(pending.operation_id)||null:[]}
async function recover(pending,selected,valid,retained={}){
 const expected=pending.uploads||[],snapshots=[];
 if(selected.length!==expected.length)throw Error('Выберите те же '+expected.length+' файлов в исходном порядке.');
 for(let i=0;i<selected.length;i++){
  const file=selected[i],item=expected[i];
  const buffer=await file.arrayBuffer();
  if(file.name!==item.name||file.size!==item.size||await digest(buffer)!==item.sha256)throw Error('Выбранные файлы отличаются от исходного сохранения. Изменение не повторялось.');
  if(!valid())return false;
  snapshots.push(new File([buffer],file.name,{type:file.type,lastModified:file.lastModified}));
 }
 if(!valid())return false;remember(pending.operation_id,snapshots);
 // Retain the same verified Files for editing after a definitive rejection.
 // The command's saved order maps upload indexes to this scoped editor only.
 for(const [index,item]of (pending.data?.materials||[]).entries()){
  const row=retained.editor?.materials?.[index],file=snapshots[item.upload_index];
  if(retained.scope&&row?.client_id&&file&&row.name===file.name&&row.size===file.size&&row.sha256===expected[item.upload_index]?.sha256)files.set(fileKey(retained.scope,row.client_id),file);
 }
 return true;
}
function editor(node,options){
 const {get,valid,change,scope,limits}=options;
 const state=get();
 node.innerHTML=`<label for="qrSource">Источник условий<input id="qrSource" maxlength="500" value="${esc(state.source_label||'').replace(/"/g,'&quot;')}" placeholder="Например, прайс компании от 11 ноября"></label><label class="qrReviewCheck"><input id="qrReviewed" type="checkbox" ${state.reviewed?'checked':''}>Условия и материалы проверены</label><div class="qrMaterialsHeading"><strong>Материалы по порядку</strong><span>До ${Number(limits?.max_files)||10} файлов</span></div><input id="qrMaterialFiles" type="file" multiple aria-label="Добавить материалы к шаблону"><div id="qrMaterialEditorRows" class="qrMaterialRows"></div><p id="qrMaterialEditorStatus" class="qrHint" role="status"></p>`;
 const status=text=>{if(valid()&&$('qrMaterialEditorStatus'))$('qrMaterialEditorStatus').textContent=text};
 function render(){
  if(!valid())return;
  const rows=get().materials||[],target=$('qrMaterialEditorRows');if(!target)return;
  target.innerHTML=rows.length?rows.map((row,index)=>`<div class="qrMaterialRow"><span class="qrMaterialNumber">${index+1}</span><div><strong>${esc(row.name)}</strong><span>${esc(labels[kind(row.mime)])} · ${esc(bytes(row.size))}${row.client_id&&!files.has(fileKey(scope,row.client_id))?' · Выберите файл заново':''}</span></div><div class="qrMaterialOrder"><button type="button" data-qr-material-action="up" data-index="${index}" aria-label="Выше: ${esc(row.name).replace(/"/g,'&quot;')}" ${index===0?'disabled':''}>↑</button><button type="button" data-qr-material-action="down" data-index="${index}" aria-label="Ниже: ${esc(row.name).replace(/"/g,'&quot;')}" ${index===rows.length-1?'disabled':''}>↓</button><button type="button" data-qr-material-action="remove" data-index="${index}" aria-label="Убрать: ${esc(row.name).replace(/"/g,'&quot;')}">×</button></div></div>`).join(''):'<p class="qrHint">Добавьте документы или фотографии. Порядок сохранится в шаблоне.</p>';
  target.onclick=event=>{
   const button=event.target.closest('[data-qr-material-action]');if(!button||!valid()||options.busy())return;
   const index=Number(button.dataset.index),next=[...(get().materials||[])];if(!Number.isInteger(index)||!next[index])return;
   if(button.dataset.qrMaterialAction==='remove'){if(next[index].client_id)files.delete(fileKey(scope,next[index].client_id));next.splice(index,1)}
   else{const to=index+(button.dataset.qrMaterialAction==='up'?-1:1);if(to<0||to>=next.length)return;[next[index],next[to]]=[next[to],next[index]]}
   change({materials:next,reviewed:false});if($('qrReviewed'))$('qrReviewed').checked=false;render();
  };
 }
 $('qrSource').oninput=()=>{if(!valid())return;change({source_label:$('qrSource').value,reviewed:false});$('qrReviewed').checked=false};
 $('qrReviewed').onchange=()=>{if(valid())change({reviewed:$('qrReviewed').checked})};
 $('qrMaterialFiles').onchange=async event=>{
  if(!valid()||options.busy())return;
  const selected=Array.from(event.target.files||[]),prior=get().materials||[],max=Number(limits?.max_files)||10;
  event.target.value='';
  if(prior.length+selected.length>max){status('В шаблоне не больше '+max+' файлов. Выбранные файлы не добавлены.');return}
  const total=selected.reduce((sum,f)=>sum+f.size,0),cap=Number(limits?.max_file_bytes)||50000000,totalCap=Number(limits?.max_total_bytes)||50000000;
  if(selected.some(f=>f.size<=0||f.size>cap)||total>totalCap){status('Выберите непустые файлы до '+bytes(cap)+' каждый; вместе — до '+bytes(totalCap)+'.');return}
  change({preparing_files:true});status('Проверяем выбранные файлы…');const additions=[];
  try{
   for(const file of selected){const buffer=await file.arrayBuffer(),hash=await digest(buffer);if(!valid())return;const id=uuid();additions.push({client_id:id,name:file.name,size:file.size,mime:file.type||'application/octet-stream',sha256:hash});
    // Retain immutable bytes, independent of the native picker after reset.
    files.set(fileKey(scope,id),new File([buffer],file.name,{type:file.type,lastModified:file.lastModified}));}
   if(!valid())return;
   change({materials:[...(get().materials||[]),...additions],reviewed:false,preparing_files:false});$('qrReviewed').checked=false;render();status('Файлы подготовлены. Они сохранятся вместе с шаблоном.');
  }catch(error){for(const row of additions)files.delete(fileKey(scope,row.client_id));if(valid()){change({preparing_files:false});status('Не удалось прочитать выбранный файл. Выберите его снова.')}}
  finally{if(!valid())for(const row of additions)files.delete(fileKey(scope,row.client_id))}
 };
 render();
}
function preview(reply){
 const source=reply.source_label?esc(reply.source_label):'Источник не указан';
 let review='Нужна проверка условий',verified=false;
 if(!reply.requires_review&&reply.reviewed_at){const date=new Date(reply.reviewed_at);if(Number.isFinite(date.getTime())){verified=true;review='Проверено '+new Intl.DateTimeFormat('ru-RU',{timeZone:'Europe/Moscow',dateStyle:'short'}).format(date)}}
 return `<div class="qrSourceSummary"><span class="${verified?'qrReviewed':'qrNeedsReview'}">${esc(review)}</span><span>${source}</span></div>`+((reply.materials||[]).length?`<section class="qrMaterialPreview" aria-label="Материалы шаблона"><strong>Материалы · ${reply.materials.length}</strong><ol>${reply.materials.map(row=>`<li><strong>${esc(row.name)}</strong><span>${esc(labels[kind(row.mime)])} · ${esc(bytes(row.size))}</span></li>`).join('')}</ol><p>Отправятся отдельными сообщениями по порядку. Подпись — к первому файлу.</p></section>`:'');
}
async function write(endpoint,action,data,uploads,valid){
 const generation=S.authGeneration,form=new FormData();form.append('command',JSON.stringify({action,csrf:S.csrf,...data}));for(const file of uploads)form.append('materials[]',file,file.name);
 const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',body:form});
 const result=await response.json().catch(()=>({ok:false,error:'invalid_response'}));
 if(!valid()||generation!==S.authGeneration)return {ok:false,error:'stale_session'};
 if(response.status===401){W.showAuthRecovery();return {ok:false,error:'stale_session'}}
 return response.ok?result:{...result,ok:false,http_status:response.status};
}
async function download(endpoint,data,item,valid){
 const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'material',csrf:S.csrf,...data,material_id:item.id})});
 if(!valid())throw Error('stale_context');
 if(response.status===401){W.showAuthRecovery();throw Error('stale_context')}
 if(!response.ok)throw Error('Материал больше недоступен. Обновите шаблон; текущий черновик сохранён.');
 if(response.headers.get('X-Material-SHA256')!==item.sha256||Number(response.headers.get('Content-Length'))!==item.size)throw Error('Не удалось подтвердить сохранённый файл. Черновик не изменён.');
 const chunks=[];let length=0;
 if(response.body?.getReader){
  const reader=response.body.getReader();try{while(true){const row=await reader.read();if(row.done)break;length+=row.value.length;if(length>item.size||!valid()){await reader.cancel();throw Error('stale_context')}chunks.push(row.value)}}finally{reader.releaseLock()}
 }else{const value=new Uint8Array(await response.arrayBuffer());length=value.length;chunks.push(value)}
 if(length!==item.size||!valid())throw Error('Файл загружен не полностью. Черновик не изменён.');
 const blob=new Blob(chunks,{type:item.mime});if(await digest(await blob.arrayBuffer())!==item.sha256||!valid())throw Error('Не удалось подтвердить содержимое файла. Черновик не изменён.');
 return new File([blob],item.name,{type:item.mime});
}
function problems(rows,policy,text){
 const errors=[],warnings=[];
 if(rows.length&&!policy.supported)errors.push('В этом канале отправка файлов пока недоступна.');
 if(rows.length>policy.max_files)errors.push('В ответе не больше '+policy.max_files+' файлов.');
 if(policy.max_total_bytes&&rows.reduce((sum,row)=>sum+row.size,0)>policy.max_total_bytes)errors.push('Общий размер файлов должен быть не больше '+bytes(policy.max_total_bytes)+'.');
 for(const row of rows){
  const type=kind(row.mime||row.type),limit=policy.max_file_bytes_by_type?.[type];
  if(row.size<=0||(limit&&row.size>limit))errors.push(row.name+': размер должен быть от 1 байта до '+bytes(limit)+'.');
  if(type==='image'){
   if(!policy.image_formats?.includes(row.mime||row.type))warnings.push(row.name+': формат изображения требует проверки для этого канала.');
   if(row.width&&row.height&&((policy.max_image_edge&&Math.max(row.width,row.height)>policy.max_image_edge)||(policy.max_photo_dimension_sum&&row.width+row.height>policy.max_photo_dimension_sum)||(policy.max_photo_ratio&&Math.max(row.width/row.height,row.height/row.width)>policy.max_photo_ratio)))errors.push(row.name+': размеры изображения не подходят для этого канала.');
  }
 }
 if(rows.length&&policy.caption_warning_limit&&Array.from(text).length>policy.caption_warning_limit)errors.push('Сократите подпись до '+policy.caption_warning_limit+' символов для этого канала.');
 return {errors,warnings};
}
async function insert({endpoint,reply,text,context,project,valid,notice}){
 const media=window.WorkspaceV2Media,snapshot=media?.captureSelection?.(),input=$('replyText');
 if(!snapshot||!input||!valid())return false;
 const original={value:input.value,start:input.selectionStart,end:input.selectionEnd,direction:input.selectionDirection};
 const sameDraft=()=>valid()&&input.isConnected&&input.value===original.value&&input.selectionStart===original.start&&input.selectionEnd===original.end&&input.selectionDirection===original.direction;
 const data={id:reply.id,version:reply.version,conversation_id:context.conversationId,project_key:project,expected_manager_id:context.managerId};
 const prepare=()=>W.request(endpoint,'prepare_insert',data,{isCurrent:sameDraft});
 const first=await prepare();if(!sameDraft())return false;
 if(!first?.ok||first.reply?.project_key!==project||first.reply?.version!==reply.version)throw Error('Шаблон изменён или больше недоступен. Обновите его перед вставкой.');
 const rows=first.reply.materials||[],existing=snapshot.rows.map(x=>x.row.file).filter(Boolean);
 const combined=original.value+(original.value&&!original.value.endsWith('\n')?'\n':'')+text;
 if(!first.constraints||!first.constraints.max_file_bytes_by_type)throw Error('Не удалось проверить параметры файлов для этого канала.');
 const inspection=problems([...existing,...rows],first.constraints,combined);
 if(inspection.errors.length)throw Error(inspection.errors.join(' '));
 if(inspection.warnings.length)notice(inspection.warnings.join(' '));
 const prepared=[];for(const item of rows){prepared.push(await download(endpoint,data,item,sameDraft));if(!sameDraft())return false}
 const final=await prepare();if(!sameDraft())return false;
 if(!final?.ok||final.reply?.version!==reply.version||JSON.stringify(final.reply.materials)!==JSON.stringify(rows))throw Error('Материалы изменились во время загрузки. Текущий черновик сохранён.');
 return media.appendPrepared(prepared,snapshot,()=>sameDraft()&&window.WorkspaceV2Conversation?.insertQuickReply?.(text,context)===true);
}
window.WorkspaceV2QuickReplyMaterials={editor,preview,packet,remember,pendingFiles,recover,forgetOperation,release,reset,write,insert,problems,kind,bytes};
})();
