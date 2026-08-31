<?php

declare(strict_types=1);

$workflow = (string)file_get_contents(dirname(__DIR__) . '/.github/workflows/cutover-preflight.yml');

function cutoverPreflightAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

cutoverPreflightAssert(strpos($workflow, 'workflow_dispatch:') !== false, 'preflight must remain manually dispatched');
cutoverPreflightAssert(strpos($workflow, 'require_data_match:') !== false, 'preflight must support a strict final data-match gate');
cutoverPreflightAssert(strpos($workflow, 'standalone_readiness.php') !== false, 'preflight must verify standalone readiness');
cutoverPreflightAssert(strpos($workflow, 'cutover_data_snapshot.php') !== false, 'preflight must compare conversation-store snapshots');
cutoverPreflightAssert(strpos($workflow, 'CUTOVER_PREFLIGHT_READY=YES') !== false, 'preflight must emit an explicit ready result');
cutoverPreflightAssert(strpos($workflow, 'production_sha_mismatch') !== false, 'preflight must detect stale production code');
cutoverPreflightAssert(strpos($workflow, 'standby_sha_mismatch') !== false, 'preflight must detect stale standby code');

foreach (['mysqldump ', 'mysql ', 'DROP DATABASE', 'DROP TABLE', 'TRUNCATE ', 'INSERT ', 'UPDATE ', 'DELETE ', 'webhook set'] as $forbidden) {
    cutoverPreflightAssert(stripos($workflow, $forbidden) === false, 'preflight must stay read-only: ' . $forbidden);
}
cutoverPreflightAssert(stripos($workflow, 'crontab ') === false, 'preflight must not install or mutate cron');
cutoverPreflightAssert(stripos($workflow, 'systemctl ') === false, 'preflight must not start or restart services');

cutoverPreflightAssert(strpos($workflow, 'secrets.DEPLOY_SSH_KEY') !== false, 'preflight should reuse production deploy SSH connection');
cutoverPreflightAssert(strpos($workflow, 'secrets.STANDBY_DEPLOY_SSH_KEY') !== false, 'preflight should reuse standby deploy SSH connection');

echo "OK cutover preflight contract regression\n";
