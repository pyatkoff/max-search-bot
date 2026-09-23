<?php

class ChildAgesParser
{
    public static function parse(string $text, int $childrenCount): ?array
    {
        if ($childrenCount <= 0) return null;

        $lower = function_exists('mb_strtolower')
            ? mb_strtolower(trim($text), 'UTF-8')
            : strtolower(trim($text));
        $lower = trim(preg_replace('/[.!?]+$/u', '', $lower));

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
