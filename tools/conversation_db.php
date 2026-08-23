<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$baseDir = dirname(__DIR__);
$configFile = $baseDir . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "ERROR: config.php not found\n");
    exit(2);
}

require_once $configFile;
require_once $baseDir . '/services/ConversationDb.php';

$command = $argv[1] ?? 'check';

try {
    if ($command === 'check') {
        if (!ConversationDb::isConfigured()) {
            fwrite(STDERR, 'ERROR: missing config: ' . implode(', ', ConversationDb::missingConfig()) . "\n");
            exit(2);
        }

        $result = ConversationDb::ping();
        echo "CONVERSATION DB CHECK\n";
        echo 'RESULT: ' . ($result['ok'] ? 'OK' : 'ERROR') . "\n";
        echo 'DATABASE: ' . $result['database'] . "\n";
        echo 'HOST: ' . $result['host'] . "\n";
        echo 'CHARSET: ' . $result['charset'] . "\n";
        echo 'LATENCY_MS: ' . $result['latency_ms'] . "\n";
        exit($result['ok'] ? 0 : 1);
    }

    if ($command === 'migrate') {
        $schemaFile = $baseDir . '/migrations/001_conversation_core.sql';
        if (!is_file($schemaFile) || !is_readable($schemaFile)) {
            throw new RuntimeException('Schema file is not readable: ' . $schemaFile);
        }

        $sql = trim((string)file_get_contents($schemaFile));
        if ($sql === '') {
            throw new RuntimeException('Schema file is empty');
        }

        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);
        $pdo = ConversationDb::connection();
        $executed = 0;

        foreach ($statements as $statement) {
            $statement = trim((string)$statement);
            if ($statement === '') continue;
            $pdo->exec($statement);
            $executed++;
        }

        echo "CONVERSATION DB MIGRATION\n";
        echo "RESULT: OK\n";
        echo 'DATABASE: ' . CONVERSATION_DB_NAME . "\n";
        echo 'STATEMENTS_EXECUTED: ' . $executed . "\n";
        exit(0);
    }

    fwrite(STDERR, "Usage: php tools/conversation_db.php [check|migrate]\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, "RESULT: ERROR\n");
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
