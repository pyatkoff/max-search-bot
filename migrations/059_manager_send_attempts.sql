-- Text-send execution receipts only: no payload, queue, retry or transcript.
CREATE TABLE IF NOT EXISTS manager_send_attempts (
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    context_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    state VARCHAR(16) NOT NULL,
    result_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id, operation_id),
    KEY idx_manager_send_attempt_conversation (conversation_id, manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
