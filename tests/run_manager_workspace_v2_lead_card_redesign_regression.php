<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/index.php');
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$mobile=(string)file_get_contents($root.'/manager/assets/workspace-v2-mobile.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.css');
$service=(string)file_get_contents($root.'/services/SalesPipelineService.php');
$passed=0;$failed=0;
function lcCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
function lcAssetLoaded(string $html,string $file):bool{return strpos($html,'assets/'.$file)!==false||strpos($html,"workspaceAsset('{$file}')")!==false;}

lcCheck('workspace loads dedicated lead card stylesheet',lcAssetLoaded($page,'workspace-v2-lead-card.css'));
lcCheck('mobile lead card has explicit close control',strpos($page,'id="mobileLeadClose"')!==false&&strpos($mobile,"$('mobileLeadClose')")!==false&&strpos($mobile,'back()')!==false);
lcCheck('lead card prioritizes identity contact and trip',strpos($js,'leadHeroName')!==false&&strpos($js,'leadContactActions')!==false&&strpos($js,'leadRouteMain')!==false);
lcCheck('phone action is safely normalized',strpos($js,'function phoneHref')!==false&&strpos($js,"/^\\+?\\d{5,20}$/")!==false&&strpos($js,"'tel:'")!==false);
lcCheck('email action is validated before mailto',strpos($js,'function emailHref')!==false&&strpos($js,"'mailto:'")!==false);
lcCheck('next action appears before sales controls',($taskPos=strpos($js,'Следующее действие'))!==false&&($salesPos=strpos($js,'<div class="leadPanelTitle">Продажа</div>'))!==false&&$taskPos<$salesPos);
lcCheck('sales stage history is read-only lead context',strpos($js,'function stageHistoryMarkup')!==false&&strpos($js,'pipeline.stage_history')!==false&&strpos($js,'История этапов')!==false&&strpos($js,"pipe('set_stage_history'")===false);
lcCheck('stage history projection exposes manager display name',strpos($service,'changed_by_manager_name')!==false&&strpos($service,'LEFT JOIN managers m ON m.id=h.changed_by_manager_id')!==false);
lcCheck('stage history layout stays compact and responsive',strpos($css,'.leadStageHistoryDetails')!==false&&strpos($css,'.leadStageHistoryRow')!==false&&strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'.leadStageHistoryDetails summary{min-height:40px}')!==false);
lcCheck('sales stage and tag saves expose one accessible inline status',strpos($js,'id="salesSaveStatus"')!==false&&strpos($js,'aria-live="polite"')!==false&&strpos($pipeline,'function setSalesSaveState')!==false);
lcCheck('lead card delegates sales editor behavior to pipeline owner',strpos($js,'WorkspaceV2Pipeline?.bindSalesEditor({canEdit,pipeline})')!==false&&strpos($pipeline,'function bindSalesEditor(')!==false&&strpos($js,"pipe('set_stage'")===false);
lcCheck('stage mutation disables control and restores last confirmed value on failure',strpos($pipeline,'let confirmedStage=')!==false&&strpos($pipeline,'stage.disabled=true')!==false&&strpos($pipeline,'stage.value=previousStage')!==false&&strpos($pipeline,'saveStage(stage,confirmedStage)')!==false&&strpos($pipeline,"setSalesSaveState('Не удалось изменить этап','error')")!==false);
lcCheck('stage mutation reports success only after target-pinned refresh',strpos($pipeline,"setSalesSaveState('Этап сохранён','success')")!==false&&strpos($pipeline,'await refreshAfterSave(target)')!==false&&strpos($pipeline,"pipe('set_stage',{conversation_id:target")!==false);
lcCheck('tag mutation guards duplicate save per source lead and disables captured controls',strpos($pipeline,'tagSavingLeads.has(target)')!==false&&strpos($pipeline,'tagSavingLeads.add(target)')!==false&&strpos($pipeline,'tagSavingLeads.delete(target)')!==false&&strpos($pipeline,'inputs.forEach(x=>x.disabled=true)')!==false);
lcCheck('failed tag mutation restores changed checkbox',strpos($pipeline,'previous=changed?!changed.checked:null')!==false&&strpos($pipeline,'changed.checked=previous')!==false&&strpos($pipeline,"setSalesSaveState('Не удалось сохранить теги','error')")!==false);
lcCheck('transport-failed tag mutation also restores changed checkbox',strpos($pipeline,"catch(e){if(sameLead(target)&&changed?.isConnected)changed.checked=previous;if(sameLead(target))setSalesSaveState('Не удалось сохранить теги','error');return false}")!==false);
lcCheck('successful tag mutation reports after refresh',strpos($pipeline,"setSalesSaveState('Теги сохранены','success')")!==false&&strpos($pipeline,'saveTags(x)')!==false&&strpos($pipeline,'await refreshAfterSave(target)')!==false);
lcCheck('sales mutation status has success and error presentation',strpos($css,'.salesSaveStatus.success')!==false&&strpos($css,'.salesSaveStatus.error')!==false);
lcCheck('outcome save has inline accessible status',strpos($js,'id="outcomeSaveStatus"')!==false&&strpos($js,'aria-live="polite"')!==false&&strpos($pipeline,'function setOutcomeSaveState')!==false);
lcCheck('outcome save prevents duplicate submit per lead and reports progress',strpos($pipeline,'isOutcomeSaving(target)')!==false&&strpos($pipeline,'outcomeSavingLeads.add(target)')!==false&&strpos($pipeline,"button.textContent='Сохраняем…'")!==false&&strpos($pipeline,"setOutcomeSaveState('Результат сохранён','success')")!==false);
lcCheck('outcome dirty state is owned by current lead',strpos($pipeline,'let outcomeDirtyLead=0')!==false&&strpos($pipeline,'function isOutcomeDirty(target=S.current)')!==false&&strpos($pipeline,'outcomeDirtyLead=dirty?target:0')!==false);
lcCheck('lost outcome validation is inline and focuses reason',strpos($pipeline,"setOutcomeSaveState('Выберите причину отказа','error')")!==false&&(strpos($pipeline,"$('leadCloseReason').focus()")!==false||strpos($pipeline,'reasonEl.focus()')!==false));
lcCheck('outcome save status has success and error presentation',strpos($css,'.outcomeSaveStatus.success')!==false&&strpos($css,'.outcomeSaveStatus.error')!==false);
lcCheck('technical state is collapsed into details',strpos($js,'Источник и служебная информация')!==false&&strpos($js,'<details class="leadDetails">')!==false&&strpos($js,"statusText(handoff.technical_status)")!==false);
lcCheck('technical status remains read only',strpos($pipeline,"pipe('set_stage'")!==false&&strpos($pipeline,"pipe('set_status'")===false&&strpos($js,"pipe('set_status'")===false&&strpos($js,'technical_status=')===false);
lcCheck('lead card mutations keep target-pinned stability refresh boundary',substr_count($js,'refreshLeadData({refreshInbox:true,conversationId:target})')>=4&&strpos($js,'WorkspaceV2Conversation?.open')===false);
lcCheck('mobile lead card is a full screen surface',strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'.leadZone{inset:0!important')!==false);
lcCheck('redesign does not mutate manager shift or routing',stripos($js.' '.$pipeline.' '.$css,'set_working')===false&&stripos($js.' '.$pipeline.' '.$css,'routing_bonus')===false);
lcCheck('redesign does not touch metrika or lead delivery',stripos($js.' '.$pipeline.' '.$css,'metrika')===false&&strpos($js.$pipeline,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
