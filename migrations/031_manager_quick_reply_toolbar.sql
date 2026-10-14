-- The existing per-account favorites are the canonical toolbar selection.
-- Retain every legacy selection. Initial ties are deterministic; a manager's
-- explicit reorder sets positions, and new selections append at the end.
SET @quick_reply_sort_column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_quick_reply_favorites' AND COLUMN_NAME='sort_order'
);
SET @quick_reply_sort_column_sql = IF(
    @quick_reply_sort_column_exists=0,
    'ALTER TABLE manager_quick_reply_favorites ADD COLUMN sort_order BIGINT UNSIGNED NOT NULL DEFAULT 0',
    'DO 0'
);
PREPARE quick_reply_sort_column_stmt FROM @quick_reply_sort_column_sql;
EXECUTE quick_reply_sort_column_stmt;
DEALLOCATE PREPARE quick_reply_sort_column_stmt;

SET @quick_reply_sort_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_quick_reply_favorites' AND INDEX_NAME='idx_manager_reply_toolbar_order'
);
SET @quick_reply_sort_index_sql = IF(
    @quick_reply_sort_index_exists=0,
    'ALTER TABLE manager_quick_reply_favorites ADD KEY idx_manager_reply_toolbar_order (manager_id,sort_order,created_at,reply_id)',
    'DO 0'
);
PREPARE quick_reply_sort_index_stmt FROM @quick_reply_sort_index_sql;
EXECUTE quick_reply_sort_index_stmt;
DEALLOCATE PREPARE quick_reply_sort_index_stmt;

-- Monotonic account revision and transaction lock serialize independent tabs.
-- Empty accounts remain revision zero until their first explicit mutation.
CREATE TABLE IF NOT EXISTS manager_quick_reply_toolbar_states (
    manager_id BIGINT UNSIGNED NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
