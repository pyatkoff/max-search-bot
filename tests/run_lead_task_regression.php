<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/LeadTaskService.php';
$passed=0;$failed=0;function ltCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$input=LeadTaskService::normalizeCreateInput('  Перезвонить   туристу  ','2026-08-27T18:30:00+02:00');
ltCheck('task title is normalized',!empty($input['ok'])&&($input['title']??'')==='Перезвонить туристу');
ltCheck('task due time is normalized to UTC',($input['due_at_utc']??'')==='2026-08-27 16:30:00');
ltCheck('empty task is rejected',LeadTaskService::normalizeCreateInput('   ',null)['error']==='invalid_title');
ltCheck('invalid task date is rejected',LeadTaskService::normalizeCreateInput('Позвонить','not-a-date')['error']==='invalid_due_at');
ltCheck('task without due date is allowed',LeadTaskService::normalizeCreateInput('Уточнить паспортные данные',null)['due_at_utc']===null);
ltCheck('unicode title limit is character based',!empty(LeadTaskService::normalizeCreateInput(str_repeat('я',255),null)['ok'])&&LeadTaskService::normalizeCreateInput(str_repeat('я',256),null)['error']==='invalid_title');

$root=dirname(__DIR__);$migration=(string)file_get_contents($root.'/migrations/014_lead_tasks.sql');$api=(string)file_get_contents($root.'/manager/pipeline-api.php');$shell=(string)file_get_contents($root.'/manager/workspace-v2.php');$leadCardJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');$taskJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.js');$taskCss=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.css');
ltCheck('migration is forward-only and indexed',strpos($migration,'CREATE TABLE IF NOT EXISTS lead_tasks')!==false&&strpos($migration,'idx_lead_tasks_due')!==false&&strpos($migration,'due_at_utc')!==false&&stripos($migration,'DROP TABLE')===false);
ltCheck('tasks remain separate from technical conversation state',strpos($migration,'conversation_status')===false&&strpos($api,'UPDATE conversations SET status')===false);
ltCheck('task API reuses conversation visibility and shared pipeline edit guard',strpos($api,'pipelineConversation($id')!==false&&strpos($api,'ManagerRequestContext::canEditAssignedConversation($c,$m)')!==false&&strpos($api,"\$action==='create_task'")!==false&&strpos($api,"\$action==='set_task_completed'")!==false&&substr_count($api,"error'=>'forbidden")>=2);
ltCheck('task detail is read-only visible data',strpos($api,"'tasks'=>LeadTaskService::listForConversation(\$id)")!==false);
ltCheck('workspace loads dedicated task module and styles',strpos($shell,'workspace-v2-tasks.js')!==false&&strpos($shell,'workspace-v2-tasks.css')!==false);
ltCheck('lead card delegates task rendering and mutations to task module',strpos($leadCardJs,'WorkspaceV2Tasks.render')!==false&&strpos($leadCardJs,"pipe('create_task'")!==false&&strpos($leadCardJs,"pipe('set_task_completed'")!==false);
ltCheck('task module renders open done create and due controls',strpos($taskJs,'Задачи и напоминания')!==false&&strpos($taskJs,'datetime-local')!==false&&strpos($taskJs,'Выполнено:')!==false&&strpos($taskCss,'.taskCreate')!==false&&strpos($taskCss,'.taskRow.done')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
