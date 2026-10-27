-- Hotel readiness metadata only. No hotel facts, customer data or hosted credentials.
CREATE TABLE IF NOT EXISTS hotel_work_queues (
    queue_id VARBINARY(80) NOT NULL,
    project_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    oauth_connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    batch_limit TINYINT UNSIGNED NULL,
    start_country CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    initial_phase_counter TINYINT UNSIGNED NOT NULL,
    phase_counter TINYINT UNSIGNED NOT NULL,
    baseline_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    plan_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stage_index INT UNSIGNED NOT NULL DEFAULT 0,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    claim_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    current_run_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    accepted_batches INT UNSIGNED NOT NULL DEFAULT 0,
    event_ack VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    delivered_at DATETIME NULL,
    claim_deadline DATETIME NULL,
    attention_at DATETIME NULL,
    attention_ack VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (queue_id),
    KEY idx_hotel_work_queue_owner (project_key,admin_id,oauth_connection_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hotel_work_receipts (
    queue_id VARBINARY(80) NOT NULL,
    stage_index INT UNSIGNED NOT NULL,
    stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_json VARBINARY(4096) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (queue_id,stage_index),
    UNIQUE KEY uq_hotel_work_stage_id (stage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The existing row is the global admission lock, including when no queue owns it.
CREATE TABLE IF NOT EXISTS hotel_work_lane (
    lane_key VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    queue_id VARBINARY(80) NULL,
    PRIMARY KEY (lane_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO hotel_work_lane (lane_key,queue_id) VALUES ('HOTEL',NULL);
