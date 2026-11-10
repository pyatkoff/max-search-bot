<?php

class NightsParser
{
    /**
     * Parse a short answer to the nights question.
     * Returns canonical storage form ("6" or "7-10") or an empty string.
     */
    public static function parse(string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';

        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);
        $normalized = str_replace(['–', '—'], '-', $normalized);
        // A zero typed as Cyrillic/Latin o is eligible only after a digit.
        // The complete short-answer grammar and bounds below still decide.
        $normalized = preg_replace('/(?<=\d)[оo](?![\p{L}\d])/ui', '0', $normalized);

        $normalized = trim(preg_replace('/[.!?,]+$/u', '', $normalized));

        if (preg_match('/^(?:на\s+)?недел(?:я|ю|ьку)$/ui', $normalized)) {
            return '7';
        }

        // Word forms describe only the same complete short answer as digits.
        // Keep a question about a duration with the ordinary dialogue collector.
        $wordNumbers = self::normalizeWordNumbers($normalized);
        if ($wordNumbers !== $normalized && preg_match('/[?？]/u', $text)) return '';
        $normalized = $wordNumbers;

        // Adjacent alternatives have exactly the same choices as this range.
        // Do not widen a noncontiguous "seven or ten" into seven through ten.
        if (preg_match('/^(?:на\s+)?(\d{1,2})\s+или\s+(\d{1,2})(?:\s+ноч(?:ь|и|ей)?)?$/ui', $normalized, $m)) {
            $first = (int)$m[1];
            $second = (int)$m[2];
            if (preg_match('/[?？]/u', $text) || abs($first - $second) !== 1) return '';
            $from = min($first, $second);
            $to = max($first, $second);
            return $from >= 1 && $to <= 28 ? $from.'-'.$to : '';
        }

        // A short approximation is still a definite single duration in the
        // context of the explicit nights question. Keep minimum-only "от N"
        // separate because it does not provide an upper bound.
        if (preg_match('/^(?:(?:на|примерно)\s+)?(\d{1,2})(?:\s*(?:ноч(?:ь|и|ей)?))?$/ui', $normalized, $m)) {
            $value = (int)$m[1];
            return ($value >= 1 && $value <= 28) ? (string)$value : '';
        }

        // Short two-number replies are a natural range answer to the nights question.
        // Accept whitespace, dash, comma and repeated-dot separators. The comma and
        // repeated-dot forms are contextual to this resolver, so "3,4" and live typo
        // "8..9" mean ranges. A single dot/slash (for example "1.10") stays invalid
        // so an accidentally entered date is never reinterpreted as nights. A short
        // day suffix is also accepted for live phrases such as "2,3 д" because the
        // surrounding question already fixes the semantic field to trip duration.
        if (preg_match('/^(?:(?:на|от)\s+)?(\d{1,2})(?:\s*-\s*|\s*,\s*|\s*\.{2,}\s*|\s+)(\d{1,2})(?:\s*(?:ноч(?:ь|и|ей)?|д|дн(?:я|ей)?))?$/ui', $normalized, $m)) {
            $from = (int)$m[1];
            $to = (int)$m[2];
            if ($from >= 1 && $to >= $from && $to <= 28) {
                return $from . '-' . $to;
            }
        }

        return '';
    }

    /** Normalize a finite number vocabulary; the full-answer grammar still decides. */
    private static function normalizeWordNumbers(string $text): string
    {
        $words = [
            1=>'один|одна|одну', 2=>'два|две', 3=>'три', 4=>'четыре',
            5=>'пять', 6=>'шесть', 7=>'семь', 8=>'восемь', 9=>'девять',
            10=>'десять', 11=>'одиннадцать|одинадцать', 12=>'двенадцать',
            13=>'тринадцать', 14=>'четырнадцать', 15=>'пятнадцать',
            16=>'шестнадцать', 17=>'семнадцать', 18=>'восемнадцать',
            19=>'девятнадцать', 20=>'двадцать',
        ];
        // Resolve twenty-one through twenty-eight before their individual words.
        for ($unit = 1; $unit <= 8; $unit++) {
            $text = preg_replace('/(?<![\p{L}\d])двадцать\s+(?:'.$words[$unit].')(?![\p{L}\d])/ui',
                (string)(20 + $unit), $text);
        }
        foreach ($words as $value => $spellings) {
            $text = preg_replace('/(?<![\p{L}\d])(?:'.$spellings.')(?![\p{L}\d])/ui',
                (string)$value, $text);
        }
        return $text;
    }
}
