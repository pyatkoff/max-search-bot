<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/manager/workspace-v2.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$pipeline=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$lead=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$conversation=(string)file_get_contents($root.'/manager/assets/workspace-v2-conversation.js');
$bootstrap=(string)file_get_contents($root.'/manager/assets/workspace-v2-bootstrap.js');
$passed=0;$failed=0;
function splitCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$ordered=['assets/workspace-v2.js','assets/workspace-v2-inbox.js','assets/workspace-v2-pipeline.js','assets/workspace-v2-lead-card.js','assets/workspace-v2-conversation.js','assets/workspace-v2-bootstrap.js'];
$positions=array_map(fn($asset)=>strpos($page,$asset),$ordered);
$validOrder=!in_array(false,$positions,true);
if($validOrder){for($i=1;$i<count($positions);$i++){if($positions[$i]<=$positions[$i-1]){$validOrder=false;break;}}}
splitCheck('workspace loads feature modules after shared core in dependency-safe order',$validOrder);
splitCheck('shared core owns state transport utilities and boot only',strlen($core)<5000&&strpos($core,'window.WorkspaceV2=')!==false&&strpos($core,"request('api.php'")!==false&&strpos($core,"request('pipeline-api.php'")!==false);
splitCheck('shared core no longer renders inbox conversation or lead card',strpos($core,'leadItem')===false&&strpos($core,'(d.messages||[]).forEach')===false&&strpos($core,'id="leadOutcome"')===false&&strpos($core,"pipe('set_stage'")===false);
splitCheck('inbox module owns lead list rendering',strpos($inbox,'window.WorkspaceV2Inbox=')!==false&&strpos($inbox,'leadItem')!==false&&strpos($inbox,"pipe('list'")!==false);
splitCheck('pipeline module owns filters tags and outcome persistence',strpos($pipeline,'window.WorkspaceV2Pipeline=')!==false&&strpos($pipeline,"pipe('set_tags'")!==false&&strpos($pipeline,"pipe('set_outcome'")!==false&&strpos($pipeline,'leadStageFilter')!==false);
splitCheck('lead card module owns structured business card',strpos($lead,'window.WorkspaceV2LeadCard=')!==false&&strpos($lead,'<div class="sectionTitle">Продажа</div>')!==false&&strpos($lead,'<div class="sectionTitle">Поездка</div>')!==false&&strpos($lead,'<div class="sectionTitle">Источник и handoff</div>')!==false);
splitCheck('conversation module owns transcript lifecycle and composer',strpos($conversation,'window.WorkspaceV2Conversation=')!==false&&strpos($conversation,'(d.messages||[]).forEach')!==false&&strpos($conversation,"change('take')")!==false&&strpos($conversation,"api('send',{conversation_id:S.current,text})")!==false);
splitCheck('bootstrap only starts assembled workspace',trim($bootstrap)==='window.WorkspaceV2?.boot();');
$all=$core."\n".$inbox."\n".$pipeline."\n".$lead."\n".$conversation."\n".$bootstrap;
splitCheck('module split does not introduce manager shift mutation',strpos($all,'set_working')===false&&strpos($all,'is_working=')===false);
splitCheck('module split does not touch analytics or lead delivery contracts',stripos($all,'metrika')===false&&stripos($all,'yclid')===false&&strpos($all,'LeadDestination')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
