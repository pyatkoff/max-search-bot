<?php

declare(strict_types=1);

class TelegramWebhookHealth
{
    public static function collect(?callable $request = null, bool $retryTransientReads = false): array
    {
        $token = defined('TELEGRAM_BOT_TOKEN') ? trim((string)TELEGRAM_BOT_TOKEN) : '';
        return self::collectToken($token, $request, $retryTransientReads);
    }

    public static function collectToken(string $token, ?callable $request = null, bool $retryTransientReads = false): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok'=>false,'configured'=>false,'reason'=>'missing_token'];
        }

        $request = $request ?: static function (string $method, int $timeoutMs = 15000) use ($token): array {
            $ch = curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min(5000, $timeoutMs));
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            return [
                'transport_ok'=>$raw !== false && $errno === 0 && $http >= 200 && $http < 300,
                'http'=>$http,
                'curl_errno'=>$errno,
                'json'=>is_array($decoded) ? $decoded : null,
            ];
        };

        try {
            // Only the production smoke opts in. Existing status readers keep
            // their single-request behavior; no mutating method can be retried.
            $me = $retryTransientReads ? self::readProbe($request, 'getMe') : $request('getMe');
            $webhook = $retryTransientReads ? self::readProbe($request, 'getWebhookInfo') : $request('getWebhookInfo');
            $meJson = is_array($me['json'] ?? null) ? $me['json'] : [];
            $whJson = is_array($webhook['json'] ?? null) ? $webhook['json'] : [];
            $meResult = is_array($meJson['result'] ?? null) ? $meJson['result'] : [];
            $whResult = is_array($whJson['result'] ?? null) ? $whJson['result'] : [];
            $meOk = !empty($me['transport_ok']) && !empty($meJson['ok']);
            $whOk = !empty($webhook['transport_ok']) && !empty($whJson['ok']);

            return [
                'ok'=>$meOk && $whOk,
                'configured'=>true,
                'bot'=>[
                    'id'=>$meResult['id'] ?? null,
                    'username'=>(string)($meResult['username'] ?? ''),
                    'first_name'=>(string)($meResult['first_name'] ?? ''),
                ],
                'webhook'=>[
                    'url'=>(string)($whResult['url'] ?? ''),
                    'pending_update_count'=>(int)($whResult['pending_update_count'] ?? 0),
                    'last_error_date'=>isset($whResult['last_error_date']) ? (int)$whResult['last_error_date'] : null,
                    'last_error_message'=>(string)($whResult['last_error_message'] ?? ''),
                    'max_connections'=>isset($whResult['max_connections']) ? (int)$whResult['max_connections'] : null,
                    'allowed_updates'=>array_values((array)($whResult['allowed_updates'] ?? [])),
                    'has_custom_certificate'=>(bool)($whResult['has_custom_certificate'] ?? false),
                ],
                'transport'=>[
                    'get_me_http'=>(int)($me['http'] ?? 0),
                    'get_webhook_info_http'=>(int)($webhook['http'] ?? 0),
                ] + self::transportSummary($me, 'get_me') + self::transportSummary($webhook, 'get_webhook_info'),
            ];
        } catch (Throwable $e) {
            return ['ok'=>false,'configured'=>true,'reason'=>'exception','error'=>'probe_exception'];
        }
    }

    /** Bounded, read-only retry; the original 15-second transfer budget is shared. */
    private static function readProbe(callable $request, string $method, ?callable $clock = null): array
    {
        if (!in_array($method, ['getMe', 'getWebhookInfo'], true)) {
            throw new InvalidArgumentException('unsupported_health_read');
        }
        $clock = $clock ?: static fn(): float => hrtime(true) / 1000000;
        $deadline = $clock() + 15000;
        $attempts = [];
        $result = ['transport_ok'=>false, 'http'=>0, 'curl_errno'=>28, 'json'=>null];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $remaining = min(15000, (int)floor($deadline - $clock()));
            // Zero would disable cURL's timeout, so never pass it to transport.
            if ($remaining <= 0) break;
            $result = $request($method, $remaining);
            $attempts[] = self::safeAttempt($result);
            // Only no-response DNS/connect/timeout/empty/send/receive errors.
            // HTTP errors (including 429/5xx), TLS failures, API refusals,
            // invalid JSON and unknown exceptions are never retried here.
            if (!empty($result['transport_ok']) || (int)($result['http'] ?? 0) !== 0
                || !in_array((int)($result['curl_errno'] ?? 0), [6, 7, 28, 52, 55, 56], true)) break;
        }
        $result['_health_attempts'] = $attempts;
        return $result;
    }

    /** Never copy error text, URLs, tokens or API response bodies into diagnostics. */
    private static function safeAttempt(array $result): array
    {
        $errno = (int)($result['curl_errno'] ?? 0);
        $http = (int)($result['http'] ?? 0);
        $kind = match ($errno) {
            6 => 'dns', 7 => 'connect', 28 => 'timeout', 52 => 'empty_reply',
            55 => 'send', 56 => 'receive',
            35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 => 'tls',
            0 => 'none', default => 'transport_error',
        };
        if ($errno === 0) {
            if ($http < 200 || $http >= 300) $kind = $http === 0 ? 'no_response' : 'http_error';
            elseif (empty($result['transport_ok'])) $kind = 'transport_error';
            elseif (!is_array($result['json'] ?? null)) $kind = 'invalid_json';
            elseif (empty($result['json']['ok'])) $kind = 'api_rejected';
        }
        return ['http'=>$http, 'curl_errno'=>$errno, 'failure_kind'=>$kind];
    }

    private static function transportSummary(array $result, string $prefix): array
    {
        $attempts = $result['_health_attempts'] ?? [self::safeAttempt($result)];
        $last = self::safeAttempt($result);
        return [
            $prefix.'_curl_errno'=>$last['curl_errno'],
            $prefix.'_failure_kind'=>$last['failure_kind'],
            $prefix.'_attempts'=>count($attempts),
            $prefix.'_recovered'=>count($attempts) > 1 && $last['failure_kind'] === 'none',
            $prefix.'_prior_failures'=>array_slice($attempts, 0, -1),
        ];
    }
}
