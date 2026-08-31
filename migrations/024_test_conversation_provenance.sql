ALTER TABLE conversations
  ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN test_source VARCHAR(64) NULL AFTER is_test,
  ADD COLUMN test_reason VARCHAR(255) NULL AFTER test_source,
  ADD INDEX idx_conversations_is_test (is_test);
