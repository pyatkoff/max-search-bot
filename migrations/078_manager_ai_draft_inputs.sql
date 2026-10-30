-- Private immutable normalized input for a validated manual AI draft.
-- No media bytes, public export, runtime DDL or historical backfill.
CREATE TABLE IF NOT EXISTS manager_ai_draft_inputs (
    draft_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    input_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    input_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (draft_id),
    CONSTRAINT fk_manager_ai_draft_inputs_draft
        FOREIGN KEY (draft_id) REFERENCES manager_ai_drafts(draft_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
