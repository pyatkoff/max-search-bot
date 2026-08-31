<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$config = '/var/www/anytoour/data/config/max-search.php';
$mode = (string)($argv[1] ?? '--status');
$backup = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'max-search-max-shadow.backup.php';

if (strpos(str_replace('\\', '/', $root), '/app.anytoour.ru') === false) {
    fwrite(STDERR, "Refusing MAX mode mutation outside app.anytoour.ru checkout\n"); exit(2);
}
if (!in_array($mode, ['--status','--live','--shadow','--rollback','--commit'], true)) {
    fwrite(STDERR, "Usage: max_shadow_mode.php [--status|--live|--shadow|--rollback|--commit]\n"); exit(2);
}
if (!is_file($config) || !is_readable($config)) {
    fwrite(STDERR, "External standby config unavailable\n"); exit(2);
}

function shadowState(string $source): ?bool
{
    if (preg_match('/define\s*\(\s*[\'\"]MAX_SEARCH_MAX_SHADOW_MODE[\'\"]\s*,\s*(true|false)\s*\)\s*;/i', $source, $m)) {
        return strtolower($m[1]) === 'true';
    }
    return null;
}

if ($mode === '--status') {
    $state = shadowState((string)file_get_contents($config));
    echo 'MAX_SHADOW_MODE=' . ($state === true ? 'ON' : ($state === false ? 'OFF' : 'UNDEFINED')) . PHP_EOL;
    exit($state === null ? 1 : 0);
}

if (getenv('MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE') !== '1') {
    fwrite(STDERR, "Refusing MAX mode mutation without explicit standby write guard\n"); exit(2);
}
if (!is_writable($config)) {
    fwrite(STDERR, "External standby config is not writable\n"); exit(2);
}

if ($mode === '--rollback') {
    if (!is_file($backup) || !copy($backup, $config)) { fwrite(STDERR, "Unable to restore MAX shadow config backup\n"); exit(2); }
    @unlink($backup);
    echo "MAX_SHADOW_ROLLBACK=OK\n";
    exit(0);
}
if ($mode === '--commit') {
    @unlink($backup);
    echo "MAX_SHADOW_COMMIT=OK\n";
    exit(0);
}

$source = (string)file_get_contents($config);
if ($source === '') { fwrite(STDERR, "Unable to read external standby config\n"); exit(2); }
if (!copy($config, $backup)) { fwrite(STDERR, "Unable to back up external standby config\n"); exit(2); }

$target = $mode === '--shadow' ? 'true' : 'false';
$pattern = '/^\s*define\s*\(\s*[\'\"]MAX_SEARCH_MAX_SHADOW_MODE[\'\"]\s*,.*?\)\s*;\s*$/mi';
$replacement = "define('MAX_SEARCH_MAX_SHADOW_MODE', {$target});";
if (preg_match($pattern, $source)) {
    $source = (string)preg_replace($pattern, $replacement, $source);
} else {
    $source = rtrim($source) . PHP_EOL . $replacement . PHP_EOL;
}

$tmp = $config . '.max-mode-' . getmypid();
if (file_put_contents($tmp, $source) === false) { @unlink($backup); fwrite(STDERR, "Unable to write MAX mode temp config\n"); exit(2); }
@chmod($tmp, fileperms($config) & 0777);
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $lintCode);
if ($lintCode !== 0) {
    @unlink($tmp); @unlink($backup);
    fwrite(STDERR, "MAX mode config failed PHP lint\n"); exit(2);
}
if (!rename($tmp, $config)) { @unlink($tmp); @unlink($backup); fwrite(STDERR, "Unable to atomically replace MAX mode config\n"); exit(2); }

$state = shadowState((string)file_get_contents($config));
$expected = $mode === '--shadow';
if ($state !== $expected) {
    @copy($backup, $config); @unlink($backup);
    fwrite(STDERR, "MAX mode verification failed\n"); exit(2);
}
echo 'MAX_SHADOW_MODE=' . ($state ? 'ON' : 'OFF') . PHP_EOL;
