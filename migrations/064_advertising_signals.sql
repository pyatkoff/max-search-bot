-- Private analytic observations; no dialogue state, goal activation or conversion transport.
CREATE TABLE IF NOT EXISTS advertising_signals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    counter_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    signal_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    handoff_event_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    manager_event_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    manager_message_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    occurred_at_unix BIGINT UNSIGNED NOT NULL,
    captured_at_unix BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_advertising_signal (project_key,source_key,platform,counter_id,conversation_id,signal_key),
    KEY idx_advertising_signal_subject (project_key,platform,counter_id,external_user_id,occurred_at_unix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
