<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$tmp=tempnam(sys_get_temp_dir(),'live-anomaly-');
$input=[
    'ok'=>true,
    'generated_at'=>'2026-08-27T18:01:24+00:00',
    'window_hours'=>1,
    'sessions'=>[
        [
            'conversation_id'=>390,
            'status'=>'ai',
            'needs_collected'=>true,
            'tours_opened'=>true,
            'drop_point'=>'tours_opened',
            'flags'=>['excessive_turns'],
            'last_message_at'=>'2026-08-27 17:31:00',
            'message_tail'=>[
                ['direction'=>'inbound','sender_type'=>'customer','text'=>'nights_6_8'],
                ['direction'=>'outbound','sender_type'=>'ai','text'=>'🌙 На сколько ночей хотите поехать?'],
                ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_change_09.2026'],
                ['direction'=>'outbound','sender_type'=>'ai','text'=>'📅 Когда хотите вылететь?'],
                ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_change_09.2026'],
                ['direction'=>'outbound','sender_type'=>'ai','text'=>'📅 Когда хотите вылететь?'],
            ],
        ],
        [
            'conversation_id'=>999,
            'status'=>'ai',
            'needs_collected'=>false,
            'tours_opened'=>false,
            'drop_point'=>'collecting_needs',
            'flags'=>['repeated_same_input'],
            'last_message_at'=>'2026-08-27 18:00:00',
            'message_tail'=>[
                ['direction'=>'inbound','sender_type'=>'customer','text'=>'7 ночей'],
                ['direction'=>'outbound','sender_type'=>'ai','text'=>'🌙 На сколько ночей хотите поехать?'],
                ['direction'=>'inbound','sender_type'=>'customer','text'=>'7 ночей'],
                ['direction'=>'outbound','sender_type'=>'ai','text'=>'🌙 На сколько ночей хотите поехать?'],
            ],
        ],
    ],
];
file_put_contents($tmp,json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$cmd='php '.escapeshellarg($root.'/tools/compose_live_anomalies.php').' '.escapeshellarg($tmp);
exec($cmd,$lines,$code);@unlink($tmp);
$data=json_decode(implode("\n",$lines),true);
$passed=0;$failed=0;
function lacCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
lacCheck('composer exits successfully',$code===0&&is_array($data)&&!empty($data['ok']));
$ids=array_map(static fn($a)=>(int)($a['conversation_id']??0),(array)($data['anomalies']??[]));
lacCheck('ordinary repeated callback/calendar render is not ranked',!in_array(390,$ids,true));
lacCheck('repeated free-text answer remains ranked',in_array(999,$ids,true));
$target=null;foreach((array)($data['anomalies']??[]) as $a)if((int)($a['conversation_id']??0)===999)$target=$a;
lacCheck('repeated prompt is context after independent signal',is_array($target)&&in_array('customer_repeated_answer',(array)$target['signals'],true)&&in_array('bot_repeated_question_nights',(array)$target['signals'],true));
lacCheck('summary counts match bounded anomaly list',(int)($data['summary']['ranked']??-1)===count((array)($data['anomalies']??[]))&&((int)($data['summary']['high']??0)+(int)($data['summary']['medium']??0))===count((array)($data['anomalies']??[])));

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
