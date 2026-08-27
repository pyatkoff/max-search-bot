CREATE TABLE IF NOT EXISTS lead_stages (
    stage_key VARCHAR(32) NOT NULL PRIMARY KEY,
    display_name VARCHAR(96) NOT NULL,
    color VARCHAR(16) NOT NULL DEFAULT '#64748b',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_terminal TINYINT(1) NOT NULL DEFAULT 0,
    is_won TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lead_stages_order (is_active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO lead_stages (stage_key,display_name,color,sort_order,is_terminal,is_won) VALUES
('new','Новый лид','#2563eb',10,0,0),
('working','В работе','#0f766e',20,0,0),
('clarifying','Уточняем','#7c3aed',30,0,0),
('selecting','Подбираем','#0284c7',40,0,0),
('offer_sent','Предложение отправлено','#d97706',50,0,0),
('waiting_customer','Ждём клиента','#ca8a04',60,0,0),
('follow_up','Вернуться позже','#64748b',70,0,0),
('booking','Бронирование','#9333ea',80,0,0),
('won','Продано','#16a34a',90,1,1),
('lost','Закрыто без продажи','#dc2626',100,1,0);

ALTER TABLE conversations
    ADD COLUMN lead_stage_key VARCHAR(32) NOT NULL DEFAULT 'new' AFTER status,
    ADD KEY idx_conversations_lead_stage (lead_stage_key,last_message_at);

CREATE TABLE IF NOT EXISTS lead_tags (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tag_key VARCHAR(64) NOT NULL,
    display_name VARCHAR(96) NOT NULL,
    color VARCHAR(16) NOT NULL DEFAULT '#64748b',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_lead_tag_key (tag_key),
    KEY idx_lead_tags_order (is_active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_lead_tags (
    conversation_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    added_by_manager_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id,tag_id),
    KEY idx_conversation_lead_tags_tag (tag_id,conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
