(function(){
const W=window.WorkspaceV2,{S,$,pipe}=W;let outcomeDirtyLead=0;const outcomeSavingLeads=new Set();let completionState=null,tripEditor=null,tripOwner='',tripView=0;const tripEntries=new Map();
function sameLead(target){return Number(S.current||0)===Number(target||0)}
function isOutcomeDirty(target=S.current){return Number(outcomeDirtyLead||0)===Number(target||0)}
function isOutcomeSaving(target=S.current){return outcomeSavingLeads.has(Number(target||0))}
function setOutcomeSaveState(text='',kind=''){const el=$('outcomeSaveStatus');if(!el)return;el.textContent=text;el.className='outcomeSaveStatus'+(kind?' '+kind:'')}
function setOutcomeDirty(dirty=true){const target=Number(S.current||0);outcomeDirtyLead=dirty?target:0;const button=$('saveOutcome');if(button&&!isOutcomeSaving(target))button.disabled=!dirty;if(dirty)setOutcomeSaveState('Есть несохранённые изменения','dirty');else if(!isOutcomeSaving(target))setOutcomeSaveState();if(dirty&&tripCurrent(tripEditor)){tripEditor.dirty=true;tripEditor.edits++;tripEditor.draft=tripDraft();if(tripEditor.pending)persistTripPending(tripEditor);if(!tripEditor.pending&&!tripEditor.conflict){tripEditor.message='Есть несохранённые изменения';tripEditor.kind='dirty';}updateTripControls(tripEditor)}}
async function refreshAfterSave(target){if(sameLead(target))return !!(await window.WorkspaceV2Conversation?.refreshLeadData({refreshInbox:true,conversationId:target}));await window.WorkspaceV2Inbox?.load({preserveScroll:true});return false}
async function saveOutcome(){if(tripEditor)return saveBoundOutcome();const target=Number(S.current||0);if(!target||isOutcomeSaving(target)||!isOutcomeDirty(target))return;const outcomeEl=$('leadOutcome'),reasonEl=$('leadCloseReason'),noteEl=$('leadOutcomeNote'),saleAmountEl=$('leadSaleAmount'),saleDateEl=$('leadSaleDate'),button=$('saveOutcome');if(!outcomeEl||!reasonEl||!noteEl)return;const outcome=outcomeEl.value,closeReason=reasonEl.value,note=noteEl.value.trim(),saleAmount=saleAmountEl?.value.trim()||'',saleDate=saleDateEl?.value||'';if(outcome==='lost'&&!closeReason){setOutcomeSaveState('Выберите причину отказа','error');reasonEl.focus();return}if(outcome==='won'&&saleAmount!==''&&(!/^\d+(?:[.,]\d{1,2})?$/.test(saleAmount)||Number(saleAmount.replace(',','.'))<0)){setOutcomeSaveState('Проверьте сумму продажи','error');saleAmountEl?.focus();return}const completion=$('leadOutcomeStageWrap'),stageSelect=$('leadOutcomeStage'),stageData=completion&&!completion.hidden?{stage_key:stageSelect?.value||'',expected_stage_key:completionState?.expectedStage||''}:{};if(completion&&!completion.hidden&&!stageData.stage_key){setOutcomeSaveState('Выберите активный этап завершения','error');stageSelect?.focus();return}outcomeSavingLeads.add(target);if(button){button.disabled=true;button.textContent='Сохраняем…'}if(sameLead(target))setOutcomeSaveState('Сохраняем результат…');try{const j=await pipe('set_outcome',{conversation_id:target,outcome,close_reason:closeReason,note,sale_amount:saleAmount,sale_date:saleDate,...stageData});if(!j.ok){if(sameLead(target)){outcomeDirtyLead=target;setOutcomeSaveState('Не удалось сохранить результат','error');if(j.error==='stage_or_result_changed')setOutcomeSaveState('Этап уже изменился. Обновите карточку перед сохранением.','error')}return}if(sameLead(target))outcomeDirtyLead=0;await refreshAfterSave(target);if(sameLead(target))setOutcomeSaveState('Результат сохранён','success')}catch(e){if(sameLead(target)){outcomeDirtyLead=target;setOutcomeSaveState('Не удалось сохранить результат','error')}}finally{outcomeSavingLeads.delete(target);if(sameLead(target)&&button?.isConnected){button.disabled=!isOutcomeDirty(target);button.textContent='Сохранить результат';syncCompletionStage()}}}
function bind({canEdit=false,pipeline={}}={}){tripEditor=null;if(!canEdit){bindTrip(canEdit,pipeline);return;}bindCompletionStage(pipeline);const outcomeEl=$('leadOutcome'),reasonEl=$('leadCloseReason'),reasonWrap=$('leadCloseReasonWrap'),saleWrap=$('leadSaleWrap'),noteEl=$('leadOutcomeNote'),saleAmountEl=$('leadSaleAmount'),saleDateEl=$('leadSaleDate'),saveButton=$('saveOutcome');if(outcomeEl&&reasonEl&&reasonWrap){outcomeEl.onchange=()=>{reasonWrap.classList.toggle('hidden',outcomeEl.value!=='lost');saleWrap?.classList.toggle('hidden',outcomeEl.value!=='won');if(completionState)completionState.forced='';syncCompletionStage();setOutcomeDirty(true)};reasonEl.onchange=()=>setOutcomeDirty(true)}if(noteEl)noteEl.oninput=()=>setOutcomeDirty(true);if(saleAmountEl)saleAmountEl.oninput=()=>setOutcomeDirty(true);if(saleDateEl)saleDateEl.onchange=()=>setOutcomeDirty(true);if(saveButton)saveButton.onclick=saveOutcome;setOutcomeDirty(false);bindTrip(canEdit,pipeline)}

function tripItem(value){return value&&typeof value==='object'&&/^[a-f0-9]{32}$/.test(value.trip_id)&&Number.isSafeInteger(value.version)&&value.version>0&&typeof value.label==='string'&&typeof value.summary==='string'?value:null}
function tripModel(pipeline){
 if(!document.body?.classList?.contains('manager2')||!Object.prototype.hasOwnProperty.call(pipeline,'outcome_trip'))return null;
 const value=pipeline.outcome_trip;
 if(value?.available===false&&value.reason==='unsupported_project')return null;
 if(value?.available!==true||!/^[a-f0-9]{64}$/.test(value.revision)||!Array.isArray(value.choices)||value.choices.some(item=>!tripItem(item))||(value.binding!==null&&!tripItem(value.binding)))return {available:false,binding:tripItem(value?.binding),choices:[]};
 const choices=value.choices.slice();if(value.binding&&!choices.some(item=>tripKey(item)===tripKey(value.binding)))choices.push(value.binding);
 return {available:true,revision:value.revision,binding:value.binding,choices,truncated:value.choices_truncated===true};
}
function tripKey(item){return item?item.trip_id+':'+item.version:''}
function tripStorageKey(entry){return 'anytour-outcome-trip-v1:'+entry.owner+':'+entry.lead}
function tripStorageFits(value){return value.length<=8192&&new TextEncoder().encode(value).byteLength<=8192}
function validTripCommand(command,lead){
 const allowed=['conversation_id','outcome','close_reason','note','sale_amount','sale_date','stage_key','expected_stage_key','outcome_trip'];
 if(!command||typeof command!=='object'||Array.isArray(command)||Object.keys(command).some(key=>!allowed.includes(key))||command.conversation_id!==lead||!['open','won','lost'].includes(command.outcome))return false;
 for(const [key,max]of Object.entries({close_reason:80,note:500,sale_amount:30,sale_date:10}))if(typeof command[key]!=='string'||command[key].length>max)return false;
 const staged=Object.prototype.hasOwnProperty.call(command,'stage_key');
 if(staged!==Object.prototype.hasOwnProperty.call(command,'expected_stage_key')||staged&&(['stage_key','expected_stage_key'].some(key=>typeof command[key]!=='string'||command[key].length>80)))return false;
 const trip=command.outcome_trip;
 return !!trip&&typeof trip==='object'&&!Array.isArray(trip)&&Object.keys(trip).length===4&&/^[a-f0-9]{32}$/.test(trip.operation_id)&&/^[a-f0-9]{64}$/.test(trip.expected_revision)
  &&(trip.trip_id===null&&trip.profile_version===null||/^[a-f0-9]{32}$/.test(trip.trip_id)&&Number.isSafeInteger(trip.profile_version)&&trip.profile_version>0);
}
function validTripDraft(draft){
 if(!draft||typeof draft!=='object'||Array.isArray(draft)||!['open','won','lost'].includes(draft.outcome))return false;
 const limits={outcome:4,reason:80,note:500,amount:30,date:10,choice:50,stage:80,forced:80};
 return Object.keys(draft).length===Object.keys(limits).length&&Object.entries(limits).every(([key,max])=>typeof draft[key]==='string'&&draft[key].length<=max)
  &&(draft.choice===''||/^[a-f0-9]{32}:[1-9][0-9]{0,15}$/.test(draft.choice));
}
function loadTripPending(entry){
 try{
  const raw=sessionStorage.getItem(tripStorageKey(entry));entry.storageFailed=false;if(raw===null)return;
  if(!tripStorageFits(raw))throw Error('pending_size');const saved=JSON.parse(raw);
  if(saved?.version!==1||saved.owner!==entry.owner||saved.conversation_id!==entry.lead||!validTripCommand(saved.pending?.command,entry.lead)||!validTripDraft(saved.draft)
   ||!Number.isSafeInteger(saved.edits)||saved.edits<0||saved.edits>1000000||!Number.isSafeInteger(saved.pending.edits)||saved.pending.edits<0||saved.pending.edits>saved.edits)throw Error('pending_contract');
  if(entry.pending&&entry.pending.command.outcome_trip.operation_id!==saved.pending.command.outcome_trip.operation_id)throw Error('pending_changed');
  entry.pending={command:saved.pending.command,edits:saved.pending.edits,phase:'unknown'};entry.draft=saved.draft;entry.edits=saved.edits;entry.dirty=true;
 }catch(error){entry.storageFailed=true;}
}
function persistTripPending(entry){
 if(!entry.pending)return true;
 try{
  const value=JSON.stringify({version:1,owner:entry.owner,conversation_id:entry.lead,pending:{command:entry.pending.command,edits:entry.pending.edits},draft:entry.draft,edits:entry.edits});
  if(!tripStorageFits(value)||!validTripCommand(entry.pending.command,entry.lead)||!validTripDraft(entry.draft)||!Number.isSafeInteger(entry.edits)||entry.edits<0||entry.edits>1000000)throw Error('pending_contract');
  const previous=sessionStorage.getItem(tripStorageKey(entry));if(previous!==null&&(!tripStorageFits(previous)||JSON.stringify(JSON.parse(previous)?.pending?.command)!==JSON.stringify(entry.pending.command)))throw Error('pending_changed');
  sessionStorage.setItem(tripStorageKey(entry),value);if(sessionStorage.getItem(tripStorageKey(entry))!==value)throw Error('pending_storage');entry.storageFailed=false;return true;
 }catch(error){entry.storageFailed=true;return false;}
}
function clearTripPending(entry,operation){
 try{
  const raw=sessionStorage.getItem(tripStorageKey(entry));if(raw!==null){if(!tripStorageFits(raw)||JSON.stringify(JSON.parse(raw)?.pending?.command)!==JSON.stringify(operation.command))throw Error('pending_changed');sessionStorage.removeItem(tripStorageKey(entry));}
  if(sessionStorage.getItem(tripStorageKey(entry))!==null)throw Error('pending_storage');entry.storageFailed=false;return true;
 }catch(error){entry.storageFailed=true;return false;}
}
function tripText(item,model){return [item.label,item.summary].filter(Boolean).join(' · ')+(model?.binding&&tripKey(item)===tripKey(model.binding)&&model.choices.some(other=>other.trip_id===item.trip_id&&other.version>item.version)?' — сохранённая версия':'')}
function tripMarkup({canEdit=false,pipeline={}}={}){
 const model=tripModel(pipeline);if(!model)return '';
 const esc=W.esc,selected=tripKey(model.binding);
 return '<div id="leadOutcomeTripWrap"><label class="label" for="leadOutcomeTrip">Поездка для результата</label>'
  +'<select id="leadOutcomeTrip" class="stageSelect" '+(!canEdit||!model.available?'disabled':'')+'>'
  +(model.available?'<option value="" '+(!selected?'selected':'')+'>Поездка не определена</option>'+model.choices.map(item=>'<option value="'+esc(tripKey(item))+'" '+(tripKey(item)===selected?'selected':'')+'>'+esc(tripText(item,model))+'</option>').join(''):
   '<option value="">'+esc(model.binding?tripText(model.binding,model):'Привязка поездки сейчас недоступна')+'</option>')
  +'</select><p id="leadOutcomeTripSummary" class="muted">'+esc(model.available?(model.binding?tripText(model.binding,model):'Выберите поездку, только если результат относится именно к ней.'):'Привязка поездки сейчас недоступна. Обновите данные перед сохранением.')+'</p>'
  +(model.truncated?'<p class="muted">Показаны не все поездки. Если нужной нет, уточните профиль запроса.</p>':'')
  +'<p id="outcomeTripCurrent" class="muted" hidden></p>'
  +'<button id="outcomeTripCheck" class="actionBtn" type="button" hidden>Проверить сохранение</button> '
  +'<button id="outcomeTripRetry" class="actionBtn" type="button" hidden>Повторить ту же запись</button> '
  +'<button id="outcomeTripRefresh" class="actionBtn" type="button" hidden>Обновить данные</button></div>';
}
function tripScope(){const c=S.detail?.conversation||{};return JSON.stringify([c.id,c.manager_id,c.status,c.project_key,S.manager?.role])}
function tripCurrent(entry,view=entry?.view){return !!entry&&tripEditor===entry&&entry.view===view&&!S.authExpired&&Number(S.manager?.id)===entry.owner&&Number(S.authGeneration||0)===entry.generation&&Number(S.current)===entry.lead&&Number(S.detail?.conversation?.id)===entry.lead&&tripScope()===entry.scope&&window.WorkspaceV2Conversation?.hasReadAccess?.()!==false&&window.WorkspaceV2Conversation?.getOpenGeneration?.()===entry.navigation}
function tripDraft(){return {outcome:$('leadOutcome')?.value||'open',reason:$('leadCloseReason')?.value||'',note:$('leadOutcomeNote')?.value||'',amount:$('leadSaleAmount')?.value||'',date:$('leadSaleDate')?.value||'',choice:$('leadOutcomeTrip')?.value||'',stage:$('leadOutcomeStageWrap')?.hidden?'':$('leadOutcomeStage')?.value||'',forced:completionState?.forced||''}}
function restoreTripDraft(draft){
 for(const [id,key]of Object.entries({leadOutcome:'outcome',leadCloseReason:'reason',leadOutcomeNote:'note',leadSaleAmount:'amount',leadSaleDate:'date'})){const node=$(id);if(node)node.value=draft[key]||'';}
 $('leadCloseReasonWrap')?.classList.toggle('hidden',draft.outcome!=='lost');$('leadSaleWrap')?.classList.toggle('hidden',draft.outcome!=='won');
 if(completionState)completionState.forced=draft.forced||(draft.outcome!==completionState.initialOutcome?draft.stage:'');syncCompletionStage();
}
function paintTripChoice(entry){
 const select=$('leadOutcomeTrip');if(!select||!entry.model.available)return;
 const choice=entry.draft?.choice??tripKey(entry.model.binding);select.replaceChildren();
 const add=(value,text)=>{const option=document.createElement('option');option.value=value;option.textContent=text;select.appendChild(option)};
 add('','Поездка не определена');for(const item of entry.model.choices)add(tripKey(item),tripText(item,entry.model));
 if(choice&&!entry.model.choices.some(item=>tripKey(item)===choice))add(choice,'Прежний выбор недоступен — выберите поездку заново');
 select.value=choice;const item=entry.model.choices.find(item=>tripKey(item)===choice);
 $('leadOutcomeTripSummary').textContent=choice?(item?tripText(item,entry.model):'Данные поездки изменились. Выберите актуальную поездку или явно оставьте её неопределённой.'):'Выберите поездку, только если результат относится именно к ней.';
}
function bindTrip(canEdit,pipeline){
 const model=tripModel(pipeline);if(!model)return;
 const owner=Number(S.manager?.id||0),generation=Number(S.authGeneration||0),lead=Number(S.current||0),identity=owner+':'+generation;
 if(identity!==tripOwner){tripOwner=identity;tripEntries.clear();}
 let entry=tripEntries.get(lead);
 if(!entry){entry={owner,generation,lead,model,draft:null,dirty:false,edits:0,pending:null,busy:false,conflict:false,message:'',kind:'',storageFailed:false};tripEntries.set(lead,entry);if(owner>0&&lead>0&&!S.authExpired&&Number(S.detail?.conversation?.id)===lead)loadTripPending(entry);}
 const refresh=entry.refreshRequested===true;entry.refreshRequested=false;
 if(refresh||(!entry.dirty&&!entry.pending)){entry.model=model;entry.conflict=false;}
 else if(!entry.pending&&model.available&&entry.model.available&&model.revision!==entry.model.revision){entry.conflict=true;entry.message='Данные результата или поездки изменились. Обновите данные и сверьте свои правки.';entry.kind='error';}
 if(!model.available||!canEdit)entry.model=model;
 entry.canEdit=canEdit;entry.scope=tripScope();entry.navigation=window.WorkspaceV2Conversation?.getOpenGeneration?.();entry.view=++tripView;tripEditor=entry;
 if(canEdit&&entry.draft&&(entry.dirty||entry.pending)){restoreTripDraft(entry.draft);paintTripChoice(entry);}
 else{entry.draft=tripDraft();if(model.available)entry.draft.choice=tripKey(model.binding);paintTripChoice(entry);}
 outcomeDirtyLead=entry.dirty?lead:0;
 const select=$('leadOutcomeTrip');if(select)select.onchange=()=>{setOutcomeDirty(true);paintTripChoice(entry)};
 if($('outcomeTripCheck'))$('outcomeTripCheck').onclick=()=>checkOutcomeOperation(entry);
 if($('outcomeTripRetry'))$('outcomeTripRetry').onclick=()=>retryOutcomeOperation(entry);
 if($('outcomeTripRefresh'))$('outcomeTripRefresh').onclick=()=>refreshOutcomeTrip(entry);
 if(refresh){entry.message=entry.dirty?'Данные обновлены. Ваши правки сохранены в форме; сверьте результат и поездку перед записью.':'';entry.kind=entry.dirty?'dirty':'';}
 updateTripControls(entry);
}
function updateTripControls(entry){
 if(!tripCurrent(entry))return;
 const blocked=!entry.canEdit||!entry.model.available||entry.busy||!!entry.pending||entry.conflict||entry.storageFailed;
 const button=$('saveOutcome');if(button){button.disabled=blocked||!entry.dirty;button.textContent=entry.busy?'Сохраняем…':$('leadOutcomeStageWrap')&&!$('leadOutcomeStageWrap').hidden?'Сохранить этап и результат':'Сохранить результат';}
 if($('leadOutcomeTrip'))$('leadOutcomeTrip').disabled=!entry.canEdit||!entry.model.available;
 for(const [id,visible]of Object.entries({outcomeTripCheck:!!entry.pending,outcomeTripRetry:entry.pending?.phase==='not_found',outcomeTripRefresh:entry.storageFailed||!entry.pending&&(!entry.model.available||entry.conflict)})){const node=$(id);if(node){node.hidden=!visible||!entry.canEdit;node.disabled=entry.busy||id==='outcomeTripRetry'&&entry.storageFailed;}}
 if(!entry.canEdit)for(const id of ['leadOutcome','leadCloseReason','leadOutcomeNote','leadSaleAmount','leadSaleDate','leadOutcomeStage'])if($(id))$(id).disabled=true;
 let text=entry.message,kind=entry.kind;
 if(entry.storageFailed){text='Не удалось проверить незавершённую запись в этой вкладке. Новое сохранение заблокировано. Обновите данные после восстановления хранилища.';kind='error';}
 else if(entry.canEdit&&entry.pending&&!entry.busy){text=entry.pending.phase==='not_found'?'Запись пока не найдена. Можно повторить ту же запись. Новые правки в форме не войдут в этот повтор.':'Исход сохранения неизвестен. Проверьте сохранение перед повтором; ваши правки остаются в форме.';kind='error';}
 else if(!entry.model.available){text='Привязка поездки сейчас недоступна. Обновите данные перед сохранением.';kind='error';}
 else if(entry.dirty&&!text){text='Есть несохранённые изменения';kind='dirty';}
 setOutcomeSaveState(text,kind);
}
function outcomeOperationId(){const bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);return Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('')}
function outcomeReplyMatches(entry,result){
 if(result?.ok!==true)return false;
 if(result.viewer_id!==entry.owner){W.showAuthRecovery?.('Аккаунт изменился. Войдите снова перед сохранением результата.');return false;}
 return result.conversation_id===entry.lead;
}
async function saveBoundOutcome(){
 const entry=tripEditor;if(!tripCurrent(entry)||!entry.canEdit||!entry.model.available||entry.busy||entry.pending||entry.conflict||entry.storageFailed||!entry.dirty)return;
 const draft=tripDraft(),item=entry.model.choices.find(value=>tripKey(value)===draft.choice);
 if(draft.choice&&!item){entry.message='Выберите актуальную поездку или явно оставьте её неопределённой.';entry.kind='error';updateTripControls(entry);return;}
 if(draft.outcome==='lost'&&!draft.reason){setOutcomeSaveState('Выберите причину отказа','error');$('leadCloseReason')?.focus();return;}
 if(draft.outcome==='won'&&draft.amount.trim()!==''&&(!/^\d+(?:[.,]\d{1,2})?$/.test(draft.amount.trim())||Number(draft.amount.replace(',','.'))<0)){setOutcomeSaveState('Проверьте сумму продажи','error');$('leadSaleAmount')?.focus();return;}
 const stageData=$('leadOutcomeStageWrap')&&!$('leadOutcomeStageWrap').hidden?{stage_key:draft.stage,expected_stage_key:completionState?.expectedStage||''}:{};
 if(Object.prototype.hasOwnProperty.call(stageData,'stage_key')&&!stageData.stage_key){setOutcomeSaveState('Выберите активный этап завершения','error');$('leadOutcomeStage')?.focus();return;}
 entry.draft=draft;entry.pending={phase:'unknown',edits:entry.edits,command:{conversation_id:entry.lead,outcome:draft.outcome,close_reason:draft.reason,note:draft.note.trim(),sale_amount:draft.amount.trim(),sale_date:draft.date,...stageData,
  outcome_trip:{operation_id:outcomeOperationId(),expected_revision:entry.model.revision,trip_id:item?.trip_id||null,profile_version:item?.version||null}}};
 await sendOutcomeOperation(entry,entry.pending);
}
async function sendOutcomeOperation(entry,operation){
 if(!tripCurrent(entry)||entry.busy||entry.pending!==operation)return;
 if(!persistTripPending(entry)){updateTripControls(entry);return;}
 const view=entry.view;entry.busy=true;entry.message='Сохраняем результат…';entry.kind='';outcomeSavingLeads.add(entry.lead);updateTripControls(entry);
 let result;try{result=await pipe('set_outcome',JSON.parse(JSON.stringify(operation.command)),{isCurrent:()=>tripCurrent(entry,view)})}catch(error){result=null}
 entry.busy=false;outcomeSavingLeads.delete(entry.lead);
 if(!tripCurrent(entry,view)){operation.phase='unknown';if(tripCurrent(entry))updateTripControls(entry);return;}
 if(outcomeReplyMatches(entry,result)&&tripModel(result.pipeline||{})?.available){await confirmOutcomeOperation(entry,operation,result,false);return;}
 if(!tripCurrent(entry,view))return;
 if(result?.ok===false&&['conflict','context_changed','stage_or_result_changed'].includes(result.error)){
  if(clearTripPending(entry,operation))entry.pending=null;entry.conflict=true;entry.message='Данные результата или поездки изменились. Обновите данные и сверьте свои правки.';entry.kind='error';
 }else if(result?.ok===false&&(Number(result.http_status)===403||result.error==='forbidden')){
  entry.canEdit=false;entry.message='Доступ к сохранению результата изменился. Обновите карточку.';entry.kind='error';
 }else if(result?.ok===false&&Number(result.http_status)===422){if(clearTripPending(entry,operation))entry.pending=null;entry.message='Проверьте результат и выбранную поездку. Запись отклонена.';entry.kind='error';}
 else operation.phase='unknown';
 updateTripControls(entry);
}
async function confirmOutcomeOperation(entry,operation,result,checked){
 if(entry.pending!==operation)return;
 const model=tripModel(result.pipeline||{});if(!model?.available){operation.phase='unknown';updateTripControls(entry);return;}
 if(!clearTripPending(entry,operation)){updateTripControls(entry);return;}
 const later=entry.edits!==operation.edits;entry.pending=null;entry.model=model;
 if(checked||result.replayed===true){
  // An operation receipt proves that command committed; the returned pipeline can be newer.
  entry.dirty=true;entry.conflict=true;outcomeDirtyLead=entry.lead;
  entry.message='Запись подтверждена. Обновите данные и сверьте текущий результат перед новым сохранением.';entry.kind='success';
  const current=$('outcomeTripCurrent');if(current){current.hidden=false;current.textContent='Сейчас в карточке: '+(S.pipeline.outcomes?.[result.pipeline.outcome?.outcome]||'результат требует сверки')+' · '+(model.binding?tripText(model.binding,model):'Поездка не определена');}
  updateTripControls(entry);return;
 }
 entry.dirty=later;outcomeDirtyLead=later?entry.lead:0;if(!later)entry.draft=null;
 entry.message=later?'Результат сохранён. Более поздние правки в форме ещё не сохранены.':'Результат сохранён';entry.kind=later?'dirty':'success';
 try{await refreshAfterSave(entry.lead)}catch(error){entry.message='Результат сохранён, но карточка ещё не обновилась. Ваши правки остаются в форме.';}
 if(tripCurrent(entry))updateTripControls(entry);
}
async function checkOutcomeOperation(entry){
 if(!tripCurrent(entry)||!entry.canEdit||entry.busy||!entry.pending)return;
 const operation=entry.pending,view=entry.view;entry.busy=true;entry.message='Проверяем сохранение…';entry.kind='';updateTripControls(entry);
 let result;try{result=await pipe('outcome_operation',{conversation_id:entry.lead,operation_id:operation.command.outcome_trip.operation_id},{isCurrent:()=>tripCurrent(entry,view)})}catch(error){result=null}
 entry.busy=false;if(!tripCurrent(entry,view)){if(tripCurrent(entry))updateTripControls(entry);return;}
 if(outcomeReplyMatches(entry,result)){
  const saved=result.operation,wanted=operation.command.outcome_trip;
  if(result.found===true&&saved?.operation_id===wanted.operation_id&&saved.trip_id===wanted.trip_id&&saved.profile_version===wanted.profile_version){await confirmOutcomeOperation(entry,operation,result,true);return;}
  if(result.found===false&&result.operation===null)operation.phase='not_found';else operation.phase='unknown';
 }else{operation.phase='unknown';if(result?.ok===false&&(Number(result.http_status)===403||result.error==='forbidden')){entry.canEdit=false;entry.message='Доступ к сохранению результата изменился. Обновите карточку.';entry.kind='error';}}
 if(tripCurrent(entry,view))updateTripControls(entry);
}
async function retryOutcomeOperation(entry){if(tripCurrent(entry)&&entry.canEdit&&entry.pending?.phase==='not_found')await sendOutcomeOperation(entry,entry.pending)}
async function refreshOutcomeTrip(entry){
 if(!tripCurrent(entry)||!entry.canEdit||entry.busy)return;
 if(entry.storageFailed){loadTripPending(entry);if(entry.storageFailed||entry.pending){if(!entry.storageFailed&&entry.draft)restoreTripDraft(entry.draft);paintTripChoice(entry);updateTripControls(entry);return;}}
 if(entry.pending)return;
 entry.refreshRequested=true;entry.busy=true;updateTripControls(entry);
 try{await window.WorkspaceV2Conversation?.refreshLeadData({conversationId:entry.lead})}finally{entry.busy=false;entry.refreshRequested=false;if(tripCurrent(entry))updateTripControls(entry)}
}

function syncCompletionStage(){
 const select=$('leadOutcomeStage'),wrap=$('leadOutcomeStageWrap'),outcome=$('leadOutcome');if(!select||!wrap||!outcome||!completionState)return;
 const needed=outcome.value!==completionState.initialOutcome||!!completionState.forced;
 wrap.hidden=!needed;
 const eligible=(S.pipeline.stages||[]).filter(stage=>outcome.value==='open'?Number(stage.is_terminal)===0:Number(stage.is_terminal)===1&&(Number(stage.is_won)===1)===(outcome.value==='won'));
 const previous=completionState.forced||select.value||completionState.expectedStage;
 select.replaceChildren();
 for(const stage of eligible){const option=document.createElement('option');option.value=String(stage.stage_key);option.textContent=String(stage.display_name);select.appendChild(option);}
 select.value=eligible.some(stage=>String(stage.stage_key)===previous)?previous:eligible[0]?.stage_key||'';
 select.disabled=!needed||!eligible.length;
 const label=$('leadOutcomeStageLabel');if(label)label.textContent=outcome.value==='open'?'Этап для продолжения работы':'Этап завершения';
 const hint=$('leadOutcomeStageHint');if(hint)hint.textContent=eligible.length?'Этап и результат сохранятся вместе.':'Нет подходящего активного этапа. Обратитесь к администратору.';
 const button=$('saveOutcome');if(button&&!isOutcomeSaving())button.textContent=needed?'Сохранить этап и результат':'Сохранить результат';
}
function bindCompletionStage(pipeline){
 completionState=null;
 if(!document.body?.classList?.contains('manager2'))return;
 const button=$('saveOutcome');if(!button?.parentElement)return;
 completionState={conversationId:Number(S.current),initialOutcome:String(pipeline.outcome?.outcome||'open'),expectedStage:String(pipeline.stage?.stage_key||''),forced:''};
 const wrap=document.createElement('div');wrap.id='leadOutcomeStageWrap';wrap.className='leadOutcomeStageWrap';wrap.hidden=true;
 const label=document.createElement('label');label.id='leadOutcomeStageLabel';label.className='label';label.htmlFor='leadOutcomeStage';wrap.appendChild(label);
 const select=document.createElement('select');select.id='leadOutcomeStage';select.className='stageSelect';wrap.appendChild(select);
 const hint=document.createElement('p');hint.id='leadOutcomeStageHint';hint.className='muted';wrap.appendChild(hint);
 button.parentElement.insertBefore(wrap,button);select.onchange=()=>{completionState.forced=select.value;setOutcomeDirty(true)};
 syncCompletionStage();
}
function startCompletion(stage){
 if(!completionState||completionState.conversationId!==Number(S.current)||!stage||Number(stage.is_terminal)!==1)return false;
 const outcome=$('leadOutcome');if(!outcome||outcome.disabled)return false;
 completionState.forced=String(stage.stage_key);outcome.value=Number(stage.is_won)===1?'won':'lost';
 $('leadCloseReasonWrap')?.classList.toggle('hidden',outcome.value!=='lost');$('leadSaleWrap')?.classList.toggle('hidden',outcome.value!=='won');
 syncCompletionStage();setOutcomeDirty(true);
 const details=outcome.closest('details');if(details)details.open=true;
 window.Manager2Presentation?.openClientCard?.($('conversationTitle'));
 outcome.scrollIntoView?.({block:'nearest'});(outcome.value==='lost'?$('leadCloseReason'):$('leadSaleAmount'))?.focus({preventScroll:true});return true;
}

window.WorkspaceV2Outcome={tripMarkup,startCompletion,syncCompletionStage,bind,saveOutcome,setOutcomeSaveState,setOutcomeDirty,sameLead,isOutcomeDirty,isOutcomeSaving,refreshAfterSave};
})();
