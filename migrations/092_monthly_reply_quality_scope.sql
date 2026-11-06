-- Forward-only capacity for an optional explicitly consented historical quality read.
-- Preserve all existing grants and their scopes; refresh never adds a capability.
ALTER TABLE manager_preparation_oauth_grants
    MODIFY COLUMN scopes VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
