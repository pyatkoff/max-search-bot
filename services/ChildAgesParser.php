<?php

class ChildAgesParser
{
    public static function parse(string $text, int $childrenCount): ?array
    {
        if ($childrenCount <= 0) return null;

        $lower = self::normalize($text);

        // For an explicit correction, accept only the positive replacement and
        // only when both sides independently satisfy the existing age grammar.
        // This keeps corrections deterministic without turning a rejected age
        // into the active value.
        if (preg_match('/^не\s+(.+?)\s*[,;]?\s+а\s+(.+)$/ui', $lower, $m)) {
            $rejected = self::parseDirect(trim((string)$m[1]), $childrenCount);
            $replacement = self::parseDirect(trim((string)$m[2]), $childrenCount);
            if ($rejected === null || $replacement === null) return null;
            return $replacement;
        }

        // A rejected-only age is not a value. Keep it unresolved so the caller
        // can ask for the actual replacement instead of silently restoring it.
        if (preg_match('/\bне\s+(?=\d)/u', $lower)) return null;

        return self::parseDirect($lower, $childrenCount);
    }

    private static function normalize(string $text): string
    {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower(trim($text), 'UTF-8')
            : strtolower(trim($text));
        return trim(preg_replace('/[.!?]+$/u', '', $lower));
    }

    private static function parseDirect(string $lower, int $childrenCount): ?array
    {
        // A pair written as a date/range is ambiguous in the age step. Keep the
        // resolver fail-closed rather than silently turning "5-7" or "05.07"
        // into two child ages. Natural age phrasing such as "5 и 12" remains
        // deterministic because only the numeric age tokens are projected.
        if (preg_match('/\b\d{1,2}\s*[-–—\/.]\s*\d{1,2}\b/u', $lower)) return null;

        preg_match_all('/\b(\d{1,2})\b/u', $lower, $m);
        $ages = array_map('intval', $m[1] ?? []);
        foreach ($ages as $age) {
            if ($age < 0 || $age > 17) return null;
        }
        if (count($ages) !== $childrenCount) return null;

        return $ages;
    }
}
