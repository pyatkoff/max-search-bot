<?php

class MealParser
{
    /** Returns the canonical AI meal value or null when the phrase is not recognized. */
    public static function parse(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') return null;
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = trim(preg_replace('/[.!?,]+$/u', '', $text));

        // Current search has no separate "room only" filter. Phrases meaning
        // "do not require a meal filter" therefore map to the existing ANY value.
        if (preg_match('/^(?:питание\s+)?(?:не\s+нужно|не\s+важно|неважно|любое|любая|вс[её])$/ui', $text)) return 'any';
        if (preg_match('/^(?:ai|all\s*inclusive|вс[её]\s*включено|включено\s+вс[её])$/ui', $text)) return 'all_inclusive';
        if (preg_match('/^(?:bb|завтрак|завтраки|только\s+завтрак|только\s+завтраки)$/ui', $text)) return 'breakfast';
        if (preg_match('/^(?:hb|полупансион|завтрак\s*(?:\+|и)\s*ужин)$/ui', $text)) return 'half_board';
        if (preg_match('/^(?:fb|полный\s+пансион|завтрак\s*(?:\+|,|и)\s*обед\s*(?:\+|,|и)\s*ужин|завтрак\s+обед\s+ужин)$/ui', $text)) return 'full_board';
        return null;
    }
}
