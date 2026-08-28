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

        if (preg_match('/^(?:на\s+)?недел(?:я|ю|ьку)$/ui', $normalized)) {
            return '7';
        }

        if (preg_match('/^(?:на\s+)?(\d{1,2})(?:\s*(?:ноч(?:ь|и|ей)?))?$/ui', $normalized, $m)) {
            $value = (int)$m[1];
            return ($value >= 1 && $value <= 28) ? (string)$value : '';
        }

        // A short two-number answer such as "8 9" is a natural range reply to the
        // nights question. Accept whitespace as a range separator alongside a dash,
        // but keep dotted/slashed values (for example "1.10") invalid so an
        // accidentally entered date is never reinterpreted as nights.
        if (preg_match('/^(?:(?:на|от)\s+)?(\d{1,2})(?:\s*-\s*|\s+)(\d{1,2})(?:\s*(?:ноч(?:ь|и|ей)?))?$/ui', $normalized, $m)) {
            $from = (int)$m[1];
            $to = (int)$m[2];
            if ($from >= 1 && $to >= $from && $to <= 28) {
                return $from . '-' . $to;
            }
        }

        return '';
    }
}
