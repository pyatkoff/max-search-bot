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
        $title = trim((string)($context['title'] ?? ''));
        if (function_exists('mb_substr')) $title = mb_substr($title, 0, 255, 'UTF-8');
        else $title = substr($title, 0, 255);

        if ($url === '' && $title === '') return false;

        $sql = 'INSERT INTO website_page_context (chat_id,external_user_id,page_url,page_title) VALUES (?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE external_user_id=VALUES(external_user_id),page_url=VALUES(page_url),page_title=VALUES(page_title),updated_at=NOW()';
        ConversationDb::connection()->prepare($sql)->execute([$chatId,$externalUserId,$url,$title]);
        return true;
    }

    public static function forChat(int $chatId): array
    {
        if ($chatId <= 0) return [];
        try {
            $q = ConversationDb::connection()->prepare('SELECT page_url,page_title,updated_at FROM website_page_context WHERE chat_id=? LIMIT 1');
            $q->execute([$chatId]);
            $row = $q->fetch();
            if (!$row) return [];
            return [
                'page_url'=>(string)$row['page_url'],
                'page_title'=>(string)$row['page_title'],
                'updated_at'=>(string)$row['updated_at'],
            ];
        } catch (Throwable $e) {
            return [];
        }
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
