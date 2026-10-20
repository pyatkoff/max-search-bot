-- Private link to the prepared source; only explicit manager previews create it.
ALTER TABLE manager_ai_drafts
    ADD COLUMN IF NOT EXISTS preparation_job_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;
