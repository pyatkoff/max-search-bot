(function(){
const W=window.WorkspaceV2,{S,$,pipe}=W;let outcomeDirtyLead=0;const outcomeSavingLeads=new Set();let completionState=null;
function sameLead(target){return Number(S.current||0)===Number(target||0)}
function isOutcomeDirty(target=S.current){return Number(outcomeDirtyLead||0)===Number(target||0)}
function isOutcomeSaving(target=S.current){return outcomeSavingLeads.has(Number(target||0))}
function setOutcomeSaveState(text='',kind=''){const el=$('outcomeSaveStatus');if(!el)return;el.textContent=text;el.className='outcomeSaveStatus'+(kind?' '+kind:'')}
function setOutcomeDirty(dirty=true){const target=Number(S.current||0);outcomeDirtyLead=dirty?target:0;const button=$('saveOutcome');if(button&&!isOutcomeSaving(target))button.disabled=!dirty;if(dirty)setOutcomeSaveState('Есть несохранённые изменения','dirty');else if(!isOutcomeSaving(target))setOutcomeSaveState()}
async function refreshAfterSave(target){if(sameLead(target))return !!(await window.WorkspaceV2Conversation?.refreshLeadData({refreshInbox:true,conversationId:target}));await window.WorkspaceV2Inbox?.load({preserveScroll:true});return false}
async function saveOutcome(){const target=Number(S.current||0);if(!target||isOutcomeSaving(target)||!isOutcomeDirty(target))return;const outcomeEl=$('leadOutcome'),reasonEl=$('leadCloseReason'),noteEl=$('leadOutcomeNote'),saleAmountEl=$('leadSaleAmount'),saleDateEl=$('leadSaleDate'),button=$('saveOutcome');if(!outcomeEl||!reasonEl||!noteEl)return;const outcome=outcomeEl.value,closeReason=reasonEl.value,note=noteEl.value.trim(),saleAmount=saleAmountEl?.value.trim()||'',saleDate=saleDateEl?.value||'';if(outcome==='lost'&&!closeReason){setOutcomeSaveState('Выберите причину отказа','error');reasonEl.focus();return}if(outcome==='won'&&saleAmount!==''&&(!/^\d+(?:[.,]\d{1,2})?$/.test(saleAmount)||Number(saleAmount.replace(',','.'))<0)){setOutcomeSaveState('Проверьте сумму продажи','error');saleAmountEl?.focus();return}const completion=$('leadOutcomeStageWrap'),stageSelect=$('leadOutcomeStage'),stageData=completion&&!completion.hidden?{stage_key:stageSelect?.value||'',expected_stage_key:completionState?.expectedStage||''}:{};if(completion&&!completion.hidden&&!stageData.stage_key){setOutcomeSaveState('Выберите активный этап завершения','error');stageSelect?.focus();return}outcomeSavingLeads.add(target);if(button){button.disabled=true;button.textContent='Сохраняем…'}if(sameLead(target))setOutcomeSaveState('Сохраняем результат…');try{const j=await pipe('set_outcome',{conversation_id:target,outcome,close_reason:closeReason,note,sale_amount:saleAmount,sale_date:saleDate,...stageData});if(!j.ok){if(sameLead(target)){outcomeDirtyLead=target;setOutcomeSaveState('Не удалось сохранить результат','error');if(j.error==='stage_or_result_changed')setOutcomeSaveState('Этап уже изменился. Обновите карточку перед сохранением.','error')}return}if(sameLead(target))outcomeDirtyLead=0;await refreshAfterSave(target);if(sameLead(target))setOutcomeSaveState('Результат сохранён','success')}catch(e){if(sameLead(target)){outcomeDirtyLead=target;setOutcomeSaveState('Не удалось сохранить результат','error')}}finally{outcomeSavingLeads.delete(target);if(sameLead(target)&&button?.isConnected){button.disabled=!isOutcomeDirty(target);button.textContent='Сохранить результат';syncCompletionStage()}}}
function bind({canEdit=false,pipeline={}}={}){if(!canEdit)return;bindCompletionStage(pipeline);const outcomeEl=$('leadOutcome'),reasonEl=$('leadCloseReason'),reasonWrap=$('leadCloseReasonWrap'),saleWrap=$('leadSaleWrap'),noteEl=$('leadOutcomeNote'),saleAmountEl=$('leadSaleAmount'),saleDateEl=$('leadSaleDate'),saveButton=$('saveOutcome');if(outcomeEl&&reasonEl&&reasonWrap){outcomeEl.onchange=()=>{reasonWrap.classList.toggle('hidden',outcomeEl.value!=='lost');saleWrap?.classList.toggle('hidden',outcomeEl.value!=='won');if(completionState)completionState.forced='';syncCompletionStage();setOutcomeDirty(true)};reasonEl.onchange=()=>setOutcomeDirty(true)}if(noteEl)noteEl.oninput=()=>setOutcomeDirty(true);if(saleAmountEl)saleAmountEl.oninput=()=>setOutcomeDirty(true);if(saleDateEl)saleDateEl.onchange=()=>setOutcomeDirty(true);if(saveButton)saveButton.onclick=saveOutcome;setOutcomeDirty(false)}

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
 window.Manager2Presentation?.openClientCard?.($('mobileLeadBtn'));
 outcome.scrollIntoView?.({block:'nearest'});(outcome.value==='lost'?$('leadCloseReason'):$('leadSaleAmount'))?.focus({preventScroll:true});return true;
}

window.WorkspaceV2Outcome={startCompletion,syncCompletionStage,bind,saveOutcome,setOutcomeSaveState,setOutcomeDirty,sameLead,isOutcomeDirty,isOutcomeSaving,refreshAfterSave};
})();
