<?php

/** Presentation-only formatter for the selected departure date used by search handoff. */
class SearchDateSummary
{
    public static function line($rawDate, ?DateTimeImmutable $today = null): ?string
    {
        $rawDate = trim((string)$rawDate);
        if ($rawDate === '') return null;

        $zone = new DateTimeZone('Europe/Kaliningrad');
        $date = self::parseDate($rawDate, $zone);
        if (!$date) return null;

        $date = $date->setTimezone($zone)->setTime(0, 0, 0);
        return '📅 Вылет ' . $date->format('d.m.Y') . ' · поиск на эту дату';
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
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))) {
                return $date;
            }
        }
        try {
            return new DateTimeImmutable($rawDate, $zone);
        } catch (Throwable $e) {
            return null;
        }
    }
}
