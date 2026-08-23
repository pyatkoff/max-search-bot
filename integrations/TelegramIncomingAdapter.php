<?php
require_once __DIR__ . '/../services/IncomingMessage.php';

class TelegramIncomingAdapter
{
    public static function fromUpdate(array $update): ?array
    {
        if (!empty($update['callback_query'])) {
            $q = (array)$update['callback_query'];
            $from = (array)($q['from'] ?? []);
            $externalUserId = (int)($from['id'] ?? 0);
            if (!$externalUserId) return null;
            return IncomingMessage::callback(
                'telegram',
                $externalUserId,
                $externalUserId,
                (string)($q['id'] ?? ''),
                (string)($q['data'] ?? ''),
                self::normalizedUser($from),
                $update
            );
        }

        $message = (array)($update['message'] ?? []);
        if (!$message) return null;
        $from = (array)($message['from'] ?? []);
        $externalUserId = (int)($from['id'] ?? 0);
        if (!$externalUserId) return null;
        $messageId = (string)($message['message_id'] ?? '');
        $contact = (array)($message['contact'] ?? []);
        if (!empty($contact['phone_number'])) {
            return IncomingMessage::contact(
                'telegram',
                $externalUserId,
                $externalUserId,
                $messageId,
                (string)$contact['phone_number'],
                self::normalizedUser($from),
                $update
            );
        }
        return IncomingMessage::text(
            'telegram',
            $externalUserId,
            $externalUserId,
            $messageId,
            (string)($message['text'] ?? ''),
            self::normalizedUser($from),
            $update
        );
    }

    private static function normalizedUser(array $from): array
    {
        return [
            'first_name' => (string)($from['first_name'] ?? ''),
            'last_name' => (string)($from['last_name'] ?? ''),
            'username' => (string)($from['username'] ?? ''),
        ];
    }
}
