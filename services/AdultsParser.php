<?php

class AdultsParser
{
    public static function parse(string $text): ?int
    {
        $lower = self::normalize($text);
        $n = self::numberFromShortText($lower, 1, 6);
        if ($n === null && preg_match('/^(\d)\s*(?:взросл(?:ый|ых|ого)?|человек(?:а)?)$/ui', $lower, $m)) {
            $n = (int)$m[1];
        }
        return ($n !== null && $n >= 1 && $n <= 6) ? $n : null;
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
            'шесть'=>6, 'шестеро'=>6,
        ];
        if (!array_key_exists($text, $words)) return null;
        $n = $words[$text];
        return ($n >= $min && $n <= $max) ? $n : null;
    }
}
