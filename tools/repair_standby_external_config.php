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

$lines = preg_split('/\R/', $source) ?: [];
foreach ($targets as $name => $_value) {
    $namePattern = preg_quote($name, '/');
    $lines = array_values(array_filter($lines, static function (string $line) use ($namePattern): bool {
        return preg_match('/\b' . $namePattern . '\b/', $line) !== 1;
    }));
}

$insertAt = count($lines);
for ($i = count($lines) - 1; $i >= 0; --$i) {
    if (trim($lines[$i]) === '?>') { $insertAt = $i; break; }
}
$overrides = [];
foreach ($targets as $name => $value) { $overrides[] = "define('{$name}', {$value});"; }
array_splice($lines, $insertAt, 0, $overrides);
$repaired = rtrim(implode(PHP_EOL, $lines)) . PHP_EOL;

$tmp = $config . '.repair-' . getmypid();
$backup = $config . '.pre-cutover-repair';
if (!copy($config, $backup)) { fwrite(STDERR, "Unable to back up broken standby config\n"); exit(2); }
if (file_put_contents($tmp, $repaired) === false) { @unlink($tmp); fwrite(STDERR, "Unable to write repaired config temp file\n"); exit(2); }
@chmod($tmp, fileperms($config) & 0777);
$lint = []; $code = 0;
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $code);
if ($code !== 0) {
    @unlink($tmp);
    fwrite(STDERR, "Repaired standby config still fails PHP lint\n"); exit(2);
}
if (!rename($tmp, $config)) { @unlink($tmp); fwrite(STDERR, "Unable to install repaired standby config\n"); exit(2); }
echo "STANDBY_EXTERNAL_CONFIG_REPAIR=OK\n";
