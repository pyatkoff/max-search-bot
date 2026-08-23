<?php

class TripStateMerger
{
    /**
     * Apply extractor changes using stable dot paths. Unknown paths are ignored.
     * The merger is pure and never writes to storage.
     */
    public static function merge(array $state, array $changes): array
    {
        foreach ($changes as $path => $value) {
            $path = self::normalizePath((string)$path);
            if ($path === null) continue;
            self::set($state, $path, $value);
        }

        self::normalizeTourists($state);
        self::normalizeRanges($state);
        self::normalizeLists($state);
        return $state;
    }

    public static function allowedPaths(): array
    {
        return [
            'departure.city_id', 'departure.city',
            'destination.country_id', 'destination.country', 'destination.region', 'destination.resort',
            'dates.from', 'dates.to', 'dates.month', 'dates.flexible_days',
            'nights.min', 'nights.max',
            'tourists.adults', 'tourists.children', 'tourists.children_ages',
            'budget.max', 'budget.currency',
            'hotel.stars_min', 'hotel.meal', 'hotel.line',
            'preferences', 'negative_preferences',
        ];
    }

    private static function normalizePath(string $path): ?string
    {
        $aliases = [
            'city'=>'departure.city',
            'country'=>'destination.country',
            'adults'=>'tourists.adults',
            'children'=>'tourists.children',
            'child_ages'=>'tourists.children_ages',
            'stars'=>'hotel.stars_min',
            'meal'=>'hotel.meal',
            'date'=>'dates.from',
            'nights'=>'nights.min',
            'budget'=>'budget.max',
        ];
        $path = $aliases[$path] ?? $path;
        return in_array($path, self::allowedPaths(), true) ? $path : null;
    }

    private static function set(array &$state, string $path, $value): void
    {
        $parts = explode('.', $path);
        if (count($parts) === 1) {
            $state[$parts[0]] = $value;
            return;
        }
        if (!isset($state[$parts[0]]) || !is_array($state[$parts[0]])) $state[$parts[0]] = [];
        $state[$parts[0]][$parts[1]] = $value;

        if ($path === 'dates.from' && empty($state['dates']['to'])) $state['dates']['to'] = $value;
        if ($path === 'nights.min' && empty($state['nights']['max'])) $state['nights']['max'] = $value;
    }

    private static function normalizeTourists(array &$state): void
    {
        foreach (['adults','children'] as $key) {
            if (!array_key_exists($key, $state['tourists'] ?? []) || $state['tourists'][$key] === null || $state['tourists'][$key] === '') continue;
            $state['tourists'][$key] = (int)$state['tourists'][$key];
        }
        if (($state['tourists']['children'] ?? null) === 0) $state['tourists']['children_ages'] = [];
        if (isset($state['tourists']['children_ages']) && !is_array($state['tourists']['children_ages'])) {
            preg_match_all('/\b(\d{1,2})\b/u', (string)$state['tourists']['children_ages'], $m);
            $state['tourists']['children_ages'] = array_map('intval', $m[1] ?? []);
        }
    }

    private static function normalizeRanges(array &$state): void
    {
        if (isset($state['nights']['min']) && $state['nights']['min'] !== null) $state['nights']['min'] = (int)$state['nights']['min'];
        if (isset($state['nights']['max']) && $state['nights']['max'] !== null) $state['nights']['max'] = (int)$state['nights']['max'];
        if (($state['nights']['min'] ?? null) !== null && ($state['nights']['max'] ?? null) === null) {
            $state['nights']['max'] = $state['nights']['min'];
        }
        if (($state['nights']['min'] ?? null) !== null && ($state['nights']['max'] ?? null) !== null && $state['nights']['min'] > $state['nights']['max']) {
            [$state['nights']['min'], $state['nights']['max']] = [$state['nights']['max'], $state['nights']['min']];
        }
    }

    private static function normalizeLists(array &$state): void
    {
        foreach (['preferences','negative_preferences'] as $key) {
            if (!isset($state[$key])) $state[$key] = [];
            if (!is_array($state[$key])) $state[$key] = [$state[$key]];
            $state[$key] = array_values(array_unique(array_filter(array_map(static function ($v) {
                return trim((string)$v);
            }, $state[$key]), static function ($v) { return $v !== ''; })));
        }
    }
}
