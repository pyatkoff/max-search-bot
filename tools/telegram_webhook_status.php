<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/services/TelegramWebhookHealth.php';

$primary=TelegramWebhookHealth::collect();
$legacyToken=defined('TG_SEARCH_TOKEN') ? trim((string)TG_SEARCH_TOKEN) : '';
$legacy=$legacyToken!=='' ? TelegramWebhookHealth::collectToken($legacyToken) : ['ok'=>false,'configured'=>false,'reason'=>'missing_token'];

$result=[
    'telegram_bot_token'=>$primary,
    'tg_search_token'=>$legacy,
    'identity_match'=>
        !empty($primary['configured']) && !empty($legacy['configured'])
        ? (($primary['bot']['id']??null)===($legacy['bot']['id']??null))
        : null,
];

echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
exit(!empty($primary['configured']) ? 0 : 2);
