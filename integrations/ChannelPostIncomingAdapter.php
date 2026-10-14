<?php
declare(strict_types=1);

/** Channel publications are not customer messages and never enter the dialogue dispatcher. */
final class ChannelPostIncomingAdapter
{
    private const MAX_JSON_BYTES = 1048576;

    public static function isChannelUpdate(string $platform, array $update): bool
    {
        if ($platform === 'telegram') return array_key_exists('channel_post', $update) || array_key_exists('edited_channel_post', $update);
        return $platform === 'max' && ($update['message']['recipient']['chat_type'] ?? '') === 'channel';
    }

    public static function fromUpdate(string $platform, array $update): ?array
    {
        if (!self::isChannelUpdate($platform, $update)) return null;
        if ($platform === 'max') {
            if (!in_array($update['update_type'] ?? '', ['message_created', 'message_edited'], true)) return null;
            $message = $update['message'] ?? null;
            if (!is_array($message)) return null;
            $snapshot = self::maxSnapshot($message);
            $channelId = self::channelId($message['recipient']['chat_id'] ?? null);
            $messageId = $message['body']['mid'] ?? null;
            if ($snapshot === null || $channelId === '' || !is_string($messageId) || !preg_match('/\A[a-zA-Z0-9_.-]{1,200}\z/', $messageId)) return null;
            // Message.timestamp is creation time; Update.timestamp is event time.
            // Using the latter prevents a delayed older edit replacing new text.
            $revision = self::revisionMilliseconds($update['timestamp'] ?? $message['timestamp'] ?? null);
            if ($revision !== '') $snapshot['metadata']['source_revision_ms'] = $revision;
            if ($update['update_type'] === 'message_edited' && isset($update['timestamp'])) $snapshot['edited_at'] = self::timestamp($update['timestamp'], true);
            $event = [
                'platform' => 'max', 'channel_id' => $channelId, 'external_message_id' => $messageId,
                'snapshot' => $snapshot, 'edited' => $update['update_type'] === 'message_edited',
            ];
        } else {
            $edited = array_key_exists('edited_channel_post', $update);
            $message = $update[$edited ? 'edited_channel_post' : 'channel_post'] ?? null;
            if (!is_array($message) || ($message['chat']['type'] ?? '') !== 'channel') return null;
            $snapshot = self::telegramSnapshot($message);
            $channelId = self::channelId($message['chat']['id'] ?? null);
            $messageId = (string)($message['message_id'] ?? '');
            if ($snapshot === null || $channelId === '' || !preg_match('/\A[1-9][0-9]{0,19}\z/', $messageId)) return null;
            $revision = self::revisionMilliseconds($message['edit_date'] ?? $message['date'] ?? null, false);
            if ($revision !== '') $snapshot['metadata']['source_revision_ms'] = $revision;
            // Telegram edit_date has second precision. Within that same second,
            // its authenticated update_id orders previously unseen edit events.
            $sequence = $update['update_id'] ?? null;
            if ((is_int($sequence) || is_string($sequence)) && preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/', (string)$sequence)) {
                $snapshot['metadata']['source_sequence'] = (string)$sequence;
                $snapshot['metadata']['source_sequence_scope'] = 'telegram_update_id';
            }
            $group = (string)($message['media_group_id'] ?? '');
            if ($group !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $group)) return null;
            $event = [
                'platform' => 'telegram', 'channel_id' => $channelId,
                // An unsupported album still gets one shared publication identity,
                // never an unrelated token for each photo in the same group.
                'external_message_id' => $group !== '' ? 'group:' . $group : $messageId,
                'snapshot' => $snapshot, 'edited' => $edited,
            ];
        }
        $encoded = json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_JSON_BYTES) return null;
        $event['source_event_id'] = hash('sha256', $platform . ':' . ($update['update_id'] ?? '') . ':' . $encoded);
        $event['snapshot']['metadata']['source_event_id'] = $event['source_event_id'];
        return $event;
    }

    public static function maxSnapshot(array $message): ?array
    {
        $body = $message['body'] ?? null;
        if (!is_array($body) || !array_key_exists('text', $body) || (!is_string($body['text']) && $body['text'] !== null)) return null;
        $attachments = $body['attachments'] ?? [];
        if (!is_array($attachments)) return null;
        $media = []; $keyboard = []; $reason = ''; $keyboardCount = 0;
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) { $reason = 'invalid_attachment'; continue; }
            $type = $attachment['type'] ?? '';
            $payload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
            if ($type === 'inline_keyboard') {
                $keyboardCount++;
                if (!is_array($payload['buttons'] ?? null) || $keyboardCount > 1) $reason = 'invalid_keyboard';
                else $keyboard = $payload['buttons'];
                continue;
            }
            $item = ['type' => is_string($type) ? $type : 'unsupported', 'provider' => 'max'];
            foreach (['photo_id', 'token', 'url'] as $key) if (isset($payload[$key]) && is_scalar($payload[$key])) $item[$key] = (string)$payload[$key];
            $media[] = $item;
            if ($type !== 'image') $reason = 'unsupported_media';
            elseif (!preg_match('/\A[0-9]+\z/', (string)($payload['photo_id'] ?? ''))) $reason = 'image_stable_id_missing';
        }
        if (count($media) > 1) $reason = 'album_not_supported';
        if (count($attachments) > 12) $reason = 'attachment_limit';
        $channelId = self::channelId($message['recipient']['chat_id'] ?? null);
        $url = (string)($message['url'] ?? '');
        if ($url !== '' && !self::maxPostUrlAllowed($url, $channelId)) $url = '';
        return [
            'text' => $body['text'] ?? '', 'format' => 'native', 'attachments' => $media,
            'markup' => $body['markup'] ?? [], 'keyboard' => $keyboard,
            'post_url' => $url, 'raw_body' => $message,
            'published_at' => self::timestamp($message['timestamp'] ?? null, true),
            'metadata' => ['capability' => $reason === '' ? 'supported' : 'requires_attention', 'capability_reason' => $reason, 'link' => $message['link'] ?? null],
        ];
    }

    public static function telegramSnapshot(array $message): ?array
    {
        $text = $message['text'] ?? $message['caption'] ?? '';
        if (!is_string($text)) return null;
        $media = []; $reason = ''; $photo = null;
        foreach ((array)($message['photo'] ?? []) as $size) {
            if (!is_array($size) || !is_string($size['file_id'] ?? null) || !is_string($size['file_unique_id'] ?? null)) { $reason = 'image_stable_id_missing'; continue; }
            if ($photo === null || (int)($size['width'] ?? 0) * (int)($size['height'] ?? 0) > (int)($photo['width'] ?? 0) * (int)($photo['height'] ?? 0)) $photo = $size;
        }
        if ($photo !== null) $media[] = ['type' => 'image', 'provider' => 'telegram', 'telegram_file_id' => $photo['file_id'], 'file_unique_id' => $photo['file_unique_id']];
        foreach (['video', 'animation', 'audio', 'voice', 'video_note', 'document', 'sticker', 'contact', 'location', 'venue', 'poll', 'dice', 'paid_media'] as $field) {
            if (!array_key_exists($field, $message)) continue;
            $reason = 'unsupported_media';
            $media[] = ['type' => $field, 'provider' => 'telegram'];
        }
        if (!empty($message['media_group_id'])) $reason = 'album_not_supported';
        $keyboard = $message['reply_markup']['inline_keyboard'] ?? [];
        if (!is_array($keyboard)) { $keyboard = []; $reason = 'invalid_keyboard'; }
        $chatId = self::channelId($message['chat']['id'] ?? null);
        $username = (string)($message['chat']['username'] ?? '');
        $messageId = (string)($message['message_id'] ?? '');
        $url = '';
        if (preg_match('/\A[A-Za-z0-9_]{5,64}\z/', $username) && preg_match('/\A[1-9][0-9]*\z/', $messageId)) $url = 'https://t.me/' . $username . '/' . $messageId;
        elseif (preg_match('/\A-100([1-9][0-9]*)\z/', $chatId, $match) && preg_match('/\A[1-9][0-9]*\z/', $messageId)) $url = 'https://t.me/c/' . $match[1] . '/' . $messageId;
        return [
            'text' => $text, 'format' => 'native', 'attachments' => $media,
            'markup' => $message['entities'] ?? $message['caption_entities'] ?? [], 'keyboard' => $keyboard,
            'post_url' => $url, 'raw_body' => $message,
            'published_at' => self::timestamp($message['date'] ?? null, false),
            'edited_at' => self::timestamp($message['edit_date'] ?? null, false),
            'metadata' => ['capability' => $reason === '' ? 'supported' : 'requires_attention', 'capability_reason' => $reason, 'media_group_id' => (string)($message['media_group_id'] ?? ''), 'actual_message_id' => $messageId],
        ];
    }

    /** Signed tokens/URLs and our appended URL keyboard do not create a new post version. */
    public static function contentFingerprint(array $snapshot, string $ownedButtonUrl = '', string $botUrl = ''): string
    {
        $media = [];
        foreach ((array)($snapshot['attachments'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $media[] = [
                'type' => $item['type'] ?? '',
                'stable_id' => (string)($item['photo_id'] ?? $item['file_unique_id'] ?? $item['telegram_file_id'] ?? ''),
            ];
        }
        $keyboard = self::withoutOwnedButton((array)($snapshot['keyboard'] ?? []), $ownedButtonUrl, $botUrl);
        return hash('sha256', (string)json_encode([
            $snapshot['text'] ?? '', $snapshot['markup'] ?? null, $media, $keyboard,
            $snapshot['metadata']['link'] ?? null, $snapshot['metadata']['media_group_id'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function withoutOwnedButton(array $keyboard, string $ownedButtonUrl = '', string $botUrl = ''): array
    {
        $out = [];
        foreach ($keyboard as $row) {
            if (!is_array($row)) { $out[] = $row; continue; }
            $remaining = [];
            foreach ($row as $button) {
                $url = is_array($button) && is_string($button['url'] ?? null) ? $button['url'] : '';
                $owned = $url !== '' && ($url === $ownedButtonUrl || ($botUrl !== '' && preg_match('/\A' . preg_quote(rtrim($botUrl, '/') . '?start=', '/') . 'p_[A-Za-z0-9_-]{8,60}\z/', $url)));
                if (!$owned) $remaining[] = $button;
            }
            if ($remaining !== []) $out[] = $remaining;
        }
        return $out;
    }

    public static function containsButton(array $snapshot, string $url, string $label): bool
    {
        $count = 0;
        foreach ((array)($snapshot['keyboard'] ?? []) as $row) foreach ((array)$row as $button) {
            if (is_array($button) && ($button['url'] ?? '') === $url && ($button['text'] ?? '') === $label && (!isset($button['type']) || $button['type'] === 'link')) $count++;
        }
        return $count === 1;
    }

    public static function channelId($value): string
    {
        if (!is_int($value) && !is_string($value)) return '';
        $value = (string)$value;
        return preg_match('/\A-[1-9][0-9]{0,19}\z/', $value) ? $value : '';
    }

    private static function timestamp($value, bool $milliseconds): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[0-9]{1,16}\z/', (string)$value)) return '';
        $seconds = $milliseconds ? (int)floor((float)$value / 1000) : (int)$value;
        return $seconds > 0 ? gmdate('Y-m-d H:i:s', $seconds) : '';
    }

    private static function revisionMilliseconds($value, bool $milliseconds = true): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]{0,15}\z/', (string)$value)) return '';
        return $milliseconds ? (string)$value : (string)((int)$value * 1000);
    }

    private static function maxPostUrlAllowed(string $url, string $channelId): bool
    {
        return $channelId !== '' && strlen($url) <= 300 && (bool)preg_match('/\Ahttps:\/\/max\.ru\/c\/' . preg_quote($channelId, '/') . '\/[A-Za-z0-9_-]{1,100}\z/', $url);
    }
}
