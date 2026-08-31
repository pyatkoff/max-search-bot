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

    public static function claimUrl(string $code, string $yclid = ''): string
    {
        $path = (string)self::get('search.claim_path', '/poisk-turov-tg/{code}/');
        $path = str_replace('{code}', rawurlencode($code), $path);
        $url = self::baseDomain() . '/' . ltrim($path, '/');
        if ($yclid !== '') $url .= (strpos($url, '?') === false ? '?' : '&') . 'yclid=' . rawurlencode($yclid);
        return $url;
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