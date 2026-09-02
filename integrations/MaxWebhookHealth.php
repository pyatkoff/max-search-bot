<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/MaxTlsConfig.php';

/**
 * Read-only MAX webhook/subscription health boundary.
 * Healthy means exactly one subscription and it is the configured canonical URL.
 */
final class MaxWebhookHealth
{
    private const API = 'https://platform-api2.max.ru';
    private const DEFAULT_WEBHOOK = 'https://app.anytoour.ru/webhook.php';

    public static function collect(?callable $request = null): array
    {
        $token = defined('MAX_SEARCH_TOKEN') ? trim((string) MAX_SEARCH_TOKEN) : '';
        $expected = defined('MAX_SEARCH_WEBHOOK_URL') && trim((string) MAX_SEARCH_WEBHOOK_URL) !== ''
            ? trim((string) MAX_SEARCH_WEBHOOK_URL)
            : self::DEFAULT_WEBHOOK;

        if ($token === '') {
            return ['ok'=>false,'configured'=>false,'reason'=>'missing_token','expected_url'=>$expected];
        }

        $request = $request ?? static function (string $url, string $token): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => ['Authorization: ' . $token, 'Accept: application/json'],
            ] + MaxTlsConfig::curlOptions(false));
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['http'=>$http,'errno'=>$errno,'body'=>is_string($body)?$body:''];
        };

        try {
            $response = (array) $request(self::API . '/subscriptions', $token);
        } catch (Throwable $e) {
            return ['ok'=>false,'configured'=>true,'reason'=>'request_exception','expected_url'=>$expected];
        }
        return self::evaluate($response, $expected);
    }

    public static function evaluate(array $response, string $expectedUrl): array
    {
        $http = (int)($response['http'] ?? 0);
        $errno = (int)($response['errno'] ?? 0);
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if ($errno !== 0 || $http < 200 || $http >= 300 || !is_array($decoded)) {
            return [
                'ok'=>false,'configured'=>true,
                'reason'=>$errno!==0?'transport_error':'invalid_api_response',
                'http_status'=>$http,'expected_url'=>$expectedUrl,
            ];
        }

        $rows = isset($decoded['subscriptions']) && is_array($decoded['subscriptions'])
            ? $decoded['subscriptions']
            : (array_is_list($decoded) ? $decoded : []);
        $urls = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $url = trim((string)($row['url'] ?? ''));
            if ($url !== '') $urls[] = $url;
        }
        $urls = array_values(array_unique($urls));
        $expectedPresent = in_array($expectedUrl, $urls, true);
        $extra = array_values(array_filter($urls, static fn(string $url): bool => $url !== $expectedUrl));
        $ok = $expectedPresent && count($urls) === 1;
        $reason = $ok
            ? 'healthy'
            : ($expectedPresent ? 'extra_subscriptions' : 'expected_subscription_missing');

        return [
            'ok'=>$ok,
            'configured'=>true,
            'reason'=>$reason,
            'http_status'=>$http,
            'expected_url'=>$expectedUrl,
            'expected_present'=>$expectedPresent,
            'subscription_count'=>count($urls),
            'subscription_urls'=>$urls,
            'extra_subscription_urls'=>$extra,
            'unexpected_subscription_urls'=>$extra,
        ];
    }
}
