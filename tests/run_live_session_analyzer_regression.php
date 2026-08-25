<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/LiveSessionAnalyzer.php';
$passed=0;$failed=0;
function lsCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;}else{echo "FAIL  {$name}\n";$failed++;}}
$c=['id'=>10,'project_key'=>'anytour','channel'=>'max','status'=>'ai','started_at'=>'2026-08-24 20:00:00','last_message_at'=>'2026-08-24 20:10:00'];
$m=[
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_15.02.2027','created_at'=>'2026-08-24 20:00:10'],
 ['direction'=>'outbound','sender_type'=>'ai','text'=>'✅ Готово! Проверьте параметры','created_at'=>'2026-08-24 20:00:20'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_28.02.2027','created_at'=>'2026-08-24 20:01:00'],
 ['direction'=>'outbound','sender_type'=>'ai','text'=>'✅ Готово! Проверьте параметры','created_at'=>'2026-08-24 20:01:10'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_08.02.2027','created_at'=>'2026-08-24 20:02:00'],
 ['direction'=>'outbound','sender_type'=>'ai','text'=>'✅ Готово! Проверьте параметры','created_at'=>'2026-08-24 20:02:10'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'show_tours','created_at'=>'2026-08-24 20:03:00'],
];
$r=LiveSessionAnalyzer::analyze($c,$m,[]);
lsCheck('detects completed needs',!empty($r['needs_collected']));
lsCheck('detects tours opened',!empty($r['tours_opened']));
lsCheck('detects rapid date reselection',in_array('rapid_date_reselection',$r['flags'],true));
lsCheck('drop point is tours opened',$r['drop_point']==='tours_opened');
$c['status']='waiting_manager';
$events=[['event_type'=>'waiting_manager','actor_type'=>'customer','created_at'=>'2026-08-24 20:04:00']];
$r=LiveSessionAnalyzer::analyze($c,$m,$events);
lsCheck('detects waiting manager request',!empty($r['manager_requested']));
lsCheck('waiting manager is drop point',$r['drop_point']==='manager_requested');
lsCheck('waiting manager without reply is flagged',in_array('manager_requested_no_reply',$r['flags'],true));
lsCheck('waiting manager remains in queue flag is absent',!in_array('left_waiting_queue_without_manager_reply',$r['flags'],true));
lsCheck('unanswered request bucket',($r['manager_response_bucket']??'')==='still_unanswered');

$c['status']='manager';
$events[]=['event_type'=>'manager_taken','actor_type'=>'manager','actor_id'=>'4','created_at'=>'2026-08-24 20:04:20'];
$m[]=['direction'=>'outbound','sender_type'=>'manager','text'=>'Старое сообщение менеджера','created_at'=>'2026-08-24 19:55:00'];
$r=LiveSessionAnalyzer::analyze($c,$m,$events);
lsCheck('old manager message does not satisfy later request',empty($r['manager_replied']));
lsCheck('taken without reply is flagged',in_array('manager_taken_no_reply',$r['flags'],true));
lsCheck('left waiting queue without reply is flagged',in_array('left_waiting_queue_without_manager_reply',$r['flags'],true));

$m[]=['direction'=>'outbound','sender_type'=>'manager','text'=>'Здравствуйте','created_at'=>'2026-08-24 20:05:10'];
$r=LiveSessionAnalyzer::analyze($c,$m,$events);
lsCheck('detects manager reply after request',!empty($r['manager_replied']));
lsCheck('manager response seconds measured',($r['manager_response_seconds']??null)===70);
lsCheck('response within 90 seconds bucket',($r['manager_response_bucket']??'')==='answered_in_90s');
lsCheck('manager reply clears no-reply flag',!in_array('manager_requested_no_reply',$r['flags'],true));

$m[count($m)-1]['created_at']='2026-08-24 20:06:00';
$r=LiveSessionAnalyzer::analyze($c,$m,$events);
lsCheck('response after 90 seconds bucket',($r['manager_response_bucket']??'')==='answered_after_90s');

echo "\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
