<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$transport = (string)file_get_contents($root . '/services/MaxTransport.php');
$legacy = (string)file_get_contents($root . '/maxsearchbaseclass.php');
$admin = (string)file_get_contents($root . '/repair_max_search_subscription.php');
$config = (string)file_get_contents($root . '/services/MaxTlsConfig.php');

function maxTlsTransportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

maxTlsTransportAssert(substr_count($transport, 'MaxTlsConfig::curlOptions(false)') === 2, 'MAX request and multipart upload must share verified TLS config');
maxTlsTransportAssert(str_contains($legacy, 'MaxTlsConfig::curlOptions(false)'), 'legacy MAX API helper must share verified TLS config');
maxTlsTransportAssert(str_contains($admin, 'MaxTlsConfig::curlOptions(false)'), 'MAX subscription admin must share verified TLS config');

foreach ([$transport, $legacy, $admin] as $source) {
    maxTlsTransportAssert(!preg_match('/CURLOPT_SSL_VERIFYPEER\s*(?:=>|,)\s*false/', $source), 'MAX runtime must not disable peer verification directly');
    maxTlsTransportAssert(!preg_match('/CURLOPT_SSL_VERIFYHOST\s*(?:=>|,)\s*(?:false|0)/', $source), 'MAX runtime must not disable hostname verification directly');
}

foreach (['CURLOPT_SSL_VERIFYPEER => true', 'CURLOPT_SSL_VERIFYHOST => 2', 'MAX_SEARCH_MAX_API_INSECURE_COMPAT'] as $needle) {
    maxTlsTransportAssert(str_contains($config, $needle), 'central MAX TLS config is missing: ' . $needle);
}

echo "MAX transport TLS verification contract OK\n";
