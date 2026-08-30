<?php

declare(strict_types=1);

class TelegramWebhookHealth
{
    public static function collect(?callable $request = null): array
    {
        $token = defined('TELEGRAM_BOT_TOKEN') ? trim((string)TELEGRAM_BOT_TOKEN) : '';
        return self::collectToken($token, $request);
    }

    public static function collectToken(string $token, ?callable $request = null): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok'=>false,'configured'=>false,'reason'=>'missing_token'];
        }

        $request = $request ?: static function (string $method) use ($token): array {
            $ch = curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            return [
                'transport_ok'=>$raw !== false && $errno === 0 && $http >= 200 && $http < 300,
                'http'=>$http,
                'curl_errno'=>$errno,
                'curl_error'=>$error,
                'json'=>is_array($decoded) ? $decoded : null,
            ];
        };

        try {
            $me = $request('getMe');
            $webhook = $request('getWebhookInfo');
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
                ],
            ];
        } catch (Throwable $e) {
            return ['ok'=>false,'configured'=>true,'reason'=>'exception','error'=>$e->getMessage()];
        }
    }
}
