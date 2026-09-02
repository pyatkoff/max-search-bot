<?php

declare(strict_types=1);

require_once __DIR__ . '/../integrations/MaxWebhookHealth.php';

$expected = 'https://app.anytoour.ru/webhook.php';
$legacy = 'https://app.anytoour.ru/webhook.php';

$result = MaxWebhookHealth::evaluate([
    'http' => 200,
    'errno' => 0,
    'body' => json_encode(['subscriptions' => [
        ['url' => $expected],
        ['url' => $legacy],
    ]]),
], $expected);

if (($result['ok'] ?? false) !== true || ($result['reason'] ?? '') !== 'healthy_cutover_dual') {
    fwrite(STDERR, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

echo "MAX_DUAL_CUTOVER_CONTRACT=OK\n";
