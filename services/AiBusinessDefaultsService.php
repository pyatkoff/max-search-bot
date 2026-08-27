<?php

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

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
