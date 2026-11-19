<?php

declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/../integrations/TelegramMessengerAdapter.php';

final class ProjectTelegramMessengerFactory
{
    public static function forConversation(array $conversation, string $senderType = 'manager'): ?TelegramMessengerAdapter
    {
        if (($conversation['channel'] ?? null) !== 'telegram') return null;
        $project = $conversation['project_key'] ?? null;
        if ($project !== 'appleservice39') {
            return $project === ProjectConfig::projectId() ? new TelegramMessengerAdapter(null, $senderType, false) : null;
        }

        try {
            require_once __DIR__.'/AppleService39TelegramConnection.php';
            $binding = AppleService39TelegramConnection::runtimeBinding();
            return $binding === null ? null : self::appleMessenger($conversation, $binding, $senderType);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Only for the protected manager file endpoint. The saved message and conversation must both belong to this bot. */
    public static function mediaTokenForConversation(array $conversation, string $messageBotId): ?string
    {
        if (($conversation['project_key'] ?? '') !== 'appleservice39' || $messageBotId === '') return null;
        require_once __DIR__.'/AppleService39TelegramConnection.php';
        $binding = AppleService39TelegramConnection::runtimeBinding();
        if ($binding === null || $binding['bot_id'] !== $messageBotId
            || (int)$conversation['source_id'] !== $binding['source_id']
            || self::appleMessenger($conversation, $binding, 'manager') === null) return null;
        return $binding['bot_token'];
    }

    private static function appleMessenger(array $conversation, array $binding, string $senderType): ?TelegramMessengerAdapter
    {
        $chat = (string)($conversation['external_chat_id'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $chat)) return null;
        if (($binding['project_key'] ?? null) !== $conversation['project_key'] || ($binding['source_key'] ?? null) !== 'telegram:appleservice39-main'
            || !is_string($binding['bot_id'] ?? null) || !preg_match('/^[1-9][0-9]{0,18}$/D', $binding['bot_id'])
            || !is_string($binding['bot_token'] ?? null) || $binding['bot_token'] === '') return null;

        $query = ConversationDb::connection()->prepare("SELECT c.metadata_json FROM conversations c
            JOIN projects p ON p.project_key=c.project_key AND p.is_active=1
            JOIN conversation_sources s ON s.id=c.source_id AND s.project_id=p.id AND s.channel=c.channel AND s.is_active=1
            JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id
                AND cc.project_key=c.project_key AND cc.channel=c.channel AND cc.external_chat_id=c.external_chat_id
            WHERE c.id=? AND c.project_key=? AND c.channel='telegram' AND c.customer_id=? AND c.source_id=?
                AND c.external_chat_id=? AND cc.external_user_id=? AND s.source_key=? LIMIT 1");
        $query->execute([(int)($conversation['id'] ?? 0), $conversation['project_key'], (int)($conversation['customer_id'] ?? 0),
            (int)($conversation['source_id'] ?? 0), $chat, $chat, $binding['source_key']]);
        $metadata = json_decode((string)$query->fetchColumn(), true);
        if (!is_array($metadata) || ($metadata['telegram_bot_id'] ?? null) !== $binding['bot_id']) return null;
        return new TelegramMessengerAdapter(null, $senderType, false, $binding['bot_token'], $chat);
    }
}
