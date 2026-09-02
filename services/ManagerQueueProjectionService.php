<?php
require_once __DIR__ . '/ManagerConversationService.php';
require_once __DIR__ . '/ManagerDeliveryStateService.php';

class ManagerQueueProjectionService
{
    public static function actionableRows(string $queue,array $rows): array
    {
        if(!in_array($queue,['waiting','attention'],true))return array_values($rows);
        return ManagerDeliveryStateService::withoutSuspendedRecipients($rows);
    }

    public static function counts(int $managerId,string $projectKey='*'): array
    {
        $rawWaiting=ManagerConversationService::list($managerId,'waiting',200,$projectKey);
        $requested=ManagerConversationService::list($managerId,'requested',200,$projectKey);
        $mine=ManagerConversationService::list($managerId,'mine',200,$projectKey);
        $waiting=self::actionableRows('waiting',$rawWaiting);

        $waitingUnread=array_filter($waiting,static function($row){return empty($row['manager_id']);});
        $out=[
            'waiting'=>[
                'count'=>count($waiting),
                'unread'=>array_sum(array_map(static function($row){return(int)($row['unread_count']??0);},$waitingUnread)),
            ],
            'requested'=>[
                'count'=>count($requested),
                'unread'=>array_sum(array_map(static function($row){return(int)($row['unread_count']??0);},$requested)),
            ],
            'mine'=>[
                'count'=>count($mine),
                'unread'=>array_sum(array_map(static function($row){return(int)($row['unread_count']??0);},$mine)),
            ],
        ];

        // Preserve the existing notification badge contract: it is the unique
        // unread-message total across the raw waiting + mine projections. The
        // requested queue overlaps those lifecycle projections and must not be
        // added again, while the actionable waiting counter still excludes
        // suspended recipients.
        $unique=[];
        foreach(array_merge($rawWaiting,$mine) as $row){
            $id=(int)($row['id']??0);if($id<=0)continue;
            $unique[$id]=max((int)($unique[$id]??0),(int)($row['unread_count']??0));
        }
        $out['notification_unread']=array_sum($unique);
        return$out;
    }
}
