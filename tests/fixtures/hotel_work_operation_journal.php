<?php
declare(strict_types=1);

/** Test databases ONLY; deliberately not a production migration or runtime DDL. */
function hotelJournalFixtureSchema(PDO $pdo, bool $reset = true): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql && preg_match('/\Amaxbot_prepared_test(?:_[a-zA-Z0-9_]+)?\z/D', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()) !== 1) throw new RuntimeException('unsafe_journal_fixture_database');
    if ($reset) foreach (['hotel_journal_requests','hotel_journal_snapshots','hotel_journal_operations','hotel_journal_heads'] as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
    $id = $mysql ? 'VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin' : 'TEXT';
    $sha = $mysql ? 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin' : 'TEXT';
    $blob = $mysql ? 'LONGBLOB' : 'BLOB';
    $engine = $mysql ? ' ENGINE=InnoDB' : '';
    $sql = [
        "CREATE TABLE hotel_journal_heads(queue_id $id NOT NULL,stage_id $id NOT NULL,baseline_sha256 $sha NOT NULL,generation INTEGER NOT NULL,control_sha256 $sha NOT NULL,owner_label $id NOT NULL,active_operation_id $sha,accepted_snapshot_id $sha,hold_reason $id,PRIMARY KEY(queue_id,stage_id))$engine",
        "CREATE TABLE hotel_journal_operations(operation_id $sha PRIMARY KEY,queue_id $id NOT NULL,stage_id $id NOT NULL,generation INTEGER NOT NULL,event_id $id NOT NULL,task_id $id NOT NULL,state $id NOT NULL,capability_sha256 $sha NOT NULL,instance_id $id,reported_run_id $id,run_evidence_sha256 $sha,candidate_sha256 $sha,candidate_bytes $blob,native_effect $id NOT NULL,UNIQUE(queue_id,stage_id,generation,event_id))$engine",
        "CREATE TABLE hotel_journal_requests(queue_id $id NOT NULL,stage_id $id NOT NULL,request_id $id NOT NULL,generation INTEGER NOT NULL,method $id NOT NULL,request_sha256 $sha NOT NULL,receipt_bytes $blob NOT NULL,PRIMARY KEY(queue_id,stage_id,request_id))$engine",
        "CREATE TABLE hotel_journal_snapshots(snapshot_id $sha PRIMARY KEY,queue_id $id NOT NULL,stage_id $id NOT NULL,operation_id $sha NOT NULL UNIQUE,generation INTEGER NOT NULL,event_id $id NOT NULL,packet_sha256 $sha NOT NULL,body_bytes $blob NOT NULL,UNIQUE(queue_id,stage_id),UNIQUE(queue_id,packet_sha256))$engine",
    ];
    if ($reset) foreach ($sql as $statement) $pdo->exec($statement);
}
