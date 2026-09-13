<?php
require_once __DIR__ . '/../services/IncomingMessage.php';

class TelegramIncomingAdapter
{
    public static function fromUpdate(array $update): ?array
    {
        if (!empty($update['callback_query'])) {
            $q = (array)$update['callback_query'];
            $from = (array)($q['from'] ?? []);
            $externalUserId = (int)($from['id'] ?? 0);
            if (!$externalUserId) return null;
            return IncomingMessage::callback(
                'telegram',
                $externalUserId,
                $externalUserId,
                (string)($q['id'] ?? ''),
                (string)($q['data'] ?? ''),
                self::normalizedUser($from),
                $update
            );
        }

        $message = (array)($update['message'] ?? []);
        if (!$message) return null;
        $from = (array)($message['from'] ?? []);
        $externalUserId = (int)($from['id'] ?? 0);
        if (!$externalUserId) return null;
        $messageId = (string)($message['message_id'] ?? '');
        $contact = (array)($message['contact'] ?? []);
        if (!empty($contact['phone_number'])) {
            return IncomingMessage::contact(
                'telegram',
                $externalUserId,
                $externalUserId,
                $messageId,
                (string)$contact['phone_number'],
                self::normalizedUser($from),
                $update
            );
        }
        return IncomingMessage::text(
            'telegram',
            $externalUserId,
            $externalUserId,
            $messageId,
            (string)($message['text'] ?? $message['caption'] ?? ''),
            self::normalizedUser($from),
            $update,
            self::mediaAttachments($message)
        );
    }

    /** Telegram forwards retain media on the message itself, not forward_origin. */
    private static function mediaAttachments(array $message): array
    {
        $photo = null;
        foreach ((array)($message['photo'] ?? []) as $size) {
            if (!is_array($size) || empty($size['file_id'])) continue;
            if ($photo === null || (int)($size['width'] ?? 0) * (int)($size['height'] ?? 0) > (int)($photo['width'] ?? 0) * (int)($photo['height'] ?? 0)) $photo = $size;
        }
        $items = $photo ? [['image', $photo, 'Фото.jpg']] : [];
        // animation also carries document for compatibility; store it only once.
        foreach (['animation'=>'video', 'video'=>'video', 'video_note'=>'video', 'voice'=>'audio', 'audio'=>'audio', 'document'=>'file', 'sticker'=>'file'] as $key=>$type) {
            $file = $message[$key] ?? null;
            if (!is_array($file) || empty($file['file_id'])) continue;
            if ($key === 'sticker' && empty($file['is_animated'])) $type = empty($file['is_video']) ? 'image' : 'video';
            $items[] = [$type, $file, ['video'=>'Видео', 'audio'=>'Аудио', 'image'=>'Стикер.webp', 'file'=>'Файл'][$type]];
            break;
        }
        $attachments = [];
        foreach ($items as [$type, $file, $name]) {
            $attachments[] = [
                'type'=>$type, 'provider'=>'telegram',
                'telegram_file_id'=>(string)$file['file_id'],
                'name'=>(string)($file['file_name'] ?? $name),
                'size'=>max(0, (int)($file['file_size'] ?? 0)),
            ];
        }
        return $attachments;
    }

    private static function normalizedUser(array $from): array
    {
        return [
            'first_name' => (string)($from['first_name'] ?? ''),
            'last_name' => (string)($from['last_name'] ?? ''),
            'username' => (string)($from['username'] ?? ''),
        ];
    }
}
