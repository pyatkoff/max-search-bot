<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$conversation=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$lead=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$tasks=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$outcome=(string)file_get_contents($root.'/manager/assets/workspace-v2-outcome.js');
$passed=0;$failed=0;
function navSaveCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

navSaveCheck('lead refresh accepts an explicit source conversation',strpos($conversation,'conversationId=S.current')!==false&&strpos($conversation,'const target=Number(conversationId||0)')!==false&&strpos($conversation,"pipe('detail',{conversation_id:target})")!==false);
navSaveCheck('late lead refresh cannot repaint another open lead',strpos($conversation,'const stillCurrent=Number(S.current)===target')!==false&&strpos($conversation,'if(stillCurrent){S.detail.lead=p;window.WorkspaceV2LeadCard?.render')!==false);
navSaveCheck('Inbox refresh remains independent from Lead Card repaint',strpos($conversation,"if(refreshInbox)await window.WorkspaceV2Inbox?.load({preserveScroll:true})")!==false&&strpos($conversation,'return stillCurrent')!==false);
navSaveCheck('stage mutation is pinned to source lead',strpos($pipeline,'const target=Number(S.current||0),wanted=stage.value')!==false&&strpos($pipeline,"pipe('set_stage',{conversation_id:target")!==false&&strpos($pipeline,'await refreshAfterSave(target)')!==false&&strpos($lead,'WorkspaceV2Pipeline?.bindSalesEditor({canEdit,pipeline})')!==false);
navSaveCheck('stage saves are independently scoped per lead',strpos($pipeline,'stageSavingLeads=new Set()')!==false&&strpos($pipeline,'stageSavingLeads.has(target)')!==false&&strpos($pipeline,'stageSavingLeads.add(target)')!==false&&strpos($pipeline,'stageSavingLeads.delete(target)')!==false);
navSaveCheck('duplicate stage save restores the current control without touching another lead',strpos($pipeline,"if(stageSavingLeads.has(target)){if(stage.isConnected)stage.value=previousStage")!==false&&strpos($pipeline,"setSalesSaveState('Сохранение этапа уже выполняется','dirty')")!==false&&strpos($pipeline,'if(stage.isConnected)stage.disabled=false')!==false);
navSaveCheck('stage save status is not written into a different lead',strpos($pipeline,"if(sameLead(target))setSalesSaveState('Сохраняем этап…')")!==false&&strpos($pipeline,"if(sameLead(target))setSalesSaveState('Этап сохранён','success')")!==false);
navSaveCheck('Lead Card delegates task rendering and source lead identity to task owner',strpos($lead,'WorkspaceV2Tasks.render(root,{tasks,canEdit,conversationId})')!==false&&strpos($lead,'const taskMutations=new Map()')===false&&strpos($lead,"pipe('create_task'")===false&&strpos($lead,"pipe('set_task_completed'")===false);
navSaveCheck('task create toggle and pin capture explicit source lead',strpos($tasks,'render(root,{tasks=[],canEdit=false,conversationId=0})')!==false&&strpos($tasks,'const target=Number(conversationId||0)')!==false&&strpos($tasks,"W.pipe('create_task',{conversation_id:target")!==false&&strpos($tasks,"W.pipe('set_task_completed',{conversation_id:target")!==false&&strpos($tasks,"W.pipe('set_task_pinned',{conversation_id:target")!==false);
navSaveCheck('all task refreshes use source conversation id',substr_count($tasks,'refreshLead(target)')>=4&&strpos($tasks,'refreshLeadData({refreshInbox:true,conversationId:target})')!==false);
navSaveCheck('task mutations keep in-flight ownership outside rerendered DOM',strpos($tasks,'const taskMutations=new Map()')!==false&&strpos($tasks,'async function guardTaskMutation(key,signature,work)')!==false&&strpos($tasks,'taskMutations.get(key)')!==false&&strpos($tasks,'taskMutations.set(key,{signature,promise:pending})')!==false&&strpos($tasks,'taskMutations.delete(key)')!==false);
navSaveCheck('same task mutation payload coalesces while conflicting payload is rejected',strpos($tasks,'if(active)return active.signature===signature?active.promise:false')!==false&&strpos($tasks,'guardTaskMutation(`create:${target}`,signature')!==false&&strpos($tasks,'guardTaskMutation(`update:${target}:${id}`,signature')!==false&&strpos($tasks,'guardTaskMutation(`toggle:${target}:${id}`,String(!!completed)')!==false&&strpos($tasks,'guardTaskMutation(`pin:${target}:${id}`,String(!!pinned)')!==false);
navSaveCheck('task mutation guards remain scoped by lead and task ids',strpos($tasks,'`create:${target}`')!==false&&strpos($tasks,'`update:${target}:${id}`')!==false&&strpos($tasks,'`toggle:${target}:${id}`')!==false&&strpos($tasks,'`pin:${target}:${id}`')!==false);
navSaveCheck('tag saves are independently scoped per lead',strpos($pipeline,'tagSavingLeads=new Set()')!==false&&strpos($pipeline,'tagSavingLeads.has(target)')!==false&&strpos($pipeline,'tagSavingLeads.add(target)')!==false&&strpos($pipeline,'tagSavingLeads.delete(target)')!==false);
navSaveCheck('tag save is pinned and status is current-lead scoped',strpos($pipeline,"pipe('set_tags',{conversation_id:target")!==false&&strpos($pipeline,"if(sameLead(target))setSalesSaveState('Теги сохранены','success')")!==false&&strpos($pipeline,'changed?.isConnected')!==false);
navSaveCheck('tag finally only unlocks captured old controls',strpos($pipeline,'inputs.forEach(x=>{if(x.isConnected)x.disabled=false})')!==false&&strpos($pipeline,"document.querySelectorAll('#leadTags input').forEach(x=>x.disabled=false)")===false);
navSaveCheck('outcome dirty and saving ownership are lead scoped',strpos($outcome,'let outcomeDirtyLead=0')!==false&&strpos($outcome,'function isOutcomeDirty(target=S.current)')!==false&&strpos($outcome,'function isOutcomeSaving(target=S.current)')!==false&&strpos($outcome,'outcomeSavingLeads.add(target)')!==false&&strpos($outcome,'outcomeSavingLeads.delete(target)')!==false);
navSaveCheck('outcome save is pinned and status is current-lead scoped',strpos($outcome,"pipe('set_outcome',{conversation_id:target")!==false&&strpos($outcome,"if(sameLead(target))setOutcomeSaveState('Результат сохранён','success')")!==false);
navSaveCheck('outcome finally only mutates source lead controls',strpos($outcome,"button=$('saveOutcome')")!==false&&strpos($outcome,'if(sameLead(target)&&button?.isConnected){button.disabled=!isOutcomeDirty(target)')!==false&&strpos($outcome,"const next=$('saveOutcome')")===false);
navSaveCheck('pipeline delegates outcome binding without duplicating outcome persistence',strpos($pipeline,'WorkspaceV2Outcome?.bind({canEdit,pipeline})')!==false&&strpos($pipeline,"pipe('set_outcome'")===false&&strpos($pipeline,'outcomeDirtyLead')===false);
navSaveCheck('save refresh falls back to Inbox only after navigation',strpos($pipeline,'async function refreshAfterSave(target)')!==false&&strpos($pipeline,"await window.WorkspaceV2Inbox?.load({preserveScroll:true});return false")!==false&&strpos($outcome,'async function refreshAfterSave(target)')!==false);
$all=$conversation.$lead.$tasks.$pipeline.$outcome;
navSaveCheck('slice preserves protected product boundaries',stripos($all,'metrika')===false&&stripos($all,'routing_bonus')===false&&stripos($all,'set_working')===false&&strpos($all,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
