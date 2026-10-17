<?php
declare(strict_types=1);

/** Credentials for the dedicated archive bot never enter the checkout or a DTO. */
final class TelegramArchiveBotConfig
{
    public const USERNAME = 'Anytourtg_bot';
    public const WEBHOOK_URL = 'https://app.anytoour.ru/telegram_archive_webhook.php';
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? (defined('MAX_SEARCH_TELEGRAM_ARCHIVE_PRIVATE_DIR')
            ? (string)MAX_SEARCH_TELEGRAM_ARCHIVE_PRIVATE_DIR : dirname(__DIR__, 2).'/max-search-bot-telegram-archive');
        $root = realpath(dirname(__DIR__));
        $document = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($this->directory === '' || $this->directory[0] !== '/' || str_contains($this->directory, "\0")
            || preg_match('~/(?:\.|\.\.)(?:/|$)~', $this->directory)) throw new RuntimeException('private_config_unavailable');
        foreach ([$root, $document] as $public) {
            if ($public !== false && ($this->directory === $public || str_starts_with($this->directory, $public.'/'))) throw new RuntimeException('private_config_unavailable');
        }
    }

    public static function token(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/\A[1-9][0-9]{4,19}:[A-Za-z0-9_-]{20,200}\z/', $value)) throw new RuntimeException('invalid_bot_token');
        return $value;
    }

    private function directory(bool $create): bool
    {
        clearstatcache();
        if (!file_exists($this->directory) && !is_link($this->directory)) {
            if (!$create) return false;
            if (!@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new RuntimeException('private_config_unavailable');
        }
        $stat = @lstat($this->directory);
        if (!$stat || is_link($this->directory) || realpath($this->directory) !== $this->directory
            || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0777) !== 0700
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) throw new RuntimeException('private_config_unavailable');
        return true;
    }

    private function read(string $name): ?array
    {
        if (!$this->directory(false)) return null;
        $path = $this->directory.'/'.$name;
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) return null;
        $stream = @fopen($path, 'rb');
        if (!$stream) throw new RuntimeException('private_config_unavailable');
        try {
            $stat = fstat($stream); $pathStat = lstat($path);
            if (!$stat || !$pathStat || ($pathStat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600
                || $stat['ino'] !== $pathStat['ino'] || $stat['dev'] !== $pathStat['dev'] || realpath($path) !== $path
                || $stat['size'] > 16384 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) throw new RuntimeException('private_config_unavailable');
            $raw = stream_get_contents($stream, 16385);
            if (!is_string($raw) || strlen($raw) > 16384) throw new RuntimeException('private_config_unavailable');
            $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($value)) throw new RuntimeException('private_config_unavailable');
            return $value;
        } finally { fclose($stream); }
    }

    private function write(string $name, array $value): void
    {
        $this->directory(true);
        // Verify an existing target before an authorized atomic replacement.
        $this->read($name);
        $path = $this->directory.'/'.$name;
        $temporary = $this->directory.'/.config-'.bin2hex(random_bytes(16));
        $stream = @fopen($temporary, 'x+b');
        if (!$stream) throw new RuntimeException('private_config_unavailable');
        try {
            $bytes = json_encode($value, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (!@chmod($temporary, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)
                || (function_exists('fsync') && !fsync($stream))) throw new RuntimeException('private_config_unavailable');
            fclose($stream); $stream = null;
            if (!@rename($temporary, $path)) throw new RuntimeException('private_config_unavailable');
        } finally { if (is_resource($stream)) fclose($stream); if (is_file($temporary)) unlink($temporary); }
    }

    public function load(): ?array
    {
        $value = $this->read('bot.json');
        if ($value === null) return null;
        if (($value['username'] ?? '') !== self::USERNAME || !is_string($value['token'] ?? null)
            || self::token($value['token']) !== $value['token'] || !preg_match('/\A[1-9][0-9]{0,19}\z/', (string)($value['bot_id'] ?? ''))
            || !preg_match('/\A[a-f0-9]{64}\z/', (string)($value['secret'] ?? ''))
            || !in_array($value['webhook_state'] ?? '', ['prepared','ready','unknown'], true)
            || !is_bool($value['can_connect_to_business'] ?? null)) throw new RuntimeException('private_config_unavailable');
        return $value;
    }

    public function save(array $value): void { $this->write('bot.json', $value); }

    /** Serialize setup and secret rotation, including the provider write claim. */
    public function locked(callable $operation)
    {
        $this->directory(true);
        $path = $this->directory.'/setup.lock';
        if (is_link($path)) throw new RuntimeException('private_config_unavailable');
        $lock = @fopen($path, 'c+b');
        if (!$lock) throw new RuntimeException('private_config_unavailable');
        try {
            $stat = fstat($lock); $named = lstat($path);
            if (!$stat || !$named || ($named['mode'] & 0170000) !== 0100000 || $stat['ino'] !== $named['ino']
                || $stat['dev'] !== $named['dev'] || !@chmod($path, 0600) || !flock($lock, LOCK_EX)) throw new RuntimeException('private_config_unavailable');
            return $operation($this->load());
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** The durable marker is written before setWebhook; UNKNOWN cannot retry. */
    public function claimSetup(): bool
    {
        if ($this->read('setup-claim.json') !== null) return false;
        $this->write('setup-claim.json', ['claimed_at'=>gmdate('c'), 'operation'=>'set_archive_webhook']);
        return true;
    }

    public function authenticatedEvent(string $secret): void
    {
        $current = $this->load();
        if (!$current || !hash_equals($current['secret'], $secret) || $current['webhook_state'] === 'ready') return;
        $this->locked(function (?array $value) use ($secret): void {
            if (!$value || !hash_equals($value['secret'], $secret)) return;
            $value['webhook_state'] = 'ready'; $value['checked_at'] = gmdate('c'); $this->save($value);
        });
    }

    public function publicStatus(): array
    {
        $value = $this->load();
        return ['username'=>self::USERNAME, 'configured'=>$value !== null,
            'business_mode_enabled'=>$value['can_connect_to_business'] ?? false,
            'webhook_ready'=>($value['webhook_state'] ?? '') === 'ready',
            'needs_readback'=>($value['webhook_state'] ?? '') === 'unknown'];
    }
}
