<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/index.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$filters=(string)file_get_contents($root.'/manager/assets/workspace-v2-filters.js');
$outcome=(string)file_get_contents($root.'/manager/assets/workspace-v2-outcome.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$history=(string)file_get_contents($root.'/manager/assets/workspace-v2-stage-history.js');
$lead=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$conversation=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$shift=(string)file_get_contents($root.'/manager/assets/workspace-v2-shift.js');
$bootstrap=(string)file_get_contents($root.'/manager/assets/workspace-v2-bootstrap.js');
$api=(string)file_get_contents($root.'/manager/api.php');
$queueProjection=(string)file_get_contents($root.'/services/ManagerQueueProjectionService.php');
$availability=(string)file_get_contents($root.'/services/ManagerAvailabilityService.php');
$passed=0;$failed=0;
function splitCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
function splitAssetPos(string $html,string $asset){$static=strpos($html,$asset);if($static!==false)return$static;$file=basename($asset);return strpos($html,"workspaceAsset('{$file}')");}

$ordered=['assets/workspace-v2.js','assets/workspace-v2-inbox.js','assets/workspace-v2-filters.js','assets/workspace-v2-outcome.js','assets/workspace-v2-pipeline.js','assets/workspace-v2-stage-history.js','assets/workspace-v2-lead-card.js','assets/workspace-v2-conversation.js','assets/workspace-v2-shift.js','assets/workspace-v2-bootstrap.js'];
$positions=array_map(fn($asset)=>splitAssetPos($page,$asset),$ordered);
$validOrder=!in_array(false,$positions,true);
if($validOrder){for($i=1;$i<count($positions);$i++){if($positions[$i]<=$positions[$i-1]){$validOrder=false;break;}}}
splitCheck('workspace loads feature modules after shared core in dependency-safe order',$validOrder);
splitCheck('shared core owns state transport boot and auth recovery only',strpos($core,'window.WorkspaceV2=')!==false&&strpos($core,"request('api.php'")!==false&&strpos($core,"request('pipeline-api.php'")!==false&&strpos($core,'showAuthRecovery')!==false&&strpos($core,'bindWorkspaceOnce')!==false);
splitCheck('shared core no longer renders inbox conversation or lead card',strpos($core,'leadItem')===false&&strpos($core,'(d.messages||[]).forEach')===false&&strpos($core,'id="leadOutcome"')===false&&strpos($core,"pipe('set_stage'")===false);
splitCheck('inbox module owns lead list rendering',strpos($inbox,'window.WorkspaceV2Inbox=')!==false&&strpos($inbox,'leadItem')!==false&&strpos($inbox,"pipe('list'")!==false);
splitCheck('inbox module owns queue-count presentation using canonical manager counts projection',strpos($inbox,'function refreshQueueCounts')!==false&&strpos($inbox,"api('counts'")!==false&&strpos($inbox,"renderQueueCount('waiting'")!==false&&strpos($inbox,"renderQueueCount('mine'")!==false&&strpos($inbox,"className='filtersCount queueCount'")!==false&&strpos($inbox,'непрочитанных сообщений')!==false&&strpos($api,"if(\$action==='counts')")!==false&&strpos($api,'ManagerQueueProjectionService::counts')!==false&&strpos($queueProjection,'class ManagerQueueProjectionService')!==false);
splitCheck('filter module owns inbox filter persistence and shortcut orchestration',strpos($filters,'window.WorkspaceV2Filters=')!==false&&strpos($filters,'FILTER_STORAGE_KEY')!==false&&strpos($filters,'leadStageFilter')!==false&&strpos($filters,'setTaskShortcut')!==false&&strpos($filters,'WorkspaceV2Inbox.load({preserveScroll:false})')!==false);
splitCheck('outcome module owns result and sale persistence',strpos($outcome,'window.WorkspaceV2Outcome=')!==false&&strpos($outcome,"pipe('set_outcome'")!==false&&strpos($outcome,'outcomeDirtyLead')!==false&&strpos($outcome,'saleAmountEl')!==false&&strpos($pipeline,"pipe('set_outcome'")===false&&strpos($pipeline,'outcomeDirtyLead')===false);
splitCheck('pipeline module owns sales stage and tag persistence and delegates outcome binding',strpos($pipeline,'window.WorkspaceV2Pipeline=')!==false&&strpos($pipeline,"pipe('set_tags'")!==false&&strpos($pipeline,"pipe('set_stage'")!==false&&strpos($pipeline,'WorkspaceV2Outcome?.bind({canEdit,pipeline})')!==false&&strpos($pipeline,'FILTER_STORAGE_KEY')===false&&strpos($pipeline,'setTaskShortcut')===false);
splitCheck('stage history module owns sales-stage history presentation',strpos($history,'window.WorkspaceV2StageHistory=')!==false&&strpos($history,'История этапов')!==false&&strpos($history,'history.slice(0,5)')!==false&&strpos($lead,'WorkspaceV2StageHistory?.markup(history)')!==false&&strpos($lead,'history.slice(0,5)')===false);
splitCheck('lead card module owns structured business card',strpos($lead,'window.WorkspaceV2LeadCard=')!==false&&strpos($lead,'leadHeroName')!==false&&strpos($lead,'Следующее действие')!==false&&strpos($lead,'<div class="leadPanelTitle">Продажа</div>')!==false&&strpos($lead,'Все параметры поездки')!==false&&strpos($lead,'Источник и служебная информация')!==false);
splitCheck('conversation module owns transcript lifecycle and source-pinned composer send',strpos($conversation,'window.WorkspaceV2Conversation=')!==false&&strpos($conversation,'function renderMessages(')!==false&&strpos($conversation,'(messages||[]).forEach')!==false&&strpos($conversation,"change('take')")!==false&&strpos($conversation,'const target=Number(S.current||0),generation=openSeq,text=')!==false&&strpos($conversation,"api('send',{conversation_id:target,text})")!==false);
splitCheck('workspace exposes explicit shift control',strpos($page,'id="managerShiftBtn"')!==false&&strpos($page,'Начать смену')!==false);
splitCheck('dedicated shift module uses existing set_working API',strpos($shift,'window.WorkspaceV2Shift=')!==false&&strpos($shift,"W.api('set_working',{working:next})")!==false&&strpos($shift,'S.manager=j.manager')!==false&&strpos($shift,"aria-pressed")!==false);
splitCheck('shift backend remains owned by ManagerAvailabilityService',strpos($api,"if(\$action==='set_working')")!==false&&strpos($api,'ManagerAvailabilityService::setWorking')!==false&&strpos($availability,'public static function setWorking')!==false&&strpos($availability,'UPDATE managers SET is_working=?')!==false);
$shiftPos=splitAssetPos($page,'assets/workspace-v2-shift.js');$bootstrapPos=splitAssetPos($page,'assets/workspace-v2-bootstrap.js');
splitCheck('shift feature is cache-busted and loaded before bootstrap starts assembled workspace',$shiftPos!==false&&$bootstrapPos!==false&&$shiftPos<$bootstrapPos&&strpos($page,"workspaceAsset('workspace-v2-shift.js')")!==false&&strpos($bootstrap,'window.WorkspaceV2?.boot()')!==false&&strpos($bootstrap,'workspace-v2-shift.js')===false);
$all=$core."\n".$inbox."\n".$filters."\n".$outcome."\n".$pipeline."\n".$history."\n".$lead."\n".$conversation."\n".$shift."\n".$bootstrap;
splitCheck('shift UI does not duplicate shift storage or routing policy',strpos($shift,'UPDATE managers')===false&&strpos($shift,'manager_projects')===false&&strpos($shift,'RoutingAccessService')===false);
splitCheck('module split does not touch analytics or lead delivery contracts',stripos($all,'metrika')===false&&stripos($all,'yclid')===false&&strpos($all,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);