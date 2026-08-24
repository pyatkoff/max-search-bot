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
}
