<?php

declare(strict_types=1);

final class ManagerSelectionLinks
{
    /** URLs are server-only capability data, never provider or log fields. */
    public static function inMessage(string $text, int $messageId, string $sender, string $sentAt): array
    {
        if (!in_array($sender, ['customer','manager'], true)) return [];
        preg_match_all('~https?://[^\s<>"\x27]+~iu', $text, $matches);
        $out = [];
        foreach ($matches[0] as $raw) {
            $raw = rtrim($raw, '.,;!?)}]»');
            if (!preg_match('~\Ahttps?://(?:www\.)?qui-quo\.ru(?:[/:?#]|\z)~i', $raw)) continue;
            $out[] = ['message_id'=>$messageId, 'sender_type'=>$sender, 'sent_at'=>$sentAt,
                'url'=>self::canonical($raw)];
            if (count($out) === 41) break;
        }
        return $out;
    }

    public static function canonical(string $url): ?string
    {
        if (strlen($url) > 256 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return null;
        $p = parse_url($url);
        if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['https','http'], true)
            || !in_array(strtolower($p['host'] ?? ''), ['qui-quo.ru','www.qui-quo.ru'], true)
            || isset($p['user']) || isset($p['pass']) || isset($p['port'])
            || isset($p['query']) || isset($p['fragment'])
            || !preg_match('~\A/[a-z0-9]{4}-[a-z0-9]{4}\z~iD', $p['path'] ?? '')) return null;
        return 'https://qui-quo.ru'.$p['path'];
    }
}
