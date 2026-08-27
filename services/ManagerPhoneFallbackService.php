<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ManagerAvailabilityService.php';
require_once __DIR__ . '/DialogueView.php';
require_once __DIR__ . '/ConversationControlService.php';

class ManagerPhoneFallbackService
{
    public const DELAY_SECONDS = 300;

    public static function runDue(?int $now = null, int $limit = 50): array
    {
        $now = $now ?? time();
        $summary = ['checked'=>0,'sent'=>0,'skipped'=>0,'failed'=>0,'outside_hours'=>false];
        if (!ManagerAvailabilityService::withinWorkingHours($now)) {
            $summary['outside_hours'] = true;
            return $summary;
        }
        if (!ConversationDb::isConfigured()) return $summary;

        foreach (self::dueCandidates($now, $limit) as $candidate) {
            $summary['checked']++;
            $result = self::processCandidate($candidate, $now);
            if (isset($summary[$result])) $summary[$result]++;
            else $summary['skipped']++;
        }
        return $summary;
    }

    public static function dueCandidates(int $now, int $limit = 50): array
    {
        if (!ConversationDb::isConfigured()) return [];
        $limit = max(1, min(200, $limit));
        $pdo = ConversationDb::connection();
        $sql = "SELECT c.id AS conversation_id,c.external_chat_id,c.channel,c.status,e.id AS request_event_id,e.created_at AS request_at,e.payload_json
                FROM conversations c
                JOIN conversation_events e ON e.id=(
                    SELECT e2.id FROM conversation_events e2
                    WHERE e2.conversation_id=c.id AND e2.event_type='waiting_manager'
                    ORDER BY e2.id DESC LIMIT 1
                )
                WHERE c.channel='max' AND c.status IN ('waiting_manager','manager')
                ORDER BY e.created_at ASC LIMIT {$limit}";
        $rows = $pdo->query($sql)->fetchAll();
        return array_values(array_filter($rows, static function (array $row) use ($now): bool {
            $requestedAt = strtotime((string)($row['request_at'] ?? ''));
            return $requestedAt > 0 && ($now - $requestedAt) >= self::DELAY_SECONDS;
        }));
    }

    public static function processCandidate(array $candidate, ?int $now = null): string
    {
        $now = $now ?? time();
        if (!ManagerAvailabilityService::withinWorkingHours($now)) return 'skipped';
        if (!ConversationDb::isConfigured()) return 'skipped';

        $conversationId = (int)($candidate['conversation_id'] ?? 0);
        $chatId = trim((string)($candidate['external_chat_id'] ?? ''));
        $requestEventId = (int)($candidate['request_event_id'] ?? 0);
        $requestAt = trim((string)($candidate['request_at'] ?? ''));
        if ($conversationId <= 0 || $chatId === '' || $requestEventId <= 0 || $requestAt === '') return 'skipped';

        $pdo = ConversationDb::connection();
        $lockName = 'manager_phone_fallback_' . $conversationId;
        $lock = $pdo->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) return 'skipped';

        try {
            $fresh = self::freshState($conversationId, $requestEventId, $requestAt, $now);
            if (!$fresh['eligible']) return 'skipped';

            $claim = MaxSearchApi::getLastClaimForChat($chatId);
            if ($claim && trim((string)($claim['UF_PHONE'] ?? '')) !== '') return 'skipped';

            // Final reply check immediately before the external send narrows the cutoff race.
            if (self::hasManagerReply($conversationId, $requestAt)) return 'skipped';

            $payload = json_decode((string)($candidate['payload_json'] ?? ''), true);
            $fromTours = is_array($payload) && !empty($payload['from_tours']);
            $ok = DialogueView::managerPhoneFallback($chatId, $fromTours);
            ConversationControlService::event(
                $conversationId,
                $ok ? 'manager_phone_fallback_sent' : 'manager_phone_fallback_failed',
                'system',
                null,
                ['request_event_id'=>$requestEventId,'delay_seconds'=>self::DELAY_SECONDS,'from_tours'=>$fromTours]
            );
            return $ok ? 'sent' : 'failed';
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private static function freshState(int $conversationId, int $requestEventId, string $requestAt, int $now): array
    {
        $requestedTs = strtotime($requestAt);
        if ($requestedTs <= 0 || ($now - $requestedTs) < self::DELAY_SECONDS) return ['eligible'=>false];

        $pdo = ConversationDb::connection();
        $q = $pdo->prepare("SELECT status,channel FROM conversations WHERE id=? LIMIT 1");
        $q->execute([$conversationId]);
        $conversation = $q->fetch();
        if (!$conversation || (string)$conversation['channel'] !== 'max' || !in_array((string)$conversation['status'], ['waiting_manager','manager'], true)) return ['eligible'=>false];

        $q = $pdo->prepare("SELECT id FROM conversation_events WHERE conversation_id=? AND event_type='waiting_manager' ORDER BY id DESC LIMIT 1");
        $q->execute([$conversationId]);
        if ((int)$q->fetchColumn() !== $requestEventId) return ['eligible'=>false];

        if (self::hasManagerReply($conversationId, $requestAt)) return ['eligible'=>false];

        $q = $pdo->prepare("SELECT id FROM conversation_events WHERE conversation_id=? AND event_type='manager_phone_fallback_sent' AND created_at>=? ORDER BY id DESC LIMIT 1");
        $q->execute([$conversationId,$requestAt]);
        if ($q->fetchColumn()) return ['eligible'=>false];

        return ['eligible'=>true];
    }

    private static function hasManagerReply(int $conversationId, string $requestAt): bool
    {
        $q = ConversationDb::connection()->prepare("SELECT id FROM messages WHERE conversation_id=? AND direction='outbound' AND sender_type='manager' AND created_at>=? ORDER BY id DESC LIMIT 1");
        $q->execute([$conversationId,$requestAt]);
        return (bool)$q->fetchColumn();
    }
}
