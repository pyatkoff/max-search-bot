<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/LiveSessionAnalyzer.php';

$conversation=[
    'id'=>543,
    'project_key'=>'anytour',
    'channel'=>'max',
    'status'=>'ai',
    'started_at'=>'2026-08-28 21:26:38',
    'last_message_at'=>'2026-08-28 21:28:56',
];
$messages=[
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'adults_2','created_at'=>'2026-08-28 21:27:34'],
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_click','created_at'=>'2026-08-28 21:28:18'],
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_click','created_at'=>'2026-08-28 21:28:20'],
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_click','created_at'=>'2026-08-28 21:28:30'],
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_click','created_at'=>'2026-08-28 21:28:30'],
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_change_09.2026','created_at'=>'2026-08-28 21:28:45'],
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_12.09.2026','created_at'=>'2026-08-28 21:28:56'],
];

$result=LiveSessionAnalyzer::analyze($conversation,$messages,[]);
$flags=(array)($result['flags']??[]);

$fail=[];
if(in_array('repeated_same_input',$flags,true))$fail[]='month_click must not be treated as repeated free text';
if(in_array('repeated_callback_input',$flags,true))$fail[]='passive calendar controls must not trigger repeated callback anomaly';
if(($result['anomaly_inbound_messages']??0)!==7)$fail[]='passive callbacks must remain visible in observed turn count';

if($fail){
    foreach($fail as $message)fwrite(STDERR,"FAIL {$message}\n");
    exit(1);
}

echo "PASS passive calendar callbacks do not create false anomaly flags\n";
