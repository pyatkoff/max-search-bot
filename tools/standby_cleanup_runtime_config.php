<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE') !== '1') {
    fwrite(STDERR, "Refusing runtime config cleanup without explicit standby write guard\n");
    exit(2);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
if (strpos(str_replace('\\', '/', $root), '/app.anytoour.ru') === false) {
    fwrite(STDERR, "Refusing runtime config cleanup outside standby checkout\n");
    exit(2);
}

$runtimeConfig = $root . '/config.php';
$externalConfig = '/var/www/anytoour/data/config/max-search.php';
if (!is_file($runtimeConfig) || !is_readable($runtimeConfig) || !is_writable($runtimeConfig)) {
    fwrite(STDERR, "Standby runtime config.php is not readable/writable\n");
    exit(2);
}
if (!is_file($externalConfig) || !is_readable($externalConfig)) {
    fwrite(STDERR, "External standby config is unavailable; refusing to remove runtime overrides\n");
    exit(2);
}

$source = (string)file_get_contents($runtimeConfig);
if ($source === '') {
    fwrite(STDERR, "Unable to read standby runtime config\n");
    exit(2);
}

// The runtime config must explicitly load the external deployment config before
// we remove duplicate deployment-owned constants from it.
if (strpos($source, $externalConfig) === false && strpos($source, 'data/config/max-search.php') === false) {
    fwrite(STDERR, "Runtime config does not reference external standby config; refusing cleanup\n");
    exit(2);
}

$targets = [
    'MAX_SEARCH_STANDALONE_RUNTIME',
    'MAX_SEARCH_RUNTIME_STORAGE',
    'MAX_SEARCH_DESTINATION_STORAGE',
    'MAX_SEARCH_LEAD_DELIVERY',
    'MAX_SEARCH_WEBHOOK_URL',
    'TELEGRAM_WEBHOOK_URL',
    'MAX_SEARCH_PUBLIC_BASE_URL',
    'MAX_SEARCH_TRACKING_BASE_URL',
];

$lines = preg_split('/\R/', $source) ?: [];
$removed = 0;
foreach ($targets as $name) {
    $namePattern = preg_quote($name, '/');
    $lines = array_values(array_filter($lines, static function (string $line) use ($namePattern, &$removed): bool {
        $define = preg_match('/^\s*define\s*\(\s*[\'\"]' . $namePattern . '[\'\"]\s*,.*\)\s*;\s*$/', $line) === 1;
        $const = preg_match('/^\s*const\s+' . $namePattern . '\s*=.*;\s*$/', $line) === 1;
        if ($define || $const) { $removed++; return false; }
        return true;
    }));
}

if ($removed === 0) {
    echo "STANDBY_RUNTIME_CONFIG_CLEANUP=NOOP\n";
    exit(0);
}

$candidate = rtrim(implode(PHP_EOL, $lines)) . PHP_EOL;
$tmp = $runtimeConfig . '.cleanup-' . getmypid();
if (file_put_contents($tmp, $candidate) === false) {
    fwrite(STDERR, "Unable to write runtime cleanup candidate\n");
    exit(2);
}
@chmod($tmp, fileperms($runtimeConfig) & 0777);
$lint = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $code);
if ($code !== 0) {
    @unlink($tmp);
    fwrite(STDERR, "Cleaned runtime config failed PHP lint\n" . implode("\n", $lint) . "\n");
    exit(2);
}
if (!rename($tmp, $runtimeConfig)) {
    @unlink($tmp);
    fwrite(STDERR, "Unable to atomically install cleaned runtime config\n");
    exit(2);
}

echo "STANDBY_RUNTIME_CONFIG_CLEANUP=OK removed={$removed}\n";
