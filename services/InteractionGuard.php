<?php
require_once __DIR__ . '/DialogueStateMachine.php';
require_once __DIR__ . '/DiagnosticLogger.php';

/**
 * Shared callback interaction guard helpers.
 *
 * This service deliberately contains only generic interaction-safety mechanics:
 * per-chat serialization, expected-status checks, duplicate-window checks,
 * one-shot callback generations and wizard forward-step expectations. Dialogue
 * actions keep ownership of business behavior.
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
    public static function synchronized(
        int $chatId,
        string $scope,
        callable $callback,
        array $suppressionContext = []
    ): bool {
        $fp = @fopen(self::lockPath($chatId, $scope), 'c+');
        if (!$fp || !flock($fp, LOCK_EX)) {
            if ($fp) fclose($fp);
            self::reportSuppressed(
                $chatId,
                (string)($suppressionContext['payload'] ?? ''),
                'concurrent',
                isset($suppressionContext['current_status']) ? (int)$suppressionContext['current_status'] : null,
                isset($suppressionContext['expected_status']) ? (int)$suppressionContext['expected_status'] : null,
                $scope
            );
            return true;
        }

        try {
            return (bool)$callback($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Serialize a callback and require one exact dialogue status before handing
     * control to the business action. Stale/concurrent decisions and structured
     * diagnostics stay owned by the shared guard; optional onStale keeps legacy
     * operational text logs outside the safety policy itself.
     */
    public static function runExpectedStatusCallback(
        int $chatId,
        string $payload,
        string $scope,
        int $expectedStatus,
        callable $callback,
        ?callable $onStale = null
    ): bool {
        return self::synchronized(
            $chatId,
            $scope,
            function ($fp) use ($chatId, $payload, $scope, $expectedStatus, $callback, $onStale): bool {
                $currentStatus = (int)MaxSearchApi::getCurentStatus($chatId);
                if ($currentStatus !== $expectedStatus) {
                    self::reportSuppressed($chatId, $payload, 'stale_state', $currentStatus, $expectedStatus, $scope);
                    if ($onStale !== null) $onStale($currentStatus, $expectedStatus);
                    return true;
                }
                return (bool)$callback($fp);
            },
            ['payload'=>$payload, 'expected_status'=>$expectedStatus]
        );
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

    /**
     * Consume an exact repeated callback delivery inside a short window.
     * The marker is written while holding the same per-chat/scope lock, so a
     * concurrent retry sees the first delivery before it can repeat behavior.
     * Different payloads are never suppressed by this helper.
     */
    public static function suppressDuplicateCallback(
        int $chatId,
        string $payload,
        string $scope,
        float $windowSeconds = 2.0
    ): bool {
        $fp = @fopen(self::lockPath($chatId, 'dedupe.' . $scope), 'c+');
        if (!$fp || !flock($fp, LOCK_EX)) {
            if ($fp) fclose($fp);
            self::reportSuppressed($chatId, $payload, 'concurrent', null, null, $scope);
            return true;
        }

        try {
            rewind($fp);
            $state = json_decode((string)stream_get_contents($fp), true);
            $previousPayload = is_array($state) ? (string)($state['payload'] ?? '') : '';
            $previousAt = is_array($state) ? (float)($state['at'] ?? 0.0) : 0.0;
            $now = microtime(true);

            if (self::isDuplicate($previousPayload, $previousAt, $payload, $now, $windowSeconds)) {
                self::reportSuppressed($chatId, $payload, 'duplicate', null, null, $scope);
                return true;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(['payload'=>$payload, 'at'=>$now], JSON_UNESCAPED_SLASHES));
            fflush($fp);
            return false;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Run a versioned callback exactly once for the currently rendered surface.
     *
     * The generation claim is persisted before the business callback executes,
     * under the same per-chat lock. A concurrent or late retry therefore sees
     * `used:<generation>` and is consumed as obsolete instead of repeating side
     * effects. If a handler rejects the action before changing dialogue state,
     * the claim is restored so the user can retry.
     */
    public static function runGeneratedCallback(
        int $chatId,
        string $rawPayload,
        string $generation,
        int $generationStatus,
        callable $callback
    ): bool {
        return self::synchronized($chatId, 'callback_generation', function () use ($chatId, $rawPayload, $generation, $generationStatus, $callback): bool {
            $currentGeneration = (string)MaxSearchApi::getLastValue($chatId, $generationStatus);
            if ($currentGeneration !== $generation) {
                self::reportSuppressed(
                    $chatId,
                    $rawPayload,
                    'obsolete_generation',
                    null,
                    null,
                    'callback_generation',
                    ['generation'=>$generation, 'current_generation'=>$currentGeneration]
                );
                return true;
            }

            $claimedValue = 'used:' . $generation;
            MaxSearchApi::saveLastValue($chatId, $generationStatus, $claimedValue);
            $claimed = (string)MaxSearchApi::getLastValue($chatId, $generationStatus) === $claimedValue;
            if (!$claimed) {
                self::reportSuppressed(
                    $chatId,
                    $rawPayload,
                    'generation_claim_failed',
                    null,
                    null,
                    'callback_generation',
                    ['generation'=>$generation]
                );
                return true;
            }

            $handled = (bool)$callback();
            if (!$handled) {
                $latest = (string)MaxSearchApi::getLastValue($chatId, $generationStatus);
                if ($latest === $claimedValue) {
                    MaxSearchApi::saveLastValue($chatId, $generationStatus, $generation);
                }
            }
            return $handled;
        });
    }

    public static function expectedWizardStatus(string $payload): ?int
    {
        return DialogueStateMachine::expectedStatusForForwardCallback($payload);
    }

    public static function reportSuppressed(
        int $chatId,
        string $payload,
        string $reason,
        ?int $currentStatus = null,
        ?int $expectedStatus = null,
        string $scope = 'wizard',
        array $details = []
    ): bool {
        $data = $details;
        $data['reason'] = $reason;
        $data['scope'] = $scope;
        if ($payload !== '') $data['payload'] = $payload;
        if ($currentStatus !== null) {
            $data['current_status'] = $currentStatus;
            $data['current_state'] = DialogueStateMachine::stateForStatus($currentStatus);
        }
        if ($expectedStatus !== null) {
            $data['expected_status'] = $expectedStatus;
            $data['expected_state'] = DialogueStateMachine::stateForStatus($expectedStatus);
        }

        return DiagnosticLogger::warning('interaction_guard', 'callback_suppressed', $data, $chatId);
    }

    public static function isStaleWizardForward(int $chatId, string $payload): bool
    {
        $expected = self::expectedWizardStatus($payload);
        if ($expected === null) return false;

        $current = (int)MaxSearchApi::getCurentStatus($chatId);
        if ($current === $expected) return false;

        self::reportSuppressed($chatId, $payload, 'stale_state', $current, $expected, 'wizard_forward');
        if (function_exists('put_log_in')) {
            put_log_in('STALE_WIZARD_CALLBACK_SKIPPED chat=' . $chatId . ' payload=' . $payload . ' status=' . $current . ' expected=' . $expected);
        }
        return true;
    }
}
