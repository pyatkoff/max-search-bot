<?php

declare(strict_types=1);

/**
 * Небольшой экспертный слой поверх фактических маршрутов Tourvisor.
 * Он НЕ определяет доступность рейсов. На вход получает только направления,
 * которые уже подтверждены DepartureRouteResolver/Advisor.
 */
final class DestinationPreferenceResolver
{
    private const PROFILES = [
        'египет' => ['sea'=>true,'warm_months'=>[1,2,3,4,5,6,7,8,9,10,11,12],'winter_sun'=>true],
        'оаэ' => ['sea'=>true,'warm_months'=>[1,2,3,4,5,10,11,12],'winter_sun'=>true],
        'таиланд' => ['sea'=>true,'warm_months'=>[1,2,3,4,11,12],'winter_sun'=>true],
        'вьетнам' => ['sea'=>true,'warm_months'=>[1,2,3,4,11,12],'winter_sun'=>true],
        'шри-ланка' => ['sea'=>true,'warm_months'=>[1,2,3,4,11,12],'winter_sun'=>true],
        'мальдивы' => ['sea'=>true,'warm_months'=>[1,2,3,4,11,12],'winter_sun'=>true],
        'катар' => ['sea'=>true,'warm_months'=>[1,2,3,4,10,11,12],'winter_sun'=>true],
        'танзания' => ['sea'=>true,'warm_months'=>[1,2,3,6,7,8,9,10,11,12],'winter_sun'=>true],
        'сейшелы' => ['sea'=>true,'warm_months'=>[1,2,3,4,5,6,7,8,9,10,11,12],'winter_sun'=>true],
        'индонезия' => ['sea'=>true,'warm_months'=>[1,2,3,4,5,6,7,8,9,10,11,12],'winter_sun'=>true],
        'турция' => ['sea'=>true,'warm_months'=>[5,6,7,8,9,10],'winter_sun'=>false],
        'тунис' => ['sea'=>true,'warm_months'=>[5,6,7,8,9,10],'winter_sun'=>false],
        'абхазия' => ['sea'=>true,'warm_months'=>[6,7,8,9],'winter_sun'=>false],
        'россия' => ['sea'=>true,'warm_months'=>[6,7,8,9],'winter_sun'=>false],
        'китай' => ['sea'=>true,'warm_months'=>[3,4,5,10,11],'winter_sun'=>false],
        'индия' => ['sea'=>true,'warm_months'=>[1,2,3,11,12],'winter_sun'=>true],
        'филиппины' => ['sea'=>true,'warm_months'=>[1,2,3,4,11,12],'winter_sun'=>true],
    ];

    public static function detectIntent(string $text): ?string
    {
        $text = self::lower($text);
        if (preg_match('/(?:куда\s+потеплее|где\s+тепло|в\s+тепло|на\s+теплое\s+море|на\s+тёплое\s+море|зим(?:ой|ою)\s+на\s+море)/ui', $text)) {
            return 'warm';
        }
        if (preg_match('/(?:хочу\s+(?:на\s+)?море|куда\s+на\s+море|пляжн|море\s+и\s+солнце)/ui', $text)) {
            return 'sea';
        }
        return null;
    }

    public static function filterAndRank(array $destinations, ?string $intent, ?string $period = null): array
    {
        if ($intent === null) return $destinations;
        $month = self::monthFromPeriod($period);
        $out = [];

        foreach ($destinations as $row) {
            $country = trim((string)($row['country'] ?? ''));
            $profile = self::PROFILES[self::lower($country)] ?? null;
            if (!$profile) continue;
            if ($intent === 'sea' && empty($profile['sea'])) continue;
            if ($intent === 'warm' && $month !== null && !in_array($month, (array)$profile['warm_months'], true)) continue;

            $score = (int)($row['recommendation_score'] ?? 0);
            if ($intent === 'warm' && !empty($profile['winter_sun'])) $score += 500;
            if ($intent === 'sea' && !empty($profile['sea'])) $score += 200;
            $row['preference_score'] = $score;
            $out[] = $row;
        }

        usort($out, static fn(array $a, array $b): int => ($b['preference_score'] ?? 0) <=> ($a['preference_score'] ?? 0));
        return $out;
    }

    private static function monthFromPeriod(?string $period): ?int
    {
        if ($period && preg_match('/^\d{4}-(\d{2})$/', $period, $m)) return (int)$m[1];
        return null;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    }
}
