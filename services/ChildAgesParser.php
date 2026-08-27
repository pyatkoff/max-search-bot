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

        preg_match_all('/\b(\d{1,2})\b/u', $lower, $m);
        $ages = array_map('intval', $m[1] ?? []);
        foreach ($ages as $age) {
            if ($age < 0 || $age > 17) return null;
        }
        if (count($ages) !== $childrenCount) return null;

        return $ages;
    }
}
