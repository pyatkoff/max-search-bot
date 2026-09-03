<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/services/MaxTlsConfig.php';

$token = defined('MAX_SEARCH_TOKEN') ? trim((string)MAX_SEARCH_TOKEN) : '';
if ($token === '') {
    echo json_encode([
        'ok'=>false,
        'reason'=>'missing_token',
        'tls_verification'=>'strict',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

$ch = curl_init('https://platform-api2.max.ru/subscriptions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Authorization: ' . $token, 'Accept: application/json'],
] + MaxTlsConfig::strictCurlOptions());

$body = curl_exec($ch);
$errno = curl_errno($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$verifyResult = (int)curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
curl_close($ch);

$decoded = is_string($body) ? json_decode($body, true) : null;
$ok = $errno === 0
    && $verifyResult === 0
    && $http >= 200
    && $http < 300
    && is_array($decoded);

echo json_encode([
    'ok'=>$ok,
    'reason'=>$ok ? 'verified' : ($errno !== 0 || $verifyResult !== 0 ? 'tls_transport_error' : 'api_response_error'),
    'tls_verification'=>'strict',
    'ca_source'=>MaxTlsConfig::caBundle() !== '' ? 'configured_bundle' : 'system_store',
    'http_status'=>$http,
    'curl_errno'=>$errno,
    'ssl_verify_result'=>$verifyResult,
    'api_json_valid'=>is_array($decoded),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($ok ? 0 : 2);
