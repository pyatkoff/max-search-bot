<?php

declare(strict_types=1);

/** Read-only calendar activity across transports, independent of saved advertising metadata. */
final class ChannelDailyReport
{
    public static function collect(PDO $pdo, string $project, DateTimeImmutable $now): array
    {
        $utc = new DateTimeZone('UTC');
        $local = $now->setTimezone(new DateTimeZone('Europe/Kaliningrad'));
        $capture = $now->setTimezone($utc)->format('Y-m-d H:i:s');
        $report = [
            'ok'=>true, 'generated_at'=>$now->setTimezone($utc)->format('c'),
            'timezone'=>'Europe/Kaliningrad', 'basis'=>'recorded_conversation_activity',
            'reply_basis'=>'any_recorded_outbound_manager_message_in_day',
            'request_basis'=>'recorded_manager_request_or_waiting_manager_event_in_day',
            'days'=>[],
        ];
        // Aggregate timestamps/directions only. No transcript, identifiers or source labels leave this boundary.
        $query = $pdo->prepare("SELECT c.channel,c.started_at,c.source_id,
            COALESCE(m.inbound_count,0) AS inbound_count,
            COALESCE(m.reply_count,0) AS reply_count,
            COALESCE(e.request_count,0) AS request_count
            FROM conversations c
            LEFT JOIN (
                SELECT m.conversation_id,
                    SUM(CASE WHEN m.direction='inbound' THEN 1 ELSE 0 END) AS inbound_count,
                    SUM(CASE WHEN m.direction='outbound' AND m.sender_type='manager' THEN 1 ELSE 0 END) AS reply_count
                FROM messages m JOIN conversations scope ON scope.id=m.conversation_id
                WHERE scope.project_key=? AND scope.is_test=0 AND m.created_at>=? AND m.created_at<?
                GROUP BY m.conversation_id
            ) m ON m.conversation_id=c.id
            LEFT JOIN (
                SELECT e.conversation_id,
                    SUM(CASE WHEN e.event_type='waiting_manager' OR (LOWER(e.event_type) LIKE '%manager%' AND LOWER(e.event_type) LIKE '%request%') THEN 1 ELSE 0 END) AS request_count
                FROM conversation_events e JOIN conversations scope ON scope.id=e.conversation_id
                WHERE scope.project_key=? AND scope.is_test=0 AND e.created_at>=? AND e.created_at<?
                GROUP BY e.conversation_id
            ) e ON e.conversation_id=c.id
            WHERE c.project_key=? AND c.is_test=0 AND c.started_at<?
                AND (c.started_at>=? OR m.conversation_id IS NOT NULL OR e.conversation_id IS NOT NULL)
            ORDER BY c.id LIMIT 10001");
        for ($day=$local->setTime(0,0)->modify('-6 days'); $day <= $local; $day=$day->modify('+1 day')) {
            $since = $day->setTimezone($utc)->format('Y-m-d H:i:s');
            $until = min($capture,$day->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'));
            $query->execute([$project,$since,$until,$project,$since,$until,$project,$until,$since]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows)>10000) throw new RuntimeException('channel_report_conversation_limit');
            $channels = [];
            foreach (['max','telegram','website','other'] as $channel) {
                $channels[$channel] = ['channel'=>$channel, 'active_conversations'=>0,
                    'new_conversations'=>0, 'continued_conversations'=>0,
                    'inbound_conversations'=>0, 'manager_requested_conversations'=>0,
                    'manager_replied_conversations'=>0, 'missing_source_conversations'=>0];
            }
            foreach ($rows as $row) {
                $channel = isset($channels[$row['channel']]) ? $row['channel'] : 'other';
                $counts = &$channels[$channel];
                $counts['active_conversations']++;
                $counts[$row['started_at'] >= $since ? 'new_conversations' : 'continued_conversations']++;
                if ((int)$row['inbound_count']>0) $counts['inbound_conversations']++;
                if ((int)$row['request_count']>0) $counts['manager_requested_conversations']++;
                if ((int)$row['reply_count']>0) $counts['manager_replied_conversations']++;
                if (empty($row['source_id'])) $counts['missing_source_conversations']++;
                unset($counts);
            }
            $report['days'][] = ['date'=>$day->format('Y-m-d'), 'partial'=>$day->format('Y-m-d')===$local->format('Y-m-d'),
                'since_utc'=>$since, 'until_utc'=>$until, 'channels'=>array_values($channels)];
        }
        return $report;
    }
}
