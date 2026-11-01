-- One explicitly authorized additional producer for a retained research claim.
-- These three metadata audits are INSERT-only. They do not fence HOST or files.
CREATE TABLE IF NOT EXISTS hotel_work_execution_attempts (
    permit_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    queue_id VARBINARY(80) NOT NULL,
    stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stage_index INT UNSIGNED NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    canonical_run_id VARBINARY(128) NOT NULL,
    claim_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    audit_json VARBINARY(12288) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (permit_sha256),
    UNIQUE KEY uq_hotel_execution_attempt_stage (queue_id,stage_id),
    UNIQUE KEY uq_hotel_execution_attempt_token (attempt_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hotel_work_execution_attempt_entries (
    entry_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    permit_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    queue_id VARBINARY(80) NOT NULL,
    stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    instance_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    audit_json VARBINARY(8192) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (entry_sha256),
    UNIQUE KEY uq_hotel_execution_entry_permit (permit_sha256),
    UNIQUE KEY uq_hotel_execution_entry_stage (queue_id,stage_id),
    UNIQUE KEY uq_hotel_execution_entry_instance (instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hotel_work_execution_attempt_associations (
    association_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    permit_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entry_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    queue_id VARBINARY(80) NOT NULL,
    stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    producer_run_id VARBINARY(128) NOT NULL,
    packet_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    audit_json VARBINARY(8192) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (association_sha256),
    UNIQUE KEY uq_hotel_execution_association_permit (permit_sha256),
    UNIQUE KEY uq_hotel_execution_association_entry (entry_sha256),
    UNIQUE KEY uq_hotel_execution_association_stage (queue_id,stage_id),
    UNIQUE KEY uq_hotel_execution_association_producer (producer_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
