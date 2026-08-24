<?php
require_once __DIR__ . '/ConversationDb.php';

class ManagerReadService
{
    private static $ready=false;

    public static function ensureSchema(): void
    {
        if(self::$ready)return;
        ConversationDb::connection()->exec("CREATE TABLE IF NOT EXISTS manager_conversation_reads (
            manager_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (manager_id,conversation_id),
            KEY idx_manager_reads_conversation (conversation_id,manager_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
