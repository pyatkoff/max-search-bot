<?php

require_once(__DIR__ . '/../services/DateParser.php');
require_once(__DIR__ . '/../services/PendingMonthStore.php');

class AiDateHandler
{
    public static function rememberMonthFromText($chatId, string $text): array
    {
        $resolved = DateParser::resolveDate($text);

        if (!empty($resolved['date'])) {
            PendingMonthStore::clear($chatId);
            return $resolved;
        }

        if (!empty($resolved['month']) && !empty($resolved['year'])) {
            PendingMonthStore::set($chatId, (int)$resolved['month'], (int)$resolved['year']);
        }

        return $resolved;
    }

    public static function resolvePendingShortDate($chatId, string $text): string
    {
        $pending = PendingMonthStore::get($chatId);
        if (empty($pending)) return '';

        $day = DateParser::parseShortDay($text);
        if ($day <= 0) return '';

        $date = DateParser::buildDate($day, (int)$pending['month'], (int)$pending['year']);
        if ($date !== '') PendingMonthStore::clear($chatId);

        return $date;
    }

    public static function clear($chatId): void
    {
        PendingMonthStore::clear($chatId);
    }
}
