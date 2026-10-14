<?php
require_once __DIR__ . '/ConversationDb.php';

class ManagerReadService
{
    private static $ready=false;

    public static function ensureSchema(): void
    {
        if(self::$ready)return;
        // Schema is managed by versioned migrations.
        self::$ready=true;
    }

    /** Advance only through a message captured in a successfully returned page. */
    public static function markRead(int $managerId,int $conversationId,int $lastMessageId): void
    {
        if($managerId<=0||$conversationId<=0||$lastMessageId<=0)return;
        self::ensureSchema();
        $pdo=ConversationDb::connection();
        $q=$pdo->prepare('INSERT INTO manager_conversation_reads (manager_id,conversation_id,last_read_message_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id)),updated_at=NOW()');
        $q->execute([$managerId,$conversationId,$lastMessageId]);
    }
}
