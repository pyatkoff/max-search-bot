<?php

class FollowupQueueService
{
    public static function dir(string $baseDir): string
    {
        $dir = rtrim($baseDir, '/') . '/followup';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    public static function file(string $baseDir, $chatId): string
    {
        $safe = preg_replace('/[^0-9\-]/', '', (string)$chatId);
        return self::dir($baseDir) . '/' . $safe . '.json';
    }

    public static function schedule(string $baseDir, $chatId, int $delaySeconds = 180, ?int $now = null): bool
    {
        $now = $now ?? time();
        $data = [
            'chat_id' => (string)$chatId,
            'send_at' => $now + $delaySeconds,
            'created_at' => $now,
        ];
        return @file_put_contents(
            self::file($baseDir, $chatId),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
    }

    public static function cancel(string $baseDir, $chatId): bool
    {
        $file = self::file($baseDir, $chatId);
        if (!is_file($file)) return true;
        return @unlink($file);
    }

    public static function readFile(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return ['ok'=>false, 'error'=>'not_readable'];
        }
        $raw = (string)@file_get_contents($file);
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['chat_id']) || empty($data['send_at'])) {
            return ['ok'=>false, 'error'=>'invalid_json', 'raw'=>$raw];
        }
        return ['ok'=>true, 'data'=>$data, 'raw'=>$raw];
    }

    public static function list(string $baseDir): array
    {
        return glob(self::dir($baseDir) . '/*.json') ?: [];
    }

    public static function classify(array $data, ?int $now = null): array
    {
        $now = $now ?? time();
        $sendAt = (int)($data['send_at'] ?? 0);
        if ($sendAt <= 0) return ['status'=>'invalid'];
        if ($sendAt > $now) {
            return ['status'=>'waiting', 'seconds'=>$sendAt - $now];
        }
        return ['status'=>'due', 'seconds'=>0];
    }
}
