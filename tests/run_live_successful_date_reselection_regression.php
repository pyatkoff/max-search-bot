<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$tmp=tempnam(sys_get_temp_dir(),'live-date-');
$fixture=[
    'ok'=>true,
    'generated_at'=>'2026-08-29T06:07:11+00:00',
    'window_hours'=>1,
    'sessions'=>[[
        'conversation_id'=>557,
        'status'=>'ai',
        'needs_collected'=>true,
        'tours_opened'=>true,
        'drop_point'=>'tours_opened',
        'last_message_at'=>'2026-08-29 07:41:02',
        'flags'=>['rapid_date_reselection','repeated_callback_input'],
        'message_tail'=>[
            ['direction'=>'outbound','sender_type'=>'ai','text'=>'🌙 На сколько ночей хотите поехать?'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'nights_9_11'],
            ['direction'=>'outbound','sender_type'=>'ai','text'=>'📅 Когда хотите вылететь?'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_change_09.2026'],
            ['direction'=>'outbound','sender_type'=>'ai','text'=>'📅 Когда хотите вылететь?'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'month_change_10.2026'],
            ['direction'=>'outbound','sender_type'=>'ai','text'=>'📅 Когда хотите вылететь?'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_05.10.2026'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'pick_date_05.10.2026'],
            ['direction'=>'inbound','sender_type'=>'customer','text'=>'show_tours'],
            ['direction'=>'outbound','sender_type'=>'ai','text'=>'🔥 Подходящие туры готовы'],
        ],
    ]],
];
file_put_contents($tmp,json_encode($fixture,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/compose_live_anomalies.php').' '.escapeshellarg($tmp);
exec($cmd,$lines,$code);
@unlink($tmp);
$result=json_decode(implode("\n",$lines),true);
if($code!==0||!is_array($result)){fwrite(STDERR,"FAIL composer did not return JSON\n");exit(1);}
if((int)($result['summary']['ranked']??-1)!==0){fwrite(STDERR,"FAIL successful calendar reselection must not be ranked without independent failure evidence\n");exit(1);}
echo "PASS successful calendar reselection remains context-only after tours open\n";
