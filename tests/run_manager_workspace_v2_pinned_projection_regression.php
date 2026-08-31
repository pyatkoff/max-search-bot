<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/LeadTaskService.php';
$root=dirname(__DIR__);
$taskService=(string)file_get_contents($root.'/services/LeadTaskService.php');
$inboxService=(string)file_get_contents($root.'/services/ManagerLeadInboxService.php');
$inboxJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$kanbanJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.js');
$kanbanTasksJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban-tasks.js');
$pipelineApi=(string)file_get_contents($root.'/manager/pipeline-api.php');
$inboxCss=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.css');
$kanbanCss=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban.css');
$passed=0;$failed=0;
function ppCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
$order=LeadTaskService::openTaskOrderSql('t');
ppCheck('canonical open task order prioritizes pinned work',strpos($order,'t.is_pinned=1')!==false&&strpos($order,'t.due_at_utc ASC')!==false&&strpos($order,'t.is_pinned=1')<strpos($order,'t.due_at_utc ASC'));
ppCheck('task detail list consumes canonical priority order',strpos($taskService,"\$order=self::openTaskOrderSql('t')")!==false&&strpos($taskService,'ORDER BY CASE WHEN t.status=\'open\' THEN 0 ELSE 1 END,{$order}')!==false);
ppCheck('inbox projection consumes same canonical priority order',strpos($inboxService,"LeadTaskService::openTaskOrderSql('t')")!==false&&strpos($inboxService,'ORDER BY t.conversation_id ASC,{$taskOrder}')!==false);
ppCheck('inbox batch projection includes pinned state',strpos($inboxService,'t.is_pinned')!==false&&strpos($inboxService,"next_task_pinned")!==false);
ppCheck('inbox visibly marks projected pinned task',strpos($inboxJs,'c.next_task_pinned')!==false&&strpos($inboxJs,"taskPinned?'📌'")!==false&&strpos($inboxCss,'.leadTaskCompact.pinned')!==false);
ppCheck('kanban task module visibly marks projected pinned task',strpos($kanbanTasksJs,'c.next_task_pinned')!==false&&strpos($kanbanTasksJs,'📌 В приоритете')!==false&&strpos($kanbanCss,'.kanbanTask.pinned')!==false);
ppCheck('kanban core delegates task presentation to dedicated module',strpos($kanbanJs,'WorkspaceV2KanbanTasks')!==false&&strpos($kanbanJs,'tasks?.signal(c)')!==false&&strpos($kanbanJs,'tasks?.controls(c)')!==false);
ppCheck('presentation requests pin mutation only through canonical manager API',strpos($inboxJs,"pipe('set_task_pinned'")===false&&strpos($kanbanJs,"pipe('set_task_pinned'")===false&&strpos($kanbanTasksJs,"pipe('set_task_pinned'")!==false&&strpos($kanbanTasksJs,'LeadTaskService::setPinned')===false&&strpos($kanbanTasksJs,'UPDATE lead_tasks')===false);
ppCheck('pipeline API retains authorization and delegates pin business rule',strpos($pipelineApi,"if(\$action==='set_task_pinned'")!==false&&strpos($pipelineApi,'ManagerHttp::requireConversationEdit($c,$m);')!==false&&strpos($pipelineApi,'LeadTaskService::setPinned')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
