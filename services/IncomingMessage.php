<?php
require_once __DIR__ . '/UserContext.php';

class IncomingMessage
{
    public static function text(string $platform, $externalUserId, $internalChatId, string $messageId, string $text, array $user = [], array $raw = [], array $attachments = []): array
    {
        return [
            'type' => 'message',
            'platform' => strtolower(trim($platform)),
            'message_id' => $messageId,
            'text' => $text,
            'callback_data' => null,
            'callback_id' => null,
            'contact_phone' => null,
            'attachments' => array_values($attachments),
            'user' => UserContext::make($platform, $externalUserId, $internalChatId, $user),
            'raw' => $raw,
        ];
    }

    public static function callback(string $platform, $externalUserId, $internalChatId, string $callbackId, string $payload, array $user = [], array $raw = []): array
    {
        return [
            'type' => 'callback',
            'platform' => strtolower(trim($platform)),
            'message_id' => '',
            'text' => '',
            'callback_data' => $payload,
            'callback_id' => $callbackId,
            'contact_phone' => null,
            'attachments' => [],
            'user' => UserContext::make($platform, $externalUserId, $internalChatId, $user),
            'raw' => $raw,
        ];
    }

    public static function contact(string $platform, $externalUserId, $internalChatId, string $messageId, string $phone, array $user = [], array $raw = []): array
    {
        $message = self::text($platform, $externalUserId, $internalChatId, $messageId, '', $user, $raw);
        $message['type'] = 'contact';
        $message['contact_phone'] = trim($phone);
        return $message;
    }
}
