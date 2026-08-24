<?php
require_once __DIR__ . '/ConversationDb.php';

class ManagerReadService
{
    private static $ready=false;

    public static function ensureSchema(): void
    {
        if(self::$ready)return;
        self::$ready=true;
    }

    public static function markRead(int $managerId,int $conversationId): void
    {
        self::ensureSchema();
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM messages WHERE conversation_id=?');$q->execute([$conversationId]);
        $last=(int)$q->fetchColumn();
        $q=$pdo->prepare('INSERT INTO manager_conversation_reads (manager_id,conversation_id,last_read_message_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_read_message_id=VALUES(last_read_message_id),updated_at=NOW()');
        $q->execute([$managerId,$conversationId,$last]);
    }
}
