<?php
require_once __DIR__ . '/AdultsParser.php';
require_once __DIR__ . '/ChildrenParser.php';
require_once __DIR__ . '/ChildAgesParser.php';
require_once __DIR__ . '/StarsParser.php';
require_once __DIR__ . '/MealParser.php';
require_once __DIR__ . '/NightsParser.php';
require_once __DIR__ . '/BudgetParser.php';
require_once __DIR__ . '/DateValueContract.php';

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

        if ($field === 'budget') {
            $parsed = BudgetParser::parse($text);
            return array_merge(self::result($parsed !== null, $parsed['changes'] ?? null, 'deterministic:budget_parser'),
                ['only_budget'=>$parsed['only_budget'] ?? false]);
        }

        if ($field === 'adults') {
            $value = AdultsParser::parse($text);
            return self::result($value !== null, $value, 'deterministic:adults_parser');
        }

        if ($field === 'children') {
            $value = ChildrenParser::parse($text);
            return self::result($value !== null, $value, 'deterministic:children_parser');
        }

        if ($field === 'child_ages') {
            $childrenCount = (int)($context['children'] ?? 0);
            $value = ChildAgesParser::parse($text, $childrenCount);
            return self::result($value !== null, $value, 'deterministic:child_ages_parser');
        }

        if ($field === 'stars') {
            $value = StarsParser::parse($text);
            return self::result($value !== null, $value, 'deterministic:stars_parser');
        }

        if ($field === 'meal') {
            $value = MealParser::parse($text);
            return self::result($value !== null, $value, 'deterministic:meal_parser');
        }

        if ($field === 'nights') {
            $value = NightsParser::parse($text);
            return self::result($value !== '', $value !== '' ? $value : null, 'deterministic:nights_parser');
        }

        if ($field === 'date') {
            $value = DateValueContract::fromStorageValue($text);
            return self::result($value !== null, $value, 'deterministic:date_value_contract');
        }

        if ($field === 'date_flexibility') {
            $value = self::dateFlexibilityChanges($text);
            return self::result($value !== null, $value, 'deterministic:date_flexibility');
        }

        return self::result(false, null, 'unsupported');
    }

    /**
     * Explicit date-flexibility wording is manager context, not a search range.
     * Keep this deliberately narrow: if the same turn contains an actual date,
     * the ordinary date parser owns it and we do not silently keep the old date.
     */
    private static function dateFlexibilityChanges(string $text): ?array
    {
        $text = trim((string)(preg_replace('/\s+/u', ' ', $text) ?? $text));
        if ($text === '') return null;

        if (preg_match('/(?:\b\d{1,2}[.\/-]\d{1,2}(?:[.\/-]\d{2,4})?\b|\b(?:январ\w*|феврал\w*|март\w*|апрел\w*|ма[йя]\w*|июн\w*|июл\w*|август\w*|сентябр\w*|октябр\w*|ноябр\w*|декабр\w*)\b)/ui', $text)) {
            return null;
        }

        $strict = preg_match(
            '/(?:дат(?:а|у|ы)?\s+(?:не\s+)?(?:сдвигать|двигать|переносить)|дат(?:а|ы)?\s+(?:строг\w*|фиксирован\w*)|только\s+(?:эта|эти)\s+дат\w*)/ui',
            $text
        ) === 1;
        if ($strict) return ['preferences_remove'=>['даты можно сдвигать']];

        $flexible = preg_match(
            '/(?:дат(?:а|ы)?\s+(?:можно\s+)?(?:сдвигать|сдвинуть|двигать|подвигать|перенести|гибк\w*)|дат(?:а|ы)?\s+не\s+принципиал\w*|по\s+датам\s+(?:гибк\w*|не\s+принципиал\w*))/ui',
            $text
        ) === 1;
        if ($flexible) return ['preferences'=>['даты можно сдвигать']];

        return null;
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
