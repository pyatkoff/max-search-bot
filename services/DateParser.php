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

        // Явные цифровые даты/диапазоны: 28-31.08, 28–31.08.2026, 28.08.
        // В текущей модели поиска хранится одна ориентировочная дата, поэтому для
        // диапазона берём его середину. Главное — не терять месяц и не спрашивать дату повторно.
        if (preg_match('/(?<!\d)(\d{1,2})\s*[-–—]\s*(\d{1,2})[.\/]\s*(\d{1,2})(?:[.\/]\s*(\d{2,4}))?(?!\d)/u', $text, $m)) {
            $fromDay = (int)$m[1];
            $toDay = (int)$m[2];
            $month = (int)$m[3];
            $year = self::normalizeYear(isset($m[4]) ? (string)$m[4] : '', $month);
            if ($fromDay > 0 && $toDay >= $fromDay && checkdate($month, $fromDay, $year) && checkdate($month, $toDay, $year)) {
                $day = (int)round(($fromDay + $toDay) / 2);
                return [
                    'date'=>sprintf('%02d.%02d.%04d',$day,$month,$year),
                    'month'=>$month,
                    'year'=>$year,
                    'range_from'=>sprintf('%02d.%02d.%04d',$fromDay,$month,$year),
                    'range_to'=>sprintf('%02d.%02d.%04d',$toDay,$month,$year),
                ];
            }
        }

        if (preg_match('/(?<!\d)(\d{1,2})[.\/]\s*(\d{1,2})(?:[.\/]\s*(\d{2,4}))?(?!\d)/u', $text, $m)) {
            $day = (int)$m[1];
            $month = (int)$m[2];
            $year = self::normalizeYear(isset($m[3]) ? (string)$m[3] : '', $month);
            if (checkdate($month, $day, $year)) {
                return ['date'=>sprintf('%02d.%02d.%04d',$day,$month,$year), 'month'=>$month, 'year'=>$year];
            }
        }

        $monthInfo = self::detectMonth($text);
        if (empty($monthInfo)) return [];

        $month = (int)$monthInfo['month'];
        $year = (int)$monthInfo['year'];
        $stem = (string)$monthInfo['stem'];
        $day = 0;

        if (preg_match('/(?:в\s+)?начал(?:е|о)\s+[а-яё]+/ui', $text)) $day = 5;
        elseif (preg_match('/(?:в\s+)?середин(?:е|у|а)\s+[а-яё]+/ui', $text)) $day = 15;
        // Поддерживаем естественные формы: "конец августа", "в конце августа", "конца августа".
        // Так как выдача строится ±3 дня от опорной даты, ставим опору за 3 дня до конца месяца,
        // чтобы "конец августа" давал действительно последние числа месяца, а не 22–28 августа.
        elseif (preg_match('/(?:в\s+)?кон(?:ец|це|ца)\s+[а-яё]+/ui', $text)) $day = self::endOfMonthAnchorDay($month, $year);
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

    private static function endOfMonthAnchorDay(int $month, int $year): int
    {
        $last = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
        return max(1, $last - 3);
    }

    private static function normalizeYear(string $rawYear, int $month): int
    {
        $year = (int)$rawYear;
        if ($year > 0 && $year < 100) $year += 2000;
        if ($year <= 0) {
            $year = (int)date('Y');
            if ($month < (int)date('n')) $year++;
        }
        return $year;
    }

    private static function lower(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text,'UTF-8') : strtolower($text);
    }
}
