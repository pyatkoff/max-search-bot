<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$mode = (string)($argv[1] ?? '');
if (!in_array($mode, ['--on', '--off'], true)) {
    fwrite(STDERR, "Usage: standby_max_shadow_mode.php [--on|--off]\n");
    exit(2);
}
if (getenv('MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE') !== '1') {
    fwrite(STDERR, "Refusing config mutation without explicit standby write guard\n");
    exit(2);
}
if (strpos(str_replace('\\', '/', $root), '/app.anytoour.ru') === false) {
    fwrite(STDERR, "Refusing config mutation outside new-server checkout\n");
    exit(2);
}

$config = '/var/www/anytoour/data/config/max-search.php';
if (!is_file($config) || !is_readable($config) || !is_writable($config)) {
    fwrite(STDERR, "New-server mutable config is unavailable\n");
    exit(2);
}

$source = (string)file_get_contents($config);
$lines = preg_split('/\R/', $source) ?: [];
$name = 'MAX_SEARCH_MAX_SHADOW_MODE';
$namePattern = preg_quote($name, '/');
$lines = array_values(array_filter($lines, static function (string $line) use ($namePattern): bool {
    return preg_match('/^\s*define\s*\(\s*[\'\"]' . $namePattern . '[\'\"]\s*,.*\)\s*;\s*$/', $line) !== 1
        && preg_match('/^\s*const\s+' . $namePattern . '\s*=.*;\s*$/', $line) !== 1;
}));
$insertAt = count($lines);
for ($i = count($lines) - 1; $i >= 0; --$i) {
    if (trim($lines[$i]) === '?>') { $insertAt = $i; break; }
}
$value = $mode === '--on' ? 'true' : 'false';
array_splice($lines, $insertAt, 0, ["define('{$name}', {$value});"]);
$updated = rtrim(implode(PHP_EOL, $lines)) . PHP_EOL;
$tmp = $config . '.shadow-tmp-' . getmypid();
if (file_put_contents($tmp, $updated) === false) { fwrite(STDERR, "Unable to write temp config\n"); exit(2); }
@chmod($tmp, fileperms($config) & 0777);
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $code);
if ($code !== 0 || !rename($tmp, $config)) { @unlink($tmp); fwrite(STDERR, "Unable to activate shadow-mode change\n"); exit(2); }

echo 'MAX_SHADOW_MODE=' . ($mode === '--on' ? 'ON' : 'OFF') . PHP_EOL;
