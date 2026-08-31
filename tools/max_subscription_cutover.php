<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/services/WebhookTargetConfig.php';

$mode = (string)($argv[1] ?? '--status');
$oldUrl = 'https://anytour.online/max-search/webhook.php';
$newUrl = WebhookTargetConfig::max();
$api = 'https://platform-api2.max.ru';
$token = defined('MAX_SEARCH_TOKEN') ? trim((string)MAX_SEARCH_TOKEN) : '';
if ($token === '') { fwrite(STDERR, "MAX_SEARCH_TOKEN missing\n"); exit(2); }

function maxReq(string $method, string $url, string $token, ?array $body = null): array
{
    $ch = curl_init($url);
    $headers = ['Authorization: ' . $token, 'Content-Type: application/json'];
    $insecureCompat = getenv('MAX_SEARCH_MAX_API_INSECURE_COMPAT') === '1';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        // Secure by default. The guarded cutover workflow may explicitly opt
        // into the same temporary compatibility behavior already used by the
        // production MaxTransport until the Minцифры CA is installed on the
        // new host. Never enable this implicitly from hostname or environment.
        CURLOPT_SSL_VERIFYPEER => !$insecureCompat,
        CURLOPT_SSL_VERIFYHOST => $insecureCompat ? 0 : 2,
    ];
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

if (!in_array($mode, ['--activate-new', '--rollback-old', '--status'], true)) {
    fwrite(STDERR, "Usage: max_subscription_cutover.php [--activate-new|--rollback-old|--status]\n"); exit(2);
}

if ($mode === '--activate-new') {
    deleteSubscription($api, $token, $oldUrl);
    if ($newUrl !== $oldUrl) deleteSubscription($api, $token, $newUrl);
    createSubscription($api, $token, $newUrl);
}
if ($mode === '--rollback-old') {
    deleteSubscription($api, $token, $newUrl);
    if ($newUrl !== $oldUrl) deleteSubscription($api, $token, $oldUrl);
    createSubscription($api, $token, $oldUrl);
}

$urls = urlsFromSubscriptions(subscriptions($api, $token));
$target = $mode === '--rollback-old' ? $oldUrl : $newUrl;
$targetCount = count(array_filter($urls, static fn(string $url): bool => $url === $target));
$legacyCount = count(array_filter($urls, static fn(string $url): bool => $url === $oldUrl));
$newCount = count(array_filter($urls, static fn(string $url): bool => $url === $newUrl));

echo 'MAX_SUBSCRIPTION_TARGET=' . $target . PHP_EOL;
echo 'MAX_SUBSCRIPTION_TARGET_COUNT=' . $targetCount . PHP_EOL;
echo 'MAX_SUBSCRIPTION_LEGACY_COUNT=' . $legacyCount . PHP_EOL;
echo 'MAX_SUBSCRIPTION_NEW_COUNT=' . $newCount . PHP_EOL;
if ($mode !== '--status') {
    $ok = $targetCount === 1 && ($target === $oldUrl ? $newCount === ($newUrl === $oldUrl ? 1 : 0) : $legacyCount === 0);
    echo 'MAX_SUBSCRIPTION_TARGET_OK=' . ($ok ? 'YES' : 'NO') . PHP_EOL;
    exit($ok ? 0 : 1);
}
