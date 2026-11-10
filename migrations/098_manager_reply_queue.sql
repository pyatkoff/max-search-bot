-- Explicit staff decisions only. Current state/audit remains in conversation_events.
CREATE TABLE IF NOT EXISTS manager_reply_queue_operations (
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id,operation_id),
    KEY idx_reply_queue_operation_conversation (conversation_id,manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
