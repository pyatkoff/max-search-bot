<?php

require_once __DIR__ . '/TripStateService.php';

class RulesEngine
{
    public const ASK = 'ASK';
    public const OPEN_SEARCH = 'OPEN_SEARCH';
    public const SHOW_OPTIONS = 'SHOW_OPTIONS';
    public const MANAGER = 'MANAGER';
    public const CHANNEL = 'CHANNEL';
    public const ANSWER = 'ANSWER';
    public const STOP = 'STOP';

    public static function decide(string $intent, array $state, array $context = []): array
    {
        $intent = trim($intent);

        if ($intent === 'stop') return self::result(self::STOP, [], null, 'stop');
        if ($intent === 'manager_request') return self::result(self::MANAGER, [], null, 'explicit_manager_request');
        if (in_array($intent, ['hot_tours','channel_request'], true)) return self::result(self::CHANNEL, [], null, 'channel_intent');

        if ($intent === 'destination_advice') {
            if (empty($state['departure']['city_id'])) {
                return self::result(self::ASK, ['departure_city'], 'departure_city', 'destination_advice_needs_departure');
            }
            return self::result(self::SHOW_OPTIONS, [], null, 'destination_advice');
        }

        if (in_array($intent, ['general_question','hotel_question','price_question'], true)) {
            return self::result(self::ANSWER, [], null, 'consultation');
        }

        $missing = TripStateService::searchMissing($state);
        if ($missing !== []) {
            return self::result(self::ASK, $missing, $missing[0], 'search_missing_fields');
        }

        return self::result(self::OPEN_SEARCH, [], null, 'search_ready');
    }

    public static function questionFor(string $field): string
    {
        $questions = [
            'departure_city' => 'Из какого города планируете вылет?',
            'destination' => 'Куда хотите поехать?',
            'dates' => 'Когда примерно хотите вылететь?',
            'nights' => 'На сколько ночей планируете поездку?',
            'adults' => 'Сколько будет взрослых туристов?',
            'children' => 'Будут дети? Если да — сколько?',
            'children_ages' => 'Сколько лет детям на момент поездки?',
        ];
        return $questions[$field] ?? 'Уточните, пожалуйста, параметры поездки.';
    }

    private static function result(string $action, array $missing, ?string $nextField, string $reason): array
    {
        return [
            'action' => $action,
            'missing' => array_values($missing),
            'next_field' => $nextField,
            'reason' => $reason,
            'ready_for_search' => $action === self::OPEN_SEARCH,
        ];
    }
}
