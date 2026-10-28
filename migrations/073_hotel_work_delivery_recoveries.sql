-- One bounded fresh metadata delivery after an acknowledged same-binding resume.
-- Original handoff audit, previous ACK evidence and all hotel facts stay immutable.
CREATE TABLE IF NOT EXISTS hotel_work_delivery_recoveries (
    recovery_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    queue_id VARBINARY(80) NOT NULL,
    stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    oauth_connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    baseline_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    resume_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pending_event_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attention_event_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    delivered_at DATETIME NOT NULL,
    claim_deadline DATETIME NOT NULL,
    attention_at DATETIME NOT NULL,
    previous_updated_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (recovery_sha256),
    UNIQUE KEY uq_hotel_delivery_recovery_stage (queue_id,stage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
