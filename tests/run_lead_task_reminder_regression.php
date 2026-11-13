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
ltrCheck('task reminders stay independent from technical conversation state and shifts',strpos($service,'c.status')===false&&strpos($service,'is_working')===false&&strpos($service,'UPDATE conversations')===false);
ltrCheck('targeted push is explicitly manager-owned and does not use routing bonuses',strpos($push,'function notifyManager')!==false&&strpos($push,'ManagerPushConnectionService::activeSubscriptions($pdo,[$managerId])')!==false&&strpos($push,"notification_kind'=>'lead_task_reminder'")!==false);
ltrCheck('push sender reports actual web-push success to reminder owner',strpos($push,'private static function send(array $sub,string $payload,int $conversationId,string $dispatchId,array$conversation,bool$taskReminder=false): bool')!==false&&strpos($push,'return true;')!==false&&strpos($push,'return false;')!==false);
// Execute the canonical task owner: spelling IF versus CASE is not evidence of
// reminder behavior. The isolated fixture has the actual revision column.
require_once $root.'/services/LeadTaskService.php';
$connection=new ReflectionProperty(ConversationDb::class,'pdo');$previous=$connection->getValue();
try {
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>'2029-12-31 12:00:00');$connection->setValue(null,$pdo);
    $pdo->exec("CREATE TABLE lead_tasks(id INTEGER PRIMARY KEY,conversation_id INTEGER,title TEXT,due_at_utc TEXT,status TEXT,reminder_attempted_at_utc TEXT,reminder_notified_at_utc TEXT,business_revision INTEGER,updated_at TEXT);
INSERT INTO lead_tasks VALUES(1,10,'Follow up','2030-01-01 09:00:00','open','2029-12-31 07:00:00','2029-12-31 07:00:01',4,'2029-12-30 10:00:00');");
    $read=static fn():array=>$pdo->query('SELECT * FROM lead_tasks WHERE id=1')->fetch();$before=$read();
    $same=LeadTaskService::update(10,1,'  Follow up  ','2030-01-01T12:00:00+03:00');
    ltrCheck('identical task save preserves both reminder receipts revision and timestamp',!empty($same['ok'])&&$read()===$before);
    $title=LeadTaskService::update(10,1,'Follow up revised','2030-01-01T12:00:00+03:00');$renamed=$read();
    ltrCheck('title-only task edit keeps reminders for the unchanged deadline',!empty($title['ok'])&&$renamed['reminder_attempted_at_utc']===$before['reminder_attempted_at_utc']&&$renamed['reminder_notified_at_utc']===$before['reminder_notified_at_utc']&&$renamed['business_revision']===5);
    $changed=LeadTaskService::update(10,1,'Follow up revised','2030-01-02T12:00:00+03:00');$dated=$read();
    ltrCheck('deadline edits reset stale reminder delivery state',!empty($changed['ok'])&&$dated['due_at_utc']==='2030-01-02 09:00:00'&&$dated['reminder_attempted_at_utc']===null&&$dated['reminder_notified_at_utc']===null&&$dated['business_revision']===6);
    $pdo->exec("UPDATE lead_tasks SET reminder_attempted_at_utc='2030-01-01 07:00:00',reminder_notified_at_utc='2030-01-01 07:00:01' WHERE id=1");
    $cleared=LeadTaskService::update(10,1,'Follow up revised',null);$empty=$read();$repeat=LeadTaskService::update(10,1,'Follow up revised',null);
    ltrCheck('clearing a deadline resets receipts once and repeating it is a no-op',!empty($cleared['ok'])&&!empty($repeat['ok'])&&$empty['due_at_utc']===null&&$empty['reminder_attempted_at_utc']===null&&$empty['reminder_notified_at_utc']===null&&$empty['business_revision']===7&&$read()===$empty);
} finally {$connection->setValue(null,$previous);}
ltrCheck('reopening a completed task makes it reminder-eligible again',strpos($tasks,'reminderReset=')!==false&&strpos($tasks,'reminder_attempted_at_utc=NULL,reminder_notified_at_utc=NULL')!==false);
ltrCheck('cron owner runs reminder dispatcher after existing phone fallback',strpos($cron,'LeadTaskReminderService::runDue()')!==false&&strpos($cron,'LEAD_TASK_REMINDERS')!==false&&strpos($cron,'task_reminders_notified=')!==false&&strpos($cron,'ManagerPhoneFallbackService::runDue($now)')<strpos($cron,'LeadTaskReminderService::runDue()'));

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
