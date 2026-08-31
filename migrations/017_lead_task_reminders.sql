ALTER TABLE lead_tasks
  ADD COLUMN reminder_attempted_at_utc DATETIME NULL AFTER completed_at_utc,
  ADD COLUMN reminder_notified_at_utc DATETIME NULL AFTER reminder_attempted_at_utc,
  ADD KEY idx_lead_tasks_reminder_due (status, reminder_notified_at_utc, reminder_attempted_at_utc, due_at_utc);
