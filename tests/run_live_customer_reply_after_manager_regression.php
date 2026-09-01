<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/LiveSessionAnalyzer.php';

$passed=0;$failed=0;
function crCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$conversation=['id'=>77,'project_key'=>'anytour','channel'=>'max','status'=>'manager','started_at'=>'2026-09-01 09:00:00','last_message_at'=>'2026-09-01 09:10:00'];
$events=[
 ['event_type'=>'waiting_manager','actor_type'=>'customer','created_at'=>'2026-09-01 09:01:00'],
 ['event_type'=>'manager_taken','actor_type'=>'manager','actor_id'=>'5','created_at'=>'2026-09-01 09:01:20'],
];
$messages=[
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'Нужен менеджер','created_at'=>'2026-09-01 09:00:50'],
 ['direction'=>'outbound','sender_type'=>'manager','text'=>'Здравствуйте! Чем помочь?','created_at'=>'2026-09-01 09:02:00'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'show_tours','created_at'=>'2026-09-01 09:02:10'],
];

$result=LiveSessionAnalyzer::analyze($conversation,$messages,$events);
crCheck('manager reply is detected',!empty($result['manager_replied']));
crCheck('callback after manager reply is not customer reply',empty($result['customer_replied_after_manager']));
crCheck('customer reply timestamp stays empty for callback-only activity',($result['customer_first_reply_after_manager_at']??null)===null);

$messages[]=['direction'=>'inbound','sender_type'=>'customer','text'=>'Да, хочу уточнить по отелю','created_at'=>'2026-09-01 09:02:35'];
$result=LiveSessionAnalyzer::analyze($conversation,$messages,$events);
crCheck('real tourist message after manager is detected',!empty($result['customer_replied_after_manager']));
crCheck('first tourist reply time is preserved',($result['customer_first_reply_after_manager_at']??'')==='2026-09-01 09:02:35');
crCheck('customer response delay is measured',($result['customer_reply_after_manager_seconds']??null)===35);
crCheck('funnel advances past manager reply',($result['drop_point']??'')==='customer_replied_after_manager');

$messages[]=['direction'=>'inbound','sender_type'=>'customer','text'=>'И ещё вопрос','created_at'=>'2026-09-01 09:03:10'];
$result=LiveSessionAnalyzer::analyze($conversation,$messages,$events);
crCheck('first customer reply remains canonical',($result['customer_first_reply_after_manager_at']??'')==='2026-09-01 09:02:35');

$tool=(string)file_get_contents(dirname(__DIR__).'/tools/live_session_snapshot.php');
crCheck('live summary exposes customer reply after manager',strpos($tool,"'customer_replied_after_manager'=>0")!==false);
crCheck('calendar-day summary exposes customer reply after manager',substr_count($tool,"'customer_replied_after_manager'=>0")>=2);
crCheck('calendar-day counter uses first customer reply timestamp',strpos($tool,"customer_first_reply_after_manager_at")!==false);

echo "\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
