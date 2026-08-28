<?php

/** Presentation-only formatter for the preferred departure date and its search window. */
class SearchDateSummary
{
    public static function line($rawDate, ?DateTimeImmutable $today = null): ?string
    {
        $rawDate = trim((string)$rawDate);
        if ($rawDate === '') return null;

        $zone = new DateTimeZone('Europe/Kaliningrad');
        $date = self::parseDate($rawDate, $zone);
        if (!$date) return null;

        $today = $today ?: new DateTimeImmutable('today', $zone);
        $today = $today->setTimezone($zone)->setTime(0, 0, 0);
        $date = $date->setTimezone($zone)->setTime(0, 0, 0);
        $from = $date->modify('-3 days');
        if ($from < $today) $from = $today;
        $to = $date->modify('+3 days');

        return '📅 Вылет ' . $date->format('d.m.Y')
            . ' · ищем ' . $from->format('d.m') . '–' . $to->format('d.m.Y');
    }

    public static function replaceDateLine(array $summary, $rawDate, ?DateTimeImmutable $today = null): array
    {
        $line = self::line($rawDate, $today);
        if ($line === null) return $summary;

        foreach ($summary as $index => $item) {
            if (strpos((string)$item, '📅') === 0) {
                $summary[$index] = $line;
                return $summary;
            }
        }
        $summary[] = $line;
        return $summary;
    }

    private static function parseDate(string $rawDate, DateTimeZone $zone): ?DateTimeImmutable
    {
        foreach (['!d.m.Y', '!Y-m-d', '!d.m.Y H:i:s', '!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $rawDate, $zone);
            if ($date instanceof DateTimeImmutable) return $date;
        }
        try {
            return new DateTimeImmutable($rawDate, $zone);
        } catch (Throwable $e) {
            return null;
        }
    }
}
