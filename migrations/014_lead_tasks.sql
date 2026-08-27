CREATE TABLE IF NOT EXISTS lead_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  due_at_utc DATETIME NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'open',
  assigned_manager_id BIGINT UNSIGNED NULL,
  created_by_manager_id BIGINT UNSIGNED NOT NULL,
  completed_at_utc DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lead_tasks_conversation (conversation_id, status),
  KEY idx_lead_tasks_due (status, due_at_utc),
  KEY idx_lead_tasks_assignee (assigned_manager_id, status, due_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
