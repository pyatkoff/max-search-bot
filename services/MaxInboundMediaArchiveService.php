<?php
declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/../integrations/MaxInboundMediaDownloadAdapter.php';

/**
 * Durable private archive for inbound MAX photos.
 *
 * The webhook may carry only a provider token. In that case the saved MAX `mid`
 * is resolved through the official message endpoint and the photo bytes are
 * copied into private local runtime storage. Provider tokens/URLs stay server-side.
 */
final class MaxInboundMediaArchiveService
{
    public const MAX_MEDIA_BYTES = 50 * 1024 * 1024;

    /** Best-effort post-response archive for a newly recorded inbound MAX message. */
    public static function archiveRecordedMessage(string $externalMessageId, ?callable $messageFetcher = null, ?callable $mediaFetcher = null): int
    {
        $externalMessageId = trim($externalMessageId);
        if ($externalMessageId === '' || !ConversationDb::isConfigured()) return 0;

        $q = ConversationDb::connection()->prepare("SELECT id,metadata_json FROM messages WHERE channel='max' AND direction='inbound' AND external_message_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$externalMessageId]);
        $row = $q->fetch();
        if (!$row) return 0;

        $meta = json_decode((string)($row['metadata_json'] ?? ''), true);
        if (!is_array($meta) || !is_array($meta['attachments'] ?? null)) return 0;
        $attachments = $meta['attachments'];
        $changed = 0;

        foreach ($attachments as $key => $attachment) {
            if (!is_array($attachment) || !in_array((string)($attachment['type'] ?? ''), ['image','video','audio','file'], true)) continue;
            if (self::localDescriptorAvailable((array)($attachment['local_media'] ?? []))) continue;
            $index = self::publicIndexForKey($attachments, $key);
            if ($index < 0) continue;
            $local = self::archiveMedia((int)$row['id'], $index, $externalMessageId, $attachment, $messageFetcher, $mediaFetcher);
            if ($local === null) continue;
            $attachments[$key]['local_media'] = $local;
            $changed++;
        }

        if ($changed > 0) {
            $meta['attachments'] = $attachments;
            self::updateMetadata((int)$row['id'], $meta);
        }
        return $changed;
    }

    /**
     * Resolve/download/store one MAX photo. Injectable fetchers keep the contract
     * testable without touching MAX or customer data.
     */
    public static function archiveMedia(int $messageId, int $index, string $externalMessageId, array $attachment, ?callable $messageFetcher = null, ?callable $mediaFetcher = null): ?array
    {
        if ($messageId <= 0 || $index < 0 || $index > 20 || !in_array((string)($attachment['type'] ?? ''), ['image','video','audio','file'], true)) return null;
        $known = (array)($attachment['local_media'] ?? []);
        if (self::localDescriptorAvailable($known)) return $known;

        $urls = self::urls($attachment);
        $remote = null;
        if (!$urls) {
            $fetchMessage = $messageFetcher ?? [self::class, 'fetchMessage'];
            $remoteMessage = $fetchMessage($externalMessageId);
            if (is_array($remoteMessage)) {
                $remote = self::matchingRemoteAttachment($remoteMessage, $index, $attachment);
                if (is_array($remote)) $urls = self::urls($remote);
            }
        }
        if (!$urls && (string)($attachment['type'] ?? '') === 'video') {
            $token=trim((string)($attachment['token'] ?? ''));
            if($token!==''){
                $video=MaxInboundMediaDownloadAdapter::fetchVideo($token);
                if(is_array($video)) $urls=self::urls($video);
            }
        }
        if (!$urls) return null;

        $fetchMedia = $mediaFetcher ?? [self::class, 'fetchMedia'];
        foreach ($urls as $url) {
            $stream = $fetchMedia($url, self::MAX_MEDIA_BYTES);
            if (!is_resource($stream)) continue;
            try {
                $descriptor = self::storeStream($messageId, $index, $attachment + (is_array($remote) ? $remote : []), $stream);
                if ($descriptor !== null) return $descriptor;
            } finally {
                fclose($stream);
            }
        }

        // A webhook URL can expire. Re-resolve the message once and retry a fresh
        // provider URL before giving up, unless we already did that above.
        if ($remote === null) {
            $fetchMessage = $messageFetcher ?? [self::class, 'fetchMessage'];
            $remoteMessage = $fetchMessage($externalMessageId);
            if (is_array($remoteMessage)) {
                $remote = self::matchingRemoteAttachment($remoteMessage, $index, $attachment);
                foreach (is_array($remote) ? self::urls($remote) : [] as $url) {
                    if (in_array($url, $urls, true)) continue;
                    $stream = $fetchMedia($url, self::MAX_MEDIA_BYTES);
                    if (!is_resource($stream)) continue;
                    try {
                        $descriptor = self::storeStream($messageId, $index, $attachment + $remote, $stream);
                        if ($descriptor !== null) return $descriptor;
                    } finally {
                        fclose($stream);
                    }
                }
            }
        }
        return null;
    }

    /** Load an authorized message attachment from local storage, recovering it from MAX once when absent. */
    public static function openMessageAttachment(int $messageId, int $index, ?callable $messageFetcher = null, ?callable $mediaFetcher = null): ?array
    {
        if ($messageId <= 0 || $index < 0 || $index > 20 || !ConversationDb::isConfigured()) return null;
        $q = ConversationDb::connection()->prepare("SELECT channel,direction,external_message_id,metadata_json FROM messages WHERE id=? LIMIT 1");
        $q->execute([$messageId]);
        $row = $q->fetch();
        if (!$row || (string)$row['channel'] !== 'max' || (string)$row['direction'] !== 'inbound') return null;
        $meta = json_decode((string)($row['metadata_json'] ?? ''), true);
        if (!is_array($meta) || !is_array($meta['attachments'] ?? null)) return null;
        $keys = self::publicAttachmentKeys($meta['attachments']);
        $key = $keys[$index] ?? null;
        if ($key === null || !is_array($meta['attachments'][$key] ?? null)) return null;
        $attachment = $meta['attachments'][$key];
        if (!in_array((string)($attachment['type'] ?? ''), ['image','video','audio','file'], true)) return null;

        $local = (array)($attachment['local_media'] ?? []);
        $file = self::openLocal($local);
        if ($file !== null) return $file;

        $local = self::archiveMedia($messageId, $index, (string)($row['external_message_id'] ?? ''), $attachment, $messageFetcher, $mediaFetcher);
        if ($local === null) return null;
        $meta['attachments'][$key]['local_media'] = $local;
        self::updateMetadata($messageId, $meta);
        return self::openLocal($local);
    }

    /** Returns a private stream; caller must close it. */
    public static function openLocal(array $descriptor): ?array
    {
        $id = (string)($descriptor['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
        $path = self::dir().'/'.$id.'.bin';
        if (!is_file($path) || !is_readable($path)) return null;
        $size = (int)filesize($path);
        if ($size <= 0 || $size > self::MAX_MEDIA_BYTES || $size !== (int)($descriptor['size'] ?? $size)) return null;
        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) return null;
        $probe = (string)fread($stream, 8192);
        rewind($stream);
        $mime = self::mediaMime($probe, (string)($descriptor['type'] ?? 'image'));
        if ($mime === null) { fclose($stream); return null; }
        $name = self::safeName((string)($descriptor['name'] ?? 'Фото'));
        return ['stream'=>$stream,'size'=>$size,'mime'=>$mime,'inline'=>true,'name'=>$name];
    }

    private static function storeStream(int $messageId, int $index, array $attachment, $stream): ?array
    {
        $stat = fstat($stream);
        $size = (int)($stat['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_MEDIA_BYTES) return null;
        $probe = (string)fread($stream, 8192);
        rewind($stream);
        $mime = self::mediaMime($probe, (string)($attachment['type'] ?? 'file'));
        if ($mime === null) return null;

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
        @chmod($dir, 0700);
        $id = substr(hash('sha256', 'max-inbound-image:'.$messageId.':'.$index), 0, 32);
        $path = $dir.'/'.$id.'.bin';
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(6));
        $out = @fopen($tmp, 'xb');
        if (!is_resource($out)) return null;
        $written = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) return null;
                if ($chunk === '') break;
                $written += strlen($chunk);
                if ($written > self::MAX_MEDIA_BYTES || fwrite($out, $chunk) !== strlen($chunk)) return null;
            }
        } finally {
            fclose($out);
        }
        if ($written !== $size || !@rename($tmp, $path)) { @unlink($tmp); return null; }
        @chmod($path, 0600);
        return ['version'=>1,'id'=>$id,'size'=>$size,'mime'=>$mime,'type'=>(string)($attachment['type'] ?? 'file'),'name'=>self::safeName((string)($attachment['name'] ?? 'Вложение'))];
    }

    private static function updateMetadata(int $messageId, array $meta): void
    {
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        try {
            $q = ConversationDb::connection()->prepare('UPDATE messages SET metadata_json=? WHERE id=?');
            $q->execute([$json, $messageId]);
        } catch (Throwable $ignored) {
            // The original inbound message is more important than media enrichment.
        }
    }

    private static function localDescriptorAvailable(array $descriptor): bool
    {
        $file = self::openLocal($descriptor);
        if ($file === null) return false;
        fclose($file['stream']);
        return true;
    }

    private static function publicIndexForKey(array $attachments, $targetKey): int
    {
        $i = 0;
        foreach ($attachments as $key => $attachment) {
            if (!is_array($attachment) || !in_array((string)($attachment['type'] ?? ''), ['image','video','audio','file'], true)) continue;
            if ($key === $targetKey) return $i;
            $i++;
        }
        return -1;
    }

    private static function publicAttachmentKeys(array $attachments): array
    {
        $keys = [];
        foreach ($attachments as $key => $attachment) {
            if (is_array($attachment) && in_array((string)($attachment['type'] ?? ''), ['image','video','audio','file'], true)) $keys[] = $key;
        }
        return $keys;
    }

    private static function matchingRemoteAttachment(array $message, int $index, array $saved): ?array
    {
        $items = $message['body']['attachments'] ?? $message['message']['body']['attachments'] ?? [];
        $items = array_values(array_filter((array)$items, static fn($a)=>is_array($a) && in_array((string)($a['type'] ?? ''), ['image','video','audio','file'], true)));
        $wantedToken = trim((string)($saved['token'] ?? ''));
        if ($wantedToken !== '') {
            foreach ($items as $item) {
                if ((string)($item['type'] ?? '') !== (string)($saved['type'] ?? '')) continue;
                if (self::containsToken($item, $wantedToken)) return $item;
            }
        }
        $candidate = $items[$index] ?? null;
        return is_array($candidate) && (string)($candidate['type'] ?? '') === (string)($saved['type'] ?? '') ? $candidate : null;
    }

    private static function containsToken($value, string $token): bool
    {
        if (!is_array($value)) return false;
        foreach ($value as $key => $item) {
            if ($key === 'token' && is_scalar($item) && hash_equals($token, trim((string)$item))) return true;
            if (is_array($item) && self::containsToken($item, $token)) return true;
        }
        return false;
    }

    private static function urls(array $value): array
    {
        $urls = [];
        $walk = static function ($node) use (&$walk, &$urls): void {
            if (!is_array($node)) return;
            foreach ($node as $key => $item) {
                if ($key === 'url' && is_scalar($item)) {
                    $url = trim((string)$item);
                    if (self::safeHttpsUrl($url)) $urls[] = $url;
                } elseif (is_array($item)) $walk($item);
            }
        };
        $walk($value);
        return array_values(array_unique($urls));
    }

    private static function safeHttpsUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass']);
    }

    private static function mediaMime(string $probe, string $type): ?string
    {
        if ($probe === '') return null;
        $mime=(string)(new finfo(FILEINFO_MIME_TYPE))->buffer($probe);
        if($type==='image') return str_starts_with($mime,'image/') ? $mime : null;
        if($type==='video') return str_starts_with($mime,'video/') ? $mime : null;
        if($type==='audio') return str_starts_with($mime,'audio/') ? $mime : null;
        if($type==='file') return !in_array($mime,['text/html','application/xhtml+xml'],true) ? $mime : null;
        return null;
    }

    private static function safeName(string $name): string
    {
        $name = trim(basename($name));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]+/u', '_', $name) ?: 'Фото';
        return mb_substr($name, 0, 180);
    }

    private static function dir(): string
    {
        $override = trim((string)(getenv('MAX_SEARCH_INCOMING_MEDIA_DIR') ?: ''));
        return $override !== '' ? rtrim($override, '/') : dirname(__DIR__).'/runtime/incoming-media';
    }

    private static function fetchMessage(string $externalMessageId): ?array
    {
        return MaxInboundMediaDownloadAdapter::fetchMessage($externalMessageId);
    }

    private static function fetchMedia(string $url, int $limit)
    {
        return MaxInboundMediaDownloadAdapter::fetchMedia($url, $limit);
    }

}
