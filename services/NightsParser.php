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
        $normalized = trim(preg_replace('/[.!?,]+$/u', '', $normalized));

        // A tourist may correct an already stated duration in one turn. Resolve
        // only a narrow explicit "не <old>, а <new>" form, and only when both
        // sides are valid nights values under this same canonical parser. This
        // prevents the rejected value from becoming current while avoiding a
        // broad prose extractor inside the deterministic nights boundary.
        if (preg_match('/^не\s+(.+?)\s*,?\s+а\s+(.+)$/ui', $normalized, $m)) {
            $rejected = self::parse(trim((string)$m[1]));
            if ($rejected === '') return '';
            return self::parse(trim((string)$m[2]));
        }

        if (preg_match('/^(?:на\s+)?недел(?:я|ю|ьку)$/ui', $normalized)) {
            return '7';
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
}