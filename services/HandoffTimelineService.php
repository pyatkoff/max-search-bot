<?php

/**
 * Side-effect-free diagnostic assembler for one manager handoff lifecycle.
 *
 * Inputs intentionally come from existing DB lifecycle rows/messages and
 * structured manager priority/push diagnostics. This keeps the view read-only
 * and lets production diagnostics explain a handoff without changing routing,
 * assignment or delivery behavior.
 */
class HandoffTimelineService
{
    public static function build(
        array $conversation,
        array $dbEvents,
        array $messages,
        array $priorityEvents,
        array $pushEvents
    ): array {
        $conversationId = (int)($conversation['id'] ?? $conversation['conversation_id'] ?? 0);
        $request = self::latestDbEvent($dbEvents, 'waiting_manager');
        $requestAt = (string)($request['created_at'] ?? '');

        $dbEvents = array_values(array_filter($dbEvents, static function(array $row) use ($requestAt): bool {
            return $requestAt === '' || (string)($row['created_at'] ?? '') >= $requestAt;
        }));
        $messages = array_values(array_filter($messages, static function(array $row) use ($requestAt): bool {
            return $requestAt === '' || (string)($row['created_at'] ?? '') >= $requestAt;
        }));
        $priorityEvents = self::structuredForConversation($priorityEvents, $conversationId, $requestAt);
        $pushEvents = self::structuredForConversation($pushEvents, $conversationId, $requestAt);

        $selected = self::latestStructuredEvent($priorityEvents, 'push_selected');
        $dispatchId = trim((string)($selected['data']['dispatch_id'] ?? ''));
        if ($dispatchId === '') {
            foreach ($pushEvents as $row) {
                $candidate = trim((string)($row['data']['dispatch_id'] ?? ''));
                if ($candidate !== '') { $dispatchId = $candidate; break; }
            }
        }

        $dispatchPushEvents = $dispatchId === '' ? $pushEvents : array_values(array_filter(
            $pushEvents,
            static fn(array $row): bool => (string)($row['data']['dispatch_id'] ?? '') === $dispatchId
        ));

        $taken = self::firstDbEvent($dbEvents, 'manager_taken');
        $replyEvent = self::firstDbEvent($dbEvents, 'manager_message');
        $replyFailure = self::firstDbEvent($dbEvents, 'manager_message_failed');
        $firstReplyMessage = self::firstManagerMessage($messages);

        $pushResults = [];
        foreach ($dispatchPushEvents as $row) {
            $data = (array)($row['data'] ?? []);
            $pushResults[] = [
                'at' => (string)($row['ts'] ?? ''),
                'event' => (string)($row['event'] ?? ''),
                'manager_id' => isset($data['manager_id']) ? (int)$data['manager_id'] : null,
                'subscription_id' => isset($data['subscription_id']) ? (int)$data['subscription_id'] : null,
                'http_code' => isset($data['http_code']) ? (int)$data['http_code'] : null,
                'reason' => $data['reason'] ?? null,
            ];
        }

        $selectedData = (array)($selected['data'] ?? []);
        $timeline = [];
        if ($request) $timeline[] = ['stage'=>'requested','at'=>(string)($request['created_at'] ?? ''),'actor_type'=>(string)($request['actor_type'] ?? '')];
        if ($selected) $timeline[] = [
            'stage'=>'selected',
            'at'=>(string)($selected['ts'] ?? ''),
            'dispatch_id'=>$dispatchId !== '' ? $dispatchId : null,
            'eligible_manager_ids'=>array_values((array)($selectedData['eligible_manager_ids'] ?? [])),
            'selected_manager_ids'=>array_values((array)($selectedData['selected_manager_ids'] ?? [])),
            'scores'=>(array)($selectedData['scores'] ?? []),
        ];
        foreach ($pushResults as $result) $timeline[] = array_merge(['stage'=>'push_result'], $result);
        if ($taken) $timeline[] = ['stage'=>'taken','at'=>(string)($taken['created_at'] ?? ''),'manager_id'=>isset($taken['actor_id'])?(int)$taken['actor_id']:null];
        if ($firstReplyMessage) $timeline[] = ['stage'=>'first_reply','at'=>(string)($firstReplyMessage['created_at'] ?? ''),'message_id'=>isset($firstReplyMessage['id'])?(int)$firstReplyMessage['id']:null];
        if ($replyEvent) $timeline[] = ['stage'=>'delivery_success','at'=>(string)($replyEvent['created_at'] ?? ''),'manager_id'=>isset($replyEvent['actor_id'])?(int)$replyEvent['actor_id']:null];
        if ($replyFailure) $timeline[] = ['stage'=>'delivery_failed','at'=>(string)($replyFailure['created_at'] ?? ''),'manager_id'=>isset($replyFailure['actor_id'])?(int)$replyFailure['actor_id']:null];

        usort($timeline, static fn(array $a,array $b): int => strcmp((string)($a['at'] ?? ''),(string)($b['at'] ?? '')));

        return [
            'conversation_id' => $conversationId,
            'project_key' => (string)($conversation['project_key'] ?? ''),
            'channel' => (string)($conversation['channel'] ?? ''),
            'status' => (string)($conversation['status'] ?? ''),
            'manager_id' => isset($conversation['manager_id']) ? (int)$conversation['manager_id'] : null,
            'request_at' => $requestAt !== '' ? $requestAt : null,
            'dispatch_id' => $dispatchId !== '' ? $dispatchId : null,
            'timeline' => $timeline,
            'summary' => [
                'requested' => (bool)$request,
                'selected' => (bool)$selected,
                'push_result_count' => count($pushResults),
                'push_success_count' => count(array_filter($pushResults, static fn(array $r): bool => $r['event'] === 'delivery_success')),
                'push_failure_count' => count(array_filter($pushResults, static fn(array $r): bool => $r['event'] !== 'delivery_success')),
                'taken' => (bool)$taken,
                'first_reply' => (bool)$firstReplyMessage,
                'delivery_success' => (bool)$replyEvent,
                'delivery_failed' => (bool)$replyFailure,
            ],
        ];
    }

    private static function structuredForConversation(array $events, int $conversationId, string $requestAt): array
    {
        $out = [];
        foreach ($events as $row) {
            $data = (array)($row['data'] ?? []);
            if ((int)($data['conversation_id'] ?? 0) !== $conversationId) continue;
            if ($requestAt !== '' && (string)($row['ts'] ?? '') < str_replace(' ', 'T', $requestAt)) continue;
            $out[] = $row;
        }
        usort($out, static fn(array $a,array $b): int => strcmp((string)($a['ts'] ?? ''),(string)($b['ts'] ?? '')));
        return $out;
    }

    private static function latestStructuredEvent(array $events, string $event): ?array
    {
        $matched = array_values(array_filter($events, static fn(array $row): bool => (string)($row['event'] ?? '') === $event));
        return $matched ? $matched[count($matched)-1] : null;
    }

    private static function latestDbEvent(array $events, string $eventType): ?array
    {
        $matched = array_values(array_filter($events, static fn(array $row): bool => (string)($row['event_type'] ?? '') === $eventType));
        return $matched ? $matched[count($matched)-1] : null;
    }

    private static function firstDbEvent(array $events, string $eventType): ?array
    {
        foreach ($events as $row) if ((string)($row['event_type'] ?? '') === $eventType) return $row;
        return null;
    }

    private static function firstManagerMessage(array $messages): ?array
    {
        foreach ($messages as $row) {
            if ((string)($row['direction'] ?? '') === 'outbound' && (string)($row['sender_type'] ?? '') === 'manager') return $row;
        }
        return null;
    }
}
