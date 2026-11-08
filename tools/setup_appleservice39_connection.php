<?php

declare(strict_types=1);

/**
 * One fixed, explicitly invoked bootstrap. Credential import never changes a
 * subscription; verify/activate are separate, explicit commissioning operations.
 * prepare emits an RSA public key. apply accepts only an encrypted envelope on
 * stdin: RSA-OAEP-SHA256 wraps an AES-256-GCM key; the GCM plaintext is the token.
 * Private material remains beside the existing protected configuration. No mkdir.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class AppleService39SetupFailure extends RuntimeException {}

final class AppleService39ConnectionSetup
{
    public const CONTRACT = 'appleservice39-connection-bootstrap-v1';
    public const PROJECT = 'appleservice39';
    public const BOT = 'id390521129062_bot';
    public const SOURCE = 'max:appleservice39-main';
    public const REGISTRY = 'MAX_SEARCH_PROJECT_CONNECTIONS';
    public const ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
    public const CONFIG = '/var/www/anytoour/data/config/max-search.php';
    public const TTL = 900;
    private string $root;
    private string $config;
    private $identity;
    private $directory;
    private $clock;
    private $provenance;

    /** Injectable dependencies are reachable only from PHP CLI fixtures, never HTTP/input. */
    public function __construct(string $root, string $config, callable $identity, callable $directory,
        ?callable $clock = null, ?callable $provenance = null)
    {
        $this->root = $root;
        $this->config = $config;
        $this->identity = $identity;
        $this->directory = $directory;
        $this->clock = $clock ?? static fn(): int => time();
        $this->provenance = $provenance ?? static function (string $sha) use ($root): bool {
            [$code, $head] = self::command(['git', '-C', $root, 'rev-parse', 'HEAD']);
            if ($code !== 0 || trim($head) !== $sha) return false;
            foreach ([['diff', '--quiet'], ['diff', '--cached', '--quiet']] as $arguments) {
                [$code] = self::command(['git', '-C', $root, ...$arguments]);
                if ($code !== 0) return false;
            }
            return true;
        };
    }

    private static function check(bool $ok, string $reason): void
    {
        if (!$ok) throw new AppleService39SetupFailure($reason);
    }

    /** Fixed local programs; stdout stays in memory and stderr is never reported. */
    private static function command(array $arguments, string $input = ''): array
    {
        $process = @proc_open($arguments, [['pipe','r'], ['pipe','w'], ['file','/dev/null','w']], $pipes);
        self::check(is_resource($process), 'local_command_unavailable');
        try {
            $written = $input === '' ? 0 : fwrite($pipes[0], $input);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1], 65537);
            fclose($pipes[1]);
            $code = proc_close($process); $process = null;
            self::check($written === strlen($input) && is_string($output) && strlen($output) <= 65536, 'local_command_failed');
            return [$code, $output];
        } finally { if (is_resource($process)) proc_terminate($process); }
    }

    private function path(string $suffix): string { return $this->config.'.appleservice39-'.$suffix; }

    private function activationState(): array
    {
        $path=$this->path('activation.json');
        if (!file_exists($path)&&!is_link($path)) return ['contract'=>self::CONTRACT,'attempts'=>[],'confirmed_registrations'=>[]];
        $state=json_decode($this->readPrivate($path,32768),true,8);
        self::check(is_array($state)&&($state['contract']??null)===self::CONTRACT
            && is_array($state['attempts']??null)&&count($state['attempts'])<=32
            && is_array($state['confirmed_registrations']??[])&&count($state['confirmed_registrations']??[])<=32, 'activation_state_invalid');
        $state['confirmed_registrations']=$state['confirmed_registrations']??[];
        return $state;
    }

    private function saveActivationState(array $state): void
    {
        $path=$this->path('activation.json'); $temporary=$this->path('activation-'.bin2hex(random_bytes(12)));
        try {
            $this->writeNew($temporary,json_encode($state,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
            self::check(@rename($temporary,$path), 'activation_state_write_failed');
        } finally { if (is_file($temporary)) @unlink($temporary); }
    }

    private static function activationKey(string $operation,string $url): string
    {
        return hash('sha256',self::PROJECT."\n".self::BOT."\n".$operation."\n".$url);
    }

    private function registrationProof(): callable
    {
        return function ($connection): bool {
            if (!$connection instanceof ProjectMaxConnection || $connection->projectKey()!==self::PROJECT || $connection->botKey()!==self::BOT) return false;
            $fingerprint=hash('sha256',serialize([$connection->botKey(),$connection->token(),$connection->webhookSecret()]));
            $state=$this->activationState(); $receipt=$state['confirmed_registrations'][$fingerprint]??null;
            $claim=$state['attempts'][self::activationKey('register','https://app.anytoour.ru/appleservice39_webhook.php')]??null;
            return is_array($receipt)&&($receipt['confirmed']??null)===true&&is_array($claim)
                && ($claim['connection_fingerprint']??null)===$fingerprint;
        };
    }

    private function owner(array $stat): bool
    {
        return !function_exists('posix_geteuid') || (int)$stat['uid'] === posix_geteuid();
    }

    private function preflight(string $sha, bool $write = true): void
    {
        self::check(preg_match('/\A[0-9a-f]{40}\z/', $sha) === 1 && ($this->provenance)($sha) === true, 'application_sha_mismatch');
        $dir = dirname($this->config); clearstatcache(); $stat = @lstat($dir);
        self::check(is_array($stat) && ($stat['mode'] & 0170000) === 0040000 && ($stat['mode'] & 0022) === 0
            && $this->owner($stat) && realpath($dir) === $dir && (!$write || is_writable($dir))
            && $dir !== $this->root && !str_starts_with($dir, $this->root.'/'), 'private_directory_unavailable');
        $this->readPrivate($this->config, 1048576, false);
        self::check(!$write || is_writable($this->config), 'private_config_unwritable');
        self::check(!$write || in_array($this->configMetadata()['mode'], [0600,0640], true), 'unsupported_config_permissions');
    }

    private function configMetadata(): array
    {
        clearstatcache(true,$this->config); $stat=@lstat($this->config);
        self::check(is_array($stat), 'private_file_unavailable');
        return ['uid'=>(int)$stat['uid'],'gid'=>(int)$stat['gid'],'mode'=>$stat['mode']&0777];
    }

    private function assertPreimage(array $state): void
    {
        self::check(hash_equals($state['config_preimage'],hash('sha256',$this->readPrivate($this->config,1048576,false)))
            && ($state['config_metadata'] ?? null)===$this->configMetadata(), 'configuration_changed');
    }

    /** The real admitted runtime must accept the candidate; output is only a digest. */
    private function effectiveRegistryDigest(string $path): array
    {
        $probe='ini_set("display_errors","0");ini_set("log_errors","0");ob_start();try{require $argv[1];require $argv[2]."/services/ProjectMaxConnection.php";'
            .'$v=defined("MAX_SEARCH_PROJECT_CONNECTIONS")?constant("MAX_SEARCH_PROJECT_CONNECTIONS"):null;$c=ProjectMaxConnection::resolve("appleservice39");'
            .'$ok=$c!==null&&!ProjectMaxConnection::isLegacyProject("appleservice39")&&$c->projectKey()==="appleservice39"&&$c->botKey()==="id390521129062_bot";'
            .'ob_end_clean();if(!$ok)exit(3);echo hash("sha256",serialize($v));}catch(Throwable $e){while(ob_get_level())ob_end_clean();exit(2);}';
        // In production __DIR__ belongs to the exact admitted checkout. Fixtures
        // also exercise its actual resolver, never a replacement resolver stub.
        return self::command([PHP_BINARY,'-r',$probe,$path,dirname(__DIR__)]);
    }

    /** Reject links, ownership changes and unstable inode reads before handling secrets. */
    private function readPrivate(string $path, int $limit, bool $strict = true): string
    {
        clearstatcache(true, $path); $named = @lstat($path);
        self::check(is_array($named) && ($named['mode'] & 0170000) === 0100000 && $named['nlink'] === 1
            && ($named['mode'] & 0022) === 0 && (!$strict || ($named['mode'] & 0777) === 0600)
            && $this->owner($named) && realpath($path) === $path && $named['size'] <= $limit, 'private_file_unavailable');
        $file = @fopen($path, 'rb'); self::check(is_resource($file), 'private_file_unavailable');
        try {
            $opened = fstat($file);
            self::check(is_array($opened) && $opened['ino'] === $named['ino'] && $opened['dev'] === $named['dev'], 'private_file_changed');
            $raw = stream_get_contents($file, $limit + 1);
            self::check(is_string($raw) && strlen($raw) <= $limit, 'private_file_unavailable');
            return $raw;
        } finally { fclose($file); }
    }

    private function writeNew(string $path, string $bytes): void
    {
        $old = umask(0077); $file = @fopen($path, 'x+b'); umask($old);
        self::check(is_resource($file), 'private_file_exists');
        try {
            self::check(@chmod($path, 0600) && fwrite($file, $bytes) === strlen($bytes) && fflush($file)
                && (!function_exists('fsync') || fsync($file)), 'private_write_failed');
        } catch (Throwable $error) { @unlink($path); throw $error; }
        finally { fclose($file); }
    }

    private function state(): ?array
    {
        $path = $this->path('bootstrap.json');
        if (!file_exists($path) && !is_link($path)) return null;
        $state = json_decode($this->readPrivate($path, 16384), true, 16);
        self::check(is_array($state) && ($state['contract'] ?? null) === self::CONTRACT
            && preg_match('/\A[a-f0-9]{32}\z/', (string)($state['request_id'] ?? '')) === 1
            && is_int($state['expires_at'] ?? null) && is_int($state['admin_manager_id'] ?? null)
            && preg_match('/\A[0-9a-f]{40}\z/', (string)($state['application_sha'] ?? '')) === 1
            && preg_match('/\A[0-9a-f]{64}\z/', (string)($state['config_preimage'] ?? '')) === 1
            && in_array($state['state'] ?? '', ['prepared','applying','configured','expired'], true), 'bootstrap_state_invalid');
        return $state;
    }

    private function saveState(array $state): void
    {
        $path = $this->path('bootstrap.json');
        if (file_exists($path) || is_link($path)) $this->readPrivate($path, 16384);
        $temporary = $this->path('state-'.bin2hex(random_bytes(12)));
        try {
            $this->writeNew($temporary, json_encode($state, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
            self::check(@rename($temporary, $path), 'bootstrap_state_write_failed');
        } finally { if (is_file($temporary)) @unlink($temporary); }
    }

    private function removeKey(): void
    {
        $path = $this->path('bootstrap.pem');
        if (!file_exists($path) && !is_link($path)) return;
        $this->readPrivate($path, 8192);
        self::check(@unlink($path), 'private_key_cleanup_failed');
    }

    private function expire(?array $state): ?array
    {
        if ($state !== null && $state['state'] === 'prepared' && ($this->clock)() >= $state['expires_at']) {
            $this->removeKey(); $state['state'] = 'expired'; $this->saveState($state);
        }
        return $state;
    }

    private function locked(string $sha, callable $operation): array
    {
        $this->preflight($sha);
        $path = $this->path('setup.lock');
        if (file_exists($path) || is_link($path)) $this->readPrivate($path, 0);
        $old = umask(0077); $lock = @fopen($path, 'c+b'); umask($old);
        self::check(is_resource($lock), 'setup_lock_unavailable');
        try {
            $stat = fstat($lock); $named = @lstat($path);
            self::check(is_array($stat) && is_array($named) && $stat['ino'] === $named['ino'] && $stat['dev'] === $named['dev']
                && $stat['nlink'] === 1 && ($stat['mode'] & 0170000) === 0100000 && $this->owner($stat)
                && @chmod($path, 0600) && flock($lock, LOCK_EX|LOCK_NB), 'setup_locked');
            $this->preflight($sha);
            return $operation();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function aad(string $requestId, string $sha): string
    {
        return self::CONTRACT."\n".$requestId."\n".$sha."\n".self::PROJECT."\n".self::BOT;
    }

    public function prepare(string $sha, int $adminId): array
    {
        return $this->locked($sha, function () use ($sha, $adminId): array {
            self::check($adminId > 0, 'admin_manager_required');
            self::check(extension_loaded('openssl') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true), 'crypto_unavailable');
            [$code] = self::command(['openssl', 'version']); self::check($code === 0, 'crypto_unavailable');
            $source = $this->readPrivate($this->config, 1048576, false);
            self::registry($source); // Unsupported config expressions are never executed/rebuilt.
            $directory = ($this->directory)($adminId, false);
            self::check(is_array($directory) && preg_match('/\A[a-f0-9]{64}\z/', (string)($directory['fingerprint'] ?? '')) === 1, 'directory_unavailable');
            $state = $this->expire($this->state());
            if ($state !== null && $state['state'] !== 'expired') {
                self::check($state['state'] === 'prepared', 'request_consumed');
                self::check($state['application_sha'] === $sha && $state['admin_manager_id'] === $adminId
                    && hash_equals($state['config_preimage'], hash('sha256', $source))
                    && ($state['config_metadata'] ?? null)===$this->configMetadata()
                    && hash_equals($state['directory_preimage'], $directory['fingerprint']), 'existing_request_mismatch');
                $this->readPrivate($this->path('bootstrap.pem'), 8192);
                return $this->publicRequest($state); // Same request/expiry, never a lease renewal.
            }
            self::check(!file_exists($this->path('bootstrap.pem')) && !is_link($this->path('bootstrap.pem')), 'private_key_exists');
            $key = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA, 'private_key_bits'=>3072]);
            $pem = ''; $details = $key ? openssl_pkey_get_details($key) : false;
            self::check($key !== false && openssl_pkey_export($key, $pem) && is_array($details), 'key_generation_failed');
            $state = ['contract'=>self::CONTRACT, 'state'=>'prepared', 'request_id'=>bin2hex(random_bytes(16)),
                'application_sha'=>$sha, 'admin_manager_id'=>$adminId, 'expires_at'=>($this->clock)()+self::TTL,
                'config_preimage'=>hash('sha256', $source), 'directory_preimage'=>$directory['fingerprint'],
                'config_metadata'=>$this->configMetadata(),
                'public_key_pem'=>$details['key']];
            $this->writeNew($this->path('bootstrap.pem'), $pem);
            try { $this->saveState($state); } catch (Throwable $error) { $this->removeKey(); throw $error; }
            unset($pem, $key);
            return $this->publicRequest($state);
        });
    }

    private function publicRequest(array $state): array
    {
        return ['ok'=>true, 'state'=>'prepared', 'contract'=>self::CONTRACT, 'project_key'=>self::PROJECT,
            'bot_key'=>self::BOT, 'request_id'=>$state['request_id'], 'application_sha'=>$state['application_sha'],
            'admin_manager_id'=>$state['admin_manager_id'], 'expires_at'=>$state['expires_at'],
            'algorithm'=>'RSA-OAEP-SHA256+A256GCM', 'public_key_pem'=>$state['public_key_pem'],
            'aad'=>self::aad($state['request_id'], $state['application_sha']), 'subscription_writes'=>0];
    }

    private function decrypt(string $raw, array $state): string
    {
        self::check(strlen($raw) <= 16384, 'envelope_invalid');
        $envelope = json_decode($raw, true, 8);
        self::check(is_array($envelope), 'envelope_invalid');
        $keys = array_keys($envelope); sort($keys);
        self::check($keys === ['ciphertext','encrypted_key','nonce','request_id','tag','version']
            && ($envelope['version'] ?? null) === 1 && ($envelope['request_id'] ?? null) === $state['request_id'], 'envelope_invalid');
        $decoded = [];
        foreach (['encrypted_key','nonce','ciphertext','tag'] as $name) {
            self::check(is_string($envelope[$name]) && preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $envelope[$name]) === 1, 'envelope_invalid');
            $decoded[$name] = base64_decode($envelope[$name], true);
            self::check(is_string($decoded[$name]) && base64_encode($decoded[$name]) === $envelope[$name], 'envelope_invalid');
        }
        self::check(strlen($decoded['encrypted_key']) === 384 && strlen($decoded['nonce']) === 12
            && strlen($decoded['tag']) === 16 && strlen($decoded['ciphertext']) >= 8 && strlen($decoded['ciphertext']) <= 4096, 'envelope_invalid');
        $private = $this->readPrivate($this->path('bootstrap.pem'), 8192);
        $key = openssl_pkey_get_private($private); $details = $key ? openssl_pkey_get_details($key) : false;
        self::check(is_array($details) && hash_equals($state['public_key_pem'], $details['key']), 'private_key_changed');
        unset($private, $key);
        [$code, $symmetric] = self::command(['openssl','pkeyutl','-decrypt','-inkey',$this->path('bootstrap.pem'),
            '-pkeyopt','rsa_padding_mode:oaep','-pkeyopt','rsa_oaep_md:sha256','-pkeyopt','rsa_mgf1_md:sha256'], $decoded['encrypted_key']);
        self::check($code === 0 && strlen($symmetric) === 32, 'envelope_authentication_failed');
        $token = openssl_decrypt($decoded['ciphertext'], 'aes-256-gcm', $symmetric, OPENSSL_RAW_DATA,
            $decoded['nonce'], $decoded['tag'], self::aad($state['request_id'], $state['application_sha']));
        unset($symmetric);
        self::check(is_string($token) && strlen($token) >= 8 && strlen($token) <= 4096
            && preg_match('/[\x00-\x20\x7f]/', $token) !== 1, 'envelope_authentication_failed');
        return $token;
    }

    private static function entry(array $registry, string $token): array
    {
        foreach ($registry as $project => $entry) {
            if ($project === self::PROJECT) continue;
            $max = is_array($entry) && is_array($entry['max'] ?? null) ? $entry['max'] : [];
            self::check(strcasecmp((string)($max['bot_key'] ?? ''), self::BOT) !== 0
                && (!is_string($max['token'] ?? null) || !hash_equals($max['token'], $token)), 'connection_already_bound');
        }
        $old = $registry[self::PROJECT] ?? null;
        $project = ['id'=>self::PROJECT, 'brand'=>['name'=>'AppleService39'],
            'business'=>['kind'=>'device_repair'], 'routing'=>['source_key'=>self::SOURCE]];
        if ($old !== null) {
            self::check(is_array($old) && ($old['project']['id'] ?? null) === self::PROJECT
                && ($old['max']['bot_key'] ?? null) === self::BOT
                && ($old['project']['business']['kind'] ?? null) === 'device_repair'
                && ($old['project']['routing']['source_key'] ?? null) === self::SOURCE
                && is_string($old['max']['webhook_secret'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/', $old['max']['webhook_secret']) === 1, 'existing_connection_mismatch');
            $old['max']['token'] = $token; return $old; // Preserve an existing webhook secret/settings.
        }
        return ['project'=>$project, 'max'=>['bot_key'=>self::BOT, 'token'=>$token, 'webhook_secret'=>bin2hex(random_bytes(32))]];
    }

    public function apply(string $sha, int $adminId, string $envelope): array
    {
        return $this->locked($sha, function () use ($sha, $adminId, $envelope): array {
            $state = $this->expire($this->state());
            self::check($state !== null && $state['state'] === 'prepared', 'request_consumed');
            self::check($state['application_sha'] === $sha && $state['admin_manager_id'] === $adminId, 'request_binding_mismatch');
            $source = $this->readPrivate($this->config, 1048576, false);
            $this->assertPreimage($state);
            $directory = ($this->directory)($adminId, false);
            self::check(is_array($directory) && hash_equals($state['directory_preimage'], (string)($directory['fingerprint'] ?? '')), 'directory_changed');
            $token = $this->decrypt($envelope, $state);
            $parsed = self::registry($source); $registry = $parsed['value'];
            $registry[self::PROJECT] = self::entry($registry, $token);
            try { $me = ($this->identity)($token); }
            catch (Throwable $ignored) { throw new AppleService39SetupFailure('max_identity_unavailable'); }
            self::check(is_array($me) && ($me['http'] ?? null) === 200 && empty($me['transport_error'])
                && ($me['data']['is_bot'] ?? null) === true && ($me['data']['username'] ?? null) === self::BOT
                && preg_match('/\A[1-9][0-9]{0,19}\z/', (string)($me['data']['user_id'] ?? '')) === 1, 'max_identity_mismatch');
            unset($token, $me);
            // Recheck both admitted source and exact private preimage after the network read.
            $this->preflight($sha);
            self::check(($this->clock)() < $state['expires_at'], 'request_expired');
            $this->assertPreimage($state);
            $candidate = self::replaceRegistry($source, $parsed, $registry);
            $temporary = $this->path('candidate-'.$state['request_id']);
            $backup = $this->path('backup-'.$state['request_id']);
            try {
                $this->writeNew($temporary, $candidate);
                [$code] = self::command([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-l', $temporary]);
                self::check($code === 0, 'candidate_lint_failed');
                [$code, $digest] = $this->effectiveRegistryDigest($temporary);
                self::check($code === 0 && hash_equals(hash('sha256', serialize($registry)), $digest), 'candidate_effective_mismatch');
                // Preserve the actual runtime reader's supported group/mode, before
                // consuming the one-use import or creating any directory records.
                $metadata=$state['config_metadata']; clearstatcache(true,$temporary);
                self::check((filegroup($temporary)===$metadata['gid'] || @chgrp($temporary,$metadata['gid']))
                    && @chmod($temporary,$metadata['mode']), 'candidate_permissions_failed');
                clearstatcache(true,$temporary); $stat=@lstat($temporary);
                self::check(is_array($stat) && (int)$stat['uid']===$metadata['uid'] && (int)$stat['gid']===$metadata['gid']
                    && ($stat['mode']&0777)===$metadata['mode'], 'candidate_permissions_failed');
                $this->preflight($sha);
                $this->assertPreimage($state);
                $fresh = ($this->directory)($adminId, false);
                self::check(hash_equals($state['directory_preimage'], (string)($fresh['fingerprint'] ?? '')), 'directory_changed');
                $state['state'] = 'applying'; $state['candidate_sha256'] = hash('sha256', $candidate);
                $this->saveState($state); $this->removeKey(); // Consume before any database/config mutation.
                ($this->directory)($adminId, true);
                $this->preflight($sha);
                $this->assertPreimage($state);
                $this->writeNew($backup, $source);
                self::check(@rename($temporary, $this->config), 'configuration_write_unknown');
                self::check(hash_equals($state['candidate_sha256'], hash('sha256', $this->readPrivate($this->config, 1048576, false)))
                    && $this->configMetadata()===$metadata, 'configuration_write_unknown');
                $state['state'] = 'configured'; $this->saveState($state);
                @unlink($backup);
                return $this->receipt($state, 'configured');
            } finally { if (is_file($temporary)) @unlink($temporary); }
        });
    }

    private function receipt(array $state, string $status): array
    {
        return ['ok'=>$status === 'configured', 'state'=>$status, 'project_key'=>self::PROJECT, 'bot_key'=>self::BOT,
            'request_id'=>$state['request_id'], 'application_sha'=>$state['application_sha'],
            'subscription_writes'=>0, 'next_step'=>$status === 'configured' ? 'verify_project_access_then_explicit_webhook_activation' : 'inspect_existing_result_without_reimport'];
    }

    /** Read-only inspection, including one readback after an uncertain apply. */
    public function status(string $sha): array
    {
        $this->preflight($sha, false);
        $source = $this->readPrivate($this->config, 1048576, false); $state = $this->state();
        $parsed = self::registry($source); $entry = $parsed['value'][self::PROJECT] ?? null;
        $max = is_array($entry) && is_array($entry['max'] ?? null) ? $entry['max'] : [];
        $ready = is_array($entry) && ($entry['project']['id'] ?? null) === self::PROJECT
            && ($entry['project']['business']['kind'] ?? null) === 'device_repair'
            && ($entry['project']['routing']['source_key'] ?? null) === self::SOURCE && ($max['bot_key'] ?? null) === self::BOT
            && is_string($max['token'] ?? null) && strlen($max['token']) >= 8 && strlen($max['token']) <= 4096
            && !preg_match('/[\x00-\x20\x7f]/', $max['token']) && is_string($max['webhook_secret'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/', $max['webhook_secret']) === 1;
        if ($ready) {
            [$code,$digest]=$this->effectiveRegistryDigest($this->config);
            $ready=$code===0&&hash_equals(hash('sha256',serialize($parsed['value'])),$digest);
        }
        $directory = ($this->directory)(0, false);
        $bootstrap = ['state'=>$state['state'] ?? 'not_prepared'];
        if ($state !== null) {
            $bootstrap += ['request_id'=>$state['request_id'],'expires_at'=>$state['expires_at'],'application_matches'=>$state['application_sha']===$sha];
            if ($state['state'] === 'prepared' && ($this->clock)() >= $state['expires_at']) $bootstrap['state'] = 'expired';
            if (in_array($state['state'], ['applying','configured'], true)) {
                $actual = hash('sha256', $source);
                $bootstrap['import_outcome'] = hash_equals((string)($state['candidate_sha256'] ?? ''), $actual) ? 'installed'
                    : (hash_equals($state['config_preimage'], $actual) ? 'not_installed_request_consumed' : 'configuration_changed');
            }
        }
        self::check(hash_equals(hash('sha256',$source), hash('sha256',$this->readPrivate($this->config,1048576,false))), 'configuration_changed');
        return ['ok'=>true, 'state'=>'inspected', 'project_key'=>self::PROJECT, 'bot_key'=>self::BOT,
            'application_sha'=>$sha, 'config_ready'=>$ready, 'config_write_available'=>is_writable($this->config)&&is_writable(dirname($this->config))&&in_array($this->configMetadata()['mode'],[0600,0640],true),
            'bootstrap'=>$bootstrap, 'directory'=>$directory, 'subscription_writes'=>0];
    }

    /** Provider verification is a separate read-only operation after import. */
    public function verify(string $sha, callable $operation): array
    {
        $this->preflight($sha,false);
        $before=hash('sha256',$this->readPrivate($this->config,1048576,false));
        $result=$operation(null,$this->registrationProof());
        $this->preflight($sha,false);
        self::check(hash_equals($before,hash('sha256',$this->readPrivate($this->config,1048576,false))), 'configuration_changed');
        self::check(is_array($result), 'webhook_setup_unavailable');
        return $result;
    }

    /** Explicit provider activation, serialized with import and the normal installer. */
    public function activate(string $sha, int $adminId, callable $operation): array
    {
        return $this->locked($sha,function () use ($sha,$adminId,$operation): array {
            self::check($adminId>0, 'admin_manager_required');
            $source=$this->readPrivate($this->config,1048576,false); $metadata=$this->configMetadata();
            $registry=self::registry($source)['value'];
            [$code,$digest]=$this->effectiveRegistryDigest($this->config);
            self::check($code===0&&hash_equals(hash('sha256',serialize($registry)),$digest), 'connection_unavailable');
            $directory=($this->directory)($adminId,false);
            self::check(!empty($directory['project_ready'])&&!empty($directory['source_ready']), 'directory_not_ready');
            $max=$registry[self::PROJECT]['max'];
            $connection=hash('sha256',serialize([$max['bot_key'],$max['token'],$max['webhook_secret']]));
            $preimage=hash('sha256',$source);
            $beforeMutation=function (string $kind,string $url) use ($sha,$adminId,$metadata,$preimage,$connection): void {
                $allowed=['register'=>'https://app.anytoour.ru/appleservice39_webhook.php',
                    'remove_legacy'=>'https://tour-max.ru/new/appleservice39/webhook.php'];
                self::check(isset($allowed[$kind])&&$allowed[$kind]===$url, 'unexpected_provider_mutation');
                $this->preflight($sha);
                self::check($this->configMetadata()===$metadata
                    && hash_equals($preimage,hash('sha256',$this->readPrivate($this->config,1048576,false))), 'configuration_changed');
                $directory=($this->directory)($adminId,false);
                self::check(!empty($directory['project_ready'])&&!empty($directory['source_ready']), 'directory_not_ready');
                $claims=$this->activationState();
                // Keep a claim across source/config/credential changes. A previous
                // uncertain write for this bot/operation may only be inspected.
                $key=self::activationKey($kind,$url);
                self::check(!isset($claims['attempts'][$key]), 'provider_mutation_already_claimed');
                self::check(count($claims['attempts'])<32, 'activation_state_full');
                $claims['attempts'][$key]=['operation'=>$kind,'application_sha'=>$sha,'config_preimage'=>$preimage,
                    'connection_fingerprint'=>$connection,'admin_manager_id'=>$adminId,'claimed_at'=>($this->clock)()];
                $this->saveActivationState($claims);
            };
            $result=$operation($beforeMutation,$this->registrationProof());
            self::check(is_array($result), 'webhook_setup_unavailable');
            if (($result['receipt']['registration_confirmed']??null)===true) {
                $this->preflight($sha);
                self::check($this->configMetadata()===$metadata
                    && hash_equals($preimage,hash('sha256',$this->readPrivate($this->config,1048576,false))), 'configuration_changed');
                $state=$this->activationState();
                $claim=$state['attempts'][self::activationKey('register','https://app.anytoour.ru/appleservice39_webhook.php')]??null;
                self::check(is_array($claim)&&($claim['connection_fingerprint']??null)===$connection, 'registration_receipt_unbound');
                if (!isset($state['confirmed_registrations'][$connection])) {
                    $state['confirmed_registrations'][$connection]=['confirmed'=>true,'confirmed_at'=>($this->clock)(),'application_sha'=>$sha,'config_preimage'=>$preimage];
                    $this->saveActivationState($state);
                }
            }
            return $result;
        });
    }

    /** Parse literal arrays without eval; replace only one exact constant value. */
    public static function registry(string $source): array
    {
        $tokens = []; $offset = 0; $opens = 0; $append = strlen($source);
        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : null; $text = is_array($token) ? $token[1] : $token;
            if ($id === T_OPEN_TAG) $opens++;
            self::check($id !== T_OPEN_TAG_WITH_ECHO && ($id !== T_INLINE_HTML || trim($text) === ''), 'unsupported_config_structure');
            if ($id === T_CLOSE_TAG) $append = $offset;
            if (!in_array($id, [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_OPEN_TAG,T_CLOSE_TAG,T_INLINE_HTML], true)) $tokens[] = ['id'=>$id,'text'=>$text,'start'=>$offset,'end'=>$offset+strlen($text)];
            $offset += strlen($text);
        }
        self::check($opens === 1, 'unsupported_config_structure');
        $matches = []; $mentions = 0;
        foreach ($tokens as $i => $token) {
            $keyLiterals = ["'".self::REGISTRY."'", '"'.self::REGISTRY.'"'];
            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && in_array($token['text'], $keyLiterals, true)) $mentions++;
            $define = in_array($token['id'], [T_STRING,defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : -1], true)
                && strtolower(ltrim($token['text'], '\\')) === 'define' && ($tokens[$i+1]['text'] ?? '') === '('
                && ($tokens[$i+2]['id'] ?? null) === T_CONSTANT_ENCAPSED_STRING
                && in_array($tokens[$i+2]['text'], $keyLiterals, true) && ($tokens[$i+3]['text'] ?? '') === ',';
            $constant = $token['id'] === T_CONST && ($tokens[$i+1]['text'] ?? '') === self::REGISTRY && ($tokens[$i+2]['text'] ?? '') === '=';
            if (!$define && !$constant) continue;
            $at = $i + ($define ? 4 : 3); $start = $tokens[$at]['start'] ?? -1;
            $value = self::literal($tokens, $at, 0);
            self::check(is_array($value) && ($tokens[$at]['text'] ?? '') === ($define ? ')' : ';')
                && (!$define || ($tokens[$at+1]['text'] ?? '') === ';'), 'unsupported_registry_definition');
            $matches[] = ['value'=>$value, 'start'=>$start, 'end'=>$tokens[$at-1]['end'], 'append'=>$append];
        }
        self::check(count($matches) <= 1 && (count($matches) === 1 || $mentions === 0), 'ambiguous_registry_definition');
        return $matches[0] ?? ['value'=>[], 'start'=>null, 'end'=>null, 'append'=>$append];
    }

    private static function stringValue(string $text): string
    {
        if ($text[0] === "'") return str_replace(["\\'",'\\\\'], ["'",'\\'], substr($text,1,-1));
        self::check(!str_contains(substr($text,1,-1), '\\') && !str_contains(substr($text,1,-1), '$'), 'unsupported_config_literal');
        return substr($text,1,-1);
    }

    private static function literal(array $tokens, int &$at, int $depth)
    {
        self::check($depth <= 16 && isset($tokens[$at]), 'unsupported_config_literal');
        $token = $tokens[$at++];
        if ($token['text'] === '[' || $token['id'] === T_ARRAY) {
            $close = ']';
            if ($token['id'] === T_ARRAY) { self::check(($tokens[$at++]['text'] ?? '') === '(', 'unsupported_config_literal'); $close = ')'; }
            $value = []; $count = 0;
            while (($tokens[$at]['text'] ?? '') !== $close) {
                self::check(++$count <= 2000, 'unsupported_config_literal');
                $first = self::literal($tokens, $at, $depth+1);
                if (($tokens[$at]['id'] ?? null) === T_DOUBLE_ARROW) {
                    $at++; self::check(is_string($first) || is_int($first), 'unsupported_config_literal');
                    self::check(!array_key_exists($first, $value), 'duplicate_registry_key');
                    $value[$first] = self::literal($tokens, $at, $depth+1);
                } else $value[] = $first;
                if (($tokens[$at]['text'] ?? '') === $close) break;
                self::check(($tokens[$at++]['text'] ?? '') === ',', 'unsupported_config_literal');
            }
            $at++; return $value;
        }
        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) return self::stringValue($token['text']);
        if ($token['id'] === T_STRING && in_array(strtolower($token['text']), ['true','false','null'], true)) return ['true'=>true,'false'=>false,'null'=>null][strtolower($token['text'])];
        if ($token['id'] === T_LNUMBER && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $token['text']) === 1) return (int)$token['text'];
        throw new AppleService39SetupFailure('unsupported_config_literal');
    }

    public static function replaceRegistry(string $source, array $parsed, array $registry): string
    {
        $value = var_export($registry, true);
        if ($parsed['start'] !== null) return substr($source,0,$parsed['start']).$value.substr($source,$parsed['end']);
        return substr($source,0,$parsed['append'])."\n// AppleService39 connection: provisioned by the fixed encrypted bootstrap.\n"
            ."define('".self::REGISTRY."', ".$value.");\n".substr($source,$parsed['append']);
    }

    /** Exactly one bounded, verified-TLS GET; no subscriptions/messages endpoint. */
    public static function maxIdentity(string $token): array
    {
        self::check(extension_loaded('curl'), 'max_identity_unavailable');
        require_once dirname(__DIR__).'/services/MaxTlsConfig.php';
        $raw = ''; $ch = curl_init('https://platform-api2.max.ru/me');
        self::check($ch !== false, 'max_identity_unavailable');
        curl_setopt_array($ch, \MaxTlsConfig::strictCurlOptions() + [CURLOPT_CUSTOMREQUEST=>'GET', CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>8, CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_HTTPHEADER=>['Authorization: '.$token,'Accept: application/json'],
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$raw): int {
                if (strlen($raw)+strlen($chunk)>16384) return 0; $raw.=$chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($ch); $http = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $failed = $ok === false || curl_errno($ch) !== 0;
        curl_close($ch); $data = $failed ? null : json_decode($raw,true,16);
        return ['http'=>$http,'transport_error'=>$failed,'data'=>is_array($data)?$data:null];
    }
}

/** Metadata only, then the existing project/source owners; never reads conversations. */
final class AppleService39DirectorySetup
{
    private static function inspect(): array
    {
        $snapshot = AdminDirectoryService::snapshot(); $admins = []; $project = null; $source = null;
        foreach ($snapshot['projects'] ?? [] as $row) {
            if (($row['project_key'] ?? null) === AppleService39ConnectionSetup::PROJECT) {
                $project = ['id'=>(int)$row['id'],'display_name'=>(string)$row['display_name'],'active'=>(int)$row['is_active']===1];
            }
        }
        foreach ($snapshot['sources'] ?? [] as $row) {
            if (($row['project_key'] ?? null) === AppleService39ConnectionSetup::PROJECT && ($row['source_key'] ?? null) === AppleService39ConnectionSetup::SOURCE) {
                $source = ['id'=>(int)$row['id'],'source_key'=>AppleService39ConnectionSetup::SOURCE,'channel'=>(string)$row['channel'],'active'=>(int)$row['is_active']===1];
                $q=ConversationDb::connection()->prepare('SELECT handling_mode FROM conversation_sources WHERE id=? AND project_id=? LIMIT 1');
                $q->execute([$source['id'],(int)($project['id']??0)]); $source['handling_mode']=(string)$q->fetchColumn();
            }
        }
        foreach ($snapshot['managers'] ?? [] as $row) {
            if (($row['role'] ?? null) === 'admin' && (int)($row['is_active'] ?? 0) === 1) {
                $admins[] = ['id'=>(int)$row['id'],'display_name'=>(string)($row['display_name'] ?? ''),
                    'apple_project_access'=>$project!==null&&in_array($project['id'],array_map('intval',$row['project_ids']??[]),true)];
            }
        }
        return ['project'=>$project,'source'=>$source,'active_admins'=>$admins];
    }

    public static function run(int $adminId, bool $apply): array
    {
        try {
            require_once dirname(__DIR__).'/config.php';
            require_once dirname(__DIR__).'/services/AdminDirectoryService.php';
            require_once dirname(__DIR__).'/services/RoutingAdminService.php';
            ProjectAccessService::initializeReadOnly(); // Suppress the legacy current-project UPSERT.
            if ($adminId === 0 && !$apply) return self::inspect();
            $pdo = ConversationDb::connection();
            $q = $pdo->prepare('SELECT id,role,is_active FROM managers WHERE id=? LIMIT 1'); $q->execute([$adminId]); $admin = $q->fetch();
            if (!$admin || $admin['role'] !== 'admin' || (int)$admin['is_active'] !== 1) throw new AppleService39SetupFailure('active_admin_required');
            $q = $pdo->prepare('SELECT id,project_key,display_name,is_active FROM projects WHERE project_key=? LIMIT 1');
            $q->execute([AppleService39ConnectionSetup::PROJECT]); $project = $q->fetch() ?: null;
            if ($project !== null && ($project['project_key'] !== AppleService39ConnectionSetup::PROJECT
                || $project['display_name'] !== 'AppleService39' || (int)$project['is_active'] !== 1
                || !ProjectAccessService::canAccess($adminId, AppleService39ConnectionSetup::PROJECT))) throw new AppleService39SetupFailure('existing_project_mismatch');
            $source = null;
            if ($project !== null) {
                $q = $pdo->prepare('SELECT id,project_id,source_key,channel,handling_mode,is_active,primary_group_id,fallback_mode,fallback_group_id,fallback_after_minutes,suppress_channel_offer FROM conversation_sources WHERE project_id=? AND source_key=? LIMIT 1');
                $q->execute([(int)$project['id'],AppleService39ConnectionSetup::SOURCE]); $source = $q->fetch() ?: null;
                if ($source !== null && ($source['source_key'] !== AppleService39ConnectionSetup::SOURCE || $source['channel'] !== 'max'
                    || $source['handling_mode'] !== 'manager' || (int)$source['is_active'] !== 1)) throw new AppleService39SetupFailure('existing_source_mismatch');
            }
            if ($apply) {
                if ($project === null) {
                    $result = AdminDirectoryService::saveProject(['project_key'=>AppleService39ConnectionSetup::PROJECT,'display_name'=>'AppleService39','is_active'=>1],$adminId);
                    if (empty($result['ok'])) throw new AppleService39SetupFailure('project_provision_failed');
                }
                if ($source === null) {
                    $result = RoutingAdminService::saveSourceResult($adminId,AppleService39ConnectionSetup::PROJECT,0,
                        AppleService39ConnectionSetup::SOURCE,'AppleService39 · MAX','max',0,'none',0,0,'manager',true);
                    if (empty($result['ok'])) throw new AppleService39SetupFailure('source_provision_failed');
                }
                return self::run($adminId,false);
            }
            return ['fingerprint'=>hash('sha256',json_encode([$admin,$project,$source],JSON_THROW_ON_ERROR)),
                'project_ready'=>$project!==null, 'source_ready'=>$source!==null];
        } catch (AppleService39SetupFailure $error) { throw $error; }
        catch (Throwable $ignored) { throw new AppleService39SetupFailure('directory_unavailable'); }
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors','0'); ini_set('log_errors','0');
    $level = ob_get_level(); ob_start();
    try {
        $mode = $argv[1] ?? ''; $options = [];
        foreach (array_slice($argv,2) as $argument) {
            if (!preg_match('/\A--(expected-sha|admin-manager-id)=([a-z0-9]+)\z/',$argument,$match) || isset($options[$match[1]])) throw new AppleService39SetupFailure('invalid_arguments');
            $options[$match[1]] = $match[2];
        }
        if (!in_array($mode,['prepare','apply','status','verify','activate'],true) || !isset($options['expected-sha'])
            || (in_array($mode,['prepare','apply','activate'],true) && (!isset($options['admin-manager-id']) || !preg_match('/\A[1-9][0-9]{0,17}\z/',$options['admin-manager-id'])))) throw new AppleService39SetupFailure('invalid_arguments');
        if (realpath(dirname(__DIR__)) !== AppleService39ConnectionSetup::ROOT) throw new AppleService39SetupFailure('canonical_checkout_required');
        if (in_array($mode,['prepare','apply','activate'],true) && getenv('MAX_SEARCH_ALLOW_STANDBY_CONFIG_WRITE') !== '1') throw new AppleService39SetupFailure('explicit_write_guard_required');
        $setup = new AppleService39ConnectionSetup(AppleService39ConnectionSetup::ROOT, AppleService39ConnectionSetup::CONFIG,
            [AppleService39ConnectionSetup::class,'maxIdentity'],[AppleService39DirectorySetup::class,'run']);
        $sha = $options['expected-sha']; $admin = (int)($options['admin-manager-id'] ?? 0);
        if (in_array($mode,['verify','activate'],true)) {
            $operation=static function (?callable $beforeMutation=null,?callable $registrationConfirmed=null) use ($mode): array {
                require_once AppleService39ConnectionSetup::ROOT.'/config.php';
                require_once AppleService39ConnectionSetup::ROOT.'/services/ProjectMaxConnection.php';
                require_once AppleService39ConnectionSetup::ROOT.'/integrations/ProjectMaxWebhookSetup.php';
                $connection=ProjectMaxConnection::resolve(AppleService39ConnectionSetup::PROJECT);
                if ($connection===null||$connection->botKey()!==AppleService39ConnectionSetup::BOT) throw new AppleService39SetupFailure('connection_unavailable');
                return $mode==='verify' ? ProjectMaxWebhookSetup::verify($connection,null,null,null,$registrationConfirmed)
                    : ProjectMaxWebhookSetup::activate($connection,null,null,null,$beforeMutation,$registrationConfirmed);
            };
            $result=$mode==='verify'?$setup->verify($sha,$operation):$setup->activate($sha,$admin,$operation);
        } else {
            $result = $mode === 'prepare' ? $setup->prepare($sha,$admin) : ($mode === 'status' ? $setup->status($sha)
                : $setup->apply($sha,$admin,(string)stream_get_contents(STDIN,16385)));
        }
        $code = $result['ok'] ? 0 : 2;
    } catch (Throwable $error) {
        $result = ['ok'=>false,'state'=>'blocked','reason'=>$error instanceof AppleService39SetupFailure ? $error->getMessage() : 'setup_unavailable','subscription_writes'=>0]; $code = 2;
    }
    while (ob_get_level() > $level) ob_end_clean();
    echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit($code);
}
