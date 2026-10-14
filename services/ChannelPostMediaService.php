<?php
declare(strict_types=1);

require_once __DIR__.'/ChannelPostRegistryService.php';
require_once __DIR__.'/MaxTlsConfig.php';
require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/../integrations/MaxInboundMediaDownloadAdapter.php';

/** Private original-post images. A recorded entry always opens its immutable version. */
final class ChannelPostMediaService
{
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const MAX_CACHE_FILES = 512;
    public const MAX_CACHE_BYTES = 128 * 1024 * 1024;
    private ChannelPostRegistryService $registry;
    private $mediaFetcher;
    private $messageFetcher;
    private $telegramFileFetcher;
    private $resolver;
    private $identity;

    /** Injectable read-only transports keep fixtures independent from real providers. */
    public function __construct(?ChannelPostRegistryService $registry = null, ?callable $mediaFetcher = null, ?callable $messageFetcher = null, ?callable $telegramFileFetcher = null, ?callable $resolver = null, ?callable $identity = null)
    {
        $this->registry = $registry ?? new ChannelPostRegistryService();
        $this->mediaFetcher = $mediaFetcher;
        $this->messageFetcher = $messageFetcher ?? [MaxInboundMediaDownloadAdapter::class, 'fetchMessage'];
        $this->telegramFileFetcher = $telegramFileFetcher;
        $this->resolver = $resolver ?? [self::class, 'resolveHost'];
        $this->identity = $identity;
    }

    /** Caller first rechecks ManagerConversationService::visibleConversation(). */
    public function openEntry(int $conversationId, string $projectKey, int $entryId, int $attachment = 0): ?array
    {
        if ($conversationId <= 0 || $entryId <= 0 || $attachment !== 0) return null;
        $version = $this->registry->versionForEntry($conversationId, $projectKey, $entryId);
        return $version === null ? null : $this->openVersion($version, $attachment);
    }

    /** Best effort after response flush; no database, transcript or provider writes. */
    public function archiveVersion(array $version): bool
    {
        try {
            $file = $this->openVersion($version, 0);
            if ($file === null) return false;
            fclose($file['stream']);
            return true;
        } catch (Throwable $ignored) { return false; }
    }

    private function openVersion(array $version, int $index): ?array
    {
        if ($index !== 0 || (int)($version['version_id'] ?? 0) <= 0 || !$this->runtimeMatches($version)) return null;
        $attachment = $version['snapshot']['attachments'][0] ?? null;
        if (!is_array($attachment) || ($attachment['type'] ?? '') !== 'image' || ($attachment['provider'] ?? '') !== ($version['platform'] ?? '')) return null;
        $key = hash('sha256', 'channel-post-image|'.($version['project_key'] ?? '').'|'.$version['version_id'].'|'.$index);
        $dir = self::cacheDir();
        if ($dir === '') return null;
        $path = $dir.'/'.$key.'.bin';
        $cached = self::openLocal($path);
        if ($cached !== null) return $cached;
        if (is_link($path) || file_exists($path)) return null;

        if ($version['platform'] === 'max') {
            // Prefer the saved signed URL. A fresh MID may now contain a different photo.
            $file = $this->downloadImage((string)($attachment['url'] ?? ''), $path);
            if ($file !== null) return $file;
            $photoId = (string)($attachment['photo_id'] ?? '');
            $mid = (string)($version['external_message_id'] ?? $version['message_id'] ?? '');
            if (!preg_match('/\A[0-9]{1,100}\z/D', $photoId) || !preg_match('/\A[A-Za-z0-9_.-]{1,200}\z/D', $mid)) return null;
            $remote = ($this->messageFetcher)($mid);
            if (!is_array($remote)) return null;
            $remote = is_array($remote['message'] ?? null) ? $remote['message'] : $remote;
            if (($remote['body']['mid'] ?? '') !== $mid || (string)($remote['recipient']['chat_id'] ?? '') !== (string)($version['channel_id'] ?? '') || ($remote['recipient']['chat_type'] ?? '') !== 'channel') return null;
            foreach ((array)($remote['body']['attachments'] ?? []) as $candidate) {
                if (!is_array($candidate) || ($candidate['type'] ?? '') !== 'image') continue;
                $payload = $candidate['payload'] ?? [];
                if (!is_array($payload) || !hash_equals($photoId, (string)($payload['photo_id'] ?? ''))) continue;
                return $this->downloadImage((string)($payload['url'] ?? ''), $path);
            }
            return null;
        }
        if ($version['platform'] !== 'telegram') return null;
        $fileId = (string)($attachment['telegram_file_id'] ?? '');
        if (!preg_match('/\A[A-Za-z0-9_-]{1,1024}\z/D', $fileId)) return null;
        $file = $this->telegramFile($fileId);
        if (!is_array($file) || (int)($file['file_size'] ?? 0) > self::MAX_IMAGE_BYTES) return null;
        $remotePath = (string)($file['file_path'] ?? '');
        if (!preg_match('~\A[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)+\z~D', $remotePath) || str_contains($remotePath, '..')) return null;
        $token = defined('TELEGRAM_BOT_TOKEN') ? trim((string)TELEGRAM_BOT_TOKEN) : '';
        if ($token === '') return null;
        return $this->downloadImage('https://api.telegram.org/file/bot'.$token.'/'.$remotePath, $path);
    }

    private function runtimeMatches(array $version): bool
    {
        $platform = (string)($version['platform'] ?? '');
        if (!in_array($platform, ['max', 'telegram'], true)) return false;
        if ($this->identity !== null) $identity = ($this->identity)($platform);
        else {
            $file = __DIR__.'/ChannelPostRuntime.php';
            if (!is_file($file)) return false;
            require_once $file;
            $identity = ChannelPostRuntime::identity($platform);
        }
        return is_array($identity)
            && (string)($identity['project_key'] ?? '') !== ''
            && ($identity['project_key'] ?? '') === ($version['project_key'] ?? '')
            && ($identity['platform'] ?? '') === $platform
            && (string)($identity['bot_key'] ?? '') !== ''
            && ($identity['bot_key'] ?? '') === ($version['bot_key'] ?? '');
    }

    private function downloadImage(string $url, string $path): ?array
    {
        $stream = $this->fetch($url, self::MAX_IMAGE_BYTES);
        if (!is_resource($stream)) return null;
        try {
            $descriptor = self::imageDescriptor($stream);
            if ($descriptor === null || !self::store($stream, $path, $descriptor['size'])) return null;
        } finally { fclose($stream); }
        return self::openLocal($path);
    }

    private function telegramFile(string $fileId): ?array
    {
        if ($this->telegramFileFetcher !== null) return ($this->telegramFileFetcher)($fileId);
        $token = defined('TELEGRAM_BOT_TOKEN') ? trim((string)TELEGRAM_BOT_TOKEN) : '';
        if ($token === '') return null;
        // getFile is a read-only GET; the immutable saved file_id is never replaced.
        $stream = $this->fetch('https://api.telegram.org/bot'.$token.'/getFile?file_id='.rawurlencode($fileId), 65536);
        if (!is_resource($stream)) return null;
        try { $data = json_decode((string)stream_get_contents($stream, 65537), true, 16); }
        finally { fclose($stream); }
        return is_array($data) && ($data['ok'] ?? false) === true && is_array($data['result'] ?? null) ? $data['result'] : null;
    }

    /** HTTPS only, every DNS answer public, pinned DNS, no redirects or ambient proxy. */
    private function fetch(string $url, int $limit)
    {
        $target = self::target($url);
        if ($target === null) return null;
        $addresses = ($this->resolver)($target['host']);
        if (!is_array($addresses) || !$addresses || count($addresses) > 16) return null;
        foreach ($addresses as $address) if (!is_string($address) || !self::publicIp($address)) return null;
        $ip = $addresses[0];
        if ($this->mediaFetcher !== null) return ($this->mediaFetcher)($url, $limit, ['host'=>$target['host'], 'pinned_ip'=>$ip, 'strict_tls'=>true, 'redirects'=>false, 'timeout'=>6]);
        if (!extension_loaded('curl')) return null;
        $pinned = $target['host'].':443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip);
        $options = MaxTlsConfig::strictCurlOptions() + [
            CURLOPT_HTTPGET=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>6, CURLOPT_PROXY=>'', CURLOPT_RESOLVE=>[$pinned],
        ];
        $stream = tmpfile();
        if ($stream === false) return null;
        $bytes = 0;
        $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use ($stream, $limit, &$bytes): int {
            $bytes += strlen($chunk);
            if ($bytes > $limit) return 0;
            return (int)fwrite($stream, $chunk);
        };
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_errno($ch);
        curl_close($ch);
        if ($ok === false || $error !== 0 || $status !== 200 || $bytes <= 0 || $bytes > $limit) { fclose($stream); return null; }
        rewind($stream);
        return $stream;
    }

    private static function target(string $url): ?array
    {
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return null;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) return null;
        $host = strtolower((string)($parts['host'] ?? ''));
        if (strlen($host) > 253 || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', $host) || preg_match('/\.(?:localhost|local|internal|test|invalid)\z/D', $host)) return null;
        return ['host'=>$host];
    }

    private static function publicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        if (!str_contains($ip, ':')) {
            $n = ip2long($ip);
            foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4]] as [$network, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($n & $mask) === (ip2long($network) & $mask)) return false;
            }
        }
        // Reject transition/mapped addresses rather than risk hidden private IPv4.
        if (str_contains($ip, ':')) {
            $hex = bin2hex(inet_pton($ip));
            if (!in_array($hex[0], ['2', '3'], true) || str_starts_with($hex, '2002')
                || in_array(substr($hex, 0, 8), ['20010000', '20010002', '20010db8'], true)
                || in_array(substr($hex, 0, 7), ['2001001', '2001002', '3fff000'], true)) return false;
        }
        return true;
    }

    private static function resolveHost(string $host): array
    {
        $answers = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach (is_array($answers) ? $answers : [] as $answer) {
            $ip = $answer['ip'] ?? $answer['ipv6'] ?? '';
            if (is_string($ip) && $ip !== '') $addresses[] = $ip;
        }
        return array_values(array_unique($addresses));
    }

    private static function imageDescriptor($stream): ?array
    {
        if (!is_resource($stream)) return null;
        $size = (int)(fstat($stream)['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_IMAGE_BYTES) return null;
        rewind($stream);
        $bytes = stream_get_contents($stream, self::MAX_IMAGE_BYTES + 1);
        rewind($stream);
        if (!is_string($bytes) || strlen($bytes) !== $size) return null;
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
        $image = @getimagesizefromstring($bytes);
        if (!is_array($image) || ($image['mime'] ?? '') !== $mime || $image[0] <= 0 || $image[1] <= 0 || $image[0] > 16000 || $image[1] > 16000 || $image[0] * $image[1] > 40000000) return null;
        return ['mime'=>$mime, 'size'=>$size];
    }

    private static function openLocal(string $path): ?array
    {
        if (is_link($path) || !is_file($path)) return null;
        if (!@chmod($path, 0600)) return null;
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) return null;
        $descriptor = self::imageDescriptor($stream);
        if ($descriptor === null) { fclose($stream); return null; }
        return $descriptor + ['stream'=>$stream, 'inline'=>true, 'name'=>'post-image'];
    }

    private static function store($stream, string $path, int $size): bool
    {
        $dir = dirname($path);
        $lockPath = $dir.'/.lock';
        if (is_link($lockPath)) return false;
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock)) return false;
        if (!@chmod($lockPath, 0600)) { fclose($lock); return false; }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return false; }
        $tmp = '';
        try {
            if (is_link($path)) return false;
            if (is_file($path)) return true;
            $count = 0; $bytes = 0;
            foreach (new DirectoryIterator($dir) as $file) {
                if (!$file->isFile() || $file->getFilename() === '.lock') continue;
                $count++; $bytes += $file->getSize();
                if ($count >= self::MAX_CACHE_FILES || $bytes + $size > self::MAX_CACHE_BYTES) return false;
            }
            $tmp = $path.'.tmp.'.bin2hex(random_bytes(8));
            $out = @fopen($tmp, 'xb');
            if (!is_resource($out)) return false;
            if (!@chmod($tmp, 0600)) { fclose($out); return false; }
            try { rewind($stream); $written = stream_copy_to_stream($stream, $out, self::MAX_IMAGE_BYTES + 1); }
            finally { fclose($out); }
            if ($written !== $size || !@rename($tmp, $path)) return false;
            $tmp = '';
            if (!@chmod($path, 0600)) { @unlink($path); return false; }
            return true;
        } finally {
            if ($tmp !== '') @unlink($tmp);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    /** Reject configured and symlinked paths inside either application or web root. */
    private static function cacheDir(): string
    {
        $dir = trim((string)(getenv('MAX_SEARCH_CHANNEL_POST_MEDIA_DIR') ?: sys_get_temp_dir().'/max-search-bot-channel-post-images'));
        if ($dir === '' || $dir[0] !== '/' || str_contains($dir, "\0")) return '';
        $parts = [];
        foreach (explode('/', $dir) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') array_pop($parts); else $parts[] = $part;
        }
        $dir = '/'.implode('/', $parts);
        $documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $roots = [realpath(dirname(__DIR__)) ?: dirname(__DIR__), $documentRoot !== '' ? (realpath($documentRoot) ?: $documentRoot) : ''];
        foreach ($roots as $root) if ($root !== '' && ($dir === $root || str_starts_with($dir, rtrim($root, '/').'/'))) return '';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return '';
        $resolved = realpath($dir);
        if ($resolved === false) return '';
        foreach ($roots as $root) if ($root !== '' && ($resolved === $root || str_starts_with($resolved, rtrim($root, '/').'/'))) return '';
        if (!@chmod($resolved, 0700)) return '';
        return $resolved;
    }
}
