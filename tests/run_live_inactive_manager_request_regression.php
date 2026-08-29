<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/LiveSessionAnalyzer.php';
$passed=0;$failed=0;
function imCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo"PASS  {$name}\n";$passed++;}else{echo"FAIL  {$name}\n";$failed++;}}
$c=['id'=>617,'project_key'=>'anytour','channel'=>'max','status'=>'ai','started_at'=>'2026-08-29 21:03:37','last_message_at'=>'2026-08-29 21:42:07'];
$m=[['direction'=>'inbound','sender_type'=>'customer','text'=>'show_tours','created_at'=>'2026-08-29 21:05:20']];
$e=[
 ['event_type'=>'waiting_manager','actor_type'=>'customer','created_at'=>'2026-08-29 21:42:07'],
 ['event_type'=>'ai_resumed','actor_type'=>'system','created_at'=>'2026-08-29 23:07:44'],
];
$r=LiveSessionAnalyzer::analyze($c,$m,$e,strtotime('2026-08-29 20:00:00'));
imCheck('historical manager request remains in funnel',!empty($r['manager_requested']));
imCheck('resumed AI conversation has no active manager wait',empty($r['manager_request_active']));
imCheck('inactive historical request has no still-unanswered bucket',($r['manager_response_bucket']??null)===null);
imCheck('inactive historical request has no no-reply anomaly',!in_array('manager_requested_no_reply',$r['flags'],true));
imCheck('inactive historical request has no left-queue anomaly',!in_array('left_waiting_queue_without_manager_reply',$r['flags'],true));
$c['status']='waiting_manager';
$r=LiveSessionAnalyzer::analyze($c,$m,[['event_type'=>'waiting_manager','actor_type'=>'customer','created_at'=>'2026-08-29 21:42:07']],strtotime('2026-08-29 20:00:00'));
imCheck('real waiting state remains active',!empty($r['manager_request_active']));
imCheck('real waiting state remains flagged',in_array('manager_requested_no_reply',$r['flags'],true));
imCheck('real waiting state retains unanswered bucket',($r['manager_response_bucket']??null)==='still_unanswered');
echo"\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
