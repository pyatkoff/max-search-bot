<?php

declare(strict_types=1);

require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ProjectAccessService.php';
require_once __DIR__ . '/ManagerConversationAccessPolicy.php';

/** Read-only recent arrivals. Reconcile exact IDs: allocation is not commit order. */
final class ManagerIncomingNotificationService
{
    public const PAGE_SIZE = 500;
    public const LOOKBACK_SECONDS = 300;

    public static function poll(int $managerId, ?int $cursor): array
    {
        ProjectAccessService::initializeReadOnly();
        return self::collect(
            ConversationDb::connection(), $managerId,
            array_column(ProjectAccessService::projectsForManager($managerId), 'project_key'), $cursor,
            static fn(array $row): bool => ManagerConversationAccessPolicy::canView($managerId, $row)
        );
    }

    /** Cursor denotes an initialized client, not a SQL high-water exclusion. */
    public static function collect(PDO $pdo, int $managerId, array $projectKeys, ?int $cursor, callable $canView): array
    {
        if ($managerId <= 0 || ($cursor !== null && ($cursor < 0 || $cursor > 9007199254740991))) {
            throw new InvalidArgumentException('invalid_notification_cursor');
        }
        $result = ['manager_id'=>$managerId, 'cursor'=>0, 'events'=>[], 'has_more'=>false];
        $keys = array_values(array_unique(array_filter($projectKeys, static fn($key): bool => is_string($key) && $key !== '')));
        if (!$keys) return $result;
        // Use the database wall clock, matching messages.created_at even when its
        // session timezone differs from PHP. No external timezone is assumed.
        $now = (string)$pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
        $clock = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $now, new DateTimeZone('UTC'));
        if (!$clock || $clock->format('Y-m-d H:i:s') !== $now) throw new RuntimeException('notification_clock_unavailable');
        $since = $clock->modify('-'.self::LOOKBACK_SECONDS.' seconds')->format('Y-m-d H:i:s');
        $in = implode(',', array_fill(0, count($keys), '?'));
        $sql = "SELECT m.id AS message_id,m.metadata_json,c.id AS conversation_id,c.project_key,c.source_id,c.status,c.manager_id,c.started_at,c.last_message_at "
            ."FROM messages m JOIN conversations c ON c.id=m.conversation_id "
            ."WHERE m.created_at>=? AND m.created_at<=? AND m.direction='inbound' AND m.sender_type='customer' "
            ."AND c.is_test=0 AND c.project_key IN ($in) "
            ."AND ((c.status='manager' AND c.manager_id=?) OR (c.status='waiting_manager' AND c.manager_id IS NULL)) "
            .'ORDER BY m.id ASC LIMIT '.(self::PAGE_SIZE + 1);
        $q = $pdo->prepare($sql);
        $q->execute(array_merge([$since, $now], $keys, [$managerId]));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        // Never claim a complete window after truncation. Keep OS push intact;
        // the page shows an unavailable state and rebaselines after recovery.
        if (count($rows) > self::PAGE_SIZE) throw new RuntimeException('notification_window_overflow');
        $allowed = [];
        foreach ($rows as $row) {
            $raw = (string)($row['metadata_json'] ?? '');
            $metadata = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($metadata) || !in_array($metadata['type'] ?? 'message', ['message','contact'], true)) continue;
            $id = (int)$row['conversation_id'];
            $row['id'] = $id;
            unset($row['metadata_json']);
            if (!array_key_exists($id, $allowed)) $allowed[$id] = (bool)$canView($row);
            if ($allowed[$id]) {
                $messageId = (int)$row['message_id'];
                $result['events'][] = ['conversation_id'=>$id, 'message_id'=>$messageId];
                $result['cursor'] = max($result['cursor'], $messageId);
            }
        }
        // Initial events are returned so the client can mark the whole current
        // window silently. Late lower-ID commits within five minutes remain visible.
        return $result;
    }
}
