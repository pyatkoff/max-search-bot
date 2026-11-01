-- Inert schema only. Does not enroll, claim, dispatch or advance any queue.
-- Requires 082 journal authority. Only explicit trusted control import fills it.
CREATE TABLE IF NOT EXISTS hotel_collection_baselines (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 baseline_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 baseline_bytes LONGBLOB NOT NULL,
 index_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 index_bytes LONGBLOB NOT NULL, index_attestation TEXT NOT NULL,
 created_at VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_collection_heads (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 revision INTEGER NOT NULL,
 snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_collection_snapshots (
 snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision INTEGER NOT NULL,
 previous_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 baseline_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 baseline_index_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 overlay_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 overlay_bytes LONGBLOB NOT NULL,
 operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
 UNIQUE KEY collection_snapshot_revision (queue_id,revision)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_collection_assignments (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 stage_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 generation INTEGER NOT NULL,owner_admin_id INTEGER NOT NULL,
 owner_connection_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reserve_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 context_bytes LONGBLOB NOT NULL,
 context_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 base_revision INTEGER NOT NULL,
 base_snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(queue_id,operation_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_collection_candidates (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 instance_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 raw_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 semantic_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 raw_bytes LONGBLOB NOT NULL,PRIMARY KEY(queue_id,operation_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_collection_ledger (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_packet_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 semantic_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision INTEGER NOT NULL,
 ledger_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ledger_bytes LONGBLOB NOT NULL,PRIMARY KEY(queue_id,operation_id),
 UNIQUE KEY collection_source_packet (queue_id,source_packet_id),
 UNIQUE KEY collection_semantic_packet (queue_id,semantic_sha256),
 UNIQUE KEY collection_ledger_revision (queue_id,revision)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_collection_requests (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 method VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 input_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receipt_bytes LONGBLOB NOT NULL,PRIMARY KEY(queue_id,operation_id,request_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_collection_policy (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 revision INTEGER NOT NULL,
 policy_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 policy_bytes LONGBLOB NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_collection_policy_versions (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision INTEGER NOT NULL,
 policy_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 policy_bytes LONGBLOB NOT NULL,PRIMARY KEY(queue_id,revision),
 UNIQUE KEY collection_policy_hash (queue_id,policy_sha256)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_collection_authorizations (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 stage_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 generation INTEGER NOT NULL,owner_admin_id INTEGER NOT NULL,
 owner_connection_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 policy_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 context_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 context_bytes LONGBLOB NOT NULL,PRIMARY KEY(queue_id,operation_id)
) ENGINE=InnoDB;
