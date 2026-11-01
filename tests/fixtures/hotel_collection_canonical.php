<?php
declare(strict_types=1);

/** Test databases ONLY. Never called by the store or a production bootstrap. */
function hotelCollectionCanonicalFixtureSchema(PDO $pdo, bool $reset = true): void
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['sqlite', 'mysql'], true)) throw new RuntimeException('unsupported_collection_fixture_driver');
    $mysql = $driver === 'mysql';
    if ($mysql && preg_match('/\Amaxbot_prepared_test(?:_[a-zA-Z0-9_]+)?\z/D', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()) !== 1) throw new RuntimeException('unsafe_collection_fixture_database');
    if (!$reset) return;
    foreach (['hotel_collection_authorizations','hotel_collection_policy','hotel_collection_policy_versions','hotel_collection_requests','hotel_collection_ledger','hotel_collection_candidates','hotel_collection_assignments','hotel_collection_heads','hotel_collection_snapshots','hotel_collection_baselines','hotel_collection_test_locks'] as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
    if ($mysql) {
        // Run the actual forward migration in the allowlisted test database.
        // Native checks must not accidentally test a parallel hand-written DDL.
        $source = (string)file_get_contents(dirname(__DIR__,2).'/migrations/083_hotel_collection_canonical.sql');
        if (trim($source) === '') throw new RuntimeException('collection_migration_unavailable');
        foreach (explode(';', $source) as $sql) if (trim($sql) !== '') $pdo->exec($sql);
        $pdo->exec('CREATE TABLE hotel_collection_test_locks(queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,touched INTEGER NOT NULL) ENGINE=InnoDB');
        return;
    }
    $id = $mysql ? 'VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin' : 'TEXT';
    $sha = $mysql ? 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin' : 'TEXT';
    $blob = $mysql ? 'LONGBLOB' : 'BLOB';
    $text = $mysql ? 'TEXT' : 'TEXT';
    $engine = $mysql ? ' ENGINE=InnoDB' : '';
    $statements = [
        "CREATE TABLE hotel_collection_baselines(queue_id $id PRIMARY KEY,baseline_sha256 $sha NOT NULL,baseline_bytes $blob NOT NULL,index_sha256 $sha NOT NULL,index_bytes $blob NOT NULL,index_attestation $text NOT NULL,created_at $id NOT NULL)$engine",
        "CREATE TABLE hotel_collection_heads(queue_id $id PRIMARY KEY,revision INTEGER NOT NULL,snapshot_sha256 $sha NOT NULL)$engine",
        "CREATE TABLE hotel_collection_snapshots(snapshot_sha256 $sha PRIMARY KEY,queue_id $id NOT NULL,revision INTEGER NOT NULL,previous_sha256 $sha,baseline_sha256 $sha NOT NULL,baseline_index_sha256 $sha NOT NULL,overlay_sha256 $sha NOT NULL,overlay_bytes $blob NOT NULL,operation_id $id,UNIQUE(queue_id,revision))$engine",
        "CREATE TABLE hotel_collection_assignments(queue_id $id NOT NULL,operation_id $id NOT NULL,stage_id $id NOT NULL,generation INTEGER NOT NULL,owner_admin_id INTEGER NOT NULL,owner_connection_id $id NOT NULL,reserve_sha256 $sha NOT NULL,context_bytes $blob NOT NULL,context_sha256 $sha NOT NULL,base_revision INTEGER NOT NULL,base_snapshot_sha256 $sha NOT NULL,PRIMARY KEY(queue_id,operation_id))$engine",
        "CREATE TABLE hotel_collection_candidates(queue_id $id NOT NULL,operation_id $id NOT NULL,instance_id $id NOT NULL,raw_sha256 $sha NOT NULL,semantic_sha256 $sha NOT NULL,raw_bytes $blob NOT NULL,PRIMARY KEY(queue_id,operation_id))$engine",
        "CREATE TABLE hotel_collection_ledger(queue_id $id NOT NULL,operation_id $id NOT NULL,source_packet_id $id NOT NULL,semantic_sha256 $sha NOT NULL,revision INTEGER NOT NULL,ledger_sha256 $sha NOT NULL,ledger_bytes $blob NOT NULL,PRIMARY KEY(queue_id,operation_id),UNIQUE(queue_id,source_packet_id),UNIQUE(queue_id,semantic_sha256),UNIQUE(queue_id,revision))$engine",
        "CREATE TABLE hotel_collection_requests(queue_id $id NOT NULL,operation_id $id NOT NULL,request_id $id NOT NULL,method $id NOT NULL,input_sha256 $sha NOT NULL,receipt_sha256 $sha NOT NULL,receipt_bytes $blob NOT NULL,PRIMARY KEY(queue_id,operation_id,request_id))$engine",
        "CREATE TABLE hotel_collection_policy(queue_id $id PRIMARY KEY,revision INTEGER NOT NULL,policy_sha256 $sha NOT NULL,policy_bytes $blob NOT NULL)$engine",
        "CREATE TABLE hotel_collection_policy_versions(queue_id $id NOT NULL,revision INTEGER NOT NULL,policy_sha256 $sha NOT NULL,policy_bytes $blob NOT NULL,PRIMARY KEY(queue_id,revision),UNIQUE(queue_id,policy_sha256))$engine",
        "CREATE TABLE hotel_collection_authorizations(queue_id $id NOT NULL,operation_id $id NOT NULL,stage_id $id NOT NULL,generation INTEGER NOT NULL,owner_admin_id INTEGER NOT NULL,owner_connection_id $id NOT NULL,policy_sha256 $sha NOT NULL,context_sha256 $sha NOT NULL,context_bytes $blob NOT NULL,PRIMARY KEY(queue_id,operation_id))$engine",
        // Test-only lock models the caller's preexisting queue lock. It is NOT
        // proof that production lane/queue/journal factories have been connected.
        "CREATE TABLE hotel_collection_test_locks(queue_id $id PRIMARY KEY,touched INTEGER NOT NULL)$engine",
    ];
    foreach ($statements as $sql) $pdo->exec($sql);
}
