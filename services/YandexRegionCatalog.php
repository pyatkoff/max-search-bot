<?php
declare(strict_types=1);

/** Shared advertising geography; these IDs are never travel-catalogue IDs. */
final class YandexRegionCatalog
{
    public static function normalizeId($value): string
    {
        if (!is_string($value) && !is_int($value)) return '';
        $value = trim((string)$value);
        if (!preg_match('/\A[0-9]{1,20}\z/D', $value)) return '';
        return ltrim($value, '0');
    }

    public static function findByYandexId(string $value): ?array
    {
        $id = self::normalizeId($value);
        if ($id === '') return null;
        static $regions;
        if ($regions === null) {
            $decoded = json_decode((string)@file_get_contents(dirname(__DIR__).'/resources/yandex_regions.json'), true);
            $regions = is_array($decoded['regions'] ?? null) ? $decoded['regions'] : [];
        }
        $row = $regions[$id] ?? null;
        if (!is_array($row)) return null;
        $name = self::label($row['name'] ?? null);
        if ($name === '') return null;
        $departures = [];
        foreach (is_array($row['departure_names'] ?? null) ? $row['departure_names'] : [] as $candidate) {
            $candidate = self::label($candidate);
            if ($candidate !== '') $departures[$candidate] = $candidate;
            if (count($departures) >= 8) break;
        }
        return ['id' => $id, 'name' => $name, 'departure_names' => array_values($departures)];
    }

    private static function label($value): string
    {
        if (!is_string($value) || strlen($value) > 400 || preg_match('/[\x00-\x1f\x7f]/', $value)) return '';
        return trim($value);
    }
}
