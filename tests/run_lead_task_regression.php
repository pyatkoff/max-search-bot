<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/LeadTaskService.php';
require_once dirname(__DIR__).'/services/ManagerLeadInboxService.php';
$passed=0;$failed=0;function ltCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$input=LeadTaskService::normalizeCreateInput('  Перезвонить   туристу  ','2026-08-27T18:30:00+02:00');
ltCheck('task title is normalized',!empty($input['ok'])&&($input['title']??'')==='Перезвонить туристу');
ltCheck('task due time is normalized to UTC',($input['due_at_utc']??'')==='2026-08-27 16:30:00');
ltCheck('empty task is rejected',LeadTaskService::normalizeCreateInput('   ',null)['error']==='invalid_title');
ltCheck('invalid task date is rejected',LeadTaskService::normalizeCreateInput('Позвонить','not-a-date')['error']==='invalid_due_at');
ltCheck('task without due date is allowed',LeadTaskService::normalizeCreateInput('Уточнить паспортные данные',null)['due_at_utc']===null);
ltCheck('unicode title limit is character based',!empty(LeadTaskService::normalizeCreateInput(str_repeat('я',255),null)['ok'])&&LeadTaskService::normalizeCreateInput(str_repeat('я',256),null)['error']==='invalid_title');

$now=new DateTimeImmutable('2026-08-28 01:30:00',new DateTimeZone('UTC'));
ltCheck('LeadTaskService owns Kaliningrad due-today classification',LeadTaskService::dueState('2026-08-28 18:00:00',false,$now)==='today');
ltCheck('future Kaliningrad task stays upcoming',LeadTaskService::dueState('2026-08-29 00:30:00',false,$now)==='upcoming');
ltCheck('overdue task wins over calendar-day classification',LeadTaskService::dueState('2026-08-28 00:15:00',true,$now)==='overdue');
ltCheck('task without deadline is unscheduled',LeadTaskService::dueState(null,false,$now)==='unscheduled');
ltCheck('inbox compatibility projection delegates urgency semantics',ManagerLeadInboxService::taskDueState('2026-08-28 18:00:00',false,$now)===LeadTaskService::dueState('2026-08-28 18:00:00',false,$now));
$actionRows=ManagerLeadInboxService::filter([
    ['id'=>1,'lead_outcome'=>'open','next_task_title'=>'Сегодня','next_task_due_state'=>'today'],
    ['id'=>2,'lead_outcome'=>'open','next_task_title'=>'Просрочено','next_task_due_state'=>'overdue','next_task_overdue'=>1],
    ['id'=>3,'lead_outcome'=>'open','next_task_title'=>'Позже','next_task_due_state'=>'upcoming'],
    ['id'=>4,'lead_outcome'=>'open','next_task_title'=>null,'next_task_due_state'=>'unscheduled'],
],'','','action');
ltCheck('action-required filter means due today or overdue only',array_column($actionRows,'id')===[1,2]);

$root=dirname(__DIR__);$migration=(string)file_get_contents($root.'/migrations/014_lead_tasks.sql');$pinMigration=(string)file_get_contents($root.'/migrations/016_lead_task_pinning.sql');$api=(string)file_get_contents($root.'/manager/pipeline-api.php');$http=(string)file_get_contents($root.'/manager/lib/ManagerHttp.php');$shell=(string)file_get_contents($root.'/manager/index.php');$leadCardJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');$taskService=(string)file_get_contents($root.'/services/LeadTaskService.php');$taskJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.js');$taskCss=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.css');$inboxService=(string)file_get_contents($root.'/services/ManagerLeadInboxService.php');$inboxJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');$inboxCss=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.css');
ltCheck('migration is forward-only and indexed',strpos($migration,'CREATE TABLE IF NOT EXISTS lead_tasks')!==false&&strpos($migration,'idx_lead_tasks_due')!==false&&strpos($migration,'due_at_utc')!==false&&stripos($migration,'DROP TABLE')===false);
ltCheck('task pinning migration is forward-only and indexed',strpos($pinMigration,'ADD COLUMN is_pinned')!==false&&strpos($pinMigration,'idx_lead_tasks_pinned')!==false&&stripos($pinMigration,'DROP ')===false);
ltCheck('tasks remain separate from technical conversation state',strpos($migration,'conversation_status')===false&&strpos($pinMigration,'conversation_status')===false&&strpos($api,'UPDATE conversations SET status')===false);
ltCheck('task API reuses conversation visibility and shared pipeline edit guard',strpos($api,'pipelineConversation($id')!==false&&strpos($api,"\$action==='create_task'")!==false&&strpos($api,"\$action==='update_task'")!==false&&strpos($api,"\$action==='set_task_completed'")!==false&&strpos($api,"\$action==='set_task_pinned'")!==false&&substr_count($api,'ManagerHttp::requireConversationEdit($c,$m);')>=7&&strpos($http,'ManagerRequestContext::canEditAssignedConversation')!==false&&strpos($http,"'error'=>'forbidden'")!==false);
ltCheck('task detail is read-only visible data',strpos($api,"'tasks'=>LeadTaskService::listForConversation(\$id)")!==false);
ltCheck('workspace loads dedicated task module and styles',strpos($shell,'workspace-v2-tasks.js')!==false&&strpos($shell,'workspace-v2-tasks.css')!==false);
ltCheck('lead card delegates task rendering and mutations to task module',strpos($leadCardJs,'WorkspaceV2Tasks.render')!==false&&strpos($leadCardJs,"pipe('create_task'")!==false&&strpos($leadCardJs,"pipe('update_task'")!==false&&strpos($leadCardJs,"pipe('set_task_completed'")!==false&&strpos($leadCardJs,"pipe('set_task_pinned'")!==false&&strpos($leadCardJs,'onUpdate:updateTask')!==false&&strpos($leadCardJs,'onPin:pinTask')!==false);
ltCheck('task module renders open done create and due controls',strpos($taskJs,'Задачи и напоминания')!==false&&strpos($taskJs,'datetime-local')!==false&&strpos($taskJs,'Выполнено:')!==false&&strpos($taskCss,'.taskCreate')!==false&&strpos($taskCss,'.taskRow.done')!==false);
ltCheck('task creation uses inline accessible status instead of blocking validation alert',strpos($taskJs,'id="leadTaskCreateStatus"')!==false&&strpos($taskJs,'aria-live="polite"')!==false&&strpos($taskJs,"setStatus('Введите название задачи','error')")!==false&&strpos($taskJs,"alert('Введите задачу')")===false);
ltCheck('task creation guards duplicate submit and exposes progress',strpos($taskJs,'if(creating)return')!==false&&strpos($taskJs,"add.textContent='Добавляем…'")!==false&&strpos($taskJs,"setStatus('Задача добавлена','success')")!==false&&strpos($taskJs,"setStatus('Не удалось добавить задачу','error')")!==false);
ltCheck('task creation supports explicit keyboard add',strpos($taskJs,"e.key==='Enter'")!==false&&strpos($taskJs,'e.preventDefault();submit()')!==false);
ltCheck('lead card returns task create success to task UI',strpos($leadCardJs,'if(!j.ok)return false')!==false&&strpos($leadCardJs,'return true')!==false);
ltCheck('task create status has success and error presentation',strpos($taskCss,'.taskCreateStatus.success')!==false&&strpos($taskCss,'.taskCreateStatus.error')!==false);
ltCheck('LeadTaskService is the single owner of Kaliningrad task urgency',strpos($taskService,'Europe/Kaliningrad')!==false&&strpos($taskService,'function dueState')!==false&&strpos($inboxService,'return LeadTaskService::dueState')!==false&&strpos($inboxService,'Europe/Kaliningrad')===false);
ltCheck('task detail exposes canonical urgency without client-side date rules',strpos($taskService,"\$row['due_state']=self::dueState")!==false&&strpos($taskService,' AS overdue')!==false&&strpos($taskJs,'due_state')!==false&&strpos($taskJs,"new Date(localValue)")!==false&&strpos($taskJs,'Europe/Kaliningrad')===false);
ltCheck('lead card renders visible urgency badges for overdue today and planned work',strpos($taskJs,"overdue:'Просрочено'")!==false&&strpos($taskJs,"today:'Сегодня'")!==false&&strpos($taskJs,"upcoming:'Запланировано'")!==false&&strpos($taskCss,'.taskUrgency.overdue')!==false&&strpos($taskCss,'.taskUrgency.today')!==false&&strpos($taskCss,'.taskRow.due-overdue')!==false);
ltCheck('LeadTaskService owns pinned task persistence and priority ordering',strpos($taskService,'function setPinned')!==false&&strpos($taskService,"status='open' AND is_pinned=?")!==false&&strpos($taskService,'function openTaskOrderSql')!==false&&strpos($taskService,'is_pinned=1')!==false&&strpos($taskService,"\$order=self::openTaskOrderSql('t')")!==false&&strpos($taskService,"'open',0")!==false);
ltCheck('repeated pin mutations are idempotent for an existing open task',strpos($taskService,'if($q->rowCount()>0)return true')!==false&&strpos($taskService,'SELECT 1 FROM lead_tasks')!==false&&strpos($taskService,'is_pinned=? LIMIT 1')!==false);
ltCheck('task pinning stays role-gated and outside technical status',strpos($api,"\$action==='set_task_pinned'")!==false&&strpos($api,'LeadTaskService::setPinned')!==false&&strpos($api,'ManagerHttp::requireConversationEdit($c,$m);')!==false&&strpos($api,'UPDATE conversations SET status')===false);
ltCheck('task module exposes explicit accessible pinned priority control',strpos($taskJs,'task.is_pinned')!==false&&strpos($taskJs,'data-task-pin')!==false&&strpos($taskJs,'В приоритете')!==false&&strpos($taskJs,'aria-label=')!==false&&strpos($taskCss,'.taskRow.pinned')!==false&&strpos($taskCss,'.taskPinBtn')!==false);
ltCheck('open lead tasks can be edited without changing technical state',strpos($taskService,'function update(')!==false&&strpos($taskService,"status='open'")!==false&&strpos($taskService,'SET title=?')!==false&&strpos($taskService,'due_at_utc=?')!==false&&strpos($taskService,'UPDATE conversations SET status')===false);
ltCheck('repeated identical task edits are idempotent',strpos($taskService,'SELECT title,due_at_utc FROM lead_tasks')!==false&&strpos($taskService,"['ok'=>true]")!==false&&strpos($taskService,"'error'=>'update_failed'")!==false);
ltCheck('task editing stays role gated through shared conversation authorization',strpos($api,"\$action==='update_task'")!==false&&strpos($api,'LeadTaskService::update')!==false&&substr_count($api,'ManagerHttp::requireConversationEdit($c,$m);')>=7);
ltCheck('task module provides inline edit title deadline save and cancel',strpos($taskJs,'data-task-edit=')!==false&&strpos($taskJs,'data-task-edit-title')!==false&&strpos($taskJs,'data-task-edit-due')!==false&&strpos($taskJs,'data-task-edit-save')!==false&&strpos($taskJs,'data-task-edit-cancel')!==false&&strpos($taskJs,"prompt(")===false);
ltCheck('task edit preserves input on failure and exposes accessible status',strpos($taskJs,"setEditStatus('Сохраняем…')")!==false&&strpos($taskJs,"setEditStatus('Не удалось сохранить задачу','error')")!==false&&strpos($taskJs,'class="taskEditStatus" role="status" aria-live="polite"')!==false);
ltCheck('task edit is responsive in dedicated task stylesheet',strpos($taskCss,'.taskEditForm')!==false&&strpos($taskCss,'.taskEditButtons')!==false&&strpos($taskCss,'.taskEditStatus.error')!==false&&strpos($taskCss,'@media(max-width:520px)')!==false);
ltCheck('V2 inbox batch-projects one next open task without N+1',strpos($inboxService,"FROM lead_tasks t WHERE t.conversation_id IN ({\$in}) AND t.status='open'")!==false&&strpos($inboxService,"array_key_exists(\$id,\$tasks)")!==false&&substr_count($inboxService,'lead_tasks')===1);
ltCheck('V2 inbox exposes overdue task signal and due time',strpos($inboxService,'next_task_title')!==false&&strpos($inboxService,'next_task_due_at_utc')!==false&&strpos($inboxService,'next_task_overdue')!==false&&strpos($inboxService,'UTC_TIMESTAMP()')!==false);
ltCheck('V2 inbox action-required is deadline-derived through canonical task owner',strpos($inboxService,"'action'")!==false&&strpos($inboxService,'LeadTaskService::dueState')!==false&&strpos($inboxService,"['overdue','today']")!==false&&strpos($shell,'Нужно действие')!==false);
ltCheck('V2 inbox renders task title due and urgency states',strpos($inboxJs,'c.next_task_title')!==false&&strpos($inboxJs,'c.next_task_due_at_utc')!==false&&strpos($inboxJs,'c.next_task_overdue')!==false&&strpos($inboxJs,'c.next_task_due_state')!==false&&strpos($inboxJs,'leadTaskCompact')!==false&&strpos($inboxCss,'.leadTaskCompact.today')!==false&&strpos($inboxCss,'.leadTaskCompact.overdue')!==false);
ltCheck('V2 inbox search includes task title',strpos($inboxService,"\$row['next_task_title']??''")!==false);
ltCheck('task signal stays read-only in inbox',strpos($inboxJs,"pipe('create_task'")===false&&strpos($inboxJs,"pipe('update_task'")===false&&strpos($inboxJs,"pipe('set_task_completed'")===false&&strpos($inboxJs,"pipe('set_task_pinned'")===false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
