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
            return $value !== null && $value !== '' && $value !== 0 && $value !== '0';
        });
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $url;
    }

    public static function searchUrlFromSavedData(array $savedData, array $statusMap, string $yclid = ''): string
    {
        return self::searchUrl([
            'from' => (int)($savedData[$statusMap['city']] ?? 0),
            'country' => (int)($savedData[$statusMap['country']] ?? 0),
            'yclid' => $yclid,
        ]);
    }

    public static function searchUrlFromClaim(array $claim, string $yclid = ''): string
    {
        return self::searchUrl([
            'from' => (int)($claim['UF_CITY'] ?? 0),
            'country' => (int)($claim['UF_COUNTRY'] ?? 0),
            'yclid' => $yclid,
        ]);
    }

    public static function claimUrl(string $code, string $yclid = ''): string
    {
        // Backward-compatible helper for callers that only have a claim code.
        // There is one public search owner: searchUrl().
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
