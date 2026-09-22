<?php

require_once __DIR__ . '/TripBudgetPolicy.php';

/** Pure versioned metadata stored on the existing dialogue start row. */
final class TripContextMetadataPolicy
{
    private const KIND = 'trip_context_v1';
    private const MAX_ITEMS = 10;
    private const MAX_ITEM_CHARS = 120;

    public static function empty(): array
    {
        return ['budget'=>[], 'preferences'=>[], 'negative_preferences'=>[]];
    }

    /** Accept the previous budget-only envelope and the new context envelope. */
    public static function fromStartValue($raw): ?array
    {
        if ($raw === null || $raw === '') return self::empty();
        if (!is_string($raw) || strlen($raw) > 4096) return null;

        $legacyBudget = TripBudgetPolicy::fromStartValue($raw);
        if ($legacyBudget !== null) {
            return ['budget'=>$legacyBudget, 'preferences'=>[], 'negative_preferences'=>[]];
        }

        $value = json_decode($raw, true);
        if (!is_array($value) || ($value['kind'] ?? null) !== self::KIND
            || array_diff(array_keys($value), ['kind','budget','preferences','negative_preferences']) !== []
            || count($value) !== 4 || !is_array($value['budget'] ?? null)) {
            return null;
        }
        if (!self::validBudget($value['budget'])) return null;
        $preferences = self::normalizeList($value['preferences'] ?? null);
        $negative = self::normalizeList($value['negative_preferences'] ?? null);
        if ($preferences === null || $negative === null) return null;
        return ['budget'=>$value['budget'], 'preferences'=>$preferences, 'negative_preferences'=>$negative];
    }

    /** Keep budget-only sessions byte-compatible until wishes are actually stored. */
    public static function toStartValue(array $context): string
    {
        $normalized = self::normalizeContext($context);
        if ($normalized === null) throw new InvalidArgumentException('invalid_trip_context');
        if ($normalized['preferences'] === [] && $normalized['negative_preferences'] === []) {
            if ($normalized['budget'] === []) return '';
            return TripBudgetPolicy::toStartValue($normalized['budget']);
        }
        $raw = json_encode(['kind'=>self::KIND] + $normalized, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if (self::fromStartValue($raw) === null) throw new InvalidArgumentException('invalid_trip_context_envelope');
        return $raw;
    }

    /** Extractor lists are incremental because its contract contains only new-message changes. */
    public static function applyPreferences(array $context, array $changes): ?array
    {
        if (array_diff(array_keys($changes), ['preferences','negative_preferences']) !== []) return null;
        $next = self::normalizeContext($context);
        if ($next === null) return null;
        foreach (['preferences','negative_preferences'] as $key) {
            if (!array_key_exists($key, $changes)) continue;
            $incoming = self::normalizeList($changes[$key]);
            if ($incoming === null) return null;
            foreach ($incoming as $item) {
                if (!in_array($item, $next[$key], true)) $next[$key][] = $item;
            }
            if (count($next[$key]) > self::MAX_ITEMS) return null;
        }
        return $next;
    }

    private static function normalizeContext(array $context): ?array
    {
        if (array_diff(array_keys($context), ['budget','preferences','negative_preferences']) !== []) return null;
        $budget = $context['budget'] ?? [];
        if (!is_array($budget) || !self::validBudget($budget)) return null;
        $preferences = self::normalizeList($context['preferences'] ?? []);
        $negative = self::normalizeList($context['negative_preferences'] ?? []);
        if ($preferences === null || $negative === null) return null;
        return ['budget'=>$budget, 'preferences'=>$preferences, 'negative_preferences'=>$negative];
    }

    private static function validBudget(array $budget): bool
    {
        if ($budget === []) return true;
        try {
            return TripBudgetPolicy::fromStartValue(TripBudgetPolicy::toStartValue($budget)) !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function normalizeList($value): ?array
    {
        if (!is_array($value) || count($value) > self::MAX_ITEMS) return null;
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || !preg_match('//u', $item)) return null;
            $item = trim((string)(preg_replace('/\s+/u', ' ', $item) ?? $item));
            if ($item === '' || preg_match('/[\x00-\x1f\x7f]/u', $item)) return null;
            $length = function_exists('mb_strlen') ? mb_strlen($item, 'UTF-8') : strlen($item);
            if ($length > self::MAX_ITEM_CHARS) return null;
            if (!in_array($item, $out, true)) $out[] = $item;
        }
        return $out;
    }
}
