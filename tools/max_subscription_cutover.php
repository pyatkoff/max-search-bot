<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/services/WebhookTargetConfig.php';
require_once $root . '/services/MaxTlsConfig.php';

$mode = (string)($argv[1] ?? '--status');
$targetUrl = WebhookTargetConfig::max();
$api = 'https://platform-api2.max.ru';
$token = defined('MAX_SEARCH_TOKEN') ? trim((string)MAX_SEARCH_TOKEN) : '';
if ($token === '') { fwrite(STDERR, "MAX_SEARCH_TOKEN missing\n"); exit(2); }

function maxReq(string $method, string $url, string $token, ?array $body = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $token, 'Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
    ] + MaxTlsConfig::curlOptions(false);
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $errno !== 0) throw new RuntimeException('MAX API cURL error: ' . $error);
    return [$http, (string)$raw];
}

function deleteSubscription(string $api, string $token, string $url): void
{
    [$http] = maxReq('DELETE', $api . '/subscriptions?url=' . rawurlencode($url), $token);
    if (!in_array($http, [200, 204, 404], true)) throw new RuntimeException('MAX delete failed HTTP ' . $http . ' for ' . $url);
}

function createSubscription(string $api, string $token, string $url): void
{
    $body = ['url' => $url, 'update_types' => ['message_callback', 'bot_started', 'message_created']];
    if (defined('MAX_SEARCH_WEBHOOK_SECRET') && MAX_SEARCH_WEBHOOK_SECRET !== '') $body['secret'] = MAX_SEARCH_WEBHOOK_SECRET;
    [$http, $raw] = maxReq('POST', $api . '/subscriptions', $token, $body);
    if (!in_array($http, [200, 201], true)) throw new RuntimeException('MAX create failed HTTP ' . $http . ': ' . $raw);
}

function subscriptions(string $api, string $token): array
{
    [$http, $raw] = maxReq('GET', $api . '/subscriptions', $token);
    if ($http !== 200) throw new RuntimeException('MAX list failed HTTP ' . $http);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function urlsFromSubscriptions(array $data): array
{
    $urls = [];
    $walk = function ($node) use (&$walk, &$urls): void {
        if (!is_array($node)) return;
        foreach ($node as $key => $value) {
            if ($key === 'url' && is_string($value)) $urls[] = $value;
            elseif (is_array($value)) $walk($value);
        }
    };
    $walk($data);
    return array_values(array_unique($urls));
}

if (!in_array($mode, ['--add-new', '--activate-new', '--status'], true)) {
    fwrite(STDERR, "Usage: max_subscription_cutover.php [--add-new|--activate-new|--status]\n"); exit(2);
}

if ($mode === '--add-new') {
    $before = urlsFromSubscriptions(subscriptions($api, $token));
    if (!in_array($targetUrl, $before, true)) createSubscription($api, $token, $targetUrl);
}

if ($mode === '--activate-new') {
    $before = urlsFromSubscriptions(subscriptions($api, $token));
    foreach ($before as $url) {
        if ($url !== $targetUrl) deleteSubscription($api, $token, $url);
    }
    deleteSubscription($api, $token, $targetUrl);
    createSubscription($api, $token, $targetUrl);
}

$urls = urlsFromSubscriptions(subscriptions($api, $token));
$targetCount = count(array_filter($urls, static fn(string $url): bool => $url === $targetUrl));
$extraCount = count(array_filter($urls, static fn(string $url): bool => $url !== $targetUrl));

echo 'MAX_SUBSCRIPTION_TARGET=' . $targetUrl . PHP_EOL;
echo 'MAX_SUBSCRIPTION_TARGET_COUNT=' . $targetCount . PHP_EOL;
echo 'MAX_SUBSCRIPTION_LEGACY_COUNT=' . $extraCount . PHP_EOL;
echo 'MAX_SUBSCRIPTION_NEW_COUNT=' . $targetCount . PHP_EOL;
if ($mode !== '--status') {
    $ok = $mode === '--add-new' ? $targetCount === 1 : ($targetCount === 1 && $extraCount === 0);
    echo 'MAX_SUBSCRIPTION_TARGET_OK=' . ($ok ? 'YES' : 'NO') . PHP_EOL;
    exit($ok ? 0 : 1);
}
