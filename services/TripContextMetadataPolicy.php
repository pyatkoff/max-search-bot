<?php

require_once __DIR__ . '/TripBudgetPolicy.php';

/** Pure versioned metadata stored on the existing dialogue start row. */
final class TripContextMetadataPolicy
{
    private const KIND = 'trip_context_v1';
    private const MAX_ITEMS = 10;
    private const MAX_ITEM_CHARS = 120;
    private const MAX_ENVELOPE_BYTES = 8192;

    public static function empty(): array
    {
        return ['budget'=>[], 'preferences'=>[], 'negative_preferences'=>[]];
    }

    /** Accept the previous budget-only envelope and the new context envelope. */
    public static function fromStartValue($raw): ?array
    {
        if ($raw === null || $raw === '') return self::empty();
        if (!is_string($raw) || strlen($raw) > self::MAX_ENVELOPE_BYTES) return null;

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
        if ($preferences === null || $negative === null || array_intersect($preferences, $negative) !== []) return null;
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

    /**
     * Extractor lists are incremental because its contract contains only new-message changes.
     * Removal keys are neutral corrections: they delete an exact active item without turning it
     * into the opposite polarity. Identity ignores harmless letter-case differences while the
     * originally stored/displayed spelling remains untouched.
     */
    public static function applyPreferences(array $context, array $changes): ?array
    {
        $allowed = ['preferences','negative_preferences','preferences_remove','negative_preferences_remove'];
        if (array_diff(array_keys($changes), $allowed) !== []) return null;
        $next = self::normalizeContext($context);
        if ($next === null) return null;

        $incoming = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $changes)) continue;
            $normalized = self::normalizeList($changes[$key]);
            if ($normalized === null) return null;
            $incoming[$key] = $normalized;
        }
        if (self::listsOverlap($incoming['preferences'] ?? [], $incoming['negative_preferences'] ?? [])) return null;
        if (self::listsOverlap($incoming['preferences'] ?? [], $incoming['preferences_remove'] ?? [])) return null;
        if (self::listsOverlap($incoming['negative_preferences'] ?? [], $incoming['negative_preferences_remove'] ?? [])) return null;

        foreach (($incoming['preferences_remove'] ?? []) as $item) {
            $next['preferences'] = self::withoutEquivalent($next['preferences'], $item);
        }
        foreach (($incoming['negative_preferences_remove'] ?? []) as $item) {
            $next['negative_preferences'] = self::withoutEquivalent($next['negative_preferences'], $item);
        }

        foreach (['preferences','negative_preferences'] as $key) {
            if (!array_key_exists($key, $incoming)) continue;
            $opposite = $key === 'preferences' ? 'negative_preferences' : 'preferences';
            foreach ($incoming[$key] as $item) {
                $next[$opposite] = self::withoutEquivalent($next[$opposite], $item);
                if (!self::containsEquivalent($next[$key], $item)) $next[$key][] = $item;
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
        if ($preferences === null || $negative === null || array_intersect($preferences, $negative) !== []) return null;
        return ['budget'=>$budget,'preferences'=>$preferences,'negative_preferences'=>$negative];
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

    private static function identityKey(string $item): string
    {
        $item = trim((string)(preg_replace('/\s+/u', ' ', $item) ?? $item));
        return function_exists('mb_strtolower') ? mb_strtolower($item, 'UTF-8') : strtolower($item);
    }

    private static function containsEquivalent(array $items, string $needle): bool
    {
        $identity = self::identityKey($needle);
        foreach ($items as $item) {
            if (self::identityKey((string)$item) === $identity) return true;
        }
        return false;
    }

    private static function withoutEquivalent(array $items, string $needle): array
    {
        $identity = self::identityKey($needle);
        return array_values(array_filter(
            $items,
            static fn(string $item): bool => self::identityKey($item) !== $identity
        ));
    }

    private static function listsOverlap(array $left, array $right): bool
    {
        foreach ($left as $item) {
            if (self::containsEquivalent($right, (string)$item)) return true;
        }
        return false;
    }
}
