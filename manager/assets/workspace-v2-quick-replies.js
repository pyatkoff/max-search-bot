(function(){
'use strict';
const W=window.WorkspaceV2,{S,$,esc}=W;
const endpoint='quick-replies-api.php',storagePrefix='workspaceV2.quickReplyDraft.';
const attr=value=>esc(value).replace(/"/g,'&quot;');
let dialog=null,bound=false,returnFocus=null,epoch=0,searchTimer=null,loading=null,loadTicket=0;
let owner=0,ownerGeneration=0,rows=[],categories=[],selected=null,preview='',replyContext=null;
let editor=null,pending=null,mutationBusy=false,query={scope:'all',search:'',category:'',favorites_only:false},nextOffset=null;
let lastError='',listFailed=false;

function identity(){return !S.authExpired?Number(S.manager?.id||0):0}
function authenticated(){return owner>0&&identity()===owner&&Number(S.authGeneration||0)===ownerGeneration}
function contextValid(ctx=replyContext){
  const now=window.WorkspaceV2Conversation?.captureReplyContext?.();
  return !!ctx&&!!now&&['conversationId','managerId','authGeneration','openGeneration'].every(key=>Number(ctx[key])===Number(now[key]));
}
function placeholders(text){return Array.from(String(text||'').matchAll(/\{\{([^{}\n]+)\}\}|\{([^{}\n]+)\}/g),match=>match[1]||match[2]);}
function resolveText(text,values={}){
  return String(text||'').replace(/\{\{([^{}\n]+)\}\}|\{([^{}\n]+)\}/g,(token,a,b)=>{
    const key=String(a||b).trim(),value=values[key];
    return ['manager_name','client_name'].includes(key)&&typeof value==='string'&&value.trim()?value:token;
  });
}
function knownValues(){
  const conversation=S.detail?.conversation||{};
  // A shared internal alias is for colleagues, never a salutation to the client.
  const client=String(Object.prototype.hasOwnProperty.call(conversation,'original_display_name')?conversation.original_display_name||'':conversation.display_name||'').trim();
  return {manager_name:String(S.manager?.display_name||S.manager?.login||'').trim(),client_name:contextValid()&&client!=='Турист'?client:''};
}
function draftKey(){return authenticated()?storagePrefix+owner:''}
function persist(){
  const key=draftKey();if(!key)return;
  try{if(editor||pending)sessionStorage.setItem(key,JSON.stringify({editor,pending}));else sessionStorage.removeItem(key)}catch(e){}
}
function restore(){
  const key=draftKey();if(!key)return;
  try{
    const saved=JSON.parse(sessionStorage.getItem(key)||'null');
    if(saved?.editor&&typeof saved.editor.body==='string')editor=saved.editor;
    if(saved?.pending&&typeof saved.pending.operation_id==='string'&&typeof saved.pending.action==='string')pending=saved.pending;
  }catch(e){}
}
function operationId(){
  const bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);
  return Array.from(bytes,x=>x.toString(16).padStart(2,'0')).join('');
}
function setStatus(text='',kind=''){
  const node=$('qrStatus');if(!node)return;
  node.textContent=text;node.className='qrStatus'+(kind?' '+kind:'');
}
function errorText(result){
  const errors={forbidden:'Недостаточно прав. Общие ответы редактирует администратор.',not_found:'Ответ больше недоступен. Обновите список.',conflict:'Ответ изменён в другом окне. Ваш текст сохранён. Откройте актуальную версию перед сохранением.',invalid_title:'Добавьте название ответа.',invalid_body:'Добавьте текст ответа.',invalid_category:'Категория должна быть не длиннее 64 символов.',invalid_scope:'Выберите доступную область ответа.',network_error:'Нет связи с сервером.',invalid_response:'Не удалось прочитать ответ сервера.',operation_mismatch:'Результат операции требует проверки. Не повторяйте изменение.'};
  return errors[result?.error]||'Не удалось выполнить действие. Ваш текст сохранён.';
}
async function request(action,data={}){
  const capturedEpoch=epoch;
  if(!authenticated())return {ok:false,error:'stale_session'};
  let result;
  try{result=await W.request(endpoint,action,data)}catch(e){result={ok:false,error:S.authExpired?'stale_session':'network_error'}}
  return capturedEpoch===epoch&&authenticated()?result:{ok:false,error:'stale_session'};
}
function ensureDialog(){
  if(dialog)return dialog;
  dialog=document.createElement('dialog');dialog.id='quickRepliesDialog';dialog.className='qrDialog';
  dialog.setAttribute('aria-labelledby','qrHeading');
  dialog.innerHTML=`<div class="qrShell">
    <header class="qrHead"><div><div class="qrEyebrow">РАБОЧАЯ БИБЛИОТЕКА</div><h2 id="qrHeading">Быстрые ответы</h2><p>Выберите текст, дополните и вставьте в ответ клиенту.</p></div><button id="qrClose" type="button" class="qrIcon" aria-label="Закрыть быстрые ответы">×</button></header>
    <div id="qrStatus" class="qrStatus" role="status" aria-live="polite"></div>
    <div id="qrPending" class="qrPending" hidden></div>
    <div class="qrWorkspace"><section class="qrLibrary" aria-label="Библиотека ответов">
      <div class="qrSearchRow"><label class="qrSearchLabel" for="qrSearch"><span class="qrVisuallyHidden">Поиск по названию и тексту</span><input id="qrSearch" type="search" placeholder="Найти ответ…" autocomplete="off"></label><button id="qrCreate" class="qrButton qrPrimary" type="button">+ Создать</button></div>
      <div id="qrScopes" class="qrScopes" role="group" aria-label="Видимость ответов"><button type="button" data-qr-scope="all" aria-pressed="true">Все</button><button type="button" data-qr-scope="personal" aria-pressed="false">Мои</button><button type="button" data-qr-scope="shared" aria-pressed="false">Общие</button><button type="button" data-qr-scope="favorites" aria-pressed="false">★ Избранные</button></div>
      <label class="qrCategoryLabel" for="qrCategory">Категория<select id="qrCategory"><option value="">Все категории</option></select></label>
      <div id="qrList" class="qrList" aria-label="Ответы"></div><button id="qrMore" class="qrMore qrButton" type="button" hidden>Показать ещё</button>
      <p class="qrLibraryHint">Мои ответы видны только вам. Общие — всем менеджерам.</p>
    </section><section id="qrDetail" class="qrDetail" aria-label="Текст быстрого ответа"></section></div>
  </div>`;
  document.body.appendChild(dialog);
  $('qrClose').onclick=close;
  dialog.addEventListener('cancel',e=>{e.preventDefault();close()});
  // Workspace shortcuts belong to the conversation behind this modal.
  dialog.addEventListener('keydown',event=>event.stopPropagation());
  dialog.addEventListener('close',()=>{if(returnFocus?.isConnected&&!returnFocus.disabled)returnFocus.focus({preventScroll:true})});
  $('qrSearch').addEventListener('input',()=>{
    query.search=$('qrSearch').value;clearTimeout(searchTimer);
    // Invalidate the previous read immediately, before the debounce fires.
    loadTicket++;searchTimer=setTimeout(()=>load(),220);
  });
  $('qrCategory').addEventListener('change',()=>{query.category=$('qrCategory').value;load()});
  $('qrScopes').addEventListener('click',event=>{
    const button=event.target.closest('[data-qr-scope]');if(!button)return;
    const scope=button.dataset.qrScope;query.scope=scope==='favorites'?'all':scope;query.favorites_only=scope==='favorites';
    $('qrScopes').querySelectorAll('button').forEach(node=>node.setAttribute('aria-pressed',String(node===button)));load();
  });
  $('qrCreate').onclick=()=>beginEditor();$('qrMore').onclick=()=>load(true);
  $('qrList').addEventListener('click',event=>{
    const select=event.target.closest('[data-qr-select]');
    if(select)selectReply(Number(select.dataset.qrSelect));
    if(event.target.closest('[data-qr-retry]'))load();
  });
  return dialog;
}
function renderPending(){
  const node=$('qrPending');if(!node)return;
  node.hidden=!pending;
  if(!pending)return;
  node.innerHTML='<span>Результат изменения уточняется. Новые изменения доступны после проверки.</span><button id="qrResolve" class="qrButton" type="button">Уточнить результат</button>';
  $('qrResolve').disabled=mutationBusy;$('qrResolve').onclick=reconcile;
}
function renderCategories(){
  const node=$('qrCategory');if(!node)return;
  const values=Array.from(new Set([...categories,query.category].filter(Boolean)));
  node.innerHTML='<option value="">Все категории</option>'+values.map(value=>`<option value="${attr(value)}">${esc(value)}</option>`).join('');node.value=query.category;
}
function renderList(isLoading=false){
  const node=$('qrList');if(!node)return;
  node.setAttribute('aria-busy',String(isLoading));
  if(isLoading&&!rows.length)node.innerHTML='<div class="qrEmpty"><span class="qrLoadingMark" aria-hidden="true">···</span><strong>Загружаем ответы</strong><span>Переписка и черновик остаются на месте.</span></div>';
  else if(!rows.length)node.innerHTML=listFailed?'<div class="qrEmpty"><strong>Не удалось загрузить ответы</strong><span>Проверьте соединение и попробуйте снова.</span><button class="qrButton" type="button" data-qr-retry>Повторить загрузку</button></div>':`<div class="qrEmpty"><strong>${query.search||query.category||query.favorites_only?'Подходящих ответов нет':'Пока нет быстрых ответов'}</strong><span>${query.search||query.category||query.favorites_only?'Измените поиск или фильтры.':'Создайте приветствие, уточнение или готовый ответ на частый вопрос.'}</span></div>`;
  else node.innerHTML=rows.map(reply=>`<button type="button" class="qrItem${Number(selected?.id)===Number(reply.id)?' selected':''}" data-qr-select="${Number(reply.id)}" aria-pressed="${Number(selected?.id)===Number(reply.id)}"><span class="qrItemTop"><strong>${esc(reply.title)}</strong>${reply.is_favorite?'<span class="qrStar" aria-label="Избранное">★</span>':''}</span><span class="qrItemBody">${esc(reply.body)}</span><span class="qrItemMeta"><span>${reply.scope==='shared'?'Общий':'Мой'}</span>${reply.category?`<span>· ${esc(reply.category)}</span>`:''}</span></button>`).join('');
  if(listFailed&&rows.length)node.insertAdjacentHTML('beforeend','<div class="qrEmpty"><strong>Не удалось загрузить следующую страницу</strong><button class="qrButton" type="button" data-qr-retry>Повторить загрузку</button></div>');
  $('qrMore').hidden=nextOffset===null;$('qrMore').disabled=isLoading;
}
async function load(append=false){
  if(!authenticated())return;
  const params={...query,offset:append?nextOffset||0:0,limit:50},key=JSON.stringify(params),capturedEpoch=epoch;
  if(loading?.key===key&&loading.ticket===loadTicket)return loading.promise;
  const ticket=++loadTicket;
  if(!append){rows=[];nextOffset=null}listFailed=false;renderList(true);
  const operation={key,ticket,promise:null};loading=operation;
  operation.promise=(async()=>{
    const result=await request('list',params);
    if(capturedEpoch!==epoch||ticket!==loadTicket||!authenticated())return;
    if(!result?.ok){listFailed=true;renderList();return}
    const received=Array.isArray(result.replies)?result.replies:[];
    rows=append?Array.from(new Map([...rows,...received].map(reply=>[Number(reply.id),reply])).values()):received;
    categories=Array.isArray(result.categories)?result.categories:[];
    nextOffset=result.has_more&&Number.isFinite(Number(result.next_offset))?Number(result.next_offset):null;
    renderCategories();renderList();
  })().finally(()=>{if(loading===operation)loading=null});
  return operation.promise;
}
function selectReply(id){
  if(editor){setStatus('Сначала сохраните или отмените редактирование. Черновик шаблона сохранён.','warn');return}
  selected=rows.find(reply=>Number(reply.id)===id)||null;
  replyContext=window.WorkspaceV2Conversation?.captureReplyContext?.()||null;
  preview=resolveText(selected?.body||'',knownValues());lastError='';setStatus();renderList();renderDetail();
}
function editorMarkup(){
  const draft=editor,isAdmin=S.manager?.role==='admin';
  return `<form id="qrEditor" class="qrEditor"><div class="qrDetailTop"><div><span class="qrBadge">${draft.id?'Редактирование':'Новый ответ'}</span><h3>${draft.id?'Изменить быстрый ответ':'Создать быстрый ответ'}</h3></div></div>
    <label for="qrTitle">Название<input id="qrTitle" type="text" maxlength="120" required value="${attr(draft.title)}" placeholder="Например, приветствие"></label>
    <div class="qrEditorMeta"><label for="qrEditCategory">Категория<input id="qrEditCategory" maxlength="64" list="qrCategorySuggestions" value="${attr(draft.category)}" placeholder="Например, знакомство"></label><label for="qrEditScope">Видимость<select id="qrEditScope" ${draft.id?'disabled':''}><option value="personal">Только мне</option>${isAdmin?'<option value="shared">Всем менеджерам</option>':''}</select></label></div>
    <datalist id="qrCategorySuggestions">${categories.map(value=>`<option value="${attr(value)}"></option>`).join('')}</datalist>
    <label class="qrBodyLabel" for="qrBody">Текст ответа<textarea id="qrBody" rows="8" maxlength="8000" required placeholder="Напишите готовый текст…">${esc(draft.body)}</textarea></label>
    <div class="qrVariables"><span>Подставить имя:</span><button type="button" data-qr-variable="manager_name">Менеджера</button><button type="button" data-qr-variable="client_name">Клиента</button></div>
    <p id="qrVisibilityNote" class="qrHint"></p><p class="qrHint">Перед вставкой можно изменить текст. Если имя неизвестно, потребуется заменить подсказку.</p>
    ${lastError?`<div class="qrInlineError" role="alert">${esc(lastError)}</div>`:''}
    <div class="qrEditorActions"><button id="qrSave" class="qrButton qrPrimary" type="submit">Сохранить ответ</button><button id="qrCancelEdit" class="qrButton" type="button">Отмена</button>${draft.conflict?'<button id="qrReloadConflict" class="qrButton" type="button">Открыть актуальную версию</button>':''}</div></form>`;
}
function renderDetail(){
  const node=$('qrDetail');if(!node)return;
  if(editor){
    node.innerHTML=editorMarkup();$('qrEditScope').value=editor.scope==='shared'&&S.manager?.role==='admin'?'shared':'personal';
    const persistFields=()=>{
      editor={...editor,title:$('qrTitle').value,body:$('qrBody').value,category:$('qrEditCategory').value,scope:$('qrEditScope').value};persist();updateVisibility();
    };
    ['qrTitle','qrBody','qrEditCategory'].forEach(id=>$(id).addEventListener('input',persistFields));$('qrEditScope').addEventListener('change',persistFields);
    $('qrEditor').addEventListener('submit',event=>{event.preventDefault();saveEditor()});
    $('qrCancelEdit').onclick=()=>{if(mutationBusy||pending)return;editor=null;lastError='';persist();renderDetail();$('qrCreate')?.focus()};
    node.querySelectorAll('[data-qr-variable]').forEach(button=>button.onclick=()=>{
      const input=$('qrBody'),start=input.selectionStart,end=input.selectionEnd,token='{'+button.dataset.qrVariable+'}';
      input.setRangeText(token,start,end,'end');persistFields();input.focus();
    });
    if($('qrReloadConflict'))$('qrReloadConflict').onclick=()=>{
      const current=editor.conflict;if(!current)return;
      if(!window.confirm('Открыть актуальную версию? Несохранённый текст этого шаблона будет заменён.'))return;
      editor={id:current.id,version:current.version,title:current.title,body:current.body,category:current.category,scope:current.scope};lastError='';persist();renderDetail();
    };
    updateVisibility();updateBusy();return;
  }
  if(!selected){node.innerHTML='<div class="qrWelcome"><div class="qrWelcomeIcon" aria-hidden="true">↗</div><h3>Меньше повторного набора</h3><p>Выберите ответ слева или создайте свой. Текст появится здесь для проверки перед вставкой.</p><span>Отправка клиенту — отдельное действие.</span></div>';return}
  node.innerHTML=`<div class="qrDetailTop"><div><span class="qrBadge">${selected.scope==='shared'?'Общий ответ':'Мой ответ'}</span><h3>${esc(selected.title)}</h3>${selected.category?`<p>${esc(selected.category)}</p>`:''}</div><button id="qrFavorite" class="qrIcon qrFavorite" type="button" aria-label="${selected.is_favorite?'Убрать из избранных':'Добавить в избранные'}" aria-pressed="${!!selected.is_favorite}">${selected.is_favorite?'★':'☆'}</button></div>
    <div class="qrTools">${selected.can_edit?'<button id="qrEdit" class="qrButton" type="button">Изменить</button>':'<span class="qrHint">Общий ответ редактирует администратор</span>'}<button id="qrCopy" class="qrButton" type="button">Копировать в мои</button>${selected.can_edit?'<button id="qrDelete" class="qrButton qrDanger" type="button">Удалить</button>':''}</div>
    <label class="qrPreviewLabel" for="qrPreview">Текст для вставки<textarea id="qrPreview" rows="8">${esc(preview)}</textarea></label><div id="qrPlaceholderStatus" class="qrPlaceholderStatus" role="status"></div>
    <div class="qrInsertFooter"><button id="qrInsert" class="qrButton qrPrimary" type="button">Вставить в ответ</button><p>Добавим текст в текущий черновик. Вложения сохранятся.</p></div>`;
  $('qrPreview').addEventListener('input',()=>{preview=$('qrPreview').value;updateInsertion()});
  $('qrInsert').onclick=insert;$('qrFavorite').onclick=()=>mutate('favorite',{id:selected.id,is_favorite:!selected.is_favorite});
  $('qrCopy').onclick=()=>mutate('copy',{id:selected.id});
  if($('qrEdit'))$('qrEdit').onclick=()=>beginEditor(selected);
  if($('qrDelete'))$('qrDelete').onclick=()=>{
    if(window.confirm(selected.scope==='shared'?'Удалить общий ответ для всех менеджеров?':'Удалить этот быстрый ответ?'))mutate('delete',{id:selected.id,version:selected.version});
  };
  updateInsertion();updateBusy();
}
function updateVisibility(){
  const note=$('qrVisibilityNote');if(note)note.textContent=editor?.scope==='shared'?'Этот ответ будет доступен всем менеджерам. Редактирование — у администраторов.':'Этот ответ будет доступен только вашему аккаунту, в том числе на другом устройстве.';
}
function updateInsertion(){
  const button=$('qrInsert'),note=$('qrPlaceholderStatus');if(!button||!note)return;
  const unresolved=placeholders(preview),valid=contextValid();
  button.disabled=mutationBusy||!!pending||!valid||!preview.trim()||unresolved.length>0;
  note.className='qrPlaceholderStatus'+(unresolved.length||!valid?' warn':'');
  note.textContent=!valid?'Выберите ответ заново в текущем диалоге, чтобы вставить текст.':unresolved.length?'Замените подсказки в тексте: '+Array.from(new Set(unresolved)).join(', '):'Текст можно изменить здесь и после вставки.';
}
function updateBusy(){
  const disabled=mutationBusy||!!pending;
  ['qrCreate','qrFavorite','qrCopy','qrEdit','qrDelete','qrSave','qrCancelEdit','qrReloadConflict'].forEach(id=>{const node=$(id);if(node)node.disabled=disabled});
  if(editor&&$('qrEditor'))$('qrEditor').querySelectorAll('input,textarea,select,[data-qr-variable]').forEach(node=>{node.disabled=disabled||(node.id==='qrEditScope'&&!!editor.id)});
  if($('qrSave'))$('qrSave').textContent=mutationBusy?'Сохраняем…':'Сохранить ответ';
  renderPending();updateInsertion();
}
function beginEditor(reply=null){
  if(mutationBusy||pending)return;
  if(editor){setStatus('Продолжайте сохранённый черновик или отмените редактирование.','warn');$('qrTitle')?.focus();return}
  editor=reply?{id:reply.id,version:reply.version,title:reply.title,body:reply.body,category:reply.category||'',scope:reply.scope}:{id:0,title:'',body:'',category:query.category,scope:'personal'};
  lastError='';persist();setStatus();renderDetail();$('qrTitle').focus();
}
function saveEditor(){
  if(!editor||mutationBusy||pending)return;
  if(!editor.title.trim()||!editor.body.trim()){setStatus('Добавьте название и текст ответа.','warn');return}
  const data={title:editor.title,body:editor.body,category:editor.category,scope:editor.scope};
  if(editor.id){data.id=editor.id;data.version=editor.version;delete data.scope}
  return mutate(editor.id?'update':'create',data);
}
function unknownResult(result){return !result||['network_error','invalid_response','operation_mismatch','operation_pending'].includes(result.error)||Number(result.http_status||0)>=500}
async function mutate(action,data){
  if(!authenticated()||mutationBusy||pending)return;
  const capturedEpoch=epoch;
  pending={action,data,operation_id:operationId()};mutationBusy=true;persist();updateBusy();setStatus('Сохраняем изменение…');
  const result=await request(action,{...data,operation_id:pending.operation_id});
  if(capturedEpoch!==epoch||!authenticated())return;
  mutationBusy=false;
  if(unknownResult(result)){setStatus('Не удалось подтвердить результат. Уточните его перед следующим изменением.','warn');updateBusy();return}
  finishMutation(result,action);updateBusy();
}
function finishMutation(result,action){
  pending=null;mutationBusy=false;
  if(!result?.ok){
    lastError=errorText(result);
    if(result?.error==='conflict'&&result.current&&editor)editor.conflict=result.current;
    persist();setStatus(lastError,'error');renderDetail();return;
  }
  if(['create','update'].includes(action)){editor=null;lastError=''}
  if(result.reply){
    const preservePreview=action==='favorite'&&Number(selected?.id)===Number(result.reply.id);
    selected=result.reply;
    if(!preservePreview){replyContext=window.WorkspaceV2Conversation?.captureReplyContext?.()||null;preview=resolveText(selected.body,knownValues())}
  }
  if(action==='delete'){selected=null;preview=''}
  persist();renderDetail();setStatus({create:'Ответ создан.',update:'Ответ сохранён.',copy:'Копия добавлена в ваши ответы.',delete:'Ответ удалён.',favorite:'Избранное обновлено.'}[action]||'Изменение сохранено.','success');
  // Follow-up read is deliberately independent of the confirmed write.
  void load();
}
async function reconcile(){
  if(!pending||mutationBusy||!authenticated())return;
  const capturedEpoch=epoch,operation=pending;mutationBusy=true;updateBusy();
  const receipt=await request('operation',{operation_id:operation.operation_id});
  if(capturedEpoch!==epoch||!authenticated()||pending!==operation)return;
  if(receipt?.ok&&receipt.found){finishMutation(receipt.result,operation.action);updateBusy();return}
  if(receipt?.ok&&receipt.found===false){
    // A checked missing receipt can safely use the identical idempotency key.
    const result=await request(operation.action,{...operation.data,operation_id:operation.operation_id});
    if(capturedEpoch!==epoch||!authenticated()||pending!==operation)return;
    mutationBusy=false;
    if(!unknownResult(result)){finishMutation(result,operation.action);updateBusy();return}
  }
  mutationBusy=false;setStatus('Результат пока не подтверждён. Черновик сохранён; уточните позже.','warn');updateBusy();
}
function insert(){
  if(!authenticated()||mutationBusy||pending||placeholders(preview).length||!preview.trim()||!contextValid())return false;
  const inserted=window.WorkspaceV2Conversation?.insertQuickReply?.(preview,replyContext);
  if(!inserted){setStatus('Не удалось вставить текст. Проверьте доступ к текущему диалогу.','warn');return false}
  returnFocus=$('replyText');close();return true;
}
async function open(){
  if(!identity())return false;
  if(owner!==identity()||ownerGeneration!==Number(S.authGeneration||0)){resetSession();owner=identity();ownerGeneration=Number(S.authGeneration||0);restore()}
  ensureDialog();returnFocus=document.activeElement;
  if(dialog.open){$('qrSearch').focus();return true}
  dialog.showModal();setStatus();renderCategories();renderDetail();updateBusy();
  if(editor)$('qrTitle')?.focus();else $('qrSearch').focus();
  await load();return true;
}
function close(){persist();if(dialog?.open)dialog.close()}
function resetSession(){
  persist();epoch++;loadTicket++;clearTimeout(searchTimer);searchTimer=null;loading=null;
  owner=0;ownerGeneration=0;rows=[];categories=[];selected=null;preview='';replyContext=null;editor=null;pending=null;mutationBusy=false;nextOffset=null;listFailed=false;lastError='';query={scope:'all',search:'',category:'',favorites_only:false};
  returnFocus=null;if(dialog){if(dialog.open)dialog.close();dialog.remove();dialog=null}
}
function bind(){
  if(bound)return;
  const trigger=$('quickRepliesOpen');if(!trigger)return;
  trigger.onclick=open;trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-controls','quickRepliesDialog');bound=true;
}
window.WorkspaceV2QuickReplies={bind,open,close,resetSession,resolveText,placeholders};
})();
