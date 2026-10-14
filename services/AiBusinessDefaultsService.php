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

        // Rich requests bypass the short local fallback. Seed a country only from
        // conservative resort hints when neither AI nor current state supplied one.
        $p = DestinationHintService::seedCountry($p, $userText, $current);

        // Stars and meal are optional hotel refinements. A Turkey/Egypt destination
        // alone is not evidence that the tourist requested 4★ or all inclusive.
        // Explicit AI/user values already present in $p are preserved unchanged.

        return $ai;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    }
}
