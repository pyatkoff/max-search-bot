<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/WebhookTargetConfig.php';

$telegram = WebhookTargetConfig::telegram();
$max = WebhookTargetConfig::max();

echo 'TELEGRAM_WEBHOOK_TARGET=' . $telegram . PHP_EOL;
echo 'MAX_WEBHOOK_TARGET=' . $max . PHP_EOL;
echo 'TELEGRAM_WEBHOOK_TARGET_HOST=' . (string)(parse_url($telegram, PHP_URL_HOST) ?: '') . PHP_EOL;
echo 'MAX_WEBHOOK_TARGET_HOST=' . (string)(parse_url($max, PHP_URL_HOST) ?: '') . PHP_EOL;
