<?php
declare(strict_types=1);
require_once __DIR__.'/TelegramArchiveBotConfig.php';
require_once __DIR__.'/TelegramDialogueNormalizer.php';

/** Bounded setup for one owner-selected bot; no messages or account mutations. */
final class TelegramArchiveBotSetup
{
    private TelegramArchiveBotConfig $store;
    private $request;

    public function __construct(?TelegramArchiveBotConfig $store = null, ?callable $request = null)
    {
        $this->store = $store ?? new TelegramArchiveBotConfig();
        $this->request = $request ?? [self::class, 'network'];
    }

    private function read(string $token, string $method, array $body = []): array
    {
        $reply = ($this->request)($token, $method, $body);
        if (($reply['http'] ?? 0) === 401) throw new RuntimeException('invalid_bot_token');
        if (($reply['http'] ?? 0) !== 200 || ($reply['ok'] ?? false) !== true || !is_array($reply['result'] ?? null)) throw new RuntimeException('telegram_unavailable');
        return $reply['result'];
    }

    private function identity(array $me, ?array $current): void
    {
        if (($me['is_bot'] ?? false) !== true || strcasecmp((string)($me['username'] ?? ''), TelegramArchiveBotConfig::USERNAME) !== 0
            || !preg_match('/\A[1-9][0-9]{0,19}\z/', (string)($me['id'] ?? ''))
            || ($current && (string)$me['id'] !== $current['bot_id'])) throw new RuntimeException('archive_bot_mismatch');
    }

    private function target(array $info): void
    {
        if (!is_string($info['url'] ?? null) || !in_array($info['url'], ['', TelegramArchiveBotConfig::WEBHOOK_URL], true)) throw new RuntimeException('archive_bot_has_other_webhook');
        if (($info['has_custom_certificate'] ?? false) !== false) throw new RuntimeException('archive_bot_has_custom_certificate');
    }

    private function types(array $info): array
    {
        $types = $info['allowed_updates'] ?? [];
        if (!is_array($types) || !array_is_list($types)) throw new RuntimeException('telegram_unavailable');
        foreach ($types as $type) if (!is_string($type) || !preg_match('/\A[a-z_]{1,64}\z/', $type)) throw new RuntimeException('telegram_unavailable');
        return array_values(array_unique($types));
    }

    private function ready(array $info): bool
    {
        $types = $this->types($info);
        return ($info['url'] ?? '') === TelegramArchiveBotConfig::WEBHOOK_URL
            && ($types === [] || array_diff(TelegramDialogueNormalizer::UPDATE_TYPES, $types) === []);
    }

    public function configure(string $token): array
    {
        $token = TelegramArchiveBotConfig::token($token);
        return $this->store->locked(function (?array $current) use ($token): array {
            $me = $this->read($token, 'getMe'); $this->identity($me, $current);
            $before = $this->read($token, 'getWebhookInfo'); $this->target($before);
            $value = ['username'=>TelegramArchiveBotConfig::USERNAME, 'bot_id'=>(string)$me['id'], 'token'=>$token,
                'secret'=>$current['secret'] ?? bin2hex(random_bytes(32)),
                'can_connect_to_business'=>($me['can_connect_to_business'] ?? false) === true,
                'webhook_state'=>$current['webhook_state'] ?? 'prepared', 'checked_at'=>gmdate('c')];
            // Keep the stored secret stable; verified existing delivery needs no write.
            if ($current && $current['webhook_state'] === 'ready' && $this->ready($before)) {
                $this->store->save($value); return $this->store->publicStatus();
            }
            $fresh = $this->read($token, 'getWebhookInfo'); $this->target($fresh);
            $comparison = static function(array $info): array { unset($info['pending_update_count'], $info['last_error_date'], $info['last_error_message']); return $info; };
            if ($comparison($fresh) !== $comparison($before)) throw new RuntimeException('webhook_configuration_changed');
            $previous = $this->types($before);
            $types = $before['url'] === '' ? TelegramDialogueNormalizer::UPDATE_TYPES
                : ($previous === [] ? [] : array_values(array_unique(array_merge($previous, TelegramDialogueNormalizer::UPDATE_TYPES))));
            $max = $before['max_connections'] ?? 40;
            if (!is_int($max) || $max < 1 || $max > 100) throw new RuntimeException('telegram_unavailable');
            $value['webhook_state'] = 'unknown';
            $this->store->save($value);
            if (!$this->store->claimSetup()) throw new RuntimeException('webhook_requires_readback');
            // Credentials already exist privately before Telegram can deliver an event.
            try { $reply = ($this->request)($token, 'setWebhook', ['url'=>TelegramArchiveBotConfig::WEBHOOK_URL,
                'secret_token'=>$value['secret'], 'allowed_updates'=>$types, 'max_connections'=>$max, 'drop_pending_updates'=>false]); }
            catch (Throwable $error) { throw new RuntimeException('webhook_requires_readback'); }
            if (($reply['http'] ?? 0) !== 200 || ($reply['ok'] ?? false) !== true || ($reply['result'] ?? null) !== true) throw new RuntimeException('webhook_requires_readback');
            $after = $this->read($token, 'getWebhookInfo'); $this->target($after);
            if (!$this->ready($after) || (int)($after['max_connections'] ?? 40) !== $max
                || ($types !== [] && array_diff($types, $this->types($after)) !== [])) throw new RuntimeException('webhook_requires_readback');
            $value['webhook_state'] = 'ready'; $this->store->save($value);
            return $this->store->publicStatus();
        });
    }

    /** Explicit read-only refresh after the owner changes Secretary Mode. */
    public function inspect(): array
    {
        return $this->store->locked(function (?array $value): array {
            if (!$value) return $this->store->publicStatus();
            $me = $this->read($value['token'], 'getMe'); $this->identity($me, $value);
            $info = $this->read($value['token'], 'getWebhookInfo'); $this->target($info);
            $value['can_connect_to_business'] = ($me['can_connect_to_business'] ?? false) === true;
            if (!$this->ready($info)) $value['webhook_state'] = 'unknown';
            $value['checked_at'] = gmdate('c'); $this->store->save($value);
            return $this->store->publicStatus();
        });
    }

    public static function network(string $token, string $method, array $body): array
    {
        TelegramArchiveBotConfig::token($token);
        if (!in_array($method, ['getMe','getWebhookInfo','setWebhook'], true)) throw new RuntimeException('invalid_archive_operation');
        $curl = curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
        curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>12, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
        try {
            $raw = curl_exec($curl); $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if (!is_string($raw) || curl_errno($curl) !== 0 || strlen($raw) > 1048576) return ['http'=>$http, 'ok'=>false];
            $value = json_decode($raw, true);
            return ['http'=>$http, 'ok'=>($value['ok'] ?? false) === true, 'result'=>$value['result'] ?? null];
        } finally { curl_close($curl); }
    }
}
