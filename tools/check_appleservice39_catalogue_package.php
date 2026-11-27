<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/services/AppleService39PilotCatalog.php';

if ($argc !== 3 || preg_match('/\A[a-f0-9]{64}\z/D', $argv[2]) !== 1) {
    fwrite(STDERR, "Usage: php tools/check_appleservice39_catalogue_package.php <local-json-file> <sha256>\n");
    exit(64);
}

try {
    $path = realpath($argv[1]);
    if ($path === false || is_link($argv[1]) || !is_file($path)) {
        throw new RuntimeException('appleservice39_pilot_package_unavailable');
    }
    $bytes = @file_get_contents($path, false, null, 0, AppleService39PilotCatalog::MAX_PACKAGE_BYTES + 1);
    if ($bytes === false) throw new RuntimeException('appleservice39_pilot_package_unavailable');
    $result = AppleService39PilotCatalog::comparePackage($bytes, $argv[2]);
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    // A valid but different package is reviewable; only exact equality passes.
    exit($result['same_bytes'] && $result['different_entries'] === 0 ? 0 : 2);
} catch (Throwable $error) {
    $code = $error->getMessage();
    if (!in_array($code, ['appleservice39_pilot_package_unavailable',
        'appleservice39_pilot_package_digest_mismatch'], true)) {
        $code = 'appleservice39_pilot_catalog_invalid';
    }
    fwrite(STDERR, $code."\n");
    exit(1);
}
