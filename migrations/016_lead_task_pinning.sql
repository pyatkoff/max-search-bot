ALTER TABLE lead_tasks
  ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD KEY idx_lead_tasks_pinned (conversation_id, status, is_pinned, due_at_utc);
