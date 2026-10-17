<?php
declare(strict_types=1);

/** Private CLI file boundary. Archive data must never be written in the checkout. */
final class TelegramDialogueFiles
{
    public const MAX_INPUT_BYTES = 67108864;

    private static function path(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) throw new InvalidArgumentException('invalid_private_path');
        if ($path[0] !== '/') $path = getcwd().'/'.$path;
        $parent = realpath(dirname($path));
        if ($parent === false || $parent !== dirname($path)) throw new InvalidArgumentException('private_path_must_be_canonical');
        $root = dirname(__DIR__);
        if ($parent === $root || str_starts_with($parent, $root.'/')) throw new InvalidArgumentException('private_path_inside_checkout');
        return $parent.'/'.basename($path);
    }

    public static function read(string $input): string
    {
        $input = self::path($input);
        if (is_link($input) || !is_file($input)) throw new InvalidArgumentException('private_input_not_regular');
        $stream = @fopen($input, 'rb');
        if (!$stream) throw new RuntimeException('private_input_unavailable');
        try {
            $stat = fstat($stream);
            clearstatcache(true, $input);
            $pathStat = lstat($input);
            if (!$stat || !$pathStat || ($pathStat['mode'] & 0170000) !== 0100000 || $stat['ino'] !== $pathStat['ino'] || $stat['dev'] !== $pathStat['dev']
                || realpath($input) !== $input || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > self::MAX_INPUT_BYTES) throw new InvalidArgumentException('private_input_limit');
            $data = stream_get_contents($stream, self::MAX_INPUT_BYTES + 1);
            if (!is_string($data) || strlen($data) > self::MAX_INPUT_BYTES) throw new RuntimeException('private_input_limit');
            return $data;
        } finally { fclose($stream); }
    }

    public static function write(string $output, callable $writer): void
    {
        $output = self::path($output); $directory = dirname($output);
        $mode = fileperms($directory);
        if ($mode === false || ($mode & 0777) !== 0700 || (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid())) {
            throw new InvalidArgumentException('private_output_directory_required');
        }
        if (file_exists($output) || is_link($output)) throw new InvalidArgumentException('private_output_exists');
        $temporary = $directory.'/.telegram-archive-'.bin2hex(random_bytes(16));
        $stream = @fopen($temporary, 'x+b');
        if (!$stream) throw new RuntimeException('private_output_unavailable');
        try {
            if (!@chmod($temporary, 0600)) throw new RuntimeException('private_output_permissions');
            $writer(static function (string $bytes) use ($stream): void {
                $remaining = strlen($bytes); $offset = 0;
                while ($remaining > 0) {
                    $written = fwrite($stream, substr($bytes, $offset));
                    if ($written === false || $written === 0) throw new RuntimeException('private_output_write_failed');
                    $remaining -= $written; $offset += $written;
                }
            });
            if (!fflush($stream) || (function_exists('fsync') && !fsync($stream))) throw new RuntimeException('private_output_flush_failed');
            fclose($stream); $stream = null;
            // A hard-link publishes a completed file exclusively, never overwriting a target.
            if (!@link($temporary, $output)) throw new RuntimeException('private_output_publish_failed');
        } finally {
            if (is_resource($stream)) fclose($stream);
            @unlink($temporary);
        }
    }
}
