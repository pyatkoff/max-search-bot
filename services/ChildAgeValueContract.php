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

    public static function toStorage(array $ages, int $childrenCount): ?string
    {
        if ($childrenCount <= 0 || count($ages) !== $childrenCount) return null;

        foreach ($ages as $age) {
            if (!is_int($age) || $age < 0 || $age > 17) return null;
        }

        return implode(', ', array_map('strval', $ages));
    }
}
