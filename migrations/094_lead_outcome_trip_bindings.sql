CREATE TABLE IF NOT EXISTS lead_outcome_trip_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    trip_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    profile_version INT UNSIGNED NULL,
    profile_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    outcome_json VARBINARY(8192) NOT NULL,
    outcome_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_json VARBINARY(2048) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY lead_outcome_trip_operation (manager_id,operation_id),
    KEY lead_outcome_trip_conversation (conversation_id,id),
    KEY lead_outcome_trip_profile (conversation_id,profile_version),
    CONSTRAINT lead_outcome_trip_conversation_fk FOREIGN KEY (conversation_id)
        REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT lead_outcome_trip_profile_fk FOREIGN KEY (conversation_id,profile_version)
        REFERENCES manager_request_profiles(conversation_id,version) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
