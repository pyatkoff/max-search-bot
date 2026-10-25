-- Private append-only observations, independent from channel subscription state.
CREATE TABLE IF NOT EXISTS advertising_visit_cursors (
    project_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    counter_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_cursor VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
    PRIMARY KEY (project_key,source_key,platform,counter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS advertising_visit_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    counter_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_cursor VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    visit_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    visit_at_unix BIGINT UNSIGNED NOT NULL,
    observed_at_unix BIGINT UNSIGNED NOT NULL,
    external_user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    identity_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    yclid VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    campaign_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    region_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    received_at_unix BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_advertising_observation (project_key,source_key,platform,counter_id,source_cursor),
    KEY idx_advertising_identity (project_key,source_key,platform,counter_id,external_user_id,identity_status,observed_at_unix),
    KEY idx_advertising_visit (project_key,source_key,platform,counter_id,visit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
