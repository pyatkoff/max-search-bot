<?php

declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/tools/cutover_data_snapshot.php');
$workflow = (string)file_get_contents(dirname(__DIR__) . '/.github/workflows/cutover-data-audit.yml');

function cutoverSnapshotAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

cutoverSnapshotAssert(strpos($source, "PHP_SAPI !== 'cli'") !== false, 'snapshot tool must be CLI-only');
cutoverSnapshotAssert(strpos($source, 'ConversationDb::connection()') !== false, 'snapshot tool must use conversation DB boundary');
cutoverSnapshotAssert(strpos($source, 'SELECT COUNT(*) AS row_count, COALESCE(MAX(id), 0) AS max_id') !== false, 'snapshot must stay read-only and expose count/max id');
cutoverSnapshotAssert(strpos($source, 'INSERT ') === false, 'snapshot must not insert');
cutoverSnapshotAssert(strpos($source, 'UPDATE ') === false, 'snapshot must not update');
cutoverSnapshotAssert(strpos($source, 'DELETE ') === false, 'snapshot must not delete');
cutoverSnapshotAssert(strpos($source, "'customers'") !== false, 'snapshot must include customers');
cutoverSnapshotAssert(strpos($source, "'messages'") !== false, 'snapshot must include messages');
cutoverSnapshotAssert(strpos($source, "'conversation_events'") !== false, 'snapshot must include events');
cutoverSnapshotAssert(strpos($source, 'CONVERSATION_DB_PASS') === false, 'snapshot must never emit DB password');
cutoverSnapshotAssert(strpos($workflow, 'workflow_dispatch:') !== false, 'cutover audit must remain manually runnable');
cutoverSnapshotAssert(strpos($workflow, "branches: [main]") !== false, 'audit workflow change must trigger one main audit run');
cutoverSnapshotAssert(strpos($workflow, "- 'tools/cutover_data_snapshot.php'") !== false, 'push trigger must stay scoped to snapshot tool changes');
cutoverSnapshotAssert(strpos($workflow, "- '.github/workflows/cutover-data-audit.yml'") !== false, 'push trigger must stay scoped to audit workflow changes');

echo "OK cutover data snapshot regression\n";
