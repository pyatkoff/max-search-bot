<?php
require_once __DIR__ . '/ConversationDb.php';

class AuditLogService
{
    public static function record(int $actorManagerId,string $action,string $entityType,string $entityId='',string $projectKey='',?array $before=null,?array $after=null): void
    {
        try{
            $q=ConversationDb::connection()->prepare('INSERT INTO admin_audit_log (actor_manager_id,action,entity_type,entity_id,project_key,before_json,after_json) VALUES (?,?,?,?,?,?,?)');
            $q->execute([
                $actorManagerId>0?$actorManagerId:null,
                $action,
                $entityType,
                $entityId!==''?$entityId:null,
                $projectKey!==''?$projectKey:null,
                $before===null?null:json_encode($before,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $after===null?null:json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]);
        }catch(Throwable $ignored){}
    }

    public static function recent(int $limit=100): array
    {
        $limit=max(1,min(500,$limit));
        $q=ConversationDb::connection()->query('SELECT id,actor_manager_id,action,entity_type,entity_id,project_key,before_json,after_json,created_at FROM admin_audit_log ORDER BY id DESC LIMIT '.$limit);
        return $q?$q->fetchAll():[];
    }

    /** Read-only, data-minimized projection for the admin UI. */
    public static function recentSummaries(int $limit=50): array
    {
        $limit=max(1,min(100,$limit));
        $q=ConversationDb::connection()->query("SELECT a.id,a.actor_manager_id,a.action,a.entity_type,a.entity_id,a.project_key,a.created_at,m.display_name AS actor_name,m.login AS actor_login FROM admin_audit_log a LEFT JOIN managers m ON m.id=a.actor_manager_id ORDER BY a.id DESC LIMIT {$limit}");
        return $q?$q->fetchAll():[];
    }
}
