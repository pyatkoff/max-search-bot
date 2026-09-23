<?php

/** Pure, public projection of a locally recorded adapter-acceptance result.
 * No network, database, read-receipt inference or historical backfill.
 */
class ManagerMessageDeliveryService
{
    public static function accepted(string $channel): array
    {
        if (!in_array($channel, ['max', 'telegram', 'website'], true)) return [];
        return ['version'=>1, 'source'=>'manager_outbound', 'channel'=>$channel,
            'state'=>$channel === 'website' ? 'stored' : 'accepted'];
    }

    /** Call only with a row obtained through the existing authorized detail path. */
    public static function project(array $message, string $conversationChannel): ?array
    {
        if ((int)($message['id'] ?? 0) <= 0
            || ($message['direction'] ?? '') !== 'outbound'
            || ($message['sender_type'] ?? '') !== 'manager'
            || !in_array($conversationChannel, ['max', 'telegram', 'website'], true)) return null;

        $state = 'unrecorded';
        $raw = $message['metadata_json'] ?? null;
        $metadata = is_string($raw) ? json_decode($raw, true, 32) : null;
        $receipt = is_array($metadata) ? ($metadata['manager_send_receipt'] ?? null) : null;
        $expected = $conversationChannel === 'website' ? 'stored' : 'accepted';
        if (($message['channel'] ?? null) === $conversationChannel
            && is_array($receipt)
            && ($receipt['version'] ?? null) === 1
            && ($receipt['source'] ?? null) === 'manager_outbound'
            && ($receipt['channel'] ?? null) === $conversationChannel
            && ($receipt['state'] ?? null) === $expected) $state = $expected;

        // A local read marker or a later client reply is never an outbound read receipt.
        return ['state'=>$state, 'channel'=>$conversationChannel, 'read'=>'unavailable'];
    }
}
