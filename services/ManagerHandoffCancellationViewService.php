<?php
require_once __DIR__ . '/ManagerConversationService.php';

/** Optional historical context, never a state/queue owner or callback interpreter. */
final class ManagerHandoffCancellationViewService
{
    public static function forVisibleConversation(int $conversationId, int $managerId): ?array
    {
        if ($conversationId <= 0 || $managerId <= 0) return null;
        try {
            return self::fromVisibleContext(ManagerConversationService::visibleConversation($conversationId, $managerId) ?? []);
        } catch (Throwable $ignored) { return null; }
    }

    /** Only the canonical detail owner may pass its freshly authorized row. No public endpoint. */
    public static function fromVisibleContext(array $conversation): ?array
    {
        $conversationId = (int)($conversation['id'] ?? 0);
        if ($conversationId <= 0 || ($conversation['status'] ?? '') !== 'ai') return null;
        try {
            $q = ConversationDb::connection()->prepare(
                "SELECT id,event_type,actor_type,payload_json,created_at FROM conversation_events "
                . "WHERE conversation_id=? ORDER BY id DESC LIMIT 32"
            );
            $q->execute([$conversationId]);
            $transitions = ['waiting_manager','ai_resumed','manager_taken','manager_reassigned','manager_released','conversation_closed','conversation_reopened'];
            foreach ($q->fetchAll() as $row) {
                if (in_array((string)$row['event_type'], $transitions, true)) return self::project($row);
            }
            return null; // No authoritative transition within the bounded tail: unknown.
        } catch (Throwable $ignored) {
            // Unknown context is not evidence that the client cancelled waiting.
            return null;
        }
    }

    public static function project(array $event): ?array
    {
        $payload = json_decode((string)($event['payload_json'] ?? ''), true);
        $time = (string)($event['created_at'] ?? '');
        if (($event['event_type'] ?? '') !== 'ai_resumed'
            || ($event['actor_type'] ?? '') !== 'customer'
            || !is_array($payload) || ($payload['reason'] ?? '') !== 'handoff_cancelled'
            || (int)($event['id'] ?? 0) <= 0
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $time)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $time) return null;
        return ['event_id'=>(int)$event['id'], 'event_type'=>'ai_resumed',
            'reason'=>'handoff_cancelled', 'created_at'=>$time];
    }
}
