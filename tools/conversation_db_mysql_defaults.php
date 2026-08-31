<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/services/ConversationDb.php';

if (!ConversationDb::isConfigured()) {
    fwrite(STDERR, 'ERROR: conversation DB is not configured' . PHP_EOL);
    exit(2);
}

$command = (string)($argv[1] ?? '');
if ($command === '--database-name') {
    echo (string)CONVERSATION_DB_NAME . PHP_EOL;
    exit(0);
}

if ($command !== '--write-defaults' || empty($argv[2])) {
    fwrite(STDERR, "Usage: php tools/conversation_db_mysql_defaults.php --database-name|--write-defaults <path>\n");
    exit(2);
}

$path = (string)$argv[2];
$dir = dirname($path);
if (!is_dir($dir) || !is_writable($dir)) {
    fwrite(STDERR, 'ERROR: target directory is not writable' . PHP_EOL);
    exit(2);
}

$escape = static function (string $value): string {
    return str_replace(["\\", "\n", "\r", '"'], ["\\\\", '\\n', '\\r', '\\"'], $value);
};

$charset = defined('CONVERSATION_DB_CHARSET') && trim((string)CONVERSATION_DB_CHARSET) !== ''
    ? (string)CONVERSATION_DB_CHARSET
    : 'utf8mb4';

$content = "[client]\n"
    . 'host="' . $escape((string)CONVERSATION_DB_HOST) . "\"\n"
    . 'user="' . $escape((string)CONVERSATION_DB_USER) . "\"\n"
    . 'password="' . $escape((string)CONVERSATION_DB_PASS) . "\"\n"
    . 'default-character-set="' . $escape($charset) . "\"\n";

if (file_put_contents($path, $content, LOCK_EX) === false) {
    fwrite(STDERR, 'ERROR: unable to write defaults file' . PHP_EOL);
    exit(1);
}
chmod($path, 0600);

echo "MYSQL_DEFAULTS_READY=YES\n";
