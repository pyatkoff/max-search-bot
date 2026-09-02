<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$deploy = (string)file_get_contents($root . '/.github/workflows/deploy.yml');
$helper = (string)file_get_contents($root . '/tools/conversation_db_mysql_defaults.php');

function cutoverDbSyncExecutionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

cutoverDbSyncExecutionAssert(!is_file($root . '/.github/workflows/cutover-db-sync-execution.yml'), 'completed final DB-copy workflow must stay retired');
cutoverDbSyncExecutionAssert(strpos($deploy, '/var/www/anytoour/data/www/app.anytoour.ru') !== false, 'normal production deploy targets canonical checkout');
cutoverDbSyncExecutionAssert(strpos($deploy, 'conversation_db.php migrate') !== false, 'normal production deploy applies forward migrations');
cutoverDbSyncExecutionAssert(strpos($deploy, 'git bundle') !== false, 'normal production deploy transfers exact repository state without server GitHub credentials');
cutoverDbSyncExecutionAssert(strpos($deploy, 'EXPECTED_SHA') !== false, 'normal production deploy remains exact-SHA bound');

foreach (['SYNC_CONVERSATION_DB', 'writes_frozen:', 'mysqldump ', '--single-transaction', 'max-search-precutover-', 'DROP DATABASE', 'DROP TABLE'] as $retiredOperation) {
    cutoverDbSyncExecutionAssert(strpos($deploy, $retiredOperation) === false, 'normal deploy must not retain final cross-host DB-copy operation: ' . $retiredOperation);
}

cutoverDbSyncExecutionAssert(strpos($helper, "PHP_SAPI !== 'cli'") !== false, 'MySQL defaults helper remains CLI-only');
cutoverDbSyncExecutionAssert(strpos($helper, 'CONVERSATION_DB_PASS') !== false, 'helper still sources credentials from external config');
cutoverDbSyncExecutionAssert(strpos($helper, 'chmod($path, 0600)') !== false, 'credential file remains mode 0600');
cutoverDbSyncExecutionAssert(strpos($helper, 'echo (string)CONVERSATION_DB_PASS') === false, 'helper never prints the DB password');

echo "OK retired cutover DB sync execution regression\n";
