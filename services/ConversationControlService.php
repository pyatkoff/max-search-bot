<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectConfig.php';

class ConversationControlService
{
    public static function statusByChat(string $platform, $chatId): ?array
    {
        if (!ConversationDb::isConfigured()) return null;
        $q = ConversationDb::connection()->prepare('SELECT id,status,manager_id FROM conversations WHERE project_key=? AND channel=? AND external_chat_id=? AND status<>? ORDER BY id DESC LIMIT 1');
        $q->execute([ProjectConfig::projectId(), strtolower(trim($platform)), (string)$chatId, 'closed']);
        $row = $q->fetch();
        return $row ?: null;
    }

    public static function shouldRouteToAi(string $platform, $chatId): bool
    {
        $row = self::statusByChat($platform, $chatId);
        if (!$row) return true;
        return !in_array((string)$row['status'], ['waiting_manager','manager'], true);
    }

    public static function markWaitingByChat(string $platform, $chatId, array $payload = []): bool
    {
        $row = self::statusByChat($platform, $chatId);
        if (!$row) return false;
        $pdo = ConversationDb::connection();
        $pdo->prepare('UPDATE conversations SET status=?, manager_id=NULL WHERE id=?')->execute(['waiting_manager',(int)$row['id']]);
        self::event((int)$row['id'], 'waiting_manager', 'customer', null, $payload);
        return true;
    }

    public static function event(int $conversationId, string $type, string $actorType, $actorId = null, array $payload = []): void
    {
        $json = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
        ConversationDb::connection()->prepare('INSERT INTO conversation_events (conversation_id,event_type,actor_type,actor_id,payload_json) VALUES (?,?,?,?,?)')
            ->execute([$conversationId,$type,$actorType,$actorId !== null ? (string)$actorId : null,$json]);
    }
}
