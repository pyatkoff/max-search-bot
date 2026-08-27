<?php

class ChildrenParser
{
    public static function parse(string $text): ?int
    {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower(trim($text), 'UTF-8')
            : strtolower(trim($text));
        $lower = trim(preg_replace('/[.!?]+$/u', '', $lower));

        if (preg_match('/^(?:нет|не будет|без детей|детей нет|без ребёнка|без ребенка|0)$/ui', $lower)) {
            return 0;
        }

        if (preg_match('/^(\d)\s*(?:реб[её]нок|реб[её]нка|реб[её]нков|дет(?:ей|и))$/ui', $lower, $m)) {
            $n = (int)$m[1];
            return ($n >= 0 && $n <= 3) ? $n : null;
        }

        if (preg_match('/^\d+$/', $lower)) {
            $n = (int)$lower;
            return ($n >= 0 && $n <= 3) ? $n : null;
        }

        $words = [
            'ноль'=>0,
            'один'=>1, 'одна'=>1, 'одного'=>1,
            'два'=>2, 'двое'=>2, 'двух'=>2,
            'три'=>3, 'трое'=>3, 'трех'=>3, 'трёх'=>3,
        ];
        if (!array_key_exists($lower, $words)) return null;

        return $words[$lower];
    }
}
