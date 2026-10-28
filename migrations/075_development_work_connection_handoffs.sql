-- Administrative metadata recovery only. Never a worker receipt or a replay.
CREATE TABLE IF NOT EXISTS development_work_connection_handoffs (
    queue_id VARBINARY(80) NOT NULL,
    handoff_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    old_connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    new_connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    task_id VARBINARY(80) NOT NULL,
    spec_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_plan_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_pending_event_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    retirement_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    intent_json VARBINARY(4096) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (queue_id),
    UNIQUE KEY uq_development_work_handoff (handoff_sha256),
    UNIQUE KEY uq_development_work_handoff_retirement (retirement_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
