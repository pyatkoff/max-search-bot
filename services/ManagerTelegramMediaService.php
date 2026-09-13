<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationService.php';
require_once __DIR__.'/ProjectConfig.php';

/** Saved Telegram file references are resolved only after conversation authorization. */
final class ManagerTelegramMediaService
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public static function attachment(int $messageId, int $index, int $managerId): ?array
    {
        if ($messageId <= 0 || $index < 0 || $index > 20 || $managerId <= 0) return null;
        $q = ConversationDb::connection()->prepare('SELECT conversation_id,channel,direction,metadata_json FROM messages WHERE id=? LIMIT 1');
        $q->execute([$messageId]);
        $row = $q->fetch();
        if (!$row || $row['channel'] !== 'telegram' || $row['direction'] !== 'inbound') return null;
        $conversation = ManagerConversationService::visibleConversation((int)$row['conversation_id'], $managerId);
        if (!$conversation || (string)$conversation['project_key'] !== ProjectConfig::projectId()) return null;
        $meta = json_decode((string)$row['metadata_json'], true);
        $items = array_values(array_filter((array)($meta['attachments'] ?? []), static fn($a)=>is_array($a) && in_array((string)($a['type'] ?? ''), ['image','video','audio','file'], true)));
        $a = $items[$index] ?? null;
        return is_array($a) && ($a['provider'] ?? '') === 'telegram' && !empty($a['telegram_file_id']) ? $a : null;
    }

    /** Returns a private temporary stream; the caller must close it after serving. */
    public static function open(array $attachment, ?callable $fetch = null): ?array
    {
        $token = defined('TELEGRAM_BOT_TOKEN') ? trim((string)TELEGRAM_BOT_TOKEN) : '';
        $fileId = (string)($attachment['telegram_file_id'] ?? '');
        if ($token === '' || $fileId === '' || strlen($fileId) > 1024) return null;
        if ((int)($attachment['size'] ?? 0) > self::MAX_BYTES) throw new RuntimeException('telegram_media_too_large', 413);
        $fetch = $fetch ?? [self::class, 'request'];
        $info = $fetch('https://api.telegram.org/bot'.$token.'/getFile', ['file_id'=>$fileId], 65536);
        if (!is_resource($info)) return null;
        try { $data = json_decode((string)stream_get_contents($info, 65537), true); } finally { fclose($info); }
        $file = $data['result'] ?? [];
        $path = (string)($file['file_path'] ?? '');
        if (empty($data['ok']) || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)+$~D', $path) || str_contains($path, '..')) return null;
        if ((int)($file['file_size'] ?? 0) > self::MAX_BYTES) throw new RuntimeException('telegram_media_too_large', 413);
        $stream = $fetch('https://api.telegram.org/file/bot'.$token.'/'.$path, null, self::MAX_BYTES);
        if (!is_resource($stream)) return null;
        $size = (int)(fstat($stream)['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) { fclose($stream); return null; }
        $probe = (string)fread($stream, 8192);
        rewind($stream);
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($probe);
        // Never serve SVG, HTML, XML or a claimed MIME as executable same-origin content.
        $inline = in_array($mime, ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm','audio/mpeg','audio/ogg','audio/mp4','audio/wav'], true);
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]+/u', '_', basename((string)($attachment['name'] ?? 'Вложение'))) ?: 'Вложение';
        return ['stream'=>$stream, 'size'=>$size, 'mime'=>$inline ? $mime : 'application/octet-stream', 'inline'=>$inline, 'name'=>mb_substr($name, 0, 180)];
    }

    private static function request(string $url, ?array $post, int $limit)
    {
        $stream = tmpfile();
        if ($stream === false) return null;
        $ch = curl_init($url);
        $bytes = 0;
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>30,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use ($stream, $limit, &$bytes): int {
                $bytes += strlen($chunk);
                if ($bytes > $limit) return 0;
                return (int)fwrite($stream, $chunk);
            },
        ]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok === false || $errno !== 0 || $status !== 200) { fclose($stream); return null; }
        rewind($stream);
        return $stream;
    }
}
