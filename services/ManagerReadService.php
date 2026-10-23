<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ManagerConversationAccessPolicy.php';

class ManagerReadService
{
    private static $ready=false;

    public static function ensureSchema(): void
    {
        if(self::$ready)return;
        // Schema is managed by versioned migrations.
        self::$ready=true;
    }

    private static function context(array $c): string
    {
        return hash('sha256',json_encode(array_map(static fn($key)=>(string)($c[$key]??''),['id','manager_id','project_key','source_id','channel','status']),JSON_THROW_ON_ERROR));
    }

    private static function eligible(int $managerId,array $c): bool
    {
        $manager=ManagerAuthService::byId($managerId);
        return !empty($manager['is_active'])&&ManagerConversationAccessPolicy::canView($managerId,$c)
            && ((int)($c['manager_id']??0)===$managerId || (!(int)($c['manager_id']??0)&&!ManagerSupervisorAccessService::canRead($managerId,$c)));
    }

    /** Metadata only. Returning a transcript is never a read acknowledgement. */
    public static function snapshot(int $managerId,array $c): array
    {
        if(!self::eligible($managerId,$c))return ['acknowledge'=>false];
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('SELECT last_read_message_id FROM manager_conversation_reads WHERE manager_id=? AND conversation_id=?');$q->execute([$managerId,(int)$c['id']]);$last=(int)$q->fetchColumn();
        $q=$pdo->prepare("SELECT MIN(id) AS first_id,COUNT(*) AS unread_count FROM messages WHERE conversation_id=? AND direction='inbound' AND sender_type='customer' AND id>?");$q->execute([(int)$c['id'],$last]);$row=$q->fetch();
        return ['acknowledge'=>true,'context'=>self::context($c),'last_read_message_id'=>$last,'first_unread_id'=>(int)($row['first_id']??0),'unread_count'=>(int)($row['unread_count']??0)];
    }

    /** Advance only through the contiguous prefix of actual customer rows shown by this viewer. */
    public static function acknowledge(int $managerId,int $conversationId,array $seen,string $context): array
    {
        if(!$seen||!array_is_list($seen)||count($seen)>200||!preg_match('/^[a-f0-9]{64}$/D',$context))return ['ok'=>false,'error'=>'invalid_read_ack'];
        foreach($seen as $id)if(!is_int($id)||$id<=0)return ['ok'=>false,'error'=>'invalid_read_ack'];
        $pdo=ConversationDb::connection();$pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT id,project_key,source_id,channel,status,manager_id FROM conversations WHERE id=? FOR UPDATE');$q->execute([$conversationId]);$c=$q->fetch();
            if(!$c||!self::eligible($managerId,$c)||!hash_equals(self::context($c),$context)){$pdo->rollBack();return ['ok'=>false,'error'=>'read_context_changed'];}
            $state=self::snapshot($managerId,$c);if(empty($state['acknowledge'])){$pdo->rollBack();return ['ok'=>false,'error'=>'read_context_changed'];}$last=$state['last_read_message_id'];
            $q=$pdo->prepare("SELECT id FROM messages WHERE conversation_id=? AND direction='inbound' AND sender_type='customer' AND id>? AND id<=? ORDER BY id ASC LIMIT 201");$q->execute([$conversationId,$last,max($seen)]);$available=$q->fetchAll(PDO::FETCH_COLUMN);$set=array_fill_keys($seen,true);$through=$last;
            foreach($available as $id){if(!isset($set[(int)$id]))break;$through=(int)$id;}
            if($through>$last)self::markRead($managerId,$conversationId,$through);
            $state=self::snapshot($managerId,$c);if(empty($state['acknowledge'])){$pdo->rollBack();return ['ok'=>false,'error'=>'read_context_changed'];}$pdo->commit();return ['ok'=>true,'read_state'=>$state];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    /** Internal atomic monotonic writer; callers must establish shown prefix and current access. */
    public static function markRead(int $managerId,int $conversationId,int $lastMessageId): void
    {
        if($managerId<=0||$conversationId<=0||$lastMessageId<=0)return;
        self::ensureSchema();
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('INSERT INTO manager_conversation_reads (manager_id,conversation_id,last_read_message_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id)),updated_at=NOW()');
        $q->execute([$managerId,$conversationId,$lastMessageId]);
    }
}
