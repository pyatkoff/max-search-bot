<?php

require_once __DIR__ . '/ConversationDb.php';

/**
 * Serializes manager text sends per conversation and suppresses only an exact
 * immediate replay. This protects both manager UIs and API retries without
 * turning business-level repeated messages into a permanent dedup rule.
 */
class ManagerSendGuardService
{
    private const DUPLICATE_WINDOW_SECONDS = 3;
    private const LOCK_WAIT_SECONDS = 3;

    public static function lockKey(int $conversationId, int $managerId): string
    {
        return 'manager-send:' . $conversationId . ':' . $managerId;
    }

    public static function acquire(int $conversationId, int $managerId): bool
    {
        try {
            $q = ConversationDb::connection()->prepare('SELECT GET_LOCK(?, ?)');
            $q->execute([self::lockKey($conversationId, $managerId), self::LOCK_WAIT_SECONDS]);
            return (int)$q->fetchColumn() === 1;
        } catch (Throwable $e) {
            // Fail open if the advisory lock is unavailable; delivery must not
            // be blocked by diagnostics/guard infrastructure.
            return false;
        }
    }

    public static function release(int $conversationId, int $managerId): void
    {
        try {
            $q = ConversationDb::connection()->prepare('SELECT RELEASE_LOCK(?)');
            $q->execute([self::lockKey($conversationId, $managerId)]);
        } catch (Throwable $e) {
        }
    }

    public static function isImmediateDuplicate(int $conversationId, string $text): bool
    {
        $text = trim($text);
        if ($text === '') return false;

        try {
            $sql = "SELECT text FROM messages WHERE conversation_id=? AND direction='outbound' AND sender_type='manager' AND created_at>=DATE_SUB(NOW(), INTERVAL " . self::DUPLICATE_WINDOW_SECONDS . " SECOND) ORDER BY id DESC LIMIT 5";
            $q = ConversationDb::connection()->prepare($sql);
            $q->execute([$conversationId]);
            foreach ($q->fetchAll() as $row) {
                $stored = html_entity_decode((string)($row['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (trim($stored) === $text) return true;
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }
}
