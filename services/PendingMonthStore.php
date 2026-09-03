<?php

require_once __DIR__ . '/AiRuntimeLogger.php';

class PendingMonthStore
{
    public static function filePath($chatId): string
    {
        $dir = __DIR__ . '/../ai_pending_month';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $safeChatId = preg_replace('/[^0-9\-]/', '', (string)$chatId);
        return $dir . '/' . $safeChatId . '.json';
    }

    private static function debug($chatId, string $action, array $extra = []): void
    {
        $payload = array_merge([
            'time' => date('d.m.Y H:i:s'),
            'chat' => (string)$chatId,
            'action' => $action,
        ], $extra);
        AiRuntimeLogger::debug(
            "PENDING_MONTH: ".json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    public static function set($chatId, int $month, int $year): void
    {
        if ($month < 1 || $month > 12 || $year < 2020) {
            self::debug($chatId, 'set_rejected', ['month'=>$month,'year'=>$year]);
            return;
        }
        $file = self::filePath($chatId);
        $bytes = @file_put_contents(
            $file,
            json_encode(['month'=>$month,'year'=>$year], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        self::debug($chatId, 'set', [
            'month'=>$month,
            'year'=>$year,
            'file'=>$file,
            'bytes'=>$bytes === false ? false : (int)$bytes,
            'exists'=>is_file($file),
        ]);
    }

    public static function get($chatId): array
    {
        $file = self::filePath($chatId);
        if (!is_file($file)) {
            self::debug($chatId, 'get_missing', ['file'=>$file]);
            return [];
        }
        $raw = (string)@file_get_contents($file);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::debug($chatId, 'get_invalid', ['file'=>$file,'raw'=>$raw]);
            return [];
        }
        $month = (int)($data['month'] ?? 0);
        $year = (int)($data['year'] ?? 0);
        if ($month < 1 || $month > 12 || $year < 2020) {
            self::debug($chatId, 'get_rejected', ['file'=>$file,'month'=>$month,'year'=>$year]);
            return [];
        }
        self::debug($chatId, 'get', ['file'=>$file,'month'=>$month,'year'=>$year]);
        return ['month'=>$month,'year'=>$year];
    }

    public static function clear($chatId): void
    {
        $file = self::filePath($chatId);
        $existed = is_file($file);
        $deleted = $existed ? @unlink($file) : false;
        self::debug($chatId, 'clear', [
            'file'=>$file,
            'existed'=>$existed,
            'deleted'=>$deleted,
        ]);
    }
}
