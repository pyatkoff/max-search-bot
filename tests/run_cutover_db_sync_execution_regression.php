<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/cutover-db-sync-execution.yml');
$helper = (string) file_get_contents($root . '/tools/conversation_db_mysql_defaults.php');

function cutoverDbSyncExecutionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

cutoverDbSyncExecutionAssert(strpos($workflow, 'workflow_dispatch:') !== false, 'final DB sync must remain manual-only');
cutoverDbSyncExecutionAssert(strpos($workflow, "SYNC_CONVERSATION_DB") !== false, 'final DB sync must require typed confirmation');
cutoverDbSyncExecutionAssert(strpos($workflow, 'writes_frozen:') !== false, 'final DB sync must require an explicit write-freeze acknowledgement');
cutoverDbSyncExecutionAssert(strpos($workflow, 'prod_sha') !== false && strpos($workflow, 'standby_sha') !== false, 'final DB sync must verify exact code revisions');
cutoverDbSyncExecutionAssert(strpos($workflow, 'max-search-precutover-') !== false, 'standby DB must be backed up before replacement');
cutoverDbSyncExecutionAssert(strpos($workflow, '--single-transaction') !== false, 'production dump must use a consistent transactional snapshot');
cutoverDbSyncExecutionAssert(strpos($workflow, 'Production conversation data changed while final snapshot was being transferred.') !== false, 'sync must abort if production writes continue');
cutoverDbSyncExecutionAssert(strpos($workflow, 'conversation_db.php migrate') !== false, 'migrations must run after import');
cutoverDbSyncExecutionAssert(strpos($workflow, 'FINAL_DATA_MATCH=YES') !== false, 'sync must require exact post-import data match');
cutoverDbSyncExecutionAssert(strpos($workflow, 'CUTOVER_DB_SYNC_COMPLETE=YES') !== false, 'sync must emit an explicit completion marker');

foreach (['webhook set', 'crontab ', 'systemctl ', 'service '] as $forbidden) {
    cutoverDbSyncExecutionAssert(stripos($workflow, $forbidden) === false, 'DB sync must not switch processing while copying data: ' . $forbidden);
}

cutoverDbSyncExecutionAssert(strpos($helper, "PHP_SAPI !== 'cli'") !== false, 'MySQL defaults helper must remain CLI-only');
cutoverDbSyncExecutionAssert(strpos($helper, 'CONVERSATION_DB_PASS') !== false, 'helper must source credentials from existing external config');
cutoverDbSyncExecutionAssert(strpos($helper, 'chmod($path, 0600)') !== false, 'credential file must be mode 0600');
cutoverDbSyncExecutionAssert(strpos($helper, 'echo (string)CONVERSATION_DB_PASS') === false, 'helper must never print the DB password');
cutoverDbSyncExecutionAssert(strpos($helper, 'MYSQL_DEFAULTS_READY=YES') !== false, 'helper should emit only a non-secret readiness marker after writing credentials');

echo "OK cutover DB sync execution regression\n";
