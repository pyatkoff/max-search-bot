<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = (string)file_get_contents($root . '/services/MaxTlsConfig.php');
$tool = (string)file_get_contents($root . '/tools/max_tls_preflight.php');
$workflow = (string)file_get_contents($root . '/.github/workflows/max-tls-preflight.yml');

function maxTlsPreflightAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach (['strictCurlOptions', 'CURLOPT_SSL_VERIFYPEER => true', 'CURLOPT_SSL_VERIFYHOST => 2'] as $needle) {
    maxTlsPreflightAssert(str_contains($config, $needle), 'strict MAX TLS config is missing: ' . $needle);
}
maxTlsPreflightAssert(str_contains($tool, "PHP_SAPI !== 'cli'"), 'MAX TLS preflight must be CLI-only');
foreach (['MaxTlsConfig::strictCurlOptions()', '/subscriptions', '/uploads?type=image', '--include-upload-host', 'CURLOPT_NOBODY => true', 'CURLINFO_SSL_VERIFYRESULT', "'tls_verification'=>'strict'"] as $needle) {
    maxTlsPreflightAssert(str_contains($tool, $needle), 'MAX TLS preflight contract is missing: ' . $needle);
}
foreach (['CURLOPT_POSTFIELDS', 'DELETE', '/messages'] as $forbidden) {
    maxTlsPreflightAssert(!str_contains($tool, $forbidden), 'MAX TLS preflight must not transfer a file or send/delete messages: ' . $forbidden);
}
foreach (['push:', 'workflow_dispatch:', 'EXPECTED_SHA', 'tools/max_tls_preflight.php --include-upload-host', '.ssl_verify_result == 0', '.upload_host.ok == true'] as $needle) {
    maxTlsPreflightAssert(str_contains($workflow, $needle), 'MAX TLS workflow contract is missing: ' . $needle);
}

echo "MAX strict TLS preflight contract OK\n";
