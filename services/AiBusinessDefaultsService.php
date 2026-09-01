<?php
require_once __DIR__ . '/DestinationHintService.php';

class AiBusinessDefaultsService
{
    public static function apply(array $ai, string $userText, array $current): array
    {
        if (!empty($ai['_error'])) {
            return $ai;
        }

        if (!isset($ai['parameters']) || !is_array($ai['parameters'])) {
            $ai['parameters'] = [];
        }

        $p =& $ai['parameters'];

        if (empty($p['city']) && empty($current['city'])) {
            $p['city'] = 'Москва';
        }

        $text = self::lower($userText);
        if (
            strpos($text, 'вдвоём') !== false ||
            strpos($text, 'вдвоем') !== false ||
            strpos($text, 'на двоих') !== false
        ) {
            if (empty($p['adults']) && empty($current['adults'])) {
                $p['adults'] = 2;
            }
            if (
                (!isset($p['children']) || $p['children'] === null || $p['children'] === '') &&
                !array_key_exists('children', $current)
            ) {
                $p['children'] = 0;
            }
        }

        // Rich AI requests can occasionally preserve children=1 while dropping an
        // explicitly stated singleton age (live: "2 взрослых и ребёнок 6 лет").
        // Recover only that unambiguous shape; never override an AI/current age or
        // infer ages for multi-child composition.
        self::recoverExplicitSingleChildAge($p, $userText, $current);

        // Rich requests bypass the short local fallback. Seed a country only from
        // conservative resort hints when neither AI nor current state supplied one.
        $p = DestinationHintService::seedCountry($p, $userText, $current);

        $country = trim((string)($p['country'] ?? ($current['country'] ?? '')));
        $countryKey = self::lower($country);
        if (in_array($countryKey, ['турция', 'египет'], true)) {
            if (empty($p['meal']) && empty($current['meal'])) {
                $p['meal'] = 'all_inclusive';
            }
            if (empty($p['stars']) && empty($current['stars'])) {
                $p['stars'] = 4;
            }
        }

        return $ai;
    }

    private static function recoverExplicitSingleChildAge(array &$params, string $userText, array $current): void
    {
        if (!empty($params['child_ages']) || !empty($current['child_ages'])) {
            return;
        }

        $effectiveChildren = $params['children'] ?? ($current['children'] ?? null);
        if ($effectiveChildren !== null && (int)$effectiveChildren !== 1) {
            return;
        }

        if (!preg_match('/\bреб[её]н(?:ок|ка)\s+(\d{1,2})\s*(?:лет|год(?:а)?)\b/ui', $userText, $m)) {
            return;
        }

        $age = (int)$m[1];
        if ($age < 0 || $age > 17) {
            return;
        }

        if ($effectiveChildren === null) {
            $params['children'] = 1;
        }
        $params['child_ages'] = [$age];
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
