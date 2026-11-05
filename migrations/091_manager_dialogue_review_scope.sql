-- Preserve every existing grant while allowing the explicitly consented
-- current-dialogue review scope alongside the existing capabilities.
ALTER TABLE manager_preparation_oauth_grants
    MODIFY COLUMN scopes VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
