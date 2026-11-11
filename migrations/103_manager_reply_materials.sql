-- Forward-only: legacy text replies remain attached to the installation's
-- original project at the service boundary; no existing content is rewritten.
ALTER TABLE manager_quick_replies
    ADD COLUMN project_id BIGINT UNSIGNED NULL,
    ADD COLUMN source_label VARCHAR(500) NOT NULL DEFAULT '',
    ADD COLUMN reviewed_at DATETIME NULL,
    ADD COLUMN materials_json TEXT NULL,
    ADD KEY idx_manager_reply_project (project_id, scope, owner_manager_id, id);

ALTER TABLE manager_quick_reply_operations
    ADD COLUMN project_id BIGINT UNSIGNED NULL;
