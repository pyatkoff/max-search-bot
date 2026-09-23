<?php
require_once __DIR__ . '/../services/IncomingMessage.php';

class MaxIncomingAdapter
{
    public static function fromUpdate(array $update): ?array
    {
        $type = (string)($update['update_type'] ?? '');
        $user = self::user($update);
        $externalUserId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        if (!$externalUserId) return null;
        $internalId = $externalUserId > 0 ? -$externalUserId : $externalUserId;
        $normalizedUser = self::normalizedUser($user);

        if ($type === 'message_created') {
            $messageId = (string)($update['message']['body']['mid'] ?? '');
            $phone = self::contactPhone($update);
            if ($phone !== '') {
                return IncomingMessage::contact('max', $externalUserId, $internalId, $messageId, $phone, $normalizedUser, $update);
            }
            $text = (string)($update['message']['body']['text'] ?? $update['message']['text'] ?? '');
            return IncomingMessage::text(
                'max',
                $externalUserId,
                $internalId,
                $messageId,
                $text,
                $normalizedUser,
                $update,
                self::mediaAttachments($update),
                self::linkedMessage($update)
            );
        }

        if ($type === 'message_callback') {
            return IncomingMessage::callback(
                'max',
                $externalUserId,
                $internalId,
                (string)($update['callback']['callback_id'] ?? ''),
                (string)($update['callback']['payload'] ?? ''),
                $normalizedUser,
                $update
            );
        }

        return null;
    }

    public static function user(array $update): array
    {
        if (!empty($update['callback']['user'])) return (array)$update['callback']['user'];
        if (!empty($update['message']['sender'])) return (array)$update['message']['sender'];
        if (!empty($update['user'])) return (array)$update['user'];
        return [];
    }

    public static function linkedMessage(array $update): array
    {
        $link=$update['message']['link']??null;
        if(!is_array($link)) return [];
        $type=strtolower(trim((string)($link['type']??'')));
        if(!in_array($type,['reply','forward'],true)) return [];
        $mid=trim((string)($link['mid']??$link['message_id']??''));
        $result=['type'=>$type];
        if($mid!=='' && preg_match('/^(?:mid\.)?[A-Za-z0-9_-]+$/D',$mid)) $result['mid']=$mid;
        $sender=is_array($link['sender']??null)?$link['sender']:[];
        $name=trim((string)($sender['name']??''));
        if($name!=='') $result['sender_name']=mb_substr($name,0,160,'UTF-8');
        $text=trim((string)($link['message']['body']['text']??$link['body']['text']??$link['text']??''));
        if($text!=='') $result['text']=mb_substr($text,0,500,'UTF-8');
        $linkedMessage=is_array($link['message']??null)?$link['message']:[];
        $summary=[];
        foreach((array)($linkedMessage['body']['attachments']??$linkedMessage['attachments']??[]) as $a){
            if(!is_array($a))continue;$t=strtolower(trim((string)($a['type']??'')));
            if(!in_array($t,['image','video','audio','file'],true))continue;
            $payload=is_array($a['payload']??null)?$a['payload']:[];
            $name=trim((string)($a['name']??$a['filename']??$payload['name']??$payload['filename']??''));
            $summary[]=['type'=>$t,'name'=>mb_substr($name,0,180,'UTF-8')];if(count($summary)>=4)break;
        }
        if($summary)$result['attachments']=$summary;
        return $result;
    }

    public static function mediaAttachments(array $update): array
    {
        $attachments = $update['message']['body']['attachments'] ?? $update['message']['attachments'] ?? [];
        if(!$attachments){
            $linked=$update['message']['link']['message']??null;
            if(is_array($linked)) $attachments=$linked['body']['attachments']??$linked['attachments']??[];
        }
        $out = [];
        foreach ((array)$attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $type = strtolower(trim((string)($attachment['type'] ?? '')));
            if (!in_array($type, ['image','video','audio','file'], true)) continue;
            $payload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
            $item = ['type'=>$type,'provider'=>'max'];
            foreach (['url','token'] as $key) {
                $value = trim((string)($payload[$key] ?? ''));
                if ($value !== '') $item[$key] = $value;
            }
            foreach (['name','filename'] as $key) {
                $value = trim((string)($attachment[$key] ?? $payload[$key] ?? ''));
                if ($value !== '') { $item['name'] = $value; break; }
            }
            foreach (['mime_type','content_type'] as $key) {
                $value = trim((string)($attachment[$key] ?? $payload[$key] ?? ''));
                if ($value !== '') { $item['mime_type'] = $value; break; }
            }
            $size = (int)($attachment['size'] ?? $payload['size'] ?? 0);
            if ($size > 0) $item['size'] = $size;
            $transcription = trim((string)($attachment['transcription'] ?? $payload['transcription'] ?? ''));
            if ($transcription !== '') $item['transcription'] = $transcription;
            if ($type === 'image' && !empty($payload['photos']) && is_array($payload['photos'])) {
                foreach ($payload['photos'] as $photo) {
                    if (!is_array($photo)) continue;
                    foreach (['url','token'] as $key) {
                        if (!empty($item[$key])) continue;
                        $value = trim((string)($photo[$key] ?? ''));
                        if ($value !== '') $item[$key] = $value;
                    }
                    if (!empty($item['url']) && !empty($item['token'])) break;
                }
            }
            // Keep only usable media. A type-only placeholder cannot be opened or
            // recovered, so surfacing it as successful media would be misleading.
            if (!empty($item['url']) || !empty($item['token'])) $out[] = $item;
        }
        return $out;
    }

    private static function normalizedUser(array $user): array
    {
        $name = trim((string)($user['name'] ?? ''));
        $parts = $name !== '' ? preg_split('/\s+/', $name, 2) : [];
        return [
            'first_name' => (string)($user['first_name'] ?? ($parts[0] ?? '')),
            'last_name' => (string)($user['last_name'] ?? ($parts[1] ?? '')),
            'username' => (string)($user['username'] ?? ''),
        ];
    }

    private static function contactPhone(array $update): string
    {
        $attachments = $update['message']['body']['attachments'] ?? $update['message']['attachments'] ?? [];
        foreach ((array)$attachments as $attachment) {
            if (($attachment['type'] ?? '') !== 'contact') continue;
            $payload = (array)($attachment['payload'] ?? []);
            $vcf = (string)($payload['vcf_info'] ?? '');
            if ($vcf !== '' && preg_match('/TEL[^:]*:([+0-9]+)/i', $vcf, $m)) return trim($m[1]);
            foreach (['phone','phone_number'] as $key) {
                if (!empty($payload[$key])) return trim((string)$payload[$key]);
            }
        }
        return '';
    }
}
