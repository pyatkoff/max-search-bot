-- OAuth grants authorize preparation only; transcripts and raw credentials never live here.
CREATE TABLE IF NOT EXISTS manager_preparation_oauth_clients (
    client_id CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    redirect_uri VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registration_bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at BIGINT NOT NULL,
    PRIMARY KEY (client_id),
    KEY idx_preparation_oauth_registration (registration_bucket,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manager_preparation_oauth_grants (
    grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_id CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    resource VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scopes VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    revoked_at BIGINT NULL,
    PRIMARY KEY (grant_id),
    KEY idx_preparation_oauth_owner (admin_id,project_key,created_at),
    CONSTRAINT fk_preparation_oauth_client FOREIGN KEY (client_id)
        REFERENCES manager_preparation_oauth_clients(client_id) ON DELETE CASCADE,
    CONSTRAINT fk_preparation_oauth_admin FOREIGN KEY (admin_id)
        REFERENCES managers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manager_preparation_oauth_codes (
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    redirect_uri VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code_challenge CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at BIGINT NOT NULL,
    consumed_at BIGINT NULL,
    PRIMARY KEY (code_hash),
    KEY idx_preparation_oauth_codes_grant (grant_id),
    CONSTRAINT fk_preparation_oauth_code_grant FOREIGN KEY (grant_id)
        REFERENCES manager_preparation_oauth_grants(grant_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manager_preparation_oauth_tokens (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_kind VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at BIGINT NOT NULL,
    consumed_at BIGINT NULL,
    PRIMARY KEY (token_hash),
    KEY idx_preparation_oauth_tokens_grant (grant_id),
    CONSTRAINT fk_preparation_oauth_token_grant FOREIGN KEY (grant_id)
        REFERENCES manager_preparation_oauth_grants(grant_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
