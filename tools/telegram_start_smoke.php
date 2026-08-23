<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$siteRoot = dirname(dirname(__DIR__));
$prolog = $siteRoot . '/bitrix/modules/main/include/prolog_before.php';
if (!is_file($prolog)) {
    fwrite(STDERR, "BITRIX PROLOG NOT FOUND: {$prolog}\n");
    exit(2);
}
require_once $prolog;
require_once dirname(__DIR__) . '/maxsearchclass.php';
require_once dirname(__DIR__) . '/handlers/TelegramWebhookHandler.php';

$sent = [];
$messenger = new TelegramMessengerAdapter(static function (string $method, array $payload) use (&$sent): bool {
    $sent[] = ['method'=>$method,'payload'=>$payload];
    return true;
});

$chatId = 990000777;
try {
    MaxSearchApi::deleteAllStatus($chatId);
    $update = [
        'update_id'=>990000771,
        'message'=>[
            'message_id'=>990000772,
            'from'=>['id'=>$chatId,'is_bot'=>false,'first_name'=>'Smoke'],
            'chat'=>['id'=>$chatId,'type'=>'private','first_name'=>'Smoke'],
            'date'=>time(),
            'text'=>'/start',
        ],
    ];
    $handled = TelegramWebhookHandler::dispatchUpdate($update, null, $messenger);
    $first = $sent[0]['payload'] ?? [];
    $text = (string)($first['text'] ?? '');
    $markup = (string)($first['reply_markup'] ?? '');

    $checks = [
        'real controller handled /start'=>$handled === true,
        'start produced Telegram sendMessage'=>($sent[0]['method'] ?? '') === 'sendMessage',
        'start text present'=>strpos($text,'Давайте найдём ваш отдых') !== false,
        'start buttons present'=>strpos($markup,'ai_start') !== false && strpos($markup,'start_search') !== false,
    ];
    $failed = 0;
    foreach ($checks as $label=>$ok) {
        echo ($ok?'PASS  ':'FAIL  ') . $label . PHP_EOL;
        if (!$ok) $failed++;
    }
    echo PHP_EOL . ($failed===0?'TELEGRAM START SMOKE: OK':"TELEGRAM START SMOKE: FAILED ({$failed})") . PHP_EOL;
    exit($failed===0?0:1);
} finally {
    try { MaxSearchApi::deleteAllStatus($chatId); } catch (Throwable $e) {}
}
