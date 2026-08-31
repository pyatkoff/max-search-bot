<?php

declare(strict_types=1);

$workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/cutover-db-sync-readiness.yml');

function cutoverDbSyncReadinessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

cutoverDbSyncReadinessAssert(strpos($workflow, 'workflow_dispatch:') !== false, 'DB sync readiness must remain manual');
cutoverDbSyncReadinessAssert(strpos($workflow, 'command -v mysqldump') !== false, 'production export tooling must be checked');
cutoverDbSyncReadinessAssert(strpos($workflow, 'command -v mysql') !== false, 'standby import tooling must be checked');
cutoverDbSyncReadinessAssert(strpos($workflow, 'conversation_db.php check') !== false, 'both DB connections must be checked');
cutoverDbSyncReadinessAssert(strpos($workflow, 'standalone_readiness.php') !== false, 'standby standalone readiness must be checked');
cutoverDbSyncReadinessAssert(strpos($workflow, 'CUTOVER_DB_SYNC_READINESS=YES') !== false, 'workflow must emit explicit ready marker');

foreach (['--all-databases', 'DROP DATABASE', 'DROP TABLE', 'TRUNCATE ', 'INSERT ', 'UPDATE ', 'DELETE ', 'crontab ', 'systemctl '] as $forbidden) {
    cutoverDbSyncReadinessAssert(stripos($workflow, $forbidden) === false, 'DB sync readiness must stay non-mutating: ' . $forbidden);
}

cutoverDbSyncReadinessAssert(strpos($workflow, 'secrets.DEPLOY_SSH_KEY') !== false, 'production SSH secret must be reused');
cutoverDbSyncReadinessAssert(strpos($workflow, 'secrets.STANDBY_DEPLOY_SSH_KEY') !== false, 'standby SSH secret must be reused');

echo "OK cutover DB sync readiness regression\n";
