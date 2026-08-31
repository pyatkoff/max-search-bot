<?php

declare(strict_types=1);

final class NativeDateService
{
    public static function isTodayOrFuture(string $date, ?DateTimeImmutable $today = null): bool
    {
        $value = self::parseDate($date);
        if (!$value) return false;

        $today = $today ?: new DateTimeImmutable('today');
        return $value >= $today->setTime(0, 0, 0);
    }

    /**
     * Preserve the existing lead date-window behavior: ±3 days around the
     * requested departure date, with the lower bound clamped to today.
     */
    public static function leadWindow(string $date, ?DateTimeImmutable $today = null): array
    {
        $value = self::parseDate($date);
        if (!$value) {
            throw new InvalidArgumentException('Invalid departure date: ' . $date);
        }

        $today = ($today ?: new DateTimeImmutable('today'))->setTime(0, 0, 0);
        $from = $value->modify('-3 days');
        $to = $value->modify('+3 days');
        if ($from < $today) $from = $today;

        return [
            'from' => $from->format('d.m.Y'),
            'to' => $to->format('d.m.Y'),
        ];
    }

    private static function parseDate(string $date): ?DateTimeImmutable
    {
        $date = trim($date);
        if ($date === '') return null;

        $value = DateTimeImmutable::createFromFormat('!d.m.Y', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$value) return null;
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return null;
        }
        return $value;
    }
}
