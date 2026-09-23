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
            self::mediaAttachments($message),
            self::linkedMessage($message)
        );
    }

    private static function linkedMessage(array $message): array
    {
        $reply=is_array($message['reply_to_message']??null)?$message['reply_to_message']:null;
        if($reply){
            $out=['type'=>'reply'];
            $mid=trim((string)($reply['message_id']??''));
            if($mid!=='') $out['mid']=$mid;
            $from=is_array($reply['from']??null)?$reply['from']:[];
            $name=trim(implode(' ',array_filter([(string)($from['first_name']??''),(string)($from['last_name']??'')])));
            if($name!=='') $out['sender_name']=mb_substr($name,0,160,'UTF-8');
            $text=trim((string)($reply['text']??$reply['caption']??''));
            if($text!=='') $out['text']=mb_substr($text,0,500,'UTF-8');
            $summary=self::attachmentSummary($reply);if($summary)$out['attachments']=$summary;
            return $out;
        }
        $origin=is_array($message['forward_origin']??null)?$message['forward_origin']:null;
        if(!$origin) return [];
        $out=['type'=>'forward'];
        $name='';
        if(is_array($origin['sender_user']??null)){
            $u=$origin['sender_user'];$name=trim(implode(' ',array_filter([(string)($u['first_name']??''),(string)($u['last_name']??'')])));
        } elseif(!empty($origin['sender_user_name'])) $name=trim((string)$origin['sender_user_name']);
        elseif(!empty($origin['chat']['title'])) $name=trim((string)$origin['chat']['title']);
        if($name!=='')$out['sender_name']=mb_substr($name,0,160,'UTF-8');
        return $out;
    }

    private static function attachmentSummary(array $message): array
    {
        $items=self::mediaAttachments($message);$out=[];
        foreach($items as $item){
            if(!is_array($item))continue;
            $type=(string)($item['type']??'file');if(!in_array($type,['image','video','audio','file'],true))continue;
            $out[]=['type'=>$type,'name'=>mb_substr(trim((string)($item['name']??'')),0,180,'UTF-8')];
            if(count($out)>=4)break;
        }
        return $out;
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
