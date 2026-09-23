<?php

require_once __DIR__ . '/IntegrationRegistry.php';
require_once __DIR__ . '/TripBudgetPolicy.php';

/**
 * Optional post-qualification budget UX.
 *
 * This service never creates a required dialogue field or changes search parameters.
 * It only offers a skippable budget clarification after the ordinary required needs
 * are complete, and confirms a budget that was already persisted by the canonical
 * resolver/application path.
 */
final class OptionalBudgetPromptService
{
    public static function sendIfMissing($chatId): bool
    {
        try {
            $context = class_exists('MaxSearchApi') ? (array)MaxSearchApi::getAiSearchContext($chatId) : [];
        } catch (Throwable $e) {
            return false;
        }

        if (self::budgetLine($context) !== null) return false;

        return (bool)IntegrationRegistry::messenger()->send(
            $chatId,
            "💰 Бюджет — необязательно.\n\nЕсли знаете предел, напишите, например: «до 250 тыс.» или «до 90 тыс. на человека». Если не определились, можно сразу открыть туры или обратиться к менеджеру кнопками выше."
        );
    }

    public static function sendSavedConfirmation($chatId): bool
    {
        try {
            $context = class_exists('MaxSearchApi') ? (array)MaxSearchApi::getAiSearchContext($chatId) : [];
        } catch (Throwable $e) {
            $context = [];
        }

        $line = self::budgetLine($context);
        $text = $line !== null
            ? $line . ". Можно использовать кнопки в сообщении с параметрами."
            : "💰 Ограничение по бюджету снято. Можно сразу открыть туры или обратиться к менеджеру кнопками в сообщении с параметрами.";

        return (bool)IntegrationRegistry::messenger()->send($chatId, $text);
    }

    /**
     * A tourist may explicitly decline this one optional clarification without
     * creating another dialogue field or silently changing the search contract.
     * Keep the classifier deliberately narrow: generic preference retractions
     * such as "не важно" belong to their own owner and are not budget skips.
     */
    public static function isExplicitSkipText(string $text): bool
    {
        if (!preg_match('//u', $text)) return false;
        $normalized = trim($text);
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;
        $normalized = trim($normalized, " \t\n\r\0\x0B.,!?…");

        return in_array($normalized, [
            'не знаю',
            'пока не знаю',
            'бюджет не знаю',
            'бюджет пока не знаю',
            'не определился',
            'не определилась',
            'не определились',
            'пока не определился',
            'пока не определилась',
            'пока не определились',
            'не определился с бюджетом',
            'не определилась с бюджетом',
            'не определились с бюджетом',
            'с бюджетом не определился',
            'с бюджетом не определилась',
            'с бюджетом не определились',
            'по бюджету не определился',
            'по бюджету не определилась',
            'по бюджету не определились',
        ], true);
    }

    public static function sendSkippedConfirmation($chatId): bool
    {
        return (bool)IntegrationRegistry::messenger()->send(
            $chatId,
            "💰 Хорошо, бюджет пока не фиксируем. Можно сразу открыть туры или обратиться к менеджеру кнопками в сообщении с параметрами."
        );
    }

    public static function budgetLine(array $context): ?string
    {
        $budget = is_array($context['budget'] ?? null) ? $context['budget'] : [];
        $amount = TripBudgetPolicy::amount($budget['max'] ?? null);
        if ($amount === null) return null;

        $currency = TripBudgetPolicy::currency($budget['currency'] ?? 'RUB');
        $basis = TripBudgetPolicy::basis($budget);
        if ($currency === null || $basis === null) return null;

        $precision = floor((float)$amount) == $amount ? 0 : 2;
        $basisLabel = $basis === 'per_person' ? 'на человека' : 'на всех';

        return '💰 Бюджет сохранён: до '
            . number_format((float)$amount, $precision, '.', ' ')
            . ' ' . $currency . ' ' . $basisLabel;
    }
}
