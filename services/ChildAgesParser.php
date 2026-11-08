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

        // Explicit months are not completed years. Keep the existing integer-year
        // storage contract, without guessing a birth date or age at a future trip.
        if (preg_match('/месяц|(?<![а-яё])мес/ui', $lower)) {
            return self::parseExplicitMonthAges($lower, $childrenCount);
        }

        // Repeat a year age only when the reply explicitly assigns it to every
        // known child. Keep these phrases out of generic numeric extraction:
        // "обоим" must not silently become one age when the saved count is one.
        if (preg_match('/(?<![\p{L}])(?:обоим|обеим|каждому)(?![\p{L}])|(?<![\p{L}])детям\s+по(?![\p{L}])/ui', $lower)) {
            return self::parseSharedAge($lower, $childrenCount);
        }

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

    /** A complete shared-age answer never changes the known child count. */
    private static function parseSharedAge(string $text, int $childrenCount): ?array
    {
        if (preg_match('/\A(?:обоим|обеим)\s+по\s+(\d{1,2})(?:\s*(?:лет|года?))?\z/ui', $text, $match)) {
            if ($childrenCount !== 2) return null;
        } elseif (preg_match('/\Aдетям\s+по\s+(\d{1,2})\s*(?:лет|года?)\z/ui', $text, $match)) {
            if ($childrenCount < 2) return null;
        } elseif (!preg_match('/\Aкаждому(?:\s+реб[её]нку)?\s+(\d{1,2})\s*(?:лет|года?)\z/ui', $text, $match)) {
            return null;
        }

        $age = (int)$match[1];
        if ($age < 0 || $age > 17) return null;
        return array_fill(0, $childrenCount, $age);
    }

    /** Month-bearing replies require explicit units for every child. */
    private static function parseExplicitMonthAges(string $text, int $childrenCount): ?array
    {
        // Ranges, approximate ages and additional numeric details need clarification.
        if (preg_match('/[+−-]\\s*\\d/u', $text)) return null;
        if (preg_match('/\\b\\d{1,2}\\s*[-–—\\/.]\\s*\\d{1,2}\\b/u', $text)
            || preg_match('/(?<![а-яё])(?:почти|около|примерно|до|через|будет|меньше|больше|не|нет|или|либо|вместо|было|был|была)(?![а-яё])/ui', $text)) return null;

        $monthUnit = '(?:месяц(?:а|ев)?|мес\\.?)(?![а-яё])';
        $yearUnit = '(?:лет|года?|г\\.?)(?![а-яё])';
        $pattern = '/(?<![\\p{L}\\d])(\\d{1,2})\\s*' . $yearUnit
            . '(?:\\s*(?:и\\s+)?(\\d{1,2})\\s*' . $monthUnit . ')?'
            . '|(?<![\\p{L}\\d])(\\d{1,3})\\s*' . $monthUnit . '/ui';
        $ages = [];
        $valid = true;
        $rest = preg_replace_callback($pattern, static function (array $match) use (&$ages, &$valid): string {
            if (isset($match[3]) && $match[3] !== '') {
                $age = intdiv((int)$match[3], 12);
            } else {
                $age = (int)$match[1];
                if (isset($match[2]) && $match[2] !== '' && (int)$match[2] > 11) $valid = false;
            }
            if ($age > 17) $valid = false;
            $ages[] = $age;
            return ' ';
        }, $text);
        if (!$valid || !is_string($rest) || preg_match('/\\d/u', $rest)
            || preg_match('/месяц|(?<![а-яё])мес/ui', $rest)
            || count($ages) !== $childrenCount) return null;
        return $ages;
    }

}
