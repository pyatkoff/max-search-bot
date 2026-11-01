-- Inert route attestations and single-attempt delivery receipts, not a new queue.
CREATE TABLE IF NOT EXISTS hotel_native_bindings (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 admin_id BIGINT UNSIGNED NOT NULL,
 connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 subscription_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 binding_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 host_trigger_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 host_evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(queue_id,role)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_native_deliveries (
 event_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 generation INTEGER NOT NULL,
 role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 subscription_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 binding_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 http_status INTEGER NULL,
 UNIQUE KEY hotel_native_stage_attempt(queue_id,stage_id,generation,role)
) ENGINE=InnoDB;
