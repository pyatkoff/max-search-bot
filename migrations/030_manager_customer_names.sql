-- Manager-only presentation labels; customers/channel identities remain untouched.
CREATE TABLE IF NOT EXISTS manager_customer_names (
    customer_id BIGINT UNSIGNED NOT NULL,
    working_name VARCHAR(120) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by_manager_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (customer_id),
    CONSTRAINT fk_manager_customer_names_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every successful version is both protected change history and its durable
-- account-scoped receipt. Restore keeps its version; retry never repeats a write.
CREATE TABLE IF NOT EXISTS manager_customer_name_history (
    customer_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    actor_manager_id BIGINT UNSIGNED NOT NULL,
    operation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    before_name VARCHAR(120) NULL,
    after_name VARCHAR(120) NULL,
    result_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (customer_id, version),
    UNIQUE KEY uq_customer_name_operation (actor_manager_id, operation_id),
    CONSTRAINT fk_manager_customer_name_history_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
