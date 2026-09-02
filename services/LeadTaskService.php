<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/SalesPipelineService.php';

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

    /** Canonical business policy: closed sales leads may clean up existing tasks but cannot create new ones. */
    public static function canCreateForConversation(int $conversationId): bool
    {
        if($conversationId<=0)return false;
        $outcome=SalesPipelineService::outcomeForConversation($conversationId);
        return (string)($outcome['outcome']??'open')==='open';
    }

    /** Canonical business classification for task urgency in Europe/Kaliningrad. */
    public static function dueState($dueAtUtc,bool $overdue=false,?DateTimeImmutable $nowUtc=null): string
    {
        $dueAtUtc=trim((string)($dueAtUtc??''));
        if($dueAtUtc==='')return'unscheduled';
        if($overdue)return'overdue';
        try{
            $utc=new DateTimeZone('UTC');$local=new DateTimeZone('Europe/Kaliningrad');
            $due=new DateTimeImmutable($dueAtUtc,$utc);$now=$nowUtc?:new DateTimeImmutable('now',$utc);
            return$due->setTimezone($local)->format('Y-m-d')===$now->setTimezone($local)->format('Y-m-d')?'today':'upcoming';
        }catch(Throwable $ignored){return'upcoming';}
    }

    /** Canonical operational work buckets: overdue → today → soon/planned → no next action. */
    public static function operationalRank(string $dueState,bool $hasTask=true): int
    {
        if(!$hasTask)return 3;
        if($dueState==='overdue')return 0;
        if($dueState==='today')return 1;
        return 2;
    }

    public static function operationalState(string $dueState,bool $hasTask=true): string
    {
        if(!$hasTask)return 'none';
        if($dueState==='overdue')return 'overdue';
        if($dueState==='today')return 'today';
        return 'soon';
    }

    /** Canonical read-only projection of the most urgent task across an open-task set. */
    public static function operationalProjection(array $tasks): array
    {
        $best=null;
        foreach($tasks as $task){
            $state=self::dueState($task['due_at_utc']??null,!empty($task['overdue']));
            $rank=self::operationalRank($state,true);
            $due=trim((string)($task['due_at_utc']??''));
            $replace=$best===null||$rank<(int)$best['rank']||($rank===(int)$best['rank']&&$due!==''&&(empty($best['due_at_utc'])||strcmp($due,(string)$best['due_at_utc'])<0));
            if($replace)$best=['rank'=>$rank,'state'=>self::operationalState($state,true),'due_state'=>$state,'due_at_utc'=>$due!==''?$due:null];
        }
        return$best??['rank'=>self::operationalRank('none',false),'state'=>self::operationalState('none',false),'due_state'=>'none','due_at_utc'=>null];
    }

    /** Canonical priority order for open-task projections: pinned first, then nearest deadline. */
    public static function openTaskOrderSql(string $alias='t'): string
    {
        $alias=preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/',$alias)?$alias:'t';$p=$alias.'.';
        return "CASE WHEN {$p}is_pinned=1 THEN 0 ELSE 1 END,CASE WHEN {$p}due_at_utc IS NULL THEN 1 ELSE 0 END,{$p}due_at_utc ASC,{$p}id DESC";
    }

    public static function listForConversation(int $conversationId): array
    {
        if($conversationId<=0)return[];
        $order=self::openTaskOrderSql('t');
        $q=ConversationDb::connection()->prepare("SELECT t.id,t.conversation_id,t.title,t.due_at_utc,t.status,t.is_pinned,t.assigned_manager_id,t.created_by_manager_id,t.completed_at_utc,t.reminder_attempted_at_utc,t.reminder_notified_at_utc,t.created_at,t.updated_at,CASE WHEN t.status='open' AND t.due_at_utc IS NOT NULL AND t.due_at_utc<UTC_TIMESTAMP() THEN 1 ELSE 0 END AS overdue,m.display_name AS assigned_manager_name FROM lead_tasks t LEFT JOIN managers m ON m.id=t.assigned_manager_id WHERE t.conversation_id=? ORDER BY CASE WHEN t.status='open' THEN 0 ELSE 1 END,{$order}");
        $q->execute([$conversationId]);$rows=$q->fetchAll()?:[];
        foreach($rows as &$row){$row['is_pinned']=!empty($row['is_pinned']);$row['due_state']=self::dueState($row['due_at_utc']??null,!empty($row['overdue']));}unset($row);
        return$rows;
    }

    public static function create(int $conversationId,string $title,?string $dueIso,int $createdByManagerId,?int $assignedManagerId=null): array
    {
        if($conversationId<=0||$createdByManagerId<=0)return ['ok'=>false,'error'=>'invalid_owner'];
        if(!self::canCreateForConversation($conversationId))return ['ok'=>false,'error'=>'lead_closed'];
        $input=self::normalizeCreateInput($title,$dueIso);if(empty($input['ok']))return$input;
        $assignedManagerId=(int)($assignedManagerId??0);if($assignedManagerId<=0)$assignedManagerId=$createdByManagerId;
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare("INSERT INTO lead_tasks (conversation_id,title,due_at_utc,status,is_pinned,assigned_manager_id,created_by_manager_id,created_at,updated_at) VALUES (?,?,?,'open',0,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $q->execute([$conversationId,$input['title'],$input['due_at_utc'],$assignedManagerId,$createdByManagerId]);
        return ['ok'=>true,'id'=>(int)$pdo->lastInsertId()];
    }

    /** Edit the business task itself without touching technical conversation state. Completed tasks stay immutable. */
    public static function update(int $conversationId,int $taskId,string $title,?string $dueIso): array
    {
        if($conversationId<=0||$taskId<=0)return ['ok'=>false,'error'=>'not_found'];
        $input=self::normalizeCreateInput($title,$dueIso);if(empty($input['ok']))return$input;
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare("UPDATE lead_tasks SET title=?,reminder_attempted_at_utc=IF(NOT(due_at_utc<=>?),NULL,reminder_attempted_at_utc),reminder_notified_at_utc=IF(NOT(due_at_utc<=>?),NULL,reminder_notified_at_utc),due_at_utc=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND conversation_id=? AND status='open'");
        $q->execute([$input['title'],$input['due_at_utc'],$input['due_at_utc'],$input['due_at_utc'],$taskId,$conversationId]);
        if($q->rowCount()>0)return ['ok'=>true];
        $q=$pdo->prepare("SELECT title,due_at_utc FROM lead_tasks WHERE id=? AND conversation_id=? AND status='open' LIMIT 1");
        $q->execute([$taskId,$conversationId]);$current=$q->fetch();
        if(!$current)return ['ok'=>false,'error'=>'not_found'];
        $sameTitle=(string)($current['title']??'')===$input['title'];
        $sameDue=(string)($current['due_at_utc']??'')===(string)($input['due_at_utc']??'');
        return $sameTitle&&$sameDue?['ok'=>true]:['ok'=>false,'error'=>'update_failed'];
    }

    public static function setCompleted(int $conversationId,int $taskId,bool $completed): bool
    {
        if($conversationId<=0||$taskId<=0)return false;
        $status=$completed?'done':'open';$completedSql=$completed?'UTC_TIMESTAMP()':'NULL';
        $reminderReset=$completed?'':' ,reminder_attempted_at_utc=NULL,reminder_notified_at_utc=NULL';
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare("UPDATE lead_tasks SET status=?,completed_at_utc={$completedSql}{$reminderReset},updated_at=UTC_TIMESTAMP() WHERE id=? AND conversation_id=? AND status<>?");
        $q->execute([$status,$taskId,$conversationId,$status]);if($q->rowCount()>0)return true;
        $q=$pdo->prepare("SELECT status FROM lead_tasks WHERE id=? AND conversation_id=? LIMIT 1");
        $q->execute([$taskId,$conversationId]);$current=$q->fetchColumn();
        return$current!==false&&(string)$current===$status;
    }

    public static function setPinned(int $conversationId,int $taskId,bool $pinned): bool
    {
        if($conversationId<=0||$taskId<=0)return false;
        $pdo=ConversationDb::connection();$value=$pinned?1:0;
        $q=$pdo->prepare("UPDATE lead_tasks SET is_pinned=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND conversation_id=? AND status='open'");
        $q->execute([$value,$taskId,$conversationId]);if($q->rowCount()>0)return true;
        $q=$pdo->prepare("SELECT 1 FROM lead_tasks WHERE id=? AND conversation_id=? AND status='open' AND is_pinned=? LIMIT 1");
        $q->execute([$taskId,$conversationId,$value]);return(bool)$q->fetchColumn();
    }

    private static function length(string $value): int
    {
        if(function_exists('mb_strlen'))return mb_strlen($value,'UTF-8');
        $count=preg_match_all('/./us',$value,$unused);
        return$count===false?strlen($value):$count;
    }
}
