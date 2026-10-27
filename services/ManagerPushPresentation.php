<?php

declare(strict_types=1);

/** Display text only. Delivery, access and event identity remain with the push owner. */
final class ManagerPushPresentation
{
    public const PREVIEW_LENGTH = 180;

    public static function conversation(array $conversation, string $notice, ?array $incoming = null): array
    {
        $name = self::line((string)($conversation['display_name'] ?? ''), 80);
        if ($name === '') $name = 'Новый диалог';
        $channel = ['max'=>'MAX', 'telegram'=>'Telegram', 'website'=>'Сайт'][strtolower((string)($conversation['channel'] ?? ''))] ?? '';
        $title = $name . ($channel !== '' ? ' · ' . $channel : '');
        $project = self::line((string)($conversation['project_name'] ?? ''), 40);
        if ($project !== '' && strcasecmp($project, 'AnyTour') !== 0) $title .= ' · ' . $project;

        $preview = $incoming === null ? '' : self::incomingPreview($incoming);
        return [
            'title'=>$title,
            'body'=>$preview !== '' ? $preview : self::line($notice, 500),
            'conversationId'=>(int)($conversation['id'] ?? 0),
            'url'=>'./',
        ];
    }

    private static function incomingPreview(array $incoming): string
    {
        $raw = (string)($incoming['metadata_json'] ?? '');
        $metadata = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($metadata)) return '';
        $type = $metadata['type'] ?? 'message';
        if ($type === 'contact') return 'Отправил контакт';
        if ($type !== 'message') return '';

        $text = self::line((string)($incoming['text'] ?? ''), self::PREVIEW_LENGTH);
        if ($text !== '') return $text;
        $labels = ['image'=>'Фото', 'video'=>'Видео', 'audio'=>'Аудиосообщение', 'file'=>'Файл'];
        $attachments = is_array($metadata['attachments'] ?? null) ? $metadata['attachments'] : [];
        $names = [];
        foreach (array_slice($attachments, 0, 10) as $attachment) {
            if (!is_array($attachment)) continue;
            $kind = is_string($attachment['type'] ?? null) ? $attachment['type'] : '';
            $names[] = $labels[$kind] ?? 'Вложение';
        }
        return $names ? '📎 ' . implode(', ', array_unique($names)) : '';
    }

    private static function line(string $text, int $limit): string
    {
        $text = trim(preg_replace('/[\p{Z}\p{Cc}\s]+/u', ' ', $text) ?? '');
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit - 1, 'UTF-8') . '…' : $text;
    }
}
