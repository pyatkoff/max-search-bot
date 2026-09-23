<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/services/LeadReceiverConfigEditor.php';

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$config = '/var/www/anytoour/data/config/max-search.php';
$backup = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'max-search-lead-receiver-config.backup';
$mode = (string)($argv[1] ?? '--status');

if ($mode === '--status') {
    if (!is_file($config) || !is_readable($config)) { fwrite(STDERR, "Lead receiver config is not readable\n"); exit(2); }
    $source = (string)file_get_contents($config);
    $value = LeadReceiverConfigEditor::directValue($source);
    if ($value === null || trim($value) === '') { echo "LEAD_RECEIVER_CONFIG=DEFAULT\n"; exit(0); }
    try { $valid = LeadReceiverConfigEditor::validateUrl($value); }
    catch (Throwable $e) { echo "LEAD_RECEIVER_CONFIG=INVALID\n"; exit(1); }
    echo "LEAD_RECEIVER_CONFIG=EXPLICIT\n";
    echo 'LEAD_RECEIVER_HOST=' . $valid['host'] . PHP_EOL;
    echo 'LEAD_RECEIVER_PATH=' . $valid['path'] . PHP_EOL;
    exit(0);
}

if (getenv('MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE') !== '1') {
    fwrite(STDERR, "Refusing lead receiver config mutation without explicit write guard\n"); exit(2);
}
if (strpos(str_replace('\\', '/', $root), '/app.anytoour.ru') === false) {
    fwrite(STDERR, "Refusing lead receiver config mutation outside canonical checkout\n"); exit(2);
}
if (!is_file($config) || !is_readable($config) || !is_writable($config)) {
    fwrite(STDERR, "External production config is not readable/writable\n"); exit(2);
}

if ($mode === '--rollback') {
    if (!is_file($backup) || !copy($backup, $config)) { fwrite(STDERR, "Unable to restore lead receiver config backup\n"); exit(2); }
    @chmod($config, 0600); @unlink($backup);
    echo "LEAD_RECEIVER_CONFIG_ROLLBACK=OK\n"; exit(0);
}
if ($mode === '--commit') {
    if (is_file($backup) && !@unlink($backup)) { fwrite(STDERR, "Unable to remove lead receiver config backup\n"); exit(2); }
    echo "LEAD_RECEIVER_CONFIG_COMMIT=OK\n"; exit(0);
}
if ($mode !== '--apply' || !isset($argv[2])) {
    fwrite(STDERR, "Usage: repair_lead_receiver_config.php [--status|--apply URL|--rollback|--commit]\n"); exit(2);
}
if (is_file($backup)) {
    fwrite(STDERR, "Lead receiver config backup already exists; resolve it before another apply\n"); exit(2);
}

try { $validated = LeadReceiverConfigEditor::validateUrl((string)$argv[2]); }
catch (Throwable $e) { fwrite(STDERR, "Invalid lead receiver target: ".$e->getMessage()."\n"); exit(2); }

$source = (string)file_get_contents($config);
if ($source === '') { fwrite(STDERR, "Unable to read external production config\n"); exit(2); }
$candidate = LeadReceiverConfigEditor::rewrite($source, $validated['url']);
$tmp = $config . '.lead-receiver-' . getmypid();
if (file_put_contents($tmp, $candidate) === false) { fwrite(STDERR, "Unable to write lead receiver config candidate\n"); exit(2); }
@chmod($tmp, 0600);
$lint=[];$code=0;
exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $lint, $code);
if ($code !== 0) { @unlink($tmp); fwrite(STDERR, "Lead receiver config candidate failed PHP lint\n"); exit(2); }
if (!copy($config, $backup)) { @unlink($tmp); fwrite(STDERR, "Unable to back up external production config\n"); exit(2); }
@chmod($backup, 0600);
if (!rename($tmp, $config)) {
    @unlink($tmp); @copy($backup, $config); @unlink($backup);
    fwrite(STDERR, "Unable to install lead receiver config candidate\n"); exit(2);
}
@chmod($config, 0600);
echo "LEAD_RECEIVER_CONFIG_APPLY=OK\n";
echo 'LEAD_RECEIVER_HOST=' . $validated['host'] . PHP_EOL;
echo 'LEAD_RECEIVER_PATH=' . $validated['path'] . PHP_EOL;
