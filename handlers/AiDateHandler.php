<?php

require_once(__DIR__ . '/../services/DateContextResolver.php');

/**
 * Backward-compatible AI-facing wrapper around the shared date-context boundary.
 */
class AiDateHandler
{
    public static function rememberMonthFromText($chatId, string $text): array
    {
        return DateContextResolver::resolveFromText($chatId, $text);
    }

    public static function rememberMonth($chatId, int $month, int $year): void
    {
        DateContextResolver::rememberMonth($chatId, $month, $year);
    }

    public static function resolvePendingShortDate($chatId, string $text): string
    {
        return DateContextResolver::resolvePendingShortDate($chatId, $text);
    }

    public static function clear($chatId): void
    {
        DateContextResolver::clear($chatId);
    }
}

// Временная совместимость с одним оставшимся вызовом в webhook.php.
// Удалим этот wrapper на следующем этапе, когда вынесем весь AI routing.
if (!function_exists('maxSetPendingMonth')) {
    function maxSetPendingMonth($chatId, $month, $year)
    {
        AiDateHandler::rememberMonth($chatId, (int)$month, (int)$year);
    }
}
