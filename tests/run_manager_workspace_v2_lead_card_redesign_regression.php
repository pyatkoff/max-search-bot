<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/index.php');
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$tasks=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$outcome=(string)file_get_contents($root.'/manager/assets/workspace-v2-outcome.js');
$mobile=(string)file_get_contents($root.'/manager/assets/workspace-v2-mobile.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.css');
$service=(string)file_get_contents($root.'/services/SalesPipelineService.php');
$passed=0;$failed=0;
function lcCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
function lcAssetLoaded(string $html,string $file):bool{return strpos($html,'assets/'.$file)!==false||strpos($html,"workspaceAsset('{$file}')")!==false;}

lcCheck('workspace loads dedicated lead card stylesheet',lcAssetLoaded($page,'workspace-v2-lead-card.css'));
lcCheck('mobile lead card has explicit close control',strpos($page,'id="mobileLeadClose"')!==false&&strpos($mobile,"$('mobileLeadClose')")!==false&&strpos($mobile,'back()')!==false);
lcCheck('lead card prioritizes identity contact and trip',strpos($js,'leadHeroName')!==false&&strpos($js,'leadContactActions')!==false&&strpos($js,'leadRouteMain')!==false);
lcCheck('collected child ages stay visible in structured trip summary and full details',substr_count($js,"compactValue('Возраст детей',trip.child_ages)")===1&&substr_count($js,"tripField('Возраст детей',trip.child_ages)")===1);
lcCheck('canonical meal values render as human-readable manager labels',strpos($js,'function mealLabel')!==false&&strpos($js,"all_inclusive:'Всё включено'")!==false&&strpos($js,"half_board:'Полупансион'")!==false&&strpos($js,"full_board:'Полный пансион'")!==false&&substr_count($js,"mealLabel(trip.meal)")===2);
lcCheck('phone action is safely normalized',strpos($js,'function phoneHref')!==false&&strpos($js,"/^\\+?\\d{5,20}$/")!==false&&strpos($js,"'tel:'")!==false);
lcCheck('email action is validated before mailto',strpos($js,'function emailHref')!==false&&strpos($js,"'mailto:'")!==false);
lcCheck('next action appears before sales controls',($taskPos=strpos($js,'Следующее действие'))!==false&&($salesPos=strpos($js,'<div class="leadPanelTitle">Продажа</div>'))!==false&&$taskPos<$salesPos);
lcCheck('sales stage history is read-only lead context',strpos($js,'function stageHistoryMarkup')!==false&&strpos($js,'pipeline.stage_history')!==false&&strpos($js,'История этапов')!==false&&strpos($js,"pipe('set_stage_history'")===false);
lcCheck('stage history projection exposes manager display name',strpos($service,'changed_by_manager_name')!==false&&strpos($service,'LEFT JOIN managers m ON m.id=h.changed_by_manager_id')!==false);
lcCheck('stage history layout stays compact and responsive',strpos($css,'.leadStageHistoryDetails')!==false&&strpos($css,'.leadStageHistoryRow')!==false&&strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'.leadStageHistoryDetails summary{min-height:40px}')!==false);
lcCheck('sales stage and tag saves expose one accessible inline status',strpos($js,'id="salesSaveStatus"')!==false&&strpos($js,'aria-live="polite"')!==false&&strpos($pipeline,'function setSalesSaveState')!==false);
lcCheck('lead card delegates sales editor behavior to pipeline owner',strpos($js,'WorkspaceV2Pipeline?.bindSalesEditor({canEdit,pipeline})')!==false&&strpos($pipeline,'function bindSalesEditor(')!==false&&strpos($js,"pipe('set_stage'")===false);
lcCheck('pipeline delegates outcome editor behavior to dedicated owner',strpos($pipeline,'WorkspaceV2Outcome?.bind({canEdit,pipeline})')!==false&&strpos($outcome,'window.WorkspaceV2Outcome=')!==false&&strpos($pipeline,"pipe('set_outcome'")===false);
lcCheck('stage mutation disables control and restores last confirmed value on failure',strpos($pipeline,'let confirmedStage=')!==false&&strpos($pipeline,'stage.disabled=true')!==false&&strpos($pipeline,'stage.value=previousStage')!==false&&strpos($pipeline,'saveStage(stage,confirmedStage)')!==false&&strpos($pipeline,"setSalesSaveState('Не удалось изменить этап','error')")!==false);
lcCheck('stage mutation reports success only after target-pinned refresh',strpos($pipeline,"setSalesSaveState('Этап сохранён','success')")!==false&&strpos($pipeline,'await refreshAfterSave(target)')!==false&&strpos($pipeline,"pipe('set_stage',{conversation_id:target")!==false);
lcCheck('tag mutation guards duplicate save per source lead and disables captured controls',strpos($pipeline,'tagSavingLeads.has(target)')!==false&&strpos($pipeline,'tagSavingLeads.add(target)')!==false&&strpos($pipeline,'tagSavingLeads.delete(target)')!==false&&strpos($pipeline,'inputs.forEach(x=>x.disabled=true)')!==false);
lcCheck('failed tag mutation restores changed checkbox',strpos($pipeline,'previous=changed?!changed.checked:null')!==false&&strpos($pipeline,'changed.checked=previous')!==false&&strpos($pipeline,"setSalesSaveState('Не удалось сохранить теги','error')")!==false);
lcCheck('transport-failed tag mutation also restores changed checkbox',strpos($pipeline,"catch(e){if(sameLead(target)&&changed?.isConnected)changed.checked=previous;if(sameLead(target))setSalesSaveState('Не удалось сохранить теги','error');return false}")!==false);
lcCheck('successful tag mutation reports after refresh',strpos($pipeline,"setSalesSaveState('Теги сохранены','success')")!==false&&strpos($pipeline,'saveTags(x)')!==false&&strpos($pipeline,'await refreshAfterSave(target)')!==false);
lcCheck('sales mutation status has success and error presentation',strpos($css,'.salesSaveStatus.success')!==false&&strpos($css,'.salesSaveStatus.error')!==false);
lcCheck('outcome save has inline accessible status',strpos($js,'id="outcomeSaveStatus"')!==false&&strpos($js,'aria-live="polite"')!==false&&strpos($outcome,'function setOutcomeSaveState')!==false);
lcCheck('outcome save prevents duplicate submit per lead and reports progress',strpos($outcome,'isOutcomeSaving(target)')!==false&&strpos($outcome,'outcomeSavingLeads.add(target)')!==false&&strpos($outcome,"button.textContent='Сохраняем…'")!==false&&strpos($outcome,"setOutcomeSaveState('Результат сохранён','success')")!==false);
lcCheck('outcome dirty state is owned by current lead',strpos($outcome,'let outcomeDirtyLead=0')!==false&&strpos($outcome,'function isOutcomeDirty(target=S.current)')!==false&&strpos($outcome,'outcomeDirtyLead=dirty?target:0')!==false);
lcCheck('lost outcome validation is inline and focuses reason',strpos($outcome,"setOutcomeSaveState('Выберите причину отказа','error')")!==false&&(strpos($outcome,"$('leadCloseReason').focus()")!==false||strpos($outcome,'reasonEl.focus()')!==false));
lcCheck('outcome save status has success and error presentation',strpos($css,'.outcomeSaveStatus.success')!==false&&strpos($css,'.outcomeSaveStatus.error')!==false);
lcCheck('technical state is collapsed into details',strpos($js,'Источник и служебная информация')!==false&&strpos($js,'<details class="leadDetails">')!==false&&strpos($js,"statusText(handoff.technical_status)")!==false);
lcCheck('technical status remains read only',strpos($pipeline,"pipe('set_stage'")!==false&&strpos($pipeline,"pipe('set_status'")===false&&strpos($js,"pipe('set_status'")===false&&strpos($js,'technical_status=')===false);
lcCheck('lead card delegates task mutations while task owner keeps target-pinned stability refresh boundary',strpos($js,'WorkspaceV2Tasks.render(root,{tasks,canEdit,conversationId})')!==false&&strpos($js,"pipe('create_task'")===false&&substr_count($tasks,'refreshLead(target)')>=4&&strpos($tasks,'refreshLeadData({refreshInbox:true,conversationId:target})')!==false&&strpos($tasks,'WorkspaceV2Conversation?.open')===false);
lcCheck('mobile lead card is a full screen surface',strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'.leadZone{inset:0!important')!==false);
$all=$js.' '.$tasks.' '.$pipeline.' '.$outcome.' '.$css;
lcCheck('redesign does not mutate manager shift or routing',stripos($all,'set_working')===false&&stripos($all,'routing_bonus')===false);
lcCheck('redesign does not touch metrika or lead delivery',stripos($all,'metrika')===false&&strpos($all,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);