INSERT INTO conversation_events (conversation_id,event_type,actor_type,actor_id,payload_json)
SELECT c.id,'ai_resumed','system',NULL,'{"reason":"outside_hours_queue_repair","migration":"019_repair_outside_hours_waiting_manager.sql"}'
FROM conversations c
JOIN conversation_events e ON e.id=(
    SELECT MAX(e2.id)
    FROM conversation_events e2
    WHERE e2.conversation_id=c.id
      AND e2.event_type='waiting_manager'
)
WHERE c.status='waiting_manager'
  AND c.manager_id IS NULL
  AND e.payload_json LIKE '%"within_working_hours":false%';

UPDATE conversations c
JOIN conversation_events e ON e.id=(
    SELECT MAX(e2.id)
    FROM conversation_events e2
    WHERE e2.conversation_id=c.id
      AND e2.event_type='waiting_manager'
)
SET c.status='ai',c.manager_id=NULL
WHERE c.status='waiting_manager'
  AND c.manager_id IS NULL
  AND e.payload_json LIKE '%"within_working_hours":false%';
