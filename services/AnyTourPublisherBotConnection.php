<?php
declare(strict_types=1);

require_once __DIR__.'/ProjectClientCredentialStore.php';
require_once __DIR__.'/TelegramArchiveBotSetup.php';
require_once __DIR__.'/ManagerAuthService.php';
require_once __DIR__.'/../integrations/ChannelPostApiTransport.php';

/** Dedicated credentials and a scoped transport lease; no webhook or routing changes. */
final class AnyTourPublisherBotConnection
{
    public const PROJECT = 'anytour';
    public const USERNAME = 'ANYTOURBOT_bot';
    private const CHANNEL = '-1001845835645';
    private const MESSAGE = '1678';
    private string $root;
    private ProjectClientCredentialStore $vault;
    private $request;
    private $publisherRequest;
    private $publisherKeyboardPilotRequest;

    public function __construct(?string $root = null, ?callable $request = null, ?callable $publisherRequest = null,
        ?callable $publisherKeyboardPilotRequest = null)
    {
        $checkout = realpath(dirname(__DIR__));
        if ($checkout === false) throw new RuntimeException('storage_unavailable');
        // Same canonical private-log sibling as the existing vault; deriving the
        // path must not call the logger's directory-creating filePath() on GET.
        $this->root = $root ?? dirname($checkout).'/max-search-bot-private-logs/anytour-publisher-credentials';
        $this->vault = new ProjectClientCredentialStore($this->root);
        $this->request = $request ?? [TelegramArchiveBotSetup::class, 'network'];
        $this->publisherRequest = $publisherRequest ?? [ChannelPostApiTransport::class, 'publisherButtonRequest'];
        $this->publisherKeyboardPilotRequest = $publisherKeyboardPilotRequest
            ?? [ChannelPostApiTransport::class, 'publisherKeyboardPilotRequest'];
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

    /** Historical identity and installed scoped API, never a message-edit permission check. */
    public function status(int $actor): array
    {
        if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
        $row = $this->record();
        if ($row) $this->verifySaved($row);
        return ['configured'=>$row !== null, 'username'=>self::USERNAME, 'bot_id'=>$row['bot_id'] ?? null,
            'version'=>$row['version'] ?? 0, 'updated_at'=>$row['updated_at'] ?? null,
            'identity_verified'=>$row !== null, 'publication_api_ready'=>$row !== null, 'webhook_unchanged'=>true];
    }

    private function expected(?array $row, int $version, string $botId): void
    {
        if (($row['version'] ?? 0) !== $version) throw new RuntimeException('context_changed');
        if ($row && $row['bot_id'] !== $botId) throw new RuntimeException('identity_mismatch');
        if ($row) $this->verifySaved($row);
    }

    private function publisherContext(int $actor, int $version, ?string $botId = null): array
    {
        // A caller-owned transaction may contain an old ACL snapshot. Do not
        // commit, roll back or perform a provider operation inside that context.
        if (ConversationDb::connection()->inTransaction()) throw new RuntimeException('storage_unavailable');
        if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
        $row = $this->record();
        if ($row === null) throw new RuntimeException('publisher_not_configured');
        if ($version < 1 || $version !== $row['version']) throw new RuntimeException('context_changed');
        $this->expected($row, $version, $botId ?? $row['bot_id']);
        return $row;
    }

    private static function publisherId($value, string $expected): bool
    {
        return (is_string($value) || is_int($value)) && (string)$value === $expected;
    }

    /** Exact capability boundary, independent of the caller's publication plan. */
    private static function publisherWrite(string $platform, string $method, string $path, array $query, ?array $body, string $botId): bool
    {
        if ($platform !== 'telegram' || $method !== 'POST' || $query !== [] || $body === null)
            throw new RuntimeException('publisher_operation_forbidden');
        if ($path === 'getMe' && $body === []) return false;
        if ($path === 'getChat' && count($body) === 1 && self::publisherId($body['chat_id'] ?? null, self::CHANNEL)) return false;
        if ($path === 'getChatMember' && count($body) === 2
            && self::publisherId($body['chat_id'] ?? null, self::CHANNEL)
            && self::publisherId($body['user_id'] ?? null, $botId)) return false;
        if ($path !== 'editMessageReplyMarkup' || count($body) !== 3
            || !self::publisherId($body['chat_id'] ?? null, self::CHANNEL)
            || !self::publisherId($body['message_id'] ?? null, self::MESSAGE))
            throw new RuntimeException('publisher_operation_forbidden');
        $markup = $body['reply_markup'] ?? null;
        $keyboard = is_array($markup) ? ($markup['inline_keyboard'] ?? null) : null;
        $row = is_array($keyboard) ? ($keyboard[0] ?? null) : null;
        $button = is_array($row) ? ($row[0] ?? null) : null;
        if (!is_array($markup) || count($markup) !== 1 || !is_array($keyboard)
            || array_keys($keyboard) !== [0] || !is_array($row) || array_keys($row) !== [0]
            || !is_array($button) || count($button) !== 2
            || !is_string($button['text'] ?? null) || trim($button['text']) === ''
            || preg_match('/\A[^\x00-\x1f\x7f]{1,80}\z/uD', $button['text']) !== 1
            || !is_string($button['url'] ?? null)
            || preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{24}\z~D', $button['url']) !== 1)
            throw new RuntimeException('publisher_operation_forbidden');
        return true;
    }

    private static function publisherPublicValue($value, #[\SensitiveParameter] string $secret, int $depth = 0): bool
    {
        if ($depth > 32) return false;
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ((is_string($key) && str_contains($key, $secret)) || !self::publisherPublicValue($item, $secret, $depth + 1)) return false;
            }
            return true;
        }
        if (is_string($value)) return !str_contains($value, $secret);
        return $value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value));
    }

    /**
     * Lend only a bounded request capability to trusted application code. The
     * existing file lock serializes the entire operation with credential rotation.
     * Approval, a durable write claim and result reconciliation belong to the
     * existing publication owner, never to credential storage.
     */
    public function withPublisher(int $actor, int $version, callable $operation, bool $allowWrite = false): array
    {
        return $this->publisherLease($actor, $version, $operation, $allowWrite,
            static fn(string $platform, string $method, string $path, array $query, ?array $body, string $botId): bool
                => self::publisherWrite($platform, $method, $path, $query, $body, $botId), $this->publisherRequest);
    }

    /**
     * One exact keyboard request for 1047, separate from the unchanged 1678 API.
     * The existing HOTEL caller owns the approved preimage, durable once-only
     * marker and native readback. This lease never changes registry or routing.
     */
    public function withPublisherKeyboardPilot(int $actor, int $version, array $exactBody, callable $operation,
        bool $allowWrite = false): array
    {
        if (ChannelPostApiTransport::publisherKeyboardPilotPayload($exactBody) === null)
            throw new RuntimeException('publisher_operation_forbidden');
        // PHP array copies can retain references supplied by a caller. Freeze
        // those values before any callback can change the admitted request.
        $exactBody = json_decode(json_encode($exactBody, JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR);
        $check = static function (string $platform, string $method, string $path, array $query, ?array $body,
            string $botId) use ($exactBody): bool {
            if ($path === 'editMessageReplyMarkup') {
                if ($platform !== 'telegram' || $method !== 'POST' || $query !== [] || $body !== $exactBody)
                    throw new RuntimeException('publisher_operation_forbidden');
                return true;
            }
            // All other admitted operations are the existing bounded reads.
            return self::publisherWrite($platform, $method, $path, $query, $body, $botId);
        };
        return $this->publisherLease($actor, $version, $operation, $allowWrite, $check,
            $this->publisherKeyboardPilotRequest);
    }

    private function publisherLease(int $actor, int $version, callable $operation, bool $allowWrite,
        callable $check, callable $transport): array
    {
        $initial = $this->publisherContext($actor, $version);
        $result = null;
        $this->locked(function () use ($actor, $version, $operation, $allowWrite, $check, $transport, $initial, &$result): void {
            $row = $this->publisherContext($actor, $version, $initial['bot_id']);
            $secret = $this->vault->open(self::PROJECT, 'telegram:'.$row['bot_id'], $row['envelope']);
            $token = $secret['token'];
            unset($secret);
            $binding = ['username'=>self::USERNAME, 'bot_id'=>$row['bot_id'], 'credential_version'=>$version];
            $active = true;
            $writeAttempted = false;
            $identity = false;
            $rights = false;
            $channel = false;
            $request = function (string $platform, string $method, string $path, array $query, ?array $body) use (
                $actor, $version, $binding, $allowWrite, $check, $transport, &$active, &$token, &$writeAttempted,
                &$identity, &$rights, &$channel
            ): array {
                if (!$active) throw new RuntimeException('publisher_scope_closed');
                $this->publisherContext($actor, $version, $binding['bot_id']);
                $write = $check($platform, $method, $path, $query, $body, $binding['bot_id']);
                if ($write && (!$allowWrite || $writeAttempted)) throw new RuntimeException('publisher_operation_forbidden');
                if ($write && (!$identity || !$rights || !$channel)) throw new RuntimeException('publisher_verification_required');
                if ($write) $writeAttempted = true; // An uncertain attempt must never be repeated in this lease.
                try { $reply = $transport($token, $method, $path, $query, $body); }
                catch (Throwable $ignored) { $reply = null; }
                if (!is_array($reply) || !self::publisherPublicValue($reply, $token)) $reply = [];
                $response = ['http'=>(int)($reply['http'] ?? 0),
                    'data'=>is_array($reply['data'] ?? null) ? $reply['data'] : null,
                    'transport_error'=>($reply['transport_error'] ?? ($reply === [])) === true];
                $native = !$response['transport_error'] && $response['http'] === 200
                    && ($response['data']['ok'] ?? null) === true && is_array($response['data']['result'] ?? null)
                    ? $response['data']['result'] : [];
                if ($path === 'getMe') {
                    $identity = ($native['is_bot'] ?? null) === true
                        && self::publisherId($native['id'] ?? null, $binding['bot_id'])
                        && is_string($native['username'] ?? null) && strcasecmp($native['username'], self::USERNAME) === 0;
                    $rights = $channel = false;
                } elseif ($path === 'getChatMember') {
                    $rights = $identity && self::publisherId($native['user']['id'] ?? null, $binding['bot_id'])
                        && is_string($native['user']['username'] ?? null) && strcasecmp($native['user']['username'], self::USERNAME) === 0
                        && (($native['status'] ?? '') === 'creator'
                            || (($native['status'] ?? '') === 'administrator' && ($native['can_edit_messages'] ?? null) === true));
                } elseif ($path === 'getChat') {
                    $channel = self::publisherId($native['id'] ?? null, self::CHANNEL) && ($native['type'] ?? null) === 'channel'
                        && is_string($native['username'] ?? null) && strcasecmp($native['username'], 'anytour') === 0;
                }
                return $response;
            };
            try {
                $result = $operation($binding, $request);
                $this->publisherContext($actor, $version, $binding['bot_id']);
                if (!is_array($result) || !self::publisherPublicValue($result, $token)) throw new RuntimeException('publisher_invalid_result');
            } finally {
                $active = false;
                $token = '';
                unset($request);
            }
        });
        return $result;
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
