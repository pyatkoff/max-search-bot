<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/integrations/MaxWebhookHealth.php';
require_once dirname(__DIR__) . '/integrations/MaxWebhookReconciler.php';
require_once dirname(__DIR__) . '/services/MaxTlsConfig.php';

$apply = in_array('--apply', $argv ?? [], true);
$health = MaxWebhookHealth::collect();
$plan = MaxWebhookReconciler::plan($health);
$result = ['ok'=>false,'apply'=>$apply,'health'=>$health,'plan'=>$plan,'deleted'=>[]];

if (($plan['ok'] ?? false) !== true) {
    $result['reason'] = (string)($plan['reason'] ?? 'blocked');
    echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

if (($plan['action'] ?? '') === 'none') {
    $result['ok'] = true;
    $result['reason'] = 'already_healthy';
    echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if (!$apply) {
    $result['ok'] = true;
    $result['reason'] = 'dry_run';
    echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$token = defined('MAX_SEARCH_TOKEN') ? trim((string)MAX_SEARCH_TOKEN) : '';
if ($token === '') {
    $result['reason'] = 'missing_token';
    echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

foreach ((array)($plan['delete_urls'] ?? []) as $url) {
    $endpoint = 'https://platform-api2.max.ru/subscriptions?url=' . rawurlencode((string)$url);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>'DELETE',
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>['Authorization: ' . $token, 'Accept: application/json'],
    ] + MaxTlsConfig::curlOptions(false));
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $result['deleted'][] = ['url'=>(string)$url,'http'=>$http,'errno'=>$errno];
    if ($errno !== 0 || $http < 200 || $http >= 300) {
        $result['reason'] = 'delete_failed';
        echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(3);
    }
}

$after = MaxWebhookHealth::collect();
$result['after'] = $after;
$result['ok'] = ($after['ok'] ?? false) === true;
$result['reason'] = $result['ok'] ? 'reconciled' : 'post_reconcile_unhealthy';
echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 4);
