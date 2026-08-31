<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/LiveSessionAnalyzer.php';
$passed=0;$failed=0;
function lsCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;}else{echo "FAIL  {$name}\n";$failed++;}}
$c=['id'=>10,'project_key'=>'anytour','channel'=>'max','status'=>'ai','started_at'=>'2026-08-24 20:00:00','last_message_at'=>'2026-08-24 20:10:00'];
$m=[
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_15.02.2027','created_at'=>'2026-08-24 20:00:10'],
 ['direction'=>'outbound','sender_type'=>'ai','text'=>'✅ Готово! Проверьте параметры','created_at'=>'2026-08-24 20:00:11'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_28.02.2027','created_at'=>'2026-08-24 20:00:14'],
 ['direction'=>'outbound','sender_type'=>'ai','text'=>'✅ Готово! Проверьте параметры','created_at'=>'2026-08-24 20:00:15'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_08.02.2027','created_at'=>'2026-08-24 20:00:18'],
 ['direction'=>'outbound','sender_type'=>'ai','text'=>'✅ Готово! Проверьте параметры','created_at'=>'2026-08-24 20:00:19'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'show_tours','created_at'=>'2026-08-24 20:03:00'],
];
$r=LiveSessionAnalyzer::analyze($c,$m,[]);
lsCheck('detects completed needs',!empty($r['needs_collected']));
lsCheck('detects tours opened',!empty($r['tours_opened']));
lsCheck('detects truly rapid date reselection',in_array('rapid_date_reselection',$r['flags'],true));
lsCheck('drop point is tours opened',$r['drop_point']==='tours_opened');
lsCheck('ordinary short flow remains real-like',($r['traffic_class']??'')==='real_like');

$phoneMessages=[['direction'=>'inbound','sender_type'=>'customer','text'=>'79158966837','created_at'=>'2026-08-24 20:03:10']];
$phoneResult=LiveSessionAnalyzer::analyze($c,$phoneMessages,[]);
lsCheck('bare 7-prefixed Russian phone is detected',!empty($phoneResult['phone_received']));
$phoneMessages[0]['text']='+7 (915) 896-68-37';
$phoneResult=LiveSessionAnalyzer::analyze($c,$phoneMessages,[]);
lsCheck('formatted +7 Russian phone stays detected',!empty($phoneResult['phone_received']));
$phoneMessages[0]['text']='89158966837';
$phoneResult=LiveSessionAnalyzer::analyze($c,$phoneMessages,[]);
lsCheck('8-prefixed Russian phone stays detected',!empty($phoneResult['phone_received']));

$slow=$m;
$slow[0]['created_at']='2026-08-24 20:00:10';
$slow[2]['created_at']='2026-08-24 20:01:00';
$slow[4]['created_at']='2026-08-24 20:02:00';
$slowResult=LiveSessionAnalyzer::analyze($c,$slow,[]);
lsCheck('spaced date edits are not called rapid',!in_array('rapid_date_reselection',$slowResult['flags'],true));

$callbackMessages=[
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_country_1','created_at'=>'2026-08-24 20:00:01'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_country_1','created_at'=>'2026-08-24 20:00:02'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_country_1','created_at'=>'2026-08-24 20:00:03'],
];
$callbackResult=LiveSessionAnalyzer::analyze($c,$callbackMessages,[]);
lsCheck('repeated actionable callback input has dedicated flag',in_array('repeated_callback_input',$callbackResult['flags'],true));
lsCheck('repeated callback input is not mislabeled as user text',!in_array('repeated_same_input',$callbackResult['flags'],true));

$textMessages=[
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'Турция','created_at'=>'2026-08-24 20:00:01'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'Турция','created_at'=>'2026-08-24 20:00:20'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'Турция','created_at'=>'2026-08-24 20:00:40'],
];
$textResult=LiveSessionAnalyzer::analyze($c,$textMessages,[]);
lsCheck('repeated free text keeps context-loss triage flag',in_array('repeated_same_input',$textResult['flags'],true));
lsCheck('repeated free text is not callback noise',!in_array('repeated_callback_input',$textResult['flags'],true));

$normalWizard=[];
for($i=0;$i<14;$i++){
    $normalWizard[]=['direction'=>'inbound','sender_type'=>'customer','text'=>'step_'.$i,'created_at'=>sprintf('2026-08-24 20:10:%02d',$i*2)];
    $normalWizard[]=['direction'=>'outbound','sender_type'=>'ai','text'=>'Шаг '.($i+1),'created_at'=>sprintf('2026-08-24 20:10:%02d',$i*2+1)];
}
$normalWizardResult=LiveSessionAnalyzer::analyze($c,$normalWizard,[]);
lsCheck('normal guided flow is not excessive just because bot replies double message count',!in_array('excessive_turns',$normalWizardResult['flags'],true));
lsCheck('normal guided flow reports fourteen observed customer turns',($normalWizardResult['anomaly_inbound_messages']??0)===14);

$windowSince=strtotime('2026-08-24 20:00:00');
$oldHistory=[
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_change_11.2026','created_at'=>'2026-08-24 18:00:01'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_change_11.2026','created_at'=>'2026-08-24 18:00:02'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_change_11.2026','created_at'=>'2026-08-24 18:00:03'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'Турция','created_at'=>'2026-08-24 18:01:01'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'Турция','created_at'=>'2026-08-24 18:01:02'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'Турция','created_at'=>'2026-08-24 18:01:03'],
 ['direction'=>'outbound','sender_type'=>'ai','text'=>'✅ Готово! Проверьте параметры','created_at'=>'2026-08-24 18:02:00'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'show_tours','created_at'=>'2026-08-24 18:02:01'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'Спасибо','created_at'=>'2026-08-24 20:05:00'],
];
$windowResult=LiveSessionAnalyzer::analyze($c,$oldHistory,[],$windowSince);
lsCheck('historical funnel progress remains visible inside a later diagnostic window',!empty($windowResult['needs_collected'])&&!empty($windowResult['tours_opened']));
lsCheck('old repeated callbacks do not pollute current diagnostic window',!in_array('repeated_callback_input',$windowResult['flags'],true));
lsCheck('old repeated free text does not pollute current diagnostic window',!in_array('repeated_same_input',$windowResult['flags'],true));
lsCheck('window counts only recent customer turns',($windowResult['anomaly_inbound_messages']??0)===1);

$busyWindow=[];
for($i=0;$i<18;$i++)$busyWindow[]=['direction'=>'inbound','sender_type'=>'customer','text'=>'Ответ '.$i,'created_at'=>sprintf('2026-08-24 20:%02d:00',$i)];
$busyResult=LiveSessionAnalyzer::analyze($c,$busyWindow,[],$windowSince);
lsCheck('genuinely long current-window customer flow remains flagged',in_array('excessive_turns',$busyResult['flags'],true));
lsCheck('long free-text conversation is not assumed to be a test',($busyResult['traffic_class']??'')==='real_like');

$machineLike=[];
for($i=0;$i<20;$i++)$machineLike[]=['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_'.sprintf('%02d.09.2026',($i%20)+1),'created_at'=>sprintf('2026-08-24 20:20:%02d',$i)];
$machineResult=LiveSessionAnalyzer::analyze($c,$machineLike,[],strtotime('2026-08-24 20:20:00'));
lsCheck('callback-heavy high-turn session is only suspected test',($machineResult['traffic_class']??'')==='suspected_test');
lsCheck('suspected test classification explains its heuristic',in_array('callback_heavy_high_turn_session',(array)($machineResult['traffic_class_reasons']??[]),true));
lsCheck('suspected test exposes callback ratio',($machineResult['callback_ratio']??0)>=0.8);

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
