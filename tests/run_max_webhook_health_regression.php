<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/MaxWebhookHealth.php';

function fail(string $message): void { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
function check(bool $condition, string $message): void { if (!$condition) fail($message); }

$expected = 'https://app.anytoour.ru/webhook.php';

$healthy = MaxWebhookHealth::evaluate([
    'http' => 200,
    'errno' => 0,
    'body' => json_encode(['subscriptions' => [['url' => $expected]]]),
], $expected);
check(($healthy['ok'] ?? false) === true, 'single expected subscription must be healthy');
check(($healthy['subscription_count'] ?? 0) === 1, 'healthy subscription count');
check(($healthy['reason'] ?? '') === 'healthy', 'healthy reason');

$duplicateOwner = MaxWebhookHealth::evaluate([
    'http' => 200,
    'errno' => 0,
    'body' => json_encode(['subscriptions' => [
        ['url' => $expected],
        ['url' => 'https://anytour.online/max-search/webhook.php'],
    ]]),
], $expected);
check(($duplicateOwner['ok'] ?? true) === false, 'multiple webhook owners must fail');
check(($duplicateOwner['reason'] ?? '') === 'extra_subscriptions', 'extra subscription reason');
check(count($duplicateOwner['extra_subscription_urls'] ?? []) === 1, 'extra subscription must be exposed');

$wrongOwner = MaxWebhookHealth::evaluate([
    'http' => 200,
    'errno' => 0,
    'body' => json_encode(['subscriptions' => [['url' => 'https://anytour.online/max-search/webhook.php']]]),
], $expected);
check(($wrongOwner['ok'] ?? true) === false, 'missing expected owner must fail');
check(($wrongOwner['reason'] ?? '') === 'expected_subscription_missing', 'missing owner reason');

$transport = MaxWebhookHealth::evaluate(['http' => 0, 'errno' => 7, 'body' => ''], $expected);
check(($transport['ok'] ?? true) === false, 'transport failure must fail closed');
check(($transport['reason'] ?? '') === 'transport_error', 'transport failure reason');

$invalid = MaxWebhookHealth::evaluate(['http' => 502, 'errno' => 0, 'body' => '<html>bad gateway</html>'], $expected);
check(($invalid['ok'] ?? true) === false, 'invalid API response must fail closed');

$source = file_get_contents(dirname(__DIR__) . '/services/MaxWebhookHealth.php');
check(strpos($source, "'/subscriptions'") !== false, 'service must use subscriptions read endpoint');
check(stripos($source, "CURLOPT_CUSTOMREQUEST") === false, 'health service must not issue mutation verbs');
check(stripos($source, "CURLOPT_POST") === false, 'health service must not POST');

fwrite(STDOUT, "MAX_WEBHOOK_HEALTH_REGRESSION=OK\n");
