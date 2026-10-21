-- Private, frozen virtual replies. No sends, automatic provider calls or rule writes.
CREATE TABLE IF NOT EXISTS virtual_reply_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    source_kind ENUM('conversation','telegram_archive') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    source_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    state ENUM('active','invalidated') NOT NULL,
    payload_json MEDIUMTEXT NULL,
    evidence_ids_json TEXT NULL,
    mode ENUM('live','historical_replay') NULL,
    reference_time CHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lesson_state ENUM('unresolved','rejected','duplicate','applied') NOT NULL DEFAULT 'unresolved',
    lesson_decision_json TEXT NULL,
    lesson_updated_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_virtual_case_id (case_id),
    UNIQUE KEY uq_virtual_source (project_key,source_kind,source_id),
    KEY ix_virtual_cases_rotate (project_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virtual_reply_jobs (
    case_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role ENUM('author','reviewer') NOT NULL,
    status ENUM('pending','running','completed','unknown','invalidated') NOT NULL,
    available_at DATETIME NOT NULL,
    claim_token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claimed_by BIGINT UNSIGNED NULL,
    lease_expires_at DATETIME NULL,
    profile_json TEXT NOT NULL,
    schema_json TEXT NOT NULL,
    instructions MEDIUMTEXT NOT NULL,
    instruction_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json MEDIUMTEXT NULL,
    result_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL,
    claimed_at DATETIME NULL,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (case_id,role),
    KEY ix_virtual_job_queue (role,status,available_at,case_id),
    KEY ix_virtual_job_lease (status,lease_expires_at),
    CONSTRAINT fk_virtual_job_case FOREIGN KEY (case_id) REFERENCES virtual_reply_cases(case_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virtual_reply_cursors (
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    source_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    lane VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (project_key,source_kind,lane)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A claim consumes one slot. Unknown outcomes never release it or retry.
CREATE TABLE IF NOT EXISTS virtual_reply_quotas (
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    day_utc DATE NOT NULL,
    role ENUM('author','reviewer') NOT NULL,
    used_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (project_key,day_utc,role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
