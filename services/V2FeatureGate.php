<?php

class V2FeatureGate
{
    private static $features;

    public static function enabled(string $feature): bool
    {
        $features = self::features();
        return !empty($features['ai_v2'][$feature]);
    }

    public static function all(): array
    {
        return self::features();
    }

    public static function resetForTests(?array $features = null): void
    {
        self::$features = $features;
    }

    private static function features(): array
    {
        if (is_array(self::$features)) return self::$features;
        $file = dirname(__DIR__) . '/project_features.php';
        if (!is_file($file)) return self::$features = ['ai_v2'=>[]];
        $loaded = require $file;
        return self::$features = is_array($loaded) ? $loaded : ['ai_v2'=>[]];
    }
}
