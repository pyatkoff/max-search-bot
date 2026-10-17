<?php

class DiagnosticLogger
{
    private static $file;

    public static function log($component, $event, array $data = [], $chatId = null, $level = 'info')
    {
        $record = [
            'ts' => date('c'),
            'level' => (string)$level,
            'component' => (string)$component,
            'event' => (string)$event,
        ];

        if ($chatId !== null && $chatId !== '') {
            $record['chat_id'] = $chatId;
        }
        if ($data) {
            $record['data'] = self::sanitize($data);
        }

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;

        $file = self::filePath();
        if ($file === '') return false;
        $handle = @fopen($file, 'ab');
        if (!$handle) return false;
        $ok = false;
        if (self::sameRegularFile($file, $handle) && @chmod($file, 0600) && @flock($handle, LOCK_EX)) {
            $message = $json . PHP_EOL;
            $ok = @fwrite($handle, $message) === strlen($message);
            @fflush($handle);
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);
        return $ok;
    }

    public static function error($component, $event, array $data = [], $chatId = null)
    {
        return self::log($component, $event, $data, $chatId, 'error');
    }

    public static function warning($component, $event, array $data = [], $chatId = null)
    {
        return self::log($component, $event, $data, $chatId, 'warning');
    }

    public static function setFile($file)
    {
        self::$file = (string)$file;
    }

    /** Canonical private reader/writer path. This never creates the log itself. */
    public static function filePath(): string
    {
        $root = realpath(dirname(__DIR__));
        if ($root === false) return '';
        $override = (string)self::$file;
        $file = $override !== '' ? $override : dirname($root) . '/max-search-bot-private-logs/structured_events.log';
        if (!self::safeAbsolutePath($file, $root)) return '';
        $dir = dirname($file);
        // Test overrides keep their existing external directory permissions;
        // in particular, never chmod a shared temporary-directory parent.
        if ($override === '') {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return '';
            if (!@chmod($dir, 0700)) return '';
        }
        $resolved = realpath($dir);
        if ($resolved === false || !self::safeAbsolutePath($resolved, $root)) return '';
        clearstatcache(true, $file);
        $stat = @lstat($file);
        if ($stat !== false && (($stat['mode'] & 0170000) !== 0100000)) return '';
        return $resolved . DIRECTORY_SEPARATOR . basename($file);
    }

    private static function safeAbsolutePath(string $path, string $root): bool
    {
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0")) return false;
        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '') continue;
            if ($part === '.' || $part === '..') return false;
            $parts[] = $part;
        }
        $normalized = DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        if ($normalized === $root || str_starts_with($normalized, $root . DIRECTORY_SEPARATOR)) return false;
        $current = '';
        foreach ($parts as $part) {
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) return false;
        }
        return true;
    }

    private static function sameRegularFile(string $file, $handle): bool
    {
        clearstatcache(true, $file);
        $named = @lstat($file);
        $opened = @fstat($handle);
        return is_array($named) && is_array($opened)
            && ($named['mode'] & 0170000) === 0100000
            && $named['dev'] === $opened['dev'] && $named['ino'] === $opened['ino'];
    }

    private static function sanitize(array $data)
    {
        $out = [];
        foreach ($data as $key => $value) {
            $keyString = strtolower((string)$key);
            if (preg_match('/token|secret|password|authorization|api[_-]?key/', $keyString)) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::sanitize($value);
                continue;
            }
            if (is_string($value)) {
                $value = preg_replace('/(?<!\d)(?:\+7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}(?!\d)/u', '[phone-redacted]', $value);
                if (strlen($value) > 4000) $value = substr($value, 0, 4000) . '…';
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
