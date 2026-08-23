<?php

class SearchRequestBuilder
{
    public static function fromTripState(array $state): array
    {
        return array_filter([
            'departure_city_id' => self::nullableInt($state['departure']['city_id'] ?? null),
            'departure_city' => self::nullableString($state['departure']['city'] ?? null),
            'country_id' => self::nullableInt($state['destination']['country_id'] ?? null),
            'country' => self::nullableString($state['destination']['country'] ?? null),
            'region' => self::nullableString($state['destination']['region'] ?? null),
            'resort' => self::nullableString($state['destination']['resort'] ?? null),
            'date_from' => self::nullableString($state['dates']['from'] ?? null),
            'date_to' => self::nullableString($state['dates']['to'] ?? null),
            'nights_min' => self::nullableInt($state['nights']['min'] ?? null),
            'nights_max' => self::nullableInt($state['nights']['max'] ?? null),
            'adults' => self::nullableInt($state['tourists']['adults'] ?? null),
            'children' => self::nullableInt($state['tourists']['children'] ?? null),
            'children_ages' => array_values(array_map('intval', (array)($state['tourists']['children_ages'] ?? []))),
            'budget_max' => self::nullableInt($state['budget']['max'] ?? null),
            'currency' => self::nullableString($state['budget']['currency'] ?? null),
            'stars_min' => self::nullableInt($state['hotel']['stars_min'] ?? null),
            'meal' => self::nullableString($state['hotel']['meal'] ?? null),
            'preferences' => array_values((array)($state['preferences'] ?? [])),
            'negative_preferences' => array_values((array)($state['negative_preferences'] ?? [])),
        ], static function($value, $key) {
            if (in_array($key, ['children','adults','nights_min','nights_max','departure_city_id','country_id','budget_max','stars_min'], true)) return $value !== null;
            if (is_array($value)) return $value !== [];
            return $value !== null && $value !== '';
        }, ARRAY_FILTER_USE_BOTH);
    }

    public static function missingRequired(array $request): array
    {
        $missing = [];
        foreach (['departure_city_id','country_id','date_from','nights_min','adults','children'] as $field) {
            if (!array_key_exists($field, $request) || $request[$field] === null || $request[$field] === '') $missing[] = $field;
        }
        if (($request['children'] ?? 0) > 0 && count((array)($request['children_ages'] ?? [])) < (int)$request['children']) $missing[] = 'children_ages';
        return $missing;
    }

    public static function isReady(array $request): bool
    {
        return self::missingRequired($request) === [];
    }

    private static function nullableInt($value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }

    private static function nullableString($value): ?string
    {
        if ($value === null) return null;
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
