(function(){
'use strict';
const W=window.WorkspaceV2;
if(!W||!document.body.classList.contains('manager2'))return;
const {S,$}=W,storagePrefix='workspaceV2.promiseTasks.pending.v1.';
const kinds={send_calculation:'Отправить расчёт',send_selection:'Отправить подборку'};
const commandKeys=['conversation_id','operation_id','task_id','kind','trip_id','profile_version','profile_sha256','profile_context_sha256','source_message_id','source_message_revision','deadline'];
let dialog=null,view=null,epoch=0,ticket=0,read=null,timer=0,deniedScope='';
const requests=new Set();
const positive=n=>Number.isSafeInteger(n)&&n>0;
const hex=(s,n)=>typeof s==='string'&&new RegExp('^[a-f0-9]{'+n+'}$').test(s);
const object=v=>!!v&&typeof v==='object'&&!Array.isArray(v);
const clone=v=>JSON.parse(JSON.stringify(v));
const node=(tag,text='',className='')=>{const n=document.createElement(tag);n.textContent=text;if(className)n.className=className;return n;};

function capture(){
 const c=S.detail?.conversation,base=window.WorkspaceV2Conversation?.captureReplyContext?.();
 if(!base||S.authExpired||S.logoutPending||!positive(base.managerId)||!positive(base.conversationId)
  ||Number(S.current)!==base.conversationId||Number(c?.id)!==base.conversationId||c.project_key!=='anytour'
  ||window.WorkspaceV2Conversation?.hasReadAccess?.()===false)return null;
 return {...base,project:c.project_key,customer:Number(c.customer_id||0),customerChannel:Number(c.customer_channel_id||0),
  channel:String(c.channel||''),externalChat:String(c.external_chat_id||''),owner:Number(c.manager_id||0),status:String(c.status||''),
  role:String(S.manager?.role||''),supervisor:S.detail?.supervisor_read===true,ownerRead:S.detail?.owner_read===true};
}
const scopeKey=c=>c?JSON.stringify(c):'';
const sameSession=c=>!!c&&!S.authExpired&&!S.logoutPending&&Number(S.manager?.id)===c.managerId&&S.authGeneration===c.authGeneration;
const sameScope=c=>!!c&&scopeKey(c)===scopeKey(capture());
const current=v=>view===v&&v.epoch===epoch&&dialog?.open===true&&sameScope(v.scope);
function candidate(id){return (S.detail?.messages||[]).find(m=>Number(m.id)===id)||null;}
function eligible(m){return !!m&&positive(Number(m.id))&&m.direction==='outbound'&&m.sender_type==='manager'
 &&typeof m.text==='string'&&m.text.trim()!==''&&!m.linked_message&&!m.service_action;}
function messageStamp(m){return m?JSON.stringify([m.id,m.direction,m.sender_type,m.text,m.linked_message||null]):'';}
function editHint(c){return c&&(c.owner===c.managerId||c.role==='admin');}

function validDeadline(d){
 if(d===null)return true;
 if(!object(d)||typeof d.value!=='string')return false;
 if(d.kind==='date')return Object.keys(d).length===3&&d.zone==='Europe/Moscow'&&calendarDay(d.value);
 return d.kind==='instant'&&Object.keys(d).length===2&&/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/.test(d.value)
  &&!!window.ManagerTime?.parse(d.value);
}
function calendarDay(value){
 if(!/^\d{4}-\d{2}-\d{2}$/.test(value)||Number(value.slice(0,4))<1000)return false;
 const date=new Date(value+'T00:00:00Z');return !Number.isNaN(date.getTime())&&date.toISOString().slice(0,10)===value;
}
function validCommand(c){
 return object(c)&&Object.keys(c).length===commandKeys.length&&commandKeys.every(k=>Object.hasOwn(c,k))
  &&positive(c.conversation_id)&&hex(c.operation_id,32)&&positive(c.task_id)&&Object.hasOwn(kinds,c.kind)
  &&hex(c.trip_id,32)&&positive(c.profile_version)&&hex(c.profile_sha256,64)&&hex(c.profile_context_sha256,64)
  &&positive(c.source_message_id)&&hex(c.source_message_revision,64)&&validDeadline(c.deadline);
}
function recordKey(p){return storagePrefix+p.actor+'.'+p.input.conversation_id+'.'+p.input.operation_id;}
function pendingFor(c,sourceId=0){
 try{
  const store=window.localStorage,prefix=storagePrefix+c.managerId+'.'+c.conversationId+'.',records=[];
  for(let i=0;i<store.length;i++){
   const key=store.key(i);if(!key?.startsWith(prefix))continue;
   const raw=store.getItem(key);if(!raw||raw.length>12000)throw new Error('invalid_pending');
   const p=JSON.parse(raw);
   if(p?.schema!==1||p.actor!==c.managerId||!validCommand(p.input)||p.input.conversation_id!==c.conversationId||recordKey(p)!==key)throw new Error('invalid_pending');
   records.push(p);if(records.length>32)throw new Error('pending_limit');
  }
  records.sort((a,b)=>a.input.operation_id.localeCompare(b.input.operation_id));
  return {pending:records.find(p=>p.input.source_message_id===sourceId)||records[0]||null,blocked:false};
 }catch(e){return {pending:null,blocked:true};}
}
function persistPending(p){
 try{const raw=JSON.stringify(p),key=recordKey(p);if(raw.length>12000)return false;window.localStorage.setItem(key,raw);return window.localStorage.getItem(key)===raw;}catch(e){return false;}
}
function forgetPending(p){
 try{const key=recordKey(p),raw=window.localStorage.getItem(key);if(raw&&raw!==JSON.stringify(p))return false;window.localStorage.removeItem(key);return window.localStorage.getItem(key)===null;}catch(e){return false;}
}
function operationId(){const bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);return [...bytes].map(b=>b.toString(16).padStart(2,'0')).join('');}
function abortRead(){ticket++;read?.abort();read=null;}
async function request(action,data,{isCurrent,controller=new AbortController()}={}){
 requests.add(controller);const timeout=setTimeout(()=>controller.abort(),20000);
 try{return await W.pipe(action,data,{signal:controller.signal,isCurrent});}
 finally{clearTimeout(timeout);requests.delete(controller);}
}
function status(text,error=false){if(!$('promiseTaskStatus'))return;$('promiseTaskStatus').textContent=text;$('promiseTaskStatus').classList.toggle('error',error);}
function sourceText(source){
 $('promiseTaskSource').textContent=source?.text||'Текст исходного сообщения сейчас не загружен.';
 const at=source?.recorded_at_utc?window.ManagerTime.format(source.recorded_at_utc,{year:true})+' МСК':'Время записи не подтверждено';
 $('promiseTaskSourceMeta').textContent=source?.author_manager_id?'Сообщение менеджера · '+at:'Исходящая реплика в этом диалоге';
}
function taskDue(task){return task.due_at_utc?window.ManagerTime.format(task.due_at_utc,{year:true})+' МСК':'без срока';}
function taskLabel(task){return String(task.title||'Существующая задача')+' · '+(task.status==='done'?'Выполнено · ':'')+taskDue(task);}
function validTask(t,cid,id=null){return object(t)&&positive(Number(t.id))&&Number(t.conversation_id)===cid&&(id===null||Number(t.id)===id)
 &&typeof t.title==='string'&&t.title.length<=1024&&['open','done'].includes(t.status)&&(t.due_at_utc===null||typeof t.due_at_utc==='string');}
function validBinding(b,c,kind,sourceId,trip=null){return object(b)&&hex(b.promise_id,64)&&b.conversation_id===c.conversationId
 &&positive(b.task_id)&&validTask(b.task,c.conversationId,b.task_id)&&b.kind===kind&&b.source_message_id===sourceId
 &&hex(b.trip_id,32)&&(trip===null||b.trip_id===trip)&&positive(b.profile_version)&&hex(b.profile_sha256,64)
 &&hex(b.source_message_revision,64)&&positive(b.source_author_manager_id)&&validDeadline(b.deadline);}
function validContext(r,v,taskId,kind){
 const p=r?.profile,s=r?.source;
 return r?.ok===true&&r.viewer_id===v.scope.managerId&&r.conversation_id===v.scope.conversationId&&typeof r.can_bind==='boolean'
  &&validTask(r.task,v.scope.conversationId,taskId)&&object(p)&&hex(p.trip_id,32)&&positive(p.version)&&hex(p.sha256,64)&&hex(p.context_sha256,64)
  &&positive(p.boundary_message_id)&&['fresh','needs_review'].includes(p.status)&&typeof p.label==='string'&&p.label.length<=640
  &&(object(p.fields)||Array.isArray(p.fields)&&p.fields.length===0)&&Object.keys(p.fields).length<=12&&object(p.field_labels)
  &&object(s)&&s.id===v.sourceId&&s.id>=p.boundary_message_id&&positive(s.author_manager_id)&&hex(s.revision,64)
  &&typeof s.text==='string'&&s.text.trim()!==''&&s.text.length<=24000&&(s.recorded_at_utc===null||typeof s.recorded_at_utc==='string')
  &&(r.binding===null||validBinding(r.binding,v.scope,kind,v.sourceId,p.trip_id));
}
function setDeadline(d){
 $('promiseTaskDeadlineMode').value=d===null?'none':d.kind;
 $('promiseTaskDate').value=d?.kind==='date'?d.value:'';
 $('promiseTaskInstant').value=d?.kind==='instant'?window.ManagerTime.toInput(d.value):'';
}
function deadline(){
 const mode=$('promiseTaskDeadlineMode').value;
 if(mode==='none')return null;
 if(mode==='date'&&calendarDay($('promiseTaskDate').value))return {kind:'date',value:$('promiseTaskDate').value,zone:'Europe/Moscow'};
 if(mode==='instant'){
  const iso=window.ManagerTime?.fromInput($('promiseTaskInstant').value);
  if(iso){const value=iso.replace(/\.000Z$/,'Z');if(validDeadline({kind:'instant',value}))return {kind:'instant',value};}
 }
 throw new Error('Выберите срок: не указан, дата или точное время по Москве.');
}
function deadlineText(d){if(d===null)return 'Срок обещания не указан';if(d.kind==='date'){const [y,m,day]=d.value.split('-');return 'До '+day+'.'+m+'.'+y+' · календарный день, Москва';}return 'До '+window.ManagerTime.format(d.value,{year:true})+' МСК';}
function selection(){return JSON.stringify([$('promiseTaskKind').value,$('promiseTaskTask').value,$('promiseTaskDeadlineMode').value,$('promiseTaskDate').value,$('promiseTaskInstant').value]);}
function controls(){
 const v=view;if(!v||!dialog?.open)return;
 const locked=!!v.busy||!!v.pending||!!v.confirmed;
 for(const id of ['promiseTaskKind','promiseTaskTask','promiseTaskDeadlineMode','promiseTaskDate','promiseTaskInstant'])$(id).disabled=locked||!v.loaded;
 $('promiseTaskDateWrap').hidden=$('promiseTaskDeadlineMode').value!=='date';
 $('promiseTaskInstantWrap').hidden=$('promiseTaskDeadlineMode').value!=='instant';
 $('promiseTaskPreview').hidden=!!v.pending||!!v.confirmed;
 $('promiseTaskPreview').disabled=!!v.busy||!v.loaded||!v.tasks.length||v.storageBlocked;
 $('promiseTaskConfirm').hidden=!v.ready||!!v.ready.result.binding||!v.ready.result.can_bind||!!v.pending||!!v.confirmed;
 $('promiseTaskConfirm').disabled=!!v.busy||!v.canEdit||v.storageBlocked;
 $('promiseTaskCheck').hidden=!v.pending;
 $('promiseTaskCheck').disabled=!!v.busy;
 $('promiseTaskRetry').hidden=!v.pending||!v.notFound;
 $('promiseTaskRetry').disabled=!!v.busy||v.storageBlocked;
 $('promiseTaskReload').hidden=v.loaded||!!v.pending;
 $('promiseTaskReload').disabled=!!v.busy;
 $('promiseTaskProfileOpen').hidden=!v.profileRequired||!!v.pending;
 $('promiseTaskPreview').textContent=v.busy==='preview'?'Проверяем…':'Проверить связь';
 $('promiseTaskConfirm').textContent=v.busy==='write'?'Сохраняем…':'Подтвердить связь';
}
function edit(){
 const v=view;if(!v||v.pending||v.confirmed)return;
 abortRead();v.busy='';v.ready=null;v.profileRequired=false;$('promiseTaskReview').hidden=true;status('');controls();
}
function renderProfile(p,binding){
 const root=$('promiseTaskProfile');root.replaceChildren();root.appendChild(node('h3',p.label||'Текущая поездка'));
 root.appendChild(node('p',binding&&binding.profile_version!==p.version
  ?'Связь создана с версией '+binding.profile_version+'. Ниже показан текущий запрос, версия '+p.version+'.'
  :'Текущий запрос · версия '+p.version,'promiseTaskHint'));
 if(p.status==='needs_review')root.appendChild(node('p','После сохранения запроса переписка изменилась. Сверьте текущие условия и выбранное обещание.','promiseTaskReviewNotice'));
 const list=node('dl','','promiseTaskConditions');
 for(const [key,field] of Object.entries(p.fields)){
  if(!object(field))continue;
  let value=typeof field.value==='string'?field.value:'';
  if(key==='party'&&object(field.value)){const party=field.value;value=party.adults+' взрослых, '+party.children+' детей';if(Array.isArray(party.child_ages)&&party.child_ages.length)value+=' · возраст: '+party.child_ages.join(', ');}
  if(!value)continue;
  const label=typeof p.field_labels[key]==='string'?p.field_labels[key]:key;
  list.append(node('dt',label),node('dd',value));
 }
 if(list.children.length)root.appendChild(list);else root.appendChild(node('p','Условия поездки ещё не заполнены.','promiseTaskHint'));
}
function renderTask(task,target=$('promiseTaskTaskSummary')){
 target.replaceChildren(node('strong',task.title),node('p',(task.status==='done'?'Выполнено':'Открытая задача')+' · срок задачи: '+taskDue(task),'promiseTaskHint'));
}
function renderPreview(v,result,declaredDeadline){
 const binding=result.binding,task=binding?.task||result.task;
 sourceText(result.source);renderProfile(result.profile,binding);renderTask(task);
 $('promiseTaskReviewTitle').textContent=binding?'Обещание уже связано с задачей':'Проверьте связь';
 $('promiseTaskResultKind').textContent=kinds[binding?.kind||$('promiseTaskKind').value];
 $('promiseTaskDeclaredDeadline').textContent=deadlineText(binding?binding.deadline:declaredDeadline);
 $('promiseTaskBindingNotice').textContent=binding&&binding.task_id!==Number($('promiseTaskTask').value)
  ?'Для этого обещания уже выбрана другая задача. Показана сохранённая связь.':binding?'Сохранённая связь остаётся прежней, включая выполненную задачу.':'';
 $('promiseTaskReview').hidden=false;
}
function ensureDialog(){
 if(dialog)return;
 dialog=document.createElement('dialog');dialog.id='promiseTaskDialog';dialog.className='promiseTaskDialog';dialog.setAttribute('aria-labelledby','promiseTaskTitle');
 dialog.innerHTML=`<div class="promiseTaskShell"><header class="promiseTaskHead"><div><p class="promiseTaskEyebrow">Обещание менеджера</p><h2 id="promiseTaskTitle">Связать с задачей</h2><p id="promiseTaskClient"></p></div><button id="promiseTaskClose" type="button" class="promiseTaskClose" aria-label="Закрыть привязку">×</button></header><div id="promiseTaskStatus" class="promiseTaskStatus" role="status" aria-live="polite" tabindex="-1"></div><div class="promiseTaskBody"><section class="promiseTaskSource"><h3>Выбранное обещание</h3><blockquote id="promiseTaskSource"></blockquote><p id="promiseTaskSourceMeta" class="promiseTaskHint"></p></section><div class="promiseTaskFields"><label for="promiseTaskKind">Что обещали отправить<select id="promiseTaskKind"><option value="">Выберите результат</option><option value="send_calculation">Расчёт</option><option value="send_selection">Подборку</option></select></label><label for="promiseTaskTask">Существующая задача<select id="promiseTaskTask"><option value="">Выберите задачу</option></select></label><label for="promiseTaskDeadlineMode">Срок обещания<select id="promiseTaskDeadlineMode"><option value="">Укажите срок явно</option><option value="none">Срок не указан</option><option value="date">Календарная дата</option><option value="instant">Дата и точное время</option></select></label><label id="promiseTaskDateWrap" for="promiseTaskDate" hidden>Дата · Москва<input id="promiseTaskDate" type="date" min="1000-01-01" max="9999-12-31"></label><label id="promiseTaskInstantWrap" for="promiseTaskInstant" hidden>Дата и время · Москва<input id="promiseTaskInstant" type="datetime-local" min="1000-01-01T00:00" max="9999-12-31T23:59"></label></div><section id="promiseTaskReview" class="promiseTaskReview" hidden><h3 id="promiseTaskReviewTitle">Проверьте связь</h3><p id="promiseTaskResultKind" class="promiseTaskResultKind"></p><div id="promiseTaskTaskSummary" class="promiseTaskTaskSummary"></div><p id="promiseTaskDeclaredDeadline"></p><p id="promiseTaskBindingNotice" class="promiseTaskHint"></p><div id="promiseTaskProfile"></div></section><button id="promiseTaskReload" class="promiseTaskButton" type="button" hidden>Повторить загрузку задач</button><button id="promiseTaskProfileOpen" class="promiseTaskButton" type="button" hidden>Открыть запрос клиента</button></div><footer class="promiseTaskFooter"><p>Связь сохраняет обещание и его срок. Поля и выполнение задачи остаются в карточке клиента.</p><div class="promiseTaskActions"><button id="promiseTaskPreview" class="promiseTaskButton" type="button">Проверить связь</button><button id="promiseTaskConfirm" class="promiseTaskButton promiseTaskPrimary" type="button" hidden>Подтвердить связь</button><button id="promiseTaskCheck" class="promiseTaskButton promiseTaskPrimary" type="button" hidden>Проверить сохранение</button><button id="promiseTaskRetry" class="promiseTaskButton" type="button" hidden>Повторить эту попытку</button></div></footer></div>`;
 document.body.appendChild(dialog);
 $('promiseTaskClose').onclick=()=>close();$('promiseTaskPreview').onclick=()=>preview();$('promiseTaskConfirm').onclick=()=>confirm();$('promiseTaskCheck').onclick=()=>check();$('promiseTaskRetry').onclick=()=>retry();$('promiseTaskReload').onclick=()=>view&&loadTasks(view);
 $('promiseTaskProfileOpen').onclick=()=>{const v=view;if(!v||!current(v))return;const opener=v.opener;close({restoreFocus:false});void window.WorkspaceV2RequestProfile?.open(opener);};
 for(const id of ['promiseTaskKind','promiseTaskTask','promiseTaskDeadlineMode','promiseTaskDate','promiseTaskInstant']){ $(id).addEventListener('input',edit);$(id).addEventListener('change',edit); }
 dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
 dialog.addEventListener('keydown',event=>event.stopPropagation());
 dialog.addEventListener('close',()=>{if(view&&!dialog.open)close();});
}
function clearDialog(){
 if(!dialog)return;
 for(const id of ['promiseTaskSource','promiseTaskSourceMeta','promiseTaskProfile','promiseTaskTaskSummary','promiseTaskClient','promiseTaskStatus','promiseTaskBindingNotice','promiseTaskResultKind','promiseTaskDeclaredDeadline'])$(id).replaceChildren();
 $('promiseTaskTask').replaceChildren();
 for(const id of ['promiseTaskKind','promiseTaskDeadlineMode','promiseTaskDate','promiseTaskInstant'])$(id).value='';
 $('promiseTaskReview').hidden=true;
}
function close({restoreFocus=true,fromHistory=false}={}){
 const previous=view;view=null;epoch++;abortRead();for(const controller of requests)controller.abort();
 if(previous?.overlay&&!fromHistory)window.WorkspaceV2Mobile?.closeOverlay('promise-task');
 if(dialog?.open)dialog.close();clearDialog();
 if(restoreFocus&&previous&&sameScope(previous.scope)){
  const opener=previous.opener?.isConnected?previous.opener:sourceButton(previous.sourceId);
  opener?.focus({preventScroll:true});
 }
 return !!previous;
}
function reset(forget=false){
 close({restoreFocus:false});clearTimeout(timer);timer=0;deniedScope='';
 document.querySelectorAll('.promiseTaskSourceButton').forEach(button=>button.remove());
 // Durable pending commands stay isolated by actor. A different identity never
 // reads them; the original actor may reconcile after authenticating again.
 if(forget)clearDialog();
}
function sourceButton(id){return [...document.querySelectorAll('.promiseTaskSourceButton')].find(button=>Number(button.dataset.promiseSourceId)===id)||null;}
function refuse(v){if(view===v){const key=scopeKey(v.scope);close({restoreFocus:false});deniedScope=key;decorate();}}
function taskOptions(v){
 const select=$('promiseTaskTask'),selected=v.pending?String(v.pending.input.task_id):select.value;
 select.replaceChildren();const first=node('option','Выберите задачу');first.value='';select.appendChild(first);
 for(const task of v.tasks){const option=node('option',taskLabel(task));option.value=String(task.id);select.appendChild(option);}
 if(v.pending&&!v.tasks.some(t=>Number(t.id)===v.pending.input.task_id)){const option=node('option','Задача из неподтверждённой попытки · требуется проверка');option.value=String(v.pending.input.task_id);select.appendChild(option);}
 select.value=selected;
}
async function loadTasks(v){
 if(!current(v)||v.busy)return false;
 abortRead();const run=ticket;read=new AbortController();v.busy='loading';status('Загружаем существующие задачи…');controls();
 const valid=()=>current(v)&&run===ticket;let result;
 try{result=await request('detail',{conversation_id:v.scope.conversationId},{controller:read,isCurrent:valid});}catch(e){result=null;}
 if(!valid())return false;read=null;v.busy='';
 if([403,404].includes(result?.http_status)){refuse(v);return false;}
 const degraded=result?.degraded_components;
 const good=result?.ok===true&&typeof result.can_edit_pipeline==='boolean'&&Array.isArray(result.tasks)
  &&(degraded===undefined||Array.isArray(degraded))&&!(degraded||[]).includes('tasks')&&!(degraded||[]).includes('pipeline')
  &&result.tasks.every(task=>validTask(task,v.scope.conversationId));
 if(!good){v.loaded=false;status('Не удалось проверить список задач. Попробуйте загрузить его снова.',true);controls();return false;}
 v.loaded=true;v.tasks=result.tasks;v.canEdit=result.can_edit_pipeline===true;taskOptions(v);
 if(v.pending)status('Есть неподтверждённая попытка привязки. Проверьте сохранение перед следующим действием.');
 else if(v.storageBlocked)status('Не удалось прочитать сведения о незавершённых попытках. Сохранение пока недоступно.',true);
 else if(!v.tasks.length)status('В этом диалоге пока нет задач. Создайте задачу в карточке клиента, затем откройте привязку снова.');
 else if(!v.canEdit)status('Доступен просмотр существующей связи. Новую связь сейчас сохранить нельзя.');
 else status('Выберите результат, задачу и срок обещания. Затем проверьте связь.');
 controls();return true;
}
async function open(sourceId,opener=document.activeElement){
 sourceId=Number(sourceId);const scope=capture(),message=candidate(sourceId);
 if(!scope||scopeKey(scope)===deniedScope)return false;
 const saved=pendingFor(scope,sourceId),pending=saved.pending;
 if(!eligible(message)&&!pending)return false;
 close({restoreFocus:false});ensureDialog();
 const v={scope,sourceId:pending?.input.source_message_id||sourceId,opener,epoch:++epoch,overlay:false,
  originalStamp:messageStamp(candidate(pending?.input.source_message_id||sourceId)),loaded:false,tasks:[],canEdit:false,busy:'',ready:null,confirmed:null,
  pending,notFound:false,storageBlocked:saved.blocked,profileRequired:false};view=v;
 const overlay=window.WorkspaceV2Mobile?.openOverlay('promise-task',()=>close({fromHistory:true}));
 if(overlay===false){view=null;return false;}v.overlay=!!overlay;
 $('promiseTaskClient').textContent=String(S.detail.conversation.display_name||'Турист');
 $('promiseTaskKind').value=pending?.input.kind||'';$('promiseTaskTask').value='';$('promiseTaskDeadlineMode').value='';$('promiseTaskDate').value='';$('promiseTaskInstant').value='';
 if(pending)setDeadline(pending.input.deadline);
 sourceText(candidate(v.sourceId));$('promiseTaskReview').hidden=true;
 dialog.showModal();status('Загружаем существующие задачи…');$('promiseTaskStatus').focus({preventScroll:true});controls();
 return loadTasks(v);
}
const errors={profile_required:'Сначала сохраните запрос текущей поездки.',profile_source_changed:'Основание запроса изменилось. Проверьте запрос клиента.',
 profile_changed:'Запрос поездки изменился. Проверьте связь заново.',context_changed:'Переписка или назначение изменились. Проверьте связь заново.',
 source_changed:'Выбранное сообщение изменилось. Проверьте обещание заново.',source_unavailable:'Это сообщение нельзя использовать как основание связи. Выберите исходящее сообщение менеджера текущей поездки.',
 binding_conflict:'Для обещания или задачи уже сохранена другая связь. Проверьте существующую связь.',task_closed:'Для новой связи нужна открытая задача.',task_not_found:'Задача больше недоступна. Загрузите список задач заново.',
 invalid_binding:'Данные привязки не приняты. Проверьте связь заново.',invalid_deadline:'Проверьте дату и время обещания.',operation_mismatch:'Данные сохранённой попытки не совпали. Повторное сохранение заблокировано.'};
async function preview(){
 const v=view;if(!v||!current(v)||v.busy||v.pending||v.confirmed||!v.loaded||v.storageBlocked)return false;
 const taskId=Number($('promiseTaskTask').value),kind=$('promiseTaskKind').value;let declared;
 if(!Object.hasOwn(kinds,kind)||!positive(taskId)||!v.tasks.some(t=>Number(t.id)===taskId)){status('Выберите результат и существующую задачу.',true);return false;}
 try{declared=deadline();}catch(e){status(e.message,true);return false;}
 abortRead();const run=ticket,signature=selection();read=new AbortController();v.busy='preview';v.ready=null;v.profileRequired=false;$('promiseTaskReview').hidden=true;status('Проверяем обещание и условия поездки…');controls();
 const valid=()=>current(v)&&run===ticket&&selection()===signature;let result;
 try{result=await request('promise_context',{conversation_id:v.scope.conversationId,task_id:taskId,source_message_id:v.sourceId,kind},{controller:read,isCurrent:valid});}catch(e){result=null;}
 if(!valid())return false;read=null;v.busy='';
 if(result?.error==='forbidden'||result?.http_status===403){refuse(v);return false;}
 if(!validContext(result,v,taskId,kind)){v.profileRequired=result?.error==='profile_required';status(errors[result?.error]||'Не удалось проверить связь. Ничего не сохранено.',true);controls();return false;}
 v.ready={result,deadline:declared,signature};renderPreview(v,result,declared);
 if(result.binding)status('Связь уже сохранена. Задача показана в её текущем состоянии.');
 else if(!result.can_bind||!v.canEdit)status(result.task.status==='done'?'Для новой связи нужна открытая задача.':'Новую связь для этой задачи сейчас сохранить нельзя.',true);
 else status('Проверьте обещание, задачу и условия поездки. Сохранение — отдельным подтверждением.');
 controls();return true;
}
function successfulBind(result,v,p){
 const c=p.input,b=result?.binding;
 return result?.ok===true&&result.viewer_id===p.actor&&result.id===c.task_id&&hex(result.promise_id,64)
  &&validBinding(b,v.scope,c.kind,c.source_message_id,c.trip_id)&&b.task_id===c.task_id&&b.promise_id===result.promise_id
  &&b.source_message_revision===c.source_message_revision&&JSON.stringify(b.deadline)===JSON.stringify(c.deadline)
  &&validTask(result.task,c.conversation_id,c.task_id);
}
function showConfirmed(v,p,result){
 if(!current(v))return;
 v.pending=null;v.ready=null;v.busy='';v.confirmed=result;v.notFound=false;
 if(result.found===true)$('promiseTaskProfile').replaceChildren();
 $('promiseTaskReview').hidden=false;$('promiseTaskReviewTitle').textContent='Связь сохранена';$('promiseTaskResultKind').textContent=kinds[p.input.kind];
 renderTask(result.task);$('promiseTaskDeclaredDeadline').textContent=deadlineText(p.input.deadline);
 $('promiseTaskBindingNotice').textContent='Обещание связано с этой задачей. Её поля и состояние не изменены.';
 status('Связь подтверждена. '+(result.task.status==='done'?'Задача остаётся выполненной.':'Выполнение отмечается в карточке задачи.'));controls();
}
const definiteRejections=new Set(['profile_required','profile_changed','context_changed','profile_source_changed','source_changed','source_unavailable','binding_conflict','task_closed','task_not_found']);
async function dispatch(v,p){
 if(!current(v)||v.busy)return false;
 if(!persistPending(p)){v.storageBlocked=true;status('Не удалось сохранить данные попытки для проверки результата. Запрос не отправлен.',true);controls();return false;}
 v.busy='write';v.notFound=false;status('Сохраняем связь…');controls();let result;
 try{result=await request('bind_promise',clone(p.input),{isCurrent:()=>sameSession(v.scope)});}catch(e){result=null;}
 if(!sameSession(v.scope))return false;
 if(successfulBind(result,v,p)){forgetPending(p);showConfirmed(v,p,result);return true;}
 if(!current(v))return false;v.busy='';
 if(result?.error==='forbidden'||result?.http_status===403){refuse(v);return false;}
 if(result?.ok===false&&definiteRejections.has(result.error)&&Number(result.http_status||0)<500){
  if(forgetPending(p)){v.pending=null;v.ready=null;$('promiseTaskReview').hidden=true;v.profileRequired=result.error==='profile_required';}
  else v.storageBlocked=true;
  status(errors[result.error],true);controls();return false;
 }
 status(errors[result?.error]||'Результат сохранения пока неизвестен. Проверьте, появилась ли связь.',true);controls();return false;
}
async function confirm(){
 const v=view,r=v?.ready;
 if(!v||!current(v)||v.busy||v.pending||v.confirmed||!r||r.signature!==selection()||r.result.binding||r.result.can_bind!==true||!v.canEdit||v.storageBlocked)return false;
 const saved=pendingFor(v.scope,v.sourceId);
 if(saved.blocked){v.storageBlocked=true;status('Не удалось проверить незавершённые попытки. Сохранение заблокировано.',true);controls();return false;}
 if(saved.pending){v.pending=saved.pending;v.ready=null;v.sourceId=saved.pending.input.source_message_id;$('promiseTaskKind').value=saved.pending.input.kind;setDeadline(saved.pending.input.deadline);taskOptions(v);sourceText(candidate(v.sourceId));$('promiseTaskReview').hidden=true;status('В этом диалоге уже есть неподтверждённая попытка. Проверьте её сохранение.');controls();return false;}
 const profile=r.result.profile,source=r.result.source;let id;
 try{id=operationId();}catch(e){status('Не удалось подготовить идентификатор попытки. Ничего не сохранено.',true);return false;}
 const p={schema:1,actor:v.scope.managerId,input:{conversation_id:v.scope.conversationId,operation_id:id,task_id:Number(r.result.task.id),kind:$('promiseTaskKind').value,
  trip_id:profile.trip_id,profile_version:profile.version,profile_sha256:profile.sha256,profile_context_sha256:profile.context_sha256,
  source_message_id:source.id,source_message_revision:source.revision,deadline:clone(r.deadline)}};
 if(!validCommand(p.input))return false;
 if(!persistPending(p)){v.storageBlocked=true;status('Не удалось сохранить данные попытки для проверки результата. Запрос не отправлен.',true);controls();return false;}
 v.pending=p;return dispatch(v,p);
}
async function check(){
 const v=view,p=v?.pending;if(!v||!p||!current(v)||v.busy)return false;
 v.busy='check';v.notFound=false;status('Проверяем сохранение…');controls();let result;
 try{result=await request('promise_operation',{conversation_id:p.input.conversation_id,operation_id:p.input.operation_id},{isCurrent:()=>sameSession(v.scope)});}catch(e){result=null;}
 if(!sameSession(v.scope))return false;
 const valid=result?.ok===true&&result.viewer_id===p.actor&&typeof result.found==='boolean';
 if(valid&&result.found&&hex(result.promise_id,64)&&result.task_id===p.input.task_id&&validTask(result.task,p.input.conversation_id,p.input.task_id)){
  forgetPending(p);showConfirmed(v,p,result);return true;
 }
 if(!current(v))return false;v.busy='';
 if(result?.error==='forbidden'||result?.http_status===403){refuse(v);return false;}
 if(valid&&!result.found&&result.promise_id===null&&result.task_id===null&&result.task===null){v.notFound=true;status('Подтверждение пока не найдено. Можно проверить снова или явно повторить эту же попытку.');}
 else status(errors[result?.error]||'Исход пока неизвестен. Повторная запись заблокирована; проверьте сохранение позже.',true);
 controls();return false;
}
function retry(){const v=view;if(!v||!current(v)||!v.pending||!v.notFound||v.busy||v.storageBlocked)return Promise.resolve(false);return dispatch(v,v.pending);}
function decorate(){
 if(view&&!sameScope(view.scope))close({restoreFocus:false});
 if(view&&!view.pending&&!view.confirmed){
  const m=candidate(view.sourceId),stamp=messageStamp(m);
  if(m&&stamp!==view.originalStamp){view.originalStamp=stamp;edit();sourceText(m);status('Выбранное сообщение изменилось. Проверьте связь заново.',true);}
 }
 const c=capture(),recover=c?pendingFor(c):{pending:null},allowed=!!c&&scopeKey(c)!==deniedScope&&(editHint(c)||!!recover.pending);
 const messages=new Map((S.detail?.messages||[]).map(m=>[Number(m.id),m]));
 for(const row of $('messages')?.children||[]){
  const id=Number(row.dataset.scrollMessageKey),m=messages.get(id),button=row.querySelector('.promiseTaskSourceButton');
  if(!allowed||(!eligible(m)&&recover.pending?.input.source_message_id!==id)){button?.remove();continue;}
  if(button)continue;const meta=row.querySelector('.msgMeta');if(!meta)continue;
  const action=node('button','Связать с задачей','promiseTaskSourceButton');action.type='button';action.dataset.promiseSourceId=String(id);
  action.setAttribute('aria-label','Связать обещание из этого сообщения с задачей');action.onclick=()=>open(id,action);meta.appendChild(action);
 }
}
function schedule(){clearTimeout(timer);timer=setTimeout(()=>{timer=0;decorate();},0);}
window.addEventListener('pagehide',()=>reset());
window.WorkspaceV2PromiseTasks={open,close,preview,confirm,check,retry,reset,schedule,decorate};
schedule();
})();
