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

    /** Rows must be the whole authorized, capped queue for this project. */
    public static function summarize(string $queue,array $rows): array
    {
        $unread=0;
        foreach($rows as $row){
            if($queue!=='waiting'||empty($row['manager_id']))$unread+=(int)($row['unread_count']??0);
        }
        return ['count'=>count($rows),'unread'=>$unread];
    }

    public static function counts(int $managerId,string $projectKey='*',bool $inboxTabsOnly=false,?string $onlyTab=null): array
    {
        if($inboxTabsOnly&&in_array($onlyTab,['waiting','mine'],true)){
            $rows=ManagerConversationService::queueCountRows($managerId,$onlyTab,$projectKey);
            return [$onlyTab=>self::summarize($onlyTab,self::actionableRows($onlyTab,$rows))];
        }
        $rawWaiting=$inboxTabsOnly?[]:ManagerConversationService::notificationWaitingRows($managerId,$projectKey);
        $operationalWaiting=ManagerConversationService::queueCountRows($managerId,'waiting',$projectKey);
        $requested=$inboxTabsOnly?[]:ManagerConversationService::queueCountRows($managerId,'requested',$projectKey);
        $mine=ManagerConversationService::queueCountRows($managerId,'mine',$projectKey);
        $out=[
            'waiting'=>self::summarize('waiting',self::actionableRows('waiting',$operationalWaiting)),
            'requested'=>self::summarize('requested',$requested),
            'mine'=>self::summarize('mine',$mine),
        ];

        if($inboxTabsOnly)return ['waiting'=>$out['waiting'],'mine'=>$out['mine']];

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
