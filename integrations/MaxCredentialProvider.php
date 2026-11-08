<?php

declare(strict_types=1);

/** MAX-specific credential access stays at the platform integration boundary. */
final class MaxCredentialProvider
{
    public static function token(): string
    {
        return defined('MAX_SEARCH_TOKEN') ? trim((string) MAX_SEARCH_TOKEN) : '';
    }

    public static function webhookSecret(): string
    {
        return defined('MAX_SEARCH_WEBHOOK_SECRET') ? (string) MAX_SEARCH_WEBHOOK_SECRET : '';
    }

    public static function apiBaseUrl(): string
    {
        return 'https://platform-api2.max.ru';
    }
}
