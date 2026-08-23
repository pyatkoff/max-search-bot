SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(191) NULL,
    phone VARCHAR(64) NULL,
    email VARCHAR(191) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customers_phone (phone),
    KEY idx_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS managers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    login VARCHAR(191) NOT NULL,
    display_name VARCHAR(191) NULL,
    email VARCHAR(191) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_managers_login (login),
    KEY idx_managers_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_channels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    project_key VARCHAR(64) NOT NULL DEFAULT 'default',
    channel VARCHAR(32) NOT NULL,
    external_user_id VARCHAR(191) NOT NULL,
    external_chat_id VARCHAR(191) NULL,
    username VARCHAR(191) NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_channel_identity (project_key, channel, external_user_id),
    KEY idx_customer_channels_customer (customer_id),
    KEY idx_customer_channels_chat (project_key, channel, external_chat_id),
    CONSTRAINT fk_customer_channels_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    customer_channel_id BIGINT UNSIGNED NULL,
    project_key VARCHAR(64) NOT NULL DEFAULT 'default',
    channel VARCHAR(32) NOT NULL,
    external_chat_id VARCHAR(191) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ai',
    manager_id BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_at DATETIME NULL,
    closed_at DATETIME NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conversations_customer (customer_id),
    KEY idx_conversations_channel (project_key, channel, external_chat_id),
    KEY idx_conversations_status (status, last_message_at),
    KEY idx_conversations_manager (manager_id, status),
    CONSTRAINT fk_conversations_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_conversations_customer_channel
        FOREIGN KEY (customer_channel_id) REFERENCES customer_channels(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_conversations_manager
        FOREIGN KEY (manager_id) REFERENCES managers(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    direction VARCHAR(16) NOT NULL,
    sender_type VARCHAR(16) NOT NULL,
    sender_id VARCHAR(191) NULL,
    channel VARCHAR(32) NOT NULL,
    external_message_id VARCHAR(191) NULL,
    text MEDIUMTEXT NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_conversation (conversation_id, id),
    KEY idx_messages_created (created_at),
    KEY idx_messages_external (channel, external_message_id),
    CONSTRAINT fk_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manager_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    manager_id BIGINT UNSIGNED NOT NULL,
    assignment_type VARCHAR(32) NOT NULL DEFAULT 'manual',
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_at DATETIME NULL,
    metadata_json JSON NULL,
    PRIMARY KEY (id),
    KEY idx_manager_assignments_conversation (conversation_id, assigned_at),
    KEY idx_manager_assignments_manager (manager_id, released_at),
    CONSTRAINT fk_manager_assignments_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_manager_assignments_manager
        FOREIGN KEY (manager_id) REFERENCES managers(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    actor_type VARCHAR(32) NULL,
    actor_id VARCHAR(191) NULL,
    payload_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conversation_events_conversation (conversation_id, id),
    KEY idx_conversation_events_type (event_type, created_at),
    CONSTRAINT fk_conversation_events_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
