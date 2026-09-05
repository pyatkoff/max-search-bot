<?php

declare(strict_types=1);

/**
 * Bounded legacy webhook diagnostics stored outside the public application tree.
 *
 * Some compatibility callers still emit message or callback details through
 * put_log_in()/put_log_out(). Those values may contain customer data, so the
 * logger refuses paths inside the repository/document root and keeps private,
 * bounded files only.
 */
final class WebhookRuntimeLogger
{
    private const MAX_FILE_BYTES = 1048576;
    private const MAX_RECORD_BYTES = 65536;

    public static function input(string $message): bool
    {
        return self::append('webhook_input.log', $message);
    }

    public static function output(string $message): bool
    {
        return self::append('webhook_output.log', $message);
    }

    public static function inputFile(): string
    {
        return self::file('webhook_input.log');
    }

    public static function outputFile(): string
    {
        return self::file('webhook_output.log');
    }

    private static function append(string $name, string $message): bool
    {
        $file = self::file($name);
        if ($file === '' || is_link($file)) return false;

        if (strlen($message) > self::MAX_RECORD_BYTES) {
            $message = substr($message, 0, self::MAX_RECORD_BYTES) . "\n[record truncated]\n";
        }
        if ($message === '' || substr($message, -1) !== "\n") $message .= "\n";

        $handle = @fopen($file, 'c+');
        if (!$handle) return false;
        @chmod($file, 0600);

        $ok = false;
        if (@flock($handle, LOCK_EX)) {
            $stat = fstat($handle);
            $size = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
            if ($size + strlen($message) > self::MAX_FILE_BYTES) {
                @ftruncate($handle, 0);
                @rewind($handle);
            } else {
                @fseek($handle, 0, SEEK_END);
            }
            $ok = @fwrite($handle, $message) !== false;
            @fflush($handle);
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);
        return $ok;
    }

    private static function file(string $name): string
    {
        $dir = self::directory();
        return $dir === '' ? '' : $dir . DIRECTORY_SEPARATOR . $name;
    }

    private static function directory(): string
    {
        $configured = trim((string)(getenv('MAX_SEARCH_WEBHOOK_LOG_DIR') ?: ''));
        $dir = $configured !== ''
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'max-search-bot-webhook';

        if ($dir === '' || $dir[0] !== DIRECTORY_SEPARATOR || is_link($dir)) return '';

        $projectRoot = realpath(dirname(__DIR__));
        if ($projectRoot === false) return '';
        $normalizedDir = self::normalizeAbsolutePath($dir);
        if ($normalizedDir === $projectRoot
            || str_starts_with($normalizedDir, $projectRoot . DIRECTORY_SEPARATOR)) {
            return '';
        }

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return '';
        @chmod($dir, 0700);

        $resolved = realpath($dir);
        if ($resolved === false
            || $resolved === $projectRoot
            || str_starts_with($resolved, $projectRoot . DIRECTORY_SEPARATOR)) {
            return '';
        }
        return $resolved;
    }

    private static function normalizeAbsolutePath(string $path): string
    {
        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }
}
