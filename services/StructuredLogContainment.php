<?php

declare(strict_types=1);

/** One-way containment of the former public structured log, without reading it. */
final class StructuredLogContainment
{
    public static function contain(string $legacy, string $active): array
    {
        $result = ['ok'=>false,'legacy_fenced'=>false,'archive_created'=>false,'active_seeded'=>false];
        $archive = null;
        $fence = null;
        $handle = null;
        try {
            if (!self::validPaths($legacy, $active)) return $result;
            $stat = self::regularStat($legacy);
            if ($stat === null) {
                // Atomically reserve an absent name with mode 000 from creation.
                // A concurrent old writer that wins creation is handled below.
                if (!file_exists($legacy) && !is_link($legacy)) {
                    $oldMask = umask(0777);
                    try { $created = @fopen($legacy, 'x+b'); }
                    finally { umask($oldMask); }
                    if ($created) { @fclose($created); return self::finishFence($legacy, $active, $result); }
                }
                $stat = self::regularStat($legacy);
                if ($stat === null) return $result;
            }
            if (($stat['mode'] & 0777) === 0000) {
                if ($stat['size'] !== 0) return $result;
                return self::finishFence($legacy, $active, $result);
            }
            $handle = @fopen($legacy, 'rb');
            if (!$handle || !@flock($handle, LOCK_EX)) return $result;
            $opened = @fstat($handle);
            if (!self::sameFile($opened, self::regularStat($legacy))) return $result;
            $archive = dirname($active) . '/structured_events.legacy-' . bin2hex(random_bytes(12)) . '.log';
            if (!@link($legacy, $archive)) return $result;
            $saved = self::regularStat($archive);
            if (!self::sameFile($opened, $saved) || (int)$saved['nlink'] < 2
                || !self::sameFile($opened, self::regularStat($legacy))) return $result;
            $result['archive_created'] = true;
            $fence = dirname($legacy) . '/.structured-log-fence-' . bin2hex(random_bytes(12));
            $oldMask = umask(0777);
            try { $empty = @fopen($fence, 'x+b'); }
            finally { umask($oldMask); }
            if (!$empty) return $result;
            @fclose($empty);
            $fenceStat = self::regularStat($fence);
            if ($fenceStat === null || $fenceStat['size'] !== 0 || ($fenceStat['mode'] & 0777) !== 0000
                || !self::sameFile($opened, self::regularStat($legacy))) return $result;
            // Existing open writer descriptors keep the archived inode. Future
            // old writers see this empty mode-000 inode instead of recreating it.
            if (!@rename($fence, $legacy)) return $result;
            $fence = null;
            if (!self::sameFile($fenceStat, self::regularStat($legacy))) return $result;
            $result['legacy_fenced'] = true;
            if (!@chmod($archive, 0600)) return $result;
            if (!file_exists($active) && !is_link($active)) {
                if (@link($archive, $active)) {
                    $result['active_seeded'] = true;
                } elseif (!self::validPaths($legacy, $active) || self::regularStat($active) === null) {
                    return $result;
                }
                // A live new writer may have created a regular active file
                // between the absent check and link; keep that data untouched.
            }
            return self::finishFence($legacy, $active, $result);
        } catch (Throwable $ignored) {
            return $result;
        } finally {
            if (is_resource($handle)) { @flock($handle, LOCK_UN); @fclose($handle); }
            if ($fence !== null && self::regularStat($fence) !== null) @unlink($fence);
            // Never remove an archive, including after an interrupted migration.
        }
    }

    /** Check only; no archive, marker, replacement, chmod or history mutation. */
    public static function check(string $legacy, string $active): array
    {
        $valid = self::validPaths($legacy, $active);
        $fence = $valid ? self::regularStat($legacy) : null;
        $file = $valid ? self::regularStat($active) : null;
        $fenced = $fence !== null && $fence['size'] === 0 && ($fence['mode'] & 0777) === 0000;
        $private = $file !== null && ($file['mode'] & 0777) === 0600;
        return ['ok'=>$valid && $fenced && $private,'outside_document_root'=>$valid,
            'legacy_fenced'=>$fenced,'directory_private'=>$valid,'file_private'=>$private];
    }

    private static function finishFence(string $legacy, string $active, array $result): array
    {
        $fence = self::regularStat($legacy);
        if ($fence === null || $fence['size'] !== 0 || ($fence['mode'] & 0777) !== 0000) return $result;
        $result['legacy_fenced'] = true;
        $activeStat = self::regularStat($active);
        if ($activeStat !== null && !@chmod($active, 0600)) return $result;
        if ($activeStat === null && (file_exists($active) || is_link($active))) return $result;
        $result['ok'] = true;
        return $result;
    }

    private static function validPaths(string $legacy, string $active): bool
    {
        $root = realpath(dirname(__DIR__));
        if ($root === false || !self::plainAbsolute($legacy) || !self::plainAbsolute($active)
            || $legacy === $active) return false;
        $legacyDir = realpath(dirname($legacy));
        $privateDir = realpath(dirname($active));
        if ($legacyDir === false || $privateDir === false || $legacyDir === $privateDir
            || $privateDir === $root || str_starts_with($privateDir, $root . DIRECTORY_SEPARATOR)
            || (fileperms($privateDir) & 0777) !== 0700) return false;
        $activeStat = self::regularStat($active);
        return $activeStat !== null || (!file_exists($active) && !is_link($active));
    }

    private static function plainAbsolute(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) return false;
        $parts = explode('/', $path);
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') continue;
            if ($part === '.' || $part === '..') return false;
            $current .= '/' . $part;
            if (is_link($current)) return false;
        }
        return true;
    }

    private static function regularStat(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000 ? $stat : null;
    }

    private static function sameFile($a, $b): bool
    {
        return is_array($a) && is_array($b) && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
    }
}
