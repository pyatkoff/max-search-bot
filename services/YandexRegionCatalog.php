<?php
declare(strict_types=1);

/** Shared advertising geography; these IDs are never travel-catalogue IDs. */
final class YandexRegionCatalog
{
    private const MANIFEST_MAX_BYTES = 65536;
    private const BUCKET_MAX_BYTES = 1048576;
    private const REGION_LIMIT = 250000;
    private static bool $initialized = false;
    private static ?array $manifest = null;
    private static ?array $legacyRegions = null;
    private static ?string $bucket = null;
    private static array $bucketRegions = [];

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
        self::initialize();
        if (self::$legacyRegions !== null) {
            return self::record($id, self::$legacyRegions[$id] ?? null);
        }
        if (self::$manifest === null) return null;
        $bucket = str_pad(substr($id, -2), 2, '0', STR_PAD_LEFT);
        if (self::$bucket !== $bucket) self::loadBucket($bucket);
        return self::record($id, self::$bucketRegions[$id] ?? null);
    }

    private static function initialize(): void
    {
        if (self::$initialized) return;
        self::$initialized = true;
        // Legacy snapshots are bounded too; a full flat export is never decoded.
        $raw = self::read(dirname(__DIR__).'/resources/yandex_regions.json', self::BUCKET_MAX_BYTES);
        if ($raw === null) return;
        $decoded = self::decode($raw);
        if ($decoded === null) return;
        if (($decoded['version'] ?? null) === 1) {
            if (is_array($decoded['regions'] ?? null) && count($decoded['regions']) <= self::REGION_LIMIT) {
                self::$legacyRegions = $decoded['regions'];
            }
            return;
        }
        if (strlen($raw) > self::MANIFEST_MAX_BYTES || ($decoded['version'] ?? null) !== 2
            || ($decoded['layout'] ?? '') !== 'suffix-2'
            || !is_int($decoded['region_count'] ?? null) || $decoded['region_count'] < 1
            || $decoded['region_count'] > self::REGION_LIMIT || !is_array($decoded['buckets'] ?? null)
            || count($decoded['buckets']) < 1 || count($decoded['buckets']) > 100) return;
        $count = 0;
        foreach ($decoded['buckets'] as $suffix => $entry) {
            if (!preg_match('/\A[0-9]{2}\z/D', (string)$suffix) || !is_array($entry)
                || !is_int($entry['count'] ?? null) || $entry['count'] < 1 || $entry['count'] > self::REGION_LIMIT
                || !is_string($entry['sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256'])) return;
            $count += $entry['count'];
            if ($count > self::REGION_LIMIT) return;
        }
        if ($count !== $decoded['region_count']) return;
        self::$manifest = $decoded;
    }

    private static function loadBucket(string $bucket): void
    {
        // Evict the preceding bucket before reading another one. A long request
        // can never accumulate the complete geography in a process-wide cache.
        self::$bucketRegions = [];
        self::$bucket = $bucket;
        $entry = self::$manifest['buckets'][$bucket] ?? null;
        if (!is_array($entry)) return;
        $raw = self::read(dirname(__DIR__).'/resources/yandex_regions/'.$bucket.'.json', self::BUCKET_MAX_BYTES);
        if ($raw === null || !hash_equals($entry['sha256'], hash('sha256', $raw))) return;
        $decoded = self::decode($raw);
        $regions = $decoded['regions'] ?? null;
        if (!is_array($regions) || count($regions) !== $entry['count']) return;
        foreach ($regions as $id => $row) {
            $id = (string)$id;
            if (self::normalizeId($id) !== $id
                || str_pad(substr($id, -2), 2, '0', STR_PAD_LEFT) !== $bucket || !is_array($row)) return;
        }
        self::$bucketRegions = $regions;
    }

    private static function read(string $path, int $limit): ?string
    {
        if (!is_file($path) || is_link($path)) return null;
        $size = @filesize($path);
        if (!is_int($size) || $size < 1 || $size > $limit) return null;
        $handle = @fopen($path, 'rb');
        if ($handle === false) return null;
        try { $raw = stream_get_contents($handle, $limit + 1); }
        finally { fclose($handle); }
        return is_string($raw) && strlen($raw) <= $limit ? $raw : null;
    }

    private static function decode(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $ignored) { return null; }
    }

    private static function record(string $id, $row): ?array
    {
        if (!is_array($row)) return null;
        $name = self::label($row['name'] ?? null);
        if ($name === '') return null;
        $departures = [];
        foreach (is_array($row['departure_names'] ?? null) ? $row['departure_names'] : [] as $candidate) {
            $candidate = self::label($candidate);
            if ($candidate !== '') $departures[$candidate] = $candidate;
            if (count($departures) >= 8) break;
        }
        $result = ['id' => $id, 'name' => $name, 'departure_names' => array_values($departures)];
        if (array_key_exists('hierarchy', $row)) {
            if (!is_array($row['hierarchy']) || !array_is_list($row['hierarchy']) || count($row['hierarchy']) > 5) return null;
            $hierarchy = [];
            foreach ($row['hierarchy'] as $label) {
                $label = self::label($label);
                if ($label === '') return null;
                $hierarchy[] = $label;
            }
            $result['hierarchy'] = $hierarchy;
        }
        return $result;
    }

    private static function label($value): string
    {
        if (!is_string($value) || strlen($value) > 400 || preg_match('/[\x00-\x1f\x7f]/', $value)) return '';
        return trim($value);
    }
}
