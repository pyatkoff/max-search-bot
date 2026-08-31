<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ManagerPushService.php';

/**
 * Application owner for due lead-task reminder delivery.
 * Sales reminders are independent from technical conversation state and manager shift state.
 */
class LeadTaskReminderService
{
    private const RETRY_SECONDS = 1800;
    private const BATCH_LIMIT = 100;

    public static function retrySeconds(): int { return self::RETRY_SECONDS; }

    public static function runDue(?DateTimeImmutable $nowUtc=null, ?callable $notifier=null): array
    {
        $utc=new DateTimeZone('UTC');
        $now=$nowUtc?:new DateTimeImmutable('now',$utc);
        $now=$now->setTimezone($utc);
        $nowSql=$now->format('Y-m-d H:i:s');
        $cutoffSql=$now->modify('-'.self::RETRY_SECONDS.' seconds')->format('Y-m-d H:i:s');
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare("SELECT t.id,t.conversation_id,t.title,t.due_at_utc,t.assigned_manager_id,m.display_name AS manager_name,cu.display_name AS customer_name FROM lead_tasks t JOIN managers m ON m.id=t.assigned_manager_id AND m.is_active=1 JOIN conversations c ON c.id=t.conversation_id JOIN customers cu ON cu.id=c.customer_id WHERE t.status='open' AND t.due_at_utc IS NOT NULL AND t.due_at_utc<=? AND t.reminder_notified_at_utc IS NULL AND (t.reminder_attempted_at_utc IS NULL OR t.reminder_attempted_at_utc<=?) ORDER BY t.due_at_utc ASC,t.id ASC LIMIT ".self::BATCH_LIMIT);
        $q->execute([$nowSql,$cutoffSql]);
        $rows=$q->fetchAll()?:[];
        $result=['due'=>count($rows),'claimed'=>0,'notified'=>0,'deferred'=>0,'failed'=>0];
        $notify=$notifier?:static function(int $managerId,int $conversationId,string $title,string $body): array {
            return ManagerPushService::notifyManager($managerId,$conversationId,$title,$body);
        };
        foreach($rows as $row){
            $taskId=(int)$row['id'];$managerId=(int)$row['assigned_manager_id'];$conversationId=(int)$row['conversation_id'];
            $claim=$pdo->prepare("UPDATE lead_tasks SET reminder_attempted_at_utc=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='open' AND reminder_notified_at_utc IS NULL AND (reminder_attempted_at_utc IS NULL OR reminder_attempted_at_utc<=?)");
            $claim->execute([$nowSql,$taskId,$cutoffSql]);
            if($claim->rowCount()!==1){$result['deferred']++;continue;}
            $result['claimed']++;
            $customer=trim((string)($row['customer_name']??''));
            $taskTitle=trim((string)($row['title']??''));
            $title=$customer!==''?'Задача · '.$customer:'Задача по лиду';
            $body=$taskTitle!==''?$taskTitle:'Наступил срок задачи';
            try{$delivery=$notify($managerId,$conversationId,$title,$body);}
            catch(Throwable $e){$delivery=['delivered'=>0,'error'=>$e->getMessage()];}
            if((int)($delivery['delivered']??0)>0){
                $done=$pdo->prepare("UPDATE lead_tasks SET reminder_notified_at_utc=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='open' AND reminder_notified_at_utc IS NULL");
                $done->execute([$nowSql,$taskId]);
                if($done->rowCount()===1)$result['notified']++;else $result['deferred']++;
                continue;
            }
            $result['failed']++;
        }
        if(class_exists('DiagnosticLogger')){
            try{DiagnosticLogger::log('lead_task_reminder','run',$result,null,$result['failed']>0?'warning':'info');}catch(Throwable $ignored){}
        }
        return$result;
    }
}
