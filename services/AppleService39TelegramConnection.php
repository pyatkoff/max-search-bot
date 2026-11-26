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
    public const WEBHOOK_URL = 'https://app.anytoour.ru/appleservice39_telegram_webhook.php';
    public const SOURCE = 'telegram:appleservice39-main';

    public function __construct(
        private PDO $pdo,
        private ProjectClientCredentialStore $vault,
        private $telegram = [TelegramArchiveBotSetup::class, 'network']
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
        $q = $this->pdo->prepare('SELECT p.id AS project_id,p.project_key,b.bot_id,b.version,b.webhook_state,b.webhook_started_at,b.credentials_json FROM project_telegram_bot_connections b JOIN projects p ON p.id=b.project_id WHERE p.project_key=? AND p.is_active=1');
        $q->execute([self::PROJECT]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['project_key'] !== self::PROJECT) throw new RuntimeException('source_unavailable');
        return $row ?: null;
    }

    public function status(int $actor): array
    {
        if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
        $row = $this->row();
        $state = (string)($row['webhook_state'] ?? 'not_set');
        return ['configured'=>$row !== null, 'version'=>(int)($row['version'] ?? 0),
            'webhook_state'=>$state, 'received'=>$state === 'verified' && $this->received($row)];
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

    /** The conversion reader needs the verified identity and active source, never credentials. */
    public static function forecastBinding(PDO $pdo,?callable $read=null): ?array
    {
        $read??=static function(string $sql,array $args)use($pdo):array{
            $q=$pdo->prepare($sql);
            $q->execute($args);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        };
        try{
            $rows=$read("SELECT p.project_key,b.bot_id,s.id AS source_id,s.source_key,s.channel
                FROM project_telegram_bot_connections b JOIN projects p ON p.id=b.project_id
                JOIN conversation_sources s ON s.project_id=p.id
                WHERE p.project_key=? AND p.is_active=1 AND b.webhook_state='verified'
                  AND s.channel='telegram' AND s.source_key=? AND s.is_active=1 AND s.handling_mode='manager' LIMIT 2",
                [self::PROJECT,self::SOURCE]);
        }catch(PDOException $unavailable){
            return null;
        }
        if(count($rows)!==1)return null;
        $row=$rows[0];
        if($row['project_key']!==self::PROJECT||$row['source_key']!==self::SOURCE||$row['channel']!=='telegram'
            ||!preg_match('/\A[1-9][0-9]{0,15}\z/D',(string)$row['bot_id'])||(int)$row['source_id']<=0)return null;
        return ['project_key'=>self::PROJECT,'bot_id'=>(string)$row['bot_id'],'source_key'=>self::SOURCE,'source_id'=>(int)$row['source_id']];
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

    private function verifyIdentity(string $token, string $botId): void
    {
        $response = ($this->telegram)($token, 'getMe', []);
        if (($response['http'] ?? 0) !== 200 || ($response['ok'] ?? false) !== true) throw new RuntimeException('identity_unavailable');
        $me = $response['result'] ?? [];
        if (($me['is_bot'] ?? null) !== true || !is_string($me['username'] ?? null)
            || strcasecmp($me['username'],self::USERNAME) !== 0 || (string)($me['id'] ?? '') !== $botId)
            throw new RuntimeException('identity_mismatch');
    }

    /** Only report whether the dedicated bot both persisted an entry and delivered its reply. */
    private function received(array $row): bool
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $bot = $mysql ? "JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.bot_id'))" : "json_extract(e.payload_json,'$.bot_id')";
        $state = $mysql ? "JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.state'))" : "json_extract(e.payload_json,'$.state')";
        $q = $this->pdo->prepare("SELECT 1 FROM conversation_sources s JOIN conversations c ON c.source_id=s.id
            JOIN conversation_events e ON e.conversation_id=c.id WHERE s.project_id=? AND s.source_key=? AND s.is_active=1
            AND s.channel='telegram' AND c.project_key=? AND c.channel='telegram' AND e.event_type='repair_telegram_reply'
            AND $bot=? AND $state='delivered' LIMIT 1");
        $q->execute([(int)$row['project_id'],self::SOURCE,self::PROJECT,(string)$row['bot_id']]);
        return (bool)$q->fetchColumn();
    }

    /** Telegram never returns the shared secret in getWebhookInfo. */
    private function hookInfo(string $token): array
    {
        $reply = ($this->telegram)($token,'getWebhookInfo',[]);
        $info = $reply['result'] ?? null;
        if (($reply['http'] ?? 0) !== 200 || ($reply['ok'] ?? false) !== true || !is_array($info)
            || !is_string($info['url'] ?? null)) throw new RuntimeException('webhook_unavailable');
        if (!in_array($info['url'],['',self::WEBHOOK_URL],true) || ($info['has_custom_certificate'] ?? false) !== false)
            throw new RuntimeException('webhook_conflict');
        $types = $info['allowed_updates'] ?? [];
        $max = $info['max_connections'] ?? 40;
        if (!is_array($types) || !array_is_list($types) || !is_int($max) || $max < 1 || $max > 100)
            throw new RuntimeException('webhook_unavailable');
        foreach ($types as $type) if (!is_string($type) || !preg_match('/\A[a-z_]{1,64}\z/D',$type))
            throw new RuntimeException('webhook_unavailable');
        sort($types);
        return ['url'=>$info['url'],'types'=>$types,'max'=>$max];
    }

    private function ready(array $info): bool
    {
        return $info['url'] === self::WEBHOOK_URL && ($info['types'] === [] || in_array('message',$info['types'],true));
    }

    /** Compare-and-swap each transition, including the claim before the external mutation. */
    private function transition(array &$row, string $state): void
    {
        $q = $this->pdo->prepare('UPDATE project_telegram_bot_connections SET webhook_state=?,webhook_started_at=?,version=version+1
            WHERE project_id=? AND bot_id=? AND version=? AND webhook_state=?');
        $now = time();
        $q->execute([$state,$now,$row['project_id'],$row['bot_id'],$row['version'],$row['webhook_state']]);
        if ($q->rowCount() !== 1) throw new RuntimeException('context_changed');
        $row['version'] = (int)$row['version'] + 1;
        $row['webhook_state'] = $state;
        $row['webhook_started_at'] = $now;
    }

    /** An explicit owner action. A pending claim outlives the bounded provider request. */
    public function activate(int $actor, int $expectedVersion): void
    {
        if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
        $row = $this->row();
        if (!$row || (int)$row['version'] !== $expectedVersion) throw new RuntimeException('context_changed');
        if ($row['webhook_state'] === 'verified') {
            $this->requireReadyForPublication($actor);
            return;
        }
        if ($row['webhook_state'] === 'pending' && (int)$row['webhook_started_at'] > time()-120)
            throw new RuntimeException('webhook_busy');
        if (!$this->source((int)$row['project_id'])) throw new RuntimeException('source_unavailable');
        $secret = $this->credentials($row);
        $token = $secret['token'];
        $this->verifyIdentity($token,(string)$row['bot_id']);
        $before = $this->hookInfo($token);
        if ($row['webhook_state'] === 'accepted' && $this->ready($before)) {
            if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
            $this->transition($row,'verified');
            return;
        }
        // A previous ambiguous attempt can be repeated only through a new explicit
        // POST. Same identity, URL and stable secret; existing pending updates are kept.
        if ($this->hookInfo($token) !== $before) throw new RuntimeException('context_changed');
        $this->pdo->beginTransaction();
        try {
            if (!$this->mayConfigure($actor,true)) throw new RuntimeException('forbidden');
            if (!$this->source((int)$row['project_id'])) throw new RuntimeException('source_unavailable');
            $this->transition($row,'pending');
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        // Existing registrations on this same Apple URL retain their subscriptions
        // and connection limit. An empty registration needs private messages only.
        $types = $before['url'] === '' ? ['message'] : $before['types'];
        if ($types !== [] && !in_array('message',$types,true)) $types[] = 'message';
        try {
            $answer = ($this->telegram)($token,'setWebhook',['url'=>self::WEBHOOK_URL,
                'secret_token'=>$secret['webhook_secret'],'allowed_updates'=>$types,'max_connections'=>$before['max'],
                'drop_pending_updates'=>false]);
        } catch (Throwable $error) {
            $answer = [];
        }
        if (($answer['http'] ?? 0) !== 200 || ($answer['ok'] ?? false) !== true || ($answer['result'] ?? null) !== true) {
            $this->transition($row,'unknown');
            throw new RuntimeException('webhook_unknown');
        }
        $this->transition($row,'accepted');
        $after = $this->hookInfo($token);
        if (!$this->ready($after) || ($types !== [] && array_diff($types,$after['types'])) || $after['max'] !== $before['max'])
            throw new RuntimeException('webhook_unavailable');
        $this->transition($row,'verified');
    }

    /** Publishing is an explicit owner action; check for provider-side drift again. */
    public function requireReadyForPublication(int $actor): void
    {
        if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
        $row = $this->row();
        if (!$row || $row['webhook_state'] !== 'verified' || !$this->source((int)$row['project_id']))
            throw new RuntimeException('connection_required');
        $secret = $this->credentials($row);
        try {
            $ready = $this->ready($this->hookInfo($secret['token']));
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'webhook_conflict') $this->transition($row,'not_set');
            throw $error;
        }
        if (!$ready) {
            $this->transition($row,'not_set');
            throw new RuntimeException('connection_required');
        }
    }

    public function save(int $actor, int $expectedVersion, string $token): void
    {
        if (!$this->mayConfigure($actor)) throw new RuntimeException('forbidden');
        if ($expectedVersion < 0 || !preg_match('/\A([1-9][0-9]{4,18}):[A-Za-z0-9_-]{20,200}\z/D',$token,$match))
            throw new RuntimeException('invalid_token');
        $this->verifyIdentity($token,$match[1]);

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
            if ($row && $row['webhook_state'] === 'pending' && (int)$row['webhook_started_at'] > time()-120)
                throw new RuntimeException('webhook_busy');
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
