<?php

declare(strict_types=1);

require_once __DIR__ . '/../integrations/MaxWebhookHealth.php';

$expected = 'https://app.anytoour.ru/webhook.php';

$healthy = MaxWebhookHealth::evaluate([
    'http' => 200,
    'errno' => 0,
    'body' => json_encode(['subscriptions' => [
        ['url' => $expected],
    ]]),
], $expected);

if (($healthy['ok'] ?? false) !== true || ($healthy['reason'] ?? '') !== 'healthy' || ($healthy['subscription_count'] ?? 0) !== 1) {
    fwrite(STDERR, json_encode($healthy, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

$extra = MaxWebhookHealth::evaluate([
    'http' => 200,
    'errno' => 0,
    'body' => json_encode(['subscriptions' => [
        ['url' => $expected],
        ['url' => 'https://unexpected.invalid/webhook.php'],
    ]]),
], $expected);

if (($extra['ok'] ?? true) !== false || ($extra['reason'] ?? '') !== 'extra_subscriptions') {
    fwrite(STDERR, json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

echo "MAX_CANONICAL_WEBHOOK_CONTRACT=OK\n";
