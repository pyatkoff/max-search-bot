<?php
require_once(__DIR__ . '/config.php');
require_once __DIR__ . '/services/WebhookTargetConfig.php';

header('Content-Type: text/plain; charset=utf-8');

$token = defined('MAX_SEARCH_TOKEN') ? MAX_SEARCH_TOKEN : '';
$webhookUrl = WebhookTargetConfig::max();
$api = 'https://platform-api2.max.ru';

if ($token === '') {
    exit("ERROR: MAX_SEARCH_TOKEN пуст в config.php\n");
}

function apiReq($method, $url, $token, $body = null) {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);
    return [$http,$errno,$error,$response];
}

function out($title,$r) {
    echo "=== {$title} ===\n";
    echo "HTTP_CODE: {$r[0]}\n";
    echo "CURL_ERRNO: {$r[1]}\n";
    echo "CURL_ERROR: {$r[2]}\n";
    echo "RESPONSE:\n{$r[3]}\n\n";
}

echo "MAX SEARCH ONLINE WEBHOOK UPDATE TYPES FIX\n";
echo "WEBHOOK: {$webhookUrl}\n\n";

out('BEFORE', apiReq('GET',$api.'/subscriptions',$token));

out(
    'DELETE ONLINE',
    apiReq('DELETE',$api.'/subscriptions?url='.rawurlencode($webhookUrl),$token)
);

$body = [
    'url' => $webhookUrl,
    'update_types' => [
        'message_callback',
        'bot_started',
        'message_created'
    ],
];

if (defined('MAX_SEARCH_WEBHOOK_SECRET') && MAX_SEARCH_WEBHOOK_SECRET !== '') {
    $body['secret'] = MAX_SEARCH_WEBHOOK_SECRET;
}

out(
    'CREATE ONLINE WITH UPDATE_TYPES',
    apiReq('POST',$api.'/subscriptions',$token,$body)
);

out('AFTER', apiReq('GET',$api.'/subscriptions',$token));
