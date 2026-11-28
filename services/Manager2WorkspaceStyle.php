<?php
declare(strict_types=1);

/** Versioned presentation asset only. The workspace session and APIs stay on the common shell. */
final class Manager2WorkspaceStyle
{
    public const BUNDLED = 'manager2/assets/manager2.css';
    public const MAX_BYTES = 131072;
    public const CONTRACT_FILES = [
        'manager/index.php',
        'manager2/assets/manager2.js',
        self::BUNDLED,
        'manager2/workspace-chrome.php',
        'manager2/workspace-style.php',
        'services/Manager2WorkspaceStyle.php',
    ];

    public static function releaseRoot(string $app): string
    {
        return dirname($app).'/max-search-bot-private-logs/manager2-style-releases';
    }

    public static function contractPin(string $app): string
    {
        $pin = hash_init('sha256');
        foreach (self::CONTRACT_FILES as $file) {
            $digest = hash_file('sha256', $app.'/'.$file);
            if ($digest === false) throw new RuntimeException('manager2_style_contract_unavailable');
            hash_update($pin, $file.':'.$digest."\n");
        }
        return hash_final($pin);
    }

    public static function href(string $app): string
    {
        $releases = self::releaseRoot($app);
        $current = $releases.'/current';
        clearstatcache(true, $current);
        if (is_link($current) && realpath($releases) === $releases) {
            $target = readlink($current);
            if (is_string($target)) {
                $version = basename($target);
                if (self::version($version) && $target === $releases.'/'.$version
                    && realpath($target) === $target && is_file($target.'/style.css') && !is_link($target.'/style.css')) {
                    return '/manager2/workspace-style.php?v='.$version;
                }
            }
        }
        return '/'.self::BUNDLED.'?v='.filemtime($app.'/'.self::BUNDLED);
    }

    public static function bytes(string $app, string $version): ?string
    {
        if (!self::version($version)) return null;
        $releases = self::releaseRoot($app);
        $directory = $releases.'/'.$version;
        $file = $directory.'/style.css';
        if (realpath($releases) !== $releases || realpath($directory) !== $directory
            || is_link($file) || !is_file($file)) return null;
        $bytes = @file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES
            || !hash_equals(substr($version, 0, 64), hash('sha256', $bytes))) return null;
        return $bytes;
    }

    private static function version(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}-[a-f0-9]{64}\z/D', $value) === 1;
    }
}
