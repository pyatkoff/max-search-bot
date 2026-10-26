-- Private prospective observations. No conversion outbox or customer state.
CREATE TABLE IF NOT EXISTS modeled_conversion_stream_state (
    project_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    started_at_unix BIGINT UNSIGNED NOT NULL,
    state_json MEDIUMTEXT NOT NULL,
    quota_day CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quota_used INT UNSIGNED NOT NULL DEFAULT 0,
    ticks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_receipt_json TEXT NULL,
    PRIMARY KEY (project_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS modeled_conversion_stream_sources (
    project_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    latest_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version_no BIGINT UNSIGNED NOT NULL,
    observed_at_unix BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (project_key,source_kind,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS modeled_conversion_stream_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    version_no BIGINT UNSIGNED NOT NULL,
    revision_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observed_at_unix BIGINT UNSIGNED NOT NULL,
    snapshot_json MEDIUMTEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_modeled_source_version (project_key,source_kind,source_id,version_no),
    KEY idx_modeled_observed (project_key,observed_at_unix,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
