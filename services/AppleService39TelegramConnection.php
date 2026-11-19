<?php
declare(strict_types=1);

require_once __DIR__.'/ProjectClientCredentialStore.php';
require_once __DIR__.'/RoutingAdminService.php';
require_once __DIR__.'/TelegramArchiveBotSetup.php';

/** Only the verified AppleService39 Telegram identity and its own encrypted credentials. */
final class AppleService39TelegramConnection
{
    public const PROJECT = 'appleservice39';
    public const USERNAME = 'Appleservice39_bot';
    public const SOURCE = 'telegram:appleservice39-main';

    public function __construct(
        private PDO $pdo,
        private ProjectClientCredentialStore $vault,
        private $telegramRead = [TelegramArchiveBotSetup::class, 'network']
    ) {}

    public static function current(): self
    {
        $log = DiagnosticLogger::filePath();
        if ($log === '') throw new RuntimeException('storage_unavailable');
        // Keep the existing client vault format and protect this key's first-use
        // lifecycle independently from the existing MAX client registry.
        $vault = new ProjectClientCredentialStore(dirname($log).'/apple-telegram-credentials');
        return new self(ConversationDb::connection(), $vault);
    }

    public function mayConfigure(int $actor, bool $locked = false): bool
    {
        return $actor > 0 && ManagerAuthService::isAdmin(ManagerAuthService::byId($actor, $locked))
            && ProjectAccessService::canAccess($actor, self::PROJECT, $locked);
    }

    private function row(): ?array
    {
        $q = $this->pdo->prepare('SELECT p.id AS project_id,p.project_key,b.bot_id,b.version,b.credentials_json FROM project_telegram_bot_connections b JOIN projects p ON p.id=b.project_id WHERE p.project_key=? AND p.is_active=1');
        $q->execute([self::PROJECT]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['project_key'] !== self::PROJECT) throw new RuntimeException('source_unavailable');
        return $row ?: null;
    }

    public function status(int $actor): array
    {
        if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
        $row = $this->row();
        return ['configured'=>$row !== null, 'version'=>(int)($row['version'] ?? 0)];
    }

    private function source(int $projectId): ?array
    {
        $q = $this->pdo->prepare('SELECT id,project_id,source_key,channel,handling_mode,is_active FROM conversation_sources WHERE project_id=? AND source_key=? LIMIT 1');
        $q->execute([$projectId,self::SOURCE]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ($row['source_key'] !== self::SOURCE || $row['channel'] !== 'telegram'
            || $row['handling_mode'] !== 'manager' || (int)$row['is_active'] !== 1) throw new RuntimeException('source_unavailable');
        return $row;
    }

    private function credentials(array $row): array
    {
        $secret = $this->vault->open(self::PROJECT, 'telegram:'.$row['bot_id'], $row['credentials_json']);
        if (!preg_match('/\A[1-9][0-9]{4,18}\z/D',(string)$row['bot_id'])
            || !str_starts_with($secret['token'], $row['bot_id'].':')
            || !preg_match('/\A[a-f0-9]{64}\z/D',$secret['webhook_secret'])) throw new RuntimeException('storage_unavailable');
        return $secret;
    }

    /** The token and webhook secret are never projected to a manager response. */
    public static function runtimeBinding(): ?array
    {
        try {
            return self::current()->binding();
        } catch (Throwable $unavailable) {
            return null;
        }
    }

    /** Read from the same verified project/source on every transport operation. */
    public function binding(): ?array
    {
        $row = $this->row();
        if (!$row) return null;
        $source = $this->source((int)$row['project_id']);
        if (!$source) return null;
        $secret = $this->credentials($row);
        return ['project_key'=>self::PROJECT, 'bot_id'=>(string)$row['bot_id'], 'bot_username'=>self::USERNAME,
            'bot_token'=>$secret['token'], 'webhook_secret'=>$secret['webhook_secret'],
            'source_key'=>self::SOURCE, 'source_id'=>(int)$source['id']];
    }

    public function save(int $actor, int $expectedVersion, string $token): void
    {
        if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
        if ($expectedVersion < 0 || !preg_match('/\A([1-9][0-9]{4,18}):[A-Za-z0-9_-]{20,200}\z/D',$token,$match))
            throw new RuntimeException('invalid_token');
        $response = ($this->telegramRead)($token, 'getMe', []);
        if (($response['http'] ?? 0) !== 200 || ($response['ok'] ?? false) !== true) throw new RuntimeException('identity_unavailable');
        $me = $response['result'] ?? [];
        if (($me['is_bot'] ?? null) !== true || !is_string($me['username'] ?? null)
            || strcasecmp($me['username'],self::USERNAME) !== 0 || (string)($me['id'] ?? '') !== $match[1])
            throw new RuntimeException('identity_mismatch');

        // Serialize rotations on the project even when there is no connection row yet.
        // The provider request is finished before taking database locks.
        $this->pdo->beginTransaction();
        try {
            if (!$this->mayConfigure($actor,true)) throw new RuntimeException('forbidden');
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : '';
            $q = $this->pdo->prepare('SELECT id,project_key FROM projects WHERE project_key=? AND is_active=1'.$lock);
            $q->execute([self::PROJECT]);
            $project = $q->fetch(PDO::FETCH_ASSOC);
            if (!$project || $project['project_key'] !== self::PROJECT) throw new RuntimeException('source_unavailable');
            $row = $this->row();
            if ((int)($row['version'] ?? 0) !== $expectedVersion) throw new RuntimeException('context_changed');
            if ($row && (string)$row['bot_id'] !== $match[1]) throw new RuntimeException('identity_mismatch');

            // Verify any previously saved envelope before allowing rotation. A lost
            // key or a different bot cannot silently replace the existing binding.
            $this->vault->initialize($row === null);
            $old = $row ? $this->credentials($row) : null;
            $secret = $old['webhook_secret'] ?? bin2hex(random_bytes(32));
            $envelope = $this->vault->seal(self::PROJECT,'telegram:'.$match[1],['token'=>$token,'webhook_secret'=>$secret]);
            $projectId = (int)$project['id'];
            if (!$this->source($projectId)) {
                $source = RoutingAdminService::saveSourceResult($actor,self::PROJECT,0,self::SOURCE,'AppleService39 · Telegram','telegram',0,'none',0,0,'manager',true);
                if (empty($source['ok']) || !$this->source($projectId)) throw new RuntimeException('source_unavailable');
            }
            if ($row) {
                $q = $this->pdo->prepare('UPDATE project_telegram_bot_connections SET credentials_json=?,version=version+1 WHERE project_id=? AND version=? AND bot_id=?');
                $q->execute([$envelope,$projectId,$expectedVersion,$match[1]]);
                if ($q->rowCount() !== 1) throw new RuntimeException('context_changed');
            } else {
                $q = $this->pdo->prepare('INSERT INTO project_telegram_bot_connections(project_id,bot_id,credentials_json,version) VALUES(?,?,?,1)');
                $q->execute([$projectId,$match[1],$envelope]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
