<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ConversationRecorder.php';

class ManagerMessageMediaService
{
    public static function hydrate(array $messages): array
    {
        $ids = array_values(array_filter(array_map(static function ($message) {
            return (int)($message['id'] ?? 0);
        }, $messages)));
        if (!$ids || !ConversationDb::isConfigured()) return $messages;

        $pdo = ConversationDb::connection();
        $sql = 'SELECT id,channel,direction,metadata_json FROM messages WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $q = $pdo->prepare($sql);
        $q->execute($ids);
        $mediaById = [];
        foreach ($q->fetchAll() as $row) {
            $meta = json_decode((string)($row['metadata_json'] ?? ''), true);
            if (!is_array($meta) || empty($meta['attachments']) || !is_array($meta['attachments'])) continue;
            $mediaById[(int)$row['id']] = [
                'channel'=>(string)($row['channel'] ?? ''),
                'direction'=>(string)($row['direction'] ?? ''),
                'attachments'=>array_values(array_filter($meta['attachments'], static function ($attachment) {
                    return is_array($attachment) && in_array((string)($attachment['type'] ?? ''), ['image','video','audio','file'], true);
                })),
            ];
        }
        foreach ($messages as &$message) {
            $media = $mediaById[(int)($message['id'] ?? 0)] ?? ['channel'=>'','direction'=>'','attachments'=>[]];
            $attachments = $media['attachments'];
            $message['attachments'] = self::publicAttachments(
                (int)($message['id'] ?? 0),
                $attachments,
                (string)$media['channel'],
                (string)$media['direction']
            );
            if ($attachments && self::isSyntheticAttachmentPreview($message, $attachments)) {
                $message['text'] = '';
            }
        }
        unset($message);
        return $messages;
    }

    public static function publicAttachments(int $messageId, array $attachments, string $channel = '', string $direction = ''): array
    {
        foreach ($attachments as $index=>&$attachment) {
            $provider=(string)($attachment['provider'] ?? '');
            $type=(string)($attachment['type'] ?? 'file');
            $protectedTelegram=$provider==='telegram';
            // Historical MAX rows predate provider tagging. Channel+direction is
            // authoritative and lets those already-recorded photos be recovered.
            $protectedMaxMedia=$channel==='max' && $direction==='inbound' && in_array($type,['image','video','audio','file'],true);
            if (!$protectedTelegram && !$protectedMaxMedia) continue;
            // Never expose provider tokens/file identifiers or expiring provider
            // URLs in the browser. The authenticated endpoint resolves/archives it.
            $attachment = [
                'type'=>$type,
                'name'=>(string)($attachment['name'] ?? ($type==='image' ? 'Фото' : 'Вложение')),
                'url'=>'media-file.php?message_id='.$messageId.'&attachment='.$index,
            ];
        }
        unset($attachment);
        return $attachments;
    }

    public static function isSyntheticAttachmentPreview(array $message, array $attachments): bool
    {
        if ((string)($message['direction'] ?? '') !== 'outbound') return false;
        if ((string)($message['sender_type'] ?? '') !== 'manager') return false;
        $text = trim((string)($message['text'] ?? ''));
        if ($text === '') return false;
        return hash_equals(ConversationRecorder::attachmentPreview($attachments), $text);
    }
}
