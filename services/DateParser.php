<?php

class DateParser
{
    private const MONTHS = [
        'январ' => 1,
        'феврал' => 2,
        'март' => 3,
        'апрел' => 4,
        'май' => 5,
        'мая' => 5,
        'мае' => 5,
        'июн' => 6,
        'июл' => 7,
        'август' => 8,
        'сентябр' => 9,
        'октябр' => 10,
        'ноябр' => 11,
        'декабр' => 12,
    ];

    public static function detectMonth(string $text): array
    {
        $normalized = self::lower($text);
        foreach (self::MONTHS as $stem => $month) {
            if (strpos($normalized, $stem) !== false) {
                $year = (int)date('Y');
                if ($month < (int)date('n')) $year++;
                return ['month'=>$month, 'year'=>$year, 'stem'=>$stem];
            }
        }
        return [];
    }

    public static function parseShortDay(string $text): int
    {
        $normalized = trim(self::lower($text));
        if (preg_match('/^(?:в\s+)?начал(?:о|е)$/ui', $normalized)) return 5;
        if (preg_match('/^(?:в\s+)?середин(?:а|е|у)$/ui', $normalized)) return 15;
        if (preg_match('/^(?:в\s+)?кон(?:ец|це)$/ui', $normalized)) return 25;
        if (preg_match('/^(?:в\s+)?10(?:-?х|-?е|ые)?\s+числ(?:ах|а)?$/ui', $normalized)) return 15;
        if (preg_match('/^(?:в\s+)?20(?:-?х|-?е|ые)?\s+числ(?:ах|а)?$/ui', $normalized)) return 25;
        if (preg_match('/^(\d{1,2})(?:\s*(?:числа|число))?$/ui', $normalized, $m)) {
            $day = (int)$m[1];
            return ($day >= 1 && $day <= 31) ? $day : 0;
        }
        return 0;
    }

    public static function resolveDate(string $text): array
    {
        if (preg_match('/\bзавтра\b/ui', $text)) return ['date'=>date('d.m.Y', strtotime('+1 day'))];
        if (preg_match('/\bпослезавтра\b/ui', $text)) return ['date'=>date('d.m.Y', strtotime('+2 days'))];
        if (preg_match('/\b(?:ближайш(?:ая|ие|ую)|как\s+можно\s+скорее|поскорее)\b/ui', $text)) {
            return ['date'=>date('d.m.Y', strtotime('+1 day'))];
        }

        $monthInfo = self::detectMonth($text);
        if (empty($monthInfo)) return [];

        $month = (int)$monthInfo['month'];
        $year = (int)$monthInfo['year'];
        $stem = (string)$monthInfo['stem'];
        $day = 0;

        if (preg_match('/(?:в\s+)?начал(?:е|о)\s+[а-яё]+/ui', $text)) $day = 5;
        elseif (preg_match('/(?:в\s+)?середин(?:е|у)\s+[а-яё]+/ui', $text)) $day = 15;
        elseif (preg_match('/(?:в\s+)?конц(?:е|а)\s+[а-яё]+/ui', $text)) $day = 25;
        elseif (preg_match('/(?:в\s+)?10(?:-?х|-?е|ые)?\s+числ(?:ах|а)?\s+[а-яё]+/ui', $text)) $day = 15;
        elseif (preg_match('/(?:в\s+)?20(?:-?х|-?е|ые)?\s+числ(?:ах|а)?\s+[а-яё]+/ui', $text)) $day = 25;
        elseif (preg_match('/после\s+(\d{1,2})\s+[а-яё]*'.preg_quote($stem,'/').'[а-яё]*/ui', $text, $m)) $day = min(28, ((int)$m[1])+1);
        elseif (preg_match('/\b(\d{1,2})\s+[а-яё]*'.preg_quote($stem,'/').'[а-яё]*/ui', $text, $m)) $day = (int)$m[1];

        if ($day > 0 && checkdate($month, $day, $year)) {
            return ['date'=>sprintf('%02d.%02d.%04d',$day,$month,$year), 'month'=>$month, 'year'=>$year];
        }

        return ['month'=>$month, 'year'=>$year];
    }

    public static function buildDate(int $day, int $month, int $year): string
    {
        return checkdate($month, $day, $year) ? sprintf('%02d.%02d.%04d',$day,$month,$year) : '';
    }

    private static function lower(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text,'UTF-8') : strtolower($text);
    }
}
