<?php
require_once __DIR__ . '/ConversationDb.php';

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
            $message['attachments'] = $mediaById[(int)($message['id'] ?? 0)] ?? [];
        }
        unset($message);
        return $messages;
    }
}
