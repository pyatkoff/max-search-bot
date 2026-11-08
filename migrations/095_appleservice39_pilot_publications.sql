-- A fixed reviewed pilot owns one durable attempt per post and operation.
-- Keys deliberately exclude source/manifest hashes: a changed release cannot
-- reopen an ambiguous external write. Existing channel observations are unchanged.
CREATE TABLE IF NOT EXISTS appleservice39_pilot_campaigns (
    campaign_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    project_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bot_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    channel_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    admin_manager_id BIGINT UNSIGNED NOT NULL,
    manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    connection_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (campaign_key),
    CONSTRAINT as39_pilot_source_fk FOREIGN KEY (source_id) REFERENCES conversation_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS appleservice39_pilot_operations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    post_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_json MEDIUMTEXT NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    write_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    external_message_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    public_url VARCHAR(512) NULL,
    outcome_json MEDIUMTEXT NOT NULL,
    started_ms BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY as39_pilot_operation (campaign_key,post_key,operation),
    CONSTRAINT as39_pilot_campaign_fk FOREIGN KEY (campaign_key) REFERENCES appleservice39_pilot_campaigns(campaign_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
