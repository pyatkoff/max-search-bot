<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationService.php';
require_once __DIR__.'/MaxInboundMediaArchiveService.php';
require_once __DIR__.'/ProjectMaxConnection.php';

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
        if (!$conversation || (string)$conversation['channel'] !== 'max'
            || ProjectMaxConnection::forConversation($conversation) === null) return null;
        $meta = json_decode((string)($row['metadata_json'] ?? ''), true);
        $items = array_values(array_filter((array)($meta['attachments'] ?? []), static fn($a)=>is_array($a) && in_array((string)($a['type'] ?? ''), ['image','video','audio','file'], true)));
        $attachment = $items[$index] ?? null;
        if (!is_array($attachment) || !in_array((string)($attachment['type'] ?? ''), ['image','video','audio','file'], true)) return null;
        return ['message_id'=>$messageId,'attachment'=>$index];
    }

    /** Returns a private local stream; the caller must close it after serving. */
    public static function open(array $attachment, ?callable $messageFetcher = null, ?callable $mediaFetcher = null, bool $persistRecovery = true, ?callable $videoFetcher = null): ?array
    {
        // Resolve credentials from the saved owner, never from browser metadata.
        $messageId=(int)($attachment['message_id'] ?? 0);
        if($messageId<=0)return null;
        $q=ConversationDb::connection()->prepare("SELECT c.id,c.project_key,c.channel FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.id=? AND m.channel='max' AND m.direction='inbound' LIMIT 1");
        $q->execute([$messageId]);$conversation=$q->fetch();
        if(!$conversation||(string)$conversation['channel']!=='max')return null;
        $connection=ProjectMaxConnection::forConversation($conversation);
        if(!$connection)return null;
        $messageFetcher=$messageFetcher??static fn(string $mid):?array=>MaxInboundMediaDownloadAdapter::fetchMessage($mid,$connection->token(),$connection->baseUrl());
        $videoFetcher=$videoFetcher??static fn(string $token):?array=>MaxInboundMediaDownloadAdapter::fetchVideo($token,$connection->token(),$connection->baseUrl());
        return MaxInboundMediaArchiveService::openMessageAttachment(
            $messageId,
            (int)($attachment['attachment'] ?? -1),
            $messageFetcher,
            $mediaFetcher,
            $persistRecovery,
            $videoFetcher
        );
    }
}
