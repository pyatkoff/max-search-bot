<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectMaxConnection.php';
require_once __DIR__ . '/../integrations/MaxMessengerAdapter.php';

/** Resolve the saved conversation's connection before any provider operation. */
final class ProjectMaxMessengerFactory
{
    private static $testFactory = null;

    public static function forConversation(array $conversation, string $senderType = 'manager'): ?MaxMessengerAdapter
    {
        if (($conversation['channel'] ?? null) !== 'max') return null;
        $projectKey = $conversation['project_key'] ?? null;
        if (!is_string($projectKey)) return null;
        $connection = ProjectMaxConnection::forConversation($conversation);
        return $connection ? self::forConnection($connection, $senderType) : null;
    }

    public static function forConnection(ProjectMaxConnection $connection, string $senderType = 'system'): MaxMessengerAdapter
    {
        if (self::$testFactory !== null) {
            $adapter = call_user_func(self::$testFactory, $connection, $senderType);
            if (!$adapter instanceof MaxMessengerAdapter) throw new LogicException('invalid_test_messenger');
            return $adapter;
        }
        if (ProjectMaxConnection::isLegacyProject($connection->projectKey())) {
            return new MaxMessengerAdapter(null, null, $senderType, null, false);
        }
        // Direct transport avoids AnyTour's legacy per-chat state/outcome mirror.
        // The caller records the result through its exact conversation owner.
        $send = static function ($chatId, string $text) use ($connection) {
            return self::send($connection, $chatId, ['text'=>$text, 'format'=>'html']);
        };
        $buttons = static function ($chatId, string $text, array $buttons) use ($connection) {
            $body = ['text'=>$text, 'format'=>'html'];
            $rows = MaxTransport::convertButtons($buttons);
            if ($rows) $body['attachments'] = [['type'=>'inline_keyboard','payload'=>['buttons'=>$rows]]];
            return self::send($connection, $chatId, $body);
        };
        $media = static function ($chatId, string $type, string $filePath, string $fileName, string $mimeType, string $text) use ($connection) {
            if ($connection->token() === '') return false;
            return MaxTransport::uploadAndSend($connection->baseUrl(), $connection->token(), $chatId,
                $type, $filePath, $fileName, $mimeType, $text, null);
        };
        $edit = static function (string $messageId, string $text) use ($connection): bool {
            if ($connection->token() === '') return false;
            return MaxTransport::editMessage($connection->baseUrl(), $connection->token(), $messageId, $text, null);
        };
        return new MaxMessengerAdapter($send, $buttons, $senderType, $media, false, $edit);
    }

    public static function resetForTests(?callable $factory = null): void
    {
        if (PHP_SAPI !== 'cli') throw new LogicException('test_override_requires_cli');
        self::$testFactory = $factory;
    }

    private static function send(ProjectMaxConnection $connection, $chatId, array $body)
    {
        if ($connection->token() === '' || MaxTransport::externalUserId($chatId) < 1) return false;
        $result = MaxTransport::request($connection->baseUrl(), $connection->token(), 'POST', '/messages',
            ['user_id'=>MaxTransport::externalUserId($chatId)], $body, null);
        return MaxTransport::extractMessageId($result);
    }
}
