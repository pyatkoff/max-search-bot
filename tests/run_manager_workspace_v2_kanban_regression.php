<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$workspace=(string)file_get_contents($root.'/manager/index.php');
$core=(string)file_get_contents($root.'/manager/assets/workspace-v2.js');
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$kanban=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.js');
$tasks=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban-tasks.js');
$presets=(string)file_get_contents($root.'/manager/assets/workspace-v2-task-presets.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.css');
$taskCss=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.css');
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$http=(string)file_get_contents($root.'/manager/lib/ManagerHttp.php');
$projection=(string)file_get_contents($root.'/services/ManagerLeadInboxService.php');
$passed=0;$failed=0;
function kbCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
function kbAssetLoaded(string $html,string $file):bool{return strpos($html,"workspaceAsset('{$file}')")!==false||strpos($html,'assets/'.$file)!==false;}
kbCheck('workspace loads board task module before board core',kbAssetLoaded($workspace,'workspace-v2-kanban-tasks.js')&&kbAssetLoaded($workspace,'workspace-v2-kanban.js')&&strpos($workspace,"workspace-v2-kanban-tasks.js")<strpos($workspace,"workspace-v2-kanban.js"));
kbCheck('workspace loads dedicated kanban stylesheet',kbAssetLoaded($workspace,'workspace-v2-kanban.css'));
kbCheck('workspace exposes list and board toggle',strpos($workspace,'id="listViewBtn"')!==false&&strpos($workspace,'id="kanbanViewBtn"')!==false&&strpos($workspace,'id="kanbanBoard"')!==false);
kbCheck('kanban mode is explicit UI state',strpos($core,"viewMode:'list'")!==false&&strpos($kanban,"S.viewMode=mode")!==false&&strpos($kanban,"mode==='kanban'")!==false);
kbCheck('board reuses existing filtered lead list',strpos($inbox,'async function fetchRows()')!==false&&strpos($kanban,'WorkspaceV2Inbox')!==false&&strpos($inbox,"if(S.viewMode==='kanban')")!==false);
kbCheck('board groups only by business pipeline stage',strpos($kanban,'S.pipeline.stages')!==false&&strpos($kanban,'lead_stage?.stage_key')!==false&&strpos($kanban,'technical_status')===false&&strpos($kanban,'statusText(')===false);
kbCheck('editable cards expose explicit stage selector',strpos($kanban,'c.can_edit_pipeline')!==false&&strpos($kanban,'kanbanStageSelect')!==false&&strpos($kanban,"pipe('set_stage'")!==false);
kbCheck('stage mutation keeps centralized backend authorization',strpos($api,"if(\$action==='set_stage'")!==false&&strpos($api,'ManagerHttp::requireConversationEdit($c,$m);')!==false&&strpos($http,"'error'=>'forbidden'")!==false);
kbCheck('stage mutation keeps recovery and inline feedback',strpos($kanban,"select.value=previous;select.disabled=false")!==false&&strpos($kanban,"stageStatus(select,'Сохраняем…','saving')")!==false&&strpos($kanban,"showFeedback('Этап сохранён')")!==false&&strpos($css,'.kanbanStageStatus.error')!==false);
kbCheck('board card keeps lead context and delegates task UI',strpos($kanban,'origin_label')!==false&&strpos($kanban,'trip_summary')!==false&&strpos($kanban,'lead_tags')!==false&&strpos($kanban,'WorkspaceV2KanbanTasks')!==false&&strpos($kanban,'tasks?.signal(c)')!==false&&strpos($kanban,'tasks?.controls(c)')!==false);
kbCheck('lost cards surface canonical close reason catalog label',strpos($kanban,'function closeReasonText')!==false&&strpos($kanban,'S.pipeline.closeReasons')!==false&&strpos($kanban,"outcome==='lost'?closeReasonText(c.lead_close_reason):''")!==false&&strpos($kanban,'Причина отказа')!==false&&strpos($css,'.kanbanCloseReason')!==false);
kbCheck('task module renders canonical projected urgency',strpos($tasks,'next_task_due_state')!==false&&strpos($tasks,'next_task_overdue')!==false&&strpos($tasks,'next_task_due_at_utc')!==false&&strpos($tasks,'WorkspaceV2Inbox?.formatTaskDue')!==false&&strpos($tasks,"'Просрочено'")!==false&&strpos($tasks,"'Сегодня'")!==false&&strpos($css,'.kanbanTask.overdue')!==false&&strpos($css,'.kanbanTask.today')!==false);
kbCheck('lead projection exposes canonical next open task id',strpos($projection,'SELECT t.id,t.conversation_id,t.title')!==false&&strpos($projection,"\$row['next_task_id']=\$task['id']??null")!==false);
kbCheck('task module completes only projected next task',strpos($tasks,'function completionControl')!==false&&strpos($tasks,'c.next_task_id')!==false&&strpos($tasks,"if(!c.can_edit_pipeline||taskId<=0)return''")!==false&&strpos($tasks,'kanbanTaskCompleteBtn')!==false);
kbCheck('completion reuses canonical mutation endpoint',strpos($tasks,"pipe('set_task_completed',{conversation_id:id,task_id:taskId,completed:true})")!==false&&strpos($api,"if(\$action==='set_task_completed'")!==false&&strpos($api,'LeadTaskService::setCompleted')!==false);
kbCheck('task priority reuses canonical mutation endpoint',strpos($tasks,"pipe('set_task_pinned',{conversation_id:id,task_id:taskId,pinned:!pinned})")!==false&&strpos($api,"if(\$action==='set_task_pinned'")!==false&&strpos($api,'LeadTaskService::setPinned')!==false);
kbCheck('task mutations keep reentry and failure recovery',strpos($tasks,"if(button.dataset.saving==='1')return")!==false&&strpos($tasks,"button.dataset.saving='0';button.disabled=false")!==false&&strpos($tasks,"feedback('Задача не закрыта','error')")!==false&&strpos($tasks,"feedback('Приоритет не сохранён','error')")!==false);
kbCheck('task module exposes direct quick task creation',strpos($tasks,'kanbanQuickTaskToggle')!==false&&strpos($tasks,'kanbanTaskTitle')!==false&&strpos($tasks,'kanbanTaskDue')!==false&&strpos($tasks,"pipe('create_task'")!==false);
kbCheck('quick task reuses shared due preset owner',strpos($presets,'.kanbanQuickTaskForm')!==false&&strpos($presets,'.kanbanTaskDue')!==false&&strpos($kanban,'WorkspaceV2TaskPresets?.enhanceAll(root)')!==false&&strpos($tasks,'Сегодня 18:00')===false&&strpos($taskCss,'.taskDuePresets')!==false);
kbCheck('quick task reuses backend owner and authorization',strpos($api,"if(\$action==='create_task'")!==false&&strpos($api,'LeadTaskService::create')!==false&&strpos($api,'ManagerHttp::requireConversationEdit($c,$m);')!==false);
kbCheck('quick task keeps save guard error recovery and draft',strpos($tasks,"if(form.dataset.saving==='1')return")!==false&&strpos($tasks,"form.dataset.saving='1'")!==false&&strpos($tasks,"form.dataset.saving='0'")!==false&&strpos($tasks,"taskStatus(error,'Не удалось сохранить задачу','error')")!==false&&strpos($tasks,"form.querySelector('.kanbanTaskTitle').value=''")===false);
kbCheck('quick task keyboard behavior remains deliberate',strpos($tasks,"e.key==='Enter'")!==false&&strpos($tasks,"e.key==='Escape'")!==false&&strpos($tasks,'aria-expanded')!==false);
kbCheck('column headers retain actionable task workload and sales summaries',strpos($kanban,'function columnSummary')!==false&&strpos($kanban,'awaiting_first_reply')!==false&&strpos($kanban,"String(c.next_task_title||'').trim()!==''")!==false&&strpos($kanban,'`${tasks} задач`')!==false&&strpos($kanban,'next_task_overdue')!==false&&strpos($kanban,'unread_count')!==false&&strpos($kanban,'function columnSalesTotal')!==false&&strpos($kanban,'lead_sale_amount')!==false&&strpos($kanban,'Продажи ${sales}')!==false);
kbCheck('task module owns no stage grouping or task persistence SQL',strpos($tasks,'S.pipeline.stages')===false&&strpos($tasks,"pipe('set_stage'")===false&&strpos($tasks,'UPDATE lead_tasks')===false&&strpos($tasks,'LeadTaskService::')===false);
kbCheck('kanban core owns no task mutation requests after extraction',strpos($kanban,"pipe('set_task_completed'")===false&&strpos($kanban,"pipe('set_task_pinned'")===false&&strpos($kanban,"pipe('create_task'")===false);
kbCheck('kanban controls remain responsive and keyboard-visible',strpos($css,'.kanbanStageControl')!==false&&strpos($css,'.kanbanStageSelect:focus')!==false&&strpos($css,'.kanbanQuickTaskToggle:focus-visible')!==false&&strpos($css,'.kanbanTaskCompleteBtn:focus-visible')!==false&&strpos($css,'@media(max-width:900px)')!==false&&strpos($css,'min-height:44px')!==false);
kbCheck('pipeline API does not couple technical status to business stage',strpos($api,"'technical_status'=>\$c['status']")!==false&&strpos($api,'UPDATE conversations SET status')===false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
