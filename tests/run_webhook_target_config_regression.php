<?php

declare(strict_types=1);

define('TELEGRAM_WEBHOOK_URL', 'https://app.anytoour.ru/telegram_webhook.php');
define('MAX_SEARCH_WEBHOOK_URL', 'https://app.anytoour.ru/webhook.php');

require_once __DIR__ . '/../services/WebhookTargetConfig.php';

$checks = [
    ['telegram override', WebhookTargetConfig::telegram(), 'https://app.anytoour.ru/telegram_webhook.php'],
    ['max override', WebhookTargetConfig::max(), 'https://app.anytoour.ru/webhook.php'],
];

foreach ($checks as [$name, $actual, $expected]) {
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$name}: expected {$expected}, got {$actual}\n");
        exit(1);
    }
}

$service = (string)file_get_contents(__DIR__ . '/../services/WebhookTargetConfig.php');
foreach ([
    "https://anytour.online/max-search/telegram_webhook.php",
    "https://anytour.online/max-search/webhook.php",
    "invalid_webhook_target:"
] as $needle) {
    if (!str_contains($service, $needle)) {
        fwrite(STDERR, "Missing compatibility/safety contract: {$needle}\n");
        exit(1);
    }
}

$telegramAdmin = (string)file_get_contents(__DIR__ . '/../telegram_webhook_admin.php');
$maxAdmin = (string)file_get_contents(__DIR__ . '/../repair_max_search_subscription.php');
if (!str_contains($telegramAdmin, 'WebhookTargetConfig::telegram()')) {
    fwrite(STDERR, "Telegram webhook admin bypasses target config\n");
    exit(1);
}
if (!str_contains($maxAdmin, 'WebhookTargetConfig::max()')) {
    fwrite(STDERR, "MAX subscription admin bypasses target config\n");
    exit(1);
}
if (str_contains($telegramAdmin, "\$webhookUrl = 'https://anytour.online")) {
    fwrite(STDERR, "Telegram webhook target remains hardcoded\n");
    exit(1);
}

$template = (string)file_get_contents(__DIR__ . '/../config.example.php');
foreach (['MAX_SEARCH_WEBHOOK_URL', 'TELEGRAM_WEBHOOK_URL'] as $constant) {
    if (!str_contains($template, $constant)) {
        fwrite(STDERR, "Missing config template constant: {$constant}\n");
        exit(1);
    }
}

echo "webhook target config regression OK\n";