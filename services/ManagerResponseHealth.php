<?php
require_once __DIR__ . '/RoutingAccessService.php';

class ManagerResponseHealth
{
    public static function evaluate(array $rows,int $warningSeconds=90,int $stuckSeconds=600): array
    {
        $warningSeconds=max(1,$warningSeconds);
        $stuckSeconds=max($warningSeconds,$stuckSeconds);
        $pending=[];$overdue=0;$stuck=0;$oldest=0;
        foreach($rows as $row){
            if(!empty($row['first_reply_at']))continue;
            if(!empty($row['delivery_suspended']))continue;
            $age=max(0,(int)($row['wait_seconds']??0));
            $eligibleIds=array_values(array_unique(array_map('intval',(array)($row['eligible_working_manager_ids']??[]))));
            sort($eligibleIds);
            $entry=[
                'conversation_id'=>(int)($row['conversation_id']??0),
                'project_key'=>(string)($row['project_key']??''),
                'channel'=>(string)($row['channel']??''),
                'status'=>(string)($row['status']??''),
                'manager_id'=>isset($row['manager_id'])&&$row['manager_id']!==null?(int)$row['manager_id']:null,
                'manager_request_at'=>$row['manager_request_at']??null,
                'wait_seconds'=>$age,
                'severity'=>$age>=$stuckSeconds?'stuck':($age>=$warningSeconds?'overdue':'pending'),
                'eligible_working_manager_ids'=>$eligibleIds,
                'eligible_working_manager_count'=>count($eligibleIds),
                'routing_blocked'=>(string)($row['status']??'')==='waiting_manager' && count($eligibleIds)===0,
            ];
            if($entry['severity']==='stuck')$stuck++;
            if($age>=$warningSeconds)$overdue++;
            $oldest=max($oldest,$age);
            $pending[]=$entry;
        }
        usort($pending,static function($a,$b){return(int)$b['wait_seconds']<=>(int)$a['wait_seconds'];});
        return [
            'ok'=>$stuck===0,
            'warning_after_seconds'=>$warningSeconds,
            'stuck_after_seconds'=>$stuckSeconds,
            'pending_count'=>count($pending),
            'overdue_count'=>$overdue,
            'stuck_count'=>$stuck,
            'oldest_wait_seconds'=>$oldest,
            'routing_blocked_count'=>count(array_filter($pending,static function($row){return !empty($row['routing_blocked']);})),
            'requests'=>array_slice($pending,0,30),
        ];
    }

    public static function collect(PDO $pdo,int $warningSeconds=90,int $stuckSeconds=600): array
    {
        $sql="SELECT c.id AS conversation_id,c.project_key,c.source_id,c.channel,c.status,c.manager_id,c.started_at,c.last_message_at,r.manager_request_at,"
            ."TIMESTAMPDIFF(SECOND,r.manager_request_at,NOW()) AS wait_seconds,"
            ."(SELECT MIN(m.created_at) FROM messages m WHERE m.conversation_id=c.id AND m.direction='outbound' AND m.sender_type='manager' AND m.created_at>=r.manager_request_at) AS first_reply_at "
            ."FROM conversations c JOIN (SELECT conversation_id,MAX(created_at) AS manager_request_at FROM conversation_events WHERE event_type='waiting_manager' GROUP BY conversation_id) r ON r.conversation_id=c.id "
            ."WHERE c.status IN ('waiting_manager','manager') ORDER BY r.manager_request_at ASC";
        $rows=$pdo->query($sql)->fetchAll();
        if(class_exists('ManagerDeliveryStateService')){
            $ids=array_values(array_filter(array_map(static function($row){return(int)($row['conversation_id']??0);},$rows)));
            $failures=ManagerDeliveryStateService::activeFailures($ids);
            foreach($rows as &$row){
                $id=(int)($row['conversation_id']??0);$failure=$failures[$id]??null;
                $row['delivery_suspended']=is_array($failure)&&($failure['category']??null)==='suspended';
            }
            unset($row);
        }
        foreach($rows as &$row){
            $row['eligible_working_manager_ids']=self::eligibleWorkingManagerIds($pdo,$row);
        }
        unset($row);
        return self::evaluate($rows,$warningSeconds,$stuckSeconds);
    }

    private static function eligibleWorkingManagerIds(PDO $pdo,array $row): array
    {
        $row['waiting_since']=$row['manager_request_at']??null;
        $q=$pdo->query('SELECT id FROM managers WHERE is_active=1 AND is_working=1 ORDER BY id');
        $ids=[];
        foreach($q->fetchAll() as $manager){
            $id=(int)($manager['id']??0);
            if($id>0 && RoutingAccessService::canSeeConversation($id,$row))$ids[]=$id;
        }
        return $ids;
    }
}
