-- Append-only metadata recovery. The original UNKNOWN receipt is never replaced.
CREATE TABLE IF NOT EXISTS hotel_work_connection_handoffs (
    handoff_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    queue_id VARBINARY(80) NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    old_connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    new_connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    baseline_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    handoff_mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_run_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claim_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (handoff_sha256),
    KEY idx_hotel_handoff_queue (queue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hotel_work_resolutions (
    queue_id VARBINARY(80) NOT NULL,
    stage_index INT UNSIGNED NOT NULL,
    stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unknown_receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    resolution_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_json VARBINARY(4096) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (queue_id,stage_index),
    UNIQUE KEY uq_hotel_resolution_stage (stage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE hotel_work_queues
    ADD COLUMN watchdog_due_at DATETIME NULL,
    ADD COLUMN watchdog_epoch INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN last_watch_ack VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL;
