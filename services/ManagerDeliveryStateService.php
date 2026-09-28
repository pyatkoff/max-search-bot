<?php
require_once __DIR__ . '/ConversationDb.php';

class ManagerDeliveryStateService
{
    public static function activeFailures(array $conversationIds): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$conversationIds),static function($id){return $id>0;})));
        if(!$ids)return[];
        $pdo=ConversationDb::connection();$ph=implode(',',array_fill(0,count($ids),'?'));

        $sql="SELECT e.id,e.conversation_id,e.created_at,e.payload_json FROM conversation_events e JOIN (SELECT conversation_id,MAX(id) AS id FROM conversation_events WHERE event_type='manager_message_failed' AND conversation_id IN ({$ph}) GROUP BY conversation_id) latest ON latest.id=e.id";
        $q=$pdo->prepare($sql);$q->execute($ids);$events=$q->fetchAll();
        if(!$events)return[];

        $eventIds=array_values(array_unique(array_map(static function($row){return(int)($row['conversation_id']??0);},$events)));
        $inbound=[];
        if($eventIds){$iph=implode(',',array_fill(0,count($eventIds),'?'));$q=$pdo->prepare("SELECT conversation_id,MAX(created_at) AS last_inbound_at FROM messages WHERE conversation_id IN ({$iph}) AND direction='inbound' AND sender_type='customer' GROUP BY conversation_id");$q->execute($eventIds);foreach($q->fetchAll() as $row)$inbound[(int)$row['conversation_id']]=(string)($row['last_inbound_at']??'');}
        $restarted=self::maxRestartsAfter(array_column($events,'id','conversation_id'));

        $out=[];
        foreach($events as $event){
            $conversationId=(int)($event['conversation_id']??0);if($conversationId<=0)continue;
            $payload=json_decode((string)($event['payload_json']??''),true);
            if(!is_array($payload)||(string)($payload['category']??'')!=='suspended')continue;
            $failedAt=(string)($event['created_at']??'');$lastInboundAt=(string)($inbound[$conversationId]??'');
            if($lastInboundAt!==''&&$failedAt!==''&&$lastInboundAt>$failedAt)continue;
            if(!empty($restarted[$conversationId]))continue;
            $out[$conversationId]=[
                'category'=>'suspended',
                'http_code'=>(int)($payload['http_code']??403),
                'message'=>(string)($payload['message']??'MAX dialog suspended'),
                'notice'=>'Пользователь остановил или заблокировал бота MAX. Отправка станет доступна после новой активности клиента — когда он снова запустит или разблокирует бота.',
                'failed_at'=>$failedAt,
                'retry_allowed'=>false,
            ];
        }
        return$out;
    }

    /** Shared read-only evidence for Workspace and explicit outbound text/media sends.
     * @param array<int,int> $failureEventIds conversation ID => recorded failure event ID
     * @return array<int,bool> Conversations with a newer recorded MAX system start.
     */
    public static function maxRestartsAfter(array $failureEventIds): array
    {
        $failures=[];
        foreach($failureEventIds as $conversationId=>$eventId){
            $conversationId=(int)$conversationId;$eventId=(int)$eventId;
            if($conversationId>0&&$eventId>0)$failures[$conversationId]=$eventId;
        }
        if(!$failures)return[];
        $ids=array_keys($failures);$ph=implode(',',array_fill(0,count($ids),'?'));
        $q=ConversationDb::connection()->prepare("SELECT e.conversation_id,MAX(e.id) AS last_start_id FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE c.channel='max' AND e.event_type='bot_started' AND e.actor_type='system' AND e.conversation_id IN ({$ph}) GROUP BY e.conversation_id");
        $q->execute($ids);$out=[];
        foreach($q->fetchAll() as $row){
            $id=(int)$row['conversation_id'];
            // Both facts use the same event ledger: same-second timestamps remain ordered.
            if((int)$row['last_start_id']>$failures[$id])$out[$id]=true;
        }
        return$out;
    }

    public static function withoutSuspendedRecipients(array $rows): array
    {
        if(!$rows)return[];
        return array_values(array_filter($rows,static function($row){
            return (string)($row['delivery_failure_category']??'')!=='suspended';
        }));
    }

    public static function activeFailure(int $conversationId): ?array
    {
        if($conversationId<=0)return null;
        $all=self::activeFailures([$conversationId]);
        return$all[$conversationId]??null;
    }
}
