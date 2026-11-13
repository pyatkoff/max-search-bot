(function(){
'use strict';
const W=window.WorkspaceV2;
if(!W||!document.body.classList.contains('manager2'))return;
const {S,$}=W,endpoint='promise-selections-api.php',prefix='workspaceV2.promiseSelection.pending.v1.',previewLifetime=300000;
const states=['armed','completed','cancelled','blocked'],deliveryStates=['pending','rejected','reserved','unknown','known','delivered'];
const stateLabels={armed:'Ожидает отправки',completed:'Автоматическое выполнение применено',cancelled:'Автоматическое выполнение отменено',blocked:'Автоматическое выполнение остановлено'};
let dialog=null,view=null,epoch=0,ticket=0,read=null;
const requests=new Set(),object=value=>!!value&&typeof value==='object'&&!Array.isArray(value),positive=value=>Number.isSafeInteger(value)&&value>0;
const hex=(value,length)=>typeof value==='string'&&new RegExp('^[a-f0-9]{'+length+'}$').test(value),clone=value=>JSON.parse(JSON.stringify(value));
const text=(value,limit=24000)=>typeof value==='string'&&value.length<=limit;
const node=(tag,value='',className='')=>{const element=document.createElement(tag);element.textContent=value;if(className)element.className=className;return element};
function capture(){
 const c=S.detail?.conversation,base=window.WorkspaceV2Conversation?.captureReplyContext?.();
 if(!base||S.authExpired||S.logoutPending||!positive(base.managerId)||!positive(base.conversationId)||Number(S.current)!==base.conversationId||Number(c?.id)!==base.conversationId||c.project_key!=='anytour'||window.WorkspaceV2Conversation?.hasReadAccess?.()===false)return null;
 return {...base,project:c.project_key,customer:Number(c.customer_id||0),customerChannel:Number(c.customer_channel_id||0),channel:String(c.channel||''),externalChat:String(c.external_chat_id||''),owner:Number(c.manager_id||0),status:String(c.status||''),role:String(S.manager?.role||''),supervisor:S.detail?.supervisor_read===true,ownerRead:S.detail?.owner_read===true};
}
const scopeKey=value=>value?JSON.stringify(value):'',sameScope=value=>!!value&&scopeKey(value)===scopeKey(capture());
const sameSession=value=>!!value&&!S.authExpired&&!S.logoutPending&&Number(S.manager?.id)===value.managerId&&S.authGeneration===value.authGeneration;
const current=v=>view===v&&v.epoch===epoch&&dialog?.open===true&&sameScope(v.scope);
const canEdit=v=>v.catalog?.can_edit===true;
const accepted=(r,v)=>r?.ok===true&&r.viewer_id===v.scope.managerId&&r.conversation_id===v.scope.conversationId;
const validTime=value=>typeof value==='string'&&value.length<=40&&!!window.ManagerTime?.parse(value);
const time=(value,seconds=false)=>value?window.ManagerTime.format(value,{year:true})+(seconds?':'+String(window.ManagerTime.wallDate(value).getUTCSeconds()).padStart(2,'0'):'')+' МСК':'не указан';
function validTask(value){return object(value)&&positive(value.id)&&text(value.title,1024)&&['open','done'].includes(value.status)&&positive(value.business_revision)&&(value.due_at_utc===null||validTime(value.due_at_utc));}
function validDeadline(value){
 if(value===null)return true;if(!object(value)||typeof value.value!=='string')return false;
 if(value.kind==='date'){if(value.zone!=='Europe/Moscow'||!/^\d{4}-\d{2}-\d{2}$/.test(value.value)||Number(value.value.slice(0,4))<1000)return false;const date=new Date(value.value+'T00:00:00Z');return !Number.isNaN(date.getTime())&&date.toISOString().slice(0,10)===value.value;}
 return value.kind==='instant'&&/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/.test(value.value)&&validTime(value.value);
}
function validProfile(value){return object(value)&&hex(value.trip_id,32)&&positive(value.version)&&hex(value.sha256,64)&&text(value.label,640)&&['fresh','needs_review'].includes(value.status)&&(object(value.fields)||Array.isArray(value.fields)&&value.fields.length===0)&&Object.keys(value.fields).length<=12&&object(value.field_labels);}
function validIntent(value){return object(value)&&hex(value.intent_id,32)&&hex(value.promise_id,64)&&hex(value.publication_id,32)&&positive(value.task_id)&&states.includes(value.state)&&(value.reason===null||text(value.reason,128))&&positive(value.card_count)&&value.card_count<=20&&Number.isInteger(value.delivered_count)&&value.delivered_count>=0&&value.delivered_count<=value.card_count&&validTime(value.created_at)&&(value.completed_at===null||validTime(value.completed_at))&&(value.cancelled_at===null||validTime(value.cancelled_at));}
function validCatalog(r,v){return accepted(r,v)&&typeof r.can_edit==='boolean'&&validProfile(r.profile)&&['promises','publications','intents'].every(key=>Array.isArray(r[key])&&r[key].length<=20)
 &&r.promises.every(p=>object(p)&&hex(p.promise_id,64)&&p.kind==='send_selection'&&positive(p.source_message_id)&&validTask(p.task))
 &&r.publications.every(p=>object(p)&&hex(p.publication_id,32)&&text(p.title,640)&&positive(p.card_count)&&p.card_count<=20&&validTime(p.created_at)&&Array.isArray(p.states)&&p.states.length<=20&&p.states.every(state=>deliveryStates.includes(state)))&&r.intents.every(validIntent)
 &&new Set(r.promises.map(p=>p.promise_id)).size===r.promises.length&&new Set(r.publications.map(p=>p.publication_id)).size===r.publications.length&&new Set(r.intents.map(p=>p.intent_id)).size===r.intents.length;}
function validPreview(r,v,promiseId,publicationId){return accepted(r,v)&&hex(r.preview_sha256,64)&&typeof r.can_attach==='boolean'&&(r.reason===null||text(r.reason,128))
 &&object(r.promise)&&r.promise.promise_id===promiseId&&r.promise.kind==='send_selection'&&validDeadline(r.promise.deadline)&&validTask(r.task)&&validProfile(r.profile)
 &&object(r.source)&&positive(r.source.id)&&hex(r.source.revision,64)&&positive(r.source.author_manager_id)&&text(r.source.text)&&r.source.text.trim()!==''&&(r.source.recorded_at_utc===null||validTime(r.source.recorded_at_utc))
 &&object(r.publication)&&r.publication.publication_id===publicationId&&text(r.publication.title,640)&&positive(r.publication.card_count)&&r.publication.card_count<=20&&Array.isArray(r.publication.cards)&&r.publication.cards.length===r.publication.card_count
 &&r.publication.cards.every((card,index)=>object(card)&&card.index===index&&positive(card.offer_id)&&deliveryStates.includes(card.state)&&text(card.text)&&object(card.snapshot))&&(r.intent===null||validIntent(r.intent));}
const errors={invalid_input:'Не удалось принять данные связи. Проверьте выбор заново.',forbidden:'Доступ к этому действию изменился.',not_found:'Обещание или подборка больше недоступны.',profile_required:'Сначала сохраните запрос текущей поездки.',profile_source_changed:'Основание запроса изменилось. Проверьте условия поездки.',source_unavailable:'Исходное обещание больше недоступно.',source_changed:'Сообщение с обещанием изменилось. Проверьте связь заново.',profile_changed:'Условия поездки изменились. Проверьте связь заново.',preview_changed:'Проверенные данные изменились. Проверьте связь заново.',intent_conflict:'Для этого обещания уже сохранена другая связь.',operation_mismatch:'Сведения о попытке не совпали. Повторное сохранение заблокировано.',storage_unavailable:'Сервер пока не подтвердил действие.',task_changed:'Задача была изменена. Проверьте её вручную.',task_closed:'Задача уже завершена.',publication_already_sent:'В подборке есть ранее отправленные варианты.',delivery_started:'Отправка уже началась. Проверьте её результат.',delivery_unknown:'Результат отправки пока неизвестен.',trip_changed:'Текущая поездка изменилась.'};
Object.assign(errors,{already_attached:'Связь этого обещания с подборкой уже сохранена.',duplicate_cards:'В сохранённой подборке повторяются одинаковые варианты. Подготовьте состав без повторов.',unsupported_promise:'Для этой связи нужно обещание отправить подборку.',task_missing:'Связанная задача больше недоступна.',scope_changed:'Получатель или контекст диалога изменился. Проверьте связь заново.',context_changed:'После подтверждения связи появились новые сообщения или запрос клиента. Проверьте задачу вручную.',access_changed:'Доступ к автоматическому выполнению изменился.',delivery_evidence_changed:'Подтверждение доставки требует проверки.',task_done:'Задача уже была завершена.',publication_changed:'Сохранённый состав подборки изменился. Проверьте связь вручную.'});
const reason=code=>errors[code]||'Не удалось подтвердить возможность автоматического выполнения. Проверьте обещание, задачу и подборку.';
function status(value,error=false){$('promiseSelectionStatus').textContent=value;$('promiseSelectionStatus').classList.toggle('error',error);}
function abortRead(){ticket++;read?.abort();read=null;}
async function request(action,data,{isCurrent,controller=new AbortController()}={}){
 requests.add(controller);const timeout=setTimeout(()=>controller.abort(),20000);
 try{return await W.request(endpoint,action,data,{signal:controller.signal,isCurrent});}finally{clearTimeout(timeout);requests.delete(controller);}
}
function command(record){return record.action==='attach'?{operation_id:record.operation_id,promise_id:record.promise_id,publication_id:record.publication_id,preview_sha256:record.preview_sha256}:{operation_id:record.operation_id,intent_id:record.intent_id};}
function validPending(p){
 if(!object(p)||p.schema!==1||!positive(p.actor)||!positive(p.conversation_id)||!hex(p.operation_id,32)||!hex(p.request_sha256,64)||!['attach','cancel'].includes(p.action))return false;
 const keys=p.action==='attach'?['schema','actor','conversation_id','action','operation_id','request_sha256','promise_id','publication_id','preview_sha256']:['schema','actor','conversation_id','action','operation_id','request_sha256','intent_id'];
 return Object.keys(p).length===keys.length&&keys.every(key=>Object.hasOwn(p,key))&&(p.action==='attach'?hex(p.promise_id,64)&&hex(p.publication_id,32)&&hex(p.preview_sha256,64):hex(p.intent_id,32));
}
function pendingKey(p){return prefix+p.actor+'.'+p.conversation_id+'.'+p.operation_id;}
function pendingFor(scope){
 try{
  const records=[],store=window.localStorage,base=prefix+scope.managerId+'.'+scope.conversationId+'.';
  for(let index=0;index<store.length;index++){const key=store.key(index);if(!key?.startsWith(base))continue;const raw=store.getItem(key);if(!raw||raw.length>1800)throw Error('invalid_pending');const item=JSON.parse(raw);if(!validPending(item)||item.actor!==scope.managerId||item.conversation_id!==scope.conversationId||pendingKey(item)!==key)throw Error('invalid_pending');records.push(item);if(records.length>32)throw Error('pending_limit');}
  records.sort((a,b)=>a.operation_id.localeCompare(b.operation_id));return{pending:records[0]||null,blocked:false};
 }catch(e){return{pending:null,blocked:true};}
}
function persist(p){try{const key=pendingKey(p),raw=JSON.stringify(p),old=window.localStorage.getItem(key);if(old!==null&&old!==raw)return false;window.localStorage.setItem(key,raw);return window.localStorage.getItem(key)===raw;}catch(e){return false;}}
function forget(p){try{const key=pendingKey(p),old=window.localStorage.getItem(key);if(old&&old!==JSON.stringify(p))return false;window.localStorage.removeItem(key);return window.localStorage.getItem(key)===null;}catch(e){return false;}}
async function newPending(v,action,data){
 const bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);const operation_id=[...bytes].map(value=>value.toString(16).padStart(2,'0')).join('');
 const values=action==='attach'?[action,v.scope.conversationId,operation_id,data.promise_id,data.publication_id,data.preview_sha256]:[action,v.scope.conversationId,operation_id,data.intent_id];
 const digest=await window.crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify(values))),request_sha256=[...new Uint8Array(digest)].map(value=>value.toString(16).padStart(2,'0')).join('');
 return{schema:1,actor:v.scope.managerId,conversation_id:v.scope.conversationId,action,operation_id,request_sha256,...data};
}
function selection(){return JSON.stringify([$('promiseSelectionPromise').value,$('promiseSelectionPublication').value,$('promiseSelectionExisting').value]);}
function controls(){
 const v=view;if(!v||!dialog?.open)return;const locked=!!v.busy||!!v.pending||v.storageBlocked;
 const recovered=!v.catalog&&(!!v.pending||!!v.intent);$('promiseSelectionFields').hidden=recovered;
 if(recovered)$('promiseSelectionCatalogHint').textContent=v.pending?'Сначала проверьте сохранение этой попытки.':'Чтобы проверить основание связи или отменить автоматическое выполнение, обновите список.';
 for(const id of ['promiseSelectionPromise','promiseSelectionPublication','promiseSelectionExisting'])$(id).disabled=locked||!v.catalog;
 $('promiseSelectionReload').disabled=!!v.busy||!!v.pending;
 $('promiseSelectionExistingRead').disabled=locked||!$('promiseSelectionExisting').value;
 $('promiseSelectionPreview').hidden=!!v.pending||!!v.cancelling||!!v.intent;$('promiseSelectionPreview').disabled=locked||!v.catalog||!$('promiseSelectionPromise').value||!$('promiseSelectionPublication').value;
 $('promiseSelectionConfirm').hidden=!v.ready||!v.ready.result.can_attach||!!v.pending||!!v.cancelling;$('promiseSelectionConfirm').disabled=locked||!canEdit(v)||v.ready?.expires<=Date.now();
 $('promiseSelectionStatusRead').hidden=!v.intent||!!v.pending||!!v.cancelling;$('promiseSelectionStatusRead').disabled=locked;
 $('promiseSelectionCancel').hidden=v.intent?.state!=='armed'||!!v.pending||!!v.cancelling||!v.catalog;$('promiseSelectionCancel').disabled=locked||!canEdit(v);
 $('promiseSelectionCancelNotice').hidden=!v.cancelling;$('promiseSelectionCancelConfirm').hidden=!v.cancelling||!!v.pending;$('promiseSelectionCancelConfirm').disabled=locked||!canEdit(v);
 $('promiseSelectionCancelBack').hidden=!v.cancelling||!!v.pending;$('promiseSelectionCancelBack').disabled=locked;
 $('promiseSelectionCheck').hidden=!v.pending;$('promiseSelectionCheck').disabled=!!v.busy;
 $('promiseSelectionRetry').hidden=!v.pending||!v.notFound;$('promiseSelectionRetry').disabled=!!v.busy||v.storageBlocked;
}
function changed(){const v=view;if(!v||v.pending||v.busy==='write')return;abortRead();v.busy='';v.ready=null;v.intent=null;v.cancelling=null;$('promiseSelectionReview').hidden=true;$('promiseSelectionIntent').hidden=true;status('Проверьте выбранное обещание и сохранённую подборку.');controls();}
function option(select,value,label){const item=node('option',label);item.value=value;select.appendChild(item);}
function renderCatalog(v){
 const catalog=v.catalog,promise=$('promiseSelectionPromise'),publication=$('promiseSelectionPublication'),existing=$('promiseSelectionExisting');
 const selected=[promise.value,publication.value,existing.value];promise.replaceChildren();publication.replaceChildren();existing.replaceChildren();
 option(promise,'','Выберите обещание и задачу');option(publication,'','Выберите сохранённую подборку');option(existing,'','Выберите сохранённую связь');
 for(const p of catalog.promises)option(promise,p.promise_id,p.task.title+' · '+(p.task.status==='done'?'Выполнена':'Открыта'));
 for(const p of catalog.publications)option(publication,p.publication_id,p.title+' · '+p.card_count+' вар. · '+time(p.created_at));
 for(const p of catalog.intents){const task=catalog.promises.find(item=>item.promise_id===p.promise_id)?.task,publication=catalog.publications.find(item=>item.publication_id===p.publication_id);option(existing,p.intent_id,(task?.title||'Связь от '+time(p.created_at))+' · '+(publication?.title||'Сохранённая подборка')+' · '+stateLabels[p.state]);}
 for(const [i,select] of [promise,publication,existing].entries())if([...select.children].some(item=>item.value===selected[i]))select.value=selected[i];
 $('promiseSelectionExistingWrap').hidden=catalog.intents.length===0;
 $('promiseSelectionCatalogHint').textContent=!catalog.promises.length?'Сначала свяжите обещание «Отправить подборку» с существующей задачей в диалоге.':!catalog.publications.length?'Сначала подготовьте предпросмотр в редакторе подборки, затем обновите список.':'Публикации — сохранённые версии. Сверьте все варианты с условиями этой поездки.';
}
function renderProfile(profile){
 const root=$('promiseSelectionProfile');root.replaceChildren(node('h4',profile.label||'Текущая поездка'));
 if(profile.status==='needs_review')root.appendChild(node('p','Переписка изменилась. Сверьте текущие условия поездки с обещанием.','promiseSelectionHint'));
 const list=node('dl','','promiseSelectionConditions');
 for(const [key,field] of Object.entries(profile.fields)){if(!object(field))continue;let value=typeof field.value==='string'?field.value:'';if(key==='party'&&object(field.value))value=field.value.adults+' взрослых, '+field.value.children+' детей'+(Array.isArray(field.value.child_ages)&&field.value.child_ages.length?' · возраст: '+field.value.child_ages.join(', '):'');if(value)list.append(node('dt',typeof profile.field_labels[key]==='string'?profile.field_labels[key]:key),node('dd',value));}
 root.appendChild(list.children.length?list:node('p','Условия поездки ещё не заполнены.','promiseSelectionHint'));
}
function renderPreview(r){
 const deadline=r.promise.deadline,deadlineLabel=deadline===null?'не указан':deadline.kind==='date'?deadline.value.split('-').reverse().join('.')+' · календарный день, Москва':time(deadline.value,true);
 $('promiseSelectionQuote').textContent=r.source.text;$('promiseSelectionSourceMeta').textContent='Исходящее обещание менеджера · '+(r.source.recorded_at_utc?time(r.source.recorded_at_utc):'Время записи не подтверждено')+'. Срок обещания: '+deadlineLabel+'.';
 $('promiseSelectionTask').textContent=r.task.title+' · на момент проверки: '+(r.task.status==='done'?'выполнена':'открыта')+' · срок задачи: '+time(r.task.due_at_utc);
 renderProfile(r.profile);$('promiseSelectionCompositionTitle').textContent=r.publication.title+' · весь состав: '+r.publication.card_count+' вариантов';
 const list=$('promiseSelectionCards');list.replaceChildren();for(const card of r.publication.cards)list.appendChild(node('li',card.text));
 $('promiseSelectionReview').hidden=false;
}
function renderIntent(v,intent){
 v.intent=clone(intent);v.cancelling=null;const root=$('promiseSelectionIntent');root.replaceChildren(node('strong',stateLabels[intent.state]));
 if(intent.state==='armed')root.appendChild(node('p','Подтверждена отправка '+intent.delivered_count+' из '+intent.card_count+' вариантов. Выполнение задачи ожидает весь закреплённый состав.'));
 if(intent.state==='completed')root.appendChild(node('p','Автоматическое выполнение было применено'+(intent.completed_at?' '+time(intent.completed_at):'')+'. Текущее состояние задачи смотрите в карточке клиента.'));
 if(intent.state==='cancelled')root.appendChild(node('p','Автоматическое выполнение отменено'+(intent.cancelled_at?' '+time(intent.cancelled_at):'')+'. Обещание и задача остаются для ручной работы.'));
 if(intent.state==='blocked')root.appendChild(node('p',reason(intent.reason)));
 root.hidden=false;
}
function ensureDialog(){
 if(dialog)return;dialog=document.createElement('dialog');dialog.id='promiseSelectionDialog';dialog.className='promiseSelectionDialog';dialog.setAttribute('aria-labelledby','promiseSelectionTitle');
 dialog.innerHTML=`<div class="promiseSelectionShell"><header class="promiseSelectionHead"><div><h2 id="promiseSelectionTitle">Подборка к обещанию</h2><p id="promiseSelectionClient"></p></div><button id="promiseSelectionClose" class="promiseSelectionClose" type="button" aria-label="Закрыть связь подборки">×</button></header><div class="promiseSelectionBody"><div id="promiseSelectionStatus" class="promiseSelectionStatus" role="status" aria-live="polite" tabindex="-1"></div><p>Связь задаёт полный состав, после подтверждённой отправки которого задача может быть выполнена автоматически.</p><div id="promiseSelectionFields" class="promiseSelectionFields"><label for="promiseSelectionPromise">Обещание и существующая задача<select id="promiseSelectionPromise"></select></label><label for="promiseSelectionPublication">Сохранённая подборка<select id="promiseSelectionPublication"></select></label></div><p id="promiseSelectionCatalogHint" class="promiseSelectionHint"></p><button id="promiseSelectionReload" type="button" class="promiseSelectionButton">Обновить список</button><div id="promiseSelectionExistingWrap" class="promiseSelectionExisting" hidden><label for="promiseSelectionExisting">Сохранённая связь<select id="promiseSelectionExisting"></select></label><button id="promiseSelectionExistingRead" type="button" class="promiseSelectionButton">Показать состояние</button></div><section id="promiseSelectionReview" class="promiseSelectionReview" hidden><h3>Проверьте обещание и весь состав</h3><blockquote id="promiseSelectionQuote" class="promiseSelectionQuote"></blockquote><p id="promiseSelectionSourceMeta" class="promiseSelectionHint"></p><p id="promiseSelectionTask"></p><div id="promiseSelectionProfile"></div><h4 id="promiseSelectionCompositionTitle"></h4><p>Сверьте все варианты с условиями этой поездки.</p><ol id="promiseSelectionCards" class="promiseSelectionCards"></ol></section><section id="promiseSelectionIntent" class="promiseSelectionIntent" hidden></section><p id="promiseSelectionCancelNotice" class="promiseSelectionCancelNotice" hidden>Подтвердите отмену автоматического выполнения выбранной связи. Обещание и задача останутся для ручной работы.</p></div><footer class="promiseSelectionFooter"><p class="promiseSelectionHint">Отправка вариантов выполняется отдельно в редакторе подборки.</p><div class="promiseSelectionActions"><button id="promiseSelectionPreview" type="button" class="promiseSelectionButton">Проверить связь</button><button id="promiseSelectionConfirm" type="button" class="promiseSelectionButton promiseSelectionPrimary" hidden>Подтвердить связь</button><button id="promiseSelectionStatusRead" type="button" class="promiseSelectionButton" hidden>Обновить состояние</button><button id="promiseSelectionCancel" type="button" class="promiseSelectionButton" hidden>Отменить автоматическое выполнение</button><button id="promiseSelectionCancelBack" type="button" class="promiseSelectionButton" hidden>Продолжить без отмены</button><button id="promiseSelectionCancelConfirm" type="button" class="promiseSelectionButton promiseSelectionPrimary" hidden>Подтвердить отмену</button><button id="promiseSelectionCheck" type="button" class="promiseSelectionButton promiseSelectionPrimary" hidden>Проверить сохранение</button><button id="promiseSelectionRetry" type="button" class="promiseSelectionButton" hidden>Повторить эту попытку</button></div></footer></div>`;
 document.body.appendChild(dialog);
 $('promiseSelectionClose').onclick=()=>close();$('promiseSelectionReload').onclick=()=>view&&loadCatalog(view);$('promiseSelectionPreview').onclick=()=>preview();$('promiseSelectionConfirm').onclick=()=>confirm();
 $('promiseSelectionStatusRead').onclick=()=>readStatus();$('promiseSelectionExistingRead').onclick=()=>readStatus($('promiseSelectionExisting').value);
 $('promiseSelectionCancel').onclick=()=>beginCancel();$('promiseSelectionCancelConfirm').onclick=()=>confirmCancel();$('promiseSelectionCancelBack').onclick=()=>{if(view&&!view.busy&&!view.pending){view.cancelling=null;controls()}};
 $('promiseSelectionCheck').onclick=()=>check();$('promiseSelectionRetry').onclick=()=>retry();
 for(const id of ['promiseSelectionPromise','promiseSelectionPublication','promiseSelectionExisting'])$(id).addEventListener('change',changed);
 dialog.addEventListener('cancel',event=>{event.preventDefault();close()});dialog.addEventListener('close',()=>{if(!dialog.open&&view)close()});dialog.addEventListener('keydown',event=>event.stopPropagation());
}
function clearDialog(){if(!dialog)return;for(const id of ['promiseSelectionPromise','promiseSelectionPublication','promiseSelectionExisting','promiseSelectionQuote','promiseSelectionSourceMeta','promiseSelectionTask','promiseSelectionProfile','promiseSelectionCards','promiseSelectionIntent','promiseSelectionClient','promiseSelectionStatus','promiseSelectionCatalogHint','promiseSelectionCompositionTitle'])$(id).replaceChildren();for(const id of ['promiseSelectionReview','promiseSelectionIntent','promiseSelectionCancelNotice','promiseSelectionExistingWrap'])$(id).hidden=true;}
function close({restoreFocus=true,fromHistory=false}={}){
 const previous=view;view=null;epoch++;abortRead();for(const controller of requests)controller.abort();
 if(previous?.overlay&&!fromHistory)window.WorkspaceV2Mobile?.closeOverlay('promise-selection');if(dialog?.open)dialog.close();clearDialog();
 if(restoreFocus&&previous&&sameScope(previous.scope)){if(!window.Manager2Presentation?.focusConversationTool?.('m2PromiseSelectionOpen'))previous.opener?.isConnected&&previous.opener.focus({preventScroll:true});}
 return !!previous;
}
function sync(){if(view&&!sameScope(view.scope))close({restoreFocus:false});}
async function open(opener=document.activeElement){
 const scope=capture();if(!scope)return false;close({restoreFocus:false});ensureDialog();
 const saved=pendingFor(scope),v={scope,opener,epoch:++epoch,overlay:false,catalog:null,ready:null,intent:null,cancelling:null,busy:'',pending:saved.pending,storageBlocked:saved.blocked,notFound:false};view=v;
 const overlay=window.WorkspaceV2Mobile?.openOverlay('promise-selection',()=>close({fromHistory:true}));if(overlay===false){view=null;return false;}v.overlay=!!overlay;
 $('promiseSelectionClient').textContent=String(S.detail.conversation.display_name||'Турист');dialog.showModal();$('promiseSelectionStatus').focus({preventScroll:true});controls();
 if(v.pending){status('Есть неподтверждённая попытка. Проверьте сохранение перед следующим действием.');return true;}
 if(v.storageBlocked){status('Не удалось прочитать незавершённые попытки. Сохранение пока недоступно.',true);return false;}
 return loadCatalog(v);
}
async function loadCatalog(v){
 if(!current(v)||v.busy||v.pending)return false;abortRead();const run=ticket;read=new AbortController();v.busy='catalog';v.ready=null;v.intent=null;v.cancelling=null;$('promiseSelectionReview').hidden=true;$('promiseSelectionIntent').hidden=true;status('Загружаем обещания и сохранённые подборки…');controls();
 const valid=()=>current(v)&&run===ticket;let result;try{result=await request('catalog',{conversation_id:v.scope.conversationId},{controller:read,isCurrent:valid});}catch(e){result=null;}
 if(!valid())return false;read=null;v.busy='';if(!validCatalog(result,v)){v.catalog=null;status(reason(result?.error),true);controls();return false;}
 v.catalog=result;renderCatalog(v);status('Выберите обещание и сохранённую подборку, затем проверьте весь состав.');controls();return true;
}
async function preview(){
 const v=view;if(!v||!current(v)||v.busy||v.pending||v.storageBlocked||!v.catalog)return false;
 const promise_id=$('promiseSelectionPromise').value,publication_id=$('promiseSelectionPublication').value;if(!v.catalog.promises.some(p=>p.promise_id===promise_id)||!v.catalog.publications.some(p=>p.publication_id===publication_id))return false;
 abortRead();const run=ticket,signature=selection();read=new AbortController();v.busy='preview';v.ready=null;v.intent=null;v.cancelling=null;$('promiseSelectionReview').hidden=true;$('promiseSelectionIntent').hidden=true;status('Проверяем обещание, задачу и состав подборки…');controls();
 const valid=()=>current(v)&&run===ticket&&selection()===signature;let result;try{result=await request('preview',{conversation_id:v.scope.conversationId,promise_id,publication_id},{controller:read,isCurrent:valid});}catch(e){result=null;}
 if(!valid())return false;read=null;v.busy='';if(!validPreview(result,v,promise_id,publication_id)){status(reason(result?.error),true);controls();return false;}
 v.ready={result,signature,expires:Date.now()+previewLifetime};renderPreview(result);if(result.intent?.promise_id===promise_id&&result.intent.publication_id===publication_id)renderIntent(v,result.intent);
 status(result.can_attach?'Проверьте все варианты и условия поездки. Связь сохраняется отдельным подтверждением.':reason(result.reason),!result.can_attach&&result.reason!=='already_attached');controls();return true;
}
function adoptPending(v,p){v.pending=p;v.ready=null;v.cancelling=null;v.notFound=false;$('promiseSelectionReview').hidden=true;status('Есть неподтверждённая попытка. Проверьте её сохранение.');controls();}
async function confirm(){
 const v=view,r=v?.ready;if(!v||!current(v)||v.busy||v.pending||v.storageBlocked||!canEdit(v)||!r||!r.result.can_attach||r.signature!==selection())return false;
 if(r.expires<=Date.now()){v.ready=null;status('Срок проверки истёк. Проверьте связь заново.',true);controls();return false;}
 return startWrite(v,'attach',{promise_id:r.result.promise.promise_id,publication_id:r.result.publication.publication_id,preview_sha256:r.result.preview_sha256},()=>v.ready===r&&r.signature===selection()&&r.expires>Date.now());
}
function beginCancel(){const v=view;if(!v||!current(v)||v.busy||v.pending||v.storageBlocked||!canEdit(v)||v.intent?.state!=='armed')return false;v.cancelling=clone(v.intent);controls();$('promiseSelectionCancelConfirm').focus({preventScroll:true});return true;}
async function confirmCancel(){const v=view,intent=v?.cancelling;if(!v||!current(v)||v.busy||v.pending||!intent||v.intent?.intent_id!==intent.intent_id||!canEdit(v))return false;return startWrite(v,'cancel',{intent_id:intent.intent_id},()=>v.cancelling===intent&&v.intent?.intent_id===intent.intent_id);}
async function startWrite(v,action,data,validChoice){
 if(!current(v)||v.busy||v.pending||v.storageBlocked||!validChoice())return false;v.busy='prepare-write';controls();let p;
 try{p=await newPending(v,action,data);}catch(e){if(current(v)){v.busy='';status('Не удалось подготовить попытку. Ничего не сохранено.',true);controls();}return false;}
 if(!current(v)||!validChoice()){if(view===v){v.busy='';controls();}return false;}v.busy='';const saved=pendingFor(v.scope);
 if(saved.blocked){v.storageBlocked=true;status('Не удалось проверить незавершённые попытки. Сохранение недоступно.',true);controls();return false;}
 if(saved.pending){adoptPending(v,saved.pending);return false;}
 if(!validPending(p)||!persist(p)){v.storageBlocked=true;status('Не удалось сохранить данные попытки. Запрос не отправлен.',true);controls();return false;}
 v.pending=p;return dispatch(v,p);
}
const matchesIntent=(intent,p)=>validIntent(intent)&&(p.action==='attach'?intent.promise_id===p.promise_id&&intent.publication_id===p.publication_id:intent.intent_id===p.intent_id&&intent.state!=='armed');
const definiteRejections=new Set(['invalid_input','not_found','profile_required','profile_source_changed','source_unavailable','source_changed','profile_changed','preview_changed','intent_conflict','already_attached','delivery_started','duplicate_cards','unsupported_promise','task_closed','task_missing','scope_changed']);
function showResult(v,p,intent){if(!current(v))return;v.pending=null;v.ready=null;v.cancelling=null;v.notFound=false;v.busy='';renderIntent(v,intent);status(p.action==='cancel'&&intent.state==='armed'?'Результат отмены требует проверки.':'Состояние связи подтверждено.');controls();}
async function dispatch(v,p){
 if(!current(v)||v.busy)return false;if(!persist(p)){v.storageBlocked=true;status('Не удалось сохранить данные попытки. Запрос не отправлен.',true);controls();return false;}
 v.busy='write';v.notFound=false;status(p.action==='attach'?'Сохраняем связь с подборкой…':'Отменяем автоматическое выполнение…');controls();let result;
 try{result=await request(p.action,{conversation_id:p.conversation_id,...command(p)},{isCurrent:()=>sameSession(v.scope)});}catch(e){result=null;}
 if(!sameSession(v.scope))return false;
 if(accepted(result,v)&&matchesIntent(result.intent,p)){const cleared=forget(p);if(current(v)){if(cleared)showResult(v,p,result.intent);else{v.busy='';v.storageBlocked=true;status('Состояние получено, но сведения о попытке не удалось удалить. Сначала проверьте сохранение.',true);controls();}}return cleared;}
 if(!current(v))return false;v.busy='';
 if(result?.ok===false&&definiteRejections.has(result.error)&&Number(result.http_status||0)<500&&forget(p)){v.pending=null;v.ready=null;v.cancelling=null;status(reason(result.error),true);controls();return false;}
 status(result?.error==='operation_mismatch'?reason(result.error):'Результат действия пока неизвестен. Проверьте сохранение перед повтором.',true);controls();return false;
}
async function check(){
 const v=view,p=v?.pending;if(!v||!current(v)||!p||v.busy)return false;v.busy='check';v.notFound=false;status('Проверяем результат исходной попытки…');controls();let result;
 try{result=await request('operation',{conversation_id:p.conversation_id,operation_id:p.operation_id},{isCurrent:()=>current(v)&&v.pending===p});}catch(e){result=null;}
 if(!current(v)||v.pending!==p)return false;v.busy='';
 if(accepted(result,v)&&result.found===true&&result.action===p.action&&result.request_sha256===p.request_sha256&&matchesIntent(result.intent,p)){
  if(forget(p)){showResult(v,p,result.intent);return true;}v.storageBlocked=true;status('Результат подтверждён, но сведения о попытке не удалось удалить.',true);controls();return false;
 }
 if(accepted(result,v)&&result.found===false&&result.action===null&&result.request_sha256===null&&result.intent===null){v.notFound=true;status('Сохранённая попытка не найдена. Можно явно повторить те же данные.');}
 else status('Результат исходной попытки не подтверждён. Повторное сохранение заблокировано.',true);
 controls();return false;
}
function retry(){const v=view;if(!v||!current(v)||!v.pending||!v.notFound||v.busy||v.storageBlocked)return Promise.resolve(false);return dispatch(v,v.pending);}
async function readStatus(intentId=view?.intent?.intent_id){
 const v=view;if(!v||!current(v)||v.pending||v.busy||!hex(intentId,32))return false;
 if(v.intent?.intent_id!==intentId&&!v.catalog?.intents.some(item=>item.intent_id===intentId))return false;
 abortRead();const run=ticket,signature=selection();read=new AbortController();v.busy='status';v.ready=null;v.cancelling=null;status('Читаем состояние сохранённой связи…');controls();
 const valid=()=>current(v)&&run===ticket&&selection()===signature;let result;try{result=await request('status',{conversation_id:v.scope.conversationId,intent_id:intentId},{controller:read,isCurrent:valid});}catch(e){result=null;}
 if(!valid())return false;read=null;v.busy='';if(!accepted(result,v)||!validIntent(result.intent)||result.intent.intent_id!==intentId){status(reason(result?.error),true);controls();return false;}
 $('promiseSelectionReview').hidden=true;renderIntent(v,result.intent);status('Показано сохранённое состояние связи.');controls();return true;
}
window.addEventListener('pagehide',()=>close({restoreFocus:false}));
if(typeof MutationObserver==='function'){const observer=new MutationObserver(sync);for(const target of [document.body,$('managerName'),$('conversationTitle')])if(target)observer.observe(target,{attributes:true,childList:true,subtree:target!==document.body,attributeFilter:['class']});}
window.WorkspaceV2PromiseSelection={open,close,sync,preview,confirm,beginCancel,confirmCancel,check,retry,readStatus,reload:()=>view?loadCatalog(view):Promise.resolve(false)};
})();
