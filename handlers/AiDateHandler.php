<?php

require_once(__DIR__ . '/../services/DateContextResolver.php');
require_once(__DIR__ . '/../services/NeedApplicationService.php');
require_once(__DIR__ . '/../services/EditFlowService.php');

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
        // A tourist may edit an already selected exact date only to say that it
        // can move. That is manager context, not a new search range. Persist the
        // explicit wish through the canonical need application path, then return
        // the pre-edit exact date so the existing statusDate update/check path can
        // finish without forcing the tourist to select the same date again.
        $preservedDate = self::resolveExplicitFlexibilityEdit($chatId, $text);
        if ($preservedDate !== '') return $preservedDate;

        return DateContextResolver::resolvePendingShortDate($chatId, $text);
    }

    public static function clear($chatId): void
    {
        DateContextResolver::clear($chatId);
    }

    private static function resolveExplicitFlexibilityEdit($chatId, string $text): string
    {
        if (!class_exists('MaxSearchApi') || !method_exists('MaxSearchApi', 'getEditMode')) return '';
        if ((string)MaxSearchApi::getEditMode($chatId) !== 'date') return '';

        $flexibility = NeedApplicationService::resolveAndApply($chatId, 'date_flexibility', $text);
        if (empty($flexibility['recognized']) || empty($flexibility['applied'])) return '';

        $snapshot = EditFlowService::captureSnapshot($chatId, false);
        $previous = (string)($snapshot[MaxSearchApi::$statusDate] ?? '');
        $resolved = NeedValueResolver::resolve('date', $previous);
        return !empty($resolved['recognized']) ? (string)$resolved['value'] : '';
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
