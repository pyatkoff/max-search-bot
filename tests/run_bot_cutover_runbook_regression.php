<?php

declare(strict_types=1);

$path = __DIR__ . '/../docs/BOT_CUTOVER_RUNBOOK.md';
$text = (string)file_get_contents($path);

$required = [
    'Never run old and new bot live-processing concurrently.',
    'production bot writes are actually frozen',
    'Do not disable the Bitrix lead receiver.',
    'two production conversation snapshots',
    'SYNC_CONVERSATION_DB',
    'writes_frozen=true',
    'exact final data match',
    'Legacy-host independence is not required for bot cutover.',
    'one controlled lead reaching Bitrix through the existing bridge',
    'stop new-server bot processing before re-enabling the old bot processing',
];

foreach ($required as $needle) {
    if (!str_contains($text, $needle)) {
        fwrite(STDERR, "Missing cutover invariant: {$needle}\n");
        exit(1);
    }
}

if (str_contains($text, 'disable the Bitrix lead receiver.')) {
    if (substr_count($text, 'disable the Bitrix lead receiver.') !== 1 || !str_contains($text, 'Do not disable the Bitrix lead receiver.')) {
        fwrite(STDERR, "Runbook may disable intentional Bitrix lead receiver\n");
        exit(1);
    }
}

$service = (string)file_get_contents(__DIR__ . '/../services/WebhookTargetConfig.php');
$telegramAdmin = (string)file_get_contents(__DIR__ . '/../telegram_webhook_admin.php');
$maxAdmin = (string)file_get_contents(__DIR__ . '/../repair_max_search_subscription.php');
$template = (string)file_get_contents(__DIR__ . '/../config.example.php');

foreach ([
    'TELEGRAM_WEBHOOK_URL',
    'MAX_SEARCH_WEBHOOK_URL',
    'https://anytour.online/max-search/telegram_webhook.php',
    'https://anytour.online/max-search/webhook.php',
    'invalid_webhook_target:'
] as $needle) {
    if (!str_contains($service . $template, $needle)) {
        fwrite(STDERR, "Missing webhook cutover contract: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($telegramAdmin, 'WebhookTargetConfig::telegram()')) {
    fwrite(STDERR, "Telegram webhook admin bypasses configurable target\n");
    exit(1);
}
if (!str_contains($maxAdmin, 'WebhookTargetConfig::max()')) {
    fwrite(STDERR, "MAX subscription admin bypasses configurable target\n");
    exit(1);
}
if (str_contains($telegramAdmin, "\$webhookUrl = 'https://anytour.online")) {
    fwrite(STDERR, "Telegram webhook target remains hardcoded to legacy host\n");
    exit(1);
}

echo "bot cutover runbook contract OK\n";