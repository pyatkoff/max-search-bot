<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/LiveSessionAnalyzer.php';
$passed=0;$failed=0;
function lsCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;}else{echo "FAIL  {$name}\n";$failed++;}}
$c=['id'=>10,'project_key'=>'anytour','channel'=>'max','status'=>'ai','started_at'=>'2026-08-24 20:00:00','last_message_at'=>'2026-08-24 20:10:00'];
$m=[
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_15.02.2027'],
 ['direction'=>'outbound','sender_type'=>'ai','text'=>'✅ Готово! Проверьте параметры'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_28.02.2027'],
 ['direction'=>'outbound','sender_type'=>'ai','text'=>'✅ Готово! Проверьте параметры'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_08.02.2027'],
 ['direction'=>'outbound','sender_type'=>'ai','text'=>'✅ Готово! Проверьте параметры'],
 ['direction'=>'inbound','sender_type'=>'customer','text'=>'show_tours'],
];
$r=LiveSessionAnalyzer::analyze($c,$m,[]);
lsCheck('detects completed needs',!empty($r['needs_collected']));
lsCheck('detects tours opened',!empty($r['tours_opened']));
lsCheck('detects rapid date reselection',in_array('rapid_date_reselection',$r['flags'],true));
lsCheck('drop point is tours opened',$r['drop_point']==='tours_opened');
$c['status']='waiting_manager';
$r=LiveSessionAnalyzer::analyze($c,$m,[]);
lsCheck('detects waiting manager request',!empty($r['manager_requested']));
lsCheck('waiting manager is drop point',$r['drop_point']==='manager_requested');
lsCheck('waiting manager without reply is flagged',in_array('manager_requested_no_reply',$r['flags'],true));
$c['status']='manager';$m[]=['direction'=>'outbound','sender_type'=>'manager','text'=>'Здравствуйте'];
$r=LiveSessionAnalyzer::analyze($c,$m,[]);
lsCheck('detects manager request',!empty($r['manager_requested']));
lsCheck('detects manager reply',!empty($r['manager_replied']));
lsCheck('manager reply clears no-reply flag',!in_array('manager_requested_no_reply',$r['flags'],true));
echo "\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
