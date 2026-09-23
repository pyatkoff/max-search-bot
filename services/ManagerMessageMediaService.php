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
            if (!is_array($meta)) continue;
            $linked=self::publicLinkedMessage((array)($meta['linked_message']??[]));
            $attachments=!empty($meta['attachments'])&&is_array($meta['attachments'])?$meta['attachments']:[];
            if(!$attachments && !$linked) continue;
            $mediaById[(int)$row['id']] = [
                'channel'=>(string)($row['channel'] ?? ''),
                'direction'=>(string)($row['direction'] ?? ''),
                'linked_message'=>$linked,
                'attachments'=>array_values(array_filter($attachments, static function ($attachment) {
                    return is_array($attachment) && in_array((string)($attachment['type'] ?? ''), ['image','video','audio','file'], true);
                })),
            ];
        }
        foreach ($messages as &$message) {
            $media = $mediaById[(int)($message['id'] ?? 0)] ?? ['channel'=>'','direction'=>'','attachments'=>[],'linked_message'=>[]];
            if(!empty($media['linked_message'])) $message['linked_message']=$media['linked_message'];
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

    private static function publicLinkedMessage(array $linked): array
    {
        $type=(string)($linked['type']??'');
        if(!in_array($type,['reply','forward'],true)) return [];
        $out=['type'=>$type];
        $mid=trim((string)($linked['mid']??''));
        if($mid!=='' && preg_match('/^(?:mid\.)?[A-Za-z0-9_-]+$/D',$mid)) $out['mid']=$mid;
        $name=trim((string)($linked['sender_name']??''));
        if($name!=='') $out['sender_name']=mb_substr($name,0,160,'UTF-8');
        $text=trim((string)($linked['text']??''));
        if($text!=='') $out['text']=mb_substr($text,0,500,'UTF-8');
        return $out;
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
