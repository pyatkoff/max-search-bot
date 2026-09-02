<?php

declare(strict_types=1);

require_once __DIR__ . '/../integrations/MaxWebhookHealth.php';
require_once __DIR__ . '/../integrations/MaxWebhookReconciler.php';

$expected = 'https://app.anytoour.ru/webhook.php';
$legacy = MaxWebhookReconciler::LEGACY_ANYTOUR_WEBHOOK;

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

$healthyPlan = MaxWebhookReconciler::plan($healthy);
if (($healthyPlan['ok'] ?? false) !== true || ($healthyPlan['action'] ?? '') !== 'none') {
    fwrite(STDERR, json_encode($healthyPlan, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

$legacyExtra = MaxWebhookHealth::evaluate([
    'http' => 200,
    'errno' => 0,
    'body' => json_encode(['subscriptions' => [
        ['url' => $expected],
        ['url' => $legacy],
    ]]),
], $expected);
$legacyPlan = MaxWebhookReconciler::plan($legacyExtra);
if (($legacyPlan['ok'] ?? false) !== true || ($legacyPlan['action'] ?? '') !== 'delete_legacy' || ($legacyPlan['delete_urls'] ?? []) !== [$legacy]) {
    fwrite(STDERR, json_encode($legacyPlan, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

$unknownExtra = MaxWebhookHealth::evaluate([
    'http' => 200,
    'errno' => 0,
    'body' => json_encode(['subscriptions' => [
        ['url' => $expected],
        ['url' => 'https://unexpected.invalid/webhook.php'],
    ]]),
], $expected);
$unknownPlan = MaxWebhookReconciler::plan($unknownExtra);
if (($unknownPlan['ok'] ?? true) !== false || ($unknownPlan['reason'] ?? '') !== 'unknown_extra_subscription') {
    fwrite(STDERR, json_encode($unknownPlan, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

$missingCanonical = MaxWebhookHealth::evaluate([
    'http' => 200,
    'errno' => 0,
    'body' => json_encode(['subscriptions' => [
        ['url' => $legacy],
    ]]),
], $expected);
$missingPlan = MaxWebhookReconciler::plan($missingCanonical);
if (($missingPlan['ok'] ?? true) !== false || ($missingPlan['reason'] ?? '') !== 'canonical_not_safely_reconcilable') {
    fwrite(STDERR, json_encode($missingPlan, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

echo "MAX_CANONICAL_WEBHOOK_CONTRACT=OK\n";
