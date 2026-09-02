<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$tasks=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.js');
$kanban=(string)file_get_contents($root.'/manager/assets/workspace-v2-kanban-tasks.js');
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');

$passed=0;$failed=0;
function closedTaskCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

closedTaskCheck('lead card task owner reads canonical sales outcome',strpos($tasks,"function currentLeadOutcome(){return String(workspace()?.S?.detail?.lead?.pipeline?.outcome?.outcome||'open')}")!==false);
closedTaskCheck('lead card only creates tasks for open editable leads',strpos($tasks,"canCreate=canEdit&&currentLeadOutcome()==='open'")!==false&&strpos($tasks,'${canCreate?\'<div class="taskCreate">')!==false);
closedTaskCheck('closed lead keeps existing task mutation controls',strpos($tasks,'open.map(t=>this.row(t,canEdit))')!==false&&strpos($tasks,'data-task-toggle')!==false&&strpos($tasks,'data-task-snooze')!==false&&strpos($tasks,'data-task-edit=')!==false&&strpos($tasks,'data-task-pin')!==false);
closedTaskCheck('closed lead explains why new task action is absent',strpos($tasks,'Для закрытого лида новую задачу не создаём')!==false);
closedTaskCheck('task creation binding is absent when create UI is absent',strpos($tasks,'if(canCreate){const add=root.querySelector(\'#leadTaskAdd\')')!==false);
closedTaskCheck('kanban and lead card use the same open outcome rule',strpos($kanban,"String(c.lead_outcome||'open')!=='open'")!==false&&strpos($tasks,"currentLeadOutcome()==='open'")!==false);
closedTaskCheck('task API derives current outcome from canonical pipeline owner',strpos($api,'function pipelineLeadOutcome(int $id):string')!==false&&strpos($api,'SalesPipelineService::outcomeForConversation($id)')!==false);
closedTaskCheck('task API rejects new task creation after lead close',strpos($api,"if(pipelineLeadOutcome(\$id)!=='open')ManagerHttp::respond(['ok'=>false,'error'=>'lead_closed'],409)")!==false);
closedTaskCheck('existing task cleanup remains allowed after lead close',strpos($api,"if(\$action==='update_task')")!==false&&strpos($api,"if(\$action==='set_task_completed')")!==false&&strpos($api,"if(\$action==='set_task_pinned')")!==false&&substr_count($api,"pipelineLeadOutcome(\$id)!=='open'")===1);
closedTaskCheck('no technical status or routing semantics introduced',strpos($tasks,"set_status")===false&&stripos($tasks,'routing')===false&&stripos($tasks,'is_working')===false&&strpos($api,'UPDATE conversations SET status')===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
