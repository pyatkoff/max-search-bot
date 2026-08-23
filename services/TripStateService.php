<?php

class TripStateService
{
    /**
     * Build the new structured trip state on top of the existing HL-backed saved data.
     * This is intentionally read-only: no storage migration is required yet.
     */
    public static function fromSaved(
        array $saved,
        array $status,
        callable $cityById,
        callable $countryById
    ): array {
        $cityId = self::intOrNull($saved[$status['city']] ?? null);
        $countryId = self::intOrNull($saved[$status['country']] ?? null);

        $city = null;
        if ($cityId !== null) {
            $resolved = $cityById($cityId);
            if ($resolved !== false && trim((string)$resolved) !== '') $city = (string)$resolved;
        }

        $country = null;
        if ($countryId !== null) {
            $resolved = $countryById($countryId);
            if ($resolved !== false && trim((string)$resolved) !== '') $country = (string)$resolved;
        }

        $date = self::stringOrNull($saved[$status['date']] ?? null);
        $nights = self::parseRange($saved[$status['nights']] ?? null, 1, 28);
        $ages = self::parseAges($saved[$status['child_ages']] ?? null);
        $childrenKnown = array_key_exists($status['children'], $saved);
        $children = $childrenKnown ? (int)$saved[$status['children']] : null;

        return [
            'departure' => [
                'city_id' => $cityId,
                'city' => $city,
            ],
            'destination' => [
                'country_id' => $countryId,
                'country' => $country,
                'region' => null,
                'resort' => null,
            ],
            'dates' => [
                'from' => $date,
                'to' => $date,
                'month' => self::monthFromDate($date),
                'flexible_days' => $date ? 3 : 0,
            ],
            'nights' => [
                'min' => $nights['min'],
                'max' => $nights['max'],
            ],
            'tourists' => [
                'adults' => self::intOrNull($saved[$status['adults']] ?? null),
                'children' => $children,
                'children_ages' => $ages,
            ],
            'budget' => [
                'max' => null,
                'currency' => 'RUB',
            ],
            'hotel' => [
                'stars_min' => self::intOrNull($saved[$status['stars']] ?? null),
                'meal' => self::mealFromStorage($saved[$status['meal']] ?? null),
                'line' => null,
            ],
            'preferences' => [],
            'negative_preferences' => [],
            'meta' => [
                'storage' => 'legacy_hl',
                'version' => 1,
            ],
        ];
    }

    /** Flat compatibility view for the current AiRouter while migration is gradual. */
    public static function toLegacyAiContext(array $state): array
    {
        $out = [];
        if (!empty($state['departure']['city'])) $out['city'] = $state['departure']['city'];
        if (!empty($state['destination']['country'])) $out['country'] = $state['destination']['country'];
        if ($state['tourists']['adults'] !== null) $out['adults'] = (int)$state['tourists']['adults'];
        if ($state['tourists']['children'] !== null) $out['children'] = (int)$state['tourists']['children'];
        if (!empty($state['tourists']['children_ages'])) $out['child_ages'] = implode(', ', $state['tourists']['children_ages']);
        if ($state['hotel']['stars_min'] !== null) $out['stars'] = (int)$state['hotel']['stars_min'];
        if (!empty($state['hotel']['meal'])) $out['meal'] = $state['hotel']['meal'];
        if ($state['nights']['min'] !== null) {
            $out['nights'] = $state['nights']['min'] === $state['nights']['max']
                ? (string)$state['nights']['min']
                : ($state['nights']['min'] . '-' . $state['nights']['max']);
        }
        if (!empty($state['dates']['from'])) $out['date'] = $state['dates']['from'];
        return $out;
    }

    public static function searchMissing(array $state): array
    {
        $missing = [];
        if (empty($state['departure']['city_id'])) $missing[] = 'departure_city';
        if (empty($state['destination']['country_id'])) $missing[] = 'destination';
        if (empty($state['dates']['from'])) $missing[] = 'dates';
        if ($state['nights']['min'] === null) $missing[] = 'nights';
        if ($state['tourists']['adults'] === null) $missing[] = 'adults';
        if ($state['tourists']['children'] === null) {
            $missing[] = 'children';
        } elseif ((int)$state['tourists']['children'] > 0 && count($state['tourists']['children_ages']) < (int)$state['tourists']['children']) {
            $missing[] = 'children_ages';
        }
        return $missing;
    }

    public static function isSearchReady(array $state): bool
    {
        return self::searchMissing($state) === [];
    }

    private static function mealFromStorage($value): ?string
    {
        if ($value === null || $value === '') return null;
        $map = ['999'=>'any','7'=>'all_inclusive','3'=>'breakfast','4'=>'half_board','5'=>'full_board'];
        return $map[(string)$value] ?? null;
    }

    private static function parseRange($value, int $minAllowed, int $maxAllowed): array
    {
        $empty = ['min'=>null, 'max'=>null];
        if ($value === null || trim((string)$value) === '') return $empty;
        if (!preg_match('/^(\d{1,2})(?:\s*[-–—]\s*(\d{1,2}))?$/u', trim((string)$value), $m)) return $empty;
        $a = (int)$m[1];
        $b = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $a;
        if ($a < $minAllowed || $b > $maxAllowed || $a > $b) return $empty;
        return ['min'=>$a, 'max'=>$b];
    }

    private static function parseAges($value): array
    {
        if ($value === null || trim((string)$value) === '') return [];
        preg_match_all('/\b(\d{1,2})\b/u', (string)$value, $m);
        $ages = [];
        foreach ($m[1] ?? [] as $age) {
            $age = (int)$age;
            if ($age >= 0 && $age <= 17) $ages[] = $age;
        }
        return $ages;
    }

    private static function monthFromDate(?string $date): ?string
    {
        if (!$date || !preg_match('/^\d{2}\.(\d{2})\.(\d{4})$/', $date, $m)) return null;
        return $m[2] . '-' . $m[1];
    }

    private static function intOrNull($value): ?int
    {
        if ($value === null || $value === '') return null;
        return (int)$value;
    }

    private static function stringOrNull($value): ?string
    {
        if ($value === null) return null;
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
