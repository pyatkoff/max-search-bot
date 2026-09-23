<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationService.php';
require_once __DIR__.'/MaxInboundMediaArchiveService.php';
require_once __DIR__.'/ProjectConfig.php';

/** Authorized manager access to saved inbound MAX media. */
final class ManagerMaxMediaService
{
    public static function attachment(int $messageId, int $index, int $managerId): ?array
    {
        if ($messageId <= 0 || $index < 0 || $index > 20 || $managerId <= 0) return null;
        $q = ConversationDb::connection()->prepare('SELECT conversation_id,channel,direction,metadata_json FROM messages WHERE id=? LIMIT 1');
        $q->execute([$messageId]);
        $row = $q->fetch();
        if (!$row || (string)$row['channel'] !== 'max' || (string)$row['direction'] !== 'inbound') return null;
        $conversation = ManagerConversationService::visibleConversation((int)$row['conversation_id'], $managerId);
        if (!$conversation || (string)$conversation['project_key'] !== ProjectConfig::projectId()) return null;
        $meta = json_decode((string)($row['metadata_json'] ?? ''), true);
        $items = array_values(array_filter((array)($meta['attachments'] ?? []), static fn($a)=>is_array($a) && in_array((string)($a['type'] ?? ''), ['image','video','audio','file'], true)));
        $attachment = $items[$index] ?? null;
        if (!is_array($attachment) || !in_array((string)($attachment['type'] ?? ''), ['image','video','audio','file'], true)) return null;
        return ['message_id'=>$messageId,'attachment'=>$index];
    }

    /** Returns a private local stream; the caller must close it after serving. */
    public static function open(array $attachment, ?callable $messageFetcher = null, ?callable $mediaFetcher = null): ?array
    {
        return MaxInboundMediaArchiveService::openMessageAttachment(
            (int)($attachment['message_id'] ?? 0),
            (int)($attachment['attachment'] ?? -1),
            $messageFetcher,
            $mediaFetcher
        );
    }
}
