<?php

require_once __DIR__ . '/../handlers/AiDateHandler.php';

/**
 * AI-facing policy around the shared stateful date context.
 * Keeps month-only clarification and the AI date guard out of AiMessageHandler.
 */
class AiDateContextService
{
    public static function resolveLocal($chatId, string $text): array
    {
        $resolved = AiDateHandler::rememberMonthFromText($chatId, $text);

        return [
            'date' => !empty($resolved['date']) ? (string)$resolved['date'] : '',
            'month_only' => !empty($resolved['month']) && empty($resolved['date']),
        ];
    }

    public static function applyAiGuard($chatId, string $text, array $params): array
    {
        $resolved = AiDateHandler::rememberMonthFromText($chatId, $text);

        if (!empty($resolved['date'])) {
            $params['date'] = $resolved['date'];
        } elseif (!empty($resolved['month'])) {
            // User named a month without an exact day/period: do not accept an AI-invented date.
            $params['date'] = null;
        } elseif (!empty($params['date'])) {
            // An AI date is allowed only when the user did not name a conflicting month.
            AiDateHandler::clear($chatId);
        }

        return $params;
    }
}
