<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$deploy = (string)file_get_contents($root . '/.github/workflows/deploy.yml');

function cutoverDbSyncReadinessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

cutoverDbSyncReadinessAssert(!is_file($root . '/.github/workflows/cutover-db-sync-readiness.yml'), 'completed cross-host DB readiness workflow must stay retired');
cutoverDbSyncReadinessAssert(strpos($deploy, '/var/www/anytoour/data/www/app.anytoour.ru') !== false, 'production deploy targets canonical checkout');
cutoverDbSyncReadinessAssert(strpos($deploy, 'conversation_db.php migrate') !== false, 'production deploy keeps forward migrations');
cutoverDbSyncReadinessAssert(strpos($deploy, 'EXPECTED_SHA') !== false, 'production deploy remains exact-SHA bound');
cutoverDbSyncReadinessAssert(strpos($deploy, 'STANDBY_DEPLOY_SSH_KEY') !== false, 'canonical server SSH credentials are used');

foreach (['mysqldump ', '--all-databases', 'DROP DATABASE', 'DROP TABLE', 'TRUNCATE '] as $forbidden) {
    cutoverDbSyncReadinessAssert(stripos($deploy, $forbidden) === false, 'normal production deploy must not contain old DB cutover operation: ' . $forbidden);
}

echo "OK retired cutover DB sync readiness regression\n";
