<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$workspace=(string)file_get_contents($root.'/manager/index.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$kanban=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.css');
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$http=(string)file_get_contents($root.'/manager/lib/ManagerHttp.php');
$passed=0;$failed=0;
function kbCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
function kbAssetLoaded(string $html,string $file):bool{return strpos($html,'assets/'.$file)!==false||strpos($html,"workspaceAsset('{$file}')")!==false;}
kbCheck('workspace loads dedicated kanban module and stylesheet',kbAssetLoaded($workspace,'workspace-v2-kanban.js')&&kbAssetLoaded($workspace,'workspace-v2-kanban.css'));
kbCheck('workspace exposes list and board toggle',strpos($workspace,'id="listViewBtn"')!==false&&strpos($workspace,'id="kanbanViewBtn"')!==false&&strpos($workspace,'id="kanbanBoard"')!==false);
kbCheck('kanban mode is explicit UI state',strpos($core,"viewMode:'list'")!==false&&strpos($kanban,"S.viewMode=mode")!==false&&strpos($kanban,"mode==='kanban'")!==false);
kbCheck('board reuses existing filtered lead list',strpos($inbox,'async function fetchRows()')!==false&&strpos($kanban,'WorkspaceV2Inbox')!==false&&strpos($inbox,"if(S.viewMode==='kanban')")!==false);
kbCheck('board groups only by business pipeline stage',strpos($kanban,'S.pipeline.stages')!==false&&strpos($kanban,'lead_stage?.stage_key')!==false&&strpos($kanban,'technical_status')===false&&strpos($kanban,'statusText(')===false);
kbCheck('list exposes centralized pipeline edit permission',strpos($api,"\$row['can_edit_pipeline']=ManagerHttp::canEditConversation")!==false&&strpos($http,'ManagerRequestContext::canEditAssignedConversation')!==false);
kbCheck('editable cards expose explicit stage selector',strpos($kanban,'c.can_edit_pipeline')!==false&&strpos($kanban,'kanbanStageSelect')!==false&&strpos($kanban,"pipe('set_stage'")!==false);
kbCheck('stage selector uses configured business stages only',strpos($kanban,'S.pipeline.stages||[]')!==false&&strpos($kanban,'stage_key')!==false&&strpos($kanban,"'manager'")===false&&strpos($kanban,"'waiting_manager'")===false);
kbCheck('stage mutation keeps backend authorization gate',strpos($api,"if(\$action==='set_stage'")!==false&&substr_count($api,'ManagerHttp::requireConversationEdit($c,$m);')>=6&&strpos($http,"'error'=>'forbidden'")!==false);
kbCheck('board cards preserve lead context',strpos($kanban,'origin_label')!==false&&strpos($kanban,'trip_summary')!==false&&strpos($kanban,'lead_tags')!==false&&strpos($kanban,'next_task_title')!==false&&strpos($kanban,'awaiting_first_reply')!==false);
kbCheck('board surfaces shared task urgency projection',strpos($kanban,'next_task_due_state')!==false&&strpos($kanban,'next_task_overdue')!==false&&strpos($kanban,'next_task_due_at_utc')!==false&&strpos($kanban,'WorkspaceV2Inbox?.formatTaskDue')!==false&&strpos($kanban,"'Просрочено'")!==false&&strpos($kanban,"'Сегодня'")!==false&&strpos($css,'.kanbanTask.overdue')!==false&&strpos($css,'.kanbanTask.today')!==false);
kbCheck('column headers summarize actionable attention signals',strpos($kanban,'function columnSummary')!==false&&strpos($kanban,'awaiting_first_reply')!==false&&strpos($kanban,'next_task_overdue')!==false&&strpos($kanban,'unread_count')!==false&&strpos($kanban,'без ответа')!==false&&strpos($kanban,'просроч.')!==false&&strpos($kanban,'непрочит.')!==false&&strpos($kanban,'function columnHeader')!==false&&strpos($css,'.kanbanColumnTitle small')!==false);
kbCheck('editable cards expose direct quick task creation',strpos($kanban,'kanbanQuickTaskToggle')!==false&&strpos($kanban,'kanbanTaskTitle')!==false&&strpos($kanban,'kanbanTaskDue')!==false&&strpos($kanban,"pipe('create_task'")!==false);
kbCheck('quick task reuses canonical backend task owner and authorization',strpos($api,"if(\$action==='create_task'")!==false&&strpos($api,'LeadTaskService::create')!==false&&strpos($api,'ManagerHttp::requireConversationEdit($c,$m);')!==false);
kbCheck('quick task is hidden from read-only cards',strpos($kanban,"if(!c.can_edit_pipeline)return''")!==false&&strpos($kanban,'function quickTaskControl')!==false);
kbCheck('quick task has explicit save error and true reentry guard',strpos($kanban,"if(form.dataset.saving==='1')return")!==false&&strpos($kanban,"form.dataset.saving='1'")!==false&&strpos($kanban,"form.dataset.saving='0'")!==false&&strpos($kanban,"save.disabled=true")!==false&&strpos($kanban,"error.textContent='Не удалось сохранить задачу'")!==false&&strpos($kanban,"if(!title)")!==false);
kbCheck('quick task keyboard behavior is deliberate',strpos($kanban,"e.key==='Enter'")!==false&&strpos($kanban,"e.key==='Escape'")!==false&&strpos($kanban,"aria-expanded")!==false);
kbCheck('card open remains separate from stage and task controls',strpos($kanban,'kanbanOpen')!==false&&strpos($kanban,"setMode('list')")!==false&&strpos($kanban,'WorkspaceV2Conversation?.open')!==false&&strpos($kanban,'quickTaskControl(c)')!==false);
kbCheck('kanban controls remain responsive and keyboard-visible',strpos($css,'.kanbanStageControl')!==false&&strpos($css,'.kanbanStageSelect:focus')!==false&&strpos($css,'.kanbanQuickTaskToggle:focus-visible')!==false&&strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'82vw')!==false&&strpos($css,'min-height:44px')!==false);
kbCheck('pipeline API does not couple technical status to business stage',strpos($api,"'technical_status'=>\$c['status']")!==false&&strpos($api,'UPDATE conversations SET status')===false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);