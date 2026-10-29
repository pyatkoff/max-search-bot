-- Explicit, append-only owner abandonment of research batches. No OAuth or hotel-data changes.
-- Stop/isolation hashes are caller attestations, not independent proof or hosted cancellation.
CREATE TABLE IF NOT EXISTS hotel_work_research_skips (
    skip_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    queue_id VARBINARY(80) NOT NULL,
    stage_index INT UNSIGNED NOT NULL,
    stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    old_connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    new_connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    baseline_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    claim_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    previous_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unknown_receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decision_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_effects_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_effects_readback_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    next_stage_index INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (queue_id,stage_index),
    UNIQUE KEY uq_hotel_research_skip_stage (queue_id,stage_id),
    UNIQUE KEY uq_hotel_research_skip_hash (skip_sha256),
    KEY idx_hotel_research_skip_next (queue_id,next_stage_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
