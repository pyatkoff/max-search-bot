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

DROP TABLE IF EXISTS active_manager_assignment_guards;
