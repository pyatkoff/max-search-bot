UPDATE manager_assignments a
JOIN conversations c ON c.id=a.conversation_id
SET a.released_at=NOW()
WHERE a.released_at IS NULL
  AND (c.status<>'manager' OR c.manager_id IS NULL OR a.manager_id<>c.manager_id);

UPDATE manager_assignments a
JOIN (
    SELECT conversation_id,MAX(id) AS keep_id
    FROM manager_assignments
    WHERE released_at IS NULL
    GROUP BY conversation_id
    HAVING COUNT(*)>1
) duplicates ON duplicates.conversation_id=a.conversation_id
SET a.released_at=NOW()
WHERE a.released_at IS NULL
  AND a.id<>duplicates.keep_id;

CREATE TABLE IF NOT EXISTS active_manager_assignment_guards (
    conversation_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM active_manager_assignment_guards;

INSERT INTO active_manager_assignment_guards (conversation_id,assignment_id)
SELECT conversation_id,MAX(id)
FROM manager_assignments
WHERE released_at IS NULL
GROUP BY conversation_id;

DROP TRIGGER IF EXISTS trg_manager_assignments_active_insert;
CREATE TRIGGER trg_manager_assignments_active_insert
BEFORE INSERT ON manager_assignments
FOR EACH ROW
INSERT INTO active_manager_assignment_guards (conversation_id,assignment_id)
SELECT NEW.conversation_id,NEW.id
WHERE NEW.released_at IS NULL;

DROP TRIGGER IF EXISTS trg_manager_assignments_active_update_add;
CREATE TRIGGER trg_manager_assignments_active_update_add
BEFORE UPDATE ON manager_assignments
FOR EACH ROW
INSERT INTO active_manager_assignment_guards (conversation_id,assignment_id)
SELECT NEW.conversation_id,NEW.id
WHERE NEW.released_at IS NULL
  AND (OLD.released_at IS NOT NULL OR OLD.conversation_id<>NEW.conversation_id);

DROP TRIGGER IF EXISTS trg_manager_assignments_active_update_release;
CREATE TRIGGER trg_manager_assignments_active_update_release
AFTER UPDATE ON manager_assignments
FOR EACH ROW
DELETE FROM active_manager_assignment_guards
WHERE conversation_id=OLD.conversation_id
  AND OLD.released_at IS NULL
  AND (NEW.released_at IS NOT NULL OR OLD.conversation_id<>NEW.conversation_id);

DROP TRIGGER IF EXISTS trg_manager_assignments_active_delete;
CREATE TRIGGER trg_manager_assignments_active_delete
AFTER DELETE ON manager_assignments
FOR EACH ROW
DELETE FROM active_manager_assignment_guards
WHERE conversation_id=OLD.conversation_id
  AND OLD.released_at IS NULL;
