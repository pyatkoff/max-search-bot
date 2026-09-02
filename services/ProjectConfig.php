<?php

require_once __DIR__ . '/TourSearchHandoffService.php';

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
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '' || $value === 0 || $value === '0' || $value === []) continue;
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item === null || $item === '') continue;
                    $pairs[] = rawurlencode((string)$key) . '%5B%5D=' . rawurlencode((string)$item);
                }
                continue;
            }
            $pairs[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
        }
        if ($pairs) $url .= '?' . implode('&', $pairs);
        return $url;
    }

    public static function searchUrlFromSavedData(array $savedData, array $statusMap, string $yclid = ''): string
    {
        return self::searchUrl(TourSearchHandoffService::queryFromSavedData($savedData, $statusMap, $yclid));
    }

    public static function searchUrlFromClaim(array $claim, string $yclid = ''): string
    {
        return self::searchUrl(TourSearchHandoffService::queryFromClaim($claim, $yclid));
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
