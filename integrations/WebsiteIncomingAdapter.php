<?php
require_once __DIR__ . '/../services/IncomingMessage.php';

class WebsiteIncomingAdapter
{
    public static function fromPayload(array $payload, string $sessionId, int $chatId): ?array
    {
        $action = strtolower(trim((string)($payload['action'] ?? 'message')));
        $messageId = trim((string)($payload['message_id'] ?? ''));
        if ($messageId === '') $messageId = 'web-' . bin2hex(random_bytes(8));

        $user = [
            'first_name' => trim((string)($payload['name'] ?? '')),
            'last_name' => '',
            'username' => '',
        ];

        if ($action === 'start') {
            return IncomingMessage::text('website', $sessionId, $chatId, $messageId, '/start', $user, $payload);
        }

        if ($action === 'callback') {
            $data = trim((string)($payload['data'] ?? ''));
            if ($data === '') return null;
            return IncomingMessage::callback('website', $sessionId, $chatId, $messageId, $data, $user, $payload);
        }

        if ($action === 'contact') {
            $phone = trim((string)($payload['phone'] ?? ''));
            if ($phone === '') return null;
            return IncomingMessage::contact('website', $sessionId, $chatId, $messageId, $phone, $user, $payload);
        }

        $text = trim((string)($payload['text'] ?? ''));
        if ($text === '') return null;
        return IncomingMessage::text('website', $sessionId, $chatId, $messageId, $text, $user, $payload);
    }
}
