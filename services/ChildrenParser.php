<?php

class ChildrenParser
{
    public static function parse(string $text): ?int
    {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower(trim($text), 'UTF-8')
            : strtolower(trim($text));
        $lower = trim(preg_replace('/[.!?]+$/u', '', $lower));

        if (preg_match('/^да(?:\s*[,;:\-]\s*|\s+)(.+)$/ui', $lower, $m)) {
            $lower = trim((string)$m[1]);
        }

        if (preg_match('/^(?:нет|не будет|без детей|детей нет|без ребёнка|без ребенка|0)$/ui', $lower)) {
            return 0;
        }

        if (preg_match('/^(\d)\s*(?:реб[её]нок|реб[её]нка|реб[её]нков|дет(?:ей|и))$/ui', $lower, $m)) {
            $n = (int)$m[1];
            return ($n >= 0 && $n <= 3) ? $n : null;
        }

        // Natural labeled answers to an explicit child-count question, e.g. "Дети, 1 человек".
        // Keep this narrow: require an explicit child label plus a people noun so age phrases such
        // as "дети, 2 года" are not consumed as a child count.
        if (preg_match('/^(?:дети|детей|реб[её]нок|реб[её]нка)\s*[,;:\-]?\s*(\d)\s*(?:человек|человека|чел\.?|реб[её]нок|реб[её]нка|реб[её]нков)$/ui', $lower, $m)) {
            $n = (int)$m[1];
            return ($n >= 0 && $n <= 3) ? $n : null;
        }

        $words = [
            'ноль'=>0,
            'один'=>1, 'одна'=>1, 'одного'=>1,
            'два'=>2, 'двое'=>2, 'двух'=>2,
            'три'=>3, 'трое'=>3, 'трех'=>3, 'трёх'=>3,
        ];

        if (preg_match('/^(ноль|один|одна|одного|два|двое|двух|три|трое|трех|трёх)\s+(?:реб[её]нок|реб[её]нка|реб[её]нков|дет(?:ей|и))$/ui', $lower, $m)) {
            return $words[(string)$m[1]] ?? null;
        }
        if (preg_match('/^(?:реб[её]нок|реб[её]нка|реб[её]нков|дет(?:ей|и))\s+(ноль|один|одна|одного|два|двое|двух|три|трое|трех|трёх)$/ui', $lower, $m)) {
            return $words[(string)$m[1]] ?? null;
        }

        if (preg_match('/^\d+$/', $lower)) {
            $n = (int)$lower;
            return ($n >= 0 && $n <= 3) ? $n : null;
        }

        if (!array_key_exists($lower, $words)) return null;
        return $words[$lower];
    }

    /**
     * Diagnostic only for an answer to the child-count question. The accepted
     * search/storage range stays unchanged; this value must never be applied.
     * Do not infer a count from ages, dates, a mixed party or negated statements.
     */
    public static function unsupportedCount(string $text): ?int
    {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower(trim($text), 'UTF-8')
            : strtolower(trim($text));
        $lower = trim(preg_replace('/[.!?]+$/u', '', $lower));
        $lower = preg_replace('/^(?:да|конечно)(?:\s*[,;:\-]\s*|\s+)/ui', '', $lower);
        $words = ['четыре'=>4, 'четверо'=>4, 'пять'=>5, 'пятеро'=>5,
            'шесть'=>6, 'шестеро'=>6, 'семь'=>7, 'семеро'=>7,
            'восемь'=>8, 'восьмеро'=>8, 'девять'=>9, 'десять'=>10];
        $token = '\d{1,2}|'.implode('|', array_keys($words));
        $noun = 'реб[её]нок|реб[её]нка|реб[её]нков|дет(?:ей|и)';
        $count = null;
        if (preg_match('/\A('.$token.')(?:\s*(?:'.$noun.'))?\z/ui', $lower, $m)
            || preg_match('/\A(?:'.$noun.')\s*[,;:\-]?\s*('.$token.')(?:\s*(?:человек|человека|чел\.?))?\z/ui', $lower, $m)) {
            $count = $words[$m[1]] ?? (int)$m[1];
        }
        return $count !== null && $count > 3 ? $count : null;
    }
}
