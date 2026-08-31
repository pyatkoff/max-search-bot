<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE') !== '1') {
    fwrite(STDERR, "Refusing config repair without explicit standby write guard\n"); exit(2);
}

$config = '/var/www/anytoour/data/config/max-search.php';
if (!is_file($config) || !is_readable($config) || !is_writable($config)) {
    fwrite(STDERR, "Standby external config is not readable/writable\n"); exit(2);
}

$source = (string)file_get_contents($config);
if ($source === '') { fwrite(STDERR, "Unable to read standby external config\n"); exit(2); }

$targets = [
    'MAX_SEARCH_STANDALONE_RUNTIME' => 'true',
    'MAX_SEARCH_RUNTIME_STORAGE' => "'mysql'",
    'MAX_SEARCH_DESTINATION_STORAGE' => "'mysql'",
    'MAX_SEARCH_LEAD_DELIVERY' => "'bridge'",
    'MAX_SEARCH_WEBHOOK_URL' => "'https://app.anytoour.ru/webhook.php'",
    'TELEGRAM_WEBHOOK_URL' => "'https://app.anytoour.ru/telegram_webhook.php'",
    'MAX_SEARCH_PUBLIC_BASE_URL' => "'https://app.anytoour.ru'",
    'MAX_SEARCH_TRACKING_BASE_URL' => "'https://app.anytoour.ru'",
];

$appendOverrides = static function (array $lines) use ($targets): string {
    $insertAt = count($lines);
    for ($i = count($lines) - 1; $i >= 0; --$i) {
        if (trim((string)$lines[$i]) === '?>') { $insertAt = $i; break; }
    }
    $overrides = [];
    foreach ($targets as $name => $value) { $overrides[] = "define('{$name}', {$value});"; }
    array_splice($lines, $insertAt, 0, $overrides);
    return rtrim(implode(PHP_EOL, $lines)) . PHP_EOL;
};

$lintSource = static function (string $candidate, string $tmp): array {
    if (file_put_contents($tmp, $candidate) === false) { return [false, ['unable to write temporary candidate']]; }
    $lint = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $code);
    return [$code === 0, $lint];
};

// First try the least-invasive repair. Remove complete one-line definitions only;
// do not delete guard/if lines merely because they mention a deployment-owned key.
$lines = preg_split('/\R/', $source) ?: [];
foreach ($targets as $name => $_value) {
    $namePattern = preg_quote($name, '/');
    $lines = array_values(array_filter($lines, static function (string $line) use ($namePattern): bool {
        $define = preg_match('/^\s*define\s*\(\s*[\'\"]' . $namePattern . '[\'\"]\s*,.*\)\s*;\s*$/', $line) === 1;
        $const = preg_match('/^\s*const\s+' . $namePattern . '\s*=.*;\s*$/', $line) === 1;
        return !($define || $const);
    }));
}
$repaired = $appendOverrides($lines);

$tmp = $config . '.repair-' . getmypid();
[$ok, $lint] = $lintSource($repaired, $tmp);

if (!$ok) {
    // Previous cutover attempts may already have left unmatched braces after a
    // broad line deletion. Recover by rebuilding only from complete top-level
    // constant-definition lines. Standby config is intentionally constant-based;
    // secrets are preserved verbatim and never emitted to logs.
    $safeLines = ['<?php'];
    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        if (preg_match('/^\s*define\s*\(\s*([\'\"])([A-Z][A-Z0-9_]*)\1\s*,.*\)\s*;\s*$/', $line, $m) === 1) {
            if (!array_key_exists($m[2], $targets)) { $safeLines[] = $line; }
            continue;
        }
        if (preg_match('/^\s*const\s+([A-Z][A-Z0-9_]*)\s*=.*;\s*$/', $line, $m) === 1) {
            if (!array_key_exists($m[1], $targets)) { $safeLines[] = $line; }
        }
    }
    $repaired = $appendOverrides($safeLines);
    [$ok, $lint] = $lintSource($repaired, $tmp);
}

if (!$ok) {
    @unlink($tmp);
    fwrite(STDERR, "Repaired standby config still fails PHP lint\n" . implode("\n", $lint) . "\n"); exit(2);
}

$backup = $config . '.pre-cutover-repair';
if (!is_file($backup) && !copy($config, $backup)) { @unlink($tmp); fwrite(STDERR, "Unable to back up broken standby config\n"); exit(2); }
@chmod($tmp, fileperms($config) & 0777);
if (!rename($tmp, $config)) { @unlink($tmp); fwrite(STDERR, "Unable to install repaired standby config\n"); exit(2); }
echo "STANDBY_EXTERNAL_CONFIG_REPAIR=OK\n";
