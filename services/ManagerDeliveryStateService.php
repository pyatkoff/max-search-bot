<?php
require_once __DIR__ . '/ConversationDb.php';

class ManagerDeliveryStateService
{
    public static function activeFailures(array $conversationIds): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$conversationIds),static function($id){return $id>0;})));
        if(!$ids)return[];
        $pdo=ConversationDb::connection();$ph=implode(',',array_fill(0,count($ids),'?'));

        $sql="SELECT e.conversation_id,e.created_at,e.payload_json FROM conversation_events e JOIN (SELECT conversation_id,MAX(id) AS id FROM conversation_events WHERE event_type='manager_message_failed' AND conversation_id IN ({$ph}) GROUP BY conversation_id) latest ON latest.id=e.id";
        $q=$pdo->prepare($sql);$q->execute($ids);$events=$q->fetchAll();
        if(!$events)return[];

        $eventIds=array_values(array_unique(array_map(static function($row){return(int)($row['conversation_id']??0);},$events)));
        $inbound=[];
        if($eventIds){$iph=implode(',',array_fill(0,count($eventIds),'?'));$q=$pdo->prepare("SELECT conversation_id,MAX(created_at) AS last_inbound_at FROM messages WHERE conversation_id IN ({$iph}) AND direction='inbound' AND sender_type='customer' GROUP BY conversation_id");$q->execute($eventIds);foreach($q->fetchAll() as $row)$inbound[(int)$row['conversation_id']]=(string)($row['last_inbound_at']??'');}

        $out=[];
        foreach($events as $event){
            $conversationId=(int)($event['conversation_id']??0);if($conversationId<=0)continue;
            $payload=json_decode((string)($event['payload_json']??''),true);
            if(!is_array($payload)||(string)($payload['category']??'')!=='suspended')continue;
            $failedAt=(string)($event['created_at']??'');$lastInboundAt=(string)($inbound[$conversationId]??'');
            if($lastInboundAt!==''&&$failedAt!==''&&$lastInboundAt>$failedAt)continue;
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
