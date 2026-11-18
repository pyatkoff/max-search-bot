<?php
declare(strict_types=1);
// Offline only: evaluate the sendPhoto fields, never the publisher or cURL call.
$path = $argv[1] ?? __DIR__.'/commercial-batch-template-20261102.php';
$source = file_get_contents($path);
if (!is_string($source) || !preg_match("~CURLOPT_POSTFIELDS=>(\\[.*?\\]),CURLOPT_CONNECTTIMEOUT~s", substr($source, strpos($source, "'/sendPhoto'")), $match)) {
    throw new RuntimeException('telegram_payload_not_found');
}
$t = ['channel'=>'-1000000000000'];
$row = ['text'=>"Hotel\nCaption"];
$captionEntities = [['type'=>'bold','offset'=>0,'length'=>5]];
$file = __FILE__;
$mime = 'image/png';
$fields = eval('return '.$match[1].';');
if (($fields['disable_notification'] ?? null) !== 'true') {
    throw new RuntimeException('telegram_silent_flag_missing');
}
if ($fields['chat_id'] !== $t['channel'] || $fields['caption'] !== $row['text'] ||
    json_decode($fields['caption_entities'], true) !== $captionEntities || !($fields['photo'] instanceof CURLFile)) {
    throw new RuntimeException('telegram_payload_changed');
}
echo "PASS: silent Telegram request payload; caption/entities/photo preserved; no provider calls\n";
