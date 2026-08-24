<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectConfig.php';

class ProjectAccessService
{
    private static $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) return;
        $pdo = ConversationDb::connection();

        // Schema is managed by versioned migrations. Runtime initialization below
        // only keeps project/access data in sync and does not alter table structure.
        self::ensureCurrentProject();
        $admins = (int)$pdo->query("SELECT COUNT(*) FROM managers WHERE is_active=1 AND role='admin'")->fetchColumn();
        if ($admins === 0) {
            $first = (int)$pdo->query("SELECT id FROM managers WHERE is_active=1 ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($first > 0) $pdo->prepare("UPDATE managers SET role='admin' WHERE id=?")->execute([$first]);
        }
        $pdo->exec("INSERT IGNORE INTO manager_projects (manager_id,project_id)
            SELECT m.id,p.id FROM managers m CROSS JOIN projects p WHERE m.is_active=1 AND m.role='admin' AND p.is_active=1");

        // A live manager with no project sees an empty panel. While the installation has
        // exactly one active project, there is no ambiguity: attach that project automatically.
        $activeProjectCount = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE is_active=1")->fetchColumn();
        if ($activeProjectCount === 1) {
            $projectId = (int)$pdo->query("SELECT id FROM projects WHERE is_active=1 LIMIT 1")->fetchColumn();
            if ($projectId > 0) {
                $q = $pdo->prepare("INSERT IGNORE INTO manager_projects (manager_id,project_id)
                    SELECT m.id,? FROM managers m
                    LEFT JOIN manager_projects mp ON mp.manager_id=m.id
                    WHERE m.is_active=1 AND mp.manager_id IS NULL");
                $q->execute([$projectId]);
            }
        }
        self::$schemaReady = true;
    }

    public static function ensureCurrentProject(): int
    {
        $pdo = ConversationDb::connection();
        $key = ProjectConfig::projectId();
        $name = (string)ProjectConfig::get('brand.name', $key);
        $q = $pdo->prepare('INSERT INTO projects (project_key,display_name,is_active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),is_active=1');
        $q->execute([$key,$name !== '' ? $name : $key]);
        $q = $pdo->prepare('SELECT id FROM projects WHERE project_key=? LIMIT 1');
        $q->execute([$key]);
        return (int)$q->fetchColumn();
    }

    public static function projectIdByKey(string $projectKey): int
    {
        self::ensureSchema();
        $q=ConversationDb::connection()->prepare('SELECT id FROM projects WHERE project_key=? AND is_active=1 LIMIT 1');
        $q->execute([trim($projectKey)]);
        return(int)$q->fetchColumn();
    }

    public static function projectsForManager(int $managerId): array
    {
        self::ensureSchema();
        $q = ConversationDb::connection()->prepare("SELECT p.id,p.project_key,p.display_name
            FROM projects p JOIN manager_projects mp ON mp.project_id=p.id
            WHERE mp.manager_id=? AND p.is_active=1 ORDER BY p.display_name,p.id");
        $q->execute([$managerId]);
        return $q->fetchAll();
    }

    public static function canAccess(int $managerId, string $projectKey): bool
    {
        if ($managerId <= 0 || $projectKey === '') return false;
        self::ensureSchema();
        $q = ConversationDb::connection()->prepare("SELECT 1 FROM manager_projects mp JOIN projects p ON p.id=mp.project_id
            WHERE mp.manager_id=? AND p.project_key=? AND p.is_active=1 LIMIT 1");
        $q->execute([$managerId,$projectKey]);
        return (bool)$q->fetchColumn();
    }

    public static function defaultProjectKey(int $managerId): string
    {
        $projects = self::projectsForManager($managerId);
        return $projects ? (string)$projects[0]['project_key'] : '';
    }
}
