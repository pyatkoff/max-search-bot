<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$recoveryWorkflow = (string)file_get_contents($root . '/.github/workflows/restore-live-runtime.yml');

function cutoverPreflightAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([
    '.github/workflows/cutover-preflight.yml',
    '.github/workflows/standby-legacy-host-diagnostic.yml',
    'services/CutoverLegacyHostDependency.php',
    'tools/cutover_legacy_host_dependency.php',
] as $retired) {
    cutoverPreflightAssert(!is_file($root . '/' . $retired), 'retired cutover artifact must stay absent: ' . $retired);
}

cutoverPreflightAssert(strpos($recoveryWorkflow, 'workflow_dispatch:') !== false, 'canonical runtime recovery remains manually runnable');
cutoverPreflightAssert(strpos($recoveryWorkflow, '/var/www/anytoour/data/www/app.anytoour.ru') !== false, 'runtime recovery targets canonical checkout');
cutoverPreflightAssert(strpos($recoveryWorkflow, 'MAX_NEW_ONLY_HEALTH=OK') !== false, 'runtime recovery requires canonical MAX-only ownership');
cutoverPreflightAssert(strpos($recoveryWorkflow, 'subscription_count') !== false, 'runtime recovery verifies exact MAX subscription count');
cutoverPreflightAssert(strpos($recoveryWorkflow, 'lead_bridge_probe.php') !== false, 'runtime recovery preserves lead bridge verification');
cutoverPreflightAssert(strpos($recoveryWorkflow, 'MAX_SHADOW_MODE=OFF') !== false, 'runtime recovery verifies live processing state');
cutoverPreflightAssert(strpos($recoveryWorkflow, 'BOT_CRON_OWNERSHIP=NEW_ONLY') !== false, 'runtime recovery verifies single canonical cron owner');

foreach (['DROP DATABASE', 'DROP TABLE', 'TRUNCATE ', 'mysqldump '] as $forbidden) {
    cutoverPreflightAssert(stripos($recoveryWorkflow, $forbidden) === false, 'runtime recovery must not contain destructive DB operation: ' . $forbidden);
}

echo "OK retired cutover preflight contract regression\n";
