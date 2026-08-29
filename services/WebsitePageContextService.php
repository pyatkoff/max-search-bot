<?php
require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/WebsiteOriginPolicy.php';

class WebsitePageContextService
{
    public static function save(string $externalUserId, int $chatId, array $context): bool
    {
        $externalUserId = trim($externalUserId);
        if ($externalUserId === '' || $chatId <= 0) return false;

        $url = self::sanitizeUrl((string)($context['url'] ?? ''));
        $title = self::cleanString($context['title'] ?? '', 255);
        $structured = self::sanitizeStructured($context['structured'] ?? []);
        $contextJson = $structured ? json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        if ($url === '' && $title === '' && !$structured) return false;

        $sql = 'INSERT INTO website_page_context (chat_id,external_user_id,page_url,page_title,context_json) VALUES (?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE external_user_id=VALUES(external_user_id),page_url=VALUES(page_url),page_title=VALUES(page_title),context_json=VALUES(context_json),updated_at=NOW()';
        ConversationDb::connection()->prepare($sql)->execute([$chatId,$externalUserId,$url,$title,$contextJson]);
        return true;
    }

    public static function forChat(int $chatId): array
    {
        if ($chatId <= 0) return [];
        try {
            $q = ConversationDb::connection()->prepare('SELECT page_url,page_title,context_json,updated_at FROM website_page_context WHERE chat_id=? LIMIT 1');
            $q->execute([$chatId]);
            $row = $q->fetch();
            if (!$row) return [];
            $result = [
                'page_url'=>(string)$row['page_url'],
                'page_title'=>(string)$row['page_title'],
                'updated_at'=>(string)$row['updated_at'],
            ];
            $structured = json_decode((string)($row['context_json'] ?? ''), true);
            if (is_array($structured) && $structured) $result['structured'] = $structured;
            return $result;
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function sanitizeStructured($value): array
    {
        if (!is_array($value)) return [];
        $out = [];
        $stringFields = [
            'entity_type'=>80,'hotel_name'=>220,'tour_name'=>220,'destination'=>120,'country'=>120,
            'resort'=>120,'currency'=>12,'meal'=>80,'departure_date'=>40,'return_date'=>40,
            'operator'=>120,'room'=>160,
        ];
        foreach ($stringFields as $key=>$max) {
            $clean = self::cleanString($value[$key] ?? '', $max);
            if ($clean !== '') $out[$key] = $clean;
        }
        foreach (['price'=>1000000000,'stars'=>5,'nights'=>60] as $key=>$max) {
            if (!isset($value[$key]) || !is_numeric($value[$key])) continue;
            $number = (float)$value[$key];
            if ($number < 0 || $number > $max) continue;
            $out[$key] = $number;
        }
        return $out;
    }

    private static function cleanString($value, int $max): string
    {
        if (!is_scalar($value)) return '';
        $value = trim((string)$value);
        if ($value === '') return '';
        if (function_exists('mb_substr')) return mb_substr($value, 0, $max, 'UTF-8');
        return substr($value, 0, $max);
    }

    private static function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 4096) return '';
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http','https'], true)) return '';

        $origin = $scheme . '://' . strtolower((string)$parts['host']);
        if (isset($parts['port'])) $origin .= ':' . (int)$parts['port'];
        if (!in_array($origin, WebsiteOriginPolicy::configuredOrigins(), true)) return '';

        $path = isset($parts['path']) ? (string)$parts['path'] : '/';
        if ($path === '') $path = '/';
        $safe = $origin . $path;

        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
            foreach (array_keys($query) as $key) {
                $lower = strtolower((string)$key);
                if ($lower === 'yclid' || $lower === 'gclid' || $lower === 'rb_clickid' || strpos($lower, 'utm_') === 0 || strpos($lower, '_ym_') === 0) {
                    unset($query[$key]);
                }
            }
            if ($query) $safe .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if (strlen($safe) > 2048) $safe = substr($safe, 0, 2048);
        return $safe;
    }
}
