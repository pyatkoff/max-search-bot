<?php

declare(strict_types=1);

/** Display text only. Delivery, access and event identity remain with the push owner. */
final class ManagerPushPresentation
{
    public const PREVIEW_LENGTH = 180;

    public static function conversation(array $conversation, string $notice, ?array $incoming = null, ?array $context = null): array
    {
        $name = self::line((string)($conversation['display_name'] ?? ''), 80);
        if ($name === '') $name = 'Новый диалог';
        $channel = ['max'=>'MAX', 'telegram'=>'Telegram', 'website'=>'Сайт'][strtolower((string)($conversation['channel'] ?? ''))] ?? '';
        $title = $name . ($channel !== '' ? ' · ' . $channel : '');
        $project = self::line((string)($conversation['project_name'] ?? ''), 40);
        if ($project !== '' && strcasecmp($project, 'AnyTour') !== 0) $title .= ' · ' . $project;

        $preview = $incoming === null ? self::eventPreview($notice,$context) : self::incomingPreview($incoming);
        return [
            'title'=>$title,
            'body'=>$preview !== '' ? $preview : self::line($notice, 500),
            'conversationId'=>(int)($conversation['id'] ?? 0),
            'url'=>'./',
        ];
    }

    private static function eventPreview(string $notice, ?array $context): string
    {
        if (!in_array($notice,['Новая заявка ждёт менеджера','Обращение из поиска','Обращение по веб-подборке'],true)) return '';
        $tour=$context['tour']??null;
        if (is_array($tour)) {
            $hotel=self::line((string)($tour['hotel']??''),80);
            $date=(string)($tour['date']??'');
            if ($hotel!==''&&preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D',$date,$m)&&checkdate((int)$m[2],(int)$m[3],(int)$m[1])
                &&($tour['nights']??0)>0&&($tour['adults']??0)>0&&($tour['children']??-1)>=0) {
                $text=($tour['origin']==='search'?'Из поиска: ':'Из подборки: ').$hotel.' · '.$m[3].'.'.$m[2].'.'.$m[1]
                    .' · '.$tour['nights'].' ноч. · '.$tour['adults'].' взр.'.($tour['children']>0?' + '.$tour['children'].' реб.':'');
                return self::line($text,self::PREVIEW_LENGTH);
            }
        }
        if ($notice==='Новая заявка ждёт менеджера') {
            $message=$context['message']??null;
            $preview=is_array($message)&&!preg_match('/\A\s*\//u',(string)($message['text']??''))?self::incomingPreview($message):'';
            return $preview!==''?self::line('Просит менеджера · Последнее: '.$preview,self::PREVIEW_LENGTH):'Просит подключить менеджера';
        }
        return '';
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
