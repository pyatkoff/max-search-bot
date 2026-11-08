<?php

require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/SourceHotelIntentService.php';

/**
 * Read-only projection of the latest manager-request event for panel context.
 *
 * The queue/routing event is already persisted by the canonical handoff path.
 * This service must never mutate conversation state or infer a viewed hotel/tour.
 */
final class ManagerHandoffEventContextService
{
    public static function latestManagerRequest(int $conversationId): array
    {
        if ($conversationId <= 0 || !ConversationDb::isConfigured()) return [];

        try {
            $q = ConversationDb::connection()->prepare(
                "SELECT payload_json,created_at FROM conversation_events "
                . "WHERE conversation_id=? AND event_type='waiting_manager' ORDER BY id DESC LIMIT 1"
            );
            $q->execute([$conversationId]);
            $row = $q->fetch();
            $intent = SourceHotelIntentService::projection($conversationId);
            if (!$row) return $intent ? ['hotel_request_intent'=>$intent] : [];

            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) $payload = [];

            return [
                'from_tours'=>!empty($payload['from_tours']),
                'created_at'=>(string)($row['created_at'] ?? ''),
            ] + ($intent ? ['hotel_request_intent'=>$intent] : []);
        } catch (Throwable $ignored) {
            // Panel enrichment is optional. A read failure must not break the
            // manager detail endpoint or change queue/routing behaviour.
            return [];
        }
    }
}
