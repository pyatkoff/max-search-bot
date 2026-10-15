CREATE TABLE IF NOT EXISTS max_channel_attribution_settings (
    project_key VARCHAR(64) NOT NULL,
    mode VARCHAR(16) NOT NULL DEFAULT 'off',
    counter_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    activated_at_unix BIGINT UNSIGNED NULL,
    message_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO max_channel_attribution_settings (project_key,mode,counter_id)
VALUES ('anytour','off','98615635');

CREATE TABLE IF NOT EXISTS max_channel_conversion_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_key VARCHAR(64) NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    external_user_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    counter_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_unix BIGINT UNSIGNED NOT NULL,
    client_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    tracker_record_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    tracker_observed_unix BIGINT UNSIGNED NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'pending',
    next_attempt_unix BIGINT UNSIGNED NOT NULL DEFAULT 0,
    claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claimed_at_unix BIGINT UNSIGNED NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    upload_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    upload_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_error VARCHAR(64) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_max_channel_stage (project_key,conversation_id,target),
    KEY idx_max_channel_pending (project_key,state,next_attempt_unix,id),
    KEY idx_max_channel_claim (claim_token),
    KEY idx_max_channel_upload (upload_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
