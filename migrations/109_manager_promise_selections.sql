-- Business changes advance this revision; reminder bookkeeping does not.
ALTER TABLE lead_tasks ADD COLUMN business_revision BIGINT UNSIGNED NOT NULL DEFAULT 1;

-- An explicit pre-send association with one immutable, complete publication.
-- Task status remains exclusively owned by LeadTaskService. No task/publication
-- FK is added: deletion leaves an auditable intent and cannot recreate a task.
CREATE TABLE IF NOT EXISTS manager_promise_selection_intents (
    intent_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    promise_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    task_id BIGINT UNSIGNED NOT NULL,
    publication_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    publication_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    manifest_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    trip_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_version INT UNSIGNED NOT NULL,
    profile_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_context_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_message_id BIGINT UNSIGNED NOT NULL,
    source_message_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_author_manager_id BIGINT UNSIGNED NOT NULL,
    task_revision BIGINT UNSIGNED NOT NULL,
    scope_json VARCHAR(4096) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_by_manager_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    active_promise_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    active_publication_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    evidence_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    PRIMARY KEY (intent_id),
    UNIQUE KEY manager_promise_selection_active_promise (active_promise_id),
    UNIQUE KEY manager_promise_selection_active_publication (active_publication_id),
    KEY manager_promise_selection_trip_history (conversation_id,trip_id,created_at),
    KEY manager_promise_selection_publication_history (conversation_id,publication_id),
    CONSTRAINT manager_promise_selection_conversation_fk FOREIGN KEY (conversation_id)
        REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- One receipt namespace for attach and cancel; reconciliation never resubmits.
CREATE TABLE IF NOT EXISTS manager_promise_selection_operations (
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    intent_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id,operation_id),
    KEY manager_promise_selection_operation_conversation (conversation_id),
    KEY manager_promise_selection_operation_intent (intent_id),
    CONSTRAINT manager_promise_selection_operation_conversation_fk FOREIGN KEY (conversation_id)
        REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT manager_promise_selection_operation_intent_fk FOREIGN KEY (intent_id)
        REFERENCES manager_promise_selection_intents(intent_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
