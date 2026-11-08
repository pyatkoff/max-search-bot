<?php
declare(strict_types=1);

require_once __DIR__.'/../services/ProjectMaxConnection.php';
require_once __DIR__.'/../services/ConversationDb.php';
require_once __DIR__.'/../services/MaxTransport.php';
require_once __DIR__.'/MaxWebhookHealth.php';
require_once __DIR__.'/MaxWebhookReconciler.php';

/** Explicit commissioning for one registered repair bot; importing has no effects. */
final class ProjectMaxWebhookSetup
{
    public const PROJECT = 'appleservice39';
    public const BOT = 'id390521129062_bot';
    public const SOURCE = 'max:appleservice39-main';
    public const EXPECTED_URL = 'https://app.anytoour.ru/appleservice39_webhook.php';
    public const LEGACY_URL = 'https://tour-max.ru/new/appleservice39/webhook.php';
    public const UPDATE_TYPES = ['bot_started','message_created'];
    private const PROBE = ['update_type'=>'project_connection_probe'];
    private ProjectMaxConnection $connection;
    private $provider;
    private $probe;
    private $directory;
    private $beforeMutation;
    private $registrationConfirmed;
    private array $receipt = ['request_count'=>0,'write_attempts'=>0,'register_attempts'=>0,'remove_legacy_attempts'=>0,'probe_count'=>0,'directory_checks'=>0,'registration_confirmed'=>false];

    private function __construct(ProjectMaxConnection $connection, ?callable $provider, ?callable $probe, ?callable $directory, ?callable $beforeMutation, ?callable $registrationConfirmed)
    {
        $this->connection = $connection;
        $this->provider = $provider ?? static fn(ProjectMaxConnection $owner, string $method, string $path, array $query, ?array $body): array => MaxTransport::webhookRequest($owner->token(), $method, $path, $query, $body);
        $this->probe = $probe ?? [self::class,'probeEndpoint'];
        $this->directory = $directory ?? [self::class,'activeDirectory'];
        $this->beforeMutation = $beforeMutation;
        $this->registrationConfirmed = $registrationConfirmed;
    }

    /** Provider GETs and an authenticated ignored endpoint probe; no business writes. */
    public static function verify(ProjectMaxConnection $connection, ?callable $provider = null, ?callable $probe = null, ?callable $directory = null, ?callable $registrationConfirmed = null): array
    {
        return (new self($connection,$provider,$probe,$directory,null,$registrationConfirmed))->run(false);
    }

    /**
     * beforeMutation(operation, fixedUrl) must durably claim that exact operation
     * under the existing setup lock and recheck config/provenance. Throw to deny.
     * The wrapper owns this claim across processes; this service never retries it.
     */
    public static function activate(ProjectMaxConnection $connection, ?callable $provider = null, ?callable $probe = null, ?callable $directory = null, ?callable $beforeMutation = null, ?callable $registrationConfirmed = null): array
    {
        return (new self($connection,$provider,$probe,$directory,$beforeMutation,$registrationConfirmed))->run(true);
    }

    private function run(bool $activate): array
    {
        try {
            self::check($this->connection->projectKey() === self::PROJECT && $this->connection->botKey() === self::BOT
                && !ProjectMaxConnection::isLegacyProject(self::PROJECT)
                && $this->connection->settings()->get('business.kind') === 'device_repair'
                && $this->connection->settings()->get('routing.source_key') === self::SOURCE, 'connection_scope_mismatch');
            self::check(preg_match('/\A[A-Za-z0-9_-]{32,256}\z/D', $this->connection->webhookSecret()) === 1, 'webhook_secret_invalid');
            if ($activate) self::check($this->beforeMutation !== null, 'mutation_admission_required');
            if ($this->registrationConfirmed !== null) {
                try { $this->receipt['registration_confirmed'] = ($this->registrationConfirmed)($this->connection) === true; }
                catch (Throwable $ignored) { throw new ProjectMaxWebhookSetupFailure('registration_receipt_unavailable'); }
            }

            $me = $this->read('/me');
            $nativeId = $me['user_id'] ?? null;
            self::check(($me['is_bot'] ?? null) === true && ($me['username'] ?? null) === self::BOT
                && (is_int($nativeId) || is_string($nativeId)) && preg_match('/\A[1-9][0-9]{0,19}\z/D', (string)$nativeId) === 1, 'bot_identity_mismatch');
            $before = $this->subscriptions();
            $this->receipt['directory_checks']++;
            try { $directoryReady = ($this->directory)($this->connection) === true; } catch (Throwable $ignored) { $directoryReady = false; }
            self::check($directoryReady, 'project_source_unavailable');
            $this->receipt['probe_count']++;
            try { $probe = ($this->probe)($this->connection,self::EXPECTED_URL,self::PROBE); } catch (Throwable $ignored) { $probe = []; }
            self::check(is_array($probe) && empty($probe['transport_error']) && (int)($probe['http'] ?? 0) === 200
                && is_array($probe['data'] ?? null) && ($probe['data']['ok'] ?? null) === true && ($probe['data']['result'] ?? null) === 'ignored', 'endpoint_probe_failed');

            // GET /subscriptions never proves which secret the provider holds.
            // Only the wrapper's exact bot/token/secret receipt may reuse a prior registration.
            $needsRegistration = !$before['expected_present'] || !$before['types_verified'] || !$this->receipt['registration_confirmed'];
            if (!$activate) return $this->result($needsRegistration || $before['legacy_present'] ? 'planned' : 'ready',
                $needsRegistration ? 'registration_required' : ($before['legacy_present'] ? 'legacy_removal_required' : 'already_active'));
            if (!$needsRegistration && !$before['legacy_present']) return $this->result('active','already_active');

            // The read-only preflight is not a lease over provider configuration.
            $fresh = $this->subscriptions();
            self::check($fresh['fingerprint'] === $before['fingerprint'], 'subscription_configuration_changed');
            $unknownResponse = false;
            if ($needsRegistration) {
                $this->receipt['registration_confirmed'] = false;
                $written = $this->mutate('register',self::EXPECTED_URL,'POST',[],[
                    'url'=>self::EXPECTED_URL,'update_types'=>self::UPDATE_TYPES,'secret'=>$this->connection->webhookSecret()]);
                $unknownResponse = !self::accepted($written);
                // Exactly one observation after a write, including timeout/unknown response.
                try { $fresh = $this->subscriptions(); } catch (Throwable $ignored) { return $this->result('unknown','registration_readback_unavailable'); }
                if (!$fresh['expected_present'] || !$fresh['types_verified']) return $this->result('unknown','registration_unconfirmed');
                if ($unknownResponse) return $this->result('unknown','registration_secret_unconfirmed');
                $this->receipt['registration_confirmed'] = true;
            }

            $plan = MaxWebhookReconciler::plan($fresh['health'], [self::LEGACY_URL]);
            self::check(($plan['ok'] ?? false) === true, 'canonical_not_safely_reconcilable');
            if (($plan['action'] ?? '') === 'delete_legacy') {
                self::check(($plan['delete_urls'] ?? null) === [self::LEGACY_URL], 'legacy_removal_not_allowed');
                $removed = $this->mutate('remove_legacy',self::LEGACY_URL,'DELETE',['url'=>self::LEGACY_URL],null);
                $unknownResponse = $unknownResponse || !self::accepted($removed);
                try { $fresh = $this->subscriptions(); } catch (Throwable $ignored) { return $this->result('unknown','removal_readback_unavailable'); }
            }
            if (($fresh['health']['ok'] ?? false) !== true || !$fresh['types_verified']) return $this->result('unknown','activation_unconfirmed');
            return $this->result('active',$unknownResponse ? 'verified_after_unknown_response' : 'activated');
        } catch (ProjectMaxWebhookSetupFailure $error) {
            return $this->result($this->receipt['write_attempts'] > 0 ? 'unknown' : 'blocked',$error->getMessage());
        } catch (Throwable $ignored) {
            return $this->result($this->receipt['write_attempts'] > 0 ? 'unknown' : 'blocked','configuration_unavailable');
        }
    }

    private function read(string $path): array
    {
        $response = $this->call('GET',$path,[],null);
        self::check(!$response['transport_error'] && $response['http'] >= 200 && $response['http'] < 300 && is_array($response['data']), 'provider_read_failed');
        return $response['data'];
    }

    private function subscriptions(): array
    {
        $data = $this->read('/subscriptions');
        self::check(is_array($data['subscriptions'] ?? null) && array_is_list($data['subscriptions']) && count($data['subscriptions']) <= 8, 'subscriptions_invalid');
        $normalized = [];
        foreach ($data['subscriptions'] as $row) {
            self::check(is_array($row) && is_string($row['url'] ?? null), 'subscriptions_invalid');
            $url = $row['url'];
            self::check(in_array($url,[self::EXPECTED_URL,self::LEGACY_URL],true), 'unknown_subscription_url');
            self::check(!array_key_exists($url,$normalized), 'duplicate_subscription_url');
            $types = $row['update_types'] ?? null;
            if ($types !== null) {
                self::check(is_array($types) && array_is_list($types) && count($types) <= 128, 'subscription_types_invalid');
                foreach ($types as $type) self::check(is_string($type) && preg_match('/\A[a-z_]{1,64}\z/D',$type) === 1, 'subscription_types_invalid');
                $types = array_values(array_unique($types)); sort($types);
            }
            $normalized[$url] = $types;
        }
        ksort($normalized);
        $health = MaxWebhookHealth::evaluate(['http'=>200,'errno'=>0,'body'=>json_encode(['subscriptions'=>$data['subscriptions']],JSON_THROW_ON_ERROR)],self::EXPECTED_URL);
        $expected = array_key_exists(self::EXPECTED_URL,$normalized);
        $required = self::UPDATE_TYPES; sort($required);
        $verified = $expected && $normalized[self::EXPECTED_URL] === $required;
        $legacy = array_key_exists(self::LEGACY_URL,$normalized);
        $this->receipt['subscription_count'] = count($normalized);
        $this->receipt['expected_present'] = $expected;
        $this->receipt['update_types_verified'] = $verified;
        $this->receipt['legacy_present'] = $legacy;
        return ['health'=>$health,'expected_present'=>$expected,'types_verified'=>$verified,'legacy_present'=>$legacy,
            'fingerprint'=>hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR))];
    }

    private function mutate(string $operation, string $url, string $method, array $query, ?array $body): array
    {
        self::check($this->beforeMutation !== null, 'mutation_admission_required');
        self::check(($operation === 'register' && $url === self::EXPECTED_URL && $method === 'POST')
            || ($operation === 'remove_legacy' && $url === self::LEGACY_URL && $method === 'DELETE'), 'operation_not_allowed');
        $counter = $operation === 'register' ? 'register_attempts' : 'remove_legacy_attempts';
        self::check($this->receipt[$counter] === 0, 'mutation_budget_exhausted');
        try { $admitted = ($this->beforeMutation)($operation,$url); } catch (Throwable $ignored) { $admitted = false; }
        self::check($admitted !== false, 'mutation_admission_denied');
        $this->receipt[$counter]++;
        $this->receipt['write_attempts']++;
        return $this->call($method,'/subscriptions',$query,$body);
    }

    private function call(string $method, string $path, array $query, ?array $body): array
    {
        self::check($this->receipt['request_count'] < 7, 'request_budget_exhausted');
        $this->receipt['request_count']++;
        try { $response = ($this->provider)($this->connection,$method,$path,$query,$body); } catch (Throwable $ignored) { $response = []; }
        if (!is_array($response)) $response = [];
        return ['http'=>(int)($response['http'] ?? 0),'transport_error'=>!empty($response['transport_error']),
            'data'=>is_array($response['data'] ?? null) ? $response['data'] : null];
    }

    private static function accepted(array $response): bool
    {
        return !$response['transport_error'] && $response['http'] >= 200 && $response['http'] < 300 && ($response['data']['success'] ?? null) === true;
    }

    private function result(string $state, string $reason): array
    {
        return ['ok'=>in_array($state,['ready','planned','active'],true),'state'=>$state,'reason'=>$reason,
            'expected_url'=>self::EXPECTED_URL,'receipt'=>$this->receipt];
    }

    private static function check(bool $ok, string $reason): void
    {
        if (!$ok) throw new ProjectMaxWebhookSetupFailure($reason);
    }

    /** Exact metadata read, no schema initialization or project/source creation. */
    public static function activeDirectory(ProjectMaxConnection $connection): bool
    {
        if ($connection->projectKey() !== self::PROJECT || !ConversationDb::isConfigured()) return false;
        $q = ConversationDb::connection()->prepare("SELECT p.project_key,s.source_key,s.channel,s.handling_mode FROM projects p JOIN conversation_sources s ON s.project_id=p.id WHERE p.project_key=? AND p.is_active=1 AND s.source_key=? AND s.channel='max' AND s.is_active=1 AND s.handling_mode IN ('manager','ask') LIMIT 2");
        $q->execute([self::PROJECT,self::SOURCE]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 && $rows[0]['project_key'] === self::PROJECT && $rows[0]['source_key'] === self::SOURCE;
    }

    /** Only the fixed authenticated unsupported update, with no customer identity. */
    public static function probeEndpoint(ProjectMaxConnection $connection, string $url, array $update): array
    {
        $failure = ['http'=>0,'transport_error'=>true,'data'=>null];
        if ($connection->projectKey() !== self::PROJECT || $connection->botKey() !== self::BOT
            || $url !== self::EXPECTED_URL || $update !== self::PROBE || !extension_loaded('curl')) return $failure;
        $raw = ''; $ch = curl_init(self::EXPECTED_URL);
        if ($ch === false) return $failure;
        curl_setopt_array($ch, MaxTlsConfig::strictCurlOptions() + [
            CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>6,CURLOPT_CUSTOMREQUEST=>'POST',
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Max-Bot-Api-Secret: '.$connection->webhookSecret()],
            CURLOPT_POSTFIELDS=>json_encode(self::PROBE,JSON_THROW_ON_ERROR),
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$raw): int {
                if (strlen($raw)+strlen($chunk) > 4096) return 0; $raw .= $chunk; return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch); $http = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $failed = $ok === false || curl_errno($ch) !== 0;
        curl_close($ch);
        $data = $failed ? null : json_decode($raw,true,8);
        return ['http'=>$http,'transport_error'=>$failed,'data'=>is_array($data) ? $data : null];
    }
}

final class ProjectMaxWebhookSetupFailure extends RuntimeException {}
