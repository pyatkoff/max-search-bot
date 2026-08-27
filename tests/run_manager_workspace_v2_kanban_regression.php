<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$workspace=(string)file_get_contents($root.'/manager/workspace-v2.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$kanban=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.css');
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$passed=0;$failed=0;
function kbCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
kbCheck('workspace loads dedicated kanban module and stylesheet',strpos($workspace,'assets/workspace-v2-kanban.js')!==false&&strpos($workspace,'assets/workspace-v2-kanban.css')!==false);
kbCheck('workspace exposes list and board toggle',strpos($workspace,'id="listViewBtn"')!==false&&strpos($workspace,'id="kanbanViewBtn"')!==false&&strpos($workspace,'id="kanbanBoard"')!==false);
kbCheck('kanban mode is explicit UI state',strpos($core,"viewMode:'list'")!==false&&strpos($kanban,"S.viewMode=mode")!==false&&strpos($kanban,"mode==='kanban'")!==false);
kbCheck('board reuses existing filtered lead list',strpos($inbox,'async function fetchRows()')!==false&&strpos($kanban,'WorkspaceV2Inbox')!==false&&strpos($inbox,"if(S.viewMode==='kanban')")!==false);
kbCheck('board groups only by business pipeline stage',strpos($kanban,'S.pipeline.stages')!==false&&strpos($kanban,'lead_stage?.stage_key')!==false&&strpos($kanban,'technical_status')===false&&strpos($kanban,'statusText(')===false);
kbCheck('list exposes centralized pipeline edit permission',strpos($api,"\$row['can_edit_pipeline']=ManagerRequestContext::canEditAssignedConversation")!==false);
kbCheck('editable cards expose explicit stage selector',strpos($kanban,'c.can_edit_pipeline')!==false&&strpos($kanban,'kanbanStageSelect')!==false&&strpos($kanban,"pipe('set_stage'")!==false);
kbCheck('stage selector uses configured business stages only',strpos($kanban,'S.pipeline.stages||[]')!==false&&strpos($kanban,'stage_key')!==false&&strpos($kanban,"'manager'")===false&&strpos($kanban,"'waiting_manager'")===false);
kbCheck('stage mutation keeps backend authorization gate',substr_count($api,"if(!\$can)pipelineOut(['ok'=>false,'error'=>'forbidden'],403)")>=4&&strpos($api,"if(\$action==='set_stage'")!==false);
kbCheck('board cards preserve lead context',strpos($kanban,'origin_label')!==false&&strpos($kanban,'trip_summary')!==false&&strpos($kanban,'lead_tags')!==false&&strpos($kanban,'next_task_title')!==false&&strpos($kanban,'awaiting_first_reply')!==false);
kbCheck('card open remains separate from stage control',strpos($kanban,'kanbanOpen')!==false&&strpos($kanban,"setMode('list')")!==false&&strpos($kanban,'WorkspaceV2Conversation?.open')!==false);
kbCheck('kanban stage control remains responsive and keyboard-visible',strpos($css,'.kanbanStageControl')!==false&&strpos($css,'.kanbanStageSelect:focus')!==false&&strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'82vw')!==false);
kbCheck('pipeline API does not couple technical status to business stage',strpos($api,"'technical_status'=>\$c['status']")!==false&&strpos($api,'UPDATE conversations SET status')===false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
