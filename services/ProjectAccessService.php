<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectConfig.php';

class ProjectAccessService
{
    private static $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) return;
        // Schema is managed by versioned migrations. Runtime requests must not
        // silently change manager roles or project access.
        self::ensureCurrentProject();
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
