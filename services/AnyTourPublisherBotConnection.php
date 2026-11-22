<?php
declare(strict_types=1);

require_once __DIR__.'/ProjectClientCredentialStore.php';
require_once __DIR__.'/TelegramArchiveBotSetup.php';
require_once __DIR__.'/ManagerAuthService.php';

/** Dedicated, opt-in credentials. No webhook, routing or publication operations. */
final class AnyTourPublisherBotConnection
{
    public const PROJECT = 'anytour';
    public const USERNAME = 'ANYTOURBOT_bot';
    private string $root;
    private ProjectClientCredentialStore $vault;
    private $request;

    public function __construct(?string $root = null, ?callable $request = null)
    {
        $checkout = realpath(dirname(__DIR__));
        if ($checkout === false) throw new RuntimeException('storage_unavailable');
        // Same canonical private-log sibling as the existing vault; deriving the
        // path must not call the logger's directory-creating filePath() on GET.
        $this->root = $root ?? dirname($checkout).'/max-search-bot-private-logs/anytour-publisher-credentials';
        $this->vault = new ProjectClientCredentialStore($this->root);
        $this->request = $request ?? [TelegramArchiveBotSetup::class, 'network'];
    }

    public function mayConfigure(int $actor, bool $locked = false): bool
    {
        if ($actor <= 0) return false;
        ProjectAccessService::initializeReadOnly();
        return ManagerAuthService::isAdmin(ManagerAuthService::byId($actor, $locked))
            && ProjectAccessService::canAccess($actor, self::PROJECT, $locked);
    }

    private function directory(bool $create): bool
    {
        if ($this->root === '' || $this->root[0] !== '/' || str_contains($this->root, "\0"))
            throw new RuntimeException('storage_unavailable');
        $cursor = '';
        foreach (explode('/', $this->root) as $part) {
            if ($part === '.' || $part === '..') throw new RuntimeException('storage_unavailable');
            if ($part === '') continue;
            $cursor .= '/'.$part;
            if (is_link($cursor)) throw new RuntimeException('storage_unavailable');
        }
        $checkout = realpath(dirname(__DIR__));
        $document = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        foreach ([$checkout, $document !== '' ? realpath($document) : false] as $public) {
            if ($public !== false && ($this->root === $public || str_starts_with($this->root, $public.'/')))
                throw new RuntimeException('storage_unavailable');
        }
        clearstatcache(true, $this->root);
        if (!file_exists($this->root) && !is_link($this->root)) {
            if (!$create) return false;
            if (!@mkdir($this->root, 0700, true) && !is_dir($this->root))
                throw new RuntimeException('storage_unavailable');
        }
        $stat = @lstat($this->root);
        if (!$stat || realpath($this->root) !== $this->root || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 0777) !== 0700
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()))
            throw new RuntimeException('storage_unavailable');
        return true;
    }

    /** Reject replaced, public or non-regular files before reading or locking. */
    private function privateFile($stream, string $path): array
    {
        clearstatcache(true, $path);
        $opened = fstat($stream);
        $named = @lstat($path);
        if (!$opened || !$named || ($named['mode'] & 0170000) !== 0100000
            || ($opened['mode'] & 0777) !== 0600 || $opened['ino'] !== $named['ino']
            || $opened['dev'] !== $named['dev'] || realpath($path) !== $path
            || (function_exists('posix_geteuid') && $opened['uid'] !== posix_geteuid()))
            throw new RuntimeException('storage_unavailable');
        return $opened;
    }

    private function locked(callable $operation): void
    {
        $this->directory(true);
        $path = $this->root.'/connection.lock';
        if (is_link($path)) throw new RuntimeException('storage_unavailable');
        $mask = umask(0077);
        try { $stream = @fopen($path, 'c+b'); }
        finally { umask($mask); }
        if (!$stream) throw new RuntimeException('storage_unavailable');
        try {
            $this->privateFile($stream, $path);
            if (!flock($stream, LOCK_EX)) throw new RuntimeException('storage_unavailable');
            $this->privateFile($stream, $path);
            $operation();
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function record(): ?array
    {
        if (!$this->directory(false)) return null;
        $path = $this->root.'/connection.json';
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) return null;
        if (is_link($path)) throw new RuntimeException('storage_unavailable');
        $stream = @fopen($path, 'rb');
        if (!$stream) throw new RuntimeException('storage_unavailable');
        try {
            $stat = $this->privateFile($stream, $path);
            if ($stat['size'] > 20000) throw new RuntimeException('storage_unavailable');
            $raw = stream_get_contents($stream, 20001);
            if (!is_string($raw) || strlen($raw) > 20000) throw new RuntimeException('storage_unavailable');
            $data = json_decode($raw, true);
        } finally { fclose($stream); }
        if (!is_array($data) || ($data['username'] ?? null) !== self::USERNAME
            || !is_int($data['version'] ?? null) || $data['version'] < 1 || $data['version'] > 9999999999999999
            || !is_string($data['bot_id'] ?? null) || !preg_match('/\A[1-9][0-9]{4,18}\z/D', $data['bot_id'])
            || !is_string($data['envelope'] ?? null) || !is_string($data['updated_at'] ?? null)
            || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00\z/D', $data['updated_at']))
            throw new RuntimeException('storage_unavailable');
        $updated = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $data['updated_at']);
        if (!$updated || $updated->format('Y-m-d\TH:i:sP') !== $data['updated_at'])
            throw new RuntimeException('storage_unavailable');
        return $data;
    }

    private function tokenId(#[\SensitiveParameter] string $token): string
    {
        if (!preg_match('/\A([1-9][0-9]{4,18}):[A-Za-z0-9_-]{20,200}\z/D', $token, $match))
            throw new RuntimeException('invalid_token');
        return $match[1];
    }

    private function verifySaved(array $row): void
    {
        $secret = $this->vault->open(self::PROJECT, 'telegram:'.$row['bot_id'], $row['envelope']);
        if ($this->tokenId($secret['token']) !== $row['bot_id'] || $secret['webhook_secret'] !== 'not-used')
            throw new RuntimeException('storage_unavailable');
    }

    private function identity(#[\SensitiveParameter] string $token, string $botId): void
    {
        try { $response = ($this->request)($token, 'getMe', []); }
        catch (Throwable $ignored) { throw new RuntimeException('identity_unavailable'); }
        $me = $response['result'] ?? null;
        if (($response['http'] ?? 0) !== 200 || ($response['ok'] ?? false) !== true || !is_array($me))
            throw new RuntimeException('identity_unavailable');
        if (($me['is_bot'] ?? null) !== true || !is_string($me['username'] ?? null)
            || strcasecmp($me['username'], self::USERNAME) !== 0 || (string)($me['id'] ?? '') !== $botId)
            throw new RuntimeException('identity_mismatch');
    }

    /** The saved identity check is historical; it grants no publication capability. */
    public function status(int $actor): array
    {
        if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
        $row = $this->record();
        if ($row) $this->verifySaved($row);
        return ['configured'=>$row !== null, 'username'=>self::USERNAME, 'bot_id'=>$row['bot_id'] ?? null,
            'version'=>$row['version'] ?? 0, 'updated_at'=>$row['updated_at'] ?? null,
            'identity_verified'=>$row !== null, 'publication_api_ready'=>false, 'webhook_unchanged'=>true];
    }

    private function expected(?array $row, int $version, string $botId): void
    {
        if (($row['version'] ?? 0) !== $version) throw new RuntimeException('context_changed');
        if ($row && $row['bot_id'] !== $botId) throw new RuntimeException('identity_mismatch');
        if ($row) $this->verifySaved($row);
    }

    public function save(int $actor, int $version, #[\SensitiveParameter] string $token): void
    {
        if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
        if ($version < 0 || $version >= 9999999999999999) throw new RuntimeException('context_changed');
        $botId = $this->tokenId($token);
        $this->expected($this->record(), $version, $botId);
        $this->identity($token, $botId);
        if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
        $this->locked(function () use ($actor, $version, $token, $botId): void {
            // Keep the actor/project rows locked through the file replacement.
            // This transaction owns no business writes or provider operation.
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction()) throw new RuntimeException('storage_unavailable');
            $pdo->beginTransaction();
            try {
                if (!$this->mayConfigure($actor, true)) throw new RuntimeException('forbidden');
                $row = $this->record();
                $this->expected($row, $version, $botId);
                $this->vault->initialize($row === null);
                $envelope = $this->vault->seal(self::PROJECT, 'telegram:'.$botId,
                    ['token'=>$token, 'webhook_secret'=>'not-used']);
                $data = json_encode(['username'=>self::USERNAME, 'bot_id'=>$botId, 'version'=>$version + 1,
                    'updated_at'=>gmdate('c'), 'envelope'=>$envelope], JSON_THROW_ON_ERROR);
                $temporary = $this->root.'/connection.'.bin2hex(random_bytes(12)).'.tmp';
                $mask = umask(0077);
                try { $stream = @fopen($temporary, 'x+b'); }
                finally { umask($mask); }
                if (!$stream) throw new RuntimeException('storage_unavailable');
                try {
                    if (!@chmod($temporary, 0600) || fwrite($stream, $data) !== strlen($data) || !fflush($stream)
                        || (function_exists('fsync') && !fsync($stream))) throw new RuntimeException('storage_unavailable');
                    fclose($stream); $stream = null;
                    if (!@rename($temporary, $this->root.'/connection.json')) throw new RuntimeException('storage_unavailable');
                } finally {
                    if (is_resource($stream)) fclose($stream);
                    if (is_file($temporary)) @unlink($temporary);
                }
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
        });
    }

    public function __debugInfo(): array { return ['credentials'=>'[redacted]']; }
}
