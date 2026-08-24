<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/RoutingAccessService.php';

class ManagerAvailabilityService
{
    public static function ensureSchema(): void
    {
        ConversationDb::connection()->exec('ALTER TABLE managers ADD COLUMN IF NOT EXISTS is_working TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
    }

    public static function setWorking(int $managerId, bool $working): bool
    {
        self::ensureSchema();
        $q=ConversationDb::connection()->prepare('UPDATE managers SET is_working=? WHERE id=? AND is_active=1');
        $q->execute([$working?1:0,$managerId]);
        return $q->rowCount()>=0;
    }

    public static function isWorking(int $managerId): bool
    {
        self::ensureSchema();
        $q=ConversationDb::connection()->prepare('SELECT is_working FROM managers WHERE id=? AND is_active=1 LIMIT 1');
        $q->execute([$managerId]);
        return (bool)$q->fetchColumn();
    }

    public static function anyWorkingForConversation(array $conversation): bool
    {
        self::ensureSchema();
        $projectKey=trim((string)($conversation['project_key']??''));
        if($projectKey==='')return false;
        $projectId=ProjectAccessService::projectIdByKey($projectKey);
        if($projectId<=0)return false;
        $q=ConversationDb::connection()->prepare('SELECT DISTINCT m.id FROM managers m JOIN manager_projects mp ON mp.manager_id=m.id WHERE mp.project_id=? AND m.is_active=1 AND m.is_working=1');
        $q->execute([$projectId]);
        foreach($q->fetchAll() as $row){
            $id=(int)$row['id'];
            if(RoutingAccessService::canSeeConversation($id,$conversation))return true;
        }
        return false;
    }
}
