<?php
declare(strict_types=1);

/** Pure per-message album merge. No provider calls, timers or mutable shared state. */
final class ChannelPostTelegramAlbum
{
    public static function isAlbum(array $snapshot): bool
    {
        return (string)($snapshot['metadata']['media_group_id'] ?? $snapshot['media_group_id'] ?? '') !== '';
    }

    /** Stable first-observed target; later captions never move an existing button. */
    public static function target(array $snapshot): array
    {
        $mid = (string)($snapshot['metadata']['actual_message_id'] ?? '');
        $item = $snapshot['metadata']['telegram_album_items']['m_' . $mid] ?? null;
        return is_array($item) ? $item : $snapshot;
    }

    public static function sameTargetObservation(array $first, array $second): bool
    {
        if (!self::isAlbum($first) || !self::isAlbum($second)) return false;
        $a = self::target($first); $b = self::target($second);
        return (string)($a['metadata']['actual_message_id'] ?? '') !== ''
            && ($a['metadata']['actual_message_id'] ?? null) === ($b['metadata']['actual_message_id'] ?? null)
            && (string)($a['metadata']['source_event_id'] ?? '') !== ''
            && ($a['metadata']['source_event_id'] ?? null) === ($b['metadata']['source_event_id'] ?? null);
    }

    /** Merge under the publication-row lock; ordering is per member, never global. */
    public static function merge(?array $previous, array $incoming): array
    {
        $group = (string)($incoming['metadata']['media_group_id'] ?? $incoming['media_group_id'] ?? '');
        $mid = (string)($incoming['metadata']['actual_message_id'] ?? '');
        if ($group === '' || !preg_match('/\A[1-9][0-9]{0,19}\z/D', $mid)) throw new InvalidArgumentException('invalid_telegram_album_member');
        if ($previous !== null && (string)($previous['metadata']['media_group_id'] ?? $previous['media_group_id'] ?? '') !== $group) {
            throw new InvalidArgumentException('telegram_album_identity_mismatch');
        }
        $items = is_array($previous['metadata']['telegram_album_items'] ?? null) ? $previous['metadata']['telegram_album_items'] : [];
        $targetId = (string)($previous['metadata']['actual_message_id'] ?? $mid);
        if (!preg_match('/\A[1-9][0-9]{0,19}\z/D', $targetId)) $targetId = $mid;
        // Convert a historical unsupported-album snapshot without inventing members.
        if ($previous !== null && !$items) $items['m_' . $targetId] = self::member($previous);
        $old = $items['m_' . $mid] ?? null;
        if (is_array($old) && self::older($incoming, $old)) return ['snapshot' => $previous, 'stale' => true, 'anchor_event' => $mid === $targetId];
        $items['m_' . $mid] = self::member($incoming);
        if (count($items) > 10) throw new InvalidArgumentException('telegram_album_member_limit');
        uksort($items, static fn(string $a, string $b): int => self::decimalCompare(substr($a, 2), substr($b, 2)));
        $target = $items['m_' . $targetId];
        $snapshot = $target;
        $snapshot['raw_body'] = $mid === $targetId ? (array)($incoming['raw_body'] ?? []) : (array)($previous['raw_body'] ?? []);
        $snapshot['attachments'] = [];
        $caption = null; $reason = '';
        foreach ($items as $key => $item) {
            if ($caption === null && (string)($item['text'] ?? '') !== '') $caption = $item;
            foreach ((array)($item['attachments'] ?? []) as $attachment) {
                if (is_array($attachment)) $snapshot['attachments'][] = $attachment;
            }
            if (($item['metadata']['capability'] ?? '') !== 'supported') $reason = (string)($item['metadata']['capability_reason'] ?? 'unsupported_media');
            if ($key !== 'm_' . $targetId && !empty($item['keyboard'])) $reason = 'telegram_album_non_anchor_keyboard';
        }
        $snapshot['text'] = (string)($caption['text'] ?? '');
        $snapshot['markup'] = (array)($caption['markup'] ?? []);
        $snapshot['media_group_id'] = $group;
        $snapshot['metadata'] = (array)($incoming['metadata'] ?? []);
        $snapshot['metadata']['media_group_id'] = $group;
        $snapshot['metadata']['actual_message_id'] = $targetId;
        $snapshot['metadata']['telegram_album_event_message_id'] = $mid;
        $snapshot['metadata']['telegram_album_items'] = $items;
        $snapshot['metadata']['capability'] = $reason === '' ? 'supported' : 'requires_attention';
        $snapshot['metadata']['capability_reason'] = $reason;
        return ['snapshot' => $snapshot, 'stale' => false, 'anchor_event' => $mid === $targetId];
    }

    /** Non-anchor observations cannot turn an attempted album operation into a retry. */
    public static function successorOperation(array $previous, array $merged, bool $anchorEvent): array
    {
        $out = ['state' => 'pending', 'write_attempts' => 0, 'receipt' => [], 'reason' => ''];
        if (($merged['metadata']['capability'] ?? '') !== 'supported') {
            $out['state'] = 'requires_attention';
            $out['write_attempts'] = (int)($previous['write_attempts'] ?? 0) > 0 ? 1 : 0;
            $out['receipt'] = json_decode((string)($previous['receipt_json'] ?? '{}'), true) ?: [];
            $out['reason'] = (string)($merged['metadata']['capability_reason'] ?? 'unsupported_media');
            return $out;
        }
        if ($anchorEvent || !self::sameTargetObservation((array)($previous['snapshot'] ?? []), $merged)) return $out;
        $state = (string)($previous['state'] ?? '');
        $attempts = (int)($previous['write_attempts'] ?? 0);
        if ($attempts > 0 || $state === 'unknown') {
            $out['state'] = $state === 'sent' ? 'sent' : ($state === 'requires_attention' ? 'requires_attention' : 'unknown');
            $out['write_attempts'] = 1;
        } elseif ($state === 'sent') $out['state'] = 'sent';
        elseif ($state === 'requires_attention' && !in_array((string)($previous['error_reason'] ?? ''), ['album_not_supported', 'superseded_before_write'], true)) $out['state'] = 'requires_attention';
        if ($out['state'] !== 'pending') {
            $out['receipt'] = json_decode((string)($previous['receipt_json'] ?? '{}'), true) ?: [];
            $out['reason'] = (string)($previous['error_reason'] ?? '');
        }
        return $out;
    }

    private static function member(array $snapshot): array
    {
        unset($snapshot['raw_body'], $snapshot['content_hash']);
        // A stored photo member was previously rejected solely because it belonged
        // to an album. The new merge policy removes that obsolete limitation.
        if (($snapshot['metadata']['capability_reason'] ?? '') === 'album_not_supported') {
            $images = (array)($snapshot['attachments'] ?? []);
            $valid = $images !== [];
            foreach ($images as $image) {
                if (!is_array($image) || ($image['type'] ?? '') !== 'image' || ($image['provider'] ?? '') !== 'telegram'
                    || (string)($image['file_unique_id'] ?? '') === '' || (string)($image['telegram_file_id'] ?? '') === '') $valid = false;
            }
            $snapshot['metadata']['capability'] = $valid ? 'supported' : 'requires_attention';
            $snapshot['metadata']['capability_reason'] = $valid ? '' : 'unsupported_media';
        }
        unset($snapshot['metadata']['telegram_album_items'], $snapshot['metadata']['telegram_album_event_message_id']);
        return $snapshot;
    }

    private static function older(array $incoming, array $previous): bool
    {
        $a = self::revision($incoming); $b = self::revision($previous);
        if ($a !== $b) return $a > 0 && $b > $a;
        if ($a <= 0) return false;
        $as = $incoming['metadata']['source_sequence'] ?? null; $bs = $previous['metadata']['source_sequence'] ?? null;
        return self::sequence($as) && self::sequence($bs) && self::decimalCompare((string)$as, (string)$bs) < 0;
    }

    private static function revision(array $snapshot): int
    {
        $value = $snapshot['metadata']['source_revision_ms'] ?? null;
        if ((is_int($value) || is_string($value)) && preg_match('/\A[0-9]{1,16}\z/D', (string)$value)) return (int)$value;
        return (strtotime((string)(($snapshot['edited_at'] ?? '') ?: ($snapshot['published_at'] ?? ''))) ?: 0) * 1000;
    }

    private static function sequence($value): bool
    {
        return (is_int($value) || is_string($value)) && (bool)preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D', (string)$value);
    }

    private static function decimalCompare(string $first, string $second): int
    {
        return strlen($first) === strlen($second) ? strcmp($first, $second) : strlen($first) <=> strlen($second);
    }
}
