<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$runtimeConfig = $root . '/config.php';
$externalConfig = '/var/www/anytoour/data/config/max-search.php';
$config = is_file($externalConfig) ? $externalConfig : $runtimeConfig;
$backup = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'max-search-standby-config.backup';
$mode = (string)($argv[1] ?? '--enable');

if (getenv('MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE') !== '1') {
    fwrite(STDERR, "Refusing config mutation without explicit standby write guard\n"); exit(2);
}
if (strpos(str_replace('\\', '/', $root), '/app.anytoour.ru') === false) {
    fwrite(STDERR, "Refusing config mutation outside standby checkout\n"); exit(2);
}
if (!is_file($runtimeConfig) || !is_readable($runtimeConfig)) {
    fwrite(STDERR, "Standby runtime config.php is not readable\n"); exit(2);
}
if (!is_file($config) || !is_readable($config) || !is_writable($config)) {
    fwrite(STDERR, "Standby mutable config is not readable/writable\n"); exit(2);
}

if ($mode === '--rollback') {
    if (!is_file($backup) || !copy($backup, $config)) { fwrite(STDERR, "Unable to restore standby config\n"); exit(2); }
    @unlink($backup); echo "STANDBY_MODE_ROLLBACK=OK\n"; exit(0);
}
if ($mode === '--commit') { @unlink($backup); echo "STANDBY_MODE_COMMIT=OK\n"; exit(0); }
if ($mode !== '--enable') { fwrite(STDERR, "Usage: standby_enable_standalone.php [--enable|--rollback|--commit]\n"); exit(2); }

require $runtimeConfig;
require_once $root . '/services/LeadBridgeConfig.php';
foreach (['CONVERSATION_DB_NAME', 'ANYTOUR_DATA_DB_NAME'] as $name) {
    if (!defined($name) || trim((string)constant($name)) === '') {
        fwrite(STDERR, "Standby prerequisite missing: {$name}\n"); exit(2);
    }
}
if (LeadBridgeConfig::receiverUrl() === '' || LeadBridgeConfig::secret() === '') {
    fwrite(STDERR, "Standby lead bridge authentication is unavailable\n"); exit(2);
}

$source = (string)file_get_contents($config);
if ($source === '') { fwrite(STDERR, "Unable to read standby config\n"); exit(2); }
if (!copy($config, $backup)) { fwrite(STDERR, "Unable to create standby config backup\n"); exit(2); }

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
foreach ($targets as $name => $value) {
    $namePattern = preg_quote($name, '/');
    $lines = array_values(array_filter($lines, static function (string $line) use ($namePattern): bool {
        $mentionsQuotedTarget = preg_match('/[\'\"]' . $namePattern . '[\'\"]/', $line) === 1;
        $mentionsBareTarget = preg_match('/\b' . $namePattern . '\b/', $line) === 1;
        $definesWithFunction = preg_match('/\bdefine\s*\(/', $line) === 1;
        $definesWithConst = preg_match('/^\s*const\s+' . $namePattern . '\s*=/', $line) === 1;
        return !(($mentionsQuotedTarget && $definesWithFunction) || ($mentionsBareTarget && $definesWithConst));
    }));
    $lines[] = "define('{$name}', {$value});";
}
$source = rtrim(implode(PHP_EOL, $lines)) . PHP_EOL;

$tmp = $config . '.standby-tmp-' . getmypid();
if (file_put_contents($tmp, $source) === false) { @unlink($backup); fwrite(STDERR, "Unable to write standby config temp file\n"); exit(2); }
@chmod($tmp, fileperms($config) & 0777);
if (!rename($tmp, $config)) { @unlink($tmp); @unlink($backup); fwrite(STDERR, "Unable to atomically replace standby config\n"); exit(2); }

echo "STANDBY_MODE_SWITCH=OK\n";
echo 'MUTATED_CONFIG=' . ($config === $externalConfig ? 'external' : 'runtime') . "\n";
echo "ENABLED=standalone,mysql_runtime,mysql_destination,bridge_lead,new_public_urls,new_webhook_urls\n";
