<?php

class PendingMonthStore
{
    public static function filePath($chatId): string
    {
        $dir = __DIR__ . '/../ai_pending_month';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $safeChatId = preg_replace('/[^0-9\-]/', '', (string)$chatId);
        return $dir . '/' . $safeChatId . '.json';
    }

    public static function set($chatId, int $month, int $year): void
    {
        if ($month < 1 || $month > 12 || $year < 2020) {
            return;
        }

        @file_put_contents(
            self::filePath($chatId),
            json_encode([
                'month' => $month,
                'year' => $year,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    public static function get($chatId): array
    {
        $file = self::filePath($chatId);
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }

        $month = (int)($data['month'] ?? 0);
        $year = (int)($data['year'] ?? 0);

        if ($month < 1 || $month > 12 || $year < 2020) {
            return [];
        }

        return [
            'month' => $month,
            'year' => $year,
        ];
    }

    public static function clear($chatId): void
    {
        $file = self::filePath($chatId);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
