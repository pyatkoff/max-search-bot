<?php
declare(strict_types=1);

/** Reviewed ID/path policies propose cities; they never select a tourist's departure. */
final class RegionalDeparturePolicy
{
    private const RULES_MAX_BYTES = 262144;
    private const MANIFEST_MAX_BYTES = 65536;
    private static bool $initialized = false;
    private static bool $valid = false;
    private static array $exact = [];
    private static array $scopes = [];

    /** null means unavailable/unmapped; an explicit empty array disables inheritance. */
    public static function departureNames(array $region): ?array
    {
        self::initialize();
        if (!self::$valid) return null;
        $id = self::id($region['id'] ?? null);
        if ($id === '') return null;
        if (array_key_exists($id, self::$exact)) return self::$exact[$id];
        $hierarchy = self::path($region['hierarchy'] ?? null);
        if ($hierarchy === null) return null;
        $length = -1;
        $names = null;
        foreach (self::$scopes as $scope) {
            $prefix = $scope['prefix'];
            if (!self::startsWith($hierarchy, $prefix)) continue;
            $specificity = count($prefix);
            if ($specificity === $length && $names !== $scope['departure_names']) return null;
            if ($specificity > $length) {
                $length = $specificity;
                $names = $scope['departure_names'];
            }
        }
        return $names;
    }

    private static function initialize(): void
    {
        if (self::$initialized) return;
        self::$initialized = true;
        $raw = self::read(dirname(__DIR__).'/resources/regional_departure_rules.json', self::RULES_MAX_BYTES);
        if ($raw === null) return;
        try { $rules = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { return; }
        if (!is_array($rules) || ($rules['version'] ?? null) !== 1
            || !is_string($rules['catalog_manifest_sha256'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $rules['catalog_manifest_sha256'])
            || !is_array($rules['exact'] ?? null) || count($rules['exact']) > 1000
            || !is_array($rules['scopes'] ?? null) || !array_is_list($rules['scopes'])
            || count($rules['scopes']) > 1000) return;
        // The small manifest binds the reviewed anchor paths to this exact source.
        // Runtime must not read extra region buckets to look up each policy anchor.
        $manifest = self::read(dirname(__DIR__).'/resources/yandex_regions.json', self::MANIFEST_MAX_BYTES);
        if ($manifest === null || !hash_equals($rules['catalog_manifest_sha256'], hash('sha256', $manifest))) return;
        $exact = [];
        foreach ($rules['exact'] as $id => $entry) {
            if (self::id($id) === '' || self::id($id) !== (string)$id || !is_array($entry)
                || self::path($entry['anchor_hierarchy'] ?? null) === null) return;
            $names = self::names($entry['departure_names'] ?? null);
            if ($names === null) return;
            $exact[(string)$id] = $names;
        }
        $scopes = [];
        foreach ($rules['scopes'] as $scope) {
            if (!is_array($scope) || self::id($scope['anchor_id'] ?? null) === '') return;
            $prefix = self::path($scope['prefix'] ?? null);
            $anchor = self::path($scope['anchor_hierarchy'] ?? null);
            $names = self::names($scope['departure_names'] ?? null);
            $kind = $scope['kind'] ?? '';
            $minimum = $kind === 'city' ? 3 : ($kind === 'province' ? 2 : 6);
            if ($prefix === null || $anchor === null || $names === null
                || count($prefix) < $minimum || !self::startsWith($anchor, $prefix)) return;
            $key = json_encode($prefix, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if (array_key_exists($key, $scopes) && $scopes[$key]['departure_names'] !== $names) return;
            $scopes[$key] = ['prefix'=>$prefix, 'departure_names'=>$names];
        }
        self::$exact = $exact;
        self::$scopes = array_values($scopes);
        self::$valid = true;
    }

    private static function startsWith(array $path, array $prefix): bool
    {
        return count($path) >= count($prefix) && array_slice($path, 0, count($prefix)) === $prefix;
    }

    private static function id($value): string
    {
        if (!is_string($value) && !is_int($value)) return '';
        $value = (string)$value;
        return preg_match('/\A[1-9][0-9]{0,19}\z/D', $value) ? $value : '';
    }

    private static function path($value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 5) return null;
        foreach ($value as $label) if (!self::label($label)) return null;
        return $value;
    }

    private static function names($value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 8) return null;
        foreach ($value as $name) if (!self::label($name)) return null;
        return $value;
    }

    private static function label($value): bool
    {
        return is_string($value) && $value !== '' && $value === trim($value)
            && strlen($value) <= 400 && !preg_match('/[\x00-\x1f\x7f]/', $value);
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
}
