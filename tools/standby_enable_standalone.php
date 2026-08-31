<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$config = $root . '/config.php';
$backup = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'max-search-standby-config.backup';
$mode = (string)($argv[1] ?? '--enable');

if (getenv('MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE') !== '1') {
    fwrite(STDERR, "Refusing config mutation without explicit standby write guard\n");
    exit(2);
}
if (strpos(str_replace('\\', '/', $root), '/app.anytoour.ru') === false) {
    fwrite(STDERR, "Refusing config mutation outside standby checkout\n");
    exit(2);
}
if (!is_file($config) || !is_readable($config) || !is_writable($config)) {
    fwrite(STDERR, "Standby config.php is not readable/writable\n");
    exit(2);
}

if ($mode === '--rollback') {
    if (!is_file($backup)) { fwrite(STDERR, "No standby config backup found\n"); exit(2); }
    if (!copy($backup, $config)) { fwrite(STDERR, "Unable to restore standby config\n"); exit(2); }
    @unlink($backup);
    echo "STANDBY_MODE_ROLLBACK=OK\n";
    exit(0);
}
if ($mode !== '--enable') { fwrite(STDERR, "Usage: standby_enable_standalone.php [--enable|--rollback]\n"); exit(2); }

require $config;
$required = [
    'CONVERSATION_DB_NAME', 'ANYTOUR_DATA_DB_NAME',
    'MAX_SEARCH_LEAD_RECEIVER_URL', 'MAX_SEARCH_LEAD_BRIDGE_SECRET',
];
foreach ($required as $name) {
    if (!defined($name) || trim((string)constant($name)) === '') {
        fwrite(STDERR, "Standby prerequisite missing: {$name}\n");
        exit(2);
    }
}

$source = (string)file_get_contents($config);
if ($source === '') { fwrite(STDERR, "Unable to read standby config\n"); exit(2); }
if (!copy($config, $backup)) { fwrite(STDERR, "Unable to create standby config backup\n"); exit(2); }

$targets = [
    'MAX_SEARCH_STANDALONE_RUNTIME' => 'true',
    'MAX_SEARCH_RUNTIME_STORAGE' => "'mysql'",
    'MAX_SEARCH_DESTINATION_STORAGE' => "'mysql'",
    'MAX_SEARCH_LEAD_DELIVERY' => "'bridge'",
];
foreach ($targets as $name => $value) {
    $replacement = "define('{$name}', {$value});";
    $pattern = '/define\s*\(\s*[\'\"]' . preg_quote($name, '/') . '[\'\"]\s*,\s*[^;]+\);/';
    $count = 0;
    $source = preg_replace($pattern, $replacement, $source, 1, $count) ?? $source;
    if ($count === 0) $source .= PHP_EOL . $replacement;
}

$tmp = $config . '.standby-tmp-' . getmypid();
if (file_put_contents($tmp, $source) === false) { @unlink($backup); fwrite(STDERR, "Unable to write standby config temp file\n"); exit(2); }
@chmod($tmp, fileperms($config) & 0777);
if (!rename($tmp, $config)) { @unlink($tmp); @unlink($backup); fwrite(STDERR, "Unable to atomically replace standby config\n"); exit(2); }

echo "STANDBY_MODE_SWITCH=OK\n";
echo "ENABLED=standalone,mysql_runtime,mysql_destination,bridge_lead\n";
