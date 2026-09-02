<?php

class ProjectConfig
{
    private static $config;

    public static function all(): array
    {
        if (self::$config !== null) return self::$config;
        $file = dirname(__DIR__) . '/project.php';
        $data = is_file($file) ? require $file : [];
        self::$config = is_array($data) ? $data : [];
        return self::$config;
    }

    public static function get(string $path, $default = null)
    {
        $value = self::all();
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return $default;
            $value = $value[$part];
        }
        return $value;
    }

    public static function projectId(): string
    {
        return (string)self::get('id', 'default');
    }

    public static function baseDomain(): string
    {
        if (defined('MAX_SEARCH_PUBLIC_BASE_URL')) {
            $override = trim((string)MAX_SEARCH_PUBLIC_BASE_URL);
            if ($override !== '') return rtrim($override, '/');
        }
        return rtrim((string)self::get('search.base_domain', ''), '/');
    }

    public static function trackingBaseDomain(): string
    {
        if (defined('MAX_SEARCH_TRACKING_BASE_URL')) {
            $override = trim((string)MAX_SEARCH_TRACKING_BASE_URL);
            if ($override !== '') return rtrim($override, '/');
        }
        return rtrim((string)self::get('search.tracking_base_domain', self::baseDomain()), '/');
    }

    public static function searchUrl(array $query = []): string
    {
        $path = '/' . trim((string)self::get('search.search_path', '/poisk-turov/'), '/') . '/';
        $url = self::baseDomain() . $path;
        $query = array_filter($query, static function ($value): bool {
            return $value !== null && $value !== '' && $value !== 0 && $value !== '0' && $value !== [];
        });
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $url;
    }

    public static function searchUrlFromSavedData(array $savedData, array $statusMap, string $yclid = ''): string
    {
        [$nightsFrom, $nightsTill] = self::rangeQueryValues($savedData[$statusMap['nights']] ?? '');
        $children = self::positiveInt($savedData[$statusMap['children']] ?? 0);

        return self::searchUrl([
            'from' => (int)($savedData[$statusMap['city']] ?? 0),
            'country' => (int)($savedData[$statusMap['country']] ?? 0),
            'dateFrom' => self::dateQueryValue($savedData[$statusMap['date']] ?? ''),
            'dateTo' => self::dateQueryValue($savedData[$statusMap['date']] ?? ''),
            'daysFrom' => $nightsFrom,
            'daysTill' => $nightsTill,
            'count_people' => self::positiveInt($savedData[$statusMap['adults']] ?? 0),
            'child_count' => $children,
            'child_age' => self::childAgeQueryValues($savedData[$statusMap['child_ages']] ?? '', $children),
            'stars' => self::positiveInt($savedData[$statusMap['stars']] ?? 0),
            'food' => self::positiveInt($savedData[$statusMap['meal']] ?? 0),
            'yclid' => $yclid,
        ]);
    }

    public static function searchUrlFromClaim(array $claim, string $yclid = ''): string
    {
        [$nightsFrom, $nightsTill] = self::rangeQueryValues($claim['UF_NIGHTS'] ?? '');
        $children = self::positiveInt($claim['UF_CHILD'] ?? 0);

        return self::searchUrl([
            'from' => (int)($claim['UF_CITY'] ?? 0),
            'country' => (int)($claim['UF_COUNTRY'] ?? 0),
            'dateFrom' => self::dateQueryValue($claim['UF_DATE_DEPART'] ?? ''),
            'dateTo' => self::dateQueryValue($claim['UF_DATE_DEPART'] ?? ''),
            'daysFrom' => $nightsFrom,
            'daysTill' => $nightsTill,
            'count_people' => self::positiveInt($claim['UF_ADULTS'] ?? 0),
            'child_count' => $children,
            'child_age' => self::childAgeQueryValues($claim['UF_AGE'] ?? '', $children),
            'stars' => self::positiveInt($claim['UF_STARS'] ?? 0),
            'food' => self::positiveInt($claim['UF_MEAL'] ?? 0),
            'yclid' => $yclid,
        ]);
    }

    private static function positiveInt($value): int
    {
        $value = (int)$value;
        return $value > 0 ? $value : 0;
    }

    private static function rangeQueryValues($value): array
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

    private static function childAgeQueryValues($value, int $children): array
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

    private static function dateQueryValue($value): string
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

    public static function claimUrl(string $code, string $yclid = ''): string
    {
        return self::searchUrl([
            'claim' => $code,
            'yclid' => $yclid,
        ]);
    }

    public static function v2StoreDir(string $baseDir): string
    {
        $relative = trim((string)self::get('state.v2_store_dir', 'runtime/trip_state'), '/');
        return rtrim($baseDir, '/') . '/' . $relative;
    }

    public static function resetForTests(?array $config = null): void
    {
        self::$config = $config;
    }
}
