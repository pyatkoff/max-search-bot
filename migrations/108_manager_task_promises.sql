CREATE TABLE IF NOT EXISTS manager_task_promises (
    promise_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    trip_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_message_id BIGINT UNSIGNED NOT NULL,
    source_message_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_author_manager_id BIGINT UNSIGNED NOT NULL,
    profile_version INT UNSIGNED NOT NULL,
    profile_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_context_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    task_id BIGINT UNSIGNED NOT NULL,
    deadline_json VARBINARY(512) NOT NULL,
    scope_json VARCHAR(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_by_manager_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (promise_id),
    UNIQUE KEY manager_task_promise_source (conversation_id,trip_id,kind,source_message_id),
    UNIQUE KEY manager_task_promise_task (task_id),
    CONSTRAINT manager_task_promise_conversation_fk FOREIGN KEY (conversation_id)
        REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Deliberately no task_id foreign key: a missing task retains its immutable
-- binding, and the service refuses reuse instead of creating another task.
-- This also leaves the existing conversation/task purge order unchanged.
CREATE TABLE IF NOT EXISTS manager_task_promise_operations (
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    promise_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id,operation_id),
    KEY manager_task_promise_operation_conversation (conversation_id),
    KEY manager_task_promise_operation_promise (promise_id),
    CONSTRAINT manager_task_promise_operation_conversation_fk FOREIGN KEY (conversation_id)
        REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT manager_task_promise_operation_promise_fk FOREIGN KEY (promise_id)
        REFERENCES manager_task_promises(promise_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
