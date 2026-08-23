<?php
require_once __DIR__ . '/../services/IncomingMessage.php';

class MaxIncomingAdapter
{
    public static function fromUpdate(array $update): ?array
    {
        $type = (string)($update['update_type'] ?? '');
        $user = self::user($update);
        $externalUserId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        if (!$externalUserId) return null;
        $internalId = $externalUserId > 0 ? -$externalUserId : $externalUserId;
        $normalizedUser = self::normalizedUser($user);

        if ($type === 'message_created') {
            $messageId = (string)($update['message']['body']['mid'] ?? '');
            $phone = self::contactPhone($update);
            if ($phone !== '') {
                return IncomingMessage::contact('max', $externalUserId, $internalId, $messageId, $phone, $normalizedUser, $update);
            }
            $text = (string)($update['message']['body']['text'] ?? $update['message']['text'] ?? '');
            return IncomingMessage::text('max', $externalUserId, $internalId, $messageId, $text, $normalizedUser, $update);
        }

        if ($type === 'message_callback') {
            return IncomingMessage::callback(
                'max',
                $externalUserId,
                $internalId,
                (string)($update['callback']['callback_id'] ?? ''),
                (string)($update['callback']['payload'] ?? ''),
                $normalizedUser,
                $update
            );
        }

        return null;
    }

    public static function user(array $update): array
    {
        if (!empty($update['callback']['user'])) return (array)$update['callback']['user'];
        if (!empty($update['message']['sender'])) return (array)$update['message']['sender'];
        if (!empty($update['user'])) return (array)$update['user'];
        return [];
    }

    private static function normalizedUser(array $user): array
    {
        $name = trim((string)($user['name'] ?? ''));
        $parts = $name !== '' ? preg_split('/\s+/', $name, 2) : [];
        return [
            'first_name' => (string)($user['first_name'] ?? ($parts[0] ?? '')),
            'last_name' => (string)($user['last_name'] ?? ($parts[1] ?? '')),
            'username' => (string)($user['username'] ?? ''),
        ];
    }

    private static function contactPhone(array $update): string
    {
        $attachments = $update['message']['body']['attachments'] ?? $update['message']['attachments'] ?? [];
        foreach ((array)$attachments as $attachment) {
            if (($attachment['type'] ?? '') !== 'contact') continue;
            $payload = (array)($attachment['payload'] ?? []);
            $vcf = (string)($payload['vcf_info'] ?? '');
            if ($vcf !== '' && preg_match('/TEL[^:]*:([+0-9]+)/i', $vcf, $m)) return trim($m[1]);
            foreach (['phone','phone_number'] as $key) {
                if (!empty($payload[$key])) return trim((string)$payload[$key]);
            }
        }
        return '';
    }
}
