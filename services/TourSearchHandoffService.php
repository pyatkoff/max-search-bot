<?php

final class TourSearchHandoffService
{
    public static function queryFromSavedData(array $savedData, array $statusMap, string $yclid = ''): array
    {
        [$nightsFrom, $nightsTill] = self::rangeValues($savedData[$statusMap['nights']] ?? '');
        $children = self::positiveInt($savedData[$statusMap['children']] ?? 0);
        $date = self::dateValue($savedData[$statusMap['date']] ?? '');

        return [
            'from' => (int)($savedData[$statusMap['city']] ?? 0),
            'country' => (int)($savedData[$statusMap['country']] ?? 0),
            'dateFrom' => $date,
            'dateTo' => $date,
            'daysFrom' => $nightsFrom,
            'daysTill' => $nightsTill,
            'count_people' => self::positiveInt($savedData[$statusMap['adults']] ?? 0),
            'child_count' => $children,
            'child_age' => self::childAgeValues($savedData[$statusMap['child_ages']] ?? '', $children),
            'stars' => self::positiveInt($savedData[$statusMap['stars']] ?? 0),
            'food' => self::positiveInt($savedData[$statusMap['meal']] ?? 0),
            'yclid' => $yclid,
        ];
    }

    public static function queryFromClaim(array $claim, string $yclid = ''): array
    {
        [$nightsFrom, $nightsTill] = self::rangeValues($claim['UF_NIGHTS'] ?? '');
        $children = self::positiveInt($claim['UF_CHILD'] ?? 0);
        $date = self::dateValue($claim['UF_DATE_DEPART'] ?? '');

        return [
            'from' => (int)($claim['UF_CITY'] ?? 0),
            'country' => (int)($claim['UF_COUNTRY'] ?? 0),
            'dateFrom' => $date,
            'dateTo' => $date,
            'daysFrom' => $nightsFrom,
            'daysTill' => $nightsTill,
            'count_people' => self::positiveInt($claim['UF_ADULTS'] ?? 0),
            'child_count' => $children,
            'child_age' => self::childAgeValues($claim['UF_AGE'] ?? '', $children),
            'stars' => self::positiveInt($claim['UF_STARS'] ?? 0),
            'food' => self::positiveInt($claim['UF_MEAL'] ?? 0),
            'yclid' => $yclid,
        ];
    }

    private static function positiveInt($value): int
    {
        $value = (int)$value;
        return $value > 0 ? $value : 0;
    }

    private static function rangeValues($value): array
    {
        $raw = trim((string)$value);
        if ($raw === '') return [0, 0];
        if (preg_match('/^(\d{1,2})\s*[-–—]\s*(\d{1,2})$/u', $raw, $m)) {
            $from = self::positiveInt($m[1]);
            $till = self::positiveInt($m[2]);
            return [$from, $till >= $from ? $till : $from];
        }
        $single = self::positiveInt($raw);
        return [$single, $single];
    }

    private static function childAgeValues($value, int $children): array
    {
        if ($children < 1) return [];
        if (is_array($value)) $parts = $value;
        else $parts = preg_split('/\s*[,;]\s*/u', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ages = [];
        foreach ($parts as $part) {
            $raw = trim((string)$part);
            if ($raw === '' || !preg_match('/^\d{1,2}$/', $raw)) continue;
            $age = (int)$raw;
            if ($age >= 0 && $age <= 17) $ages[] = $age;
            if (count($ages) >= $children) break;
        }
        return $ages;
    }

    private static function dateValue($value): string
    {
        if ($value instanceof DateTimeInterface) return $value->format('Y-m-d');
        $raw = trim((string)$value);
        if ($raw === '') return '';

        foreach (['!Y-m-d', '!d.m.Y', '!d.m.Y H:i:s', '!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))) {
                return $date->format('Y-m-d');
            }
        }

        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d');
        } catch (Throwable $e) {
            return '';
        }
    }
}
