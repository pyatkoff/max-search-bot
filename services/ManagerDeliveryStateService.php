<?php
require_once __DIR__ . '/ConversationDb.php';

class ManagerDeliveryStateService
{
    public static function activeFailure(int $conversationId): ?array
    {
        if ($conversationId <= 0) return null;
        $pdo = ConversationDb::connection();

        $q = $pdo->prepare("SELECT id,created_at,payload_json FROM conversation_events WHERE conversation_id=? AND event_type='manager_message_failed' ORDER BY id DESC LIMIT 1");
        $q->execute([$conversationId]);
        $event = $q->fetch();
        if (!$event) return null;

        $payload = json_decode((string)($event['payload_json'] ?? ''), true);
        if (!is_array($payload) || (string)($payload['category'] ?? '') !== 'suspended') return null;

        $q = $pdo->prepare("SELECT created_at FROM messages WHERE conversation_id=? AND direction='inbound' AND sender_type='customer' ORDER BY id DESC LIMIT 1");
        $q->execute([$conversationId]);
        $lastInboundAt = (string)($q->fetchColumn() ?: '');
        $failedAt = (string)($event['created_at'] ?? '');
        if ($lastInboundAt !== '' && $failedAt !== '' && $lastInboundAt > $failedAt) return null;

        return [
            'category' => 'suspended',
            'http_code' => (int)($payload['http_code'] ?? 403),
            'message' => (string)($payload['message'] ?? 'MAX dialog suspended'),
            'notice' => 'Пользователь остановил или заблокировал бота MAX. Отправка станет доступна после новой активности клиента — когда он снова запустит или разблокирует бота.',
            'failed_at' => $failedAt,
            'retry_allowed' => false,
        ];
    }
}
