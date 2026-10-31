CREATE TABLE IF NOT EXISTS manager_request_profiles (
    conversation_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    trip_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    boundary_message_id BIGINT UNSIGNED NOT NULL,
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    context_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    profile_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    changed_fields_json VARBINARY(2048) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (conversation_id,version),
    UNIQUE KEY manager_request_profile_operation (manager_id,operation_id),
    CONSTRAINT manager_request_profile_conversation_fk FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
