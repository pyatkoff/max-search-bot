CREATE TABLE IF NOT EXISTS lead_close_reasons (
    reason_key VARCHAR(64) NOT NULL,
    display_name VARCHAR(96) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reason_key),
    KEY idx_lead_close_reasons_active_sort (is_active, sort_order, display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO lead_close_reasons (reason_key, display_name, sort_order, is_active) VALUES
('price', 'Цена', 10, 1),
('no_contact', 'Не удалось связаться', 20, 1),
('changed_plans', 'Изменились планы', 30, 1),
('bought_elsewhere', 'Купил в другом месте', 40, 1),
('dates', 'Не подошли даты', 50, 1),
('destination', 'Не подошло направление', 60, 1),
('documents', 'Документы/ограничения', 70, 1),
('other', 'Другое', 80, 1);
