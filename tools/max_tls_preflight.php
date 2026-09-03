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
$apiOk = $errno === 0
    && $verifyResult === 0
    && $http >= 200
    && $http < 300
    && is_array($decoded);

$uploadHost = [
    'checked'=>false,
    'ok'=>null,
    'reservation_http_status'=>null,
    'curl_errno'=>null,
    'ssl_verify_result'=>null,
];

if ($apiOk && in_array('--include-upload-host', $argv ?? [], true)) {
    $reserve = curl_init('https://platform-api2.max.ru/uploads?type=image');
    curl_setopt_array($reserve, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $token, 'Accept: application/json'],
    ] + MaxTlsConfig::strictCurlOptions());
    $reserveBody = curl_exec($reserve);
    $reserveErrno = curl_errno($reserve);
    $reserveHttp = (int)curl_getinfo($reserve, CURLINFO_HTTP_CODE);
    $reserveVerifyResult = (int)curl_getinfo($reserve, CURLINFO_SSL_VERIFYRESULT);
    curl_close($reserve);

    $reserveData = is_string($reserveBody) ? json_decode($reserveBody, true) : null;
    $uploadUrl = is_array($reserveData)
        ? trim((string)($reserveData['url'] ?? $reserveData['upload_url'] ?? ''))
        : '';

    $uploadHost = [
        'checked'=>true,
        'ok'=>false,
        'reservation_http_status'=>$reserveHttp,
        'curl_errno'=>$reserveErrno,
        'ssl_verify_result'=>$reserveVerifyResult,
    ];

    if ($reserveErrno === 0 && $reserveVerifyResult === 0 && $reserveHttp >= 200 && $reserveHttp < 300 && $uploadUrl !== '') {
        $probe = curl_init($uploadUrl);
        curl_setopt_array($probe, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_NOBODY => true,
        ] + MaxTlsConfig::strictCurlOptions());
        curl_exec($probe);
        $probeErrno = curl_errno($probe);
        $probeVerifyResult = (int)curl_getinfo($probe, CURLINFO_SSL_VERIFYRESULT);
        curl_close($probe);

        $uploadHost['curl_errno'] = $probeErrno;
        $uploadHost['ssl_verify_result'] = $probeVerifyResult;
        $uploadHost['ok'] = $probeErrno === 0 && $probeVerifyResult === 0;
    }
}

$uploadRequired = in_array('--include-upload-host', $argv ?? [], true);
$ok = $apiOk && (!$uploadRequired || $uploadHost['ok'] === true);
$reason = $ok
    ? 'verified'
    : (!$apiOk
        ? ($errno !== 0 || $verifyResult !== 0 ? 'tls_transport_error' : 'api_response_error')
        : 'upload_host_tls_error');

echo json_encode([
    'ok'=>$ok,
    'reason'=>$reason,
    'tls_verification'=>'strict',
    'ca_source'=>MaxTlsConfig::caBundle() !== '' ? 'configured_bundle' : 'system_store',
    'http_status'=>$http,
    'curl_errno'=>$errno,
    'ssl_verify_result'=>$verifyResult,
    'api_json_valid'=>is_array($decoded),
    'upload_host'=>$uploadHost,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($ok ? 0 : 2);
