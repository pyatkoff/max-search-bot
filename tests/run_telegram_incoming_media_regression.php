<?php
declare(strict_types=1);
require_once __DIR__.'/../integrations/TelegramIncomingAdapter.php';
$update=['update_id'=>90001,'message'=>[
    'message_id'=>123,'from'=>['id'=>777,'first_name'=>'Test'],'chat'=>['id'=>777],
    'forward_origin'=>['type'=>'hidden_user','sender_user_name'=>'Original sender','date'=>1700000000],
    'caption'=>'Этот вариант: Hotel Example, Beach Villa, всё включено',
    'photo'=>[
        ['file_id'=>'largest_photo','file_unique_id'=>'unique-large','width'=>1280,'height'=>960,'file_size'=>30000],
        ['file_id'=>'small_photo','width'=>90,'height'=>90,'file_size'=>1000],
    ],
]];
$incoming=TelegramIncomingAdapter::fromUpdate($update);
$failures=[];
if(($incoming['text']??'')!==$update['message']['caption'])$failures[]='forwarded photo caption lost';
if(($incoming['attachments'][0]['telegram_file_id']??'')!=='largest_photo')$failures[]='forwarded photo file lost';
if(($incoming['attachments'][0]['type']??'')!=='image')$failures[]='photo not renderable';
if(($incoming['user']['external_user_id']??null)!=777)$failures[]='forward origin replaced customer identity';
foreach($failures as $failure)echo "FAIL $failure\n";
if($failures)exit(1);
echo "PASS forwarded Telegram photo keeps content and customer identity\n";
