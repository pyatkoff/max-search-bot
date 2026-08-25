<?php

class HandoffIntegrityHealth
{
    public static function evaluate(array $rows): array
    {
        $anomalies=[];
        foreach($rows as $row){
            $id=(int)($row['conversation_id']??0);
            $status=(string)($row['status']??'');
            $managerId=isset($row['manager_id'])&&$row['manager_id']!==null?(int)$row['manager_id']:null;
            $requestCount=(int)($row['manager_request_count']??0);
            $activeAssignments=(int)($row['active_assignment_count']??0);
            $activeAssignmentManagerId=isset($row['active_assignment_manager_id'])&&$row['active_assignment_manager_id']!==null?(int)$row['active_assignment_manager_id']:null;

            $add=static function(string $reason,array $extra=[])use(&$anomalies,$id,$status,$managerId){
                $anomalies[]=['conversation_id'=>$id,'status'=>$status,'manager_id'=>$managerId,'reason'=>$reason]+$extra;
            };

            if($status==='waiting_manager'){
                if($managerId!==null)$add('waiting_manager_has_manager');
                if($requestCount===0)$add('waiting_manager_missing_request_event');
                if($activeAssignments>0)$add('waiting_manager_has_active_assignment',['active_assignment_count'=>$activeAssignments]);
            }
            if($status==='manager'){
                if($managerId===null)$add('manager_status_missing_manager');
                if($activeAssignments===0)$add('manager_status_missing_active_assignment');
                if($activeAssignments>1)$add('duplicate_active_assignments',['active_assignment_count'=>$activeAssignments]);
                if($managerId!==null&&$activeAssignmentManagerId!==null&&$managerId!==$activeAssignmentManagerId){
                    $add('active_assignment_manager_mismatch',['active_assignment_manager_id'=>$activeAssignmentManagerId]);
                }
            } elseif($activeAssignments>1){
                $add('duplicate_active_assignments',['active_assignment_count'=>$activeAssignments]);
            }
            if($status==='ai'&&$managerId!==null)$add('ai_status_has_manager');
            if(in_array($status,['ai','closed'],true)&&$activeAssignments>0){
                $add('inactive_status_has_active_assignment',['active_assignment_count'=>$activeAssignments]);
            }
        }

        return [
            'ok'=>count($anomalies)===0,
            'anomaly_count'=>count($anomalies),
            'anomalies'=>array_slice($anomalies,0,50),
        ];
    }

    public static function collect(PDO $pdo): array
    {
        $sql="SELECT c.id AS conversation_id,c.status,c.manager_id,"
            ."(SELECT COUNT(*) FROM conversation_events e WHERE e.conversation_id=c.id AND e.event_type='waiting_manager') AS manager_request_count,"
            ."(SELECT COUNT(*) FROM manager_assignments a WHERE a.conversation_id=c.id AND a.released_at IS NULL) AS active_assignment_count,"
            ."(SELECT MIN(a.manager_id) FROM manager_assignments a WHERE a.conversation_id=c.id AND a.released_at IS NULL) AS active_assignment_manager_id "
            ."FROM conversations c WHERE c.status IN ('ai','waiting_manager','manager','closed') ORDER BY c.id DESC LIMIT 1000";
        return self::evaluate($pdo->query($sql)->fetchAll());
    }
}
