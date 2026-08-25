<?php
require_once dirname(__DIR__).'/services/ManagerResponseHealth.php';

$passed=0;$failed=0;
function mrhCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;}else{echo "FAIL  {$name}\n";$failed++;}}

$rows=[
    ['conversation_id'=>1,'project_key'=>'anytour','channel'=>'max','status'=>'waiting_manager','manager_id'=>null,'manager_request_at'=>'2026-08-25 10:00:00','wait_seconds'=>45,'first_reply_at'=>null],
    ['conversation_id'=>2,'project_key'=>'anytour','channel'=>'max','status'=>'manager','manager_id'=>4,'manager_request_at'=>'2026-08-25 10:00:00','wait_seconds'=>120,'first_reply_at'=>null],
    ['conversation_id'=>3,'project_key'=>'anytour','channel'=>'max','status'=>'manager','manager_id'=>5,'manager_request_at'=>'2026-08-25 10:00:00','wait_seconds'=>900,'first_reply_at'=>null],
    ['conversation_id'=>4,'project_key'=>'anytour','channel'=>'max','status'=>'manager','manager_id'=>4,'manager_request_at'=>'2026-08-25 10:00:00','wait_seconds'=>1200,'first_reply_at'=>'2026-08-25 10:01:00'],
    ['conversation_id'=>5,'project_key'=>'anytour','channel'=>'max','status'=>'manager','manager_id'=>4,'manager_request_at'=>'2026-08-25 10:00:00','wait_seconds'=>1800,'first_reply_at'=>null,'delivery_suspended'=>true],
];
$health=ManagerResponseHealth::evaluate($rows,90,600);
mrhCheck('pending unanswered requests counted',($health['pending_count']??null)===3);
mrhCheck('overdue includes warning and stuck',($health['overdue_count']??null)===2);
mrhCheck('stuck request makes health unhealthy',($health['stuck_count']??null)===1 && ($health['ok']??true)===false);
mrhCheck('answered request excluded',!in_array(4,array_column($health['requests'],'conversation_id'),true));
mrhCheck('suspended recipient excluded',!in_array(5,array_column($health['requests'],'conversation_id'),true));
mrhCheck('oldest active wait retained',($health['oldest_wait_seconds']??null)===900);
mrhCheck('requests sorted by urgency',($health['requests'][0]['conversation_id']??0)===3 && ($health['requests'][0]['severity']??'')==='stuck');

$healthy=ManagerResponseHealth::evaluate([
    ['conversation_id'=>9,'wait_seconds'=>89,'first_reply_at'=>null],
],90,600);
mrhCheck('sub-warning wait remains healthy',($healthy['ok']??false)===true && ($healthy['overdue_count']??-1)===0);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
