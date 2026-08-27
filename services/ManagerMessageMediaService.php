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
        $sql = 'SELECT id,metadata_json FROM messages WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $q = $pdo->prepare($sql);
        $q->execute($ids);
        $mediaById = [];
        foreach ($q->fetchAll() as $row) {
            $meta = json_decode((string)($row['metadata_json'] ?? ''), true);
            if (!is_array($meta) || empty($meta['attachments']) || !is_array($meta['attachments'])) continue;
            $mediaById[(int)$row['id']] = array_values(array_filter($meta['attachments'], static function ($attachment) {
                return is_array($attachment) && in_array((string)($attachment['type'] ?? ''), ['image','video','audio','file'], true);
            }));
        }
        foreach ($messages as &$message) {
            $attachments = $mediaById[(int)($message['id'] ?? 0)] ?? [];
            $message['attachments'] = $attachments;
            if ($attachments && self::isSyntheticAttachmentPreview($message, $attachments)) {
                $message['text'] = '';
            }
        }
        unset($message);
        return $messages;
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
