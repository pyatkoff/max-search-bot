<?php

/**
 * Executable child-age input and storage contract.
 *
 * This class is intentionally not wired into runtime yet. The input method
 * preserves the current StateMessageHandler separator semantics exactly, while
 * the projection method turns a validated integer array into the existing
 * comma-space storage representation.
 */
final class ChildAgeValueContract
{
    public static function parseLegacyInput(string $text, int $childrenCount): ?array
    {
        preg_match('/[^\d\s,]{1,}/', $text, $invalid);
        if (is_array($invalid) && count($invalid) > 0) return null;

        $separator = strpos($text, ',') !== false ? ',' : ' ';
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
