<?php

require_once __DIR__ . '/DateParser.php';
require_once __DIR__ . '/PendingMonthStore.php';

/**
 * Owns stateful date clarification without changing DateParser semantics.
 *
 * DateParser remains the deterministic stateless parser. This boundary owns the
 * transient pending-month context needed for natural follow-ups such as "14",
 * "28-31" or "в конце месяца" after the user named only a month.
 */
class DateContextResolver
{
    public static function resolveFromText($chatId, string $text): array
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

    public static function rememberMonth($chatId, int $month, int $year): void
    {
        PendingMonthStore::set($chatId, $month, $year);
    }

    public static function resolvePendingShortDate($chatId, string $text): string
    {
        $pending = PendingMonthStore::get($chatId);
        if (empty($pending)) return '';

        $month = (int)$pending['month'];
        $year = (int)$pending['year'];
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower(trim($text), 'UTF-8')
            : strtolower(trim($text));
        $normalized = trim(preg_replace('/[.!?]+$/u', '', $normalized));

        $fromDay = 0;
        $toDay = 0;
        if (preg_match('/^(\d{1,2})\s*[-–—]\s*(\d{1,2})$/u', $normalized, $m)
            || preg_match('/^с\s*(\d{1,2})\s*по\s*(\d{1,2})(?:\s*число|\s*числа)?$/ui', $normalized, $m)) {
            $fromDay = (int)$m[1];
            $toDay = (int)$m[2];
            if ($fromDay >= 1 && $toDay >= $fromDay
                && checkdate($month, $fromDay, $year)
                && checkdate($month, $toDay, $year)) {
                $day = (int)round(($fromDay + $toDay) / 2);
                $date = DateParser::buildDate($day, $month, $year);
                if ($date !== '') PendingMonthStore::clear($chatId);
                return $date;
            }
            return '';
        }

        if (preg_match('/^(?:в\s+)?начал(?:о|е)\s+месяца$/ui', $normalized)) {
            $day = 5;
        } elseif (preg_match('/^(?:в\s+)?середин(?:а|е|у)\s+месяца$/ui', $normalized)) {
            $day = 15;
        } elseif (preg_match('/^(?:в\s+)?кон(?:ец|це)\s+месяца$/ui', $normalized)) {
            $lastDay = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
            $day = max(1, $lastDay - 3);
        } else {
            $day = DateParser::parseShortDay($text);
        }

        if ($day <= 0) return '';

        $date = DateParser::buildDate($day, $month, $year);
        if ($date !== '') PendingMonthStore::clear($chatId);

        return $date;
    }

    public static function clear($chatId): void
    {
        PendingMonthStore::clear($chatId);
    }
}
