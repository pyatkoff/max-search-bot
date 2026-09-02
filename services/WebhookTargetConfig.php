<?php

declare(strict_types=1);

final class WebhookTargetConfig
{
    public static function telegram(): string
    {
        return self::configuredUrl(
            'TELEGRAM_WEBHOOK_URL',
            'https://app.anytoour.ru/telegram_webhook.php'
        );
    }

    public static function max(): string
    {
        return self::configuredUrl(
            'MAX_SEARCH_WEBHOOK_URL',
            'https://app.anytoour.ru/webhook.php'
        );
    }

    private static function configuredUrl(string $constant, string $fallback): string
    {
        $value = defined($constant) ? trim((string)constant($constant)) : '';
        $url = $value !== '' ? $value : $fallback;

        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || trim((string)($parts['host'] ?? '')) === '') {
            throw new RuntimeException('invalid_webhook_target:' . $constant);
        }

        return rtrim($url, '/');
    }
}
