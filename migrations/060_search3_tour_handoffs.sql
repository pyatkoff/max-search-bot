-- Capabilities only. Native inquiry/message/history ownership stays unchanged.
CREATE TABLE IF NOT EXISTS search3_tour_handoffs (
    handoff_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    project_key VARCHAR(64) NOT NULL,
    producer_key VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_json MEDIUMTEXT NOT NULL,
    telegram_token CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    max_token CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bot_bindings_json TEXT NOT NULL,
    is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (handoff_id),
    UNIQUE KEY search3_operation (project_key,operation_id),
    UNIQUE KEY search3_telegram_token (telegram_token),
    UNIQUE KEY search3_max_token (max_token),
    KEY search3_bounds (project_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
