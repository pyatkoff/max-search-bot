<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$conversation=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$lead=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$passed=0;$failed=0;
function navSaveCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

navSaveCheck('lead refresh accepts an explicit source conversation',strpos($conversation,'conversationId=S.current')!==false&&strpos($conversation,'const target=Number(conversationId||0)')!==false&&strpos($conversation,"pipe('detail',{conversation_id:target})")!==false);
navSaveCheck('late lead refresh cannot repaint another open lead',strpos($conversation,'const stillCurrent=Number(S.current)===target')!==false&&strpos($conversation,'if(stillCurrent){S.detail.lead=p;window.WorkspaceV2LeadCard?.render')!==false);
navSaveCheck('Inbox refresh remains independent from Lead Card repaint',strpos($conversation,"if(refreshInbox)await window.WorkspaceV2Inbox?.load({preserveScroll:true})")!==false&&strpos($conversation,'return stillCurrent')!==false);
navSaveCheck('stage mutation is pinned to source lead',strpos($lead,'const target=Number(S.current||0),wanted=stage.value')!==false&&strpos($lead,"pipe('set_stage',{conversation_id:target")!==false&&strpos($lead,'refreshLeadData({refreshInbox:true,conversationId:target})')!==false);
navSaveCheck('stage save status is not written into a different lead',strpos($lead,"if(sameLead(target))window.WorkspaceV2Pipeline?.setSalesSaveState('Сохраняем этап…')")!==false&&strpos($lead,"if(sameLead(target))window.WorkspaceV2Pipeline?.setSalesSaveState('Этап сохранён','success')")!==false);
navSaveCheck('task create toggle and pin capture their source lead',substr_count($lead,'const target=Number(S.current||0)')>=4&&strpos($lead,"pipe('create_task',{conversation_id:target")!==false&&strpos($lead,"pipe('set_task_completed',{conversation_id:target")!==false&&strpos($lead,"pipe('set_task_pinned',{conversation_id:target")!==false);
navSaveCheck('all task refreshes use source conversation id',substr_count($lead,'refreshLeadData({refreshInbox:true,conversationId:target})')>=4);
navSaveCheck('tag save is pinned and status is current-lead scoped',strpos($pipeline,"pipe('set_tags',{conversation_id:target")!==false&&strpos($pipeline,"if(sameLead(target))setSalesSaveState('Теги сохранены','success')")!==false&&strpos($pipeline,'changed?.isConnected')!==false);
navSaveCheck('tag finally only unlocks captured old controls',strpos($pipeline,'inputs.forEach(x=>{if(x.isConnected)x.disabled=false})')!==false&&strpos($pipeline,"document.querySelectorAll('#leadTags input').forEach(x=>x.disabled=false)")===false);
navSaveCheck('outcome save is pinned and status is current-lead scoped',strpos($pipeline,"pipe('set_outcome',{conversation_id:target")!==false&&strpos($pipeline,"if(sameLead(target))setOutcomeSaveState('Результат сохранён','success')")!==false);
navSaveCheck('outcome finally only mutates captured connected button',strpos($pipeline,"button=$('saveOutcome')")!==false&&strpos($pipeline,'if(button?.isConnected){button.disabled=!outcomeDirty')!==false&&strpos($pipeline,"const next=$('saveOutcome')")===false);
navSaveCheck('save refresh falls back to Inbox only after navigation',strpos($pipeline,'async function refreshAfterSave(target)')!==false&&strpos($pipeline,"await window.WorkspaceV2Inbox?.load({preserveScroll:true});return false")!==false);
navSaveCheck('slice preserves protected product boundaries',stripos($conversation.$lead.$pipeline,'metrika')===false&&stripos($conversation.$lead.$pipeline,'routing_bonus')===false&&stripos($conversation.$lead.$pipeline,'set_working')===false&&strpos($conversation.$lead.$pipeline,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
