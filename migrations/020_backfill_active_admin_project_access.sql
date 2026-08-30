INSERT IGNORE INTO manager_projects (manager_id, project_id)
SELECT m.id, p.id
FROM managers m
CROSS JOIN projects p
WHERE m.is_active = 1
  AND m.role = 'admin'
  AND p.is_active = 1;
