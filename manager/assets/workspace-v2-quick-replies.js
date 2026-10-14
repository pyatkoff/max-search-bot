(function(){
'use strict';
const W=window.WorkspaceV2,{S,$,esc}=W;
const endpoint='quick-replies-api.php',storagePrefix='workspaceV2.quickReplyDraft.';
const attr=value=>esc(value).replace(/"/g,'&quot;');
let dialog=null,bound=false,returnFocus=null,epoch=0,searchTimer=null,loading=null,loadTicket=0;
let owner=0,ownerGeneration=0,rows=[],categories=[],selected=null,preview='',replyContext=null;
let editor=null,pending=null,mutationBusy=false,query={scope:'all',search:'',category:'',favorites_only:false},nextOffset=null;
let lastError='',listFailed=false;
let toolbarRows=[],toolbarVersion='',toolbarLimit=100,toolbarReady=false,toolbarError='',toolbarLoading=null,toolbarTicket=0,toolbarNode=null;
let toolbarFocus=null;
let emojiState=null;

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
function knownValues(ctx=replyContext){
  const client=String(S.detail?.conversation?.display_name||'').trim();
  return {manager_name:String(S.manager?.display_name||S.manager?.login||'').trim(),client_name:contextValid(ctx)&&Number(S.detail?.conversation?.id)===Number(ctx?.conversationId)&&client!=='Турист'?client:''};
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
function adoptSession(){
  if(owner===identity()&&ownerGeneration===Number(S.authGeneration||0))return;
  resetSession();owner=identity();ownerGeneration=Number(S.authGeneration||0);restore();
}
function acceptToolbar(result){
  if(!result||!Array.isArray(result.replies)||typeof result.version!=='string')return false;
  toolbarRows=result.replies;toolbarVersion=result.version;toolbarLimit=Number(result.max_replies)||100;
  toolbarReady=true;toolbarError=result.ok===false?'На панели больше '+toolbarLimit+' ответов. Уберите лишние в библиотеке; затем измените порядок.':'';
  if(selected){const current=toolbarRows.find(reply=>Number(reply.id)===Number(selected.id));if(current)selected={...selected,...current};else if(!toolbarError)selected={...selected,is_favorite:false}}
  renderToolbar();return true;
}
function renderToolbar(){
  if(!toolbarNode)return;
  if(!authenticated()){toolbarNode.replaceChildren();return}
  toolbarNode.innerHTML=toolbarRows.map(reply=>`<button type="button" class="qrToolbarReply" data-qr-toolbar-id="${Number(reply.id)}" title="${attr(reply.title)}">${esc(reply.title)}</button>`).join('')+
    (!toolbarRows.length?`<span class="qrToolbarEmpty">${toolbarLoading?'Загружаем быстрые ответы…':toolbarReady?'Добавьте свои быстрые ответы':'Быстрые ответы недоступны'}</span>`:'')+
    (toolbarError?`<span class="qrToolbarError" role="status">${esc(toolbarError)}</span><button type="button" data-qr-toolbar-retry>Повторить</button>`:'')+
    (pending?'<span class="qrToolbarError" role="status">Уточните сохранение панели</span><button type="button" data-qr-toolbar-resolve>Уточнить результат</button>':'');
  toolbarNode.setAttribute('aria-busy',String(!!toolbarLoading));
}
async function loadToolbar(force=false){
  if(!authenticated())return;
  if(toolbarLoading)return toolbarLoading.promise;
  if(toolbarReady&&!force)return;
  const capturedEpoch=epoch,ticket=++toolbarTicket,operation={promise:null};toolbarLoading=operation;renderToolbar();
  operation.promise=(async()=>{
    const result=await request('toolbar');
    if(capturedEpoch!==epoch||ticket!==toolbarTicket||!authenticated())return;
    if(!acceptToolbar(result)){toolbarError='Не удалось загрузить панель ответов.';renderToolbar()}
    else if(query.scope==='toolbar'){
      const target=editor?'qrBody':'qrPreview',priorInput=$(target),priorActive=document.activeElement;
      const focusedEmoji=emojiState?.target===target&&emojiState.input===priorInput&&$('qrEmojiPicker')?.contains?.(priorActive);
      const focusedInput=dialog?.open&&priorInput&&(priorActive===priorInput||focusedEmoji)?{
        target,epoch,id:Number((target==='qrBody'?editor:selected)?.id||0),value:priorInput.value,start:focusedEmoji?emojiState.start:priorInput.selectionStart,end:focusedEmoji?emojiState.end:priorInput.selectionEnd,direction:priorInput.selectionDirection,fromEmoji:focusedEmoji
      }:null;
      if(selected&&!toolbarError&&!toolbarRows.some(reply=>Number(reply.id)===Number(selected.id))){selected=null;preview='';replyContext=null}
      renderDetail();updateBusy();
      const currentInput=$(target),currentReply=target==='qrBody'?editor:selected;
      if(focusedInput&&focusedInput.epoch===epoch&&dialog?.open&&authenticated()&&currentReply&&Number(currentReply.id)===focusedInput.id&&currentInput?.isConnected&&currentInput.value===focusedInput.value&&[document.body,dialog,priorInput,priorActive].includes(document.activeElement)){
        const focusTarget=focusedInput.fromEmoji?$('qrEmojiToggle')||currentInput:currentInput;
        if(focusTarget.isConnected&&!focusTarget.disabled)focusTarget.focus({preventScroll:true});
        currentInput.setSelectionRange?.(focusedInput.start,focusedInput.end,focusedInput.direction);
      }
    }
  })().finally(()=>{
    if(toolbarLoading!==operation)return;
    toolbarLoading=null;renderToolbar();if(query.scope==='toolbar')renderList();
  });
  return operation.promise;
}
// Called once per authenticated lifecycle, independently of Inbox/conversation reads.
function configure(){if(!identity())return;adoptSession();bind();void loadToolbar()}
function clickToolbar(id){
  if(!authenticated())return false;
  const reply=toolbarRows.find(row=>Number(row.id)===id),ctx=window.WorkspaceV2Conversation?.captureReplyContext?.();
  if(!reply||!contextValid(ctx))return false;
  const text=resolveText(reply.body,knownValues(ctx));
  if(placeholders(text).length){
    ensureDialog();returnFocus=$('replyText');selected=reply;replyContext=ctx;preview=text;lastError='';
    if(!dialog.open)dialog.showModal();renderCategories();renderList();renderDetail();updateBusy();
    setStatus('Замените неизвестное имя или подсказку перед вставкой.','warn');$('qrPreview')?.focus();return false;
  }
  return window.WorkspaceV2Conversation?.insertQuickReply?.(text,ctx)||false;
}
function moveToolbar(id,direction){
  if(!authenticated()||mutationBusy||pending||!toolbarReady||toolbarError)return;
  const ids=toolbarRows.map(reply=>Number(reply.id)),from=ids.indexOf(id),to=from+(direction==='up'?-1:1);
  if(from<0||to<0||to>=ids.length)return;
  [ids[from],ids[to]]=[ids[to],ids[from]];
  toolbarFocus={id,direction};
  return mutate('reorder',{ids,toolbar_version:toolbarVersion});
}
async function removeFromToolbar(id){
  if(!authenticated()||mutationBusy||pending||!toolbarReady||!toolbarRows.some(reply=>Number(reply.id)===id))return;
  const active=document.activeElement,capturedEpoch=epoch,index=toolbarRows.findIndex(reply=>Number(reply.id)===id),neighbor=toolbarRows[index+1]||toolbarRows[index-1];
  const restoreFocus=Number(active?.dataset?.qrRemoveId)===id;
  await mutate('favorite',{id,is_favorite:false,toolbar_version:toolbarVersion},{preserveSelection:true});
  if(restoreFocus&&capturedEpoch===epoch&&dialog?.open&&authenticated()&&!mutationBusy&&!pending&&[document.body,dialog,active].includes(document.activeElement)){
    const target=$('qrList')?.querySelector?.(`[data-qr-remove-id="${id}"]`)||$('qrList')?.querySelector?.(`[data-qr-select="${Number(neighbor?.id||0)}"]`)||$('qrCreate');
    if(target?.isConnected&&!target.disabled)target.focus({preventScroll:true});
  }
}
function emojiChoices(){
  return Array.from($('emojiPicker')?.querySelectorAll?.('button[data-emoji]')||[]).map(button=>({
    emoji:button.dataset.emoji,label:button.getAttribute('aria-label')||button.title||button.dataset.emoji
  })).filter(choice=>typeof choice.emoji==='string'&&choice.emoji.length>0);
}
function emojiMarkup(){
  const choices=emojiChoices();if(!choices.length)return '';
  return `<div class="qrEmojiTools"><button id="qrEmojiToggle" class="qrButton" type="button" aria-expanded="false" aria-controls="qrEmojiPicker">😊 Смайлики</button><div id="qrEmojiPicker" class="qrEmojiPicker" role="group" aria-label="Выбрать смайлик" hidden>${choices.map(choice=>`<button type="button" data-qr-emoji="${attr(choice.emoji)}" aria-label="${attr(choice.label)}" title="${attr(choice.label)}">${esc(choice.emoji)}</button>`).join('')}</div></div>`;
}
function closeQuickReplyEmojis(restoreFocus=false){
  const wasOpen=!!emojiState;emojiState=null;
  const picker=$('qrEmojiPicker'),toggle=$('qrEmojiToggle');if(picker)picker.hidden=true;
  toggle?.setAttribute('aria-expanded','false');
  if(wasOpen&&restoreFocus&&dialog?.open&&authenticated()&&toggle?.isConnected&&!toggle.disabled)toggle.focus({preventScroll:true});
}
function bindQuickReplyEmojis(target){
  const toggle=$('qrEmojiToggle'),picker=$('qrEmojiPicker'),input=$(target);if(!toggle||!picker||!input)return;
  const usable=()=>dialog?.open&&authenticated()&&!mutationBusy&&!pending&&input.isConnected&&!input.disabled&&$(target)===input&&(target==='qrBody'?!!editor:!!selected);
  input.addEventListener('focus',()=>closeQuickReplyEmojis());
  input.addEventListener('input',()=>closeQuickReplyEmojis());
  toggle.onclick=()=>{
    if(!usable())return;
    if(emojiState){closeQuickReplyEmojis();return}
    emojiState={target,input,epoch,id:Number((target==='qrBody'?editor:selected)?.id||0),start:input.selectionStart,end:input.selectionEnd};
    picker.hidden=false;toggle.setAttribute('aria-expanded','true');picker.querySelector('button')?.focus();
  };
  picker.addEventListener('click',event=>{
    const button=event.target.closest('[data-qr-emoji]'),state=emojiState;
    if(!button||!state||!usable()||state.epoch!==epoch||state.input!==input||state.target!==target||state.id!==Number((target==='qrBody'?editor:selected)?.id||0)||!emojiChoices().some(choice=>choice.emoji===button.dataset.qrEmoji))return;
    if(input.maxLength>0&&input.value.length-(state.end-state.start)+button.dataset.qrEmoji.length>input.maxLength){setStatus('В тексте ответа не больше '+input.maxLength+' символов. Сократите текст или замените выделенный фрагмент.','warn');return}
    input.setRangeText(button.dataset.qrEmoji,state.start,state.end,'end');
    if(target==='qrBody'){editor={...editor,body:input.value};persist()}else{preview=input.value;updateInsertion()}
    closeQuickReplyEmojis();input.focus({preventScroll:true});
  });
}
function restorePanelFocus(){
  if(!toolbarFocus||mutationBusy||pending||query.scope!=='toolbar')return;
  const {id,direction}=toolbarFocus;toolbarFocus=null;
  const button=$('qrList')?.querySelector?.(`[data-qr-move-id="${id}"][data-qr-move="${direction}"]`);
  if(button&&!button.disabled)button.focus({preventScroll:true});
  else $('qrList')?.querySelector?.(`[data-qr-select="${id}"]`)?.focus({preventScroll:true});
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
      <div id="qrScopes" class="qrScopes" role="group" aria-label="Видимость ответов"><button type="button" data-qr-scope="toolbar" aria-pressed="false">Моя панель</button><button type="button" data-qr-scope="all" aria-pressed="true">Все</button><button type="button" data-qr-scope="personal" aria-pressed="false">Мои</button><button type="button" data-qr-scope="shared" aria-pressed="false">Общие</button></div>
      <label class="qrCategoryLabel" for="qrCategory">Категория<select id="qrCategory"><option value="">Все категории</option></select></label>
      <div id="qrList" class="qrList" aria-label="Ответы"></div><button id="qrMore" class="qrMore qrButton" type="button" hidden>Показать ещё</button>
      <p id="qrLibraryHint" class="qrLibraryHint">Выбирайте «На панели», чтобы видеть ответ рядом с полем сообщения.</p>
    </section><section id="qrDetail" class="qrDetail" aria-label="Текст быстрого ответа"></section></div>
  </div>`;
  document.body.appendChild(dialog);
  $('qrClose').onclick=close;
  dialog.addEventListener('cancel',e=>{e.preventDefault();close()});
  // Workspace shortcuts belong to the conversation behind this modal.
  dialog.addEventListener('keydown',event=>{event.stopPropagation();if(event.key==='Escape'&&emojiState){event.preventDefault();closeQuickReplyEmojis(true)}});
  dialog.addEventListener('click',event=>{if(!event.target.closest?.('.qrEmojiTools'))closeQuickReplyEmojis()});
  dialog.addEventListener('close',()=>{if(returnFocus?.isConnected&&!returnFocus.disabled)returnFocus.focus({preventScroll:true})});
  $('qrSearch').addEventListener('input',()=>{
    query.search=$('qrSearch').value;clearTimeout(searchTimer);
    // Invalidate the previous read immediately, before the debounce fires.
    loadTicket++;searchTimer=setTimeout(()=>load(),220);
  });
  $('qrCategory').addEventListener('change',()=>{query.category=$('qrCategory').value;load()});
  $('qrScopes').addEventListener('click',event=>{
    const button=event.target.closest('[data-qr-scope]');if(!button)return;
    const scope=button.dataset.qrScope;query.scope=scope;query.favorites_only=false;
    updateScope();if(scope==='toolbar')void loadToolbar(true);load();
  });
  $('qrCreate').onclick=()=>beginEditor();$('qrMore').onclick=()=>load(true);
  $('qrList').addEventListener('click',event=>{
    const remove=event.target.closest('[data-qr-remove-id]');
    if(remove){void removeFromToolbar(Number(remove.dataset.qrRemoveId));return}
    const move=event.target.closest('[data-qr-move]');
    if(move){void moveToolbar(Number(move.dataset.qrMoveId),move.dataset.qrMove);return}
    const select=event.target.closest('[data-qr-select]');
    if(select)selectReply(Number(select.dataset.qrSelect));
    if(event.target.closest('[data-qr-retry]'))query.scope==='toolbar'?void loadToolbar(true):void load();
  });
  return dialog;
}
function updateScope(){
  $('qrScopes')?.querySelectorAll('[data-qr-scope]').forEach(node=>node.setAttribute('aria-pressed',String(node.dataset.qrScope===query.scope)));
  const panel=query.scope==='toolbar';if($('qrSearch'))$('qrSearch').disabled=panel;
  if($('qrCategory'))$('qrCategory').disabled=panel;
  if($('qrLibraryHint'))$('qrLibraryHint').textContent=panel?'Стрелки меняют порядок. «Убрать с панели» оставит ответ в вашей библиотеке.':'Выбирайте «Добавить на панель», чтобы видеть ответ рядом с полем сообщения.';
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
  if(query.scope==='toolbar'){
    // A settings refresh must not detach the arrow a keyboard user just focused.
    const active=document.activeElement,focused=dialog?.open&&authenticated()&&node.contains?.(active)?{
      id:Number(active.dataset?.qrMoveId||active.dataset?.qrSelect||active.dataset?.qrRemoveId||0),direction:active.dataset?.qrMove||'',remove:!!active.dataset?.qrRemoveId
    }:null;
    node.setAttribute('aria-busy',String(!!toolbarLoading));
    node.innerHTML=toolbarRows.length?toolbarRows.map((reply,index)=>`<div class="qrPanelItem" data-qr-panel-id="${Number(reply.id)}"><div class="qrPanelContent"><button type="button" class="qrItem${Number(selected?.id)===Number(reply.id)?' selected':''}" data-qr-select="${Number(reply.id)}" aria-pressed="${Number(selected?.id)===Number(reply.id)}"><span class="qrItemTop"><strong>${esc(reply.title)}</strong><span class="qrPanelNumber">${index+1}</span></span><span class="qrItemBody">${esc(reply.body)}</span><span class="qrItemMeta">${reply.scope==='shared'?'Общий ответ на моей панели':'Мой ответ на панели'}</span></button><button type="button" class="qrPanelRemove" data-qr-remove-id="${Number(reply.id)}" aria-label="Убрать с панели: ${attr(reply.title)}" ${mutationBusy||pending||!toolbarReady?'disabled':''}>Убрать с панели</button></div><div class="qrPanelOrder" role="group" aria-label="Порядок: ${attr(reply.title)}"><button type="button" data-qr-move="up" data-qr-move-id="${Number(reply.id)}" aria-label="Выше: ${attr(reply.title)}" ${index===0||mutationBusy||pending||toolbarError?'disabled':''}>↑</button><button type="button" data-qr-move="down" data-qr-move-id="${Number(reply.id)}" aria-label="Ниже: ${attr(reply.title)}" ${index===toolbarRows.length-1||mutationBusy||pending||toolbarError?'disabled':''}>↓</button></div></div>`).join(''):`<div class="qrEmpty"><strong>${toolbarLoading?'Загружаем вашу панель':toolbarError?'Не удалось загрузить панель':'Добавьте свои быстрые ответы'}</strong><span>${toolbarError?'Попробуйте загрузить панель ещё раз.':'Создайте ответ или выберите существующий в «Мои» / «Общие», затем нажмите «Добавить на панель».'}</span>${toolbarError?'<button type="button" class="qrButton" data-qr-retry>Повторить загрузку</button>':''}</div>`;
    if(toolbarError&&toolbarRows.length)node.insertAdjacentHTML('beforeend',`<div class="qrInlineError" role="status">${esc(toolbarError)}</div>`);
    if(focused?.id>0&&dialog?.open&&authenticated()&&[document.body,dialog,active].includes(document.activeElement)){
      let control=node.querySelector(focused.direction?`[data-qr-move-id="${focused.id}"][data-qr-move="${focused.direction}"]`:focused.remove?`[data-qr-remove-id="${focused.id}"]`:`[data-qr-select="${focused.id}"]`);
      if(control?.disabled&&focused.direction)control=node.querySelector(`[data-qr-select="${focused.id}"]`);
      if(control?.isConnected&&!control.disabled)control.focus({preventScroll:true});
    }
    $('qrMore').hidden=true;return;
  }
  node.setAttribute('aria-busy',String(isLoading));
  if(isLoading&&!rows.length)node.innerHTML='<div class="qrEmpty"><span class="qrLoadingMark" aria-hidden="true">···</span><strong>Загружаем ответы</strong><span>Переписка и черновик остаются на месте.</span></div>';
  else if(!rows.length)node.innerHTML=listFailed?'<div class="qrEmpty"><strong>Не удалось загрузить ответы</strong><span>Проверьте соединение и попробуйте снова.</span><button class="qrButton" type="button" data-qr-retry>Повторить загрузку</button></div>':`<div class="qrEmpty"><strong>${query.search||query.category||query.favorites_only?'Подходящих ответов нет':'Пока нет быстрых ответов'}</strong><span>${query.search||query.category||query.favorites_only?'Измените поиск или фильтры.':'Создайте приветствие, уточнение или готовый ответ на частый вопрос.'}</span></div>`;
  else node.innerHTML=rows.map(reply=>`<button type="button" class="qrItem${Number(selected?.id)===Number(reply.id)?' selected':''}" data-qr-select="${Number(reply.id)}" aria-pressed="${Number(selected?.id)===Number(reply.id)}"><span class="qrItemTop"><strong>${esc(reply.title)}</strong>${reply.is_favorite?'<span class="qrStar" aria-label="На панели">★ На панели</span>':''}</span><span class="qrItemBody">${esc(reply.body)}</span><span class="qrItemMeta"><span>${reply.scope==='shared'?'Общий':'Мой'}</span>${reply.category?`<span>· ${esc(reply.category)}</span>`:''}</span></button>`).join('');
  if(listFailed&&rows.length)node.insertAdjacentHTML('beforeend','<div class="qrEmpty"><strong>Не удалось загрузить следующую страницу</strong><button class="qrButton" type="button" data-qr-retry>Повторить загрузку</button></div>');
  $('qrMore').hidden=nextOffset===null;$('qrMore').disabled=isLoading;
}
async function load(append=false){
  if(!authenticated())return;
  if(query.scope==='toolbar'){renderList();return loadToolbar()}
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
  selected=(query.scope==='toolbar'?toolbarRows:rows).find(reply=>Number(reply.id)===id)||null;
  replyContext=window.WorkspaceV2Conversation?.captureReplyContext?.()||null;
  preview=resolveText(selected?.body||'',knownValues());lastError='';setStatus();renderList();renderDetail();
}
function editorMarkup(){
  const draft=editor,isAdmin=S.manager?.role==='admin';
  return `<form id="qrEditor" class="qrEditor"><div class="qrDetailTop"><div><span class="qrBadge">${draft.id?'Редактирование':'Новый ответ'}</span><h3>${draft.id?'Изменить быстрый ответ':'Создать быстрый ответ'}</h3></div></div>
    <label for="qrTitle">Название<input id="qrTitle" type="text" maxlength="120" required value="${attr(draft.title)}" placeholder="Например, приветствие"></label>
    <div class="qrEditorMeta"><label for="qrEditCategory">Категория<input id="qrEditCategory" maxlength="64" list="qrCategorySuggestions" value="${attr(draft.category)}" placeholder="Например, знакомство"></label><label for="qrEditScope">Видимость<select id="qrEditScope" ${draft.id?'disabled':''}><option value="personal">Только мне</option>${isAdmin?'<option value="shared">Всем менеджерам</option>':''}</select></label></div>
    <datalist id="qrCategorySuggestions">${categories.map(value=>`<option value="${attr(value)}"></option>`).join('')}</datalist>
    <label class="qrBodyLabel" for="qrBody">Текст ответа<textarea id="qrBody" rows="8" maxlength="8000" required placeholder="Напишите готовый текст…">${esc(draft.body)}</textarea></label>${emojiMarkup()}
    <div class="qrVariables"><span>Подставить имя:</span><button type="button" data-qr-variable="manager_name">Менеджера</button><button type="button" data-qr-variable="client_name">Клиента</button></div>
    <p id="qrVisibilityNote" class="qrHint"></p><p class="qrHint">Перед вставкой можно изменить текст. Если имя неизвестно, потребуется заменить подсказку.</p>
    ${lastError?`<div class="qrInlineError" role="alert">${esc(lastError)}</div>`:''}
    <div class="qrEditorActions"><button id="qrSave" class="qrButton qrPrimary" type="submit">Сохранить ответ</button><button id="qrCancelEdit" class="qrButton" type="button">Отмена</button>${draft.conflict?'<button id="qrReloadConflict" class="qrButton" type="button">Открыть актуальную версию</button>':''}</div></form>`;
}
function renderDetail(){
  closeQuickReplyEmojis();
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
    bindQuickReplyEmojis('qrBody');updateVisibility();updateBusy();return;
  }
  if(!selected){node.innerHTML='<div class="qrWelcome"><div class="qrWelcomeIcon" aria-hidden="true">↗</div><h3>Меньше повторного набора</h3><p>Выберите ответ слева или создайте свой. Текст появится здесь для проверки перед вставкой.</p><span>Отправка клиенту — отдельное действие.</span></div>';return}
  node.innerHTML=`<div class="qrDetailTop"><div><span class="qrBadge">${selected.scope==='shared'?'Общий ответ':'Мой ответ'}</span><h3>${esc(selected.title)}</h3>${selected.category?`<p>${esc(selected.category)}</p>`:''}</div><button id="qrFavorite" class="qrButton qrPanelToggle" type="button" aria-label="${selected.is_favorite?'Убрать с панели':'Добавить на панель'}" aria-pressed="${!!selected.is_favorite}">${selected.is_favorite?'Убрать с панели':'Добавить на панель'}</button></div>
    <div class="qrTools">${selected.can_edit?'<button id="qrEdit" class="qrButton" type="button">Изменить</button>':'<span class="qrHint">Общий ответ редактирует администратор</span>'}<button id="qrCopy" class="qrButton" type="button">Копировать в мои</button>${selected.can_edit?'<button id="qrDelete" class="qrButton qrDanger" type="button">Удалить ответ</button>':''}</div>
    <label class="qrPreviewLabel" for="qrPreview">Текст для вставки<textarea id="qrPreview" rows="8">${esc(preview)}</textarea></label>${emojiMarkup()}<div id="qrPlaceholderStatus" class="qrPlaceholderStatus" role="status"></div>
    <div class="qrInsertFooter"><button id="qrInsert" class="qrButton qrPrimary" type="button">Вставить в ответ</button><p>Добавим текст в текущий черновик. Вложения сохранятся.</p></div>`;
  $('qrPreview').addEventListener('input',()=>{preview=$('qrPreview').value;updateInsertion()});
  $('qrInsert').onclick=insert;$('qrFavorite').onclick=()=>mutate('favorite',{id:selected.id,is_favorite:!selected.is_favorite,...(toolbarReady?{toolbar_version:toolbarVersion}:{})});
  $('qrCopy').onclick=()=>mutate('copy',{id:selected.id});
  if($('qrEdit'))$('qrEdit').onclick=()=>beginEditor(selected);
  if($('qrDelete'))$('qrDelete').onclick=()=>{
    if(window.confirm(selected.scope==='shared'?'Удалить общий ответ из библиотеки для всех менеджеров? Он исчезнет и с их панелей.':'Удалить этот быстрый ответ из библиотеки? Он исчезнет и с панели.'))mutate('delete',{id:selected.id,version:selected.version});
  };
  bindQuickReplyEmojis('qrPreview');updateInsertion();updateBusy();
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
  ['qrCreate','qrFavorite','qrCopy','qrEdit','qrDelete','qrSave','qrCancelEdit','qrReloadConflict','qrEmojiToggle'].forEach(id=>{const node=$(id);if(node)node.disabled=disabled});
  $('qrEmojiPicker')?.querySelectorAll('[data-qr-emoji]').forEach(node=>{node.disabled=disabled});if(disabled)closeQuickReplyEmojis();
  if(editor&&$('qrEditor'))$('qrEditor').querySelectorAll('input,textarea,select,[data-qr-variable]').forEach(node=>{node.disabled=disabled||(node.id==='qrEditScope'&&!!editor.id)});
  if($('qrSave'))$('qrSave').textContent=mutationBusy?'Сохраняем…':'Сохранить ответ';
  renderPending();updateInsertion();renderToolbar();if(query.scope==='toolbar')renderList();
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
async function mutate(action,data,options={}){
  if(!authenticated()||mutationBusy||pending)return;
  const capturedEpoch=epoch;
  // Reads begun before this write cannot restore an obsolete order or body.
  toolbarTicket++;toolbarLoading=null;
  pending={action,data,operation_id:operationId(),...(action==='reorder'?{focus:toolbarFocus}:{}),...(options.preserveSelection?{preserve_selection:true}:{})};mutationBusy=true;persist();updateBusy();setStatus('Сохраняем изменение…');
  const result=await request(action,{...data,operation_id:pending.operation_id});
  if(capturedEpoch!==epoch||!authenticated())return;
  mutationBusy=false;
  if(unknownResult(result)){setStatus('Не удалось подтвердить результат. Уточните его перед следующим изменением.','warn');updateBusy();return}
  finishMutation(result,action);updateBusy();restorePanelFocus();
}
function finishMutation(result,action){
  if(action==='reorder'&&pending?.focus)toolbarFocus=pending.focus;
  const preserveSelection=action==='favorite'&&pending?.preserve_selection;
  pending=null;mutationBusy=false;
  const hasToolbar=acceptToolbar(result?.toolbar);
  if(!result?.ok){
    lastError=['reorder','favorite'].includes(action)&&result?.error==='conflict'?'Панель изменена на другом устройстве. Показан сохранённый порядок; повторите нужное изменение.':result?.error==='toolbar_limit_exceeded'?'На панели можно выбрать до '+toolbarLimit+' ответов. Сначала уберите ненужный.':result?.error==='invalid_ids'?'Состав панели изменился. Обновите панель перед изменением порядка.':errorText(result);
    if(result?.error==='conflict'&&result.current&&editor)editor.conflict=result.current;
    persist();setStatus(lastError,'error');renderDetail();if(!hasToolbar&&action==='reorder')void loadToolbar(true);return;
  }
  if(['create','update'].includes(action)){editor=null;lastError=''}
  if(result.reply&&!preserveSelection){
    const preservePreview=action==='favorite'&&Number(selected?.id)===Number(result.reply.id);
    selected=result.reply;
    if(!preservePreview){replyContext=window.WorkspaceV2Conversation?.captureReplyContext?.()||null;preview=resolveText(selected.body,knownValues())}
  }
  if(hasToolbar&&selected){const current=toolbarRows.find(reply=>Number(reply.id)===Number(selected.id));if(current)selected={...selected,...current};else if(!toolbarError)selected={...selected,is_favorite:false}}
  if(action==='delete'){selected=null;preview=''}
  persist();renderDetail();setStatus({create:'Ответ создан. Нажмите «Добавить на панель», чтобы видеть его рядом с полем сообщения.',update:'Ответ сохранён.',copy:'Копия добавлена в ваши ответы.',delete:'Ответ удалён.',favorite:'Панель быстрых ответов обновлена.',reorder:'Порядок на панели сохранён.'}[action]||'Изменение сохранено.','success');
  // Follow-up read is deliberately independent of the confirmed write.
  void load();
  if(!hasToolbar)void loadToolbar(true);
}
async function reconcile(){
  if(!pending||mutationBusy||!authenticated())return;
  const capturedEpoch=epoch,operation=pending;mutationBusy=true;updateBusy();
  const receipt=await request('operation',{operation_id:operation.operation_id});
  if(capturedEpoch!==epoch||!authenticated()||pending!==operation)return;
  if(receipt?.ok&&receipt.found){await finishRecovered(receipt.result,operation,capturedEpoch);return}
  if(receipt?.ok&&receipt.found===false){
    // A checked missing receipt can safely use the identical idempotency key.
    const result=await request(operation.action,{...operation.data,operation_id:operation.operation_id});
    if(capturedEpoch!==epoch||!authenticated()||pending!==operation)return;
    mutationBusy=false;
    if(!unknownResult(result)){await finishRecovered(result,operation,capturedEpoch);return}
  }
  mutationBusy=false;setStatus('Результат пока не подтверждён. Черновик сохранён; уточните позже.','warn');updateBusy();
}
async function finishRecovered(result,operation,capturedEpoch){
  // A receipt confirms the write, but its saved snapshot can predate another
  // device's changes. Reconcile current server state before restoring chips.
  toolbarTicket++;toolbarLoading=null;
  const current=await request('toolbar');
  if(capturedEpoch!==epoch||!authenticated()||pending!==operation)return;
  const valid=Array.isArray(current?.replies)&&typeof current?.version==='string';
  finishMutation({...result,toolbar:valid?current:null},operation.action);
  if(!valid)toolbarError='Сохранение подтверждено. Обновите панель ответов.';
  updateBusy();restorePanelFocus();
}
function insert(){
  if(!authenticated()||mutationBusy||pending||placeholders(preview).length||!preview.trim()||!contextValid())return false;
  const inserted=window.WorkspaceV2Conversation?.insertQuickReply?.(preview,replyContext);
  if(!inserted){setStatus('Не удалось вставить текст. Проверьте доступ к текущему диалогу.','warn');return false}
  returnFocus=$('replyText');close();return true;
}
async function open(options={}){
  if(!identity())return false;
  adoptSession();
  ensureDialog();returnFocus=document.activeElement;
  if(dialog.open){focusLibrary();return true}
  if(options.panel&&!editor)query.scope='toolbar';updateScope();
  // Explicit settings visits refresh changes from another device/admin. This
  // joins any startup read and is never called by a conversation rerender.
  if(options.panel||toolbarReady)void loadToolbar(true);
  dialog.showModal();setStatus();renderCategories();renderDetail();updateBusy();
  if(editor)$('qrTitle')?.focus();else focusLibrary();
  await load();return true;
}
function focusLibrary(){
  const node=query.scope==='toolbar'?$('qrList')?.querySelector?.('[data-qr-select]')||$('qrCreate'):$('qrSearch');node?.focus();
}
function close(){closeQuickReplyEmojis();persist();if(dialog?.open)dialog.close()}
function resetSession(){
  closeQuickReplyEmojis();
  persist();epoch++;loadTicket++;toolbarTicket++;clearTimeout(searchTimer);searchTimer=null;loading=null;toolbarLoading=null;
  owner=0;ownerGeneration=0;rows=[];categories=[];selected=null;preview='';replyContext=null;editor=null;pending=null;mutationBusy=false;nextOffset=null;listFailed=false;lastError='';query={scope:'all',search:'',category:'',favorites_only:false};
  toolbarRows=[];toolbarVersion='';toolbarReady=false;toolbarError='';toolbarFocus=null;renderToolbar();
  returnFocus=null;if(dialog){if(dialog.open)dialog.close();dialog.remove();dialog=null}
}
function bind(){
  if(bound)return;
  const trigger=$('quickRepliesOpen');if(!trigger)return;
  const row=trigger.parentElement;if(!row)return;
  row.classList.add('qrToolbar');row.querySelectorAll('[data-reply]').forEach(button=>{button.hidden=true;button.remove()});
  toolbarNode=document.createElement('div');toolbarNode.id='quickRepliesSelected';toolbarNode.className='qrToolbarSelected';toolbarNode.setAttribute('role','group');toolbarNode.setAttribute('aria-label','Мои быстрые ответы');
  row.insertBefore(toolbarNode,trigger);
  toolbarNode.addEventListener('click',event=>{
    const button=event.target.closest('[data-qr-toolbar-id]');if(button){clickToolbar(Number(button.dataset.qrToolbarId));return}
    if(event.target.closest('[data-qr-toolbar-retry]'))void loadToolbar(true);
    if(event.target.closest('[data-qr-toolbar-resolve]'))void reconcile();
  });
  trigger.textContent='Настроить ответы';trigger.title='Выбрать и расположить быстрые ответы · Ctrl+Alt+Q';
  trigger.onclick=()=>open({panel:true});trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-controls','quickRepliesDialog');bound=true;renderToolbar();
}
window.WorkspaceV2QuickReplies={bind,configure,open,close,resetSession,resolveText,placeholders};
})();
