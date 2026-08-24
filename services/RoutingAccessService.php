<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/ManagerAuthService.php';

class RoutingAccessService
{
    private static $schemaReady=false;

    public static function ensureSchema(): void
    {
        if(self::$schemaReady)return;
        ProjectAccessService::ensureSchema();
        $pdo=ConversationDb::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS manager_groups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            group_key VARCHAR(64) NOT NULL,
            display_name VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_manager_groups_project_key (project_id, group_key),
            KEY idx_manager_groups_project (project_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS manager_group_members (
            group_id BIGINT UNSIGNED NOT NULL,
            manager_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, manager_id),
            KEY idx_manager_group_members_manager (manager_id, group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS conversation_sources (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            source_key VARCHAR(96) NOT NULL,
            display_name VARCHAR(191) NOT NULL,
            channel VARCHAR(32) NULL,
            primary_group_id BIGINT UNSIGNED NULL,
            fallback_mode VARCHAR(16) NOT NULL DEFAULT 'immediate',
            fallback_group_id BIGINT UNSIGNED NULL,
            fallback_after_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_conversation_sources_project_key (project_id, source_key),
            KEY idx_conversation_sources_project (project_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("ALTER TABLE conversations ADD COLUMN IF NOT EXISTS source_id BIGINT UNSIGNED NULL AFTER project_key");
        self::$schemaReady=true;
    }

    public static function canSeeConversation(int $managerId, array $conversation): bool
    {
        self::ensureSchema();
        $projectKey=(string)($conversation['project_key']??'');
        if(!ProjectAccessService::canAccess($managerId,$projectKey))return false;

        $manager=ManagerAuthService::byId($managerId);
        if($manager && (string)($manager['role']??'manager')==='admin')return true;

        $sourceId=(int)($conversation['source_id']??0);
        if($sourceId<=0)return true;

        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('SELECT primary_group_id,fallback_mode,fallback_group_id,fallback_after_minutes FROM conversation_sources WHERE id=? AND is_active=1 LIMIT 1');
        $q->execute([$sourceId]);$source=$q->fetch();
        if(!$source)return true;

        $primary=(int)($source['primary_group_id']??0);
        if($primary<=0)return true;
        if(self::inGroup($managerId,$primary))return true;

        $mode=(string)($source['fallback_mode']??'immediate');
        if($mode==='none')return false;
        $fallback=(int)($source['fallback_group_id']??0);
        if($fallback<=0 || !self::inGroup($managerId,$fallback))return false;
        if($mode==='immediate')return true;
        if($mode!=='delayed')return false;

        $minutes=max(0,(int)($source['fallback_after_minutes']??0));
        $started=(string)($conversation['waiting_since']??$conversation['last_message_at']??$conversation['started_at']??'');
        if($started==='')return false;
        $ts=strtotime($started);if($ts===false)return false;
        return time() >= $ts + ($minutes*60);
    }

    public static function sourceId(string $projectKey,string $sourceKey,string $channel=''): int
    {
        self::ensureSchema();
        $projectId=ProjectAccessService::projectIdByKey($projectKey);if($projectId<=0)return 0;
        $sourceKey=trim($sourceKey);if($sourceKey==='')return 0;
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('SELECT id FROM conversation_sources WHERE project_id=? AND source_key=? AND is_active=1 LIMIT 1');
        $q->execute([$projectId,$sourceKey]);$id=(int)$q->fetchColumn();if($id)return$id;
        $q=$pdo->prepare('INSERT INTO conversation_sources (project_id,source_key,display_name,channel,fallback_mode) VALUES (?,?,?,?,?)');
        $q->execute([$projectId,$sourceKey,$sourceKey,$channel!==''?$channel:null,'immediate']);
        return(int)$pdo->lastInsertId();
    }

    public static function groupsForManager(int $managerId): array
    {
        self::ensureSchema();
        $q=ConversationDb::connection()->prepare('SELECT g.id,g.project_id,g.group_key,g.display_name FROM manager_groups g JOIN manager_group_members gm ON gm.group_id=g.id WHERE gm.manager_id=? AND g.is_active=1 ORDER BY g.display_name');
        $q->execute([$managerId]);return$q->fetchAll();
    }

    private static function inGroup(int $managerId,int $groupId): bool
    {
        $q=ConversationDb::connection()->prepare('SELECT 1 FROM manager_group_members WHERE manager_id=? AND group_id=? LIMIT 1');
        $q->execute([$managerId,$groupId]);return(bool)$q->fetchColumn();
    }
}
