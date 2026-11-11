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
let company='',observedProject='',uploadLimits=null,insertionBusy=false,materialSequence=0;
let replyReplacement=null;
const tripLabels={trip_city:'Город вылета',trip_country:'Направление',trip_date:'Дата вылета',trip_nights:'Ночи',trip_adults:'Взрослые',trip_children:'Дети',trip_budget:'Бюджет'};
const variableKeys=['manager_name','client_name',...Object.keys(tripLabels)];

function identity(){return !S.authExpired?Number(S.manager?.id||0):0}
function authenticated(){return owner>0&&identity()===owner&&Number(S.authGeneration||0)===ownerGeneration}
function contextValid(ctx=replyContext){
  const now=window.WorkspaceV2Conversation?.captureReplyContext?.();
  return !S.authExpired&&!!ctx&&!!now&&Number(ctx.conversationId)>0&&Number(S.detail?.conversation?.id)===Number(ctx.conversationId)&&['conversationId','managerId','authGeneration','openGeneration'].every(key=>Number(ctx[key])===Number(now[key]));
}
function placeholders(text){return Array.from(String(text||'').matchAll(/\{\{([^{}\n]+)\}\}|\{([^{}\n]+)\}/g),match=>match[1]||match[2]);}
function resolveText(text,values={}){
  return String(text||'').replace(/\{\{([^{}\n]+)\}\}|\{([^{}\n]+)\}/g,(token,a,b)=>{
    const key=String(a||b).trim(),value=values[key];
    return variableKeys.includes(key)&&typeof value==='string'&&value.trim()?value:token;
  });
}
// Display the existing protected card snapshot, never parse the historical
// message summary or infer missing units, dates or party composition.
function tripValues(trip){
  if(!trip||typeof trip!=='object'||Array.isArray(trip))return {};
  const values={},label=value=>typeof value==='string'&&value.trim()&&!/[\x00-\x1f\x7f]/.test(value)?value.trim():'';
  for(const key of ['city','country']){const value=label(trip[key]);if(value)values['trip_'+key]=value}
  if(typeof trip.date==='string'){
    const match=/^(\d{2})\.(\d{2})\.(\d{4})$/.exec(trip.date);
    if(match){const day=Number(match[1]),month=Number(match[2]),year=Number(match[3]),leap=year%4===0&&(year%100!==0||year%400===0);
      if(year>0&&month>=1&&month<=12&&day>=1&&day<=[31,leap?29:28,31,30,31,30,31,31,30,31,30,31][month-1])values.trip_date=trip.date;
    }
  }
  if(typeof trip.nights==='string'){
    const match=/^([1-9]\d*)(?:-([1-9]\d*))?$/.exec(trip.nights);
    if(match&&Number.isSafeInteger(Number(match[1]))&&(!match[2]||(Number.isSafeInteger(Number(match[2]))&&Number(match[2])>=Number(match[1]))))values.trip_nights=trip.nights;
  }
  for(const key of ['adults','children'])if(Number.isSafeInteger(trip[key])&&trip[key]>=(key==='adults'?1:0))values['trip_'+key]=String(trip[key]);
  const budget=trip.budget,raw=budget?.max;
  const canonical=typeof raw==='number'||(typeof raw==='string'&&/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(raw));
  const amount=canonical?Number(raw):NaN;
  if(Number.isFinite(amount)&&amount>0&&amount<=90071992547409&&Math.round(amount*100)/100===amount&&typeof budget?.currency==='string'&&/^[A-Z]{3}$/.test(budget.currency)&&['total','per_person'].includes(budget.basis)){
    values.trip_budget='до '+new Intl.NumberFormat('ru-RU',{maximumFractionDigits:2}).format(amount)+' '+budget.currency+(budget.basis==='total'?' за всех':' на человека');
  }
  return values;
}
function knownValues(ctx=replyContext){
  const conversation=S.detail?.conversation||{};
  // A shared internal alias is for colleagues, never a salutation to the client.
  const client=String(Object.prototype.hasOwnProperty.call(conversation,'original_display_name')?conversation.original_display_name||'':conversation.display_name||'').trim();
  const current=authenticated()&&contextValid(ctx)&&Number(conversation.id)===Number(ctx?.conversationId);
  return {manager_name:String(S.manager?.display_name||S.manager?.login||'').trim(),client_name:current&&client!=='Турист'?client:'',...(current&&S.detail?.lead?.ok===true?tripValues(S.detail.lead.trip):{})};
}
function fillKnown(){
  const input=$('qrPreview');
  if(!authenticated()||mutationBusy||pending||!selected||!contextValid()||!input?.isConnected||!dialog?.open)return false;
  const original=input.value,start=input.selectionStart,end=input.selectionEnd,direction=input.selectionDirection;
  const values=knownValues(),replacements=[];
  // Map selection boundaries through replaced tokens without resetting the
  // textarea/editor or any text the manager has already changed.
  const next=original.replace(/\{\{([^{}\n]+)\}\}|\{([^{}\n]+)\}/g,(token,a,b,offset)=>{
    const value=resolveText(token,values);if(value!==token)replacements.push({offset,length:token.length,nextLength:value.length});return value;
  });
  const position=value=>{let delta=0;for(const item of replacements){if(value>=item.offset+item.length)delta+=item.nextLength-item.length;else if(value>item.offset)return item.offset+delta+Math.min(value-item.offset,item.nextLength);else break}return value+delta};
  preview=next;input.value=next;updateInsertion();input.focus({preventScroll:true});
  input.setSelectionRange?.(position(start),position(end),direction);
  setStatus(placeholders(next).length?'Неизвестные подсказки остались в тексте. Заполните их перед вставкой.':'Известные параметры подставлены. Проверьте текст.',placeholders(next).length?'warn':'');return true;
}
function draftKey(){return authenticated()&&company?storagePrefix+owner+'.'+company:''}
function persist(){
  const key=draftKey();if(!key)return;
  try{if(editor||pending)sessionStorage.setItem(key,JSON.stringify({editor,pending}));else sessionStorage.removeItem(key)}catch(e){}
}
function restore(){
  const key=draftKey();if(!key)return;
  try{
    const saved=JSON.parse(sessionStorage.getItem(key)||'null');
    if(saved?.editor&&typeof saved.editor.body==='string')editor={...saved.editor,editor_key:saved.editor.editor_key||operationId(),materials:Array.isArray(saved.editor.materials)?saved.editor.materials:[],preparing_files:false};
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
async function request(action,data={},files=[],isCurrent=()=>true){
  const capturedEpoch=epoch;
  if(!authenticated()||!company)return {ok:false,error:'stale_session'};
  const project=company,payload={...data,project_key:project,expected_manager_id:owner};
  const valid=()=>capturedEpoch===epoch&&authenticated()&&company===project&&isCurrent();
  let result;
  try{result=files.length?await window.WorkspaceV2QuickReplyMaterials.write(endpoint,action,payload,files,valid):await W.request(endpoint,action,payload,{isCurrent:valid})}catch(e){result={ok:false,error:S.authExpired?'stale_session':'network_error'}}
  return valid()?result:{ok:false,error:'stale_session'};
}
function projectRows(){return Array.isArray(S.projects)?S.projects.filter(p=>typeof p.project_key==='string'):[]}
function conversationProject(){return Number(S.current)>0&&Number(S.detail?.conversation?.id)===Number(S.current)?String(S.detail.conversation.project_key||''):''}
function companyName(){return String(projectRows().find(p=>p.project_key===company)?.display_name||company||'Выберите компанию')}
function switchCompany(key){
  if(typeof key!=='string'||(key&&!/^[a-z][a-z0-9_-]{0,63}$/.test(key))||key===company)return false;
  const actor=owner,generation=ownerGeneration,observed=observedProject;
  resetSession({preserveFiles:true});owner=actor;ownerGeneration=generation;company=key;observedProject=observed;restore();return true;
}
function adoptSession(){
  if(owner!==identity()||ownerGeneration!==Number(S.authGeneration||0)){
    resetSession();owner=identity();ownerGeneration=Number(S.authGeneration||0);
  }
  const active=conversationProject();
  if(active&&active!==observedProject){observedProject=active;switchCompany(active)}
  if(!company&&projectRows().length===1)switchCompany(projectRows()[0].project_key);
}
function acceptToolbar(result){
  if(!result||result.project_key!==company||!Array.isArray(result.replies)||typeof result.version!=='string'||result.replies.some(r=>r.project_key!==company))return false;
  uploadLimits=result.upload_limits||uploadLimits;
  adoptLegacyDraft(result);
  toolbarRows=result.replies;toolbarVersion=result.version;toolbarLimit=Number(result.max_replies)||100;
  toolbarReady=true;toolbarError=result.ok===false?'На панели больше '+toolbarLimit+' ответов. Уберите лишние в библиотеке; затем измените порядок.':'';
  if(selected){const current=toolbarRows.find(reply=>Number(reply.id)===Number(selected.id));if(current)selected={...selected,...current};else if(!toolbarError)selected={...selected,is_favorite:false}}
  renderToolbar();return true;
}
function renderToolbar(){
  if(!toolbarNode)return;
  if(!authenticated()){toolbarNode.replaceChildren();return}
  if(!company){toolbarNode.innerHTML='<span class="qrToolbarEmpty">Выберите компанию в быстрых ответах</span>';return}
  toolbarNode.innerHTML=toolbarRows.map(reply=>`<button type="button" class="qrToolbarReply" data-qr-toolbar-id="${Number(reply.id)}" title="${attr(reply.title+(reply.materials?.length?' · Файлов: '+reply.materials.length:'')+(reply.requires_review?' · Условия требуют проверки':''))}">${esc(reply.title)}${reply.materials?.length?' · '+reply.materials.length+' 📎':''}</button>`).join('')+
    (!toolbarRows.length?`<span class="qrToolbarEmpty">${toolbarLoading?'Загружаем быстрые ответы…':toolbarReady?'Добавьте свои быстрые ответы':'Быстрые ответы недоступны'}</span>`:'')+
    (toolbarError?`<span class="qrToolbarError" role="status">${esc(toolbarError)}</span><button type="button" data-qr-toolbar-retry>Повторить</button>`:'')+
    (pending?'<span class="qrToolbarError" role="status">Уточните сохранение панели</span><button type="button" data-qr-toolbar-resolve>Уточнить результат</button>':'');
  toolbarNode.setAttribute('aria-busy',String(!!toolbarLoading));
}
async function loadToolbar(force=false){
  if(!authenticated()||!company){renderToolbar();return}
  if(toolbarLoading)return toolbarLoading.promise;
  if((toolbarReady||toolbarError)&&!force)return;
  const capturedEpoch=epoch,ticket=++toolbarTicket,operation={promise:null};toolbarLoading=operation;renderToolbar();
  operation.promise=(async()=>{
    const result=await request('toolbar');
    if(capturedEpoch!==epoch||ticket!==toolbarTicket||!authenticated())return;
    if(!acceptToolbar(result)){
      if(['not_found','actor_changed','forbidden'].includes(result?.error)||[401,403,404].includes(Number(result?.http_status))){switchCompany('');renderToolbar();return}
      toolbarRows=[];toolbarReady=false;toolbarError='Не удалось загрузить панель ответов.';renderToolbar();
    }
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
function configure(){if(!identity())return;adoptSession();bind();window.WorkspaceV2QuickInsert?.bind({search:searchComposer,choose:chooseComposer});void loadToolbar()}
function syncContext(){if(!identity())return;adoptSession();window.WorkspaceV2QuickInsert?.sync();if(replyReplacement)updateInsertion();if(company)void loadToolbar()}
async function searchComposer(term,range,valid){
  adoptSession();
  if(!authenticated()||!company||conversationProject()!==company||!contextValid(range?.context)||!valid())return {ok:false,error:'stale_context'};
  return request('list',{scope:'all',search:term,limit:8,offset:0},[],valid);
}
function chooseComposer(reply,range,valid,notice){
  if(!authenticated()||mutationBusy||pending||insertionBusy||!contextValid(range?.context)||reply.project_key!==company||conversationProject()!==company||!valid())return false;
  const text=resolveText(reply.body,knownValues(range.context));
  if(placeholders(text).length||reply.materials?.length||reply.requires_review){
    ensureDialog();returnFocus=$('replyText');selected=reply;replyContext=range.context;replyReplacement=range;preview=text;lastError='';
    if(!dialog.open)dialog.showModal();renderCategories();renderList();renderDetail();updateBusy();
    setStatus('Проверьте текст, условия и материалы. При вставке заменится только команда через /.','warn');$('qrPreview')?.focus();return 'preview';
  }
  const capturedEpoch=epoch,project=company,ticket=++materialSequence;
  const current=()=>capturedEpoch===epoch&&ticket===materialSequence&&authenticated()&&company===project&&contextValid(range.context)&&conversationProject()===project&&valid();
  if(!window.WorkspaceV2QuickReplyMaterials)return false;
  return window.WorkspaceV2QuickReplyMaterials.insert({endpoint,reply,text,context:range.context,project,valid:current,notice,replacement:range});
}
function clickToolbar(id){
  if(!authenticated())return false;
  const reply=toolbarRows.find(row=>Number(row.id)===id),ctx=window.WorkspaceV2Conversation?.captureReplyContext?.();
  if(!reply||reply.project_key!==company||conversationProject()!==company||!contextValid(ctx))return false;
  replyReplacement=null;
  const text=resolveText(reply.body,knownValues(ctx));
  if(placeholders(text).length||reply.materials?.length){
    ensureDialog();returnFocus=$('replyText');selected=reply;replyContext=ctx;preview=text;lastError='';
    if(!dialog.open)dialog.showModal();renderCategories();renderList();renderDetail();updateBusy();
    setStatus(reply.materials?.length?'Проверьте текст, условия и порядок материалов перед вставкой.':'Проверьте неизвестные параметры и замените подсказки перед вставкой.','warn');$('qrPreview')?.focus();return false;
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
      <label class="qrCompanyLabel" for="qrCompany">Компания<select id="qrCompany"></select></label>
      <div class="qrSearchRow"><label class="qrSearchLabel" for="qrSearch"><span class="qrVisuallyHidden">Поиск по названию и тексту</span><input id="qrSearch" type="search" placeholder="Найти ответ…" autocomplete="off"></label><button id="qrCreate" class="qrButton qrPrimary" type="button">+ Создать</button></div>
      <div id="qrScopes" class="qrScopes" role="group" aria-label="Видимость ответов"><button type="button" data-qr-scope="toolbar" aria-pressed="false">Моя панель</button><button type="button" data-qr-scope="all" aria-pressed="true">Все</button><button type="button" data-qr-scope="personal" aria-pressed="false">Мои</button><button type="button" data-qr-scope="shared" aria-pressed="false">Общие</button></div>
      <label class="qrCategoryLabel" for="qrCategory">Категория<select id="qrCategory"><option value="">Все категории</option></select></label>
      <div id="qrList" class="qrList" aria-label="Ответы"></div><button id="qrMore" class="qrMore qrButton" type="button" hidden>Показать ещё</button>
      <p id="qrLibraryHint" class="qrLibraryHint">Выбирайте «На панели», чтобы видеть ответ рядом с полем сообщения.</p>
    </section><section id="qrDetail" class="qrDetail" aria-label="Текст быстрого ответа"></section></div>
  </div>`;
  document.body.appendChild(dialog);
  renderCompany();
  $('qrCompany').onchange=()=>{if(mutationBusy||pending||editor)return;const key=$('qrCompany').value;if(switchCompany(key))void open()};
  $('qrClose').onclick=close;
  dialog.addEventListener('cancel',e=>{e.preventDefault();close()});
  // Workspace shortcuts belong to the conversation behind this modal.
  dialog.addEventListener('keydown',event=>{event.stopPropagation();if(event.key==='Escape'&&emojiState){event.preventDefault();closeQuickReplyEmojis(true)}});
  dialog.addEventListener('click',event=>{if(!event.target.closest?.('.qrEmojiTools'))closeQuickReplyEmojis()});
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
function renderCompany(){
  const node=$('qrCompany');if(!node)return;
  const projects=projectRows(),known=projects.some(p=>p.project_key===company);
  node.innerHTML='<option value="">Выберите компанию</option>'+projects.map(p=>`<option value="${attr(p.project_key)}">${esc(p.display_name||p.project_key)}</option>`).join('')+(company&&!known?`<option value="${attr(company)}">${esc(companyName())}</option>`:'');node.value=company;
  node.disabled=mutationBusy||!!pending||!!editor;
}
function adoptLegacyDraft(result){
  if(result.legacy_project!==true||result.project_key!==company||editor||pending||!draftKey())return;
  try{
    if(sessionStorage.getItem(draftKey())!==null)return;
    const oldKey=storagePrefix+owner,saved=JSON.parse(sessionStorage.getItem(oldKey)||'null');
    if(!saved||(!saved.editor&&!saved.pending))return;
    sessionStorage.setItem(draftKey(),JSON.stringify(saved));restore();
    if(sessionStorage.getItem(draftKey())!==null)sessionStorage.removeItem(oldKey);
    if(dialog?.open){renderDetail();updateBusy()}
  }catch(e){}
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
  const missing=pending.needs_files===true&&pending.uploads?.length&&!window.WorkspaceV2QuickReplyMaterials?.pendingFiles(pending);
  node.innerHTML='<span>Результат изменения уточняется. Новые изменения доступны после проверки.</span>'+(missing?'<label class="qrRecoverLabel">Повторно выберите исходные файлы<input id="qrRecoverFiles" type="file" multiple></label>':'')+'<button id="qrResolve" class="qrButton" type="button">Уточнить результат</button>';
  $('qrResolve').disabled=mutationBusy;$('qrResolve').onclick=reconcile;
  if($('qrRecoverFiles'))$('qrRecoverFiles').onchange=async event=>{
    if(mutationBusy||!pending)return;const operation=pending,capturedEpoch=epoch,selectedFiles=Array.from(event.target.files||[]);mutationBusy=true;updateBusy();
    const valid=()=>capturedEpoch===epoch&&authenticated()&&pending===operation;
    try{const matched=await window.WorkspaceV2QuickReplyMaterials.recover(operation,selectedFiles,valid,{editor,scope:owner+':'+company});if(valid())setStatus(matched?'Файлы совпали. Уточните результат сохранения перед продолжением.':'Выберите исходные файлы.','warn')}
    catch(error){if(valid())setStatus(error.message,'warn')}
    finally{if(valid()){mutationBusy=false;updateBusy()}}
  };
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
  if(!authenticated()||!company){renderList();return}
  if(query.scope==='toolbar'){renderList();return loadToolbar()}
  const params={...query,offset:append?nextOffset||0:0,limit:50},key=JSON.stringify(params),capturedEpoch=epoch;
  if(loading?.key===key&&loading.ticket===loadTicket)return loading.promise;
  const ticket=++loadTicket;
  if(!append){rows=[];nextOffset=null}listFailed=false;renderList(true);
  const operation={key,ticket,promise:null};loading=operation;
  operation.promise=(async()=>{
    const result=await request('list',params);
    if(capturedEpoch!==epoch||ticket!==loadTicket||!authenticated())return;
    if(!result?.ok||result.project_key!==company||(result.replies||[]).some(r=>r.project_key!==company)){
      listFailed=true;selected=null;preview='';replyContext=null;renderDetail();renderList();return;
    }
    uploadLimits=result.upload_limits||uploadLimits;
    adoptLegacyDraft(result);
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
  materialSequence++;insertionBusy=false;
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
    <label class="qrBodyLabel" for="qrBody">Текст ответа<textarea id="qrBody" rows="8" maxlength="8000" required placeholder="Напишите готовый текст…">${esc(draft.body)}</textarea></label>${emojiMarkup()}<div id="qrMaterialsEditor" class="qrMaterialsEditor"></div>
    <div class="qrVariables"><span>Подставить имя:</span><button type="button" data-qr-variable="manager_name">Менеджера</button><button type="button" data-qr-variable="client_name">Клиента</button></div>
    <div class="qrTripVariables"><label for="qrTripVariable">Параметр поездки<select id="qrTripVariable">${Object.entries(tripLabels).map(([key,label])=>`<option value="${key}">${label}</option>`).join('')}</select></label><button id="qrAddTripVariable" class="qrButton" type="button">Добавить подсказку</button></div>
    <p id="qrVisibilityNote" class="qrHint"></p><p class="qrHint">Имена и параметры подставятся при выборе ответа, только если они известны. Перед вставкой проверьте текст. Неизвестные подсказки потребуется заполнить.</p>
    ${lastError?`<div class="qrInlineError" role="alert">${esc(lastError)}</div>`:''}
    <div class="qrEditorActions"><button id="qrSave" class="qrButton qrPrimary" type="submit">Сохранить ответ</button><button id="qrCancelEdit" class="qrButton" type="button">Отмена</button>${draft.conflict?'<button id="qrReloadConflict" class="qrButton" type="button">Открыть актуальную версию</button>':''}</div></form>`;
}
function renderDetail(){
  closeQuickReplyEmojis();
  const node=$('qrDetail');if(!node)return;
  if(editor){
    node.innerHTML=editorMarkup();$('qrEditScope').value=editor.scope==='shared'&&S.manager?.role==='admin'?'shared':'personal';
    const persistFields=()=>{
      editor={...editor,title:$('qrTitle').value,body:$('qrBody').value,category:$('qrEditCategory').value,scope:$('qrEditScope').value,reviewed:false};if($('qrReviewed'))$('qrReviewed').checked=false;persist();updateVisibility();
    };
    ['qrTitle','qrBody','qrEditCategory'].forEach(id=>$(id).addEventListener('input',persistFields));$('qrEditScope').addEventListener('change',persistFields);
    $('qrEditor').addEventListener('submit',event=>{event.preventDefault();saveEditor()});
    $('qrCancelEdit').onclick=()=>{if(mutationBusy||pending)return;window.WorkspaceV2QuickReplyMaterials?.release(editor,owner+':'+company);editor=null;lastError='';persist();renderDetail();$('qrCreate')?.focus()};
    node.querySelectorAll('[data-qr-variable]').forEach(button=>button.onclick=()=>{
      const input=$('qrBody'),start=input.selectionStart,end=input.selectionEnd,token='{'+button.dataset.qrVariable+'}';
      input.setRangeText(token,start,end,'end');persistFields();input.focus();
    });
    $('qrAddTripVariable').onclick=()=>{
      if(mutationBusy||pending||!authenticated()||!editor)return;
      const key=$('qrTripVariable').value;if(!Object.hasOwn(tripLabels,key))return;
      const input=$('qrBody'),token='{'+key+'}';
      if(input.maxLength>0&&input.value.length-(input.selectionEnd-input.selectionStart)+token.length>input.maxLength){setStatus('В тексте ответа не больше '+input.maxLength+' символов. Сократите текст или замените выделенный фрагмент.','warn');return}
      input.setRangeText(token,input.selectionStart,input.selectionEnd,'end');persistFields();input.focus();
    };
    if($('qrReloadConflict'))$('qrReloadConflict').onclick=()=>{
      const current=editor.conflict;if(!current)return;
      if(!window.confirm('Открыть актуальную версию? Несохранённый текст этого шаблона будет заменён.'))return;
      window.WorkspaceV2QuickReplyMaterials?.release(editor,owner+':'+company);editor={id:current.id,version:current.version,title:current.title,body:current.body,category:current.category,scope:current.scope,editor_key:operationId(),source_label:current.source_label||'',reviewed:!current.requires_review,materials:current.materials||[]};lastError='';persist();renderDetail();
    };
    const key=editor.editor_key,capturedEpoch=epoch;
    window.WorkspaceV2QuickReplyMaterials?.editor($('qrMaterialsEditor'),{get:()=>editor,scope:owner+':'+company,limits:uploadLimits,
      valid:()=>capturedEpoch===epoch&&authenticated()&&editor?.editor_key===key,
      busy:()=>mutationBusy||!!pending||!!editor?.preparing_files,
      change:patch=>{if(capturedEpoch!==epoch||!authenticated()||editor?.editor_key!==key)return;editor={...editor,...patch};persist();updateBusy()}});
    bindQuickReplyEmojis('qrBody');updateVisibility();updateBusy();return;
  }
  if(!selected){node.innerHTML='<div class="qrWelcome"><div class="qrWelcomeIcon" aria-hidden="true">↗</div><h3>Меньше повторного набора</h3><p>Выберите ответ слева или создайте свой. Текст появится здесь для проверки перед вставкой.</p><span>Отправка клиенту — отдельное действие.</span></div>';return}
  node.innerHTML=`<div class="qrDetailTop"><div><span class="qrBadge">${selected.scope==='shared'?'Общий ответ':'Мой ответ'}</span><h3>${esc(selected.title)}</h3>${selected.category?`<p>${esc(selected.category)}</p>`:''}</div><button id="qrFavorite" class="qrButton qrPanelToggle" type="button" aria-label="${selected.is_favorite?'Убрать с панели':'Добавить на панель'}" aria-pressed="${!!selected.is_favorite}">${selected.is_favorite?'Убрать с панели':'Добавить на панель'}</button></div>
    <div class="qrTools">${selected.can_edit?'<button id="qrEdit" class="qrButton" type="button">Изменить</button>':'<span class="qrHint">Общий ответ редактирует администратор</span>'}<button id="qrCopy" class="qrButton" type="button">Копировать в мои</button>${selected.can_edit?'<button id="qrDelete" class="qrButton qrDanger" type="button">Удалить ответ</button>':''}</div>
    ${window.WorkspaceV2QuickReplyMaterials?.preview(selected)||''}<label class="qrPreviewLabel" for="qrPreview">Текст для вставки<textarea id="qrPreview" rows="8">${esc(preview)}</textarea></label>${emojiMarkup()}<div class="qrKnownParameters"><button id="qrFillKnown" class="qrButton" type="button">Подставить известное</button><span class="qrHint">Только оставшиеся подсказки из текущей карточки. Ваши правки сохранятся.</span></div><div id="qrPlaceholderStatus" class="qrPlaceholderStatus" role="status"></div>
    <div class="qrInsertFooter"><button id="qrInsert" class="qrButton qrPrimary" type="button">Вставить в ответ</button><p>Добавим текст в текущий черновик. Вложения сохранятся.</p></div>`;
  $('qrPreview').addEventListener('input',()=>{materialSequence++;insertionBusy=false;preview=$('qrPreview').value;updateInsertion()});
  $('qrFillKnown').onclick=fillKnown;$('qrInsert').onclick=insert;$('qrFavorite').onclick=()=>mutate('favorite',{id:selected.id,is_favorite:!selected.is_favorite,...(toolbarReady?{toolbar_version:toolbarVersion}:{})});
  $('qrCopy').onclick=()=>mutate('copy',{id:selected.id});
  if($('qrEdit'))$('qrEdit').onclick=()=>beginEditor(selected);
  if($('qrDelete'))$('qrDelete').onclick=()=>{
    if(window.confirm(selected.scope==='shared'?'Удалить общий ответ из библиотеки для всех менеджеров? Он исчезнет и с их панелей.':'Удалить этот быстрый ответ из библиотеки? Он исчезнет и с панели.'))mutate('delete',{id:selected.id,version:selected.version});
  };
  bindQuickReplyEmojis('qrPreview');updateInsertion();updateBusy();
}
function updateVisibility(){
  const note=$('qrVisibilityNote');if(note)note.textContent=editor?.scope==='shared'?'Этот ответ будет доступен команде компании «'+companyName()+'». Редактирование — у администраторов.':'Этот ответ будет доступен только вам в компании «'+companyName()+'», в том числе на другом устройстве.';
}
function updateInsertion(){
  const button=$('qrInsert'),note=$('qrPlaceholderStatus');if(!button||!note)return;
  const unresolved=placeholders(preview),valid=authenticated()&&contextValid()&&selected?.project_key===company&&conversationProject()===company&&(!replyReplacement||window.WorkspaceV2Conversation?.canReplaceQuickReply?.(replyReplacement)===true);
  const fill=$('qrFillKnown');if(fill)fill.disabled=mutationBusy||!!pending||!valid||!unresolved.some(key=>variableKeys.includes(key.trim()));
  button.disabled=mutationBusy||!!pending||insertionBusy||!valid||!preview.trim()||unresolved.length>0;
  button.textContent=insertionBusy?'Загружаем материалы…':'Вставить в ответ';
  note.className='qrPlaceholderStatus'+(unresolved.length||!valid?' warn':'');
  const nameLabels={manager_name:'Имя менеджера',client_name:'Имя клиента'};
  const label=key=>Object.hasOwn(tripLabels,key.trim())?tripLabels[key.trim()]:Object.hasOwn(nameLabels,key.trim())?nameLabels[key.trim()]:key;
  note.textContent=!valid?'Выберите ответ заново в текущем диалоге, чтобы вставить текст.':unresolved.length?'Замените подсказки в тексте: '+Array.from(new Set(unresolved.map(label))).join(', '):'Текст можно изменить здесь и после вставки.';
}
function updateBusy(){
  const disabled=mutationBusy||!!pending;
  ['qrCreate','qrFavorite','qrCopy','qrEdit','qrDelete','qrSave','qrCancelEdit','qrReloadConflict','qrEmojiToggle'].forEach(id=>{const node=$(id);if(node)node.disabled=disabled});
  $('qrEmojiPicker')?.querySelectorAll('[data-qr-emoji]').forEach(node=>{node.disabled=disabled});if(disabled)closeQuickReplyEmojis();
  if(editor&&$('qrEditor'))$('qrEditor').querySelectorAll('input,textarea,select,[data-qr-variable],#qrAddTripVariable').forEach(node=>{node.disabled=disabled||(node.id==='qrEditScope'&&!!editor.id)});
  if($('qrSave'))$('qrSave').textContent=mutationBusy?'Сохраняем…':'Сохранить ответ';
  if($('qrSave'))$('qrSave').disabled=disabled||!!editor?.preparing_files;
  if($('qrCreate'))$('qrCreate').disabled=disabled||!company;
  if($('qrMaterialFiles'))$('qrMaterialFiles').disabled=disabled||!!editor?.preparing_files;
  if($('qrReviewed'))$('qrReviewed').disabled=disabled||!editor?.source_label?.trim();
  if($('qrRecoverFiles'))$('qrRecoverFiles').disabled=mutationBusy;
  $('qrMaterialEditorRows')?.querySelectorAll('[data-qr-material-action]').forEach(button=>{const i=Number(button.dataset.index);button.disabled=disabled||!!editor?.preparing_files||(button.dataset.qrMaterialAction==='up'&&i===0)||(button.dataset.qrMaterialAction==='down'&&i===(editor?.materials?.length||0)-1)});
  renderCompany();renderPending();updateInsertion();renderToolbar();if(query.scope==='toolbar')renderList();
}
function beginEditor(reply=null){
  if(mutationBusy||pending||!company)return;
  if(editor){setStatus('Продолжайте сохранённый черновик или отмените редактирование.','warn');$('qrTitle')?.focus();return}
  editor=reply?{id:reply.id,version:reply.version,title:reply.title,body:reply.body,category:reply.category||'',scope:reply.scope,source_label:reply.source_label||'',reviewed:!reply.requires_review,materials:reply.materials||[],editor_key:operationId()}:{id:0,title:'',body:'',category:query.category,scope:'personal',source_label:'',reviewed:false,materials:[],editor_key:operationId()};
  lastError='';persist();setStatus();renderDetail();$('qrTitle').focus();
}
function saveEditor(){
  if(!editor||mutationBusy||pending)return;
  if(!editor.title.trim()||!editor.body.trim()){setStatus('Добавьте название и текст ответа.','warn');return}
  if(!window.WorkspaceV2QuickReplyMaterials&&editor.materials?.length){setStatus('Не удалось открыть материалы. Обновите страницу перед изменением шаблона.','warn');return}
  let packet={materials:[],upload_hashes:[],uploads:[]};
  try{if(window.WorkspaceV2QuickReplyMaterials)packet=window.WorkspaceV2QuickReplyMaterials.packet(editor,owner+':'+company)}catch(error){setStatus(error.message,'warn');return}
  const data={title:editor.title,body:editor.body,category:editor.category,scope:editor.scope,source_label:editor.source_label||'',reviewed:!!editor.reviewed,materials:packet.materials,upload_hashes:packet.upload_hashes};
  if(editor.id){data.id=editor.id;data.version=editor.version;delete data.scope}
  return mutate(editor.id?'update':'create',data,{files:packet.uploads});
}
function unknownResult(result){return !result||['network_error','invalid_response','operation_mismatch','operation_pending'].includes(result.error)||Number(result.http_status||0)>=500}
async function mutate(action,data,options={}){
  if(!authenticated()||mutationBusy||pending)return;
  const capturedEpoch=epoch;
  // Reads begun before this write cannot restore an obsolete order or body.
  toolbarTicket++;toolbarLoading=null;
  const files=options.files||[];
  pending={action,data,operation_id:operationId(),...(files.length?{uploads:files.map((file,i)=>({name:file.name,size:file.size,sha256:data.upload_hashes[i]}))}:{}),...(action==='reorder'?{focus:toolbarFocus}:{}),...(options.preserveSelection?{preserve_selection:true}:{})};
  window.WorkspaceV2QuickReplyMaterials?.remember(pending.operation_id,files);mutationBusy=true;persist();updateBusy();setStatus('Сохраняем изменение…');
  const result=await request(action,{...data,operation_id:pending.operation_id},files);
  if(capturedEpoch!==epoch||!authenticated())return;
  mutationBusy=false;
  if(unknownResult(result)){setStatus('Не удалось подтвердить результат. Уточните его перед следующим изменением.','warn');updateBusy();return}
  finishMutation(result,action);updateBusy();restorePanelFocus();
}
function finishMutation(result,action){
  window.WorkspaceV2QuickReplyMaterials?.forgetOperation(pending?.operation_id);
  if(action==='reorder'&&pending?.focus)toolbarFocus=pending.focus;
  const preserveSelection=action==='favorite'&&pending?.preserve_selection;
  pending=null;mutationBusy=false;
  const hasToolbar=acceptToolbar(result?.toolbar);
  if(!result?.ok){
    lastError=['reorder','favorite'].includes(action)&&result?.error==='conflict'?'Панель изменена на другом устройстве. Показан сохранённый порядок; повторите нужное изменение.':result?.error==='toolbar_limit_exceeded'?'На панели можно выбрать до '+toolbarLimit+' ответов. Сначала уберите ненужный.':result?.error==='invalid_ids'?'Состав панели изменился. Обновите панель перед изменением порядка.':errorText(result);
    if(result?.error==='conflict'&&result.current&&editor)editor.conflict=result.current;
    persist();setStatus(lastError,'error');renderDetail();if(!hasToolbar&&action==='reorder')void loadToolbar(true);return;
  }
  if(['create','update'].includes(action)){window.WorkspaceV2QuickReplyMaterials?.release(editor,owner+':'+company);editor=null;lastError=''}
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
    const files=window.WorkspaceV2QuickReplyMaterials?.pendingFiles(operation)||(!operation.uploads?.length?[]:null);
    if(files===null){operation.needs_files=true;persist();mutationBusy=false;setStatus('Сохранение не подтверждено. Повторно выберите исходные файлы; затем уточните результат.','warn');updateBusy();return}
    // A checked missing receipt can safely use the identical idempotency key.
    const result=await request(operation.action,{...operation.data,operation_id:operation.operation_id},files);
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
  if(!authenticated()||mutationBusy||pending||insertionBusy||placeholders(preview).length||!preview.trim()||!contextValid()||selected?.project_key!==company||conversationProject()!==company||(replyReplacement&&!window.WorkspaceV2Conversation?.canReplaceQuickReply?.(replyReplacement)))return false;
  if(selected.materials?.length||replyReplacement){
    if(!window.WorkspaceV2QuickReplyMaterials){setStatus('Не удалось открыть материалы. Обновите страницу перед вставкой.','warn');return false}
    const capturedEpoch=epoch,reply=selected,text=preview,ctx=replyContext,project=company,ticket=++materialSequence;
    const valid=()=>capturedEpoch===epoch&&ticket===materialSequence&&dialog?.open===true&&authenticated()&&selected===reply&&preview===text&&company===project&&contextValid(ctx)&&conversationProject()===project;
    insertionBusy=true;updateInsertion();setStatus('Загружаем сохранённые материалы…');
    return window.WorkspaceV2QuickReplyMaterials.insert({endpoint,reply,text,context:ctx,project,valid,notice:message=>{if(valid())setStatus(message,'warn')},replacement:replyReplacement})
      .then(inserted=>{if(!valid())return false;if(inserted){returnFocus=$('replyText');close();return true}setStatus('Черновик или вложения изменились во время загрузки. Они сохранены; вставьте шаблон заново.','warn');return false})
      .catch(error=>{if(valid())setStatus(/[А-Яа-я]/.test(error.message)?error.message:'Не удалось загрузить материалы. Текущий черновик сохранён.','warn');return false})
      .finally(()=>{if(capturedEpoch===epoch&&ticket===materialSequence){insertionBusy=false;updateInsertion()}});
  }
  const inserted=window.WorkspaceV2Conversation?.insertQuickReply?.(preview,replyContext);
  if(!inserted){setStatus('Не удалось вставить текст. Проверьте доступ к текущему диалогу.','warn');return false}
  returnFocus=$('replyText');close();return true;
}
async function open(options={}){
  if(!identity())return false;
  window.WorkspaceV2QuickInsert?.close();replyReplacement=null;
  adoptSession();
  ensureDialog();returnFocus=options.opener||document.activeElement;
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
function close(){
  materialSequence++;insertionBusy=false;replyReplacement=null;closeQuickReplyEmojis();persist();
  const focus=returnFocus;if(dialog?.open)dialog.close();
  const active=conversationProject();if(active&&active!==company){switchCompany(active);void loadToolbar()}
  // Native close events are deferred. A later event must never steal focus
  // from text the manager has already started typing after this close.
  if(focus?.isConnected&&!focus.disabled)focus.focus({preventScroll:true});
}
function resetSession(options={}){
  window.WorkspaceV2QuickInsert?.close();replyReplacement=null;
  closeQuickReplyEmojis();
  persist();epoch++;loadTicket++;toolbarTicket++;clearTimeout(searchTimer);searchTimer=null;loading=null;toolbarLoading=null;
  owner=0;ownerGeneration=0;rows=[];categories=[];selected=null;preview='';replyContext=null;editor=null;pending=null;mutationBusy=false;nextOffset=null;listFailed=false;lastError='';query={scope:'all',search:'',category:'',favorites_only:false};
  toolbarRows=[];toolbarVersion='';toolbarReady=false;toolbarError='';toolbarFocus=null;renderToolbar();
  company='';observedProject='';uploadLimits=null;insertionBusy=false;materialSequence++;
  if(!options.preserveFiles)window.WorkspaceV2QuickReplyMaterials?.reset();
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
  trigger.onclick=()=>open({panel:true,opener:trigger});trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-controls','quickRepliesDialog');bound=true;renderToolbar();
}
window.WorkspaceV2QuickReplies={bind,configure,syncContext,open,close,resetSession,resolveText,placeholders,tripValues};
})();
