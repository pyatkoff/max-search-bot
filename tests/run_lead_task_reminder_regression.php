<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/LeadTaskReminderService.php';
$passed=0;$failed=0;function ltrCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
$root=dirname(__DIR__);
$migration=(string)file_get_contents($root.'/migrations/017_lead_task_reminders.sql');
$service=(string)file_get_contents($root.'/services/LeadTaskReminderService.php');
$tasks=(string)file_get_contents($root.'/services/LeadTaskService.php');
$push=(string)file_get_contents($root.'/services/ManagerPushService.php');
$cron=(string)file_get_contents($root.'/cron_followup.php');

ltrCheck('reminder retry window is intentionally bounded',LeadTaskReminderService::retrySeconds()===1800);
ltrCheck('reminder migration is forward-only and due-indexed',strpos($migration,'reminder_attempted_at_utc')!==false&&strpos($migration,'reminder_notified_at_utc')!==false&&strpos($migration,'idx_lead_tasks_reminder_due')!==false&&stripos($migration,'DROP ')===false);
ltrCheck('reminder application owner selects only due open unnotified tasks',strpos($service,"t.status='open'")!==false&&strpos($service,'t.due_at_utc<=?')!==false&&strpos($service,'t.reminder_notified_at_utc IS NULL')!==false&&strpos($service,'reminder_attempted_at_utc<=?')!==false);
ltrCheck('reminder claim is atomic before outbound delivery',strpos($service,'UPDATE lead_tasks SET reminder_attempted_at_utc=?')!==false&&strpos($service,'rowCount()!==1')!==false&&strpos($service,'$notify($managerId,$conversationId,$title,$body)')!==false);
ltrCheck('successful delivery is the only path that marks task notified',strpos($service,"(int)(\$delivery['delivered']??0)>0")!==false&&strpos($service,'SET reminder_notified_at_utc=?')!==false);
ltrCheck('task reminders stay independent from technical conversation state and shifts',strpos($service,"c.status")===false&&strpos($service,'is_working')===false&&strpos($service,'UPDATE conversations')===false);
ltrCheck('targeted push is explicitly manager-owned and does not use routing bonuses',strpos($push,'function notifyManager')!==false&&strpos($push,"WHERE manager_id=?")!==false&&strpos($push,"notification_kind'=>'lead_task_reminder'")!==false);
ltrCheck('push sender reports actual web-push success to reminder owner',strpos($push,'private static function send(array $sub,string $payload,int $conversationId,string $dispatchId): bool')!==false&&strpos($push,'return true;')!==false&&strpos($push,'return false;')!==false);
ltrCheck('deadline edits reset stale reminder delivery state',strpos($tasks,'reminder_attempted_at_utc=IF(NOT(due_at_utc<=>?)')!==false&&strpos($tasks,'reminder_notified_at_utc=IF(NOT(due_at_utc<=>?)')!==false);
ltrCheck('reopening a completed task makes it reminder-eligible again',strpos($tasks,"$reminderReset=$completed?'':' ,reminder_attempted_at_utc=NULL,reminder_notified_at_utc=NULL'")!==false);
ltrCheck('cron owner runs reminder dispatcher after existing phone fallback',strpos($cron,'LeadTaskReminderService::runDue()')!==false&&strpos($cron,'LEAD_TASK_REMINDERS')!==false&&strpos($cron,'task_reminders_notified=')!==false&&strpos($cron,'ManagerPhoneFallbackService::runDue($now)')<strpos($cron,'LeadTaskReminderService::runDue()'));

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
