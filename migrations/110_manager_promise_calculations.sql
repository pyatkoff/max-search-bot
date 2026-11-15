-- Explicit ordinary-composer result, never a second sender or task owner.
CREATE TABLE IF NOT EXISTS manager_promise_calculation_intents (
    intent_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    promise_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    task_id BIGINT UNSIGNED NOT NULL,
    task_revision BIGINT UNSIGNED NOT NULL,
    trip_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_version INT UNSIGNED NOT NULL,
    profile_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_message_id BIGINT UNSIGNED NOT NULL,
    source_message_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_author_manager_id BIGINT UNSIGNED NOT NULL,
    scope_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    composition_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by_manager_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    active_promise_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    evidence_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    PRIMARY KEY (intent_id),
    UNIQUE KEY manager_calculation_active_promise (active_promise_id),
    KEY manager_calculation_task_history (conversation_id,task_id,created_at),
    CONSTRAINT manager_calculation_conversation_fk FOREIGN KEY (conversation_id)
        REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS manager_promise_calculation_operations (
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    intent_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id,operation_id),
    CONSTRAINT manager_calculation_operation_intent_fk FOREIGN KEY (intent_id)
        REFERENCES manager_promise_calculation_intents(intent_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- The existing manager_send_attempts row alone owns execution. These rows bind
-- its immutable ID to an exact part and retain native proof before local mirror.
CREATE TABLE IF NOT EXISTS manager_promise_calculation_deliveries (
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    intent_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    part_index INT UNSIGNED NOT NULL,
    operation_kind VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    active_part TINYINT UNSIGNED NULL,
    native_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    native_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    native_identity_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    message_id BIGINT UNSIGNED NULL,
    message_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (manager_id,operation_id),
    UNIQUE KEY manager_calculation_active_part (intent_id,part_index,active_part),
    UNIQUE KEY manager_calculation_native_identity (native_identity_sha256),
    CONSTRAINT manager_calculation_delivery_intent_fk FOREIGN KEY (intent_id)
        REFERENCES manager_promise_calculation_intents(intent_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
