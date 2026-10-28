-- Development control metadata only. No source, deploy credential or business data.
CREATE TABLE IF NOT EXISTS development_work_queues (
    queue_id VARBINARY(80) NOT NULL,
    project_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    oauth_connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    initial_plan_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    initial_tasks_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    plan_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    current_task_id VARBINARY(80) NULL,
    last_receipt_task_id VARBINARY(80) NULL,
    last_resolution_task_id VARBINARY(80) NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    task_count INT UNSIGNED NOT NULL,
    accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
    blocked_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (queue_id),
    KEY idx_development_work_owner (project_key,admin_id,oauth_connection_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Append-only immutable plan revision intents.
CREATE TABLE IF NOT EXISTS development_work_appends (
    queue_id VARBINARY(80) NOT NULL,
    plan_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_plan_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tasks_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    task_count INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (queue_id,plan_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS development_work_items (
    queue_id VARBINARY(80) NOT NULL,
    task_id VARBINARY(80) NOT NULL,
    ordinal INT UNSIGNED NOT NULL,
    plan_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    spec_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_task_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reviewer_task_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    input_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    acceptance_contract_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    deps_json VARBINARY(4096) NOT NULL,
    priority TINYINT UNSIGNED NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    claim_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    current_run_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_ack VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    delivered_at DATETIME NULL,
    claim_deadline DATETIME NULL,
    attention_at DATETIME NULL,
    attention_ack VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    watchdog_due_at DATETIME NULL,
    watchdog_epoch INT UNSIGNED NOT NULL DEFAULT 0,
    last_watch_ack VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (queue_id,task_id),
    UNIQUE KEY uq_development_work_ordinal (queue_id,ordinal),
    KEY idx_development_work_ready (queue_id,state,priority,ordinal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS development_work_receipts (
    queue_id VARBINARY(80) NOT NULL,
    task_id VARBINARY(80) NOT NULL,
    spec_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_json VARBINARY(4096) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (queue_id,task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS development_work_resolutions (
    queue_id VARBINARY(80) NOT NULL,
    task_id VARBINARY(80) NOT NULL,
    unknown_receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    resolution_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_json VARBINARY(4096) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (queue_id,task_id),
    UNIQUE KEY uq_development_work_resolution (resolution_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS development_work_lane (
    lane_key VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    queue_id VARBINARY(80) NULL,
    PRIMARY KEY (lane_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO development_work_lane (lane_key,queue_id) VALUES ('DEVELOPMENT',NULL);

-- Separate development readiness subscriptions. Existing webhook transport.
CREATE TABLE IF NOT EXISTS development_work_events (
    subscription_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    single_consumer_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    oauth_connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    project_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    queue_id VARBINARY(80) NOT NULL,
    event_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    arguments_json VARBINARY(256) NOT NULL,
    callback_url VARBINARY(2048) NOT NULL,
    signing_secret VARBINARY(128) NOT NULL,
    previous_secret VARBINARY(128) NULL,
    previous_secret_until DATETIME NULL,
    verified_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    pending_event_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    pending_body VARBINARY(2048) NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    lease_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_until DATETIME NULL,
    last_status SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (subscription_id),
    UNIQUE KEY uq_development_work_single_consumer (single_consumer_key),
    KEY idx_development_work_events_due (project_key,is_active,expires_at,next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
