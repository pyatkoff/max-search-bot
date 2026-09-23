<?php
require_once __DIR__.'/ConversationDb.php';

class ManagerMessageEditPolicy
{
    public const WINDOW_SECONDS=300;

    public static function inspect(int $messageId,int $managerId): array
    {
        if($messageId<=0||$managerId<=0||!ConversationDb::isConfigured())return ['allowed'=>false,'reason'=>'invalid'];
        try{
            $pdo=ConversationDb::connection();
            $q=$pdo->prepare("SELECT m.id,m.conversation_id,m.direction,m.sender_type,m.sender_id,m.channel,m.external_message_id,m.created_at,c.manager_id AS conversation_manager_id,c.status
                FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.id=? LIMIT 1");
            $q->execute([$messageId]);$row=$q->fetch();
            if(!$row)return ['allowed'=>false,'reason'=>'not_found'];
            if((string)$row['direction']!=='outbound'||(string)$row['sender_type']!=='manager')return ['allowed'=>false,'reason'=>'not_manager_message'];
            if((int)$row['sender_id']!==$managerId||(int)$row['conversation_manager_id']!==$managerId)return ['allowed'=>false,'reason'=>'not_owner'];
            if((string)$row['status']!=='manager')return ['allowed'=>false,'reason'=>'conversation_not_active'];
            $channel=strtolower((string)$row['channel']);
            if(!in_array($channel,['max','telegram','website'],true))return ['allowed'=>false,'reason'=>'unsupported_channel'];
            $now=(string)$pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
            $created=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$row['created_at'],new DateTimeZone('UTC'));
            $clock=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$now,new DateTimeZone('UTC'));
            if(!$created||!$clock)return ['allowed'=>false,'reason'=>'invalid_time'];
            $age=$clock->getTimestamp()-$created->getTimestamp();
            if($age<0||$age>self::WINDOW_SECONDS)return ['allowed'=>false,'reason'=>'expired','age_seconds'=>$age];
            $external=trim((string)($row['external_message_id']??''));
            if(in_array($channel,['max','telegram'],true)&&$external==='')return ['allowed'=>false,'reason'=>'missing_external_id'];
            return ['allowed'=>true,'reason'=>'ok','message_id'=>(int)$row['id'],'conversation_id'=>(int)$row['conversation_id'],'channel'=>$channel,'external_message_id'=>$external,'age_seconds'=>$age];
        }catch(Throwable $e){return ['allowed'=>false,'reason'=>'storage_error'];}
    }
}
