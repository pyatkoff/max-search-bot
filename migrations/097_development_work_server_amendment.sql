-- Fixed ONE-A02 supplementary acceptance audit only. No operational data is applied by migration.
CREATE TABLE IF NOT EXISTS development_work_server_amendments (
    queue_id VARBINARY(80) NOT NULL,
    task_id VARBINARY(80) NOT NULL,
    manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    manifest_bytes MEDIUMTEXT NOT NULL,
    future_binding_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    future_binding_bytes MEDIUMTEXT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_state_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    metadata_reads INT UNSIGNED NOT NULL DEFAULT 0,
    probe_count INT UNSIGNED NOT NULL DEFAULT 0,
    probe_slot BIGINT UNSIGNED NULL,
    release_proof_bytes MEDIUMTEXT NULL,
    release_proof_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    acceptance_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    receipt_json TEXT NULL,
    applied_cron_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    applied_at BIGINT UNSIGNED NULL,
    PRIMARY KEY (queue_id,task_id),
    UNIQUE KEY uq_development_server_amendment_manifest (manifest_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS development_work_server_raw_bytes (
    attempt_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_blob MEDIUMBLOB NULL,
    actual_request_blob MEDIUMBLOB NULL,
    PRIMARY KEY (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
