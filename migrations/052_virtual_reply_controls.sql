-- Explicit private paired controls. No model calls, schedules or ordinary-job rewrite.
CREATE TABLE IF NOT EXISTS virtual_reply_control_runs (
    run_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    baseline_contract_json MEDIUMTEXT NOT NULL,
    candidate_contract_json MEDIUMTEXT NOT NULL,
    reviewer_contract_json MEDIUMTEXT NOT NULL,
    registered_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (run_id),
    KEY ix_virtual_control_run_project (project_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virtual_reply_control_cases (
    control_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    group_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_kind ENUM('conversation','telegram_archive') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    source_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('active','invalidated') NOT NULL,
    payload_json MEDIUMTEXT NULL,
    evidence_ids_json TEXT NULL,
    order_json TEXT NULL,
    created_at DATETIME NOT NULL,
    checked_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (control_id),
    UNIQUE KEY uq_virtual_control_group (group_key),
    KEY ix_virtual_control_source (source_kind,source_id,state),
    KEY ix_virtual_control_recheck (state,checked_at,control_id),
    CONSTRAINT fk_virtual_control_run FOREIGN KEY (run_id) REFERENCES virtual_reply_control_runs(run_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virtual_reply_control_jobs (
    job_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    control_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role ENUM('author','reviewer') NOT NULL,
    slot ENUM('A','B','review') NOT NULL,
    status ENUM('pending','running','completed','unknown','invalidated') NOT NULL,
    available_at DATETIME NOT NULL,
    contract_json MEDIUMTEXT NOT NULL,
    claim_token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claimed_by BIGINT UNSIGNED NULL,
    lease_expires_at DATETIME NULL,
    result_json MEDIUMTEXT NULL,
    result_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL,
    claimed_at DATETIME NULL,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (job_id),
    UNIQUE KEY uq_virtual_control_slot (control_id,slot),
    KEY ix_virtual_control_job_queue (role,status,available_at,job_id),
    KEY ix_virtual_control_job_expiry (status,lease_expires_at),
    CONSTRAINT fk_virtual_control_job_case FOREIGN KEY (control_id) REFERENCES virtual_reply_control_cases(control_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
