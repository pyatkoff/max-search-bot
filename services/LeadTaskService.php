<?php
require_once __DIR__ . '/ConversationDb.php';

/**
 * Sales-workspace task/reminder foundation.
 * Technical conversation status and handoff are intentionally not touched here.
 */
class LeadTaskService
{
    public static function normalizeCreateInput(string $title, ?string $dueIso): array
    {
        $title=trim(preg_replace('/\s+/u',' ',$title)??$title);
        if($title===''||self::length($title)>255)return ['ok'=>false,'error'=>'invalid_title'];
        $dueUtc=null;
        if($dueIso!==null&&trim($dueIso)!==''){
            try{$d=new DateTimeImmutable(trim($dueIso));$dueUtc=$d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
            catch(Throwable $e){return ['ok'=>false,'error'=>'invalid_due_at'];}
        }
        return ['ok'=>true,'title'=>$title,'due_at_utc'=>$dueUtc];
    }

    public static function listForConversation(int $conversationId): array
    {
        if($conversationId<=0)return[];
        $q=ConversationDb::connection()->prepare("SELECT t.id,t.conversation_id,t.title,t.due_at_utc,t.status,t.assigned_manager_id,t.created_by_manager_id,t.completed_at_utc,t.created_at,t.updated_at,m.display_name AS assigned_manager_name FROM lead_tasks t LEFT JOIN managers m ON m.id=t.assigned_manager_id WHERE t.conversation_id=? ORDER BY CASE WHEN t.status='open' THEN 0 ELSE 1 END,CASE WHEN t.due_at_utc IS NULL THEN 1 ELSE 0 END,t.due_at_utc ASC,t.id DESC");
        $q->execute([$conversationId]);return$q->fetchAll()?:[];
    }

    public static function create(int $conversationId,string $title,?string $dueIso,int $createdByManagerId,?int $assignedManagerId=null): array
    {
        if($conversationId<=0||$createdByManagerId<=0)return ['ok'=>false,'error'=>'invalid_owner'];
        $input=self::normalizeCreateInput($title,$dueIso);if(empty($input['ok']))return$input;
        $assignedManagerId=(int)($assignedManagerId??0);if($assignedManagerId<=0)$assignedManagerId=$createdByManagerId;
        $q=ConversationDb::connection()->prepare("INSERT INTO lead_tasks (conversation_id,title,due_at_utc,status,assigned_manager_id,created_by_manager_id,created_at,updated_at) VALUES (?,?,?,'open',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $q->execute([$conversationId,$input['title'],$input['due_at_utc'],$assignedManagerId,$createdByManagerId]);
        return ['ok'=>true,'id'=>(int)ConversationDb::connection()->lastInsertId()];
    }

    public static function setCompleted(int $conversationId,int $taskId,bool $completed): bool
    {
        if($conversationId<=0||$taskId<=0)return false;
        $status=$completed?'done':'open';$completedSql=$completed?'UTC_TIMESTAMP()':'NULL';
        $q=ConversationDb::connection()->prepare("UPDATE lead_tasks SET status=?,completed_at_utc={$completedSql},updated_at=UTC_TIMESTAMP() WHERE id=? AND conversation_id=?");
        $q->execute([$status,$taskId,$conversationId]);return$q->rowCount()>0;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
    }
}
