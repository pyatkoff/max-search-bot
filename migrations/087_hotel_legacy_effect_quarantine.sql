-- Dormant preservation barrier, not cancellation, acceptance or a new queue.
CREATE TABLE IF NOT EXISTS hotel_legacy_effect_quarantine (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 original_run_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 queue_bytes LONGBLOB NOT NULL,
 working_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 working_bytes LONGBLOB NOT NULL,
 packet_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receipt_bytes LONGBLOB NOT NULL
) ENGINE=InnoDB;
