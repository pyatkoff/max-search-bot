<?php
require_once __DIR__ . '/ProjectConfig.php';

class TripStateRepository
{
    public static function load($chatId, string $baseDir): array
    {
        $file = self::file($chatId, $baseDir);
        if (!is_file($file) || !is_readable($file)) return [];
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public static function save($chatId, array $state, string $baseDir): bool
    {
        $dir = ProjectConfig::v2StoreDir($baseDir);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $state['meta']['project_id'] = ProjectConfig::projectId();
        $state['meta']['updated_at'] = date('c');
        $json = json_encode($state, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        $file = self::file($chatId, $baseDir);
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        @chmod($tmp, 0644);
        return @rename($tmp, $file);
    }

    public static function delete($chatId, string $baseDir): bool
    {
        $file = self::file($chatId, $baseDir);
        return !is_file($file) || @unlink($file);
    }

    public static function overlay(array $legacyState, array $storedState): array
    {
        if (!$storedState) return $legacyState;
        return self::mergeRecursivePreservingLegacyIds($legacyState, $storedState);
    }

    private static function mergeRecursivePreservingLegacyIds(array $legacy, array $stored): array
    {
        foreach ($stored as $key => $value) {
            if ($key === 'meta') {
                $legacy['meta'] = array_merge((array)($legacy['meta'] ?? []), (array)$value);
                continue;
            }
            if (is_array($value) && isset($legacy[$key]) && is_array($legacy[$key])) {
                $legacy[$key] = self::mergeRecursivePreservingLegacyIds($legacy[$key], $value);
            } else {
                $legacy[$key] = $value;
            }
        }
        return $legacy;
    }

    private static function file($chatId, string $baseDir): string
    {
        $safe = preg_replace('/[^0-9\-]/', '', (string)$chatId);
        if ($safe === '') $safe = '0';
        return ProjectConfig::v2StoreDir($baseDir) . '/' . $safe . '.json';
    }
}
