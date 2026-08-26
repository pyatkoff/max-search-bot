<?php
require_once __DIR__ . '/MealParser.php';
require_once __DIR__ . '/NightsParser.php';

/**
 * Canonical deterministic need-value resolution boundary.
 *
 * Result contract:
 * - recognized: whether this resolver confidently recognized the value
 * - value: canonical value suitable for the existing application layer
 * - source: stable resolver identifier for diagnostics
 * - confidence: deterministic confidence in the recognized value
 *
 * Fields are migrated here incrementally so existing parser behavior can remain
 * unchanged while callers stop owning parallel parsing decisions.
 */
class NeedValueResolver
{
    public static function resolve(string $field, string $text, array $context = []): array
    {
        $field = trim($field);

        if ($field === 'meal') {
            $value = MealParser::parse($text);
            return self::result($value !== null, $value, 'deterministic:meal_parser');
        }

        if ($field === 'nights') {
            $value = NightsParser::parse($text);
            return self::result($value !== '', $value !== '' ? $value : null, 'deterministic:nights_parser');
        }

        return self::result(false, null, 'unsupported');
    }

    private static function result(bool $recognized, $value, string $source): array
    {
        return [
            'recognized' => $recognized,
            'value' => $recognized ? $value : null,
            'source' => $source,
            'confidence' => $recognized ? 1.0 : 0.0,
        ];
    }
}
