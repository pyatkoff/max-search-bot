<?php

class StarsParser
{
    public static function parse(string $text): ?int
    {
        $lower = self::normalize($text);

        if (preg_match('/^(?:не важно|неважно|любая|любые|все|всё)$/ui', $lower)) {
            return 1;
        }
        if (preg_match('/^(?:от\s*)?([1-5])\s*(?:\*|★|зв[её]зд(?:а|ы)?)?$/ui', $lower, $m)) {
            return (int)$m[1];
        }

        $compact = preg_replace('/\s+/u', '', $lower) ?? $lower;
        if (preg_match('/^[1-5](?:[,;\/\-][1-5])+$/u', $compact)) {
            preg_match_all('/[1-5]/u', $compact, $m);
            $values = array_map('intval', $m[0] ?? []);
            if ($values) return min($values);
        }

        // Natural alternatives such as "4 или 5", "4 или 5 звезд" mean
        // the minimum acceptable hotel category, matching the UI/search semantics.
        if (preg_match('/^([1-5])\s*(?:\*|★|зв[её]зд(?:а|ы)?)?\s+или\s+([1-5])\s*(?:\*|★|зв[её]зд(?:а|ы)?)?$/ui', $lower, $m)) {
            return min((int)$m[1], (int)$m[2]);
        }

        return self::numberFromShortText($lower, 1, 5);
    }

    private static function normalize(string $text): string
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower(trim($text), 'UTF-8') : strtolower(trim($text));
        return trim(preg_replace('/[.!?]+$/u', '', $lower));
    }

    private static function numberFromShortText(string $text, int $min, int $max): ?int
    {
        if (preg_match('/^\d+$/', $text)) {
            $n = (int)$text;
            return ($n >= $min && $n <= $max) ? $n : null;
        }

        $words = [
            'один'=>1, 'одна'=>1, 'одного'=>1,
            'два'=>2, 'двое'=>2, 'двух'=>2,
            'три'=>3, 'трое'=>3, 'трех'=>3, 'трёх'=>3,
            'четыре'=>4, 'четверо'=>4,
            'пять'=>5, 'пятеро'=>5,
        ];
        if (!array_key_exists($text, $words)) return null;
        $n = $words[$text];
        return ($n >= $min && $n <= $max) ? $n : null;
    }
}
