<?php

/**
 * Shared callback interaction guard helpers.
 *
 * This service deliberately contains only generic interaction-safety mechanics:
 * per-chat serialization, duplicate-window checks and wizard forward-step
 * expectations. Dialogue actions keep ownership of business behavior.
 */
class InteractionGuard
{
    public static function lockPath(int $chatId, string $scope): string
    {
        $dir = sys_get_temp_dir() . '/max-search-interaction-locks';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/' . hash('sha256', (string)$chatId) . '.' . preg_replace('/[^a-z0-9_.-]+/i', '_', $scope) . '.lock';
    }

    /**
     * Execute a callback under a per-chat/per-scope exclusive lock.
     * Failure to acquire the lock is treated as a consumed interaction so a
     * concurrent delivery cannot fall through into duplicate behavior.
     */
    public static function synchronized(int $chatId, string $scope, callable $callback): bool
    {
        $fp = @fopen(self::lockPath($chatId, $scope), 'c+');
        if (!$fp || !flock($fp, LOCK_EX)) {
            if ($fp) fclose($fp);
            return true;
        }

        try {
            return (bool)$callback($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public static function isDuplicate(
        string $previousPayload,
        float $previousAt,
        string $payload,
        float $now,
        float $windowSeconds
    ): bool {
        return $previousPayload === $payload
            && $previousAt > 0
            && $now >= $previousAt
            && ($now - $previousAt) < $windowSeconds;
    }

    public static function isRecent(float $previousAt, float $now, float $windowSeconds): bool
    {
        return $previousAt > 0
            && $now >= $previousAt
            && ($now - $previousAt) < $windowSeconds;
    }

    public static function expectedWizardStatus(string $payload): ?int
    {
        if (strpos($payload, 'pick_city_') === 0) return (int)MaxSearchApi::$statusCityChoose;
        if (strpos($payload, 'pick_country_') === 0) return (int)MaxSearchApi::$statusContryChoose;
        if (strpos($payload, 'adults_') === 0) return (int)MaxSearchApi::$statusAdults;
        if (strpos($payload, 'child_') === 0) return (int)MaxSearchApi::$statusChild;
        if (strpos($payload, 'star_') === 0) return (int)MaxSearchApi::$statusStars;
        if (strpos($payload, 'meal_') === 0) return (int)MaxSearchApi::$statusMeal;
        if (strpos($payload, 'nights_') === 0) return (int)MaxSearchApi::$statusNights;
        return null;
    }

    public static function isStaleWizardForward(int $chatId, string $payload): bool
    {
        $expected = self::expectedWizardStatus($payload);
        if ($expected === null) return false;

        $current = (int)MaxSearchApi::getCurentStatus($chatId);
        if ($current === $expected) return false;

        if (function_exists('put_log_in')) {
            put_log_in('STALE_WIZARD_CALLBACK_SKIPPED chat=' . $chatId . ' payload=' . $payload . ' status=' . $current . ' expected=' . $expected);
        }
        return true;
    }
}
