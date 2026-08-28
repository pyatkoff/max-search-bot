<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/index.php');
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
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
lcCheck('technical state is collapsed into details',strpos($js,'Источник и служебная информация')!==false&&strpos($js,'<details class="leadDetails">')!==false&&strpos($js,"statusText(handoff.technical_status)")!==false);
lcCheck('technical status remains read only',strpos($js,"pipe('set_stage'")!==false&&strpos($js,"pipe('set_status'")===false&&strpos($js,'technical_status=')===false);
lcCheck('lead card mutations keep stability refresh boundary',substr_count($js,'refreshLeadData({refreshInbox:true})')>=3&&strpos($js,'WorkspaceV2Conversation?.open')===false);
lcCheck('mobile lead card is a full screen surface',strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'.leadZone{inset:0!important')!==false);
lcCheck('redesign does not mutate manager shift or routing',stripos($js.' '.$css,'set_working')===false&&stripos($js.' '.$css,'routing_bonus')===false);
lcCheck('redesign does not touch metrika or lead delivery',stripos($js.' '.$css,'metrika')===false&&strpos($js,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
