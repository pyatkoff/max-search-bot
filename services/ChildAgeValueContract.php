<?php

/**
 * Executable child-age input and storage contract.
 *
 * Used by StateMessageHandler. The input method retains legacy separator
 * semantics except repeated internal ASCII spaces between ages; those are one
 * separator, not an extra zero-age child. The projection keeps the existing
 * comma-space storage representation.
 */
final class ChildAgeValueContract
{
    public static function parseLegacyInput(string $text, int $childrenCount): ?array
    {
        preg_match('/[^\d\s,]{1,}/', $text, $invalid);
        if (is_array($invalid) && count($invalid) > 0) return null;

        $separator = strpos($text, ',') !== false ? ',' : ' ';
        if ($separator === ' ') $text = preg_replace('/(?<=\d) {2,}(?=\d)/', ' ', $text) ?? $text;
        $parts = explode($separator, $text);
        $ages = [];
        foreach ($parts as $part) {
            $age = intval(trim($part));
            if ($age < 0 || $age > 17) return null;
            $ages[] = $age;
        }

        if (count($ages) !== $childrenCount) return null;
        return $ages;
    }

    /**
     * Read a stored age list only when it exactly matches the current party.
     *
     * A child-count correction may intentionally leave the historical status
     * value untouched. That old value is evidence, not permission to guess
     * which child's age still applies. Consumers therefore fail closed until
     * the exact ages for the corrected positive child count are supplied.
     */
    public static function fromStorage($value, int $childrenCount): ?array
    {
        if ($childrenCount < 0) return null;
        if ($childrenCount === 0) return [];

        if (is_array($value)) {
            $parts = $value;
        } else {
            $raw = trim((string)$value);
            if ($raw === '') return null;
            $parts = preg_split('/\s*[,;]\s*|\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $ages = [];
        foreach ($parts as $part) {
            if (is_int($part)) {
                $age = $part;
            } else {
                $raw = trim((string)$part);
                if (!preg_match('/^\d{1,2}$/D', $raw)) return null;
                $age = (int)$raw;
            }
            if ($age < 0 || $age > 17) return null;
            $ages[] = $age;
        }

        return count($ages) === $childrenCount ? $ages : null;
    }

    public static function toStorage(array $ages, int $childrenCount): ?string
    {
        if ($childrenCount <= 0 || count($ages) !== $childrenCount) return null;

        foreach ($ages as $age) {
            if (!is_int($age) || $age < 0 || $age > 17) return null;
        }

        return implode(', ', array_map('strval', $ages));
    }
}
