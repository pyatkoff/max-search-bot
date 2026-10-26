-- Derived private analytical indexes/profiles. Original source journal stays canonical.
CREATE TABLE IF NOT EXISTS modeled_conversion_profiles_state (
 project_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
 journal_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
 ticks BIGINT UNSIGNED NOT NULL DEFAULT 0,
 last_receipt_json TEXT NULL,
 last_error_at_unix BIGINT UNSIGNED NULL,
 last_error_category VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS modeled_conversion_profile_sources (
 project_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 platform VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 customer_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 conversation_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 journal_id BIGINT UNSIGNED NOT NULL,
 source_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(project_key,source_kind,source_id),
 KEY ix_profile_scope(project_key,platform,customer_key,conversation_id,journal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS modeled_conversion_profile_versions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 project_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 platform VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 customer_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 conversation_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 journal_cursor BIGINT UNSIGNED NOT NULL,
 observed_at_unix BIGINT UNSIGNED NOT NULL,
 input_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 profile_json MEDIUMTEXT NOT NULL,
 UNIQUE KEY uq_profile_scope_cursor(project_key,platform,customer_key,conversation_id,journal_cursor),
 KEY ix_profile_scope_time(project_key,platform,customer_key,conversation_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
